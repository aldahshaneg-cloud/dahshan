<?php

declare(strict_types=1);

/**
 * اختبار نقل طيار بين فرعين — بيشتغل كله جوه معاملة بتترجع في الآخر،
 * فمفيش أي أثر على قاعدة البيانات.
 *
 * التشغيل: php ops/test_transfer.php
 */

use App\Http\Controllers\Api\BoardController;
use App\Support\Actor;
use App\Http\Middleware\ResolveApiActor;
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

function req(Actor $a, array $body): Request
{
    $r = Request::create('/', 'POST', $body);
    $r->attributes->set(ResolveApiActor::ATTRIBUTE, $a);

    return $r;
}

$ctl = new BoardController();

DB::beginTransaction();
try {
    /* ── تجهيز: فرع تاني + طيار معاه أوردر جاري ── */
    $now = date('Y-m-d H:i:s');
    DB::insert("INSERT INTO branches (name, code, created_at) VALUES ('فرع الاختبار','TST',?)", [$now]);
    $bB = (int) DB::getPdo()->lastInsertId();          // الفرع الطالب
    /* الفرع والطيار بيتكتشفوا من القاعدة مش مكتوبين بالأرقام —
       الاختبار لازم يشتغل على أي نسخة (محلية أو إنتاج) من غير تعديل. */
    $any = DB::select('SELECT id FROM pilots ORDER BY id LIMIT 1');
    if (! $any) {
        echo "مفيش طيارين في القاعدة — الاختبار اتخطى
";
        DB::rollBack();
        exit(0);
    }
    $pilotId = (int) $any[0]->id;
    $bA = (int) (DB::select('SELECT id FROM branches WHERE id <> ? ORDER BY id LIMIT 1', [$bB])[0]->id);
    DB::update("UPDATE pilots SET assigned_branch_id = ?, status = 'delivering', queue_no = NULL WHERE id = ?", [$bA, $pilotId]);

    // أوردر جاري على الطيار في فرعه القديم
    DB::insert(
        "INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, sender_phone, status, status_since,
                             created_at, total_delivery_price, pilot_id, pilot_name)
         VALUES ('TST-TRANS-1', ?, ?, 'محل الاختبار', '01000000000', 'delivering', ?, ?, 20, ?, 'اختبار')",
        [$bA, $bA, $now, $now, $pilotId]
    );
    $oid = (int) DB::getPdo()->lastInsertId();

    /* أوردر متسلّم و**فلوسه لسه مع الطيار** (`money_settled = 0`) — لازم
       يتحرك معاه. التقفيلة بتطلبه بـ`pilot_id` وبتودّي الكاش لفرع الوردية،
       فلو فضل ورا بيطلع أوفر على الفرع الجديد وعجز على القديم (بلاغ
       صاحب النظام 2026-09-10). الحارس كان مثبّت العكس. */
    DB::insert(
        "INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, sender_phone, status, status_since,
                             created_at, total_delivery_price, money_settled, pilot_id, pilot_name)
         VALUES ('TST-TRANS-2', ?, ?, 'محل الاختبار', '01000000000', 'delivered', ?, ?, 20, 0, ?, 'اختبار')",
        [$bA, $bA, $now, $now, $pilotId]
    );
    $oidDone = (int) DB::getPdo()->lastInsertId();

    /* وأوردر متسلّم **واتسوّى خلاص** — ده مايتحركش: فلوسه دخلت خزنة فرعه
       فعلًا، ونقله بعد كده بيكسر دفتر مقفول. */
    DB::insert(
        "INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, sender_phone, status, status_since,
                             created_at, total_delivery_price, money_settled, pilot_id, pilot_name)
         VALUES ('TST-TRANS-3', ?, ?, 'محل الاختبار', '01000000000', 'delivered', ?, ?, 20, 1, ?, 'اختبار')",
        [$bA, $bA, $now, $now, $pilotId]
    );
    $oidSettled = (int) DB::getPdo()->lastInsertId();

    $actorB = Actor::staff(901, 'branchB', 'branch', $bB, 'مشرف الفرع الطالب');
    $actorA = Actor::staff(902, 'branchA', 'branch', $bA, 'مشرف فرع الطيار');

    echo "\n══ 1) طلب النقل من الفرع الطالب ══\n";
    $out = json_decode($ctl->supportRequestCreate(req($actorB, ['pilotId' => $pilotId, 'notes' => 'محتاجينه']))->getContent(), true);
    $reqId = (int) ($out['id'] ?? 0);
    ok('الطلب اتسجّل', $reqId > 0, json_encode($out, JSON_UNESCAPED_UNICODE));

    $row = (array) DB::select('SELECT * FROM pilot_support_requests WHERE id = ?', [$reqId])[0];
    ok('from_branch_id اتحدد من فرع الطيار نفسه', (int) $row['from_branch_id'] === $bA, 'قيمته=' . var_export($row['from_branch_id'], true));
    ok('pilot_id اتسجّل', (int) $row['pilot_id'] === $pilotId);
    ok('الحالة pending', $row['status'] === 'pending', $row['status']);

    echo "\n══ 2) الحمايات ══\n";
    try {
        $ctl->supportRequestCreate(req($actorB, ['pilotId' => $pilotId]));
        ok('طلب مكرر مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('طلب مكرر مرفوض', str_contains($e->getMessage(), 'طلب شغّال'), $e->getMessage());
    }
    try {
        $ctl->supportRequestCreate(req($actorA, ['pilotId' => $pilotId]));
        ok('طلب طيار من فرعك مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('طلب طيار من فرعك مرفوض', str_contains($e->getMessage(), 'في فرعك أصلًا'), $e->getMessage());
    }
    try {
        $otherPilot = DB::select('SELECT id FROM pilots WHERE id <> ? ORDER BY id LIMIT 1', [$pilotId]);
        $ctl->supportSendPilot(req($actorA, ['pilotId' => $otherPilot ? (int) $otherPilot[0]->id : ($pilotId + 99999)]), (string) $reqId);
        ok('إرسال طيار غير المطلوب مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('إرسال طيار غير المطلوب مرفوض', str_contains($e->getMessage(), 'طيار محدد'), $e->getMessage());
    }
    try {
        $ctl->supportRequestRespond(req($actorA, ['response' => 'rejected']), (string) $reqId);
        ok('رفض من غير سبب مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('رفض من غير سبب مرفوض', str_contains($e->getMessage(), 'سبب الرفض'), $e->getMessage());
    }

    echo "\n══ 3) الرفض بسبب ══\n";
    $ctl->supportRequestRespond(req($actorA, ['response' => 'rejected', 'reason' => 'الفرع ضغط عندنا']), (string) $reqId);
    $resp = (array) DB::select('SELECT * FROM pilot_support_responses WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$reqId])[0];
    ok('السبب اتخزّن', $resp['reason'] === 'الفرع ضغط عندنا', var_export($resp['reason'], true));
    $st = DB::select('SELECT status FROM pilot_support_requests WHERE id = ?', [$reqId])[0]->status;
    ok('الطلب الموجّه اتقفل بالرفض', $st === 'rejected', $st);

    $wire = \App\Wire\BoardWire::supportRequest($row + ['from_branch_name' => 'فرع الطيار'], [$resp]);
    ok('السلك بيطلّع سبب الرفض', ($wire['rejections'][0]['reason'] ?? null) === 'الفرع ضغط عندنا', json_encode($wire['rejections'], JSON_UNESCAPED_UNICODE));

    echo "\n══ 4) القبول → النقل التلقائي ══\n";
    $out2 = json_decode($ctl->supportRequestCreate(req($actorB, ['pilotId' => $pilotId, 'notes' => 'تاني']))->getContent(), true);
    $reqId2 = (int) $out2['id'];

    $shiftBefore = DB::select("SELECT id FROM shifts WHERE pilot_id = ? AND status = 'active'", [$pilotId]);
    $shiftBefore = $shiftBefore ? (int) $shiftBefore[0]->id : 0;

    $ctl->supportSendPilot(req($actorA, ['pilotId' => $pilotId]), (string) $reqId2);

    $p = (array) DB::select('SELECT * FROM pilots WHERE id = ?', [$pilotId])[0];
    ok('الطيار بقى في الفرع الطالب', (int) $p['assigned_branch_id'] === $bB, var_export($p['assigned_branch_id'], true));
    ok('حالته فضلت delivering (معاه أوردر)', $p['status'] === 'delivering', var_export($p['status'], true));
    ok('مالوش رقم دور وهو شايل أوردر', $p['queue_no'] === null, var_export($p['queue_no'], true));

    $o = (array) DB::select('SELECT * FROM orders WHERE id = ?', [$oid])[0];
    ok('الأوردر الجاري اتنقل للفرع الجديد', (int) $o['branch_id'] === $bB, var_export($o['branch_id'], true));
    ok('الفرع الأصلي فضل زي ما هو', (int) $o['origin_branch_id'] === $bA, var_export($o['origin_branch_id'], true));

    $od = (array) DB::select('SELECT * FROM orders WHERE id = ?', [$oidDone])[0];
    ok(
        '🔴 والمتسلّم اللي فلوسه لسه مع الطيار اتحرك معاه',
        (int) $od['branch_id'] === $bB,
        var_export($od['branch_id'], true) . ' — لو فضل ورا بيطلع أوفر هنا وعجز هناك'
    );
    $os = (array) DB::select('SELECT * FROM orders WHERE id = ?', [$oidSettled])[0];
    ok('⚠️ والمتسلّم المسوّى ماتحركش (دفتره اتقفل)', (int) $os['branch_id'] === $bA, var_export($os['branch_id'], true));

    $sh = (array) DB::select("SELECT * FROM shifts WHERE pilot_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$pilotId])[0];
    ok('الوردية في الفرع الجديد', (int) $sh['branch_id'] === $bB, var_export($sh['branch_id'], true));
    if ($shiftBefore) {
        ok('نفس الوردية اتنقلت مش جديدة', (int) $sh['id'] === $shiftBefore, "قبل={$shiftBefore} بعد={$sh['id']}");
    }
    $hist = DB::select('SELECT COUNT(*) c FROM shift_branch_history WHERE shift_id = ?', [(int) $sh['id']])[0]->c;
    ok('بصمة الفروع اتسجّلت', (int) $hist >= 1, (string) $hist);

    $st2 = DB::select('SELECT status FROM pilot_support_requests WHERE id = ?', [$reqId2])[0]->status;
    ok('الطلب اتقفل ended من غير خطوة تانية', $st2 === 'ended', $st2);

    echo "\n══ 5) طيار فاضي → بيدخل الدور ══\n";
    /* «فاضي» يعني مفيش **ولا** أوردر جاري عليه — مش بس اللي الاختبار عمله.
       كان بيفرّغ `$oid` بس ويعتمد على إن القاعدة نضيفة، فأي أوردر تاني
       على نفس الطيار (بيانات تجربة مثلًا) بيخلّي الحالة `delivering`
       بحق وحقيق والفحص يقع من غير ما يكون فيه عيب في الكود. */
    DB::update("UPDATE orders SET status = 'delivered' WHERE pilot_id = ? AND status = 'delivering'", [$pilotId]);
    $out3 = json_decode($ctl->supportRequestCreate(req($actorA, ['pilotId' => $pilotId]))->getContent(), true);
    $ctl->supportSendPilot(req($actorB, ['pilotId' => $pilotId]), (string) $out3['id']);
    $p2 = (array) DB::select('SELECT * FROM pilots WHERE id = ?', [$pilotId])[0];
    ok('رجع للفرع الأول', (int) $p2['assigned_branch_id'] === $bA, var_export($p2['assigned_branch_id'], true));
    ok('دخل الدور waiting', $p2['status'] === 'waiting', var_export($p2['status'], true));
    ok('واخد رقم دور', $p2['queue_no'] !== null && (int) $p2['queue_no'] > 0, var_export($p2['queue_no'], true));
} catch (Throwable $e) {
    $fail++;
    echo "\n💥 " . get_class($e) . ': ' . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "TRANSFER: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
