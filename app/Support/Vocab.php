<?php

declare(strict_types=1);

namespace App\Support;

/**
 * القاموس — نقل حرفي لـ api/constants.php.
 *
 * القاعدة الذهبية (من رأس الملف الأصلي): «الـAPI بيكلّم الواجهات بالنصوص
 * العربية/القديمة حرفيًا، وقاعدة البيانات بتخزّن الأكواد الإنجليزية بس.
 * التحويل هنا حصريًا.»
 *
 * المرجع الملزم: aldahshan/db/VOCAB.md — مستخرج حرفيًا من النظام القديم
 * (tiar_branch.html + tiar.html + lib/main.dart). النصوص دي **مش قابلة
 * للتحسين**: الواجهات بتقارنها نصًا (`o.status === "تم التسليم"`)، وتطبيق
 * الطيار Flutter كذلك. أي تغيير حرف = كسر صامت.
 */
final class Vocab
{
    /* ── حالات الأوردر ──────────────────────────────────────────
       pending_pickup / received / postponed موجودين في السكيمة بس —
       ملهمش وجود في اللسان القديم، متسابين للاحتياط وممنوع يطلعوا من
       serializers الأوردرات. */
    public const ORDER_STATUS_AR = [
        'processing'     => 'قيد التنفيذ',
        'delivering'     => 'جاري التوصيل',
        'delivered'      => 'تم التسليم',
        'undelivered'    => 'لم يتم التوصيل',
        'cancelled'      => 'ملغي',
        'pending_pickup' => 'بانتظار الاستلام',
        'received'       => 'تم الاستلام',
        'postponed'      => 'مؤجل',
    ];

    public const ORDER_STATUS_CODE = [
        'قيد التنفيذ'      => 'processing',
        'جاري التوصيل'     => 'delivering',
        'تم التسليم'       => 'delivered',
        'لم يتم التوصيل'   => 'undelivered',
        'لم يتم التسليم'   => 'undelivered',   // مرادف ظهر في توثيق السكيمة
        'ملغي'             => 'cancelled',
        'بانتظار الاستلام' => 'pending_pickup',
        'تم الاستلام'      => 'received',
        'مؤجل'             => 'postponed',
    ];

    /** حالة الطرد جوه deliveries[] — الافتراضي «قيد التنفيذ» */
    public const PARCEL_STATUS_AR = [
        'processing'  => 'قيد التنفيذ',
        'delivered'   => 'تم التسليم',
        'undelivered' => 'لم يتم التوصيل',
    ];

    /* ── حالة الطيار — إنجليزي على السلك مش عربي ─────────────────
       غياب الحالة (NULL) = متحرّر من أي فرع / مش فاتح وردية */
    public const PILOT_STATUS_WIRE = [
        'waiting'    => 'waiting',
        'delivering' => 'delivering',
        'on_leave'   => 'onLeave',   // الكود القديم camelCase
    ];

    public const PILOT_STATUS_CODE = [
        'waiting'    => 'waiting',
        'delivering' => 'delivering',
        'onLeave'    => 'on_leave',
        'on_leave'   => 'on_leave',
    ];

    /** للعرض فقط — مابيتبعتش على السلك */
    public const PILOT_STATUS_AR = [
        'waiting'    => 'في الانتظار',
        'delivering' => 'جاري التوصيل',
        'on_leave'   => 'في إذن',
    ];

    /* أنواع الإذن — الكود القديم هو الحاكم (rest/dayoff/incident).
       تعليق السكيمة (break/day_off/sick/other) **مخالف** — VOCAB.md بند 41-52 */
    public const LEAVE_TYPE_WIRE = [
        'rest'     => 'rest',
        'dayoff'   => 'dayoff',
        'incident' => 'incident',
    ];

    public const LEAVE_TYPE_AR = [
        'rest'     => 'استراحة/بريك',
        'dayoff'   => 'عطلة/انصراف',
        'incident' => 'حادث',
    ];

    /** حالة الوردية — غياب الحقل قديمًا بيتعامل كـ active */
    public const SHIFT_STATUS_WIRE = ['active' => 'active', 'ended' => 'ended'];

    /** بنود تسوية تقفيلة الوردية — الافتراضي monthly (VOCAB.md:276) */
    public const SHIFT_SETTLE_WIRE = ['daily' => 'daily', 'monthly' => 'monthly'];

    public const REQUEST_STATUS_WIRE = [
        'pending'  => 'pending',
        'approved' => 'approved',
        'rejected' => 'rejected',
        'accepted' => 'accepted',   // pilotSupportRequests بس
        'ended'    => 'ended',      // pilotLeaveRequests / pilotTransfers
    ];

    /** علامة الإرجاع على الأوردر: pending / rejected / NULL — مفيش approved */
    public const ORDER_RETURN_STATUS_WIRE = ['pending' => 'pending', 'rejected' => 'rejected'];

    /** أنواع العمولة — الكود القديم percent (افتراضي) / fixed فقط */
    public const COMMISSION_TYPE_WIRE = ['percent' => 'percent', 'fixed' => 'fixed'];

    public const ORDER_SOURCE_WIRE = [
        'branch'   => 'branch',
        'admin'    => 'admin',
        'customer' => 'customer',
        'store'    => 'store',
    ];

    /* ── الأدوار — عربي حرفي على السلك ──────────────────────────── */
    public const ROLE_AR = [
        'admin'      => 'مدير',
        'branch'     => 'مشرف فرع',
        'pilot'      => 'طيار',
        'store'      => 'صاحب محل',
        'callcenter' => 'كول سنتر',
        'accountant' => 'حسابات',
        'hr'         => 'شؤون عاملين',
        'customer'   => 'عميل',
        // مشرف الطيارين — تشغيل الأسطول بلا أي صلاحية فلوس (شوف routes/api.php)
        'pilot_supervisor' => 'مشرف الطيارين',
    ];

    /** «مدير عام» (دخول جوجل للإدارة) بيتحول لكود admin برضه */
    public const ROLE_AR_ALIASES = ['مدير عام' => 'admin'];

    /* ═══ دوال التحويل ═══════════════════════════════════════════
       ملاحظة عامة: **الكود المجهول بيرجع زي ما هو** — مش بيترمي ومش
       بيتحوّل لافتراضي. ده سلوك النظام القديم بالحرف، وبيمنع اختفاء
       بيانات قديمة أو مرحّلة من الردود. */

    public static function statusToAr(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::ORDER_STATUS_AR[$code] ?? $code;
    }

    public static function statusToCode(?string $ar): ?string
    {
        if ($ar === null || $ar === '') {
            return null;
        }
        if (isset(self::ORDER_STATUS_AR[$ar])) {
            return $ar;   // اتبعت كود أصلًا
        }

        return self::ORDER_STATUS_CODE[$ar] ?? $ar;
    }

    /** حالة الطرد: كود DB → عربي (الافتراضي «قيد التنفيذ» زي العرض القديم) */
    public static function parcelStatusToAr(?string $code): string
    {
        return self::PARCEL_STATUS_AR[$code ?? ''] ?? 'قيد التنفيذ';
    }

    public static function pilotStatusToWire(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::PILOT_STATUS_WIRE[$code] ?? $code;
    }

    public static function pilotStatusToCode(?string $wire): ?string
    {
        if ($wire === null || $wire === '') {
            return null;
        }

        return self::PILOT_STATUS_CODE[$wire] ?? $wire;
    }

    public static function roleToAr(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::ROLE_AR[$code] ?? $code;
    }

    public static function roleToCode(?string $ar): ?string
    {
        if ($ar === null || $ar === '') {
            return null;
        }
        if (isset(self::ROLE_AR[$ar])) {
            return $ar;   // كود جاهز
        }

        $flip = array_flip(self::ROLE_AR);

        return $flip[$ar] ?? self::ROLE_AR_ALIASES[$ar] ?? $ar;
    }
}
