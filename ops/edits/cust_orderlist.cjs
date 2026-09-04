/* صفحة الطلبات: صف لكل **طرد** مش لكل أوردر.
 *
 * ═══ البلاغ ═══
 * «الصفحة اللي فيها الأوردرات لسه مش بتعرض الشغل صح».
 *
 * ═══ اللي كان بيحصل ═══
 * الصفحة كانت بتعرض صف واحد لكل أوردر، وبتقرا `myParcel(o)` — يعني
 * **أول طرد بس**. الأوردر اللي فيه تلات طرود لتلات ناس مختلفة كان بيبان
 * صف واحد باسم أول مستلم وسعره هو، والاتنين التانيين مالهمش أثر على
 * الشاشة خالص.
 *
 * وده كان بيتناقض مع باقي التطبيق بعد تعديلات النهارده:
 *   • الإحصائيات بتعدّ الطرود (٣)
 *   • الإشعارات بتعرض كارت لكل طرد (٣)
 *   • وصفحة الطلبات صف واحد (١)
 *
 * ═══ اللي بقى ═══
 * صف لكل طرد: رقمه المركّب، مستلمه، سعره، وحالته هو.
 * الحالة: نهاية الطرد بتغلب (اتسلّم / مااتسلّمش)، وغير كده بياخد حالة
 * الرحلة المشتركة — نفس قاعدة الإشعارات بالظبط.
 * والفلاتر فوق بتفلتر بحالة **الطرد** مش الأوردر.
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

/* ── ① دالة: حالة الطرد لوحده ── */
one(`function renderOrders() {`,
`/* حالة الطرد من منظور العميل — نفس قاعدة الإشعارات:
   نهاية الطرد بتغلب على حالة الأوردر، وغير كده بياخد حالة الرحلة.
   السبب: الطيار بيخرج بالشحنة كلها مرة واحدة (حالة مشتركة)، لكن كل طرد
   بيتسلّم لوحده (نهاية خاصة). */
function parcelState(o, d) {
  if ((o.status || "") === "ملغي") return "cancel";
  if (d && d.status === "تم التسليم")     return "done";
  if (d && d.status === "لم يتم التوصيل") return "failed";
  return orderState(o);
}

/* كل طرود الأوردر اللي تخص العميل، مع رقم كل واحد.
   الشحنة الجايّة: طروده هو بس — الأوردر ممكن يكون فيه طرود لناس تانية. */
function orderRows(o) {
  const all = o.deliveries || [];
  if (!all.length) return [{ o, d: {}, idx: 0, of: 1 }];
  const idx = o._incoming
    ? (Array.isArray(o._myParcels) && o._myParcels.length ? o._myParcels : [0])
    : all.map((_, i) => i);
  const mine = idx.filter(i => all[i]);
  return mine.map(i => ({ o, d: all[i], idx: i, of: mine.length }));
}

function renderOrders() {`,
'دالتين للطرود');

/* ── ② الصفوف ── */
one(`  const list = S.orders.filter(o => S.orderFilter === "all" || orderState(o) === S.orderFilter);
  if (!list.length) {
    $("ordersList").innerHTML = \`<div class="empty"><i>📦</i><b>لا توجد طلبات</b>
      <span>ابدأ بإنشاء طلبك الأول من زر "طلب جديد"</span></div>\`;
    return;
  }
  $("ordersList").innerHTML = list.map(o => {
    // في الشحنات الجاية له، بنعرض الطرد بتاعه هو مش أول طرد في الأوردر
    const d  = myParcel(o);
    const m  = STATE_META[orderState(o)];
    const dt = new Date(o.createdAt || 0);
    const isToday = dt.toDateString() === todayKey();
    // رقم الطرد المركّب لو الأوردر أكتر من طرد — نفس اللي على الباركود
    const num = (o._incoming && (o.deliveries || []).length > 1 && d.parcelNo)
      ? \`\${o.orderNum}-\${d.parcelNo}\` : (o.orderNum || "—");
    return \`<div class="o-item" data-id="\${esc(o.id)}">
      <div class="oi">\${o._incoming ? "📥" : (KIND_ICON[o.orderKind] || "📦")}</div>
      <div class="ob">
        <div class="r1"><span class="num">#\${esc(num)}</span>
          <span class="badge \${m.c}">\${m.t}</span></div>
        <div class="nm">\${o._incoming
          ? \`<span style="color:#22c55e">شحنة جاية لك</span> — من \${esc(o.senderName || "—")}\`
          : (d.receiverFromReceipt ? "🧾 مستلم من الريسيت" : esc(d.receiverName || "—"))}</div>
        <div class="r3"><span>\${fmtMoney(d.orderPrice)} ج.م</span>
          <span>\${isToday ? "اليوم" : dt.toLocaleDateString("ar-EG")} \${dt.toLocaleTimeString("ar-EG",{hour:"2-digit",minute:"2-digit"})}</span></div>
      </div></div>\`;
  }).join("");`,
`  /* 🔴 صف لكل **طرد**: الأوردر اللي فيه تلات طرود بيطلع تلات صفوف
     بأرقامهم ومستلميهم وأسعارهم. الفلتر بيشتغل على حالة الطرد نفسه،
     فالطرد اللي اتسلّم بيبان تحت «تم التسليم» وإخوته تحت «قيد التوصيل». */
  const rows = S.orders.flatMap(orderRows)
    .filter(r => S.orderFilter === "all" || parcelState(r.o, r.d) === S.orderFilter);
  if (!rows.length) {
    $("ordersList").innerHTML = \`<div class="empty"><i>📦</i><b>لا توجد طلبات</b>
      <span>ابدأ بإنشاء طلبك الأول من زر "طلب جديد"</span></div>\`;
    return;
  }
  $("ordersList").innerHTML = rows.map(r => {
    const o = r.o, d = r.d;
    const m  = STATE_META[parcelState(o, d)] || STATE_META.new;
    const dt = new Date(o.createdAt || 0);
    const isToday = dt.toDateString() === todayKey();
    /* الرقم المركّب لما يكون فيه أكتر من طرد — نفس اللي على الباركود
       ونفس اللي صفحة التتبّع العامة بتقبله. */
    const num = r.of > 1 && d.parcelNo
      ? \`\${o.orderNum}-\${d.parcelNo}\` : (o.orderNum || "—");
    return \`<div class="o-item" data-id="\${esc(o.id)}">
      <div class="oi">\${o._incoming ? "📥" : (KIND_ICON[o.orderKind] || "📦")}</div>
      <div class="ob">
        <div class="r1"><span class="num">#\${esc(num)}</span>
          <span class="badge \${m.c}">\${m.t}</span></div>
        <div class="nm">\${o._incoming
          ? \`<span style="color:var(--green)">شحنة جاية لك</span> — من \${esc(o.senderName || "—")}\`
          : (d.receiverFromReceipt ? "🧾 مستلم من الريسيت" : esc(d.receiverName || "—"))}\${
            r.of > 1 ? \` <span style="color:var(--dim)">· طرد \${r.idx + 1} من \${r.of}</span>\` : ""}</div>
        <div class="r3"><span>\${fmtMoney(d.orderPrice)} ج.م</span>
          <span>\${isToday ? "اليوم" : dt.toLocaleDateString("ar-EG")} \${dt.toLocaleTimeString("ar-EG",{hour:"2-digit",minute:"2-digit"})}</span></div>
      </div></div>\`;
  }).join("");`,
'صف لكل طرد');

fs.writeFileSync(f, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
console.log(bad ? `\n🔴 ${bad} مشكلة` : '\n✅ خلص');
process.exit(bad ? 1 : 0);
