<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════
   🧪 فرعين تجريبيين لتجربة «تحميل أوردر على طيار من فرع تاني من الخريطة»
   (طلب صاحب النظام 2026-09-20): «اعمل فرعين تجريبيين وكل فرع فيه طيار،
   والباسورد 1234 لكل مشرف فرع وكل طيار».

   كل حاجة بتتعمل من مسارات الـAPI الحقيقية بحساب أدمن (نفس اللي لوحة
   الإدارة بتعمله) — مش INSERT مباشر. بيتخطّى أي حاجة موجودة (آمن يتعاد).

     فرع تجريبي ١ (TSTA) · مشرف test_branch1 · طيار «طيار تجريبي ١» (test_pilot1)
     فرع تجريبي ٢ (TSTB) · مشرف test_branch2 · طيار «طيار تجريبي ٢» (test_pilot2)
     منطقة تجريبية لكل فرع + وردية مفتوحة لكل طيار + موقع على الخريطة.

   التشغيل: php ops/seed_test_branches.php            (معاينة)
            php ops/seed_test_branches.php --apply
═══════════════════════════════════════════════════════════════ */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv, true);
$PASS = '1234';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$admin = DB::selectOne("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1");
if (! $admin) { fwrite(STDERR, "مفيش حساب أدمن\n"); exit(1); }

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
$must = function (array $r, string $what): array {
    [$c, $j] = $r;
    if ($c !== 200) { fwrite(STDERR, "✗ {$what}: {$c} " . json_encode($j, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
    echo "  ✓ {$what}\n";

    return $j;
};

$defs = [
    ['code' => 'TSTA', 'name' => 'فرع تجريبي ١', 'sup' => 'test_branch1', 'pilot' => 'طيار تجريبي ١', 'pu' => 'test_pilot1', 'zone' => '🧪 تجربة ١ — لا تُستخدم', 'lat' => 30.5870, 'lng' => 31.5020],
    ['code' => 'TSTB', 'name' => 'فرع تجريبي ٢', 'sup' => 'test_branch2', 'pilot' => 'طيار تجريبي ٢', 'pu' => 'test_pilot2', 'zone' => '🧪 تجربة ٢ — لا تُستخدم', 'lat' => 30.5935, 'lng' => 31.5105],
];

foreach ($defs as $d) {
    echo "══ {$d['name']} ({$d['code']}) ══\n";
    $b = DB::selectOne('SELECT id FROM branches WHERE code = ?', [$d['code']]);
    if (! $apply) {
        echo '  ' . ($b ? '= الفرع موجود #' . $b->id : '+ هيتعمل') . " · مشرف {$d['sup']} · طيار {$d['pilot']} ({$d['pu']}) · {$d['zone']}\n";
        continue;
    }
    if (! $b) {
        $must(hit($kernel, $admin, 'POST', '/api/branches', ['name' => $d['name'], 'code' => $d['code'], 'manager' => 'تجربة', 'address' => 'فرع للتجربة فقط']), 'الفرع');
        $b = DB::selectOne('SELECT id FROM branches WHERE code = ?', [$d['code']]);
    } else { echo "  = الفرع موجود\n"; }
    $bid = (int) $b->id;

    if (! DB::selectOne('SELECT id FROM zones WHERE area_name = ? AND delivery_branch_id = ?', [$d['zone'], $bid])) {
        $must(hit($kernel, $admin, 'POST', '/api/zones', ['areaName' => $d['zone'], 'deliveryBranchId' => $bid, 'price' => 30]), 'المنطقة');
    } else { echo "  = المنطقة موجودة\n"; }

    if (! DB::selectOne('SELECT id FROM users WHERE username = ?', [$d['sup']])) {
        $must(hit($kernel, $admin, 'POST', '/api/users', ['username' => $d['sup'], 'password' => $PASS, 'role' => 'مشرف فرع', 'name' => 'مشرف ' . $d['name'], 'branchId' => $bid]), 'مشرف الفرع ' . $d['sup']);
    } else { echo "  = المشرف موجود\n"; }

    $p = DB::selectOne('SELECT id FROM pilots WHERE name = ?', [$d['pilot']]);
    if (! $p) {
        $must(hit($kernel, $admin, 'POST', '/api/pilots', ['name' => $d['pilot'], 'homeBranchId' => $bid, 'commissionType' => 'percent', 'commissionValue' => 50, 'phone1' => $d['code'] === 'TSTA' ? '01000000001' : '01000000002']), 'الطيار');
        $p = DB::selectOne('SELECT id FROM pilots WHERE name = ?', [$d['pilot']]);
    } else { echo "  = الطيار موجود\n"; }
    $pid = (int) $p->id;

    if (! DB::selectOne('SELECT id FROM users WHERE username = ?', [$d['pu']])) {
        $must(hit($kernel, $admin, 'POST', '/api/users', ['username' => $d['pu'], 'password' => $PASS, 'role' => 'طيار', 'name' => $d['pilot'], 'branchId' => $bid, 'pilotId' => $pid]), 'حساب الطيار ' . $d['pu']);
    } else { echo "  = حساب الطيار موجود\n"; }

    if (! DB::selectOne('SELECT id FROM shifts WHERE pilot_id = ? AND ended_at IS NULL', [$pid])) {
        $must(hit($kernel, $admin, 'POST', '/api/shifts/open', ['pilotId' => $pid, 'branchId' => $bid]), 'وردية مفتوحة');
    } else { echo "  = الوردية مفتوحة\n"; }

    /* موقع على الخريطة عشان الطيار يبان للفرع التاني (التطبيق هو اللي بيحدّثه في الحقيقة) */
    DB::update('UPDATE pilots SET lat = ?, lng = ?, location_updated_at = UTC_TIMESTAMP() WHERE id = ?', [$d['lat'], $d['lng'], $pid]);
    echo "  ✓ موقع الطيار على الخريطة\n";
}
echo $apply ? "تمام ✅\n" : "(معاينة — زوّد --apply للتنفيذ)\n";
