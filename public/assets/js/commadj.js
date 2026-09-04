/**
 * commadj.js — تعديلات عمولة الطيار
 * (callcenter.html · tiar.html · branch.html)
 *
 * ═══ ليه الملف ده موجود ═══
 * العمولة في النظام بتتحسب لحظيًا: نسبة الطيار × سعر التوصيل. الحساب ده
 * مابيعرفش يعبّر عن حالتين حقيقيتين في الشغل:
 *
 *   • **أوردر سفر** — حوالين المنصورة مثلًا. مابيتحسبش بالعمولة الثابتة،
 *     بيتحسب نص سعر الخدمة: أوردر بـ100 عمولته 50 مش 8.
 *   • **تعويض شكوى** — العميل بيترضّى من غير أوردر أصلًا، والطيار بياخد
 *     عمولته على الشغل ده.
 *
 * فبقى فيه سجل تعديلات على السيرفر بنوعين:
 *   override — بيحل محل عمولة أوردر بعينه
 *   extra    — مبلغ مستقل بتاريخه، مالوش أوردر
 *
 * 🔴 التعديل ده **بيدخل مستحقات الطيار الفعلية** (buildMonthlyData على
 * السيرفر بتضمّه)، مش رقم في شاشة التقارير وخلاص. عشان كده السبب إجباري
 * وكل صف مسجّل بمين عمله وإمتى.
 *
 * الصلاحية: المدير ومشرف الفرع بس — ومشرف الفرع على طيارين فرعه بس
 * (مفروض على السيرفر كمان، مش على الواجهة بس).
 */
(function (global) {
  "use strict";

  var API_PATH = "/api/pilot-commission-adjustments";

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function money(n) { return (Number(n) || 0).toFixed(2); }
  function api() { return global.API || global.api; }

  var S = { pilotId: null, from: null, to: null, byOrder: {}, extras: [], loaded: false };

  /** المدير ومشرف الفرع بس — نفس قايمة الأدوار اللي على المسار */
  function canEdit() {
    var r = (global._currentUser || {}).role;
    return r === "مدير" || r === "مشرف فرع";
  }

  /** بيحمّل تعديلات الطيار في المدى — بينده مرة لكل تغيير فلتر */
  async function load(pilotId, from, to) {
    S.pilotId = pilotId; S.from = from || null; S.to = to || null;
    S.byOrder = {}; S.extras = []; S.loaded = false;
    if (!pilotId) return S;
    var q = { pilotId: pilotId };
    if (from) q.from = from;
    if (to) q.to = to;
    try {
      var d = await api().get(API_PATH, q);
      (d.items || []).forEach(function (a) {
        if (a.kind === "override" && a.orderId != null) S.byOrder[String(a.orderId)] = a;
        else if (a.kind === "extra") S.extras.push(a);
      });
      S.loaded = true;
    } catch (e) {
      /* 403 = الدور مايقراش (محاسب فرع تاني مثلًا) — الشاشة بتفضل شغّالة
         بالحساب التلقائي بدل ما تقع. */
      S.loaded = false;
    }
    return S;
  }

  /** التعديل المسجّل على أوردر، أو null */
  function overrideFor(orderId) { return S.byOrder[String(orderId)] || null; }

  /** العمولة النهائية للأوردر: المكتوب بالإيد لو موجود، وإلا الحساب التلقائي */
  function amountFor(order, autoValue) {
    var a = overrideFor(order && order.id);
    return a ? Number(a.amount) || 0 : (Number(autoValue) || 0);
  }

  function extrasTotal() {
    return S.extras.reduce(function (s, a) { return s + (Number(a.amount) || 0); }, 0);
  }

  /* ── المودال ─────────────────────────────────────────────────
     مبني بستايلات inline عن قصد: التلات لوحات ليهم CSS مختلف، وده
     بيخلي الملف يشتغل في أي واحدة من غير ما نلمس ستايلها. */
  function sheet(title, bodyHtml, onSave, extraBtns) {
    var box = document.createElement("div");
    box.id = "_caBox";
    box.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(5px);" +
      "z-index:9600;display:flex;align-items:center;justify-content:center;padding:18px";
    box.innerHTML =
      '<div style="background:#16161a;border:1px solid rgba(128,128,128,.28);border-radius:16px;' +
      'width:100%;max-width:520px;max-height:92vh;overflow-y:auto;color:#eee;font-family:inherit">' +
        '<div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;' +
        'border-bottom:1px solid rgba(128,128,128,.22)">' +
          '<b style="font-size:1rem">' + title + '</b>' +
          '<button type="button" id="_caX" style="background:rgba(128,128,128,.18);border:none;color:#bbb;' +
          'width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:14px">✕</button>' +
        '</div>' +
        '<div style="padding:18px 20px">' + bodyHtml +
          '<div id="_caErr" style="color:#ff6b6b;font-size:.82rem;min-height:18px;margin-top:8px"></div>' +
        '</div>' +
        '<div style="padding:14px 20px;border-top:1px solid rgba(128,128,128,.22);display:flex;gap:9px;flex-wrap:wrap">' +
          '<button type="button" id="_caSave" style="background:#22c55e;color:#fff;border:none;padding:9px 18px;' +
          'border-radius:9px;cursor:pointer;font-weight:700;font-size:.86rem">💾 حفظ</button>' +
          (extraBtns || "") +
          '<button type="button" id="_caCancel" style="background:transparent;color:#bbb;' +
          'border:1px solid rgba(128,128,128,.35);padding:9px 18px;border-radius:9px;cursor:pointer;font-size:.86rem">إلغاء</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(box);
    var close = function () { box.remove(); };
    box.querySelector("#_caX").onclick = close;
    box.querySelector("#_caCancel").onclick = close;
    box.onclick = function (e) { if (e.target === box) close(); };
    box.querySelector("#_caSave").onclick = function () { onSave(box, close); };
    return box;
  }

  function err(box, msg) { box.querySelector("#_caErr").textContent = msg || ""; }

  function fld(label, id, type, value, hint) {
    return '<div style="margin-bottom:13px">' +
      '<label style="display:block;font-size:.78rem;color:#9a9aa2;margin-bottom:5px">' + label + '</label>' +
      '<input id="' + id + '" type="' + (type || "text") + '" value="' + esc(value == null ? "" : value) + '" ' +
      'style="width:100%;box-sizing:border-box;background:#1e1e24;border:1px solid rgba(128,128,128,.3);' +
      'border-radius:9px;padding:10px 12px;color:#eee;font-size:.9rem;font-family:inherit" />' +
      (hint ? '<div style="font-size:.72rem;color:#8a8a92;margin-top:5px;line-height:1.7">' + hint + '</div>' : '') +
      '</div>';
  }

  /* 💸 اختيار الخزنة — إجباري مع أي كتابة عمولة (طلب صاحب النظام
     2026-09-03: «لما أكتب العمولة في الصفحة دي لازم تحصل حركة في سجل
     الخزنة»). السيرفر بيسجّل حركة `out` بالقيمة، والتعديل بعد كده
     بالفرق بس، والمسح بيرجّع المصروف للخزنة. */
  function storeSelHtml(id, selectedId) {
    var stores = global._cashStoresData || [];
    var opts = stores.map(function (s, i) {
      var sel = selectedId != null ? String(s.id) === String(selectedId) : i === 0;
      return '<option value="' + esc(s.id) + '"' + (sel ? ' selected' : '') + '>' +
        esc(s.name) + (s.branchName ? ' — ' + esc(s.branchName) : '') + '</option>';
    }).join("");
    return '<div style="margin-bottom:13px">' +
      '<label style="display:block;font-size:.78rem;color:#9a9aa2;margin-bottom:5px">' +
      '💸 الخزنة اللي العمولة هتتصرف منها (بتتسجل حركة بقيمتها/بالفرق في سجل الخزنة)</label>' +
      '<select id="' + id + '" style="width:100%;box-sizing:border-box;background:#1e1e24;' +
      'border:1px solid rgba(128,128,128,.3);border-radius:9px;padding:10px 12px;color:#eee;' +
      'font-size:.9rem;font-family:inherit">' +
      '<option value="">— اختر الخزنة —</option>' + opts + '</select></div>';
  }
  function toast(msg) {
    if (typeof global.showToast === "function") global.showToast(msg, "success");
  }

  async function save(body, box, close, onDone) {
    try {
      var res = await api().post(API_PATH, body);
      await load(S.pilotId, S.from, S.to);
      close();
      var d = Number(res && res.paidDelta) || 0;
      if (d > 0.004) toast("💸 اتسجلت حركة خروج من الخزنة: " + money(d) + " ج.م");
      else if (d < -0.004) toast("↩️ رجع للخزنة فرق العمولة: " + money(-d) + " ج.م");
      if (onDone) onDone();
    } catch (e) {
      err(box, "خطأ: " + (e && e.message ? e.message : e));
    }
  }

  /** تعديل عمولة أوردر بعينه */
  function openEdit(pilot, order, autoValue, onDone) {
    if (!canEdit()) return;
    var cur = overrideFor(order.id);
    var price = Number(order.totalDeliveryPrice) || 0;
    var body =
      '<div style="background:rgba(14,165,233,.08);border:1px solid rgba(14,165,233,.25);border-radius:10px;' +
      'padding:11px 13px;font-size:.83rem;line-height:1.9;margin-bottom:15px">' +
        'الأوردر <b>' + esc(order.orderNum || order.id) + '</b> — سعر التوصيل <b>' + money(price) + ' ج.م</b><br>' +
        'العمولة المحسوبة تلقائيًا: <b>' + money(autoValue) + ' ج.م</b>' +
      '</div>' +
      fld("العمولة (ج.م)", "_caAmt", "number", cur ? cur.amount : money(autoValue),
          'اضغط «نص سعر التوصيل» لأوردرات السفر — بتحسبها لك.') +
      '<div style="margin:-6px 0 14px">' +
        '<button type="button" id="_caHalf" style="background:rgba(168,85,247,.16);color:#c084fc;' +
        'border:1px solid rgba(168,85,247,.4);padding:6px 12px;border-radius:8px;cursor:pointer;font-size:.78rem">' +
        '½ نص سعر التوصيل (' + money(price / 2) + ')</button></div>' +
      fld("السبب", "_caReason", "text", cur ? cur.reason : "",
          'إجباري — ده مبلغ بيدخل مستحقات الطيار، والسبب بيفضل مسجّل في السجل.') +
      storeSelHtml("_caStore", cur ? cur.paidStoreId : null) +
      (cur && cur.paidAt
        ? '<div style="font-size:.78rem;color:#22c55e;font-weight:700;margin-bottom:10px">' +
          '💸 اتصرف منها من الخزنة: ' + money(cur.paidAmount) + ' ج.م — التعديل هيتحاسب بالفرق بس</div>'
        : "") +
      (cur
        ? '<div style="font-size:.74rem;color:#8a8a92;border-top:1px solid rgba(128,128,128,.2);padding-top:10px">' +
          'آخر تعديل: <b>' + esc(cur.createdBy) + '</b>' + (cur.updatedAt || cur.createdAt
            ? ' — ' + new Date(cur.updatedAt || cur.createdAt).toLocaleString("ar-EG") : "") + '</div>'
        : "");

    var extraBtn = cur
      ? '<button type="button" id="_caDel" style="background:rgba(239,68,68,.15);color:#ff6b6b;' +
        'border:1px solid rgba(239,68,68,.4);padding:9px 16px;border-radius:9px;cursor:pointer;font-size:.86rem">' +
        '↺ رجّع الحساب التلقائي</button>'
      : "";

    var box = sheet("✏️ تعديل عمولة أوردر", body, function (b, close) {
      var amt = parseFloat(b.querySelector("#_caAmt").value);
      var rsn = b.querySelector("#_caReason").value.trim();
      var st  = b.querySelector("#_caStore").value;
      if (isNaN(amt) || amt < 0) return err(b, "اكتب مبلغ صحيح (صفر أو أكتر)");
      if (!rsn) return err(b, "اكتب سبب التعديل");
      if (!st) return err(b, "اختر الخزنة — العمولة لازم تتسجل بحركة في سجل الخزنة");
      save({ pilotId: pilot.id, orderId: order.id, amount: amt, reason: rsn, cashStoreId: st }, b, close, onDone);
    }, extraBtn);

    box.querySelector("#_caHalf").onclick = function () {
      box.querySelector("#_caAmt").value = money(price / 2);
      if (!box.querySelector("#_caReason").value.trim())
        box.querySelector("#_caReason").value = "أوردر سفر — نص سعر الخدمة";
    };
    var del = box.querySelector("#_caDel");
    if (del) del.onclick = async function () {
      var q = cur.paidAmount > 0
        ? "ترجّع الأوردر ده للحساب التلقائي؟ المصروف (" + money(cur.paidAmount) + " ج.م) هيرجع للخزنة بحركة دخول."
        : "ترجّع الأوردر ده للحساب التلقائي بنسبة الطيار؟";
      if (!confirm(q)) return;
      try {
        var res = await api().del(API_PATH + "/" + encodeURIComponent(cur.id));
        await load(S.pilotId, S.from, S.to);
        box.remove();
        var rf = Number(res && res.refunded) || 0;
        if (rf > 0.004) toast("↩️ رجع للخزنة: " + money(rf) + " ج.م");
        if (onDone) onDone();
      } catch (e) { err(box, "خطأ: " + (e && e.message ? e.message : e)); }
    };
  }

  /** عمولة مستقلة بلا أوردر (تعويض شكوى) */
  function openAdd(pilot, onDone) {
    if (!canEdit()) return;
    var today = new Date().toISOString().slice(0, 10);
    var body =
      '<div style="background:rgba(249,115,22,.08);border:1px solid rgba(249,115,22,.25);border-radius:10px;' +
      'padding:11px 13px;font-size:.83rem;line-height:1.9;margin-bottom:15px">' +
        'عمولة للطيار <b>' + esc(pilot.name || "") + '</b> <u>من غير أوردر</u> — زي تعويض شكوى عميل.' +
      '</div>' +
      fld("المبلغ (ج.م)", "_caAmt", "number", "") +
      fld("السبب", "_caReason", "text", "", 'إجباري — مثال: تعويض شكوى العميل أحمد.') +
      fld("التاريخ", "_caDate", "date", today, 'بيحدد الشهر اللي المبلغ يتحسب فيه.') +
      storeSelHtml("_caStore", null);

    sheet("➕ عمولة بلا أوردر", body, function (b, close) {
      var amt = parseFloat(b.querySelector("#_caAmt").value);
      var rsn = b.querySelector("#_caReason").value.trim();
      var dt  = b.querySelector("#_caDate").value;
      var st  = b.querySelector("#_caStore").value;
      if (isNaN(amt) || amt < 0) return err(b, "اكتب مبلغ صحيح");
      if (!rsn) return err(b, "اكتب السبب");
      if (!st) return err(b, "اختر الخزنة — العمولة لازم تتسجل بحركة في سجل الخزنة");
      save({ pilotId: pilot.id, amount: amt, reason: rsn, effectiveDate: dt || undefined, cashStoreId: st }, b, close, onDone);
    });
  }

  async function remove(id, onDone) {
    if (!confirm("تمسح المبلغ ده من مستحقات الطيار؟ لو اتصرف من الخزنة هيرجع لها بحركة دخول.")) return;
    try {
      var res = await api().del(API_PATH + "/" + encodeURIComponent(id));
      await load(S.pilotId, S.from, S.to);
      var rf = Number(res && res.refunded) || 0;
      if (rf > 0.004) toast("↩️ رجع للخزنة: " + money(rf) + " ج.م");
      if (onDone) onDone();
    } catch (e) { alert("خطأ: " + (e && e.message ? e.message : e)); }
  }

  /** بلوك «عمولات بلا أوردر» — بيتحط تحت جدول الأوردرات */
  function extrasHtml() {
    if (!S.extras.length) {
      return canEdit()
        ? '<div style="font-size:.82rem;color:#8a8a92;padding:10px 2px">مفيش عمولات مستقلة في المدى ده.</div>'
        : "";
    }
    return '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.84rem">' +
      '<thead><tr style="color:#9a9aa2;font-size:.75rem">' +
        '<th style="text-align:right;padding:7px 6px">التاريخ</th>' +
        '<th style="text-align:right;padding:7px 6px">السبب</th>' +
        '<th style="text-align:right;padding:7px 6px">المبلغ</th>' +
        '<th style="text-align:right;padding:7px 6px">سجّلها</th>' +
        '<th style="text-align:right;padding:7px 6px"></th></tr></thead><tbody>' +
      S.extras.map(function (a) {
        return '<tr style="border-top:1px solid rgba(128,128,128,.18)">' +
          '<td style="padding:8px 6px;white-space:nowrap">' + esc(a.effectiveDate) + '</td>' +
          '<td style="padding:8px 6px">' + esc(a.reason || "—") + '</td>' +
          '<td style="padding:8px 6px;color:#22c55e;font-weight:700;white-space:nowrap">' + money(a.amount) + ' ج.م' +
            (a.paidAt ? ' <span title="اتصرفت من الخزنة">💸</span>' : '') + '</td>' +
          '<td style="padding:8px 6px;color:#8a8a92;font-size:.78rem">' + esc(a.createdBy || "—") + '</td>' +
          '<td style="padding:8px 6px">' + (canEdit()
            ? '<button type="button" onclick="CommAdj.remove(' + Number(a.id) + ', window._caRefresh)" ' +
              'style="background:rgba(239,68,68,.14);color:#ff6b6b;border:1px solid rgba(239,68,68,.35);' +
              'padding:4px 10px;border-radius:7px;cursor:pointer;font-size:.75rem">مسح</button>' : "") +
          '</td></tr>';
      }).join("") +
      '</tbody></table></div>';
  }

  global.CommAdj = {
    load: load, overrideFor: overrideFor, amountFor: amountFor,
    extrasTotal: extrasTotal, extrasHtml: extrasHtml,
    openEdit: openEdit, openAdd: openAdd, remove: remove,
    canEdit: canEdit, state: S
  };
})(window);
