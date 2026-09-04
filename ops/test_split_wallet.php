<?php

declare(strict_types=1);

/**
 * ✂️💰 اختبار «تفريق الطرود مايأكلش رصيد المحفظة».
 *
 * ═══ اللسعة ═══
 * العميل بيدفع جزء من سعر التوصيل من رصيد محفظته، والرصيد بينزل **فورًا**
 * وقت إنشاء الأوردر. الرقم المخصوم بيتسجّل في `wallet_used`، والطيار
 * بيحصّل `max(0, السعر − المخصوم)` — لكل أوردر لوحده.
 *
 * التفريق كان بيقسّم `total_delivery_price` على الأوردرين وبيسيب
 * `wallet_used` كله على الأصل:
 *
 *   أوردر 70 والعميل دفع 50 من محفظته ⟵ المفروض 20 كاش
 *   اتقسم 40 + 30:
 *     • الجزء المفصول:  max(0, 40 − 0)  = 40
 *     • الأصل:          max(0, 30 − 50) = 0   ⟵ الـ20 الزيادة اتبخّرت هنا
 *     ⟵ المجموع 40 بدل 20. العميل دفع 20 زيادة من فلوسه هو.
 *
 * القاعدة اللي الملف ده بيحرسها في جملة واحدة:
 *   **مجموع اللي بيتحصّل كاش بعد التفريق = اللي كان هيتحصّل قبله.**
 *
 * كله جوه معاملة بتترجع فمفيش أثر. للتجربة العملية اللي بتفضل في القاعدة
 * شوف `ops/demo_split_wallet.php`.
 *
 * التشغيل: php ops/test_split_wallet.php
 */

use App\Http\Controllers\Api\OrdersController;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use App\Support\Money;
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

function req(Actor $a, array $body = []): Request
{
    $r = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    $r->attributes->set(ResolveApiActor::ATTRIBUTE, $a);

    return $r;
}

$ctl = new OrdersController();

DB::beginTransaction();
try {
    echo "\n══ 1) الحساب نفسه — Money::splitWallet ══\n";
    [$a, $b] = Money::splitWallet(50, 40, 30);
    ok('70 والمحفظة 50 → اتقسم 40/30 ⟵ 28.57 + 21.43',
        abs($a - 28.57) < 0.005 && abs($b - 21.43) < 0.005, "{$a} / {$b}");
    ok('المجموع محفوظ بالظبط', abs(($a + $b) - 50.0) < 0.0001, (string) ($a + $b));

    [$a, $b] = Money::splitWallet(0, 40, 30);
    ok('مفيش محفظة → صفر وصفر', $a === 0.0 && $b === 0.0, "{$a} / {$b}");

    [$a, $b] = Money::splitWallet(60, 30, 30);
    ok('نص بنص → 30 و30', abs($a - 30.0) < 0.005 && abs($b - 30.0) < 0.005, "{$a} / {$b}");

    /* بيانات مرحّلة: سعر صفر ومخصوم عليه محفظة. من غير الحارس ده
       القسمة على صفر بترمي `DivisionByZeroError` جوه المعاملة. */
    [$a, $b] = Money::splitWallet(25, 0, 0);
    ok('سعر صفر ومحفظة → الخصم كله على الأصل', $a === 0.0 && abs($b - 25.0) < 0.005, "{$a} / {$b}");

    /* التقريب: الطرح بدل الضربة التانية هو اللي بيمنع فرق القرش.
       المسح ده بيغطّي 2000 حالة — 340 منهم بينحرفوا فعلًا لو الحساب
       اترجّع لضربتين (جرّبتها: `round(50*1/2)+round(50*1/2)` على قرش
       فردي بيطلع قرش زيادة من العدم). */
    $drift = [];
    foreach ([[1, 1], [1, 2], [1, 3], [2, 3], [1, 9]] as [$pa, $pb]) {
        for ($c = 1; $c <= 400; $c++) {
            $w = $c / 100;
            [$x, $y] = Money::splitWallet($w, (float) $pa, (float) $pb);
            if (abs(($x + $y) - $w) > 0.0001) {
                $drift[] = "w={$w} {$pa}/{$pb}";
            }
        }
    }
    ok('2000 قسمة — مفيش قرش اتخلق ولا ضاع',
        $drift === [], count($drift) . ' انحراف، أولهم: ' . ($drift[0] ?? '—'));


    echo "\n══ 2) التفريق الحقيقي — من غير ما نعيد كتابة الحساب ══\n";
    $now      = date('Y-m-d H:i:s');
    $branchId = (int) DB::selectOne('SELECT id FROM branches ORDER BY id LIMIT 1')->id;
    /* الطيار المؤرشف مايتحملش عليه (OrdersController بيرفضه بـ409) — ومن
       لقطة الإنتاج 2026-09-04 تاني طيار بالترتيب كان مؤرشف فالفحص وقع
       من غير باج. بنستبعده هنا عشان الحارس يختبر التفريق مش الأرشفة. */
    $pilotA   = (array) DB::selectOne('SELECT id, name, status FROM pilots WHERE archived_at IS NULL ORDER BY id LIMIT 1');
    $pilotB   = (array) DB::selectOne('SELECT id, name FROM pilots WHERE archived_at IS NULL AND id <> ? ORDER BY id LIMIT 1', [$pilotA['id']]);
    if (! $pilotB) {
        throw new RuntimeException('محتاج طيارين على الأقل');
    }
    DB::update("UPDATE pilots SET status = 'waiting' WHERE id IN (?,?)", [$pilotA['id'], $pilotB['id']]);

    /** بيعمل أوردر بطرود وسعر محفظة، وبيرجّع الـid */
    $mk = function (array $prices, float $wallet) use ($branchId, $now): int {
        DB::insert(
            "INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, sender_phone,
                                 status, status_since, created_at, total_delivery_price, wallet_used,
                                 pieces_count, added_by, added_by_role, source)
             VALUES (?,?,?,'محل الاختبار','01000000000','processing',?,?,?,?,?,'t1','admin','admin')",
            ['SW-' . uniqid(), $branchId, $branchId, $now, $now, array_sum($prices), $wallet, count($prices)]
        );
        $id = (int) DB::getPdo()->lastInsertId();
        foreach ($prices as $i => $p) {
            DB::insert(
                'INSERT INTO order_deliveries (order_id, parcel_no, receiver_name, receiver_phone,
                                               zone_price, order_price, created_at)
                 VALUES (?,?,?,?,?,0,?)',
                [$id, $i + 1, 'مستلم ' . ($i + 1), '0100000000' . $i, $p, $now]
            );
        }

        return $id;
    };

    $admin = Actor::staff(1, 'admin', 'admin', null, 'مدير');

    /* الحالة الأساسية: 70 = 40 + 30، والعميل دفع 50 من محفظته */
    $orderId = $mk([40.0, 30.0], 50.0);
    $before  = (array) DB::selectOne('SELECT total_delivery_price, wallet_used FROM orders WHERE id = ?', [$orderId]);
    $expect  = Money::netCollect($before);
    ok('قبل التفريق: المتوقّع كاش 20', abs($expect - 20.0) < 0.005, (string) $expect);

    $ctl->split(req($admin, ['parcelNos' => [1], 'pilotId' => (int) $pilotB['id']]), (string) $orderId);

    $parent = (array) DB::selectOne('SELECT total_delivery_price, wallet_used FROM orders WHERE id = ?', [$orderId]);
    $child  = (array) DB::selectOne(
        'SELECT id, order_num, total_delivery_price, wallet_used FROM orders WHERE split_from_id = ?',
        [$orderId]
    );
    ok('الجزء المفصول اتعمل', (bool) $child);

    ok('السعر اتقسم 40 / 30',
        abs((float) $child['total_delivery_price'] - 40.0) < 0.005
        && abs((float) $parent['total_delivery_price'] - 30.0) < 0.005,
        "جزء={$child['total_delivery_price']} أصل={$parent['total_delivery_price']}");

    /* 🔴 البند اللي كان بيقع قبل الإصلاح */
    ok('المحفظة اتقسمت هي كمان — مش كلها على الأصل',
        (float) $child['wallet_used'] > 0.0,
        "جزء={$child['wallet_used']} أصل={$parent['wallet_used']}");

    ok('مجموع المحفظة بعد التفريق = 50 بالظبط',
        abs(((float) $child['wallet_used'] + (float) $parent['wallet_used']) - 50.0) < 0.005,
        (string) ((float) $child['wallet_used'] + (float) $parent['wallet_used']));

    /* ═══ الحارس الرئيسي ═══ */
    $after = Money::netCollect($child) + Money::netCollect($parent);
    ok('🔴 مجموع الكاش المتوقّع بعد التفريق = 20 (زي ما كان)',
        abs($after - 20.0) < 0.005,
        'بعد=' . $after . ' (جزء=' . Money::netCollect($child) . ' أصل=' . Money::netCollect($parent) . ')');

    echo "\n══ 3) قسمات مختلفة — نفس القاعدة ══\n";
    /* القاعدة مالهاش علاقة بأرقام معيّنة: أي توزيع طرود، وأي مبلغ محفظة،
       المجموع لازم يفضل ثابت. لو الحساب اتغيّر لضربة تانية بدل الطرح،
       واحدة من دول هتقع بفرق قرش. */
    $cases = [
        [[25.0, 25.0, 25.0], 45.0, [1]],
        [[25.0, 25.0, 25.0], 45.0, [1, 2]],
        [[33.33, 33.33, 33.34], 100.0, [2]],
        [[10.0, 90.0], 5.0, [1]],
        [[60.0, 40.0], 100.0, [2]],      // المحفظة غطّت السعر كله
        [[15.0, 15.0], 0.0, [1]],        // من غير محفظة خالص
    ];
    foreach ($cases as $n => [$prices, $wallet, $take]) {
        $oid = $mk($prices, $wallet);
        $pre = Money::netCollect((array) DB::selectOne(
            'SELECT total_delivery_price, wallet_used FROM orders WHERE id = ?', [$oid]
        ));
        $ctl->split(req($admin, ['parcelNos' => $take, 'pilotId' => (int) $pilotB['id']]), (string) $oid);
        $rows = array_map(fn ($r) => (array) $r, DB::select(
            'SELECT total_delivery_price, wallet_used FROM orders WHERE id = ? OR split_from_id = ?',
            [$oid, $oid]
        ));
        $post   = array_sum(array_map(fn ($r) => Money::netCollect($r), $rows));
        $wSum   = array_sum(array_map(fn ($r) => (float) $r['wallet_used'], $rows));
        ok('حالة ' . ($n + 1) . ": أسعار " . implode('+', $prices) . " · محفظة {$wallet} · اتاخد " . implode(',', $take),
            abs($post - $pre) < 0.005 && abs($wSum - $wallet) < 0.005,
            "قبل={$pre} بعد={$post} مجموع المحفظة={$wSum}");
    }

    echo "\n══ 4) حارس الرجوع: الأعمدة في مكانها ══\n";
    /* `php -l` بيعدّي على INSERT ناقص عمود عادي — العدد بيقع وقت التشغيل
       بس. والحارس ده بيمسك كمان لو حد شال `wallet_used` من التفريق. */
    $src = file_get_contents((new ReflectionClass(OrdersController::class))->getFileName());
    $at  = strpos($src, "split_from_id, source, added_by, added_by_role");
    $ins = substr($src, strrpos(substr($src, 0, $at), "'INSERT INTO orders"), 1400);
    $cols = substr($ins, strpos($ins, '(') + 1, strpos($ins, 'VALUES') - strpos($ins, '(') - 3);
    $nCols = count(array_filter(array_map('trim', explode(',', $cols))));
    $nPh   = substr_count(substr($ins, strpos($ins, 'VALUES'), strpos($ins, "',", strpos($ins, 'VALUES')) - strpos($ins, 'VALUES')), '?');
    ok('عدد الأعمدة = عدد العلامات في INSERT التفريق', $nCols === $nPh, "أعمدة={$nCols} علامات={$nPh}");
    ok('wallet_used جوه أعمدة التفريق', str_contains($cols, 'wallet_used'));
    ok('تحديث الأصل بيكتب wallet_used',
        str_contains($src, 'UPDATE orders SET total_delivery_price = ?, wallet_used = ?, store_prepaid = ?'));
    ok('الحساب من Money::splitWallet مش منسوخ بالإيد',
        str_contains($src, 'Money::splitWallet('));
} catch (Throwable $e) {
    $fail++;
    echo "\n💥 " . get_class($e) . ': ' . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "SPLIT WALLET: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
