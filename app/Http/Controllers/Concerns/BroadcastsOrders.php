<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Events\OrderChanged;
use App\Jobs\SendCustomerPush;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * بثّ «الأوردر ده اتغيّر» من مسارات الكتابة — الخطوة 1 في docs/REALTIME.md §6.
 *
 * ═══ ليه trait مش صنف مساعد في app/Support/ ولا كنترولر أساس ═══
 * الشكل المطلوب `protected function broadcastOrder(int $orderId): void` —
 * يعني دالة **على الكنترولر نفسه** مش دالة ساكنة على صنف بره. وكنترولرات
 * الـAPI هنا **مالهاش أب مشترك أصلًا** (`class OrdersController` من غير
 * `extends`، وكذلك الباقيين — بالقصد، عشان النقل الحرفي من api/routes/*.php)،
 * فكنترولر أساس جديد كان معناه لمس تعريف 12 كلاس عشان 3 محتاجينه فعلًا.
 * الـtrait بيدي نفس الدالة لللي محتاجها بس، ومن غير أي وراثة جديدة.
 *
 * ═══ إزاي دي «إضافة صامتة» ═══
 * • **جوه المعاملة عادي.** `OrderChanged` بينفّذ `ShouldDispatchAfterCommit`،
 *   فالنداء هنا بيسجّل callback على مدير المعاملات والبعت الفعلي بيحصل بعد
 *   الـcommit. لو المعاملة اتلغت، مفيش حدث أصلًا.
 * • **الطابور هو العزل.** على السيرفر `QUEUE_CONNECTION=database`، يعني
 *   `event()` بترمي مهمة `BroadcastEvent` في جدول `jobs` وخلاص — Reverb واقع
 *   أو شغّال مايفرقش مع الطلب. **ممنوع** يتحوّل ده لبعت متزامن
 *   (`broadcast()->toOthers()` أو `->via('sync')`) لأن ده بيلغي العزل ده.
 * • **الاستطلاع لسه هو المصدر الرسمي.** الحدث ده زيادة فوق `?since=` مش بديل
 *   عنه. لو شيلت الـtrait، النظام بيرجع زي ما كان بالحرف.
 * • **إشعار ستارة العميل راكب على نفس النقطة.** `SendCustomerPush` بتتدفع
 *   هنا كمان (من 2026-08-22) — مهمة طابور بـ`afterCommit` زي الحدث بالظبط،
 *   وبتقرر لوحدها لو فيه حاجة تستاهل إرسال (أوردر عميل تطبيق؟ حالة جديدة
 *   ماتبعتتش قبل كده؟ VAPID متظبّط؟). من هنا مجرد نداء أعمى بالـid.
 *
 * ⚠️ حدود الـtry/catch اللي تحت: بيغطّي قراءة الصف وتسجيل الحدث، وبيغطّي
 * البعت نفسه **بس** لما نتنده بره معاملة. جوه معاملة الـcallback بيتنفّذ
 * من `DatabaseTransactionsManager::commit()` — بره الـtry/catch ده. الحماية
 * الحقيقية هناك هي الطابور، مش الـcatch.
 */
trait BroadcastsOrders
{
    /**
     * بتقرا الصف بعد التعديل وتبثّ. جوه المعاملة عادي —
     * ShouldDispatchAfterCommit بتأجّل البعت للـcommit.
     *
     * الأعمدة الستة دي بالظبط هي اللي `OrderChanged::fromRow()` بتقراها.
     * `SELECT *` كان هيجيب 58 عمود (بما فيها `notes` من نوع TEXT) على كل
     * كتابة من غير داعي.
     *
     * ⚠️ قراءة عادية من غير قفل — الصف اللي بنبثّه اتقفل `FOR UPDATE` فوق في
     * الكنترولر أصلًا، والمعاملة بتشوف تعديلاتها هي دايمًا.
     *
     * الصف الغايب = سكوت. (`orderClearReturnFlag` مثلًا بيقبل id مش موجود
     * وبيرجّع ok — باج موروث؛ مانرميش استثناء جديد بسببه.)
     */
    protected function broadcastOrder(int $orderId, ?int $leftBranchId = null): void
    {
        if ($orderId <= 0) {
            return;
        }

        try {
            $row = DB::selectOne(
                'SELECT id, order_num, status, branch_id, pilot_id, updated_at
                   FROM orders WHERE id = ?',
                [$orderId]
            );

            if ($row === null) {
                return;
            }

            event(OrderChanged::fromRow($row));
            /* الأوردر غيّر فرعه → الفرع القديم لازم يعرف إنه خرج (شوف OrderChanged::$notifyBranchId) */
            if ($leftBranchId !== null && $leftBranchId > 0 && $leftBranchId !== (int) $row->branch_id) {
                event(new OrderChanged((int) $row->id, (string) $row->order_num, (string) ($row->status ?? ''), (int) $row->branch_id, null, $row->updated_at ?? null, $leftBranchId));
            }

            /* إشعار ستارة الهاتف للعميل — نفس الموضع عشان أي تغيير أوردر يعدّي
               عليه. `$afterCommit` جوه المهمة بيأجّل الدفع للـcommit زي الحدث
               بالظبط، والمهمة بتسكت لوحدها لو مفيش حاجة تتبعت (شوف رأس
               `SendCustomerPush`). بنبعت الـid بس — المهمة بتقرا الصف بنفسها
               وقت التنفيذ، لأن الحالة وقتها هي اللي تهم مش وقت الدفع. */
            SendCustomerPush::dispatch($orderId);
        } catch (Throwable $e) {
            // البثّ مايكسرش الطلب أبدًا — بيتسجّل في اللوج وبس.
            report($e);
        }
    }
}
