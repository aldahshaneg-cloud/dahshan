<?php

/**
 * 📍 حارس: لوكيشن المستلم بالإحداثيات من فورم الفرع — تنفيذ حقيقي.
 *
 * الطلب (صاحب النظام 2026-09-03): «لوكيشن عميل عايز أضيفه بدقة على الماب
 * وأنا بضرب الأوردر». الأوردر اللي الفرع بيضربه كان بيطلع «بدون موقع» لأن
 * الخريطة بتقرا إحداثيات حقيقية بس.
 *
 * العقود المثبتة:
 * • POST /api/orders بـ lat/lng على الطرد → بيتخزنوا ويرجعوا في الـwire.
 * • PUT /api/orders/{id} من غير مفتاح lat = الإحداثيات القديمة **بتفضل**
 *   (واجهة قديمة ماتمسحش لوكيشن جاي من تطبيق العميل).
 * • PUT بـ lat/lng جداد = بيتحدّثوا و geo_src = branch.
 * • PUT بـ lat فاضي (null) = مسح مقصود.
 *
 * ⚠️ إنشاء أوردرات حقيقي جوه معاملة بترجع (بث) — ممنوع على الإنتاج.
 *
 * التشغيل: php ops/test_delivery_geo.php
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
$zone = DB::select('SELECT id, area_name FROM zones WHERE price > 0 LIMIT 1')[0] ?? null;
if (! $sup || ! $zone) { echo "مافيش مشرف فرع/زون — تخطّي\n"; exit(0); }

$body = fn (?float $lat, ?float $lng) => [
    'senderName' => 'اختبار اللوكيشن', 'senderPhone' => '01000000001',
    'senderAddress' => 'شارع الفحص', 'notes' => 'حارس geo',
    'deliveries' => [[
        'parcelNo' => 1, 'receiverName' => 'مستلم جوجل مابس', 'receiverPhone' => '01000000002',
        'zoneId' => (int) $zone->id, 'zoneName' => (string) $zone->area_name,
        'zonePrice' => 15, 'orderPrice' => 0, 'address' => 'الجلاء — سمنود', 'note' => '',
        'lat' => $lat, 'lng' => $lng,
    ]],
    'source' => 'branch',
];

DB::beginTransaction();
try {
    echo "══ 1) الإنشاء بإحداثيات من جوجل ══\n";
    [$c1, $j1] = hit($kernel, $sup, 'POST', '/api/orders', $body(31.018732, 31.228285));
    ok('HTTP 200', $c1 === 200, (string) $c1);
    $o = $j1['orders'][0] ?? [];
    $d = $o['deliveries'][0] ?? [];
    ok('🔴 lat/lng راجعين في الـwire بالظبط',
        abs((float) ($d['lat'] ?? 0) - 31.018732) < 1e-6 && abs((float) ($d['lng'] ?? 0) - 31.228285) < 1e-6,
        json_encode([$d['lat'] ?? null, $d['lng'] ?? null]));
    $oid = (int) ($o['id'] ?? 0);
    $did = (int) ($d['id'] ?? 0);

    echo "\n══ 2) تعديل من غير مفتاح lat = اللوكيشن بيفضل ══\n";
    [$c2] = hit($kernel, $sup, 'PUT', "/api/orders/{$oid}", [
        'senderName' => 'اختبار اللوكيشن', 'senderPhone' => '01000000001', 'senderPhone2' => '', 'senderAddress' => 'شارع الفحص',
        'deliveries' => [['id' => $did, 'receiverName' => 'مستلم جوجل مابس', 'receiverPhone' => '01000000002', 'receiverPhone2' => '', 'address' => 'الجلاء — سمنود']],
    ]);
    $row = DB::selectOne('SELECT lat, lng, geo_src FROM order_deliveries WHERE id = ?', [$did]);
    ok('🔴 HTTP 200 والإحداثيات القديمة فضلت',
        $c2 === 200 && abs((float) $row->lat - 31.018732) < 1e-6 && abs((float) $row->lng - 31.228285) < 1e-6,
        json_encode($row));

    echo "\n══ 3) تعديل بإحداثيات جديدة ══\n";
    [$c3] = hit($kernel, $sup, 'PUT', "/api/orders/{$oid}", [
        'senderName' => 'اختبار اللوكيشن', 'senderPhone' => '01000000001', 'senderPhone2' => '', 'senderAddress' => 'شارع الفحص',
        'deliveries' => [['id' => $did, 'receiverName' => 'مستلم جوجل مابس', 'receiverPhone' => '01000000002', 'receiverPhone2' => '', 'address' => 'الجلاء — سمنود',
                          'lat' => 31.05, 'lng' => 31.38]],
    ]);
    $row = DB::selectOne('SELECT lat, lng, geo_src FROM order_deliveries WHERE id = ?', [$did]);
    ok('اتحدثت و geo_src=branch',
        $c3 === 200 && abs((float) $row->lat - 31.05) < 1e-6 && abs((float) $row->lng - 31.38) < 1e-6 && $row->geo_src === 'branch',
        json_encode($row));

    echo "\n══ 4) تعديل بـ lat فاضي = مسح مقصود ══\n";
    [$c4] = hit($kernel, $sup, 'PUT', "/api/orders/{$oid}", [
        'senderName' => 'اختبار اللوكيشن', 'senderPhone' => '01000000001', 'senderPhone2' => '', 'senderAddress' => 'شارع الفحص',
        'deliveries' => [['id' => $did, 'receiverName' => 'مستلم جوجل مابس', 'receiverPhone' => '01000000002', 'receiverPhone2' => '', 'address' => 'الجلاء — سمنود',
                          'lat' => null, 'lng' => null]],
    ]);
    $row = DB::selectOne('SELECT lat, lng, geo_src FROM order_deliveries WHERE id = ?', [$did]);
    ok('اتمسحت (null/null)', $c4 === 200 && $row->lat === null && $row->lng === null, json_encode($row));

    echo "\n══ 5) الإنشاء من غير لوكيشن — زي الأول بالظبط ══\n";
    [$c5, $j5] = hit($kernel, $sup, 'POST', '/api/orders', $body(null, null));
    $d5 = $j5['orders'][0]['deliveries'][0] ?? [];
    ok('HTTP 200 وlat/lng null (بدون موقع)',
        $c5 === 200 && array_key_exists('lat', $d5) && $d5['lat'] === null && array_key_exists('lng', $d5) && $d5['lng'] === null,
        json_encode([$c5, $d5['lat'] ?? 'غايب', $d5['lng'] ?? 'غايب']));
} finally {
    DB::rollBack();
}

echo "\n────────────────────────────────────\n";
if ($fail > 0) { echo "🔴 {$fail} فحص وقع (نجح {$pass})\n"; exit(1); }
echo "✅ كل الفحوص عدّت ({$pass})\n";
