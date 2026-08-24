<?php

declare(strict_types=1);

namespace App\Wire;

use App\Support\WireTime;

/**
 * طبقة السلك للدعم والإعدادات — نقل حرفي لـ support_complaint_wire /
 * support_zone_request_wire / support_partner_wire من api/routes/support.php.
 *
 * ⚠️ ملاحظة عامة على الملف ده: الحالات هنا **إنجليزية على السلك** مش عربية.
 * الشكوى بترجع `open` / `resolved`، وطلب المنطقة بيرجع `pending` / `added`
 * / `rejected` زي ما هي في القاعدة بالظبط. الملف ده **مابيعدّيش على Vocab
 * خالص** — لأن اللسان القديم لقوايم الدعم إنجليزي، ولوحة الكول سنتر بتقارن
 * `c.status === 'open'` نصًا. أي ترجمة هنا = فلترة اللوحة بتوقف بالصمت.
 *
 * قواعد ملزمة (نفس قواعد CoreWire):
 *  • كل مفتاح موجود دايمًا حتى لو القيمة null.
 *  • أسماء المفاتيح مش انعكاس لأسماء الأعمدة — `customer` مش `customerName`،
 *    و`count` مش `requestCount`، و`desc` مش `description`. الأسماء دي هي
 *    اللي الواجهات بتقراها.
 */
final class SupportWire
{
    /** بيقبل صف كـarray أو stdClass ويرجّع مصفوفة — DB::select بيرجّع stdClass */
    public static function row(array|object $row): array
    {
        return is_array($row) ? $row : (array) $row;
    }

    /* ── شكوى الكول سنتر ─────────────────────────────────────────
       الحقول المخزّنة اللي **مش** بتطلع على السلك: مفيش. الجدول كله بيتقرا
       بـ SELECT * والدالة بتاخد منه المفاتيح دي بالترتيب ده. */
    public static function complaint(array|object $row): array
    {
        $r = self::row($row);

        return [
            'id'  => (int) $r['id'],
            'key' => $r['legacy_key'],
            'orderId'  => $r['order_id'] !== null ? (int) $r['order_id'] : null,
            // رقم الأوردر زي ما الموظف كتبه — ممكن ما يطابقش أوردر فعلي،
            // عشان كده orderId ممكن يبقى null والـorderNum مليان
            'orderNum' => $r['order_num'],
            'customer' => $r['customer_name'],
            'phone'    => $r['phone'],
            'type'     => $r['type'],
            'details'  => $r['details'],
            'status'   => $r['status'],          // open / resolved — إنجليزي
            'resolution' => $r['resolution'],
            'createdBy'  => $r['created_by'],
            'createdAt'  => WireTime::toWire($r['created_at']),
            'resolvedBy' => $r['resolved_by'],
            'resolvedAt' => WireTime::toWire($r['resolved_at']),
        ];
    }

    /* ── طلب منطقة غير معرفة ─────────────────────────────────────
       الأعمدة `first_order_id` و`created_at` موجودة في الجدول بس **مش**
       على السلك — الأصل مابيطلعهاش، فمابنضيفهاش. */
    public static function zoneRequest(array|object $row): array
    {
        $r = self::row($row);

        return [
            'id'  => (int) $r['id'],
            'key' => $r['legacy_key'],
            'areaName'  => $r['area_name'],
            // مفيش join على branches — الاسم مش على السلك، الـid بس
            'branchId'  => $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
            'lastPrice' => $r['last_price'] !== null ? (float) $r['last_price'] : null,
            'count'     => (int) $r['request_count'],
            'status'    => $r['status'],         // pending / added / rejected
            'firstAt'   => WireTime::toWire($r['first_at']),
            'lastAt'    => WireTime::toWire($r['last_at']),
            'lastOrderNum' => $r['last_order_num'],
            'requestedBy'  => $r['requested_by'],
            'lastBy'       => $r['last_by'],
        ];
    }

    /* ── شريك الموقع التسويقي ────────────────────────────────────
       `is_active` و`created_at` مش على السلك — الأصل مابيطلعهمش. */
    public static function partner(array|object $row): array
    {
        $r = self::row($row);

        return [
            'id'    => (int) $r['id'],
            'key'   => $r['legacy_key'],
            'name'  => $r['name'],
            'desc'  => $r['description'],   // desc مش description — اللسان القديم
            'url'   => $r['url'],
            'logo'  => $r['logo_url'],      // logo مش logoUrl
            'order' => (int) $r['sort_order'],
        ];
    }
}
