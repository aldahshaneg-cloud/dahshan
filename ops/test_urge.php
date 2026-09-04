<?php

declare(strict_types=1);

/**
 * ⏰ اختبار استعجال الأوردر — كله جوه معاملة بتترجع فمفيش أثر.
 *
 * بيغطي: التوجيه (فرع مقابل طيار) · الحد الزمني 4 دقايق · نطاق المحل ·
 * الأوردر المنتهي · العدّاد · الحدث المبثوث.
 *
 * التشغيل: php ops/test_urge.php
 */

use App\Events\OrderUrged;
use App\Http\Controllers\Api\OrderUrgeController;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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

function req(Actor $a, array $body = []): Request
{
    $r = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    $r->attributes->set(ResolveApiActor::ATTRIBUTE, $a);

    return $r;
}

$ctl = new OrderUrgeController();

DB::beginTransaction();
try {
    $now = date('Y-m-d H:i:s');

    $branchId = (int) DB::selectOne('SELECT id FROM branches ORDER BY id LIMIT 1')->id;
    $pilotId  = (int) DB::selectOne('SELECT id FROM pilots ORDER BY id LIMIT 1')->id;

    $mkOrder = function (?int $pilot, string $status = 'processing') use ($branchId, $now): int {
        DB::insert(
            "INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, sender_phone,
                                 status, status_since, created_at, total_delivery_price,
                                 added_by, added_by_role, source, pilot_id)
             VALUES (?,?,?,'محل الاختبار','01000000000',?,?,?,30,'shop1','store','store',?)",
            ['URGE-' . uniqid(), $branchId, $branchId, $status, $now, $now, $pilot]
        );

        return (int) DB::getPdo()->lastInsertId();
    };

    $store = Actor::staff(801, 'shop1', 'store', null, 'محل الاختبار');
    $other = Actor::staff(802, 'shop2', 'store', null, 'محل تاني');

    Event::fake([OrderUrged::class]);

    echo "\n══ 1) الأوردر في المكتب → التنبيه للفرع ══\n";
    $o1 = $mkOrder(null);
    $r1 = json_decode($ctl->urge(req($store), (string) $o1)->getContent(), true);
    ok('الاستعجال عدّى', ! empty($r1['ok']), json_encode($r1, JSON_UNESCAPED_UNICODE));
    ok('الهدف الفرع', ($r1['target'] ?? '') === 'branch', (string) ($r1['target'] ?? ''));
    ok('العدّاد 1', (int) ($r1['times'] ?? 0) === 1, (string) ($r1['times'] ?? 0));

    $row = (array) DB::selectOne('SELECT * FROM order_urges WHERE order_id = ?', [$o1]);
    ok('رقم الصف رجع في الرد', (int) ($r1['urgeId'] ?? 0) === (int) $row['id'],
        ($r1['urgeId'] ?? 'null') . ' مقابل ' . $row['id']);
    ok('الصف اتسجّل بالفرع', (int) $row['branch_id'] === $branchId && $row['pilot_id'] === null,
        json_encode(['b' => $row['branch_id'], 'p' => $row['pilot_id']]));

    /* 🔴 الحدث ShouldDispatchAfterCommit — يعني مابيتبعتش قبل تثبيت
       المعاملة، والاختبار كله جوه معاملة بترجع. فبنختبر **منطق
       التوجيه** على الحدث نفسه: القنوات اللي بيروح عليها والحمولة. */
    $e1 = new OrderUrged($o1, 'X-1', $branchId, null, 'محل', 1, '');
    $ch1 = array_map(fn ($c) => (string) $c, $e1->broadcastOn());
    ok('الأوردر في المكتب → قناة الفرع بس', $ch1 === ['private-branch.' . $branchId], implode(' · ', $ch1));
    ok('اسم الحدث على السلك', $e1->broadcastAs() === 'order.urged', $e1->broadcastAs());
    $e1b = new OrderUrged($o1, 'X-1', $branchId, null, 'محل', 1, '', 777);
    ok('رقم الصف في الحمولة', (int) ($e1b->broadcastWith()['urgeId'] ?? 0) === 777,
        var_export($e1b->broadcastWith()['urgeId'] ?? null, true));

    echo "\n══ 2) الحد الزمني: تاني استعجال فورًا مرفوض ══\n";
    try {
        $ctl->urge(req($store), (string) $o1);
        ok('الاستعجال المتكرر مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('الاستعجال المتكرر مرفوض', str_contains($e->getMessage(), 'استنى'), $e->getMessage());
    }
    ok('مفيش صف تاني اتسجّل',
        (int) DB::selectOne('SELECT COUNT(*) n FROM order_urges WHERE order_id = ?', [$o1])->n === 1);

    echo "\n══ 3) بعد 4 دقايق بيعدّي ══\n";
    // بنرجّع وقت آخر استعجال 5 دقايق ورا — نفس اللي بيحصل مع مرور الوقت
    DB::update('UPDATE order_urges SET urged_at = ? WHERE order_id = ?',
        [gmdate('Y-m-d H:i:s', time() - 300), $o1]);
    $r3 = json_decode($ctl->urge(req($store), (string) $o1)->getContent(), true);
    ok('الاستعجال عدّى بعد المهلة', ! empty($r3['ok']), json_encode($r3, JSON_UNESCAPED_UNICODE));
    ok('العدّاد بقى 2', (int) ($r3['times'] ?? 0) === 2, (string) ($r3['times'] ?? 0));

    echo "\n══ 4) الأوردر متحمّل على طيار → التنبيه للطيار ══\n";
    $o2 = $mkOrder($pilotId, 'delivering');
    $r4 = json_decode($ctl->urge(req($store), (string) $o2)->getContent(), true);
    ok('الهدف الطيار', ($r4['target'] ?? '') === 'pilot', (string) ($r4['target'] ?? ''));
    ok('الرسالة بتقول للطيار', str_contains((string) ($r4['message'] ?? ''), 'للطيار'), (string) ($r4['message'] ?? ''));
    $row2 = (array) DB::selectOne('SELECT * FROM order_urges WHERE order_id = ?', [$o2]);
    ok('الصف اتسجّل بالطيار', (int) $row2['pilot_id'] === $pilotId, var_export($row2['pilot_id'], true));

    // الطيار **والفرع** — الفرع لازم يشوف إن المحل بيستعجل
    $e2 = new OrderUrged($o2, 'X-2', $branchId, $pilotId, 'محل', 1, '');
    $ch2 = array_map(fn ($c) => (string) $c, $e2->broadcastOn());
    ok('متحمّل على طيار → القناتين',
       $ch2 === ['private-branch.' . $branchId, 'private-pilot.' . $pilotId], implode(' · ', $ch2));
    $w = $e2->broadcastWith();
    ok('الحمولة فيها رسالة جاهزة للطيار', str_contains((string) ($w['body'] ?? ''), 'X-2'), (string) ($w['body'] ?? ''));

    echo "\n══ 5) الحمايات ══\n";
    try {
        $ctl->urge(req($other), (string) $o1);
        ok('محل تاني مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('محل تاني مرفوض', str_contains($e->getMessage(), 'مش بتاع محلك'), $e->getMessage());
    }

    $o3 = $mkOrder(null, 'delivered');
    try {
        $ctl->urge(req($store), (string) $o3);
        ok('الأوردر المتسلّم مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('الأوردر المتسلّم مرفوض', str_contains($e->getMessage(), 'مش جاري'), $e->getMessage());
    }

    try {
        $ctl->urge(req($store), '99999999');
        ok('أوردر مش موجود مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('أوردر مش موجود مرفوض', str_contains($e->getMessage(), 'غير موجود'), $e->getMessage());
    }

    echo "\n══ 6) الفرع بيشوف استعجالات فرعه بس ══\n";
    $branchActor = Actor::staff(803, 'br', 'branch', $branchId, 'مشرف');
    $list = json_decode($ctl->list(req($branchActor))->getContent(), true);
    $mine = array_filter($list['items'] ?? [], fn ($x) => (int) $x['branchId'] === $branchId);
    ok('القايمة رجعت استعجالات الفرع', count($mine) === count($list['items'] ?? []),
        count($list['items'] ?? []) . ' بند');
    ok('الاستعجالات اللي عملناها موجودة', count($list['items'] ?? []) >= 3, (string) count($list['items'] ?? []));

    echo "\n══ 7) تعليمها مقروءة ══\n";
    $ids = array_map(fn ($x) => $x['id'], array_slice($list['items'] ?? [], 0, 2));
    $ctl->seen(req($branchActor, ['ids' => $ids]));
    $seen = (int) DB::selectOne(
        'SELECT COUNT(*) n FROM order_urges WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') AND seen_at IS NOT NULL'
    )->n;
    ok('اتعلّموا مقروءين', $seen === count($ids), $seen . ' من ' . count($ids));
    echo "
══ 8) توجيه المسارات — الحارس ضد ابتلاع {id} ══
";
    /* 🔴 اللسعة دي كانت هتعدّي: `orders/{id}` كان مسجّل قبل `orders/urges`،
       فالراوتر بياخد «urges» على إنه رقم أوردر ويوديها لـOrdersController::show.
       الاختبار اللي بينده الكنترولر مباشرةً عمره ما هيمسكها — فبنسأل
       الراوتر نفسه، وده اللي بيخلّي الترتيب محروس مش متروك للعين. */
    $routes = app('router')->getRoutes();
    $resolve = function (string $method, string $uri) use ($routes): string {
        try {
            return (string) $routes->match(Request::create($uri, $method))->getActionName();
        } catch (Throwable $e) {
            return 'مفيش مسار: ' . $e->getMessage();
        }
    };
    foreach ([
        ['GET',  '/api/orders/urges',      'OrderUrgeController@list'],
        ['POST', '/api/orders/urges/seen', 'OrderUrgeController@seen'],
        ['POST', '/api/orders/12/urge',    'OrderUrgeController@urge'],
        ['GET',  '/api/orders/12',         'OrdersController@show'],
    ] as [$m, $u, $want]) {
        $got = $resolve($m, $u);
        ok("{$m} {$u} → {$want}", str_contains($got, $want), $got);
    }
} catch (Throwable $e) {
    $fail++;
    echo "\n💥 " . get_class($e) . ': ' . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "URGE: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
