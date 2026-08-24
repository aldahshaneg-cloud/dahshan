<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * يوم القاهرة ورقم الأوردر — نقل حرفي من api/constants.php.
 *
 * ⚠️ **مفتاح اليوم بتوقيت القاهرة مش UTC.** ده مقصود: التخزين كله UTC،
 * لكن «اليوم» في العدّاد وفي الحضور هو اليوم اللي الناس بتشتغله. لو
 * اتحسب بـUTC، الشغل بعد 10 مساءً بتوقيت القاهرة صيفًا كان هيتحسب على
 * اليوم اللي بعده ويصفّر العدّاد في نص الشيفت.
 */
final class OrderNumber
{
    /** مفتاح اليوم بتوقيت القاهرة: "2026-08-06" — للحضور */
    public static function cairoDayKey(?int $ts = null): string
    {
        return (new DateTimeImmutable('@' . ($ts ?? time())))
            ->setTimezone(new DateTimeZone('Africa/Cairo'))
            ->format('Y-m-d');
    }

    /** مفتاح اليوم المضغوط: "20260806" — لعدّادات الترقيم */
    public static function cairoDayKeyCompact(?int $ts = null): string
    {
        return (new DateTimeImmutable('@' . ($ts ?? time())))
            ->setTimezone(new DateTimeZone('Africa/Cairo'))
            ->format('Ymd');
    }

    /**
     * رقم الأوردر: {branchCode}-{YYMMDD قاهرة}-{NNN} — مثال HAL-260804-001
     *
     * العدّاد يومي لكل فرع وبيصفّر كل يوم. **الزيادة مسؤولية المسار مش
     * الدالة دي** — لازم تتم جوه معاملة SQL بقفل صف.
     *
     * ملاحظة تاريخية: النظام القديم كان بيجيب العدّاد بـ LAST_INSERT_ID
     * وده كان بيدّي **id الصف** بدل 1 في أول شحنة كل يوم (اتصلح 2026-08-19).
     * الترحيل لازم يقرا العدّاد صراحةً بعد الـupsert.
     */
    public static function format(string $branchCode, int $dailyCount, ?int $ts = null): string
    {
        $code = $branchCode !== '' ? $branchCode : 'ORD';
        $day  = substr(self::cairoDayKeyCompact($ts), 2);   // YYMMDD

        return $code . '-' . $day . '-' . str_pad((string) $dailyCount, 3, '0', STR_PAD_LEFT);
    }

    /** رقم الجزء المفصول: HAL-260804-001 + طرود [2,3] → HAL-260804-001-2+3 */
    public static function parcelSuffix(string $orderNum, array $parcelNos): string
    {
        $nos = array_values(array_filter(array_map('intval', $parcelNos)));

        return $nos ? $orderNum . '-' . implode('+', $nos) : $orderNum;
    }
}
