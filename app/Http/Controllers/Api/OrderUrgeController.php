<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\OrderUrged;
use App\Exceptions\ApiException;
use App\Support\Actor;
use App\Support\ApiResponse;
use App\Support\WireTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ⏰ «الأوردر جاهز — برجاء الاستعجال» من بوابة المحل.
 *
 * ═══ لمين بيروح التنبيه ═══
 * الأوردر لسه في المكتب (`pilot_id` فاضي) ⇒ **الفرع**.
 * متحمّل على طيار ⇒ **الطيار** — والفرع بيشوفه كمان، لأنه هو اللي
 * بيتصرّف لو الطيار متأخر أو مش راد.
 *
 * ═══ الحد الزمني ═══
 * استعجال واحد كل **4 دقايق** لكل أوردر (قرار صاحب النظام). الحد
 * محسوب من **آخر استعجال متسجّل في القاعدة** مش من حالة في الذاكرة —
 * عشان المحل مايقدرش يلفّ عليه بفتح التطبيق في تابين، والحد يفضل
 * صحيح مع أكتر من عملية PHP.
 *
 * ═══ ليه الحد أصلًا ═══
 * من غيره التنبيه بيتحوّل لضجيج: محل بيدوس 20 مرة في دقيقة بيخلّي
 * الطيار يقفل الإشعارات، وساعتها الاستعجال الحقيقي مش هيوصل. الحد
 * بيحمي قيمة التنبيه نفسه.
 */
class OrderUrgeController
{
    /** الحد الأدنى بين استعجالين على نفس الأوردر — قرار صاحب النظام */
    private const COOLDOWN_SECONDS = 240;

    /**
     * POST /api/orders/{id}/urge — {note?}
     * الأدوار: store (صاحب الأوردر) · admin · branch.
     */
    public function urge(Request $request, string $id): JsonResponse
    {
        $actor   = $request->actorOrFail();
        $orderId = (int) $id;

        $order = $this->orderInScope($actor, $orderId);

        /* الأوردر المنتهي مايتستعجلش — التنبيه ساعتها ضجيج خالص */
        if (! in_array($order['status'], ['processing', 'delivering', 'postponed'], true)) {
            throw new ApiException('الطلب ده مش جاري — مفيش استعجال');
        }

        // ── الحد الزمني ──
        $last = DB::selectOne(
            'SELECT urged_at FROM order_urges WHERE order_id = ? ORDER BY id DESC LIMIT 1',
            [$orderId]
        );
        if ($last) {
            $elapsed = time() - (int) strtotime($last->urged_at . ' UTC');
            if ($elapsed < self::COOLDOWN_SECONDS) {
                $wait = (int) ceil((self::COOLDOWN_SECONDS - $elapsed) / 60);

                throw new ApiException(
                    'استعجلت الطلب ده من شوية — استنى ' . $wait . ' دقيقة كمان',
                    429
                );
            }
        }

        $pilotId  = $order['pilot_id'] !== null ? (int) $order['pilot_id'] : null;
        $branchId = (int) $order['branch_id'];
        $target   = $pilotId ? 'pilot' : 'branch';
        $note     = mb_substr(trim((string) $request->input('note', '')), 0, 190);

        $storeName = trim((string) ($order['sender_name'] ?? '')) ?: ($actor->name ?: $actor->username);

        try {
            [$urgeId, $times] = DB::transaction(function () use (
                $orderId, $actor, $target, $branchId, $pilotId, $note
            ): array {
                DB::insert(
                    'INSERT INTO order_urges
                       (order_id, urged_by, urged_at, target, branch_id, pilot_id, note, created_at)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [$orderId, $actor->username, WireTime::nowDb(), $target,
                     $branchId, $pilotId, $note ?: null, WireTime::nowDb()]
                );

                return [
                    (int) DB::getPdo()->lastInsertId(),
                    (int) (DB::selectOne(
                        'SELECT COUNT(*) n FROM order_urges WHERE order_id = ?', [$orderId]
                    )->n ?? 1),
                ];
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر إرسال الاستعجال', 500);
        }

        /* البثّ برّه المعاملة — ShouldDispatchAfterCommit بيأجّله لبعد
           التثبيت لوحده، بس النداء هنا بيفضل برّه عشان أي فشل في البثّ
           مايرجّعش الصف المتسجّل. */
        try {
            OrderUrged::dispatch(
                $orderId, (string) $order['order_num'], $branchId, $pilotId,
                $storeName, $times, $note, $urgeId
            );
        } catch (Throwable $e) {
            report($e);   // الاستعجال متسجّل — اللوحة هتشوفه في الاستطلاع
        }

        return ApiResponse::out([
            'ok'      => true,
            'urgeId'  => $urgeId,
            'target'  => $target,
            'times'   => $times,
            'message' => $target === 'pilot'
                ? 'التنبيه راح للطيار ✓'
                : 'التنبيه راح للفرع ✓',
        ]);
    }

    /**
     * GET /api/orders/urges?since= — الاستعجالات اللي تخصّني.
     * الفرع بياخد بتاعة فرعه، والطيار بتاعته هو.
     */
    public function list(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        $sql  = 'SELECT u.*, o.order_num FROM order_urges u
                 JOIN orders o ON o.id = u.order_id
                 WHERE u.urged_at >= ?';
        $args = [gmdate('Y-m-d H:i:s', time() - 12 * 3600)];   // آخر 12 ساعة

        if ($actor->role === 'branch') {
            $sql .= ' AND u.branch_id = ?';
            $args[] = (int) ($actor->branchId ?? 0);
        } elseif ($actor->role === 'pilot') {
            $pid = DB::selectOne('SELECT pilot_id FROM users WHERE id = ?', [$actor->userId])->pilot_id ?? null;
            if (! $pid) {
                return ApiResponse::out(['ok' => true, 'items' => []]);
            }
            $sql .= ' AND u.pilot_id = ?';
            $args[] = (int) $pid;
        }

        $rows = DB::select($sql . ' ORDER BY u.id DESC LIMIT 100', $args);

        return ApiResponse::out([
            'ok'    => true,
            'items' => array_map(fn ($r) => [
                'id'        => (int) $r->id,
                'orderId'   => (int) $r->order_id,
                'orderNum'  => $r->order_num,
                'urgedBy'   => $r->urged_by,
                'urgedAt'   => WireTime::toWire($r->urged_at),
                'target'    => $r->target,
                'branchId'  => $r->branch_id !== null ? (int) $r->branch_id : null,
                'pilotId'   => $r->pilot_id !== null ? (int) $r->pilot_id : null,
                'note'      => $r->note,
                'seenAt'    => WireTime::toWire($r->seen_at),
            ], $rows),
        ]);
    }

    /** POST /api/orders/urges/seen — {ids:[]} تعليم التنبيهات مقروءة */
    public function seen(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $ids   = $request->input('ids');
        $ids   = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
        if (! $ids) {
            return ApiResponse::ok();
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        DB::update(
            "UPDATE order_urges SET seen_at = ?, seen_by = ? WHERE id IN ({$ph}) AND seen_at IS NULL",
            array_merge([WireTime::nowDb(), $actor->username], $ids)
        );

        return ApiResponse::ok();
    }

    /**
     * 🔒 المحل بيستعجل **أوردراته هو بس**.
     *
     * أوردر المحل متعلّم بـ`added_by = اسم مستخدم المحل` (نفس القاعدة
     * اللي بوابة المحل بتفلتر بيها قايمتها). الإدارة ومشرف الفرع
     * بيعدّوا على أوردرات فرعهم.
     */
    private function orderInScope(Actor $actor, int $orderId): array
    {
        $row = DB::selectOne(
            'SELECT id, order_num, status, branch_id, pilot_id, added_by, sender_name
               FROM orders WHERE id = ?',
            [$orderId]
        );
        if (! $row) {
            throw ApiException::notFound('الطلب غير موجود');
        }
        $o = (array) $row;

        if ($actor->role === 'store' && (string) $o['added_by'] !== $actor->username) {
            throw ApiException::forbidden('الطلب ده مش بتاع محلك');
        }
        if ($actor->role === 'branch' && (int) $o['branch_id'] !== (int) ($actor->branchId ?? -1)) {
            throw ApiException::forbidden('الطلب ده مش في فرعك');
        }

        return $o;
    }
}
