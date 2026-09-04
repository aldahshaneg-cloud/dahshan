/* ربط برنامج «تقفيل الطيارين» بالمنظومة.
 *
 * ═══ ① السيرفر: المحاسب يقدر يملا الشيت ═══
 * التعليق فوق مسارات `pilot-accounting` بيقول صراحةً:
 *   «السلف المؤجلة وقفل الشهر والإعدادات **للإدارة بس** — قرارات مالية»
 * فالمحاسب بياخد اللي مشرف الفرع بياخده بالظبط: `entry` (يملا الشيت)
 * و`perms` (يظبّط أعمدة الصلاحيات). والتلاتة الماليين يفضلوا للأدمن —
 * مش هوسّع صلاحية فلوس بالسكوت من غير ما صاحب النظام يقول.
 *
 * القراءة (`month` · `deferred` · `settings`) كانت أصلاً بتقبل `accountant`.
 *
 * ═══ ② شاشة المجموعة ═══
 * الكارت موجود من زمان بس مطفّي — `accounts` مسجّل في `APPS_NOT_BUILT`
 * والتعليق هناك بيقول: «بيتصرّحوا في allowedApps بس مفيش ملفات ليهم في
 * public/. النتيجة كانت كروت شغّالة بتودّي 404».
 * دلوقتي الملف موجود، فبنشيله من اللستة ونضيف مساره.
 *
 * والاسم بيتغيّر من «النظام المحاسبي» لـ«تقفيل الطيارين» — البرنامج
 * بياخد التقفيل بس (قرار صاحب النظام)، والاسم القديم بيوعد بحاجة أكبر.
 */
const fs = require('fs');
let bad = 0;

const one = (file, old, neu, label) => {
  let s = fs.readFileSync(file, 'utf8');
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  fs.writeFileSync(file, s.replace(old, neu));
  console.log('  ✓ ' + label);
};
const L = (...x) => x.join('\n');

/* ═══ ① المحاسب يملا الشيت ═══ */
one('routes/api.php',
  L("Route::post('pilot-accounting/entry', [PilotAccountingController::class, 'entrySave'])",
    "    ->middleware('role:admin,branch');",
    "Route::post('pilot-accounting/perms', [PilotAccountingController::class, 'permsSave'])",
    "    ->middleware('role:admin,branch');"),
  L("/* المحاسب اتضاف 2026-08-31 مع برنامج «تقفيل الطيارين» المستقل",
    "   (public/accounts.html). بياخد اللي مشرف الفرع بياخده بالظبط: يملا",
    "   الشيت ويظبّط أعمدة الصلاحيات. أما السلف والقفل والإعدادات تحت",
    "   فبيفضلوا للإدارة — قرارات مالية زي ما التعليق فوق بيقول. */",
    "Route::post('pilot-accounting/entry', [PilotAccountingController::class, 'entrySave'])",
    "    ->middleware('role:admin,branch,accountant');",
    "Route::post('pilot-accounting/perms', [PilotAccountingController::class, 'permsSave'])",
    "    ->middleware('role:admin,branch,accountant');"),
  '① المحاسب على entry و perms');

/* ═══ ② شاشة المجموعة — اللستة ═══ */
one('public/home.html',
  'const APPS_NOT_BUILT = ["hr", "hrOld", "accounts", "perf"];',
  L('/* `accounts` اتشال 2026-08-31 — البرنامج اتبنى (public/accounts.html).',
    '   الباقي لسه بيتصرّح في allowedApps من غير ملفات، فكروتهم بتفضل مطفية. */',
    'const APPS_NOT_BUILT = ["hr", "hrOld", "perf"];'),
  '② شيل accounts من غير المبنيّة');

/* ═══ ②ب المسار ═══ */
one('public/home.html',
  L('        storesadmin:"stores.html", pilotsadmin:"pilots.html",',
    '        site:"https://aldahshan.cloud/", siteadmin:"site-admin.html"'),
  L('        storesadmin:"stores.html", pilotsadmin:"pilots.html",',
    '        accounts:"accounts.html",',
    '        site:"https://aldahshan.cloud/", siteadmin:"site-admin.html"'),
  '②ب مسار accounts');

/* ═══ ②ج الاسم ═══ */
one('public/home.html',
  '          <div class="app-name">النظام المحاسبي</div>',
  L('          <!-- الاسم اتغيّر 2026-08-31: البرنامج بياخد تقفيل الطيارين بس',
    '               (قرار صاحب النظام)، و«النظام المحاسبي» كان بيوعد بأكبر. -->',
    '          <div class="app-name">تقفيل الطيارين</div>'),
  '②ج اسم الكارت');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة'); process.exit(1); }
console.log('\n✅ البرنامج اترابط');
