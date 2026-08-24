<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Jobs\SendOrderWhatsapp;
use Throwable;

/**
 * حطّ مهمة «ابعت للمستلمين» في الطابور من مسار إنشاء الأوردر.
 *
 * ═══ ليه trait — نفس سبب BroadcastsOrders بالحرف ═══
 * الشكل المطلوب دالة **على الكنترولر نفسه**، وكنترولرات الـAPI هنا مالهاش
 * أب مشترك (بالقصد، عشان النقل الحرفي من api/routes/*.php). الـtrait بيدّي
 * الدالة لللي محتاجها بس ومن غير أي وراثة جديدة.
 *
 * ═══ 🔴 العزل: الرسالة ممنوع تكسر إنشاء الأوردر ═══
 * نفس نمط `broadcastOrder()` بالحرف:
 *  • **جوه المعاملة عادي.** `SendOrderWhatsapp::$afterCommit = true`، يعني
 *    النداء هنا بيسجّل كولباك على مدير المعاملات والدفع الفعلي بعد الـcommit.
 *    المعاملة اتلغت = مفيش مهمة أصلًا.
 *  • **الطابور هو العزل.** `dispatch()` بتكتب صف في `jobs` وترجع — Meta
 *    واقعة أو شغّالة مايفرقش مع الطلب.
 *  • **والـtry/catch للباقي.** لو القاعدة نفسها رفضت كتابة صف الطابور،
 *    الاستثناء بيتسجّل في اللوج والأوردر بيكمل — الأوردر اتحفظ خلاص،
 *    ومفيش سبب في الدنيا يخلّي رسالة واتساب ترجّعه للمستخدم كفشل.
 *
 * ⚠️ نفس حدود الـtry/catch اللي في `BroadcastsOrders`: جوه معاملة، الكولباك
 * بيتنفّذ من `DatabaseTransactionsManager::commit()` — بره الـcatch ده.
 * عشان كده `SendOrderWhatsapp::handle()` ماسكة كل حاجة جوّاها كمان.
 */
trait NotifiesOrderReceivers
{
    /**
     * الصف الغايب أو المعرّف الغلط = سكوت (نفس قاعدة `broadcastOrder`) —
     * المهمة نفسها بتفحص وجود الأوردر لما تشتغل.
     */
    protected function notifyOrderReceivers(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        try {
            SendOrderWhatsapp::dispatch($orderId);
        } catch (Throwable $e) {
            // الرسايل مابتكسرش إنشاء الأوردر أبدًا — لوج وبس.
            report($e);
        }
    }
}
