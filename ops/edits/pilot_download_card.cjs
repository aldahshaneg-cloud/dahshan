/* كارت تطبيق الطيار في الموقع — بيوضّح شرط أندرويد ويدّي بديل للتليفون القديم.
 *
 * ═══ المشكلة اللي بيصلّحها ═══
 * `downloads/dahshan-pilot-latest.apk` كان **arm64 بس** (41 ميجا، نفس مقاس
 * نسخة arm64 بالبايت). يعني الطيار اللي على تليفون قديم بمعالج 32-بت كان
 * بينزّل الملف ويلاقي التثبيت بيترفض — من غير أي سبب مفهوم. وفي المجلد
 * نسخة `-32bit` موجودة من زمان بس **مافيش رابط ليها في الموقع خالص**.
 *
 * ═══ الحل ═══
 * ① `latest` بقى النسخة **الشاملة** (universal) — فيها المعالجين، فأي
 *    تليفون بينزّل نفس اللينك ويشتغل. الطيار مش مطلوب منه يعرف نوع معالجه.
 * ② الكارت بقى مكتوب عليه شرط أندرويد ٧، لأن ده الحد اللي التطبيق مبني
 *    عليه (`minSdkVersion 24`) — وأقدم من كده مابيتثبتش مهما كانت النسخة،
 *    والطيار كان هيفضل يجرّب من غير ما يعرف السبب.
 * ③ ورابط صغير تحته للنسخة المختصرة (32-بت) لو النت بطيء — أصغر بـ٧ ميجا.
 *
 * ⚠️ `APP_DEF` قيم افتراضية بس — لوحة تحكم الموقع بتقدر تدهس `url` و`label`.
 *    فالكلام المهم متحطوط في `desc` و`note` اللي جوه الكود، مش في الرابط.
 *
 * 🔒 الحارس: ops/test_pilot_download.cjs
 */
const fs = require('fs');
const F = 'public/index.html';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};
const L = (...x) => x.join('\n');

/* ═══ ① وصف الكارت + ملاحظة ورابط بديل ═══ */
one(
  L('  /* الافتراضي بقى رابط الـAPK المرفوع على السيرفر — كان فاضي فالكارت',
    '     كان بيعرض «قريبًا». لوحة تحكم الموقع لسه بتقدر تدهسه (نفس منطق',
    '     باقي النظام: افتراضي في الكود + تحكّم من اللوحة). */',
    '  pilot   : { icon:"🛵", name:"تطبيق الطيار",  def:"downloads/dahshan-pilot-latest.apk", desc:"للطيارين: استلام الطلبات، الخريطة، والورديات.", label:"تحميل التطبيق" },'),
  L('  /* الافتراضي بقى رابط الـAPK المرفوع على السيرفر — كان فاضي فالكارت',
    '     كان بيعرض «قريبًا». لوحة تحكم الموقع لسه بتقدر تدهسه (نفس منطق',
    '     باقي النظام: افتراضي في الكود + تحكّم من اللوحة).',
    '',
    '     🔴 الرابط ده بقى النسخة **الشاملة** (فيها معالج 64 و32 مع بعض).',
    '     قبل كده كان arm64 بس، فالطيار على تليفون قديم بـ32-بت كان بينزّله',
    '     والتثبيت يترفض من غير سبب مفهوم — ونسخة الـ32-بت موجودة في المجلد',
    '     من زمان بس مكانش ليها رابط في الموقع خالص.',
    '',
    '     `note` و`alt` بيتعرضوا تحت الزرار. متحطّهمش في `desc` عشان لوحة',
    '     تحكّم الموقع بتقدر تدهس `url` و`label` — إنما دول جوه الكود. */',
    '  pilot   : { icon:"🛵", name:"تطبيق الطيار",  def:"downloads/dahshan-pilot-latest.apk",',
    '              desc:"للطيارين: استلام الطلبات، الخريطة، والورديات.", label:"تحميل التطبيق",',
    '              note:"يحتاج أندرويد 7 أو أحدث · يشتغل على كل الموبايلات",',
    '              alt:{ url:"downloads/dahshan-pilot-32bit.apk", label:"نسخة أخف للموبايلات القديمة" } },'),
  '① تعريف الكارت');

/* ═══ ② الرسم ═══ */
one(
  L('    return `<div class="app-card"><div class="ic">${d.icon}</div><h3>${esc(d.name)}</h3><p>${esc(d.desc)}</p>${',
    '      url ? `<a class="go" href="${esc(url)}"${/^https?:/i.test(url) ? \' target="_blank" rel="noopener"\' : ""}>${esc(label)}</a>` : `<div class="soon">قريبًا</div>`}</div>`;'),
  L('    /* الملاحظة والرابط البديل بيبانوا مع الزرار بس — كارت «قريبًا»',
    '       مالوش لازمة يقول شروط تشغيل لحاجة لسه مانزلتش. */',
    '    const note = d.note ? `<div class="app-note">${esc(d.note)}</div>` : "";',
    '    const alt  = d.alt && d.alt.url',
    '      ? `<a class="app-alt" href="${esc(d.alt.url)}">${esc(d.alt.label)}</a>` : "";',
    '    return `<div class="app-card"><div class="ic">${d.icon}</div><h3>${esc(d.name)}</h3><p>${esc(d.desc)}</p>${',
    '      url ? `<a class="go" href="${esc(url)}"${/^https?:/i.test(url) ? \' target="_blank" rel="noopener"\' : ""}>${esc(label)}</a>${note}${alt}`',
    '          : `<div class="soon">قريبًا</div>`}</div>`;'),
  '② الرسم');

/* ═══ ③ الستايل ═══ */
one(
  '.app-card:hover{border-color:rgba(232,25,44,.45);transform:translateY(-4px)}',
  L('.app-card:hover{border-color:rgba(232,25,44,.45);transform:translateY(-4px)}',
    '/* شرط التشغيل والرابط البديل — تحت زرار التحميل. صغيّرين عن قصد:',
    '   معلومة بتلزم لما تلزم، مش عنوان يزاحم الزرار. */',
    '.app-card .app-note{font-size:11.5px;color:var(--muted);line-height:1.7;margin-top:10px}',
    '.app-card .app-alt{display:inline-block;margin-top:7px;font-size:11.5px;font-weight:700;',
    '  color:var(--muted);text-decoration:underline;text-underline-offset:3px}',
    '.app-card .app-alt:hover{color:var(--red)}'),
  '③ الستايل');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ كارت تطبيق الطيار اتظبط');
