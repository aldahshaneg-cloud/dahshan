<?php
/**
 * 🛵 حارس: تتبّع الطيار من بوابة المحلات — خاصية تتفتح وتتقفل لكل محل (طلب 2026-09-16).
 *
 * «عايز أفتح تتبّع لبوابة المحلات للمندوب اللي هيجي يرفع منها وتبقى خاصية تتفتح وتتقفل».
 *
 * ═══ العقود (تنفيذ حقيقي جوه معاملة بتترجع) ═══
 * • الافتراضي مقفول: GET store/orders/{id}/pickup-track = 403، وملف الاستلام canTrackPilot=false.
 * • POST users/{id}/track-pilot (أدمن بس، لحسابات المحلات بس) بيفتح/بيقفل؛ المحل مايفتحش لنفسه.
 * • مفتوح + أوردر المحل + طيار متعيّن + لسه ما اتسلّمش → 200 باسم الطيار وموقعه وموقع المحل.
 * • أوردر محل تاني = 403 · من غير طيار = 409 · بعد التسليم للطيار = 409.
 * • البوابة: زرار «فين الطيار؟» على الأوردر المستني الاستلام للمفتوح له بس + خريطة حيّة كل 8 ثواني.
 * • إدارة المحلات: زرار فتح/قفل + سطر الحالة.
 * التشغيل: php ops/test_store_pickup_track.php
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

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

echo "══ 0) الواجهات ══\n";
$S = file_get_contents($ROOT . '/public/store.html');
ok('store: زرار «فين الطيار؟» للمستني الاستلام وللمفتوح له بس', str_contains($S, 'window.trackBtnHtml = function(o) {')
    && str_contains($S, 'if (!awaitingPickup(o)) return "";')
    && str_contains($S, 'if (!(window._pickupProfile && window._pickupProfile.canTrackPilot)) return "";')
    && str_contains($S, '${trackBtnHtml(o)}'));
ok('  والخريطة بتسأل السيرفر كل 8 ثواني وبتتقفل على 409', str_contains($S, 'api.get(`/api/store/orders/${_trkOrder}/pickup-track`)')
    && str_contains($S, '_trkTimer = setInterval(_trkTick, 8000);')
    && str_contains($S, 'if (e && (e.status === 409 || e.status === 403)) { setTimeout(closePilotTrack, 1800); }'));
ok('  والغطاء موجود ومحسوب كنافذة مفتوحة', str_contains($S, 'id="pilotTrackOverlay"') && str_contains($S, 'on("pilotTrackOverlay")'));
$A = file_get_contents($ROOT . '/public/stores.html');
ok('stores: زرار الفتح/القفل وسطر الحالة', str_contains($A, 'data-act="track"') && str_contains($A, '/api/users/${s.id}/track-pilot')
    && str_contains($A, 'kv("تتبّع الطيار", s.canTrackPilot'));

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
function hit($kernel, array $u, string $method, string $url, array $body = []): array
{
    $req = Request::create($url, $method, [], [], [], [], $body ? json_encode($body) : null);
    $req->headers->set('Accept', 'application/json');
    if ($body) { $req->headers->set('Content-Type', 'application/json'); }
    $ses = app('session')->driver();
    $ses->flush();
    $ses->start();
    $ses->put(['user_id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role'],
               'branch_id' => $u['branch_id'] ?? null, 'name' => $u['username']]);
    $req->setLaravelSession($ses);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$stores = array_map(fn ($r) => (array) $r, DB::select("SELECT id, username, role, branch_id, shop_zone_id, shop_name, shop_phone FROM users WHERE role = 'store' AND blocked = 0 AND shop_zone_id IS NOT NULL ORDER BY id LIMIT 2"));
$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
$zone = DB::selectOne('SELECT id, price FROM zones WHERE price > 0 LIMIT 1');
$pilot = DB::selectOne('SELECT id, name FROM pilots ORDER BY id LIMIT 1');
if (count($stores) < 2 || ! $admin || ! $zone || ! $pilot) { echo "مافيش محلين/أدمن/زون/طيار — تخطّي\n"; exit(0); }
[$st, $st2] = $stores;

$body = fn (array $s) => [
    'senderName' => $s['shop_name'] ?: 'محل فحص', 'senderPhone' => $s['shop_phone'] ?: '01000000000', 'senderAddress' => 'شارع الفحص',
    'senderZoneId' => (int) $s['shop_zone_id'], 'source' => 'store', 'senderLat' => 30.05, 'senderLng' => 31.25, 'geoSrc' => 'store-pin',
    'deliveries' => [['parcelNo' => 1, 'receiverName' => 'مستلم فحص', 'receiverPhone' => '01012345678',
        'zoneId' => (int) $zone->id, 'zonePrice' => (float) $zone->price, 'orderPrice' => 0, 'address' => 'عنوان']],
];

DB::beginTransaction();
try {
    DB::update('UPDATE users SET can_track_pilot = 0 WHERE id IN (?, ?)', [$st['id'], $st2['id']]);
    DB::update('UPDATE pilots SET lat = 30.0600, lng = 31.2400, location_updated_at = UTC_TIMESTAMP() WHERE id = ?', [$pilot->id]);

    echo "\n══ 1) أوردر للمحل + طيار متعيّن ══\n";
    [$c, $j] = hit($kernel, $st, 'POST', '/api/orders', $body($st));
    $o = $j['orders'][0] ?? $j['order'] ?? null;
    ok('الإنشاء 200', $c === 200 && $o, $c . ' ' . mb_substr(json_encode($j, JSON_UNESCAPED_UNICODE), 0, 160));
    $oid = (int) ($o['id'] ?? 0);

    echo "\n══ 2) مقفول (الافتراضي) ══\n";
    [$c, $j] = hit($kernel, $st, 'GET', "/api/store/orders/{$oid}/pickup-track");
    ok('🔴 403 والرسالة بتقول اطلبه من الإدارة', $c === 403 && str_contains((string) ($j['error'] ?? ''), 'مش مفتوح'), $c . ' ' . ($j['error'] ?? ''));
    [, $p] = hit($kernel, $st, 'GET', '/api/store/pickup-profile');
    ok('ملف الاستلام canTrackPilot=false', ($p['profile']['canTrackPilot'] ?? null) === false);

    echo "\n══ 3) الفتح من إدارة المحلات ══\n";
    [$c, $j] = hit($kernel, $admin, 'POST', "/api/users/{$st['id']}/track-pilot", ['enabled' => true]);
    ok('200 وcanTrackPilot=true', $c === 200 && ($j['canTrackPilot'] ?? false) === true, (string) $c);
    [$c] = hit($kernel, $admin, 'POST', "/api/users/{$admin['id']}/track-pilot", ['enabled' => true]);
    ok('لحساب غير محل = مرفوض', $c >= 400, (string) $c);
    [$c] = hit($kernel, $st, 'POST', "/api/users/{$st['id']}/track-pilot", ['enabled' => true]);
    ok('المحل مايفتحش لنفسه', $c >= 400, (string) $c);
    [, $p] = hit($kernel, $st, 'GET', '/api/store/pickup-profile');
    ok('ملف الاستلام canTrackPilot=true', ($p['profile']['canTrackPilot'] ?? null) === true);
    [, $u] = hit($kernel, $admin, 'GET', '/api/users');
    $row = null;
    foreach (($u['items'] ?? []) as $r) { if ((int) ($r['id'] ?? 0) === (int) $st['id']) { $row = $r; } }
    ok('قايمة المستخدمين (إدارة المحلات) بترجّع canTrackPilot=true', ($row['canTrackPilot'] ?? null) === true, json_encode(array_keys($row ?? [])));

    echo "\n══ 4) من غير طيار = 409 · بعد التعيين = 200 ══\n";
    [$c, $j] = hit($kernel, $st, 'GET', "/api/store/orders/{$oid}/pickup-track");
    ok('من غير طيار 409', $c === 409, $c . ' ' . ($j['error'] ?? ''));
    DB::update("UPDATE orders SET pilot_id = ?, status = 'delivering' WHERE id = ?", [$pilot->id, $oid]);
    [$c, $j] = hit($kernel, $st, 'GET', "/api/store/orders/{$oid}/pickup-track");
    $t = $j['track'] ?? [];
    ok('🔴 200 باسم الطيار وموقعه', $c === 200 && ($t['pilot']['name'] ?? '') === $pilot->name
        && abs((float) ($t['pilot']['lat'] ?? 0) - 30.06) < 0.0001 && abs((float) ($t['pilot']['lng'] ?? 0) - 31.24) < 0.0001,
        $c . ' ' . mb_substr(json_encode($j, JSON_UNESCAPED_UNICODE), 0, 200));
    ok('  وموقع المحل من دبوس الأوردر', abs((float) ($t['store']['lat'] ?? 0) - 30.05) < 0.0001 && abs((float) ($t['store']['lng'] ?? 0) - 31.25) < 0.0001);
    ok('  وآخر تحديث موجود', ! empty($t['pilot']['updatedAt']));

    echo "\n══ 5) محل تاني = 403 · بعد التسليم للطيار = 409 · القفل = 403 ══\n";
    DB::update('UPDATE users SET can_track_pilot = 1 WHERE id = ?', [$st2['id']]);
    [$c] = hit($kernel, $st2, 'GET', "/api/store/orders/{$oid}/pickup-track");
    ok('محل تاني 403', $c === 403, (string) $c);
    DB::update('UPDATE orders SET handed_over_at = UTC_TIMESTAMP() WHERE id = ?', [$oid]);
    [$c, $j] = hit($kernel, $st, 'GET', "/api/store/orders/{$oid}/pickup-track");
    ok('بعد التسليم 409', $c === 409, $c . ' ' . ($j['error'] ?? ''));
    [$c, $j] = hit($kernel, $admin, 'POST', "/api/users/{$st['id']}/track-pilot", ['enabled' => false]);
    ok('القفل 200 وcanTrackPilot=false', $c === 200 && ($j['canTrackPilot'] ?? true) === false);
    DB::update('UPDATE orders SET handed_over_at = NULL WHERE id = ?', [$oid]);
    [$c] = hit($kernel, $st, 'GET', "/api/store/orders/{$oid}/pickup-track");
    ok('وبعد القفل 403', $c === 403, (string) $c);
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "STORE PICKUP TRACK: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
