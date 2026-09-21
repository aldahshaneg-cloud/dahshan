/* عمود «ساعات إضافية» في ملفات الإكسل (تقفيل يومي · كشف الطيار · تقفيل الشهر) — مكمّل add_overtime_column.cjs */
const fs = require("fs");
const path = require("path");
const file = path.join(__dirname, "..", "..", "public", "accounts.html");
let s = fs.readFileSync(file, "utf8");
const eol = s.includes("\r\n") ? "\r\n" : "\n";
s = s.replace(/\r\n/g, "\n");

const reps = [
  [`"ساعة الانصراف", "عدد ساعات العمل", "عدد الأوردرات",`, `"ساعة الانصراف", "عدد ساعات العمل", "ساعات إضافية", "عدد الأوردرات",`, 1],
  [`  const rowX  = r => [r.in ?? "", permX(r, "out"), permX(r, "in"), r.out ?? "", fmt2(r.hours), r.orders ?? 0,`,
   `  const rowX  = (r, req) => [r.in ?? "", permX(r, "out"), permX(r, "in"), r.out ?? "", fmt2(r.hours), fmt2(paOt(r, req)), r.orders ?? 0,`, 1],
  [`    const t = { hours: 0, orders: 0, svc: 0, psvc: 0, net: 0, handed: 0, adv: 0, ded: 0, bonus: 0 };\n    (d.pilots || []).forEach(p => { const r = (p.days || [])[day - 1] || {}; aoa.push([p.name, ...rowX(r)]);`,
   `    const t = { hours: 0, orders: 0, svc: 0, psvc: 0, net: 0, handed: 0, adv: 0, ded: 0, bonus: 0 };\n    let otAll = 0;\n    (d.pilots || []).forEach(p => { const r = (p.days || [])[day - 1] || {}; const req = paReqHours(p, d); otAll += paOt(r, req); aoa.push([p.name, ...rowX(r, req)]);`, 1],
  [`    aoa.push(["الإجمالي", "", "", "", "", fmt2(t.hours), t.orders, fmt2(t.svc),`,
   `    aoa.push(["الإجمالي", "", "", "", "", fmt2(t.hours), fmt2(otAll), t.orders, fmt2(t.svc),`, 1],
  [`    (p.days || []).forEach(r => aoa.push([r.day, ...rowX(r)]));\n    aoa.push(["الإجمالي", "", "", "", "", fmt2(t.hours), t.orders ?? 0,`,
   `    (p.days || []).forEach(r => aoa.push([r.day, ...rowX(r, paReqHours(p, d))]));\n    aoa.push(["الإجمالي", "", "", "", "", fmt2(t.hours), fmt2(t.overtimeHours), t.orders ?? 0,`, 1],
  [`["الطيار", "الفرع", "الساعات", "أيام الشغل",`, `["الطيار", "الفرع", "الساعات", "ساعات إضافية", "أيام الشغل",`, 1],
  [`    const keys = ["hours", "worked", "orders", "hourPay",`, `    const keys = ["hours", "overtimeHours", "worked", "orders", "hourPay",`, 1],
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
