<?php

declare(strict_types=1);

namespace App\Wire;

use App\Support\WireTime;

/**
 * طبقة السلك لتطبيق العميل — نقل حرفي لـ customer_wire()
 * و customer_address_wire() و customer_saved_receiver_wire()
 * في api/routes/customer_app.php.
 *
 * ⚠️ **ليه كلاس منفصل عن `CustomerWire` رغم إن الاسمين متشابهين**: الملفين
 * في الأصل دومينين مختلفين (`customers.php` = لوحة إدارة العملاء، و
 * `customer_app.php` = تطبيق العميل نفسه)، والسيريالايزرز **مش نفس الشكل**:
 *
 *  • `CustomerAppWire::customer()` فيها `uid` و`notifSeenAt` وبترجّع `''`
 *    بدل null للنصوص، ومفيهاش `addresses`/`savedReceivers`.
 *  • `CustomerAppWire::address()` فيها `zoneName`/`branchName` (من الـjoin)
 *    — و`CustomerWire::address()` مافيهاش.
 *  • `CustomerAppWire::savedReceiver()` بتطلّع `lat`/`lng` — والتانية لأ
 *    (متسجّل في تعليق `CustomerWire` إن الأصل مابيطلعهمش هناك).
 *
 * فتوحيدهم مش تنضيف — هو تغيير عقد على واجهتين مختلفتين.
 *
 * ⚠️ الفرق التاني اللي لازم يفضل: هنا الافتراضي `?? ''` مش `null`.
 * `customer.html` بيحط القيم دي في `<input value>` مباشرة، وnull بيطلع
 * "null" حرفيًا في بعض المسارات القديمة. الأصل حاطط `''` عن قصد.
 */
final class CustomerAppWire
{
    /** بيقبل صف كـarray أو stdClass ويرجّع مصفوفة — DB::select بيرجّع stdClass */
    public static function row(array|object $row): array
    {
        return is_array($row) ? $row : (array) $row;
    }

    /**
     * كائن العميل — بنفس أسماء حقول `customers/{uid}` القديمة في فايربيز.
     *
     * `id` و`key` و`uid` **التلاتة** بيطلعوا: `key`/`uid` الاتنين من
     * `legacy_key`. التكرار مقصود — التطبيق القديم كان بيقرا `uid`،
     * والنسخة الجديدة بتقرا `id`، والتخزين المحلي في المتصفح لسه فيه
     * مفاتيح متكتبة بـ`key`. حذف أي واحد فيهم بيكسّر عميل مفتوح.
     *
     * `defaultZoneName`/`defaultBranchName` بيقعوا على أسماء الأعمدة
     * المشتقة من الـjoin (`_zone_name` / `_branch_name`) — الأسماء دي
     * جزء من العقد، والاستعلام لازم يسمّيهم كده بالظبط.
     */
    public static function customer(array|object $row): array
    {
        $c = self::row($row);

        return [
            'id'  => (int) $c['id'],
            'key' => $c['legacy_key'],
            'uid' => $c['legacy_key'],
            'email'         => $c['email'] ?? '',
            'photoURL'      => $c['photo_url'] ?? '',
            'displayNameAr' => $c['display_name'] ?? '',
            'phone1'        => $c['phone1'] ?? '',
            'phone2'        => $c['phone2'] ?? '',
            'address'       => $c['address'] ?? '',
            'lat'           => $c['lat'] !== null ? (float) $c['lat'] : null,
            'lng'           => $c['lng'] !== null ? (float) $c['lng'] : null,
            'defaultZoneId'     => $c['default_zone_id'] !== null ? (int) $c['default_zone_id'] : null,
            'defaultZoneName'   => $c['_zone_name'] ?? null,
            'defaultBranchId'   => $c['default_branch_id'] !== null ? (int) $c['default_branch_id'] : null,
            'defaultBranchName' => $c['_branch_name'] ?? null,
            'profileCompleted'  => (bool) $c['profile_completed'],
            /* فتح تعديل سعر التوصيل من إدارة العملاء (طلب 2026-09-02) —
               التطبيق بيفتح خانة السعر لما تبقى true، والبوابة الحقيقية
               على السيرفر في customerDeliveryPrice */
            'canEditPrice'      => (int) ($c['can_edit_price'] ?? 0) === 1,
            'blocked'           => (bool) $c['blocked'],
            'notifSeenAt'       => WireTime::toWire($c['notif_seen_at']),
            'createdAt'         => WireTime::toWire($c['created_at']),
            'lastLoginAt'       => WireTime::toWire($c['last_login_at']),
        ];
    }

    /**
     * عنوان محفوظ في دفتر العميل.
     * `_zone_name`/`_branch_name` جايين من الـjoin في `customer_addresses_fetch`.
     */
    public static function address(array|object $row): array
    {
        $a = self::row($row);

        return [
            'id'          => (int) $a['id'],
            'label'       => $a['label'] ?? '',
            'fullAddress' => $a['full_address'] ?? '',
            'lat'         => $a['lat'] !== null ? (float) $a['lat'] : null,
            'lng'         => $a['lng'] !== null ? (float) $a['lng'] : null,
            'zoneId'      => $a['zone_id'] !== null ? (int) $a['zone_id'] : null,
            'zoneName'    => $a['_zone_name'] ?? null,
            'branchId'    => $a['branch_id'] !== null ? (int) $a['branch_id'] : null,
            'branchName'  => $a['_branch_name'] ?? null,
            'isDefault'   => (bool) $a['is_default'],
            'createdAt'   => WireTime::toWire($a['created_at']),
        ];
    }

    /**
     * مستلم محفوظ في دفتر العميل (saveReceiver القديمة).
     * ⚠️ `name` و`phone` **من غير `?? ''`** — الأصل كده بالظبط، والعمودين
     * NOT NULL أصلًا. الباقي بـ`?? ''`.
     */
    public static function savedReceiver(array|object $row): array
    {
        $r = self::row($row);

        return [
            'id'      => (int) $r['id'],
            'name'    => $r['name'],
            'phone'   => $r['phone'],
            'phone2'  => $r['phone2'] ?? '',
            'address' => $r['address'] ?? '',
            'zoneId'  => $r['zone_id'] !== null ? (int) $r['zone_id'] : null,
            'lat'     => $r['lat'] !== null ? (float) $r['lat'] : null,
            'lng'     => $r['lng'] !== null ? (float) $r['lng'] : null,
            'createdAt' => WireTime::toWire($r['created_at']),
        ];
    }

    /**
     * حركة محفظة بالشكل اللي `readWallet` القديمة كانت بترجّعه للتطبيق.
     *
     * ⚠️ **مش نفس شكل `finance_wallet_txn_wire`**: هنا `by` مش `createdBy`
     * و`at` مش `createdAt`، ومفيش `key` ولا `walletId`. شاشة المحفظة في
     * `customer.html` بتقرا الأسماء القصيرة دي.
     */
    public static function walletTxn(array|object $row): array
    {
        $r = self::row($row);

        return [
            'id'           => (int) $r['id'],
            'amount'       => (float) $r['amount'],
            'type'         => $r['type'],
            'note'         => $r['note'] ?? '',
            'orderNum'     => $r['order_num'],
            'balanceAfter' => (float) $r['balance_after'],
            'by'           => $r['created_by'] ?? '',
            'at'           => WireTime::toWire($r['created_at']),
        ];
    }
}
