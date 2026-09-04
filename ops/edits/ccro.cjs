/* تقليص كارت البحث السريع لـ«تفاصيل + شكوى» بس. */
const fs = require('fs');
const f = 'public/callcenter.html';
let s = fs.readFileSync(f, 'utf8');

const from = s.indexOf('        ${/* 🔴 الكارت ده كان «تفاصيل + شكوى» بس');
const to   = s.indexOf('        <button class="btn-ghost-cc btn-xs-cc" style="border-color:var(--orange);color:var(--orange)" onclick="openComplaintModal(');
if (from < 0 || to < 0 || to < from) { console.log('🔴 مالقيتش الحدود'); process.exit(1); }

const block = s.slice(from, to);
/* حارس: لازم يكون فيه الأربع دوال بالظبط — لو الملف اتغيّر نوقف */
for (const fn of ['openTransferOrderModal', 'postponeOrder', 'unpostponeOrder', 'cancelOrder'])
  if (!block.includes(fn)) { console.log('🔴 ' + fn + ' مش في النطاق — وقف'); process.exit(1); }
if (block.includes('viewOrderDetails') || block.includes('openComplaintModal')) {
  console.log('🔴 النطاق بياكل زرار مسموح — وقف'); process.exit(1);
}

const note = `        \${/* ═══ الكول سنتر بيشوف بس ═══
             موظف الكول سنتر بيتعامل مع العميل في حاجتين: استقبال الأوردر
             وتسجيل الشكوى. الإلغاء والتأجيل والنقل لفرع شغل باقي المنظومة
             (الفرع · الإدارة · الطيار)، فاتشالوا من هنا في **كل** حالات
             الأوردر — قرار صاحب النظام 2026-08-30.

             السيرفر لسه بيقبل الأفعال دي من دور الكول سنتر
             (routes/api.php: cancel 229 · postpone/unpostpone 230-231 ·
             transfer-branch 223). الواجهة بس هي اللي اتقفلت.
             الحارس: node ops/test_cc_readonly.cjs */""}
`;
s = s.slice(0, from) + note + s.slice(to);
fs.writeFileSync(f, s);
console.log('✓ اتشال ' + block.split('\n').length + ' سطر من trackCard');
