<?php

/**
 * 📅 حارس: اليوم التجاري — النظام مايقلبش التاريخ الساعة 12 بالليل.
 *
 * ═══ البلاغ (صاحب النظام 2026-09-02) ═══
 * «البرنامج بالكامل بيقلب التاريخ بعد الساعة 12 بالليل وده بيعمل مشاكل.
 *  الوردية بتبدأ 9 صباحًا وتنتهي 5 فجرًا — كل ده يوم واحد علشان الحسابات».
 *
 * اللي حصل فعلًا: أوردرات الفرع الساعة 00:32 فجر يوم 2 اتّرقمت
 * HAL-260902-* واتحسبت على يوم جديد، مع إنها من وردية يوم 1.
 *
 * ═══ العقود المثبتة هنا ═══
 * • BizDay: قبل ساعة البداية (9ص قاهرة) = اليوم اللي قبله — بفحص الساعة
 *   المحلية مش بطرح ثابت (عشان أيام تغيير التوقيت الصيفي).
 * • ترقيم الأوردرات: العدّاد **والرقم المطبوع** الاتنين بنفس المفتاح.
 * • جلسة موظف عابرة لنص الليل = صف يوم واحد بساعاتها الصح (مش صفر).
 * • الواجهات الخمسة معاها bizDayKey و«النهارده» بقت تجارية.
 *
 * ⚠️ فيه POST /api/orders حقيقي جوه معاملة بترجع — ممنوع على الإنتاج.
 *
 * التشغيل: php ops/test_business_day.php
 */
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

set_exception_handler(function (Throwable $e): void {
    echo "\n🔴 استثناء مش متمسك: " . get_class($e) . ' — ' . $e->getMessage()
        . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
});
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        exit(1);
    }
});

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

use App\Support\BizDay;
use App\Support\OrderNumber;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$what}\n"; }
    else { $fail++; echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n"; }
}

echo "══ 1) قاعدة اليوم التجاري نفسها ══\n";
$t = fn (string $utc) => strtotime($utc . ' UTC');
ok('🔴 00:32 فجر يوم 2 (قاهرة) = يوم 1 تجاري — قلب البلاغ',
    BizDay::key($t('2026-09-01 21:32:00')) === '2026-09-01',
    BizDay::key($t('2026-09-01 21:32:00')));
ok('05:00 فجرًا (نهاية الوردية) لسه يوم امبارح',
    BizDay::key($t('2026-09-02 02:00:00')) === '2026-09-01');
ok('08:59 صباحًا لسه يوم امبارح', BizDay::key($t('2026-09-02 05:59:00')) === '2026-09-01');
ok('09:00 بالظبط = يوم جديد', BizDay::key($t('2026-09-02 06:00:00')) === '2026-09-02');
ok('الظهر يوم عادي', BizDay::key($t('2026-09-02 09:00:00')) === '2026-09-02');
ok('والمضغوط نفس المفتاح من غير شرط',
    BizDay::keyCompact($t('2026-09-01 21:32:00')) === '20260901');
ok('فجر أول الشهر تبع الشهر اللي فات — الشهرية مابتقفزش بدري',
    BizDay::key($t('2026-09-30 22:00:00')) === '2026-09-30'
    && substr(BizDay::key($t('2026-09-30 22:00:00')), 0, 7) === '2026-09');

echo "\n══ 2) الترقيم: الرقم المطبوع = مفتاح العدّاد ══\n";
ok('format بياخد مفتاح اليوم صراحةً',
    OrderNumber::format('HAL', 7, null, '20260901') === 'HAL-260901-007',
    OrderNumber::format('HAL', 7, null, '20260901'));
$srv = file_get_contents('app/Http/Controllers/Api/OrdersController.php');
$cus = file_get_contents('app/Http/Controllers/Api/CustomerAppController.php');
foreach ([['OrdersController', $srv], ['CustomerAppController', $cus]] as [$n, $src]) {
    $fn = strpos($src, '$allocOrderNum = function ()');
    $cut = substr($src, max(0, $fn - 2500), 3500);
    ok("🔴 {$n}: عدّاد اليوم بمفتاح BizDay", str_contains($cut, 'BizDay::keyCompact()'));
    ok("  والرقم بيتبني بنفس المفتاح (مش بيحسب اليوم تاني لوحده)",
        str_contains($src, ', null, $dayKey)'));
}

echo "\n══ 3) تنفيذ حقيقي: أوردر دلوقتي بياخد مفتاح اليوم التجاري ══\n";
$sup = DB::select("SELECT id, username, role, branch_id FROM users
                    WHERE role='branch' AND blocked=0 AND branch_id IS NOT NULL LIMIT 1")[0] ?? null;
$zone = $sup ? (DB::select('SELECT id, area_name FROM zones WHERE delivery_branch_id = ? LIMIT 1', [$sup->branch_id])[0] ?? null) : null;
if ($sup && $zone) {
    DB::beginTransaction();
    try {
        $bizKey = BizDay::keyCompact();
        $before = (int) (DB::select('SELECT counter FROM order_counters WHERE branch_id = ? AND day_key = ?',
            [$sup->branch_id, $bizKey])[0]->counter ?? 0);

        $req = Illuminate\Http\Request::create('/api/orders', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode([
            'senderName' => 'فحص اليوم التجاري', 'senderPhone' => '01000000003',
            'senderAddress' => 'شارع', 'source' => 'branch',
            'deliveries' => [['parcelNo' => 1, 'receiverName' => 'مستلم', 'receiverPhone' => '01000000004',
                              'zoneId' => (int) $zone->id, 'zoneName' => (string) $zone->area_name,
                              'zonePrice' => 10, 'orderPrice' => 0, 'address' => 'ع', 'note' => '']],
        ], JSON_UNESCAPED_UNICODE));
        $ses = app('session')->driver(); $ses->start();
        $ses->put(['user_id' => (int) $sup->id, 'username' => $sup->username, 'role' => 'branch',
                   'branch_id' => (int) $sup->branch_id, 'name' => $sup->username]);
        $req->setLaravelSession($ses);
        $res = $kernel->handle($req);
        $j = json_decode($res->getContent(), true);
        $num = (string) ($j['order']['orderNum'] ?? '');

        $after = (int) (DB::select('SELECT counter FROM order_counters WHERE branch_id = ? AND day_key = ?',
            [$sup->branch_id, $bizKey])[0]->counter ?? 0);
        ok('🔴 عدّاد **اليوم التجاري** هو اللي اتزوّد', $after === $before + 1,
            "قبل {$before} بعد {$after} (مفتاح {$bizKey})");
        ok('والرقم المطبوع بنفس المفتاح', str_contains($num, '-' . substr($bizKey, 2) . '-'), $num);
        DB::rollBack();
        echo "  ✅ رجعت\n";
    } catch (Throwable $e) {
        DB::rollBack();
        ok('التنفيذ الحقيقي', false, $e->getMessage());
    }
} else {
    echo "  ⚠️ مافيش مشرف/زون محليًا — تخطّي التنفيذ\n";
}

echo "\n══ 4) جلسة موظف عابرة لنص الليل = يوم واحد بساعاتها ══\n";
$admin = DB::select("SELECT id, username, role FROM users WHERE role='admin' LIMIT 1")[0] ?? null;
/* موظف دهشاني — مش حساب «روح دمشق بس» (دول قطاع لوحده ومش بيظهروا في تقفيلة
   الموظفين بعد 2026-09-04)، وإلا LIMIT 1 ممكن يقع على واحد منهم والفحص يقع من غير باج */
$rdOnly = App\Http\Controllers\Api\AuthController::damascusOnlyUserIds();
$anyStaff = DB::select("SELECT id, username FROM users WHERE role IN ('branch','callcenter','accountant') AND blocked=0"
    . ($rdOnly ? ' AND id NOT IN (' . implode(',', array_map('intval', $rdOnly)) . ')' : '') . ' ORDER BY id LIMIT 1')[0] ?? null;
if ($admin && $anyStaff) {
    DB::beginTransaction();
    try {
        /* 23:00 → 02:00 قاهرة (20:00 → 23:00 UTC) — على مفتاح اليوم التجاري */
        $bday = BizDay::key(strtotime('2026-09-15 20:00:00 UTC'));
        DB::insert('INSERT INTO attendance_sessions (username, session_date, check_in, check_out, last_seen, created_at)
                    VALUES (?,?,?,?,?,NOW())',
            [$anyStaff->username, $bday, '2026-09-15 20:00:00', '2026-09-15 23:00:00', '2026-09-15 23:00:00']);

        $req = Illuminate\Http\Request::create('/api/pilot-accounting/staff-month?month=2026-09', 'GET');
        $req->headers->set('Accept', 'application/json');
        $ses = app('session')->driver(); $ses->start();
        $ses->put(['user_id' => (int) $admin->id, 'username' => $admin->username, 'role' => 'admin',
                   'branch_id' => null, 'name' => $admin->username]);
        $req->setLaravelSession($ses);
        $j = json_decode($kernel->handle($req)->getContent(), true);
        $found = null;
        foreach ($j['staff'] ?? [] as $u) {
            if ($u['username'] === $anyStaff->username) {
                $found = $u['days'][(int) substr($bday, 8, 2) - 1] ?? null;
            }
        }
        ok('صف اليوم موجود', $found !== null);
        ok('🔴 23:00 → 02:00 = 3 ساعات (مش صفر — دقايق اليوم التجاري مش دقايق الساعة)',
            $found !== null && abs((float) ($found['hours'] ?? -1) - 3.0) < 0.02,
            (string) ($found['hours'] ?? '؟'));
        DB::rollBack();
        echo "  ✅ رجعت\n";
    } catch (Throwable $e) {
        DB::rollBack();
        ok('جلسة نص الليل', false, $e->getMessage());
    }
}

echo "\n══ 5) الواجهات — «النهارده» بقت تجارية ══\n";
$strip = fn (string $t) => preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $t);
foreach (['public/tiar.html', 'public/branch.html', 'public/callcenter.html', 'public/store.html'] as $f) {
    $s = $strip(file_get_contents($f));
    ok("{$f}: bizDayKey متعرّفة", str_contains($s, 'window.bizDayKey = function'));
    ok('  وisCairoToday بتقارن باليوم التجاري',
        preg_match('/isCairoToday[^}]+bizDayKey\(t\) === window\.bizDayKey\(new Date\(\)\)/s', $s) === 1);
}
$cc = $strip(file_get_contents('public/callcenter.html'));
ok('كول سنتر: مفيش todayKey لسه على الميلادي',
    ! preg_match('/function todayKey(HR)?\(\)\s*\{\s*return window\.cairoDayKey\(\);/s', $cc));
$fin = $strip(file_get_contents('app/Http/Controllers/Api/FinanceController.php'));
ok('السيرفر: التحصيل والمصاريف والحضور كلهم BizDay — مفيش cairoDayKey فاضل في Finance',
    ! str_contains($fin, 'WireTime::cairoDayKey()') && substr_count($fin, 'BizDay::key()') >= 5);

echo "\n" . str_repeat('─', 52) . "\n";
echo $fail === 0
    ? "✅ عدّى {$pass} فحص — اليوم التجاري ثابت لحد ما الوردية تخلص\n"
    : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
