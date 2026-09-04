/**
 * 🗑 اختبار «شاشة أوردر جديد المستقلة اتشالت — من غير ما حاجة تتكسر».
 *
 * فيه كانوا فورمين لنفس الشغل في الكول سنتر: شاشة `page-neworder` من
 * القائمة الجانبية، ومودال «➕ طلب جديد» جوه صفحة الطلبات. صاحب النظام
 * بيستعمل المودال، فالشاشة اتشالت.
 *
 * 🔴 الحقل الوحيد اللي كان في الشاشة ومش في المودال هو «قيمة البضاعة»
 * (`goodsValue`) — اتنقل **قبل** الحذف. من غيره كان الموظف هيفقد قدرته
 * على تسجيله من الكول سنتر من غير ما حد ياخد باله.
 *
 * التشغيل: node ops/test_cc_neworder_removed.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (w, c, g) => { if (c) { pass++; console.log('  ✓ ' + w); }
  else { fail++; console.log('  ✗ ' + w + (g !== undefined ? '   ← ' + g : '')); } };

const S = fs.readFileSync('public/callcenter.html', 'utf8');

console.log('\n══ 1) الشاشة اتشالت بالكامل ══');
ok('مفيش عنصر الصفحة', !/<div class="page" id="page-neworder">/.test(S));
ok('مفيش زرار في القائمة الجانبية', !/navigateTo\('neworder'\)/.test(S));
ok('واتشالت من قايمة الصفحات', !/"neworder","ccsearch"/.test(S));
ok('ومفيش أي ربط في الراوتر', !/page === "neworder"/.test(S));
/* الإشارات الباقية تعليقات بتشرح الحذف — دي مطلوبة مش أثر */
const hits = (S.match(/neworder/g) || []).length;
ok('اللي فاضل تعليقات بس', hits === 2, String(hits) + ' إشارة');

console.log('\n══ 2) 🔴 الحقل اللي كان هيضيع اتنقل ══');
ok('الحقل موجود في المودال', /id="orderGoodsValue"/.test(S));
ok('وبيتبعت للسيرفر', /goodsValue: Number\(document\.getElementById\("orderGoodsValue"\)\?\.value\) \|\| 0/.test(S));
ok('وبيتفضّى بعد الحفظ', /getElementById\("orderGoodsValue"\)\.value = ""/.test(S));
/* النص اللي بيمنع الخلط بين «قيمة البضاعة» و«العهدة» لازم يفضل */
ok('والتوضيح إن الطيار مابيدفعهاش لسه موجود', /الطيار مابيدفعهاش/.test(S));

console.log('\n══ 3) المودال فيه كل حقول الشاشة القديمة ══');
/* المقارنة اتعملت حقل بحقل قبل الحذف */
[['بحث المُرسِل','senderSearchInput'], ['هاتف المُرسِل','orderSenderPhone'],
 ['هاتف احتياطي','orderSenderPhone2'], ['عنوان الاستلام','orderSenderAddress'],
 ['منطقة الاستلام','orderSenderZone'], ['الفرع المسؤول','orderBranchSelect'],
 ['تقييم المُرسِل','senderTrust'], ['الطرود','deliveriesContainer'],
 ['سبب الدفع المقدم','orderStorePrepaidNote'], ['إجمالي العهدة','orderTotalPrepaid'],
 ['ملاحظات','orderNotes'], ['قيمة البضاعة','orderGoodsValue']
].forEach(([اسم, id]) => ok(اسم, S.includes('id="' + id + '"'), id + ' مش موجود'));

console.log('\n══ 4) الصفحات التانية ما اتلمستش ══');
/* ═══ تصحيح نيّة ═══
   أول ما اتشالت الشاشة سِبت `logZoneRequest` ظنًّا إنها بتغذّي «دليل
   المناطق». الفحص على الصفحة الحيّة طلّع إنها **مالهاش أي نداء** —
   الوحيد اللي كان بينده عليها هو `saveOrder` بتاعة الشاشة المحذوفة،
   وجدول `cc_zone_requests` على الإنتاج فيه صفر صف من يوم ما اتعمل.
   فاتشالت هي كمان، ونسختها في ops/edits/cc-zonerequest-removed.js.

   اللي **لازم** يفضل: الاستطلاع اللي بيقرا GET /api/zone-requests
   وبيملا `ZONEREQS`، وصفحة «دليل المناطق» نفسها. */
ok('الكاتب الميّت اتشال', !/function logZoneRequest/.test(S));
ok('🔴 قراية طلبات المناطق لسه شغّالة', /\/api\/zone-requests/.test(S) && /ZONEREQS = d\.items/.test(S));
ok('والنسخة المرجعية محفوظة',
   fs.existsSync('ops/edits/cc-zonerequest-removed.js'));
ok('وصفحة دليل المناطق شغّالة', /renderCCZones/.test(S));
ok('ومودال الطلب لسه كامل', /id="modal-order"/.test(S) && /window\.addOrder = async function/.test(S));

console.log('\n════════════════════════════════════════');
console.log('CC NEWORDER REMOVED: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
