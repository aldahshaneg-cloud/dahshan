<?php
/**
 * 💰 حارس: الوارد/الصادر اليدوي من الإدارة بس — الفروع لا (طلب صاحب النظام 2026-09-16).
 *
 * «لازم تقفل وارد وصادر دي من يوزرات المشرفين — وارد وصادر دي عند المديرين بس إنما الفروع لا».
 *
 * ═══ العقود ═══
 * • مشرف فرع: POST /api/cash-stores/{id}/transactions (in أو out) على خزنة فرعه = 403، والرصيد
 *   والسجل مايتلمسوش.
 * • الأدمن: نفس النداء = 200 وحركة فعلية.
 * • حركات الفرع التلقائية لسه شغّالة: سلفة من الخزنة (pilot-cash) لمشرف الفرع = 200 (مسار تاني).
 * • لوحة الفرع: مفيش زرار «تسجيل صادر/وارد».
 * تنفيذ حقيقي جوه معاملة بتترجع. التشغيل: php ops/test_branch_no_manual_cash.php
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

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
function hit($kernel, array $u, string $method, string $url, array $body = []): array
{
    $req = Request::create($url, $method, [], [], [], [], $body ? json_encode($body) : null);
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
$near = fn ($a, $b) => abs((float) $a - (float) $b) < 0.011;

$sup   = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'branch' AND blocked = 0 AND branch_id IS NOT NULL ORDER BY id LIMIT 1")[0] ?? null);
$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
if (! $sup || ! $admin) { echo "مافيش مشرف/أدمن — تخطّي\n"; exit(0); }
$b1 = (int) $sup['branch_id'];

echo "══ 0) الواجهة والمسار ══\n";
$B = file_get_contents($ROOT . '/public/branch.html');
$R = file_get_contents($ROOT . '/routes/api.php');
ok('🔴 لوحة الفرع من غير زرار «تسجيل صادر/وارد»', ! str_contains($B, "openModal('cashtxn')\">➕ تسجيل صادر/وارد"));
ok('🔴 المسار مقصور على admin,accountant', (bool) preg_match("~Route::post\('cash-stores/\{id\}/transactions'[^;]*->middleware\('role:admin,accountant'\)~s", $R));

DB::beginTransaction();
try {
    DB::insert('INSERT INTO cash_stores (name, branch_id, balance, created_at) VALUES (?,?,?,NOW())', ['خزنة فحص الإقفال', $b1, 1000]);
    $store = (int) DB::getPdo()->lastInsertId();
    $bal = fn () => round((float) DB::selectOne('SELECT balance FROM cash_stores WHERE id = ?', [$store])->balance, 2);
    $cnt = fn () => (int) DB::selectOne('SELECT COUNT(*) c FROM cash_transactions WHERE store_id = ?', [$store])->c;

    echo "\n══ 1) مشرف الفرع = 403 ══\n";
    [$c] = hit($kernel, $sup, 'POST', "/api/cash-stores/{$store}/transactions", ['type' => 'in', 'amount' => 50, 'reason' => 'فحص']);
    ok('🔴 وارد يدوي من مشرف الفرع مرفوض 403', $c === 403, (string) $c);
    [$c] = hit($kernel, $sup, 'POST', "/api/cash-stores/{$store}/transactions", ['type' => 'out', 'amount' => 50, 'reason' => 'فحص']);
    ok('🔴 صادر يدوي من مشرف الفرع مرفوض 403', $c === 403, (string) $c);
    ok('  والرصيد والسجل زي ما هما', $near($bal(), 1000) && $cnt() === 0, $bal() . ' / ' . $cnt());

    echo "\n══ 2) الأدمن = 200 ══\n";
    [$c, $j] = hit($kernel, $admin, 'POST', "/api/cash-stores/{$store}/transactions", ['type' => 'out', 'amount' => 50, 'reason' => 'فحص إدارة']);
    ok('صادر من الأدمن 200 والرصيد نقص', $c === 200 && $near($bal(), 950), $c . ' ' . $bal());

    echo "\n══ 3) حركات الفرع التلقائية لسه شغّالة ══\n";
    $pilot = DB::selectOne('SELECT id FROM pilots WHERE archived_at IS NULL AND (home_branch_id = ? OR assigned_branch_id = ?) ORDER BY id LIMIT 1', [$b1, $b1]);
    if ($pilot) {
        DB::update('UPDATE pilots SET home_branch_id = ?, assigned_branch_id = ? WHERE id = ?', [$b1, $b1, (int) $pilot->id]);
        DB::delete('DELETE FROM pilot_month_locks WHERE month = ?', [substr(App\Support\BizDay::key(), 0, 7)]);
        [$c, $j] = hit($kernel, $sup, 'POST', '/api/pilot-accounting/pilot-cash', ['pilotId' => (int) $pilot->id, 'kind' => 'advance', 'amount' => 20, 'cashStoreId' => $store]);
        ok('سلفة من الخزنة لمشرف الفرع لسه 200 (مسار السلف مش المسار اليدوي)', $c === 200 && $near($bal(), 930), $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    } else {
        echo "  ⊘ مافيش طيار للفرع — تخطّي\n";
    }
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "BRANCH NO MANUAL CASH: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
