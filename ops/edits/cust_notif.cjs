/* إشعارات تطبيق العميل: كارت لكل **طرد** مش لكل أوردر.
 *
 * ═══ البلاغ ═══
 * «لما بعمل أوردر فيه أكتر من طرد، الإشعارات بتبيّنهم طرد واحد. لازم
 *  يظهروا بعددهم الحقيقي وبأرقامهم، ولما طيار يشيل واحد منهم يظهر
 *  بالشكل الطبيعي».
 *
 * ═══ إزاي بيشتغل ═══
 * السيرفر بيبعت الإشعارات **على مستوى الأوردر** (created · assigned ·
 * received · out · delivered…) — وده صح: الطيار بيستلم الشحنة كلها
 * ويخرج بيها مرة واحدة. اللي بيتفرّق هو **نهاية كل طرد**:
 * `order_deliveries.status` بياخد processing → delivered | undelivered
 * لكل طرد لوحده.
 *
 * فالكارت بيتبني من الاتنين:
 *   • خطوات الرحلة المشتركة (إسناد · استلام · خروج) من إشعار الأوردر
 *   • ونهاية الطرد نفسه من حالته هو
 * يعني طرد اتسلّم يبان «تم التسليم» وأخوه لسه في الطريق يبان «الطيار في
 * الطريق» — في نفس الأوردر.
 *
 * الرقم بيبقى `{رقم الأوردر}-{رقم الطرد}` — نفس الصيغة اللي صفحة التتبّع
 * العامة بتقبلها، فالعميل يقدر ينسخه ويتتبّع بيه.
 *
 * ⚠️ الشحنة الجايّة (حد باعتله): بنعرض **طروده هو بس** (`_myParcels`) —
 * الأوردر ممكن يكون فيه طرود لناس تانية ومش من حقه يشوفها.
 */
const fs = require('fs');
const f = 'public/customer.html';
let s = fs.readFileSync(f, 'utf8');
const eol = s.includes('\r\n') ? '\r\n' : '\n';
if (eol === '\r\n') s = s.replace(/\r\n/g, '\n');

let bad = 0;
const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log(`  🔴 ${label}: اتلقت ${n} مرة`); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};

/* ── ① دالة: طرود الأوردر اللي تخص العميل ── */
one(`function renderNotifs() {`,
`/* طرود الأوردر اللي تخص العميل — مع رقم كل طرد وحالته.
   بترجّع [] لو الأوردر مش موجود في القايمة (الإشعار وصل قبل البيانات). */
function myParcelsOf(orderId) {
  const o = (S.orders || []).find(x => String(x.id) === String(orderId));
  if (!o) return [];
  const all = o.deliveries || [];
  if (!all.length) return [];
  /* الشحنة الجايّة: طروده هو بس */
  const idx = o._incoming ? (o._myParcels || [0]) : all.map((_, i) => i);
  return idx.map(i => all[i]).filter(Boolean);
}

/* نهاية الطرد بتغلب على حالة الأوردر: طرد اتسلّم يفضل «تم التسليم» حتى
   لو الأوردر لسه بيتحرّك لطرود تانية. و«قيد التنفيذ» معناها لسه ماخلصش
   — ساعتها بنعرض آخر خطوة مشتركة من إشعار الأوردر. */
function parcelNotifKey(parcel, orderKey) {
  const st = parcel?.status;
  if (st === "تم التسليم")    return "delivered";
  if (st === "لم يتم التوصيل") return "failed";
  return orderKey;
}

function renderNotifs() {`,
'دالتين مساعدتين');

/* ── ② الكروت: واحد لكل طرد ── */
one(`    const groups = [...byOrder.values()].sort((a, b) => (b.latest.at > a.latest.at ? 1 : -1));
    const STEPS = ["created", "assigned", "received", "out", "delivered"];
    $("notifList").innerHTML = groups.map(g => {
      const n = g.latest;
      const m = NOTIF_META[n.key] || { i: "🔔", c: "var(--red)" };
      const unread = !last || n.at > last;
      const ended = g.keys.has("failed") || g.keys.has("cancel");
      const dots = STEPS.map(k => {
        if (ended) return \`<i class="bad"></i>\`;
        if (k === "delivered" && g.keys.has("delivered")) return \`<i class="ok"></i>\`;
        return \`<i class="\${g.keys.has(k) ? "on" : ""}"></i>\`;
      }).join("");
      return \`
      <div class="nt \${unread ? "unread" : ""}" data-id="\${esc(g.orderId)}">
        <div class="ni" style="background:rgba(232,25,44,.12);color:\${m.c}">\${m.i}</div>
        <div style="flex:1;min-width:0"><b>\${esc(n.title)}</b>
          <span>#\${esc(g.num || "")} · \${fmtTime(n.at)}</span>
          <div class="dots">\${dots}</div>
          \${n.key === "out" ? \`<span class="go">🗺️ اضغط لمتابعة الطيار على الخريطة</span>\` : ""}</div></div>\`;
    }).join("");`,
`    const groups = [...byOrder.values()].sort((a, b) => (b.latest.at > a.latest.at ? 1 : -1));
    const STEPS = ["created", "assigned", "received", "out", "delivered"];

    /* 🔴 كارت لكل **طرد** مش لكل أوردر: الأوردر اللي فيه تلات طرود بيطلع
       تلات كروت بأرقامهم (…-1 · …-2 · …-3). الأوردر بطرد واحد بيفضل
       كارت واحد بالرقم العادي زي ما كان. */
    const cards = [];
    groups.forEach(g => {
      const n = g.latest;
      const ended = g.keys.has("failed") || g.keys.has("cancel");
      const parcels = myParcelsOf(g.orderId);
      const multi = parcels.length > 1;
      /* أوردر بطرد واحد (أو لسه بياناته ما وصلتش) = كارت واحد بالسلوك القديم */
      const items = multi ? parcels : [null];

      items.forEach((p, i) => {
        const key = p ? parcelNotifKey(p, n.key) : n.key;
        const m = NOTIF_META[key] || { i: "🔔", c: "var(--red)" };
        const title = (NOTIF_META[key] || {}).t || n.title;
        const unread = !last || n.at > last;
        const num = multi ? \`\${g.num || ""}-\${p.parcelNo ?? (i + 1)}\` : (g.num || "");
        /* نقط التقدّم: الخطوات المشتركة من الأوردر، وآخر نقطة من الطرد نفسه */
        const dots = STEPS.map(k => {
          if (key === "failed" || (ended && !p)) return \`<i class="bad"></i>\`;
          if (k === "delivered") return key === "delivered" ? \`<i class="ok"></i>\` : \`<i></i>\`;
          return \`<i class="\${g.keys.has(k) ? "on" : ""}"></i>\`;
        }).join("");
        cards.push(\`
      <div class="nt \${unread ? "unread" : ""}" data-id="\${esc(g.orderId)}">
        <div class="ni" style="background:rgba(232,25,44,.12);color:\${m.c}">\${m.i}</div>
        <div style="flex:1;min-width:0"><b>\${esc(title)}</b>
          <span>#\${esc(num)}\${multi ? \` · طرد \${i + 1} من \${parcels.length}\` : ""} · \${fmtTime(n.at)}</span>
          <div class="dots">\${dots}</div>
          \${key === "out" ? \`<span class="go">🗺️ اضغط لمتابعة الطيار على الخريطة</span>\` : ""}</div></div>\`);
      });
    });
    $("notifList").innerHTML = cards.join("");`,
'كارت لكل طرد');

/* ── ③ عدّاد غير المقروء يعدّ الطرود ── */
one(`function unreadOrders() {
  const list = S.notifs || []; if (!list.length) return S.notifUnread || 0;
  const last = S.notifSeenAt; const seen = new Set();
  list.forEach(n => { if (!last || n.at > last) seen.add(String(n.orderId)); });
  return seen.size;
}`,
`function unreadOrders() {
  const list = S.notifs || []; if (!list.length) return S.notifUnread || 0;
  const last = S.notifSeenAt; const seen = new Set();
  list.forEach(n => { if (!last || n.at > last) seen.add(String(n.orderId)); });
  /* الرقم لازم يطابق عدد الكروت على الشاشة — والكروت بقت لكل طرد.
     أوردر بتلات طرود = تلات كروت = تلاتة في العدّاد. */
  let n = 0;
  seen.forEach(id => { n += Math.max(1, myParcelsOf(id).length); });
  return n;
}`,
'عدّاد غير المقروء');

fs.writeFileSync(f, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
console.log(bad ? `\n🔴 ${bad} مشكلة` : '\n✅ خلص');
process.exit(bad ? 1 : 0);
