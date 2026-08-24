<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

/**
 * صف واحد جاي في `order_notifications` — مستلم واحد وقرار واحد.
 *
 * الرسالة **بتتبني حتى للمتخطّى**، وده مقصود: صف `skipped` من غير نص هو
 * مجرد شكوى. صف `skipped` بنصه جاهز هو حاجة الموظف يقدر ينسخها ويبعتها
 * بإيده أول ما يعرف الرقم الصح — يعني الجدول بيفضل مفيد في أسوأ حالة.
 */
final class Recipient
{
    public function __construct(
        /** اللي بيتخزّن في عمود recipient_phone — فاضي يعني مفيش رقم أصلًا */
        public readonly string $phoneKey,
        public readonly OutgoingMessage $message,
        /** null = ابعت. غير كده = السبب اللي بيتكتب في عمود error */
        public readonly ?string $skipReason,
    ) {
    }

    public function isSkipped(): bool
    {
        return $this->skipReason !== null;
    }

    /** نسخة من نفس المستلم بسبب تخطّي مختلف — بتستعمل لما الأوردر يتلغي */
    public function skippedBecause(string $reason): self
    {
        return new self($this->phoneKey, $this->message, $reason);
    }
}
