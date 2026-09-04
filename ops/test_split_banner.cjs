/**
 * 🔔 حارس: بانر «طلب جديد وصل للفرع» مايكدبش.
 *
 * ═══ الباج ═══
 * صاحب النظام 2026-08-31: تفريق طرد واحد من أوردر متعدد الطرود كان بيعرض
 * «📦 طلب جديد وصل للفرع!» + صوت، ومفيش شحنة جديدة.
 *
 * السبب: `split` بيعمل صف `orders` **جديد** (مابينقلش الطرد)، والشرط كان
 * بيحكم بالمعرّف بس — أي معرّف جديد = طلب جديد.
 *
 * ═══ الفحص بيقص الدالة من الملف ويشغّلها ═══
 * مش بيحاكيها. اختبار قبل كده كتب المنطق بإيده فعدّى على طفرة كسرت
 * الأصل. هنا `isRealArrival` بتتقص من `branch.html` نفسها وبتتنفّذ على
 * حالات حقيقية — لو حد غيّر شرطها، الفحص بيقع.
 *
 * ═══ الحالة اللي **لازم تفضل** ترن ═══
 * جزء مفصول اتنقل لفرع تاني: `transferBranch` بيعمل UPDATE على `branch_id`
 * — بينقل الصف — فالفرع المستقبِل بيشوف معرّف لأول مرة وأبوه مش عنده.
 * ده وصول حقيقي. أي إصلاح بيخرّس ده أسوأ من الباج.
 *
 * التشغيل: node ops/test_split_banner.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const SRC = fs.readFileSync('public/branch.html', 'utf8');

/* ══ 1) الدالة موجودة ومتندى عليها ══ */
console.log('\n══ 1) التوصيل ══');
const m = /function isRealArrival\(o, idsNow\) \{[\s\S]*?\n    \}/.exec(SRC);
ok('isRealArrival معرّفة في branch.html', !!m);
ok('الشرط بينده عليها مش بيكرّر المنطق', SRC.includes('if (isRealArrival(o, idsNow)) {'));
ok('الشرط القديم (المعرّف بس) اتشال',
   !SRC.includes('if (!window._knownOrderIds.has(o.id) && o.status !== "تم التسليم") {'));
ok('idsNow بتتبني من أوردرات الفرع', SRC.includes('const idsNow = new Set(branchOrders.map(o => o.id));'));
ok('التسجيل في _knownOrderIds لسه بعد الفحص مش قبله',
   SRC.indexOf('if (isRealArrival(o, idsNow))') < SRC.indexOf('branchOrders.forEach(o => window._knownOrderIds.add(o.id));'));
ok('splitFrom لسه في ID_FIELDS — من غيرها بيوصل رقم والمقارنة بتفشل',
   /ID_FIELDS = \[[\s\S]*?"splitFrom"[\s\S]*?\];/.test(SRC));

if (!m) { console.log('\n🔴 مالقيتش الدالة — وقفت\n'); process.exit(1); }

/* ══ 2) تشغيل فعلي للدالة المقصوصة ══ */
console.log('\n══ 2) تشغيل الدالة المقصوصة على حالات حقيقية ══');
let known = new Set();
const window = { _knownOrderIds: { has: id => known.has(id) } };
const isRealArrival = new Function('window', 'return ' + m[0])(window);

const run = (o, ids) => isRealArrival(o, new Set(ids));

/* الحالة المبلّغة: أوردر ٣ طرود، فرّقنا طرد واحد */
known = new Set(['100']);                       // الأصل معروف للفرع
ok('🔴 الباج: الجزء المفصول مايرنش',
   run({ id: '101', splitFrom: '100', status: 'جاري التوصيل' }, ['100', '101']) === false);
ok('والأصل نفسه مايرنش تاني (معروف)',
   run({ id: '100', splitFrom: null, status: 'قيد التنفيذ' }, ['100', '101']) === false);

/* شحنة جديدة حقيقية */
ok('✅ شحنة جديدة ترن',
   run({ id: '200', splitFrom: null, status: 'قيد التنفيذ' }, ['100', '200']) === true);

/* نقل فرع — الصف بينتقل، الفرع المستقبل مايعرفش الأب */
known = new Set(['300']);
ok('✅ أوردر اتنقل لفرعنا يرن',
   run({ id: '400', splitFrom: null, status: 'جاري التوصيل' }, ['300', '400']) === true);
ok('✅ **جزء مفصول** اتنقل لفرعنا يرن — أبوه مش عندنا',
   run({ id: '401', splitFrom: '999', status: 'جاري التوصيل' }, ['300', '401']) === true);

/* الأب والابن في نفس النبضة */
known = new Set();
ok('الأب والابن في نبضة واحدة: الأب يرن',
   run({ id: '500', splitFrom: null, status: 'قيد التنفيذ' }, ['500', '501']) === true);
ok('الأب والابن في نبضة واحدة: الابن ماينرش',
   run({ id: '501', splitFrom: '500', status: 'جاري التوصيل' }, ['500', '501']) === false);

/* المسلَّم */
known = new Set();
ok('أوردر متسلّم ماينرش', run({ id: '600', splitFrom: null, status: 'تم التسليم' }, ['600']) === false);

/* الأنواع — nrm بتحوّل لنص، فالمقارنة نصية */
known = new Set(['700']);
ok('المقارنة نصية زي ما nrm بتسيبها',
   run({ id: '701', splitFrom: '700', status: 'جاري التوصيل' }, ['700', '701']) === false);

console.log('\n' + '─'.repeat(46));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — البانر مابيكدبش\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
