<?php
/**
 * 📞 حارس: تنظيف أرقام التليفون المنسوخة قبل التخزين (بلاغ بوابة المحلات 2026-09-16).
 *
 * الواقعة: محل حاول 3 مرات (11:51 → 12:04) يبعت أوردر والسيرفر رفض
 * «اسم جهة التسليم أطول من المسموح (190 حرف)» — الرسالة الملزوقة كلها راحت في
 * خانة الاسم، والمحل فهمها «الرقم فيه مسافة». والأرقام المنسوخة أصلًا كانت
 * بتتخزّن بمسافات وعلامات اتجاه مخفية (‎ ⁦) وأرقام عربية (٠١٠…) — 12+ صف
 * في order_deliveries — فالبحث ومفتاح الثقة والاتصال من التطبيق بيفشلوا.
 *
 * ═══ العقود ═══
 * • OrdersController::cleanPhone: مسافات/شرط/أقواس/علامات اتجاه/أرقام عربية →
 *   أرقام لاتينية بس، +20/0020/20 → 0، الـ+ بتفضل لرقم أجنبي بس.
 * • POST /api/orders (محل) بيخزّن الرقم نظيف، ورقم طويل بالمسافات مابيترفضش.
 * • PUT /api/orders/{id} نفس التنظيف للمستلم والمرسل.
 * • الاسم > 190 = 400 بالرسالة الواضحة (السيرفر) — والبوابة بتقولها قبل الإرسال.
 * • store.html: cleanPhone معرّفة وبتتطبّق على هاتف المستلم/المرسل قبل الإرسال.
 * التشغيل: php ops/test_phone_normalize.php
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

use App\Http\Controllers\Api\OrdersController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

echo "══ 0) cleanPhone ══\n";
$cases = [
    ['010 1234 5678', '01012345678'],
    ["\u{202A}+20 100 123 4567\u{202C}", '01001234567'],
    ["\u{2066}0 10 26655806\u{2069}", '01026655806'],
    ["\u{200E}01062219329", '01062219329'],
    ['٠١٠٣٢٨٨٨٠١٤', '01032888014'],
    ['0020 101 234 5678', '01012345678'],
    ['201012345678', '01012345678'],
    ['0100-123-4567', '01001234567'],
    ['(055) 224 7525', '0552247525'],
    ['+44 7911 123456', '+447911123456'],
    ['', ''],
    ['abc', ''],
];
foreach ($cases as [$in, $want]) {
    $got = OrdersController::cleanPhone($in);
    ok(json_encode($in, JSON_UNESCAPED_UNICODE) . ' → ' . $want, $got === $want, $got);
}

echo "\n══ 1) البوابة ══\n";
$S = file_get_contents($ROOT . '/public/store.html');
ok('window.cleanPhone معرّفة', str_contains($S, 'window.cleanPhone = function (raw) {'));
ok('هاتف المستلم وهاتف 2 بيتنضّفوا قبل الإرسال', str_contains($S, 'const phone   = window.cleanPhone(document.getElementById(`rPhone-${n}`)?.value);')
    && str_contains($S, 'const phone2  = window.cleanPhone(document.getElementById(`rPhone2-${n}`)?.value);'));
ok('هاتف المُرسِل (مكان آخر) بيتنضّف', str_contains($S, 'phone : window.cleanPhone(document.getElementById("senderPhoneIn")?.value),'));
ok('الاسم الطويل بيتقال بوضوح قبل الإرسال', str_contains($S, 'if (name.length > 190) { _jumpToParcel(n, `rName-${n}`, `اسم المستلِم طويل جدًا'));

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
function hit($kernel, array $u, string $method, string $url, array $body = []): array
{
    $req = Request::create($url, $method, [], [], [], [], $body ? json_encode($body) : null);
    $req->headers->set('Accept', 'application/json');
    if ($body) { $req->headers->set('Content-Type', 'application/json'); }
    $ses = app('session')->driver();
    $ses->start();
    $ses->put(['user_id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role'],
               'branch_id' => $u['branch_id'] ?? null, 'name' => $u['username']]);
    $req->setLaravelSession($ses);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$st = (array) (DB::select("SELECT id, username, role, shop_zone_id, shop_name, shop_phone FROM users WHERE role = 'store' AND blocked = 0 AND shop_zone_id IS NOT NULL ORDER BY id LIMIT 1")[0] ?? null);
$sup = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'branch' AND blocked = 0 AND branch_id IS NOT NULL ORDER BY id LIMIT 1")[0] ?? null);
$zone = DB::selectOne('SELECT id, price FROM zones WHERE price > 0 LIMIT 1');
if (! $st || ! $sup || ! $zone) { echo "مافيش محل/مشرف/زون — تخطّي\n"; exit(0); }

$body = fn (string $phone, string $name = 'مستلم فحص') => [
    'senderName' => $st['shop_name'] ?: 'محل فحص', 'senderPhone' => $st['shop_phone'] ?: '01000000000', 'senderAddress' => 'شارع الفحص',
    'senderZoneId' => (int) $st['shop_zone_id'], 'source' => 'store',
    'deliveries' => [[
        'parcelNo' => 1, 'receiverName' => $name, 'receiverPhone' => $phone, 'receiverPhone2' => ' 011 2345 6789 ',
        'zoneId' => (int) $zone->id, 'zonePrice' => (float) $zone->price, 'orderPrice' => 0, 'address' => 'عنوان',
    ]],
];

DB::beginTransaction();
try {
    echo "\n══ 2) POST /api/orders من المحل برقم منسوخ ══\n";
    [$c, $j] = hit($kernel, $st, 'POST', '/api/orders', $body("\u{202A}+20 100 123 4567\u{202C} "));
    $o = $j['orders'][0] ?? $j['order'] ?? null;
    ok('200', $c === 200 && $o, $c . ' ' . mb_substr(json_encode($j, JSON_UNESCAPED_UNICODE), 0, 160));
    $oid = (int) ($o['id'] ?? 0);
    $d = DB::selectOne('SELECT id, receiver_phone, receiver_phone2 FROM order_deliveries WHERE order_id = ? ORDER BY parcel_no LIMIT 1', [$oid]);
    ok('🔴 الرقم اتخزّن نظيف 01001234567', $d && $d->receiver_phone === '01001234567', (string) ($d->receiver_phone ?? '—'));
    ok('  وهاتف 2 كمان 01123456789', $d && $d->receiver_phone2 === '01123456789', (string) ($d->receiver_phone2 ?? '—'));

    echo "\n══ 3) رقم بمسافات أطول من 20 حرف = مقبول بعد التنظيف ══\n";
    [$c, $j] = hit($kernel, $st, 'POST', '/api/orders', $body('0 1 0 1 2 3 4 5 6 7 8 - - - '));
    $o2 = $j['orders'][0] ?? $j['order'] ?? null;
    $d2 = $o2 ? DB::selectOne('SELECT receiver_phone FROM order_deliveries WHERE order_id = ? LIMIT 1', [(int) $o2['id']]) : null;
    ok('200 و01012345678', $c === 200 && $d2 && $d2->receiver_phone === '01012345678', $c . ' ' . ($d2->receiver_phone ?? ($j['error'] ?? '')));

    echo "\n══ 4) أرقام عربية ══\n";
    [$c, $j] = hit($kernel, $st, 'POST', '/api/orders', $body('٠١٠٣٢٨٨٨٠١٤'));
    $o3 = $j['orders'][0] ?? $j['order'] ?? null;
    $d3 = $o3 ? DB::selectOne('SELECT receiver_phone FROM order_deliveries WHERE order_id = ? LIMIT 1', [(int) $o3['id']]) : null;
    ok('200 و01032888014', $c === 200 && $d3 && $d3->receiver_phone === '01032888014', $c . ' ' . ($d3->receiver_phone ?? ($j['error'] ?? '')));

    echo "\n══ 5) الاسم أطول من 190 = 400 برسالة واضحة ══\n";
    [$c, $j] = hit($kernel, $st, 'POST', '/api/orders', $body('01012345678', str_repeat('عمرو ', 45)));
    ok('400 «اسم جهة التسليم أطول من المسموح»', $c === 400 && str_contains((string) ($j['error'] ?? ''), 'اسم جهة التسليم أطول من المسموح'), $c . ' ' . ($j['error'] ?? ''));

    echo "\n══ 6) PUT /api/orders/{id} (مشرف) بينضّف كمان ══\n";
    [$c] = hit($kernel, $sup, 'PUT', "/api/orders/{$oid}", [
        'senderName' => 'محل فحص', 'senderPhone' => '٠١٠ ٠٠٠٠ ٠٠٠٩', 'senderAddress' => 'شارع الفحص',
        'deliveries' => [['id' => $d->id, 'receiverName' => 'مستلم فحص', 'receiverPhone' => "\u{200E}010 6221 9329", 'address' => 'عنوان']],
    ]);
    $row = DB::selectOne('SELECT o.sender_phone, d.receiver_phone FROM orders o JOIN order_deliveries d ON d.order_id = o.id WHERE o.id = ? LIMIT 1', [$oid]);
    ok('200 والمستلم 01062219329 والمرسل 01000000009', $c === 200 && $row && $row->receiver_phone === '01062219329' && $row->sender_phone === '01000000009',
        $c . ' ' . ($row->receiver_phone ?? '—') . ' / ' . ($row->sender_phone ?? '—'));
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "PHONE NORMALIZE: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
