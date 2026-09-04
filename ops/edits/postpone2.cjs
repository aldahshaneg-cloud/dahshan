/* نفس إضافة «تأجيل / فك تأجيل» — لوحة الإدارة (tiar.html).
   نفس اللسعة زي الفرع: `pending` بيفلتر «قيد التنفيذ» بالظبط، فالمؤجل
   مابيظهرش في أي جدول. */
const fs = require('fs');
const f = 'public/tiar.html';
let s = fs.readFileSync(f, 'utf8');
const eol = s.includes('\r\n') ? '\r\n' : '\n';
if (eol === '\r\n') s = s.replace(/\r\n/g, '\n');

const FNS = `
    /* ══ تأجيل الأوردر ══
       السيرفر بيحفظ الحالة السابقة في prev_status وبيرجّعها عند فك
       التأجيل، فمابنبعتش الحالة من هنا. متاح للأوردر غير المحمّل بس
       (قيد التنفيذ · لم يتم التوصيل) — وأي حاجة تانية بيردّ 409. */
    window.postponeOrder = async function(orderId) {
      const o = (window._ordersData || []).find(x => x.id === orderId);
      if (!confirm("تأجيل الطلب " + (o?.orderNum || "") + "؟\\n" +
                   "هيفضل في الطلبات النشطة بحالة «مؤجل» لحد ما تفك التأجيل.")) return;
      try {
        await api.post(\`/api/orders/\${orderId}/postpone\`, {});
        showToast("تمّ تأجيل الطلب ✓", "success");
        /* الجدول بيتحدّث لوحده: السيرفر بيعمل broadcastOrder واللوحة مشتركة في البث. */
      } catch (e) { showToast("خطأ أثناء التأجيل: " + e.message, "error"); }
    };

    /* ══ فك التأجيل ══ — السيرفر بيرجّع الأوردر لحالته السابقة لوحده */
    window.unpostponeOrder = async function(orderId) {
      try {
        await api.post(\`/api/orders/\${orderId}/unpostpone\`, {});
        showToast("تمّ فك التأجيل ✓ — رجع لحالته السابقة", "success");
      } catch (e) { showToast("خطأ أثناء فك التأجيل: " + e.message, "error"); }
    };
`;

const PAIRS = [
  /* ① المؤجل يدخل جدول «قيد التنفيذ» */
  [`      const pending   = list.filter(o => o.status === "قيد التنفيذ");`,
   `      /* «مؤجل» بيتعرض مع «قيد التنفيذ»: نفس المرحلة بس متوقّفة مؤقتًا.
         باقي الجداول بتفلتر حالات تانية، فلو مابانش هنا مايبقاش في أي
         جدول — وفك التأجيل مايبقاش ليه باب. */
      const pending   = list.filter(o => o.status === "قيد التنفيذ" || o.status === "مؤجل");`],

  /* ② أزرار الصف */
  [`              <button class="view-btn" onclick="viewOrderDetails('\${ escJs(o.id) }')">🔍 تفاصيل</button>
              <button class="assign-btn" onclick="assignPilotToOrder('\${ escJs(o.id) }')">🛵 تحميل</button>
              <button class="del-btn" style="background:rgba(249,115,22,.15);color:var(--orange);border:1px solid var(--orange)" onclick="openTransferOrderModal('\${ escJs(o.id) }')">🔁 نقل لفرع آخر</button>`,
   `              <button class="view-btn" onclick="viewOrderDetails('\${ escJs(o.id) }')">🔍 تفاصيل</button>
              \${o.status === "مؤجل"
                /* المؤجل: فك التأجيل بس — التحميل والنقل بيرجّعوا 409 وهو
                   في الحالة دي، فمابنعرضهمش. */
                ? \`<button class="assign-btn" style="background:rgba(34,197,94,.15);color:var(--green);border:1px solid var(--green)" onclick="unpostponeOrder('\${ escJs(o.id) }')">▶️ فك التأجيل</button>\`
                : \`<button class="assign-btn" onclick="assignPilotToOrder('\${ escJs(o.id) }')">🛵 تحميل</button>
              <button class="del-btn" style="background:rgba(249,115,22,.15);color:var(--orange);border:1px solid var(--orange)" onclick="openTransferOrderModal('\${ escJs(o.id) }')">🔁 نقل لفرع آخر</button>
              <button class="del-btn" style="background:rgba(168,85,247,.15);color:#a855f7;border:1px solid #a855f7" onclick="postponeOrder('\${ escJs(o.id) }')">⏸️ تأجيل</button>\`}`],

  /* ③ جدول «لم يتم التوصيل» */
  [`                <button class="view-btn" onclick="viewOrderDetails('\${ escJs(o.id) }')">🔍 تفاصيل</button>
                <button class="transfer-btn" style="background:rgba(249,115,22,.15);color:var(--orange);border:1px solid var(--orange)" onclick="transferOrderPilot('\${ escJs(o.id) }')">🔄 نقل لطيار آخر</button>
                <button class="del-btn" style="background:rgba(239,68,68,.15);color:var(--red);border:1px solid var(--red)" onclick="cancelOrder('\${ escJs(o.id) }')">❌ إلغاء</button>
                <button class="del-btn" onclick="deleteOrder('\${ escJs(o.id) }')">حذف</button>
                <button class="finish-btn" style="margin-right:4px"`,
   `                <button class="view-btn" onclick="viewOrderDetails('\${ escJs(o.id) }')">🔍 تفاصيل</button>
                <button class="transfer-btn" style="background:rgba(249,115,22,.15);color:var(--orange);border:1px solid var(--orange)" onclick="transferOrderPilot('\${ escJs(o.id) }')">🔄 نقل لطيار آخر</button>
                <button class="del-btn" style="background:rgba(168,85,247,.15);color:#a855f7;border:1px solid #a855f7" onclick="postponeOrder('\${ escJs(o.id) }')">⏸️ تأجيل</button>
                <button class="del-btn" style="background:rgba(239,68,68,.15);color:var(--red);border:1px solid var(--red)" onclick="cancelOrder('\${ escJs(o.id) }')">❌ إلغاء</button>
                <button class="del-btn" onclick="deleteOrder('\${ escJs(o.id) }')">حذف</button>
                <button class="finish-btn" style="margin-right:4px"`],

  /* ④ الدوال — جنب cancelOrder */
  [`    window.cancelOrder = async function (id) {`,
   FNS + `
    window.cancelOrder = async function (id) {`],
];

let bad = 0;
for (const [old, neu] of PAIRS) {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log(`🔴 قطعة اتلقت ${n} مرة — «${old.trim().slice(0, 60)}»`); bad++; continue; }
  s = s.replace(old, neu);
}
if (bad) { console.log('\nوقفت — مافيش تعديل اتكتب.'); process.exit(1); }
fs.writeFileSync(f, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
console.log('✓ tiar.html');
