/**
 * 💬 حارس: روابط واتساب في الموقع العام لازم تكون بصيغة دولية.
 *
 * ═══ الواقعة (2026-09-02) ═══
 * أرقام الدعم متخزّنة في الإعدادات بالشكل المحلي `01040065651`، وكل
 * صفحات الموقع كانت بتلزق الرقم في `wa.me/` زي ما هو. واتساب بيقرا
 * `wa.me/01040065651` على إنه كود دولة غلط وبيفتح «رقم غير صالح» —
 * يعني **كل** أزرار الواتساب في الموقع كانت ميتة (زرار الفوتر، الزرار
 * العايم، أزرار عرض السعر)، والزبون اللي بيضغط بيستنتج إن الشركة مش
 * بتردّ. اتكشفت لما صاحب النظام جرّب الزرار في صفحة التواصل.
 *
 * ═══ العقد ═══
 * التحويل بيتعمل في مكان واحد: `SupportNums.intl` جوّه
 * `assets/js/support-numbers.js`. أي صفحة بتبني رابط wa.me بإيدها من
 * غير ما تعدّي على الدالة دي = الباج رجع.
 *
 * التشغيل: node ops/test_whatsapp_links.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

console.log('\n══ 1) دالة التحويل — تنفيذ فعلي ══');
const SRC = fs.readFileSync('public/assets/js/support-numbers.js', 'utf8');
// الملف IIFE بياخد global — بنديله كائن وهمي ونسحب منه SupportNums
const sandbox = {};
new Function('window', SRC)(sandbox);
const SN = sandbox.SupportNums;
ok('SupportNums اتحمّلت وفيها intl', !!(SN && typeof SN.intl === 'function'));

const CASES = [
  ['01040065651',   '201040065651', 'موبايل محلي — ده الشكل المتخزّن فعلًا في الإعدادات'],
  ['201040065651',  '201040065651', 'دولي جاهز — مايتلمسش'],
  ['+20 104 006 5651', '201040065651', 'دولي بمسافات وعلامة +'],
  ['0020104006565',  '20104006565',  'بادئة 00 بتتشال'],
  ['1040065651',    '201040065651', 'من غير صفر — بيتحط 20'],
  ['0502345678',    '20502345678',  'أرضي محلي'],
  ['',              '',             'فاضي بيرجّع فاضي — المنادي بيخفي الزرار'],
  ['—',             '',             'نص مش رقم بيرجّع فاضي'],
];
const bad = CASES.filter(([i, o]) => SN.intl(i) !== o)
  .map(([i, o, why]) => `«${i}» (${why}) → «${SN.intl(i)}» المفروض «${o}»`);
ok('🔴 كل حالات التحويل ماشية صح', bad.length === 0, bad.join(' · '));

ok('وwaLink بيبني رابط كامل', SN.waLink('01040065651') === 'https://wa.me/201040065651');
ok('وبيرجّع فاضي لو مفيش رقم — مايبنيش رابط مكسور', SN.waLink('') === '');

console.log('\n══ 2) صفحات الموقع بتعدّي على الدالة ══');
const PAGES = ['index', 'about', 'faq', 'pricing', 'profile', 'services', 'contact'];
for (const p of PAGES) {
  const t = fs.readFileSync(`public/${p}.html`, 'utf8');

  /* التعبير الواحد ممكن يتكتب على أكتر من سطر (نص + رقم + نص)، والتحويل
     بيبقى على السطر التاني — ففحص سطر-بسطر بيطلع إنذار كاذب. بندمج أي
     سطر بينتهي بـ`+` مع اللي بعده قبل الفحص. */
  const merged = [];
  for (const line of t.split('\n')) {
    if (merged.length && /\+\s*$/.test(merged[merged.length - 1])) {
      merged[merged.length - 1] += ' ' + line.trim();
    } else {
      merged.push(line);
    }
  }
  const raw = merged.filter(l => /wa\.me\//.test(l)
    && !/SupportNums\.intl|SupportNums\.waLink/.test(l)
    && !/^\s*(\/\/|\*|<!--)/.test(l)          // تعليقات
    && /['"`]\s*\+|\$\{/.test(l));            // بناء برمجي فعلًا مش نص ثابت
  ok(`${p}.html — مفيش رابط wa.me متبني بالإيد`, raw.length === 0, raw.map(s => s.trim()).join(' | '));

  if (/wa\.me\//.test(t)) {
    ok(`${p}.html — بيحمّل support-numbers.js`, t.includes('assets/js/support-numbers.js'),
      'الدالة مش متحمّلة = SupportNums undefined وقت التشغيل');
  }
}

console.log('\n══ 3) الرقم المخزّن نفسه ══');
// لو الإعدادات اتحفظت يوم ما بصيغة دولية، التحويل لازم يفضل صحيح كمان
ok('التحويل ثابت لو اتنده مرتين (idempotent)',
  SN.intl(SN.intl('01040065651')) === '201040065651');

console.log('\n' + '─'.repeat(52));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — أزرار الواتساب بتفتح محادثة فعلًا\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
