<?php

declare(strict_types=1);

/**
 * فحص إشعارات ستارة الهاتف (Web Push) لتطبيق العملاء — الطبقة السيرفرية كلها.
 *
 * ليه الفحص ده: الميزة بتتنده من 22 موضع بثّ جوه معاملات، وبتكتب في
 * جدولين جداد، وبتتكلم مع المتصفح بحمولة **نصها جزء من العقد** مع
 * public/app-sw.js. أي انحراف صامت (مفتاح ناقص، ترتيب مختلف، url غلط،
 * إشعار مكرر) مش هيبان غير على موبايل عميل. الفحص بيثبّت:
 *   • الكلاسات **بتتحمّل فعلًا** (مش `php -l` بس — درس من تريتة من غير
 *     import عدّت من `php -l` وكسرت الإنتاج): CustomerAppController و
 *     SendCustomerPush و BroadcastsOrders و WebPushFactory.
 *   • `latestState()` بتطلّع نفس مفاتيح `notifications()` بنفس القواعد،
 *     و`payload()` مطابقة للعقد حرف بحرف (ترتيب المفاتيح والـurl والـtag).
 *   • المسارات التلاتة متسجّلة على الدوال الصح، والكنترولر بيعمل upsert
 *     ويرفض المدخلات الغلط ويمسح عند الإلغاء.
 *   • المهمة: بتبعت «وصل الفرع» لحظة الإنشاء، وبعدها مرة واحدة لكل حالة (اختيار بالأولوية)
 *     (التكرار بيتمنع من القاعدة)، وبتتعامل مع 410 (مسح) و500 (fail_count)،
 *     وبتسكت تمامًا لو VAPID فاضي أو الأوردر مش لعميل تطبيق.
 *   • جولة تشفير **حقيقية** بالمكتبة (VAPID JWT + ECDH + AES-GCM على bcmath)
 *     بعميل HTTP وهمي — بتثبت إن السيرفر ده قادر يبعت فعلًا.
 *
 * ⚠️ **صفر أثر في القاعدة.** كل الكتابة جوه `DB::transaction` بترجع دايمًا —
 * في المسار الطبيعي، وفي أي استثناء، وفي أي خروج مفاجئ. والعميل الوهمي
 * بيتشال من الحاوية في الـfinally.
 *
 * ملحوظة ويندوز/XAMPP: جولة التشفير الحقيقية محتاجة OpenSSL يلاقي ملف
 * إعداده عشان يولّد مفتاح EC مؤقت — لو `openssl_pkey_new` فشل الجولة
 * بتتخطى بتحذير واضح (مش فشل). شغّل بـ:
 *   OPENSSL_CONF=C:\xampp\apache\conf\openssl.cnf php tests/customer_push.php
 *
 * التشغيل: php tests/customer_push.php
 */

use App\Http\Controllers\Api\CustomerAppController;
use App\Http\Controllers\Concerns\BroadcastsOrders;
use App\Jobs\SendCustomerPush;
use App\Services\Push\WebPushFactory;
use App\Support\ApiResponse;
use App\Support\WireTime;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$pass = 0;
$fail = 0;

/** عرض مختصر — var_export بيفرد المصفوفات على أسطر وبيغرّق المخرج */
$show = static fn ($v): string => is_array($v)
    ? json_encode($v, ApiResponse::JSON_FLAGS)
    : var_export($v, true);

/** @param mixed $expected */
$check = function (string $label, $expected, $got) use (&$pass, &$fail, $show): void {
    $ok = ($got === $expected);
    printf("%s  %-46s  توقّع=%s  طلع=%s\n", $ok ? '  ok' : 'FAIL', $label,
        $show($expected), $show($got));
    $ok ? $pass++ : $fail++;
};

/* أي استثناء المهمة بتمسكه وتبعته لـreport() كان هيروح اللوج بصمت —
   هنا بنطبعه في المخرج عشان الفشل يبقى مرئي مع سببه. */
$handler = $app->make(ExceptionHandler::class);
if (method_exists($handler, 'reportable')) {
    $handler->reportable(static function (Throwable $e): void {
        echo '  ⚠ report(): ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
    });
}

echo "── تحميل الكلاسات فعليًا (مش php -l) ──\n";

foreach ([CustomerAppController::class, SendCustomerPush::class, WebPushFactory::class, WebPush::class] as $cls) {
    $check('class_exists ' . basename(str_replace('\\', '/', $cls)), true, class_exists($cls));
}
$check('trait_exists BroadcastsOrders', true, trait_exists(BroadcastsOrders::class));
$check('الكنترولر بيستخدم BroadcastsOrders', true,
    in_array(BroadcastsOrders::class, class_uses(CustomerAppController::class), true));
foreach (['pushKey', 'pushSubscribe', 'pushUnsubscribe'] as $m) {
    $check("method {$m}", true, method_exists(CustomerAppController::class, $m));
}

// المهمة بتتبني — ده هو الاختبار الحقيقي لتعارض الـtrait على $afterCommit
$probe = new SendCustomerPush(1);
$check('afterCommit = true من الـconstructor', true, $probe->afterCommit === true);
$check('tries = 1', 1, $probe->tries);

echo "\n── المسارات التلاتة → الدوال الصح ──\n";

$routeAction = static function (string $method, string $uri): ?string {
    try {
        return Route::getRoutes()->match(Request::create($uri, $method))->getActionName();
    } catch (Throwable) {
        return null;
    }
};
$check('GET  customer/push/key',         CustomerAppController::class . '@pushKey',         $routeAction('GET',  '/api/customer/push/key'));
$check('POST customer/push/subscribe',   CustomerAppController::class . '@pushSubscribe',   $routeAction('POST', '/api/customer/push/subscribe'));
$check('POST customer/push/unsubscribe', CustomerAppController::class . '@pushUnsubscribe', $routeAction('POST', '/api/customer/push/unsubscribe'));

echo "\n── الإعداد ──\n";

$check('VAPID متظبّط محليًا (.env)', true, WebPushFactory::configured());
$check('subject الافتراضي', 'mailto:info@aldahshan.cloud', config('dahshan.push.subject'));

echo "\n── latestState / payload — نقي من غير قاعدة ──\n";

$base = [
    'id' => 77, 'order_num' => 'CAI-260822-007', 'status' => 'pending_pickup', 'pilot_id' => null,
    'status_since' => null, 'current_pilot_since' => null, 'received_at' => null, 'trip_started_at' => null,
    'delivered_at' => null, 'undelivered_at' => null, 'undelivered_reason' => null, 'cancelled_at' => null,
    'customer_id' => 5,
];
$with = static fn (array $over): array => array_merge($base, $over);
$keyOf = static fn (array $o): ?string => SendCustomerPush::latestState($o)['key'] ?? null;

$check('أوردر جديد → مفيش حالة', null, SendCustomerPush::latestState($base));
$check('طيار متعيّن → assigned', 'assigned', $keyOf($with(['pilot_id' => 3, 'current_pilot_since' => '2026-08-22 10:00:00'])));
$check('delivering بلا pilot_id → assigned من status_since', 'assigned',
    $keyOf($with(['status' => 'delivering', 'status_since' => '2026-08-22 10:00:00'])));
$check('استلم → received', 'received',
    $keyOf($with(['pilot_id' => 3, 'current_pilot_since' => '2026-08-22 10:00:00', 'received_at' => '2026-08-22 10:05:00'])));
$check('في الطريق → out', 'out',
    $keyOf($with(['pilot_id' => 3, 'current_pilot_since' => '2026-08-22 10:00:00', 'received_at' => '2026-08-22 10:05:00', 'trip_started_at' => '2026-08-22 10:10:00'])));
$check('اتسلّم → delivered', 'delivered',
    $keyOf($with(['status' => 'delivered', 'pilot_id' => 3, 'trip_started_at' => '2026-08-22 10:10:00', 'delivered_at' => '2026-08-22 10:30:00'])));
$check('لم يتم التوصيل → failed', 'failed',
    $keyOf($with(['status' => 'undelivered', 'pilot_id' => 3, 'trip_started_at' => '2026-08-22 10:10:00', 'undelivered_at' => '2026-08-22 10:30:00'])));
$check('ملغي بلا cancelled_at → cancel من status_since', 'cancel',
    $keyOf($with(['status' => 'cancelled', 'status_since' => '2026-08-22 09:00:00'])));
// delivered_at أقدم من trip_started_at؟ **الأولوية تكسب الزمن**: delivered حالة نهائية
// أعلى من out حتى لو وقتها المسجّل أقدم (توقيتات مش متسقة مش سبب نبعت «في الطريق» لأوردر اتسلّم)
$check('الأولوية تكسب الزمن: delivered فوق out حتى لو أقدم', 'delivered',
    $keyOf($with(['status' => 'delivered', 'pilot_id' => 3, 'trip_started_at' => '2026-08-22 11:00:00', 'delivered_at' => '2026-08-22 10:30:00'])));
// الحالة اللي كسرت الاختيار الزمني فعلًا: أوردر اتعمل واتسند في نفس الثانية من اللوحة
// (created_at = current_pilot_since) — لازم assigned مش created
$check('created_at مساوي/أحدث من الإسناد → assigned مش created', 'assigned',
    $keyOf($with(['status' => 'delivering', 'pilot_id' => 3, 'created_at' => '2026-08-22 10:00:00', 'current_pilot_since' => '2026-08-22 10:00:00', 'status_since' => '2026-08-22 10:00:00'])));

$outOrder = $with(['pilot_id' => 3, 'current_pilot_since' => '2026-08-22 10:00:00', 'trip_started_at' => '2026-08-22 10:10:00']);
$outJson  = json_encode(SendCustomerPush::payload($outOrder, SendCustomerPush::latestState($outOrder)), ApiResponse::JSON_FLAGS);
$check('حمولة out بالعقد حرفيًا',
    '{"title":"الطيار في الطريق إليك","body":"طلب رقم CAI-260822-007","tag":"order-77","orderId":77,"orderNum":"CAI-260822-007","key":"out","url":"/customer.html#track=77"}',
    $outJson);

$doneOrder = $with(['status' => 'delivered', 'pilot_id' => 3, 'delivered_at' => '2026-08-22 10:30:00']);
$check('delivered → url #order=', '/customer.html#order=77',
    SendCustomerPush::payload($doneOrder, SendCustomerPush::latestState($doneOrder))['url']);

$failedOrder = $with(['status' => 'undelivered', 'pilot_id' => 3, 'undelivered_at' => '2026-08-22 10:30:00', 'undelivered_reason' => 'العميل مش بيرد']);
$failedPayload = SendCustomerPush::payload($failedOrder, SendCustomerPush::latestState($failedOrder));
$check('failed: العنوان قصير والسبب في الجسم', ['لم يتم التوصيل', 'طلب رقم CAI-260822-007 — العميل مش بيرد', '/customer.html#order=77'],
    [$failedPayload['title'], $failedPayload['body'], $failedPayload['url']]);

/* ═══════════════════════════════════════════════════════════════
   تشغيل فعلي على القاعدة — جوه معاملة بترجع دايمًا
═══════════════════════════════════════════════════════════════ */

echo "\n── تشغيل فعلي (معاملة بترجع) ──\n";

/**
 * عميل Web Push وهمي: بيسجّل اللي اتبعت وبيرجّع النتيجة اللي الفحص محدّدها.
 * بيرث WebPush عشان يعدّي type hint الـfactory، ومش بينده constructor الأب
 * عن قصد (مش محتاجين عميل HTTP ولا مفاتيح).
 */
$fake = new class extends WebPush {
    /** @var array<int, array{endpoint: string, payload: ?string}> */
    public array $sent = [];
    /** @var int[] أكواد الرد للإرسالات الجاية بالترتيب — الافتراضي 201 */
    public array $outcomes = [];

    public function __construct()
    {
    }

    public function sendOneNotification(SubscriptionInterface $subscription, ?string $payload = null, array $options = [], array $auth = []): MessageSentReport
    {
        $this->sent[] = ['endpoint' => $subscription->getEndpoint(), 'payload' => $payload];
        $code = array_shift($this->outcomes) ?? 201;
        $req  = new PsrRequest('POST', $subscription->getEndpoint());

        return new MessageSentReport($req, new Response($code), $code < 300, $code < 300 ? 'OK' : 'HTTP ' . $code);
    }
};

$branchId = DB::table('branches')->min('id');

if ($branchId === null) {
    echo "  (مفيش فروع في القاعدة — قسم التشغيل الفعلي اتخطى)\n";
} else {
    // مفاتيح مشترك صالحة (متجهات الاختبار في RFC 8291 §5) — لازم تكون سليمة لجولة التشفير الحقيقية تحت
    $P256DH = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    $AUTH   = 'BTBZMqHH6r4Tts7J_aSIgg';
    $tagEmail = '__push_test__' . bin2hex(random_bytes(4));

    // حماية من الخروج المفاجئ — أي حاجة اتكتبت بترجع
    register_shutdown_function(static function (): void {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    });

    DB::beginTransaction();
    try {
        $app->instance(WebPush::class, $fake);

        $now = WireTime::nowDb();

        // عميلين وهميين
        DB::insert('INSERT INTO customers (email, display_name, phone1) VALUES (?,?,?)', [$tagEmail . 'a@example.invalid', 'فحص الإشعارات أ', '01000000001']);
        $cidA = (int) DB::getPdo()->lastInsertId();
        DB::insert('INSERT INTO customers (email, display_name, phone1) VALUES (?,?,?)', [$tagEmail . 'b@example.invalid', 'فحص الإشعارات ب', '01000000002']);
        $cidB = (int) DB::getPdo()->lastInsertId();

        $newOrder = static function (?int $cid, array $cols = []) use ($branchId, $now): int {
            $cols = array_merge(['order_num' => '__PUSHTEST-' . bin2hex(random_bytes(4)), 'branch_id' => $branchId,
                'customer_id' => $cid, 'status' => 'pending_pickup', 'status_since' => $now, 'source' => 'customer'], $cols);
            DB::table('orders')->insert($cols);

            return (int) DB::getPdo()->lastInsertId();
        };

        $subsOf  = static fn (int $cid): array => array_map(static fn ($r) => (array) $r,
            DB::select('SELECT id, customer_id, endpoint, p256dh, auth, ua, last_ok_at, fail_count FROM customer_push_subscriptions WHERE customer_id = ? ORDER BY id', [$cid]));
        $events  = static fn (int $oid): array => array_map(static fn ($r) => (string) $r->state_key,
            DB::select('SELECT state_key FROM customer_push_events WHERE order_id = ? ORDER BY id', [$oid]));

        /* ── الكنترولر بجلسة عميل حقيقية ── */
        $ctl = $app->make(CustomerAppController::class);
        $mkReq = static function (string $method, string $uri, array $json, int $cid) use ($app): Request {
            $session = $app->make('session.store');
            $session->put('role', 'customer');
            $session->put('customer_id', $cid);
            $r = Request::create($uri, $method, [], [], [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
                json_encode($json, ApiResponse::JSON_FLAGS));
            $r->setLaravelSession($session);

            return $r;
        };
        /** بيرجّع [status, body] — ApiException بتترجم لنفس شكل الرد اللي الواجهة بتشوفه */
        $call = static function (callable $fn) {
            try {
                $res = $fn();

                return [$res->getStatusCode(), $res->getData(true)];
            } catch (\App\Exceptions\ApiException $e) {
                return [$e->status(), $e->render()->getData(true)];
            }
        };

        [$st, $body] = $call(static fn () => $ctl->pushKey($mkReq('GET', '/api/customer/push/key', [], $cidA)));
        $check('push/key → {ok, publicKey}', [200, true, config('dahshan.push.public_key')], [$st, $body['ok'], $body['publicKey'] ?? null]);

        $endpointA = 'https://push.example.invalid/send/' . bin2hex(random_bytes(24));
        $endpointB = 'https://push.example.invalid/send/' . bin2hex(random_bytes(24));

        [$st, $body] = $call(static fn () => $ctl->pushSubscribe($mkReq('POST', '/api/customer/push/subscribe',
            ['endpoint' => $endpointA, 'keys' => ['p256dh' => $P256DH, 'auth' => $AUTH], 'ua' => 'فحص/1.0'], $cidA)));
        $check('subscribe → {ok:true}', [200, ['ok' => true]], [$st, $body]);
        $check('صف اشتراك واحد للعميل أ', 1, count($subsOf($cidA)));
        $check('الـendpoint والـua اتخزّنوا زي ما هم', [$endpointA, 'فحص/1.0'], [$subsOf($cidA)[0]['endpoint'], $subsOf($cidA)[0]['ua']]);

        // upsert: نفس الـendpoint تاني بمفاتيح جديدة = نفس الصف اتحدّث، مش صف تاني
        $call(static fn () => $ctl->pushSubscribe($mkReq('POST', '/api/customer/push/subscribe',
            ['endpoint' => $endpointA, 'keys' => ['p256dh' => $P256DH, 'auth' => 'AAAAAAAAAAAAAAAAAAAAAA']], $cidA)));
        $check('إعادة الاشتراك = upsert (صف واحد)', 1, count($subsOf($cidA)));
        $check('المفاتيح اتحدّثت والـua اتحافظ عليه', ['AAAAAAAAAAAAAAAAAAAAAA', 'فحص/1.0'], [$subsOf($cidA)[0]['auth'], $subsOf($cidA)[0]['ua']]);
        // رجّع المفتاح الصح لجولة التشفير
        $call(static fn () => $ctl->pushSubscribe($mkReq('POST', '/api/customer/push/subscribe',
            ['endpoint' => $endpointA, 'keys' => ['p256dh' => $P256DH, 'auth' => $AUTH]], $cidA)));

        // نفس الجهاز سجّل دخول بعميل تاني واشترك → الصف بيتنقل له
        $call(static fn () => $ctl->pushSubscribe($mkReq('POST', '/api/customer/push/subscribe',
            ['endpoint' => $endpointA, 'keys' => ['p256dh' => $P256DH, 'auth' => $AUTH]], $cidB)));
        $check('الاشتراك اتنقل للعميل ب', [0, 1], [count($subsOf($cidA)), count($subsOf($cidB))]);
        // ورجّعه للعميل أ
        $call(static fn () => $ctl->pushSubscribe($mkReq('POST', '/api/customer/push/subscribe',
            ['endpoint' => $endpointA, 'keys' => ['p256dh' => $P256DH, 'auth' => $AUTH]], $cidA)));
        $check('ورجع للعميل أ', [1, 0], [count($subsOf($cidA)), count($subsOf($cidB))]);

        // مدخلات غلط → 400 ومفيش صف
        [$st] = $call(static fn () => $ctl->pushSubscribe($mkReq('POST', '/api/customer/push/subscribe',
            ['endpoint' => 'http://insecure.example/x', 'keys' => ['p256dh' => $P256DH, 'auth' => $AUTH]], $cidA)));
        $check('endpoint مش https → 400', 400, $st);
        [$st] = $call(static fn () => $ctl->pushSubscribe($mkReq('POST', '/api/customer/push/subscribe',
            ['endpoint' => $endpointB, 'keys' => ['p256dh' => $P256DH]], $cidA)));
        $check('auth ناقص → 400', 400, $st);
        [$st] = $call(static fn () => $ctl->pushSubscribe($mkReq('POST', '/api/customer/push/subscribe',
            ['endpoint' => $endpointB, 'keys' => ['p256dh' => 'not base64url!!', 'auth' => $AUTH]], $cidA)));
        $check('p256dh مش base64url → 400', 400, $st);
        [$st] = $call(static fn () => $ctl->pushSubscribe($mkReq('POST', '/api/customer/push/subscribe',
            ['endpoint' => ['x'], 'keys' => 'nope'], $cidA)));
        $check('أنواع غلط (مصفوفة/نص) → 400 مش 500', 400, $st);
        $check('المرفوضين ماكتبوش صفوف', 1, count($subsOf($cidA)));

        // بلا جلسة → 401. (`session.store` singleton في الحاوية — لازم نفضّيه
        // صراحةً، وإلا الطلب «المجهول» بيورث جلسة العميل من الطلبات اللي فاتت)
        $anonSession = $app->make('session.store');
        $anonSession->flush();
        $anon = Request::create('/api/customer/push/key', 'GET');
        $anon->setLaravelSession($anonSession);
        [$st] = $call(static fn () => $ctl->pushKey($anon));
        $check('بلا جلسة عميل → 401', 401, $st);

        /* ── المهمة ── */
        $oid = $newOrder($cidA);

        (new SendCustomerPush($oid))->handle();
        $check('أوردر جديد → «وصل الفرع» إرسال واحد + صف created', [1, ['created']], [count($fake->sent), $events($oid)]);

        // اتسند لطيار
        DB::update('UPDATE orders SET status = ?, status_since = ?, current_pilot_since = ? WHERE id = ?',
            ['delivering', '2026-08-22 10:00:00', '2026-08-22 10:00:00', $oid]);
        (new SendCustomerPush($oid))->handle();
        $check('assigned → إرسال تاني + صف events', [2, ['created', 'assigned']], [count($fake->sent), $events($oid)]);
        $check('اتبعت للـendpoint الصح', $endpointA, $fake->sent[0]['endpoint'] ?? null);
        $check('النجاح حدّث last_ok_at وصفّر fail_count', [true, 0],
            [$subsOf($cidA)[0]['last_ok_at'] !== null, (int) $subsOf($cidA)[0]['fail_count']]);

        // نفس الحالة تاني (تعديل ملاحظة مثلًا → broadcastOrder تاني) → مفيش إرسال تاني
        (new SendCustomerPush($oid))->handle();
        $check('نفس الحالة تاني → مفيش تكرار', [2, ['created', 'assigned']], [count($fake->sent), $events($oid)]);

        // إعادة إسناد لطيار تاني (طابع current_pilot_since أحدث) → assigned بيتبعت **تاني**
        // رغم إن المفتاح محجوز — ده إصلاح «المحاولة التانية صامتة» بعد «لم يتم التوصيل»
        DB::update('UPDATE orders SET current_pilot_since = ? WHERE id = ?', ['2026-08-22 10:05:00', $oid]);
        (new SendCustomerPush($oid))->handle();
        $check('إسناد بطابع أحدث → إرسال جديد بنفس المفتاح', [3, ['created', 'assigned']], [count($fake->sent), $events($oid)]);
        (new SendCustomerPush($oid))->handle();
        $check('ونفس الطابع تاني → سكوت', 3, count($fake->sent));

        // في الطريق
        DB::update('UPDATE orders SET trip_started_at = ? WHERE id = ?', ['2026-08-22 10:10:00', $oid]);
        (new SendCustomerPush($oid))->handle();
        $check('out → إرسال رابع (بعد الإسناد المعاد)', [4, ['created', 'assigned', 'out']], [count($fake->sent), $events($oid)]);

        $orderNum = (string) DB::table('orders')->where('id', $oid)->value('order_num');
        $check('حمولة out من القاعدة بالعقد حرفيًا',
            '{"title":"الطيار في الطريق إليك","body":"طلب رقم ' . $orderNum . '","tag":"order-' . $oid . '","orderId":' . $oid . ',"orderNum":"' . $orderNum . '","key":"out","url":"/customer.html#track=' . $oid . '"}',
            $fake->sent[3]['payload'] ?? null);
        $check('الحمولة JSON صالح والـSW هيقدر يفكّه', true,
            is_array(json_decode((string) ($fake->sent[3]['payload'] ?? ''), true)));

        // اتسلّم
        DB::update('UPDATE orders SET status = ?, delivered_at = ? WHERE id = ?', ['delivered', '2026-08-22 10:30:00', $oid]);
        (new SendCustomerPush($oid))->handle();
        $d = json_decode((string) ($fake->sent[4]['payload'] ?? '{}'), true);
        $check('delivered → key/url/tag', ['delivered', '/customer.html#order=' . $oid, 'order-' . $oid],
            [$d['key'] ?? null, $d['url'] ?? null, $d['tag'] ?? null]);
        $check('4 حالات = 4 صفوف', ['created', 'assigned', 'out', 'delivered'], $events($oid));

        /* ── 410 يمسح الاشتراك، 500 يزوّد fail_count، وباقي الأجهزة مابتتأثرش ── */
        DB::insert('INSERT INTO customer_push_subscriptions (customer_id, endpoint_hash, endpoint, p256dh, auth, created_at) VALUES (?,?,?,?,?,?)',
            [$cidA, hash('sha256', $endpointB), $endpointB, $P256DH, $AUTH, $now]);
        $check('جهازين للعميل أ', 2, count($subsOf($cidA)));

        $oid2 = $newOrder($cidA, ['status' => 'undelivered', 'undelivered_at' => '2026-08-22 12:00:00', 'undelivered_reason' => 'العميل مش بيرد', 'pilot_id' => null, 'current_pilot_since' => '2026-08-22 11:00:00']);
        $fake->outcomes = [410, 500];   // الأول (endpointA) منتهي، التاني (endpointB) فشل مؤقت
        (new SendCustomerPush($oid2))->handle();
        $left = $subsOf($cidA);
        $check('failed → اتبعت للجهازين', 7, count($fake->sent));
        $check('410 → الاشتراك اتمسح، 500 → fail_count=1', [1, $endpointB, 1],
            [count($left), $left[0]['endpoint'] ?? null, (int) ($left[0]['fail_count'] ?? -1)]);
        $f = json_decode((string) ($fake->sent[6]['payload'] ?? '{}'), true);
        $check('failed: السبب في الجسم', ['failed', 'لم يتم التوصيل', true],
            [$f['key'] ?? null, $f['title'] ?? null, str_contains((string) ($f['body'] ?? ''), 'العميل مش بيرد')]);

        /* ── VAPID فاضي = مفيش حاجة خالص (ولا صف) ── */
        $savedKey = config('dahshan.push.public_key');
        config(['dahshan.push.public_key' => '']);
        $oid3 = $newOrder($cidA, ['status' => 'cancelled', 'cancelled_at' => '2026-08-22 12:30:00']);
        (new SendCustomerPush($oid3))->handle();
        $check('VAPID فاضي → سكوت تام', [7, []], [count($fake->sent), $events($oid3)]);
        [$st] = $call(static fn () => $ctl->pushKey($mkReq('GET', '/api/customer/push/key', [], $cidA)));
        $check('VAPID فاضي → push/key بيرد 404 (مش 5xx)', 404, $st);
        config(['dahshan.push.public_key' => $savedKey]);
        (new SendCustomerPush($oid3))->handle();
        $check('ورجع يبعت بعد الإعداد (cancel)', [8, ['cancel']], [count($fake->sent), $events($oid3)]);
        $c3 = json_decode((string) ($fake->sent[7]['payload'] ?? '{}'), true);
        $check('cancel → #order=', '/customer.html#order=' . $oid3, $c3['url'] ?? null);

        /* ── أوردر مش لعميل تطبيق (فرع/محل) = مفيش حد يتبعتله ── */
        $oid4 = $newOrder(null, ['status' => 'delivered', 'delivered_at' => '2026-08-22 13:00:00', 'source' => 'branch']);
        (new SendCustomerPush($oid4))->handle();
        $check('بلا customer_id ولا مستلم مطابق → سكوت ولا صف', [8, []], [count($fake->sent), $events($oid4)]);

        // id مش موجود / صفر — مايرميش
        (new SendCustomerPush(0))->handle();
        (new SendCustomerPush(PHP_INT_MAX))->handle();
        $check('id غلط → سكوت', 8, count($fake->sent));

        /* ── unsubscribe ── */
        [$st, $body] = $call(static fn () => $ctl->pushUnsubscribe($mkReq('POST', '/api/customer/push/unsubscribe', ['endpoint' => $endpointB], $cidA)));
        $check('unsubscribe → {ok:true} والصف اتمسح', [200, ['ok' => true], 0], [$st, $body, count($subsOf($cidA))]);
        [$st, $body] = $call(static fn () => $ctl->pushUnsubscribe($mkReq('POST', '/api/customer/push/unsubscribe', ['endpoint' => $endpointB], $cidA)));
        $check('unsubscribe تاني → ok برضه (idempotent)', [200, true], [$st, $body['ok'] ?? null]);
        [$st] = $call(static fn () => $ctl->pushUnsubscribe($mkReq('POST', '/api/customer/push/unsubscribe', [], $cidA)));
        $check('unsubscribe بلا endpoint → 400', 400, $st);

        /* ── جولة تشفير حقيقية: المكتبة كاملة (VAPID JWT + ECDH + AES-GCM) بعميل HTTP وهمي ── */
        echo "\n── جولة تشفير حقيقية بالمكتبة ──\n";

        $ecOk = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]) !== false;
        if (! $ecOk) {
            echo "  ⚠ اتخطت: OpenSSL مش لاقي ملف إعداده فمش قادر يولّد مفتاح EC مؤقت.\n";
            echo "    شغّل: OPENSSL_CONF=C:\\xampp\\apache\\conf\\openssl.cnf php tests/customer_push.php\n";
        } else {
            $history = [];
            $stack   = HandlerStack::create(new MockHandler([new Response(201)]));
            $stack->push(Middleware::history($history));

            $cfg  = (array) config('dahshan.push');
            $real = new WebPush(
                ['VAPID' => ['subject' => $cfg['subject'], 'publicKey' => $cfg['public_key'], 'privateKey' => $cfg['private_key']]],
                ['TTL' => (int) $cfg['ttl'], 'urgency' => (string) $cfg['urgency']],
                new Client(['handler' => $stack])
            );
            $app->instance(WebPush::class, $real);

            DB::insert('INSERT INTO customer_push_subscriptions (customer_id, endpoint_hash, endpoint, p256dh, auth, created_at) VALUES (?,?,?,?,?,?)',
                [$cidA, hash('sha256', $endpointA), $endpointA, $P256DH, $AUTH, $now]);

            $oid5 = $newOrder($cidA, ['status' => 'delivered', 'delivered_at' => '2026-08-22 14:00:00', 'pilot_id' => null, 'current_pilot_since' => '2026-08-22 13:30:00']);
            (new SendCustomerPush($oid5))->handle();

            $check('طلب HTTP واحد خرج', 1, count($history));
            $req = $history[0]['request'] ?? null;
            if ($req !== null) {
                $check('POST على الـendpoint', ['POST', $endpointA], [$req->getMethod(), (string) $req->getUri()]);
                $check('Content-Encoding: aes128gcm', 'aes128gcm', $req->getHeaderLine('Content-Encoding'));
                $check('TTL من الإعداد', (string) $cfg['ttl'], $req->getHeaderLine('TTL'));
                $check('Urgency من الإعداد', (string) $cfg['urgency'], $req->getHeaderLine('Urgency'));
                $check('Authorization: vapid t=…, k=…', true,
                    str_starts_with($req->getHeaderLine('Authorization'), 'vapid t=') && str_contains($req->getHeaderLine('Authorization'), ', k='));
                // الحمولة متشفّرة: مش JSON خام، وأكبر من النص (حشو المكتبة الافتراضي)
                $bodyBin = (string) $req->getBody();
                $check('الجسم متشفّر (مش JSON نص)', [true, false], [strlen($bodyBin) > 100, str_contains($bodyBin, '"title"')]);
            }
            $sub5 = $subsOf($cidA);
            $check('201 → last_ok_at اتحدّث', true, ($sub5[0]['last_ok_at'] ?? null) !== null && (int) $sub5[0]['fail_count'] === 0);
            $check('صف events اتكتب', ['delivered'], $events($oid5));
        }
    } finally {
        DB::rollBack();
        $app->forgetInstance(WebPush::class);
        $app->offsetUnset(WebPush::class);
    }

    echo "\n── صفر أثر في القاعدة ──\n";
    $check('مفيش عملاء فحص', 0, (int) DB::table('customers')->where('email', 'like', $tagEmail . '%')->count());
    $check('مفيش اشتراكات على endpoint الفحص', 0, (int) DB::table('customer_push_subscriptions')->where('endpoint', 'like', 'https://push.example.invalid/%')->count());
    $check('مفيش أوردرات فحص', 0, (int) DB::table('orders')->where('order_num', 'like', '\_\_PUSHTEST-%')->count());
    $check('العميل الوهمي اتشال من الحاوية', false, $app->bound(WebPush::class));
}

echo "\n════════════════════════════════════════════\n";
printf("PUSH: %d مطابق / %d مختلف   (إجمالي %d)\n", $pass, $fail, $pass + $fail);
echo "════════════════════════════════════════════\n";

exit($fail === 0 ? 0 : 1);
