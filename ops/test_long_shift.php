<?php
/**
 * ⏱️ حارس: الوردية المقطوعة والاستئذان بالغلط — مراجعة كشف الطيار (2026-09-05).
 *
 * ═══ اللي اتلقى على الإنتاج ═══
 * • ورديات فضلت مفتوحة من ١ لـ ٤ سبتمبر (الطيار نسي يقفل والمشرف قفلها بعدين)
 *   طلّعت ٥٧ ساعة في يوم واحد، والإدارة صلّحت ٤٥ خانة بالإيد.
 * • ١٨ من ٣٥ «استئذان» مدتهم ثواني (موافقة وإنهاء بالغلط) وكانوا بيبانوا في
 *   خروج مؤقت/رجوع وبيتخصموا من الساعات.
 *
 * ═══ العقود المثبتة ═══
 * • وردية أطول من LONG_SHIFT_HOURS (١٦): ساعاتها = ساعات الوردية من الإعدادات،
 *   والصف متعلّم longShift. الوردية العادية زي ما هي.
 * • استئذان أقل من MIN_PERM_MINUTES (٥ دقايق) مش بيظهر ولا بيتخصم؛ الأطول بيظهر.
 * • الواجهة بتعلّم «مقطوعة» في الشيت اليومي وكشف الطيار، والخانة المعدّلة بتقول
 *   إزاي ترجع لرقم النظام.
 * كله جوه معاملة بتترجع.
 *
 * التشغيل: php ops/test_long_shift.php
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
$near = fn ($a, $b) => abs((float) $a - (float) $b) < 0.011;

$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
if (! $admin) { echo "مافيش أدمن — تخطّي\n"; exit(0); }
$pilot = DB::selectOne("SELECT id, assigned_branch_id FROM pilots WHERE archived_at IS NULL ORDER BY id LIMIT 1");
if (! $pilot) { echo "مافيش طيارين — تخطّي\n"; exit(0); }
$pid = (int) $pilot->id;

/* شهر مستقبلي فاضي — اليوم ٥ و١٠ و١٥ منه، الساعة ١١ صباحًا بتوقيت القاهرة (= ٠٨ UTC) */
$ym = date('Y-m', strtotime('+2 month'));
$dayStart = 9;

DB::beginTransaction();
try {
    DB::delete('DELETE FROM pilot_month_locks WHERE month = ?', [$ym]);
    DB::delete('DELETE FROM pilot_acct_snapshots WHERE month = ?', [$ym]);
    DB::delete('DELETE FROM pilot_day_entries WHERE month = ? AND pilot_id = ?', [$ym, $pid]);
    [$c, $S] = hit($kernel, $admin, 'GET', '/api/pilot-accounting/settings');
    $shiftHours = (float) ($S['settings']['shiftHours'] ?? 10);
    ok('ساعات الوردية من الإعدادات معروفة', $shiftHours > 0, (string) $shiftHours);

    $mk = function (int $day, float $hours) use ($pid, $pilot, $ym): int {
        $start = "{$ym}-" . sprintf('%02d', $day) . ' 08:00:00';   // 11 ص القاهرة
        $end   = gmdate('Y-m-d H:i:s', strtotime($start . ' UTC') + (int) round($hours * 3600));
        DB::insert('INSERT INTO shifts (pilot_id, branch_id, status, started_at, ended_at, created_at) VALUES (?,?,?,?,?,NOW())',
            [$pid, $pilot->assigned_branch_id, 'ended', $start, $end]);
        return (int) DB::getPdo()->lastInsertId();
    };
    $mk(5, 57.3);    // مقطوعة — زي اللي حصل
    /* وردية صباحية بتبدأ ٠٨:٣٠ القاهرة يوم ١٢ (قبل بداية اليوم ٩) وتخلص ١٨:٣٠ — لازم تتحسب على يوم ١٢ مش ١١ */
    DB::insert('INSERT INTO shifts (pilot_id, branch_id, status, started_at, ended_at, created_at) VALUES (?,?,?,?,?,NOW())',
        [$pid, $pilot->assigned_branch_id, 'ended', "{$ym}-12 05:30:00", "{$ym}-12 15:30:00"]);
    $mk(10, 12.0);   // عادية طويلة شوية
    $mk(15, 16.0);   // على الحد بالظبط — مش مقطوعة

    /* استئذانات: ١٨ ثانية (بالغلط) و٣٠ دقيقة (حقيقي) في يوم ١٠ */
    $ins = function (int $day, int $secs) use ($pid, $pilot, $ym): void {
        $t0 = "{$ym}-" . sprintf('%02d', $day) . ' 10:00:00';
        $t1 = gmdate('Y-m-d H:i:s', strtotime($t0 . ' UTC') + $secs);
        DB::insert("INSERT INTO pilot_leave_requests (pilot_id, branch_id, type, status, requested_at, responded_at, responded_by, ended_at, ended_by, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,NOW())", [$pid, (int) ($pilot->assigned_branch_id ?: DB::selectOne('SELECT id FROM branches ORDER BY id LIMIT 1')->id), 'rest', 'ended', $t0, $t0, 'test', $t1, 'test']);
    };
    $ins(10, 18);
    $ins(10, 1800);

    [$c, $m] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}&pilotId={$pid}");
    ok('الشهر 200', $c === 200, (string) $c);
    $p = null;
    foreach ($m['pilots'] ?? [] as $x) { if ((int) $x['pilotId'] === $pid) { $p = $x; } }
    $row = fn (int $d) => $p['days'][$d - 1] ?? [];

    echo "\n══ 1) الوردية المقطوعة ══\n";
    ok("🔴 ٥٧ ساعة → اتحسبت {$shiftHours} (ساعات الوردية) ومتعلّمة مقطوعة", $near($row(5)['hours'] ?? -1, $shiftHours) && ($row(5)['longShift'] ?? false) === true, json_encode([$row(5)['hours'] ?? null, $row(5)['longShift'] ?? null]));
    ok('ورقم النظام في auto نفس الرقم — مش ٥٧', $near($row(5)['auto']['hours'] ?? -1, $shiftHours));
    ok('الوردية ١٢ ساعة زي ما هي ومش مقطوعة', $near($row(10)['hours'] ?? -1, 11.5) && ($row(10)['longShift'] ?? true) === false, json_encode($row(10)['hours'] ?? null));
    ok('١٦ ساعة بالظبط (على الحد) مش مقطوعة', $near($row(15)['hours'] ?? -1, 16) && ($row(15)['longShift'] ?? true) === false);
    ok('والمشرف لسه يقدر يكتب رقم بالإيد يغلب', true);
    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/entry', ['month' => $ym, 'pilotId' => $pid, 'day' => 5, 'field' => 'hours', 'value' => 13]);
    [, $m2] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/month?month={$ym}&pilotId={$pid}");
    $p2 = null;
    foreach ($m2['pilots'] ?? [] as $x) { if ((int) $x['pilotId'] === $pid) { $p2 = $x; } }
    ok('التعديل اليدوي ١٣ بيغلب والعلامة فضلت', $c === 200 && $near($p2['days'][4]['hours'], 13) && $p2['days'][4]['longShift'] === true && in_array('hours', $p2['days'][4]['edited']));

    echo "\n══ 1ب) الوردية اللي بتبدأ قبل بداية اليوم ══\n";
    ok('🔴 ٠٨:٣٠ → ١٨:٣٠ اتحسبت على يومها (١٢) بـ١٠ ساعات، وحضورها ٠٨:٣٠', $near($row(12)['hours'] ?? -1, 10) && in_array($row(12)['in'] ?? '', ['08:30', '07:30'], true) /* توقيت صيفي أو شتوي */, json_encode([$row(12)['hours'] ?? null, $row(12)['in'] ?? null]));
    ok('ويوم ١١ فاضي — مش اتحسبت عليه', $near($row(11)['hours'] ?? -1, 0) && ($row(11)['in'] ?? null) === null);
    ok('والوردية المسائية (١١ ص → ٩ م يوم ١٠) فضلت على يومها', $near($row(10)['hours'] ?? -1, 11.5));

    echo "\n══ 2) الاستئذان بالغلط ══\n";
    $perms = $row(10)['perms'] ?? [];
    $mins = count($perms) === 1 ? W::permMinutes($perms) : -1;
    ok('🔴 استئذان ١٨ ثانية مش موجود، و٣٠ دقيقة موجود (واحد بس ومدته ٣٠ دقيقة)', count($perms) === 1 && $mins === 30, json_encode($perms));
    ok('وساعات اليوم اتخصم منها نص ساعة بس (١٢ − ٠٫٥)', $near($row(10)['hours'] ?? -1, 11.5), (string) ($row(10)['hours'] ?? '؟'));
    ok('الثوابت: ١٦ ساعة و٥ دقايق', W::LONG_SHIFT_HOURS == 16 && W::MIN_PERM_MINUTES == 5);
} finally {
    DB::rollBack();
}

echo "\n══ 3) الواجهة ══\n";
$acc = file_get_contents($ROOT . '/public/accounts.html');
ok('شارة «مقطوعة» في الشيت اليومي وكشف الطيار', substr_count($acc, 'row.longShift ?') >= 2 && substr_count($acc, '⚠️ مقطوعة') >= 2);
ok('الخانة المعدّلة بتقول إزاي ترجع لرقم النظام', str_contains($acc, 'امسح الخانة واضغط Enter ترجع لرقم النظام'));

echo "\n════════════════════════════════════════\n";
echo "LONG SHIFT: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
