/**
 * 📥 حارس: تنزيل تطبيق الطيار من الموقع.
 *
 * ═══ المشكلة اللي اتصلّحت ═══
 * `downloads/dahshan-pilot-latest.apk` كان **arm64 بس**. الطيار اللي على
 * تليفون قديم بمعالج 32-بت كان بينزّله والتثبيت يترفض من غير سبب مفهوم،
 * ونسخة `-32bit` موجودة في المجلد من زمان بس **مافيش رابط ليها في الموقع**.
 *
 * ═══ اللي بيتحرس ═══
 * ① الكارت لسه بيشاور على `latest` — الطيار مش مطلوب منه يعرف نوع معالجه.
 * ② شرط أندرويد ٧ مكتوب. التطبيق مبني على `minSdkVersion 24`، وأقدم من
 *    كده مابيتثبتش مهما كانت النسخة. من غير الجملة دي الطيار بيفضل يجرّب.
 * ③ في رابط بديل للنسخة الأخف.
 * ④ الشرط ورابط البديل بيبانوا **مع الزرار بس** — كارت «قريبًا» مالوش
 *    لازمة يقول شروط تشغيل لحاجة لسه مانزلتش.
 * ⑤ رقم النسخة في `pubspec.yaml` و`kAppVersion` متساويين — الحارس التاني
 *    (`test_app_version.cjs`) بيغطّيها، وبنقرا الرقم هنا عشان نطابقه بالملف
 *    المرفوع لما يتفحص يدويًا.
 *
 * التشغيل: node ops/test_pilot_download.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const SRC = fs.readFileSync('public/index.html', 'utf8');

/* ══ 1) تعريف الكارت ══ */
console.log('\n══ 1) كارت تطبيق الطيار ══');
const def = /pilot\s*:\s*\{[\s\S]*?\n              alt:\{[^}]*\} \},/.exec(SRC)?.[0] || '';
ok('تعريف الكارت اتقصّ', def.length > 0);
ok('الرابط الافتراضي هو latest',
   def.includes('def:"downloads/dahshan-pilot-latest.apk"'));
ok('شرط أندرويد ٧ مكتوب', /note:"يحتاج أندرويد 7 أو أحدث/.test(def));
/* 🔴 اتغيّر 2026-09-01: الرابط الأساسي كان بناء **شامل** (كل المعماريات)
   فالنص كان بيقول «يشتغل على كل الموبايلات» بحق. بس الشامل بياخد
   versionCode بلا إزاحة فبيقع تحت كل بناء مقسّم منشور، وأندرويد بيرفضه
   كرجوع لنسخة أقدم — وده اللي عطّل التحديث على موبايلات الطيارين.
   الأساسي بقى arm64 مقسّم، فالنص **لازم** يقول إنه للحديث بس، والبديل
   (armeabi-v7a) هو اللي بيشتغل على القديم والحديث مع بعض. */
ok('وبيقول إنه للموبايلات الحديثة (64 بت)', /للموبايلات الحديثة \(64 بت\)/.test(def),
   'من غير كده صاحب موبايل قديم هيدوس الأساسي والتثبيت هيفشل من غير ما يفهم');
ok('في رابط بديل للموبايلات القديمة', /alt:\{ url:"downloads\/dahshan-pilot-32bit\.apk"/.test(def));
ok('واسم البديل بيوضّح إنه مخرج لما التثبيت يفشل',
   /موبايلك قديم أو التثبيت مش راضي يكمّل/.test(def),
   'البديل هو الحل الشامل الحقيقي — لازم يبان كده');

/* ══ 2) الرسم ══ */
console.log('\n══ 2) الرسم ══');
const render = /function renderApps\(cfg\)[\s\S]*?\n\}/.exec(SRC)?.[0] || '';
ok('renderApps اتقصّت', render.length > 0);
ok('الملاحظة بتترسم من d.note', /const note = d\.note \? `<div class="app-note">\$\{esc\(d\.note\)\}<\/div>` : "";/.test(render));
ok('والبديل من d.alt', /const alt\s+= d\.alt && d\.alt\.url/.test(render));
/* `${note}${alt}` لازم يكونوا جوه فرع «فيه رابط» من الشرط الثلاثي —
   يعني بين `url ?` و`:`. لو اتنقلوا بره الشرط، كارت «قريبًا» هيقول
   شروط تشغيل لحاجة لسه مانزلتش. */
ok('🔴 الاتنين مع الزرار بس مش مع «قريبًا»',
   /url \? `<a class="go"[\s\S]{0,240}\$\{note\}\$\{alt\}`[\s\S]{0,60}: `<div class="soon">/.test(render),
   'ممكن يبانوا على كارت لسه مانزلش');
ok('والنص بيعدّي على esc', /esc\(d\.note\)/.test(render) && /esc\(d\.alt\.label\)/.test(render));

/* ══ 3) الستايل ══ */
console.log('\n══ 3) الستايل ══');
ok('.app-note موجودة', /\.app-card \.app-note\{/.test(SRC));
ok('.app-alt موجودة وواضح إنها رابط', /\.app-card \.app-alt\{[^}]*text-decoration:underline/.test(SRC));
ok('وأصغر من نص الكارت — معلومة مش عنوان',
   /\.app-card \.app-note\{font-size:11\.5px/.test(SRC));

/* ══ 4) الكروت التانية ما اتأثرتش ══ */
console.log('\n══ 4) باقي الكروت ══');
['customer', 'store'].forEach(k => {
  const d = new RegExp(k + '\\s*:\\s*\\{[^}]*\\}').exec(SRC)?.[0] || '';
  ok(k + ' مالهوش note', d.length > 0 && !d.includes('note:'));
  ok(k + ' مالهوش alt', d.length > 0 && !d.includes('alt:'));
});

/* ══ 5) رقم النسخة اللي المفروض مرفوع ══ */
console.log('\n══ 5) النسخة ══');
const pub = fs.readFileSync('../aldahshan/pubspec.yaml', 'utf8');
const m = /^version:\s*([0-9.]+)\+([0-9]+)\s*$/m.exec(pub);
ok('pubspec فيه نسخة صالحة', !!m, m ? m[0] : 'مش موجود');
if (m) {
  console.log(`  ℹ️ المفروض على السيرفر: downloads/dahshan-pilot-${m[1]}-${m[2]}.apk`);
  const main = fs.readFileSync('../aldahshan/lib/main.dart', 'utf8');
  ok('kAppVersion مطابق للـpubspec',
     main.includes(`kAppVersion = '${m[1]}'`), m[1]);
}

console.log('\n' + '─'.repeat(48));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — صفحة تنزيل الطيار سليمة\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
