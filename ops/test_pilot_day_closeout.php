<?php
/**
 * 💰 حارس: بلوك تقفيلة الفرع اليومي في تقفيل الطيارين — زي روح دمشق من غير النسبة.
 *
 * ═══ الطلب (صاحب النظام 2026-09-04) ═══
 * «اعمل كل حاجة ولكن شيل نسبة روح دمشق فقط لأن رسوم التطوير معانا».
 *
 * ═══ المعادلات المثبتة (نفس branchDayCloseout القديمة بدون pct) ═══
 *   outTotal = hourPay + devFee + ext + exp
 *   expected = cash − ext − exp − adv − devFee
 *   diff     = hasRecv ? received − expected : 0
 *   net      = cash − outTotal + diff  ≡  received + adv − hourPay (لما المستلم مكتوب)
 *   رسوم التطوير: الفرع المتحمّل بياخد أوردرات الشركة كلها وباقي الفروع صفر.
 *
 * ═══ الفحص بينفّذ الكود الحقيقي ═══
 * ① طبقة السلك على صفوف مصطنعة بأرقام قطعية.
 * ② الراوتر الحقيقي: بيكتب الخارجي/المصاريف/المستلم بمسار day-summary، وبيقرا
 *    month، وبيتأكد إن الهوية net = received + adv − hourPay متحققة على بيانات
 *    القاعدة، وإن الصلاحيات بتقص البلوك. كله جوه معاملة بتترجع.
 *
 * التشغيل: php ops/test_pilot_day_closeout.php
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

echo "\n══ 1) طبقة السلك — أرقام قطعية ══\n";
$rows = [
    ['row' => ['hours' => 10, 'orders' => 30, 'net' => 900, 'adv' => 100, 'svc' => 1200], 'hourRate' => 30],
    ['row' => ['hours' => 8,  'orders' => 20, 'net' => 500, 'adv' => 0,   'svc' => 700],  'hourRate' => 25],
    ['row' => ['hours' => 0,  'orders' => 0,  'net' => 0,   'adv' => 0,   'svc' => 0],    'hourRate' => 30],
];
$set = ['orderRate' => 2, 'devFeeBranchId' => 0];
$c = W::branchDayCloseout($rows, ['ext' => 40, 'exp' => 60, 'recv' => 1000], $set, 7, 999);
ok('أجر الساعات = 10×30 + 8×25 = 500', $near($c['hourPay'], 500), (string) $c['hourPay']);
ok('رسوم التطوير = 50 أوردر × 2 = 100 (كل فرع بأوردراته)', $near($c['devFee'], 100) && $near($c['devFeeOrders'], 50), $c['devFee'] . '/' . $c['devFeeOrders']);
ok('إجمالي الخارج = 500 + 100 + 40 + 60 = 700', $near($c['outTotal'], 700), (string) $c['outTotal']);
ok('إجمالي نقدي = 1400', $near($c['cash'], 1400), (string) $c['cash']);
ok('المفروض يورّده = 1400 − 40 − 60 − 100 − 100 = 1100', $near($c['expected'], 1100), (string) $c['expected']);
ok('الفرق = 1000 − 1100 = −100', $c['hasRecv'] && $near($c['diff'], -100), (string) $c['diff']);
ok('صافي الفرع = 1400 − 700 − 100 = 600', $near($c['net'], 600), (string) $c['net']);
ok('🔴 الهوية: net = received + adv − hourPay = 1000 + 100 − 500', $near($c['net'], $c['received'] + $c['adv'] - $c['hourPay']));
ok('طيارين شغّالين = 2', $c['count'] === 2, (string) $c['count']);
$c2 = W::branchDayCloseout($rows, ['ext' => 40, 'exp' => 60], $set, 7, 999);
ok('من غير مستلم: الفرق صفر والصافي = النقدي − الخارج = 700', ! $c2['hasRecv'] && $near($c2['diff'], 0) && $near($c2['net'], 700), $c2['net'] . '');
$c3 = W::branchDayCloseout($rows, [], ['orderRate' => 2, 'devFeeBranchId' => 7], 7, 999);
$c4 = W::branchDayCloseout($rows, [], ['orderRate' => 2, 'devFeeBranchId' => 8], 7, 999);
ok('🔴 الفرع المتحمّل بياخد أوردرات الشركة كلها: 999 × 2', $near($c3['devFee'], 1998) && $near($c3['devFeeOrders'], 999), $c3['devFee'] . '');
ok('🔴 وفرع تاني بصفر رسوم', $near($c4['devFee'], 0) && $near($c4['devFeeOrders'], 0), $c4['devFee'] . '');
ok('مافيش «نسبة روح دمشق» في الناتج', ! array_key_exists('pct', $c));
$f = W::filterCloseout($c, ['blk.cash' => true]);
ok('القصّ: blk.cash بس → cash موجود والباقي متشال', isset($f['cash']) && ! isset($f['hourPay']) && ! isset($f['expected']) && ! isset($f['net']));
$g = [];
foreach (W::permGroups() as $grp) { foreach ($grp['items'] as [$k]) { $g[] = $k; } }
ok('مفاتيح blk.* العشرة معرّفة على السلك', count(array_intersect(['blk.hourPay','blk.devFee','blk.ext','blk.exp','blk.out','blk.cash','blk.net','blk.adv','blk.recon','blk.branches'], $g)) === 10);
ok('ومافيش blk.pct', ! in_array('blk.pct', $g, true));

echo "\n══ 2) الراوتر الحقيقي ══\n";
$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
if (! $admin) { echo "مافيش أدمن — تخطّي\n"; exit(0); }
$br = DB::select('SELECT id, name FROM branches ORDER BY id LIMIT 2');
if (count($br) < 1) { echo "مافيش فروع — تخطّي\n"; exit(0); }
$bid = (int) $br[0]->id;
$bid2 = isset($br[1]) ? (int) $br[1]->id : null;
$ym = date('Y-m');
$day = max(1, min((int) date('j'), W::daysInMonth($ym)));

DB::beginTransaction();
try {
    [$c, $j] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/day-summary', ['month' => $ym, 'branchId' => $bid, 'day' => $day, 'field' => 'ext', 'value' => 40]);
    ok('كتابة الخارجي 200', $c === 200, (string) $c);
    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/day-summary', ['month' => $ym, 'branchId' => $bid, 'day' => $day, 'field' => 'exp', 'value' => 60]);
    ok('كتابة المصاريف 200', $c === 200, (string) $c);
    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/day-summary', ['month' => $ym, 'branchId' => $bid, 'day' => $day, 'field' => 'recv', 'value' => 1000]);
    ok('كتابة المستلم 200', $c === 200, (string) $c);
    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/day-summary', ['month' => $ym, 'branchId' => $bid, 'day' => $day, 'field' => 'pct', 'value' => 5]);
    ok('🔴 «نسبة» مش خانة معروفة → 400', $c === 400, (string) $c);

    [$c, $j] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}");
    ok('month 200 وفيه closeout', $c === 200 && isset($j['closeout']['branches']), (string) $c);
    $b = null;
    foreach ($j['closeout']['branches'] ?? [] as $x) { if ((int) $x['branchId'] === $bid) { $b = $x; } }
    $co = $b ? ($b['days'][$day - 1] ?? null) : null;
    ok('تقفيلة الفرع/اليوم موجودة', $co !== null);
    if ($co) {
        ok('الخارجي 40 والمصاريف 60 والمستلم 1000 رجعوا', $near($co['ext'], 40) && $near($co['exp'], 60) && $co['hasRecv'] && $near($co['received'], 1000));
        ok('outTotal = hourPay + devFee + 100', $near($co['outTotal'], $co['hourPay'] + $co['devFee'] + 100), $co['outTotal'] . '');
        ok('expected = cash − 100 − adv − devFee', $near($co['expected'], $co['cash'] - 100 - $co['adv'] - $co['devFee']), $co['expected'] . '');
        ok('diff = 1000 − expected', $near($co['diff'], 1000 - $co['expected']), $co['diff'] . '');
        ok('🔴 net = received + adv − hourPay (على بيانات القاعدة)', $near($co['net'], 1000 + $co['adv'] - $co['hourPay']), $co['net'] . '');
        $sumNet = 0.0;
        foreach ($b['days'] as $dd) { $sumNet += (float) $dd['net']; }
        ok('مجموع الشهر للفرع = مجموع الأيام', $near($b['month']['net'], $sumNet), $b['month']['net'] . ' vs ' . $sumNet);
        $allNet = 0.0;
        foreach ($j['closeout']['branches'] as $x) { $allNet += (float) $x['month']['net']; }
        ok('إجمالي كل الفروع = مجموع الفروع', $near($j['closeout']['all']['net'], $allNet));
    }

    // رسوم التطوير على فرع متحمّل: إعداد + نطاق فرع واحد (الأوردرات لازم تبقى أوردرات الشركة)
    [$c] = hit($kernel, $admin, 'PUT', '/api/pilot-accounting/settings', ['orderRate' => 2, 'devFeeBranchId' => $bid]);
    ok('إعداد الفرع المتحمّل 200', $c === 200, (string) $c);
    [, $jAll] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}");
    $companyOrders = 0.0;
    foreach ($jAll['pilots'] ?? [] as $p) { $companyOrders += (float) ($p['days'][$day - 1]['orders'] ?? 0); }
    [, $jOne] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}&branchId={$bid}");
    $one = $jOne['closeout']['branches'][0]['days'][$day - 1] ?? null;
    ok('🔴 بنطاق فرع واحد: رسوم الفرع المتحمّل على أوردرات الشركة كلها',
        $one && $near($one['devFeeOrders'], $companyOrders) && $near($one['devFee'], $companyOrders * 2),
        $one ? $one['devFeeOrders'] . ' vs ' . $companyOrders : 'null');
    if ($bid2 !== null) {
        [, $jTwo] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}&branchId={$bid2}");
        $two = $jTwo['closeout']['branches'][0]['days'][$day - 1] ?? null;
        ok('والفرع التاني رسومه صفر', $two && $near($two['devFee'], 0), $two ? $two['devFee'] . '' : 'null');
    }
    [$c] = hit($kernel, $admin, 'PUT', '/api/pilot-accounting/settings', ['devFeeBranchId' => 999999]);
    ok('فرع مش موجود بيترفض', $c >= 400, (string) $c);

    echo "\n══ 3) الصلاحيات ══\n";
    $mk = function (string $role, ?array $keys, ?int $branch = null): array {
        $un = 'pdc_' . substr(bin2hex(random_bytes(3)), 0, 6);
        DB::insert('INSERT INTO users (username, password_hash, role, name, branch_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$un, password_hash('x', PASSWORD_BCRYPT), $role, 'فحص', $branch]);
        $id = (int) DB::getPdo()->lastInsertId();
        if ($keys !== null) {
            $k = [];
            foreach ($keys as $x) { $k[$x] = true; }
            DB::insert('INSERT INTO pilot_acct_perms (user_id, perm_keys, branches) VALUES (?, ?, NULL)', [$id, json_encode($k)]);
        }

        return ['id' => $id, 'username' => $un, 'role' => $role, 'branch_id' => $branch];
    };
    $viewer = $mk('accountant', ['page.daily', 'col.hours', 'blk.cash', 'blk.ext']);
    [$c, $j] = hit($kernel, $viewer, 'GET', "/api/pilot-accounting/month?month={$ym}");
    $b = null;
    foreach ($j['closeout']['branches'] ?? [] as $x) { if ((int) $x['branchId'] === $bid) { $b = $x; } }
    $co = $b['days'][$day - 1] ?? [];
    ok('محاسب بصلاحيات محدودة: cash وext بس في البلوك', $c === 200 && isset($co['cash'], $co['ext']) && ! isset($co['expected']) && ! isset($co['net']) && ! isset($co['hourPay']), json_encode(array_keys($co)));
    [$c] = hit($kernel, $viewer, 'POST', '/api/pilot-accounting/day-summary', ['month' => $ym, 'branchId' => $bid, 'day' => $day, 'field' => 'ext', 'value' => 5]);
    ok('ومن غير act.edit الكتابة 403', $c === 403, (string) $c);
    $sup = $mk('branch', null, $bid2 ?? $bid);
    [$c] = hit($kernel, $sup, 'POST', '/api/pilot-accounting/day-summary', ['month' => $ym, 'branchId' => $bid, 'day' => $day, 'field' => 'exp', 'value' => 5]);
    ok($bid2 !== null ? 'مشرف فرع تاني بيترفض 403' : 'مشرف الفرع نفسه بيكتب 200', $bid2 !== null ? $c === 403 : $c === 200, (string) $c);
    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/day-summary', ['month' => $ym, 'branchId' => $bid, 'day' => $day, 'field' => 'recv', 'value' => '']);
    ok('مسح المستلم بقيمة فاضية 200', $c === 200, (string) $c);
    [, $j] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}&branchId={$bid}");
    $co = $j['closeout']['branches'][0]['days'][$day - 1] ?? [];
    ok('وبعدها hasRecv=false والفرق صفر', isset($co['hasRecv']) && $co['hasRecv'] === false && $near($co['diff'], 0));
} finally {
    DB::rollBack();
}

echo "\n══ 4) الواجهة ══\n";
$html = file_get_contents($ROOT . '/public/accounts.html');
ok('البلوك بيتكتب من closeout السيرفر', str_contains($html, 'function renderCloseoutBlock') && str_contains($html, 'd.closeout'));
ok('الحفظ على day-summary', str_contains($html, '"/api/pilot-accounting/day-summary"'));
ok('سطور المطابقة موجودة', str_contains($html, 'مطابقة توريد المشرف') && str_contains($html, 'المفروض يورّده المشرف'));
ok('🔴 مافيش «نسبة روح دمشق» في تقفيل الطيارين', ! str_contains($html, 'نسبة روح دمشق'));
ok('الإعدادات فيها رسوم التطوير والفرع المتحمّل', str_contains($html, 'id="setOrderRate"') && str_contains($html, 'id="setDevBranch"'));
ok('تقفيل الشهر فيه البلوك الشهري', str_contains($html, 'id="paMonthClose"'));

echo "\n════════════════════════════════════════\n";
echo "PILOT DAY CLOSEOUT: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
