<?php

declare(strict_types=1);

/**
 * 💰 اختبار «العميل مايتحاسبش مرتين» — رصيد المحفظة والتحصيل الكاش.
 *
 * ═══ اللسعة ═══
 * العميل يقدر يدفع جزء من سعر التوصيل من رصيد محفظته، والرصيد بيتخصم
 * **فورًا** وقت إنشاء الأوردر. لكن:
 *   • تطبيق الطيار كان بيعرض `totalDeliveryPrice` الخام كـ«قيمة التحصيل»
 *   • وحساب التسوية في الفرع كان بيطلب نفس الرقم الخام من الطيار
 * يعني العميل بيدفع المخصوم مرتين: مرة من محفظته ومرة كاش.
 *
 * حصلت فعلًا على الإنتاج مرة واحدة (HAL-260824-001، 30 جنيه).
 *
 * كله جوه معاملة بتترجع فمفيش أثر.
 *
 * التشغيل: php ops/test_wallet_collect.php
 */

use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\OrdersController;
use App\Support\Actor;
use App\Support\Money;
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

DB::beginTransaction();
try {
    echo "\n══ 1) الحساب نفسه ══\n";
    ok('70 والمحفظة 50 → 20', abs(Money::netCollect(['total_delivery_price' => 70, 'wallet_used' => 50]) - 20.0) < 0.005);
    ok('30 والمحفظة 30 → 0', abs(Money::netCollect(['total_delivery_price' => 30, 'wallet_used' => 30]) - 0.0) < 0.005);
    ok('30 من غير محفظة → 30', abs(Money::netCollect(['total_delivery_price' => 30, 'wallet_used' => 0]) - 30.0) < 0.005);
    /* بيانات مرحّلة ممكن يكون فيها مخصوم أكبر من السعر — الحارس بيمنع
       «متوقّع بالسالب» اللي كان هيخصم من عهدة الطيار بدل ما يزوّدها. */
    ok('المخصوم أكبر من السعر → صفر مش سالب',
        abs(Money::netCollect(['total_delivery_price' => 20, 'wallet_used' => 50]) - 0.0) < 0.005);
    ok('أعمدة ناقصة → صفر', abs(Money::netCollect([]) - 0.0) < 0.005);
    ok('نفس الحساب من كائن السلك',
        abs(Money::netCollectWire(['totalDeliveryPrice' => 70, 'walletUsed' => 50]) - 20.0) < 0.005);

    echo "\n══ 2) الطيار بيشوف اللي هيحصّله — والفرع بيشوف الخام ══\n";
    $view = new ReflectionMethod(OrdersController::class, 'pilotCollectView');
    $view->setAccessible(true);

    $orders = [
        ['totalDeliveryPrice' => 70.0, 'walletUsed' => 50.0],
        ['totalDeliveryPrice' => 30.0, 'walletUsed' => 0.0],
        ['totalDeliveryPrice' => 30.0, 'walletUsed' => 30.0],
    ];
    $asPilot  = $view->invoke(null, Actor::staff(1, 'p1', 'pilot', 1, 'طيار'), $orders);
    $asBranch = $view->invoke(null, Actor::staff(2, 'b1', 'branch', 1, 'مشرف'), $orders);
    $asAdmin  = $view->invoke(null, Actor::staff(3, 'a1', 'admin', null, 'مدير'), $orders);

    $vals = fn (array $l) => array_map(fn ($x) => (float) $x['totalDeliveryPrice'], $l);
    ok('الطيار: 20 · 30 · 0', $vals($asPilot) === [20.0, 30.0, 0.0], json_encode($vals($asPilot)));
    ok('الفرع: الخام زي ما هو', $vals($asBranch) === [70.0, 30.0, 30.0], json_encode($vals($asBranch)));
    ok('الإدارة: الخام زي ما هو', $vals($asAdmin) === [70.0, 30.0, 30.0], json_encode($vals($asAdmin)));
    /* التطبيق محتاج يعرف **ليه** الرقم أقل — من غير الحقل ده الطيار
       بيفتكر إن النظام غلط في السعر. */
    ok('walletUsed لسه على سلك الطيار',
        array_map(fn ($x) => (float) $x['walletUsed'], $asPilot) === [50.0, 0.0, 30.0],
        json_encode(array_map(fn ($x) => $x['walletUsed'], $asPilot)));

    echo "\n══ 2.1) 🔴 كل مسار بيوصل تطبيق الطيار — مش واحد بس ══\n";
    /* اللسعة اللي اتكشفت في المراجعة: الإصلاح اتحط في `OrdersController`
       بس، وهو اللي التطبيق بينده عليه لشاشة **الحساب**. لكن الشاشة
       الرئيسية (كارت الأوردر «قيمة التحصيل») وإجمالي الوردية بيجوا من
       `PilotAppController` — ودي فضلت بترجّع الخام.

       الاختبار ده بيقفل الباب: بيعدّ **كل** مكان بيخرج منه أوردر لتطبيق
       الطيار، ويتأكد إن كل واحد فيهم بيعدّي على التصفية. */
    $pacSrc = file_get_contents((new ReflectionClass(App\Http\Controllers\Api\PilotAppController::class))->getFileName());
    $ocSrc  = file_get_contents((new ReflectionClass(OrdersController::class))->getFileName());

    ok('PilotAppController: مفيش OrderWire::batch عريان',
        substr_count($pacSrc, 'OrderWire::batch') === substr_count($pacSrc, 'netCollectView(OrderWire::batch'),
        'batch=' . substr_count($pacSrc, 'OrderWire::batch') .
        ' متصفّى=' . substr_count($pacSrc, 'netCollectView(OrderWire::batch'));
    ok('OrdersController: القايمة والتفاصيل بيعدّوا على pilotCollectView',
        substr_count($ocSrc, 'pilotCollectView(') >= 3,
        'عدد النداءات: ' . substr_count($ocSrc, 'pilotCollectView('));

    $pacView = new ReflectionMethod(App\Http\Controllers\Api\PilotAppController::class, 'netCollectView');
    $pacView->setAccessible(true);
    $out = $pacView->invoke(null, [
        ['totalDeliveryPrice' => 70.0, 'walletUsed' => 50.0],
        ['totalDeliveryPrice' => 30.0, 'walletUsed' => 0.0],
    ]);
    ok('مسارات التطبيق بترجّع المحصَّل',
        array_map(fn ($x) => (float) $x['totalDeliveryPrice'], $out) === [20.0, 30.0],
        json_encode(array_map(fn ($x) => $x['totalDeliveryPrice'], $out)));

    /* إجمالي الوردية والعمولة **رقمين مختلفين**: التحصيل صافي، والعمولة
       على الأجرة الخام (المحل مدين بالأجرة كاملة مهما دفع العميل إزاي). */
    ok('إجمالي التحصيل صافي', str_contains($pacSrc, "'totalCollected'   => round(\$collectedTotal, 2)"));
    ok('العمولة على الخام', str_contains($pacSrc, '$feeTotal * $commissionValue / 100.0'));
    ok('التحصيل بيتجمّع بـMoney::netCollect', str_contains($pacSrc, '$collectedTotal += Money::netCollect((array) $r)'));

    echo "\n══ 3) التسوية: الفرع بيطلب المحصَّل مش الخام ══\n";
    $branchId = (int) DB::selectOne('SELECT id FROM branches ORDER BY id LIMIT 1')->id;
    $pilotId  = (int) DB::selectOne('SELECT id FROM pilots ORDER BY id LIMIT 1')->id;
    $now      = date('Y-m-d H:i:s');

    // نبدأ من صفحة نضيفة: مفيش أوردرات سابقة على الطيار ده
    DB::update("UPDATE orders SET pilot_id = NULL WHERE pilot_id = ? AND status IN ('delivering','delivered')", [$pilotId]);

    $mk = function (float $total, float $wallet) use ($branchId, $pilotId, $now): int {
        DB::insert(
            "INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, sender_phone,
                                 status, status_since, created_at, total_delivery_price, wallet_used,
                                 added_by, added_by_role, source, pilot_id)
             VALUES (?,?,?,'محل الاختبار','01000000000','delivering',?,?,?,?,'shop1','store','store',?)",
            ['WC-' . uniqid(), $branchId, $branchId, $now, $now, $total, $wallet, $pilotId]
        );

        return (int) DB::getPdo()->lastInsertId();
    };

    $mk(70, 50);   // المتوقّع 20
    $mk(30, 0);    // المتوقّع 30
    $mk(30, 30);   // المتوقّع 0

    $rows = array_map(fn ($r) => (array) $r, DB::select(
        "SELECT id, total_delivery_price, wallet_used FROM orders
          WHERE pilot_id = ? AND status = 'delivering'",
        [$pilotId]
    ));
    ok('التلات أوردرات موجودة', count($rows) === 3, (string) count($rows));

    $expected = 0.0;
    $raw      = 0.0;
    foreach ($rows as $o) {
        $expected += Money::netCollect($o);
        $raw      += (float) $o['total_delivery_price'];
    }
    ok('المتوقّع من الطيار = 50', abs($expected - 50.0) < 0.005, (string) $expected);
    ok('الخام كان هيبقى 130 — الفرق 80 هو اللي كان بيتحصّل مرتين',
        abs($raw - 130.0) < 0.005 && abs($raw - $expected - 80.0) < 0.005, "خام={$raw} متوقّع={$expected}");

    /* الحارس اللي بيمنع الرجوع: لو حد رجّع الحساب للخام، الاختبار ده
       بيقع لأن `settlePilotMoney` نفسها بتقرا نفس الأعمدة. */
    $src = file_get_contents((new ReflectionClass(BoardController::class))->getFileName());
    ok('التسوية بتستخدم Money::netCollect مش الخام',
        substr_count($src, 'Money::netCollect($o)') >= 2,
        'عدد النداءات: ' . substr_count($src, 'Money::netCollect($o)'));
    ok('استعلامات التسوية بتجيب wallet_used',
        substr_count($src, 'total_delivery_price, wallet_used FROM orders') >= 2,
        'عدد الاستعلامات: ' . substr_count($src, 'total_delivery_price, wallet_used FROM orders'));
} catch (Throwable $e) {
    $fail++;
    echo "\n💥 " . get_class($e) . ': ' . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "WALLET COLLECT: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
