<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * غلاف قوايم الاستطلاع الموحد — CONVENTIONS.md بند 5.
 *
 *     { ok: true, serverNow: <ms>, changed: true,  items: [...] }
 *     { ok: true, serverNow: <ms>, changed: false }          ← مفيش جديد
 *
 * `serverNow` بيتاخد من السيرفر عشان الواجهة تبعته تاني في `?since=`
 * ومتعتمدش على ساعة الجهاز. و`changed:false` **بترجع من غير مفتاح items
 * خالص** — الواجهات بتفحص `changed` قبل ما تلمس `items`، والفرق ده هو
 * اللي بيوفّر الميجابايتات في الاستطلاع كل 8 ثواني.
 */
final class PollableList
{
    public static function serverNowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /** قايمة فيها بيانات */
    public static function items(array $items, ?int $serverNow = null): JsonResponse
    {
        return ApiResponse::out([
            'ok'        => true,
            'serverNow' => $serverNow ?? self::serverNowMs(),
            'changed'   => true,
            'items'     => $items,
        ]);
    }

    /** مفيش تغيير منذ ?since — من غير items */
    public static function unchanged(?int $serverNow = null): JsonResponse
    {
        return ApiResponse::out([
            'ok'        => true,
            'serverNow' => $serverNow ?? self::serverNowMs(),
            'changed'   => false,
        ]);
    }
}
