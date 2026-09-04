/**
 * 🔒 حارس: ممنوع الكدب بعد الكتابة + مفتاح مانع التكرار في كل واجهات
 * إنشاء الأوردر.
 *
 * ═══ القاعدة (اتدفع تمنها مرتين) ═══
 * ① تطبيق العميل 2026-09-01: «receipt is not defined» بعد حفظ ناجح →
 *   «تعذّر الإرسال» → العميل ضغط تاني → أوردر مكرر.
 * ② الكول سنتر 2026-09-02: `.start()` على بولر مابيبدأش بالنداء موّت
 *   نص الصفحة → «خطأ أثناء الحفظ» بعد رد 200 → الموظف أعاد الإدخال
 *   تلات مرات (GISH-260902-002/003/004).
 *
 * العقود المثبتة هنا:
 * • كل واجهة بتبعت clientRef ثابت لحد ما حفظ ينجح، وبتصفّره بعد النجاح.
 * • كود ما-بعد-النجاح معزول: عثرته بتطلّع «اتسجّل/اتبعت» مش «خطأ/تعذّر».
 * • السيرفر (المسارين) فيه الفحص المسبق + شبكة السباق uq_orders_client_ref.
 * • ومافيش `.start()` على بولرات الكول سنتر — البولر بيشتغل بالإنشاء.
 *
 * التشغيل: node ops/test_never_lie_after_write.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};
// الحارس بيمسك تعليقه هو — امشّط التعليقات قبل أي فحص نصّي
const strip = t => t
  .replace(/\/\*[\s\S]*?\*\//g, '')
  .replace(/^\s*\/\/[^\n]*$/gm, '')
  .replace(/^\s*#[^\n]*$/gm, '');

console.log('══ 1) واجهات الموظفين (كول سنتر / فرع / إدارة) ══');
for (const F of ['public/callcenter.html', 'public/branch.html', 'public/tiar.html']) {
  const raw = fs.readFileSync(F, 'utf8');
  const S = strip(raw);
  console.log('— ' + F);
  ok('بيبعت clientRef مع POST /api/orders',
    /clientRef: window\._orderClientRef,/.test(S));
  ok('والمفتاح ثابت — مش بيتولّد جديد مع كل ضغطة',
    /if \(!window\._orderClientRef\) \{/.test(S));
  ok('وبيتصفّر بعد النجاح بس', /window\._orderClientRef = null;/.test(S));
  ok('🔴 فشل الحفظ الحقيقي بيخرج بـreturn — مش بيكمّل على كود الشاشة',
    /showToast\("خطأ أثناء الحفظ: " \+ e\.message, "error"\);\n\s*btn\.disabled = false;[^\n]*\n\s*return;/.test(S));
  ok('🔴 وعثرة الشاشة بعد النجاح بتقول «اتسجّل» صراحةً — مش «خطأ أثناء الحفظ»',
    S.includes('الأوردر اتسجّل بنجاح ✓ — بس الشاشة اتعثرت'));
  ok('ورسالة «كان اتسجّل خلاص» لما السيرفر يرجّع duplicate',
    /res\.duplicate\n?\s*\?\s*`الأوردر ده كان اتسجّل خلاص/.test(S));
}

console.log('\n══ 2) الكول سنتر — البولر اللي موّت نص الصفحة ══');
{
  const S = strip(fs.readFileSync('public/callcenter.html', 'utf8'));
  ok('🔴 مافيش `.start()` على أي بولر — api.Poller بيشتغل بالإنشاء ومافيهوش start أصلًا',
    !/\.start\(\)/.test(S),
    'نداء start على البولر بيرمي TypeError وقت التحميل وبيموّت كل اللي بعده');
  ok('وبولر الرسايل لسه متعمل جوه الفحص الدفاعي',
    /if \(typeof ccPoller === "function"\) \{\n\s*ccPoller\("\/api\/order-notifications"/.test(S));
}

console.log('\n══ 3) بوابة المحلات ══');
{
  const raw = fs.readFileSync('public/store.html', 'utf8');
  const S = strip(raw);
  ok('بتبعت clientRef', /clientRef\s*:\s*window\._orderClientRef,/.test(S));
  ok('وفيه علم _orderSavedOk بيتقلب بعد رد السيرفر',
    /let _orderSavedOk = false;/.test(S) && /_orderSavedOk = true;/.test(S));
  ok('🔴 والكاتش بيفرّق: عثرة بعد النجاح = «اتبعت واتسجّل» مش «تعذّر الإرسال»',
    /if \(_orderSavedOk\) \{[\s\S]{0,700}الطلب اتبعت واتسجّل/.test(S));
  ok('ورسالة duplicate موجودة', S.includes('كان اتبعت خلاص من شوية'));
}

console.log('\n══ 4) تطبيق العميل ══');
{
  const S = strip(fs.readFileSync('public/customer.html', 'utf8'));
  ok('بيبعت clientRef', /clientRef\s*:\s*S\._orderClientRef,/.test(S));
  ok('والمفتاح بيتصفّر بعد orderCreated', /orderCreated = true;\n\s*S\._orderClientRef = null;/.test(S));
  ok('وعلم orderCreated (درس receipt is not defined) لسه موجود',
    /orderCreated = true;/.test(S));
}

console.log('\n══ 5) السيرفر — المسارين ══');
for (const F of ['app/Http/Controllers/Api/OrdersController.php',
                 'app/Http/Controllers/Api/CustomerAppController.php']) {
  const S = strip(fs.readFileSync(F, 'utf8'));
  console.log('— ' + F);
  ok('الفحص المسبق بيدوّر بالمفتاح', S.includes("WHERE client_ref LIKE ? ORDER BY id"));
  ok('وبيرجّع duplicate مش خطأ', /'duplicate' => true,/.test(S));
  ok('🔴 وشبكة السباق: القبض على uq_orders_client_ref جوه كاتش QueryException',
    /str_contains\(\$e->getMessage\(\), 'uq_orders_client_ref'\)/.test(S));
  ok('والمفتاح بيتسجّل بلاحقة الطرد (#) عشان التفريق',
    /\$clientRef \. '#' \. \$pi : null,/.test(S));
  ok('والمفتاح بيتنضّف بصرامة (أحرف وأرقام وشرطة بس)',
    /preg_match\('\/\^\[A-Za-z0-9\]\[A-Za-z0-9-\]\{7,63\}\$\/'/.test(S));
}

console.log('\n' + '─'.repeat(52));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — مافيش «فشل» بعد كتابة ناجحة ومافيش تكرار\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
