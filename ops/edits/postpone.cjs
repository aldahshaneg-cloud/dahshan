/* إضافة «تأجيل / فك تأجيل» لتطبيق الفرع ولوحة الإدارة.
 *
 * ═══ الخلفية ═══
 * الميزة موجودة على السيرفر من زمان (postpone / unpostpone) لكن الزرار
 * الوحيد اللي بيوصّلها كان في كارت البحث السريع بتاع الكول سنتر — واتشال
 * بقرار صاحب النظام. من غير الإضافة دي الميزة بتختفي من المنظومة كلها.
 *
 * ═══ اللسعة اللي لازم تتحل مع الزرار ═══
 * `renderOrdersPage` في الفرع بتفلتر `status === "قيد التنفيذ"` بالظبط،
 * وباقي الجداول بتفلتر حالات تانية. يعني أوردر حالته «مؤجل» **مابيظهرش
 * في أي جدول خالص** — فزرار «فك التأجيل» لوحده مالوش لازمة، لازم الأوردر
 * المؤجل يبان الأول.
 *
 * قواعد السيرفر (OrdersController):
 *   postpone   → processing | undelivered   فقط
 *   unpostpone → postponed                  فقط، وبيرجّع prev_status
 *   cancel     → أي حاجة غير cancelled/delivered
 */
const fs = require('fs');

let done = 0, skipped = 0;
const edit = (file, label, pairs) => {
  let s = fs.readFileSync(file, 'utf8');
  const eol = s.includes('\r\n') ? '\r\n' : '\n';
  if (eol === '\r\n') s = s.replace(/\r\n/g, '\n');
  for (const [old, neu] of pairs) {
    const n = s.split(old).length - 1;
    if (n !== 1) { console.log(`  🔴 ${label}: قطعة اتلقت ${n} مرة — «${old.trim().slice(0, 55)}»`); skipped++; return; }
    s = s.replace(old, neu);
  }
  fs.writeFileSync(file, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
  console.log('  ✓ ' + label);
  done++;
};

/* ── الدوال المشتركة: نفس النص في الملفين ── */
const FNS = (apiCall, refresh) => `
    /* ══ تأجيل الأوردر ══
       السيرفر بيحفظ الحالة السابقة في prev_status وبيرجّعها عند فك
       التأجيل، فمابنبعتش الحالة من هنا. متاح للأوردر غير المحمّل بس
       (قيد التنفيذ · لم يتم التوصيل) — وأي حاجة تانية بيردّ 409. */
    window.postponeOrder = async function(orderId) {
      const o = (window._ordersData || []).find(x => x.id === orderId);
      if (!confirm("تأجيل الطلب " + (o?.orderNum || "") + "؟\\n" +
                   "هيفضل في الطلبات النشطة بحالة «مؤجل» لحد ما تفك التأجيل.")) return;
      try {
        await ${apiCall}(\`/api/orders/\${orderId}/postpone\`, {});
        showToast("تمّ تأجيل الطلب ✓", "success");
        ${refresh}
      } catch (e) { showToast("خطأ أثناء التأجيل: " + e.message, "error"); }
    };

    /* ══ فك التأجيل ══ — السيرفر بيرجّع الأوردر لحالته السابقة لوحده */
    window.unpostponeOrder = async function(orderId) {
      try {
        await ${apiCall}(\`/api/orders/\${orderId}/unpostpone\`, {});
        showToast("تمّ فك التأجيل ✓ — رجع لحالته السابقة", "success");
        ${refresh}
      } catch (e) { showToast("خطأ أثناء فك التأجيل: " + e.message, "error"); }
    };
`;

/* ════════════════ تطبيق الفرع ════════════════ */
edit('public/branch.html', 'branch.html', [
  /* ① الجدول النشط يشوف المؤجل كمان — من غير كده الأوردر بيختفي خالص */
  [`    function renderOrdersPage() {
      const active = window._ordersData.filter(o => o.status === "قيد التنفيذ");`,
   `    function renderOrdersPage() {
      /* «مؤجل» بيتعرض مع «قيد التنفيذ»: هو نفس المرحلة بس متوقّفة مؤقتًا.
         باقي الجداول بتفلتر حالات تانية، فلو مابانش هنا مايبقاش في أي
         جدول — وزرار «فك التأجيل» مايبقاش ليه باب أصلًا. */
      const active = window._ordersData.filter(o =>
        o.status === "قيد التنفيذ" || o.status === "مؤجل");`],

  [`        setHtml(tbody, \`<tr><td colspan="11" class="empty-row">لا توجد طلبات قيد التنفيذ لهذا الفرع</td></tr>\`);`,
   `        setHtml(tbody, \`<tr><td colspan="11" class="empty-row">لا توجد طلبات قيد التنفيذ أو مؤجلة لهذا الفرع</td></tr>\`);`],

  /* ② أزرار الصف: المؤجل ليه أزرار مختلفة */
  [`              <button class="view-btn" onclick="viewOrderDetails('\${ escJs(o.id) }')">🔍 تفاصيل</button>
              <button class="assign-btn" onclick="assignPilotToOrder('\${ escJs(o.id) }')">🛵 تحميل</button>
              <button class="del-btn" style="background:rgba(249,115,22,.15);color:var(--orange);border:1px solid var(--orange)" onclick="openTransferOrderModal('\${ escJs(o.id) }')">🔁 نقل لفرع آخر</button>`,
   `              <button class="view-btn" onclick="viewOrderDetails('\${ escJs(o.id) }')">🔍 تفاصيل</button>
              \${o.status === "مؤجل"
                /* المؤجل: فك التأجيل بس — التحميل والنقل بيرجّعوا 409 من
                   السيرفر وهو في الحالة دي، فمابنعرضهمش أصلًا. */
                ? \`<button class="assign-btn" style="background:rgba(34,197,94,.15);color:var(--green);border:1px solid var(--green)" onclick="unpostponeOrder('\${ escJs(o.id) }')">▶️ فك التأجيل</button>\`
                : \`<button class="assign-btn" onclick="assignPilotToOrder('\${ escJs(o.id) }')">🛵 تحميل</button>
              <button class="del-btn" style="background:rgba(249,115,22,.15);color:var(--orange);border:1px solid var(--orange)" onclick="openTransferOrderModal('\${ escJs(o.id) }')">🔁 نقل لفرع آخر</button>
              <button class="del-btn" style="background:rgba(168,85,247,.15);color:#a855f7;border:1px solid #a855f7" onclick="postponeOrder('\${ escJs(o.id) }')">⏸️ تأجيل</button>\`}`],

  /* ③ جدول «لم يتم التوصيل» — التأجيل مسموح فيه على السيرفر */
  [`              <button class="view-btn" onclick="viewOrderDetails('\${ escJs(o.id) }')">🔍 تفاصيل</button>
              <button class="transfer-btn" onclick="retryDeliveryAssign('\${ escJs(o.id) }')">🔄 إعادة تحميل على طيار</button>`,
   `              <button class="view-btn" onclick="viewOrderDetails('\${ escJs(o.id) }')">🔍 تفاصيل</button>
              <button class="transfer-btn" onclick="retryDeliveryAssign('\${ escJs(o.id) }')">🔄 إعادة تحميل على طيار</button>
              <button class="del-btn" style="background:rgba(168,85,247,.15);color:#a855f7;border:1px solid #a855f7" onclick="postponeOrder('\${ escJs(o.id) }')">⏸️ تأجيل</button>`],

  /* ④ الدوال */
  [`    window.cancelOrderBranch = async function(orderId) {`,
   FNS('api.post', '/* الجدول بيتحدّث لوحده: السيرفر بيعمل broadcastOrder والفرع مشترك في البث (زي assign بالظبط). */') + `
    window.cancelOrderBranch = async function(orderId) {`],
]);

console.log(`\n${done} ملف اتعدّل · ${skipped} اتخطّى`);
process.exit(skipped ? 1 : 0);
