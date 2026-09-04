<?php

declare(strict_types=1);

namespace App\Wire;

use App\Support\Vocab;
use App\Support\WireTime;
use Illuminate\Support\Facades\DB;

/**
 * طبقة السلك للأوردر — نقل حرفي لـ api/ser_orders.php.
 *
 * المرجع الملزم: aldahshan/db/VOCAB.md بند 4 (كائن الأوردر) وبند 5 (الطرد).
 *
 * 🔴 **دي أخطر نقطة في الترحيل كله.** كائن الأوردر فيه 58 مفتاح، والواجهات
 * الأربعة وتطبيق الطيار Flutter بيقروا منها مفاتيح بعينها. أي مفتاح ناقص أو
 * مسمّى غلط = شاشة فاضية أو رقم غلط — من غير أي خطأ في الكونسول.
 *
 * القواعد اللي ممنوع تتكسر:
 *  • كل المفاتيح موجودة **دايمًا** حتى لو القيمة null.
 *  • الحالات عربي حرفي، التوقيتات ISO-8601 UTC بميلي ثانية.
 *  • `cancelReason` مش `cancelledReason` — اللسان القديم
 *    (tiar_branch.html سطر 3096). الاسم الغلط هنا = سبب الإلغاء بيختفي.
 *  • `zoneName` بيقع على `_zone_name` من الـjoin لو العمود المخزّن فاضي.
 */
final class OrderWire
{
    /** SELECT الموحّد لصفوف الأوردرات مع الحقول المشتقة */
    public static function baseSql(): string
    {
        return "SELECT o.*,
                       b.name       AS _branch_name,
                       b.code       AS _branch_code,
                       ob.name      AS _origin_branch_name,
                       z.area_name  AS _sender_zone_name
                FROM orders o
                JOIN branches b ON b.id = o.branch_id
                LEFT JOIN branches ob ON ob.id = o.origin_branch_id
                LEFT JOIN zones z ON z.id = o.sender_zone_id";
    }

    /**
     * تحميل الطرود + الصور + النقلات + التقييمات دفعة واحدة لمجموعة أوردرات.
     * **نفس الأربع استعلامات بنفس الترتيب** — الترتيب ده اتظبط تحت اختبار
     * الحِمل (65 ألف أوردر) وأي تغيير فيه بيرجّع المشكلة.
     * بيحافظ على ترتيب $rows زي ما جت.
     */
    public static function batch(array $rows): array
    {
        if (! $rows) {
            return [];
        }

        $rows = array_map(fn ($r) => is_array($r) ? $r : (array) $r, $rows);
        $ids  = array_map(fn ($r) => (int) $r['id'], $rows);

        // ── الطرود ──
        $deliveriesByOrder = [];
        $deliveryIds = [];
        $deliveryRows = DB::select(
            'SELECT d.*, z.area_name AS _zone_name
               FROM order_deliveries d
               LEFT JOIN zones z ON z.id = d.zone_id
              WHERE d.order_id IN (' . self::ph($ids) . ')
              ORDER BY d.order_id, d.parcel_no, d.id',
            $ids
        );
        foreach ($deliveryRows as $d) {
            $d = (array) $d;
            $deliveriesByOrder[(int) $d['order_id']][] = $d;
            $deliveryIds[] = (int) $d['id'];
        }

        // ── صور الطرود ──
        $imagesByDelivery = [];
        if ($deliveryIds) {
            foreach (DB::select(
                'SELECT delivery_id, url FROM order_images
                  WHERE delivery_id IN (' . self::ph($deliveryIds) . ') ORDER BY id',
                $deliveryIds
            ) as $im) {
                $im = (array) $im;
                $imagesByDelivery[(int) $im['delivery_id']][] = $im['url'];
            }
        }

        // ── سجل النقلات بين الطيارين (بأسماءهم) ──
        $transfersByOrder = [];
        foreach (DB::select(
            'SELECT t.*, pf.name AS _from_name, pt.name AS _to_name
               FROM order_transfers t
               LEFT JOIN pilots pf ON pf.id = t.from_pilot_id
               LEFT JOIN pilots pt ON pt.id = t.to_pilot_id
              WHERE t.order_id IN (' . self::ph($ids) . ')
              ORDER BY t.order_id, t.transferred_at, t.id',
            $ids
        ) as $t) {
            $t = (array) $t;
            $transfersByOrder[(int) $t['order_id']][] = $t;
        }

        // ── التقييمات ──
        $ratingsByOrder = [];
        foreach (DB::select(
            'SELECT * FROM order_ratings WHERE order_id IN (' . self::ph($ids) . ')
              ORDER BY order_id, rated_at, id',
            $ids
        ) as $rt) {
            $rt = (array) $rt;
            $ratingsByOrder[(int) $rt['order_id']][] = $rt;
        }

        $out = [];
        foreach ($rows as $r) {
            $oid = (int) $r['id'];
            $out[] = self::order(
                $r,
                $deliveriesByOrder[$oid] ?? [],
                $imagesByDelivery,
                $transfersByOrder[$oid] ?? [],
                $ratingsByOrder[$oid] ?? []
            );
        }

        return $out;
    }

    /** أوردر واحد كامل بالـid الرقمي أو legacy_key — null لو مش موجود */
    public static function full(int|string $idOrKey): ?array
    {
        $byId = is_int($idOrKey) || ctype_digit((string) $idOrKey);
        $sql  = self::baseSql() . ' WHERE ' . ($byId ? 'o.id = ?' : 'o.legacy_key = ?') . ' LIMIT 1';

        $row = DB::select($sql, [$idOrKey])[0] ?? null;
        if (! $row) {
            return null;
        }

        return self::batch([$row])[0] ?? null;
    }

    /** كائن الطرد — VOCAB بند 5 */
    public static function delivery(array $d, array $imagesByDelivery): array
    {
        return [
            'id'             => (int) $d['id'],
            'key'            => $d['legacy_key'],
            'parcelNo'       => (int) $d['parcel_no'],
            'receiverId'     => $d['receiver_id'] !== null ? (int) $d['receiver_id'] : null,
            'receiverName'   => $d['receiver_name'] ?? '',
            'receiverPhone'  => $d['receiver_phone'] ?? '',
            'receiverPhone2' => $d['receiver_phone2'] ?? '',
            'zoneId'         => $d['zone_id'] !== null ? (int) $d['zone_id'] : null,
            'zoneName'       => $d['zone_name'] ?: ($d['_zone_name'] ?? ''),
            'zonePrice'      => (float) $d['zone_price'],
            'orderPrice'     => (float) $d['order_price'],
            'address'        => $d['address'] ?? '',
            'note'           => $d['note'] ?? '',
            /* بيانات المستلم مكتوبة على صورة الريسيت مش مدخلة يدوي —
               الشارة دي بتقول للفرع والطيار يبصوا على الصورة. العمود
               موجود في المخطط من الأصل والواجهات بتقراه، والسلك مكانش
               بيبعته فالشارة عمرها ما ظهرت. */
            'receiverFromReceipt' => (bool) ($d['receiver_from_receipt'] ?? false),
            // دبوس التسليم — خريطة التتبّع بتحتاجه عشان ترسم نقطة الوصول
            'lat'            => isset($d['lat']) && $d['lat'] !== null ? (float) $d['lat'] : null,
            'lng'            => isset($d['lng']) && $d['lng'] !== null ? (float) $d['lng'] : null,
            'status'         => Vocab::parcelStatusToAr($d['status']),
            'images'         => $imagesByDelivery[(int) $d['id']] ?? [],
        ];
    }

    /** كائن الأوردر الكامل — VOCAB بند 4 */
    public static function order(
        array $o,
        array $deliveries,
        array $imagesByDelivery,
        array $transfers,
        array $ratings = [],
    ): array {
        // ratings بالشكل القديم: {store:{...}, customer:{...}, support:{...}}
        $ratingsWire = null;
        foreach ($ratings as $rt) {
            $ratingsWire[$rt['rater']] = [
                'stars'  => (int) $rt['stars'],
                'note'   => $rt['note'] ?? '',
                'action' => $rt['action'],
                'by'     => $rt['rated_by'] ?? '',
                'byId'   => $rt['rated_by_id'],
                'at'     => WireTime::toWire($rt['rated_at']),
            ];
        }

        $history = [];
        foreach ($transfers as $t) {
            $history[] = [
                'fromId'      => $t['from_pilot_id'] !== null ? (int) $t['from_pilot_id'] : null,
                'fromName'    => $t['_from_name'] ?? '',
                'toId'        => (int) $t['to_pilot_id'],
                'toName'      => $t['_to_name'] ?? '',
                'fromShiftId' => $t['from_shift_id'] !== null ? (int) $t['from_shift_id'] : null,
                'toShiftId'   => $t['to_shift_id'] !== null ? (int) $t['to_shift_id'] : null,
                'at'          => WireTime::toWire($t['transferred_at']),
                'by'          => $t['transferred_by'] ?? '',
            ];
        }
        $last = $history ? $history[count($history) - 1] : null;

        return [
            'id'  => (int) $o['id'],
            'key' => $o['legacy_key'],

            'orderNum' => $o['order_num'],
            'qrCode'   => $o['qr_code'],

            'senderId'      => $o['sender_id'] !== null ? (int) $o['sender_id'] : null,
            'senderName'    => $o['sender_name'] ?? '',
            'senderPhone'   => $o['sender_phone'] ?? '',
            'senderPhone2'  => $o['sender_phone2'] ?? '',
            'senderAddress' => $o['sender_address'] ?? '',
            'senderLat'     => $o['sender_lat'] !== null ? (float) $o['sender_lat'] : null,
            'senderLng'     => $o['sender_lng'] !== null ? (float) $o['sender_lng'] : null,
            'geoSender'     => $o['sender_lat'] !== null && $o['sender_lng'] !== null,
            'senderZoneId'   => $o['sender_zone_id'] !== null ? (int) $o['sender_zone_id'] : null,
            'senderZoneName' => $o['_sender_zone_name'] ?? null,

            'branchId'   => (int) $o['branch_id'],
            'branchName' => $o['_branch_name'] ?? '',
            /* الفرع اللي أنشأ الأوردر — بيفضل ثابت مهما الأوردر اتنقل.
               الواجهة بتعرضه لما يختلف عن branchId عشان يبان إن الأوردر
               ده أصله من فرع تاني. */
            'originBranchId'   => isset($o['origin_branch_id']) && $o['origin_branch_id'] !== null
                ? (int) $o['origin_branch_id'] : null,
            'originBranchName' => $o['_origin_branch_name'] ?? null,
            'notes'      => $o['notes'] ?? '',

            'deliveries' => array_map(
                fn ($d) => self::delivery($d, $imagesByDelivery),
                $deliveries
            ),
            'totalDeliveryPrice' => (float) $o['total_delivery_price'],
            'storePrepaid'       => $o['store_prepaid'] !== null ? (float) $o['store_prepaid'] : 0.0,
            'storePrepaidNote'   => $o['store_prepaid_note'] ?? '',
            'goodsValue'         => $o['goods_value'] !== null ? (float) $o['goods_value'] : 0.0,
            'walletUsed'         => $o['wallet_used'] !== null ? (float) $o['wallet_used'] : 0.0,

            'status'      => Vocab::statusToAr($o['status']),
            'statusSince' => WireTime::toWire($o['status_since']),
            'prevStatus'  => Vocab::statusToAr($o['prev_status']),
            'createdAt'   => WireTime::toWire($o['created_at']),

            'addedBy'       => $o['added_by'] ?? '',
            'addedByRole'   => Vocab::roleToAr($o['added_by_role']),
            'source'        => $o['source'] ?? '',
            'customerId'    => $o['customer_id'] !== null ? (int) $o['customer_id'] : null,
            'customerName'  => $o['customer_name'] ?? '',
            'customerPhone' => $o['customer_phone'] ?? '',
            'orderKind'     => $o['order_kind'] ?? '',
            'paymentMethod' => $o['payment_method'] ?? '',
            'piecesCount'   => $o['pieces_count'] !== null ? (int) $o['pieces_count'] : null,

            'pilotId'   => $o['pilot_id'] !== null ? (int) $o['pilot_id'] : null,
            'pilotName' => $o['pilot_name'] ?? '',
            'shiftId'   => $o['shift_id'] !== null ? (int) $o['shift_id'] : null,
            'currentPilotSince' => WireTime::toWire($o['current_pilot_since']),

            'receivedAt'    => WireTime::toWire($o['received_at']),
            'tripStartedAt' => WireTime::toWire($o['trip_started_at']),
            'handedOverAt'  => WireTime::toWire($o['handed_over_at'] ?? null),
            'handedOverBy'  => $o['handed_over_by'] ?? null,
            'deliveredAt'   => WireTime::toWire($o['delivered_at']),
            'moneySettled'  => (bool) $o['money_settled'],

            'undeliveredAt'     => WireTime::toWire($o['undelivered_at']),
            'undeliveredReason' => $o['undelivered_reason'],
            // 💵 مين دفع توصيل المرتجع: receiver/sender/none (طلب 2026-09-02)
            'undeliveredFareBy' => $o['undelivered_fare_by'] ?? null,
            'cancelledAt'       => WireTime::toWire($o['cancelled_at']),
            'cancelledBy'       => $o['cancelled_by'],
            // ⚠️ cancelReason مش cancelledReason — اللسان القديم
            'cancelReason'      => $o['cancelled_reason'],

            'returnStatus' => $o['return_status'],
            'returnReason' => $o['return_reason'],

            'splitFrom' => $o['split_from_id'] !== null ? (int) $o['split_from_id'] : null,

            'transferredFrom'     => $last ? $last['fromId'] : null,
            'transferredFromName' => $last ? $last['fromName'] : null,
            'transferredAt'       => $last ? $last['at'] : null,
            'transferCount'       => (int) $o['transfer_count'],
            'transferHistory'     => $history,
            'ratings'             => $ratingsWire,
        ];
    }

    /** @param int[] $ids */
    private static function ph(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }
}
