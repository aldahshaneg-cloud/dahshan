<?php
/**
 * 💵 حارس: سلفة الوردية المسجّلة من تقرير التقفيلة بتخرج من الخزنة (بلاغ 2026-09-16).
 *
 * «سيف 55 سجّلتهم سلفة عليه في التقفيلة ومنزلوش من الخزنة — المفروض يحصل تعديل في
 *  الخزنة عشان يتخصم منهم المبلغ». المشرف بيسجّل السلفة **بعد** الإنهاء من
 *  POST /api/shifts/{id}/settlement، وshiftEnd بس هو اللي كان بيصرفها.
 *
 * ═══ العقود (تنفيذ حقيقي جوه معاملة بتترجع) ═══
 * • settlement بسلفة 55 على وردية منتهية عمولتها اتصرفت من خزنة X → حركة out 55 «سلفة وردية»
 *   من X نفسها (من غير ما المشرف يختار خزنة)، وadvance_txn_id اتحفظ، والرد advancePaid = 55.
 * • نفس الحفظ تاني بنفس المبلغ → مفيش حركة تانية.
 * • تغيير المبلغ وهو مصروف → 409.
 * • تصفيره → حركة in 55 «إلغاء سلفة وردية» وadvance_txn_id فاضي.
 * • وردية فرع له خزنة واحدة ومن غير عمولة مصروفة → السلفة من خزنة الفرع الوحيدة.
 * التشغيل: php ops/test_shift_advance_settlement.php
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

$sup = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'branch' AND blocked = 0 AND branch_id IS NOT NULL ORDER BY id LIMIT 1")[0] ?? null);
if (! $sup) { echo "مافيش مشرف فرع — تخطّي\n"; exit(0); }
$b1 = (int) $sup['branch_id'];
$pilotRow = DB::selectOne('SELECT id, name FROM pilots WHERE archived_at IS NULL AND (assigned_branch_id = ? OR home_branch_id = ?) ORDER BY id LIMIT 1', [$b1, $b1])
    ?? DB::selectOne('SELECT id, name FROM pilots WHERE archived_at IS NULL ORDER BY id LIMIT 1');
if (! $pilotRow) { echo "مافيش طيارين — تخطّي\n"; exit(0); }
$pid = (int) $pilotRow->id;

DB::beginTransaction();
try {
    DB::update('UPDATE pilots SET assigned_branch_id = ?, home_branch_id = ? WHERE id = ?', [$b1, $b1, $pid]);
    // خزنتين للفرع عشان نثبت إن الاختيار بيروح لخزنة العمولة مش «الوحيدة»
    DB::insert('INSERT INTO cash_stores (name, branch_id, balance, created_at) VALUES (?,?,?,NOW())', ['خزنة عمولة الفحص', $b1, 1000]);
    $storeA = (int) DB::getPdo()->lastInsertId();
    DB::insert('INSERT INTO cash_stores (name, branch_id, balance, created_at) VALUES (?,?,?,NOW())', ['خزنة تانية للفحص', $b1, 1000]);
    $storeB = (int) DB::getPdo()->lastInsertId();
    $bal = fn (int $id) => round((float) DB::selectOne('SELECT balance FROM cash_stores WHERE id = ?', [$id])->balance, 2);
    $txns = fn (int $id, string $like) => (int) DB::selectOne("SELECT COUNT(*) c FROM cash_transactions WHERE store_id = ? AND reason LIKE ?", [$id, $like])->c;

    /* وردية منتهية عمولتها اتصرفت من storeA (زي حالة سيف: العمولة خرجت وقت الإنهاء) */
    $paidAt = gmdate('Y-m-d H:i:s', time() - 3600);
    DB::insert("INSERT INTO shifts (pilot_id, branch_id, status, started_at, ended_at, ended_by, commission_settle, commission_paid_amount, commission_paid_at, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,NOW())",
        [$pid, $b1, 'ended', gmdate('Y-m-d H:i:s', time() - 8 * 3600), $paidAt, $sup['username'], 'daily', 96, $paidAt]);
    $sh = (int) DB::getPdo()->lastInsertId();
    DB::insert('INSERT INTO cash_transactions (store_id, type, amount, reason, related_pilot_id, branch_id, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)',
        [$storeA, 'out', 96, 'عمولة وردية الطيار: ' . $pilotRow->name, $pid, $b1, $sup['username'], $paidAt]);
    DB::update('UPDATE cash_stores SET balance = balance - 96 WHERE id = ?', [$storeA]);

    echo "══ 1) سلفة 55 من تقرير التقفيلة → تخرج من خزنة العمولة ══\n";
    [$c, $j] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh}/settlement", ['advanceAmount' => 55, 'advanceReason' => 'سلفه', 'advanceSettle' => 'monthly', 'commissionSettle' => 'daily']);
    ok('الحفظ 200 وadvancePaid = 55', $c === 200 && $near($j['advancePaid'] ?? -1, 55), $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    ok('🔴 خزنة العمولة نقصت 55 (904 → 849)', $near($bal($storeA), 849), (string) $bal($storeA));
    ok('  بحركة out «سلفة وردية: الطيار»', $txns($storeA, 'سلفة وردية: %') === 1);
    $row = DB::selectOne('SELECT advance_amount, advance_txn_id FROM shifts WHERE id = ?', [$sh]);
    ok('  والوردية شايلة المبلغ ومعرّف الحركة', $near($row->advance_amount, 55) && (int) $row->advance_txn_id > 0);
    ok('  والخزنة التانية ماتلمستش', $near($bal($storeB), 1000));

    echo "\n══ 2) حفظ تاني بنفس المبلغ = مفيش حركة تانية ══\n";
    [$c, $j] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh}/settlement", ['advanceAmount' => 55, 'advanceReason' => 'سلفه', 'advanceSettle' => 'monthly', 'commissionSettle' => 'daily']);
    ok('200 وadvancePaid = 0 والرصيد ثابت', $c === 200 && $near($j['advancePaid'] ?? -1, 0) && $near($bal($storeA), 849) && $txns($storeA, 'سلفة وردية: %') === 1, $c . ' ' . $bal($storeA));

    echo "\n══ 3) تغيير المبلغ وهو مصروف = 409 ══\n";
    [$c, $j] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh}/settlement", ['advanceAmount' => 80, 'advanceSettle' => 'monthly', 'commissionSettle' => 'daily']);
    ok('409 برسالة واضحة', $c === 409 && str_contains((string) ($j['error'] ?? ''), 'خرجت من الخزنة'), $c . ' ' . ($j['error'] ?? ''));
    ok('  والمبلغ والرصيد زي ما هما', $near(DB::selectOne('SELECT advance_amount FROM shifts WHERE id = ?', [$sh])->advance_amount, 55) && $near($bal($storeA), 849));

    echo "\n══ 4) التصفير = الفلوس ترجع للخزنة ══\n";
    [$c, $j] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh}/settlement", ['advanceAmount' => 0, 'advanceSettle' => 'monthly', 'commissionSettle' => 'daily']);
    $row = DB::selectOne('SELECT advance_amount, advance_txn_id FROM shifts WHERE id = ?', [$sh]);
    ok('200 والرصيد رجع 904 بحركة in «إلغاء سلفة وردية»', $c === 200 && $near($bal($storeA), 904) && $txns($storeA, 'إلغاء سلفة وردية: %') === 1, $c . ' ' . $bal($storeA));
    ok('  والوردية صفر ومن غير معرّف حركة', $near($row->advance_amount, 0) && $row->advance_txn_id === null);

    echo "\n══ 5) وردية بلا عمولة مصروفة في فرع له خزنة واحدة → خزنة الفرع ══\n";
    DB::delete('DELETE FROM cash_stores WHERE id = ?', [$storeB]);
    DB::insert("INSERT INTO shifts (pilot_id, branch_id, status, started_at, ended_at, ended_by, commission_settle, created_at) VALUES (?,?,?,?,?,?,?,NOW())",
        [$pid, $b1, 'ended', gmdate('Y-m-d H:i:s', time() - 7200), gmdate('Y-m-d H:i:s', time() - 60), $sup['username'], 'monthly']);
    $sh2 = (int) DB::getPdo()->lastInsertId();
    $others = (int) DB::selectOne('SELECT COUNT(*) c FROM cash_stores WHERE branch_id = ?', [$b1])->c;
    if ($others === 1) {
        [$c, $j] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh2}/settlement", ['advanceAmount' => 30, 'advanceSettle' => 'monthly', 'commissionSettle' => 'monthly']);
        ok('السلفة خرجت من خزنة الفرع الوحيدة', $c === 200 && $near($j['advancePaid'] ?? -1, 30) && $near($bal($storeA), 874), $c . ' ' . $bal($storeA));
    } else {
        // الفرع له خزن تانية في القاعدة المحلية — لازم اختيار صريح
        [$c, $j] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh2}/settlement", ['advanceAmount' => 30, 'advanceSettle' => 'monthly', 'commissionSettle' => 'monthly']);
        ok('أكتر من خزنة ومفيش اختيار = 400 «اختر الخزنة»', $c === 400 && str_contains((string) ($j['error'] ?? ''), 'اختر الخزنة'), $c . ' ' . ($j['error'] ?? ''));
        [$c, $j] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh2}/settlement", ['advanceAmount' => 30, 'advanceSettle' => 'monthly', 'commissionSettle' => 'monthly', 'cashStoreId' => $storeA]);
        ok('وباختيار الخزنة = 200', $c === 200 && $near($j['advancePaid'] ?? -1, 30) && $near($bal($storeA), 874), $c . ' ' . $bal($storeA));
    }
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "SHIFT ADVANCE SETTLEMENT: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
