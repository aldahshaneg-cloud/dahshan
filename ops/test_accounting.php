<?php

declare(strict_types=1);

/**
 * 💰 اختبار قسم حسابات الطيارين — كله جوه معاملة بتترجع فمفيش أثر على القاعدة.
 *
 * الأرقام كلها محسوبة بالإيد في التعليقات قبل ما تتقارن، عشان الاختبار
 * يمسك غلط في المعادلة مش يعيد نفس غلطها.
 *
 * التشغيل: php ops/test_accounting.php
 */

use App\Http\Controllers\Api\PilotAccountingController;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use App\Wire\PilotAccountingWire as W;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
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


$pass = 0;
$fail = 0;
function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✓ {$what}\n";
    } else {
        $fail++;
        echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n";
    }
}
function near(float $a, float $b): bool { return abs($a - $b) < 0.005; }

function req(Actor $a, array $query = [], array $body = [], string $method = 'GET'): Request
{
    $r = Request::create('/', $method, $method === 'GET' ? $query : [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    if ($method === 'GET' && $query) {
        $r->query->replace($query);
    }
    $r->attributes->set(ResolveApiActor::ATTRIBUTE, $a);

    return $r;
}

/** وقت القاهرة → DATETIME مخزّن بالـUTC */
function cairoUtc(string $ymdHis): string
{
    return (new DateTimeImmutable($ymdHis, new DateTimeZone('Africa/Cairo')))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

$ctl = new PilotAccountingController();

DB::beginTransaction();
try {
    $now = date('Y-m-d H:i:s');
    $YM  = '2026-07';                 // شهر عدّى خلاص → الأيام المحسوبة = 31
    $DAY = 10;
    $DATE = '2026-07-10';

    // إعدادات معروفة: بداية اليوم 9 ص، الوردية 10 ساعات
    DB::statement(
        'INSERT INTO acc_settings (setting_key, setting_value, updated_at, created_at) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        ['pilotAccounting', json_encode(['dayStartHour' => 9, 'shiftHours' => 10]), $now, $now]
    );

    $branchId = (int) DB::select('SELECT id FROM branches ORDER BY id LIMIT 1')[0]->id;

    /* طيار الاختبار — أرقام مختارة عشان الحسبة تبقى في الدماغ:
         سعر الساعة 20 · مرتب 3100 (يعني 100 لليوم على 31 يوم)
         إجازة مدفوعة 2 يوم · عمولة ثابتة 18 للأوردر */
    DB::insert(
        "INSERT INTO pilots (name, phone1, assigned_branch_id, commission_type, commission_value,
                             hour_rate, paid_leave_days, monthly_salary, created_at)
         VALUES ('طيار الاختبار','01500000000',?,'fixed',18,20,2,3100,?)",
        [$branchId, $now]
    );
    $pilotId = (int) DB::getPdo()->lastInsertId();

    /* وردية 9 ص → 7 م بتوقيت القاهرة = 10 ساعات، ومعاها حافز وسلفة وخصم.
       🔴 التسويات هنا **monthly** عن قصد عشان القسم 2 يوري المعادلة
       كاملة. الافتراضي في المخطط 'daily' (يعني اتصفّى مع الطيار عند
       قفل الوردية) والقسم 9 بيغطّي الحالة دي بالظبط. */
    DB::insert(
        "INSERT INTO shifts (pilot_id, branch_id, status, started_at, ended_at,
                             bonus_amount, advance_amount, deduction_amount,
                             commission_settle, bonus_settle, deduction_settle, advance_settle, created_at)
         VALUES (?,?,'ended',?,?,50,25,10,'monthly','monthly','monthly','monthly',?)",
        [$pilotId, $branchId, cairoUtc($DATE . ' 09:00:00'), cairoUtc($DATE . ' 19:00:00'), $now]
    );

    // 3 أوردرات متسلّمة بـ30 جنيه توصيل — العمولة ثابتة 18 لكل واحد
    for ($i = 1; $i <= 3; $i++) {
        DB::insert(
            "INSERT INTO orders (order_num, branch_id, sender_name, sender_phone, status, status_since,
                                 delivered_at, created_at, total_delivery_price, pilot_id, pilot_name)
             VALUES (?,?,'محل الاختبار','01000000000','delivered',?,?,?,30,?,'طيار الاختبار')",
            ["ACC-TEST-{$i}", $branchId, $now, cairoUtc($DATE . ' 1' . $i . ':00:00'), $now, $pilotId]
        );
    }

    $admin  = Actor::staff(900, 'مدير الاختبار', 'admin', null, 'مدير');
    $fetch  = fn () => json_decode($ctl->month(req($admin, ['month' => $YM, 'pilotId' => (string) $pilotId]))->getContent(), true);

    echo "\n══ 1) الصف اليومي بيتملى من المنظومة ══\n";
    $d = $fetch();
    ok('الشهر رجع', ($d['month'] ?? '') === $YM, json_encode($d['month'] ?? null));
    ok('بداية اليوم 9', (int) ($d['settings']['dayStartHour'] ?? 0) === 9, json_encode($d['settings'] ?? null));
    ok('الأيام المحسوبة 31 (شهر عدّى)', (int) ($d['countedDays'] ?? 0) === 31, (string) ($d['countedDays'] ?? 0));

    $p   = $d['pilots'][0] ?? null;
    $row = $p['days'][$DAY - 1] ?? null;
    ok('لقينا صف يوم 10', $row && (int) $row['day'] === $DAY);
    ok('ساعة الحضور 09:00', ($row['in'] ?? '') === '09:00', json_encode($row['in'] ?? null));
    ok('ساعة الانصراف 19:00', ($row['out'] ?? '') === '19:00', json_encode($row['out'] ?? null));
    ok('الساعات 10', near((float) $row['hours'], 10), (string) $row['hours']);
    ok('الأوردرات 3', (int) $row['orders'] === 3, (string) $row['orders']);
    ok('إجمالي الخدمة 90', near((float) $row['svc'], 90), (string) $row['svc']);
    ok('خدمة الطيار 54 (3×18)', near((float) $row['psvc'], 54), (string) $row['psvc']);
    ok('صافي الخدمة 36 (90−54)', near((float) $row['net'], 36), (string) $row['net']);
    ok('الحافز 50 من الوردية', near((float) $row['bonus'], 50), (string) $row['bonus']);
    ok('السلفة 25 من الوردية', near((float) $row['adv'], 25), (string) $row['adv']);
    ok('الخصم 10 من الوردية', near((float) $row['ded'], 10), (string) $row['ded']);
    ok('مفيش تدخّل بشري', ($row['edited'] ?? []) === [], json_encode($row['edited'] ?? null));

    echo "\n══ 2) إجماليات الشهر ══\n";
    /* أجر الساعات = 10 × 20 = 200
       العمولة     = 54
       نصيب الراتب = 3100 ÷ 31 × 1 يوم شغل = 100
       إجازة مدفوعة= min(31−1, 2) = 2 يوم × 10 ساعات × 20 = 400
       الحوافز     = 50
       الإجمالي    = 200 + 54 + 100 + 400 + 50 = 804
       الصافي      = 804 − 25 سلف − 10 خصم − 0 = 769 */
    $t = $p['totals'];
    ok('أيام الشغل 1', (int) $t['worked'] === 1, (string) $t['worked']);
    ok('أجر الساعات 200', near((float) $t['hourPay'], 200), (string) $t['hourPay']);
    ok('العمولة 54', near((float) $t['commission'], 54), (string) $t['commission']);
    ok('نصيب الراتب 100', near((float) $t['salaryShare'], 100), (string) $t['salaryShare']);
    ok('أيام الإجازة المدفوعة 2', (int) $t['leaveDays'] === 2, (string) $t['leaveDays']);
    ok('أجر الإجازة 400', near((float) $t['leavePay'], 400), (string) $t['leavePay']);
    ok('الإجمالي 804', near((float) $t['gross'], 804), (string) $t['gross']);
    ok('الصافي 769', near((float) $t['netDue'], 769), (string) $t['netDue']);

    echo "\n══ 3) التدخّل اليدوي بيغلب ويترجع ══\n";
    $ctl->entrySave(req($admin, [], ['month' => $YM, 'pilotId' => $pilotId, 'day' => $DAY, 'field' => 'psvc', 'value' => 90], 'POST'));
    $row2 = $fetch()['pilots'][0]['days'][$DAY - 1];
    ok('خدمة الطيار بقت 90 بالتدخّل', near((float) $row2['psvc'], 90), (string) $row2['psvc']);
    ok('الصافي اتحدّث لـ0 (90−90)', near((float) $row2['net'], 0), (string) $row2['net']);
    ok('الخانة اتعلّمت متعدّلة', in_array('psvc', $row2['edited'], true), json_encode($row2['edited']));
    ok('رقم النظام الأصلي (54) لسه محفوظ', near((float) $row2['auto']['psvc'], 54), (string) $row2['auto']['psvc']);

    $ctl->entrySave(req($admin, [], ['month' => $YM, 'pilotId' => $pilotId, 'day' => $DAY, 'field' => 'psvc', 'value' => ''], 'POST'));
    $row3 = $fetch()['pilots'][0]['days'][$DAY - 1];
    ok('شيل التدخّل رجّع 54', near((float) $row3['psvc'], 54), (string) $row3['psvc']);
    ok('العلامة اتشالت', ! in_array('psvc', $row3['edited'], true), json_encode($row3['edited']));

    echo "\n══ 4) الاستئذان بينقّص الساعات ══\n";
    $ctl->permsSave(req($admin, [], ['month' => $YM, 'pilotId' => $pilotId, 'day' => $DAY,
        'periods' => [['out' => '12:00', 'in' => '13:30']]], 'POST'));
    $row4 = $fetch()['pilots'][0]['days'][$DAY - 1];
    ok('الساعات بقت 8.5 (10 − ساعة ونص)', near((float) $row4['hours'], 8.5), (string) $row4['hours']);
    ok('فترة الاستئذان ظاهرة', count($row4['perms']) === 1, json_encode($row4['perms']));
    $ctl->permsSave(req($admin, [], ['month' => $YM, 'pilotId' => $pilotId, 'day' => $DAY, 'periods' => []], 'POST'));
    ok('شيل الاستئذان رجّع 10 ساعات', near((float) $fetch()['pilots'][0]['days'][$DAY - 1]['hours'], 10));

    echo "\n══ 5) السلفة المؤجلة بتتخصم بالتقسيط ══\n";
    $r = json_decode($ctl->deferredSave(req($admin, [], [
        'pilotId' => $pilotId, 'amount' => 5000, 'monthly' => 1000,
        'startMonth' => $YM, 'note' => 'سلفة اختبار',
    ], 'POST'))->getContent(), true);
    $advId = (int) $r['id'];
    ok('السلفة اتسجّلت', $advId > 0);

    $d5 = $fetch();
    ok('قسط الشهر 1000', near((float) $d5['pilots'][0]['totals']['deferredDue'], 1000), (string) $d5['pilots'][0]['totals']['deferredDue']);
    ok('الصافي نزل لـ-231 (769−1000)', near((float) $d5['pilots'][0]['totals']['netDue'], -231), (string) $d5['pilots'][0]['totals']['netDue']);
    ok('المتبقي 4000', near((float) $d5['deferred'][0]['remaining'], 4000), (string) $d5['deferred'][0]['remaining']);

    $ctl->deferredPayment(req($admin, [], ['month' => $YM, 'amount' => 2500], 'POST'), (string) $advId);
    $d6 = $fetch();
    ok('قسط الشهر بقى 2500 بالتعديل', near((float) $d6['pilots'][0]['totals']['deferredDue'], 2500));
    ok('المتبقي بقى 2500', near((float) $d6['deferred'][0]['remaining'], 2500));

    echo "\n══ 6) قفل الشهر بيمنع الكتابة ══\n";
    $ctl->lockMonth(req($admin, [], ['month' => $YM], 'POST'));
    ok('الشهر باين مقفول', (bool) ($fetch()['locked'] ?? false));
    try {
        $ctl->entrySave(req($admin, [], ['month' => $YM, 'pilotId' => $pilotId, 'day' => $DAY, 'field' => 'note', 'value' => 'x'], 'POST'));
        ok('الكتابة مرفوضة بعد القفل', false, 'عدّت!');
    } catch (Throwable $e) {
        ok('الكتابة مرفوضة بعد القفل', str_contains($e->getMessage(), 'مقفول'), $e->getMessage());
    }
    $ctl->unlockMonth(req($admin, ['month' => $YM]));
    ok('الفتح رجّع التعديل', ! (bool) ($fetch()['locked'] ?? true));

    echo "\n══ 7) مشرف فرع تاني مايقدرش يعدّل ══\n";
    $other = Actor::staff(901, 'مشرف غريب', 'branch', $branchId + 99999, 'مشرف');
    try {
        $ctl->entrySave(req($other, [], ['month' => $YM, 'pilotId' => $pilotId, 'day' => $DAY, 'field' => 'note', 'value' => 'x'], 'POST'));
        ok('مشرف فرع تاني مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('مشرف فرع تاني مرفوض', str_contains($e->getMessage(), 'مش في فرعك'), $e->getMessage());
    }

    echo "\n══ 8) اليوم التجاري: وردية بتقفل بالليل ══\n";
    // وردية 8 م يوم 11 → 2 ص يوم 12 القاهرة. بداية اليوم 9 ص ⇒ الاتنين على يوم 11
    DB::insert(
        "INSERT INTO shifts (pilot_id, branch_id, status, started_at, ended_at, created_at)
         VALUES (?,?,'ended',?,?,?)",
        [$pilotId, $branchId, cairoUtc('2026-07-11 20:00:00'), cairoUtc('2026-07-12 02:00:00'), $now]
    );
    $d8 = $fetch();
    $r11 = $d8['pilots'][0]['days'][10];   // يوم 11
    $r12 = $d8['pilots'][0]['days'][11];   // يوم 12
    ok('يوم 11 حضوره 20:00', ($r11['in'] ?? '') === '20:00', json_encode($r11['in'] ?? null));
    ok('يوم 11 انصرافه 02:00 (بعد نص الليل)', ($r11['out'] ?? '') === '02:00', json_encode($r11['out'] ?? null));
    ok('يوم 11 ساعاته 6', near((float) $r11['hours'], 6), (string) $r11['hours']);
    ok('يوم 12 فاضي — الوردية اتسجّلت على 11', ($r12['in'] ?? null) === null && near((float) $r12['hours'], 0),
        json_encode(['in' => $r12['in'] ?? null, 'h' => $r12['hours']]));

    /* نشيل وردية يوم 11 اللي ضفناها فوق عشان الأرقام ترجع للمحسوبة
       بالإيد في القسم 2 — الاختبار لازم يفضل مقروء. */
    DB::delete("DELETE FROM shifts WHERE pilot_id = ? AND started_at >= ?",
        [$pilotId, cairoUtc('2026-07-11 00:00:00')]);

    echo "\n══ 9) الفلوس المتصفّاة يوميًا مابتتدفعش تاني ══\n";
    /* الوردية الأصلية في الاختبار ده كل تسوياتها الافتراضية = daily، يعني
       العمولة والحافز والسلفة والخصم **اتصفّوا مع الطيار عند قفل الوردية**.
       فالتقفيلة الشهرية لازم تاخد أجر الساعات + نصيب الراتب + الإجازة بس. */
    DB::update("UPDATE shifts SET commission_settle='daily', bonus_settle='daily',
                                  deduction_settle='daily', advance_settle='daily'
                 WHERE pilot_id = ?", [$pilotId]);
    // نشيل السلفة المؤجلة عشان نعزل الحسبة
    DB::delete('DELETE FROM pilot_deferred_advances WHERE pilot_id = ?', [$pilotId]);

    $dD = $fetch();
    $rD = $dD['pilots'][0]['days'][$DAY - 1];
    $tD = $dD['pilots'][0]['totals'];

    ok('الشيت لسه بيعرض خدمة اليوم 54 (سجل)', near((float) $rD['psvc'], 54), (string) $rD['psvc']);
    ok('المرحّل للشهر من العمولة صفر', near((float) $rD['carry']['psvc'], 0), json_encode($rD['carry']));
    ok('المرحّل من الحافز صفر', near((float) $rD['carry']['bonus'], 0));
    ok('المرحّل من السلفة صفر', near((float) $rD['carry']['adv'], 0));

    ok('العمولة المستحقة في التقفيلة صفر', near((float) $tD['commission'], 0), (string) $tD['commission']);
    ok('المعروض إن 54 اتصفّت كاش', near((float) $tD['settledDaily']['psvc'], 54), json_encode($tD['settledDaily']));
    /* الإجمالي = 200 أجر ساعات + 0 عمولة + 100 نصيب راتب + 400 إجازة + 0 حافز = 700
       والصافي = 700 − 0 سلف مرحّلة − 0 خصم مرحّل = 700 */
    ok('الإجمالي 700 (من غير عمولة متصفّاة)', near((float) $tD['gross'], 700), (string) $tD['gross']);
    ok('الصافي 700', near((float) $tD['netDue'], 700), (string) $tD['netDue']);

    echo "\n══ 10) اللي مرحّل monthly بيتدفع عادي ══\n";
    DB::update("UPDATE shifts SET commission_settle='monthly', bonus_settle='monthly',
                                  deduction_settle='monthly', advance_settle='monthly'
                 WHERE pilot_id = ?", [$pilotId]);
    $tM = $fetch()['pilots'][0]['totals'];
    ok('العمولة المستحقة رجعت 54', near((float) $tM['commission'], 54), (string) $tM['commission']);
    ok('الحافز المستحق 50', near((float) $tM['bonusDue'], 50), (string) $tM['bonusDue']);
    ok('السلفة المستحقة 25', near((float) $tM['advanceDue'], 25), (string) $tM['advanceDue']);
    ok('الإجمالي 804', near((float) $tM['gross'], 804), (string) $tM['gross']);
    ok('الصافي 769', near((float) $tM['netDue'], 769), (string) $tM['netDue']);

    echo "\n══ 11) سعر ساعة موحّد في الإعدادات ══\n";
    DB::update('UPDATE pilots SET hour_rate = 0 WHERE id = ?', [$pilotId]);
    DB::statement(
        'INSERT INTO acc_settings (setting_key, setting_value, updated_at, created_at) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        ['pilotAccounting', json_encode(['dayStartHour' => 9, 'shiftHours' => 10, 'hourRate' => 15]), $now, $now]
    );
    $tS = $fetch();
    ok('سعر ساعة الطيار بقى 15 من الإعدادات', near((float) $tS['pilots'][0]['hourRate'], 15), (string) $tS['pilots'][0]['hourRate']);
    ok('أجر الساعات 150 (10×15)', near((float) $tS['pilots'][0]['totals']['hourPay'], 150), (string) $tS['pilots'][0]['totals']['hourPay']);

    DB::update('UPDATE pilots SET hour_rate = 20 WHERE id = ?', [$pilotId]);
    $tS2 = $fetch();
    ok('سعر الطيار الشخصي بيغلب الافتراضي', near((float) $tS2['pilots'][0]['hourRate'], 20), (string) $tS2['pilots'][0]['hourRate']);

    echo "\n══ 12) الصف الفاضي بيتشال من القاعدة ══\n";
    $cnt = fn () => (int) DB::select('SELECT COUNT(*) n FROM pilot_day_entries WHERE month = ? AND pilot_id = ? AND day = 20', [$YM, $pilotId])[0]->n;
    $ctl->entrySave(req($admin, [], ['month' => $YM, 'pilotId' => $pilotId, 'day' => 20, 'field' => 'note', 'value' => 'ملاحظة'], 'POST'));
    ok('الصف اتكتب', $cnt() > 0, (string) $cnt());
    $ctl->entrySave(req($admin, [], ['month' => $YM, 'pilotId' => $pilotId, 'day' => 20, 'field' => 'note', 'value' => ''], 'POST'));
    ok('الصف اتشال لما بقى فاضي', $cnt() === 0, (string) $cnt());
} catch (Throwable $e) {
    $fail++;
    echo "\n💥 " . get_class($e) . ': ' . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "ACCOUNTING: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
