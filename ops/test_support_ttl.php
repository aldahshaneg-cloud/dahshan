<?php
/**
 * ⏳ حارس: طلب الدعم المعلّق بينتهي لوحده بعد 3 ساعات (بلاغ صاحب النظام 2026-09-20).
 *
 * «الفرع التجريبي لما بعمل كنترول F5 بلاقي إنذار طلب الدعم من كل الفروع شغال»: الطلب العام اللي
 * محدش قبله كان بيفضل pending للأبد (4 طلبات من 10/14/17 سبتمبر) — فأي فرع مردّش (فرع جديد)
 * بياخد صفارة إنذار مع كل تحميل للصفحة على طلب عمره أيام.
 *
 * ═══ العقود (تنفيذ حقيقي جوه معاملة بتترجع) ═══
 * • GET /api/support-requests بيقفل (ended) أي pending أقدم من SUPPORT_TTL_HOURS، والجديد بيفضل.
 * • GET /api/board كمان (عدّاد support مابيعدّش القديم).
 * • الطلب المقبول (accepted) مابيتلمسش مهما كان قديم — الطيار في الطريق.
 * • branch.html: الإنذار بيتجاهل الطلب اللي عمره أكتر من 3 ساعات (requestedAt) حتى لو النسخة المخزّنة pending.
 * التشغيل: php ops/test_support_ttl.php
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

use App\Http\Controllers\Api\BoardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

echo "══ 0) الواجهة ══\n";
$B = file_get_contents($ROOT . '/public/branch.html');
ok('المدة في السيرفر 3 ساعات', BoardController::SUPPORT_TTL_HOURS === 3);
ok('🔴 الإنذار بيتجاهل الطلب القديم (requestedAt + 3 ساعات)', str_contains($B, 'new Date(r.requestedAt || r.createdAt || 0).getTime()')
    && str_contains($B, '(Date.now() - t) < 3 * 3600e3') && str_contains($B, 'r.status === "pending" && _supFresh(r) &&'));

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
function hit($kernel, array $u, string $method, string $url): array
{
    $req = Request::create($url, $method);
    $req->headers->set('Accept', 'application/json');
    $ses = app('session')->driver();
    $ses->flush(); $ses->start();
    $ses->put(['user_id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role'],
               'branch_id' => $u['branch_id'] ?? null, 'name' => $u['username']]);
    $req->setLaravelSession($ses);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$sup = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'branch' AND blocked = 0 AND branch_id IS NOT NULL ORDER BY id LIMIT 1")[0] ?? null);
$other = DB::selectOne('SELECT id FROM branches WHERE id <> ? ORDER BY id LIMIT 1', [(int) ($sup['branch_id'] ?? 0)]);
if (! $sup || ! $other) { echo "مافيش مشرف/فرع تاني — تخطّي\n"; exit(0); }

DB::beginTransaction();
try {
    $ago = fn (int $h) => gmdate('Y-m-d H:i:s', time() - $h * 3600);
    $ins = function (string $status, int $hours) use ($other, $ago): int {
        DB::insert("INSERT INTO pilot_support_requests (requesting_branch_id, from_branch_id, pilot_id, notes, status, created_at) VALUES (?,?,?,?,?,?)",
            [(int) $other->id, null, null, 'حارس TTL', $status, $ago($hours)]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $old = $ins('pending', 5);
    $fresh = $ins('pending', 1);
    $acc = $ins('accepted', 30);
    $old2 = $ins('pending', 80);

    echo "\n══ 1) القايمة بتقفل القديم ══\n";
    [$c, $j] = hit($kernel, $sup, 'GET', '/api/support-requests');
    $by = [];
    foreach (($j['items'] ?? []) as $it) { $by[(int) $it['id']] = $it['status']; }
    ok('200', $c === 200, (string) $c);
    ok('🔴 طلب عمره 5 ساعات = ended', ($by[$old] ?? '') === 'ended', $by[$old] ?? '—');
    ok('  وطلب عمره ساعة لسه pending', ($by[$fresh] ?? '') === 'pending', $by[$fresh] ?? '—');
    ok('  والمقبول القديم مابيتلمسش', ($by[$acc] ?? '') === 'accepted', $by[$acc] ?? '—');

    echo "\n══ 2) عدّاد اللوحة ══\n";
    DB::update("UPDATE pilot_support_requests SET status = 'pending' WHERE id = ?", [$old2]);
    [$c, $j] = hit($kernel, $sup, 'GET', '/api/board?branch=' . (int) $sup['branch_id']);
    ok('200', $c === 200, (string) $c);
    ok('🔴 اللوحة قفلت القديم كمان', DB::selectOne('SELECT status FROM pilot_support_requests WHERE id = ?', [$old2])->status === 'ended');
    $cnt = (int) DB::selectOne("SELECT COUNT(*) c FROM pilot_support_requests WHERE status = 'pending' AND created_at < ?", [$ago(3)])->c;
    ok('  ومفيش pending أقدم من 3 ساعات خالص', $cnt === 0, (string) $cnt);
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "SUPPORT TTL: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
