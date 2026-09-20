<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════
   📡 مناورة كاملة: كول سنتر بيعمل أوردر → لوحة الفرع بتسمع بعد كام ملّي ثانية؟ (2026-09-21)

   بنفس مسار اللوحة بالظبط:
     1) تفويض القناة من POST /broadcasting/auth **بجلسة ويب لمشرف الفرع** (مش توقيع يدوي).
     2) ويبسوكت حقيقي على Reverb + اشتراك private-branch.{فرع المشرف}.
     3) حساب كول سنتر بيعمل أوردر حقيقي للفرع من POST /api/orders.
     4) قياس وصول order.changed.
   ⚠️ بيسيب أوردر تجربة حقيقي في الفرع التجريبي (ملاحظته «🧪 مناورة البث»).
   التشغيل: php ops/drill_callcenter_to_branch.php [supervisorUsername=test_branch1]
═══════════════════════════════════════════════════════════════ */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$supName = (string) ($argv[1] ?? 'test_branch1');
$sup = DB::selectOne('SELECT id, username, role, branch_id FROM users WHERE username = ?', [$supName]);
$cc  = DB::selectOne("SELECT id, username, role, branch_id FROM users WHERE role = 'callcenter' AND blocked = 0 ORDER BY id LIMIT 1");
$zone = $sup ? DB::selectOne('SELECT id, price FROM zones WHERE delivery_branch_id = ? ORDER BY id LIMIT 1', [(int) $sup->branch_id]) : null;
if (! $sup || ! $cc || ! $zone) { fwrite(STDERR, "ناقص مشرف/كول سنتر/منطقة\n"); exit(1); }
$channel = 'private-branch.' . (int) $sup->branch_id;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$hit = function ($u, string $method, string $url, array $body = [], bool $form = false) use ($kernel): array {
    $req = $form
        ? Request::create($url, $method, $body, [], [], ['HTTP_ACCEPT' => 'application/json'])
        : Request::create($url, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body ? json_encode($body, JSON_UNESCAPED_UNICODE) : null);
    $s = app('session')->driver(); $s->flush(); $s->start();
    $s->put(['user_id' => (int) $u->id, 'username' => $u->username, 'role' => $u->role, 'branch_id' => $u->branch_id !== null ? (int) $u->branch_id : null, 'name' => $u->username]);
    $req->setLaravelSession($s);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
};

$key = (string) config('broadcasting.connections.reverb.key');
$host = (string) (env('REVERB_SERVER_HOST') ?: '127.0.0.1');
$port = (int) (env('REVERB_SERVER_PORT') ?: 8080);
$fp = @stream_socket_client("tcp://{$host}:{$port}", $en, $es, 5);
if (! $fp) { fwrite(STDERR, "✗ Reverb مش بيرد\n"); exit(1); }
$wsKey = base64_encode(random_bytes(16));
fwrite($fp, "GET /app/{$key}?protocol=7&client=drill&version=1.0 HTTP/1.1\r\nHost: {$host}:{$port}\r\nOrigin: https://branch.aldahshan.cloud\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$wsKey}\r\nSec-WebSocket-Version: 13\r\n\r\n");
$hdr = '';
while (! str_contains($hdr, "\r\n\r\n")) { $c = fread($fp, 1); if ($c === '' || $c === false) { break; } $hdr .= $c; }
$send = function (string $p) use ($fp): void {
    $len = strlen($p); $mask = random_bytes(4);
    $head = "\x81" . ($len < 126 ? chr(0x80 | $len) : chr(0x80 | 126) . pack('n', $len)) . $mask;
    $out = ''; for ($i = 0; $i < $len; $i++) { $out .= $p[$i] ^ $mask[$i % 4]; }
    fwrite($fp, $head . $out);
};
$recv = function (float $timeout) use ($fp, $send): ?array {
    $end = microtime(true) + $timeout;
    while (microtime(true) < $end) {
        $r = [$fp]; $w = $e = null;
        if (! stream_select($r, $w, $e, 0, 100000)) { continue; }
        $h = fread($fp, 2); if ($h === '' || $h === false || strlen($h) < 2) { return null; }
        $op = ord($h[0]) & 0x0F; $len = ord($h[1]) & 0x7F;
        if ($len === 126) { $len = unpack('n', fread($fp, 2))[1]; } elseif ($len === 127) { $len = (int) unpack('J', fread($fp, 8))[1]; }
        $data = ''; while (strlen($data) < $len) { $chunk = fread($fp, $len - strlen($data)); if ($chunk === '' || $chunk === false) { break; } $data .= $chunk; }
        if ($op !== 0x1) { continue; }
        $j = json_decode($data, true);
        if (($j['event'] ?? '') === 'pusher:ping') { $send(json_encode(['event' => 'pusher:pong', 'data' => new stdClass()])); continue; }

        return $j;
    }

    return null;
};
$hello = $recv(5);
$socketId = json_decode((string) ($hello['data'] ?? '{}'), true)['socket_id'] ?? null;
if (! $socketId) { fwrite(STDERR, '✗ مفيش socket_id: ' . json_encode($hello) . "\n"); exit(1); }

[$c, $j] = $hit($sup, 'POST', '/broadcasting/auth', ['socket_id' => $socketId, 'channel_name' => $channel], true);
echo "1) تفويض القناة بجلسة المشرف {$sup->username}: {$c}\n";
if ($c !== 200 || empty($j['auth'])) { fwrite(STDERR, '✗ ' . json_encode($j, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
$send(json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, 'auth' => $j['auth']]]));
$sub = $recv(5);
echo "2) الاشتراك في {$channel}: " . ($sub['event'] ?? '—') . "\n";

$t0 = microtime(true);
[$c, $j] = $hit($cc, 'POST', '/api/orders', [
    'branchId' => (int) $sup->branch_id, 'source' => 'callcenter',
    'senderName' => 'مرسل مناورة البث', 'senderPhone' => '01000000009', 'senderAddress' => 'شارع التجربة', 'notes' => '🧪 مناورة البث',
    'deliveries' => [['parcelNo' => 1, 'receiverName' => 'مستلم مناورة البث', 'receiverPhone' => '01000000010', 'zoneId' => (int) $zone->id, 'zonePrice' => (float) $zone->price, 'orderPrice' => 0, 'address' => 'عنوان تجريبي']],
]);
$tCreate = (int) round((microtime(true) - $t0) * 1000);
$o = $j['orders'][0] ?? null;
echo "3) الكول سنتر ({$cc->username}) عمل الأوردر: {$c} " . ($o['orderNum'] ?? json_encode($j, JSON_UNESCAPED_UNICODE)) . " (الحفظ خد {$tCreate} ملّي ثانية)\n";
if ($c !== 200 || ! $o) { exit(1); }
$got = null;
while (($m = $recv(30)) !== null) { if (($m['event'] ?? '') === 'order.changed') { $got = $m; break; } }
$ms = (int) round((microtime(true) - $t0) * 1000);
if (! $got) { echo "4) ✗ الحدث موصلش خلال 30 ثانية\n"; exit(1); }
$pl = json_decode((string) $got['data'], true);
echo "4) ✅ order.changed وصل للمشترك بعد {$ms} ملّي ثانية من ضغطة الحفظ — " . ($pl['orderNum'] ?? '') . ' · ' . ($pl['status'] ?? '') . "\n";

/* 5) (اختياري: الوسيطة التانية cross) المشرف بيحمّل نفس الأوردر على طيار **فرع تاني** — الفرع القديم
      لازم يوصله حدث بـbranchId الجديد عشان لوحته تشيل الأوردر فورًا (بلاغ 2026-09-21). */
if (($argv[2] ?? '') === 'cross') {
    $other = DB::selectOne("SELECT p.id, p.name, p.assigned_branch_id FROM pilots p JOIN shifts s ON s.pilot_id = p.id AND s.ended_at IS NULL WHERE p.assigned_branch_id <> ? AND p.name LIKE 'طيار تجريبي%' ORDER BY p.id LIMIT 1", [(int) $sup->branch_id]);
    if (! $other) { echo "5) مفيش طيار تجريبي في فرع تاني
"; exit(1); }
    $t1 = microtime(true);
    [$c, $j] = $hit($sup, 'POST', '/api/orders/assign-bulk', ['orderIds' => [$o['id']], 'pilotId' => (int) $other->id]);
    echo "5) المشرف حمّل الأوردر على {$other->name} (فرع #{$other->assigned_branch_id}): {$c} movedBranch=" . json_encode($j['movedBranch'] ?? null) . "
";
    $left = null;
    while (($m = $recv(30)) !== null) { if (($m['event'] ?? '') !== 'order.changed') { continue; } $pl = json_decode((string) $m['data'], true); if ((int) ($pl['id'] ?? 0) === (int) $o['id'] && (int) ($pl['branchId'] ?? 0) !== (int) $sup->branch_id) { $left = $pl; break; } }
    $ms = (int) round((microtime(true) - $t1) * 1000);
    echo $left ? "6) ✅ قناة الفرع القديم {$channel} وصلها «الأوردر خرج» بعد {$ms} ملّي ثانية — branchId الجديد = {$left['branchId']}
" : "6) ✗ الفرع القديم موصلوش حاجة خلال 30 ثانية
";
    if (! $left) { exit(1); }
}
fclose($fp);
