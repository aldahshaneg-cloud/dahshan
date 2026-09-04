<?php

/**
 * 👥 حارس: تقفيلة الموظفين — تنفيذ فعلي عبر الراوتر كامل.
 *
 * ═══ الطلب (صاحب النظام 2026-09-01) ═══
 * «زي ما الطيارين لهم تقفيلة ضيف كل الموظفين كمان بشكل أوتوماتيك —
 * المشرف له وقت تسجيل دخول من أول ما يفتح المتصفح... راجع تقفيلة روح
 * دمشق فيها شغل حسابات مهم لازم توصله».
 *
 * ═══ ليه تنفيذ مش فحص نصّي ═══
 * الحارس بيزرع جلستي حضور حقيقيتين في معاملة بترجع وبيقرا الرد من
 * نقطة النهاية نفسها: الفجوة بين الجلستين لازم تطلع استئذان، والساعات
 * لازم تساوي مجموع الجلسات مش الـspan — الغلطتين دول فحص نصّي مايشوفهمش.
 * وكود خروج PHP بيكدب لو الاستثناء في نص السكربت، فالكل جوه try/catch.
 *
 * التشغيل: php ops/test_staff_closeout.php
 * (بيتخطى بهدوء لو مافيش قاعدة أو مافيش مشرف فرع)
 *
 * اختبار staff-month عبر الراوتر كامل: بيزرع جلسات حضور حقيقية في معاملة
 * بترجع، وبيتأكد إن الحساب طالع صح — الفجوة بين الجلستين استئذان،
 * والساعات = مجموع الجلسات، والراتب بمعادلة دمشق.
 */
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/* 🔴 من غير الاتنين دول: استثناء مش متمسك بعد البوتستراب بيتطبع بشكل
   جميل وبيخرج بكود 0 — الحارس يبان ناجح وهو مات في نص شغله.
   ولازم يتركّبوا بعد البوتستراب — قبله لارافل بيدوس عليهم. */
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

function hit($kernel, $u, string $url, string $method = 'GET', array $body = []): array
{
    $req = Illuminate\Http\Request::create($url, $method, [], [], [], [], $body ? json_encode($body) : null);
    $req->headers->set('Accept', 'application/json');
    if ($body) { $req->headers->set('Content-Type', 'application/json'); }
    $ses = app('session')->driver();
    $ses->start();
    $ses->put(['user_id' => (int) $u->id, 'username' => $u->username, 'role' => $u->role,
               'branch_id' => $u->branch_id !== null ? (int) $u->branch_id : null, 'name' => $u->username]);
    $req->setLaravelSession($ses);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$admin = DB::select("SELECT id, username, role, branch_id FROM users WHERE role='admin' AND blocked=0 LIMIT 1")[0];
$staff = DB::select("SELECT id, username, role, branch_id FROM users WHERE role='branch' AND blocked=0 LIMIT 1")[0] ?? null;
if (! $staff) { echo "مافيش مشرف فرع — تخطّي\n"; exit(0); }

$ym  = date('Y-m');
$day = 15;
$date = $ym . '-15';

DB::beginTransaction();
try {
    /* جلستين بتوقيت القاهرة (UTC+3): 09:00-13:00 و14:00-18:00
       يعني UTC: 06:00-10:00 و11:00-15:00. الفجوة ساعة = استئذان. */
    /* الشهر كله بيتفضّى مش يوم 15 بس: على لقطة الإنتاج المشرف الحقيقي عنده
       جلسات في أيام تانية من الشهر فأجر الساعات طلع 224 بدل 160 ونصيب
       الراتب اتقسم على 4 أيام. المسح جوه المعاملة وبيترجع مع الـrollback. */
    DB::delete('DELETE FROM attendance_sessions WHERE username = ? AND session_date LIKE ?', [$staff->username, $ym . '-%']);
    DB::insert('INSERT INTO attendance_sessions (session_date, username, role, check_in, check_out, last_seen, entry_type, created_at)
                VALUES (?,?,?,?,?,?,?,NOW())',
        [$date, $staff->username, 'branch', $ym . '-15 06:00:00', $ym . '-15 10:00:00', $ym . '-15 10:00:00', 'auto']);
    DB::insert('INSERT INTO attendance_sessions (session_date, username, role, check_in, check_out, last_seen, entry_type, created_at)
                VALUES (?,?,?,?,?,?,?,NOW())',
        [$date, $staff->username, 'branch', $ym . '-15 11:00:00', $ym . '-15 15:00:00', $ym . '-15 15:00:00', 'auto']);

    /* راتب للموظف عشان معادلة دمشق تبان */
    DB::update('UPDATE users SET hour_rate = 20, monthly_salary = 3000, paid_leave_days = 2 WHERE id = ?', [$staff->id]);
    DB::delete('DELETE FROM pilot_acct_perms WHERE user_id = ?', [$admin->id]);

    echo "══ 1) الأدمن بيقرا الشهر ══\n";
    [$code, $j] = hit($kernel, $admin, '/api/pilot-accounting/staff-month?month=' . $ym);
    ok('HTTP 200', $code === 200, (string) $code);
    $row = null;
    foreach (($j['staff'] ?? []) as $s) {
        if ($s['username'] === $staff->username) { $row = $s; break; }
    }
    ok('المشرف موجود في التقفيلة', $row !== null);
    ok('والأدمن مش موجود فيها',
        ! array_filter($j['staff'] ?? [], fn ($s) => $s['role'] === 'admin'));

    $d = $row['days'][$day - 1] ?? [];
    echo "\n══ 2) حضور اليوم {$day} ══\n";
    ok('الحضور 09:00 قاهرة', ($d['in'] ?? '') === '09:00', (string) ($d['in'] ?? 'فاضي'));
    ok('الانصراف 18:00', ($d['out'] ?? '') === '18:00', (string) ($d['out'] ?? 'فاضي'));
    ok('🔴 الفجوة بين الجلستين طلعت استئذان 13:00→14:00',
        count($d['perms'] ?? []) === 1
        && ($d['perms'][0]['out'] ?? '') === '13:00'
        && ($d['perms'][0]['in'] ?? '') === '14:00',
        json_encode($d['perms'] ?? [], JSON_UNESCAPED_UNICODE));
    ok('🔴 الساعات = مجموع الجلسات (8) مش الـspan (9)',
        abs((float) ($d['hours'] ?? 0) - 8.0) < 0.01, (string) ($d['hours'] ?? '؟'));

    $t = $row['totals'] ?? [];
    echo "\n══ 3) معادلات دمشق ══\n";
    ok('أجر الساعات = 8 × 20 = 160',
        abs((float) ($t['hourPay'] ?? 0) - 160.0) < 0.01, (string) ($t['hourPay'] ?? '؟'));
    ok('العمولة صفر — الموظف مالوش أوردرات', (float) ($t['commission'] ?? -1) === 0.0);
    $counted = (int) ($j['countedDays'] ?? 0);
    $expShare = $counted > 0 ? round(3000 / $counted * 1, 2) : 0;
    ok("نصيب الراتب = 3000/{$counted} × يوم شغل واحد = {$expShare}",
        abs((float) ($t['salaryShare'] ?? 0) - $expShare) < 0.01, (string) ($t['salaryShare'] ?? '؟'));
    ok('صافي المستحق متسق: hourPay+salaryShare+leavePay+bonus−adv−ded',
        abs((float) $t['netDue'] - ((float) $t['hourPay'] + (float) $t['salaryShare'] + (float) $t['leavePay']
            + (float) $t['bonusDue'] - (float) $t['advanceDue'] - (float) $t['deductionDue'])) < 0.02,
        (string) ($t['netDue'] ?? '؟'));

    echo "\n══ 4) تدخّل يدوي: سلفة 50 ══\n";
    [$code2] = hit($kernel, $admin, '/api/pilot-accounting/staff-entry', 'POST',
        ['month' => $ym, 'userId' => (int) $staff->id, 'day' => $day, 'field' => 'adv', 'value' => 50]);
    ok('الحفظ 200', $code2 === 200, (string) $code2);
    [, $j2] = hit($kernel, $admin, '/api/pilot-accounting/staff-month?month=' . $ym);
    $row2 = null;
    foreach (($j2['staff'] ?? []) as $s) {
        if ($s['username'] === $staff->username) { $row2 = $s; break; }
    }
    $d2 = $row2['days'][$day - 1] ?? [];
    ok('السلفة ظهرت في اليوم', abs((float) ($d2['adv'] ?? 0) - 50.0) < 0.01, (string) ($d2['adv'] ?? '؟'));
    ok('والصافي نقص 50',
        abs((float) $row2['totals']['netDue'] - ((float) $t['netDue'] - 50)) < 0.02,
        $row2['totals']['netDue'] . ' vs ' . ($t['netDue'] - 50));

    echo "\n══ 5) مشرف الفرع بيشوف فرعه بس ══\n";
    [$code3, $j3] = hit($kernel, $staff, '/api/pilot-accounting/staff-month?month=' . $ym);
    ok('HTTP 200', $code3 === 200, (string) $code3);
    $other = array_filter($j3['staff'] ?? [],
        fn ($s) => $s['branchId'] !== null && (int) $s['branchId'] !== (int) $staff->branch_id);
    ok('🔴 مافيش موظف من فرع تاني', $other === [], count($other) . ' متسربين');

    echo "\n══ 6) الصلاحيات بتقص هنا برضه ══\n";
    DB::insert('INSERT INTO pilot_acct_perms (user_id, perm_keys, branches, updated_by) VALUES (?,?,?,?)',
        [$staff->id, json_encode(['page.staff' => true, 'col.in' => true, 'col.out' => true], JSON_UNESCAPED_UNICODE), null, 'اختبار']);
    [, $j4] = hit($kernel, $staff, '/api/pilot-accounting/staff-month?month=' . $ym);
    $r4 = ($j4['staff'] ?? [])[0] ?? null;
    $f4 = array_keys($r4['days'][$day - 1] ?? []);
    $leak = array_intersect(['hours', 'adv', 'ded', 'bonus', 'note'], $f4);
    ok('🔴 الأعمدة الممنوعة مش بتخرج', $leak === [], implode(',', $leak));
    ok('🔴 ولا أرقام الفلوس في الإجماليات',
        ! isset($r4['totals']['netDue']) && ! isset($r4['totals']['hourPay']),
        json_encode(array_keys($r4['totals'] ?? [])));

    /* ومنع page.staff خالص */
    DB::update('UPDATE pilot_acct_perms SET perm_keys = ? WHERE user_id = ?',
        [json_encode(['page.daily' => true], JSON_UNESCAPED_UNICODE), $staff->id]);
    [$code5] = hit($kernel, $staff, '/api/pilot-accounting/staff-month?month=' . $ym);
    ok('🔴 من غير page.staff بيترفض 403', $code5 === 403, (string) $code5);

    echo "\n══ 7) سعر ساعة الطيارين الموحّد مايسريش على الموظفين ══\n";
    /* 🔴 طفرة عدّت من النسخة الأولى للفحص: `$staffSet['hourRate'] = 999`
       ماوقّعتش حاجة، لأن الموظف في الاختبار سعره 20 والرجوع للإعدادات
       مابيحصلش غير لما سعر الشخص صفر. هنا بنصفّر سعره ونحط سعر موحّد
       999 في الإعدادات — لو الوراثة اشتغلت أجر ساعاته هيطلع بالآلاف
       لموظف المفروض أجره بالساعة صفر. */
    DB::update('UPDATE users SET hour_rate = 0 WHERE id = ?', [$staff->id]);
    $curSet = DB::select('SELECT setting_value FROM acc_settings WHERE setting_key = ?', ['pilotAccounting'])[0] ?? null;
    $newSet = $curSet ? json_decode((string) $curSet->setting_value, true) : [];
    $newSet = is_array($newSet) ? $newSet : [];
    $newSet['hourRate'] = 999;
    if ($curSet) {
        DB::update('UPDATE acc_settings SET setting_value = ? WHERE setting_key = ?',
            [json_encode($newSet), 'pilotAccounting']);
    } else {
        /* 🔴 غلطة اتصلحت: أول نسخة كانت باعتة المعاملات بالمقلوب —
           setting_value واخد اسم المفتاح — فصف الإعدادات ماكانش بيتعمل
           والفحص عدّى على طفرة الوراثة وهو فاكر إنه غطاها. */
        DB::insert('INSERT INTO acc_settings (setting_key, setting_value, created_at) VALUES (?,?,NOW())',
            ['pilotAccounting', json_encode($newSet)]);
    }
    [, $j7] = hit($kernel, $admin, '/api/pilot-accounting/staff-month?month=' . $ym);
    $r7 = null;
    foreach (($j7['staff'] ?? []) as $s7) {
        if ($s7['username'] === $staff->username) { $r7 = $s7; break; }
    }
    ok('🔴 موظف بسعر صفر أجر ساعاته صفر — حتى مع سعر موحّد في الإعدادات',
        abs((float) ($r7['totals']['hourPay'] ?? -1)) < 0.01,
        'أجر الساعات: ' . ($r7['totals']['hourPay'] ?? '؟') . ' — سعر الطيارين الموحّد سرى على الموظفين');

    DB::rollBack();
    echo "\n✅ المعاملة رجعت — مافيش أثر\n";
} catch (Throwable $e) {
    DB::rollBack();
    echo '🔴 ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
}

echo "\n" . str_repeat('─', 52) . "\n";
echo $fail === 0 ? "✅ عدّى {$pass} فحص\n" : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
