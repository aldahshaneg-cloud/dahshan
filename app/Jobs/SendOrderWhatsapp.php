<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Whatsapp\OrderRecipients;
use App\Services\Whatsapp\ProviderFactory;
use App\Services\Whatsapp\Recipient;
use App\Services\Whatsapp\WhatsappProvider;
use App\Support\WireTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * «ابعت للمستلمين رسالة الأوردر الجديد» — المهمة اللي بتتحط في الطابور بعد
 * إنشاء أي أوردر، من أي مصدر.
 *
 * ═══ ليه مهمة طابور مش نداء مباشر ═══
 * `QUEUE_CONNECTION=database` على الإنتاج وخدمة `dahshan-queue` شغّالة، يعني
 * `dispatch()` بتكتب صف في جدول `jobs` وترجع فورًا. الإرسال الحقيقي (نداء
 * HTTP لـMeta، ممكن ياخد ثواني ×عدد المستلمين) بيحصل في العملية التانية.
 * لو كان نداء مباشر، كل أوردر كان هيدفع زمن الشبكة ده في وش المستخدم —
 * ولو Meta وقعت كان إنشاء الأوردر بيقف معاها.
 *
 * ═══ $afterCommit — نفس سبب ShouldDispatchAfterCommit في OrderChanged ═══
 * مسارات إنشاء الأوردر كلها جوه `DB::transaction()`. من غير السطر ده المهمة
 * بتتحط في الطابور **قبل** الـcommit، فالعامل ممكن يقراها ويدوّر على أوردر
 * لسه مش موجود — أو أسوأ، يبعت رسايل عن أوردر المعاملة بتاعته اتلغت.
 * `$afterCommit = true` بتأجّل الدفع لكولباك بعد الـcommit، وبتشتغل على
 * `sync` و`database` الاتنين (`SyncQueue::push` بتفحصها زي `DatabaseQueue`).
 *
 * ═══ 🔴 العزل: ليه كل حاجة هنا ملفوفة ═══
 * على `sync` (التطوير) الجسم ده بيتنفّذ جوه `commit()` — يعني **بره**
 * الـtry/catch اللي في الكنترولر، بالظبط نفس الحدّ المكتوب في
 * `BroadcastsOrders`. فالحماية لازم تكون هنا:
 *   • `handle()` كلها في try/catch بيرمي في اللوج وبس.
 *   • وكل مستلم لوحده كمان في try/catch — مستلم بايظ مايمنعش الباقيين.
 *   • والمزوّد نفسه متعاقد إنه **مايرميش** أصلًا (شوف `WhatsappProvider`).
 * تلات طبقات مقصودة: أي واحدة تقع، اللي بعدها بتمسك.
 *
 * ═══ ليه tries = 1 ═══
 * إعادة المحاولة التلقائية معناها استثناء بيطلع من `handle()` — واحنا
 * بنمسك كل حاجة، فالاستثناء مش هيطلع أصلًا. الفشل بيتسجّل في الصف بحالة
 * `failed` وسبب مكتوب وعمود `attempts`، والكنس (إعادة محاولة الصفوف
 * الفاشلة) قرار مرحلة تانية بيتبني على الأعمدة دي — مش على طابور بيعيد
 * المهمة كلها ويعيد بناء نفس الصفوف من الأول.
 * والوضع الافتراضي `manual` مابيبعتش أصلًا، فمفيش فشل شبكة يتعاد.
 */
final class SendOrderWhatsapp implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** مفيش إعادة محاولة تلقائية — الفشل بيتسجّل في الجدول (شوف رأس الملف) */
    public int $tries = 1;

    public function __construct(
        public readonly int $orderId,
    ) {
        /* 🔴 تأجيل الدفع للـcommit — أهم سطر في الكلاس (شوف رأس الملف).
           بيتضبط هنا مش كخاصية `public $afterCommit = true;` رغم إن ده
           الشكل اللي في توثيق لارافل: `Illuminate\Bus\Queueable` معرّفة
           `public $afterCommit;` (قيمتها الابتدائية null)، وPHP 8.2 بيعتبر
           إعادة التعريف بقيمة ابتدائية مختلفة **تعارض قاتل** وقت تركيب
           الـtrait — «define the same property … considered incompatible».
           الكلاس مكانش بيتحمّل أصلًا. `afterCommit()` بتاعة الـtrait نفسها
           بتعمل نفس الإسناد ده بالظبط. */
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        try {
            $this->run();
        } catch (Throwable $e) {
            // آخر شبكة أمان — الرسايل مابتكسرش حاجة، بتتسجّل في اللوج وبس
            report($e);
        }
    }

    private function run(): void
    {
        if ($this->orderId <= 0) {
            return;
        }

        /* الأعمدة الأربعة دي بالظبط هي المطلوبة: الرقم للنص، والحالة
           والإلغاء لقاعدة المنع. `SELECT *` كان هيجيب 58 عمود من غير داعي
           (نفس سبب الاختيار الصريح في `BroadcastsOrders::broadcastOrder`). */
        $order = DB::selectOne(
            'SELECT id, order_num, status, cancelled_at FROM orders WHERE id = ?',
            [$this->orderId]
        );

        // الأوردر مش موجود (اتمسح أو المعاملة اتلغت) = سكوت
        if ($order === null) {
            return;
        }

        $orderNum   = (string) $order->order_num;
        $recipients = OrderRecipients::forOrder($this->orderId, $orderNum);

        if ($recipients === []) {
            return;
        }

        /* قاعدة المنع التالتة: الأوردر اتلغى بين إنشاءه وتنفيذ المهمة.
           الفرق بين اللحظتين ثواني في الطبيعي — بس دقايق لو الطابور
           متأخّر، وده بالظبط الوقت اللي فيه كول سنتر بيلغي أوردر غلط.
           بنفحص العمودين: `status` هي المصدر، و`cancelled_at` بتتملى معاها
           في كل مسارات الإلغاء — أي واحد فيهم كفاية. */
        $cancelled = $order->status === 'cancelled' || $order->cancelled_at !== null;

        $provider = ProviderFactory::make();
        $now      = WireTime::nowDb();

        foreach ($recipients as $recipient) {
            try {
                if ($cancelled) {
                    $recipient = $recipient->skippedBecause(OrderRecipients::SKIP_CANCELLED);
                }

                $this->handleOne($recipient, $provider, $now);
            } catch (Throwable $e) {
                // مستلم واحد بايظ مايمنعش الباقيين
                report($e);
            }
        }
    }

    /**
     * بيسجّل صف المستلم، وبيبعت لو مفيش مانع.
     *
     * الترتيب مقصود: **الكتابة الأول، الإرسال بعدها.** لو عكسنا، رسالة
     * بتتبعت والسيرفر يقع قبل ما الصف يتكتب = المستلم استلم ومحدش يعرف،
     * وأول إعادة تشغيل بتبعتها تاني. بالترتيب ده أسوأ حالة هي صف `pending`
     * لرسالة اتبعتت — وده بيتشاف ويتصلّح، على عكس الرسالة المكررة.
     */
    private function handleOne(Recipient $recipient, WhatsappProvider $provider, string $now): void
    {
        $status = $recipient->isSkipped() ? 'skipped' : 'pending';

        /* 🔴 منع التكرار من القاعدة مش من الكود: `INSERT IGNORE` بيصطدم في
           `uq_order_notifications_target(order_id, channel, recipient_phone)`
           ويرجّع صفر صفوف. يعني بثّة تانية، أو تعديل على الأوردر بيعيد
           تشغيل المهمة، أو عاملين طابور شغالين في نفس اللحظة — كلهم بيدّوا
           صف واحد. الفحص-قبل-الكتابة (`SELECT` وبعده `INSERT`) كان هيسيب
           نافذة سباق بين الاتنين. */
        $inserted = DB::affectingStatement(
            'INSERT IGNORE INTO order_notifications
               (order_id, channel, recipient_phone, body, status, provider,
                provider_message_id, error, attempts, sent_by, sent_at, created_at)
             VALUES (?,?,?,?,?,?,NULL,?,0,NULL,NULL,?)',
            [
                $recipient->message->orderId,
                OrderRecipients::CHANNEL,
                $recipient->phoneKey,
                $recipient->message->body,
                $status,
                $provider->key(),
                $recipient->skipReason,
                $now,
            ]
        );

        /* صفر = الصف موجود من قبل. **ممنوع** نبعت تاني.

           ⚠️ نتيجة تشغيلية يوم التحويل للتلقائي: صفوف `pending` اللي اتكتبت
           في الفترة اليدوية **مش هتتبعت لوحدها**. المهمة مابترجعش لصف موجود،
           وده الصح — الموظف يمكن يكون بعتها بإيده خلاص، وإعادة الإرسال
           عليها كانت هتدّي المستلم رسالة تانية عن أوردر قديم. اللي هيتبعت
           تلقائي هو الأوردرات الجديدة بعد التحويل. الصفوف القديمة تتصفّى
           من الشاشة أو بأمر كنس مخصوص — قرار مرحلة تانية. */
        if ($inserted === 0) {
            return;
        }

        if ($recipient->isSkipped()) {
            return;
        }

        $rowId = (int) DB::getPdo()->lastInsertId();

        $outcome = $provider->send($recipient->message);

        /* المزوّد اليدوي بيرجّع pending — والصف اتكتب pending أصلًا،
           فمفيش UPDATE. ده مش تحسين أداء: النداء الفاضي على القاعدة كان
           هيحرّك صفوف من غير أي تغيير في كل أوردر في النظام. */
        if ($outcome->status === 'pending') {
            return;
        }

        DB::update(
            'UPDATE order_notifications
                SET status = ?, provider_message_id = ?, error = ?,
                    attempts = attempts + ?, sent_at = ?
              WHERE id = ?',
            [
                $outcome->status,
                $outcome->providerMessageId,
                $outcome->error,
                $outcome->attempted ? 1 : 0,
                $outcome->status === 'sent' ? WireTime::nowDb() : null,
                $rowId,
            ]
        );
    }
}
