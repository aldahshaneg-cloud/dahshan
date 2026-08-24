<?php

declare(strict_types=1);

namespace App\Wire;

use App\Support\WireTime;

/**
 * طبقة السلك لرسايل إشعار المستلمين (`order_notifications`).
 *
 * ⚠️ الحالة هنا **إنجليزية على السلك** — زي ContactWire وSupportWire بالظبط.
 * بترجع `pending` / `sent` / `failed` / `skipped` زي ما هي في العمود من غير
 * أي مرور على Vocab. القاموس بتاع Vocab بيترجم حالات **الأوردرات**، والشاشة
 * بتقارن الحالة نصًا (`n.status === 'pending'`) وبتفلتر بيها. أي ترجمة هنا =
 * فلترة الشاشة بتوقف بالصمت.
 *
 * قواعد ملزمة (نفس قواعد CoreWire/ContactWire):
 *  • كل مفتاح موجود دايمًا حتى لو القيمة null — الواجهة مابتفحصش الوجود.
 *  • الأسماء camelCase على السلك مش أسماء الأعمدة: `phone` مش
 *    `recipient_phone`، و`sentBy` مش `sent_by`.
 *  • التواريخ كلها بتعدّي على WireTime::toWire — ISO بـZ زي باقي النظام.
 *
 * ═══════════════════════════════════════════════════════════════════
 * 🔒 الخصوصية — الصف بيطلع **بلا تقنيع**
 * ═══════════════════════════════════════════════════════════════════
 * فيه اسم المستلم ورقمه ونص الرسالة كامل. ده مقصود: الشاشة اللي بتستهلكه
 * وظيفتها إن الموظف يفتح واتساب على الرقم ده بالنص ده — التقنيع بيلغي
 * الشاشة نفسها. عشان كده الحد الأمني **على المسار**:
 *   • `role:admin,branch,callcenter` — نفس جمهور شاشة الأوردرات.
 *   • ودور `branch` مقصوص على أوردرات فرعه في الكنترولر، بنفس شرط
 *     `OrdersController::index` (`o.branch_id = ?`).
 * لو المسار اتفتح لدور أوسع (`store` أو `pilot` مثلًا) لازم تتعمل نسخة
 * مقنّعة — الحد على المسار مش على السلك، زي ContactWire بالحرف.
 *
 * ═══════════════════════════════════════════════════════════════════
 * الحقول المحسوبة — `orderNum` و`name` و`parcels`
 * ═══════════════════════════════════════════════════════════════════
 * التلاتة **مش أعمدة** في `order_notifications`. الجدول بيخزّن الرقم
 * المطبّع بس (`recipient_phone`) لأنه جزء من مفتاح منع التكرار؛ الاسم
 * بيتغيّر (الموظف بيصلّح اسم مكتوب غلط) والصف snapshot للرسالة مش للمستلم.
 * فالكنترولر بيجيبهم من `orders` و`order_deliveries` وبيمرّرهم هنا في
 * `$meta`. الصف اللي مالقاش مقابله (الطرد اتنقل بـsplit مثلًا) بياخد
 * `name = null` — **مش خطأ**، والشاشة بتعرض الرقم لوحده وقتها.
 */
final class NotifyWire
{
    /** بيقبل صف كـarray أو stdClass ويرجّع مصفوفة — DB::select بيرجع stdClass */
    public static function row(array|object $row): array
    {
        return is_array($row) ? $row : (array) $row;
    }

    /**
     * رسالة إشعار واحدة.
     *
     * @param  array{orderNum?:string|null,name?:string|null,parcels?:int[]}  $meta
     *         الحقول اللي مصدرها بره الجدول (شوف رأس الملف)
     */
    public static function notification(array|object $row, array $meta = []): array
    {
        $r = self::row($row);

        /* `order_num` ممكن يجي من الـJOIN في نفس الصف أو من $meta — بنقبل
           الاتنين عشان الكنترولر ما يبقاش مجبور يبني خريطة لحاجة الاستعلام
           جايبها أصلًا. */
        $orderNum = $meta['orderNum'] ?? ($r['order_num'] ?? null);

        return [
            'id'      => (int) $r['id'],
            'orderId' => (int) $r['order_id'],
            // رقم الأوردر الأساسي — **مش** كود التتبّع اللي في الرسالة.
            // كود اللاحقة (`…-2`) بيبان جوه `body` وبس، شوف OrderRecipients.
            'orderNum' => $orderNum !== null ? (string) $orderNum : null,
            'channel'  => $r['channel'],
            // الرقم المطبّع زي ما هو في العمود — فاضي يعني الطرد مالوش رقم
            'phone'    => $r['recipient_phone'],
            'name'     => $meta['name'] ?? null,
            /** @var int[] أرقام الطرود اللي المستلم ده مسؤول عنها */
            'parcels'  => array_values($meta['parcels'] ?? []),
            // 🔴 نص الرسالة snapshot — بيتبعت **زي ما هو** بلا إعادة بناء
            'body'     => $r['body'],
            'status'   => $r['status'],          // pending/sent/failed/skipped — إنجليزي
            'provider' => $r['provider'],
            'providerMessageId' => $r['provider_message_id'],
            // سبب الفشل أو سبب التخطّي — عربي جاهز للعرض (OrderRecipients::SKIP_*)
            'error'    => $r['error'],
            'attempts' => (int) $r['attempts'],
            // مليان = اتبعت بإيد موظف، فاضي مع status=sent = اتبعت تلقائي
            'sentBy'   => $r['sent_by'],
            'sentAt'   => WireTime::toWire($r['sent_at']),
            'createdAt' => WireTime::toWire($r['created_at']),
        ];
    }
}
