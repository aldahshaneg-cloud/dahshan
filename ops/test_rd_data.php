<?php
/**
 * 📊 حارس: روح دمشق بعد نقل بيانات Firebase — الأرقام على السيرفر = أرقام
 *    البرنامج القديم، والمُلّاك مخفيين صح، ومشرفي دمشق بيدخلوا.
 *
 * ═══ ليه ═══
 * النقل (ops/import_rd_firebase.php) بيحوّل مفاتيح البرنامج القديم
 * (h/o/svc/psvc/net/adv/ded) لأعمدة rd_entries. لو مفتاح اتحوّل غلط، التقفيلة
 * هتطلع رقم مختلف عن اللي المشرفين متعوّدين عليه من غير أي خطأ ظاهر.
 * الفحص هنا بياخد **ملف التصدير نفسه**، يحسب تقفيلة يوم لفرع بمعادلات الشيت
 * القديم مباشرة، ويقارنها برد `/api/rd/closeout/day` من الكنترولر الحقيقي.
 *
 * التشغيل: php ops/test_rd_data.php [export.json]
 *   من غير ملف بيدوّر على آخر `_backups/firebase-rd-*.json` — ولو مفيش بيتخطّى
 *   الجزء المقارن (بيفضل يفحص الإخفاء والدخول).
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

use App\Wire\DamascusWire as W;
use Illuminate\Support\Facades\DB;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

/** نداء حقيقي على الراوتر بجلسة موظف */
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

/* ── ملف التصدير ── */
$file = $argv[1] ?? null;
if ($file === null) {
    $c = glob(dirname($ROOT) . '/_backups/firebase-rd-*.json') ?: [];
    rsort($c);
    $file = $c[0] ?? null;
}
$fb = ($file && is_file($file)) ? (json_decode((string) file_get_contents($file), true)['rd'] ?? null) : null;

$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
if (! $admin) { echo "مافيش أدمن — تخطّي\n"; exit(0); }

DB::beginTransaction();
try {
    /* حسابين اختبار بدور accountant: واحد من غير view.owner وواحد معاه */
    $mk = function (string $role, ?array $keys) use (&$mk): array {
        $un = 'rdtest_' . substr(bin2hex(random_bytes(3)), 0, 6);
        DB::insert('INSERT INTO users (username, password_hash, role, name, created_at) VALUES (?, ?, ?, ?, NOW())',
            [$un, password_hash('x', PASSWORD_BCRYPT), $role, 'فحص']);
        $id = (int) DB::getPdo()->lastInsertId();
        if ($keys !== null) {
            DB::insert('INSERT INTO rd_perms (username, perm_keys, branches) VALUES (?, ?, NULL)', [$un, json_encode($keys)]);
        }

        return ['id' => $id, 'username' => $un, 'role' => $role, 'branch_id' => null];
    };
    $base = ['page_daily' => true, 'page_pilot' => true, 'page_month' => true, 'act_edit' => true, 'act_dateNav' => true,
             'col_h' => true, 'col_o' => true, 'col_svc' => true, 'col_psvc' => true, 'col_net' => true,
             'col_adv' => true, 'col_ded' => true, 'col_note' => true, 'col_in' => true, 'col_out' => true,
             'blk_cash' => true, 'blk_net' => true, 'sal_block' => true];
    $sup   = $mk('accountant', $base);
    $supVO = $mk('accountant', $base + ['view_owner' => true]);
    $brNo  = $mk('branch', null);          // مشرف فرع دهشان من غير أي صف — مايدخلش
    $brYes = $mk('branch', $base);         // زي «ibrahim» على الإنتاج: دور branch وصف صلاحيات

    echo "\n══ 1) الدخول للبرنامج ══\n";
    [$c] = hit($kernel, $admin, 'GET', '/api/rd/bootstrap');
    ok('الأدمن بيفتح bootstrap', $c === 200, (string) $c);
    [$c] = hit($kernel, $sup, 'GET', '/api/rd/bootstrap');
    ok('محاسب معاه صلاحيات بيفتح', $c === 200, (string) $c);
    [$c] = hit($kernel, $brYes, 'GET', '/api/rd/bootstrap');
    ok('🔴 مشرف فرع (دور branch) معاه صف rd_perms بيفتح — زي حسابات دمشق المتعملة من الشاشة', $c === 200, (string) $c);
    [$c] = hit($kernel, $brNo, 'GET', '/api/rd/bootstrap');
    ok('🔴 مشرف فرع من غير صف صلاحيات مايفتحش (مافيش تسريب أسعار)', $c === 403, (string) $c);

    echo "\n══ 2) المُلّاك: ظاهرين في اليومي، مخفيين في الشهر والكشف عن غير المصرّح له ══\n";
    $owners = array_map(fn ($r) => (int) $r->id, DB::select("SELECT id FROM rd_pilots WHERE job = ? AND active = 1", [W::OWNER_JOB]));
    $ym = (string) (DB::select('SELECT month FROM rd_entries ORDER BY month DESC LIMIT 1')[0]->month ?? substr(W::today(), 0, 7));
    if ($owners) {
        [$c, $j] = hit($kernel, $sup, 'GET', '/api/rd/closeout/month?month=' . $ym);
        $ids = array_map(fn ($p) => (int) $p['pilotId'], $j['pilots'] ?? []);
        ok('من غير view.owner: كشف الشهر مافيهوش أي مالك', $c === 200 && ! array_intersect($ids, $owners), $c . ' / ' . count(array_intersect($ids, $owners)));
        [$c, $j] = hit($kernel, $supVO, 'GET', '/api/rd/closeout/month?month=' . $ym);
        $ids = array_map(fn ($p) => (int) $p['pilotId'], $j['pilots'] ?? []);
        ok('مع view.owner: المُلّاك ظاهرين', $c === 200 && count(array_intersect($ids, $owners)) === count($owners), $c . ' / ' . count(array_intersect($ids, $owners)));
        [$c] = hit($kernel, $sup, 'GET', '/api/rd/pilot-sheet?month=' . $ym . '&pilotId=' . $owners[0]);
        ok('كشف المالك مرفوض 403 من غير view.owner', $c === 403, (string) $c);
        [$c] = hit($kernel, $supVO, 'GET', '/api/rd/pilot-sheet?month=' . $ym . '&pilotId=' . $owners[0]);
        ok('كشف المالك بيفتح مع view.owner', $c === 200, (string) $c);
        [$c, $j] = hit($kernel, $sup, 'GET', '/api/rd/closeout/day?month=' . $ym . '&day=1');
        $rows = array_map(fn ($r) => (int) $r['pilotId'], $j['rows'] ?? []);
        ok('التقفيل اليومي بيعرض المالك عادي (إجمالي الفرع لازم يطابق النقدية)',
            $c === 200 && array_intersect($rows, $owners) !== [], $c . ' / rows=' . count($rows));
    } else {
        echo "  (مافيش مُلّاك في القاعدة — اتخطّى)\n";
    }

    echo "\n══ 3) الأرقام = معادلات الشيت القديم على ملف التصدير ══\n";
    if (! $fb) {
        echo "  (مافيش ملف تصدير — اتخطّى)\n";
    } else {
        /* نفس branchDayCloseout في index.html القديم، محسوبة من JSON مباشرة */
        $S = $fb['settings'];
        $num = fn ($v) => is_numeric($v) ? (float) $v : 0.0;
        $r2 = fn ($v) => round($v, 2);
        $rateO = fn (array $p) => $num($p['orderRate'] ?? 0) > 0 ? $num($p['orderRate']) : $num($S['pilotOrderRate'] ?? 0);
        $rateH = fn (array $p) => $num($p['hourRate'] ?? 0) > 0 ? $num($p['hourRate']) : $num($S['hourRate'] ?? 0);
        $days = function ($node): array {
            $o = [];
            foreach ((array) $node as $k => $v) { if ((int) $k >= 1 && is_array($v) && $v) { $o[(int) $k] = $v; } }

            return $o;
        };
        $parse = function ($t): ?int { return preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) $t), $m) && $m[1] <= 23 && $m[2] <= 59 ? (int) $m[1] * 60 + (int) $m[2] : null; };
        $hoursOf = function (array $e) use ($parse): float {
            if (isset($e['h']) && $e['h'] !== '') { return (float) $e['h']; }
            $a = $parse($e['in'] ?? null); $b = $parse($e['out'] ?? null);
            if ($a === null || $b === null) { return 0.0; }
            $d = $b - $a; if ($d < 0) { $d += 1440; }
            foreach ((array) ($e['perms'] ?? []) as $p) { $x = $parse($p['out'] ?? null); $y = $parse($p['in'] ?? null); if ($x !== null && $y !== null) { $q = $y - $x; if ($q < 0) { $q += 1440; } $d -= $q; } }

            return round(max(0, $d) / 60, 2);
        };
        $psvcOf = fn (array $e, array $p) => (isset($e['psvc']) && $e['psvc'] !== '') ? $num($e['psvc']) : $r2($num($e['o'] ?? 0) * $rateO($p));
        $netOf  = fn (array $e, array $p) => (isset($e['net']) && $e['net'] !== '') ? $num($e['net']) : $r2($num($e['svc'] ?? 0) - $psvcOf($e, $p));

        $checked = 0;
        foreach (['2026-09', '2026-08'] as $ymX) {
            if (! isset($fb['entries'][$ymX])) { continue; }
            foreach (['b1', 'b2', 'b3', 'b4'] as $bk) {
                if (! isset($fb['branches'][$bk])) { continue; }
                $bid = (int) (DB::select('SELECT id FROM rd_branches WHERE legacy_key = ?', [$bk])[0]->id ?? 0);
                if (! $bid) { continue; }
                // أول يوم فيه بيانات للفرع ده
                $dayX = null;
                foreach ($days($fb['summary'][$ymX][$bk] ?? []) as $d => $s) { $dayX = $d; break; }
                if ($dayX === null) { continue; }
                $t = ['h' => 0.0, 'o' => 0.0, 'net' => 0.0, 'adv' => 0.0, 'hourPay' => 0.0];
                $allO = 0.0;
                foreach ($fb['pilots'] as $pk => $p) {
                    $e = $days($fb['entries'][$ymX][$pk] ?? [])[$dayX] ?? null;
                    if (! $e) { continue; }
                    $allO += $num($e['o'] ?? 0);
                    if (($p['branchId'] ?? '') !== $bk || (array_key_exists('active', $p) && ! $p['active'])) { continue; }
                    $h = $hoursOf($e);
                    $t['h'] += $h; $t['o'] += $num($e['o'] ?? 0); $t['net'] += $netOf($e, $p);
                    $t['adv'] += $num($e['adv'] ?? 0); $t['hourPay'] += $h * $rateH($p);
                }
                foreach ($t as $k => $v) { $t[$k] = $r2($v); }
                $s = $days($fb['summary'][$ymX][$bk] ?? [])[$dayX] ?? [];
                $dfb = (string) ($S['devFeeBranchId'] ?? '');
                $devOrders = $dfb !== '' ? ($bk === $dfb ? $allO : 0.0) : $t['o'];
                $devFee = $r2($devOrders * $num($S['orderRate'] ?? 0));
                $pct = $num($s['pct'] ?? 0); $ext = $num($s['ext'] ?? 0); $exp = $num($s['exp'] ?? 0);
                $out = $r2($t['hourPay'] + $devFee + $pct + $ext + $exp);
                $expected = $r2($t['net'] - $pct - $ext - $exp - $t['adv'] - $devFee);
                $hasRecv = array_key_exists('recv', $s) && $s['recv'] !== '' && $s['recv'] !== null;
                $diff = $hasRecv ? $r2($num($s['recv']) - $expected) : 0.0;
                $net = $r2($t['net'] - $out + $diff);

                [$c, $j] = hit($kernel, $admin, 'GET', "/api/rd/closeout/day?month={$ymX}&day={$dayX}");
                $api = null;
                foreach ($j['branches'] ?? [] as $b) { if ((int) $b['branchId'] === $bid) { $api = $b; } }
                $label = "{$ymX}-{$dayX} {$fb['branches'][$bk]['name']}";
                ok("$label: نقدي {$t['net']} · متوقع {$expected} · صافي {$net} · أجر {$t['hourPay']} · رسوم {$devFee}",
                    $c === 200 && $api && abs($api['cash'] - $t['net']) < 0.011 && abs($api['expected'] - $expected) < 0.011
                    && abs($api['net'] - $net) < 0.011 && abs($api['hourPay'] - $t['hourPay']) < 0.011 && abs($api['devFee'] - $devFee) < 0.011,
                    $api ? "api: نقدي {$api['cash']} · متوقع {$api['expected']} · صافي {$api['net']} · أجر {$api['hourPay']} · رسوم {$api['devFee']}" : "HTTP $c");
                $checked++;
            }
        }
        ok('اتقارن ٤ فروع على الأقل', $checked >= 4, (string) $checked);

        /* كشف طيار: الساعات والسلف والخصومات لطيار عنده الثلاثة */
        $target = null;
        foreach ($fb['entries'][$ym] ?? [] as $pk => $dn) {
            foreach ($days($dn) as $d => $e) { if (isset($e['h'], $e['adv'], $e['ded'])) { $target = [$pk, $d, $e]; break 2; } }
        }
        if ($target) {
            [$pk, $d, $e] = $target;
            $pid = (int) (DB::select('SELECT id FROM rd_pilots WHERE legacy_key = ?', [$pk])[0]->id ?? 0);
            [$c, $j] = hit($kernel, $admin, 'GET', "/api/rd/pilot-sheet?month={$ym}&pilotId={$pid}");
            $row = null;
            foreach ($j['days'] ?? [] as $r) { if ((int) $r['day'] === $d) { $row = $r; } }
            ok("كشف الطيار يوم $d: ساعات {$e['h']} · سلف {$e['adv']} · خصم {$e['ded']} زي Firebase",
                $c === 200 && $row && abs($row['hours'] - (float) $e['h']) < 0.011 && abs($row['adv'] - (float) $e['adv']) < 0.011 && abs($row['ded'] - (float) $e['ded']) < 0.011,
                $row ? "api: {$row['hours']} / {$row['adv']} / {$row['ded']}" : "HTTP $c");
        }
    }
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "RD DATA: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
