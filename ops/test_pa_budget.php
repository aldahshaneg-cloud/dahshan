<?php
/**
 * 📈 حارس: الميزانية المتوقعة ونقطة التعادل — صفحة التقارير (المرحلة الأولى).
 *
 * ═══ الطلب (صاحب النظام 2026-09-05) ═══
 * «الشركة تريد أن تعرف المصروفات الكلية في كل بند وإجمالي المصروفات المتوقعة
 * لكي تعرف كم الأوردرات الواجب دخولها لتغطية نفقاتها ثم يأتي الربح — الميزانية
 * لكل فرع لوحده وبعد كده الإجمالي».
 *
 * ═══ العقود المثبتة ═══
 * • البند الثابت بمبلغه · «لكل ساعة» = ساعات × سعر · «لكل أوردر» = سعر × أوردرات/يوم × ٣٠
 *   وبيتبع افتراض الفرع (تغيير الافتراض بيغيّر المبلغ من غير حفظ تاني).
 * • الإجماليات: الثابت = كل اللي مش لكل أوردر · الهامش = متوسط السعر − المتغيّر ·
 *   أوردرات التعادل = ceil(الثابت ÷ الهامش) وعلى ٣٠ في اليوم · والإجمالي بعد الفروع.
 * • «املأ الافتراضي» بيضيف موظفي وطياري الفرع بسعرهم والعمولة ورسوم التطوير
 *   وصفوف الإيجار/المرافق — ومابيكرّرش ولا بيلمس الموجود.
 * • act.budget إدارية: المحاسب ومشرف الفرع 403 على الكتابة، ومشرف الفرع بيقرا فرعه بس.
 * • المصروف بياخد تصنيف من القايمة (rent…) وغير كده NULL — وبيرجع في الرد.
 * كله جوه معاملة بتترجع.
 *
 * التشغيل: php ops/test_pa_budget.php
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

echo "══ 0) المعادلات نفسها ══\n";
ok('ثابت = المبلغ زي ما هو', $near(W::budgetAmount('fixed', 99, 99, 6000, 50), 6000));
ok('لكل ساعة = ساعات × سعر', $near(W::budgetAmount('per_hour', 300, 10, 0, 50), 3000));
ok('🔴 لكل أوردر = سعر × أوردرات/يوم × 30', $near(W::budgetAmount('per_order', 0, 8, 0, 50), 12000));
$t = W::budgetTotals([
    ['category' => 'rent', 'kind' => 'fixed', 'amount' => 6000, 'rate' => 0],
    ['category' => 'staff', 'kind' => 'per_hour', 'amount' => 3000, 'rate' => 10],
    ['category' => 'commission', 'kind' => 'per_order', 'amount' => 12000, 'rate' => 8],
    ['category' => 'dev_fee', 'kind' => 'per_order', 'amount' => 3000, 'rate' => 2],
], 50, 32);
ok('الثابت 9000 (الإيجار + الساعات — مش العمولة)', $near($t['fixed'], 9000), (string) $t['fixed']);
ok('المتغيّر لكل أوردر 10 (8 + 2)', $near($t['perOrderRate'], 10));
ok('الهامش 22 (32 − 10)', $near($t['margin'], 22));
ok('🔴 أوردرات التعادل 410 في الشهر و14 في اليوم', $t['ordersNeeded'] === 410 && $t['ordersNeededPerDay'] === 14, $t['ordersNeeded'] . '/' . $t['ordersNeededPerDay']);
ok('بافتراض 50/يوم: 1500 أوردر · إيراد 48000 · تكلفة 24000 · ربح 24000', $t['expectedOrders'] == 1500 && $near($t['expectedRevenue'], 48000) && $near($t['expectedCost'], 24000) && $near($t['expectedProfit'], 24000));
$t0 = W::budgetTotals([['category' => 'rent', 'kind' => 'fixed', 'amount' => 100, 'rate' => 0]], 0, 0);
ok('من غير سعر: التعادل null مش قسمة على صفر', $t0['ordersNeeded'] === null && $t0['margin'] <= 0);
$c = W::budgetCompany([$t, $t]);
ok('🔴 إجمالي الشركة = جمع فرعين: ثابت 18000 · 3000 أوردر · التعادل 819/شهر', $near($c['fixed'], 18000) && $c['expectedOrders'] == 3000 && $c['ordersNeeded'] === 819 && $near($c['avgPrice'], 32), json_encode($c));

$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
$sup   = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'branch' AND blocked = 0 AND branch_id IS NOT NULL ORDER BY id LIMIT 1")[0] ?? null);
if (! $admin || ! $sup) { echo "مافيش أدمن/مشرف — تخطّي\n"; exit(0); }
$ym = date('Y-m', strtotime('+2 month'));   // شهر مستقبلي فاضي

DB::beginTransaction();
try {
    $branches = array_map(fn ($r) => (int) $r->id, DB::select('SELECT id FROM branches ORDER BY id LIMIT 2'));
    $b1 = (int) $sup['branch_id'];
    $b2 = $branches[0] !== $b1 ? $branches[0] : ($branches[1] ?? $b1);
    DB::delete('DELETE FROM pa_budget_items WHERE month = ?', [$ym]);

    echo "\n══ 1) الكتابة والقراءة ══\n";
    $post = fn (array $body) => hit($kernel, $admin, 'POST', '/api/pilot-accounting/budget', $body + ['month' => $ym, 'branchId' => $b1]);
    [$c] = $post(['category' => 'assumption', 'label' => 'ordersPerDay', 'amount' => 50]);
    [$c2] = $post(['category' => 'assumption', 'label' => 'avgPrice', 'amount' => 32]);
    ok('الافتراضات 200', $c === 200 && $c2 === 200, "{$c}/{$c2}");
    [$c, $j] = $post(['category' => 'rent', 'kind' => 'fixed', 'label' => 'إيجار الفرع', 'amount' => 6000]);
    ok('بند ثابت 200 ومبلغه 6000', $c === 200 && $near($j['item']['amount'] ?? -1, 6000), (string) $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    $rentId = (int) ($j['item']['id'] ?? 0);
    [$c, $j] = $post(['category' => 'staff', 'kind' => 'per_hour', 'label' => 'موظف', 'qty' => 300, 'rate' => 10]);
    ok('بند لكل ساعة: 300 × 10 = 3000', $c === 200 && $near($j['item']['amount'] ?? -1, 3000));
    [$c, $j] = $post(['category' => 'commission', 'kind' => 'per_order', 'label' => 'عمولة', 'rate' => 8]);
    ok('🔴 بند لكل أوردر: 8 × 50 × 30 = 12000 والكمية 1500', $c === 200 && $near($j['item']['amount'] ?? -1, 12000) && $near($j['item']['qty'] ?? -1, 1500), json_encode($j['item'] ?? null));
    [$c, $j] = $post(['category' => 'dev_fee', 'kind' => 'per_order', 'label' => 'تطوير', 'rate' => 2]);
    [$c] = $post(['category' => 'nope', 'kind' => 'fixed', 'amount' => 1]);
    ok('تصنيف غلط → 400', $c === 400, (string) $c);
    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/budget', ['month' => $ym, 'category' => 'rent', 'kind' => 'fixed', 'amount' => 1]);
    ok('من غير فرع → 400 (لكل فرع لوحده)', $c === 400, (string) $c);

    [$c, $L] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/budget?month={$ym}&branchId={$b1}");
    ok('القراءة 200', $c === 200, (string) $c);
    $blk = $L['branches'][0] ?? null;
    ok('بلوك الفرع فيه 4 بنود والافتراضات', $blk && count($blk['items']) === 4 && $near($blk['assumptions']['ordersPerDay'], 50) && $near($blk['assumptions']['avgPrice'], 32));
    ok('🔴 الإجماليات: ثابت 9000 · هامش 22 · التعادل 410/شهر · 14/يوم · ربح 24000', $blk && $near($blk['totals']['fixed'], 9000) && $near($blk['totals']['margin'], 22)
        && $blk['totals']['ordersNeeded'] === 410 && $blk['totals']['ordersNeededPerDay'] === 14 && $near($blk['totals']['expectedProfit'], 24000), json_encode($blk['totals'] ?? null));
    ok('canWrite للأدمن', ($L['canWrite'] ?? false) === true);
    ok('التصنيفات والأنواع في الرد', count($L['categories'] ?? []) === count(W::BUDGET_CATEGORIES) && ($L['kinds'] ?? []) === W::BUDGET_KINDS);

    echo "\n══ 2) الافتراض بيجرّ بنود «لكل أوردر» ══\n";
    $post(['category' => 'assumption', 'label' => 'ordersPerDay', 'amount' => 100]);
    [, $L2] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/budget?month={$ym}&branchId={$b1}");
    $comm = null;
    foreach ($L2['branches'][0]['items'] as $it) { if ($it['category'] === 'commission') { $comm = $it; } }
    ok('🔴 العمولة بقت 24000 من غير حفظ تاني', $comm && $near($comm['amount'], 24000) && $near($comm['qty'], 3000), json_encode($comm));
    ok('والربح اتغيّر معاها', $near($L2['branches'][0]['totals']['expectedProfit'], 3000 * 32 - 9000 - 3000 * 10));
    $post(['category' => 'assumption', 'label' => 'ordersPerDay', 'amount' => 50]);

    echo "\n══ 3) التعديل والحذف ══\n";
    [$c, $j] = $post(['id' => $rentId, 'category' => 'rent', 'kind' => 'fixed', 'label' => 'إيجار', 'amount' => 7000, 'note' => 'زاد']);
    ok('تعديل الإيجار 7000', $c === 200 && $near($j['item']['amount'], 7000) && $j['item']['note'] === 'زاد');
    [$c] = hit($kernel, $admin, 'DELETE', '/api/pilot-accounting/budget/' . $rentId);
    ok('الحذف 200', $c === 200, (string) $c);
    [$c] = hit($kernel, $admin, 'DELETE', '/api/pilot-accounting/budget/' . $rentId);
    ok('وتاني مرة 404', $c === 404, (string) $c);

    echo "\n══ 4) الإجمالي بعد الفروع ══\n";
    if ($b2 !== $b1) {
        hit($kernel, $admin, 'POST', '/api/pilot-accounting/budget', ['month' => $ym, 'branchId' => $b2, 'category' => 'assumption', 'label' => 'ordersPerDay', 'amount' => 20]);
        hit($kernel, $admin, 'POST', '/api/pilot-accounting/budget', ['month' => $ym, 'branchId' => $b2, 'category' => 'assumption', 'label' => 'avgPrice', 'amount' => 40]);
        hit($kernel, $admin, 'POST', '/api/pilot-accounting/budget', ['month' => $ym, 'branchId' => $b2, 'category' => 'rent', 'kind' => 'fixed', 'label' => 'إيجار', 'amount' => 4000]);
    }
    [$c, $A] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/budget?month={$ym}");
    ok('كل الفروع 200 وفيه إجمالي شركة', $c === 200 && isset($A['company']['fixed']), (string) $c);
    $sumFixed = array_sum(array_map(fn ($b) => (float) $b['totals']['fixed'], $A['branches']));
    ok('🔴 ثابت الشركة = مجموع ثابت الفروع', $near($A['company']['fixed'], $sumFixed), $A['company']['fixed'] . ' vs ' . $sumFixed);
    ok('بفرع واحد مافيش إجمالي شركة', ($L['company'] ?? null) === null);

    echo "\n══ 5) الصلاحيات والنطاق ══\n";
    [$c, $S] = hit($kernel, $sup, 'GET', "/api/pilot-accounting/budget?month={$ym}");
    ok('مشرف الفرع بيقرا فرعه بس', $c === 200 && count($S['branches'] ?? []) === 1 && (int) $S['branches'][0]['branchId'] === $b1 && ($S['canWrite'] ?? true) === false, (string) $c . ' ' . count($S['branches'] ?? []));
    [$c] = hit($kernel, $sup, 'POST', '/api/pilot-accounting/budget', ['month' => $ym, 'branchId' => $b1, 'category' => 'rent', 'kind' => 'fixed', 'amount' => 1]);
    ok('🔴 ومايكتبش (act.budget إدارية) → 403', $c === 403, (string) $c);
    [$c] = hit($kernel, $sup, 'POST', '/api/pilot-accounting/budget/prefill', ['month' => $ym, 'branchId' => $b1]);
    ok('ولا يملأ الافتراضي → 403', $c === 403, (string) $c);
    $acct = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'accountant' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
    if ($acct) {
        [$c] = hit($kernel, $acct, 'POST', '/api/pilot-accounting/budget', ['month' => $ym, 'branchId' => $b1, 'category' => 'rent', 'kind' => 'fixed', 'amount' => 1]);
        ok('المحاسب كمان 403', $c === 403, (string) $c);
    }
    ok('🔴 act.budget في ADMIN_ONLY_KEYS وpage.reports في الافتراضي', in_array('act.budget', W::ADMIN_ONLY_KEYS, true) && isset(W::defaultPermKeys()['page.reports']) && ! isset(W::defaultPermKeys()['act.budget']));

    echo "\n══ 6) املأ الافتراضي ══\n";
    DB::delete('DELETE FROM pa_budget_items WHERE month = ? AND branch_id = ?', [$ym, $b1]);
    $staffN = (int) DB::selectOne("SELECT COUNT(*) c FROM users WHERE blocked = 0 AND branch_id = ? AND role IN ('branch','callcenter','accountant','hr','pilot_supervisor')", [$b1])->c;
    $pilotN = (int) DB::selectOne('SELECT COUNT(*) c FROM pilots WHERE archived_at IS NULL AND assigned_branch_id = ?', [$b1])->c;
    [$c, $j] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/budget/prefill', ['month' => $ym, 'branchId' => $b1]);
    ok('الملء 200 وضاف بنود', $c === 200 && (int) ($j['added'] ?? 0) > 0, (string) $c . ' ' . json_encode($j));
    [, $P] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/budget?month={$ym}&branchId={$b1}");
    $items = $P['branches'][0]['items'];
    $cnt = fn (string $cat) => count(array_filter($items, fn ($i) => $i['category'] === $cat));
    ok("كل موظف بند ({$staffN}) وكل طيار بند ({$pilotN})", $cnt('staff') >= $staffN && $cnt('pilot_hours') === $pilotN, $cnt('staff') . '/' . $cnt('pilot_hours'));
    ok('العمولة ورسوم التطوير لكل أوردر، والإيجار والمرافق صفوف فاضية', ($pilotN === 0 || $cnt('commission') === 1) && $cnt('rent') === 1 && $cnt('utilities') === 1);
    $ph = array_values(array_filter($items, fn ($i) => $i['category'] === 'pilot_hours'));
    if ($ph) {
        ok('بند الطيار لكل ساعة بساعات 30 × ساعات الوردية', $ph[0]['kind'] === 'per_hour' && $near($ph[0]['qty'], 30 * (float) $P['settings']['shiftHours']), json_encode($ph[0], JSON_UNESCAPED_UNICODE));
    }
    ok('الافتراضات اتملت من الحقيقي (متوسط السعر رقم)', isset($P['branches'][0]['assumptions']['avgPrice']));
    $n1 = count($items);
    [$c, $j2] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/budget/prefill', ['month' => $ym, 'branchId' => $b1]);
    [, $P2] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/budget?month={$ym}&branchId={$b1}");
    ok('🔴 تاني مرة: مافيش تكرار ولا لمس', (int) ($j2['added'] ?? -1) === 0 && count($P2['branches'][0]['items']) === $n1, ($j2['added'] ?? '؟') . ' / ' . count($P2['branches'][0]['items']));

    echo "\n══ 7) تصنيف المصروف ══\n";
    [$c, $e] = hit($kernel, $admin, 'POST', '/api/expenses', ['date' => date('Y-m-d'), 'item' => 'إيجار فحص', 'amount' => 10, 'category' => 'rent', 'branchId' => $b1]);
    ok('مصروف بتصنيف rent → الرد فيه category', $c === 200 && ($e['expense']['category'] ?? null) === 'rent', (string) $c . ' ' . json_encode($e, JSON_UNESCAPED_UNICODE));
    $eid = (int) ($e['expense']['id'] ?? 0);
    [$c, $e2] = hit($kernel, $admin, 'PUT', '/api/expenses/' . $eid, ['category' => 'utilities']);
    ok('التعديل بيغيّر التصنيف', $c === 200 && ($e2['expense']['category'] ?? null) === 'utilities', (string) $c);
    [$c, $e3] = hit($kernel, $admin, 'POST', '/api/expenses', ['date' => date('Y-m-d'), 'item' => 'حاجة', 'amount' => 5, 'category' => 'xyz', 'branchId' => $b1]);
    ok('تصنيف مش في القايمة → NULL (غير مصنّف)', $c === 200 && array_key_exists('category', $e3['expense'] ?? []) && $e3['expense']['category'] === null, (string) $c . ' ' . json_encode($e3, JSON_UNESCAPED_UNICODE));
    [, $lst] = hit($kernel, $admin, 'GET', '/api/expenses?branchId=' . $b1);
    $found = array_values(array_filter($lst['items'] ?? [], fn ($x) => (int) $x['id'] === $eid));
    ok('والقايمة بترجّعه', $found && $found[0]['category'] === 'utilities', json_encode(array_slice($lst['items'] ?? $lst, 0, 2), JSON_UNESCAPED_UNICODE));
} finally {
    DB::rollBack();
}

echo "\n══ 8) الواجهة ══\n";
$acc = file_get_contents($ROOT . '/public/accounts.html');
ok('تبويب التقارير موجود ومربوط بـpage.reports', str_contains($acc, 'id="patab-reports"') && str_contains($acc, '<div class="page" id="pa-reports">')
    && preg_match("/\\['daily', 'pilot', 'month', 'deferred', 'staff', 'treasury', 'reports'\\]\\.forEach/", $acc) === 1);
ok('الشاشة بتقرا وبتكتب على مسار budget وبتملأ الافتراضي', str_contains($acc, '"/api/pilot-accounting/budget?"') && str_contains($acc, '"/api/pilot-accounting/budget/prefill"') && str_contains($acc, '"/api/pilot-accounting/budget/" + id'));
ok('بلوك الإجماليات فيه أوردرات التعادل وإجمالي الشركة', str_contains($acc, 'أوردرات التعادل') && str_contains($acc, 'إجمالي الشركة'));
$tiar = file_get_contents($ROOT . '/public/tiar.html');
ok('فورم المصروف في الإدارة فيه التصنيف وبيبعته', str_contains($tiar, 'id="expenseCategory"') && substr_count($tiar, 'category') >= 4);

echo "\n════════════════════════════════════════\n";
echo "BUDGET: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
