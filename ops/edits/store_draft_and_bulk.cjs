/* بوابة المحل — الشغل مايضيعش بالتنقّل + زرار إرسال الكل.
 *
 * ═══ الطلبات (صاحب النظام 2026-08-31) ═══
 * ① «لو بعتّ طلب ورحت لأي مكان تاني وكان في طلب تاني مفتوح، أرجع ألاقيه
 *    زي ما هو حتى أعمله إرسال».
 * ② «زرار في الأسفل يعمل على إرسال كل الطلبات دفعة واحدة».
 *
 * ═══ ① ليه الشغل كان بيضيع ═══
 * الصفحات في البوابة **بتتخفي مش بتتمسح** — الطرود بتفضل في الـDOM
 * بالكامل. اللي كان بيمسحها هو `resetForm()` اللي بتتنده في `navigate`
 * مع **كل** دخول على صفحة الطلب الجديد:
 *     if (page === "new-order") { resetForm(); ... }
 * يعني المحل يبعت طرد، يروح يشوف «طلباتي»، يرجع — يلاقي الطرد التاني
 * اللي كان مالياه راح. بقت بتتنده بس لما الفورم مالوش شغل لسه.
 *
 * ═══ ② الإرسال الجماعي = نفس الإرسال الفردي في حلقة ═══
 * كل طرد بيتبعت كأوردر مستقل زي ما هو — يعني الأرقام بتفضل عادية
 * متسلسلة. مافيش مسار إرسال تاني بترقيم تاني، وده مقصود: مسار واحد =
 * ترقيم واحد.
 *
 * 🔴 المودال: تلات طلبات كانوا هيفتحوا **تلات مودالات فوق بعض**. فالجماعي
 * بيجمّع الأكواد ويعرضها في مودال واحد في الآخر.
 *
 * 🔴 الوقوف عند أول فشل: لو طرد ناقص بياناته، بنقف عنده بدل ما نكمّل —
 * وإلا المحل يلاقي طرود اتبعتت وطرود لأ ومايعرفش مين فيهم. علامة النجاح
 * إن الصف اتشال من الفورم (`saveOrder` بتشيله بعد النجاح بس).
 *
 * 🔒 الحارس: ops/test_store_draft_bulk.cjs
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

/* ═══════════ ① الفورم مايتصفّرش لو فيه شغل ═══════════ */
one(
  '      if (page === "new-order")  { resetForm(); renderStoreSetupHint(); }',
  L('      /* 🔴 التصفير بقى مشروط. الصفحات بتتخفي مش بتتمسح، فالطرود',
    '         بتفضل في الـDOM — واللي كان بيمسحها هو النداء ده مع **كل**',
    '         دخول. المحل يبعت طرد، يروح «طلباتي» يطمّن، يرجع يلاقي الطرد',
    '         التاني اللي كان مالياه اتمسح. */',
    '      if (page === "new-order")  { if (!formHasWork()) resetForm(); renderStoreSetupHint(); }'),
  '① التصفير المشروط');

one(
  L('    /* الصفوف بترتيب ظهورها — الترتيب ده هو مصدر الترقيم المعروض */',
    '    function parcelRows() {'),
  L('    /**',
    '     * فيه شغل لسه ماتبعتش؟ — أي طرد فيه حاجة المحل كتبها بإيده.',
    '     *',
    '     * `rPrice` مش في القايمة عن قصد: بيتملى لوحده من المنطقة، فوجوده',
    '     * مش دليل على شغل. و`rZone` بتغطّيه.',
    '     *',
    '     * فورم نضيف (طرد واحد فاضي) بيرجّع false — فالتصفير بيشتغل عادي',
    '     * ويرجّع ملف الاستلام المحفوظ، وده اللي إحنا عايزينه أول ما ندخل.',
    '     */',
    '    window.formHasWork = function () {',
    '      return parcelRows().some(r => {',
    '        const n = r.id.replace("row-", "");',
    '        const v = id => (document.getElementById(id)?.value || "").trim();',
    '        return !!(v("rName-" + n) || v("rPhone-" + n) || v("rPhone2-" + n)',
    '               || v("rZone-" + n) || v("rOrderPrice-" + n) || v("rNote-" + n)',
    '               || (window._rowImages?.[n] || []).length',
    '               || window.rowIsReceipt(n));',
    '      });',
    '    };',
    '',
    '    /* الصفوف بترتيب ظهورها — الترتيب ده هو مصدر الترقيم المعروض */',
    '    function parcelRows() {'),
  '① formHasWork');

/* ═══════════ ② المودال اتفصل عن التجميع ═══════════ */
one(
  L('    window.showOrderCodes = function (orderNum, deliveries) {',
    '      const list = Array.isArray(deliveries) && deliveries.length ? deliveries : [{}];',
    '      window._orderCodes = list.map(d => ({',
    '        code : parcelCodeOf(orderNum, d, list.length),',
    '        phone: d.receiverPhone || "",',
    '        name : d.receiverName  || "",',
    '        zone : d.zoneName      || ""',
    '      }));',
    '',
    '      const box = document.createElement("div");'),
  L('    window.showOrderCodes = function (orderNum, deliveries) {',
    '      const list = Array.isArray(deliveries) && deliveries.length ? deliveries : [{}];',
    '      const codes = list.map(d => ({',
    '        code : parcelCodeOf(orderNum, d, list.length),',
    '        phone: d.receiverPhone || "",',
    '        name : d.receiverName  || "",',
    '        zone : d.zoneName      || ""',
    '      }));',
    '      /* 🔴 في الإرسال الجماعي بنجمّع بدل ما نفتح مودال لكل طلب —',
    '         تلات طلبات كانوا هيفتحوا تلات مودالات فوق بعض. */',
    '      if (Array.isArray(window._bulkCodes)) { window._bulkCodes.push(...codes); return; }',
    '      renderCodesModal(orderNum, codes);',
    '    };',
    '',
    '    /** بيرسم مودال الأكواد. `headline` بيبقى رقم الطلب، أو عدد الطلبات',
    '     *  في الإرسال الجماعي. `note` سطر تحذير اختياري (طرد وقف مثلًا). */',
    '    window.renderCodesModal = function (headline, codes, note) {',
    '      window._orderCodes = codes;',
    '      const orderNum = headline;',
    '',
    '      const box = document.createElement("div");'),
  '② فصل المودال');

/* ═══════════ ②ب سطر التحذير في المودال ═══════════ */
one(
  L('          <div style="font-size:.78rem;color:var(--muted);margin:8px 0 14px">',
    '            ابعت لكل مستلم الباركود بتاعه — بيتقرا من الطيار عند التسليم',
    '          </div>'),
  L('          <div style="font-size:.78rem;color:var(--muted);margin:8px 0 14px">',
    '            ابعت لكل مستلم الباركود بتاعه — بيتقرا من الطيار عند التسليم',
    '          </div>',
    '          ' + D + '{note ? `<div style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.4);',
    '                 border-radius:10px;padding:10px;font-size:.78rem;font-weight:700;margin-bottom:12px">',
    '                 ⚠️ ' + D + '{esc(note)}</div>` : ""}'),
  '②ب سطر التحذير');

/* ═══════════ ②ج زرار إرسال الكل ═══════════ */
one(
  L('    <!-- زرار «إرسال الطلب» الكبير اتشال 2026-08-31: كل طرد بقى ليه',
    '         زرار إرساله جواه، وبيتبعت كأوردر مستقل برقم عادي. وجود',
    '         الاتنين كان هيدّي طريقتين للإرسال بترقيم مختلف. -->'),
  L('    <!-- زرار «إرسال الطلب» الكبير القديم اتشال 2026-08-31 — كان بيبعت',
    '         الطرود كأوردر واحد بأرقام بشرطة.',
    '         اللي مكانه دلوقتي بيلفّ على الطرود ويبعت كل واحد **كأوردر',
    '         مستقل** — نفس مسار الإرسال الفردي بالحرف، فالترقيم واحد.',
    '         بيظهر بس لما يبقى في أكتر من طرد (`renderParcelTabs` بتتحكم). -->',
    '    <button class="submit-btn" id="sendAllBtn" style="display:none"',
    '            onclick="sendAllParcels()">📤 إرسال كل الطلبات</button>'),
  '②ج زرار إرسال الكل');

/* ═══════════ ②د الدالة ═══════════ */
one(
  '    window.saveOrder = async function (onlyRow) {',
  L('    /**',
    '     * بيبعت كل الطرود — **كل واحد أوردر مستقل** بنفس مسار الإرسال',
    '     * الفردي. مافيش مسار تاني بترقيم تاني.',
    '     *',
    '     * ⚠️ بنلفّ على **لقطة** من المعرّفات: `saveOrder` بتشيل الصف بعد',
    '     * النجاح، فالقايمة الحيّة بتتغيّر تحت رجلينا والحلقة بتتخبّط.',
    '     *',
    '     * ⚠️ بنقف عند أول فشل. علامة النجاح إن الصف اتشال — `saveOrder`',
    '     * مابتشيلوش غير بعد ما السيرفر يرد بنجاح. من غير الوقفة، المحل',
    '     * يلاقي طرود اتبعتت وطرود لأ ومايعرفش مين فيهم.',
    '     */',
    '    window.sendAllParcels = async function () {',
    '      const btn = document.getElementById("sendAllBtn");',
    '      const ids = parcelRows().map(r => r.id.replace("row-", ""));',
    '      if (!ids.length) return;',
    '',
    '      if (btn) { btn.disabled = true; btn.textContent = "⏳ جاري الإرسال…"; }',
    '      window._bulkCodes = [];',
    '      let stopped = null;',
    '      for (const n of ids) {',
    '        if (!document.getElementById(`row-' + D + '{n}`)) continue;   // اتشال من تاب تاني',
    '        await window.saveOrder(n);',
    '        if (document.getElementById(`row-' + D + '{n}`)) { stopped = n; break; }',
    '      }',
    '      const codes = window._bulkCodes || [];',
    '      window._bulkCodes = null;',
    '      if (btn) { btn.disabled = false; btn.textContent = "📤 إرسال كل الطلبات"; }',
    '',
    '      if (codes.length) {',
    '        /* التحذير جوه المودال مش توست: التوست بيتغطّى ورا المودال',
    '           والمحل يقفل ومايعرفش إن في طرد وقف. */',
    '        const left = stopped',
    '          ? `طرد واحد وقف — كمّل بياناته وابعته لوحده` : "";',
    '        renderCodesModal(`' + D + '{codes.length} ' + D + '{codes.length === 1 ? "طلب" : "طلبات"}`, codes, left);',
    '      }',
    '      window.renderParcelTabs?.();',
    '    };',
    '',
    '    window.saveOrder = async function (onlyRow) {'),
  '②د sendAllParcels');

/* ═══════════ ②ه إظهار الزرار مع أكتر من طرد ═══════════ */
one(
  L('      /* الزرار النشط يفضل في المنظور لما الطرود تكتر. block:"nearest"',
    '         مهمة: من غيرها المتصفح بيسكرول الصفحة رأسيًا كمان. */',
    '      box.querySelector(".ptab.active")?.scrollIntoView({ inline: "center", block: "nearest" });'),
  L('      /* زرار «إرسال الكل» مالوش لازمة مع طرد واحد — زراره جواه بيعمل',
    '         نفس الحاجة، ووجود زرارين لنفس الفعل بيلخبط. */',
    '      const all = document.getElementById("sendAllBtn");',
    '      if (all) all.style.display = rows.length > 1 ? "" : "none";',
    '',
    '      /* الزرار النشط يفضل في المنظور لما الطرود تكتر. block:"nearest"',
    '         مهمة: من غيرها المتصفح بيسكرول الصفحة رأسيًا كمان. */',
    '      box.querySelector(".ptab.active")?.scrollIntoView({ inline: "center", block: "nearest" });'),
  '②ه إظهار الزرار');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ الشغل بيفضل + إرسال الكل');
