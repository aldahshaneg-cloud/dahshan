/**
 * 💬 حارس: أرقام واتساب دولية صح في كل الصفحات.
 *
 * البلاغ (صاحب النظام 2026-09-03): زرار واتساب في شاشة تتبّع الطيار
 * (تطبيق العميل) بيفتح 21094183199 — واتساب بيقول «رمز الدولة غير صحيح».
 * السبب: `waNum` كانت بتشيل الصفر وتحط «2» بس بدل «20».
 *
 * القاعدة: التحويل في مكان واحد (SupportNums.intl). أي بناء بالإيد بيشيل
 * الصفر لازم يحط «20»، والـ«2» + رقم بصفر مقبولة (20xxx) بس مش «2» + رقم
 * من غير صفر.
 *
 * التشغيل: node ops/test_wa_numbers.cjs
 */
const fs = require("fs");
const vm = require("vm");
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log("  ✓ " + what); }
  else { fail++; console.log("  ✗ " + what + (got !== undefined ? "   ← " + got : "")); }
};

console.log("══ 1) SupportNums.intl ══");
const w = {};
vm.runInNewContext(fs.readFileSync("public/assets/js/support-numbers.js", "utf8"), { window: w });
const intl = w.SupportNums.intl;
ok("01094183199 → 201094183199 (رقم الطيار من البلاغ)", intl("01094183199") === "201094183199", intl("01094183199"));
ok("1094183199 (بلا صفر) → 201094183199", intl("1094183199") === "201094183199", intl("1094183199"));
ok("201094183199 يفضل زي ما هو", intl("201094183199") === "201094183199");
ok("+20 109 418 3199 بمسافات", intl("+20 109 418 3199") === "201094183199");
ok("0020… → 20…", intl("00201094183199") === "201094183199");
ok("فاضي → فاضي", intl("") === "");

console.log("\n══ 2) تطبيق العميل — waNum بتمر على intl ══");
const C = fs.readFileSync("public/customer.html", "utf8");
ok("waNum بتستخدم SupportNums.intl", /const waNum = p => window\.SupportNums\s*\n?\s*\? SupportNums\.intl\(p\)/.test(C));
ok("🔴 الصيغة الغلط («2» + الرقم بلا صفر) اختفت",
   !/"2" \+ String\(p \|\| ""\)\.replace\(\/\\D\/g, ""\)\.replace\(\/\^0\/, ""\)/.test(C), "لسه موجودة");
ok("support-numbers.js متحمّل قبل الاستخدام", /assets\/js\/support-numbers\.js/.test(C));

console.log("\n══ 3) الصفحات كلها — مافيش «2» + رقم منزوع الصفر ══");
for (const f of fs.readdirSync("public").filter(x => x.endsWith(".html"))) {
  const s = fs.readFileSync("public/" + f, "utf8");
  // النمط الغلط: حط «2» بعد ما شال الصفر
  const bad = /"2"\s*\+[^;\n]*replace\(\/\^0\/, ""\)/.test(s) || /replace\(\/\^0\/, ""\)[^;\n]*"2"\s*\+/.test(s);
  ok(f + " — مافيش بناء «2 + رقم بلا صفر»", !bad);
}

console.log("\n" + "─".repeat(50));
if (fail) { console.log(`🔴 وقع ${fail} من ${pass + fail}`); process.exit(1); }
console.log(`✅ عدّى ${pass} فحص`);
