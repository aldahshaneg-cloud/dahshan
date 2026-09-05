<?php
/**
 * 📊 حارس: الواقع قصاد المتوقع — صفحة التقارير (المرحلة ٢).
 *
 * ═══ الطلب (صاحب النظام 2026-09-05) ═══
 * «التقرير يقارن بين الواقع والمتوقع» — الفعلي من التقفيلات الموجودة والمصروفات
 * المصنّفة، والمتوقع من الميزانية.
 *
 * ═══ العقود المثبتة ═══
 * • المتوقع لحد النهارده: الثابت × الأيام اللي عدّت ÷ ٣٠، و«لكل أوردر» على الأوردرات
 *   الفعلية نسبةً للمتوقعة. الحالة: over/under لو الفرق أكتر من ١٥٪، unplanned لو
 *   فعلي من غير متوقع.
 * • التوقّع لآخر الشهر: الإيراد والأجور والعمولة بمعدل الأيام؛ بنود المصروفات
 *   (إيجار…) الأكبر من الفعلي والمتوقع.
 * • الفعلي: مصروف مصنّف rent في الشهر بيظهر في بند الإيجار، وغير المصنّف لوحده.
 *   العمولة والإيراد والأوردرات = نفس أرقام تقفيلة الشهر بالحرف.
 * • الإجمالي بعد الفروع = جمع الفروع. مشرف الفرع بيشوف فرعه بس.
 * • سلسلة يومية بطول أيام الشهر للرسوم.
 * كله جوه معاملة بتترجع.
 *
 * التشغيل: php ops/test_pa_reports.php
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

echo "══ 0) المعادلة نفسها ══\n";
$budget = W::budgetTotals([
    ['category' => 'rent', 'kind' => 'fixed', 'amount' => 6000, 'rate' => 0],
    ['category' => 'pilot_hours', 'kind' => 'per_hour', 'amount' => 9000, 'rate' => 10],
    ['category' => 'commission', 'kind' => 'per_order', 'amount' => 12000, 'rate' => 8],
], 50, 32);   // 1500 أوردر متوقع
$actual = ['byCategory' => ['rent' => 6000, 'pilot_hours' => 4000, 'commission' => 4800, 'marketing' => 300], 'revenue' => 20000, 'orders' => 600, 'uncategorized' => 100];
$c = W::reportCompare($budget, $actual, 15, 30);
$row = fn (string $cat) => array_values(array_filter($c['rows'], fn ($r) => $r['category'] === $cat))[0] ?? null;
ok('الإيجار: المتوقع لحد النهارده 3000 (6000 × 15 ÷ 30) والفعلي 6000 → over', $near($row('rent')['expectedToDate'], 3000) && $row('rent')['status'] === 'over', json_encode($row('rent')));
ok('التوقّع للإيجار = الأكبر من الفعلي والمتوقع = 6000', $near($row('rent')['projected'], 6000));
ok('🔴 العمولة على الأوردرات الفعلية: 12000 × 600 ÷ 1500 = 4800 → ok', $near($row('commission')['expectedToDate'], 4800) && $row('commission')['status'] === 'ok', json_encode($row('commission')));
ok('أجر الطيارين: متوقع لحد النهارده 4500 وفعلي 4000 → ok (أقل من 15٪)، والتوقّع 8000', $near($row('pilot_hours')['expectedToDate'], 4500) && $row('pilot_hours')['status'] === 'ok' && $near($row('pilot_hours')['projected'], 8000), json_encode($row('pilot_hours')));
ok('التسويق فعلي من غير متوقع → unplanned', $row('marketing') && $row('marketing')['status'] === 'unplanned');
$t = $c['totals'];
ok('🔴 التكلفة الفعلية 15200 (6000+4000+4800+300 + غير المصنّف 100) والمتوقع لحد النهارده 12300', $near($t['actualCost'], 15200) && $near($t['expectedToDate'], 12300), $t['actualCost'] . '/' . $t['expectedToDate']);
ok('الربح لحد النهارده 4800 والإيراد المتوقع لحد النهارده 24000', $near($t['profit'], 4800) && $near($t['expectedRevenueToDate'], 24000));
ok('التوقّع: إيراد 40000، أوردرات 40/يوم، التعادل 21/يوم (15000 ÷ 24 = 625/شهر) → فوق', $near($t['projectedRevenue'], 40000) && $near($t['ordersPerDay'], 40) && $t['ordersNeededPerDay'] === 21 && $t['breakEven'] === 'above', json_encode($t));
$c0 = W::reportCompare($budget, ['byCategory' => [], 'revenue' => 0, 'orders' => 0], 0, 30);
ok('شهر لسه مابدأش: مافيش قسمة على صفر', $c0['totals']['ordersPerDay'] == 0 && $c0['totals']['projectedRevenue'] == 0);

$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
$sup   = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'branch' AND blocked = 0 AND branch_id IS NOT NULL ORDER BY id LIMIT 1")[0] ?? null);
if (! $admin || ! $sup) { echo "مافيش أدمن/مشرف — تخطّي\n"; exit(0); }
$ymRow = DB::select("SELECT DATE_FORMAT(delivered_at, '%Y-%m') ym FROM orders WHERE status = 'delivered' ORDER BY delivered_at DESC LIMIT 1")[0] ?? null;
$ym = $ymRow ? (string) $ymRow->ym : date('Y-m');
$b1 = (int) $sup['branch_id'];

DB::beginTransaction();
try {
    echo "\n══ 1) الفعلي من التقفيلات والمصروفات ══\n";
    DB::delete('DELETE FROM pa_budget_items WHERE month = ?', [$ym]);
    DB::delete("DELETE FROM expenses WHERE branch_id = ? AND expense_date LIKE ?", [$b1, $ym . '-%']);
    hit($kernel, $admin, 'POST', '/api/pilot-accounting/budget', ['month' => $ym, 'branchId' => $b1, 'category' => 'assumption', 'label' => 'ordersPerDay', 'amount' => 50]);
    hit($kernel, $admin, 'POST', '/api/pilot-accounting/budget', ['month' => $ym, 'branchId' => $b1, 'category' => 'assumption', 'label' => 'avgPrice', 'amount' => 30]);
    hit($kernel, $admin, 'POST', '/api/pilot-accounting/budget', ['month' => $ym, 'branchId' => $b1, 'category' => 'rent', 'kind' => 'fixed', 'label' => 'إيجار', 'amount' => 3000]);
    hit($kernel, $admin, 'POST', '/api/expenses', ['date' => $ym . '-02', 'item' => 'إيجار الفرع', 'amount' => 3000, 'category' => 'rent', 'branchId' => $b1]);
    hit($kernel, $admin, 'POST', '/api/expenses', ['date' => $ym . '-03', 'item' => 'حاجة', 'amount' => 70, 'branchId' => $b1]);

    [$c, $R] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/reports?month={$ym}&branchId={$b1}");
    ok('التقرير 200', $c === 200, (string) $c . ' ' . mb_substr(json_encode($R, JSON_UNESCAPED_UNICODE), 0, 200));
    $blk = $R['branches'][0] ?? null;
    $rows = [];
    foreach ($blk['compare']['rows'] ?? [] as $r) { $rows[$r['category']] = $r; }
    ok('🔴 الإيجار الفعلي 3000 من المصروف المصنّف', isset($rows['rent']) && $near($rows['rent']['actual'], 3000), json_encode($rows['rent'] ?? null));
    ok('وغير المصنّف 70 لوحده', $near($blk['compare']['uncategorized'] ?? -1, 70));
    [, $m] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}&branchId={$b1}");
    $mComm = array_sum(array_map(fn ($p) => (float) ($p['totals']['psvc'] ?? 0), $m['pilots'] ?? []));
    $mRev  = array_sum(array_map(fn ($p) => (float) ($p['totals']['svc'] ?? 0), $m['pilots'] ?? []));
    $mOrd  = array_sum(array_map(fn ($p) => (int) ($p['totals']['orders'] ?? 0), $m['pilots'] ?? []));
    ok('🔴 العمولة والإيراد والأوردرات = تقفيلة الشهر بالحرف', $near($rows['commission']['actual'] ?? 0, $mComm) && $near($blk['compare']['totals']['revenue'], $mRev) && (int) $blk['compare']['totals']['orders'] === $mOrd,
        ($rows['commission']['actual'] ?? '؟') . "/{$mComm} · " . $blk['compare']['totals']['revenue'] . "/{$mRev} · " . $blk['compare']['totals']['orders'] . "/{$mOrd}");
    ok('السلسلة اليومية بطول أيام الشهر ومجموع أوردراتها = الأوردرات', count($blk['daily']) === (int) $R['daysInMonth'] && array_sum(array_column($blk['daily'], 'orders')) === $mOrd);
    ok('الأيام اللي عدّت من countedDays', (int) $R['elapsedDays'] === (int) ($m['countedDays'] ?? -1));
    ok('hasBudget = true والتعادل محسوب', $blk['hasBudget'] === true && $blk['compare']['totals']['ordersNeededPerDay'] !== null);

    echo "\n══ 2) الإجمالي والنطاق ══\n";
    [$c, $A] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/reports?month={$ym}");
    ok('كل الفروع 200 وفيه إجمالي', $c === 200 && isset($A['company']['compare']['totals']), (string) $c);
    $sumRev = array_sum(array_map(fn ($b) => (float) $b['compare']['totals']['revenue'], $A['branches']));
    ok('🔴 إيراد الشركة = مجموع الفروع', $near($A['company']['compare']['totals']['revenue'], $sumRev), $A['company']['compare']['totals']['revenue'] . ' vs ' . $sumRev);
    $sumRent = array_sum(array_map(fn ($b) => (float) (array_values(array_filter($b['compare']['rows'], fn ($r) => $r['category'] === 'rent'))[0]['actual'] ?? 0), $A['branches']));
    $cRent = array_values(array_filter($A['company']['compare']['rows'], fn ($r) => $r['category'] === 'rent'))[0]['actual'] ?? 0;
    ok('وإيجار الشركة = مجموع إيجارات الفروع', $near($cRent, $sumRent), "{$cRent} vs {$sumRent}");
    [$c, $S] = hit($kernel, $sup, 'GET', "/api/pilot-accounting/reports?month={$ym}");
    ok('مشرف الفرع: فرعه بس', $c === 200 && count($S['branches'] ?? []) === 1 && (int) $S['branches'][0]['branchId'] === $b1, (string) $c);
    $un = 'rpt_' . substr(bin2hex(random_bytes(3)), 0, 6);
    DB::insert('INSERT INTO users (username, password_hash, role, name, branch_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())', [$un, password_hash('x', PASSWORD_BCRYPT), 'branch', 'فحص', $b1]);
    $noRp = ['id' => (int) DB::getPdo()->lastInsertId(), 'username' => $un, 'role' => 'branch', 'branch_id' => $b1];
    DB::statement('INSERT INTO pilot_acct_perms (user_id, perm_keys, branches, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW())', [$noRp['id'], json_encode(['page.daily']), '[]', 'test']);
    [$c] = hit($kernel, $noRp, 'GET', "/api/pilot-accounting/reports?month={$ym}");
    ok('🔴 من غير page.reports → 403', $c === 403, (string) $c);
} finally {
    DB::rollBack();
}

echo "\n══ 3) الواجهة ══\n";
$acc = file_get_contents($ROOT . '/public/accounts.html');
ok('تبويبين: الواقع قصاد المتوقع والميزانية', str_contains($acc, 'id="rpView-compare"') && str_contains($acc, 'id="rpView-budget"') && str_contains($acc, '"/api/pilot-accounting/reports?"'));
ok('الجدول فيه المتوقع لحد النهارده والفرق والتوقّع', str_contains($acc, 'المتوقع لحد النهارده') && str_contains($acc, 'توقّع آخر الشهر'));
ok('غير المصنّف بيبان بتنبيه', str_contains($acc, 'مصروفات غير مصنّفة'));

echo "\n════════════════════════════════════════\n";
echo "REPORTS: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
