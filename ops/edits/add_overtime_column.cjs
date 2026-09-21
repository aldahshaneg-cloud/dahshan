/* عمود «ساعات إضافية» في جداول حسابات الطيارين والموظفين (طلب صاحب النظام 2026-09-21).
   استبدالات نصية بالحرف — كل واحدة لازم تتلاقى بالعدد المتوقع وإلا السكربت يقف من غير ما يكتب. */
const fs = require("fs");
const path = require("path");
const file = path.join(__dirname, "..", "..", "public", "accounts.html");
let s = fs.readFileSync(file, "utf8");
const eol = s.includes("\r\n") ? "\r\n" : "\n";
s = s.replace(/\r\n/g, "\n");

const OT_TH = `<th>ساعات إضافية<div class="pa-sub">فوق ساعات الوردية × ١٫٥</div></th>`;
const reps = [
  /* ── رؤوس الجداول ── */
  [`<th>عدد ساعات العمل<div class="pa-sub">ناقص الاستئذان</div></th>\n        <th>عدد الأوردرات</th>`,
   `<th>عدد ساعات العمل<div class="pa-sub">ناقص الاستئذان</div></th>\n        ${OT_TH}\n        <th>عدد الأوردرات</th>`, 1],
  [`<th>عدد ساعات العمل</th><th>عدد الأوردرات</th>`, `<th>عدد ساعات العمل</th><th>ساعات إضافية</th><th>عدد الأوردرات</th>`, 1],
  [`<th class="num">الساعات</th><th class="num">أيام الشغل</th><th class="num">الأوردرات</th>`,
   `<th class="num">الساعات</th><th class="num">ساعات إضافية</th><th class="num">أيام الشغل</th><th class="num">الأوردرات</th>`, 1],
  [`<th>عدد ساعات العمل<div class="pa-sub">ناقص الاستئذان</div></th>\n          <th>سلف</th>`,
   `<th>عدد ساعات العمل<div class="pa-sub">ناقص الاستئذان</div></th>\n          ${OT_TH}\n          <th>سلف</th>`, 1],
  [`<th>عدد ساعات العمل</th>\n          <th>سلف</th>`, `<th>عدد ساعات العمل</th><th>ساعات إضافية</th>\n          <th>سلف</th>`, 1],
  [`<th class="num">الساعات</th><th class="num">أيام الشغل</th>\n          <th class="num">أجر الساعات</th>`,
   `<th class="num">الساعات</th><th class="num">ساعات إضافية</th><th class="num">أيام الشغل</th>\n          <th class="num">أجر الساعات</th>`, 1],

  /* ── صفوف «جاري التحميل/فاضي» ── */
  [`<td colspan="16" class="empty-row">`, `<td colspan="17" class="empty-row">`, 4],
  [`<td colspan="15" class="empty-row">`, `<td colspan="16" class="empty-row">`, 3],
  [`<tbody id="stDailyBody"><tr><td colspan="12"`, `<tbody id="stDailyBody"><tr><td colspan="13"`, 1],
  [`<td colspan="11" class="empty-row">اختر موظفًا</td>`, `<td colspan="12" class="empty-row">اختر موظفًا</td>`, 2],
  [`<tbody id="stMonthBody"><tr><td colspan="13"`, `<tbody id="stMonthBody"><tr><td colspan="14"`, 1],
  [`body.innerHTML = \`<tr><td colspan="13" class="empty-row">مفيش موظفين</td></tr>\``, `body.innerHTML = \`<tr><td colspan="14" class="empty-row">مفيش موظفين</td></tr>\``, 1],
  [`body.innerHTML = \`<tr><td colspan="12" class="empty-row">مفيش موظفين</td></tr>\``, `body.innerHTML = \`<tr><td colspan="13" class="empty-row">مفيش موظفين</td></tr>\``, 1],

  /* ── خريطة قفل الأعمدة ── */
  [`[null, null, 'col.in', 'col.bout', 'col.bin', 'col.out', 'col.hours', 'col.orders',`,
   `[null, null, 'col.in', 'col.bout', 'col.bin', 'col.out', 'col.hours', 'col.hours', 'col.orders',`, 2],
  [`[null, null, null, 'mon.hours', 'mon.hours', 'mon.orders', 'mon.hourPay',`,
   `[null, null, null, 'mon.hours', 'mon.hours', 'mon.hours', 'mon.orders', 'mon.hourPay',`, 1],
  [`[null, null, null, 'col.in', 'col.bout', 'col.bin', 'col.out', 'col.hours',\n       'col.adv'`,
   `[null, null, null, 'col.in', 'col.bout', 'col.bin', 'col.out', 'col.hours', 'col.hours',\n       'col.adv'`, 1],
  [`[null, null, 'col.in', 'col.bout', 'col.bin', 'col.out', 'col.hours',\n       'col.adv'`,
   `[null, null, 'col.in', 'col.bout', 'col.bin', 'col.out', 'col.hours', 'col.hours',\n       'col.adv'`, 1],
  [`[null, null, null, null, 'mon.hours', 'mon.hours', 'mon.hourPay', 'mon.salary',`,
   `[null, null, null, null, 'mon.hours', 'mon.hours', 'mon.hours', 'mon.hourPay', 'mon.salary',`, 1],

  /* ── خلايا الصفوف ── */
  [`  function paRowCells(pid, row) {`,
   `  /* ⏱ ساعات إضافية (طلب صاحب النظام 2026-09-21: «اعمل عمود فيه عدد الساعات الإضافية»).
     نفس قاعدة السيرفر بالحرف (PilotAccountingWire::monthTotals): اللي فوق ساعات الوردية المطلوبة
     في **اليوم** — \`requiredDailyHours\` بتيجي في totals، والاحتياطي طول الوردية من الإعدادات.
     للقراءة بس: الرقم مشتق من «عدد ساعات العمل»، واللي عايز يغيّره يعدّل الساعات. */
  function paReqHours(person, d) {
    return Number(person?.totals?.requiredDailyHours) || Number(d?.settings?.shiftHours) || 10;
  }
  function paOt(row, req) {
    return Math.max(0, Math.round(((Number(row?.hours) || 0) - req) * 100) / 100);
  }
  function paOtCell(row, req) {
    const none = row.hours === undefined || row.hours === null;
    const ot = paOt(row, req);
    return \`<td class="pa-ro"><span style="display:block;text-align:center;font-weight:700;color:\${ ot > 0 ? "var(--orange)" : "var(--muted)" }">\${ none ? "—" : ot > 0 ? paMoney(ot) : "0" }</span></td>\`;
  }
  function paRowCells(pid, row, req) {`, 1],
  [`      + paCell(pid, row.day, "hours", row, { money: true })\n      + paCell(pid, row.day, "orders", row)`,
   `      + paCell(pid, row.day, "hours", row, { money: true })\n      + paOtCell(row, req)\n      + paCell(pid, row.day, "orders", row)`, 1],
  [`  function stRowCells(uid, row) {`, `  function stRowCells(uid, row, req) {`, 1],
  [`      + stCell(uid, row.day, "hours", row, { money: true })\n      + stCell(uid, row.day, "adv", row, { money: true })`,
   `      + stCell(uid, row.day, "hours", row, { money: true })\n      + paOtCell(row, req)\n      + stCell(uid, row.day, "adv", row, { money: true })`, 1],
  [`\${ paRowCells(p.pilotId, row) }</tr>\`;`, `\${ paRowCells(p.pilotId, row, paReqHours(p, d)) }</tr>\`;`, 1],
  [`\${ paRowCells(p.pilotId, row) }</tr>\`).join("");`, `\${ paRowCells(p.pilotId, row, paReqHours(p, d)) }</tr>\`).join("");`, 1],
  [`\${ stRowCells(u.userId, row) }</tr>\`;`, `\${ stRowCells(u.userId, row, paReqHours(u, d)) }</tr>\`;`, 1],
  [`\${ stRowCells(u.userId, row) }</tr>\`).join("");`, `\${ stRowCells(u.userId, row, paReqHours(u, d)) }</tr>\`).join("");`, 1],

  /* ── سطور الإجمالي ── */
  [`        <td class="pa-tot">\${ paMoney(t("hours")) }</td><td class="pa-tot">\${ paNum(t("orders")) }</td>`,
   `        <td class="pa-tot">\${ paMoney(t("hours")) }</td><td class="pa-tot" style="color:var(--orange)">\${ paMoney(ps.reduce((s, p) => s + paOt((p.days || [])[day - 1], paReqHours(p, d)), 0)) }</td><td class="pa-tot">\${ paNum(t("orders")) }</td>`, 1],
  [`        <td class="pa-tot">\${ paMoney(t.hours) }</td><td class="pa-tot">\${ paNum(t.orders) }</td>`,
   `        <td class="pa-tot">\${ paMoney(t.hours) }</td><td class="pa-tot" style="color:var(--orange)">\${ paMoney(t.overtimeHours) }</td><td class="pa-tot">\${ paNum(t.orders) }</td>`, 1],
  [`        <td class="pa-tot">\${ paMoney(t("hours")) }</td>\n        <td class="pa-tot">\${ paMoney(t("adv")) }</td>`,
   `        <td class="pa-tot">\${ paMoney(t("hours")) }</td><td class="pa-tot" style="color:var(--orange)">\${ paMoney(us.reduce((s, u) => s + paOt((u.days || [])[day - 1], paReqHours(u, d)), 0)) }</td>\n        <td class="pa-tot">\${ paMoney(t("adv")) }</td>`, 1],
  [`        <td class="pa-tot">\${ paMoney(t.hours) }</td>\n        <td class="pa-tot">\${ paMoney(t.adv) }</td>`,
   `        <td class="pa-tot">\${ paMoney(t.hours) }</td><td class="pa-tot" style="color:var(--orange)">\${ paMoney(t.overtimeHours) }</td>\n        <td class="pa-tot">\${ paMoney(t.adv) }</td>`, 1],

  /* ── تقفيلة الشهر: السطر الصغير «+x إضافي» بقى عمود لوحده ── */
  [`        <td class="num">\${ paMoney(t.hours) }\${ Number(t.overtimeHours) > 0 ? \`<div class="pa-sub" style="color:var(--orange)">+\${ paMoney(t.overtimeHours) } إضافي</div>\` : "" }</td>`,
   `        <td class="num">\${ paMoney(t.hours) }</td>\n        <td class="num" style="color:\${ Number(t.overtimeHours) > 0 ? "var(--orange)" : "var(--muted)" };font-weight:700">\${ paMoney(t.overtimeHours) }</td>`, 2],
  [`        <td class="num pa-tot">\${ paMoney(s("hours")) }</td><td class="num pa-tot">\${ paNum(s("worked")) }</td>`,
   `        <td class="num pa-tot">\${ paMoney(s("hours")) }</td><td class="num pa-tot" style="color:var(--orange)">\${ paMoney(s("overtimeHours")) }</td><td class="num pa-tot">\${ paNum(s("worked")) }</td>`, 1],
  [`        <td class="num pa-tot">\${ paMoney(sm("hours")) }</td><td class="num pa-tot">\${ paNum(sm("worked")) }</td>`,
   `        <td class="num pa-tot">\${ paMoney(sm("hours")) }</td><td class="num pa-tot" style="color:var(--orange)">\${ paMoney(sm("overtimeHours")) }</td><td class="num pa-tot">\${ paNum(sm("worked")) }</td>`, 1],
];

let bad = 0;
for (const [from, to, n] of reps) {
  const c = s.split(from).length - 1;
  if (c !== n) { console.error(`✗ متوقع ${n} لقيت ${c}: ${from.slice(0, 90)}`); bad++; continue; }
  s = s.split(from).join(to);
}
if (bad) { console.error(`وقف — ${bad} استبدال مش مطابق، الملف ما اتكتبش`); process.exit(1); }
fs.writeFileSync(file, s.replace(/\n/g, eol));
console.log(`✓ ${reps.length} استبدال`);
