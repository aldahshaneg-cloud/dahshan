/**
 * 🔑 حارس: مفتاح تطبيق «تقفيل الطيارين» المستقل (`pilotacct`).
 *
 * ═══ الفخ اللي الملف ده موجود عشانه ═══
 * `accounts` بيشتق `damascus` في **تلات** أماكن (السيرفر + مكانين في
 * البوابة). لو `pilotacct` اشتق روح دمشق زيه، يبقى أي مشرف فرع الأدمن
 * بيديله «تقفيل الطيارين» بيفتح كمان تقفيلة روح دمشق — برنامج تاني
 * بفلوس شركة تانية. الاشتقاق لازم يبقى **في اتجاه واحد**:
 *     accounts → pilotacct   ✓  (المحاسب مايفقدش حاجة)
 *     pilotacct → damascus   ✗  (ده اللي بنمنعه)
 *
 * ═══ واللي بيغلط كمان ═══
 * ① مفتاح جديد مش في قايمة `ids` في البوابة → الكارت بيفضل مقفول مهما
 *    اتدّى، والأدمن بيدّيه وبيستغرب إنه مش شغال.
 * ② مفتاح موجود في البوابة ومش في `appsFor` → السيرفر يرفض الدخول
 *    برسالة «حسابك مش مصرّح له بالتطبيق ده» بعد ما الكارت فتح.
 * ③ الصفحة بتبعت مفتاح والبوابة بتدّي غيره → دخول مرفوض بلا سبب باين.
 *
 * التشغيل: node ops/test_pilotacct_app_key.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const AUTH = fs.readFileSync('app/Http/Controllers/Api/AuthController.php', 'utf8');
const HOME = fs.readFileSync('public/home.html', 'utf8');
const TIAR = fs.readFileSync('public/tiar.html', 'utf8');
const ACCT = fs.readFileSync('public/accounts.html', 'utf8');

/* التعليقات بتتشال قبل أي فحص نصّي — الشرح فوق كل تعديل بيذكر
   «pilotacct» و«damascus» مع بعض، والفحص كان هيمسك شرحه هو. */
const strip = (t) => t.replace(/\/\*[\s\S]*?\*\//g, '').replace(/<!--[\s\S]*?-->/g, '');
const A = strip(AUTH), H = strip(HOME), T = strip(TIAR), C = strip(ACCT);

/* ══ 1) السيرفر ══ */
console.log('\n══ 1) appsFor ══');
ok('الأدمن بياخد المفتاح',
  /'admin'\s+=> \['admin', 'branch', 'store', 'callcenter', 'hr', 'accounts', 'pilotacct'\]/.test(A));
ok('و`accounts` بيشتقه — المحاسب مايفقدش حاجة',
  /if \(\$has\('accounts'\)\) \{ \$apps\[\] = 'damascus'; \$apps\[\] = 'pilotacct'; \}/.test(A));

/* 🔴 الفحص الأهم في الملف */
ok('🔴 و`pilotacct` مابيشتقش `damascus` — الاتجاه واحد',
  ! /\$has\('pilotacct'\)[\s\S]{0,120}damascus/.test(A),
  'مشرف الفرع هيفتح تقفيلة روح دمشق كمان');

ok('والسقف مالوش دور جديد — الأدوار المقيّدة زي ما هي',
  ! /'store'\s+=> \[[^\]]*pilotacct/.test(A)
  && ! /'pilot'\s+=> \[[^\]]*pilotacct/.test(A),
  'دور مقيّد اتفتحله البرنامج');

/* ══ 2) البوابة ══ */
console.log('\n══ 2) home.html ══');
ok('الاشتقاق في البوابة زي السيرفر',
  /allowedApps\.includes\("accounts"\) && !allowedApps\.includes\("pilotacct"\)/.test(H));
ok('🔴 ومافيش اشتقاق عكسي هنا كمان',
  ! /includes\("pilotacct"\)[\s\S]{0,120}"damascus"/.test(H),
  'البوابة بتفتح روح دمشق لصاحب تقفيل الطيارين');
ok('🔴 المفتاح في قايمة ids — وإلا الكارت يفضل مقفول مهما اتدّى',
  /const ids = \[[^\]]*"pilotacct"/.test(H),
  'الكارت مش هيتفتح أبدًا');
ok('والكارت موجود في الماركب', /id="card-pilotacct"/.test(HOME));
ok('وبينده openApp بنفس المفتاح', /onclick="openApp\('pilotacct'\)"/.test(HOME));
ok('وفتحه مسموح لصاحب accounts كمان',
  /\(appKey === "pilotacct" && allowed\.includes\("accounts"\)\)/.test(H));
ok('🔴 وبيوّدي على accounts.html',
  /pilotacct:"accounts\.html"/.test(H),
  'المفتاح موجود ومالوش صفحة — الدوسة مش هتعمل حاجة');

/* ══ 3) محرّر الصلاحيات ══ */
console.log('\n══ 3) لوحة الإدارة ══');
ok('المفتاح في قايمة الصلاحيات — الأدمن يقدر يدّيه',
  /\{ key: "pilotacct",\s+label: "تقفيل الطيارين"/.test(T),
  'مافيش خانة يعلّم عليها');
ok('وفي افتراضي الأدمن', /roleDefaultApps[\s\S]{0,900}"accounts","pilotacct"/.test(T));
ok('وفي افتراضي المحاسب',
  /"accountant"\]\.includes\(r\)\)\s+return \["accounts","damascus","pilotacct","site"\]/.test(T));

/* ══ 4) الصفحة ══ */
console.log('\n══ 4) accounts.html ══');
ok('🔴 الصفحة بتبعت `pilotacct` عند الدخول',
  /var APP_KEY = "pilotacct";/.test(C),
  'بتبعت مفتاح والبوابة بتدّي غيره');
ok('وبوابة الأدوار فيها مشرف الفرع',
  /const ALLOWED = \["admin", "accountant", "branch"\];/.test(C),
  'الأدمن يدّي المفتاح والراجل يترفض عند الباب');
ok('والرسالة مابقتش بتحصر الأدوار بالاسم',
  ! /الصفحة دي للإدارة والمحاسب بس/.test(C));

/* ══ 5) مافيش حد فقد حاجة ══ */
console.log('\n══ 5) الأدوار القديمة ══');
ok('المحاسب لسه بياخد accounts', /'accountant'\s+=> \['accounts'\]/.test(A));
ok('و`accounts` لسه بيشتق `damascus`', /\$apps\[\] = 'damascus';/.test(A));
ok('والكارت موجود بالمعرّف الجديد', /id="card-pilotacct"/.test(HOME));
ok('ومسار accounts.html لسه شغّال', /accounts:"accounts\.html"/.test(H));

console.log('\n' + '─'.repeat(52));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — المفتاح مستقل ومش بيجرّ روح دمشق وراه\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
