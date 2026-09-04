/**
 * 📋 حارس: جدول حركات الخزن تحت جدول الخزن في لوحة الإدارة.
 *
 * ═══ الطلب (صاحب النظام 2026-09-01) ═══
 * «في الخزنة في الإدارة أريد تحت الخزنة الجدول بالمعاملات اللي بتحصل
 * في الفروع».
 *
 * ═══ البيانات كانت محمّلة وبتترمي ═══
 * `refreshCashTxns` بتجيب حركات كل خزنة وبتحطّها في `_cashTxnsData`،
 * وكانت بتتستعمل في عمود «آخر عملية» بس. الجدول بيعرضها — مافيش نداء
 * جديد ولا مسار جديد.
 *
 * ═══ الحاجات اللي بتغلط في جدول فلوس ═══
 * ① الترتيب: الأحدث لازم يبقى فوق. جدول فلوس مرتّب غلط بيخلّي المشرف
 *    يقرا حركة قديمة على إنها الأخيرة.
 * ② التوقيت: `toWire` بيبعت UTC بـ`Z`. عرض بلا تحويل بيغلط ٣ ساعات —
 *    ودي لسعة موثّقة في المشروع.
 * ③ السقف: يوم شغل ممكن يطلّع مئات الحركات. العرض الكامل بيبطّئ الصفحة،
 *    والقص من غير ما العدد الكامل يبان بيخلّي المشرف يفتكر إن دول كل
 *    الحركات — وده أسوأ من البطء.
 * ④ الموضع: الجدول لازم يبقى جوه `page-treasury` مش في صفحة تانية.
 *
 * التشغيل: node ops/test_treasury_txns.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const UI = fs.readFileSync('public/tiar.html', 'utf8');

/* ══ 1) الموضع ══ */
console.log('\n══ 1) الموضع ══');
const iT = UI.indexOf('id="page-treasury"');
const iR = UI.indexOf('id="page-reports"');
const page = iT >= 0 && iR > iT ? UI.slice(iT, iR) : '';
ok('صفحة الخزنة اتقصّت', page.length > 0);
ok('🔴 الجدول جوه صفحة الخزنة', page.includes('id="cashTxnsBody"'),
   'اتحط في صفحة تانية');
ok('وزرار «عرض المزيد» جواها', page.includes('id="txnMoreBtn"'));
ok('وتحت جدول الخزن مش فوقه',
   page.indexOf('id="branchTreasuryBody"') < page.indexOf('id="cashTxnsBody"'));
ok('وصفحة التقارير مااتلمستش', UI.slice(iR, iR + 500).includes('📊 التقارير'));

/* ══ 2) الأعمدة والفلاتر ══ */
console.log('\n══ 2) الأعمدة والفلاتر ══');
['التاريخ', 'الخزنة', 'الفرع', 'النوع', 'المبلغ', 'السبب', 'بواسطة']
  .forEach(h => ok('عمود ' + h, new RegExp('<th[^>]*>' + h + '</th>').test(page)));
ok('فلتر الخزن', page.includes('id="txnStoreFilter"'));
ok('فلتر النوع بأنواعه التلاتة',
   /id="txnTypeFilter"[\s\S]{0,320}value="in"[\s\S]{0,160}value="out"[\s\S]{0,160}value="pending"/.test(page));
ok('والاتنين بيعيدوا الرسم', (page.match(/onchange="renderCashTxns\(\)"/g) || []).length === 2);
ok('وعدّاد الحركات', page.includes('id="txnCount"'));

/* ══ 3) الرسم ══ */
console.log('\n══ 3) الرسم ══');
const cut = (start) => {
  const i = UI.indexOf(start);
  if (i < 0) return '';
  const j = UI.indexOf('\n    };', i);
  return j < 0 ? '' : UI.slice(i, j);
};
const fn = cut('window.renderCashTxns = function');
ok('renderCashTxns اتقصّت', fn.length > 0);
ok('بتقرا من _cashTxnsData — مافيش نداء جديد',
   /window\._cashTxnsData   \|\| \[\]/.test(fn) && !/api\.get/.test(fn),
   'بينده السيرفر من جوه الرسم');
ok('🔴 الأحدث فوق',
   /\.sort\(\(a, b\) => String\(b\.createdAt \|\| ""\)\.localeCompare\(String\(a\.createdAt \|\| ""\)\)\)/.test(fn),
   'الترتيب مقلوب — المشرف هيقرا حركة قديمة على إنها الأخيرة');
ok('🔴 والوقت بيتحوّل للمحلي',
   /new Date\(t\.createdAt\)\.toLocaleString\("ar-EG"\)/.test(fn),
   'العرض الخام بيغلط ٣ ساعات');
ok('الفلترة بالخزنة والنوع',
   /!fStore \|\| String\(t\.storeId\) === String\(fStore\)/.test(fn) && /!fType  \|\| t\.type === fType/.test(fn));
ok('واسم الخزنة بيتجاب من قايمة الخزن',
   /stores\.find\(x => String\(x\.id\) === String\(t\.storeId\)\)/.test(fn));
ok('والخزنة بلا فرع بتتقال «الإدارة» — زي جدول الخزن فوق',
   /st && !st\.branchId/.test(fn) && /🏛️ الإدارة/.test(fn));
ok('🔴 وكل نص بيعدّي على esc',
   (fn.match(/esc\(/g) || []).length >= 6, String((fn.match(/esc\(/g) || []).length) + ' نداء');
ok('وحالة الفراغ بتفرّق بين «مافيش حركات» و«مافيش بالفلتر»',
   /fStore \|\| fType \? " بالفلتر ده" : " لسه"/.test(fn));

/* ══ 4) السقف ══ */
console.log('\n══ 4) السقف ══');
ok('سقف افتراضي', /window\._txnLimit = 50;/.test(UI));
ok('وزرار بيزوّده', /window\.txnShowMore = function\(\) \{[\s\S]{0,120}_txnLimit \+= 50/.test(UI));
/* 🔴 الفحوص كانت بتتأكد إن السقف **معرّف** بس، مش إنه **مطبّق**. طفرة
   شالت `.slice()` وعدّت: المتغيّر موجود والزرار موجود والجدول بيرسم كل
   الصفوف. الفحص ده على القصّ نفسه. */
ok('🔴 والسقف مطبّق فعلًا على الصفوف المرسومة',
   /const shown = rows\.slice\(0, window\._txnLimit\);/.test(fn),
   'السقف معرّف بس مش بيتقص بيه');
ok('والمرسوم هو المقصوص مش الكل',
   /body\.innerHTML = shown\.map\(/.test(fn), 'بيرسم rows بدل shown');
ok('🔴 والعدد الكامل بيبان — مش بس المعروض',
   /cnt\.textContent = rows\.length \? .{0,40}rows\.length\} حركة/.test(fn),
   'المشرف هيفتكر إن دول كل الحركات');
ok('والزرار بيقول الفاضل كام', /left\} فاضلين/.test(fn));
ok('وبيتخفي لما مايبقاش فيه فاضل', /mb\.style\.display = left > 0 \? "" : "none";/.test(fn));

/* ══ 5) الوصل ══ */
console.log('\n══ 5) الوصل بالتحديث ══');
ok('بيترسم مع كل تحديث للخزن',
   /branchTreasuryTotal[\s\S]{0,220}window\.renderCashTxns\(\);/.test(UI));

console.log('\n' + '─'.repeat(50));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — جدول الحركات تحت الخزن\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
