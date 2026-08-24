<?php

declare(strict_types=1);

namespace App\Services\Auth;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * خانق الدخول — نقل حرفي لمنطق api/routes/auth.php.
 *
 * **عمدًا مش RateLimiter بتاع لارافل**: الخانق الحالي بيتخزّن في جدول
 * `login_attempts` بمفتاح (ip, username)، والاختبارات بتفحص الجدول ده
 * مباشرةً، والحالة لازم تنجو من إعادة تشغيل السيرفر (RateLimiter الافتراضي
 * على الكاش، وكاش الملفات بيتمسح). نفس الجدول = نفس السلوك = نفس الأدلة.
 *
 * القاعدة: 8 محاولات فاشلة في نافذة 15 دقيقة → قفل 15 دقيقة.
 */
final class LoginThrottle
{
    public function __construct(
        private readonly int $maxFails = 8,
        private readonly int $windowMin = 15,
        private readonly int $lockMin = 15,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            (int) config('dahshan.login.max_fails', 8),
            (int) config('dahshan.login.window_min', 15),
            (int) config('dahshan.login.lock_min', 15),
        );
    }

    /** هل المفتاح (ip+username) مقفول دلوقتي؟ */
    public function isLocked(string $ip, string $username, DateTimeImmutable $now): bool
    {
        $row = DB::table('login_attempts')
            ->where('ip', $ip)
            ->where('username', $username)
            ->first(['locked_until']);

        if (! $row || $row->locked_until === null) {
            return false;
        }

        return new DateTimeImmutable($row->locked_until, new DateTimeZone('UTC')) > $now;
    }

    /** بيسجّل فشل ويقفل لو عدّى الحد — نفس منطق auth_register_login_fail() */
    public function registerFailure(string $ip, string $username, DateTimeImmutable $now): void
    {
        $nowS        = $now->format('Y-m-d H:i:s');
        $windowStart = $now->modify('-' . $this->windowMin . ' minutes');
        $windowS     = $windowStart->format('Y-m-d H:i:s');

        $row = DB::table('login_attempts')
            ->where('ip', $ip)
            ->where('username', $username)
            ->first(['fail_count', 'first_fail']);

        // لو أول فشل كان أقدم من النافذة، نبدأ عدّ جديد
        $count = ($row && new DateTimeImmutable($row->first_fail, new DateTimeZone('UTC')) >= $windowStart)
            ? (int) $row->fail_count + 1
            : 1;

        $lockedUntil = $count >= $this->maxFails
            ? $now->modify('+' . $this->lockMin . ' minutes')->format('Y-m-d H:i:s')
            : null;

        DB::statement(
            'INSERT INTO login_attempts (ip, username, fail_count, first_fail, last_fail, locked_until)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               fail_count   = IF(first_fail >= ?, fail_count + 1, 1),
               first_fail   = IF(first_fail >= ?, first_fail, ?),
               last_fail    = ?,
               locked_until = ?',
            [$ip, $username, $count, $nowS, $nowS, $lockedUntil,
             $windowS, $windowS, $nowS, $nowS, $lockedUntil]
        );
    }

    /** نجاح الدخول — بنصفّر عدّاد الفشل للمفتاح ده */
    public function clear(string $ip, string $username): void
    {
        DB::table('login_attempts')
            ->where('ip', $ip)
            ->where('username', $username)
            ->delete();
    }
}
