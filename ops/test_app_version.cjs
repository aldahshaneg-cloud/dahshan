/**
 * 🏷 اختبار «رقم النسخة واحد مش اتنين» — تطبيق الطيار.
 *
 * ═══ اللسعة ═══
 * رقم نسخة التطبيق مكتوب في **مكانين**:
 *   • `pubspec.yaml`  ⟵ اللي بيتحط في ملف الـAPK نفسه
 *   • `kAppVersion` في `lib/main.dart` ⟵ اللي التطبيق **بيقوله للسيرفر**
 *
 * ولقيت الاتنين اتفرّقوا فعلًا: الـpubspec على 2.3.5 و`kAppVersion`
 * لسه على 2.3.2. النتيجتين:
 *   1. شاشة الطيارين في الإدارة بتعرض نسخة غلط لكل طيار.
 *   2. أخطر: `blocked: compareVersions(kAppVersion, minV) < 0` — يعني
 *      لو الإدارة حطّت الحد الأدنى 2.3.3، الطيارين اللي على 2.3.5
 *      **الحقيقية** كانوا هيتقفل عليهم التطبيق وهم مظبوطين.
 *
 * الرقمين لازم يتساووا، والفحص ده بيقع لو اتفرّقوا تاني.
 *
 * التشغيل: node ops/test_app_version.cjs
 */
const fs = require('fs');

let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const PUBSPEC = '../aldahshan/pubspec.yaml';
const MAIN    = '../aldahshan/lib/main.dart';

console.log('\n══ رقم النسخة ══');

const pub  = fs.readFileSync(PUBSPEC, 'utf8');
const main = fs.readFileSync(MAIN, 'utf8');

const mPub  = /^version:\s*([0-9]+\.[0-9]+\.[0-9]+)\+([0-9]+)\s*$/m.exec(pub);
const mMain = /const String kAppVersion = '([0-9]+\.[0-9]+\.[0-9]+)';/.exec(main);

ok('pubspec فيه رقم نسخة صالح', !!mPub, mPub ? mPub[0] : 'مش موجود');
ok('main.dart فيه kAppVersion صالح', !!mMain, mMain ? mMain[0] : 'مش موجود');

if (mPub && mMain) {
  ok('🔴 الرقمين متساويين', mPub[1] === mMain[1],
     'pubspec=' + mPub[1] + ' · kAppVersion=' + mMain[1]);

  /* رقم البناء (اللي بعد الـ+) لازم يزيد مع كل رفعة — أندرويد بيرفض
     تثبيت نسخة برقم بناء أقل أو يساوي المتثبّتة. */
  ok('رقم البناء رقم موجب', parseInt(mPub[2], 10) > 0, mPub[2]);
}

/* الفحص ده بيمسك السبب الجذري: لو حد كتب النسخة نصًا في مكان تالت،
   يبقى فيه مصدر تالت للحقيقة هيفرق بعدين زي ما الاتنين فرقوا. */
const hard = (main.match(/'[0-9]+\.[0-9]+\.[0-9]+'/g) || [])
  .filter(v => v !== "'" + (mMain ? mMain[1] : '') + "'");
ok('مفيش رقم نسخة تاني متكتوب بالإيد في main.dart',
   hard.length === 0, hard.join('، '));

console.log('\n════════════════════════════════════════');
console.log('APP VERSION: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
