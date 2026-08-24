<?php

declare(strict_types=1);

namespace App\Support;

/**
 * عمولة الطيار — نقل حرفي لـ pilot_commission_for() في api/constants.php.
 *
 * 🔴 **دي فلوس.** المعادلة متطابقة في tiar.html و tiar_branch.html وفي
 * الـAPI، وأي انحراف بيطلع كفرق في مستحقات الطيارين. ممنوع «تحسينها».
 *
 * تفاصيل لازم تفضل زي ما هي:
 *  • المقارنة `== 0.0` **مرنة مش صارمة** — عشان القيمة جاية من القاعدة
 *    كنص decimal، و`"0.00" === 0.0` بتبقى false. الصرامة هنا كانت هتخلي
 *    عمولة الصفر تتحسب كنسبة.
 *  • النوع الفاضي أو null = `percent` (الافتراضي في الكود القديم).
 *  • أي نوع تالت غير `fixed` بيتعامل كـ`percent`. السكيمة بتذكر
 *    `per_order` وVOCAB بينفي وجودها — البند ده مفتوح ومحتاج قرار من
 *    صاحب المشروع (بند 8 في خطة الترحيل). لحد ما يتحسم، السلوك زي الأصل.
 */
final class Commission
{
    public static function forPilot(
        ?string $commissionType,
        float $commissionValue,
        float $totalDeliveryPrice,
    ): float {
        if ($commissionValue == 0.0) {   // مرنة عن قصد — شوف الشرح فوق
            return 0.0;
        }

        $type = $commissionType ?: 'percent';

        return $type === 'fixed'
            ? $commissionValue
            : $totalDeliveryPrice * $commissionValue / 100.0;
    }
}
