/* تلات إصلاحات في تطبيق العميل (بلاغ صاحب النظام):
     ① رسالة التأكيد (التوست) بتطلع نص غامق على أخضر غامق في الوضع النهاري
     ② الوضع النهاري بقى الافتراضي — والتطبيق أصله أسود
     ③ الإحصائيات بتعدّ الأوردرات مش الطرود
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

/* ═══ ① التوست ═══
   خلفياته مكتوبة غامقة بالإيد، ولونه بيورث `--txt` — اللي بقى **غامق**
   في الوضع النهاري. النتيجة: نص أسود على أخضر غامق = مش مقروء.
   الحل: التوست يفضل غامق في الوضعين بنص أبيض. ده نمط معروف (الرسالة
   العائمة بتبقى طبقة فوق الواجهة مش جزء منها)، وبيشتغل في الاتنين من
   غير لوحة تانية نفضل نصونها. */
console.log('── ① التوست ──');
one(`#toast{position:fixed;bottom:calc(96px + var(--safe-b));right:50%;transform:translateX(50%) translateY(20px);
  background:#1e1e23;border:1px solid var(--line);padding:12px 18px;border-radius:13px;font-size:13.5px;
  font-weight:600;z-index:500;opacity:0;transition:.28s;pointer-events:none;max-width:88%;text-align:center}`,
`/* التوست طبقة فوق الواجهة مش جزء منها — فبيفضل غامق في الوضعين.
   \`color\` مكتوب صراحةً: من غيره بيورث \`--txt\` اللي بيبقى **غامق** في
   النهاري، فيطلع نص أسود على خلفية سودا. */
#toast{position:fixed;bottom:calc(96px + var(--safe-b));right:50%;transform:translateX(50%) translateY(20px);
  background:#1e1e23;color:#fff;border:1px solid #33333c;padding:12px 18px;border-radius:13px;font-size:13.5px;
  font-weight:600;z-index:500;opacity:0;transition:.28s;pointer-events:none;max-width:88%;text-align:center;
  box-shadow:0 10px 30px rgba(0,0,0,.35)}`,
'خلفية ولون التوست');

one(`#toast.err{border-color:rgba(232,25,44,.55);background:#2a1416}
#toast.ok{border-color:rgba(34,197,94,.5);background:#0f2318}`,
`#toast.err{border-color:rgba(232,25,44,.55);background:#2a1416;color:#ffd7dc}
#toast.ok{border-color:rgba(34,197,94,.5);background:#123021;color:#c8f5da}`,
'ألوان النجاح والخطأ');

/* ═══ ② الافتراضي يرجع ليلي ═══
   التطبيق كان **أسود دايمًا**، وكل صفحات الموقع العامة سودا. لما خليت
   الافتراضي «النظام»، أي حد جهازه نهاري لقى التطبيق قلب أبيض من غير ما
   يطلب — وده اللي اتبلّغ عنه. الافتراضي رجع ليلي، و«النظام» بقى اختيار
   صريح لمين عايزه. */
console.log('── ② الافتراضي ──');
one(`const THEMES = [
  { key: "system", label: "النظام", icon: "🌓" },
  { key: "dark",   label: "ليلي",   icon: "🌙" },
  { key: "light",  label: "نهاري",  icon: "☀️" },
];`,
`/* الترتيب هو ترتيب التبديل، وأول واحد هو **الافتراضي**.
   ليلي أولًا عن قصد: التطبيق أسود من أصله وكل صفحات الموقع سودا،
   فالافتراضي لازم يطابق ده. «النظام» اختيار متاح مش سلوك مفروض. */
const THEMES = [
  { key: "dark",   label: "ليلي",   icon: "🌙" },
  { key: "light",  label: "نهاري",  icon: "☀️" },
  { key: "system", label: "النظام", icon: "🌓" },
];`,
'ترتيب الأوضاع');

one(`function currentTheme() {
  try { return localStorage.getItem("cust-theme") || "system"; } catch (e) { return "system"; }
}`,
`function currentTheme() {
  /* الافتراضي **ليلي** مش «النظام» — شوف التعليق فوق THEMES. */
  try { return localStorage.getItem("cust-theme") || "dark"; } catch (e) { return "dark"; }
}`,
'الافتراضي في currentTheme');

/* والسكربت المبكر لازم يحط ليلي كمان لما مفيش اختيار محفوظ */
one(`  try { var _t = localStorage.getItem("cust-theme");
        if (_t && _t !== "system") document.documentElement.setAttribute("data-theme", _t); } catch (e) {}`,
`  /* مفيش اختيار محفوظ = ليلي (الافتراضي). من غير السطر ده الصفحة بتفتح
     نهاري على أي جهاز وضعه نهاري، وده اللي اتبلّغ عنه. */
  try { var _t = localStorage.getItem("cust-theme") || "dark";
        if (_t !== "system") document.documentElement.setAttribute("data-theme", _t); } catch (e) {
        document.documentElement.setAttribute("data-theme", "dark"); }`,
'السكربت المبكر');

/* ═══ ③ الإحصائيات تعدّ الطرود ═══ */
console.log('── ③ الإحصائيات ──');
one(`  const all = S.orders;
  const st = s => all.filter(o => orderState(o) === s).length;
  $("stTotal").textContent  = all.length;
  $("stWay").textContent    = st("new") + st("way");
  $("stDone").textContent   = st("done");
  $("stCancel").textContent = st("cancel") + st("failed");`,
`  const all = S.orders;
  /* 🔴 الإحصائيات بتعدّ **الطرود** مش الأوردرات: طلب واحد فيه طردين =
     شحنتين فعليًا للعميل، وكان بيتعدّ واحد. والشحنة الجاية (مش أوردره)
     بنعدّ طرودها هو بس — \`_myParcels\` — مش طرود الأوردر كله. */
  const st = s => all.filter(o => orderState(o) === s).reduce((t, o) => t + parcelsOf(o), 0);
  $("stTotal").textContent  = all.reduce((t, o) => t + parcelsOf(o), 0);
  $("stWay").textContent    = st("new") + st("way");
  $("stDone").textContent   = st("done");
  $("stCancel").textContent = st("cancel") + st("failed");`,
'حساب الإحصائيات');

/* الدالة نفسها — بتتحط قبل orderTimeline */
one(`/* ⚠️ صف «لم يتم التوصيل» بيتحط فيه سبب بيكتبه الطيار`,
`/* عدد الطرود في الأوردر — الوحدة اللي العميل بيحسب بيها.
   • أوردره هو  → كل طرود الأوردر.
   • شحنة جايّاله → طروده هو بس (\`_myParcels\`)، لأن الأوردر ممكن يكون
     فيه طرود لناس تانية ومش من حقه يعدّها ولا يشوفها.
   • أوردر قديم من غير طرود → واحد، عشان مايختفيش من العدّ. */
function parcelsOf(o) {
  if (!o) return 0;
  if (o._incoming) return (o._myParcels || [0]).length || 1;
  return (o.deliveries || []).length || 1;
}

/* ⚠️ صف «لم يتم التوصيل» بيتحط فيه سبب بيكتبه الطيار`,
'دالة عدّ الطرود');

fs.writeFileSync(f, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
console.log(bad ? `\n🔴 ${bad} مشكلة` : '\n✅ التلاتة اتعملوا');
process.exit(bad ? 1 : 0);
