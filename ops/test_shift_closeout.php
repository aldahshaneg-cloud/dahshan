<?php

/**
 * 🔒💰 حارس: تقفيلة الوردية — إخلاء الطرف بالعهدة وعمولة المشرف.
 *
 * ═══ الطلب (صاحب النظام 2026-09-01) ═══
 * «في نفس التقفيلة إثبات إن الطيار أخلى طرف بالعهدة وعودة العهدة إلى
 * الخزنة، وإمكانية عمل العمولة من المشرف: ثابت أو نسبة لكل الأوردرات
 * أو لكل أوردر على حدة».
 *
 * ═══ ليه تنفيذ حقيقي ═══
 * ده أخطر مسار فلوس في النظام: عهدة + خزنة + عمولات في معاملة واحدة.
 * الحارس بيزرع وردية وأوردرات وعهدة حقيقيين في معاملة بترجع، وبينده
 * نقطة النهاية نفسها عبر الراوتر كامل، وبيتحقق من **الأرصدة بعد** مش
 * من شكل الكود.
 *
 * التشغيل: php ops/test_shift_closeout.php
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

function hit($kernel, $u, string $url, array $body): array
{
    $req = Illuminate\Http\Request::create($url, 'POST', [], [], [], [], json_encode($body));
    $req->headers->set('Accept', 'application/json');
    $req->headers->set('Content-Type', 'application/json');
    $ses = app('session')->driver();
    $ses->start();
    $ses->put(['user_id' => (int) $u->id, 'username' => $u->username, 'role' => $u->role,
               'branch_id' => $u->branch_id !== null ? (int) $u->branch_id : null, 'name' => $u->username]);
    $req->setLaravelSession($ses);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

/* ── تجهيز: مشرف فرع + طيار من فرعه + خزنة الفرع ── */
$sup = DB::select("SELECT id, username, role, branch_id FROM users
                    WHERE role='branch' AND blocked=0 AND branch_id IS NOT NULL LIMIT 1")[0] ?? null;
$admin = DB::select("SELECT id, username, role, branch_id FROM users WHERE role='admin' AND blocked=0 LIMIT 1")[0];
if (! $sup) { echo "مافيش مشرف فرع — تخطّي\n"; exit(0); }
$pilotRow = DB::select('SELECT id, name FROM pilots WHERE archived_at IS NULL AND assigned_branch_id = ? LIMIT 1',
    [$sup->branch_id])[0]
    ?? DB::select('SELECT id, name FROM pilots WHERE archived_at IS NULL LIMIT 1')[0] ?? null;
if (! $pilotRow) { echo "مافيش طيارين — تخطّي\n"; exit(0); }
$store = DB::select('SELECT id FROM cash_stores WHERE branch_id = ? LIMIT 1', [$sup->branch_id])[0]
    ?? DB::select('SELECT id FROM cash_stores LIMIT 1')[0] ?? null;
if (! $store) { echo "مافيش خزن — تخطّي\n"; exit(0); }

/**
 * بيبني وردية نشطة بأوردرين متسلّمين (فلوسهم متسوّاة قبل كده عشان
 * التسوية جوه القفل ماتلعبش في العهدة) + عهدة على الطيار.
 */
function seed($sup, $pilotRow, float $custody): array
{
    DB::update('UPDATE pilots SET custody_balance = ?, assigned_branch_id = ?, status = ? WHERE id = ?',
        [$custody, $sup->branch_id, 'waiting', $pilotRow->id]);
    DB::insert('INSERT INTO shifts (pilot_id, branch_id, status, started_at, created_at)
                VALUES (?,?,?,?,NOW())',
        [$pilotRow->id, $sup->branch_id, 'active', gmdate('Y-m-d H:i:s', time() - 6 * 3600)]);
    $shiftId = (int) DB::getPdo()->lastInsertId();

    $orderIds = [];
    foreach ([100.0, 50.0] as $i => $price) {
        DB::insert("INSERT INTO orders (order_num, branch_id, status, status_since, pilot_id, pilot_name,
                        shift_id, total_delivery_price, net_delivery_price, money_settled,
                        delivered_at, created_at)
                    VALUES (?,?,?,NOW(),?,?,?,?,?,1,NOW(),NOW())",
            ['TST-CLOSE-' . $shiftId . '-' . $i, $sup->branch_id, 'delivered',
             $pilotRow->id, $pilotRow->name, $shiftId, $price, $price]);
        $orderIds[] = (int) DB::getPdo()->lastInsertId();
    }

    return [$shiftId, $orderIds];
}

$storeBal = fn () => round((float) DB::select('SELECT balance FROM cash_stores WHERE id = ?', [$store->id])[0]->balance, 2);
$custBal  = fn () => round((float) DB::select('SELECT custody_balance FROM pilots WHERE id = ?', [$pilotRow->id])[0]->custody_balance, 2);

DB::beginTransaction();
try {
    echo "المشرف: {$sup->username} · الطيار: {$pilotRow->name} · الخزنة #{$store->id}\n";

    /* الطيار من نسخة قاعدة حقيقية — ممكن يكون عليه أوردرات جارية أو
       متسلّمة غير مسوّاة، والتقفيلة بتسوّيها وبتضيف فرقها للعهدة فتبوّظ
       أرقام الفحص (حصل: +41.43 من أوردر قديم). بننضّفها جوه المعاملة —
       كله بيرجع في الآخر. */
    DB::update("UPDATE orders SET money_settled = 1 WHERE pilot_id = ? AND status = 'delivered' AND money_settled = 0", [$pilotRow->id]);
    DB::update("UPDATE orders SET pilot_id = NULL, shift_id = NULL WHERE pilot_id = ? AND status = 'delivering'", [$pilotRow->id]);

    /* ══ 1) 🔴 القفل بيترفض والعهدة عليه ══ */
    echo "\n══ 1) مشرف الفرع مايقدرش يقفل والعهدة مش صفر ══\n";
    [$shiftId] = seed($sup, $pilotRow, 200.0);
    [$code, $j] = hit($GLOBALS['kernel'], $sup, "/api/shifts/{$shiftId}/end",
        ['orders' => [], 'collectedAmount' => 0]);
    ok('🔴 اترفض', $code >= 400, (string) $code);
    ok('والرسالة بتقول الباقي كام', str_contains((string) ($j['error'] ?? $j['message'] ?? ''), '200.00'),
        (string) ($j['error'] ?? $j['message'] ?? '؟'));
    ok('والوردية فضلت مفتوحة',
        DB::select('SELECT status FROM shifts WHERE id = ?', [$shiftId])[0]->status === 'active');

    /* ══ 2) ردّ العهدة جوه التقفيلة بيقفلها ══ */
    echo "\n══ 2) ردّ العهدة كامل = إخلاء طرف والوردية بتتقفل ══\n";
    $s0 = $storeBal();
    [$code2, $j2] = hit($GLOBALS['kernel'], $sup, "/api/shifts/{$shiftId}/end",
        ['orders' => [], 'collectedAmount' => 0,
         'custodyReturn' => ['amount' => 200, 'cashStoreId' => $store->id]]);
    ok('HTTP 200', $code2 === 200, (string) $code2 . ' — ' . (string) ($j2['error'] ?? ''));
    ok('🔴 عهدة الطيار بقت صفر', $custBal() === 0.0, (string) $custBal());
    ok('🔴 والفلوس دخلت الخزنة فعلًا', $storeBal() === round($s0 + 200, 2),
        $storeBal() . ' vs ' . round($s0 + 200, 2));
    $sh = (array) DB::select('SELECT * FROM shifts WHERE id = ?', [$shiftId])[0];
    ok('والوردية اتقفلت', $sh['status'] === 'ended');
    ok('🔴 والإثبات متسجّل على الوردية: رجّع 200 والباقي صفر',
        round((float) $sh['custody_returned'], 2) === 200.0 && round((float) $sh['custody_carried'], 2) === 0.0,
        $sh['custody_returned'] . ' / ' . $sh['custody_carried']);
    ok('وحركة العهدة في السجل بنوع return ومربوطة بالخزنة',
        (bool) DB::select("SELECT id FROM custody_transactions
                           WHERE pilot_id = ? AND type = 'return' AND amount = 200 AND store_id = ?
                           ORDER BY id DESC LIMIT 1", [$pilotRow->id, $store->id]));
    ok('والرد بيرجّع الإثبات للتقرير',
        round((float) ($j2['custodyReturned'] ?? -1), 2) === 200.0
        && round((float) ($j2['custodyCarried'] ?? -1), 2) === 0.0);

    /* ══ 3) الإدارة بس تقدر ترحّل — وبعلم صريح ══ */
    echo "\n══ 3) ترحيل العهدة للإدارة بس وبعلم صريح ══\n";
    [$shiftId3] = seed($sup, $pilotRow, 80.0);
    [$c3a] = hit($GLOBALS['kernel'], $admin, "/api/shifts/{$shiftId3}/end",
        ['orders' => [], 'collectedAmount' => 0]);
    ok('الإدارة من غير العلم بتترفض برضه', $c3a >= 400, (string) $c3a);
    [$c3b, $j3b] = hit($GLOBALS['kernel'], $admin, "/api/shifts/{$shiftId3}/end",
        ['orders' => [], 'collectedAmount' => 0, 'allowCustodyCarry' => true]);
    ok('وبالعلم الصريح بتقفل', $c3b === 200, (string) $c3b . ' — ' . (string) ($j3b['error'] ?? ''));
    $sh3 = (array) DB::select('SELECT custody_returned, custody_carried FROM shifts WHERE id = ?', [$shiftId3])[0];
    ok('🔴 والباقي متسجّل — التقرير هيقول مش إخلاء طرف كامل',
        round((float) $sh3['custody_carried'], 2) === 80.0, (string) $sh3['custody_carried']);

    /* مشرف الفرع مايقدرش يستعمل العلم ده */
    [$shiftId3c] = seed($sup, $pilotRow, 80.0);
    [$c3c] = hit($GLOBALS['kernel'], $sup, "/api/shifts/{$shiftId3c}/end",
        ['orders' => [], 'collectedAmount' => 0, 'allowCustodyCarry' => true]);
    ok('🔴 مشرف الفرع بالعلم نفسه بيترفض — الترحيل قرار إداري', $c3c >= 400, (string) $c3c);
    /* نقفلها عشان الطيار يتحرر للبند الجاي */
    hit($GLOBALS['kernel'], $sup, "/api/shifts/{$shiftId3c}/end",
        ['orders' => [], 'collectedAmount' => 0,
         'custodyReturn' => ['amount' => 80, 'cashStoreId' => $store->id]]);

    /* ══ 4) حدود ردّ العهدة ══ */
    echo "\n══ 4) حدود الردّ ══\n";
    [$shiftId4] = seed($sup, $pilotRow, 50.0);
    [$c4a] = hit($GLOBALS['kernel'], $sup, "/api/shifts/{$shiftId4}/end",
        ['orders' => [], 'custodyReturn' => ['amount' => 70, 'cashStoreId' => $store->id]]);
    ok('ردّ أكبر من العهدة بيترفض', $c4a >= 400, (string) $c4a);
    [$c4b] = hit($GLOBALS['kernel'], $sup, "/api/shifts/{$shiftId4}/end",
        ['orders' => [], 'custodyReturn' => ['amount' => -5, 'cashStoreId' => $store->id]]);
    ok('والسالب بيترفض', $c4b >= 400, (string) $c4b);
    [$c4c] = hit($GLOBALS['kernel'], $sup, "/api/shifts/{$shiftId4}/end",
        ['orders' => [], 'custodyReturn' => ['amount' => 50]]);
    ok('ومن غير خزنة بيترفض', $c4c >= 400, (string) $c4c);
    hit($GLOBALS['kernel'], $sup, "/api/shifts/{$shiftId4}/end",
        ['orders' => [], 'custodyReturn' => ['amount' => 50, 'cashStoreId' => $store->id]]);

    /* ══ 5) 💰 العمولة: نسبة على كل الأوردرات ══ */
    echo "\n══ 5) عمولة نسبة % ══\n";
    [$shiftId5, $oids5] = seed($sup, $pilotRow, 0.0);
    [$c5, $j5] = hit($GLOBALS['kernel'], $sup, "/api/shifts/{$shiftId5}/end",
        ['orders' => [], 'collectedAmount' => 0,
         'commission' => ['mode' => 'percent', 'value' => 10]]);
    ok('HTTP 200', $c5 === 200, (string) $c5 . ' — ' . (string) ($j5['error'] ?? ''));
    $adj = fn (int $oid) => DB::select('SELECT kind, amount, reason FROM pilot_commission_adjustments WHERE order_id = ?', [$oid])[0] ?? null;
    $a1 = $adj($oids5[0]); $a2 = $adj($oids5[1]);
    ok('🔴 أوردر الـ100 عمولته 10.00', $a1 !== null && round((float) $a1->amount, 2) === 10.0,
        $a1 ? (string) $a1->amount : 'مافيش صف');
    ok('🔴 وأوردر الـ50 عمولته 5.00', $a2 !== null && round((float) $a2->amount, 2) === 5.0,
        $a2 ? (string) $a2->amount : 'مافيش صف');
    ok('والنوع override — بيحل محل حساب الطيار', $a1->kind === 'override');
    ok('والسبب بيوثّق النسبة', str_contains((string) $a1->reason, '10'));

    /* ══ 6) 💰 مبلغ ثابت لكل أوردر ══ */
    echo "\n══ 6) عمولة مبلغ ثابت ══\n";
    [$shiftId6, $oids6] = seed($sup, $pilotRow, 0.0);
    hit($GLOBALS['kernel'], $sup, "/api/shifts/{$shiftId6}/end",
        ['orders' => [], 'commission' => ['mode' => 'fixed', 'value' => 7]]);
    $b1 = $adj($oids6[0]); $b2 = $adj($oids6[1]);
    ok('🔴 الاتنين واخدين 7.00 بغض النظر عن السعر',
        $b1 && $b2 && round((float) $b1->amount, 2) === 7.0 && round((float) $b2->amount, 2) === 7.0,
        ($b1->amount ?? '؟') . ' / ' . ($b2->amount ?? '؟'));

    /* ══ 7) 💰 لكل أوردر على حدة — أوردر السفر ══ */
    echo "\n══ 7) تحديد يدوي لكل أوردر ══\n";
    [$shiftId7, $oids7] = seed($sup, $pilotRow, 0.0);
    hit($GLOBALS['kernel'], $sup, "/api/shifts/{$shiftId7}/end",
        ['orders' => [], 'commission' => ['mode' => 'custom', 'reason' => 'أوردر سفر — نص الخدمة',
            'perOrder' => [['orderId' => $oids7[0], 'amount' => 50]]]]);
    $c1 = $adj($oids7[0]); $c2 = $adj($oids7[1]);
    ok('🔴 أوردر السفر واخد 50.00', $c1 && round((float) $c1->amount, 2) === 50.0,
        $c1->amount ?? 'مافيش');
    ok('🔴 واللي ماتحددلوش **مافيش صف** — فاضل على حساب الطيار الافتراضي',
        $c2 === null, $c2 ? 'اتكتبله صف' : '');
    ok('والسبب اليدوي وصل', str_contains((string) $c1->reason, 'سفر'));

    /* ══ 8) mode=keep = ولا لمسة ══ */
    echo "\n══ 8) سيب حساب الطيار ══\n";
    [$shiftId8, $oids8] = seed($sup, $pilotRow, 0.0);
    [$c8] = hit($GLOBALS['kernel'], $sup, "/api/shifts/{$shiftId8}/end",
        ['orders' => [], 'commission' => ['mode' => 'keep', 'value' => 999]]);
    /* 🔴 طفرة عدّت من النسخة الأولى: شيل مخرج keep المبكر خلّى keep
       يقع في فحص الأنواع والتقفيلة كلها ترفض — والفحص كان بيشوف
       «مافيش صفوف» ويفرح، وهي مافيش صفوف عشان القفل نفسه فشل.
       النجاح شرط مش تحصيل حاصل. */
    ok('🔴 التقفيلة نجحت — keep مش بيترفض كنوع غريب', $c8 === 200, (string) $c8);
    ok('والوردية اتقفلت فعلًا',
        DB::select('SELECT status FROM shifts WHERE id = ?', [$shiftId8])[0]->status === 'ended');
    ok('🔴 ومافيش ولا صف عمولة اتكتب', $adj($oids8[0]) === null && $adj($oids8[1]) === null);

    /* ══ 9) الحدود ══ */
    echo "\n══ 9) حدود العمولة ══\n";
    [$shiftId9] = seed($sup, $pilotRow, 0.0);
    [$c9a] = hit($GLOBALS['kernel'], $sup, "/api/shifts/{$shiftId9}/end",
        ['orders' => [], 'commission' => ['mode' => 'percent', 'value' => 150]]);
    ok('نسبة فوق 100 بتترفض', $c9a >= 400, (string) $c9a);
    [$c9b] = hit($GLOBALS['kernel'], $sup, "/api/shifts/{$shiftId9}/end",
        ['orders' => [], 'commission' => ['mode' => 'fixed', 'value' => -3]]);
    ok('ومبلغ سالب بيترفض', $c9b >= 400, (string) $c9b);
    ok('والوردية لسه مفتوحة بعد الرفضين',
        DB::select('SELECT status FROM shifts WHERE id = ?', [$shiftId9])[0]->status === 'active');

    DB::rollBack();
    echo "\n✅ المعاملة رجعت — مافيش أثر\n";
} catch (Throwable $e) {
    DB::rollBack();
    echo '🔴 ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
}

echo "\n" . str_repeat('─', 52) . "\n";
echo $fail === 0
    ? "✅ عدّى {$pass} فحص — التقفيلة بتثبت إخلاء الطرف وبتكتب العمولة\n"
    : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
