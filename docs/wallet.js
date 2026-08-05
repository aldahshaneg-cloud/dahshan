/* ══════════════════════════════════════════════════════════════
   محفظة العميل / المحل — وحدة مشتركة
   ──────────────────────────────────────────────────────────────
   بتتخزّن جوّه صاحبها نفسه عشان تشتغل بقواعد فايربيز الحالية
   من غير ما نضيف عقدة جديدة محتاجة رفع قواعد:
     • العميل →  customers/{uid}/wallet
     • المحل   →  users/{username}/wallet

   الشكل:
     wallet = { balance, updatedAt, txns: { <id>: {...} } }
     txn    = { amount, type, note, by, at, balanceAfter }

   الرصيد الموجب = فلوس ليه عندنا (رصيد مستحق) يقدر يستخدمها في
   أوردر مجاني أو خصم. السالب = عليه فلوس لينا.
══════════════════════════════════════════════════════════════ */
import { ref, get, push, set, update, runTransaction }
  from "https://www.gstatic.com/firebasejs/10.12.2/firebase-database.js";

export const TXN_TYPES = {
  credit:   { t: "إضافة رصيد",        sign: +1, icon: "➕", color: "var(--green)" },
  discount: { t: "خصم / أوردر مجاني", sign: +1, icon: "🎁", color: "var(--green)" },
  debit:    { t: "خصم من الرصيد",     sign: -1, icon: "➖", color: "var(--brand)" },
  use:      { t: "استخدام في أوردر",   sign: -1, icon: "🧾", color: "var(--brand)" },
  settle:   { t: "تسوية",             sign: -1, icon: "🤝", color: "var(--muted)" },
};

/** مسار محفظة صاحبها — "customer" بالـ uid أو "store" باسم المستخدم */
export function walletPath(kind, id) {
  return kind === "customer" ? `customers/${id}/wallet` : `users/${id}/wallet`;
}

export const money = v =>
  (Number(v) || 0).toLocaleString("ar-EG", { minimumFractionDigits: 0, maximumFractionDigits: 2 });

/** بيرجّع { balance, txns:[] } من الأوبچكت المحفوظ */
export function readWallet(raw) {
  const w = raw || {};
  const txns = Object.entries(w.txns || {})
    .map(([id, t]) => ({ id, ...t }))
    .sort((a, b) => new Date(b.at || 0) - new Date(a.at || 0));
  return { balance: Number(w.balance) || 0, updatedAt: w.updatedAt || null, txns };
}

/* ══════════════════════════════════════════════════════════════
   تسجيل حركة على المحفظة
   الرصيد بيتعدّل بـ runTransaction عشان لو اتنين بيعدّلوا في نفس
   اللحظة مايضيعش رقم — ده فلوس مش مجرد عرض.
══════════════════════════════════════════════════════════════ */
export async function addTxn(db, kind, id, { type, amount, note, by }) {
  const cfg = TXN_TYPES[type];
  if (!cfg) throw new Error("نوع حركة غير معروف: " + type);
  const amt = Math.abs(Number(amount) || 0);
  if (!amt) throw new Error("المبلغ لازم يكون أكبر من صفر");

  const base = walletPath(kind, id);
  const delta = cfg.sign * amt;

  const res = await runTransaction(ref(db, base + "/balance"),
    cur => Math.round(((Number(cur) || 0) + delta) * 100) / 100);
  const after = Number(res.snapshot.val()) || 0;

  const tRef = push(ref(db, base + "/txns"));
  await set(tRef, {
    amount: delta, type, note: (note || "").trim(),
    by: by || "", at: new Date().toISOString(), balanceAfter: after
  });
  await update(ref(db, base), { updatedAt: new Date().toISOString() });
  return after;
}

/* ══════════════════════════════════════════════════════════════
   عرض كارت المحفظة — بيرجّع HTML بس، التطبيق هو اللي بيربط الأزرار
   عن طريق data-w-act
══════════════════════════════════════════════════════════════ */
export function walletCardHtml(raw, opts = {}) {
  const w = readWallet(raw);
  const pos = w.balance > 0, neg = w.balance < 0;
  const color = pos ? "var(--green)" : neg ? "var(--brand)" : "var(--muted)";
  const label = pos ? "رصيد مستحق له" : neg ? "مستحق علينا منه" : "المحفظة فاضية";

  const rows = w.txns.slice(0, opts.limit || 8).map(t => {
    const c = TXN_TYPES[t.type] || { t: t.type, icon: "•", color: "var(--muted)" };
    const up = Number(t.amount) > 0;
    return `<div class="kv">
      <span class="k">${c.icon} ${c.t}${t.note ? ` — ${escHtml(t.note)}` : ""}
        <div style="font-size:11px;color:var(--muted);margin-top:2px">
          ${t.at ? new Date(t.at).toLocaleString("ar-EG") : ""}${t.by ? ` · ${escHtml(t.by)}` : ""}</div></span>
      <span class="v" style="color:${up ? "var(--green)" : "var(--brand)"};font-weight:800;white-space:nowrap">
        ${up ? "+" : "−"}${money(Math.abs(t.amount))}</span>
    </div>`;
  }).join("");

  return `
    <div class="card">
      <div class="card-h">👛 المحفظة</div>
      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:4px">
        <div>
          <div style="font-size:27px;font-weight:900;color:${color};direction:ltr;text-align:start">
            ${money(w.balance)} <span style="font-size:14px">ج.م</span></div>
          <div style="font-size:12px;color:var(--muted)">${label}</div>
        </div>
        <div style="margin-inline-start:auto;display:flex;gap:7px;flex-wrap:wrap">
          <button class="btn" data-w-act="credit">➕ إضافة رصيد</button>
          <button class="btn" data-w-act="discount">🎁 خصم / أوردر مجاني</button>
          <button class="btn" data-w-act="debit">➖ خصم من الرصيد</button>
        </div>
      </div>
      ${w.balance > 0 ? `<div style="background:var(--green-soft);color:var(--green);border-radius:9px;
        padding:9px 12px;font-size:12.5px;font-weight:700;margin-top:10px">
        ✅ الرصيد ده بيتخصم تلقائيًا من أوردراته الجاية لحد ما يخلص</div>` : ""}
      ${rows ? `<div style="margin-top:12px">${rows}</div>` : `
        <div class="empty" style="padding:20px">مفيش حركات على المحفظة</div>`}
    </div>`;
}

/** نموذج إضافة حركة — بيرجّع HTML للنافذة */
export function walletFormHtml(type) {
  const c = TXN_TYPES[type] || TXN_TYPES.credit;
  const hint = type === "discount"
    ? "المبلغ ده هيتحط في محفظته كرصيد، ويتخصم من أوردراته الجاية — يعني أوردر مجاني أو خصم."
    : type === "debit" ? "هيتخصم من رصيده الحالي."
    : "هيتضاف لرصيده ويقدر يستخدمه في أوردراته.";
  return `
    <p style="font-size:13px;color:var(--muted);line-height:1.9;margin-bottom:6px">${hint}</p>
    <input id="wAmt" type="number" min="0" step="0.5" placeholder="المبلغ بالجنيه" dir="ltr" />
    <textarea id="wNote" rows="2" placeholder="السبب (اختياري) — مثال: تعويض عن تأخير"></textarea>
    <div style="display:flex;gap:9px;margin-top:14px">
      <button class="btn" style="flex:1" onclick="closeM()">إلغاء</button>
      <button class="btn pri" style="flex:1" id="wSave">${c.icon} ${c.t}</button>
    </div>`;
}

function escHtml(s) {
  return String(s ?? "").replace(/[&<>"']/g, c =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}
