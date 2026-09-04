/* الإرسال بينقلك للطرد الناقص ويحدّد خانته.
 *
 * ═══ ليه ده مش رفاهية ═══
 * قبل التبويب كانت الطرود كلها ظاهرة، فرسالة «يرجى إدخال اسم المستلِم —
 * طرد #2» كان المحل يلاقيها بالسكرول. دلوقتي الطرد التاني **مخفي**، فنفس
 * الرسالة بتبقى بلا معنى: المحل شايف طرد واحد ومكتوبله إن في حاجة ناقصة
 * في حتة مش شايفها. فالنقلة جزء من صحة التعديل مش تحسين جنبه.
 *
 * ═══ الترقيم في الرسالة ═══
 * الرسايل كانت بتقول «طرد #${n}» و n ده **الرقم الداخلي** — بعد حذف طرد
 * بيبقى مختلف عن الرقم اللي على الزرار. بقت parcelDisplayNo(n) عشان اللي
 * في الرسالة يساوي اللي المحل شايفه.
 */
const fs = require('fs');
const F = 'public/store.html';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};

const L = (...x) => x.join('\n');
const DOL = String.fromCharCode(36);
const BT  = String.fromCharCode(96);

/* ① العلم بيتفعّل أول ما المحل يحاول يبعت */
one(
  L('      const rows     = document.querySelectorAll(".parcel-card");',
    '      if (!rows.length) { toast("أضف وجهة توصيل واحدة على الأقل", "err"); await _storeDiag({ step: "no-parcels" }); return; }'),
  L('      const rows     = document.querySelectorAll(".parcel-card");',
    '      if (!rows.length) { toast("أضف وجهة توصيل واحدة على الأقل", "err"); await _storeDiag({ step: "no-parcels" }); return; }',
    '',
    '      /* من هنا ورايح النقط الحمرا بتبان على أزرار الطرود الناقصة.',
    '         قبل أول محاولة إرسال بتفضل مطفية — فورم لسه اتفتح مايستاهلش',
    '         يبان كأنه غلطان. */',
    '      window._parcelChecked = true;',
    '      window.renderParcelTabs?.();'),
  'تفعيل النقط عند الإرسال');

/* ② الاسم */
one(
  '        if (!name)   { toast(`يرجى إدخال اسم المستلِم — طرد #' + DOL + '{n}`, "err"); await _storeDiag({ step: "no-name", parcel: n }); return; }',
  L('        if (!name)   { _jumpToParcel(n, `rName-' + DOL + '{n}`, `يرجى إدخال اسم المستلِم — طرد #' + DOL + '{window.parcelDisplayNo(n)}`); await _storeDiag({ step: "no-name", parcel: n }); return; }'),
  'الاسم');

/* ③ الهاتف */
one(
  '        if (!phone)  { toast(`يرجى إدخال هاتف المستلِم — طرد #' + DOL + '{n}`, "err"); await _storeDiag({ step: "no-phone", parcel: n }); return; }',
  L('        if (!phone)  { _jumpToParcel(n, `rPhone-' + DOL + '{n}`, `يرجى إدخال هاتف المستلِم — طرد #' + DOL + '{window.parcelDisplayNo(n)}`); await _storeDiag({ step: "no-phone", parcel: n }); return; }'),
  'الهاتف');

/* ④ المنطقة — دي كان ليها إبراز خاص، بنحافظ عليه ونزوّد النقلة */
one(
  L('        if (!zoneId) {',
    '          toast(`⚠️ لازم تختار المنطقة أولاً — طرد #' + DOL + '{n}`, "err");',
    '          // نبرز حقل المنطقة الفاضي ونوديه لعين المستخدم',
    '          if (zoneSel) {'),
  L('        if (!zoneId) {',
    '          /* النقلة الأول: الطرد ممكن يكون مخفي ورا زراره، والإبراز تحت',
    '             مالوش أي أثر على عنصر جوه `display:none`. */',
    '          window.showRow?.(n);',
    '          toast(`⚠️ لازم تختار المنطقة أولاً — طرد #' + DOL + '{window.parcelDisplayNo(n)}`, "err");',
    '          // نبرز حقل المنطقة الفاضي ونوديه لعين المستخدم',
    '          if (zoneSel) {'),
  'المنطقة');

/* ⑤ الدالة المساعدة */
one(
  '    window.saveOrder = async function () {',
  L('    /* بينقل الشاشة للطرد الناقص، يقول الرسالة، ويحدّد الخانة الفاضية.',
    '       الترتيب مقصود: `showRow` الأول عشان `focus` و`scrollIntoView`',
    '       مايشتغلوش على عنصر مخفي. */',
    '    function _jumpToParcel(n, fieldId, msg) {',
    '      window.showRow?.(n);',
    '      toast(msg, "err");',
    '      const el = document.getElementById(fieldId);',
    '      if (!el) return;',
    '      el.style.borderColor = "#ef4444";',
    '      el.style.boxShadow   = "0 0 0 3px rgba(239,68,68,.25)";',
    '      el.scrollIntoView({ behavior: "smooth", block: "center" });',
    '      el.focus();',
    '      // الإبراز بيروح أول ما يكتب — ومعاه النقطة الحمرا بتتحدّث',
    '      el.addEventListener("input", function _clr() {',
    '        el.style.borderColor = ""; el.style.boxShadow = "";',
    '        window.renderParcelTabs?.();',
    '        el.removeEventListener("input", _clr);',
    '      });',
    '    }',
    '',
    '    window.saveOrder = async function () {'),
  'دالة النقل');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ التحقق بينقل للطرد الناقص');
