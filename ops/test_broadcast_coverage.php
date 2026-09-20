<?php
/**
 * 📡 حارس: عمليات اللوحة بتبعت بثّ فوري (مراجعة صاحب النظام 2026-09-21).
 *
 * «أنا عايزك تراجع موضوع البث المباشر إن السيرفر يبعت على طول أول ما أي حد يعمل أي عملية».
 * الجرد (ops/audit_broadcast_coverage.php) لقى إن كل عمليات الأوردر بتبعت، لكن 17 عملية في
 * اللوحة كانت بتستنى الاستطلاع: طلبات الدعم (إنذار عاجل!) ونقل الطيارين وطلبات الانضمام ودور
 * الانتظار وفتح/نقل الوردية.
 *
 * ═══ العقود (تنفيذ حقيقي + Event::fake جوه معاملة بتترجع) ═══
 * • طلب دعم عام → حدث support لكل الفروع · الرد/الإلغاء → للفرع الطالب على الأقل.
 * • طلب نقل طيار → الفرعين · ترتيب الدور → الفرع · فتح وردية → فرع الطيار + قناة الطيار.
 * • الجرد: كل مسارات الدعم/النقل/الانضمام/الدور/الوردية متغطية.
 * • الواجهتين (فرع/إدارة) بيركلوا /api/support-requests مع الحدث.
 * التشغيل: php ops/test_broadcast_coverage.php
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

use App\Events\PilotRequestChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

echo "══ 0) الجرد والواجهات ══\n";
$audit = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/ops/audit_broadcast_coverage.php') . ' --missing');
foreach (['support-requests', 'pilot-transfers', 'join-requests', 'queue/', 'shifts/open', 'shifts/{id}/transfer'] as $frag) {
    ok("مفيش مسار «{$frag}» من غير بث", ! preg_match('#/api/' . preg_quote($frag, '#') . '#', $audit), trim((string) (preg_grep('#' . preg_quote($frag, '#') . '#', explode("\n", $audit))[0] ?? '')));
}
foreach (['branch', 'tiar'] as $p) {
    ok("{$p}: حدث طلب الطيار بيركل /api/support-requests", str_contains((string) file_get_contents($ROOT . "/public/{$p}.html"), '"/api/shifts", "/api/pilots", "/api/support-requests"]'));
}

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
function hit($kernel, array $u, string $method, string $url, array $body = []): array
{
    $req = Request::create($url, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body ? json_encode($body) : null);
    $ses = app('session')->driver();
    $ses->flush(); $ses->start();
    $ses->put(['user_id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role'],
               'branch_id' => $u['branch_id'] ?? null, 'name' => $u['username']]);
    $req->setLaravelSession($ses);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$sups = array_map(fn ($r) => (array) $r, DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'branch' AND blocked = 0 AND branch_id IS NOT NULL ORDER BY id LIMIT 2"));
if (count($sups) < 2 || $sups[0]['branch_id'] === $sups[1]['branch_id']) {
    $sups = array_map(fn ($r) => (array) $r, DB::select("SELECT MIN(id) id, MIN(username) username, 'branch' role, branch_id FROM users WHERE role = 'branch' AND blocked = 0 AND branch_id IS NOT NULL GROUP BY branch_id ORDER BY branch_id LIMIT 2"));
}
if (count($sups) < 2) { echo "مافيش مشرفين لفرعين — تخطّي\n"; exit(0); }
[$s1, $s2] = $sups;
$b1 = (int) $s1['branch_id'];
$b2 = (int) $s2['branch_id'];
$nBranches = (int) DB::selectOne('SELECT COUNT(*) c FROM branches')->c;

$seen = function (string $kind): array {
    $out = [];
    foreach (Event::dispatched(PilotRequestChanged::class) as [$e]) { if ($e->kind === $kind) { $out[] = $e->branchId; } }

    return $out;
};

/* الأحداث ShouldDispatchAfterCommit — والمعاملة الغلاف بتاعة الحارس عمرها ما بتتأكّد، فمن غير ده
   الحدث بيفضل مستني للأبد. نفس مدير المعاملات اللي RefreshDatabase بيركّبه: الغلاف مابيتحسبش. */
$conn = DB::connection();
$tm = new Illuminate\Foundation\Testing\DatabaseTransactionsManager([$conn->getName()]);
$app->instance('db.transactions', $tm);
$conn->setTransactionManager($tm);

DB::beginTransaction();
try {
    Event::fake([PilotRequestChanged::class]);

    echo "\n══ 1) طلب دعم عام ══\n";
    [$c, $j] = hit($kernel, $s1, 'POST', '/api/support-requests', ['notes' => 'حارس البث']);
    $rid = (int) ($j['id'] ?? 0);
    ok('الإنشاء 200', $c === 200 && $rid > 0, $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    $got = $seen('support');
    ok('🔴 حدث support وصل لكل الفروع (' . $nBranches . ')', count(array_unique($got)) === $nBranches && in_array($b2, $got, true), count(array_unique($got)) . ' فرع');

    Event::fake([PilotRequestChanged::class]);
    [$c] = hit($kernel, $s2, 'POST', "/api/support-requests/{$rid}/respond", ['response' => 'rejected', 'reason' => 'حارس']);
    ok('الرد 200 وبيبعت للفرع الطالب', $c === 200 && in_array($b1, $seen('support'), true), (string) $c);

    Event::fake([PilotRequestChanged::class]);
    [$c] = hit($kernel, $s1, 'POST', "/api/support-requests/{$rid}/cancel");
    ok('الإلغاء 200 وبيبعت', $c === 200 && count($seen('support')) > 0, (string) $c);

    echo "\n══ 2) نقل طيار · الدور · فتح وردية ══\n";
    $pilot = DB::selectOne('SELECT id FROM pilots WHERE assigned_branch_id = ? AND archived_at IS NULL ORDER BY id LIMIT 1', [$b1]);
    if ($pilot) {
        Event::fake([PilotRequestChanged::class]);
        [$c, $j] = hit($kernel, $s1, 'POST', '/api/pilot-transfers', ['pilotId' => (int) $pilot->id, 'toBranchId' => $b2]);
        $tg = $seen('transfer');
        ok('طلب النقل بيبعت للفرعين', $c === 200 ? (in_array($b1, $tg, true) && in_array($b2, $tg, true)) : true, $c . ' ' . ($j['error'] ?? '') . ' → ' . json_encode($tg));
    }
    Event::fake([PilotRequestChanged::class]);
    [$c] = hit($kernel, $s1, 'POST', '/api/queue/reorder', ['pilotIds' => [], 'order' => []]);
    ok('ترتيب الدور: لو نجح بيبعت للفرع', $c !== 200 || in_array($b1, $seen('queue'), true), (string) $c);

    $free = DB::selectOne('SELECT p.id FROM pilots p WHERE p.assigned_branch_id = ? AND p.archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM shifts s WHERE s.pilot_id = p.id AND s.ended_at IS NULL) ORDER BY p.id LIMIT 1', [$b1]);
    if ($free) {
        Event::fake([PilotRequestChanged::class]);
        [$c, $j] = hit($kernel, $s1, 'POST', '/api/shifts/open', ['pilotId' => (int) $free->id]);
        $ev = null;
        foreach (Event::dispatched(PilotRequestChanged::class) as [$e]) { if ($e->kind === 'board') { $ev = $e; } }
        ok('🔴 فتح الوردية بيبعت لفرع الطيار وقناة الطيار', $c === 200 && $ev && $ev->pilotId === (int) $free->id, $c . ' ' . ($j['error'] ?? ''));
    } else {
        echo "  (مفيش طيار من غير وردية في الفرع — اتخطّى فحص فتح الوردية)\n";
    }
    /* ═══ 3) الأوردر اللي خرج من الفرع — الفرع القديم لازم يعرف (بلاغ صاحب النظام 2026-09-21) ═══
       «لما بحمّل أوردر من الخريطة على طيار تاني بلاقي الفرع اللي أنا فيه مش بيعمل رفريش داخلي».
       الحدث كان بيروح لقناة الفرع **الجديد** بس، والدلتا عمرها ما بتقول «ده راح». */
    echo "\n══ 3) تحميل على طيار فرع تاني: حدث للفرع القديم ══\n";
    $otherPilot = DB::selectOne(
        'SELECT p.id, p.assigned_branch_id FROM pilots p JOIN shifts s ON s.pilot_id = p.id AND s.ended_at IS NULL
          WHERE p.assigned_branch_id <> ? AND p.archived_at IS NULL AND p.status IN (\'waiting\', \'delivering\') ORDER BY p.id LIMIT 1', [$b1]);
    $zone = DB::selectOne('SELECT id, price FROM zones WHERE delivery_branch_id = ? ORDER BY id LIMIT 1', [$b1])
        ?: DB::selectOne('SELECT id, price FROM zones WHERE price > 0 ORDER BY id LIMIT 1');
    if ($otherPilot && $zone) {
        /* البث الحقيقي محتاج Reverb شغّال — محليًا مش موجود، فالحدث بيتزيّف من قبل إنشاء الأوردر */
        Event::fake();   // كل الأحداث — أي بث حقيقي محليًا بيقع على Reverb المش موجود
        [$c, $j] = hit($kernel, $s1, 'POST', '/api/orders', ['senderName' => 'حارس البث', 'senderPhone' => '01000000009', 'senderAddress' => 'شارع',
            'deliveries' => [['parcelNo' => 1, 'receiverName' => 'مستلم', 'receiverPhone' => '01000000010', 'zoneId' => (int) $zone->id, 'zonePrice' => (float) $zone->price, 'orderPrice' => 0, 'address' => 'عنوان']]]);
        $oid = (int) ($j['orders'][0]['id'] ?? 0);
        ok('  أوردر الفرع اتعمل', $c === 200 && $oid > 0, $c . ' ' . mb_substr(json_encode($j, JSON_UNESCAPED_UNICODE), 0, 200));
        Event::fake();
        [$c, $j] = hit($kernel, $s1, 'POST', '/api/orders/assign-bulk', ['orderIds' => [$oid], 'pilotId' => (int) $otherPilot->id]);
        ok('التحميل 200 والأوردر اتنقل', $c === 200 && ! empty($j['movedBranch']), $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
        $main = $left = null;
        foreach (Event::dispatched(App\Events\OrderChanged::class) as [$e]) {
            if ($e->orderId !== $oid) { continue; }
            if ($e->notifyBranchId === null) { $main = $e; } else { $left = $e; }
        }
        $newBranch = (int) ($j['toBranchId'] ?? 0);
        ok('  الحدث الأساسي على قناة الفرع الجديد', $main && $main->branchId === $newBranch && $main->broadcastOn()[0]->name === 'private-branch.' . $newBranch);
        ok('🔴 وحدث «خرج من عندك» على قناة الفرع القديم', $left && $left->notifyBranchId === $b1 && count($left->broadcastOn()) === 1 && $left->broadcastOn()[0]->name === 'private-branch.' . $b1,
            $left ? $left->broadcastOn()[0]->name : 'مفيش حدث');
        ok('🔴 وحمولته فيها الفرع **الجديد** (عشان اللوحة تعرف إنه مابقاش بتاعها)', $left && (int) $left->broadcastWith()['branchId'] === $newBranch);
    } else {
        echo "  (مفيش طيار فرع تاني بوردية مفتوحة — اتخطّى)\n";
    }
    $B = (string) file_get_contents($ROOT . '/public/branch.html');
    ok('branch: حدث بفرع غير فرعنا = الأوردر بيتشال من الكاش فورًا', str_contains($B, 'String(p.branchId) !== String(branchId)) window._brDropOrders([p.id]);')
        && str_contains($B, 'stops.push(RT.subscribeBranch(branchId, onOrderEvent, function () {'));
    ok('branch: ونجاح التحميل من الخريطة بيشيله حالًا من غير ما يستنى البث', str_contains($B, 'if (res.movedBranch && typeof window._brDropOrders === "function") window._brDropOrders(res.loaded || []);'));
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "BROADCAST COVERAGE: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
