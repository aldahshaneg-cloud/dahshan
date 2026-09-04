<?php

/**
 * 📝 حارس: كل ملاحظة خاصة بطردها — تنفيذ حقيقي.
 *
 * البلاغ (صاحب النظام 2026-09-03): «في بوابة المحلات لو بتعمل أوردرين مع
 * بعض وحطيت ملحوظة في الطرد الأول بتسمع في الطرد التاني أوتوماتيك».
 * السبب: ملاحظة الشحنة العامة (`notes`) كانت بتتكتب على **كل** أوردر ناتج
 * من التفريق، وملاحظة الطرد (`note`) بتتخزن على الطرد بس ومابتظهرش في
 * جداول الفرع.
 *
 * العقود المثبتة:
 * • أوردر كل طرد بياخد ملاحظة طرده هو في `orders.notes`.
 * • الملاحظة العامة (لو الفرع/الإدارة كتبوها) بتتلحق بعد ملاحظة الطرد،
 *   ولو الطرد من غير ملاحظة بياخدها لوحدها — ماتضيعش.
 * • مافيش ولا واحدة = null.
 *
 * ⚠️ إنشاء أوردرات حقيقي جوه معاملة بترجع (بث) — ممنوع على الإنتاج.
 *
 * التشغيل: php ops/test_parcel_notes.php
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

$sup  = DB::select("SELECT id, username, role, branch_id FROM users WHERE role='branch' AND blocked=0 AND branch_id IS NOT NULL LIMIT 1")[0] ?? null;
$zone = DB::select('SELECT id, area_name FROM zones WHERE price > 0 LIMIT 1')[0] ?? null;
if (! $sup || ! $zone) { echo "مافيش مشرف فرع/زون — تخطّي\n"; exit(0); }

$parcel = fn (int $i, ?string $note) => [
    'parcelNo' => $i, 'receiverName' => "مستلم {$i}", 'receiverPhone' => '01000000002',
    'zoneId' => (int) $zone->id, 'zoneName' => (string) $zone->area_name,
    'zonePrice' => 15, 'orderPrice' => 0, 'address' => "عنوان {$i}", 'note' => $note,
];
$post = function (array $deliveries, ?string $shipmentNotes) use ($kernel, $sup): array {
    [$c, $j] = hit($kernel, $sup, 'POST', '/api/orders', [
        'senderName' => 'محل الملاحظات', 'senderPhone' => '01000000001', 'senderAddress' => 'شارع الفحص',
        'notes' => $shipmentNotes, 'deliveries' => $deliveries, 'source' => 'branch',
    ]);
    $notes = array_map(fn ($o) => (string) DB::selectOne('SELECT notes FROM orders WHERE id = ?', [(int) $o['id']])->notes, $j['orders'] ?? []);

    return [$c, $notes];
};

DB::beginTransaction();
try {
    echo "══ 1) 🔴 طردان بملاحظتين مختلفتين — كل أوردر بملاحظته ══\n";
    [$c1, $n1] = $post([$parcel(1, 'هش — لا تكسر'), $parcel(2, 'يتصل قبل الوصول')], null);
    ok('HTTP 200 وأوردران', $c1 === 200 && count($n1) === 2, $c1 . ' ' . json_encode($n1, JSON_UNESCAPED_UNICODE));
    ok('🔴 الأول بملاحظته والتاني بملاحظته — مافيش تسريب',
        ($n1[0] ?? '') === 'هش — لا تكسر' && ($n1[1] ?? '') === 'يتصل قبل الوصول',
        json_encode($n1, JSON_UNESCAPED_UNICODE));

    echo "\n══ 2) ملاحظة على طرد واحد بس ══\n";
    [, $n2] = $post([$parcel(1, 'هش'), $parcel(2, null)], null);
    ok('التاني من غير ملاحظة خالص (مش ورث ملاحظة الأول)',
        ($n2[0] ?? '') === 'هش' && ($n2[1] ?? 'x') === '', json_encode($n2, JSON_UNESCAPED_UNICODE));

    echo "\n══ 3) الملاحظة العامة للشحنة (الفرع/الإدارة) ماتضيعش ══\n";
    [, $n3] = $post([$parcel(1, 'هش'), $parcel(2, null)], 'استلم قبل الساعة 2');
    ok('طرد بملاحظة: ملاحظته ثم العامة', ($n3[0] ?? '') === 'هش — استلم قبل الساعة 2', json_encode($n3, JSON_UNESCAPED_UNICODE));
    ok('طرد بلا ملاحظة: العامة لوحدها', ($n3[1] ?? '') === 'استلم قبل الساعة 2', json_encode($n3, JSON_UNESCAPED_UNICODE));

    echo "\n══ 4) نفس النص في الاتنين = مرة واحدة ══\n";
    [, $n4] = $post([$parcel(1, 'هش')], 'هش');
    ok('مافيش تكرار «هش — هش»', ($n4[0] ?? '') === 'هش', json_encode($n4, JSON_UNESCAPED_UNICODE));
} finally {
    DB::rollBack();
}

echo "\n────────────────────────────────────\n";
if ($fail > 0) { echo "🔴 {$fail} فحص وقع (نجح {$pass})\n"; exit(1); }
echo "✅ كل الفحوص عدّت ({$pass})\n";
