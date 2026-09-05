<?php
/**
 * 💡 حارس: توصيات التقارير (المرحلة ٤ — 2026-09-05).
 *
 * ═══ الطلب (صاحب النظام) ═══
 * «توصيات — والتوصيات تكون ذكية بناءً على حالات كثيرة».
 *
 * ═══ العقود المثبتة ═══
 * • محرّك قواعد: كل قاعدة بشرط رقمي وحدّ من الإعدادات، وبتطلع بالدليل والإجراء.
 * • الحالات: تحت/فوق التعادل · خسارة متوقعة آخر الشهر · طيارين قاعدين · العمولات
 *   ماكلة الإيراد · بند أعلى من المتوقع · مش في الميزانية · السعر نازل · مقارنة
 *   بالشهر اللي فات · عهدة واقفة · مصروفات غير مصنّفة · موظفين بلا سعر · مافيش
 *   مصروفات متسجّلة · فروع خسرانة (للإجمالي).
 * • الترتيب حرج ← تحذير ← معلومة ← كويس. الأرقام الصغيرة (أقل من الحد) مابتحكمش.
 * • الحدود بتتعدّل من إعدادات البرنامج (act.settings) وبترجع في رد التقرير.
 *
 * التشغيل: php ops/test_pa_recommendations.php
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

$thr = W::reportThresholds(null);
$keys = fn (array $recs) => array_map(fn ($r) => $r['key'], $recs);
$has  = fn (array $recs, string $k) => in_array($k, $keys($recs), true);
$find = fn (array $recs, string $k) => array_values(array_filter($recs, fn ($r) => $r['key'] === $k))[0] ?? null;

/* فرع بميزانية: ثابت 15000، متغيّر 8، سعر 32 ⇒ الهامش 24، التعادل 625/شهر = 21/يوم */
$budget = W::budgetTotals([
    ['category' => 'rent', 'kind' => 'fixed', 'amount' => 6000, 'rate' => 0],
    ['category' => 'pilot_hours', 'kind' => 'per_hour', 'amount' => 9000, 'rate' => 10],
    ['category' => 'commission', 'kind' => 'per_order', 'amount' => 12000, 'rate' => 8],
], 50, 32);
$mk = function (array $actual, int $elapsed, array $daily = [], bool $hasBudget = true) use ($budget): array {
    return ['name' => 'فحص', 'hasBudget' => $hasBudget, 'budget' => $budget, 'compare' => W::reportCompare($budget, $actual, $elapsed, 30), 'daily' => $daily];
};
$dailyOf = fn (int $days, int $orders, float $hours) => array_map(fn ($d) => ['day' => $d, 'orders' => $orders, 'revenue' => $orders * 30, 'commission' => $orders * 8, 'hours' => $hours], range(1, 30));

echo "══ 1) تحت التعادل + خسارة متوقعة ══\n";
$blk = $mk(['byCategory' => ['rent' => 6000, 'pilot_hours' => 4500, 'commission' => 1200], 'revenue' => 4500, 'orders' => 150], 15, $dailyOf(15, 10, 40));
$r = W::reportRecommendations($blk, [], $thr);
ok('🔴 «تحت التعادل» حرج وبيقول الناقص كام/يوم', $has($r, 'below_breakeven') && $find($r, 'below_breakeven')['severity'] === 'critical' && str_contains($find($r, 'below_breakeven')['title'], '11'), json_encode($find($r, 'below_breakeven'), JSON_UNESCAPED_UNICODE));
ok('والإجراء فيه بديل خفض الثابت (11 × 24 × 30 = 7,920)', str_contains($find($r, 'below_breakeven')['action'], '7,920'));
ok('🔴 «الشهر هيقفل خسارة» حرج ومعاه المطلوب باقي الشهر', $has($r, 'month_loss') && str_contains($find($r, 'month_loss')['action'], 'أوردر/يوم'), json_encode($find($r, 'month_loss'), JSON_UNESCAPED_UNICODE));
ok('طيارين قاعدين: 150 أوردر ÷ 600 ساعة = 0.25 < 1.5', $has($r, 'idle_pilots'), implode(',', $keys($r)));
ok('الترتيب: الحرج الأول', $r[0]['severity'] === 'critical');
ok('كل توصية فيها دليل وإجراء', array_reduce($r, fn ($c, $x) => $c && $x['evidence'] !== '' && $x['action'] !== '', true));

echo "\n══ 2) فوق التعادل ومافيش تنبيهات غلط ══\n";
$blk = $mk(['byCategory' => ['rent' => 3000, 'pilot_hours' => 4500, 'commission' => 7200], 'revenue' => 28800, 'orders' => 900], 15, $dailyOf(15, 60, 30));
$r = W::reportRecommendations($blk, ['expensesCount' => 3], $thr);
ok('«فوق التعادل» كويس', $has($r, 'above_breakeven') && $find($r, 'above_breakeven')['severity'] === 'good');
ok('مافيش تحت التعادل ولا خسارة ولا قاعدين (900 ÷ 450 = 2 أوردر/ساعة)', ! $has($r, 'below_breakeven') && ! $has($r, 'month_loss') && ! $has($r, 'idle_pilots'), implode(',', $keys($r)));
ok('العمولة 25% أقل من الحد 35% → مافيش تنبيه', ! $has($r, 'commission_share'));

echo "\n══ 3) العمولات والبنود والسعر والاتجاه والعهدة ══\n";
$blk = $mk(['byCategory' => ['rent' => 9000, 'pilot_hours' => 4500, 'commission' => 12000, 'marketing' => 500], 'revenue' => 27000, 'orders' => 900], 15, $dailyOf(15, 60, 30));
$r = W::reportRecommendations($blk, ['prevOrdersPerDay' => 80, 'custody' => 5000, 'staffNoRate' => 2], $thr);
ok('العمولات 44% من الإيراد → تحذير', $has($r, 'commission_share'), implode(',', $keys($r)));
ok('الإيجار 9000 قصاد 3000 متوقع → «أعلى من المتوقع»', $has($r, 'over_rent') && str_contains($find($r, 'over_rent')['title'], '6,000'));
ok('التسويق مش في الميزانية → معلومة', $has($r, 'unplanned_marketing') && $find($r, 'unplanned_marketing')['severity'] === 'info');
ok('السعر 30 قصاد 32 — أقل من 10% → مافيش تنبيه سعر', ! $has($r, 'avg_price_drop'));
ok('🔴 الأوردرات 60/يوم قصاد 80 الشهر اللي فات = −25% → تحذير', $has($r, 'trend_down') && str_contains($find($r, 'trend_down')['title'], '25'));
ok('عهدة 5000 فوق الحد 2000 → تحذير', $has($r, 'custody_high'));
ok('موظفين بلا سعر → معلومة', $has($r, 'staff_no_rate'));
$blk2 = $mk(['byCategory' => ['rent' => 3000, 'commission' => 7200], 'revenue' => 22500, 'orders' => 900], 15, $dailyOf(15, 60, 30));
$r2 = W::reportRecommendations($blk2, ['prevOrdersPerDay' => 40, 'expensesCount' => 1], $thr);
ok('السعر 25 قصاد 32 (−22%) → تنبيه بالتعادل الجديد', $has($r2, 'avg_price_drop') && str_contains($find($r2, 'avg_price_drop')['action'], 'التعادل'), json_encode($find($r2, 'avg_price_drop'), JSON_UNESCAPED_UNICODE));
ok('الأوردرات طالعة +50% → كويس', $has($r2, 'trend_up') && $find($r2, 'trend_up')['severity'] === 'good');

echo "\n══ 4) بيانات ناقصة والإجمالي ══\n";
$blk = $mk(['byCategory' => ['pilot_hours' => 4500, 'commission' => 7200], 'revenue' => 28800, 'orders' => 900, 'uncategorized' => 250], 15, $dailyOf(15, 60, 30));
$r = W::reportRecommendations($blk, ['expensesCount' => 0], $thr);
ok('🔴 مافيش مصروفات متسجّلة والميزانية فيها إيجار → تحذير إن الربح أعلى من الحقيقة', $has($r, 'no_expenses') && str_contains($find($r, 'no_expenses')['evidence'], 'أعلى من الحقيقة'));
ok('غير المصنّف → معلومة', $has($r, 'uncategorized'));
$r = W::reportRecommendations($mk(['byCategory' => [], 'revenue' => 100, 'orders' => 3], 2, [], false), [], $thr);
ok('من غير ميزانية → «اكتب الميزانية» ومافيش أحكام على 3 أوردرات', $has($r, 'no_budget') && ! $has($r, 'idle_pilots') && ! $has($r, 'commission_share'), implode(',', $keys($r)));
$co = $mk(['byCategory' => ['rent' => 3000], 'revenue' => 28800, 'orders' => 900], 15, $dailyOf(15, 60, 30));
$r = W::reportRecommendations($co, ['branches' => [['name' => 'أ', 'profit' => 500, 'breakEven' => 'above'], ['name' => 'ب', 'profit' => -1200, 'breakEven' => 'below']]], $thr);
ok('الإجمالي: الفروع الخسرانة بالاسم', $has($r, 'branches_losing') && str_contains($find($r, 'branches_losing')['title'], 'ب'));

echo "\n══ 5) الحدود ══\n";
$t2 = W::reportThresholds(['idleOrdersPerHour' => 0.1, 'bogus' => 9, 'custodyMax' => -5]);
ok('الحد المعروف بيتغيّر، والمجهول والسالب بيتطنّشوا', $t2['idleOrdersPerHour'] == 0.1 && ! isset($t2['bogus']) && $t2['custodyMax'] == 2000);
$blk = $mk(['byCategory' => ['rent' => 6000, 'commission' => 1200], 'revenue' => 4500, 'orders' => 150], 15, $dailyOf(15, 10, 40));
ok('🔴 بحد 0.1 «الطيارين قاعدين» بتختفي', ! $has(W::reportRecommendations($blk, [], $t2), 'idle_pilots'));

$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
if ($admin) {
    echo "\n══ 6) المسار والإعدادات ══\n";
    DB::beginTransaction();
    try {
        $ym = date('Y-m');
        [$c, $R] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/reports?month={$ym}");
        ok('التقرير فيه توصيات لكل فرع وللإجمالي والحدود', $c === 200 && isset($R['thresholds']['idleOrdersPerHour'])
            && array_reduce($R['branches'] ?? [], fn ($k, $b) => $k && is_array($b['recommendations'] ?? null), true)
            && (($R['company'] ?? null) === null || is_array($R['company']['recommendations'] ?? null)), (string) $c);
        [$c, $S] = hit($kernel, $admin, 'PUT', '/api/pilot-accounting/settings', ['reportThresholds' => ['custodyMax' => 123456]]);
        ok('حفظ الحدود من الإعدادات 200 وبترجع', $c === 200 && (float) ($S['settings']['reportThresholds']['custodyMax'] ?? 0) == 123456, (string) $c . ' ' . json_encode($S['settings']['reportThresholds'] ?? null));
        [, $R2] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/reports?month={$ym}");
        ok('🔴 والتقرير بيقرا الحد الجديد', (float) ($R2['thresholds']['custodyMax'] ?? 0) == 123456);
        [, $S2] = hit($kernel, $admin, 'GET', '/api/pilot-accounting/settings');
        ok('وباقي الإعدادات زي ما هي', isset($S2['settings']['orderRate']) && (float) ($S2['settings']['reportThresholds']['idleOrdersPerHour'] ?? 0) == 1.5);
    } finally {
        DB::rollBack();
    }
}

echo "\n══ 7) الواجهة ══\n";
$acc = file_get_contents($ROOT . '/public/accounts.html');
ok('بلوك التوصيات بالدليل والإجراء', str_contains($acc, 'class="rp-recs"') && str_contains($acc, 'r.evidence') && str_contains($acc, 'r.action'));
ok('الحدود في شاشة الإعدادات وبتتبعت', str_contains($acc, 'حدود توصيات التقارير') && str_contains($acc, 'reportThresholds }'));

echo "\n════════════════════════════════════════\n";
echo "RECOMMENDATIONS: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
