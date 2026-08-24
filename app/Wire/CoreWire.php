<?php

declare(strict_types=1);

namespace App\Wire;

use App\Support\Vocab;
use App\Support\WireTime;
use stdClass;

/**
 * طبقة السلك للكيانات الأساسية — نقل حرفي لـ api/ser_core.php.
 *
 * ليه كلاسات ساكنة على مصفوفات مش JsonResource:
 *  • الأصل دوال نقية بتحوّل صف قاعدة لمصفوفة. أي طبقة بينهم (Resource
 *    بتغلّف في `data`، `when()` بتخفي مفاتيح) = فرصة لاختلاف صامت.
 *  • الشكل ده بيخلّي الفحص التفاضلي مع الأصل ممكن: نفس المدخل → نفس
 *    المخرج، بالمقارنة المباشرة.
 *
 * قواعد ملزمة (aldahshan/api/CONVENTIONS.md بند 8):
 *  • كل كيان بيرجع `id` الرقمي + `key` = legacy_key (لو موجود).
 *  • المراجع بترجع بالـid الرقمي + الاسم المرافق.
 *  • **ممنوع حذف اسم حقل** حتى لو قيمته null.
 */
final class CoreWire
{
    /** بيقبل صف كـarray أو stdClass ويرجّع مصفوفة — Query Builder بيرجّع stdClass */
    public static function row(array|object $row): array
    {
        return is_array($row) ? $row : (array) $row;
    }

    /** id رقمي + مفتاح Firebase القديم — النمط الموحد لكل الكيانات */
    public static function wireId(array $row): array
    {
        return [
            'id'  => (int) $row['id'],
            'key' => $row['legacy_key'] ?? null,
        ];
    }

    /* ── الفرع — VOCAB بند 8 ─────────────────────────────────── */
    public static function branch(array|object $row, array $areas = []): array
    {
        $r = self::row($row);

        return self::wireId($r) + [
            'name'    => $r['name'],
            'code'    => $r['code'],
            'areas'   => array_values($areas),
            'phone'   => $r['phone'] ?? null,
            'manager' => $r['manager'] ?? null,
            'address' => $r['address'] ?? null,
            'paused'  => (bool) ($r['paused'] ?? 0),
            'failoverBranchId' => isset($r['failover_branch_id']) && $r['failover_branch_id'] !== null
                ? (int) $r['failover_branch_id'] : null,
            'failoverBranchName' => $r['failover_branch_name'] ?? null,
            'createdAt' => WireTime::toWire($r['created_at'] ?? null),
        ];
    }

    /* ── المنطقة — VOCAB بند 9 ───────────────────────────────────
       جدول zones ملوش عمود updated_at، بس بنطلع updatedAt: null
       حفاظًا على شكل الكائن القديم (ممنوع حذف اسم حقل). */
    public static function zone(array|object $row): array
    {
        $r = self::row($row);

        return self::wireId($r) + [
            'areaName'         => $r['area_name'],
            'price'            => (float) $r['price'],
            'deliveryBranchId' => (int) $r['delivery_branch_id'],
            'deliveryBranchName' => $r['delivery_branch_name'] ?? null,
            'sourceBranchId'   => isset($r['source_branch_id']) && $r['source_branch_id'] !== null
                ? (int) $r['source_branch_id'] : null,
            'sourceBranchName' => $r['source_branch_name'] ?? null,
            'createdAt'        => WireTime::toWire($r['created_at'] ?? null),
            'updatedAt'        => WireTime::toWire($r['updated_at'] ?? null),
        ];
    }

    /* ── الطيار — VOCAB بند 6 + حقول اللوحة ──────────────────── */
    public static function pilot(array|object $row): array
    {
        $r = self::row($row);

        $onLeave  = ($r['status'] ?? null) === 'on_leave';
        $location = null;
        if (isset($r['lat'], $r['lng']) && $r['lat'] !== null && $r['lng'] !== null) {
            $location = [
                'lat'       => (float) $r['lat'],
                'lng'       => (float) $r['lng'],
                'updatedAt' => WireTime::toWire($r['location_updated_at'] ?? null),
            ];
        }

        return self::wireId($r) + [
            'name'      => $r['name'],
            'phone1'    => $r['phone1'] ?? null,
            'phone2'    => $r['phone2'] ?? null,
            'cardNum'   => $r['card_num'] ?? null,
            'vehicleNo' => $r['vehicle_no'] ?? null,
            'address'   => $r['address'] ?? null,
            'username'  => $r['username'] ?? null,
            'notes'     => $r['notes'] ?? null,
            'commissionType'  => $r['commission_type'] ?: 'percent',
            'commissionValue' => (float) ($r['commission_value'] ?? 0),
            'assignedBranchId' => isset($r['assigned_branch_id']) && $r['assigned_branch_id'] !== null
                ? (int) $r['assigned_branch_id'] : null,
            'assignedBranchName' => $r['assigned_branch_name'] ?? null,
            'pilotStatus' => Vocab::pilotStatusToWire($r['status'] ?? null),
            'queueNo'     => isset($r['queue_no']) && $r['queue_no'] !== null ? (int) $r['queue_no'] : null,
            'statusSince' => WireTime::toWire($r['status_since'] ?? null),
            'activeOrders' => (int) ($r['active_orders'] ?? 0),
            // بيانات الإذن بتظهر **بس** لو الطيار فعلًا في إذن — زي الأصل
            'leaveType'   => $onLeave ? ($r['leave_type'] ?? null) : null,
            'leaveReason' => $onLeave ? ($r['leave_reason'] ?? null) : null,
            'leaveForced' => ($onLeave && (int) ($r['leave_forced'] ?? 0) === 1) ? true : null,
            'breakStartedAt' => WireTime::toWire($r['break_started_at'] ?? null),
            'location'    => $location,
            // حقول اللوحة الإدارية (مش في اللسان القديم لكن اللوحات محتاجاها)
            'custody'       => (float) ($r['custody_balance'] ?? 0),
            'monthlySalary' => (float) ($r['monthly_salary'] ?? 0),
            'requiredDailyHours' => isset($r['required_daily_hours']) && $r['required_daily_hours'] !== null
                ? (float) $r['required_daily_hours'] : null,
            'appVersion' => $r['app_version'] ?? null,
            'createdAt'  => WireTime::toWire($r['created_at'] ?? null),
        ];
    }

    /** حقول الفلوس الراكبة على كارت الطيار */
    public const PILOT_MONEY_KEYS = ['custody', 'monthlySalary', 'commissionType', 'commissionValue'];

    /**
     * كارت الطيار **حسب دور اللي بيقرا**.
     *
     * `pilot_supervisor` بيشغّل الأسطول من غير أي صلاحية مالية (شوف الشرح في
     * routes/api.php)، لكن الكارت نفسه شايل العهدة والمرتب والعمولة — يعني
     * قفل المسارات المالية لوحده مكانش هيمنعه يشوف الأرقام. فبتتشال هنا.
     *
     * 🔴 **بتتشال مش بتتصفّر**: `custody: 0` بيتقري في أي لوحة كرصيد حقيقي،
     * أما المفتاح الناقص فبيبان «مش متاح». أي دور تاني بياخد الكارت كامل.
     */
    public static function pilotFor(?string $role, array|object $row): array
    {
        $wire = self::pilot($row);

        if ($role === 'pilot_supervisor') {
            foreach (self::PILOT_MONEY_KEYS as $k) {
                unset($wire[$k]);
            }
        }

        return $wire;
    }

    /* ── المستخدم — VOCAB بند 10 (من غير الباسورد) ───────────── */
    public static function user(array|object $row, array $allowedApps = [], array $pagePerms = []): array
    {
        $r = self::row($row);

        return self::wireId($r) + [
            'username'   => $r['username'],
            'role'       => Vocab::roleToAr($r['role']),
            'name'       => $r['name'] ?? null,
            'branchId'   => isset($r['branch_id']) && $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
            'branchName' => $r['branch_name'] ?? null,
            'pilotId'    => isset($r['pilot_id']) && $r['pilot_id'] !== null ? (int) $r['pilot_id'] : null,
            'allowedApps' => array_values($allowedApps),
            'pagePerms'   => $pagePerms ?: new stdClass(),   // {} مش [] في JSON
            'shopName'    => $r['shop_name'] ?? null,
            'shopPhone'   => $r['shop_phone'] ?? null,
            'shopPhone2'  => $r['shop_phone2'] ?? null,
            'shopAddress' => $r['shop_address'] ?? null,
            'senderId'    => isset($r['sender_id']) && $r['sender_id'] !== null ? (int) $r['sender_id'] : null,
            'blocked'     => (bool) ($r['blocked'] ?? 0),
            'protected'   => (bool) ($r['protected'] ?? 0),
            'createdAt'   => WireTime::toWire($r['created_at'] ?? null),
        ];
    }

    /* ── المُرسِل / المستلم — نفس البنية حرفيًا ────────────────── */
    public static function sender(array|object $row): array
    {
        $r = self::row($row);

        return self::wireId($r) + [
            'name'      => $r['name'],
            'phone1'    => $r['phone1'],
            'phone2'    => $r['phone2'] ?? null,
            'address'   => $r['address'] ?? null,
            'createdBy' => $r['created_by'] ?? null,
            'source'    => $r['source'] ?? null,
            'createdAt' => WireTime::toWire($r['created_at'] ?? null),
        ];
    }

    /** المستلم = نفس بنية المُرسِل بالحرف (زي ser_receiver في الأصل) */
    public static function receiver(array|object $row): array
    {
        return self::sender($row);
    }

    public static function storeContact(array|object $row): array
    {
        $r = self::row($row);

        return self::wireId($r) + [
            'storeUsername' => $r['store_username'],
            'name'      => $r['name'],
            'phone'     => $r['phone'],
            'phone2'    => $r['phone2'] ?? null,
            'address'   => $r['address'] ?? null,
            'createdAt' => WireTime::toWire($r['created_at'] ?? null),
        ];
    }
}
