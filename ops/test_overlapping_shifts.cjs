/**
 * 🔗 حارس: ورديتين متداخلتين في نفس اليوم — الساعات اتحاد مش مجموع.
 *
 * ═══ البلاغ (صاحب النظام 2026-09-11) ═══
 * «الحسابات بتاعة الساعة مش مظبوطة — راجع الفرع التجريبي». وكان صح:
 * الطيار كان موجود من ١٢:٥٥ لـ٠١:٠٠ (١٢ ساعة) والشيت بيقول **١٦٫٠٧**،
 * والإذن الواحد مكرّر حرفيًا مرتين في نفس اليوم (٠٠:١٨→٠٠:٢٧ مرتين).
 *
 * السبب: الطيار ممكن يكون عليه أكتر من وردية في نفس اليوم **ومتداخلين**
 * (نقل بين فروع، أو وردية اتفتحت والقديمة ماتقفلتش). والكود كان:
 *   • بيجمع مدد الورديات جمع عادي ⇐ الوقت المشترك بيتعدّ مرتين.
 *   • وبيقصّ الإذن على **كل وردية على حدة** وبيضيفه للقايمة ⇐ الإذن
 *     اللي جوه الاتنين بيتخصم مرتين.
 *
 * الحل: **اتحاد** الفترات مش مجموعها — للشغل وللإذن.
 *
 * ⚠️ والخصم نفسه فضل مكانه الوحيد `dayRow` — لو اتخصم هنا كمان بيبقى مرتين.
 *
 * المناورة الحيّة: php ops/drill_overlap_hours.php
 * التشغيل: node ops/test_overlapping_shifts.cjs
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
ok('دالة الاتحاد موجودة', /public static function mergeIntervals\(array \$iv\): array/.test(W));
ok('وبتدمج المتداخل والملاصق', /if \(\$a <= \$out\[\$last\]\[1\]\) \{[\s\S]{0,120}max\(\$out\[\$last\]\[1\], \$b\)/.test(W));
ok('وspanSeconds بتجمع المدموج', /public static function spanSeconds\(array \$merged\): int/.test(W));
ok('🔴 والساعات من اتحاد فترات الشغل مش من جمعها',
  /\$spans = W::mergeIntervals\(\$c\['_spans'\] \?\? \[\]\);[\s\S]{0,120}round\(W::spanSeconds\(\$spans\) \/ 3600, 2\)/.test(C),
  'الجمع المباشر بيعدّ الوقت المشترك مرتين');
ok('🔴 والإذن من اتحاد فتراته (مش مضاف لكل وردية)',
  /W::mergeIntervals\(\$c\['_permTs'\] \?\? \[\]\)/.test(C));
ok('ومقصوص على وقت الشغل الفعلي',
  /\$x = max\(\$pa, \$sa\);[\s\S]{0,80}\$y = min\(\$pb, \$sb\);/.test(C));
ok('وبيتحوّل للعرض بعد الدمج',
  /'out' => \$hmOf\(\$p\[0\]\), 'in' => \$hmOf\(\$p\[1\]\)\][\s\S]{0,80}W::mergeIntervals\(\$clipped\)/.test(C));
ok('والحقول الداخلية بتتشال من الرد', /unset\(\$m\[\$pid\]\[\$day\]\['_inTs'\][\s\S]{0,120}\['_spans'\], \$m\[\$pid\]\[\$day\]\['_permTs'\]\)/.test(C));
ok('⚠️ والخصم لسه مكانه dayRow بس',
  /\$mins  = \(\(float\) \$auto\['hours'\]\) \* 60 - self::permMinutes\(\$perms\);/.test(W)
  && /الساعات هنا \*\*قبل\*\* خصم الاستئذان عن قصد/.test(C));
ok('والوردية الطويلة لسه بتتقصّ عند ساعات الوردية',
  /\$wEnd = min\(\$w1, \$w0 \+ \(int\) \(\$shiftHours \* 3600\)\);/.test(C));

console.log('\n══ ② تنفيذ فعلي (PHP) ══');
const php = `require "vendor/autoload.php";
$W = "App" . chr(92) . "Wire" . chr(92) . "PilotAccountingWire";
$j = function($x) { return json_encode($x); };
echo $j($W::mergeIntervals([[0,10],[5,20]])), "\n";
echo $j($W::mergeIntervals([[0,10],[20,30]])), "\n";
echo $j($W::mergeIntervals([[0,10],[10,20]])), "\n";
echo $j($W::mergeIntervals([[5,20],[0,10]])), "\n";
echo $j($W::mergeIntervals([[0,30],[5,10]])), "\n";
echo $j($W::mergeIntervals([[3,3],[0,5]])), "\n";
echo $j($W::mergeIntervals([])), "\n";
echo $W::spanSeconds($W::mergeIntervals([[0,3600],[1800,7200]])), "\n";`;
let out;
try { out = execFileSync(PHP_BIN, ['-r', php], { encoding: 'utf8' }).trim().split('\n'); }
catch (e) { console.log('  ✗ تعذّر تشغيل PHP: ' + (e.message || '').slice(0, 140)); process.exit(1); }
const CASES = [
  ['🔴 متداخلتين → واحدة', '[[0,20]]'],
  ['منفصلتين → زي ما هما', '[[0,10],[20,30]]'],
  ['ملاصقتين → واحدة', '[[0,20]]'],
  ['مش مرتّبة → بتترتّب', '[[0,20]]'],
  ['واحدة جوه التانية → الكبيرة', '[[0,30]]'],
  ['الفاضية بتتشال', '[[0,5]]'],
  ['مفيش حاجة', '[]'],
  ['🔴 ساعة + ساعة متداخلين نص = ٧٢٠٠ ثانية مش ١٠٨٠٠', '7200'],
];
CASES.forEach(([label, want], i) => ok(label, out[i] === want, out[i]));

console.log('\n══ ③ كارت الوردية بيقول وقت الإذن ══');
for (const f of ['public/branch.html', 'public/tiar.html']) {
  const S = fs.readFileSync(f, 'utf8');
  const p = f.replace('public/', '');
  ok(`${p}: سطر «وقت الإذن» موجود`, /id="_srPermRow"[\s\S]{0,160}وقت الإذن/.test(S));
  ok(`${p}: وسطر «صافي ساعات العمل»`, /id="_srNetRow"[\s\S]{0,180}صافي ساعات العمل/.test(S));
  ok(`${p}: 🔴 والأذونات بتتدمج قبل الجمع (المتداخل مايتعدّش مرتين)`,
    /if \(last && a <= last\[1\]\) last\[1\] = Math\.max\(last\[1\], b\);/.test(S));
  ok(`${p}: 🔴 ومقصوصة على مدة الوردية`,
    /const x = Math\.max\(a, _w\.s\), y = Math\.min\(b, _w\.e\);/.test(S));
  ok(`${p}: والإذن الساري بيتحسب لحد نهاية الوردية`, /const b = lv\.to \? Date\.parse\(lv\.to\) : _w\.e;/.test(S));
  ok(`${p}: والصافي = المدة − الإذن`, /const netMs  = Math\.max\(0, \(Number\(_w\.durationMs\) \|\| 0\) - permMs\);/.test(S));
}

console.log('\n' + '─'.repeat(52));
console.log(fail === 0 ? `✅ عدّى ${pass} فحص — الوقت المشترك مابيتعدّش مرتين\n` : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
