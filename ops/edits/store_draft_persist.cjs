/* حفظ المسوّدة على التليفون + زرار خروج من مودال الباركود.
 *
 * ═══ الطلبات (صاحب النظام 2026-08-31) ═══
 * ① «احفظ الشغل على التليفون علشان مايضيعش مع الرفريش».
 * ② «لما صورة الباركود تظهر، اعمل فيها زرار للخروج منها علشان مااضطرش
 *    أروح للطلبات وأرجع تاني — بل أفضل في صفحة الطلبات».
 *
 * ═══ ① إيه اللي بيتحفظ ═══
 * كل طرد: الاسم · التليفونين · المنطقة · العنوان · سعر التوصيل · العهدة ·
 * الملاحظة · علامة الريسيت · روابط الصور · دبوس التسليم.
 * والصور **روابط** مرفوعة على السيرفر أصلًا — مش بيانات صور، فالمساحة
 * صغيرة ومافيش خطر على حصة التخزين.
 *
 * ═══ ليه بالمستخدم ═══
 * المفتاح فيه اسم المستخدم. من غيره، محل يفتح على نفس التليفون بعد محل
 * تاني كان هيلاقي مسوّدة مش بتاعته — بأرقام مستلمين وعناوين غريبة عنه.
 *
 * ═══ ليه بنحفظ عند التغيير مش عند الخروج بس ═══
 * `beforeunload` مابتشتغلش بالاعتماد عليها على أندرويد: المتصفح بيقتل
 * الصفحة من غير ما ينده عليها لما التليفون يضغط على الذاكرة. فبنحفظ مع
 * كل تغيير (مؤجّل نص ثانية) وكمان عند `visibilitychange` — وده الحدث
 * اللي بيتنده فعلًا لما المستخدم يطلع من التطبيق.
 *
 * ═══ ليه التخزين مايوقعش الصفحة ═══
 * كل نداء جوه try/catch: الوضع الخاص في سفاري بيرمي عند الكتابة، وحصة
 * التخزين ممكن تتملى. مسوّدة ضايعة مقبولة — صفحة واقعة لأ.
 *
 * ═══ ② الخروج من المودال ═══
 * الزرار الوحيد كان بينقل لصفحة الطلبات إجباريًا. بقى فيه ✕ فوق وزرار
 * «تمام» بيقفل ويسيبك مكانك، وزرار تاني منفصل للي عايز يروح لطلباته.
 *
 * 🔒 الحارس: ops/test_store_draft_persist.cjs
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

/* ═══════════ ① منظومة الحفظ ═══════════ */
one(
  L('    /**',
    '     * فيه شغل لسه ماتبعتش؟ — أي طرد فيه حاجة المحل كتبها بإيده.'),
  L('    /* ══════════════════════════════════════════════════════',
    '       مسوّدة الشحنة على التليفون',
    '       الشغل كان بيعيش في الـDOM بس، فأي تحديث للصفحة بيضيّعه.',
    '       دلوقتي بيتحفظ في `localStorage` وبيترجع عند الفتح.',
    '    ══════════════════════════════════════════════════════ */',
    '',
    '    /* المفتاح فيه اسم المستخدم: محل بيفتح على نفس التليفون بعد محل',
    '       تاني مايلاقيش مسوّدة مش بتاعته — أرقام مستلمين وعناوين غريبة. */',
    '    function draftKey() {',
    '      const u = window._currentUser?.username || "";',
    '      return u ? "tiar-store-draft:" + u : "";',
    '    }',
    '',
    '    /** بيقرا الفورم كله لكائن قابل للتخزين */',
    '    function readDraft() {',
    '      const v = id => document.getElementById(id)?.value ?? "";',
    '      return parcelRows().map(r => {',
    '        const n = r.id.replace("row-", "");',
    '        return {',
    '          name   : v("rName-" + n),',
    '          phone  : v("rPhone-" + n),',
    '          phone2 : v("rPhone2-" + n),',
    '          zoneId : v("rZone-" + n),',
    '          price  : v("rPrice-" + n),',
    '          cod    : v("rOrderPrice-" + n),',
    '          note   : v("rNote-" + n),',
    '          receipt: !!window.rowIsReceipt(n),',
    '          addr   : window.getAddressValue ? window.getAddressValue("rAddrWidget-" + n) : "",',
    '          // روابط بس — الصور مرفوعة على السيرفر أصلًا فالمساحة صغيرة',
    '          images : (window._rowImages?.[n] || []).filter(x => typeof x === "string"),',
    '          pin    : window._geoPins?.["r" + n] || null,',
    '        };',
    '      });',
    '    }',
    '',
    '    /* كل نداء تخزين جوه try/catch: الوضع الخاص في سفاري بيرمي عند',
    '       الكتابة، والحصة ممكن تتملى. مسوّدة ضايعة مقبولة — صفحة واقعة لأ. */',
    '    window.saveDraft = function () {',
    '      const k = draftKey(); if (!k) return;',
    '      try {',
    '        if (!formHasWork()) { localStorage.removeItem(k); return; }',
    '        localStorage.setItem(k, JSON.stringify({ at: Date.now(), rows: readDraft() }));',
    '      } catch (e) {}',
    '    };',
    '',
    '    window.clearDraft = function () {',
    '      const k = draftKey(); if (!k) return;',
    '      try { localStorage.removeItem(k); } catch (e) {}',
    '    };',
    '',
    '    /* الحفظ مؤجّل: الكتابة مع كل حرف بتقفّل الواجهة على تليفون ضعيف. */',
    '    let _draftTimer = null;',
    '    window.queueSaveDraft = function () {',
    '      clearTimeout(_draftTimer);',
    '      _draftTimer = setTimeout(() => window.saveDraft(), 500);',
    '    };',
    '',
    '    /**',
    '     * بيرجّع المسوّدة المحفوظة. بيرجّع `true` لو رجّع حاجة فعلًا.',
    '     *',
    '     * ⚠️ بينده `addRow` لكل صف عشان الودجتات (العنوان والخريطة والمناطق)',
    '     * تتبني زي أي طرد عادي — بناء الـHTML بإيدنا كان هيسيبهم ميتين.',
    '     */',
    '    window.restoreDraft = function () {',
    '      const k = draftKey(); if (!k) return false;',
    '      let d = null;',
    '      try { d = JSON.parse(localStorage.getItem(k) || "null"); } catch (e) { return false; }',
    '      if (!d || !Array.isArray(d.rows) || !d.rows.length) return false;',
    '',
    '      document.getElementById("delivContainer").innerHTML = "";',
    '      document.getElementById("parcelTabs").innerHTML = "";',
    '      window._delivCount = 0;',
    '      window._rowImages  = {};',
    '',
    '      d.rows.forEach(row => {',
    '        addRow();',
    '        const n  = window._delivCount;',
    '        const set = (id, val) => { const el = document.getElementById(id); if (el && val) el.value = val; };',
    '        set("rName-"       + n, row.name);',
    '        set("rPhone-"      + n, row.phone);',
    '        set("rPhone2-"     + n, row.phone2);',
    '        set("rNote-"       + n, row.note);',
    '        set("rOrderPrice-" + n, row.cod);',
    '        if (row.addr && window.setAddressValue) window.setAddressValue("rAddrWidget-" + n, row.addr);',
    '',
    '        /* المنطقة الأول: `onZone` بتدهس السعر بسعر المنطقة، فلازم',
    '           تتنده **قبل** ما نرجّع السعر اللي المحل كتبه. */',
    '        const zs = document.getElementById("rZone-" + n);',
    '        if (zs && row.zoneId) {',
    '          zs.value = String(row.zoneId);',
    '          if (zs.value === String(row.zoneId)) window.onZone(n);',
    '        }',
    '        if (row.price) { const pe = document.getElementById("rPrice-" + n);',
    '          if (pe) { pe.value = row.price; window.onPriceEdit?.(n); } }',
    '',
    '        if (row.receipt) {',
    '          const cb = document.getElementById("rReceipt-" + n);',
    '          if (cb) { cb.checked = true; window.onRowReceipt(n); }',
    '        }',
    '        if (Array.isArray(row.images) && row.images.length) {',
    '          window._rowImages[n] = row.images.slice();',
    '          window.renderRowImages?.(n);',
    '        }',
    '        if (row.pin) { window._geoPins["r" + n] = row.pin;',
    '          window.renderGeoPicker?.("rGeoBox-" + n, "r" + n, "نقطة التسليم"); }',
    '      });',
    '',
    '      window.showRow(1);',
    '      recalc(); recalcTotal();',
    '      return true;',
    '    };',
    '',
    '    /**',
    '     * فيه شغل لسه ماتبعتش؟ — أي طرد فيه حاجة المحل كتبها بإيده.'),
  '① منظومة الحفظ');

/* ═══════════ ② الحفظ التلقائي عند الكتابة ═══════════ */
one(
  L('      [`rName-' + D + '{n}`, `rPhone-' + D + '{n}`, `rZone-' + D + '{n}`].forEach(id => {',
    '        const el = document.getElementById(id);',
    '        if (!el) return;',
    '        el.addEventListener("input",  () => window.renderParcelTabs?.());',
    '        el.addEventListener("change", () => window.renderParcelTabs?.());',
    '      });'),
  L('      /* أي تغيير في الطرد بيتحفظ. القايمة أوسع من قايمة النقط الحمرا',
    '         عن قصد: النقطة بتهم الحقول المطلوبة، والمسوّدة بتهم **كل** حاجة',
    '         المحل كتبها — ملاحظة أو رقم تاني ضايع زي الاسم بالظبط. */',
    '      [`rName-' + D + '{n}`, `rPhone-' + D + '{n}`, `rZone-' + D + '{n}`,',
    '       `rPhone2-' + D + '{n}`, `rOrderPrice-' + D + '{n}`, `rNote-' + D + '{n}`,',
    '       `rPrice-' + D + '{n}`].forEach(id => {',
    '        const el = document.getElementById(id);',
    '        if (!el) return;',
    '        el.addEventListener("input",  () => { window.renderParcelTabs?.(); window.queueSaveDraft?.(); });',
    '        el.addEventListener("change", () => { window.renderParcelTabs?.(); window.queueSaveDraft?.(); });',
    '      });'),
  '② الحفظ عند الكتابة');

/* ═══════════ ③ الحفظ عند الخروج من التطبيق ═══════════ */
one(
  L('    /* الصفوف بترتيب ظهورها — الترتيب ده هو مصدر الترقيم المعروض */',
    '    function parcelRows() {'),
  L('    /* 🔴 `beforeunload` وحدها مش كفاية على أندرويد: المتصفح بيقتل',
    '       الصفحة من غير ما ينده عليها لما التليفون يضغط على الذاكرة.',
    '       `visibilitychange` هو اللي بيتنده فعلًا لما المستخدم يطلع من',
    '       التطبيق — فبنحفظ على الاتنين. */',
    '    document.addEventListener("visibilitychange", () => {',
    '      if (document.visibilityState === "hidden") window.saveDraft?.();',
    '    });',
    '    window.addEventListener("pagehide", () => window.saveDraft?.());',
    '',
    '    /* الصفوف بترتيب ظهورها — الترتيب ده هو مصدر الترقيم المعروض */',
    '    function parcelRows() {'),
  '③ الحفظ عند الخروج');

/* ═══════════ ④ الاسترجاع عند الدخول ═══════════ */
one(
  L('      startListeners(data);',
    '      navigateTo("new-order");'),
  L('      startListeners(data);',
    '      navigateTo("new-order");',
    '      /* بعد `navigateTo` عشان الفورم يكون اتبنى. `restoreDraft` بترجّع',
    '         false لو مافيش مسوّدة، فالفورم النضيف بيفضل زي ما هو. */',
    '      setTimeout(() => { try { window.restoreDraft?.(); } catch (e) {} }, 0);'),
  '④ الاسترجاع عند الدخول');

/* ═══════════ ⑤ المسوّدة بتتحدّث مع الإضافة والحذف والإرسال ═══════════ */
one(
  L('      // الطرد الجديد بيتفتح على طول — المحل دوس «طرد آخر» عشان يكتب فيه',
    '      window.showRow(n);'),
  L('      // الطرد الجديد بيتفتح على طول — المحل دوس «طرد آخر» عشان يكتب فيه',
    '      window.showRow(n);',
    '      window.queueSaveDraft?.();'),
  '⑤أ عند الإضافة');

one(
  L('      document.getElementById(`row-' + D + '{n}`)?.remove();',
    '      delete window._rowImages[n];',
    '      if (next) window.showRow(next.id.replace("row-", ""));',
    '      recalc();',
    '      recalcTotal();',
    '    };'),
  L('      document.getElementById(`row-' + D + '{n}`)?.remove();',
    '      delete window._rowImages[n];',
    '      if (next) window.showRow(next.id.replace("row-", ""));',
    '      recalc();',
    '      recalcTotal();',
    '      window.saveDraft?.();          // فورًا مش مؤجّل — الطرد اتبعت خلاص',
    '    };'),
  '⑤ب بعد الإرسال');

one(
  L('      document.getElementById(`row-' + D + '{n}`)?.remove();',
    '      delete window._rowImages[n];',
    '      if (next) window.showRow(next.id.replace("row-", ""));',
    '      else window.renderParcelTabs();',
    '      recalc();',
    '    };'),
  L('      document.getElementById(`row-' + D + '{n}`)?.remove();',
    '      delete window._rowImages[n];',
    '      if (next) window.showRow(next.id.replace("row-", ""));',
    '      else window.renderParcelTabs();',
    '      recalc();',
    '      window.saveDraft?.();',
    '    };'),
  '⑤ج عند الحذف');

one(
  L('      window._activeRow     = null;',
    '      window._parcelChecked = false;'),
  L('      window._activeRow     = null;',
    '      window._parcelChecked = false;',
    '      window.clearDraft?.();        // الفورم اتفضّى — المسوّدة مالهاش لازمة'),
  '⑤د عند التصفير');

one(
  L('        renderRowImages(n);',
    '        // النقطة الحمرا بتروح أول ما صورة الريسيت ترفع بنجاح',
    '        window.renderParcelTabs?.();'),
  L('        renderRowImages(n);',
    '        // النقطة الحمرا بتروح أول ما صورة الريسيت ترفع بنجاح',
    '        window.renderParcelTabs?.();',
    '        /* الحفظ مؤجّل، فالنداء ده مع كل صورة بيدمج في كتابة واحدة بعد',
    '           آخر رفع — مش كتابة لكل صورة. والمؤقّت `{uploading:true}`',
    '           مابيتحفظش أصلًا (`readDraft` بتفلتر النصوص بس). */',
    '        window.queueSaveDraft?.();'),
  '⑤و بعد رفع الصور');

one(
  L('      const hint = document.getElementById("rReceiptHint-" + n);'),
  L('      window.queueSaveDraft?.();',
    '      const hint = document.getElementById("rReceiptHint-" + n);'),
  '⑤ز عند تبديل الريسيت');

/* ═══════════ ⑥ زرار الخروج من المودال ═══════════ */
one(
  L('          <button onclick="document.getElementById(\'_codesBox\').remove(); navigateTo(\'my-orders\')"',
    '                  style="width:100%;margin-top:6px;background:var(--sky);color:#fff;border:none;',
    '                         padding:12px;border-radius:10px;font-weight:700;cursor:pointer;font-family:inherit">',
    '            تمام — روح لطلباتي',
    '          </button>'),
  L('          <!-- زرارين مش واحد: الزرار القديم كان بينقل لصفحة الطلبات',
    '               إجباريًا، فالمحل اللي عايز يكمّل شحنة تانية كان لازم',
    '               يرجع بإيده. دلوقتي «تمام» بتقفل وتسيبه مكانه. -->',
    '          <div style="display:flex;gap:8px;margin-top:6px">',
    '            <button onclick="closeCodesBox()"',
    '                    style="flex:1;background:var(--sky);color:#fff;border:none;',
    '                           padding:12px;border-radius:10px;font-weight:700;cursor:pointer;font-family:inherit">',
    '              تمام',
    '            </button>',
    '            <button onclick="closeCodesBox(); navigateTo(\'my-orders\')"',
    '                    style="flex:1;background:transparent;color:inherit;border:1px solid var(--border);',
    '                           padding:12px;border-radius:10px;font-weight:700;cursor:pointer;font-family:inherit">',
    '              طلباتي',
    '            </button>',
    '          </div>'),
  '⑥أ زرارين');

one(
  L('          <div style="font-size:2rem">✅</div>'),
  L('          <!-- ✕ فوق: أسرع خروج، ومتوقّعة في أي مودال -->',
    '          <button onclick="closeCodesBox()" aria-label="إغلاق"',
    '                  style="position:absolute;top:10px;left:12px;background:transparent;border:none;',
    '                         color:var(--muted);font-size:1.3rem;cursor:pointer;line-height:1;',
    '                         padding:4px 8px;font-family:inherit">✕</button>',
    '          <div style="font-size:2rem">✅</div>'),
  '⑥ب زرار ✕');

one(
  L('        <div style="background:var(--panel);border:1px solid var(--border);border-radius:16px;',
    '                    padding:22px;max-width:440px;width:100%;max-height:92vh;overflow:auto;text-align:center">'),
  L('        <div style="position:relative;background:var(--panel);border:1px solid var(--border);border-radius:16px;',
    '                    padding:22px;max-width:440px;width:100%;max-height:92vh;overflow:auto;text-align:center">'),
  '⑥ج موضع نسبي للـ✕');

one(
  '    window.showOrderCodes = function (orderNum, deliveries) {',
  L('    /* الإغلاق في مكان واحد: تلات أزرار بتنده عليه، والدوسة برّه المودال',
    '       كمان. تكرار `remove()` في كل واحد كان بيسيب واحد ينسى. */',
    '    window.closeCodesBox = function () {',
    '      document.getElementById("_codesBox")?.remove();',
    '    };',
    '',
    '    window.showOrderCodes = function (orderNum, deliveries) {'),
  '⑥د closeCodesBox');

/* المرساة بتاخد السطر اللي قبلها: `document.body.appendChild(box)` موجودة
   مرتين — واحدة هنا وواحدة في مودال انتهاء الجلسة (سطر ~214). */
one(
  L('      const listBox = box.querySelector("#_codesList");'),
  L('      // الدوسة على الخلفية بتقفل كمان — مش على الصندوق نفسه',
    '      box.addEventListener("click", e => { if (e.target === box) window.closeCodesBox(); });',
    '      const listBox = box.querySelector("#_codesList");'),
  '⑥ه الدوسة برّه');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ المسوّدة بتتحفظ + الخروج من المودال');
