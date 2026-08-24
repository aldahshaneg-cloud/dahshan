<?php

declare(strict_types=1);

namespace App\Events;

use App\Support\Vocab;
use App\Support\WireTime;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * «الأوردر ده اتغيّر» — الحدث النموذجي لطبقة التحديث الفوري.
 *
 * ✅ **مفعّل من 2026-08-20.** 22 موضع بثّ في OrdersController وBoardController
 * وCustomerAppController عن طريق `BroadcastsOrders::broadcastOrder()`، والتلات
 * لوحات (tiar/branch/callcenter) بتسمع عن طريق `assets/js/realtime.js`.
 *
 * 🔴 **الاستطلاع لسه شغّال جنبه بنفس الفترة (8 ثواني) — وده مقصود.**
 * الويبسوكت لما يقع بيسكت من غير ما حد يعرف؛ الاستطلاع هو اللي بيمسك اللي
 * ضاع. توسيع الفترة قرار منفصل بيتاخد بعد ما الدفع يثبت في التشغيل الحقيقي.
 *
 * ═══ الحمولة: إشعار مش بيانات ═══
 * الأوردر الكامل 58 مفتاح (`OrderWire::one()`)، والرد الكامل ~1.2 ميجا.
 * الحدث ده بيبعت **الحد الأدنى** اللي يخلّي الواجهة تقرر: تحدّث الصف عندها
 * محليًا، ولا تنده الـAPI تجيب النسخة الكاملة. لو حطّينا الأوردر كله هنا
 * هنبقى نقلنا مشكلة الحجم من الاستطلاع للويبسوكت مش أكتر — وكمان هنبقى
 * عندنا نسختين من طبقة السلك لازم يفضلوا متطابقين.
 *
 * ═══ ليه ShouldDispatchAfterCommit ═══
 * 85 مسار كتابة في النظام شغالين جوه `DB::transaction()`. من غير الواجهة
 * دي الحدث بيتبعت **قبل** الـcommit (خصوصًا و`QUEUE_CONNECTION=sync`)، فلو
 * المعاملة اتلغت بتبقى الواجهات شافت تغيير ما حصلش — وأسوأ حاجة إنها
 * هتفضل شايفاه لحد ما الاستطلاع الجاي يصحّحها.
 *
 * ═══ اللغة على السلك ═══
 * نفس لغة `OrderWire` بالحرف — الحالة **عربية** (`Vocab::statusToAr`)
 * والتوقيت ISO-8601 UTC بحرف Z (`WireTime::toWire`). الواجهات بتقارن
 * الحالة نصًا (`o.status === "تم التسليم"`)، فأي كود إنجليزي هنا بيكسّرها.
 */
final class OrderChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * المدخلات **خام زي ما هي في القاعدة** مش مترجمة، عشان مكان التحويل
     * يفضل واحد (`broadcastWith`) زي ما `OrderWire` مكان التحويل الوحيد
     * لردود الـAPI.
     *
     * @param  string       $statusCode   كود الحالة الإنجليزي من عمود orders.status
     * @param  string|null  $updatedAtDb  قيمة orders.updated_at الخام (DATETIME(3) بتوقيت UTC)
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNum,
        public readonly string $statusCode,
        public readonly int $branchId,
        public readonly ?int $pilotId,
        public readonly ?string $updatedAtDb,
    ) {
    }

    /**
     * بناء الحدث من صف `orders` زي ما `DB::select()` بترجّعه (كائن أو مصفوفة).
     * موجودة عشان الكنترولر وقت التحويل يبقى سطر واحد ومايعيدش قراءة أسماء
     * الأعمدة في كل مكان.
     */
    public static function fromRow(object|array $row): self
    {
        $r = (array) $row;

        return new self(
            orderId:     (int) $r['id'],
            orderNum:    (string) $r['order_num'],
            statusCode:  (string) ($r['status'] ?? ''),
            branchId:    (int) $r['branch_id'],
            pilotId:     isset($r['pilot_id']) && $r['pilot_id'] !== null ? (int) $r['pilot_id'] : null,
            updatedAtDb: $r['updated_at'] ?? null,
        );
    }

    /**
     * قناة الفرع — وقناة الطيار كمان لو الأوردر محمّل على طيار.
     * التفويض للقناتين في routes/channels.php.
     *
     * ليه الفرع مش الأوردر: لوحة الفرع بتتفرّج على **كل** أوردرات فرعها،
     * فالاشتراك بقناة لكل أوردر يعني آلاف الاشتراكات للوحة الواحدة.
     * والقناة **خاصة** (private-) مش عامة، لأن أرقام التليفونات وأسماء
     * العملاء بتخرج من نفس المنظومة وفحص الخصوصية العدائي بيمشّط عليها.
     *
     * نفس المنطق بيسري على الطيار: نطاقه `pilot_id` مش الفرع، فقناة الفرع
     * كانت هتوريه أوردرات مش بتاعته. قناته هي نطاقه بالظبط.
     *
     * التكرار مقصود ومش مكلّف: القناتين بيتبعتوا في نفس نداء البثّ الواحد،
     * والمشترك اللي على الاتنين (لو حصل) بيوصله الحدث مرتين — والواجهات
     * بتحدّث نفس الصف بنفس القيمة فالتكرار مالوش أثر.
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('branch.' . $this->branchId)];

        /* ⚠️ **الطيار القديم مايعرفش إن الأوردر خرج منه.** لما أوردر يتحوّل
           من طيار لطيار (`OrdersController::transferPilot`) أو يترفع من طيار
           خالص، `broadcastOrder()` بتقرا الصف **بعد** التعديل — فـ`pilotId`
           اللي وصل هنا هو الطيار **الجديد**، والحدث بيروح لقناته هو بس.
           جهاز الطيار القديم بيفضل شايف الأوردر في قايمته لحد ما الاستطلاع
           يمسكها (قايمته بتتبني من `pilot_id` فالأوردر بيختفي منها في الدورة
           الجاية). نفس محدودية `transferBranch()` بالحرف ونفس السبب.

           حدث تاني للطيار القديم محتاج `from_pilot_id` — وده معروف في
           `transferPilot()` بس، مش في `broadcastOrder()` اللي شايلة الـ22
           موضع بثّ كلهم. يعني توقيع تاني للدالة المشتركة، وده قرار مرحلة
           تحويل تطبيق الطيار مش دلوقتي.

           و`pilotId` صفر أو سالب (مالوش وجود في القاعدة عمليًا) بيبني اسم
           قناة بيترفض تفويضه أصلًا — يعني حدث على قناة بلا مشتركين، مش تسريب. */
        if ($this->pilotId !== null) {
            $channels[] = new PrivateChannel('pilot.' . $this->pilotId);
        }

        return $channels;
    }

    /**
     * اسم الحدث على السلك — بنثبّته صراحةً بدل اسم الكلاس الكامل
     * (`App\Events\OrderChanged`). كده نقدر نعيد ترتيب الكلاسات في PHP من
     * غير ما نكسّر أي واجهة بتسمع، والـJS بيبقى `.listen('.order.changed')`.
     */
    public function broadcastAs(): string
    {
        return 'order.changed';
    }

    /**
     * @return array<string, mixed>
     *
     * ⚠️ `updatedAt` بيطلع بـ`.000` دايمًا لأن `WireTime::toWire` كده —
     * ده سلوك الأصل بالحرف رغم إن العمود DATETIME(3). يعني الطابع ده صالح
     * **للعرض والمقارنة**، وممنوع يتستخدم كعلامة `?since=` للاستطلاع
     * (لأنه بيتقرّب لتحت لثانية كاملة فممكن يخلّي تغيير يتفوّت).
     * علامة الاستطلاع تفضل `serverNow` الجاية من الـAPI زي ما هي.
     */
    public function broadcastWith(): array
    {
        return [
            'id'        => $this->orderId,
            'orderNum'  => $this->orderNum,
            'status'    => Vocab::statusToAr($this->statusCode),
            'branchId'  => $this->branchId,
            'pilotId'   => $this->pilotId,
            'updatedAt' => WireTime::toWire($this->updatedAtDb),
        ];
    }
}
