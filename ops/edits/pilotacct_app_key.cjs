/* 🔑 مفتاح تطبيق مستقل لـ«تقفيل الطيارين»: `pilotacct`.
 *
 * ═══ ليه مش `accounts` ═══
 * شاشة الصلاحيات الجديدة معناها إن الأدمن يقدر يدّي البرنامج لمشرف
 * فرع أو محاسب ويحدّد له يشوف إيه. بس علشان الشخص ده يفتح البرنامج
 * أصلًا لازم ياخد مفتاح تطبيق — والمفتاح الموجود `accounts`.
 *
 * والمشكلة إن `accounts` **بيشتق** `damascus`:
 *     AuthController::appsFor:100 — if ($has('accounts')) $apps[] = 'damascus';
 *     home.html:1045 و home.html:1081 — نفس الاشتقاق
 * يعني إدّي مشرف فرع صلاحية «تقفيل الطيارين» = فتحت له «تقفيل روح
 * دمشق» كمان، وده برنامج تاني خالص بفلوس شركة تانية.
 *
 * المفتاح الجديد بيفصل الاتنين: `accounts` زي ما هو للمحاسب (وبيشتق
 * `pilotacct` كمان فمافيش حاجة بتتكسر عليه)، و`pilotacct` لوحده
 * بيفتح `accounts.html` من غير روح دمشق.
 *
 * ═══ مافيش حد بيفقد حاجة ═══
 * الأدمن بياخده في الافتراضي، والمحاسب بيشتقه من `accounts`. اللي
 * بيتغيّر إن بقى فيه مفتاح **يتدّي لوحده**.
 *
 * 🔒 الحارس: ops/test_pilotacct_app_key.cjs
 */
const fs = require('fs');
let bad = 0;
/* 🔴 التعديل بيتعمل في الذاكرة والكتابة في الآخر بس. التعديل ده بيمس
   أربع ملفات؛ لو واحد فشل في النص كانت التلاتة اللي قبله هيبقوا اتكتبوا
   والنظام في نص الطريق — وهو لايف. */
const BUF = {};
const load = f => (BUF[f] !== undefined ? BUF[f] : (BUF[f] = fs.readFileSync(f, 'utf8')));
const one = (file, old, neu, label) => {
  const s = load(file);
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  BUF[file] = s.replace(old, neu);
  console.log('  ✓ ' + label);
};
const L = (...x) => x.join('\n');

const AUTH = 'app/Http/Controllers/Api/AuthController.php';
const HOME = 'public/home.html';
const TIAR = 'public/tiar.html';
const ACCT = 'public/accounts.html';

if (fs.readFileSync(AUTH, 'utf8').includes('pilotacct')) {
  console.log('🔴 موجود قبل كده'); process.exit(1);
}

/* ═══ ① appsFor ═══ */
one(AUTH,
  "                'admin'            => ['admin', 'branch', 'store', 'callcenter', 'hr', 'accounts'],",
  "                'admin'            => ['admin', 'branch', 'store', 'callcenter', 'hr', 'accounts', 'pilotacct'],",
  '① الأدمن بياخد المفتاح الجديد'
);

one(AUTH,
  "        if ($has('accounts')) { $apps[] = 'damascus'; }",
  L(
    '        /* 🔑 `accounts` بيشتق الاتنين — المحاسب مابيفقدش حاجة.',
    '           إنما `pilotacct` لوحده **مابيشتقش** `damascus`: ده كل',
    '           الفرق، وهو اللي بيخلّي الأدمن يدّي «تقفيل الطيارين»',
    '           لمشرف فرع من غير ما يفتح له تقفيلة روح دمشق. */',
    "        if ($has('accounts')) { $apps[] = 'damascus'; $apps[] = 'pilotacct'; }"
  ),
  '② الاشتقاق'
);

one(AUTH,
  "                'allowedApps' => ['admin', 'branch', 'store', 'callcenter', 'hr', 'accounts'],",
  "                'allowedApps' => ['admin', 'branch', 'store', 'callcenter', 'hr', 'accounts', 'pilotacct'],",
  '③ قايمة الأدمن التانية'
);

/* ═══ ② home.html ═══ */
one(HOME,
  '      if (allowedApps.includes("accounts") && !allowedApps.includes("damascus")) allowedApps = [...allowedApps, "damascus"];',
  L(
    '      if (allowedApps.includes("accounts") && !allowedApps.includes("damascus")) allowedApps = [...allowedApps, "damascus"];',
    '      // 🔑 `accounts` بيشتق «تقفيل الطيارين» كمان — والعكس مش صحيح',
    '      if (allowedApps.includes("accounts") && !allowedApps.includes("pilotacct")) allowedApps = [...allowedApps, "pilotacct"];'
  ),
  '④ الاشتقاق في البوابة'
);

one(HOME,
  '      const ids = ["admin","branch","store","hr","hrOld","accounts","callcenter","damascus",\n        "customer","customers","perf","storesadmin","pilotsadmin","site","siteadmin"];',
  '      const ids = ["admin","branch","store","hr","hrOld","accounts","pilotacct","callcenter","damascus",\n        "customer","customers","perf","storesadmin","pilotsadmin","site","siteadmin"];',
  '⑤ قايمة الكروت'
);

one(HOME,
  '        || (appKey === "damascus" && allowed.includes("accounts"))',
  L(
    '        || (appKey === "damascus" && allowed.includes("accounts"))',
    '        || (appKey === "pilotacct" && allowed.includes("accounts"))'
  ),
  '⑥ فتح البرنامج'
);

one(HOME,
  '        accounts:"accounts.html",',
  '        accounts:"accounts.html", pilotacct:"accounts.html",',
  '⑦ الرابط'
);

/* الكارت نفسه — نسخة من كارت accounts بأيقونة مختلفة */
one(HOME,
  '        <!-- Accounts -->\n        <div class="app-card purple" id="card-accounts" onclick="openApp(\'accounts\')">',
  L(
    '        <!-- 🧾 تقفيل الطيارين — برنامج مستقل بمفتاحه (`pilotacct`).',
    '             بيفتح نفس الملف بتاع «النظام المحاسبي» بس بيتدّي لوحده. -->',
    '        <div class="app-card purple" id="card-pilotacct" onclick="openApp(\'pilotacct\')">',
    '          <div class="app-icon">',
    '            <svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">',
    '              <rect x="5" y="4" width="26" height="28" rx="3" fill="#a855f7" opacity=".12" stroke="#a855f7" stroke-width="1.5"/>',
    '              <line x1="10" y1="12" x2="26" y2="12" stroke="#a855f7" stroke-width="1.6" stroke-linecap="round"/>',
    '              <line x1="10" y1="18" x2="26" y2="18" stroke="#a855f7" stroke-width="1.6" stroke-linecap="round" opacity=".6"/>',
    '              <line x1="10" y1="24" x2="20" y2="24" stroke="#a855f7" stroke-width="1.6" stroke-linecap="round" opacity=".6"/>',
    '            </svg>',
    '          </div>',
    '          <div class="app-name">تقفيل الطيارين</div>',
    '        </div>',
    '',
    "        <!-- Accounts -->",
    '        <div class="app-card purple" id="card-accounts" onclick="openApp(\'accounts\')">'
  ),
  '⑧ كارت البرنامج'
);

/* ═══ ③ محرّر الصلاحيات في لوحة الإدارة ═══ */
one(TIAR,
  '      { key: "accounts",   label: "النظام المحاسبي",     icon: "💰" },',
  L(
    '      { key: "accounts",   label: "النظام المحاسبي",     icon: "💰" },',
    '      /* 🔑 مفتاح مستقل: بيفتح `accounts.html` من غير ما يشتق روح دمشق،',
    '         عشان مشرف الفرع ياخد تقفيل الطيارين لوحده. */',
    '      { key: "pilotacct",  label: "تقفيل الطيارين",      icon: "🧾" },'
  ),
  '⑨ قايمة الصلاحيات في اللوحة'
);

one(TIAR,
  '        return ["admin","branch","store","hr","hrOld","accounts","callcenter","damascus","customer","customers","site","siteadmin","perf","storesadmin","pilotsadmin"];',
  '        return ["admin","branch","store","hr","hrOld","accounts","pilotacct","callcenter","damascus","customer","customers","site","siteadmin","perf","storesadmin","pilotsadmin"];',
  '⑩ افتراضي الأدمن'
);

one(TIAR,
  '      if (["محاسب", "مدير حسابات", "accountant"].includes(r))       return ["accounts","damascus","site"];',
  '      if (["محاسب", "مدير حسابات", "accountant"].includes(r))       return ["accounts","damascus","pilotacct","site"];',
  '⑪ افتراضي المحاسب'
);

/* ═══ ④ الصفحة نفسها بتقبل المفتاحين ═══ */
one(ACCT,
  'var APP_KEY = "accounts";',
  L(
    '/* 🔑 المفتاحين بيفتحوا الصفحة دي: `accounts` (المحاسب) و`pilotacct`',
    '   (اللي الأدمن بيدّيه لمشرف فرع من غير روح دمشق). */',
    'var APP_KEY = "pilotacct";'
  ),
  '⑫ مفتاح الصفحة'
);

/* ═══ ⑤ بوابة الصفحة تقبل مشرف الفرع ═══
   من غير ده الأدمن يدّي المفتاح والراجل يترفض عند الباب برسالة
   «الصفحة دي للإدارة والمحاسب بس». السيرفر هو الحكم: كل مسارات
   `pilot-accounting` بتقبل `admin,branch,accountant` وطبقة الصلاحيات
   الجديدة بتقص من جوّه. */
one(ACCT,
  'const ALLOWED = ["admin", "accountant"];',
  'const ALLOWED = ["admin", "accountant", "branch"];',
  '⑬ بوابة الأدوار'
);

one(ACCT,
  '      err.textContent = "الصفحة دي للإدارة والمحاسب بس"; return;',
  '      err.textContent = "حسابك مش مصرّح له بالبرنامج ده"; return;',
  '⑭ رسالة الرفض'
);

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش ولا ملف'); process.exit(1); }
Object.keys(BUF).forEach(f => fs.writeFileSync(f, BUF[f]));
console.log('\n✅ المفتاح المستقل اتعمل — ' + Object.keys(BUF).length + ' ملفات');
