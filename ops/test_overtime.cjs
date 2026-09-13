/**
 * ⏱ حارس: الساعات الإضافية بتتحسب × ١.٥ — يوم بيوم.
 *
 * طلب صاحب النظام (2026-09-10): «الطيار أو أي موظف له عدد ساعات شغل، لو
 * زاد عن عدد الساعات دي المفروض الساعة تتحسب بزيادة نص ساعة — يعني لو
 * اشتغل ٢ ساعة [إضافي] يكون له ٣ ساعات».
 *
 * 🔴 العتبة **يومية** مش شهرية، والحساب لازم يفضل كده: طيار عتبته ٨ شغل
 * ١٠ + ٦ على يومين ياخد (٨ + ٢×١.٥) + ٦ = ١٧ ساعة مدفوعة. لو اتحسب على
 * المجموع الشهري (١٦ < ٨×٢) الإضافي كان هيضيع خالص.
 *
 * العتبة = `pilots.required_daily_hours` لو متحطّة، وإلا `shiftHours` من
 * الإعدادات (الموظفين مالهمش عمود خاص فبياخدوا الافتراضي — وده مقصود:
 * حساب الموظفين بيعدّي على نفس `monthTotals`).
 *
 * ⚠️ `hours` بيفضل الساعات **الفعلية** (ده اللي بيتعرض ويتراجع)،
 * و`payHours` هي أساس الأجر — الخلط بينهم بيدفع غلط.
 *
 * ⚠️ ودمشق (`DamascusController`) ليها حساباتها هي — مش داخلة هنا.
 *
 * التشغيل: node ops/test_overtime.cjs   (بيشغّل PHP الحقيقي)
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
ok('🔴 معامل الإضافي ثابت ومعرّف = 1.5', /public const OVERTIME_FACTOR = 1\.5;/.test(W));
ok('🔴 والحساب **يوم بيوم** مش على مجموع الشهر',
  /foreach \(\$days as \$d\) \{[\s\S]{0,200}\$ot = max\(0\.0, \$h - \$reqDaily\);/.test(W),
  'على المجموع الشهري الإضافي بيضيع');
ok('والعتبة: ساعات الطيار المطلوبة وإلا طول الوردية الافتراضي',
  /\$reqDaily = \(float\) \(\$pilot\['required_daily_hours'\] \?\? 0\) \?: \$shiftHours;/.test(W));
ok('والأجر من payHours مش من hours الخام',
  /\$t\['hourPay'\]     = round\(\$t\['payHours'\] \* \$hourRate, 2\);/.test(W) && !/round\(\$t\['hours'\] \* \$hourRate/.test(W));
ok('🔴 ومن الساعات **المقرّبة** عشان الشيت يجمع بالآلة الحاسبة',
  !/round\(\$payHours \* \$hourRate/.test(W),
  'من الرقم الخام: ٢٥٥٫٥٣ × ٣٥ مايساويش المكتوب');
ok('و«hours» فضلت الساعات الفعلية (للعرض والمراجعة)', /\$t\['hours'\]  \+= \(float\) \$d\['hours'\];/.test(W));
ok('والأرقام مكشوفة للواجهة (إضافي · مدفوعة · العتبة)',
  /\$t\['overtimeHours'\]      = /.test(W) && /\$t\['payHours'\]           = /.test(W) && /\$t\['requiredDailyHours'\] = /.test(W));
const C = fs.readFileSync('app/Http/Controllers/Api/PilotAccountingController.php', 'utf8');
ok('🔴 والاستعلام بيجيب required_daily_hours (وإلا العتبة الافتراضية بتسري على الكل)',
  /p\.paid_leave_days, p\.monthly_salary, p\.required_daily_hours/.test(C));
ok('وحساب الموظفين بيعدّي على نفس monthTotals (الطلب قال «أو أي موظف»)',
  /الموظف = طيار بلا أوردرات[\s\S]{0,120}W::monthTotals/.test(C));

console.log('\n══ ② تنفيذ فعلي (PHP) ══');
const php = `require "vendor/autoload.php";
$W = "App" . chr(92) . "Wire" . chr(92) . "PilotAccountingWire";
$mk = fn($h) => ["hours"=>$h,"orders"=>0,"svc"=>0,"psvc"=>0,"net"=>0,"adv"=>0,"ded"=>0,"bonus"=>0,"handed"=>0,"carry"=>[]];
$P = ["hour_rate"=>10,"monthly_salary"=>0,"paid_leave_days"=>0,"commission_type"=>"fixed","commission_value"=>0];
$run = function($days, $pilot, $req) use ($W) { $t = $W::monthTotals($days, $pilot, ["settings"=>["hourRate"=>10],"countedDays"=>30,"shiftHours"=>$req]); return $t["payHours"] . "|" . $t["overtimeHours"] . "|" . $t["hourPay"]; };
echo $run([$mk(8)], $P, 8), "\n";
echo $run([$mk(10)], $P, 8), "\n";
echo $run([$mk(6)], $P, 8), "\n";
echo $run([$mk(10), $mk(6)], $P, 8), "\n";
echo $run([$mk(8)], $P + ["required_daily_hours"=>6], 8), "\n";
echo $run([$mk(0)], $P, 8), "\n";
echo $run([$mk(8.5)], $P, 8), "\n";`;
let out;
try { out = execFileSync(PHP_BIN, ['-r', php], { encoding: 'utf8' }).trim().split('\n'); }
catch (e) { console.log('  ✗ تعذّر تشغيل PHP: ' + (e.message || '').slice(0, 120)); process.exit(1); }
const CASES = [
  ['يوم ٨ ساعات والعتبة ٨ — مفيش إضافي', '8|0|80'],
  ['🔴 يوم ١٠ ساعات (٢ إضافي) = ١١ مدفوعة', '11|2|110'],
  ['يوم ٦ ساعات — أقل من العتبة', '6|0|60'],
  ['🔴 يومين ١٠ + ٦ = ١٧ مدفوعة (العتبة يومية مش شهرية)', '17|2|170'],
  ['عتبة الطيار نفسه (٦): يوم ٨ = ٩ مدفوعة', '9|2|90'],
  ['يوم صفر — مفيش أجر ولا إضافي', '0|0|0'],
  ['نص ساعة إضافي = ٤٥ دقيقة مدفوعة', '8.75|0.5|87.5'],
];
CASES.forEach(([label, want], i) => ok(label, out[i] === want, out[i]));

console.log('\n══ ③ الصلاحيات والعرض ══');
ok('🔴 المفاتيح الجديدة على إذن أجر الساعات (مش سايبة تعدّي الفلتر)',
  /'payHours'           => 'mon\.hourPay',/.test(W)
  && /'overtimeHours'      => 'mon\.hourPay',/.test(W)
  && /'requiredDailyHours' => 'mon\.hourPay',/.test(W),
  'filterTotals بتشيل المعروف بس — أي مفتاح مش في الخريطة بيعدّي زي ما هو');
const A = fs.readFileSync('public/accounts.html', 'utf8');
ok('🔴 ملخص الحساب بيشرح الإضافي (وإلا الأجر يبان غلطة حساب)',
  /ساعة إضافية × ١\.٥/.test(A) && /paMoney\(t\.overtimeHours\)/.test(A));
ok('وبيقول الساعات المدفوعة وطول الوردية',
  /paMoney\(t\.payHours\) \} ساعة مدفوعة/.test(A) && /paMoney\(t\.requiredDailyHours\) \} ساعة/.test(A));
ok('وجدولا الطيارين والموظفين بيوروا الإضافي تحت الساعات',
  (A.match(/\+\$\{ paMoney\(t\.overtimeHours\) \} إضافي/g) || []).length === 2,
  'المفروض اتنين — جدول الطيارين وجدول الموظفين');

console.log('\n' + '─'.repeat(52));
console.log(fail === 0 ? `✅ عدّى ${pass} فحص — الساعة الإضافية بساعة ونص\n` : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
