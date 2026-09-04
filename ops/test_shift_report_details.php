<?php

/**
 * 📊 حارس: تفاصيل العهدة والأذونات في تقرير التقفيلة — تنفيذ حقيقي.
 *
 * ═══ الطلب (صاحب النظام 2026-09-01) ═══
 * «تفاصيل العهدة تظهر في التقفيلة — استلم كام وسلّم كام» و«الإذن يظهر
 * في تقفيلة الطيار».
 *
 * بيزرع وردية + حركات عهدة (استلام/ردّ/تسوية) + إذن جوه النافذة وإذن
 * خارجها، وبينده النقطة الحقيقية ويتحقق من المجاميع والفلترة والنطاق.
 *
 * التشغيل: php ops/test_shift_report_details.php
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

use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$what}\n"; }
    else { $fail++; echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n"; }
}

function hit($kernel, $u, string $url): array
{
    $req = Illuminate\Http\Request::create($url, 'GET');
    $req->headers->set('Accept', 'application/json');
    $ses = app('session')->driver();
    $ses->start();
    $ses->put(['user_id' => (int) $u->id, 'username' => $u->username, 'role' => $u->role,
               'branch_id' => $u->branch_id !== null ? (int) $u->branch_id : null, 'name' => $u->username]);
    $req->setLaravelSession($ses);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$sup = DB::select("SELECT id, username, role, branch_id FROM users
                    WHERE role='branch' AND blocked=0 AND branch_id IS NOT NULL LIMIT 1")[0] ?? null;
$other = DB::select("SELECT id, username, role, branch_id FROM users
                      WHERE role='branch' AND blocked=0 AND branch_id IS NOT NULL AND branch_id <> ? LIMIT 1",
    [$sup->branch_id ?? 0])[0] ?? null;
if (! $sup) { echo "مافيش مشرف فرع — تخطّي\n"; exit(0); }
$pilot = DB::select('SELECT id, name FROM pilots WHERE archived_at IS NULL AND assigned_branch_id = ? LIMIT 1', [$sup->branch_id])[0]
    ?? DB::select('SELECT id, name FROM pilots WHERE archived_at IS NULL LIMIT 1')[0];
$store = DB::select('SELECT id, name FROM cash_stores LIMIT 1')[0] ?? null;

DB::beginTransaction();
try {
    $t0 = gmdate('Y-m-d H:i:s', time() - 8 * 3600);
    $t9 = gmdate('Y-m-d H:i:s', time() - 600);
    DB::insert('INSERT INTO shifts (pilot_id, branch_id, status, started_at, ended_at, custody_returned, custody_carried, created_at)
                VALUES (?,?,?,?,?,?,?,NOW())',
        [$pilot->id, $sup->branch_id, 'ended', $t0, $t9, 150.0, 0.0]);
    $shiftId = (int) DB::getPdo()->lastInsertId();

    /* حركات عهدة جوه النافذة: استلام 500 · ردّ 150 · تسوية عليه 30 —
       وحركة قبل الوردية لازم **ماتبانش** */
    $mk = fn (string $type, float $amt, string $at) => DB::insert(
        'INSERT INTO custody_transactions (pilot_id, type, amount, reason, store_id, branch_id, created_by, created_at)
         VALUES (?,?,?,?,?,?,?,?)',
        [$pilot->id, $type, $amt, 'فحص', $store->id ?? null, $sup->branch_id, 'اختبار', $at]);
    $mk('give', 500, gmdate('Y-m-d H:i:s', time() - 7 * 3600));
    $mk('return', 150, gmdate('Y-m-d H:i:s', time() - 700));
    $mk('order_pending', 30, gmdate('Y-m-d H:i:s', time() - 3600));
    $mk('give', 999, gmdate('Y-m-d H:i:s', time() - 20 * 3600));   // قبل الوردية — بره

    /* إذن جوه النافذة + إذن قديم خالص */
    DB::insert("INSERT INTO pilot_leave_requests (pilot_id, branch_id, type, reason, status, requested_at, responded_at, responded_by, ended_at, ended_by, created_at)
                VALUES (?,?,?,?,'ended',?,?,?,?,?,NOW())",
        [$pilot->id, $sup->branch_id, 'rest', 'غدا', gmdate('Y-m-d H:i:s', time() - 5 * 3600),
         gmdate('Y-m-d H:i:s', time() - 5 * 3600), $sup->username,
         gmdate('Y-m-d H:i:s', time() - 4 * 3600), $sup->username]);
    DB::insert("INSERT INTO pilot_leave_requests (pilot_id, branch_id, type, status, requested_at, responded_at, ended_at, created_at)
                VALUES (?,?,?,'ended',?,?,?,NOW())",
        [$pilot->id, $sup->branch_id, 'rest',
         gmdate('Y-m-d H:i:s', time() - 30 * 3600), gmdate('Y-m-d H:i:s', time() - 30 * 3600),
         gmdate('Y-m-d H:i:s', time() - 29 * 3600)]);

    echo "══ 1) المجاميع ══\n";
    [$code, $j] = hit($kernel, $sup, "/api/shifts/{$shiftId}/closeout-details");
    ok('HTTP 200', $code === 200, (string) $code);
    $c = $j['custody'] ?? [];
    ok('🔴 استلم = 500 (اللي جوه النافذة بس — الـ999 القديمة بره)',
        abs((float) ($c['given'] ?? -1) - 500.0) < 0.01, (string) ($c['given'] ?? '؟'));
    ok('🔴 سلّم = 150', abs((float) ($c['returned'] ?? -1) - 150.0) < 0.01, (string) ($c['returned'] ?? '؟'));
    ok('واتسوّى عليه = 30', abs((float) ($c['settledOn'] ?? -1) - 30.0) < 0.01);
    ok('وإثبات التقفيلة من صف الوردية: رجّع 150 والباقي صفر',
        abs((float) ($c['closeReturned'] ?? -1) - 150.0) < 0.01
        && abs((float) ($c['closeCarried'] ?? -1)) < 0.01);
    ok('والحركات القديمة مش في القايمة',
        count($c['rows'] ?? []) === 3, (string) count($c['rows'] ?? []));
    ok('واسم الخزنة واصل', ($c['rows'][1]['storeName'] ?? null) === ($store->name ?? null));

    echo "\n══ 2) الأذونات ══\n";
    $lv = $j['leaves'] ?? [];
    ok('🔴 إذن النافذة ظاهر والقديم لأ', count($lv) === 1, (string) count($lv));
    ok('وبنوعه وسببه ومين وافق',
        ($lv[0]['type'] ?? '') === 'rest' && ($lv[0]['reason'] ?? '') === 'غدا'
        && ($lv[0]['approvedBy'] ?? '') === $sup->username);
    ok('وبوقتي البداية والنهاية', ! empty($lv[0]['from']) && ! empty($lv[0]['to']));

    echo "\n══ 3) النطاق ══\n";
    if ($other) {
        [$code3] = hit($kernel, $other, "/api/shifts/{$shiftId}/closeout-details");
        ok('🔴 مشرف فرع تاني بيترفض', $code3 >= 400, (string) $code3);
    } else {
        echo "  ⚠️ مافيش مشرف فرع تاني محليًا — تخطّي\n";
    }

    echo "\n══ 4) الواجهتين ══\n";
    foreach (['public/branch.html', 'public/tiar.html'] as $f) {
        $ui = file_get_contents($f);
        ok("{$f}: المكان المحجوز في التقرير", str_contains($ui, 'id="_srCloseoutDetails"'));
        ok("  والنداء بعد فتح المودال", str_contains($ui, '_loadShiftCloseoutDetails(shift.id);'));
        ok("  والمسح الصريح لإخلاء الطرف",
            str_contains($ui, 'صفر — أخلى طرف ✓'));
        ok('  وكل نص بيعدّي على esc',
            substr_count(explode('async function _loadShiftCloseoutDetails', $ui)[1] ?? '', 'esc(') >= 10);
        /* 🔴 طلب لاحق نفس الليلة: «التقرير الذي يُطبع يجب أن يظهر فيه
           العهدة والإذن وتفاصيله — ممكن يكون عقابًا — لأنه بيتبعت PDF
           للإدارة». الطبع بيبني HTML مستقل عن المودال، فحقن الشاشة
           ماكانش بيوصله — الفحوص دي على مسار الطبع نفسه. */
        ok('  🔴 والطبع بيستنى التفاصيل — التقرير بيتبعت PDF للإدارة',
            str_contains($ui, '_cd = await _closeoutDetailsFor(shift.id)'));
        ok('  والأقسام جوه صفحة الطبع نفسها',
            str_contains($ui, '${_closeoutPrintSections(_cd)}'));
        ok('  🔴 والإيقاف الإجباري (العقاب) معلّم صراحةً في المطبوع',
            str_contains($ui, 'إيقاف من الإدارة/الفرع'));
        ok('  ولو التفاصيل مش متاحة الطبع مايقفش',
            str_contains($ui, 'التفاصيل غير متاحة وقت الطباعة'));
    }

    DB::rollBack();
    echo "\n✅ المعاملة رجعت\n";
} catch (Throwable $e) {
    DB::rollBack();
    echo '🔴 ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
}

echo "\n" . str_repeat('─', 52) . "\n";
echo $fail === 0
    ? "✅ عدّى {$pass} فحص — العهدة والأذونات في التقرير\n"
    : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
