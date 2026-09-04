<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * اليوم التجاري — بلاغ صاحب النظام 2026-09-02:
 * «البرنامج بالكامل بيقلب التاريخ بعد الساعة 12 بالليل وده بيعمل مشاكل.
 *  الوردية بتبدأ 9 صباحًا وتنتهي 5 فجرًا — كل ده يوم واحد علشان الحسابات».
 *
 * القاعدة: أي لحظة قبل ساعة بداية اليوم (9ص قاهرة افتراضيًا) بتتبع اليوم
 * اللي قبلها. أوردر اتعمل 00:32 بليل بياخد رقم يوم امبارح وبيتحسب على
 * تحصيل امبارح — لأن وردية امبارح لسه شغالة.
 *
 * الساعة نفسها من نفس إعداد تقفيلة الطيارين (`pilotAccounting.dayStartHour`
 * في acc_settings — قرار 2026-08-27) عشان النظام كله يقلب في نفس اللحظة:
 * الترقيم والتحصيل والحضور والتقفيلة مصدرهم واحد.
 *
 * ⚠️ حصر النطاق: مفاتيح «الأيام» التشغيلية بس. تواريخ التخزين (created_at
 * إلخ) UTC زي ما هي، وعرض تاريخ سجل معيّن للمستخدم بيفضل بالتاريخ
 * الميلادي الحقيقي.
 */
final class BizDay
{
    private static ?int $startHour = null;

    /** ساعة بداية اليوم التجاري بتوقيت القاهرة (افتراضي 9) */
    public static function startHour(): int
    {
        if (self::$startHour === null) {
            try {
                $row = DB::select(
                    "SELECT setting_value FROM acc_settings WHERE setting_key = 'pilotAccounting'"
                )[0] ?? null;
                $v = $row ? json_decode((string) $row->setting_value, true) : null;
                self::$startHour = max(0, min(23, (int) (is_array($v) ? ($v['dayStartHour'] ?? 9) : 9)));
            } catch (Throwable) {
                // قبل ما الجدول يتعمل (تركيب جديد) — الافتراضي المتفق عليه
                self::$startHour = 9;
            }
        }

        return self::$startHour;
    }

    /** مفتاح اليوم التجاري: "2026-09-01" (الساعة 00:32 يوم 2 = يوم 1) */
    public static function key(?int $ts = null): string
    {
        $d = (new DateTimeImmutable('@' . ($ts ?? time())))
            ->setTimezone(new DateTimeZone('Africa/Cairo'));
        /* فحص الساعة المحلية مش طرح ثابت — عشان أيام تغيير التوقيت الصيفي
           ماتتزحزحش ساعة (نفس منطق PilotAccountingWire::bizMoment بالحرف) */
        if ((int) $d->format('G') < self::startHour()) {
            $d = $d->modify('-1 day');
        }

        return $d->format('Y-m-d');
    }

    /** المفتاح المضغوط لعدّادات الترقيم: "20260901" */
    public static function keyCompact(?int $ts = null): string
    {
        return str_replace('-', '', self::key($ts));
    }
}
