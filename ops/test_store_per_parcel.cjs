/**
 * 📤 حارس: كل طرد أوردر مستقل في بوابة المحل + قواعد الفورم.
 *
 * ═══ الطلبات (صاحب النظام 2026-08-31) ═══
 * ① الطرد اللي بصورة ريسيت: الصورة **اختيارية**.
 * ② المنطقة إجبارية (كانت كده).
 * ③ سعر الطلب (العهدة) **إجباري** — حتى لو صفر لازم يكتب رقم.
 * ④ زرار إرسال لكل طرد، والكبير يتشال. كل طرد = أوردر برقم عادي متسلسل.
 * ⑤ سعر التوصيل: (تحديث 2026-09-03) تعديله خاصية بتتفتح لبعض المحلات من
 *    إدارة المحلات — المفتوح يزوّد أو ينقّص، والمقفول سعر المنطقة إجباري.
 *
 * ═══ الرقم العادي — النقطة اللي الحارس ده موجود عشانها ═══
 * `parcelCodeOf(orderNum, d, total)` بترجّع الرقم من غير شرطة لما
 * `total === 1`. يعني «الرقم العادي» **نتيجة** إن كل طرد بقى أوردر لوحده،
 * مش تعديل في الترقيم. فأي حاجة ترجّع الإرسال المجمّع (زرار كبير، أو
 * `saveOrder` من غير رقم طرد) بترجّع أرقام الشرطة معاها. البند ٤ بيحرس ده.
 *
 * ═══ خانة العهدة الفاضية ═══
 * كانت بتتقرا `|| 0` — صفر صامت. المحل يكتشف إن العهدة ضاعت بعد ما
 * الطيار يسلّم ويرجع من غير فلوس. صفر **مكتوب** = قرار، صفر صامت = سهو.
 *
 * ═══ الحد الأدنى للسعر ═══
 * القيد في الواجهة راحة، والقيد الحقيقي في
 * `OrdersController::deliveryPrice` — لأن `zonePrice` بيتخزّن زي ما بيوصل
 * وهو **فلوس**. البند ٦ بيتأكد إن الاتنين موجودين.
 *
 * التشغيل: node ops/test_store_per_parcel.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const UI  = fs.readFileSync('public/store.html', 'utf8');
const PHP = fs.readFileSync('app/Http/Controllers/Api/OrdersController.php', 'utf8');
const loop = /const deliveries = \[\];[\s\S]*?\n      \}/.exec(UI)?.[0] || '';

/* ══ 1) الصورة اختيارية مع الريسيت ══ */
console.log('\n══ 1) صورة الريسيت اختيارية ══');
ok('حلقة التحقق اتقصّت', loop.length > 0);
ok('مافيش فحص بيطلب صورة عند الإرسال',
   !/no-receipt-image/.test(UI) && !/لازم ترفع صورة الريسيت/.test(UI),
   'لسه بيطلبها');
ok('والشرط بقى «لو مش ريسيت» بس', /const isRcpt = window\.rowIsReceipt\(n\);\n        if \(!isRcpt\) \{/.test(loop));
ok('وعلامة الناقص مابتحسبش الصورة',
   !/rowIsReceipt\(n\)\) \{\s*\n\s*return !\(window\._rowImages/.test(UI));
ok('الطرد اللي بريسيت خالص من فحوص المستلم',
   /if \(window\.rowIsReceipt\(n\)\) return false;/.test(UI));

/* ══ 2) المنطقة ══ */
console.log('\n══ 2) المنطقة ══');
ok('لسه إجبارية عند الإرسال', /if \(!zoneId\) \{/.test(loop));
ok('ولسه أول شرط في علامة الناقص', /if \(!v\("rZone-" \+ n\)\) return true;/.test(UI));

/* ══ 3) سعر الطلب إجباري ══ */
console.log('\n══ 3) سعر الطلب (العهدة) ══');
ok('الخانة الفاضية بتوقف الإرسال', /if \(codRaw === ""\) \{/.test(loop));
ok('والرسالة بتقول يكتب صفر لو مفيش تحصيل', /لو مفيش تحصيل اكتب 0/.test(loop));
ok('وبتنقل الشاشة للطرد', /_jumpToParcel\(n, `rOrderPrice-\$\{n\}`/.test(loop));
ok('🔴 والصفر المكتوب بيعدّي — مش بيترفض',
   /const orderPrice = parseFloat\(codRaw\) \|\| 0;/.test(loop) && !/codRaw === "0"/.test(loop));
ok('وعلامة الناقص بتشوفها', /if \(v\("rOrderPrice-" \+ n\) === ""\) return true;/.test(UI));
ok('ومفيش قيمة افتراضية في الخانة — الصفر لازم يبقى قرار',
   /id="rOrderPrice-\$\{n\}" placeholder="لو مفيش تحصيل اكتب 0"/.test(UI)
   && !/id="rOrderPrice-\$\{n\}"[^>]*value="0"/.test(UI));

/* ══ 4) إرسال لكل طرد + الرقم العادي ══ */
console.log('\n══ 4) كل طرد أوردر مستقل ══');
ok('زرار إرسال جوه كل طرد', /id="sendBtn-\$\{n\}"[\s\S]{0,80}onclick="saveOrder\(\$\{n\}\)"/.test(UI));
ok('🔴 الزرار الكبير اتشال', !/id="saveBtn"/.test(UI), 'لسه موجود — هيرجّع أرقام الشرطة');
ok('saveOrder بتاخد رقم الطرد', /window\.saveOrder = async function \(onlyRow\)/.test(UI));
ok('وبتصفّي على صف واحد', /const rows     = onlyRow\s*\n\s*\? \[document\.getElementById\(`row-\$\{onlyRow\}`\)\]\.filter\(Boolean\)/.test(UI));
ok('والزرار اللي بيتقفل هو زرار الطرد', /sendBtn-\$\{onlyRow\}` : "saveBtn"/.test(UI));
ok('وبعد النجاح الطرد بيخرج مش الفورم بيتفضّى',
   /if \(onlyRow\) removeSentRow\(onlyRow\); else resetForm\(\);/.test(UI));
ok('removeSentRow موجودة', /window\.removeSentRow = function \(n\)/.test(UI));
ok('وبتفضّي بالكامل لو ده آخر طرد', /if \(rows\.length <= 1\) \{[\s\S]{0,300}resetForm\(\);/.test(UI));
ok('🔴 والترقيم بيفضل من غير شرطة لأن الأوردر طرد واحد',
   /return \(total > 1 && no\) \? `\$\{orderNum\}-\$\{no\}` : orderNum;/.test(UI),
   'parcelCodeOf اتغيّرت — الرقم ممكن يرجع بشرطة');

/* ══ 5) سعر التوصيل — الواجهة ══
   (عقد 2026-09-03: التعديل خاصية بتتفتح لبعض المحلات — المفتوح يزوّد أو
   ينقّص، والمقفول الخانة readonly بسعر المنطقة) */
console.log('\n══ 5) سعر التوصيل (الواجهة) ══');
ok('الخانة رقمية وreadonly مشروطة بصلاحية المحل',
   /id="rPrice-\$\{n\}" type="number"/.test(UI)
   && /window\.storeCanEditPrice\(\) \? "" : 'readonly/.test(UI));
ok('وسعر المنطقة بيفضل متسجّل كمرجع', /el\.dataset\.floor = String\(price\);/.test(UI));
ok('وكقيمة افتراضية', /el\.value = price \? String\(price\) : "";/.test(UI));
ok('وتغيير المنطقة بيرجّع السعر الجديد — مش بيسيب القديم',
   /el\.dataset\.floor = String\(price\);[\s\S]{0,200}el\.value = price/.test(UI));
ok('🔴 والـmin بيتفك للمفتوح له (ينزل تحت سعر المنطقة)',
   /el\.min = window\.storeCanEditPrice\(\) \? "0" : String\(price\);/.test(UI));
ok('التلوين فوري وانت بتكتب', /window\.onPriceEdit = function \(n\)/.test(UI)
   && /oninput="onPriceEdit\(\$\{n\}\)"/.test(UI));
ok('🔴 الإرسال بيوقف على السالب بس — مافيش أرضية سعر المنطقة',
   /if \(price < 0\) \{/.test(loop) && !/price < floor - 0\.001/.test(loop), 'لسه فيه أرضية');
ok('الصلاحية بتتطبّق على الخانات بعد تحميل ملف الاستلام',
   /window\.applyPriceEditPerm = function/.test(UI) && /window\.applyPriceEditPerm\?\.\(\)/.test(UI));

/* ══ 6) سعر التوصيل — السيرفر (اللي بيحمي الفلوس فعلًا) ══ */
console.log('\n══ 6) سعر التوصيل (السيرفر) ══');
ok('deliveryPrice موجودة', /private static function deliveryPrice\(Actor \$actor, array \$d, array \$zone, int \$no\): float/.test(PHP));
ok('والإدراج بينده عليها', /'zone_price'\s*=> self::deliveryPrice\(\$actor, \$d, \$zone, \$no\),/.test(PHP));
ok('🔴 ومافيش مسار تاني بياخد zonePrice خام',
   !/'zone_price'\s*=> isset\(\$d\['zonePrice'\]\)/.test(PHP), 'لسه فيه مسار بيقبل أي سعر');
ok('🔴 المحل المقفول بياخد سعر المنطقة مهما بعت',
   /if \(! self::storeCanEditPrice\(\(int\) \$actor->userId\)\) \{\s*\n\s*return \$floor;/.test(PHP));
ok('والمفتوح له: السالب بس مرفوض', /if \(\$sent < 0\) \{/.test(PHP));
ok('🔴 ومافيش أرضية سعر المنطقة للمحل بعد الخاصية', !/\$sent < \$floor - 0\.001/.test(PHP), 'الأرضية القديمة لسه موجودة');
ok('والفرع والإدارة مش متقيّدين', /\$actor->role === 'store'/.test(PHP));
ok('وبتسيب الأعلى والأقل يعدّوا', /return \$sent;/.test(PHP));
ok('ومن غير سعر بترجع سعر المنطقة', /if \(\$sent === null\) \{\s*\n\s*return \$floor;/.test(PHP));
ok('الصلاحية بتتقرا من القاعدة كل مرة — مش static cache',
   /function storeCanEditPrice\(int \$userId\): bool/.test(PHP) && !/static \$cache/.test(PHP));

/* ══ 7) تشغيل فعلي: قصّ onPriceEdit ══ */
console.log('\n══ 7) تشغيل onPriceEdit المقصوصة ══');
{
  const m = /window\.onPriceEdit = function \(n\)[\s\S]*?\n    \};/.exec(UI)?.[0];
  ok('اتقصّت', !!m);
  if (m) {
    const el = { dataset: {}, style: {}, value: '' };
    const doc = { getElementById: () => el };
    const win = { renderParcelTabs: () => {}, storeCanEditPrice: () => true };
    const fn = new Function('document', 'window', 'recalc',
      'return ' + m.replace(/^window\.onPriceEdit = /, ''))(doc, win, () => {});
    const t = (floor, val) => { el.dataset.floor = String(floor); el.value = val; el.style = {}; fn(1); return el.style.borderColor; };
    ok('أقل من سعر المنطقة → عادي (مسموح للمفتوح له)', t(25, '20') === '');
    ok('سالب → أحمر', t(25, '-3') === '#ef4444');
    ok('أعلى → عادي', t(25, '40') === '');
    ok('فاضي → عادي (رسالة الإرسال بتتكفّل)', t(25, '') === '');
  }
}

console.log('\n' + '─'.repeat(50));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — كل طرد أوردر مستقل وقواعد الفورم سليمة\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
