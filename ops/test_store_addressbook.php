<?php

/**
 * 📍 حارس: دفتر عناوين بوابة المحلات («عناويني») — تنفيذ حقيقي.
 *
 * ═══ الطلب (صاحب النظام 2026-09-02) ═══
 * «في تطبيق المحلات اعمل عناويني زي تطبيق العملاء — والعملاء كمان».
 * عناويني = دفتر عناوين استلام للمحل (store_addresses + 4 مسارات
 * role:store)، وعملائي = شاشة على store-contacts الموجودة.
 *
 * بيمر على دورة CRUD كاملة بجلسة محل حقيقية جوه معاملة بترجع، وبيثبت:
 * افتراضي واحد بس، التعديل الجزئي، وحارس الملكية (محل تاني/دور تاني).
 *
 * التشغيل: php ops/test_store_addressbook.php
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

function hitAs($kernel, array $ses, string $method, string $url, ?array $body = null): array
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

$store = DB::select("SELECT id, username FROM users WHERE role='store' AND blocked=0 LIMIT 1")[0] ?? null;
if (! $store) { echo "مافيش حساب محل — تخطّي\n"; exit(0); }
$zone = DB::select('SELECT id, area_name FROM zones LIMIT 1')[0] ?? null;
if (! $zone) { echo "مافيش مناطق — تخطّي\n"; exit(0); }
$branchUser = DB::select("SELECT id, username, branch_id FROM users WHERE role='branch' AND blocked=0 LIMIT 1")[0] ?? null;

$sesOf = fn ($u, string $role) => ['user_id' => (int) $u->id, 'username' => $u->username,
    'role' => $role, 'branch_id' => $u->branch_id ?? null, 'name' => $u->username];
$ses = $sesOf($store, 'store');

DB::beginTransaction();
try {
    echo "══ 1) الدورة الكاملة ══\n";
    [$c1, $j1] = hitAs($kernel, $ses, 'POST', '/api/store/addresses', [
        'label' => 'المحل', 'fullAddress' => 'شارع الفحص 1', 'zoneId' => (int) $zone->id,
        'isDefault' => true, 'lat' => 30.9, 'lng' => 31.5,
    ]);
    ok('إضافة عنوان افتراضي', $c1 === 200 && count($j1['items'] ?? []) === 1, (string) $c1);
    $id1 = $j1['items'][0]['id'] ?? null;
    ok('والرد فيه اسم المنطقة والدبوس',
        ($j1['items'][0]['zoneName'] ?? '') !== '' && ($j1['items'][0]['lat'] ?? null) !== null);

    [$c2, $j2] = hitAs($kernel, $ses, 'POST', '/api/store/addresses', [
        'label' => 'المخزن', 'fullAddress' => 'طريق الفحص 2', 'zoneId' => (int) $zone->id,
        'isDefault' => true,
    ]);
    $id2 = null; $defaults = 0;
    foreach ($j2['items'] ?? [] as $a) {
        if ($a['label'] === 'المخزن') { $id2 = $a['id']; }
        if (! empty($a['isDefault'])) { $defaults++; }
    }
    ok('🔴 افتراضي واحد بس — التاني شال علامة الأول', $c2 === 200 && $defaults === 1,
        "افتراضيات: {$defaults}");
    ok('والافتراضي الجديد هو المخزن',
        ($j2['items'][0]['label'] ?? '') === 'المخزن' && ! empty($j2['items'][0]['isDefault']));

    [$c3, $j3] = hitAs($kernel, $ses, 'PUT', "/api/store/addresses/{$id1}", ['label' => 'المحل الرئيسي']);
    $updated = null;
    foreach ($j3['items'] ?? [] as $a) {
        if ($a['id'] === $id1) { $updated = $a; }
    }
    ok('🔴 تعديل جزئي: الاسم اتغيّر والعنوان والزون والدبوس زي ما هما',
        $c3 === 200 && $updated !== null && $updated['label'] === 'المحل الرئيسي'
        && $updated['fullAddress'] === 'شارع الفحص 1' && $updated['lat'] !== null,
        json_encode($updated, JSON_UNESCAPED_UNICODE));

    [$c4, $j4] = hitAs($kernel, $ses, 'GET', '/api/store/addresses');
    ok('القايمة: الافتراضي الأول', $c4 === 200 && ($j4['items'][0]['label'] ?? '') === 'المخزن');

    [$c5, $j5] = hitAs($kernel, $ses, 'DELETE', "/api/store/addresses/{$id2}");
    ok('الحذف بيرجّع القايمة من غيره',
        $c5 === 200 && count($j5['items'] ?? []) === 1 && ($j5['items'][0]['id'] ?? '') === $id1);

    echo "\n══ 2) الحراسة ══\n";
    [$cz] = hitAs($kernel, $ses, 'POST', '/api/store/addresses',
        ['fullAddress' => 'بلا منطقة']);
    ok('من غير منطقة بيترفض', $cz >= 400, (string) $cz);
    if ($branchUser) {
        [$cb] = hitAs($kernel, $sesOf($branchUser, 'branch'), 'GET', '/api/store/addresses');
        ok('🔴 دور تاني (فرع) مايشوفش المسار خالص — role:store', $cb >= 400, (string) $cb);
        [$cd] = hitAs($kernel, $sesOf($branchUser, 'branch'), 'DELETE', "/api/store/addresses/{$id1}");
        ok('ولا يمسح منه', $cd >= 400, (string) $cd);
    }
    /* محل تاني (مزروع بصف بلا باسورد صالح — جوه المعاملة وبيرجع) */
    DB::insert("INSERT INTO users (username, password_hash, role, blocked, created_at)
                VALUES ('متجر-فحص-2', 'x-no-login-x', 'store', 0, NOW())");
    $other = (object) ['id' => (int) DB::getPdo()->lastInsertId(), 'username' => 'متجر-فحص-2', 'branch_id' => null];
    [$co, $jo] = hitAs($kernel, $sesOf($other, 'store'), 'GET', '/api/store/addresses');
    ok('🔴 محل تاني بيشوف قايمته هو (فاضية) — مش عناوين غيره',
        $co === 200 && count($jo['items'] ?? []) === 0, json_encode($jo['items'] ?? null));
    [$cod] = hitAs($kernel, $sesOf($other, 'store'), 'DELETE', "/api/store/addresses/{$id1}");
    ok('ومحاولة حذفه لعنوان غيره = «مش موجود»', $cod === 404, (string) $cod);

    echo "\n══ 3) الواجهة — الشاشتين والربط ══\n";
    $ui = file_get_contents('public/store.html');
    $strip = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $ui);
    ok('صفحة «عناويني» موجودة وواصلة من حسابي',
        str_contains($ui, 'id="page-addressbook"') && str_contains($strip, "navigateTo('addressbook')"));
    ok('صفحة «عملائي» موجودة', str_contains($ui, 'id="page-clients"') && str_contains($strip, 'renderClientsPage'));
    ok('منتقي «استلام من عنوان محفوظ» في فورم الشحنة',
        str_contains($ui, 'id="pickupAddrChoice"') && str_contains($strip, 'applyPickupSavedAddr'));
    ok('والافتراضي بيتطبق مع فتح الفورم', str_contains($strip, 'applyDefaultSavedAddress()'));
    ok('«ابعت له» بتملا بيانات المستلم', str_contains($strip, 'window.sendToContact = function'));
    ok('وحذف العميل على مسار store-contacts الموجود',
        str_contains($strip, 'api.del(`/api/store-contacts/'));

    DB::rollBack();
    echo "\n✅ المعاملة رجعت\n";
} catch (Throwable $e) {
    DB::rollBack();
    echo '🔴 ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
}

echo "\n" . str_repeat('─', 52) . "\n";
echo $fail === 0
    ? "✅ عدّى {$pass} فحص — عناويني وعملائي شغالين ومحروسين\n"
    : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
