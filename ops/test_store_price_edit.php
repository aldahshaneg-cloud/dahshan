<?php

/**
 * 🏪 حارس: تعديل سعر التوصيل في بوابة المحلات خاصية لبعض المحلات — تنفيذ حقيقي.
 *
 * الطلب (صاحب النظام 2026-09-03): «تعديل الخدمة بالناقص أو بالموجب تبقى
 * خاصية يمكن إضافتها لبعض المحلات».
 *
 * العقود المثبتة:
 * • مقفول (الافتراضي): أي zonePrice مبعوت (أعلى أو أقل) بيتتجاهل → سعر المنطقة.
 * • POST /api/users/{id}/price-edit (أدمن) بيفتح/بيقفل، ولحسابات المحلات بس.
 * • مفتوح: أعلى **وأقل** من سعر المنطقة بيتقبلوا — السالب مرفوض.
 * • ملف الاستلام (store/pickup-profile) بيرجّع canEditPrice للبوابة.
 *
 * ⚠️ إنشاء أوردرات حقيقي جوه معاملة بترجع (بث) — ممنوع على الإنتاج.
 *
 * التشغيل: php ops/test_store_price_edit.php
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

$store = DB::select("SELECT id, username, role, branch_id FROM users WHERE role='store' AND blocked=0 LIMIT 1")[0] ?? null;
$admin = DB::select("SELECT id, username, role, branch_id FROM users WHERE role='admin' AND blocked=0 LIMIT 1")[0] ?? null;
$zone  = DB::select('SELECT id, area_name, price, delivery_branch_id FROM zones WHERE price > 0 AND delivery_branch_id IS NOT NULL LIMIT 1')[0] ?? null;
if (! $store || ! $admin || ! $zone) { echo "مافيش محل/أدمن/زون — تخطّي\n"; exit(0); }
$zp = (float) $zone->price;

/* البوابة بتبعت فرع الاستلام من زون المحل (delivery_branch_id) — نفس ما store.html بيعمل */
$body = fn (float $sent) => [
    'branchId' => (int) $zone->delivery_branch_id,
    'senderName' => 'محل الفحص', 'senderPhone' => '01000000001', 'senderAddress' => 'شارع الفحص',
    'deliveries' => [[
        'parcelNo' => 1, 'receiverName' => 'مستلم', 'receiverPhone' => '01000000002',
        'zoneId' => (int) $zone->id, 'zoneName' => (string) $zone->area_name,
        'zonePrice' => $sent, 'orderPrice' => 0, 'address' => 'عنوان', 'note' => '',
    ]],
    'source' => 'store',
];
$priceOf = function (array $j): ?float {
    $d = $j['orders'][0]['deliveries'][0] ?? null;

    return $d ? (float) $d['zonePrice'] : null;
};

DB::beginTransaction();
try {
    /* المحل مقفول جوه المعاملة — بغض النظر عن حالته الحقيقية */
    DB::update('UPDATE users SET can_edit_price = 0 WHERE id = ?', [$store->id]);
    /* المحل لازم يبقى مربوط بمنطقة استلام عشان مسار الإنشاء يعدّي */
    DB::update('UPDATE users SET shop_zone_id = ?, shop_name = COALESCE(shop_name, ?), shop_phone = COALESCE(shop_phone, ?) WHERE id = ?',
        [$zone->id, 'محل الفحص', '01000000001', $store->id]);

    echo "══ 1) مقفول (الافتراضي): السعر المبعوت بيتتجاهل ══\n";
    [$c1, $j1] = hit($kernel, $store, 'POST', '/api/orders', $body($zp + 10));
    ok('HTTP 200', $c1 === 200, (string) $c1 . ' ' . json_encode($j1, JSON_UNESCAPED_UNICODE));
    ok('🔴 أعلى من المنطقة → اتحفظ سعر المنطقة', $c1 === 200 && abs($priceOf($j1) - $zp) < 0.01, (string) $priceOf($j1));
    [$c2, $j2] = hit($kernel, $store, 'POST', '/api/orders', $body(max(0, $zp - 5)));
    ok('🔴 أقل من المنطقة → سعر المنطقة برضه (مش خطأ)', $c2 === 200 && abs($priceOf($j2) - $zp) < 0.01, (string) $priceOf($j2));
    [, $p0] = hit($kernel, $store, 'GET', '/api/store/pickup-profile');
    ok('ملف الاستلام: canEditPrice=false', ($p0['profile']['canEditPrice'] ?? null) === false);

    echo "\n══ 2) الفتح من إدارة المحلات ══\n";
    [$c3, $j3] = hit($kernel, $admin, 'POST', "/api/users/{$store->id}/price-edit", ['enabled' => true]);
    ok('HTTP 200 وcanEditPrice=true', $c3 === 200 && ($j3['canEditPrice'] ?? false) === true, (string) $c3);
    [$c3b] = hit($kernel, $admin, 'POST', "/api/users/{$admin->id}/price-edit", ['enabled' => true]);
    ok('لحساب غير محل = مرفوض', $c3b >= 400, (string) $c3b);
    [$c3c] = hit($kernel, $store, 'POST', "/api/users/{$store->id}/price-edit", ['enabled' => true]);
    ok('المحل مايفتحش لنفسه (admin بس)', $c3c >= 400, (string) $c3c);
    [, $p1] = hit($kernel, $store, 'GET', '/api/store/pickup-profile');
    ok('ملف الاستلام: canEditPrice=true', ($p1['profile']['canEditPrice'] ?? null) === true);

    echo "\n══ 3) مفتوح: بالموجب وبالناقص ══\n";
    [$c4, $j4] = hit($kernel, $store, 'POST', '/api/orders', $body($zp + 10));
    ok('🔴 أعلى اتقبل', $c4 === 200 && abs($priceOf($j4) - ($zp + 10)) < 0.01, (string) $priceOf($j4));
    $low = max(0, $zp - 5);
    [$c5, $j5] = hit($kernel, $store, 'POST', '/api/orders', $body($low));
    ok('🔴 أقل من المنطقة اتقبل (كان مرفوض قبل الخاصية)', $c5 === 200 && abs($priceOf($j5) - $low) < 0.01, (string) $priceOf($j5));
    [$c6] = hit($kernel, $store, 'POST', '/api/orders', $body(-3));
    ok('السالب مرفوض', $c6 >= 400, (string) $c6);

    echo "\n══ 4) القفل تاني ══\n";
    [$c7] = hit($kernel, $admin, 'POST', "/api/users/{$store->id}/price-edit", ['enabled' => false]);
    [$c8, $j8] = hit($kernel, $store, 'POST', '/api/orders', $body($zp + 10));
    ok('بعد القفل → سعر المنطقة تاني', $c7 === 200 && $c8 === 200 && abs($priceOf($j8) - $zp) < 0.01, (string) $priceOf($j8));
} finally {
    DB::rollBack();
}

echo "\n────────────────────────────────────\n";
if ($fail > 0) { echo "🔴 {$fail} فحص وقع (نجح {$pass})\n"; exit(1); }
echo "✅ كل الفحوص عدّت ({$pass})\n";
