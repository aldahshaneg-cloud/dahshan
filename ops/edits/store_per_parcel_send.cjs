/* بوابة المحل — خمس تعديلات مترابطة على فورم الشحنة.
 *
 * ═══ الطلبات (صاحب النظام 2026-08-31) ═══
 * ① الطرد اللي بصورة ريسيت: الصورة **اختيارية** مش إجبارية.
 * ② المنطقة إجبارية (كانت كده — بتفضل).
 * ③ سعر الطلب (العهدة) **إجباري** — حتى لو صفر، لازم يكتب رقم.
 * ④ زرار إرسال لكل طرد، والزرار الكبير يتشال. كل طرد بيبقى **أوردر
 *    مستقل برقم عادي متسلسل** — مش `004-1` و`004-2`.
 * ⑤ سعر التوصيل مفتوح للتعديل، بس مش أقل من سعر المنطقة.
 *
 * ═══ ليه ④ اتعمل بباراميتر مش بإعادة كتابة ═══
 * `saveOrder` ٢٥٠ سطر فيها حاجات مالهاش علاقة بالطرود: حفظ ملف الاستلام
 * الدائم، وحفظ جهات الاتصال، والتشخيص، ومصيدة الأخطاء المزدوجة. إعادة
 * كتابتها كانت هتخاطر بكل ده قبل الإطلاق بيوم. فبقت بتاخد `onlyRow`:
 * بتصفّي الصفوف على صف واحد، وبعد النجاح بتشيل الصف ده بدل ما تفضّي
 * الفورم. باقي المنطق بالحرف زي ما هو.
 *
 * ═══ الرقم العادي بيجي لوحده ═══
 * `parcelCodeOf` بترجّع `orderNum` من غير شرطة لما الأوردر طرد واحد
 * (`total > 1` شرطها). فطرد لوحده = أوردر لوحده = رقم نضيف. مافيش تعديل
 * على الترقيم خالص — التقسيم هو اللي بيحلّه.
 *
 * ═══ ⑤ الحد الأدنى مفروض على السيرفر كمان ═══
 * `OrdersController::deliveryPrice` بترفض سعر أقل من المنطقة لدور المحل.
 * القيد هنا للراحة، والقيد هناك هو اللي بيحمي الفلوس.
 *
 * 🔒 الحارس: ops/test_store_per_parcel.cjs
 */
const fs = require('fs');
const F = 'public/store.html';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};
const L = (...x) => x.join('\n');
const D = String.fromCharCode(36);

/* ═══════════ ① الصورة بقت اختيارية مع الريسيت ═══════════ */
one(
  L('        /* 🧾 الطرد اللي بصورة ريسيت: مابنطلبش منه اسم ولا تليفون —',
    '           الحقول مخفية أصلًا فمفيش مخرج لو طلبناهم. بس بنطلب صورة',
    '           واحدة على الأقل، لأنها المصدر الوحيد لعنوانه. */',
    '        const isRcpt = window.rowIsReceipt(n);',
    '        if (isRcpt) {',
    '          const hasImg = (window._rowImages[n] || []).some(x => typeof x === "string");',
    '          if (!hasImg) {',
    '            window.showRow?.(n);',
    '            toast(`لازم ترفع صورة الريسيت — طرد #' + D + '{window.parcelDisplayNo(n)}`, "err");',
    '            document.getElementById(`rImgs-' + D + '{n}`)?.scrollIntoView({ behavior: "smooth", block: "center" });',
    '            await _storeDiag({ step: "no-receipt-image", parcel: n });',
    '            return;',
    '          }',
    '        } else {',
    '          if (!name)   { _jumpToParcel(n, `rName-' + D + '{n}`, `يرجى إدخال اسم المستلِم — طرد #' + D + '{window.parcelDisplayNo(n)}`); await _storeDiag({ step: "no-name", parcel: n }); return; }',
    '          if (!phone)  { _jumpToParcel(n, `rPhone-' + D + '{n}`, `يرجى إدخال هاتف المستلِم — طرد #' + D + '{window.parcelDisplayNo(n)}`); await _storeDiag({ step: "no-phone", parcel: n }); return; }',
    '        }'),
  L('        /* 🧾 الطرد اللي بصورة ريسيت: مابنطلبش منه اسم ولا تليفون —',
    '           الحقول مخفية أصلًا فمفيش مخرج لو طلبناهم.',
    '           والصورة **اختيارية** (قرار صاحب النظام 2026-08-31): المحل',
    '           ساعات بيبعت الورقة مع الطيار بدل ما يصوّرها، وإجبارها كان',
    '           بيوقف شحنة سليمة. الفرع بيشوف شارة «العنوان على الريسيت»',
    '           من `fromReceipt` مش من وجود الصورة. */',
    '        const isRcpt = window.rowIsReceipt(n);',
    '        if (!isRcpt) {',
    '          if (!name)   { _jumpToParcel(n, `rName-' + D + '{n}`, `يرجى إدخال اسم المستلِم — طرد #' + D + '{window.parcelDisplayNo(n)}`); await _storeDiag({ step: "no-name", parcel: n }); return; }',
    '          if (!phone)  { _jumpToParcel(n, `rPhone-' + D + '{n}`, `يرجى إدخال هاتف المستلِم — طرد #' + D + '{window.parcelDisplayNo(n)}`); await _storeDiag({ step: "no-phone", parcel: n }); return; }',
    '        }'),
  '① الصورة اختيارية');

/* ═══════════ ①ب علامة الناقص مابقتش تحسب الصورة ═══════════ */
one(
  L('      /* الطرد اللي بصورة ريسيت: مالوش حقول مستلم يتحقق منها، بس',
    '         الصورة بقت إجبارية له — دي المصدر الوحيد لعنوانه. */',
    '      if (window.rowIsReceipt(n)) {',
    '        return !(window._rowImages?.[n] || []).some(x => typeof x === "string");',
    '      }',
    '      return !v("rName-" + n) || !v("rPhone-" + n);'),
  L('      // سعر الطلب إجباري في الحالتين — لازم رقم حتى لو صفر',
    '      if (v("rOrderPrice-" + n) === "") return true;',
    '      /* الطرد اللي بصورة ريسيت: مالوش حقول مستلم يتحقق منها، والصورة',
    '         اختيارية — فالمنطقة وسعر الطلب فوق هما كل اللي عليه. */',
    '      if (window.rowIsReceipt(n)) return false;',
    '      return !v("rName-" + n) || !v("rPhone-" + n);'),
  '①ب علامة الناقص');

/* ═══════════ ③ سعر الطلب إجباري ═══════════ */
one(
  L('        const price   = parseFloat(document.getElementById(`rPrice-' + D + '{n}`)?.value.replace(/[^0-9.]/g, "")) || 0;'),
  L('        const priceEl = document.getElementById(`rPrice-' + D + '{n}`);',
    '        const price   = parseFloat(String(priceEl?.value ?? "").replace(/[^0-9.]/g, "")) || 0;',
    '        const floor   = parseFloat(priceEl?.dataset.floor ?? "0") || 0;'),
  '③أ قراءة السعر والحد الأدنى');

one(
  L('        const orderPrice = parseFloat(document.getElementById(`rOrderPrice-' + D + '{n}`)?.value) || 0;'),
  L('        /* 🔴 سعر الطلب إجباري — حتى لو صفر لازم يكتبه بإيده. الخانة',
    '           الفاضية كانت بتتقرا صفر صامت، والمحل يكتشف إن العهدة ضاعت',
    '           بعد ما الطيار يسلّم. صفر مكتوب = قرار، صفر صامت = سهو. */',
    '        const codRaw     = String(document.getElementById(`rOrderPrice-' + D + '{n}`)?.value ?? "").trim();',
    '        if (codRaw === "") {',
    '          _jumpToParcel(n, `rOrderPrice-' + D + '{n}`, `اكتب سعر الطلب — لو مفيش تحصيل اكتب 0 — طرد #' + D + '{window.parcelDisplayNo(n)}`);',
    '          await _storeDiag({ step: "no-cod", parcel: n });',
    '          return;',
    '        }',
    '        const orderPrice = parseFloat(codRaw) || 0;'),
  '③ب سعر الطلب إجباري');

/* ═══════════ ⑤ التحقق من الحد الأدنى للسعر ═══════════ */
one(
  L('        if (!zoneId) {',
    '          /* النقلة الأول: الطرد ممكن يكون مخفي ورا زراره، والإبراز تحت',
    '             مالوش أي أثر على عنصر جوه `display:none`. */',
    '          window.showRow?.(n);'),
  L('        /* سعر التوصيل مفتوح للزيادة بس مش للنقصان. القيد الحقيقي على',
    '           السيرفر (`OrdersController::deliveryPrice`) — ده للراحة. */',
    '        if (floor > 0 && price < floor - 0.001) {',
    '          _jumpToParcel(n, `rPrice-' + D + '{n}`, `سعر التوصيل لا يقل عن ' + D + '{floor} ج.م — طرد #' + D + '{window.parcelDisplayNo(n)}`);',
    '          await _storeDiag({ step: "price-below-floor", parcel: n, price, floor });',
    '          return;',
    '        }',
    '',
    '        if (!zoneId) {',
    '          /* النقلة الأول: الطرد ممكن يكون مخفي ورا زراره، والإبراز تحت',
    '             مالوش أي أثر على عنصر جوه `display:none`. */',
    '          window.showRow?.(n);'),
  '⑤أ التحقق من السعر');

/* ═══════════ ⑤ب حقل السعر بقى مفتوح ═══════════ */
one(
  '          <input id="rPrice-' + D + '{n}" type="text" readonly class="price-ro" value="—" />',
  L('          <!-- مفتوح للتعديل من 2026-08-31: المحل يقدر يزوّد السعر،',
    '               و`data-floor` بيمسك الحد الأدنى (سعر المنطقة). -->',
    '          <input id="rPrice-' + D + '{n}" type="number" min="0" step="0.5" class="price-ro"',
    '                 data-floor="0" placeholder="—" oninput="onPriceEdit(' + D + '{n})" />'),
  '⑤ب حقل السعر مفتوح');

/* ═══════════ ⑤ج onZone بيحط الحد الأدنى ═══════════ */
one(
  L('    window.onZone = function (n) {',
    '      const sel   = document.getElementById(`rZone-' + D + '{n}`);',
    '      const price = parseFloat(sel.options[sel.selectedIndex]?.dataset.price) || 0;',
    '      const el    = document.getElementById(`rPrice-' + D + '{n}`);',
    '      if (el) el.value = price + " ج.م";',
    '      recalc();',
    '    };'),
  L('    window.onZone = function (n) {',
    '      const sel   = document.getElementById(`rZone-' + D + '{n}`);',
    '      const price = parseFloat(sel.options[sel.selectedIndex]?.dataset.price) || 0;',
    '      const el    = document.getElementById(`rPrice-' + D + '{n}`);',
    '      if (el) {',
    '        /* سعر المنطقة بيبقى **الحد الأدنى والقيمة الافتراضية** مع بعض.',
    '           لو المحل كان زوّده وغيّر المنطقة، بنرجّعه للسعر الجديد — سعر',
    '           منطقة قديمة مالوش معنى على منطقة تانية. */',
    '        el.dataset.floor = String(price);',
    '        el.min = String(price);',
    '        el.value = price ? String(price) : "";',
    '        onPriceEdit(n);',
    '      }',
    '      recalc();',
    '    };',
    '',
    '    /* بيلوّن الخانة لو السعر نزل تحت الحد الأدنى، ويحدّث الإجمالي.',
    '       التلوين فوري عشان المحل يشوف الغلط وهو بيكتب مش عند الإرسال. */',
    '    window.onPriceEdit = function (n) {',
    '      const el = document.getElementById(`rPrice-' + D + '{n}`);',
    '      if (!el) return;',
    '      const floor = parseFloat(el.dataset.floor || "0") || 0;',
    '      const val   = parseFloat(el.value) || 0;',
    '      const bad   = floor > 0 && el.value !== "" && val < floor - 0.001;',
    '      el.style.borderColor = bad ? "#ef4444" : "";',
    '      el.title = floor > 0 ? `أقل سعر للمنطقة دي ' + D + '{floor} ج.م — تقدر تزوّد` : "";',
    '      recalc();',
    '      window.renderParcelTabs?.();',
    '    };'),
  '⑤ج onZone والحد الأدنى');

/* ═══════════ ⑤د recalc بيقرا الرقم مباشرة ═══════════ */
one(
  L('    function recalc() {',
    '      let total = 0;',
    '      for (let i = 1; i <= window._delivCount; i++) {',
    '        const el = document.getElementById(`rPrice-' + D + '{i}`);',
    '        if (!el) continue;',
    '        total += parseFloat(el.value.replace(/[^0-9.]/g, "")) || 0;',
    '      }'),
  L('    function recalc() {',
    '      let total = 0;',
    '      for (let i = 1; i <= window._delivCount; i++) {',
    '        const el = document.getElementById(`rPrice-' + D + '{i}`);',
    '        if (!el) continue;',
    '        // الخانة بقت رقمية — مفيش «ج.م» نشيلها، بس بنسيب التنضيف احتياطًا',
    '        total += parseFloat(String(el.value).replace(/[^0-9.]/g, "")) || 0;',
    '      }'),
  '⑤د recalc');

/* ═══════════ ③ج تسمية سعر الطلب ═══════════ */
one(
  L('          <label>سعر الطلب <span class="req">*</span></label>',
    '          <input type="number" id="rOrderPrice-' + D + '{n}" placeholder="0" min="0" oninput="recalcTotal()" />'),
  L('          <label>سعر الطلب (العهدة) <span class="req">*</span></label>',
    '          <!-- من غير قيمة افتراضية عن قصد: خانة فيها صفر جاهز بتتعدّى',
    '               بالسهو، والصفر لازم يبقى قرار مكتوب. -->',
    '          <input type="number" id="rOrderPrice-' + D + '{n}" placeholder="لو مفيش تحصيل اكتب 0" min="0"',
    '                 oninput="recalcTotal(); window.renderParcelTabs?.()" />'),
  '③ج تسمية سعر الطلب');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ الجزء الأول: الصورة والسعر والعهدة');
