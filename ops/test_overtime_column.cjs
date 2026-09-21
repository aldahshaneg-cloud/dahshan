/* 🛡 حارس عمود «ساعات إضافية» في حسابات الطيارين والموظفين (طلب صاحب النظام 2026-09-21).
   بيتأكد إن: العمود موجود في الجداول الستة وملفات الإكسل · عدد `<th>` = عدد أعمدة PA_GRID
   (وإلا قفل الأعمدة بيزحلق) · والحسبة نفس قاعدة السيرفر (فوق ساعات الوردية في اليوم). */
const fs = require("fs");
const path = require("path");
const vm = require("vm");
const html = fs.readFileSync(path.join(__dirname, "..", "public", "accounts.html"), "utf8");

let pass = 0, fail = 0;
const ok = (c, m) => { if (c) { pass++; } else { fail++; console.log("  ✗ " + m); } };

/* 1) رؤوس الجداول + عدد الأعمدة مطابق للخريطة */
const grid = { paDailyBody: 17, paPilotBody: 17, paMonthBody: 16, stDailyBody: 13, stPersonBody: 12, stMonthBody: 14 };
for (const [id, n] of Object.entries(grid)) {
  const at = html.indexOf(`<tbody id="${id}"`);
  const head = html.slice(html.lastIndexOf("<thead>", at), at);
  ok((head.match(/<th[\s>]/g) || []).length === n, `${id}: عدد <th> = ${n}`);
  ok(head.includes("ساعات إضافية"), `${id}: فيه عمود «ساعات إضافية»`);
  ok(html.slice(at, at + 120).includes(`colspan="${n}"`), `${id}: colspan الصف الفاضي = ${n}`);
}
const gsrc = html.slice(html.indexOf("const PA_GRID = {"), html.indexOf("window.applyPaAcl"));
const names = { daily: 17, pilot: 17, month: 16, stdaily: 13, stperson: 12, stmonth: 14 };
for (const [name, n] of Object.entries(names)) {
  const m = gsrc.match(new RegExp(name + ":\\s*\\{[^\\[]*\\[([^\\]]*)\\]"));
  ok(m && m[1].split(",").filter(x => x.trim()).length === n, `PA_GRID.${name}: ${n} عمود`);
}

/* 2) الحسبة — نفس قاعدة PilotAccountingWire::monthTotals */
const src = html.slice(html.indexOf("function paReqHours("), html.indexOf("function paOtCell("));
const ctx = {}; vm.createContext(ctx); vm.runInContext(src + "; this.paReqHours = paReqHours; this.paOt = paOt;", ctx);
ok(ctx.paOt({ hours: 10.76 }, 10) === 0.76, "10.76 ساعة على وردية 10 = 0.76 إضافي");
ok(ctx.paOt({ hours: 9.5 }, 10) === 0, "أقل من الوردية = صفر");
ok(ctx.paOt({}, 10) === 0 && ctx.paOt(undefined, 10) === 0, "يوم فاضي = صفر");
ok(ctx.paOt({ hours: 10 }, 8) === 2, "عتبة الطيار الخاصة (8) بتتحترم");
ok(ctx.paReqHours({ totals: { requiredDailyHours: 8 } }, { settings: { shiftHours: 10 } }) === 8, "العتبة من totals أولًا");
ok(ctx.paReqHours({ totals: {} }, { settings: { shiftHours: 9 } }) === 9, "الاحتياطي ساعات الوردية من الإعدادات");
ok(ctx.paReqHours({}, {}) === 10, "آخر احتياطي 10");

/* 3) الخلايا والإجماليات والإكسل */
ok(/\+ paCell\(pid, row\.day, "hours", row, \{ money: true \}\)\s*\+ paOtCell\(row, req\)/.test(html), "خلية الإضافي بعد ساعات الطيار");
ok(/\+ stCell\(uid, row\.day, "hours", row, \{ money: true \}\)\s*\+ paOtCell\(row, req\)/.test(html), "خلية الإضافي بعد ساعات الموظف");
ok((html.match(/paRowCells\(p\.pilotId, row, paReqHours\(p, d\)\)/g) || []).length === 2, "الجدولين بيبعتوا عتبة الطيار");
ok((html.match(/stRowCells\(u\.userId, row, paReqHours\(u, d\)\)/g) || []).length === 2, "جدولين الموظفين بيبعتوا العتبة");
ok(html.includes(`paMoney(s("overtimeHours"))`) && html.includes(`paMoney(sm("overtimeHours"))`), "إجمالي الإضافي في تقفيلتين الشهر");
ok(html.includes(`"عدد ساعات العمل", "ساعات إضافية", "عدد الأوردرات"`), "إكسل اليومي/الكشف فيه العمود");
ok(html.includes(`"الساعات", "ساعات إضافية", "أيام الشغل"`) && html.includes(`["hours", "overtimeHours", "worked"`), "إكسل الشهر فيه العمود");

console.log(`\n${fail ? "❌" : "✅"} عمود الساعات الإضافية: ${pass} نجح · ${fail} فشل`);
process.exit(fail ? 1 : 0);
