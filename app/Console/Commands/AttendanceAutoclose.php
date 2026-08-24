<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * إقفال جلسات الحضور المقطوعة — نقل حرفي لـ cron/attendance_autoclose.php.
 *
 * الجلسة بتتقفل لما النبض (heartbeat) ينقطع: `check_out` لسه NULL وآخر نبضة
 * (`last_seen` — أو `check_in` لو الجهاز ما بعتش نبضة أصلًا) أقدم من X دقيقة.
 *
 * ⚠️ الحتة اللي **مينفعش** تتغير: `check_out` بيتحط = **آخر نبضة معروفة**
 * مش وقت التشغيل. لو حطينا `NOW()` مدة الشغل المحسوبة هتتضخم بمدة الانقطاع
 * كلها — طيار قفل موبايله 9 بالليل والكرون لقاه 7 الصبح كان هيتحسبله 10
 * ساعات إضافية في كشف الحضور والحوافز. الانقطاع مش شغل.
 *
 * ⚠️ و`auto_check_out = 1` مش تفصيلة: بيفرّق في التقارير بين انصراف سجّله
 * الموظف بإيده وانصراف افترضه النظام لانقطاع النبض.
 *
 * التشغيل: php artisan attendance:autoclose
 * الجدولة: كل 5 دقايق (routes/console.php) — نفس دورية Task Scheduler القديم.
 */
class AttendanceAutoclose extends Command
{
    protected $signature = 'attendance:autoclose
                            {--json : اطبع نفس حمولة JSON بتاعة الكرون القديم بدل النص}';

    protected $description = 'بيقفل جلسات الحضور اللي نبضها انقطع، بوقت آخر نبضة مش بوقت التشغيل';

    public function handle(): int
    {
        $minutes = $this->timeoutMinutes();

        // الحاجز بيتحسب بـUTC عشان الأعمدة متخزنة UTC (نفس ما بيعمل now_utc())
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->sub(new DateInterval('PT' . $minutes . 'M'))
            ->format('Y-m-d H:i:s');

        try {
            /* معاملة واحدة: بنقفل الصفوف المرشحة بـFOR UPDATE قبل التعديل عشان
               نبضة متأخرة تدخل في نفس اللحظة ماتخليش الجلسة تتقفل غلط. الرجوع
               تلقائي — DB::transaction بتعمل rollback لوحدها لو رمى أي استثناء
               (نفس سبب استخدام ApiException بدل exit في باقي المشروع). */
            [$rows, $closed] = DB::transaction(function () use ($cutoff): array {
                $rows = DB::select(
                    'SELECT id, username, session_date, check_in, last_seen
                       FROM attendance_sessions
                      WHERE check_out IS NULL
                        AND COALESCE(last_seen, check_in) < ?
                      FOR UPDATE',
                    [$cutoff]
                );

                $closed = 0;
                foreach ($rows as $r) {
                    /* `AND check_out IS NULL` تاني في الـUPDATE مش تكرار: لو
                       الموظف سجّل انصرافه بإيده بين الـSELECT والـUPDATE،
                       ماينفعش ندهس وقته بوقت آخر نبضة. */
                    $closed += DB::update(
                        'UPDATE attendance_sessions
                            SET check_out = COALESCE(last_seen, check_in), auto_check_out = 1
                          WHERE id = ? AND check_out IS NULL',
                        [(int) $r->id]
                    );
                }

                return [$rows, $closed];
            });
        } catch (Throwable $e) {
            // نفس سلوك الكرون القديم: الرسالة على STDERR وكود خروج 1
            $this->error('attendance_autoclose failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $sessions = array_map(fn ($r) => [
            'id'       => (int) $r->id,
            'username' => $r->username,
            'day'      => $r->session_date,
        ], $rows);

        if ($this->option('json')) {
            // نفس ترتيب المفاتيح ونفس العلم بالحرف — لو حد بيقرا مخرج الكرون
            $this->line((string) json_encode([
                'ok'             => true,
                'timeoutMinutes' => $minutes,
                'cutoffUtc'      => $cutoff,
                'closed'         => $closed,
                'sessions'       => $sessions,
            ], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line("مهلة النبض: {$minutes} دقيقة   ·   الحاجز (UTC): {$cutoff}");

        if ($closed === 0) {
            // مفيش جلسة مستحقة = تشغيلة سليمة، مش خطأ
            $this->info('✓ مفيش جلسات مقطوعة — اتقفل 0');

            return self::SUCCESS;
        }

        $this->info("✓ اتقفل {$closed} جلسة:");
        foreach ($sessions as $s) {
            $this->line("   • #{$s['id']}  {$s['username']}  ({$s['day']})");
        }

        return self::SUCCESS;
    }

    /**
     * مهلة النبض بالدقايق من `site_settings.attendanceTimeout` — الافتراضي 15.
     *
     * الشكل مش موحّد في القاعدة والنقل حرفي عشان كده: المفتاح ممكن يكون رقم
     * صريح متخزن قديم («15»)، أو JSON رقم، أو JSON كائن فيه `minutes`.
     * التلات حالات مدعومة زي الأصل، و`max(1, ...)` بيمنع مهلة صفر أو سالبة
     * تقفل الجلسات الشغالة دلوقتي.
     */
    private function timeoutMinutes(): int
    {
        $default = 15;

        try {
            $raw = DB::selectOne(
                'SELECT setting_value FROM site_settings WHERE setting_key = ?',
                ['attendanceTimeout']
            )?->setting_value;

            if ($raw === null || $raw === '') {
                return $default;
            }

            $val = json_decode((string) $raw, true);
            if (is_numeric($val)) {
                return max(1, (int) $val);
            }
            if (is_array($val) && isset($val['minutes']) && is_numeric($val['minutes'])) {
                return max(1, (int) $val['minutes']);
            }
            if (is_numeric($raw)) {
                return max(1, (int) $raw); // قيمة قديمة متخزنة رقم صريح
            }
        } catch (Throwable $e) {
            // فشل قراءة الإعداد مايوقفش الإقفال — بنكمل بالافتراضي زي الأصل
            Log::warning('attendance_autoclose: settings read failed — ' . $e->getMessage());
        }

        return $default;
    }
}
