/**
 * 📍 حارس: باراسر لوكيشن العميل (assets/js/geoloc.js).
 *
 * الطلب (صاحب النظام 2026-09-03): الموظف بيلزق اللوكيشن من خرائط جوجل وهو
 * بيضرب الأوردر — بأي صيغة جوجل بيطلّعها: إحداثيات عشرية، صيغة الدرجات
 * (31°01'07.4"N…)، أو لينك المكان نفسه. أي صيغة منهم تقع = الطرد يتسجّل
 * «بدون موقع» والموظف مايعرفش ليه.
 *
 * التشغيل: node ops/test_geoloc.cjs
 */
const vm = require("vm");
const fs = require("fs");
const path = require("path");

const w = {};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../public/assets/js/geoloc.js"), "utf8"),
  { window: w, navigator: {}, document: {} });
const G = w.GeoLoc;

const CASES = [
  ["عشري بفاصلة ومسافة", "31.018732, 31.228285", [31.018732, 31.228285]],
  ["عشري بفاصلة بس", "31.018732,31.228285", [31.018732, 31.228285]],
  ["عشري بمسافة بس", "31.018732 31.228285", [31.018732, 31.228285]],
  ["أرقام عربية وفاصلة عربية", "٣١.٠١٨٧٣٢، ٣١.٢٢٨٢٨٥", [31.018732, 31.228285]],
  ["صيغة الدرجات (زي لقطة جوجل)", '31°01\'07.4"N 31°13\'41.8"E', [31.018722, 31.228278]],
  ["لينك مكان جوجل الكامل (!3d/!4d بيغلب @)",
    "https://www.google.com/maps/place/31%C2%B001%2707.4%22N+31%C2%B013%2741.8%22E/@31.0187321,31.2257099,17z/data=!3m1!4b1!4m4!3m3!8m2!3d31.0187321!4d31.2282848?hl=en",
    [31.018732, 31.228285]],
  ["لينك ?q=", "https://maps.google.com/?q=31.018732,31.228285", [31.018732, 31.228285]],
  ["لينك @ بس", "https://www.google.com/maps/@31.0187321,31.2257099,17z", [31.018732, 31.22571]],
  ["كلام مش إحداثيات = null", "شارع الجلاء المنصورة", null],
  ["فاضي = null", "   ", null],
  ["خط عرض مستحيل = null", "200, 31", null],
  ["رقم واحد بس = null", "31.0187", null],
];

let pass = 0, fail = 0;
for (const [label, input, exp] of CASES) {
  const g = G.parse(input);
  const ok = exp === null
    ? g === null
    : !!g && Math.abs(g.lat - exp[0]) < 1e-5 && Math.abs(g.lng - exp[1]) < 1e-5;
  if (ok) { pass++; console.log(`  ✓ ${label}`); }
  else { fail++; console.log(`  ✗ ${label}   ← ${JSON.stringify(g)}`); }
}

// الصيغة الموحّدة ولينك جوجل (بفاصلة عادية عشان يتفتح على الموبايل والويب)
const f = G.fmt(31.0187321, 31.2282848);
if (f === "31.018732, 31.228285") { pass++; console.log("  ✓ fmt 6 خانات"); } else { fail++; console.log("  ✗ fmt   ← " + f); }
const u = G.gmaps(31.0187321, 31.2282848);
if (u === "https://www.google.com/maps?q=31.018732,31.228285") { pass++; console.log("  ✓ لينك جوجل"); } else { fail++; console.log("  ✗ gmaps   ← " + u); }
// read(): فاضي = ok بلا لوكيشن — الحقل اختياري
const docStub = { getElementById: id => ({ value: id === "empty" ? "  " : (id === "bad" ? "xx" : "31.0, 31.2") }) };
const w2 = {};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../public/assets/js/geoloc.js"), "utf8"),
  { window: w2, navigator: {}, document: docStub });
const r1 = w2.GeoLoc.read("empty"), r2 = w2.GeoLoc.read("bad"), r3 = w2.GeoLoc.read("good");
if (r1.ok && r1.geo === null && !r2.ok && r3.ok && r3.geo.lat === 31) { pass++; console.log("  ✓ read(): فاضي ok · غلط مرفوض · صح geo"); }
else { fail++; console.log("  ✗ read()   ← " + JSON.stringify([r1, r2, r3])); }

console.log("\n────────────────────────────────────");
if (fail) { console.log(`🔴 ${fail} فحص وقع (نجح ${pass})`); process.exit(1); }
console.log(`✅ كل الفحوص عدّت (${pass})`);
