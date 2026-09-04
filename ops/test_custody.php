<?php

declare(strict_types=1);

/**
 * اختبار حمايات العهدة وسجلها — كله جوه معاملة بتترجع، فمفيش أثر على القاعدة.
 *
 * بيغطي:
 *   1) السبب بيوصل لحركة الخزنة وبيتخزّن في سجل العهدة
 *   2) السبب إجباري
 *   3) أنواع التسوية التلقائية مرفوضة من الإنترنت
 *   4) تسليم/ردّ العهدة لسه شغّالين وسقوفهم القديمة قايمة
 *   5) التحصيل السالب مرفوض
 *   6) مجموع السجل = الرصيد حتى مع تسديد زيادة
 *
 * التشغيل: php ops/test_custody.php
 */

use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/* 🔴 من غير الاتنين دول: استثناء مش متمسك بعد البوتستراب بيتطبع بشكل
   جميل وبيخرج بكود 0 — الحارس يبان ناجح وهو مات في نص شغله.
   ولازم يتركّبوا بعد البوتستراب — قبله لارافل بيدوس عليهم. */
set_exception_handler(function (Throwable $e): void {
    echo "\n🔴 استثناء مش متمسك: " . get_class($e) . ' — ' . $e->getMessage()
        . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
});
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        exit(1);
    }
});


$pass = 0;
$fail = 0;
function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✓ {$what}\n";
    } else {
        $fail++;
        echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n";
    }
}

function req(Actor $a, array $body): Request
{
    $r = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    $r->attributes->set(ResolveApiActor::ATTRIBUTE, $a);

    return $r;
}

/** مجموع سجل العهدة بالإشارات — نفس حسبة ops/custody_reconcile.php */
function custodySum(int $pilotId): float
{
    return (float) DB::select(
        "SELECT COALESCE(SUM(CASE
                    WHEN type IN ('give','order_pending') THEN  amount
                    WHEN type IN ('return','order_extra') THEN -amount
                    ELSE 0 END), 0) s
           FROM custody_transactions WHERE pilot_id = ?",
        [$pilotId]
    )[0]->s;
}

function balanceOf(int $pilotId): float
{
    return (float) DB::select('SELECT custody_balance FROM pilots WHERE id = ?', [$pilotId])[0]->custody_balance;
}

$fin   = new FinanceController();
$board = new BoardController();

DB::beginTransaction();
try {
    $now = date('Y-m-d H:i:s');

    $pilotRow = DB::select('SELECT id, name, assigned_branch_id FROM pilots ORDER BY id LIMIT 1');
    if (! $pilotRow) {
        echo "مفيش طيارين — الاختبار اتخطى\n";
        DB::rollBack();
        exit(0);
    }
    $pilotId  = (int) $pilotRow[0]->id;
    $branchId = (int) ($pilotRow[0]->assigned_branch_id
        ?: DB::select('SELECT id FROM branches ORDER BY id LIMIT 1')[0]->id);

    DB::insert('INSERT INTO cash_stores (name, branch_id, balance, created_at) VALUES (?,?,?,?)',
        ['درج اختبار', $branchId, 999999, $now]);
    $storeId = (int) DB::getPdo()->lastInsertId();

    /* الطيار المختار ممكن يكون عنده حركات قديمة في القاعدة والرصيد بنصفّره
       هنا — فكل مقارنة تحت لازم تبقى على **الفرق** اللي الاختبار عمله مش
       على الرقم المطلق. من غير كده الاختبار بيفشل على بيانات حقيقية. */
    DB::update('UPDATE pilots SET custody_balance = 0 WHERE id = ?', [$pilotId]);
    $sumBase = custodySum($pilotId);

    $actor = Actor::staff(999, 'مشرف الاختبار', 'branch', $branchId, 'مشرف');

    echo "\n══ 1) السبب بيوصل لحركة الخزنة ══\n";
    $fin->custodyCreate(req($actor, [
        'pilotId' => $pilotId, 'type' => 'give', 'amount' => 1500,
        'storeId' => $storeId, 'reason' => 'عهدة سفر المنصورة — طلب المدير',
    ]));
    $cash = (array) DB::select('SELECT * FROM cash_transactions WHERE store_id = ? ORDER BY id DESC LIMIT 1', [$storeId])[0];
    ok('السبب اتخزّن في ملاحظات الخزنة', $cash['notes'] === 'عهدة سفر المنصورة — طلب المدير', var_export($cash['notes'], true));
    ok('عنوان الحركة التلقائي زي ما هو', str_contains((string) $cash['reason'], 'تسليم عهدة'), (string) $cash['reason']);
    ok('الخزنة نزلت out بـ1500', $cash['type'] === 'out' && abs((float) $cash['amount'] - 1500) < 0.005, $cash['type'] . '/' . $cash['amount']);
    ok('عهدة الطيار بقت 1500', abs(balanceOf($pilotId) - 1500) < 0.005, (string) balanceOf($pilotId));

    echo "\n══ 2) السبب إجباري ══\n";
    try {
        $fin->custodyCreate(req($actor, ['pilotId' => $pilotId, 'type' => 'give', 'amount' => 10, 'storeId' => $storeId]));
        ok('حركة من غير سبب مرفوضة', false, 'عدّت!');
    } catch (Throwable $e) {
        ok('حركة من غير سبب مرفوضة', str_contains($e->getMessage(), 'سبب حركة العهدة'), $e->getMessage());
    }

    echo "\n══ 3) الأنواع التلقائية مرفوضة من الإنترنت ══\n";
    foreach (['order_pending', 'order_extra'] as $t) {
        try {
            $fin->custodyCreate(req($actor, ['pilotId' => $pilotId, 'type' => $t, 'amount' => 100000, 'reason' => 'محاولة']));
            ok("{$t} مرفوض", false, 'عدّى!');
        } catch (Throwable $e) {
            ok("{$t} مرفوض", str_contains($e->getMessage(), 'تلقائي من تسوية'), $e->getMessage());
        }
    }
    ok('العهدة ماتغيّرتش بعد المحاولتين', abs(balanceOf($pilotId) - 1500) < 0.005, (string) balanceOf($pilotId));

    echo "\n══ 4) الردّ شغّال وسقفه قايم ══\n";
    $fin->custodyCreate(req($actor, [
        'pilotId' => $pilotId, 'type' => 'return', 'amount' => 500,
        'storeId' => $storeId, 'reason' => 'ردّ جزئي',
    ]));
    ok('الردّ نقّص العهدة لـ1000', abs(balanceOf($pilotId) - 1000) < 0.005, (string) balanceOf($pilotId));
    $cash2 = (array) DB::select('SELECT * FROM cash_transactions WHERE store_id = ? ORDER BY id DESC LIMIT 1', [$storeId])[0];
    ok('الردّ دخل الخزنة in بسببه', $cash2['type'] === 'in' && $cash2['notes'] === 'ردّ جزئي',
        $cash2['type'] . '/' . var_export($cash2['notes'], true));
    try {
        $fin->custodyCreate(req($actor, ['pilotId' => $pilotId, 'type' => 'return', 'amount' => 99999, 'storeId' => $storeId, 'reason' => 'محاولة']));
        ok('ردّ أكبر من العهدة مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('ردّ أكبر من العهدة مرفوض', str_contains($e->getMessage(), 'أكبر من عهدة'), $e->getMessage());
    }

    echo "\n══ 5) السبب متخزّن في سجل العهدة نفسه ══\n";
    $reasons = array_map(
        fn ($r) => ((array) $r)['reason'],
        DB::select('SELECT reason FROM custody_transactions WHERE pilot_id = ? ORDER BY id DESC LIMIT 5', [$pilotId])
    );
    ok('سبب التسليم في السجل', in_array('عهدة سفر المنصورة — طلب المدير', $reasons, true), json_encode($reasons, JSON_UNESCAPED_UNICODE));
    ok('سبب الردّ في السجل', in_array('ردّ جزئي', $reasons, true), json_encode($reasons, JSON_UNESCAPED_UNICODE));

    echo "\n══ 6) التحصيل السالب مرفوض ══\n";
    $settle = new ReflectionMethod(BoardController::class, 'settlePilotMoney');
    $settle->setAccessible(true);
    $pilotArr = (array) DB::select('SELECT * FROM pilots WHERE id = ?', [$pilotId])[0];
    try {
        $settle->invoke($board, $pilotArr, [], -5000.0, $storeId, $branchId, $actor, $now);
        ok('تحصيل سالب مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('تحصيل سالب مرفوض', str_contains($e->getMessage(), 'بالسالب'), $e->getMessage());
    }
    ok('العهدة ماتضخّمتش', abs(balanceOf($pilotId) - 1000) < 0.005, (string) balanceOf($pilotId));

    echo "\n══ 7) مجموع السجل = الرصيد حتى مع تسديد زيادة ══\n";
    $ad = new ReflectionMethod(BoardController::class, 'applyCustodyDelta');
    $ad->setAccessible(true);

    $pilotArr2 = (array) DB::select('SELECT * FROM pilots WHERE id = ?', [$pilotId])[0];
    $balBefore = (float) $pilotArr2['custody_balance'];      // ده اللي المفروض يتسجّل
    $ad->invoke($board, $pilotArr2, -3000.0, $branchId, 'مشرف الاختبار', $now, 'اختبار تسديد زيادة');

    $balF = balanceOf($pilotId);
    ok('الرصيد اتقص عند صفر', abs($balF) < 0.005, (string) $balF);

    $last = (array) DB::select('SELECT * FROM custody_transactions WHERE pilot_id = ? ORDER BY id DESC LIMIT 1', [$pilotId])[0];
    ok('الحركة اتسجّلت بالقيمة المقصوصة مش الدلتا الكاملة (3000)',
        abs((float) $last['amount'] - $balBefore) < 0.005,
        "اتسجّل={$last['amount']} والمفروض={$balBefore}");
    ok('نوعها order_extra', $last['type'] === 'order_extra', (string) $last['type']);
    ok('سبب التسوية اتكتب', $last['reason'] === 'اختبار تسديد زيادة', var_export($last['reason'], true));

    $delta = custodySum($pilotId) - $sumBase;
    ok('مجموع اللي الاختبار سجّله = الرصيد', abs($delta - $balF) < 0.005, "سجل={$delta} رصيد={$balF}");

    // تسديد زيادة والرصيد أصلًا صفر — المفروض مفيش صف وهمي
    $before    = (int) DB::select('SELECT COUNT(*) n FROM custody_transactions WHERE pilot_id = ?', [$pilotId])[0]->n;
    $pilotArr3 = (array) DB::select('SELECT * FROM pilots WHERE id = ?', [$pilotId])[0];
    $ad->invoke($board, $pilotArr3, -500.0, $branchId, 'مشرف الاختبار', $now, 'تسديد على رصيد صفر');
    $afterN = (int) DB::select('SELECT COUNT(*) n FROM custody_transactions WHERE pilot_id = ?', [$pilotId])[0]->n;
    ok('مفيش صف وهمي لما مفيش تغيير', $afterN === $before, "قبل={$before} بعد={$afterN}");
} catch (Throwable $e) {
    $fail++;
    echo "\n💥 " . get_class($e) . ': ' . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "CUSTODY: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
