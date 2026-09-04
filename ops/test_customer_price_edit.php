<?php

/**
 * 💰 حارس: تعديل سعر التوصيل لعميل التطبيق — تنفيذ حقيقي.
 *
 * ═══ الطلب (صاحب النظام 2026-09-02) ═══
 * «في تطبيق العميل عايز إمكانية إن العميل يغير سعر التوصيل زي تطبيق
 * المحلات — والميزة تتفتح له من خلال إدارة العملاء».
 *
 * العقود المثبتة:
 * • العميل العادي: أي zonePrice مبعوت بيتتجاهل **بصمت** — السعر من الزون.
 * • بعد الفتح (POST customers/{id}/price-edit): يرفع السعر، وينزل تحت
 *   سعر المنطقة = 400 برسالة واضحة (نفس قاعدة المحلات بالحرف).
 * • المنح admin بس، والعلم واصل في الـwire للطرفين (الإدارة والتطبيق).
 *
 * ⚠️ فيه POST /api/customer/orders حقيقي جوه معاملة بترجع — ممنوع على
 * الإنتاج (البث والإشعارات بتتدفع قبل الـrollback).
 *
 * التشغيل: php ops/test_customer_price_edit.php
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

function hit($kernel, array $ses, string $method, string $url, ?array $body = null): array
{
    $req = Illuminate\Http\Request::create($url, $method, [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null);
    $s = app('session')->driver();
    $s->flush(); $s->start();
    $s->put($ses);
    $req->setLaravelSession($s);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$admin = DB::select("SELECT id, username FROM users WHERE role='admin' LIMIT 1")[0] ?? null;
$zone  = DB::select('SELECT id, area_name, price FROM zones WHERE price > 0 LIMIT 1')[0] ?? null;
if (! $admin || ! $zone) { echo "مافيش أدمن/زون بسعر — تخطّي\n"; exit(0); }
$floor = (float) $zone->price;

$adminSes = ['user_id' => (int) $admin->id, 'username' => $admin->username,
    'role' => 'admin', 'branch_id' => null, 'name' => $admin->username];

DB::beginTransaction();
try {
    /* عميل مزروع كامل البيانات — جوه المعاملة وبيرجع */
    DB::insert("INSERT INTO customers (legacy_key, display_name, phone1, address, default_zone_id, profile_completed, created_at)
                VALUES ('guard-price-edit', 'عميل فحص السعر', '01000000005', 'شارع الفحص', ?, 1, NOW())",
        [(int) $zone->id]);
    $cid = (int) DB::getPdo()->lastInsertId();
    $custSes = ['role' => 'customer', 'customer_id' => $cid];

    $orderBody = fn (float $price) => [
        'senderName' => 'عميل فحص السعر', 'senderPhone' => '01000000005',
        'senderAddress' => 'شارع الفحص', 'senderZoneId' => (int) $zone->id,
        'deliveries' => [[
            'receiverName' => 'مستلم', 'receiverPhone' => '01000000006',
            'zoneId' => (int) $zone->id, 'zonePrice' => $price, 'orderPrice' => 0,
            'address' => 'عنوان',
        ]],
    ];

    echo "══ 1) العميل العادي — السعر من الزون إجباري ══\n";
    [$c1, $j1] = hit($kernel, $custSes, 'POST', '/api/customer/orders', $orderBody($floor + 50));
    $p1 = (float) ($j1['order']['totalDeliveryPrice'] ?? -1);
    ok('🔴 zonePrice مبعوت ومتجاهل بصمت — اتسجّل بسعر المنطقة',
        $c1 === 200 && abs($p1 - $floor) < 0.01, "اتبعت " . ($floor + 50) . " اتسجّل {$p1}");

    echo "\n══ 2) المنح من إدارة العملاء ══\n";
    [$c2, $j2] = hit($kernel, $adminSes, 'POST', "/api/customers/{$cid}/price-edit", ['enabled' => true]);
    ok('الفتح بينجح والـwire بيرجّع canEditPrice=true',
        $c2 === 200 && ($j2['customer']['canEditPrice'] ?? false) === true, (string) $c2);
    $branchU = DB::select("SELECT id, username, branch_id FROM users WHERE role='branch' AND blocked=0 LIMIT 1")[0] ?? null;
    if ($branchU) {
        [$c2b] = hit($kernel, ['user_id' => (int) $branchU->id, 'username' => $branchU->username,
            'role' => 'branch', 'branch_id' => (int) $branchU->branch_id, 'name' => $branchU->username],
            'POST', "/api/customers/{$cid}/price-edit", ['enabled' => true]);
        ok('🔒 المنح admin بس — الفرع بيترفض', $c2b >= 400, (string) $c2b);
    }

    echo "\n══ 3) بعد الفتح — نفس قاعدة المحلات ══\n";
    [$c3, $j3] = hit($kernel, $custSes, 'POST', '/api/customer/orders', $orderBody($floor + 25));
    $p3 = (float) ($j3['order']['totalDeliveryPrice'] ?? -1);
    ok('🔴 يرفع السعر — اتسجّل بالسعر المكتوب',
        $c3 === 200 && abs($p3 - ($floor + 25)) < 0.01, "اتسجّل {$p3}");

    [$c4, $j4] = hit($kernel, $custSes, 'POST', '/api/customer/orders', $orderBody(max(0, $floor - 5)));
    ok('🔴 وينزل تحت سعر المنطقة = رفض برسالة واضحة',
        $c4 >= 400 && str_contains((string) ($j4['error'] ?? ''), 'لا يقل عن'),
        $c4 . ' — ' . ($j4['error'] ?? '؟'));

    $noPrice = $orderBody(0);
    unset($noPrice['deliveries'][0]['zonePrice']);
    [$c5, $j5] = hit($kernel, $custSes, 'POST', '/api/customer/orders', $noPrice);
    $p5 = (float) ($j5['order']['totalDeliveryPrice'] ?? -1);
    ok('ومن غير zonePrice = سعر المنطقة عادي', $c5 === 200 && abs($p5 - $floor) < 0.01, (string) $p5);

    echo "\n══ 4) القفل بيرجّع القاعدة القديمة ══\n";
    hit($kernel, $adminSes, 'POST', "/api/customers/{$cid}/price-edit", ['enabled' => false]);
    [$c6, $j6] = hit($kernel, $custSes, 'POST', '/api/customer/orders', $orderBody($floor + 50));
    $p6 = (float) ($j6['order']['totalDeliveryPrice'] ?? -1);
    ok('بعد القفل السعر المبعوت بيتتجاهل تاني', $c6 === 200 && abs($p6 - $floor) < 0.01, (string) $p6);

    echo "\n══ 5) الواجهات ══\n";
    $strip = fn (string $t) => preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $t);
    $cu = $strip(file_get_contents('public/customer.html'));
    ok('التطبيق: خانة السعر بتظهر للمفتوح له بس', str_contains($cu, 'S.profile?.canEditPrice'));
    ok('  والقيمة بتتحفظ مع كل حرف (درس مسح إعادة الرسم)',
        str_contains($cu, 'oninput="onCustomPrice(') && str_contains($cu, 'r.customPrice = el && el.value'));
    ok('  والإجمالي والإرسال بيستعملوا نفس الدالة',
        str_contains($cu, 'reduce((s, r) => s + rcvPriceOf(r), 0)') && str_contains($cu, 'zonePrice: rcvPriceOf(r),'));
    $ca = $strip(file_get_contents('public/customers.html'));
    ok('إدارة العملاء: زرار الفتح/القفل + الحالة',
        str_contains($ca, 'data-act="price"') && str_contains($ca, '/price-edit'));

    DB::rollBack();
    echo "\n✅ المعاملة رجعت\n";
} catch (Throwable $e) {
    DB::rollBack();
    echo '🔴 ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
}

echo "\n" . str_repeat('─', 52) . "\n";
echo $fail === 0
    ? "✅ عدّى {$pass} فحص — سعر العميل محكوم بمنحة الإدارة وأرضية المنطقة\n"
    : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
