<?php

declare(strict_types=1);

/**
 * ✅ اختبار «سلّمت الأوردر للطيار» — كله جوه معاملة بتترجع فمفيش أثر.
 *
 * الفكرة اللي بيحرسها: حالة «جاري التوصيل» بتتسجّل من لحظة ما الفرع
 * يحمّل الأوردر على طيار — مش من لحظة ما الطيار يشيله من المحل. العمود
 * `handed_over_at` بيفصل اللحظتين، وشاشة المحل بتقرا الفرق ده.
 *
 * وأهم بند هنا: **التأكيد مش بوابة**. الرحلة لازم تقدر تبدأ حتى لو المحل
 * نسي يدوس — وساعتها الطابع بيتملا لوحده.
 *
 * التشغيل: php ops/test_handover.php
 */

use App\Http\Controllers\Api\OrdersController;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use App\Wire\OrderWire;
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

/** الطابع الخام من الجدول — مش من السلك، عشان نشوف الـNULL على حقيقته */
function raw(int $id): array
{
    return (array) DB::selectOne(
        'SELECT status, received_at, trip_started_at, handed_over_at, handed_over_by
           FROM orders WHERE id = ?',
        [$id]
    );
}

$ctl = new OrdersController();

DB::beginTransaction();
try {
    $now = date('Y-m-d H:i:s');

    $branchId = (int) DB::selectOne('SELECT id FROM branches ORDER BY id LIMIT 1')->id;
    /* الفرع التاني بيتعمل هنا بدل ما نتمنّى نلاقيه: قاعدة التطوير فيها
       فرع واحد بس، وفحص «فرع تاني مرفوض» كان هيتخطّى بصمت — وهو أهم
       حماية في الملف. الصف بيترجع مع المعاملة. */
    DB::insert(
        "INSERT INTO branches (name, code, created_at) VALUES ('فرع الاختبار', ?, ?)",
        ['T' . substr((string) time(), -4), $now]
    );
    $branch2 = (int) DB::getPdo()->lastInsertId();
    /* التحميل بيرفض الطيار المؤرشف أو اللي مش في وردية (`status` لازم
       waiting/delivering). على لقطة الإنتاج كل الطيارين حالتهم NULL الصبح
       فالفحص كان بيقع بـ«غير متاح للتحميل» من غير باج — بنختار طيار غير
       مؤرشف ونحطه waiting جوه المعاملة (بيترجع مع الـrollback). */
    $pilotId = (int) DB::selectOne('SELECT id FROM pilots WHERE archived_at IS NULL ORDER BY id LIMIT 1')->id;
    DB::update("UPDATE pilots SET status = 'waiting' WHERE id = ?", [$pilotId]);

    $mkOrder = function (?int $pilot, string $status = 'delivering', ?int $branch = null) use ($branchId, $now): int {
        DB::insert(
            "INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, sender_phone,
                                 status, status_since, created_at, total_delivery_price,
                                 added_by, added_by_role, source, pilot_id)
             VALUES (?,?,?,'محل الاختبار','01000000000',?,?,?,30,'shop1','store','store',?)",
            ['HO-' . uniqid(), $branch ?? $branchId, $branch ?? $branchId, $status, $now, $now, $pilot]
        );

        return (int) DB::getPdo()->lastInsertId();
    };

    $store   = Actor::staff(801, 'shop1', 'store', null, 'محل الاختبار');
    $other   = Actor::staff(802, 'shop2', 'store', null, 'محل تاني');
    $branchA = Actor::staff(803, 'br1', 'branch', $branchId, 'مشرف الفرع');

    echo "\n══ 1) المحل بيأكّد التسليم ══\n";
    $o1 = $mkOrder($pilotId);
    ok('قبل الدوس: مفيش طابع تسليم', raw($o1)['handed_over_at'] === null);

    $r1 = json_decode($ctl->handover(req($store), (string) $o1)->getContent(), true);
    ok('الطلب عدّى', ! empty($r1['ok']), json_encode($r1, JSON_UNESCAPED_UNICODE));

    $after = raw($o1);
    ok('الطابع اتسجّل', $after['handed_over_at'] !== null, var_export($after['handed_over_at'], true));
    ok('اسم اللي أكّد اتسجّل', $after['handed_over_by'] === 'shop1', var_export($after['handed_over_by'], true));
    ok('الحالة ما اتغيّرتش', $after['status'] === 'delivering', (string) $after['status']);
    /* 🔴 التأكيد **مش** استلام الطيار. لو دوسة المحل كتبت received_at
       كمان يبقى إحنا بنسجّل باسم الطيار حاجة هو ما عملهاش. */
    ok('received_at لسه فاضي — ده طابع الطيار مش المحل', $after['received_at'] === null,
        var_export($after['received_at'], true));

    echo "\n══ 2) السلك بيطلّع الحقلين ══\n";
    $w = OrderWire::full($o1);
    ok('handedOverAt على السلك', ! empty($w['handedOverAt']), var_export($w['handedOverAt'] ?? null, true));
    ok('handedOverBy على السلك', ($w['handedOverBy'] ?? null) === 'shop1', var_export($w['handedOverBy'] ?? null, true));
    ok('handedOverAt بصيغة ISO', (bool) preg_match('/^\d{4}-\d{2}-\d{2}T/', (string) $w['handedOverAt']),
        (string) $w['handedOverAt']);

    echo "\n══ 3) الدوسة التانية مابتغيّرش الطابع ══\n";
    DB::update('UPDATE orders SET handed_over_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $o1]);
    $r3 = json_decode($ctl->handover(req($store), (string) $o1)->getContent(), true);
    ok('الدوسة التانية مابترميش خطأ', ! empty($r3['ok']), json_encode($r3, JSON_UNESCAPED_UNICODE));
    ok('الطابع الأول اتحافظ عليه',
        raw($o1)['handed_over_at'] === '2020-01-01 00:00:00', (string) raw($o1)['handed_over_at']);

    echo "\n══ 4) الحمايات ══\n";
    try {
        $ctl->handover(req($other), (string) $o1);
        ok('محل تاني مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('محل تاني مرفوض', str_contains($e->getMessage(), 'مش بتاع محلك'), $e->getMessage());
    }

    $oNoPilot = $mkOrder(null, 'processing');
    try {
        $ctl->handover(req($store), (string) $oNoPilot);
        ok('أوردر من غير طيار مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('أوردر من غير طيار مرفوض', str_contains($e->getMessage(), 'ما اتعيّنش طيار'), $e->getMessage());
    }

    $oDone = $mkOrder($pilotId, 'delivered');
    try {
        $ctl->handover(req($store), (string) $oDone);
        ok('أوردر متسلّم مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('أوردر متسلّم مرفوض', str_contains($e->getMessage(), 'خلص خلاص'), $e->getMessage());
    }

    $oOther = $mkOrder($pilotId, 'delivering', $branch2);
    try {
        $ctl->handover(req($branchA), (string) $oOther);
        ok('فرع تاني مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('فرع تاني مرفوض', str_contains($e->getMessage(), 'فرع تاني'), $e->getMessage());
    }

    try {
        $ctl->handover(req($store), '99999999');
        ok('أوردر مش موجود مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('أوردر مش موجود مرفوض', str_contains($e->getMessage(), 'مابقاش موجود'), $e->getMessage());
    }

    echo "\n══ 5) مشرف الفرع يقدر يأكّد بدل المحل ══\n";
    $o5 = $mkOrder($pilotId);
    $ctl->handover(req($branchA), (string) $o5);
    ok('الفرع أكّد', raw($o5)['handed_over_at'] !== null);
    ok('الاسم اتسجّل للفرع', raw($o5)['handed_over_by'] === 'br1', (string) raw($o5)['handed_over_by']);

    echo "\n══ 6) 🔴 التأكيد مش بوابة — الرحلة بتبدأ من غيره ══\n";
    /* بننده بمشرف الفرع مش بطيار: `guardPilot` بترجع فورًا لأي دور غير
       «pilot»، والخاصية اللي بنحرسها هنا خاصية الـSQL (COALESCE) مش
       خاصية الدور — وقاعدة التطوير مافيهاش حساب طيار مربوط أصلًا.
       حماية «الأوردر مش محمّل عليك» متغطّية في اختبارات تانية. */
    $o6 = $mkOrder($pilotId);
    ok('قبل بدء الرحلة: التسليم فاضي', raw($o6)['handed_over_at'] === null);
    $ctl->startTrip(req($branchA), (string) $o6);
    $a6 = raw($o6);
    ok('بدء الرحلة عدّى من غير تأكيد المحل', $a6['trip_started_at'] !== null, var_export($a6['trip_started_at'], true));
    ok('بدء الرحلة ملا طابع التسليم', $a6['handed_over_at'] !== null, var_export($a6['handed_over_at'], true));
    ok('الطابع الضمني من غير اسم — محدش أكّد بإيده', $a6['handed_over_by'] === null,
        var_export($a6['handed_over_by'], true));

    $o7 = $mkOrder($pilotId);
    $ctl->receive(req($branchA), (string) $o7);
    ok('«استلمت» كمان بتملا طابع التسليم', raw($o7)['handed_over_at'] !== null,
        var_export(raw($o7)['handed_over_at'], true));

    $o8 = $mkOrder($pilotId);
    $ctl->handover(req($store), (string) $o8);
    DB::update('UPDATE orders SET handed_over_at = ? WHERE id = ?', ['2020-02-02 00:00:00', $o8]);
    $ctl->startTrip(req($branchA), (string) $o8);
    ok('بدء الرحلة مابيدوسش على تأكيد المحل',
        raw($o8)['handed_over_at'] === '2020-02-02 00:00:00', (string) raw($o8)['handed_over_at']);
    ok('واسم المحل فضل زي ما هو', raw($o8)['handed_over_by'] === 'shop1', (string) raw($o8)['handed_over_by']);

    echo "\n══ 6.1) 🔴 إعادة الإسناد بتصفّر طوابع الرحلة ══\n";
    /* اللسعة: الأوردر اللي فشل توصيله بيرجع للفرع ويتحمّل على طيار تاني.
       من غير التصفير، طوابع الجولة الأولى بتتورّث — فكارت المحل يقول
       «خرج للعميل» والشحنة على الرف، **والعميل يشوف موقع الطيار الجديد
       من لحظة الإسناد** قبل ما يبدأ رحلته (لأن trip_started_at هو بوابة
       خصوصية التتبّع). */
    $o61 = $mkOrder($pilotId);
    $ctl->handover(req($store), (string) $o61);
    $ctl->startTrip(req($branchA), (string) $o61);
    $b61 = raw($o61);
    ok('الجولة الأولى: الطوابع اتسجّلت',
        $b61['handed_over_at'] !== null && $b61['trip_started_at'] !== null && $b61['received_at'] !== null);

    // فشل التوصيل → رجوع للفرع
    DB::update(
        "UPDATE orders SET status = 'undelivered', undelivered_at = ?, undelivered_reason = 'العميل مش راد' WHERE id = ?",
        [$now, $o61]
    );
    // إسناد لطيار تاني (نفس الطيار كفاية — اللي بنختبره التصفير مش الطيار)
    $ctl->assign(req($branchA, ['pilotId' => $pilotId]), (string) $o61);

    $a61 = raw($o61);
    ok('الحالة رجعت جاري التوصيل', $a61['status'] === 'delivering', (string) $a61['status']);
    ok('🔒 trip_started_at اتصفّر — العميل مايشوفش الطيار قبل ما يبدأ',
        $a61['trip_started_at'] === null, var_export($a61['trip_started_at'], true));
    ok('🔒 handed_over_at اتصفّر — كارت المحل رجع «الطيار جاي يستلم»',
        $a61['handed_over_at'] === null, var_export($a61['handed_over_at'], true));
    ok('handed_over_by اتصفّر كمان', $a61['handed_over_by'] === null, var_export($a61['handed_over_by'], true));
    ok('received_at اتصفّر', $a61['received_at'] === null, var_export($a61['received_at'], true));

    echo "\n══ 6.2) «استلمت» بتملا التسليم حتى لو الاستلام متسجّل من قبل ══\n";
    /* الحالة دي بتحصل للأوردرات اللي كانت جارية وقت نشر الميزة: عندها
       received_at من قبل و handed_over_at فاضي. الشرط القديم كان بيتخطّى
       الاتنين مع بعض فالطابع عمره ما كان هيتملا. */
    $o62 = $mkOrder($pilotId);
    DB::update('UPDATE orders SET received_at = ? WHERE id = ?', ['2020-03-03 00:00:00', $o62]);
    ok('البداية: استلام قديم وتسليم فاضي',
        raw($o62)['received_at'] === '2020-03-03 00:00:00' && raw($o62)['handed_over_at'] === null);
    $ctl->receive(req($branchA), (string) $o62);
    $a62 = raw($o62);
    ok('طابع التسليم اتملا', $a62['handed_over_at'] !== null, var_export($a62['handed_over_at'], true));
    ok('وطابع الاستلام القديم ما اتغيّرش',
        $a62['received_at'] === '2020-03-03 00:00:00', (string) $a62['received_at']);

    echo "\n══ 7) توجيه المسار ══\n";
    $routes = app('router')->getRoutes();
    $act = 'مفيش مسار';
    try {
        $act = (string) $routes->match(Request::create('/api/orders/12/handover', 'POST'))->getActionName();
    } catch (Throwable $e) {
        $act = 'مفيش مسار: ' . $e->getMessage();
    }
    ok('POST /api/orders/{id}/handover → OrdersController@handover',
        str_contains($act, 'OrdersController@handover'), $act);

    $mw = null;
    foreach ($routes->getRoutes() as $r) {
        if ($r->uri() === 'api/orders/{id}/handover') {
            $mw = implode(' · ', $r->gatherMiddleware());
        }
    }
    ok('الأدوار: المحل والفرع والأدمن بس',
        $mw !== null && str_contains($mw, 'role:store,branch,admin'), (string) $mw);
} catch (Throwable $e) {
    $fail++;
    echo "\n💥 " . get_class($e) . ': ' . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "HANDOVER: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
