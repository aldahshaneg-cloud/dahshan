<?php
/**
 * 💵 حارس: العمولة في نفس اليوم عند إنهاء الوردية + «ياخد كام من الطيار».
 *
 * ═══ الطلب (صاحب النظام 2026-09-04) ═══
 * «العمولة مش على الشهر بتتسلم في نفس اليوم، والبونص والخصم والسلفة على
 * الشهر. المفروض يظهر للمشرف ياخد كام من الطيار: معاه 3000 عهدة و30 قيمة
 * توصيل، يرجع للخزنة 3022 لأنه هياخد 8 عمولته. والشغل يظهر في تقفيلة
 * الطيارين».
 *
 * ═══ العقود المثبتة ═══
 * • إنهاء الوردية بـcommissionSettle=daily: الخزنة بتسجّل تحصيل الأوردرات
 *   كامل + ردّ العهدة، وعمولة الطيار بتخرج من نفس الخزنة في نفس اللحظة —
 *   فصافي الخزنة = عهدة + تحصيل − عمولة (3022)، والوردية متعلّمة بالصرف.
 * • الرد فيه commissionPaid، وتفاصيل التقفيلة فيها cash.handed = 3022.
 * • «على الشهر» = ولا حركة عمولة، والشهرية بتحسبها.
 * • daily من غير أي خزنة (لا تحصيل ولا ردّ) → مرفوض بطلب الخزنة.
 * • تقفيلة الطيارين: خانة «سلّم للخزنة» في يوم الوردية = 3022 وبتتحكم فيها
 *   col.handed، ومجموعها في totals.
 * • الواجهات: الافتراضي داخل الراديو = العمولة daily والباقي monthly.
 * كله جوه معاملة بتترجع.
 *
 * التشغيل: php ops/test_shift_end_commission.php
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

$sup = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'branch' AND blocked = 0 AND branch_id IS NOT NULL ORDER BY id LIMIT 1")[0] ?? null);
$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
if (! $sup || ! $admin) { echo "مافيش مشرف/أدمن — تخطّي\n"; exit(0); }
$pilotRow = DB::selectOne('SELECT id, name FROM pilots WHERE archived_at IS NULL AND assigned_branch_id = ? ORDER BY id LIMIT 1', [$sup['branch_id']])
    ?? DB::selectOne('SELECT id, name FROM pilots WHERE archived_at IS NULL ORDER BY id LIMIT 1');
if (! $pilotRow) { echo "مافيش طيارين — تخطّي\n"; exit(0); }
$pid = (int) $pilotRow->id;

DB::beginTransaction();
try {
    /* عمولة ثابتة 8 ج للأوردر — نفس مثال صاحب النظام */
    DB::update("UPDATE pilots SET commission_type = 'fixed', commission_value = 8, custody_balance = 3000, assigned_branch_id = ?, status = 'waiting' WHERE id = ?", [$sup['branch_id'], $pid]);
    DB::update("UPDATE orders SET money_settled = 1 WHERE pilot_id = ? AND status = 'delivered' AND money_settled = 0", [$pid]);
    DB::update("UPDATE orders SET pilot_id = NULL, shift_id = NULL WHERE pilot_id = ? AND status = 'delivering'", [$pid]);
    DB::insert('INSERT INTO cash_stores (name, branch_id, balance, created_at) VALUES (?,?,?,NOW())', ['خزنة فحص التسليم', $sup['branch_id'], 1000]);
    $store = (int) DB::getPdo()->lastInsertId();
    $bal = fn () => round((float) DB::selectOne('SELECT balance FROM cash_stores WHERE id = ?', [$store])->balance, 2);

    /* وردية نشطة بأوردر 30 ج جاري التوصيل — التقفيلة هتسلّمه وتحصّله */
    $mkShift = function () use ($pid, $sup): int {
        DB::insert('INSERT INTO shifts (pilot_id, branch_id, status, started_at, created_at) VALUES (?,?,?,?,NOW())',
            [$pid, $sup['branch_id'], 'active', gmdate('Y-m-d H:i:s', time() - 3 * 3600)]);
        return (int) DB::getPdo()->lastInsertId();
    };
    $mkOrder = function (int $shiftId, float $price) use ($pid, $pilotRow, $sup): int {
        DB::insert("INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, status, status_since, pilot_id, pilot_name,
                        shift_id, total_delivery_price, net_delivery_price, money_settled, source, added_by, added_by_role, qr_code, created_at)
                    VALUES (?,?,?,?,?,NOW(),?,?,?,?,?,0,?,?,?,?,NOW())",
            ['HND-' . bin2hex(random_bytes(4)), $sup['branch_id'], $sup['branch_id'], 'راسل فحص', 'delivering',
             $pid, $pilotRow->name, $shiftId, $price, $price, 'branch', 'اختبار', 'branch', 'HND-' . bin2hex(random_bytes(4))]);
        return (int) DB::getPdo()->lastInsertId();
    };

    echo "\n══ 1) عهدة 3000 + تحصيل 30 − عمولة 8 = يسلّم 3022 ══\n";
    $sh = $mkShift();
    $od = $mkOrder($sh, 30);
    $b0 = $bal();
    [$c, $j] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh}/end", [
        'orders' => [['orderId' => $od, 'choice' => 'delivered']],
        'collectedAmount' => 30, 'cashStoreId' => $store,
        'custodyReturn' => ['amount' => 3000, 'cashStoreId' => $store],
        'commissionSettle' => 'daily', 'commissionStoreId' => $store,
    ]);
    ok('الإنهاء 200', $c === 200, (string) $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    ok('🔴 الرد بيقول العمولة اللي اتصرفت = 8', $near($j['commissionPaid'] ?? -1, 8), (string) ($j['commissionPaid'] ?? '؟'));
    ok('🔴 صافي الخزنة زاد 3022 بالظبط (3000 + 30 − 8)', $near($bal(), $b0 + 3022), (string) ($bal() - $b0));
    $tx = DB::select('SELECT type, amount, reason FROM cash_transactions WHERE store_id = ? ORDER BY id', [$store]);
    $kinds = array_map(fn ($t) => $t->type . ':' . (int) $t->amount . ':' . mb_substr((string) $t->reason, 0, 12), $tx);
    ok('تلات حركات: تحصيل 30 داخل · ردّ عهدة 3000 داخل · عمولة 8 خارج', count($tx) === 3
        && count(array_filter($tx, fn ($t) => $t->type === 'in' && $near($t->amount, 30) && str_starts_with($t->reason, 'تحصيل من الطيار'))) === 1
        && count(array_filter($tx, fn ($t) => $t->type === 'in' && $near($t->amount, 3000) && str_starts_with($t->reason, 'ردّ عهدة'))) === 1
        && count(array_filter($tx, fn ($t) => $t->type === 'out' && $near($t->amount, 8) && str_starts_with($t->reason, 'عمولة وردية'))) === 1,
        implode(' | ', $kinds));
    $row = DB::selectOne('SELECT commission_settle, commission_paid_amount, commission_paid_at, status FROM shifts WHERE id = ?', [$sh]);
    ok('🔴 الوردية اتقفلت ومتعلّمة daily ومصروفة 8', $row->status === 'ended' && $row->commission_settle === 'daily' && $near($row->commission_paid_amount, 8) && $row->commission_paid_at !== null);
    ok('عهدة الطيار صفر — أخلى طرف', $near(DB::selectOne('SELECT custody_balance FROM pilots WHERE id = ?', [$pid])->custody_balance, 0));

    [$c, $d] = hit($kernel, $sup, 'GET', "/api/shifts/{$sh}/closeout-details");
    ok('تفاصيل التقفيلة 200', $c === 200, (string) $c);
    ok('🔴 cash.handed = 3022 (تحصيل 30 + عهدة 3000 − عمولة 8)', $near($d['cash']['handed'] ?? -1, 3022)
        && $near($d['cash']['collected'] ?? -1, 30) && $near($d['cash']['custodyReturned'] ?? -1, 3000) && $near($d['cash']['commissionPaid'] ?? -1, 8),
        json_encode($d['cash'] ?? null));

    /* إعادة الحفظ من التقرير مابتصرفش تاني */
    [$c, $j2] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh}/settlement", ['commissionSettle' => 'daily', 'cashStoreId' => $store, 'bonusSettle' => 'monthly']);
    ok('«تطبيق وحفظ» بعدها: 200 ومافيش صرف تاني', $c === 200 && $near($j2['commissionPaid'] ?? -1, 0) && $near($bal(), $b0 + 3022), (string) $c . ' ' . ($j2['commissionPaid'] ?? '؟'));
    [$c] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh}/settlement", ['commissionSettle' => 'monthly']);
    ok('والرجوع «على الشهر» مرفوض', $c >= 400, (string) $c);

    echo "\n══ 2) تقفيلة الطيارين: خانة «سلّم للخزنة» ══\n";
    $ym = date('Y-m');
    [$c, $m] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}&pilotId={$pid}");
    ok('الشهر 200', $c === 200, (string) $c);
    $p = null;
    foreach ($m['pilots'] ?? [] as $x) { if ((int) $x['pilotId'] === $pid) { $p = $x; } }
    $handedDays = array_values(array_filter($p['days'] ?? [], fn ($r) => abs((float) ($r['handed'] ?? 0)) > 0.004));
    ok('🔴 يوم واحد فيه handed = 3022', count($handedDays) === 1 && $near($handedDays[0]['handed'], 3022),
        json_encode(array_map(fn ($r) => [$r['day'], $r['handed'] ?? null], $handedDays)));
    ok('ومجموع الشهر totals.handed = 3022', $near($p['totals']['handed'] ?? -1, 3022), (string) ($p['totals']['handed'] ?? '؟'));
    ok('والعمولة المتصفّاة كاش مش بتتكرر في الراتب', $near($p['totals']['settledDaily']['psvc'] ?? -1, 8) && $near($p['totals']['commission'] ?? -1, 0),
        json_encode($p['totals']['settledDaily'] ?? null) . ' / ' . ($p['totals']['commission'] ?? '؟'));

    /* col.handed بيقفل الخانة */
    $un = 'hnd_' . substr(bin2hex(random_bytes(3)), 0, 6);
    DB::insert('INSERT INTO users (username, password_hash, role, name, branch_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())', [$un, password_hash('x', PASSWORD_BCRYPT), 'branch', 'فحص', $sup['branch_id']]);
    $viewer = ['id' => (int) DB::getPdo()->lastInsertId(), 'username' => $un, 'role' => 'branch', 'branch_id' => $sup['branch_id']];
    DB::statement('INSERT INTO pilot_acct_perms (user_id, perm_keys, branches, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE perm_keys = VALUES(perm_keys)', [$viewer['id'], json_encode(['page.daily', 'page.pilot', 'col.in', 'col.out', 'col.net']), '[]', 'test']);
    [$c, $m2] = hit($kernel, $viewer, 'GET', "/api/pilot-accounting/month?month={$ym}&pilotId={$pid}");
    $p2 = null;
    foreach ($m2['pilots'] ?? [] as $x) { if ((int) $x['pilotId'] === $pid) { $p2 = $x; } }
    ok('🔴 من غير col.handed الخانة مابتوصلش ولا مجموعها', $c === 200 && $p2 !== null
        && ! array_key_exists('handed', $p2['days'][0] ?? ['handed' => 1]) && ! array_key_exists('handed', $p2['totals'] ?? ['handed' => 1]),
        (string) $c);

    echo "\n══ 3) «على الشهر» = زي زمان ══\n";
    DB::update('UPDATE pilots SET custody_balance = 0 WHERE id = ?', [$pid]);
    $sh2 = $mkShift();
    $od2 = $mkOrder($sh2, 50);
    $b1 = $bal();
    [$c, $j] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh2}/end", [
        'orders' => [['orderId' => $od2, 'choice' => 'delivered']],
        'collectedAmount' => 50, 'cashStoreId' => $store, 'commissionSettle' => 'monthly',
    ]);
    ok('الإنهاء 200 وcommissionPaid صفر', $c === 200 && $near($j['commissionPaid'] ?? -1, 0), (string) $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    ok('🔴 الخزنة زادت 50 كاملة — العمولة مرحّلة للشهر', $near($bal(), $b1 + 50), (string) ($bal() - $b1));
    $row2 = DB::selectOne('SELECT commission_settle, commission_paid_at FROM shifts WHERE id = ?', [$sh2]);
    ok('الوردية monthly ومش مصروفة', $row2->commission_settle === 'monthly' && $row2->commission_paid_at === null);

    echo "\n══ 4) daily من غير أي خزنة ══\n";
    $sh3 = $mkShift();
    $od3 = $mkOrder($sh3, 20);
    [$c, $j] = hit($kernel, $sup, 'POST', "/api/shifts/{$sh3}/end", [
        'orders' => [['orderId' => $od3, 'choice' => 'delivered']],
        'collectedAmount' => 0, 'commissionSettle' => 'daily',
    ]);
    /* التحصيل «لم يتم» → 20 عهدة على الطيار → القفل مرفوض أصلًا (العهدة) — الرسالة أيًا كانت، مافيش صرف */
    ok('مرفوض ومافيش عمولة اتصرفت', $c >= 400 && DB::selectOne('SELECT commission_paid_at FROM shifts WHERE id = ?', [$sh3])->commission_paid_at === null, (string) $c);
    DB::update('UPDATE pilots SET custody_balance = 0 WHERE id = ?', [$pid]);
    DB::update("UPDATE orders SET status = 'delivered', delivered_at = NOW(), money_settled = 1 WHERE id = ?", [$od3]);
    [$c, $j] = hit($kernel, $admin, 'POST', "/api/shifts/{$sh3}/end", ['orders' => [], 'collectedAmount' => 0, 'commissionSettle' => 'daily']);
    ok('🔴 وبأوردر متسلّم من غير خزنة: مرفوض بطلب خزنة العمولة', $c >= 400 && str_contains((string) json_encode($j, JSON_UNESCAPED_UNICODE), 'الخزنة'), (string) $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    ok('والوردية فضلت مفتوحة', DB::selectOne('SELECT status FROM shifts WHERE id = ?', [$sh3])->status === 'active');
} finally {
    DB::rollBack();
}

echo "\n══ 5) الواجهات ══\n";
foreach (['tiar', 'branch'] as $pg) {
    $html = file_get_contents($ROOT . "/public/{$pg}.html");
    ok("{$pg}: الافتراضي في الراديو — العمولة daily والباقي monthly",
        str_contains($html, '(name === "srCommissionSettle" ? "daily" : "monthly")') && str_contains($html, 'shift.commissionSettle || "daily"'));
    ok("{$pg}: مودال الإنهاء بيبعت commissionSettle وخزنة العمولة",
        str_contains($html, 'name="_seCommSettle" value="daily" checked') && str_contains($html, 'commissionSettle: commSettle') && str_contains($html, 'commissionStoreId:'));
    ok("{$pg}: «يسلّم للخزنة دلوقتي» في مودال الإنهاء", str_contains($html, 'id="_seHandNet"') && str_contains($html, 'function _seRecalcHand()'));
    ok("{$pg}: التقرير فيه «حساب التسليم — ياخد كام من الطيار»", str_contains($html, 'حساب التسليم — ياخد كام من الطيار') && str_contains($html, 'id="srHandNet"') && str_contains($html, 'window.recomputeShiftHand = function'));
}
$acc = file_get_contents($ROOT . '/public/accounts.html');
ok('accounts: عمود «سلّم للخزنة» في اليومي وكشف الطيار', substr_count($acc, '<th>سلّم للخزنة') === 2 && substr_count($acc, "'col.handed'") === 2 && str_contains($acc, 'paReadCell(row, "handed")'));
ok('accounts: وسطر في بلوك الراتب', str_contains($acc, 'سلّم للخزنة خلال الشهر'));

echo "\n════════════════════════════════════════\n";
echo "SHIFT END COMMISSION: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
