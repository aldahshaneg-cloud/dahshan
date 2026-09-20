<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════
   📡 مناورة البث الفوري: الحدث بيوصل للمشترك بعد كام ملّي ثانية؟ (2026-09-21)

   بلاغ صاحب النظام: «الكول سنتر بيعمل الأوردر وبعدها في الفرع بنص دقيقة أو دقيقة على ما يوصل».
   السكربت بيفتح ويبسوكت حقيقي على Reverb (127.0.0.1:8080)، يشترك في قناة فرع خاصة
   (توقيع HMAC بالسر من الإعدادات — السر مابيتطبعش)، يطلق OrderChanged لأوردر موجود
   **بنفس مسار الكنترولر** (event → طابور → عامل → Reverb)، ويقيس زمن الوصول.
   قراءة بس: مابيعدّلش أي صف.

   التشغيل: php ops/drill_realtime_latency.php [orderId] [عدد المرات=3]
═══════════════════════════════════════════════════════════════ */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Events\OrderChanged;
use Illuminate\Support\Facades\DB;

$orderId = (int) ($argv[1] ?? 0);
$rounds  = max(1, (int) ($argv[2] ?? 3));
$row = $orderId
    ? DB::selectOne('SELECT * FROM orders WHERE id = ?', [$orderId])
    : DB::selectOne("SELECT * FROM orders WHERE order_num LIKE 'TST%' ORDER BY id DESC LIMIT 1");
if (! $row) { fwrite(STDERR, "مفيش أوردر للتجربة\n"); exit(1); }

$cfg = config('broadcasting.connections.reverb');
$key = (string) $cfg['key'];
$secret = (string) $cfg['secret'];
$host = (string) (env('REVERB_SERVER_HOST') ?: '127.0.0.1');
$port = (int) (env('REVERB_SERVER_PORT') ?: 8080);
$channel = 'private-branch.' . (int) $row->branch_id;

$fp = @stream_socket_client("tcp://{$host}:{$port}", $en, $es, 5);
if (! $fp) { fwrite(STDERR, "✗ Reverb مش بيرد على {$host}:{$port} — {$es}\n"); exit(1); }
$wsKey = base64_encode(random_bytes(16));
fwrite($fp, "GET /app/{$key}?protocol=7&client=drill&version=1.0 HTTP/1.1\r\nHost: {$host}:{$port}\r\nOrigin: https://aldahshan.cloud\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$wsKey}\r\nSec-WebSocket-Version: 13\r\n\r\n");
$hdr = '';
while (! str_contains($hdr, "\r\n\r\n")) { $c = fread($fp, 1); if ($c === '' || $c === false) { break; } $hdr .= $c; }
if (! str_contains($hdr, ' 101 ')) { fwrite(STDERR, "✗ المصافحة فشلت: " . strtok($hdr, "\r\n") . "\n"); exit(1); }

$send = function (string $payload) use ($fp): void {
    $len = strlen($payload); $mask = random_bytes(4);
    $head = "\x81" . ($len < 126 ? chr(0x80 | $len) : chr(0x80 | 126) . pack('n', $len)) . $mask;
    $out = ''; for ($i = 0; $i < $len; $i++) { $out .= $payload[$i] ^ $mask[$i % 4]; }
    fwrite($fp, $head . $out);
};
$recv = function (float $timeout) use ($fp, $send): ?array {
    $end = microtime(true) + $timeout;
    while (microtime(true) < $end) {
        $r = [$fp]; $w = $e = null;
        if (! stream_select($r, $w, $e, 0, 200000)) { continue; }
        $h = fread($fp, 2); if ($h === '' || $h === false || strlen($h) < 2) { return null; }
        $op = ord($h[0]) & 0x0F; $len = ord($h[1]) & 0x7F;
        if ($len === 126) { $len = unpack('n', fread($fp, 2))[1]; } elseif ($len === 127) { $len = (int) unpack('J', fread($fp, 8))[1]; }
        $data = ''; while (strlen($data) < $len) { $chunk = fread($fp, $len - strlen($data)); if ($chunk === '' || $chunk === false) { break; } $data .= $chunk; }
        if ($op === 0x9) { continue; }
        if ($op !== 0x1) { continue; }
        $j = json_decode($data, true);
        if (($j['event'] ?? '') === 'pusher:ping') { $send(json_encode(['event' => 'pusher:pong', 'data' => new stdClass()])); continue; }

        return $j;
    }

    return null;
};

$hello = $recv(5);
$socketId = json_decode((string) ($hello['data'] ?? '{}'), true)['socket_id'] ?? null;
if (! $socketId) { fwrite(STDERR, "✗ مفيش socket_id\n"); exit(1); }
$auth = $key . ':' . hash_hmac('sha256', $socketId . ':' . $channel, $secret);
$send(json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, 'auth' => $auth]]));
$sub = $recv(5);
echo "القناة {$channel} · الاشتراك: " . ($sub['event'] ?? 'مفيش رد') . "\n";
if (($sub['event'] ?? '') !== 'pusher_internal:subscription_succeeded') { exit(1); }

$lat = [];
for ($i = 1; $i <= $rounds; $i++) {
    $t0 = microtime(true);
    event(OrderChanged::fromRow($row));
    $got = null;
    while (($m = $recv(30)) !== null) { if (($m['event'] ?? '') === 'order.changed') { $got = $m; break; } }
    $ms = (int) round((microtime(true) - $t0) * 1000);
    if ($got) { $lat[] = $ms; echo "  جولة {$i}: وصل بعد {$ms} ملّي ثانية\n"; }
    else { echo "  جولة {$i}: ✗ موصلش خلال 30 ثانية\n"; }
    usleep(1500000);
}
fclose($fp);
if ($lat) { echo 'المتوسط: ' . (int) round(array_sum($lat) / count($lat)) . ' ملّي ثانية · الأقصى: ' . max($lat) . "\n"; }
exit(count($lat) === $rounds ? 0 : 1);
