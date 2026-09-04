<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Support\ApiResponse;
use App\Support\WireTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 🚨 لوحة أعطال الإنتاج — الوجه المرئي لجدول `error_alerts`.
 *
 * الواتساب طبقة تنبيه، والجدول ده هو **مصدر الحقيقة**. حتى لو الإرسال
 * التلقائي مقفول (الوضع الحالي: `WHATSAPP_PROVIDER` مش متظبط) الأعطال
 * بتفضل ظاهرة هنا — عشان مايتكررش اللي حصل مع باج تطبيق العميل: مكتوب في
 * اللوج من أول لحظة ومحدش بيفتح اللوج.
 *
 * 🔒 الإدارة بس. التفاصيل فيها مسارات ملفات ومكدس — مش حاجة تتعرض لموظف.
 */
class AlertsController
{
    /** GET /api/alerts?resolved=0|1&limit= */
    public function index(Request $request): JsonResponse
    {
        $request->actorOrFail();

        $resolved = $request->query('resolved');
        $where = $resolved === '1' ? 'WHERE resolved_at IS NOT NULL'
            : ($resolved === '0' ? 'WHERE resolved_at IS NULL' : '');
        $limit = min(200, max(1, (int) ($request->query('limit') ?: 100)));

        $rows = DB::select(
            "SELECT * FROM error_alerts {$where} ORDER BY (resolved_at IS NULL) DESC, last_seen_at DESC LIMIT {$limit}"
        );

        return ApiResponse::out([
            'ok' => true,
            'openCount' => (int) (DB::selectOne('SELECT COUNT(*) n FROM error_alerts WHERE resolved_at IS NULL')->n ?? 0),
            'items' => array_map(fn ($r) => self::wire((array) $r), $rows),
        ]);
    }

    /** POST /api/alerts/{id}/resolve — علّم العطل إنه اتصلّح */
    public function resolve(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();

        $n = DB::update(
            'UPDATE error_alerts SET resolved_at = ?, resolved_by = ? WHERE id = ? AND resolved_at IS NULL',
            [WireTime::nowDb(), $actor->username, (int) $id]
        );
        if (! $n) {
            throw ApiException::notFound('العطل غير موجود أو متعلّم متصلّح بالفعل');
        }

        return ApiResponse::ok();
    }

    /**
     * POST /api/alerts/{id}/reopen — رجّعه للقايمة النشطة.
     * لازم يكون موجود عشان العطل اللي اترجع تاني بعد «إصلاح» يبان.
     */
    public function reopen(Request $request, string $id): JsonResponse
    {
        $request->actorOrFail();

        DB::update('UPDATE error_alerts SET resolved_at = NULL, resolved_by = NULL WHERE id = ?', [(int) $id]);

        return ApiResponse::ok();
    }

    private static function wire(array $r): array
    {
        return [
            'id'           => (int) $r['id'],
            'signature'    => substr((string) $r['signature'], 0, 12),
            'title'        => $r['title'],
            'detail'       => $r['detail'],
            'url'          => $r['url'],
            'method'       => $r['method'],
            'actor'        => $r['actor'],
            'occurrences'  => (int) $r['occurrences'],
            'firstSeenAt'  => WireTime::toWire($r['first_seen_at']),
            'lastSeenAt'   => WireTime::toWire($r['last_seen_at']),
            'notifiedAt'   => WireTime::toWire($r['notified_at']),
            'notifyStatus' => $r['notify_status'],
            'resolvedAt'   => WireTime::toWire($r['resolved_at']),
            'resolvedBy'   => $r['resolved_by'],
        ];
    }
}
