<?php
/**
 * 🔒 حارس بوابة التطبيقات — «كل دور على تطبيقه، وadmin بس بيفتح أي حاجة».
 *
 * ═══ البلاغ اللي فتح الشغل ده (صاحب النظام 2026-08-31) ═══
 * «مشرف الفرع يدخل الكول سنتر بالباسورد الخاص به واسم المستخدم، والوحيد
 *  اللي يقدر يفتح أي تطبيق هو المدير العام admin».
 *
 * ═══ السبب ═══
 * كل صفحة تطبيق فيها فورم دخول بينده `/api/login`، و**الدخول كان بيقبل أي
 * دور بيصادق بنجاح**. `allowedApps` كانت بتتقرا في `home.html` بس — وهي
 * بوابة عرض كروت، والصفحات التانية بتتفتح بالـURL المباشر من غير ما تعدّي
 * عليها. فمشرف فرع بيفتح `callcenter.aldahshan.cloud` ويدخل عادي.
 *
 * ═══ القفل ═══
 * كل صفحة بتبعت `app` مع الدخول، و`AuthController::login` بيرفض بـ403 لو
 * الحساب مش مصرّح له (`appsFor`). ودور `admin` بيعدّي دايمًا.
 *
 * الفحص هنا بينده `appsFor` **الحقيقية** (مش بيحاكيها) على قاعدة مؤقتة،
 * وبيمشّط ملفات `public/` للتأكد إن كل صفحة بتبعت كودها.
 *
 * التشغيل: php ops/test_app_gate.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;
function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$what}\n"; }
    else { $fail++; echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n"; }
}

/* أي استثناء مش متمسك = فشل — مش خروج بصفر (شوف رأس test_store_profile.php) */
set_exception_handler(function (Throwable $e): void {
    echo "\n🔴 استثناء مش متمسك: " . get_class($e) . ' — ' . $e->getMessage() . "\n";
    exit(1);
});
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        exit(1);
    }
});

/* ── قاعدة مؤقتة ── */
$live = DB::connection()->getDatabaseName();
$tmp  = 'aldahshan_app_gate_test';
if (strcasecmp($tmp, $live) === 0) { echo "🔴 اسم القاعدة المؤقتة = الحقيقية\n"; exit(1); }
$cfg = config('database.connections.mysql');
$pdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']}", $cfg['username'], $cfg['password'],
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS `{$tmp}`");
$pdo->exec("CREATE DATABASE `{$tmp}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$tmp}`");
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (array_filter(array_map('trim', explode(";\n", file_get_contents(__DIR__ . '/../database/schema/mysql-schema.sql')))) as $stmt) {
    if ($stmt !== '' && ! str_starts_with($stmt, '--')) {
        try { $pdo->exec($stmt); } catch (Throwable $e) { /* تعليقات وسطور إعداد */ }
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
config(['database.connections.mysql.database' => $tmp]);
DB::purge('mysql');
DB::reconnect('mysql');

/* ── حسابات تجربة بنفس أشكال الإنتاج ── */
$mk = function (int $id, string $username, string $role, array $apps = []): void {
    DB::insert('INSERT INTO users (id, username, password_hash, role, created_at) VALUES (?,?,?,?,NOW())',
               [$id, $username, 'x', $role]);
    foreach ($apps as $a) {
        DB::insert('INSERT INTO user_app_permissions (user_id, app, created_at) VALUES (?,?,NOW())', [$id, $a]);
    }
};
$mk(1, 'admin',   'admin');                            // بلا صفوف — بياخد الافتراضي
$mk(2, 'branch1', 'branch',     ['branch', 'site']);
$mk(3, 'cc1',     'callcenter', ['callcenter', 'site']);
$mk(4, 'branch2', 'branch');                           // بلا صفوف خالص
$mk(5, 'store1',  'store',      ['site', 'store']);
$mk(6, 'storeX',  'store',      ['admin', 'store']);   // 🔴 نفس غلطة الإنتاج (roh)
$mk(7, 'acc1',    'accountant');
$mk(8, 'hr1',     'hr');
$mk(9, 'sup1',    'pilot_supervisor');
$mk(10, 'pilot1', 'pilot');
/* 🔴 لكل دور لازم يبقى فيه حساب **بلا صفوف صريحة** كمان — وإلا أي طفرة على
   القيمة الافتراضية بتعدّي. (فلتت فعلًا في تجربة كسر الحرّاس: الفحص على
   حساب كول سنتر بصفوف صريحة، والطفرة كانت على الافتراضي.) */
$mk(11, 'cc2', 'callcenter');

$appsOf = fn (int $id, string $role): array => AuthController::appsFor($id, $role);

echo "\n══ 1) 🔴 البلاغ نفسه: مشرف الفرع والكول سنتر ══\n";
ok('مشرف الفرع **مش** مصرّح له بالكول سنتر',
   ! in_array('callcenter', $appsOf(2, 'branch'), true),
   implode(',', $appsOf(2, 'branch')));
ok('ومصرّح له بتطبيقه هو',  in_array('branch', $appsOf(2, 'branch'), true));
ok('والكول سنتر مش مصرّح له بالفرع',
   ! in_array('branch', $appsOf(3, 'callcenter'), true),
   implode(',', $appsOf(3, 'callcenter')));
ok('ولا بلوحة الإدارة',     ! in_array('admin', $appsOf(3, 'callcenter'), true));
/* نفس الفحصين على حساب **بلا صفوف صريحة** — بيغطّي الافتراضي مش الصفوف */
ok('وكول سنتر بلا صفوف مش مصرّح له بالفرع',
   ! in_array('branch', $appsOf(11, 'callcenter'), true),
   implode(',', $appsOf(11, 'callcenter')));
ok('ولا بلوحة الإدارة',     ! in_array('admin', $appsOf(11, 'callcenter'), true),
   implode(',', $appsOf(11, 'callcenter')));
ok('وبياخد تطبيقه هو',      in_array('callcenter', $appsOf(11, 'callcenter'), true));

echo "\n══ 2) المدير العام بيفتح أي تطبيق ══\n";
$adminApps = $appsOf(1, 'admin');
foreach (['admin', 'branch', 'callcenter', 'store', 'storesadmin', 'pilotsadmin',
          'customers', 'siteadmin', 'customer'] as $a) {
    ok("admin بيفتح «{$a}»", in_array($a, $adminApps, true), implode(',', $adminApps));
}

echo "\n══ 3) الافتراضي لما مفيش صفوف صريحة ══\n";
/* الافتراضي كان `[]` لكل دور غير admin/pilot_supervisor — مقبول وقت ما
   البوابة كانت عرض كروت، بس بعد ما بقت قفل دخول كان هيقفل الموظف بره
   كل التطبيقات. */
ok('مشرف فرع بلا صفوف بياخد تطبيقه',   in_array('branch', $appsOf(4, 'branch'), true),
   implode(',', $appsOf(4, 'branch')));
ok('ومابياخدش الكول سنتر',             ! in_array('callcenter', $appsOf(4, 'branch'), true));
ok('محاسب بلا صفوف بياخد accounts',     in_array('accounts', $appsOf(7, 'accountant'), true));
ok('و«damascus» المشتقّة منها',         in_array('damascus', $appsOf(7, 'accountant'), true));
ok('HR بلا صفوف بياخد hr',              in_array('hr', $appsOf(8, 'hr'), true));
ok('ومابياخدش لوحة الإدارة',            ! in_array('admin', $appsOf(8, 'hr'), true));
ok('مشرف الطيارين بياخد pilotsadmin',   in_array('pilotsadmin', $appsOf(9, 'pilot_supervisor'), true));
ok('ومابياخدش غيرها (غير site)',
   count(array_diff($appsOf(9, 'pilot_supervisor'), ['pilotsadmin', 'site'])) === 0,
   implode(',', $appsOf(9, 'pilot_supervisor')));

echo "\n══ 4) 🔴 السقف: صف صلاحيات غلط مايرفعش دور مقيّد ══\n";
/* حالة حقيقية على الإنتاج: حساب المحل `roh` عنده صف `admin`. */
$sx = $appsOf(6, 'store');
ok('محل عنده صف admin **مابياخدش** admin', ! in_array('admin', $sx, true), implode(',', $sx));
ok('ولا أي تطبيق إدارة مشتقّ منه',
   ! array_intersect($sx, ['customers', 'siteadmin', 'storesadmin', 'pilotsadmin', 'perf']),
   implode(',', $sx));
ok('وبياخد تطبيقه هو بس', in_array('store', $sx, true));
ok('والطيار مالوش أي تطبيق لوحة',
   count(array_diff($appsOf(10, 'pilot'), ['site'])) === 0, implode(',', $appsOf(10, 'pilot')));

echo "\n══ 5) كل صفحة بتبعت كودها ══\n";
/* القفل على السيرفر، بس لو الصفحة مابتبعتش `app` القفل بيتخطّى بالكامل. */
$EXPECT = [
    'callcenter.html' => 'callcenter', 'branch.html'    => 'branch',
    'tiar.html'       => 'admin',      'store.html'     => 'store',
    'damascus.html'   => 'damascus',   'pilots.html'    => 'pilotsadmin',
    'stores.html'     => 'storesadmin', 'customers.html' => 'customers',
    'site-admin.html' => 'siteadmin',
    /* اتضاف 2026-08-31 مع برنامج «تقفيل الطيارين» المستقل. اللستة دي
       **يدوية** — أي صفحة تطبيق جديدة مش مضافة هنا بتعدّي بفورم دخول
       غير محروس والحارس يقول ناجح.
       2026-09-01: المفتاح بقى `pilotacct` (شاشة صلاحيات التقفيل الجديدة) —
       و`accounts` لسه بيفتح الصفحة من appsFor، بس اللي الصفحة بتبعته
       في الدخول هو pilotacct. */
    'accounts.html'   => 'pilotacct',
];
foreach ($EXPECT as $file => $key) {
    $src = @file_get_contents(__DIR__ . '/../public/' . $file);
    if ($src === false) { ok("{$file} موجود", false); continue; }
    ok("{$file} كوده «{$key}»",
       (bool) preg_match('/APP_KEY\s*=\s*"' . preg_quote($key, '/') . '"/', $src));
    // كل نداءات الدخول في الصفحة لازم تبعت الكود — واحد ناسي = ثغرة كاملة
    $total  = preg_match_all('/\bAPI?\.login\s*\(/i', $src);
    $gated  = preg_match_all('/\bAPI?\.login\s*\([^)]*APP_KEY\s*\)/i', $src);
    ok("  وكل نداءات الدخول فيه مقفولة ({$gated}/{$total})", $total > 0 && $gated === $total);
}

echo "\n══ 6) الدالة مصدر واحد — /api/me وبوابة الدخول ══\n";
$auth = file_get_contents((new ReflectionClass(AuthController::class))->getFileName());
ok('/api/me بينده appsFor مش نسخة تانية من المنطق',
   (bool) preg_match('/allowedApps.\]\s*=\s*self::appsFor\(/', $auth),
   'فيه منطق مكرّر — البوابة والكروت هيختلفوا');
ok('وبوابة الدخول بتنده نفس الدالة',
   (bool) preg_match('/in_array\(\$wantApp, self::appsFor\(/', $auth));
ok('والرفض 403 مش 401',    str_contains($auth, "ApiException::forbidden('حسابك مش مصرّح"));
ok('وadmin بيتخطّى البوابة', (bool) preg_match("/\\\$user->role !== 'admin'/", $auth));
/* القفل مايتحسبش محاولة دخول فاشلة — المستخدم مش بيخمّن باسورد */
$loginFn = substr($auth, strpos($auth, 'public function login('),
                  strpos($auth, 'public function adminGoogleLogin') - strpos($auth, 'public function login('));
$gateAt = strpos($loginFn, '$wantApp');
ok('والقفل مابيقفلش الحساب على محاولة صحيحة',
   ! str_contains(substr($loginFn, $gateAt, 400), 'registerFailure'));

echo "\n══ 6.5) 🔴 ثغرات كانت بتلفّ حوالين القفل (مسكتها المراجعة العدائية) ══\n";
/* ① القفل كان على `POST /api/login` بس، ومسار استعادة الجلسة في
   callcenter.html كان بيفحص **الاسم بس** — فمشرف فرع بجلسة قديمة، أو
   بجلسة من home.html + سطر localStorage، بيفتح الكول سنتر عادي.
   tiar.html فيه الفحص ده من زمان مع تعليق بيوصف نفس الثغرة. */
$cc = file_get_contents(__DIR__ . '/../public/callcenter.html');
ok('استعادة جلسة الكول سنتر بتفحص الدور',
   (bool) preg_match('/CC_ROLES\s*=\s*\["callcenter",\s*"admin"\]/', $cc),
   'الباب الخلفي مفتوح — أي دور بجلسة سارية بيفتح الكول سنتر');
ok('  والفحص مستعمل في شرط الاستعادة',
   (bool) preg_match('/!CC_ROLES\.includes\(me\.role\)/', $cc));
/* والمقارنة على `me.role` من السيرفر مش على `saved.role` من الكاش —
   الكاش المستخدم بيكتبه بإيده. */
ok('  ومن رد السيرفر مش من الكاش المحلي',
   ! (bool) preg_match('/includes\(saved\.role\)/', $cc));

/* ② `app` بييجي من العميل، فحذفه كان بيلغي القفل. النطاق بييجي من ترويسة
   Host اللي أباتشي بيوجّه بيها الـvhost — مايتزوّرش للوصول لنفس المكان. */
ok('التطبيق بيتحدّد من النطاق قبل الجسم',
   (bool) preg_match("/'callcenter\.aldahshan\.cloud'\s*=>\s*'callcenter'/", $auth ?? '')
   || (bool) preg_match("/'callcenter\.aldahshan\.cloud'\s*=>\s*'callcenter'/",
                        file_get_contents((new ReflectionClass(AuthController::class))->getFileName())),
   'حذف `app` من الجسم بيلغي القفل بالكامل');
$authSrc = file_get_contents((new ReflectionClass(AuthController::class))->getFileName());
ok('  ونطاق الفرع كمان',   str_contains($authSrc, "'branch.aldahshan.cloud'     => 'branch'"));
ok('  والنطاق بيغلب الجسم', (bool) preg_match('/\$wantApp\s*=\s*\$byHost\s*\?\?/', $authSrc),
   'الجسم بيغلب النطاق — العميل يقدر يقلّل صلاحيته المعلنة');

/* ③ توكن الموبايل مالوش نهاية صلاحية وبيبني actor كامل — كان أي دور
   يقدر يطلّعه لنفسه بـclient=pilot-app ويستعمله بره الجلسة والقفل. */
ok('توكن الموبايل للطيارين بس',
   (bool) preg_match("/client'\) === 'pilot-app' && \\\$user->role === 'pilot'/", $authSrc),
   'أي حساب بيطلّع Bearer دائم لنفسه');

/* ④ `POST /api/orders/{id}/images` كان بلا أي role — وبيرجّع الأوردر كامل */
$routes = file_get_contents(__DIR__ . '/../routes/api.php');
/* 🔴 لازم نقص **جملة المسار نفسها** لحد أول `;` — regex على الملف كله
   بيلاقي `role:` بتاعة مسار تاني بعده ويعدّي. (فلتت في تجربة الكسر.) */
$imgAt   = strpos($routes, "Route::post('orders/{id}/images'");
$imgStmt = $imgAt !== false ? substr($routes, $imgAt, strpos($routes, ';', $imgAt) - $imgAt) : '';
ok('مسار صور الأوردر موجود', $imgStmt !== '');
ok('  وعليه قيد دور', str_contains($imgStmt, "->middleware('role:"),
   'أي حساب مسجّل بيقرا أي أوردر كامل ويكتب عليه');
ok('  والعميل مش من ضمنهم', ! str_contains($imgStmt, 'customer'), $imgStmt);

echo "\n══ 7) توافق التوسعات مع home.html ══\n";
/* لو الاتنين اختلفوا: البوابة بتعرض كارت والدخول يرفضه. */
$home = file_get_contents(__DIR__ . '/../public/home.html');
foreach ([['hrOld', 'hr'], ['damascus', 'accounts'], ['customer', 'admin'],
          ['storesadmin', 'admin'], ['perf', 'hr']] as [$derived, $from]) {
    ok("«{$derived}» مشتقّة من «{$from}» في السيرفر",
       in_array($derived, $appsOf(1, $from === 'admin' ? 'admin'
                : ($from === 'hr' ? 'hr' : 'accountant')) ?: [], true)
       || in_array($derived, $appsOf($from === 'hr' ? 8 : ($from === 'accounts' ? 7 : 1),
                                     $from === 'hr' ? 'hr' : ($from === 'accounts' ? 'accountant' : 'admin')), true));
    ok("  وفي home.html كمان", str_contains($home, "\"{$derived}\""));
}

/* ── تنضيف ── */
DB::purge('mysql');
$pdo->exec("DROP DATABASE `{$tmp}`");

echo "\n══════════════════════════════════════════════\n";
echo "APP GATE: {$pass} ناجح · {$fail} فاشل\n";
echo "══════════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
