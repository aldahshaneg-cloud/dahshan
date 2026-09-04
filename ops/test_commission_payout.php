<?php

/**
 * 💸 حارس: صرف عمولة الوردية من الخزنة — تنفيذ حقيقي.
 *
 * ═══ الطلب (صاحب النظام 2026-09-03) ═══
 * «الخزنة يجب أن يدخل لها المال بالكامل في البداية ثم يخرج من الخزنة
 * العمولة» + «الأوردر اللي لم يتم الحصول منه على تمن التوصيل يظهر في
 * الصفحة لوضع له عمولة من جيب الشركة».
 *
 * العقود المثبتة:
 * • تسوية الوردية بعمولة «في نفس اليوم» بتسجّل حركة `out` من الخزنة
 *   بقيمة عمولة الوردية (التلقائي للمتسلّم + الـoverride لأي حالة).
 * • الصرف مرة واحدة — إعادة الحفظ ماتسجّلش حركة تانية.
 * • بعد الصرف ممنوع الرجوع «على الشهر» (كانت هتتحسب مرتين).
 * • daily بعمولة > 0 من غير خزنة = خطأ واضح.
 * • override على أوردر مرتجع (عمولة من جيب الشركة) بيدخل التقفيلة
 *   الشهرية — وبعد الصرف اليومي بيختفي منها.
 *
 * ⚠️ كتابة عبر endpoints جوه معاملة بترجع — ممنوع على الإنتاج.
 *
 * التشغيل: php ops/test_commission_payout.php
 */
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$what}\n"; }
    else { $fail++; echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n"; }
}

function hit($kernel, $u, string $method, string $url, array $body = []): array
{
    $req = Illuminate\Http\Request::create($url, $method, [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], json_encode($body, JSON_UNESCAPED_UNICODE));
    $s = app('session')->driver();
    $s->flush(); $s->start();
    $s->put(['user_id' => (int) $u->id, 'username' => $u->username, 'role' => $u->role,
             'branch_id' => $u->branch_id !== null ? (int) $u->branch_id : null, 'name' => $u->username]);
    $req->setLaravelSession($s);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$sup = DB::select("SELECT id, username, role, branch_id FROM users
                    WHERE role='branch' AND blocked=0 AND branch_id IS NOT NULL LIMIT 1")[0] ?? null;
if (! $sup) { echo "مافيش مشرف فرع — تخطّي\n"; exit(0); }
$pilot = DB::select('SELECT id, name FROM pilots WHERE archived_at IS NULL AND assigned_branch_id = ? LIMIT 1', [$sup->branch_id])[0] ?? null;
if (! $pilot) { echo "مافيش طيار — تخطّي\n"; exit(0); }

DB::beginTransaction();
try {
    /* عمولة الطيار محددة جوه المعاملة عشان الأرقام تبقى قطعية */
    DB::update("UPDATE pilots SET commission_type = 'percent', commission_value = 10 WHERE id = ?", [$pilot->id]);

    $mkShift = function () use ($pilot, $sup): int {
        DB::insert("INSERT INTO shifts (pilot_id, branch_id, status, started_at, ended_at, created_at)
                    VALUES (?,?,?,NOW(),NOW(),NOW())", [$pilot->id, $sup->branch_id, 'ended']);

        return (int) DB::getPdo()->lastInsertId();
    };
    $mkOrder = function (int $shiftId, string $status, float $price) use ($sup, $pilot): int {
        DB::insert("INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, status, status_since,
                            pilot_id, pilot_name, shift_id, total_delivery_price, money_settled,
                            delivered_at, undelivered_at, undelivered_fare_by, source, added_by, added_by_role, qr_code, created_at)
                    VALUES (?,?,?,?,?,NOW(),?,?,?,?,1,?,?,?,?,?,?,?,NOW())",
            ['PAY-' . bin2hex(random_bytes(4)), $sup->branch_id, $sup->branch_id, 'راسل فحص', $status,
             $pilot->id, $pilot->name, $shiftId, $price,
             $status === 'delivered' ? date('Y-m-d H:i:s') : null,
             $status === 'undelivered' ? date('Y-m-d H:i:s') : null,
             $status === 'undelivered' ? 'none' : null,
             'branch', 'اختبار', 'branch', 'PAY-' . bin2hex(random_bytes(4))]);

        return (int) DB::getPdo()->lastInsertId();
    };

    $month = date('Y-m');
    $monthComm = function () use ($kernel, $sup, $pilot, $month): float {
        [, $j] = hit($kernel, $sup, 'GET', "/api/closeouts/monthly?pilot={$pilot->id}&month={$month}");

        return (float) ($j['computed']['totalCommission'] ?? 0);
    };
    $before = $monthComm();

    echo "══ 1) عمولة «من جيب الشركة» على مرتجع بتدخل الشهرية ══\n";
    $sh = $mkShift();
    $od = $mkOrder($sh, 'delivered', 100);     // تلقائي: 10% = 10
    $ou = $mkOrder($sh, 'undelivered', 30);    // محدش دفع — عمولة جيب الشركة
    [$ca, $ja] = hit($kernel, $sup, 'POST', '/api/pilot-commission-adjustments',
        ['pilotId' => $pilot->id, 'orderId' => $ou, 'amount' => 15, 'reason' => 'تعويض مشوار مرتجع']);
    ok('override على مرتجع اتقبل (HTTP 200)', $ca === 200, (string) $ca);
    $afterAdj = $monthComm();
    ok('🔴 الشهرية زادت 25 (10 تلقائي + 15 جيب الشركة)',
        abs(($afterAdj - $before) - 25) < 0.01, 'فرق=' . ($afterAdj - $before));

    echo "\n══ 2) 🔴 الصرف اليومي = حركة خروج من الخزنة ══\n";
    DB::insert('INSERT INTO cash_stores (name, branch_id, balance, created_at) VALUES (?,?,?,NOW())',
        ['خزنة فحص الصرف', $sup->branch_id, 500]);
    $store = (int) DB::getPdo()->lastInsertId();

    [$c1, $j1] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh}/settlement",
        ['commissionSettle' => 'daily', 'cashStoreId' => $store]);
    ok('HTTP 200', $c1 === 200, (string) $c1);
    ok('🔴 commissionPaid = 25', abs((float) ($j1['commissionPaid'] ?? 0) - 25) < 0.01,
        (string) ($j1['commissionPaid'] ?? '؟'));
    $bal = (float) DB::selectOne('SELECT balance FROM cash_stores WHERE id = ?', [$store])->balance;
    ok('رصيد الخزنة نقص بالعمولة (500→475)', abs($bal - 475) < 0.01, (string) $bal);
    $txns = DB::select("SELECT type, amount, reason FROM cash_transactions WHERE store_id = ?", [$store]);
    ok('حركة out واحدة بسبب «عمولة وردية»',
        count($txns) === 1 && $txns[0]->type === 'out'
        && abs((float) $txns[0]->amount - 25) < 0.01
        && str_contains($txns[0]->reason, 'عمولة وردية'),
        json_encode($txns, JSON_UNESCAPED_UNICODE));
    $row = DB::selectOne('SELECT commission_paid_amount, commission_paid_at FROM shifts WHERE id = ?', [$sh]);
    ok('الوردية اتعلّمت بالصرف', $row->commission_paid_at !== null && abs((float) $row->commission_paid_amount - 25) < 0.01);

    echo "\n══ 3) مافيش صرف مرتين ══\n";
    [$c2, $j2] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh}/settlement",
        ['commissionSettle' => 'daily', 'cashStoreId' => $store]);
    ok('إعادة الحفظ HTTP 200', $c2 === 200, (string) $c2);
    ok('🔴 commissionPaid = 0 (مش بيصرف تاني)', abs((float) ($j2['commissionPaid'] ?? -1)) < 0.01,
        (string) ($j2['commissionPaid'] ?? '؟'));
    $bal2 = (float) DB::selectOne('SELECT balance FROM cash_stores WHERE id = ?', [$store])->balance;
    ok('الرصيد ثابت 475', abs($bal2 - 475) < 0.01, (string) $bal2);
    ok('لسه حركة واحدة بس',
        (int) DB::selectOne('SELECT COUNT(*) c FROM cash_transactions WHERE store_id = ?', [$store])->c === 1);

    echo "\n══ 4) بعد الصرف: ممنوع الرجوع شهري + الشهرية ماتحسبهاش ══\n";
    [$c3] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh}/settlement", ['commissionSettle' => 'monthly']);
    ok('🔴 الرجوع «على الشهر» مرفوض', $c3 >= 400, (string) $c3);
    $afterPay = $monthComm();
    ok('🔴 الشهرية رجعت زي الأول (اليومي المصروف مش بيتكرر)',
        abs($afterPay - $before) < 0.01, 'فرق=' . ($afterPay - $before));

    echo "\n══ 5) daily بعمولة من غير خزنة = خطأ واضح ══\n";
    $sh2 = $mkShift();
    $mkOrder($sh2, 'delivered', 60);
    [$c4, $j4] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh2}/settlement", ['commissionSettle' => 'daily']);
    ok('مرفوض بطلب اختيار الخزنة', $c4 >= 400
        && str_contains((string) json_encode($j4, JSON_UNESCAPED_UNICODE), 'الخزنة'), (string) $c4);
    ok('ومافيش علامة صرف اتكتبت',
        DB::selectOne('SELECT commission_paid_at FROM shifts WHERE id = ?', [$sh2])->commission_paid_at === null);

    echo "\n══ 6) وردية من غير عمولة: daily بيتحفظ عادي من غير خزنة ══\n";
    $sh3 = $mkShift();
    [$c5, $j5] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh3}/settlement", ['commissionSettle' => 'daily']);
    ok('HTTP 200 وcommissionPaid=0', $c5 === 200 && abs((float) ($j5['commissionPaid'] ?? -1)) < 0.01,
        $c5 . ' / ' . ($j5['commissionPaid'] ?? '؟'));

    echo "\n══ 7) 🔴 كتابة عمولة من الصفحة = حركة خزنة فورية ══\n";
    $m0 = $monthComm();
    $sh4 = $mkShift();
    $mkOrder($sh4, 'delivered', 100);          // plain 10
    $ou4 = $mkOrder($sh4, 'undelivered', 40);  // مرتجع — عمولة من جيب الشركة
    DB::insert('INSERT INTO cash_stores (name, branch_id, balance, created_at) VALUES (?,?,?,NOW())',
        ['خزنة فحص الصرف الفوري', $sup->branch_id, 300]);
    $store2 = (int) DB::getPdo()->lastInsertId();
    $bal2 = fn (): float => (float) DB::selectOne('SELECT balance FROM cash_stores WHERE id = ?', [$store2])->balance;

    [$c7, $j7] = hit($kernel, $sup, 'POST', '/api/pilot-commission-adjustments',
        ['pilotId' => $pilot->id, 'orderId' => $ou4, 'amount' => 15, 'reason' => 'جيب الشركة', 'cashStoreId' => $store2]);
    $adjId = (int) ($j7['adjustment']['id'] ?? 0);
    ok('🔴 paidDelta=15 وحركة خروج «عمولة أوردر» ورصيد 285',
        $c7 === 200 && abs((float) ($j7['paidDelta'] ?? 0) - 15) < 0.01 && abs($bal2() - 285) < 0.01,
        $c7 . ' / ' . ($j7['paidDelta'] ?? '؟') . ' / ' . $bal2());
    $t7 = DB::selectOne("SELECT type, reason FROM cash_transactions WHERE store_id = ? ORDER BY id DESC LIMIT 1", [$store2]);
    ok('سبب الحركة بيبدأ بـ«عمولة أوردر»', $t7->type === 'out' && str_starts_with($t7->reason, 'عمولة أوردر'), $t7->reason);
    ok('🔴 الشهرية زادت 10 بس (المدفوع مش بيتجمع تاني)',
        abs(($monthComm() - $m0) - 10) < 0.01, 'فرق=' . ($monthComm() - $m0));

    echo "\n══ 8) التعديل بالفرق: زيادة → خروج، نقصان → استرجاع ══\n";
    [, $j8] = hit($kernel, $sup, 'POST', '/api/pilot-commission-adjustments',
        ['pilotId' => $pilot->id, 'orderId' => $ou4, 'amount' => 20, 'reason' => 'زيادة', 'cashStoreId' => $store2]);
    ok('15→20: paidDelta=5 ورصيد 280',
        abs((float) ($j8['paidDelta'] ?? 0) - 5) < 0.01 && abs($bal2() - 280) < 0.01,
        ($j8['paidDelta'] ?? '؟') . ' / ' . $bal2());
    [, $j9] = hit($kernel, $sup, 'POST', '/api/pilot-commission-adjustments',
        ['pilotId' => $pilot->id, 'orderId' => $ou4, 'amount' => 12, 'reason' => 'نقصان', 'cashStoreId' => $store2]);
    $tin = DB::selectOne("SELECT type, reason FROM cash_transactions WHERE store_id = ? ORDER BY id DESC LIMIT 1", [$store2]);
    ok('20→12: paidDelta=-8 ورصيد 288 وحركة «استرجاع فرق»',
        abs((float) ($j9['paidDelta'] ?? 0) + 8) < 0.01 && abs($bal2() - 288) < 0.01
        && $tin->type === 'in' && str_starts_with($tin->reason, 'استرجاع فرق'),
        ($j9['paidDelta'] ?? '؟') . ' / ' . $bal2() . ' / ' . $tin->reason);
    [$c10] = hit($kernel, $sup, 'POST', '/api/pilot-commission-adjustments',
        ['pilotId' => $pilot->id, 'orderId' => $ou4, 'amount' => 9, 'reason' => 'من غير خزنة']);
    ok('تعديل مبلغ مصروف من غير خزنة = مرفوض', $c10 >= 400, (string) $c10);

    echo "\n══ 9) صرف التقفيلة بيدفع الباقي بس + المسح بيرجّع للخزنة ══\n";
    [, $js4] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh4}/settlement",
        ['commissionSettle' => 'daily', 'cashStoreId' => $store2]);
    ok('🔴 commissionPaid=10 (التلقائي بس — الـoverride المدفوع مش بيتكرر)',
        abs((float) ($js4['commissionPaid'] ?? 0) - 10) < 0.01 && abs($bal2() - 278) < 0.01,
        ($js4['commissionPaid'] ?? '؟') . ' / ' . $bal2());
    ok('الشهرية رجعت للأساس (الوردية بقت daily)', abs($monthComm() - $m0) < 0.01, 'فرق=' . ($monthComm() - $m0));
    [$cd, $jd] = hit($kernel, $sup, 'DELETE', "/api/pilot-commission-adjustments/{$adjId}");
    ok('🔴 المسح رجّع 12 للخزنة (refunded)',
        $cd === 200 && abs((float) ($jd['refunded'] ?? 0) - 12) < 0.01 && abs($bal2() - 290) < 0.01,
        $cd . ' / ' . ($jd['refunded'] ?? '؟') . ' / ' . $bal2());

    echo "\n══ 10) العمولة المستقلة: صرف فوري ومسح باسترجاع ══\n";
    [, $je] = hit($kernel, $sup, 'POST', '/api/pilot-commission-adjustments',
        ['pilotId' => $pilot->id, 'amount' => 7, 'reason' => 'تعويض شكوى', 'cashStoreId' => $store2]);
    $exId = (int) ($je['adjustment']['id'] ?? 0);
    ok('paidDelta=7 ورصيد 283 والشهرية ثابتة (المدفوعة مش مستحقة)',
        abs((float) ($je['paidDelta'] ?? 0) - 7) < 0.01 && abs($bal2() - 283) < 0.01
        && abs($monthComm() - $m0) < 0.01,
        ($je['paidDelta'] ?? '؟') . ' / ' . $bal2());
    [, $jf] = hit($kernel, $sup, 'DELETE', "/api/pilot-commission-adjustments/{$exId}");
    ok('مسحها رجّع 7 (رصيد 290)',
        abs((float) ($jf['refunded'] ?? 0) - 7) < 0.01 && abs($bal2() - 290) < 0.01,
        ($jf['refunded'] ?? '؟') . ' / ' . $bal2());

    echo "\n══ 11) مصفوفة حسابات الطيارين: المصروف كاش «اتصفّى» مش مستحق ══\n";
    $matrix = function () use ($kernel, $sup, $pilot, $month): array {
        [, $j] = hit($kernel, $sup, 'GET', "/api/pilot-accounting/month?month={$month}");
        foreach (($j['pilots'] ?? []) as $p) {
            if ((int) $p['pilotId'] === (int) $pilot->id) {
                return $p['totals'];
            }
        }

        return [];
    };
    $t0 = $matrix();
    $sh5 = $mkShift();
    $mkOrder($sh5, 'delivered', 50);           // تلقائي 5 — بيترحّل شهري
    $or5 = $mkOrder($sh5, 'undelivered', 40);  // مرتجع بعمولة جيب شركة مدفوعة
    hit($kernel, $sup, 'POST', '/api/pilot-commission-adjustments',
        ['pilotId' => $pilot->id, 'orderId' => $or5, 'amount' => 20, 'reason' => 'جيب الشركة — مدفوعة', 'cashStoreId' => $store2]);
    $t1 = $matrix();
    $dPsvc  = (float) ($t1['psvc'] ?? 0) - (float) ($t0['psvc'] ?? 0);
    $dDaily = (float) ($t1['settledDaily']['psvc'] ?? 0) - (float) ($t0['settledDaily']['psvc'] ?? 0);
    ok('🔴 العرض زاد 25 (5 تلقائي + 20 جيب الشركة)', abs($dPsvc - 25) < 0.01, 'فرق=' . $dPsvc);
    ok('🔴 «اتصفّت كاش» زادت بالمدفوعة بس (20)', abs($dDaily - 20) < 0.01, 'فرق=' . $dDaily);
} finally {
    DB::rollBack();
}

echo "\n────────────────────────────────────\n";
if ($fail > 0) { echo "🔴 {$fail} فحص وقع (نجح {$pass})\n"; exit(1); }
echo "✅ كل الفحوص عدّت ({$pass})\n";
