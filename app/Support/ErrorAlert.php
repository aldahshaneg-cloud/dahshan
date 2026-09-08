<?php

declare(strict_types=1);

namespace App\Support;

use App\Jobs\SendErrorAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 🚨 تنبيه الأعطال — بيمسك العطل على الإنتاج ويسجّله ويبعت واتساب.
 *
 * ═══ ليه الملف ده موجود ═══
 * باج `Undefined variable $codAllowed` قعد **3 أيام** على الإنتاج والعملاء
 * مش عارفين يعملوا أوردر خالص، ومحدش عرف غير لما صاحب النظام جرّب التطبيق
 * بنفسه بالصدفة. العطل كان مكتوب في `laravel.log` من أول لحظة — بس محدش
 * بيفتح الملف ده. السجل اللي محدش بيقراه مش نظام إنذار.
 *
 * ═══ القواعد اللي بتخلّيه مفيد مش مزعج ═══
 *  • **التجميع بالبصمة**: نفس العطل (نوعه + ملفه + سطره) بيتعدّ في صف
 *    واحد. مسار مكسور بيتنده 500 مرة = تنبيه واحد وعدّاد بـ500، مش 500
 *    رسالة. من غير ده أول عطل كان هيغرق التليفون ويتقفل الإشعار للأبد.
 *  • **فترة تهدئة**: نفس البصمة مابتنبّهش تاني غير بعد `cooldown` دقيقة.
 *  • **سقف ساعي**: أقصى عدد تنبيهات في الساعة، عشان 10 أعطال مختلفة مع
 *    بعض مايبقوش 10 رسايل.
 *  • **الأخطاء المتوقعة مش أعطال**: أي `ApiException` تحت 500 (بيانات
 *    ناقصة، صلاحية، مش موجود) بتتفلتر قبل ما توصل هنا أصلًا.
 *
 * ═══ ممنوع يرمي ═══
 * الدالة دي بتتنده من **مسار معالجة الأعطال نفسه**. أي استثناء بيطلع منها
 * بيدفن العطل الأصلي ويخلّي الرد للمستخدم غامض. فكل حاجة جواها في
 * try/catch صامت — أسوأ نتيجة ممكنة هي «مافيش تنبيه»، مش «عطل تاني».
 */
final class ErrorAlert
{
    /** بيتنده من bootstrap/app.php عند كل عطل مستحق للتسجيل */
    public static function capture(Throwable $e, ?Request $request = null): void
    {
        try {
            if (! (bool) config('dahshan.alerts.enabled', true)) {
                return;
            }

            $sig = self::signature($e);
            $now = WireTime::nowDb();

            $row = DB::select('SELECT * FROM error_alerts WHERE signature = ? LIMIT 1', [$sig])[0] ?? null;

            if ($row === null) {
                DB::insert(
                    'INSERT INTO error_alerts
                       (signature, title, detail, url, method, actor, occurrences,
                        first_seen_at, last_seen_at, created_at)
                     VALUES (?,?,?,?,?,?,1,?,?,?)',
                    [
                        $sig,
                        mb_substr(self::title($e), 0, 190),
                        mb_substr(self::detail($e, $request), 0, 4000),
                        mb_substr((string) ($request?->fullUrl() ?? ''), 0, 300) ?: null,
                        mb_substr((string) ($request?->method() ?? ''), 0, 10) ?: null,
                        mb_substr(self::actor($request), 0, 190) ?: null,
                        $now, $now, $now,
                    ]
                );
                self::notify($sig);

                return;
            }

            $row = (array) $row;
            DB::update(
                'UPDATE error_alerts
                    SET occurrences = occurrences + 1, last_seen_at = ?,
                        detail = ?, url = ?, method = ?, actor = ?
                  WHERE id = ?',
                [
                    $now,
                    mb_substr(self::detail($e, $request), 0, 4000),
                    mb_substr((string) ($request?->fullUrl() ?? ''), 0, 300) ?: null,
                    mb_substr((string) ($request?->method() ?? ''), 0, 10) ?: null,
                    mb_substr(self::actor($request), 0, 190) ?: null,
                    (int) $row['id'],
                ]
            );

            /* التهدئة: نفس العطل مابينبّهش تاني إلا بعد المدة. الرقم بيفضل
               بيزيد في الصف فمحدش بيفقد حجم المشكلة. */
            $cool = max(1, (int) config('dahshan.alerts.cooldown_minutes', 60));
            $last = $row['notified_at'] ?? null;
            if ($last === null || strtotime($last . ' UTC') < time() - $cool * 60) {
                self::notify($sig);
            }
        } catch (Throwable) {
            // الصمت مقصود — شوف شرح الكلاس
        }
    }

    /** بصمة العطل: نوعه + مكانه. الرسالة **مش** جزء منها لأنها بتتغيّر بالقيم */
    private static function signature(Throwable $e): string
    {
        [$file, $line] = self::appFrame($e);

        return sha1(get_class($e) . '|' . $file . '|' . $line);
    }

    /**
     * أول إطار جوه app/ (2026-09-08): أخطاء القاعدة كلها بترمي من نفس سطر لارافل
     * (Connection.php)، فكانت كلها بصمة واحدة — صف واحد بعنوان أول عطل حصل
     * والأعطال الجديدة مابتبانش ولا بتنبّه.
     */
    public static function appFrame(Throwable $e): array
    {
        $app = str_replace('\\', '/', app_path());
        $own = str_replace('\\', '/', $e->getFile());
        if (str_starts_with($own, $app)) {
            return [$own, $e->getLine()];
        }
        foreach ($e->getTrace() as $fr) {
            $file = str_replace('\\', '/', (string) ($fr['file'] ?? ''));
            if ($file !== '' && str_starts_with($file, $app)) {
                return [$file, (int) ($fr['line'] ?? 0)];
            }
        }

        return [$own, $e->getLine()];
    }

    private static function title(Throwable $e): string
    {
        $cls = (string) (strrchr(get_class($e), '\\') ?: get_class($e));

        return ltrim($cls, '\\') . ': ' . $e->getMessage();
    }

    private static function detail(Throwable $e, ?Request $r): string
    {
        $lines = [
            'النوع   : ' . get_class($e),
            'الرسالة : ' . $e->getMessage(),
            'المكان  : ' . $e->getFile() . ':' . $e->getLine(),
        ];
        if ($r) {
            $lines[] = 'الطلب   : ' . $r->method() . ' ' . $r->path();
        }
        // أول 6 إطارات بس — الباقي ضجيج في رسالة واتساب
        $trace = array_slice(explode("\n", $e->getTraceAsString()), 0, 6);
        $lines[] = '';
        $lines[] = implode("\n", $trace);

        return implode("\n", $lines);
    }

    private static function actor(?Request $r): string
    {
        if (! $r) {
            return '';
        }
        try {
            $a = $r->attributes->get(\App\Http\Middleware\ResolveApiActor::ATTRIBUTE);

            return $a instanceof Actor ? ($a->username . ' (' . $a->role . ')') : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * السقف الساعي ثم الإرسال. الجدول نفسه هو العدّاد — مفيش كاش ولا
     * حالة في الذاكرة، عشان يشتغل صح مع أكتر من عملية PHP.
     */
    private static function notify(string $sig): void
    {
        $max = max(1, (int) config('dahshan.alerts.max_per_hour', 6));
        $sent = (int) (DB::selectOne(
            'SELECT COUNT(*) n FROM error_alerts WHERE notified_at >= ?',
            [gmdate('Y-m-d H:i:s', time() - 3600)]
        )->n ?? 0);
        if ($sent >= $max) {
            return;   // الهدوء أهم من الاكتمال — الصف متسجّل والعدّاد شغّال
        }

        DB::update('UPDATE error_alerts SET notified_at = ? WHERE signature = ?', [WireTime::nowDb(), $sig]);

        try {
            SendErrorAlert::dispatch($sig);
        } catch (Throwable) {
            // الطابور مش شغّال؟ التنبيه اتسجّل في الجدول وخلاص
        }
    }
}
