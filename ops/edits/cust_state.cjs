/* إصلاح: حالة الطرد كانت بتتلوّث بحالة أول طرد في الأوردر.
 *
 * ═══ الباج ═══
 * `orderState(o)` بتقرا `myParcel(o)` = **الطرد الأول**. فلما استعملتها
 * كخلفية لحالة أي طرد لسه شغّال، الطرد التاني والتالت ورثوا حالة الأول.
 *
 * اللي بان في الاختبار: أوردر فيه طرد اتسلّم وطرد لسه في الطريق وطرد فشل
 *   الطرد ١ (اتسلّم)     → «تم التسليم» ✓
 *   الطرد ٢ (لسه شغّال)  → «تم التسليم» ✗  ← ورث حالة الأول
 *   الطرد ٣ (فشل)        → «لم يتم التوصيل» ✓
 * والإحصائيات قالت ٣ مسلّمين وهو واحد.
 *
 * ═══ الإصلاح ═══
 * `tripState(o)` — حالة الرحلة من **الأوردر لوحده**، من غير أي طرد:
 * ملغي · جاري التوصيل (فيه طيار) · جديد. ودي الخلفية الصح لأي طرد
 * لسه ماخلصش، لأن الرحلة فعلًا مشتركة بين كل الطرود.
 *
 * والإحصائيات بقت بتمشي على **الطرود** وتصنّف كل واحد لوحده، بدل ما
 * تصنّف الأوردر وتضرب في عدد طروده.
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

/* ── ① حالة الرحلة من الأوردر لوحده ── */
one(`function parcelState(o, d) {
  if ((o.status || "") === "ملغي") return "cancel";
  if (d && d.status === "تم التسليم")     return "done";
  if (d && d.status === "لم يتم التوصيل") return "failed";
  return orderState(o);
}`,
`/* حالة الرحلة من **الأوردر لوحده** — من غير ما تلمس أي طرد.
   لازم تبقى مستقلة: \`orderState\` بتقرا الطرد الأول، فلو استعملناها
   كخلفية لطرد تاني بيرث حالة الأول (طرد لسه في الطريق يبان «تم التسليم»
   لأن أخوه اتسلّم). */
function tripState(o) {
  const s = o.status || "";
  if (s === "ملغي") return "cancel";
  if (s === "لم يتم التوصيل") return "failed";
  if (s === "جاري التوصيل" || o.pilotId) return "way";
  return "new";
}

/* حالة الطرد: نهايته هو بتغلب، وغير كده بياخد حالة الرحلة المشتركة.
   الطيار بيخرج بالشحنة كلها مرة واحدة، لكن كل طرد بيتسلّم لوحده. */
function parcelState(o, d) {
  if ((o.status || "") === "ملغي") return "cancel";
  if (d && d.status === "تم التسليم")     return "done";
  if (d && d.status === "لم يتم التوصيل") return "failed";
  return tripState(o);
}`,
'حالة الرحلة مستقلة');

/* ── ② الإحصائيات تصنّف كل طرد لوحده ── */
one(`  const st = s => all.filter(o => orderState(o) === s).reduce((t, o) => t + parcelsOf(o), 0);
  $("stTotal").textContent  = all.reduce((t, o) => t + parcelsOf(o), 0);`,
`  /* 🔴 كل طرد بيتصنّف **لوحده**. الطريقة القديمة (نصنّف الأوردر ونضرب في
     عدد طروده) كانت بتقول «٣ مسلّمين» لأوردر فيه واحد اتسلّم واتنين لأ. */
  const rows = all.flatMap(orderRows);
  const st = s => rows.filter(r => parcelState(r.o, r.d) === s).length;
  $("stTotal").textContent  = rows.length;`,
'الإحصائيات لكل طرد');

fs.writeFileSync(f, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
console.log(bad ? `\n🔴 ${bad} مشكلة` : '\n✅ خلص');
process.exit(bad ? 1 : 0);
