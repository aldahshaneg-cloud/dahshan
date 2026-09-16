<?php
/**
 * 💵 حارس: تعديل عهدة الطرد بعد إنشاء الأوردر (بلاغ 2026-09-16).
 *
 * «لو العميل قال السعر أعلى من العهدة اللي مع الطيار وضفتها في خانة المبلغ المدفوع مش
 *  بتسمع في السيستم ولا مع المندوب». التعديل كان بيتجاهل عهدة الطرد.
 *
 * ═══ العقود (تنفيذ حقيقي جوه معاملة بتترجع) ═══
 * • PUT /api/orders/{id} بـdeliveries[].orderPrice → order_price بيتكتب وstore_prepaid = المجموع،
 *   وstorePrepaidNote بتتكتب، وupdated_at اتلمس (التطبيق بياخدها بالدلتا).
 * • من غير مفتاح orderPrice (واجهة قديمة) = العهدة زي ما هي.
 * • سالب = 400. بعد التسليم/تسوية الفلوس = 409 ومفيش تغيير.
 * • مودال التعديل في الفرع والإدارة فيه الحقل وبيبعته.
 * التشغيل: php ops/test_prepaid_edit.php
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
$near = fn ($a, $b) => abs((float) $a - (float) $b) < 0.011;

echo "══ 0) الواجهات ══\n";
foreach (['branch', 'tiar'] as $p) {
    $H = file_get_contents($ROOT . "/public/{$p}.html");
    ok("{$p}: حقل عهدة الطرد في مودال التعديل وبيتبعت كـorderPrice", str_contains($H, 'id="edit-recv-price-${i}"')
        && str_contains($H, 'orderPrice: Number(document.getElementById("edit-recv-price-"+i)?.value) || 0')
        && str_contains($H, 'storePrepaidNote: (document.getElementById("edit-prepaid-note")?.value || "").trim()'));
    ok("  ومقفول بعد التسليم/التسوية", str_contains($H, 'function _editPrepaidLocked(o) { return !!(o && (o.status === "تم التسليم" || o.moneySettled)); }'));
}

$sup = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'branch' AND blocked = 0 AND branch_id IS NOT NULL ORDER BY id LIMIT 1")[0] ?? null);
$zone = DB::selectOne('SELECT id, area_name, price FROM zones WHERE price > 0 LIMIT 1');
if (! $sup || ! $zone) { echo "مافيش مشرف/زون — تخطّي\n"; exit(0); }

DB::beginTransaction();
try {
    echo "\n══ 1) أوردر بطرد عهدته 100 (الإنشاء بيفرّق كل طرد في أوردر لوحده) ══\n";
    [$c, $j] = hit($kernel, $sup, 'POST', '/api/orders', [
        'senderName' => 'محل فحص العهدة', 'senderPhone' => '01000000009', 'senderAddress' => 'شارع الفحص',
        'deliveries' => [
            ['parcelNo' => 1, 'receiverName' => 'مستلم ١', 'receiverPhone' => '01000000010', 'zoneId' => (int) $zone->id, 'zonePrice' => (float) $zone->price, 'orderPrice' => 100, 'address' => 'أ'],
        ],
    ]);
    $o = $j['orders'][0] ?? $j['order'] ?? null;
    ok('الإنشاء 200', $c === 200 && $o, $c . ' ' . mb_substr(json_encode($j, JSON_UNESCAPED_UNICODE), 0, 160));
    $oid = (int) ($o['id'] ?? 0);
    $dels = array_map(fn ($d) => (array) $d, DB::select('SELECT id, order_price FROM order_deliveries WHERE order_id = ? ORDER BY parcel_no', [$oid]));
    ok('store_prepaid = 100', count($dels) === 1 && $near(DB::selectOne('SELECT store_prepaid FROM orders WHERE id = ?', [$oid])->store_prepaid, 100), (string) count($dels));
    $u0 = DB::selectOne('SELECT updated_at FROM orders WHERE id = ?', [$oid])->updated_at;

    echo "\n══ 2) العميل قال السعر 150 → تعديل عهدة الطرد ══\n";
    usleep(20000);
    [$c, $j] = hit($kernel, $sup, 'PUT', "/api/orders/{$oid}", [
        'senderName' => 'محل فحص العهدة', 'senderPhone' => '01000000009', 'senderAddress' => 'شارع الفحص',
        'storePrepaidNote' => 'العميل هيدفع الفرق',
        'deliveries' => [
            ['id' => $dels[0]['id'], 'receiverName' => 'مستلم ١', 'receiverPhone' => '01000000010', 'address' => 'أ', 'orderPrice' => 150],
        ],
    ]);
    ok('التعديل 200', $c === 200, $c . ' ' . mb_substr(json_encode($j, JSON_UNESCAPED_UNICODE), 0, 160));
    $row = DB::selectOne('SELECT store_prepaid, store_prepaid_note, updated_at FROM orders WHERE id = ?', [$oid]);
    ok('🔴 عهدة الطرد اتكتبت (150)', $near(DB::selectOne('SELECT order_price FROM order_deliveries WHERE id = ?', [$dels[0]['id']])->order_price, 150));
    ok('🔴 store_prepaid = 150 (اللي التطبيق بيعرضه للطيار) والملاحظة اتكتبت', $near($row->store_prepaid, 150) && $row->store_prepaid_note === 'العميل هيدفع الفرق', $row->store_prepaid . ' / ' . $row->store_prepaid_note);
    ok('  وupdated_at اتلمس (الدلتا للتطبيق)', $row->updated_at > $u0, $u0 . ' → ' . $row->updated_at);
    ok('  والرد بيرجّع storePrepaid = 150', $near($j['order']['storePrepaid'] ?? -1, 150), json_encode(array_keys($j ?? [])));

    echo "\n══ 3) واجهة قديمة من غير orderPrice = العهدة زي ما هي ══\n";
    [$c] = hit($kernel, $sup, 'PUT', "/api/orders/{$oid}", [
        'senderName' => 'محل فحص العهدة', 'senderPhone' => '01000000009', 'senderAddress' => 'شارع الفحص',
        'deliveries' => [['id' => $dels[0]['id'], 'receiverName' => 'مستلم ١', 'receiverPhone' => '01000000010', 'address' => 'أ']],
    ]);
    ok('200 وstore_prepaid لسه 150', $c === 200 && $near(DB::selectOne('SELECT store_prepaid FROM orders WHERE id = ?', [$oid])->store_prepaid, 150));

    echo "\n══ 4) سالب = 400 · بعد التسليم = 409 ══\n";
    [$c] = hit($kernel, $sup, 'PUT', "/api/orders/{$oid}", ['deliveries' => [['id' => $dels[0]['id'], 'receiverName' => 'مستلم ١', 'receiverPhone' => '01000000010', 'address' => 'أ', 'orderPrice' => -5]]]);
    ok('سالب = 400', $c === 400, (string) $c);
    DB::update("UPDATE orders SET status = 'delivered', money_settled = 1 WHERE id = ?", [$oid]);
    [$c, $j] = hit($kernel, $sup, 'PUT', "/api/orders/{$oid}", ['deliveries' => [['id' => $dels[0]['id'], 'receiverName' => 'مستلم ١', 'receiverPhone' => '01000000010', 'address' => 'أ', 'orderPrice' => 999]]]);
    ok('بعد التسليم والتسوية = 409 والعهدة زي ما هي', $c === 409 && $near(DB::selectOne('SELECT store_prepaid FROM orders WHERE id = ?', [$oid])->store_prepaid, 150), $c . ' ' . ($j['error'] ?? ''));
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "PREPAID EDIT: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
