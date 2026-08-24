<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

/**
 * نتيجة محاولة إرسال واحدة — اللي المهمة بتكتب بيه صف
 * `order_notifications` بعد ما المزوّد يخلّص.
 *
 * ═══ ليه فيه حالة اسمها pending أصلًا ═══
 * لأن «المزوّد اليدوي» نتيجته الطبيعية هي **مافيش إرسال**: الصف بيتسجّل
 * والموظف هو اللي بيبعت من الشاشة. لو الواجهة كانت بترجّع bool كنا هنبقى
 * محتاجين نفرّق بين «فشل» و«مش المفروض يبعت أصلًا» بره الواجهة، وده
 * بالظبط اللي المزوّد المفروض يقرره مش اللي بيناديه.
 *
 * ═══ ليه attempted منفصلة عن الحالة ═══
 * عمود `attempts` بيعدّ **المحاولات الفعلية على الشبكة**، مش عدد مرات ما
 * المهمة اتنفّذت. المزوّد اليدوي مابيحاولش، والمزوّد السحابي لو رفض
 * يشتغل من غير بيانات اعتماد كمان مابيحاولش — الاتنين فشل من نوع مختلف
 * ولازم `attempts` تفضل صفر فيهم عشان أي كنس مستقبلي لصفوف `failed`
 * يقدر يفرّق بين «اتحاول وفشل» و«ما اتحاولش أصلًا».
 */
final class SendOutcome
{
    private function __construct(
        /** pending | sent | failed — نفس قيم عمود status بالحرف */
        public readonly string $status,
        public readonly ?string $providerMessageId,
        public readonly ?string $error,
        public readonly bool $attempted,
    ) {
    }

    /** الوضع اليدوي: الصف اتسجّل والموظف هو اللي هيبعت */
    public static function awaitingHuman(): self
    {
        return new self('pending', null, null, false);
    }

    /** اتبعت فعلًا — المعرّف بيرجع من المزوّد (wamid عند Meta) */
    public static function sent(?string $providerMessageId): self
    {
        return new self('sent', $providerMessageId, null, true);
    }

    /** اتحاول وفشل — السبب بيتخزّن في عمود error */
    public static function failed(string $why): self
    {
        return new self('failed', null, self::clip($why), true);
    }

    /** ما اتحاولش أصلًا (إعداد ناقص مثلًا) — فشل بس `attempts` بتفضل صفر */
    public static function notAttempted(string $why): self
    {
        return new self('failed', null, self::clip($why), false);
    }

    /**
     * عمود `error` هو varchar(190) — والقص هنا مقصود وآمن: النص ده
     * **تشخيصي** مش بيانات، والبديل (استثناء عند الكتابة) كان هيخلّي
     * رسالة خطأ طويلة من Meta تمنع تسجيل الفشل نفسه.
     * `mb_substr` مش `substr` لأن النص عربي — القص بالبايت بيقطع الحرف نصين.
     */
    private static function clip(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

        return mb_strlen($s) > 190 ? mb_substr($s, 0, 189) . '…' : $s;
    }
}
