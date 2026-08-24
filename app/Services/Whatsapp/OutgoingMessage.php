<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

/**
 * رسالة واحدة جاهزة للإرسال لمستلم واحد — الحمولة اللي بتعدّي بين
 * `OrderRecipients` (اللي بيبنيها) و`WhatsappProvider` (اللي بيبعتها).
 *
 * ليه كائن مش مصفوفة: المزوّد التاني (Meta) محتاج **أجزاء** الرسالة مش
 * النص المجمّع بس — القالب المعتمد بياخد المتغيّرات كل واحد لوحده
 * (`code`، `zone`، `trackUrl`)، والنص الحر بياخد `body` كامل. لو عدّينا
 * سترينج واحد كنا هنبقى محتاجين نفكّكه تاني جوه المزوّد بـregex.
 *
 * كل الحقول readonly — الرسالة اللي اتبنت هي اللي بتتخزّن في
 * `order_notifications.body` وهي اللي بتتبعت. مفيش تعديل في النص بعد
 * التخزين، عشان اللي في الجدول يفضل هو **نفس** اللي وصل للمستلم.
 */
final class OutgoingMessage
{
    /**
     * @param  string  $phone  الرقم **بعد التطبيع** بصيغة محلية (01xxxxxxxxx)
     *                         — التحويل للصيغة الدولية مسؤولية المزوّد
     * @param  string  $code   كود التتبّع: رقم الأوردر أو رقم الأوردر + لاحقة الطرد
     * @param  ?string $zone   اسم المنطقة — null يعني السطر ده بيختفي من الرسالة
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNum,
        public readonly string $code,
        public readonly string $phone,
        public readonly ?string $zone,
        public readonly string $trackUrl,
        public readonly string $body,
    ) {
    }

    /**
     * الصيغة اللي واتساب بيفهمها: كود الدولة من غير + .
     * `01012345678` → `201012345678`
     *
     * نفس التحويل بالحرف اللي في `sendCodeWhatsApp()` في public/store.html:
     *
     *     let p = String(c.phone || "").replace(/\D/g, "");
     *     if (p.startsWith("0")) p = "2" + p;
     *
     * 🔴 الـ2 بتتحط **قدّام** الرقم كله — الصفر بيفضل مكانه ومابيتشالش.
     * رقم مصر الدولي هو `20` + الرقم المحلي **بصفره**: `20` + `1012345678`،
     * واللي بيطلع من الجمع ده هو `2` + `01012345678` = `201012345678`.
     * استبدال الصفر بـ2 (`'2' . substr($digits, 1)`) بيدّي `21012345678` —
     * عشرة أرقام، رقم مش موجود، وواتساب بيرفضه. الغلطة دي اتعملت فعلًا هنا
     * واتمسكت في الاختبار.
     *
     * الفرع التاني (رقم مش بادئ بصفر) نظريًا مايحصلش — الرقم عدّى على
     * `TrustWire::normalizePhone` وعلى فحص `^01\d{9}$` — متساب مطابقةً للأصل.
     */
    public function waPhone(): string
    {
        $digits = preg_replace('/\D+/', '', $this->phone) ?? '';

        return str_starts_with($digits, '0') ? '2' . $digits : $digits;
    }
}
