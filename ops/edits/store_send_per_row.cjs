/* زرار إرسال لكل طرد — وكل طرد بيبقى أوردر مستقل برقم عادي.
 *
 * ═══ الطلب (صاحب النظام 2026-08-31) ═══
 * «أريد زرار إرسال لكل طرد، وفي هذه الحالة اجعل رقم الطرد رقم عادي —
 * ليس بالضرورة واحد شرطة واحد ثم واحد شرطة 2 — تسلسل عادي كأنه أوردر
 * وراء أوردر وراء أوردر». والزرار الكبير يتشال.
 *
 * ═══ الرقم العادي بييجي لوحده ═══
 * `parcelCodeOf(orderNum, d, total)` بترجّع `orderNum` من غير شرطة لما
 * `total === 1`. فطرد لوحده = أوردر لوحده = رقم نضيف. **مافيش تعديل على
 * الترقيم خالص** — التقسيم لأوردرات هو اللي بيحلّه.
 *
 * ═══ ليه باراميتر مش إعادة كتابة ═══
 * `saveOrder` ٢٥٠ سطر، وفيها حاجات مالهاش علاقة بالطرود: حفظ ملف الاستلام
 * الدائم (وفيه لسعة موثّقة إن إرسال `lat:null` بيمسح موقع المحل)، وحفظ
 * جهات الاتصال، والتشخيص، ومصيدة أخطاء مزدوجة. إعادة كتابتها قبل الإطلاق
 * بيوم = مخاطرة بكل ده. فبقت بتاخد `onlyRow`:
 *   • بتصفّي الصفوف على صف واحد
 *   • بعد النجاح بتشيل الصف ده بدل ما تفضّي الفورم
 * وباقي المنطق بالحرف زي ما هو.
 *
 * ═══ الزرار العالق ═══
 * الكود القديم بيرجّع `saveBtn` لحالته في تلات مواضع (نجاح، فشل داخلي،
 * فشل خارجي) — والتعليق هناك بيقول ليه: «الزر بيفضل عالق على جاري
 * الإرسال فالمحل يفتكر إن مفيش حاجة حصلت ويضغط تاني فيتبعت الطلب مرتين».
 * بقى نفس المنطق بس على زرار الطرد.
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

/* ═══ ① زرار الإرسال جوه كل طرد ═══ */
one(
  L('          <div class="img-strip" id="rImgs-' + D + '{n}"></div>',
    '        </div>`;'),
  L('          <div class="img-strip" id="rImgs-' + D + '{n}"></div>',
    '        </div>',
    '        <!-- إرسال الطرد ده لوحده. كل طرد بيبقى أوردر مستقل برقمه',
    '             المتسلسل — مافيش أرقام بشرطة. -->',
    '        <button type="button" class="row-send" id="sendBtn-' + D + '{n}"',
    '                onclick="saveOrder(' + D + '{n})">📤 إرسال الطرد</button>`;'),
  '① زرار الإرسال في القالب');

/* ═══ ② ستايل الزرار ═══ */
one(
  '    .price-ro { color: var(--green) !important; font-weight: 700; text-align: center; }',
  L('    .price-ro { color: var(--green) !important; font-weight: 700; text-align: center; }',
    '    /* زرار إرسال الطرد — نفس وزن الزرار الكبير القديم عشان يبان إنه',
    '       الإجراء الأساسي، بس جوه الكارت. */',
    '    .row-send {',
    '      width: 100%; margin-top: 14px; padding: 12px;',
    '      background: var(--sky); color: #fff; border: none;',
    '      border-radius: 10px; font-size: .92rem; font-weight: 800;',
    '      cursor: pointer; font-family: inherit;',
    '    }',
    '    .row-send:disabled { opacity: .6; cursor: default; }'),
  '② الستايل');

/* ═══ ③ الزرار الكبير اتشال ═══ */
one(
  '    <button class="submit-btn" id="saveBtn" onclick="saveOrder()">📤 إرسال الطلب</button>',
  L('    <!-- زرار «إرسال الطلب» الكبير اتشال 2026-08-31: كل طرد بقى ليه',
    '         زرار إرساله جواه، وبيتبعت كأوردر مستقل برقم عادي. وجود',
    '         الاتنين كان هيدّي طريقتين للإرسال بترقيم مختلف. -->'),
  '③ شيل الزرار الكبير');

/* ═══ ④ saveOrder بتاخد رقم الطرد ═══ */
one(
  '    window.saveOrder = async function () {',
  L('    /**',
    '     * بتبعت **طرد واحد** كأوردر مستقل. `onlyRow` هو رقمه الداخلي.',
    '     *',
    '     * الباراميتر اختياري عشان لو اتندهت من غيره (كود قديم أو تجربة)',
    '     * تبعت كل الطرود كأوردر واحد زي الأول — سلوك مايتكسرش صامت.',
    '     */',
    '    window.saveOrder = async function (onlyRow) {'),
  '④ التوقيع');

/* ═══ ⑤ الصفوف بتتصفّى ═══ */
one(
  L('      const notes    = ' + D + '("orderNotes").value.trim();',
    '      const rows     = document.querySelectorAll(".parcel-card");',
    '      if (!rows.length) { toast("أضف وجهة توصيل واحدة على الأقل", "err"); await _storeDiag({ step: "no-parcels" }); return; }'),
  L('      const notes    = ' + D + '("orderNotes").value.trim();',
    '      /* صف واحد بس لما يتحدّد — كل طرد بيتبعت كأوردر مستقل.',
    '         الـ`filter(Boolean)` مش زيادة: الصف ممكن يكون اتشال من تاب تاني. */',
    '      const rows     = onlyRow',
    '        ? [document.getElementById(`row-' + D + '{onlyRow}`)].filter(Boolean)',
    '        : Array.from(document.querySelectorAll(".parcel-card"));',
    '      if (!rows.length) { toast("أضف وجهة توصيل واحدة على الأقل", "err"); await _storeDiag({ step: "no-parcels" }); return; }'),
  '⑤ تصفية الصفوف');

/* ═══ ⑥ الزرار بقى زرار الطرد ═══ */
one(
  L('      const btn = ' + D + '("saveBtn");',
    '      btn.disabled = true; btn.textContent = "⏳ جاري الإرسال…";'),
  L('      /* زرار الطرد نفسه — والاحتياطي كائن وهمي عشان الكود اللي تحت',
    '         بيلمس `btn.disabled` في تلات مواضع من غير فحص. */',
    '      const btn = document.getElementById(onlyRow ? `sendBtn-' + D + '{onlyRow}` : "saveBtn")',
    '        || { disabled: false, textContent: "" };',
    '      btn.disabled = true; btn.textContent = "⏳ جاري الإرسال…";'),
  '⑥ زرار الطرد');

/* ═══ ⑦ بعد النجاح: الطرد يخرج بدل ما الفورم يتفضّى ═══ */
one(
  L('        btn.disabled = false; btn.textContent = "📤 إرسال الطلب";',
    '        // باركود لكل طرد قبل ما نفضّي الفورم — المحل يبعته للمستلم',
    '        showOrderCodes(orderNum, deliveries);',
    '        resetForm();'),
  L('        btn.disabled = false; btn.textContent = "📤 إرسال الطرد";',
    '        // الباركود قبل ما الطرد يخرج — المحل يطبعه ويلزقه عليه',
    '        showOrderCodes(orderNum, deliveries);',
    '        if (onlyRow) removeSentRow(onlyRow); else resetForm();'),
  '⑦ خروج الطرد بعد النجاح');

/* ═══ ⑧ رجوع الزرار في مساري الفشل ═══ */
one(
  L('        toast("خطأ: " + e.message, "err");',
    '        btn.disabled = false; btn.textContent = "📤 إرسال الطلب";'),
  L('        toast("خطأ: " + e.message, "err");',
    '        btn.disabled = false; btn.textContent = "📤 إرسال الطرد";'),
  '⑧أ فشل داخلي');

one(
  L('        const b = ' + D + '("saveBtn"); if (b) { b.disabled = false; b.textContent = "📤 إرسال الطلب"; }'),
  L('        const b = document.getElementById(onlyRow ? `sendBtn-' + D + '{onlyRow}` : "saveBtn");',
    '        if (b) { b.disabled = false; b.textContent = "📤 إرسال الطرد"; }'),
  '⑧ب فشل خارجي');

/* ═══ ⑧ج resetForm كانت بترجّع الزرار الكبير — بقى مش موجود ═══ */
one(
  L('      const btn = ' + D + '("saveBtn");',
    '      if (btn) { btn.disabled = false; btn.textContent = "📤 إرسال الطلب"; }'),
  L('      /* `saveBtn` اتشال — أزرار الطرود بتتبني جديدة مع كل صف فمافيش',
    '         زرار عالق يحتاج ترجيع هنا. */'),
  '⑧ج resetForm');

/* ═══ ⑨ removeSentRow ═══ */
one(
  L('    window.rmRow = function (n) {'),
  L('    /**',
    '     * بيشيل طرد **اتبعت بنجاح** ويخلّي الباقي زي ما هو.',
    '     *',
    '     * الفرق عن `rmRow`: ده بيشيل حتى لو ده الطرد الوحيد — لأنه اتبعت',
    '     * مش اتلغى. وساعتها `resetForm` بتفضّي وتحط طرد فاضي جديد،',
    '     * فالمحل يلاقي نفسه جاهز للشحنة اللي بعدها على طول.',
    '     */',
    '    window.removeSentRow = function (n) {',
    '      const rows = parcelRows();',
    '      if (rows.length <= 1) {',
    '        /* آخر طرد: تفضية كاملة — بترجّع ملف الاستلام وتضيف طرد فاضي.',
    '           ومعاها بتصفّر `_parcelChecked` فالنقط الحمرا مابتفضلش من',
    '           الشحنة اللي فاتت. */',
    '        resetForm();',
    '        return;',
    '      }',
    '      const i    = rows.findIndex(r => r.id === "row-" + n);',
    '      const next = rows[i + 1] || rows[i - 1] || null;',
    '      document.getElementById(`row-' + D + '{n}`)?.remove();',
    '      delete window._rowImages[n];',
    '      if (next) window.showRow(next.id.replace("row-", ""));',
    '      recalc();',
    '      recalcTotal();',
    '    };',
    '',
    '    window.rmRow = function (n) {'),
  '⑨ removeSentRow');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ إرسال لكل طرد');
