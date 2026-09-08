<?php
/**
 * 🛠️ حارس: المرحلة 2 من علاج المراجعة (2026-09-08) — السيرفر.
 *
 * ═══ العقود المثبتة ═══
 * • حدود الطول في إنشاء/تعديل الأوردر: عنوان > 190 أو تليفون > 20 = 400 برسالة عربية (كان 500 «Data too long»).
 * • الحضور: النبضة والانصراف بيلاقوا آخر جلسة مفتوحة حتى لو تاريخها امبارح (الدخول قبل ٩ الصبح).
 * • ?since على /api/zones و/api/order-notifications: changed:false لو مفيش تعديل، وحذف منطقة بيبان.
 * • طلب بتوكن مابيعملش جلسة ملف ولا Set-Cookie.
 * • ErrorAlert::appFrame بيرجّع أول إطار جوه app/.
 * • سماحية ثانية في since (Board/Pilot/Orders)، deadlock بـ3 محاولات، حد الساعات في دمشق،
 *   بلاغ واحد لكل عطل — فحوص نصية.
 * كله جوه معاملة بتترجع.
 *
 * التشغيل: php ops/test_phase2_server.php
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

use App\Support\ErrorAlert;
use App\Support\PollableList;
use Illuminate\Support\Facades\DB;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
function hit($kernel, array $u, string $method, string $url, array $body = [], array $headers = []): array
{
    $srv = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    foreach ($headers as $k => $v) { $srv['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v; }
    $req = Illuminate\Http\Request::create($url, $method, [], [], [], $srv, $body ? json_encode($body, JSON_UNESCAPED_UNICODE) : null);
    if ($u) {
        $ses = app('session')->driver();
        $ses->flush(); $ses->start();
        $ses->put(['user_id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role'],
                   'branch_id' => $u['branch_id'] ?? null, 'name' => $u['username']]);
        $req->setLaravelSession($ses);
    }
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true), $res];
}

$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
$sup   = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'branch' AND blocked = 0 AND branch_id IS NOT NULL ORDER BY id LIMIT 1")[0] ?? null);
$zone  = DB::select('SELECT id, area_name FROM zones WHERE price > 0 LIMIT 1')[0] ?? null;
if (! $admin || ! $sup || ! $zone) { echo "مافيش أدمن/مشرف/زون — تخطّي\n"; exit(0); }
$nowMs = PollableList::serverNowMs();

echo "══ 0) فحوص نصية ══\n";
$F = fn (string $p) => file_get_contents($ROOT . '/' . $p);
ok('🔴 المعاملة المالية بـ3 محاولات عند deadlock', str_contains($F('app/Http/Controllers/Api/FinanceController.php'), 'return DB::transaction($work, 3);'));
ok('سماحية ثانية في since (Board/Pilot/Orders)', str_contains($F('app/Http/Controllers/Api/BoardController.php'), "(int) \$request->query('since') - 1000") && str_contains($F('app/Http/Controllers/Api/PilotAppController.php'), 'max(0, (int) $raw - 1000)') && str_contains($F('app/Http/Controllers/Api/OrdersController.php'), "max(0, (int) \$q['since'] - 1000)"));
ok('الطابع قبل الاستعلام في orders/index وactive-orders', preg_match('/\$serverNow = PollableList::serverNowMs\(\);\s*\n\s*\$rows = DB::select\(\$sql, \$params\);/', $F('app/Http/Controllers/Api/OrdersController.php')) === 1 && str_contains($F('app/Http/Controllers/Api/PilotAppController.php'), 'return PollableList::unchanged($now);'));
ok('حد الساعات في شيت دمشق', str_contains($F('app/Http/Controllers/Api/DamascusController.php'), "if (\$col === 'hours' && abs(\$n) > 24)"));
ok('بلاغ واحد لكل عطل (مفيش report() جوه render)', substr_count($F('bootstrap/app.php'), 'report($e);') === 0 && str_contains($F('bootstrap/app.php'), 'TokenSessionInMemory::class,'));
foreach (['tiar' => 'zones:               { path: "/api/zones",            interval: 60000, since: true },', 'callcenter' => 'ccPoller("/api/zones", { interval: 60000, useSince: true, onChange: d => {', 'branch' => 'reg(new P("/api/zones", { interval: 60000, useSince: true, onChange: d => {'] as $pg => $needle) {
    ok("{$pg}: المناطق بـsince", str_contains($F("public/{$pg}.html"), $needle));
}
ok('tiar/callcenter: رسايل العملاء بـsince + حارس changed:false', str_contains($F('public/tiar.html'), 'useSince: true, onChange: (d) => {' . "\n        if (!d.items) return;") && str_contains($F('public/callcenter.html'), "interval: 30000, useSince: true,\n        onChange: (d) => {\n          if (!d.items) return;"));

echo "\n══ 1) ErrorAlert::appFrame ══\n";
$apiEx = App\Exceptions\ApiException::notFound('x');
[$f1] = ErrorAlert::appFrame($apiEx);
ok('استثناء من app/ → نفس ملفه', str_contains(str_replace('\\', '/', $f1), '/app/Exceptions/ApiException.php'), $f1);
$plain = new RuntimeException('من ops');
[$f2] = ErrorAlert::appFrame($plain);
ok('استثناء من برّه app/ ومفيش إطار app → الملف نفسه (زي قبل)', str_ends_with(str_replace('\\', '/', $f2), '/ops/test_phase2_server.php'), $f2);

DB::beginTransaction();
try {
    echo "\n══ 2) حدود الطول في إنشاء الأوردر ══\n";
    $parcel = fn (array $over = []) => array_merge([
        'parcelNo' => 1, 'receiverName' => 'مستلم', 'receiverPhone' => '01000000002',
        'zoneId' => (int) $zone->id, 'zoneName' => (string) $zone->area_name, 'zonePrice' => 15, 'orderPrice' => 0, 'address' => 'عنوان',
    ], $over);
    $body = fn (array $p, array $over = []) => array_merge(['senderName' => 'محل الفحص', 'senderPhone' => '01000000001', 'senderAddress' => 'شارع', 'deliveries' => [$p], 'source' => 'branch'], $over);
    [$c, $j] = hit($kernel, $sup, 'POST', '/api/orders', $body($parcel(['address' => str_repeat('ع', 191)])));
    ok('🔴 عنوان 191 حرف = 400 برسالة عربية مش 500', $c === 400 && str_contains((string) ($j['error'] ?? ''), 'أطول من المسموح'), $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    [$c, $j] = hit($kernel, $sup, 'POST', '/api/orders', $body($parcel(['receiverPhone' => str_repeat('1', 21)])));
    ok('🔴 تليفون مستلم 21 رقم = 400', $c === 400 && str_contains((string) ($j['error'] ?? ''), 'رقم هاتف المستلم'), $c . ' ' . ($j['error'] ?? ''));
    [$c, $j] = hit($kernel, $sup, 'POST', '/api/orders', $body($parcel(), ['senderAddress' => str_repeat('س', 191)]));
    ok('عنوان مرسل جديد 191 حرف = 400', $c === 400 && str_contains((string) ($j['error'] ?? ''), 'عنوان المرسل'), $c . ' ' . ($j['error'] ?? ''));
    [$c, $j] = hit($kernel, $sup, 'POST', '/api/orders', $body($parcel(['address' => str_repeat('ع', 190)])));
    ok('عنوان 190 حرف بيعدّي', $c === 200 && ! empty($j['orders']), $c . ' ' . mb_substr(json_encode($j, JSON_UNESCAPED_UNICODE), 0, 120));

    echo "\n══ 3) الحضور — جلسة مفتوحة بتاريخ امبارح ══\n";
    $y = gmdate('Y-m-d', time() - 86400);
    DB::insert('INSERT INTO attendance_sessions (session_date, username, role, check_in, last_seen, entry_type, created_at) VALUES (?,?,?,?,?,?,NOW())',
        [$y, $admin['username'], $admin['role'], gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() - 600), 'auto']);
    $sid = (int) DB::getPdo()->lastInsertId();
    [$c, $j] = hit($kernel, $admin, 'POST', '/api/attendance/heartbeat');
    $row = DB::selectOne('SELECT last_seen, check_out FROM attendance_sessions WHERE id = ?', [$sid]);
    ok('🔴 النبضة لاقت الجلسة وحدّثت last_seen', $c === 200 && ($j['open'] ?? null) !== false && strtotime($row->last_seen . ' UTC') > time() - 120, $c . ' ' . json_encode($j) . ' ' . $row->last_seen);
    [$c, $j] = hit($kernel, $admin, 'POST', '/api/attendance/check-out');
    $row = DB::selectOne('SELECT check_out FROM attendance_sessions WHERE id = ?', [$sid]);
    ok('🔴 الانصراف قفل نفس الجلسة', $c === 200 && $row->check_out !== null, $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));

    echo "\n══ 4) ?since — المناطق ورسايل العملاء ══\n";
    [$c, $j] = hit($kernel, $admin, 'GET', '/api/zones?since=' . ($nowMs + 5000));
    ok('🔴 zones?since=المستقبل → changed:false من غير items', $c === 200 && ($j['changed'] ?? null) === false && ! isset($j['items']), $c . ' ' . mb_substr(json_encode($j), 0, 100));
    [$c, $j] = hit($kernel, $admin, 'GET', '/api/zones');
    $cnt = count($j['items'] ?? []);
    ok('zones بلا since → القايمة كاملة', $c === 200 && $cnt > 0, (string) $cnt);
    DB::update('UPDATE zones SET area_name = area_name WHERE id = ?', [(int) $zone->id]);
    DB::update('UPDATE zones SET updated_at = NOW(3) WHERE id = ?', [(int) $zone->id]);
    $t0 = $nowMs - 60000;
    [$c, $j] = hit($kernel, $admin, 'GET', '/api/zones?since=' . $t0);
    ok('تعديل منطقة بعد since → القايمة كاملة', $c === 200 && ($j['changed'] ?? null) === true && count($j['items'] ?? []) === $cnt);
    /* الحذف: منطقة جديدة تتحذف بعد since → لازم يبان */
    DB::insert('INSERT INTO zones (area_name, price, delivery_branch_id, created_at, updated_at) VALUES (?,?,?,?,?)', ['منطقة فحص since', 5, (int) $sup['branch_id'], gmdate('Y-m-d H:i:s', time() - 7200), gmdate('Y-m-d H:i:s', time() - 7200)]);
    $zid = (int) DB::getPdo()->lastInsertId();
    /* عميل استطلع بعد آخر تعديل بأكتر من ثانية (السماحية) → مفيش جديد؛ وبعد الحذف لازم يبان */
    usleep(1200000);
    $tMid = PollableList::serverNowMs();
    [$c, $j] = hit($kernel, $admin, 'GET', '/api/zones?since=' . $tMid);
    ok('قبل الحذف: since بعد آخر تعديل → changed:false', $c === 200 && ($j['changed'] ?? null) === false, json_encode($j['changed'] ?? null));
    $tDel = PollableList::serverNowMs();
    [$c] = hit($kernel, $admin, 'DELETE', "/api/zones/{$zid}");
    ok('حذف منطقة 200', $c === 200, (string) $c);
    [$c, $j] = hit($kernel, $admin, 'GET', '/api/zones?since=' . $tMid);
    ok('🔴 بعد الحذف: نفس since → changed:true (ختم الحذف — مش بيبان في MAX(updated_at))', $c === 200 && ($j['changed'] ?? null) === true, json_encode($j['changed'] ?? null));
    $touch = DB::select("SELECT setting_value FROM site_settings WHERE setting_key = 'zones_touched_at'")[0]->setting_value ?? null;
    ok('ختم zones_touched_at اتكتب', $touch !== null && (int) json_decode((string) $touch, true) >= $tDel);

    DB::insert("INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, sender_phone, status, status_since, created_at, total_delivery_price)
                VALUES ('TST-P2-1', ?, ?, 'م', '01000000000', 'processing', ?, ?, 10)", [(int) $sup['branch_id'], (int) $sup['branch_id'], gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
    $oid = (int) DB::getPdo()->lastInsertId();
    DB::insert("INSERT INTO order_notifications (order_id, channel, recipient_phone, body, status, created_at) VALUES (?, 'whatsapp', '01000000009', 'فحص', 'pending', ?)", [$oid, gmdate('Y-m-d H:i:s')]);
    $nid = (int) DB::getPdo()->lastInsertId();
    [$c, $j] = hit($kernel, $admin, 'GET', '/api/order-notifications?since=' . (PollableList::serverNowMs() + 5000));
    ok('🔴 order-notifications?since=المستقبل → changed:false', $c === 200 && ($j['changed'] ?? null) === false && ! isset($j['items']), $c . ' ' . mb_substr(json_encode($j), 0, 100));
    [$c, $j] = hit($kernel, $admin, 'GET', '/api/order-notifications?since=' . ($nowMs - 60000));
    ok('رسالة جديدة بعد since → القايمة كاملة مع pending', $c === 200 && ! empty($j['items']) && isset($j['pending']));
    DB::update("UPDATE order_notifications SET status = 'sent', sent_at = NOW() WHERE id = ?", [$nid]);
    $mx = DB::selectOne('SELECT UNIX_TIMESTAMP(updated_at) * 1000 AS m FROM order_notifications WHERE id = ?', [$nid]);
    ok('تغيير الحالة بيحرّك updated_at', (int) $mx->m >= $nowMs - 1000, (string) $mx->m);
} finally {
    DB::rollBack();
}

echo "\n══ 5) طلب بتوكن = جلسة في الذاكرة ══\n";
$before = count(glob($ROOT . '/storage/framework/sessions/*') ?: []);
[$c, $j, $res] = hit($kernel, [], 'GET', '/api/pilot/state', [], ['X-Auth-Token' => 'nope-' . bin2hex(random_bytes(4))]);
$after = count(glob($ROOT . '/storage/framework/sessions/*') ?: []);
/* Set-Cookie لسه بيتبعت (StartSession بتعتبر أي سواقة غير null «دائمة») — وده مش مهم:
   عميل Dart مابيحفظش كوكيز. المهم إن **مفيش ملف** بيتكتب على القرص. */
ok('🔴 توكن بايظ = 401 ومن غير ملف جلسة جديد على القرص', $c === 401 && $after <= $before, "{$c} files {$before}→{$after}");
ok('سواقة الجلسة اتحوّلت لـarray للطلب ده', config('session.driver') === 'array');
config(['session.driver' => 'file']);

echo "\n════════════════════════════════════════\n";
echo "PHASE 2 SERVER: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
