<?php
/**
 * 💵 حارس: سلف ومرتبات الطيارين من خزنة الفرع + سلفة الوردية + صفحة المالية (2026-09-13).
 *
 * ═══ الطلب (صاحب النظام) ═══
 * «السلف الخاصة بالطيارين لازم تتخصم من الخزنة … في صفحة الحسابات حاجة مخصوصة
 *  لحسابات الطيار وتتخصم من الخزنة … لما الفرع يسلّم المرتب للطيار يتخصم من الخزنة
 *  … والعمليات دي تبان في تقفيل الطيارين … وفي تطبيق الطيار صفحة المالية».
 *
 * ═══ العقود المثبتة (تنفيذ حقيقي جوه معاملة بتترجع) ═══
 * • مشرف الفرع يسجّل سلفة من خزنة فرعه: رصيدها ينقص، حركة out «سلفة من الخزنة»،
 *   وصف في السلف المؤجلة بقسط صفر على شهر النهارده — فالتقفيلة تخصمها كلها.
 * • خزنة فرع تاني أو طيار فرع تاني → 403؛ مبلغ صفر → 400.
 * • القايمة (pilot-cash) بتوري العملية بـcanDelete لمن سجّلها.
 * • دفعة مرتب: مقيّدة بالصافي الحي للشهر (ناقص المدفوع) — وبتتسجّل صرفة + حركة out.
 * • الإلغاء بيرجّع الفلوس للخزنة بحركة in.
 * • إنهاء الوردية بسلفة > 0 بيصرفها من الخزنة مرة واحدة (advance_txn_id).
 * • GET /api/pilot/finance بيرجّع نفس أرقام التقفيلة للطيار ده بس، والسلفة من الخزنة
 *   ظاهرة في deferredDue وadvances وops.
 *
 * التشغيل: php ops/test_pilot_cash.php
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

use App\Http\Controllers\Api\PilotAccountingController;
use App\Http\Controllers\Api\PilotAppController;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use App\Support\BizDay;
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
$b2 = (int) (DB::selectOne('SELECT id FROM branches WHERE id <> ? ORDER BY id LIMIT 1', [$b1])->id ?? 0);
/* طيار مربوط بحساب تطبيق (users.pilot_id) عشان نجرّب /api/pilot/finance بنفس الطيار */
$appUser = DB::selectOne('SELECT u.id, u.username, u.pilot_id FROM users u JOIN pilots p ON p.id = u.pilot_id WHERE p.archived_at IS NULL ORDER BY u.id LIMIT 1');
$pid = $appUser ? (int) $appUser->pilot_id : (int) (DB::selectOne('SELECT id FROM pilots WHERE archived_at IS NULL ORDER BY id LIMIT 1')->id ?? 0);
if ($pid <= 0) { echo "مافيش طيارين — تخطّي\n"; exit(0); }
$other = (int) (DB::selectOne('SELECT id FROM pilots WHERE archived_at IS NULL AND id <> ? ORDER BY id LIMIT 1', [$pid])->id ?? 0);
$ym = substr(BizDay::key(), 0, 7);
$today = BizDay::key();

DB::beginTransaction();
try {
    DB::delete('DELETE FROM pilot_month_locks WHERE month = ?', [$ym]);   // الشهر الحالي لازم يبقى مفتوح للفحص
    DB::update("UPDATE pilots SET home_branch_id = ?, assigned_branch_id = ?, status = 'waiting', custody_balance = 0 WHERE id = ?", [$b1, $b1, $pid]);
    if ($other > 0 && $b2 > 0) {
        DB::update('UPDATE pilots SET home_branch_id = ?, assigned_branch_id = ? WHERE id = ?', [$b2, $b2, $other]);
    }
    DB::delete('DELETE FROM pilot_deferred_advances WHERE pilot_id = ?', [$pid]);
    DB::delete("DELETE FROM pilot_acct_payouts WHERE kind = 'pilot' AND ref_id = ? AND month = ?", [$pid, $ym]);
    DB::insert('INSERT INTO cash_stores (name, branch_id, balance, created_at) VALUES (?,?,?,NOW())', ['خزنة فحص السلف', $b1, 5000]);
    $store = (int) DB::getPdo()->lastInsertId();
    DB::insert('INSERT INTO cash_stores (name, branch_id, balance, created_at) VALUES (?,?,?,NOW())', ['خزنة فرع تاني', $b2 ?: null, 5000]);
    $storeOther = (int) DB::getPdo()->lastInsertId();
    $bal = fn (int $id) => round((float) DB::selectOne('SELECT balance FROM cash_stores WHERE id = ?', [$id])->balance, 2);
    $pilotName = (string) DB::selectOne('SELECT name FROM pilots WHERE id = ?', [$pid])->name;

    echo "══ 1) مشرف الفرع يسجّل سلفة 300 من خزنة فرعه ══\n";
    [$c, $j] = hit($kernel, $sup, 'POST', '/api/pilot-accounting/pilot-cash', ['pilotId' => $pid, 'kind' => 'advance', 'amount' => 300, 'cashStoreId' => $store, 'note' => 'فحص']);
    ok('التسجيل 200', $c === 200 && ($j['kind'] ?? '') === 'advance', (string) $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    $advId = (int) ($j['id'] ?? 0);
    ok('🔴 رصيد الخزنة نقص 300', $near($bal($store), 4700), (string) $bal($store));
    $t = DB::selectOne('SELECT id, type, amount, reason, related_pilot_id, branch_id, created_by FROM cash_transactions WHERE store_id = ? ORDER BY id DESC LIMIT 1', [$store]);
    ok('حركة out «سلفة من الخزنة: <الطيار>» مربوطة بالطيار والفرع ومين سجّلها',
        $t && $t->type === 'out' && $near($t->amount, 300) && str_starts_with((string) $t->reason, 'سلفة من الخزنة: ' . $pilotName)
        && (int) $t->related_pilot_id === $pid && (int) $t->branch_id === $b1 && $t->created_by === $sup['username'],
        json_encode($t, JSON_UNESCAPED_UNICODE));
    $rec = DB::selectOne('SELECT * FROM pilot_deferred_advances WHERE id = ?', [$advId]);
    ok('🔴 صف سلفة بقسط صفر على شهر النهارده ومربوط بالحركة',
        $rec && $near($rec->monthly, 0) && $rec->start_month === $ym && $rec->advance_date === $today
        && (int) $rec->store_id === $store && (int) $rec->txn_id === (int) $t->id, json_encode($rec, JSON_UNESCAPED_UNICODE));

    echo "\n══ 2) القايمة والنطاق ══\n";
    [$c, $lst] = hit($kernel, $sup, 'GET', "/api/pilot-accounting/pilot-cash?from={$today}&to={$today}");
    $item = null;
    foreach ($lst['items'] ?? [] as $x) { if ($x['kind'] === 'advance' && (int) $x['id'] === $advId) { $item = $x; } }
    ok('القايمة فيها السلفة باسم الطيار والخزنة وcanDelete لمن سجّلها', $c === 200 && $item
        && $item['pilotName'] === $pilotName && $item['storeName'] === 'خزنة فحص السلف' && $item['canDelete'] === true
        && $near($item['amount'], 300) && $item['month'] === $ym, json_encode($item, JSON_UNESCAPED_UNICODE));
    [$c] = hit($kernel, $sup, 'POST', '/api/pilot-accounting/pilot-cash', ['pilotId' => $pid, 'kind' => 'advance', 'amount' => 50, 'cashStoreId' => $storeOther]);
    ok('🔴 خزنة فرع تاني = 403', $c === 403, (string) $c);
    if ($other > 0 && $b2 > 0) {
        [$c] = hit($kernel, $sup, 'POST', '/api/pilot-accounting/pilot-cash', ['pilotId' => $other, 'kind' => 'advance', 'amount' => 50, 'cashStoreId' => $store]);
        ok('🔴 طيار فرع تاني = 403', $c === 403, (string) $c);
    }
    [$c] = hit($kernel, $sup, 'POST', '/api/pilot-accounting/pilot-cash', ['pilotId' => $pid, 'kind' => 'advance', 'amount' => 0, 'cashStoreId' => $store]);
    ok('مبلغ صفر = 400', $c === 400, (string) $c);
    [$c] = hit($kernel, $sup, 'POST', '/api/pilot-accounting/pilot-cash', ['pilotId' => $pid, 'kind' => 'advance', 'amount' => 99999, 'cashStoreId' => $store]);
    ok('أكبر من رصيد الخزنة = 400', $c === 400, (string) $c);
    ok('والخزنة زي ما هي بعد المرفوضات', $near($bal($store), 4700), (string) $bal($store));

    echo "\n══ 3) التقفيلة وصفحة المالية شايفين السلفة ══\n";
    $acct = app(PilotAccountingController::class);
    $row = $acct->monthRowFor($ym, $pid);
    ok('صف الطيار في تقفيلة الشهر الحي', is_array($row) && isset($row['totals']['netDue']));
    ok('🔴 السلفة كلها مستحقة في الشهر ده (deferredDue = 300)', $row && $near($row['totals']['deferredDue'] ?? -1, 300), (string) ($row['totals']['deferredDue'] ?? '؟'));
    $netDue = (float) ($row['totals']['netDue'] ?? 0);
    if ($appUser) {
        $req = Request::create('/api/pilot/finance', 'GET', ['month' => $ym]);
        $req->attributes->set(ResolveApiActor::ATTRIBUTE, new Actor(
            userId: (int) $appUser->id, customerId: null, username: (string) $appUser->username, role: 'pilot', branchId: null, name: $pilotName
        ));
        $fin = json_decode((new PilotAppController())->finance($req)->getContent(), true);
        ok('finance بيرجّع الشهر والأيام والإجماليات للطيار ده', ($fin['ok'] ?? false) === true && $fin['month'] === $ym
            && (int) ($fin['pilot']['id'] ?? 0) === $pid && count($fin['days'] ?? []) === (int) date('t', strtotime($ym . '-01'))
            && isset($fin['totals']['netDue']), json_encode(array_keys($fin ?? []), JSON_UNESCAPED_UNICODE));
        ok('🔴 نفس أرقام التقفيلة بالحرف (netDue/deferredDue)', $fin && $near($fin['totals']['netDue'], $netDue) && $near($fin['totals']['deferredDue'], 300));
        $adv = array_values(array_filter($fin['advances'] ?? [], fn ($a) => (int) $a['id'] === $advId));
        ok('السلفة في قايمة السلف بخزنتها ومستحقّها', count($adv) === 1 && $adv[0]['storeName'] === 'خزنة فحص السلف' && $near($adv[0]['monthDue'], 300));
        ok('وفي البنود (ops) كسلفة من الخزنة', count(array_filter($fin['ops'] ?? [], fn ($o) => $o['type'] === 'advance' && $o['source'] === 'store' && $near($o['amount'], 300))) === 1);
        ok('والشهور المتاحة فيها الشهر الحالي', in_array($ym, $fin['months'] ?? [], true));
    } else {
        echo "  ⊘ مافيش حساب تطبيق مربوط بطيار — فحص finance اتخطّى\n";
    }

    echo "\n══ 4) دفعة مرتب من خزنة الفرع ══\n";
    $paid0 = (float) DB::selectOne("SELECT COALESCE(SUM(amount),0) s FROM pilot_acct_payouts WHERE kind = 'pilot' AND ref_id = ? AND month = ?", [$pid, $ym])->s;
    $remaining = round($netDue - $paid0, 2);
    if ($remaining > 1) {
        $b0 = $bal($store);
        [$c, $j] = hit($kernel, $sup, 'POST', '/api/pilot-accounting/pilot-cash', ['pilotId' => $pid, 'kind' => 'salary', 'amount' => 1, 'cashStoreId' => $store, 'month' => $ym]);
        ok('دفعة 1 ج تحت حساب الشهر = 200 والباقي اتحسب', $c === 200 && $near($j['remaining'] ?? -1, $remaining - 1), (string) $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
        $payId = (int) ($j['id'] ?? 0);
        ok('🔴 الخزنة نقصت 1 وحركة out «صرف راتب»', $near($bal($store), $b0 - 1)
            && (bool) DB::selectOne("SELECT 1 FROM cash_transactions WHERE store_id = ? AND type = 'out' AND amount = 1 AND reason LIKE 'صرف راتب %' LIMIT 1", [$store]));
        $po = DB::selectOne('SELECT * FROM pilot_acct_payouts WHERE id = ?', [$payId]);
        ok('صف صرفة بالشهر والخزنة ومين صرف', $po && $po->month === $ym && (int) $po->store_id === $store && $po->paid_by === $sup['username']);
        [$c] = hit($kernel, $sup, 'POST', '/api/pilot-accounting/pilot-cash', ['pilotId' => $pid, 'kind' => 'salary', 'amount' => $remaining + 100, 'cashStoreId' => $store, 'month' => $ym]);
        ok('🔴 أكبر من الباقي من الصافي الحي = 400', $c === 400, (string) $c);
        [$c] = hit($kernel, $sup, 'DELETE', "/api/pilot-accounting/pilot-cash/salary/{$payId}");
        ok('إلغاء الصرفة 200 والفلوس رجعت', $c === 200 && $near($bal($store), $b0), (string) $c . ' ' . $bal($store));
    } else {
        [$c] = hit($kernel, $sup, 'POST', '/api/pilot-accounting/pilot-cash', ['pilotId' => $pid, 'kind' => 'salary', 'amount' => 1, 'cashStoreId' => $store, 'month' => $ym]);
        ok('مفيش صافي للطيار الشهر ده → الصرف مرفوض 400 (الحد = الصافي الحي)', $c === 400, (string) $c . ' netDue=' . $netDue);
    }

    echo "\n══ 5) إلغاء السلفة ══\n";
    [$c] = hit($kernel, $admin, 'DELETE', "/api/pilot-accounting/pilot-cash/advance/999999999");
    ok('سلفة مش موجودة = 404', $c === 404, (string) $c);
    [$c] = hit($kernel, $sup, 'DELETE', "/api/pilot-accounting/pilot-cash/advance/{$advId}");
    ok('🔴 الإلغاء 200 والفلوس رجعت للخزنة بحركة in', $c === 200 && $near($bal($store), 5000)
        && (bool) DB::selectOne("SELECT 1 FROM cash_transactions WHERE store_id = ? AND type = 'in' AND amount = 300 AND reason LIKE 'إلغاء سلفة%' LIMIT 1", [$store]),
        (string) $c . ' ' . $bal($store));
    ok('والصف اتشال', ! DB::selectOne('SELECT id FROM pilot_deferred_advances WHERE id = ?', [$advId]));

    echo "\n══ 6) سلفة الوردية بتخرج من الخزنة عند الإنهاء ══\n";
    DB::update("UPDATE orders SET money_settled = 1 WHERE pilot_id = ? AND status = 'delivered' AND money_settled = 0", [$pid]);
    DB::update("UPDATE orders SET pilot_id = NULL, shift_id = NULL WHERE pilot_id = ? AND status = 'delivering'", [$pid]);
    DB::update("UPDATE shifts SET status = 'ended', ended_at = COALESCE(ended_at, started_at) WHERE pilot_id = ? AND status = 'active'", [$pid]);
    DB::insert('INSERT INTO shifts (pilot_id, branch_id, status, started_at, created_at) VALUES (?,?,?,?,NOW())', [$pid, $b1, 'active', gmdate('Y-m-d H:i:s', time() - 3 * 3600)]);
    $sh = (int) DB::getPdo()->lastInsertId();
    DB::update("UPDATE pilots SET status = 'waiting', assigned_branch_id = ? WHERE id = ?", [$b1, $pid]);
    $b0 = $bal($store);
    [$c, $j] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh}/end", [
        'orders' => [], 'collectedAmount' => 0, 'cashStoreId' => $store,
        'advanceAmount' => 150, 'advanceReason' => 'سلفة فحص', 'advanceSettle' => 'monthly',
        'commissionSettle' => 'monthly',
    ]);
    ok('الإنهاء 200', $c === 200, (string) $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    ok('🔴 الخزنة نقصت 150 بحركة «سلفة وردية»', $near($bal($store), $b0 - 150)
        && (bool) DB::selectOne("SELECT 1 FROM cash_transactions WHERE store_id = ? AND type = 'out' AND amount = 150 AND reason LIKE 'سلفة وردية: %' AND related_pilot_id = ? LIMIT 1", [$store, $pid]),
        (string) ($bal($store) - $b0));
    $srow = DB::selectOne('SELECT advance_amount, advance_txn_id, status FROM shifts WHERE id = ?', [$sh]);
    ok('الوردية اتقفلت ومتعلّمة بحركة السلفة (advance_txn_id)', $srow && $srow->status === 'ended' && $near($srow->advance_amount, 150) && (int) $srow->advance_txn_id > 0);
    [$c, $lst] = hit($kernel, $sup, 'GET', "/api/pilot-accounting/pilot-cash?from={$today}&to={$today}");
    ok('والقايمة بتوريها كـshiftAdvance (مش قابلة للإلغاء من هنا)', count(array_filter($lst['items'] ?? [], fn ($x) => $x['kind'] === 'shiftAdvance' && (int) $x['id'] === $sh && $near($x['amount'], 150) && $x['canDelete'] === false)) === 1);
    $row2 = $acct->monthRowFor($ym, $pid);
    ok('والتقفيلة بتخصمها من الشهر (advanceDue ≥ 150)', $row2 && (float) ($row2['totals']['advanceDue'] ?? 0) >= 150 - 0.01, (string) ($row2['totals']['advanceDue'] ?? '؟'));

    echo "\n══ 7) إنهاء بسلفة من غير أي خزنة = رفض واضح ══\n";
    DB::insert('INSERT INTO shifts (pilot_id, branch_id, status, started_at, created_at) VALUES (?,?,?,?,NOW())', [$pid, $b1, 'active', gmdate('Y-m-d H:i:s', time() - 3600)]);
    $sh2 = (int) DB::getPdo()->lastInsertId();
    DB::update("UPDATE pilots SET status = 'waiting', assigned_branch_id = ? WHERE id = ?", [$b1, $pid]);
    [$c, $j] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh2}/end", ['orders' => [], 'collectedAmount' => 0, 'advanceAmount' => 20, 'commissionSettle' => 'monthly']);
    ok('400 برسالة «اختر الخزنة»', $c === 400 && str_contains((string) ($j['error'] ?? ''), 'اختر الخزنة'), (string) $c . ' ' . ($j['error'] ?? ''));
    ok('والوردية لسه مفتوحة (المعاملة اترجعت)', DB::selectOne('SELECT status FROM shifts WHERE id = ?', [$sh2])->status === 'active');
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "PILOT CASH: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
