<?php

/**
 * 💵 حارس: توصيل المرتجعات المدفوع — تنفيذ حقيقي.
 *
 * ═══ الطلب (صاحب النظام 2026-09-02) ═══
 * «لما الطيار يروح يوصل والعميل يرفض أو يرجّع، يا هو يا المحل بيدفع
 * للطيار تمن التوصيل علشان الحسابات تكون مظبوطة — لازم حاجة تبين إن
 * الأوردر رجع بس التوصيل اتدفع، على عكس إن الطيار وصل ومالقاش حد».
 *
 * العقود المثبتة:
 * • undeliver بيسجّل fareBy (receiver/sender/none) والقيمة واصلة في الـwire.
 * • تسوية عودة الطيار: المرتجع المدفوع بيدخل «المتوقع من الطيار»
 *   (عهدة/تحصيل) زي المتسلّم بالظبط — وغير المدفوع لأ.
 * • قيمة عبيطة من برة = none (مش خطأ).
 * • التسليم بعد إعادة الإرسال بيمسح العلامة القديمة.
 *
 * ⚠️ فيه بث أوردرات جوه معاملة بترجع — ممنوع على الإنتاج.
 *
 * التشغيل: php ops/test_undelivered_fare.php
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

function hit($kernel, $u, string $method, string $url, array $body = []): array
{
    $req = Illuminate\Http\Request::create($url, $method, [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], json_encode($body, JSON_UNESCAPED_UNICODE));
    $s = app('session')->driver();
    $s->flush(); $s->start();
    $s->put(['user_id' => (int) $u->id, 'username' => $u->username, 'role' => $u->role,
             'branch_id' => $u->branch_id !== null ? (int) $u->branch_id : null, 'name' => $u->username]);
    $req->setLaravelSession($s);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$sup = DB::select("SELECT id, username, role, branch_id FROM users
                    WHERE role='branch' AND blocked=0 AND branch_id IS NOT NULL LIMIT 1")[0] ?? null;
if (! $sup) { echo "مافيش مشرف فرع — تخطّي\n"; exit(0); }
$pilot = DB::select('SELECT id, name FROM pilots WHERE archived_at IS NULL AND assigned_branch_id = ? LIMIT 1', [$sup->branch_id])[0] ?? null;
$zone  = DB::select('SELECT id, area_name, price FROM zones WHERE price > 0 LIMIT 1')[0] ?? null;
if (! $pilot || ! $zone) { echo "مافيش طيار/زون — تخطّي\n"; exit(0); }

/* أوردر جاري على الطيار — مزروع مباشرة */
$mkOrder = function (float $price) use ($sup, $pilot, $zone): int {
    DB::insert("INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, status, status_since,
                        pilot_id, pilot_name, total_delivery_price, source, added_by, added_by_role, qr_code, created_at)
                VALUES (?,?,?,?,?,NOW(),?,?,?,?,?,?,?,NOW())",
        ['FARE-' . bin2hex(random_bytes(4)), $sup->branch_id, $sup->branch_id, 'راسل فحص', 'delivering',
         $pilot->id, $pilot->name, $price, 'branch', 'اختبار', 'branch', 'FARE-' . bin2hex(random_bytes(4))]);

    return (int) DB::getPdo()->lastInsertId();
};

DB::beginTransaction();
try {
    echo "══ 1) undeliver بيسجّل مين دفع ══\n";
    $o1 = $mkOrder(40);
    [$c1, $j1] = hit($kernel, $sup, 'POST', "/api/orders/{$o1}/undeliver",
        ['reason' => 'المستلم رفض', 'fareBy' => 'receiver']);
    ok('HTTP 200', $c1 === 200, (string) $c1);
    ok('🔴 fareBy=receiver اتخزنت وواصلة في الـwire',
        ($j1['order']['undeliveredFareBy'] ?? '') === 'receiver',
        (string) ($j1['order']['undeliveredFareBy'] ?? '؟'));

    $o2 = $mkOrder(35);
    [, $j2] = hit($kernel, $sup, 'POST', "/api/orders/{$o2}/undeliver",
        ['reason' => 'x', 'fareBy' => 'حاجة-عبيطة']);
    ok('قيمة عبيطة = none (مش خطأ)', ($j2['order']['undeliveredFareBy'] ?? '') === 'none');
    $o3 = $mkOrder(20);
    [, $j3] = hit($kernel, $sup, 'POST', "/api/orders/{$o3}/undeliver", ['reason' => 'y']);
    ok('ومن غير المفتاح = none (سلوك ما قبل الخاصية)',
        ($j3['order']['undeliveredFareBy'] ?? '') === 'none');

    echo "\n══ 2) 🔴 تسوية عودة الطيار — المدفوع بيدخل المتوقع ══\n";
    /* تلات أوردرات جارية: متسلّم 50 + مرتجع مدفوع (المحل) 30 + مرتجع بلا تحصيل 25.
       المتوقع = 50 + 30 = 80. التحصيل صفر → فرق العهدة = 80.
       تحييد بيانات المحلية الموروثة الأول (جوه المعاملة — بترجع): أي
       أوردر جاري أو متسلّم بفلوس مش متسوّاة على الطيار كان هيدخل الحساب
       ويبوّظ الرقم المتوقع. */
    DB::update("UPDATE orders SET status = 'processing', pilot_id = NULL, shift_id = NULL
                 WHERE pilot_id = ? AND status = 'delivering'", [$pilot->id]);
    DB::update("UPDATE orders SET money_settled = 1
                 WHERE pilot_id = ? AND status = 'delivered' AND money_settled = 0", [$pilot->id]);
    /* ومرتجعات القسم الأول المدفوعة (o1) — بقت بتدخل التسوية بحق بعد
       إصلاح المناورة، فبنحيّدها عشان توقّع الـ80 يفضل نضيف */
    DB::update("UPDATE orders SET money_settled = 1
                 WHERE pilot_id = ? AND status = 'undelivered' AND money_settled = 0", [$pilot->id]);
    $a = $mkOrder(50); $b = $mkOrder(30); $c = $mkOrder(25);
    [$cr, $jr] = hit($kernel, $sup, 'POST', "/api/pilots/{$pilot->id}/return", [
        'orders' => [
            ['orderId' => $a, 'choice' => 'delivered'],
            ['orderId' => $b, 'choice' => 'undelivered', 'reason' => 'رفض', 'fareBy' => 'sender'],
            ['orderId' => $c, 'choice' => 'undelivered', 'reason' => 'مفيش حد', 'fareBy' => 'none'],
        ],
        'collectedAmount' => 0, 'cashStoreId' => null,
    ]);
    ok('التسوية نجحت', $cr === 200, (string) $cr . ' ' . json_encode($jr, JSON_UNESCAPED_UNICODE));
    $rows = DB::select(
        "SELECT amount, reason FROM custody_transactions
          WHERE pilot_id = ? ORDER BY id DESC LIMIT 1", [$pilot->id]);
    $lastAmt = (float) ($rows[0]->amount ?? -1);
    ok('🔴 فرق العهدة = 80 (المتسلّم 50 + المرتجع المدفوع 30) — مش 50 ولا 105',
        abs($lastAmt - 80.0) < 0.01, (string) $lastAmt);
    $bRow = DB::selectOne('SELECT status, undelivered_fare_by FROM orders WHERE id = ?', [$b]);
    ok('والمرتجع المدفوع متعلّم sender', $bRow->status === 'undelivered' && $bRow->undelivered_fare_by === 'sender');

    echo "\n══ 2ب) 🔴 مرتجع اتدفع توصيله **أثناء** الوردية بيدخل التسوية ══\n";
    /* درس المناورة 2026-09-03: المرتجع اللي اتعلّم بزر «لم يتم التوصيل»
       (مش بقرار مودال التسوية) ماكانش بيدخل expected خالص — السيرفر رفض
       إخلاء الطرف («ردّ العهدة أكبر من اللي على الطيار») مع إن الفلوس
       كلها اتحصّلت. */
    $d2 = $mkOrder(45);
    hit($kernel, $sup, 'POST', "/api/orders/{$d2}/undeliver", ['reason' => 'رفض', 'fareBy' => 'receiver']);
    [$cr2] = hit($kernel, $sup, 'POST', "/api/pilots/{$pilot->id}/return", [
        'orders' => [], 'collectedAmount' => 0, 'cashStoreId' => null,
    ]);
    $rows2 = DB::select('SELECT amount FROM custody_transactions WHERE pilot_id = ? ORDER BY id DESC LIMIT 1', [$pilot->id]);
    ok('🔴 فلوسه (45) دخلت المتوقع واتسجلت عهدة', $cr2 === 200 && abs((float) ($rows2[0]->amount ?? -1) - 45.0) < 0.01,
        (string) ($rows2[0]->amount ?? '؟'));
    $d2Row = DB::selectOne('SELECT money_settled FROM orders WHERE id = ?', [$d2]);
    ok('واتعلّم money_settled — مش هيتحاسب تاني', (int) $d2Row->money_settled === 1);
    [$cr3] = hit($kernel, $sup, 'POST', "/api/pilots/{$pilot->id}/return", [
        'orders' => [], 'collectedAmount' => 0, 'cashStoreId' => null,
    ]);
    $rows3 = DB::select('SELECT amount FROM custody_transactions WHERE pilot_id = ? ORDER BY id DESC LIMIT 1', [$pilot->id]);
    ok('وتسوية تانية مافيهاش تكرار (نفس آخر حركة)', $cr3 === 200 && abs((float) $rows3[0]->amount - 45.0) < 0.01);

    echo "\n══ 3) إعادة الإرسال والتسليم بيمسح العلامة ══\n";
    DB::update("UPDATE orders SET status = 'delivering' WHERE id = ?", [$o1]);
    [$cd] = hit($kernel, $sup, 'POST', "/api/orders/{$o1}/deliver", []);
    $o1Row = DB::selectOne('SELECT undelivered_fare_by FROM orders WHERE id = ?', [$o1]);
    ok('اتسلّم والعلامة القديمة اتمسحت', $cd === 200 && $o1Row->undelivered_fare_by === null,
        (string) ($o1Row->undelivered_fare_by ?? 'NULL'));

    echo "\n══ 4) موافقة الإرجاع بتاخد fareBy ══\n";
    $o4 = $mkOrder(15);
    DB::insert("INSERT INTO pilot_return_requests (order_id, order_num, pilot_id, branch_id, reason, status, requested_at, created_at)
                VALUES (?,?,?,?,?,'pending',NOW(),NOW())",
        [$o4, 'FARE-REQ', $pilot->id, $sup->branch_id, 'بضاعة ناقصة']);
    $reqId = (int) DB::getPdo()->lastInsertId();
    [$ca] = hit($kernel, $sup, 'POST', "/api/return-requests/{$reqId}/approve", ['fareBy' => 'receiver']);
    $o4Row = DB::selectOne('SELECT status, undelivered_fare_by FROM orders WHERE id = ?', [$o4]);
    ok('الموافقة سجّلت المدفوع من المستلم',
        $ca === 200 && $o4Row->status === 'undelivered' && $o4Row->undelivered_fare_by === 'receiver',
        json_encode($o4Row));

    echo "\n══ 4ب) إصلاحات ملاحظات تدقيق المناورة (2026-09-03) ══\n";
    $bc = file_get_contents('app/Http/Controllers/Api/BoardController.php');
    $bcS = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $bc);
    ok('الشهرية بتبدأ بساعة اليوم التجاري (BizDay::startHour في monthRange)',
        str_contains($bcS, "sprintf('%s-01 %02d:00:00', \$month, BizDay::startHour())"));
    ok('وربط الـoverride بالطيار (a.pilot_id = o.pilot_id) زي autoMatrix',
        str_contains($bcS, "AND a.kind = 'override' AND a.pilot_id = o.pilot_id"));
    ok('وnet_delivery_price بيتكتب من مسار التقفيلة برضه',
        preg_match("/status = 'delivered'[^\"]*net_delivery_price = \?/", $bcS) === 1);

    echo "\n══ 5) الواجهات ══\n";
    $strip = fn (string $t) => preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $t);
    foreach (['public/branch.html', 'public/tiar.html'] as $f) {
        $s = $strip(file_get_contents($f));
        ok("{$f}: مودال «مين دفع التوصيل» موجود", str_contains($s, 'window.askUndeliverFare'));
        ok('  وعدم التسليم بيبعت fareBy', str_contains($s, 'undeliver`, { reason: reason || "—", fareBy }'));
        ok('  وموافقة الإرجاع بتبعته', str_contains($s, 'approve`, { fareBy }'));
        ok('  وراديوهات المودالين (عودة + تقفيلة)',
            str_contains($s, '_retFare_${idx}') && str_contains($s, '_seFare_${idx}'));
        ok('  وتقرير الوردية بيبيّن مجموع المرتجعات المدفوعة',
            str_contains($s, 'returnFareTotal'));
        ok('  وشارة الجدول', str_contains($s, 'المستلم دفع التوصيل'));
        /* إصلاحات ملاحظات المناورة 2026-09-03 */
        ok('  وفلتر الورديات باليوم التجاري (9ص → 9ص)',
            str_contains($s, 'function bizDayStartMs('));
        ok('  والتقرير بيقرا override التقفيلة (CommAdj.amountFor)',
            str_contains($s, 'CommAdj.amountFor(o, pilotCommissionFor(pilot'));
        ok('  واقتراحات فلوس الطيار بالصافي بعد المحفظة',
            str_contains($s, '(Number(o.totalDeliveryPrice) || 0) - (Number(o.walletUsed) || 0)'));
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
    ? "✅ عدّى {$pass} فحص — توصيل المرتجعات المدفوع بيتحاسب صح\n"
    : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
