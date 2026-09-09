<?php
/**
 * ⏱️ حارس: إنهاء الإذن من التطبيق في أقل من دقيقة = ضغطة بالغلط (2026-09-09).
 *
 * ٧ من آخر ٩ أذونات على الإنتاج اتنهت في أقل من دقيقة من الموافقة — فمابتتحسبش في التقفيلة
 * (أقل من MIN_PERM_MINUTES) والساعات بتطلع كاملة. الطيار بس هو اللي بيتمنع (409 برسالة)؛
 * المشرف من اللوحة لسه يقدر (عنده confirm أصلًا). كله جوه معاملة بتترجع.
 *
 * التشغيل: php ops/test_leave_quick_end.php
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

use Illuminate\Support\Facades\DB;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
function hit($kernel, array $u, string $method, string $url, array $body = []): array
{
    $req = Illuminate\Http\Request::create($url, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body ? json_encode($body) : null);
    $ses = app('session')->driver();
    $ses->flush(); $ses->start();
    $ses->put(['user_id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role'],
               'branch_id' => $u['branch_id'] ?? null, 'name' => $u['username']]);
    $req->setLaravelSession($ses);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
$pu    = (array) (DB::select("SELECT u.id, u.username, u.role, u.branch_id, u.pilot_id FROM users u JOIN pilots p ON p.id = u.pilot_id WHERE u.role = 'pilot' AND u.blocked = 0 AND p.archived_at IS NULL ORDER BY u.id LIMIT 1")[0] ?? null);
if (! $admin || ! $pu) { echo "مافيش أدمن/حساب طيار — تخطّي\n"; exit(0); }
$pid = (int) $pu['pilot_id'];
$bid = (int) (DB::selectOne('SELECT COALESCE(assigned_branch_id, home_branch_id, (SELECT id FROM branches ORDER BY id LIMIT 1)) AS b FROM pilots WHERE id = ?', [$pid])->b);

DB::beginTransaction();
try {
    $mkLeave = function (int $secsAgo) use ($pid, $bid): int {
        $t = gmdate('Y-m-d H:i:s', time() - $secsAgo);
        DB::update("UPDATE pilots SET status = 'on_leave', leave_forced = 0, break_started_at = ? WHERE id = ?", [$t, $pid]);
        DB::insert("INSERT INTO pilot_leave_requests (pilot_id, branch_id, type, status, requested_at, responded_at, responded_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())",
            [$pid, $bid, 'rest', 'approved', $t, $t, 'test']);
        return (int) DB::getPdo()->lastInsertId();
    };

    echo "══ 1) الطيار بينهي بعد ثواني ══\n";
    $id = $mkLeave(10);
    [$c, $j] = hit($kernel, $pu, 'POST', "/api/leave-requests/{$id}/end");
    ok('🔴 أقل من دقيقة = 409 برسالة عربية', $c === 409 && str_contains((string) ($j['error'] ?? ''), 'لسه بادئ'), $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    ok('والإذن لسه ساري', (DB::selectOne('SELECT status FROM pilot_leave_requests WHERE id = ?', [$id])->status) === 'approved');
    DB::update("UPDATE pilot_leave_requests SET status = 'ended', ended_at = NOW() WHERE id = ?", [$id]);

    echo "\n══ 2) بعد دقيقتين ══\n";
    $id = $mkLeave(120);
    [$c, $j] = hit($kernel, $pu, 'POST', "/api/leave-requests/{$id}/end");
    ok('🔴 الطيار يقدر ينهي بعد دقيقة', $c === 200, $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    ok('واتسجّل ended بوقت', (DB::selectOne('SELECT status, ended_at FROM pilot_leave_requests WHERE id = ?', [$id])->ended_at) !== null);

    echo "\n══ 3) المشرف/الأدمن مش بيتمنع (عنده تأكيد في اللوحة) ══\n";
    $id = $mkLeave(10);
    [$c] = hit($kernel, $admin, 'POST', "/api/leave-requests/{$id}/end");
    ok('الأدمن بينهي فورًا 200', $c === 200, (string) $c);
} finally {
    DB::rollBack();
}

echo "\n══ 4) التطبيق ══\n";
$appMain = $ROOT . '/../aldahshan/lib/main.dart';
if (is_file($appMain)) {
    ok('«عدت للعمل» بتأكيد قبل الإنهاء', str_contains(file_get_contents($appMain), "final ok = await _confirm(context, 'عدت للعمل؟'"));
} else {
    echo "  (مجلد التطبيق مش موجود هنا — تخطّي)\n";
}

echo "\n════════════════════════════════════════\n";
echo "LEAVE QUICK END: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
