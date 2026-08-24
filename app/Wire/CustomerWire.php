<?php

declare(strict_types=1);

namespace App\Wire;

use App\Support\WireTime;

/**
 * طبقة السلك لعملاء التطبيق — نقل حرفي لـ customers_wire()
 * و customers_address_wire() و customers_receiver_wire()
 * في api/routes/customers.php.
 *
 * ليه كلاس ساكن على مصفوفات مش JsonResource: نفس سبب CoreWire — الأصل دوال
 * نقية بتحوّل صف قاعدة لمصفوفة، وأي طبقة بينهم فرصة لاختلاف صامت في
 * المفاتيح أو ترتيبها.
 *
 * ⚠️ ملاحظتان على العقد لازم يفضلوا زي ما هما:
 *  • `name` و `displayNameAr` **الاتنين** بيطلعوا من نفس العمود
 *    `display_name`. التكرار ده مقصود في اللسان القديم (لوحة العملاء
 *    بتقرا `name`، وشاشات تانية بتقرا `displayNameAr`) — حذف أي واحد
 *    فيهم بيفضّي عمود في واجهة.
 *  • `defaultZoneName` و `defaultBranchName` بيقعوا على أسماء الأعمدة
 *    المشتقة من الـjoin (`zone_name` / `branch_name`) — الأسماء دي جزء
 *    من عقد طبقة السلك، والاستعلام في الكنترولر لازم يسمّيهم كده بالظبط.
 */
final class CustomerWire
{
    /** بيقبل صف كـarray أو stdClass ويرجّع مصفوفة — DB::select بيرجّع stdClass */
    public static function row(array|object $row): array
    {
        return is_array($row) ? $row : (array) $row;
    }

    /* ── العميل — مع أبنائه (العناوين والمستلمين المحفوظين) ────────
       الأبناء بيتبعتوا **متسلسلين جاهزين** مش صفوف خام، عشان الدالة دي
       تفضل نقية زي الأصل ومتعملش استعلامات جوّاها. */
    public static function customer(array|object $row, array $addresses = [], array $receivers = []): array
    {
        $r = self::row($row);

        // نفس نمط «id رقمي + مفتاح Firebase القديم» بتاع باقي الكيانات
        return CoreWire::wireId($r) + [
            'name'              => $r['display_name'],
            'displayNameAr'     => $r['display_name'],
            'email'             => $r['email'],
            'photoUrl'          => $r['photo_url'],
            'phone1'            => $r['phone1'],
            'phone2'            => $r['phone2'],
            'address'           => $r['address'],
            'lat'               => $r['lat'] !== null ? (float) $r['lat'] : null,
            'lng'               => $r['lng'] !== null ? (float) $r['lng'] : null,
            'defaultZoneId'     => $r['default_zone_id'] !== null ? (int) $r['default_zone_id'] : null,
            'defaultZoneName'   => $r['zone_name'] ?? null,
            'defaultBranchId'   => $r['default_branch_id'] !== null ? (int) $r['default_branch_id'] : null,
            'defaultBranchName' => $r['branch_name'] ?? null,
            'profileCompleted'  => (bool) $r['profile_completed'],
            'blocked'           => (bool) $r['blocked'],
            'blockedAt'         => WireTime::toWire($r['blocked_at']),
            'blockedBy'         => $r['blocked_by'],
            'createdAt'         => WireTime::toWire($r['created_at']),
            'lastLoginAt'       => WireTime::toWire($r['last_login_at']),
            'addresses'         => $addresses,
            'savedReceivers'    => $receivers,
        ];
    }

    /* ── عنوان محفوظ في دفتر العميل ──────────────────────────────
       مفيش `key` هنا — الأبناء دول اتولدوا بعد الترحيل من Firebase
       فمالهمش legacy_key أصلًا، والأصل مابيطلعوش. */
    public static function address(array|object $row): array
    {
        $r = self::row($row);

        return [
            'id'          => (int) $r['id'],
            'label'       => $r['label'],
            'fullAddress' => $r['full_address'],
            'lat'         => $r['lat'] !== null ? (float) $r['lat'] : null,
            'lng'         => $r['lng'] !== null ? (float) $r['lng'] : null,
            'zoneId'      => $r['zone_id'] !== null ? (int) $r['zone_id'] : null,
            'branchId'    => $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
            'isDefault'   => (bool) $r['is_default'],
            'createdAt'   => WireTime::toWire($r['created_at']),
        ];
    }

    /* ── مستلم محفوظ في دفتر العميل ──────────────────────────────
       ⚠️ الجدول فيه `lat`/`lng` بس الأصل **مابيطلعهمش** على السلك.
       سايبها زي ما هي — إضافتهم تغيير في العقد مش تصليح. */
    public static function savedReceiver(array|object $row): array
    {
        $r = self::row($row);

        return [
            'id'        => (int) $r['id'],
            'name'      => $r['name'],
            'phone'     => $r['phone'],
            'phone2'    => $r['phone2'],
            'address'   => $r['address'],
            'zoneId'    => $r['zone_id'] !== null ? (int) $r['zone_id'] : null,
            'createdAt' => WireTime::toWire($r['created_at']),
        ];
    }
}
