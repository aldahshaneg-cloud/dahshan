/* 🛡 تركيب معالجات كود الخروج في حرّاس PHP الناقصاها.
 *
 * ═══ الثغرة (موثقة في الذاكرة من 2026-08-31 واتأكدت بالتجربة النهارده) ═══
 * حارس بيعمل bootstrap للارافل ويرمي استثناء مش متمسك في نص طريقه:
 * معالج لارافل بيطبع الاستثناء بشكل جميل **ويخرج بصفر** — سطر
 * `exit($fail ? 1 : 0)` في الآخر مابيتنفّذش أصلًا. يعني حارس ميت في
 * نص شغله بيبان «ناجح» لأي حاكم بكود الخروج، وops/guards.sh حاكم
 * بكود الخروج.
 *
 * التجربة: `throw` بعد `->bootstrap()` → كود خروج 0.
 *          `throw` من غير bootstrap → كود خروج 255.
 *
 * ═══ العلاج ═══
 * `set_exception_handler` بيخرج بـ1 + `register_shutdown_function`
 * بيمسك الأخطاء المميتة. **لازم يتركّبوا بعد سطر البوتستراب** — قبله
 * لارافل بيسجّل معالجه فوقهم ويلغيهم.
 *
 * الحرّاس اللي مش بتعمل bootstrap مش محتاجة حاجة (الاستثناء الخام
 * بيخرج 255)، واللي فيها المعالجات أصلًا بتتساب. السكربت بيتحقق من
 * الكل في الذاكرة وبيكتب في الآخر — لو ملف اتعثر مافيش نص شغل يتساب.
 */
const fs = require('fs');

const HANDLERS = `
/* 🔴 من غير الاتنين دول: استثناء مش متمسك بعد البوتستراب بيتطبع بشكل
   جميل وبيخرج بكود 0 — الحارس يبان ناجح وهو مات في نص شغله.
   ولازم يتركّبوا بعد البوتستراب — قبله لارافل بيدوس عليهم. */
set_exception_handler(function (Throwable $e): void {
    echo "\\n🔴 استثناء مش متمسك: " . get_class($e) . ' — ' . $e->getMessage()
        . "\\n   " . $e->getFile() . ':' . $e->getLine() . "\\n";
    exit(1);
});
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        exit(1);
    }
});
`;

const files = fs.readdirSync('ops').filter(f => /^test_.*\.php$/.test(f)).map(f => 'ops/' + f);
const BUF = {};
let add = 0, skipHas = 0, skipNoBoot = 0, bad = 0;

for (const f of files) {
  const s = fs.readFileSync(f, 'utf8');
  if (s.includes('set_exception_handler')) { skipHas++; continue; }
  if (!s.includes('->bootstrap();')) { skipNoBoot++; continue; }

  const marks = s.split('->bootstrap();').length - 1;
  if (marks !== 1) { console.log('  🔴 ' + f + ': البوتستراب ' + marks + ' مرة'); bad++; continue; }

  const i = s.indexOf('->bootstrap();') + '->bootstrap();'.length;
  BUF[f] = s.slice(0, i) + '\n' + HANDLERS + s.slice(i);
  add++;
}

if (bad) { console.log('\n🔴 ' + bad + ' ملفات متعثرة — مالمستش ولا ملف'); process.exit(1); }
Object.keys(BUF).forEach(f => fs.writeFileSync(f, BUF[f]));
console.log('✅ اتركّبت في ' + add + ' حارس · فيها أصلًا: ' + skipHas + ' · بلا bootstrap: ' + skipNoBoot);
