<?php
/**
 * 📦 حارس: أوردرات الطيار في اليوم + عمولة كل أوردر — تنفيذ حقيقي.
 *
 * ═══ الطلب (صاحب النظام 2026-09-04) ═══
 * «إذا ضغطت على أي موظف تظهر صفحة بها كل الأوردرات لكي أستطيع وضع
 *  العمولة لكل أوردر».
 *
 * ═══ العقود المثبتة ═══
 * • GET pilot-accounting/pilot-orders بيرجّع أوردرات اليوم التجاري للطيار،
 *   وعمولة كل أوردر التلقائية = Commission::forPilot نفسها اللي التقفيلة بتحسب بيها.
 * • كتابة عمولة على أوردر (pilot-commission-adjustments) بتظهر في الرد
 *   كـoverride **وبتغيّر خدمة الطيار في شيت اليوم** بنفس القيمة.
 * • المحاسب بيقرا ومايكتبش (403 على المسار الموجود)، ومشرف فرع تاني مايشوفش.
 * كله جوه معاملة بتترجع.
 *
 * التشغيل: php ops/test_pilot_orders.php
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
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        exit(1);
    }
});

use App\Support\Commission;
use App\Wire\PilotAccountingWire as W;
use Illuminate\Support\Facades\DB;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
function hit($kernel, array $u, string $method, string $url, array $body = []): array
{
    $req = Illuminate\Http\Request::create($url, $method, [], [], [], [], $body ? json_encode($body) : null);
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

$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
if (! $admin) { echo "مافيش أدمن — تخطّي\n"; exit(0); }
$branchId = (int) (DB::select('SELECT id FROM branches ORDER BY id LIMIT 1')[0]->id ?? 0);
$pilot = DB::select('SELECT id, name, assigned_branch_id, commission_type, commission_value FROM pilots WHERE archived_at IS NULL AND assigned_branch_id = ? ORDER BY id LIMIT 1', [$branchId])[0] ?? null;
if (! $pilot) { echo "مافيش طيار — تخطّي\n"; exit(0); }
$pilot = (array) $pilot;

DB::beginTransaction();
try {
    /* أوردرين في يوم تجاري ثابت: متسلّم بـ100 ومرتجع بـ40 — الساعة 12 ظهرًا UTC (بعد بداية اليوم) */
    $ym  = '2026-08';
    $day = 10;
    DB::update("UPDATE pilots SET commission_type = 'percent', commission_value = 10 WHERE id = ?", [$pilot['id']]);
    $mk = function (string $status, float $price) use ($pilot, $branchId): int {
        DB::insert("INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, status, status_since, pilot_id, pilot_name,
                        total_delivery_price, money_settled, delivered_at, undelivered_at, undelivered_fare_by, source, added_by, added_by_role, qr_code, created_at, pieces_count)
                    VALUES (?,?,?,?,?,?,?,?,?,1,?,?,?,?,?,?,?,?,1)",
            ['PO-' . bin2hex(random_bytes(4)), $branchId, $branchId, 'راسل فحص', $status, '2026-08-10 12:00:00', $pilot['id'], $pilot['name'], $price,
             $status === 'delivered' ? '2026-08-10 12:00:00' : null, $status === 'undelivered' ? '2026-08-10 12:30:00' : null,
             $status === 'undelivered' ? 'none' : null, 'branch', 'اختبار', 'branch', 'PO-' . bin2hex(random_bytes(4)), '2026-08-10 11:00:00']);

        return (int) DB::getPdo()->lastInsertId();
    };
    $od = $mk('delivered', 100);
    $ou = $mk('undelivered', 40);

    echo "\n══ 1) القراءة ══\n";
    [$c, $j] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/pilot-orders?month={$ym}&pilotId={$pilot['id']}&day={$day}");
    ok('HTTP 200', $c === 200, (string) $c);
    $rows = [];
    foreach ($j['orders'] ?? [] as $o) { $rows[(int) $o['orderId']] = $o; }
    ok('الأوردرين موجودين', isset($rows[$od], $rows[$ou]), json_encode(array_keys($rows)));
    $expAuto = round(Commission::forPilot('percent', 10.0, 100.0), 2);
    ok("العمولة التلقائية للمتسلّم = Commission::forPilot = {$expAuto}", isset($rows[$od]) && $near($rows[$od]['autoCommission'], $expAuto) && $rows[$od]['override'] === null, (string) ($rows[$od]['autoCommission'] ?? '؟'));
    ok('المرتجع تلقائيته صفر وحالته بالعربي', isset($rows[$ou]) && $near($rows[$ou]['autoCommission'], 0) && $rows[$ou]['statusAr'] === 'لم يتم التوصيل');
    ok('الوقت باليوم التجاري (12:00 UTC → 15:00 قاهرة)', isset($rows[$od]) && $rows[$od]['time'] === '15:00', (string) ($rows[$od]['time'] ?? '؟'));
    ok('الأدمن يقدر يكتب', ! empty($j['canWrite']));
    [$c] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/pilot-orders?month={$ym}&pilotId={$pilot['id']}&day=40");
    ok('يوم مش في الشهر → 400', $c === 400, (string) $c);

    echo "\n══ 2) عمولة على أوردر بعينه بتدخل الشيت ══\n";
    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-commission-adjustments', ['pilotId' => $pilot['id'], 'orderId' => $od, 'amount' => 25, 'reason' => 'مشوار بعيد']);
    ok('الحفظ 200', $c === 200, (string) $c);
    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-commission-adjustments', ['pilotId' => $pilot['id'], 'orderId' => $ou, 'amount' => 15, 'reason' => 'من جيب الشركة']);
    ok('عمولة على المرتجع 200', $c === 200, (string) $c);
    [, $j] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/pilot-orders?month={$ym}&pilotId={$pilot['id']}&day={$day}");
    $rows = [];
    foreach ($j['orders'] ?? [] as $o) { $rows[(int) $o['orderId']] = $o; }
    ok('🔴 المكتوب بيظهر كـoverride وبيغلب التلقائي', isset($rows[$od]['override']) && $near($rows[$od]['override']['amount'], 25) && $near($rows[$od]['commission'], 25) && $near($rows[$od]['autoCommission'], $expAuto));
    ok('إجمالي عمولة اليوم = 25 + 15', $near($j['totals']['commission'], 40), (string) ($j['totals']['commission'] ?? '؟'));
    [, $m] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}&pilotId={$pilot['id']}");
    $row = $m['pilots'][0]['days'][$day - 1] ?? null;
    ok('🔴 خدمة الطيار في شيت اليوم = 40 (نفس الأرقام في الشاشتين)', $row && $near($row['psvc'], 40), (string) ($row['psvc'] ?? '؟'));

    echo "\n══ 3) الصلاحيات ══\n";
    $mk2 = function (string $role, ?int $branch = null): array {
        $un = 'po_' . substr(bin2hex(random_bytes(3)), 0, 6);
        DB::insert('INSERT INTO users (username, password_hash, role, name, branch_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$un, password_hash('x', PASSWORD_BCRYPT), $role, 'فحص', $branch]);

        return ['id' => (int) DB::getPdo()->lastInsertId(), 'username' => $un, 'role' => $role, 'branch_id' => $branch];
    };
    $acc = $mk2('accountant');
    [$c, $j] = hit($kernel, $acc, 'GET', "/api/pilot-accounting/pilot-orders?month={$ym}&pilotId={$pilot['id']}&day={$day}");
    ok('المحاسب بيقرا (200) ومش بيكتب (canWrite=false)', $c === 200 && empty($j['canWrite']), $c . '/' . json_encode($j['canWrite'] ?? null));
    [$c] = hit($kernel, $acc, 'POST', '/api/pilot-commission-adjustments', ['pilotId' => $pilot['id'], 'orderId' => $od, 'amount' => 5, 'reason' => 'x']);
    ok('ومسار الكتابة بيرفضه 403', $c === 403, (string) $c);
    $other = (int) (DB::select('SELECT id FROM branches WHERE id <> ? ORDER BY id LIMIT 1', [$branchId])[0]->id ?? 0);
    if ($other) {
        $sup = $mk2('branch', $other);
        [$c] = hit($kernel, $sup, 'GET', "/api/pilot-accounting/pilot-orders?month={$ym}&pilotId={$pilot['id']}&day={$day}");
        ok('مشرف فرع تاني مايشوفش (403)', $c === 403, (string) $c);
    }
} finally {
    DB::rollBack();
}

echo "\n══ 4) الواجهة ══\n";
$html = file_get_contents($ROOT . '/public/accounts.html');
ok('اسم الطيار في الشيت اليومي بيفتح الأوردرات', substr_count($html, 'onclick="openPilotOrders(') >= 2);
ok('المودال موجود وبيحفظ على المسار الموجود', str_contains($html, 'id="modal-orders"') && str_contains($html, '"/api/pilot-commission-adjustments"'));
ok('بيقرا من pilot-orders', str_contains($html, '/api/pilot-accounting/pilot-orders?'));

echo "\n════════════════════════════════════════\n";
echo "PILOT ORDERS: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
