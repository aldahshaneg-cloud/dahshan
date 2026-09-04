<?php
/**
 * 🍽️ حارس: حسابات «روح دمشق بس» قطاع منفصل عن موظفي الدهشان.
 *
 * ═══ الطلب (صاحب النظام 2026-09-04) ═══
 * «عايزك تعزل اللي اتنقل للسيستم الجديد — موظفين في قطاع آخر. خلي تقفيلة
 *  روح دمشق مستقلة بذاتها؛ لقيت أسماء الموظفين اللي فيها في تقفيلة الموظفين».
 *
 * ═══ القاعدة ═══
 * الحساب اللي صفوف تطبيقاته الصريحة كلها جوّه {damascus, site} = حساب دمشق بس:
 *   • مايظهرش في تقفيلة الموظفين ولا صلاحيات تقفيل الطيارين ولا قايمة /api/users.
 *   • بيظهر في قايمة صلاحيات روح دمشق (rd/perms) — وموظف الدهشان العادي لأ.
 *
 * ═══ الفحص بينفّذ الراوتر الحقيقي ═══
 * بيعمل حسابين جوه معاملة (واحد دمشق بس وواحد مشرف فرع دهشاني) ويندَه المسارات
 * الأربعة بجلسة الأدمن، وبيرجّع كل حاجة. أي رجوع للقوايم القديمة بيقع هنا.
 *
 * التشغيل: php ops/test_rd_isolation.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;

function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$what}\n"; }
    else { $fail++; echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n"; }
}

require $ROOT . '/vendor/autoload.php';
$app = require $ROOT . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): void {
    echo "\n🔴 استثناء مش متمسك: " . get_class($e) . ' — ' . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
});
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        exit(1);
    }
});

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\DB;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
function hit($kernel, array $u, string $method, string $url, array $body = []): array
{
    $req = Illuminate\Http\Request::create($url, $method, [], [], [], [], $body ? json_encode($body) : null);
    $req->headers->set('Accept', 'application/json');
    if ($body) { $req->headers->set('Content-Type', 'application/json'); }
    $ses = app('session')->driver();
    $ses->start();
    $ses->put(['user_id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role'],
               'branch_id' => $u['branch_id'] ?? null, 'name' => $u['username']]);
    $req->setLaravelSession($ses);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
if (! $admin) { echo "مافيش أدمن — تخطّي\n"; exit(0); }
$branchId = (int) (DB::select('SELECT id FROM branches ORDER BY id LIMIT 1')[0]->id ?? 0);

DB::beginTransaction();
try {
    $mk = function (string $role, array $apps, ?int $branch = null): array {
        $un = 'rdiso_' . substr(bin2hex(random_bytes(3)), 0, 6);
        DB::insert('INSERT INTO users (username, password_hash, role, name, branch_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$un, password_hash('x', PASSWORD_BCRYPT), $role, 'فحص ' . $un, $branch]);
        $id = (int) DB::getPdo()->lastInsertId();
        foreach ($apps as $a) {
            DB::insert('INSERT INTO user_app_permissions (user_id, app, created_at) VALUES (?, ?, NOW())', [$id, $a]);
        }

        return ['id' => $id, 'username' => $un, 'role' => $role, 'branch_id' => $branch];
    };
    /* زي المنقولين من Firebase: دور accountant + damascus بس */
    $rdAcc = $mk('accountant', ['damascus']);
    /* زي اللي بيتعمل من شاشة دمشق بدور «مشرف فرع» */
    $rdBr  = $mk('branch', ['damascus', 'site']);
    /* موظف دهشاني عادي — مشرف فرع بتطبيق الفرع */
    $dhBr  = $mk('branch', ['branch', 'site'], $branchId ?: null);
    /* محاسب الدهشان بلا صفوف — بياخد accounts (وبيشتق damascus) */
    $dhAcc = $mk('accountant', []);

    echo "\n══ 1) التعريف ══\n";
    $ids = AuthController::damascusOnlyUserIds();
    ok('حساب دمشق (accountant + damascus) متعرّف', in_array($rdAcc['id'], $ids, true));
    ok('حساب دمشق (branch + damascus + site) متعرّف', in_array($rdBr['id'], $ids, true));
    ok('مشرف فرع دهشاني مش متعرّف', ! in_array($dhBr['id'], $ids, true));
    ok('محاسب الدهشان بلا صفوف مش متعرّف', ! in_array($dhAcc['id'], $ids, true));

    $names = fn (array $list, string $key) => array_map(fn ($x) => (string) ($x[$key] ?? ''), $list);
    $ym = date('Y-m');

    echo "\n══ 2) تقفيلة الموظفين (staff-month) ══\n";
    [$c, $j] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/staff-month?month={$ym}");
    $us = $names($j['staff'] ?? [], 'username');
    ok('HTTP 200', $c === 200, (string) $c);
    ok('🔴 حساب دمشق (accountant) مش في التقفيلة', ! in_array($rdAcc['username'], $us, true));
    ok('🔴 حساب دمشق (branch) مش في التقفيلة', ! in_array($rdBr['username'], $us, true));
    ok('مشرف الفرع الدهشاني موجود', in_array($dhBr['username'], $us, true));
    ok('محاسب الدهشان موجود', in_array($dhAcc['username'], $us, true));

    echo "\n══ 3) صلاحيات تقفيل الطيارين (acl) ══\n";
    [$c, $j] = hit($kernel, $admin, 'GET', '/api/pilot-accounting/acl');
    $us = $names($j['users'] ?? [], 'username');
    ok('HTTP 200', $c === 200, (string) $c);
    ok('🔴 حسابات دمشق مش في القايمة', ! in_array($rdAcc['username'], $us, true) && ! in_array($rdBr['username'], $us, true));
    ok('موظفي الدهشان موجودين', in_array($dhBr['username'], $us, true) && in_array($dhAcc['username'], $us, true));

    echo "\n══ 4) قايمة المستخدمين (/api/users — لوحة الإدارة وتقفيل الطيارين) ══\n";
    [$c, $j] = hit($kernel, $admin, 'GET', '/api/users');
    $us = $names($j['items'] ?? [], 'username');
    ok('HTTP 200', $c === 200, (string) $c);
    ok('🔴 حسابات دمشق مش في القايمة', ! in_array($rdAcc['username'], $us, true) && ! in_array($rdBr['username'], $us, true));
    ok('موظفي الدهشان موجودين', in_array($dhBr['username'], $us, true) && in_array($dhAcc['username'], $us, true));
    [$c] = hit($kernel, $admin, 'PUT', '/api/users/' . $rdAcc['id'], ['name' => 'اسم جديد']);
    ok('لكن تعديل حساب دمشق من مساره لسه شغّال (شاشة دمشق بتستعمله)', $c === 200, (string) $c);

    echo "\n══ 5) صلاحيات روح دمشق (rd/perms) — العكس ══\n";
    [$c, $j] = hit($kernel, $admin, 'GET', '/api/rd/perms');
    $us = $names($j['users'] ?? [], 'username');
    ok('HTTP 200', $c === 200, (string) $c);
    ok('حسابات دمشق موجودة', in_array($rdAcc['username'], $us, true) && in_array($rdBr['username'], $us, true));
    ok('محاسب الدهشان موجود (بيقدر يفتح دمشق)', in_array($dhAcc['username'], $us, true));
    ok('🔴 مشرف الفرع الدهشاني مش في القايمة', ! in_array($dhBr['username'], $us, true));
    ok('الأدمن مش في القايمة', ! in_array($admin['username'], $us, true));
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "RD ISOLATION: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
