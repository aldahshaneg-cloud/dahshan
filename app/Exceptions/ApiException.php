<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * المقابل لـ fail() في النظام الحالي — بس كاستثناء بدل exit.
 *
 * ليه استثناء مش return: fail() القديمة كانت بتعمل exit جوه معاملة مفتوحة
 * في أماكن كتير، والكود القديم كان بيعمل rollBack() يدوي قبلها كل مرة (ولو
 * نسي، المعاملة كانت بتتقفل بالـ exit). في لارافل الاستثناء بيخلي
 * DB::transaction() تعمل rollback لوحدها، فالنسيان مابيبقاش ممكن.
 *
 * الرسالة **عربية دايمًا** — دي رسالة بتوصل للمستخدم النهائي في الواجهة،
 * مش نص تقني. نفس نصوص النظام القديم حرفيًا.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        string $messageArabic,
        private readonly int $status = 400,
        ?Throwable $previous = null,
    ) {
        parent::__construct($messageArabic, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(): JsonResponse
    {
        return ApiResponse::fail($this->getMessage(), $this->status);
    }

    /* ── اختصارات للحالات المتكررة في النظام ───────────────────── */

    public static function unauthenticated(): self
    {
        return new self('يجب تسجيل الدخول أولًا', 401);
    }

    public static function forbidden(string $message = 'غير مسموح لك بهذه العملية'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message): self
    {
        return new self($message, 404);
    }

    public static function blocked(): self
    {
        return new self('هذا الحساب موقوف — تواصل مع الإدارة', 403);
    }
}
