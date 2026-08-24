<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Push\WebPushFactory;
use App\Support\ApiResponse;
use App\Support\WireTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;
use Throwable;

/**
 * «ابعت للعميل إشعار ستارة عن آخر حالة لأوردره» — بتتحط في الطابور من
 * `BroadcastsOrders::broadcastOrder()` مع أي تغيير على أي أوردر (22 موضع).
 *
 * ═══ العقد مع الواجهة (public/app-sw.js) — حمولة JSON نصية ═══
 *   {title, body, tag:"order-<id>", orderId, orderNum, key, url}
 *   • key  واحد من: assigned · received · out · delivered · failed · cancel
 *     — **نفس مفاتيح** `CustomerAppController::notifications()` بالحرف.
 *   • url  = "/customer.html#track=<id>" للحالات الجارية (assigned/received/out)
 *          و "/customer.html#order=<id>" للنهائية (delivered/failed/cancel).
 *   • tag  ثابت لكل أوردر عشان إشعارات نفس الأوردر تستبدل بعض في الستارة
 *     (إشعار واحد لكل أوردر بيتغيّر محتواه) — الـSW بيستخدمه مع renotify.
 *   ترتيب المفاتيح جزء من العقد (الفحص بيقارن النص حرفيًا).
 *
 * ═══ «آخر حالة» بتتحسب إزاي ═══
 * مفيش جدول إشعارات — زي `notifications()` بالظبط الحالات **مشتقّة** من
 * طوابع الأوردر نفسه، وبنبعت الأحدث زمنيًا منها. المنطق منسوخ حرفيًا من
 * هناك (شوف `latestState`) بدل ما يتشارك في helper: المهمة ماتعتمدش على
 * كنترولر، والكنترولر مايتغيّرش عشان مهمة. 🔴 **أي تعديل في قواعد
 * الاشتقاق هناك لازم يتعمل هنا — والعكس.**
 *
 * ═══ منع التكرار من القاعدة (نفس order_notifications) ═══
 * `INSERT IGNORE` في `customer_push_events` على المفتاح الفريد
 * (order_id, state_key). صفر صفوف = الحالة دي اتبعتت قبل كده = خروج. كده
 * 22 موضع بثّ + تعديلات مالهاش علاقة بالحالة (ملاحظة، صورة، سعر) مابتبعتش
 * نفس الإشعار تاني، ومفيش سباق بين عاملين. **الكتابة قبل الإرسال** عن قصد —
 * أسوأ حالة صف محجوز لإشعار ماوصلش، وده أهون من إشعار مكرر.
 * نتيجة مقصودة: نقل الأوردر لطيار تاني مابيطلعش `assigned` تاني (نفس
 * المفتاح) — إشعار واحد لكل حالة، مش لكل تغيير جواها.
 *
 * ═══ $afterCommit / tries=1 / العزل ═══
 * نفس أسباب `SendOrderWhatsapp` بالحرف (اقرا رأس الملف هناك): الدفع بيتأجّل
 * لبعد الـcommit عشان العامل مايقراش أوردر لسه مش موجود أو معاملته اتلغت،
 * ومفيش إعادة محاولة تلقائية (الفشل بيتسجّل في `fail_count` على الاشتراك)،
 * وكل حاجة ملفوفة في try/catch — المهمة مابتكسرش طلب أبدًا.
 *
 * ═══ VAPID فاضي = مفيش حاجة خالص ═══
 * الفحص أول سطر وقبل أي قراءة من القاعدة: الإنتاج هيشتغل فترة من غير مفاتيح،
 * ومانعملش صفوف في customer_push_events عن إشعارات ماتبعتتش أصلًا.
 */
final class SendCustomerPush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** الحالات الجارية → شاشة التتبع (#track)؛ الباقي نهائي → تفاصيل الأوردر (#order) */
    private const TRACKING_KEYS = ['assigned', 'received', 'out'];

    /** مفيش إعادة محاولة تلقائية — الفشل بيتسجّل في fail_count (شوف رأس الملف) */
    public int $tries = 1;

    public function __construct(
        public readonly int $orderId,
    ) {
        /* 🔴 تأجيل الدفع للـcommit. بيتضبط هنا مش كخاصية `public $afterCommit = true;`
           لنفس السبب المكتوب في `SendOrderWhatsapp`: الـtrait `Queueable` معرّفة
           الخاصية بقيمة ابتدائية null، وإعادة التعريف بقيمة مختلفة تعارض قاتل
           في PHP 8.2 — الكلاس مابيتحمّلش أصلًا. */
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        try {
            $this->run();
        } catch (Throwable $e) {
            // آخر شبكة أمان — الإشعارات مابتكسرش حاجة، بتتسجّل في اللوج وبس
            report($e);
        }
    }

    private function run(): void
    {
        if ($this->orderId <= 0 || ! WebPushFactory::configured()) {
            return;
        }

        /* الأعمدة دي بالظبط: اللي `latestState` بتقراها + customer_id للاشتراكات
           + order_num للحمولة. `SELECT *` كان هيجيب 58 عمود من غير داعي
           (نفس سبب الاختيار الصريح في `BroadcastsOrders::broadcastOrder`). */
        $row = DB::selectOne(
            'SELECT id, order_num, status, pilot_id, status_since, current_pilot_since,
                    received_at, trip_started_at, delivered_at,
                    undelivered_at, undelivered_reason, cancelled_at, customer_id,
                    created_at, source, added_by
               FROM orders WHERE id = ?',
            [$this->orderId]
        );

        // الأوردر مش موجود (اتمسح أو المعاملة اتلغت) = سكوت
        if ($row === null) {
            return;
        }
        $order = (array) $row;

        /* مش أوردر عميل تطبيق (جاي من فرع/محل/كول سنتر) = مفيش حد يتبعتله.
           ⚠️ النطاق مقصود: `customer_id` بس — مش باقي مصادر الملكية اللي
           `ordersWhere()` بتعتبرها (أوردرات فايربيز القديمة بـadded_by، والطرود
           الجاية على رقم تليفون العميل). إشعار مستلم الطرد قرار منفصل. */
        // كل العملاء اللي الأوردر بيظهر لهم (صاحب الأوردر + المستلمين بالتليفون + فايربيز)
        $recipients = self::recipientCustomerIds($order);
        if ($recipients === []) {
            return;   // مفيش عميل تطبيق يتبعله الأوردر ده
        }

        $state = self::latestState($order);
        if ($state === null) {
            return;   // أوردر لسه في أوله — مفيش حالة تستاهل إشعار
        }

        /* 🔴 منع التكرار من القاعدة مش من الكود (شوف رأس الملف). `sent_at`
           هنا = وقت الحجز — الإرسال الفعلي بعده بثواني.

           `state_at` = طابع الحالة نفسها وقت الإرسال. لو نفس المفتاح رجع بطابع
           **أحدث** (إعادة توصيل بعد «لم يتم التوصيل»: إسناد جديد بـcurrent_pilot_since
           جديد، أو «بدء رحلة» تانية بـtrip_started_at جديد) بنبعت تاني — من غير
           الشرط ده المحاولة التانية كلها كانت صامتة لأن المفتاح الفريد
           (order_id, state_key) محجوز من المحاولة الأولى. نفس الطابع = تكرار
           فعلي (تعديل ملاحظة مثلًا) = سكوت زي الأول. */
        $stateAt  = self::toDb($state['at']);
        $inserted = DB::affectingStatement(
            'INSERT IGNORE INTO customer_push_events (order_id, state_key, sent_at, state_at) VALUES (?,?,?,?)',
            [$this->orderId, $state['key'], WireTime::nowDb(), $stateAt]
        );
        if ($inserted === 0) {
            $prev = DB::selectOne(
                'SELECT state_at FROM customer_push_events WHERE order_id = ? AND state_key = ?',
                [$this->orderId, $state['key']]
            );
            $prevAt = $prev?->state_at;
            if ($prevAt !== null && $stateAt !== null && strcmp($stateAt, (string) $prevAt) > 0) {
                DB::update(
                    'UPDATE customer_push_events SET state_at = ?, sent_at = ? WHERE order_id = ? AND state_key = ?',
                    [$stateAt, WireTime::nowDb(), $this->orderId, $state['key']]
                );
            } else {
                return;
            }
        }

        $ph   = implode(',', array_fill(0, count($recipients), '?'));
        $subs = DB::select(
            // fail_count ≥ 5 = اشتراك ميت (مفاتيح تالفة/خدمة بترفض ثابت) — بنتخطّاه بدل ما
            // نحاول للأبد ونعلّق الطابور ١٥ ثانية مع كل حالة؛ أول اشتراك ناجح من نفس
            // المتصفح بيصفّره (upsert في pushSubscribe)
            "SELECT id, endpoint, p256dh, auth FROM customer_push_subscriptions
              WHERE customer_id IN ($ph) AND fail_count < 5 ORDER BY id",
            $recipients
        );
        if ($subs === []) {
            return;   // العميل ماشغّلش الإشعارات على أي جهاز — الصف اتحجز وخلاص
        }

        // نفس أعلام الـAPI: العربي يفضل عربي والـ/ من غير هروب (الـSW بيعمل e.data.json())
        $payload = json_encode(self::payload($order, $state), ApiResponse::JSON_FLAGS);
        if ($payload === false) {
            throw new RuntimeException('فشل ترميز حمولة إشعار الأوردر ' . $this->orderId . ': ' . json_last_error_msg());
        }

        $webPush = WebPushFactory::make();

        foreach ($subs as $s) {
            $sub = (array) $s;
            try {
                $this->sendOne($webPush, $sub, $payload);
            } catch (Throwable $e) {
                // جهاز واحد بايظ (مفاتيح تالفة مثلًا) مايمنعش باقي أجهزة العميل
                report($e);
                $this->markFailed((int) $sub['id']);
            }
        }
    }

    /**
     * @param  array{id: int|string, endpoint: string, p256dh: string, auth: string}  $sub
     */
    private function sendOne(WebPush $webPush, array $sub, string $payload): void
    {
        /* aes128gcm (RFC 8291) صراحةً — افتراضي المكتبة لسه `aesgcm` القديم
           للتوافق الخلفي؛ كل المتصفحات اللي فيها Push API دلوقتي بتدعم المعيار. */
        $subscription = Subscription::create([
            'endpoint'        => (string) $sub['endpoint'],
            'keys'            => ['p256dh' => (string) $sub['p256dh'], 'auth' => (string) $sub['auth']],
            'contentEncoding' => 'aes128gcm',
        ]);

        $this->record((int) $sub['id'], $webPush->sendOneNotification($subscription, $payload));
    }

    /** نتيجة الإرسال → صف الاشتراك: نجاح يحدّث last_ok_at، 404/410 يمسح، غير كده fail_count++ */
    private function record(int $subId, MessageSentReport $report): void
    {
        if ($report->isSuccess()) {
            // النجاح بيصفّر العدّاد — معناه «فشل متتالي» عشان يفرز الأجهزة الميتة
            DB::update(
                'UPDATE customer_push_subscriptions SET last_ok_at = ?, fail_count = 0 WHERE id = ?',
                [WireTime::nowDb(), $subId]
            );

            return;
        }

        /* 404/410 = خدمة الدفع بتقول «الاشتراك ده انتهى» (العميل لغى الإذن أو
           مسح بيانات المتصفح). مفيش فايدة من الاحتفاظ بيه — بيتمسح فورًا،
           وأول ما التطبيق يتفتح تاني الواجهة بتسجّل اشتراك جديد. */
        if ($report->isSubscriptionExpired()) {
            DB::delete('DELETE FROM customer_push_subscriptions WHERE id = ?', [$subId]);

            return;
        }

        $this->markFailed($subId);
    }

    private function markFailed(int $subId): void
    {
        DB::update('UPDATE customer_push_subscriptions SET fail_count = fail_count + 1 WHERE id = ?', [$subId]);
    }

    /**
     * آخر حالة تستاهل إشعار — `['key', 'title', 'at' (ISO)]` أو null لو مفيش.
     *
     * 🔴 نسخة حرفية من قواعد `CustomerAppController::notifications()`:
     * نفس الشروط، نفس الطوابع، نفس الـfallbacks، ونفس الترتيب التنازلي
     * بمقارنة نصية على الطابع ISO (صالح لأن الصيغة ثابتة الطول وUTC،
     * والمقارنة النصية = المقارنة الزمنية). الفرق الوحيد إننا بناخد العنصر
     * الأول بس بدل القايمة كلها.
     * الاستثناء الوحيد في النص: سبب عدم التوصيل **مش** جزء من العنوان هنا
     * (هناك «لم يتم التوصيل — السبب») — في الستارة العنوان بيتقص، فالسبب
     * بيروح للجسم في `payload()`.
     *
     * عامة وساكنة عشان الفحص (tests/customer_push.php) يفحصها لوحدها.
     *
     * @param  array<string, mixed>  $o  صف الأوردر الخام
     * @return array{key: string, title: string, at: string}|null
     */
    /**
     * العملاء اللي الأوردر ده بيظهر لهم في التطبيق — مرآة CustomerAppController::ordersWhere
     * بالعكس: بدل «هات أوردرات العميل ده» → «هات عملاء الأوردر ده».
     *
     * 🔴 النطاق اتوسّع 2026-08-22 بعد تجربة حقيقية: أوردر من الفرع/الكول سنتر/
     * المحل ورقم المستلم فيه رقم عميل التطبيق — التطبيق بيعرضه كـ«شحنة جاية
     * ليك» (ordersWhere بتطابق بالتليفون) وكان الإشعار مابيتبعتش لأن
     * customer_id فاضي. دلوقتي بنستهدف كل العملاء بنفس قواعد ordersWhere.
     *
     * @param  array<string, mixed>  $o  صف الأوردر (id · customer_id · source · added_by)
     * @return int[]
     */
    public static function recipientCustomerIds(array $o): array
    {
        $ids = [];
        if ($o['customer_id'] !== null) {
            $ids[(int) $o['customer_id']] = true;
        }
        // أوردرات فايربيز القديمة: source=customer و added_by = uid القديم
        if (($o['source'] ?? '') === 'customer' && ! empty($o['added_by'])) {
            foreach (DB::select('SELECT id FROM customers WHERE legacy_key = ?', [(string) $o['added_by']]) as $r) {
                $ids[(int) $r->id] = true;
            }
        }
        // مستلمو الطرود بالتليفون — نفس تنويعات الرقم اللي ordersWhere بتستخدمها
        $phones = array_map(
            fn ($r) => (string) $r->receiver_phone,
            DB::select('SELECT receiver_phone FROM order_deliveries WHERE order_id = ?', [(int) $o['id']])
        );
        $variants = self::phoneVariants(...$phones);
        if ($variants !== []) {
            $in = implode(',', array_fill(0, count($variants), '?'));
            $rows = DB::select(
                "SELECT id FROM customers WHERE phone1 IN ($in) OR phone2 IN ($in)",
                array_merge($variants, $variants)
            );
            foreach ($rows as $r) {
                $ids[(int) $r->id] = true;
            }
        }

        return array_keys($ids);
    }

    /** نسخة حرفية من CustomerAppController::phoneVariants (private هناك) — أي تعديل يتعمل في الاتنين. */
    private static function phoneVariants(string ...$phones): array
    {
        $out = [];
        foreach ($phones as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $digits = preg_replace('/\D+/', '', $p);
            if (strlen($digits) < 8) {
                continue;
            }
            $noZero = ltrim($digits, '0');
            $out[$p] = true;
            $out[$digits] = true;
            $out[$noZero] = true;
            $out['0' . $noZero] = true;
        }

        return array_keys($out);
    }

    /** ISO (من WireTime::toWire) → DATETIME للقاعدة — `state_at` بيتقارن نصيًا فلازم شكل واحد */
    private static function toDb(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function latestState(array $o): ?array
    {
        $items = [];
        $push = function (string $key, ?string $at, string $title) use (&$items): void {
            if ($at === null || $at === '') {
                return;
            }
            $items[] = ['key' => $key, 'title' => $title, 'at' => (string) WireTime::toWire($at)];
        };

        // «وصل الفرع»: أول حالة — لحظة إنشاء الأوردر (العميل لازم يعرف إن طلبه
        // اتسجّل واتعالج في الفرع من غير ما يستنى إسناد طيار)
        $push('created', $o['created_at'] ?? null, 'تم استلام طلبك في الفرع — جارٍ تجهيزه');
        // «اتسند لطيار»: بيتحسب من current_pilot_since لو موجود عشان
        // النقل بين الطيارين يطلع إشعار جديد، وfallback على status_since
        if ($o['pilot_id'] !== null || in_array($o['status'], ['delivering', 'delivered'], true)) {
            $push('assigned', $o['current_pilot_since'] ?: $o['status_since'], 'تم إسناد طلبك لطيار');
        }
        $push('received', $o['received_at'],     'الطيار استلم شحنتك');
        $push('out',      $o['trip_started_at'], 'الطيار في الطريق إليك');
        if ($o['status'] === 'delivered') {
            $push('delivered', $o['delivered_at'], 'تم التسليم بنجاح');
        }
        if ($o['status'] === 'undelivered') {
            $push('failed', $o['undelivered_at'], 'لم يتم التوصيل');
        }
        if ($o['status'] === 'cancelled') {
            $push('cancel', $o['cancelled_at'] ?: $o['status_since'], 'تم إلغاء الطلب');
        }

        if ($items === []) {
            return null;
        }

        /* 🔴 الاختيار بأولوية الحالة مش بأحدث وقت. الترتيب الزمني كان بيكسر
           بعد إضافة «وصل الفرع»: لو created_at مساوي أو أحدث من وقت الإسناد
           (أوردر اتعمل واتسند في نفس الثانية من اللوحة — حالة شائعة)، كانت
           created بتغلب assigned وكل اللي بعدها يتكتم. الأولوية: created <
           assigned < received < out < (delivered|failed|cancel)، والوقت
           بيفضّ التعادل بس. */
        $rank = ['created' => 0, 'assigned' => 1, 'received' => 2, 'out' => 3,
                 'delivered' => 4, 'failed' => 4, 'cancel' => 4];
        usort($items, function ($a, $b) use ($rank): int {
            $d = ($rank[$b['key']] ?? -1) <=> ($rank[$a['key']] ?? -1);
            return $d !== 0 ? $d : strcmp((string) $b['at'], (string) $a['at']);
        });

        return $items[0];
    }

    /**
     * الحمولة بالعقد — الترتيب: title, body, tag, orderId, orderNum, key, url.
     *
     * @param  array<string, mixed>                       $o      صف الأوردر الخام
     * @param  array{key: string, title: string, at: string}  $state  من latestState()
     * @return array{title: string, body: string, tag: string, orderId: int, orderNum: string, key: string, url: string}
     */
    public static function payload(array $o, array $state): array
    {
        $id  = (int) $o['id'];
        $num = (string) $o['order_num'];

        // الجسم = رقم الأوردر عشان العميل يعرف أنهي طلب من غير ما يفتح؛ والسبب لو مفيش توصيل
        $body = 'طلب رقم ' . $num;
        if ($state['key'] === 'failed' && ! empty($o['undelivered_reason'])) {
            $body .= ' — ' . $o['undelivered_reason'];
        }

        $anchor = in_array($state['key'], self::TRACKING_KEYS, true) ? 'track' : 'order';

        return [
            'title'    => $state['title'],
            'body'     => $body,
            'tag'      => 'order-' . $id,
            'orderId'  => $id,
            'orderNum' => $num,
            'key'      => $state['key'],
            'url'      => '/customer.html#' . $anchor . '=' . $id,
        ];
    }
}
