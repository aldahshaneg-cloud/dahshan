<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * التوقيت على السلك — نقل حرفي لـ dt_to_wire() و wire_to_dt().
 *
 * صيغة اللسان القديم: ISO-8601 بتوقيت UTC، بميلي ثانية، وبحرف Z.
 * (الويب كان `new Date().toISOString()` وFlutter بيبعت ميكروثانية —
 *  فالقبول مرن، والإخراج موحّد بالميلي ثانية.)
 *
 * ⚠️ الإخراج بيحط `.000` **دايمًا** حتى لو العمود DATETIME(3) وفيه كسور
 * فعلية. ده سلوك الأصل بالحرف. تغييره يعني قيمة مختلفة على السلك.
 */
final class WireTime
{
    /** DATETIME من MariaDB (UTC) → "2026-08-07T14:03:25.000Z" */
    public static function toWire(?string $dbDatetime): ?string
    {
        if ($dbDatetime === null || $dbDatetime === '') {
            return null;
        }

        $ts = strtotime($dbDatetime . ' UTC');
        if ($ts === false) {
            return null;
        }

        // الميلي ثانية مش متخزنة في DATETIME العادي — بنطلع .000
        return gmdate('Y-m-d\TH:i:s', $ts) . '.000Z';
    }

    /** ISO من الواجهات/التطبيق (بميلي أو ميكرو ثانية أو من غيرهم) → DATETIME UTC */
    public static function toDb(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            $d = new DateTimeImmutable($iso);
        } catch (Exception) {
            return null;
        }

        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** الوقت الحالي بصيغة التخزين (UTC) — المقابل لـ now_utc() */
    public static function nowDb(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * مفتاح اليوم بتوقيت القاهرة: "2026-08-06" — المقابل لـ cairo_day_key().
     *
     * ⚠️ التخزين UTC بس **يوم الحضور بيتقفل على القاهرة** مش UTC: وردية
     * بتقفل 1 بالليل بتوقيت القاهرة لازم تتحسب على يوم امبارح مش النهارده.
     * الفرق ده هو اللي بيخلي جدول الحضور مطابق لكشوف الشيفتات الورقية.
     * (النسخة المضغوطة YYYYMMDD لعدّادات ترقيم الأوردرات حاجة تانية.)
     */
    public static function cairoDayKey(?int $ts = null): string
    {
        $d = new DateTimeImmutable('@' . ($ts ?? time()));

        return $d->setTimezone(new DateTimeZone('Africa/Cairo'))->format('Y-m-d');
    }
}
