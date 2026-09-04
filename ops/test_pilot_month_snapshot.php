<?php
/**
 * 📸 حارس: الشهر المقفول أرقامه ثابتة — لقطة وقت القفل.
 *
 * ═══ البلاغ (صاحب النظام 2026-09-04) ═══
 * «الشهر المقفول أرقامه بتتغيّر بعد القفل» — القفل كان بيمنع الكتابة في
 * الشيت بس، وتغيير سعر الساعة أو رسوم التطوير بعده كان بيعيد حساب الشهر.
 *
 * ═══ الفحص بينفّذ الراوتر الحقيقي ═══
 * ① يقرا الشهر حي، يقفله، يغيّر سعر الساعة الافتراضي وسعر طيار، يقرا تاني:
 *    نفس الأرقام (اللقطة) + علامة snapshot.
 * ② نطاق فرع من اللقطة العامة بيتقصّ صح، ومحاسب بصلاحيات محدودة بياخد
 *    اللقطة مقصوصة على أعمدته.
 * ③ تقفيلة الموظفين كمان من اللقطة، وتطبيق الطيار بيلاقي تقفيلته في
 *    pilot_monthly_closeouts.
 * ④ فتح الشهر بيرجّع الحساب الحي (الأرقام بتتغيّر) وبيشيل الصفوف.
 * كله جوه معاملة بتترجع.
 *
 * التشغيل: php ops/test_pilot_month_snapshot.php
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

$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
if (! $admin) { echo "مافيش أدمن — تخطّي\n"; exit(0); }
/* شهر فيه ساعات فعلًا — آخر شهر عنده ورديات مقفولة */
$ymRow = DB::select("SELECT DATE_FORMAT(started_at, '%Y-%m') ym FROM shifts WHERE ended_at IS NOT NULL ORDER BY started_at DESC LIMIT 1")[0] ?? null;
$ym = $ymRow ? (string) $ymRow->ym : date('Y-m');

DB::beginTransaction();
try {
    // نتأكد إن الشهر مفتوح في الأول
    DB::delete('DELETE FROM pilot_month_locks WHERE month = ?', [$ym]);
    DB::delete('DELETE FROM pilot_acct_snapshots WHERE month = ?', [$ym]);

    echo "\n══ 1) حي → قفل → تغيير الأسعار → نفس الأرقام ══\n";
    [$c, $live] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}");
    ok('الشهر مفتوح وحي', $c === 200 && empty($live['locked']) && ! isset($live['snapshot']), (string) $c);
    $withHours = null;
    foreach ($live['pilots'] ?? [] as $p) { if ((float) ($p['totals']['hours'] ?? 0) > 0) { $withHours = $p; break; } }
    ok('فيه طيار بساعات في الشهر', $withHours !== null);
    $pid = (int) ($withHours['pilotId'] ?? 0);
    $liveHourPay = (float) ($withHours['totals']['hourPay'] ?? 0);
    $liveNet = (float) ($live['closeout']['all']['net'] ?? 0);

    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/lock', ['month' => $ym]);
    ok('القفل 200', $c === 200, (string) $c);
    $snapRow = DB::select('SELECT id, branch_id, locked_by FROM pilot_acct_snapshots WHERE month = ?', [$ym]);
    ok('🔴 صف اللقطة اتكتب (نطاق الشركة = 0)', count($snapRow) === 1 && (int) $snapRow[0]->branch_id === 0);
    $clo = (int) DB::select("SELECT COUNT(*) c FROM pilot_monthly_closeouts WHERE month = ? AND closed_by LIKE 'pilotacct:%'", [$ym])[0]->c;
    ok('📱 صفوف تقفيلة الطيارين للتطبيق اتكتبت', $clo === count($live['pilots'] ?? []), "$clo");

    // تغييرات كانت بتعيد الحساب: سعر الساعة الافتراضي × 3 وسعر الطيار نفسه
    [$c] = hit($kernel, $admin, 'PUT', '/api/pilot-accounting/settings', ['hourRate' => 999, 'orderRate' => 50]);
    ok('تغيير الإعدادات بعد القفل مسموح (بيأثر على الشهور المفتوحة بس)', $c === 200, (string) $c);
    DB::update('UPDATE pilots SET hour_rate = 777 WHERE id = ?', [$pid]);

    [$c, $after] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}");
    $p2 = null;
    foreach ($after['pilots'] ?? [] as $p) { if ((int) $p['pilotId'] === $pid) { $p2 = $p; } }
    ok('مقفول وعليه علامة اللقطة', $c === 200 && ! empty($after['locked']) && ! empty($after['snapshot']['lockedAt']));
    ok('🔴 أجر ساعات الطيار زي ما كان قبل تغيير الأسعار', $p2 && $near($p2['totals']['hourPay'], $liveHourPay), ($p2['totals']['hourPay'] ?? '؟') . " vs {$liveHourPay}");
    ok('🔴 صافي كل الفروع زي ما كان', $near($after['closeout']['all']['net'] ?? -1, $liveNet), ($after['closeout']['all']['net'] ?? '؟') . " vs {$liveNet}");
    ok('الإعدادات المعروضة هي إعدادات وقت القفل', $near($after['settings']['hourRate'] ?? -1, $live['settings']['hourRate'] ?? -2));

    echo "\n══ 2) النطاق والصلاحيات على اللقطة ══\n";
    $bid = (int) ($withHours['branchId'] ?? 0);
    if ($bid) {
        [$c, $br] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}&branchId={$bid}");
        $onlyBranch = $c === 200 && ! empty($br['pilots']) && count(array_filter($br['pilots'], fn ($p) => (int) $p['branchId'] !== $bid)) === 0;
        ok('فرع واحد من اللقطة العامة: طيارينه بس', $onlyBranch);
        ok('وبلوك التقفيل فيه الفرع ده بس', count($br['closeout']['branches'] ?? []) === 1 && (int) $br['closeout']['branches'][0]['branchId'] === $bid);
        [$c, $one] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}&pilotId={$pid}");
        ok('فلتر طيار واحد من اللقطة', $c === 200 && count($one['pilots'] ?? []) === 1 && (int) $one['pilots'][0]['pilotId'] === $pid);
    }
    $un = 'snap_' . substr(bin2hex(random_bytes(3)), 0, 6);
    DB::insert('INSERT INTO users (username, password_hash, role, name, created_at) VALUES (?, ?, ?, ?, NOW())', [$un, password_hash('x', PASSWORD_BCRYPT), 'accountant', 'فحص']);
    $uid = (int) DB::getPdo()->lastInsertId();
    DB::insert('INSERT INTO pilot_acct_perms (user_id, perm_keys, branches) VALUES (?, ?, NULL)', [$uid, json_encode(['page.daily' => true, 'page.month' => true, 'col.hours' => true, 'mon.hours' => true])]);
    [$c, $lim] = hit($kernel, ['id' => $uid, 'username' => $un, 'role' => 'accountant', 'branch_id' => null], 'GET', "/api/pilot-accounting/month?month={$ym}");
    $lp = $lim['pilots'][0] ?? null;
    ok('🔴 محاسب محدود بياخد اللقطة مقصوصة (مافيش hourPay ولا netDue)', $c === 200 && $lp && isset($lp['totals']['hours']) && ! isset($lp['totals']['hourPay']) && ! isset($lp['totals']['netDue']), $lp ? json_encode(array_keys($lp['totals'])) : "HTTP $c");
    ok('والبلوك مقصوص كمان', $lp && ! isset($lim['closeout']['all']['net']));

    echo "\n══ 3) الموظفين والتطبيق ══\n";
    [$c, $st] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/staff-month?month={$ym}");
    ok('تقفيلة الموظفين مقفولة ومن اللقطة', $c === 200 && ! empty($st['locked']) && ! empty($st['snapshot']), (string) $c);
    $row = DB::select('SELECT net_due, hours FROM pilot_monthly_closeouts WHERE pilot_id = ? AND month = ?', [$pid, $ym])[0] ?? null;
    ok('صف التطبيق بنفس صافي اللقطة', $row && $near($row->net_due, $withHours['totals']['netDue']) && $near($row->hours, $withHours['totals']['hours']), $row ? "{$row->net_due}" : 'null');

    echo "\n══ 4) الفتح = حساب حي تاني ══\n";
    [$c] = hit($kernel, $admin, 'DELETE', "/api/pilot-accounting/lock?month={$ym}");
    ok('الفتح 200', $c === 200, (string) $c);
    ok('اللقطة وصفوف التطبيق اتشالت', ! DB::select('SELECT id FROM pilot_acct_snapshots WHERE month = ?', [$ym])
        && ! DB::select("SELECT id FROM pilot_monthly_closeouts WHERE month = ? AND closed_by LIKE 'pilotacct:%'", [$ym]));
    [$c, $open] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}");
    $p3 = null;
    foreach ($open['pilots'] ?? [] as $p) { if ((int) $p['pilotId'] === $pid) { $p3 = $p; } }
    ok('🔴 بعد الفتح الأرقام بتتحسب بالسعر الجديد (777 × الساعات)', $p3 && $near($p3['totals']['hourPay'], 777 * (float) $p3['totals']['hours']), (string) ($p3['totals']['hourPay'] ?? '؟'));
    ok('ومافيش علامة لقطة', empty($open['locked']) && ! isset($open['snapshot']));
} finally {
    DB::rollBack();
}

echo "\n══ 5) الواجهة ══\n";
$html = file_get_contents($ROOT . '/public/accounts.html');
ok('شريط القفل بيقول إن الأرقام من اللقطة المعتمدة', str_contains($html, 'اللقطة المعتمدة') && str_contains($html, 'd.snapshot'));

echo "\n════════════════════════════════════════\n";
echo "MONTH SNAPSHOT: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
