/**
 * 🔍 حارس: البحث في صفحة إدارة المناطق (لوحة الإدارة ← الإعدادات ← المناطق).
 *
 * طلب صاحب النظام 2026-09-21: «في الصفحة دي اعمل بحث علشان أشوف التكويد اللي أنا عايزه وقد أعدّل عليه»
 * — 656 منطقة في جدول من غير أي بحث.
 *
 * العقود (تنفيذ حقيقي لدوال الصفحة جوه vm):
 * • البحث بالاسم بتطبيع عربي (ة/ه · أ/ا · ى/ي · تشكيل · أرقام عربية) وبأكتر من كلمة، وبالفرع وبالسعر.
 * • فلتر الفرع بيتركّب مع النص. البحث الفاضي = الكل.
 * • القايمة الكاملة بتفضل في _zonesData (الاستطلاع بيعيد الرسم والبحث شغال) والعدّاد بيقول «ظاهر X من Y».
 * التشغيل: node ops/test_zones_search.cjs
 */
const fs = require('fs');
const vm = require('vm');
let pass = 0, fail = 0;
const ok = (what, cond, got) => { if (cond) { pass++; console.log('  ✓ ' + what); } else { fail++; console.log('  ✗ ' + what + (got ? '   ← ' + got : '')); } };

const T = fs.readFileSync('public/tiar.html', 'utf8');
ok('خانة البحث وفلتر الفرع وزرار المسح والعدّاد موجودين', T.includes('id="zonesSearch"') && T.includes('id="zonesBranchFilter"') && T.includes('onclick="clearZonesSearch()"') && T.includes('id="zonesSearchCount"'));
ok('الجدول بيعرض المفلتر والقايمة الكاملة محفوظة', T.includes('window._zonesData = zones;\n      _zonesFillBranchFilter(zones);') && T.includes('zones = _zonesFiltered(all);'));
ok('بحث جديد بيرجع لأول صفحة', T.includes('window._pageMul.zones = 1;'));

const a = T.indexOf('    function _zNorm(v) {');
const z = T.indexOf('    function _zonesCount(shown, total) {');
ok('دوال البحث موجودة', a > 0 && z > a);
if (a > 0 && z > a) {
  const els = { zonesSearch: { value: '' }, zonesBranchFilter: { value: '' } };
  const ctx = vm.createContext({ document: { getElementById: id => els[id] || null }, String, Number });
  vm.runInContext(T.slice(a, z) + '\nthis._f = _zonesFiltered; this._n = _zNorm;', ctx);
  const zones = [
    { id: 1, areaName: 'حي الجامعة', deliveryBranchId: 53, deliveryBranchName: 'حي الجامعة', price: 30 },
    { id: 2, areaName: 'أحمد ماهر', deliveryBranchId: 53, deliveryBranchName: 'حي الجامعة', price: 30 },
    { id: 3, areaName: 'شارع الجيش', deliveryBranchId: 55, deliveryBranchName: 'حي شرق ( الجيش )', price: 35 },
    { id: 4, areaName: 'تقسيم السمنودي', deliveryBranchId: 53, deliveryBranchName: 'حي الجامعة', sourceBranchName: 'المدير', price: 45 },
    { id: 5, areaName: '6 اكتوبر', deliveryBranchId: 55, deliveryBranchName: 'حي شرق ( الجيش )', price: 50 },
  ];
  const run = (q, br) => { els.zonesSearch.value = q; els.zonesBranchFilter.value = br || ''; return ctx._f(zones).map(x => x.id).join(','); };
  ok('فاضي = الكل', run('') === '1,2,3,4,5', run(''));
  ok('🔴 «الجامعه» بالهاء بتلاقي «الجامعة»', run('الجامعه') === '1,2,4', run('الجامعه'));
  ok('🔴 «احمد» من غير همزة بتلاقي «أحمد ماهر»', run('احمد') === '2', run('احمد'));
  ok('  أكتر من كلمة (كلها لازم تطابق)', run('شارع الجيش') === '3', run('شارع الجيش'));
  ok('  بالسعر', run('45') === '4', run('45'));
  ok('  بأرقام عربية «٦ اكتوبر»', run('٦ اكتوبر') === '5', run('٦ اكتوبر'));
  ok('  بالفرع المصدر', run('المدير') === '4', run('المدير'));
  ok('🔴 فلتر الفرع لوحده', run('', '55') === '3,5', run('', '55'));
  ok('  وفلتر الفرع مع النص', run('اكتوبر', '55') === '5' && run('اكتوبر', '53') === '', run('اكتوبر', '53'));
  ok('  مفيش مطابق = فاضي', run('مش موجودة') === '');
}

console.log('\n════════════════════════════════════════');
console.log('ZONES SEARCH: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail ? 1 : 0);
