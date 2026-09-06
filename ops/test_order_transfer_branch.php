<?php
/**
 * 🔄 حارس: نقل الأوردر بين الطيارين بينقل الأوردر لفرع الطيار الجديد (2026-09-06).
 *
 * ═══ اللي حصل على الإنتاج ═══
 * أوردر اتعمل بالغلط على «المدير» واتنقل لطيار «حي شرق» واتسلّم — وفضل مسجّل
 * على المدير في كل الشاشات (`orders.branch_id` مكانش بيتغيّر مع النقل).
 *
 * ═══ العقود المثبتة ═══
 * • النقل لطيار فرع تاني: `orders.branch_id` = فرع وردية الطيار الجديد، و`origin_branch_id`
 *   زي ما هو، وسجل النقل فيه from/to branch، والرد بيقول movedBranch.
 * • النقل لطيار في نفس الفرع: الفرع زي ما هو وmovedBranch=false.
 * • السلك (transferHistory) بيطلّع أسماء الفرعين.
 * • القواعد القديمة زي ما هي: statusSince مابيتلمسش، shift_id بينتقل، transfer_count +1.
 * كله جوه معاملة بتترجع.
 *
 * التشغيل: php ops/test_order_transfer_branch.php
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
$branches = array_map(fn ($r) => (int) $r->id, DB::select('SELECT id FROM branches ORDER BY id LIMIT 2'));
if (count($branches) < 2) { echo "محتاج فرعين — تخطّي\n"; exit(0); }
[$bA, $bB] = $branches;
$pilots = array_map(fn ($r) => (int) $r->id, DB::select('SELECT id FROM pilots WHERE archived_at IS NULL ORDER BY id LIMIT 3'));
if (count($pilots) < 3) { echo "محتاج ٣ طيارين — تخطّي\n"; exit(0); }
[$pA, $pB, $pA2] = $pilots;
$now = gmdate('Y-m-d H:i:s');

DB::beginTransaction();
try {
    /* الطيارين: A وA2 في الفرع A، وB في الفرع B — كلهم بورديات مفتوحة */
    DB::update("UPDATE orders SET pilot_id = NULL, shift_id = NULL, status = 'processing' WHERE pilot_id IN (?,?,?) AND status = 'delivering'", [$pA, $pB, $pA2]);
    DB::update("UPDATE shifts SET status = 'ended', ended_at = COALESCE(ended_at, ?) WHERE pilot_id IN (?,?,?) AND status = 'active'", [$now, $pA, $pB, $pA2]);
    $setup = function (int $pid, int $branch) use ($now): int {
        DB::update("UPDATE pilots SET assigned_branch_id = ?, home_branch_id = ?, status = 'waiting', queue_no = NULL WHERE id = ?", [$branch, $branch, $pid]);
        DB::insert('INSERT INTO shifts (pilot_id, branch_id, status, started_at, created_at) VALUES (?,?,?,?,NOW())', [$pid, $branch, 'active', $now]);
        return (int) DB::getPdo()->lastInsertId();
    };
    $shA = $setup($pA, $bA);
    $shB = $setup($pB, $bB);
    $shA2 = $setup($pA2, $bA);
    $mkOrder = function (string $num, int $branch, int $pilot, int $shift) use ($now): int {
        DB::insert("INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, sender_phone, status, status_since, created_at,
                                        total_delivery_price, pilot_id, pilot_name, shift_id, current_pilot_since)
                    VALUES (?,?,?,'محل الاختبار','01000000000','delivering',?,?,20,?,'اختبار',?,?)",
            [$num, $branch, $branch, $now, $now, $pilot, $shift, $now]);
        return (int) DB::getPdo()->lastInsertId();
    };
    $o1 = $mkOrder('TST-XFER-1', $bA, $pA, $shA);
    $o2 = $mkOrder('TST-XFER-2', $bA, $pA, $shA);

    echo "══ 1) نقل لطيار فرع تاني ══\n";
    [$c, $j] = hit($kernel, $admin, 'POST', "/api/orders/{$o1}/transfer", ['toPilotId' => $pB]);
    ok('النقل 200', $c === 200, (string) $c . ' ' . mb_substr(json_encode($j, JSON_UNESCAPED_UNICODE), 0, 200));
    $o = (array) DB::selectOne('SELECT * FROM orders WHERE id = ?', [$o1]);
    ok('🔴 الأوردر بقى في فرع الطيار الجديد', (int) $o['branch_id'] === $bB, (string) $o['branch_id']);
    ok('والفرع الأصلي (تاريخ الإنشاء) زي ما هو', (int) $o['origin_branch_id'] === $bA);
    ok('الطيار والوردية اتنقلوا', (int) $o['pilot_id'] === $pB && (int) $o['shift_id'] === $shB);
    ok('statusSince ماتلمسش وtransfer_count زاد', $o['status_since'] === $now && (int) $o['transfer_count'] === 1, $o['status_since'] . ' / ' . $o['transfer_count']);
    $t = DB::selectOne('SELECT * FROM order_transfers WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$o1]);
    ok('🔴 سجل النقل فيه الفرعين', $t && (int) $t->from_branch_id === $bA && (int) $t->to_branch_id === $bB, json_encode($t));
    ok('الرد بيقول movedBranch والفرعين', ($j['movedBranch'] ?? null) === true && (int) ($j['fromBranchId'] ?? 0) === $bA && (int) ($j['toBranchId'] ?? 0) === $bB);
    $h = $j['order']['transferHistory'] ?? $j['transferHistory'] ?? [];
    $last = $h ? $h[count($h) - 1] : null;
    ok('السلك بيطلّع أسماء الفرعين في سجل النقل', $last && ! empty($last['fromBranchName']) && ! empty($last['toBranchName']) && $last['fromBranchName'] !== $last['toBranchName'], json_encode($last, JSON_UNESCAPED_UNICODE));

    echo "\n══ 2) نقل في نفس الفرع ══\n";
    [$c, $j] = hit($kernel, $admin, 'POST', "/api/orders/{$o2}/transfer", ['toPilotId' => $pA2]);
    $o = (array) DB::selectOne('SELECT * FROM orders WHERE id = ?', [$o2]);
    ok('النقل 200 والفرع زي ما هو وmovedBranch=false', $c === 200 && (int) $o['branch_id'] === $bA && ($j['movedBranch'] ?? null) === false, (string) $c . ' ' . ($o['branch_id'] ?? '؟'));
    $t2 = DB::selectOne('SELECT from_branch_id, to_branch_id FROM order_transfers WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$o2]);
    ok('وسجل النقل الفرعين نفس الفرع', $t2 && (int) $t2->from_branch_id === $bA && (int) $t2->to_branch_id === $bA);

    echo "\n══ 3) الطيار الجديد من غير وردية → فرعه الحالي ══\n";
    DB::update("UPDATE shifts SET status = 'ended', ended_at = ? WHERE id = ?", [$now, $shA2]);
    DB::update("UPDATE pilots SET status = 'waiting', assigned_branch_id = ? WHERE id = ?", [$bB, $pA2]);
    $o3 = $mkOrder('TST-XFER-3', $bA, $pA, $shA);
    [$c, $j] = hit($kernel, $admin, 'POST', "/api/orders/{$o3}/transfer", ['toPilotId' => $pA2]);
    $o = (array) DB::selectOne('SELECT branch_id, shift_id FROM orders WHERE id = ?', [$o3]);
    ok('من غير وردية: الفرع = فرع الطيار الحالي والتحذير موجود', $c === 200 && (int) $o['branch_id'] === $bB && $o['shift_id'] === null && ! empty($j['warning']), (string) $c . ' ' . json_encode($o));
} finally {
    DB::rollBack();
}

echo "\n══ 4) الواجهة ══\n";
foreach (['tiar', 'callcenter'] as $pg) {
    $html = file_get_contents($ROOT . "/public/{$pg}.html");
    ok("{$pg}: الخط الزمني بيقول الأوردر اتنقل من فرع لفرع", str_contains($html, 'والأوردر اتنقل من فرع «'));
}
foreach (['tiar', 'branch'] as $pg) {
    $html = file_get_contents($ROOT . "/public/{$pg}.html");
    ok("{$pg}: تنبيه لما الأوردر يخرج من لوحة الفرع", str_contains($html, 'if (res.movedBranch) showToast('));
}

echo "\n════════════════════════════════════════\n";
echo "ORDER TRANSFER BRANCH: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
