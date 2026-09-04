<?php

/**
 * 🔁 حارس: مانع تكرار الأوردرات (clientRef) — تنفيذ حقيقي.
 *
 * ═══ الواقعة (صاحب النظام 2026-09-02) ═══
 * موظف الكول سنتر شاف «خطأ أثناء الحفظ» على أوردر اتسجّل فعلًا (عطل
 * شاشة بعد رد 200) فأعاد إدخال نفس الأوردر تلات مرات — تلات أوردرات
 * حقيقية GISH-260902-002/003/004. قبلها نفس القصة في تطبيق العميل
 * (receipt is not defined). القاعدة الجديدة: الواجهة بتبعت clientRef
 * ثابت لحد ما حفظ ينجح، والسيرفر بيرجّع نفس الأوردرات بعلامة duplicate
 * لأي إعادة بنفس المفتاح.
 *
 * ⚠️ ممنوع تشغيله على الإنتاج: بيعمل POST /api/orders حقيقي جوه معاملة
 * بترجع — البث وإشعارات الواتساب بتتدفع قبل الـrollback.
 *
 * التشغيل: php ops/test_order_dedupe.php
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

$sup = DB::select("SELECT id, username, role, branch_id FROM users
                    WHERE role='branch' AND blocked=0 AND branch_id IS NOT NULL LIMIT 1")[0] ?? null;
if (! $sup) { echo "مافيش مشرف فرع — تخطّي\n"; exit(0); }
$zone = DB::select('SELECT id, area_name FROM zones WHERE delivery_branch_id = ? LIMIT 1', [$sup->branch_id])[0]
    ?? DB::select('SELECT id, area_name FROM zones LIMIT 1')[0] ?? null;
if (! $zone) { echo "مافيش مناطق — تخطّي\n"; exit(0); }

function postOrder($kernel, $u, array $body): array
{
    $req = Illuminate\Http\Request::create('/api/orders', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], json_encode($body, JSON_UNESCAPED_UNICODE));
    $ses = app('session')->driver();
    $ses->start();
    $ses->put(['user_id' => (int) $u->id, 'username' => $u->username, 'role' => $u->role,
               'branch_id' => (int) $u->branch_id, 'name' => $u->username]);
    $req->setLaravelSession($ses);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$mkBody = fn (string $ref, int $parcels = 2) => [
    'clientRef'  => $ref,
    'senderName' => 'اختبار مانع التكرار', 'senderPhone' => '01000000001',
    'senderAddress' => 'شارع الفحص', 'notes' => 'حارس dedupe',
    'deliveries' => array_map(fn ($i) => [
        'parcelNo' => $i, 'receiverName' => "مستلم {$i}", 'receiverPhone' => '01000000002',
        'zoneId' => (int) $GLOBALS['zone']->id, 'zoneName' => (string) $GLOBALS['zone']->area_name,
        'zonePrice' => 15, 'orderPrice' => 0, 'address' => "عنوان {$i}", 'note' => '',
    ], range(1, $parcels)),
    'source' => 'branch',
];
$GLOBALS['zone'] = $zone;

DB::beginTransaction();
try {
    $ref = 'guard-' . bin2hex(random_bytes(8));

    echo "══ 1) الإنشاء الأول ══\n";
    [$c1, $j1] = postOrder($kernel, $sup, $mkBody($ref));
    ok('HTTP 200', $c1 === 200, (string) $c1);
    $ids1 = array_map(fn ($o) => (int) $o['id'], $j1['orders'] ?? []);
    ok('طردان → أوردران (كل طرد أوردر بذاته)', count($ids1) === 2, json_encode($ids1));
    ok('مافيش علامة duplicate في الإنشاء الأول', empty($j1['duplicate']));

    echo "\n══ 2) 🔴 إعادة الإرسال بنفس المفتاح — قلب الحارس ══\n";
    [$c2, $j2] = postOrder($kernel, $sup, $mkBody($ref));
    ok('HTTP 200 (مش خطأ — رجوع هادي)', $c2 === 200, (string) $c2);
    $ids2 = array_map(fn ($o) => (int) $o['id'], $j2['orders'] ?? []);
    ok('🔴 نفس الأوردرات بالظبط رجعت — مافيش نسخة جديدة', $ids2 === $ids1,
        json_encode(['الأول' => $ids1, 'التاني' => $ids2]));
    ok('🔴 وعلامة duplicate=true عشان الواجهة تقول «كان متسجّل خلاص»',
        ($j2['duplicate'] ?? false) === true);
    $cnt = (int) DB::select('SELECT COUNT(*) c FROM orders WHERE client_ref LIKE ?', [$ref . '#%'])[0]->c;
    ok('🔴 القاعدة فيها أوردرات المفتاح ده مرة واحدة بس', $cnt === 2, (string) $cnt);

    echo "\n══ 3) مفتاح مختلف = أوردر جديد طبيعي ══\n";
    [$c3, $j3] = postOrder($kernel, $sup, $mkBody('guard-' . bin2hex(random_bytes(8)), 1));
    $ids3 = array_map(fn ($o) => (int) $o['id'], $j3['orders'] ?? []);
    ok('اتعمل أوردر جديد فعلًا', $c3 === 200 && count($ids3) === 1 && ! in_array($ids3[0] ?? 0, $ids1, true));

    echo "\n══ 4) من غير مفتاح (تطبيقات قديمة/طيار الأيفون) — زي الأول بالظبط ══\n";
    $noRef = $mkBody('', 1);
    unset($noRef['clientRef']);
    [$c4a, $j4a] = postOrder($kernel, $sup, $noRef);
    [$c4b, $j4b] = postOrder($kernel, $sup, $noRef);
    $a = (int) ($j4a['orders'][0]['id'] ?? 0);
    $b = (int) ($j4b['orders'][0]['id'] ?? 0);
    ok('نداءان بلا مفتاح = أوردران منفصلان (مافيش دوس على السلوك القديم)',
        $c4a === 200 && $c4b === 200 && $a && $b && $a !== $b);

    echo "\n══ 5) المفتاح الفريد في القاعدة موجود فعلًا ══\n";
    $uq = DB::select("SHOW INDEX FROM orders WHERE Key_name = 'uq_orders_client_ref' AND Non_unique = 0");
    ok('uq_orders_client_ref فريد — شبكة أمان السباق مش مجرد فحص كود', count($uq) > 0);

    DB::rollBack();
    echo "\n✅ المعاملة رجعت\n";
} catch (Throwable $e) {
    DB::rollBack();
    echo '🔴 ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
}

echo "\n" . str_repeat('─', 52) . "\n";
echo $fail === 0
    ? "✅ عدّى {$pass} فحص — إعادة الإرسال مابتكرّرش الأوردر\n"
    : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
