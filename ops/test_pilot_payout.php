<?php
/**
 * 💸 حارس: صرف الرواتب من الخزنة — تنفيذ حقيقي.
 *
 * ═══ الطلب (صاحب النظام 2026-09-04) ═══
 * «ابدأ في صرف الرواتب من الخزنة» — بعد ما المراجعة طلّعت إن البرنامج بيحسب
 * «صافي المدفوع» من غير ما يسجّل إن حد قبض، والخزنة ماكانتش بتعرف.
 *
 * ═══ العقود المثبتة ═══
 * • الصرف على شهر مفتوح مرفوض 409 — الأرقام المعتمدة هي اللقطة.
 * • الصرف بينزّل رصيد الخزنة وبيسجّل حركة out بسبب فيه اسم الطيار والشهر.
 * • الصرف الجزئي بيتجمّع، والزيادة عن الباقي مرفوضة، والخزنة الفاضية مرفوضة.
 * • رد month فيه payouts للطيار — حي حتى لو الشهر من اللقطة.
 * • الإلغاء بيرجّع الفلوس بحركة in ويشيل الصرفة.
 * • مشرف فرع بلا صلاحية act.payout مرفوض 403.
 * كله جوه معاملة بتترجع.
 *
 * التشغيل: php ops/test_pilot_payout.php
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
$near = fn ($a, $b) => abs((float) $a - (float) $b) < 0.011;

$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
if (! $admin) { echo "مافيش أدمن — تخطّي\n"; exit(0); }
$ymRow = DB::select("SELECT DATE_FORMAT(started_at, '%Y-%m') ym FROM shifts WHERE ended_at IS NOT NULL ORDER BY started_at DESC LIMIT 1")[0] ?? null;
$ym = $ymRow ? (string) $ymRow->ym : date('Y-m');

DB::beginTransaction();
try {
    DB::delete('DELETE FROM pilot_month_locks WHERE month = ?', [$ym]);
    DB::delete('DELETE FROM pilot_acct_snapshots WHERE month = ?', [$ym]);
    DB::delete('DELETE FROM pilot_acct_payouts WHERE month = ?', [$ym]);
    $branchId = (int) (DB::select('SELECT id FROM branches ORDER BY id LIMIT 1')[0]->id ?? 0);
    DB::insert('INSERT INTO cash_stores (name, branch_id, balance, created_at) VALUES (?,?,?,NOW())', ['خزنة فحص الصرف', $branchId ?: null, 100000]);
    $store = (int) DB::getPdo()->lastInsertId();
    DB::insert('INSERT INTO cash_stores (name, branch_id, balance, created_at) VALUES (?,?,?,NOW())', ['خزنة فاضية', $branchId ?: null, 5]);
    $empty = (int) DB::getPdo()->lastInsertId();
    $bal = fn () => (float) DB::selectOne('SELECT balance FROM cash_stores WHERE id = ?', [$store])->balance;

    [, $live] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}");
    $target = null;
    foreach ($live['pilots'] ?? [] as $p) { if ((float) ($p['totals']['netDue'] ?? 0) > 50) { $target = $p; break; } }
    ok('فيه طيار صافيه أكبر من 50', $target !== null);
    if (! $target) { throw new RuntimeException('مافيش طيار مناسب'); }
    $pid = (int) $target['pilotId'];
    $net = round((float) $target['totals']['netDue'], 2);

    echo "\n══ 1) مافيش صرف على شهر مفتوح ══\n";
    [$c, $j] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/payout', ['month' => $ym, 'kind' => 'pilot', 'refId' => $pid, 'amount' => 10, 'cashStoreId' => $store]);
    ok('🔴 مرفوض 409 والرسالة بتقول اقفل الشهر', $c === 409 && str_contains((string) json_encode($j, JSON_UNESCAPED_UNICODE), 'اقفل الشهر'), (string) $c);

    echo "\n══ 2) بعد القفل: صرف جزئي ثم الباقي ══\n";
    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/lock', ['month' => $ym]);
    ok('القفل 200', $c === 200, (string) $c);
    $b0 = $bal();
    [$c, $j] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/payout', ['month' => $ym, 'kind' => 'pilot', 'refId' => $pid, 'amount' => 50, 'cashStoreId' => $store, 'note' => 'دفعة أولى']);
    ok('صرف 50 → 200', $c === 200, (string) $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    ok('🔴 رصيد الخزنة نقص 50', $near($bal(), $b0 - 50), (string) $bal());
    $t = DB::selectOne("SELECT type, amount, reason, related_pilot_id FROM cash_transactions WHERE store_id = ? ORDER BY id DESC LIMIT 1", [$store]);
    ok('حركة out بسبب فيه «صرف راتب» والشهر والطيار', $t && $t->type === 'out' && $near($t->amount, 50) && str_contains($t->reason, 'صرف راتب') && str_contains($t->reason, $ym) && (int) $t->related_pilot_id === $pid, $t ? $t->reason : 'null');
    ok('الرد فيه الباقي = الصافي − 50', $near($j['remaining'] ?? -1, $net - 50), (string) ($j['remaining'] ?? '؟'));
    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/payout', ['month' => $ym, 'kind' => 'pilot', 'refId' => $pid, 'amount' => $net, 'cashStoreId' => $store]);
    ok('🔴 أكتر من الباقي مرفوض', $c === 400, (string) $c);
    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/payout', ['month' => $ym, 'kind' => 'pilot', 'refId' => $pid, 'amount' => 20, 'cashStoreId' => $empty]);
    ok('🔴 خزنة رصيدها مايكفيش مرفوضة', $c === 400, (string) $c);
    [$c, $j] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/payout', ['month' => $ym, 'kind' => 'pilot', 'refId' => $pid, 'amount' => round($net - 50, 2), 'cashStoreId' => $store]);
    ok('صرف الباقي 200 والباقي صفر', $c === 200 && $near($j['remaining'] ?? -1, 0), (string) $c);
    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/payout', ['month' => $ym, 'kind' => 'pilot', 'refId' => $pid, 'amount' => 1, 'cashStoreId' => $store]);
    ok('وبعد الاكتمال أي صرف تاني مرفوض', $c === 400, (string) $c);

    echo "\n══ 3) الصرفات في رد الشهر (من اللقطة) ══\n";
    [$c, $m] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}");
    $list = $m['payouts'][(string) $pid] ?? [];
    $paid = array_sum(array_map(fn ($x) => (float) $x['amount'], $list));
    ok('🔴 الشهر من اللقطة ومعاه صرفتين مجموعهم الصافي', ! empty($m['snapshot']) && count($list) === 2 && $near($paid, $net), count($list) . ' / ' . $paid);
    ok('كل صرفة فيها الخزنة ومين صرف', isset($list[0]['storeName'], $list[0]['paidBy']) && $list[0]['paidBy'] === $admin['username']);

    echo "\n══ 4) الإلغاء بيرجّع الفلوس ══\n";
    $b1 = $bal();
    [$c] = hit($kernel, $admin, 'DELETE', '/api/pilot-accounting/payout/' . $list[0]['id']);
    ok('الإلغاء 200', $c === 200, (string) $c);
    ok('🔴 الرصيد رجع بمبلغ الصرفة', $near($bal(), $b1 + (float) $list[0]['amount']), (string) $bal());
    $t2 = DB::selectOne("SELECT type, reason FROM cash_transactions WHERE store_id = ? ORDER BY id DESC LIMIT 1", [$store]);
    ok('بحركة in مكتوب عليها إلغاء', $t2 && $t2->type === 'in' && str_contains($t2->reason, 'إلغاء صرف'), $t2 ? $t2->reason : 'null');
    [, $m2] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}");
    ok('فضلت صرفة واحدة', count($m2['payouts'][(string) $pid] ?? []) === 1);

    echo "\n══ 5) الصلاحيات ══\n";
    $un = 'pay_' . substr(bin2hex(random_bytes(3)), 0, 6);
    DB::insert('INSERT INTO users (username, password_hash, role, name, branch_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())', [$un, password_hash('x', PASSWORD_BCRYPT), 'branch', 'فحص', $branchId ?: null]);
    $sup = ['id' => (int) DB::getPdo()->lastInsertId(), 'username' => $un, 'role' => 'branch', 'branch_id' => $branchId ?: null];
    [$c] = hit($kernel, $sup, 'POST', '/api/pilot-accounting/payout', ['month' => $ym, 'kind' => 'pilot', 'refId' => $pid, 'amount' => 5, 'cashStoreId' => $store]);
    ok('🔴 مشرف فرع من غير act.payout (الافتراضي) → 403', $c === 403, (string) $c);
    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/payout', ['month' => $ym, 'kind' => 'staff', 'refId' => 999999, 'amount' => 5, 'cashStoreId' => $store]);
    ok('موظف مش في التقفيلة → 404', $c === 404, (string) $c);
} finally {
    DB::rollBack();
}

echo "\n══ 6) الواجهة ══\n";
$html = file_get_contents($ROOT . '/public/accounts.html');
ok('زرار الصرف في تقفيلة الشهر للطيارين والموظفين', substr_count($html, 'payoutCell(d, "') >= 2);
ok('الحفظ على مسار payout والإلغاء على DELETE', str_contains($html, '"/api/pilot-accounting/payout"') && str_contains($html, '"/api/pilot-accounting/payout/" + id'));
ok('المودال بيقول إن الصرف حركة منصرف في الخزنة', str_contains($html, 'هتتسجّل حركة <b>منصرف</b>'));
$wire = file_get_contents($ROOT . '/app/Wire/PilotAccountingWire.php');
ok('act.payout معرّف ومحجوز للإدارة افتراضيًا', str_contains($wire, "['act.payout',") && preg_match("/ADMIN_ONLY_KEYS = \[[^\]]*'act\.payout'/", $wire));

echo "\n════════════════════════════════════════\n";
echo "PAYOUT: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
