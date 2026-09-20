<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════
   🧪 تجربة حقيقية (مش بتترجع): تحميل أوردر على طيار من فرع تاني — بعد إصلاح
   «api is not defined» في خريطة لوحة الفرع (2026-09-20). صاحب النظام طلب:
   «وانت كمان جرب وسيب اللي انت جربته علشان أشوفه».

   بحساب مشرف «فرع تجريبي ١» (test_branch1):
     1) أوردر تجريبي → بيتحمّل على «طيار تجريبي ٢» (فرع تجريبي ٢) بنفس مسار زرار الخريطة
        POST /api/orders/assign-bulk — فبيتنقل لفرع ٢ ويبان عند الطيار.
     2) أوردر تجريبي تاني بيفضل **من غير طيار** في فرع ١ عشان صاحب النظام يجرّب الزرار بنفسه.
   لازم ops/seed_test_branches.php --apply يكون اتشغّل الأول.
   التشغيل: php ops/trial_cross_branch_load.php
═══════════════════════════════════════════════════════════════ */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
function hit($kernel, $u, string $method, string $url, array $body = []): array
{
    $req = Request::create($url, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $body ? json_encode($body, JSON_UNESCAPED_UNICODE) : null);
    $s = app('session')->driver();
    $s->flush(); $s->start();
    $s->put(['user_id' => (int) $u->id, 'username' => $u->username, 'role' => $u->role,
             'branch_id' => $u->branch_id !== null ? (int) $u->branch_id : null, 'name' => $u->username]);
    $req->setLaravelSession($s);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$sup = DB::selectOne("SELECT id, username, role, branch_id FROM users WHERE username = 'test_branch1'");
$zone = $sup ? DB::selectOne('SELECT id, price FROM zones WHERE delivery_branch_id = ? ORDER BY id LIMIT 1', [(int) $sup->branch_id]) : null;
$p2 = DB::selectOne("SELECT id, name, assigned_branch_id FROM pilots WHERE name = 'طيار تجريبي ٢'");
if (! $sup || ! $zone || ! $p2) { fwrite(STDERR, "شغّل ops/seed_test_branches.php --apply الأول\n"); exit(1); }

$mk = function (string $recv) use ($kernel, $sup, $zone): array {
    [$c, $j] = hit($kernel, $sup, 'POST', '/api/orders', [
        'senderName' => 'مرسل تجريبي', 'senderPhone' => '01000000009', 'senderAddress' => 'شارع التجربة',
        'notes' => '🧪 أوردر تجربة — لا يُنفَّذ',
        'deliveries' => [['parcelNo' => 1, 'receiverName' => $recv, 'receiverPhone' => '01000000010',
            'zoneId' => (int) $zone->id, 'zonePrice' => (float) $zone->price, 'orderPrice' => 0, 'address' => 'عنوان تجريبي']],
    ]);
    $o = $j['orders'][0] ?? null;
    if ($c !== 200 || ! $o) { fwrite(STDERR, "✗ إنشاء الأوردر: {$c} " . json_encode($j, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }

    return $o;
};

$o1 = $mk('مستلم تجريبي ١ (اتحمّل على طيار الفرع التاني)');
echo "✓ أوردر {$o1['orderNum']} (#{$o1['id']}) في فرع #{$o1['branchId']}\n";
[$c, $j] = hit($kernel, $sup, 'POST', '/api/orders/assign-bulk', ['orderIds' => [$o1['id']], 'pilotId' => (int) $p2->id]);
echo "assign-bulk → {$c} " . json_encode($j, JSON_UNESCAPED_UNICODE) . "\n";
$row = DB::selectOne('SELECT o.order_num, o.branch_id, o.origin_branch_id, o.status, p.name AS pilot, b.name AS branch FROM orders o LEFT JOIN pilots p ON p.id = o.pilot_id JOIN branches b ON b.id = o.branch_id WHERE o.id = ?', [$o1['id']]);
echo '  النتيجة: ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";

$o2 = $mk('مستلم تجريبي ٢ (جرّب تحمّله بنفسك من الخريطة)');
echo "✓ أوردر {$o2['orderNum']} (#{$o2['id']}) سايبه من غير طيار في فرع تجريبي ١\n";
