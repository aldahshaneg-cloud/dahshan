/**
 * ⏸️ حارس: وقت الإذن بيتخصم من ساعات الطيار.
 *
 * طلب صاحب النظام (2026-09-10): «لو الطيار استأذن، الوقت اللي استأذن فيه
 * يتخصم من إجمالي عدد ساعاته — زي ما هعطيهم أوفرتايم لازم يكون في خصم على
 * الوقت اللي بيكون فيه بره العمل».
 *
 * الخصم كان موجود أصلًا، وde بيثبّته وبيقفل تلات فجوات كانت بتخلّيه يفشل:
 *
 * ① 🔴 الإذن اللي **لسه مفتوح** (`ended_at` فاضي) كان بيتخطّى خالص —
 *    الشرط كان `ended_at IS NOT NULL`. طيار قاعد على إذن دلوقتي، أو إذن
 *    محدش قفله، وقته كان بيتدفع كأنه شغل. بقى بيتحسب لحد **دلوقتي**،
 *    وبسقف **آخر يومه التجاري** عشان إذن اتنسي مفتوح من أسبوع مايصفّرش
 *    ورديات أسبوع (الفترة بتتقصّ على كل وردية جوّاها).
 *
 * ② الخصم مكانه **`dayRow` بس**. كان بيتخصم في الكنترولر كمان فالساعة
 *    بتتخصم مرتين (وردية ٦ وإذن ساعة طلعت ٤ بدل ٥).
 *
 * ③ والإضافي بيتحسب **بعد** الخصم: ١٠ ساعات وإذن ساعتين = ٨ ساعات،
 *    يعني مفيش أوفرتايم — مش ١٠ ساعات وإضافي ساعتين.
 *
 * ⚠️ `hours_override` (المشرف كتب الساعات بإيده) بيغلب على كل ده — كلمته
 *    الأخيرة، والإذن مابيتخصمش من رقم كتبه هو.
 *
 * التشغيل: node ops/test_leave_hours.cjs   (بيشغّل PHP الحقيقي)
 */
const fs = require('fs');
// PHP: المتغير PHP_BIN أو أول مسار موجود (كان C:/xampp ثابت — جهاز واحد بس)
const PHP_BIN = process.env.PHP_BIN || ['C:/php83/php.exe', 'C:/xampp/php/php.exe'].find(p => fs.existsSync(p)) || 'php';
const { execFileSync } = require('child_process');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

console.log('\n══ ① الكود ══');
const W = fs.readFileSync('app/Wire/PilotAccountingWire.php', 'utf8');
const C = fs.readFileSync('app/Http/Controllers/Api/PilotAccountingController.php', 'utf8');
ok('الخصم في dayRow (المكان الوحيد)',
  /\$mins  = \(\(float\) \$auto\['hours'\]\) \* 60 - self::permMinutes\(\$perms\);/.test(W));
ok('🔴 والكنترولر مابيخصمش تاني (كان بيخصم مرتين)',
  /الساعات هنا \*\*قبل\*\* خصم الاستئذان عن قصد/.test(C) && !/hours'\] -= .*permMinutes/.test(C));
ok('🔴 والإذن المفتوح داخل الاستعلام (كان `ended_at IS NOT NULL`)',
  /AND \(ended_at IS NULL OR ended_at >= \?\) AND responded_at < \?/.test(C),
  'الإذن اللي لسه مفتوح كان بيتدفع كأنه شغل');
ok('وبيتحسب لحد دلوقتي', /\$b  = \$openLeaveNow;/.test(C) && /\$openLeaveNow  = time\(\);/.test(C));
ok('🔴 وبسقف آخر يومه التجاري (إذن اتنسي مفتوح مايصفّرش أسبوع)',
  /\[, \$endUtc\] = W::bizWindowUtc\(\$bm\['date'\], \$ds\);[\s\S]{0,120}\$b = min\(\$b, \(int\) strtotime\(\$endUtc/.test(C));
ok('واللحظة ثابتة للرد كله (مش time() جوه الحلقة)',
  (C.match(/\$openLeaveNow/g) || []).length === 2);

console.log('\n══ ② تنفيذ فعلي (PHP) ══');
const php = `require "vendor/autoload.php";
$W = "App" . chr(92) . "Wire" . chr(92) . "PilotAccountingWire";
$row = function($h, $perms, $entry = []) use ($W) { $d = $W::dayRow(1, ["hours"=>$h,"in"=>"16:00","out"=>"22:00"], $entry, $perms); return $d["hours"]; };
echo $row(6, []), "\n";
echo $row(6, [["out"=>"18:00","in"=>"19:00"]]), "\n";
echo $row(6, [["out"=>"18:00","in"=>"18:30"]]), "\n";
echo $row(6, [["out"=>"17:00","in"=>"18:00"],["out"=>"20:00","in"=>"20:30"]]), "\n";
echo $row(2, [["out"=>"16:00","in"=>"22:00"]]), "\n";
echo $row(8, [["out"=>"23:30","in"=>"00:30"]]), "\n";
echo $row(6, [["out"=>"18:00","in"=>"19:00"]], ["hours_override"=>6]), "\n";
$mk = fn($h) => ["hours"=>$h,"orders"=>0,"svc"=>0,"psvc"=>0,"net"=>0,"adv"=>0,"ded"=>0,"bonus"=>0,"handed"=>0,"carry"=>[]];
$P = ["hour_rate"=>10,"monthly_salary"=>0,"paid_leave_days"=>0,"commission_type"=>"fixed","commission_value"=>0];
$run = function($h) use ($W, $mk, $P) { $t = $W::monthTotals([$mk($h)], $P, ["settings"=>["hourRate"=>10],"countedDays"=>30,"shiftHours"=>8]); return $t["hours"] . "|" . $t["overtimeHours"] . "|" . $t["payHours"]; };
$d10  = $W::dayRow(1, ["hours"=>10,"in"=>"14:00","out"=>"00:00"], [], []);
$d10p = $W::dayRow(1, ["hours"=>10,"in"=>"14:00","out"=>"00:00"], [], [["out"=>"18:00","in"=>"20:00"]]);
echo $run($d10["hours"]), "\n";
echo $run($d10p["hours"]), "\n";`;
let out;
try { out = execFileSync(PHP_BIN, ['-r', php], { encoding: 'utf8' }).trim().split('\n'); }
catch (e) { console.log('  ✗ تعذّر تشغيل PHP: ' + (e.message || '').slice(0, 140)); process.exit(1); }
const CASES = [
  ['وردية ٦ ساعات بلا إذن', '6'],
  ['🔴 وإذن ساعة في النص → ٥', '5'],
  ['وإذن نص ساعة → ٥.٥', '5.5'],
  ['وإذنين (ساعة + نص) → ٤.٥', '4.5'],
  ['إذن أطول من الوردية بيتقص عند صفر', '0'],
  ['إذن عدّى نص الليل بيتحسب صح', '7'],
  ['⚠️ والمكتوب بالإيد بيغلب (مفيش خصم عليه)', '6'],
  ['١٠ ساعات بلا إذن → إضافي ٢ ومدفوعة ١١', '10|2|11'],
  ['🔴 ١٠ ساعات وإذن ساعتين → ٨ ومفيش إضافي', '8|0|8'],
];
CASES.forEach(([label, want], i) => ok(label, out[i] === want, out[i]));

console.log('\n' + '─'.repeat(52));
console.log(fail === 0 ? `✅ عدّى ${pass} فحص — وقت الإذن بيتخصم\n` : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
