<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * غلاف الرد الموحّد — المقابل الحرفي لـ json_out() في النظام الحالي.
 *
 * النظام القديم:
 *     function json_out($data, int $code = 200): never {
 *         http_response_code($code);
 *         header('Content-Type: application/json; charset=utf-8');
 *         echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
 *         exit;
 *     }
 *
 * العلمان دول **مش تفصيلة شكلية**: الردود كلها فيها عربي، ومن غير
 * JSON_UNESCAPED_UNICODE بيتحوّل لـ \uXXXX. الاختبارات الحالية بتمشّط
 * **نص الرد الخام** بحثًا عن أسماء وعناوين عربية (فحص الخصوصية العدائي)،
 * فأي تغيير في الترميز بيكسّرها حتى لو الـJSON صحيح منطقيًا.
 */
final class ApiResponse
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** الرد الخام بأي شكل — المقابل لـ json_out() */
    public static function out(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, [], self::JSON_FLAGS);
    }

    /** رد نجاح: {ok:true, ...} */
    public static function ok(array $extra = [], int $status = 200): JsonResponse
    {
        return self::out(['ok' => true] + $extra, $status);
    }

    /**
     * رد فشل: {ok:false, error:"رسالة عربية"} — المقابل لـ fail().
     * ملاحظة: في النظام القديم fail() بتعمل exit؛ هنا الاستخدام الطبيعي هو
     * رمي ApiException بدل ما ترجّع ده، عشان تقدر تلغي المعاملة المفتوحة.
     */
    public static function fail(string $messageArabic, int $status = 400): JsonResponse
    {
        return self::out(['ok' => false, 'error' => $messageArabic], $status);
    }
}
