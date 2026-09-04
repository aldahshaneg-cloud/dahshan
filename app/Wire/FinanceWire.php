<?php

declare(strict_types=1);

namespace App\Wire;

use App\Support\Vocab;
use App\Support\WireTime;

/**
 * طبقة السلك للمالية والحضور — نقل حرفي للدوال finance_*_wire
 * في api/routes/finance.php.
 *
 * 🔴 كل الكائنات هنا **فلوس**. القاعدة: الأرقام بتتحوّل بنفس الـcast بالظبط
 * زي الأصل ومن غير أي تقريب زيادة. أعمدة المبالغ كلها DECIMAL(12,2) وبترجع
 * من الدرايفر **نصًا** ("125.00")، والـ`(float)` هو اللي بيخليها رقم في
 * الـJSON. أي تقريب إضافي (round) هنا = رقم مختلف على الشاشة، وأي نسيان
 * للـcast = القيمة بتطلع بين علامتين تنصيص والواجهة بتجمّعها كنص.
 *
 * قواعد ملزمة (زي CoreWire/OrderWire):
 *  • كل كيان بيرجع `id` الرقمي + `key` = legacy_key.
 *  • **ممنوع حذف اسم حقل** حتى لو قيمته null — وممنوع تغيير ترتيب المفاتيح.
 *  • المراجع الاختيارية (branchId/storeId/…) بترجع int أو null، مش 0.
 */
final class FinanceWire
{
    /**
     * تعديل عمولة الطيار — pilot_commission_adjustments.
     *
     * كيان **جديد** بعد الترحيل، مالوش مقابل في النظام القديم (العمولة
     * هناك كانت بتتحسب لحظيًا وبس). حالتين:
     *   • override — بديل لعمولة أوردر بعينه. أوردر السفر مثلًا عمولته
     *     نص سعر الخدمة مش الثابت المعتاد.
     *   • extra — مبلغ مستقل بلا أوردر (تعويض شكوى للعميل والطيار أخد
     *     عمولته عليها).
     *
     * 🔴 كل صف هنا فلوس بتدخل مستحقات الطيار فعلًا — عشان كده created_by
     * و reason **مش اختياريين** في المسار اللي بيكتب.
     */
    public static function commissionAdjustment(array|object $row): array
    {
        $r = CoreWire::row($row);

        return [
            'id'            => (int) $r['id'],
            'pilotId'       => (int) $r['pilot_id'],
            'pilotName'     => $r['pilot_name'] ?? null,
            'orderId'       => $r['order_id'] !== null ? (int) $r['order_id'] : null,
            'orderNum'      => $r['order_num'] ?? null,
            'kind'          => $r['kind'],
            'amount'        => (float) $r['amount'],
            /* 💸 المصروف كاش من الخزنة (طلب 2026-09-03) — بيه الواجهة
               بتعرف تعرض «اتصرفت» وتحاسب التعديل بالفرق */
            'paidAmount'    => (float) ($r['paid_amount'] ?? 0),
            'paidAt'        => WireTime::toWire($r['paid_at'] ?? null),
            'paidStoreId'   => isset($r['paid_store_id']) && $r['paid_store_id'] !== null ? (int) $r['paid_store_id'] : null,
            'reason'        => $r['reason'],
            'effectiveDate' => $r['effective_date'],
            'branchId'      => $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
            'branchName'    => $r['branch_name'] ?? null,
            'createdBy'     => $r['created_by'],
            'createdAt'     => WireTime::toWire($r['created_at']),
            'updatedAt'     => WireTime::toWire($r['updated_at'] ?? null),
        ];
    }

    /* ── الخزنة — cash_stores ─────────────────────────────────────
       ملحوظة حاكمة منقولة من الأصل: `balance` هنا **للقراءة بس**؛ الرصيد
       مبيتعدلش من مسار الخزنة أبدًا — حصريًا عبر cash_transactions. */
    public static function store(array|object $row): array
    {
        $r = CoreWire::row($row);

        return [
            'id'        => (int) $r['id'],
            'key'       => $r['legacy_key'],
            'name'      => $r['name'],
            'branchId'  => $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
            /* اسم الفرع — الواجهات كانت بتقراه من الأول والسلك مكانش
               بيبعته، فعمود «الفرع» في جداول الخزن كان «—» دايمًا
               والموظف مايفرّقش بين خزنتين بأسماء متشابهة. */
            'branchName' => $r['_branch_name'] ?? null,
            'balance'   => (float) $r['balance'],
            'createdAt' => WireTime::toWire($r['created_at']),
        ];
    }

    /* ── حركة النقدية — cash_transactions ─────────────────────────
       `type`: in = وارد · out = منصرف · pending = معلّق (مبيلمسش الرصيد
       لحد الاعتماد). بيطلع **كود إنجليزي خام** مش عربي — الواجهة بتقارنه
       نصًا، فممنوع نمرّره على أي قاموس. */
    public static function cashTxn(array|object $row): array
    {
        $r = CoreWire::row($row);

        return [
            'id'        => (int) $r['id'],
            'key'       => $r['legacy_key'],
            'storeId'   => (int) $r['store_id'],
            'type'      => $r['type'],
            'amount'    => (float) $r['amount'],
            'reason'    => $r['reason'],
            'notes'     => $r['notes'],
            // الاسم على السلك مختصر عن اسم العمود — related_pilot_id ← pilotId
            'pilotId'   => $r['related_pilot_id'] !== null ? (int) $r['related_pilot_id'] : null,
            'expenseId' => $r['related_expense_id'] !== null ? (int) $r['related_expense_id'] : null,
            'branchId'  => $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
            'createdBy' => $r['created_by'],
            'createdAt' => WireTime::toWire($r['created_at']),
        ];
    }

    /* ── حركة العهدة — custody_transactions ───────────────────────
       الأنواع: give / return / order_pending / order_extra. كلها بتزوّد
       اللي على الطيار ما عدا return بتنقّص — بس الإشارة دي بتتحسب في
       مسار الكتابة، والمبلغ هنا بيطلع **موجب دايمًا** زي ما هو مخزّن. */
    public static function custody(array|object $row): array
    {
        $r = CoreWire::row($row);

        return [
            'id'        => (int) $r['id'],
            'key'       => $r['legacy_key'],
            'pilotId'   => (int) $r['pilot_id'],
            'type'      => $r['type'],
            'amount'    => (float) $r['amount'],
            // سبب الحركة — اتضاف 2026-08-27. الحركات القديمة قيمتها null.
            'reason'    => $r['reason'] ?? null,
            'storeId'   => $r['store_id'] !== null ? (int) $r['store_id'] : null,
            'branchId'  => $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
            'createdBy' => $r['created_by'],
            'createdAt' => WireTime::toWire($r['created_at']),
        ];
    }

    /* ── المصروف — expenses ───────────────────────────────────────
       ⚠️ `date` بيطلع **نص عمود DATE خام** ("2026-08-06") مش ISO بميلي
       ثانية زي باقي التواريخ. ده استثناء الأصل بالحرف: المصروف تاريخه يوم
       مش لحظة، والواجهة بتعرضه وبتفلتر بيه كما هو. تمريره على dt_to_wire
       كان هيحوّله لـ"…T00:00:00.000Z" ويكسر الفلترة. */
    public static function expense(array|object $row): array
    {
        $r = CoreWire::row($row);

        return [
            'id'          => (int) $r['id'],
            'key'         => $r['legacy_key'],
            'date'        => $r['expense_date'],
            'item'        => $r['item'],
            'amount'      => (float) $r['amount'],
            'branchId'    => $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
            'notes'       => $r['notes'],
            'cashStoreId' => $r['cash_store_id'] !== null ? (int) $r['cash_store_id'] : null,
            /* 🔴 الاتنين دول الواجهات بتقراهم من الأول والسلك مكانش
               بيبعتهم، والنتيجة إن **كل مصروف مدفوع من خزنة كان بيفضل
               يبان «⏳ مستحق»** مهما اتخصم فعلًا، وقايمة الخزنة في نافذة
               التعديل مابتتقفلش. العمود «cash_txn_id» موجود في المخطط
               وبيتكتب فعلًا وقت الدفع. */
            'cashTxnId'   => isset($r['cash_txn_id']) && $r['cash_txn_id'] !== null ? (int) $r['cash_txn_id'] : null,
            'cashStoreName' => $r['_cash_store_name'] ?? null,
            'createdBy'   => $r['created_by'],
            'createdAt'   => WireTime::toWire($r['created_at']),
        ];
    }

    /* ── حركة المحفظة — wallet_transactions ───────────────────────
       `amount` مخزّن **بالإشارة** (موجب إضافة / سالب خصم) و`balanceAfter`
       هو الرصيد بعد الحركة وقتها. الواجهة بتبني كشف الحساب من الاتنين
       سوا، فأي واحد فيهم يتقرّب أو يتحوّل لمطلق = كشف غلط. */
    public static function walletTxn(array|object $row): array
    {
        $r = CoreWire::row($row);

        return [
            'id'           => (int) $r['id'],
            'key'          => $r['legacy_key'],
            'walletId'     => (int) $r['wallet_id'],
            'amount'       => (float) $r['amount'],
            'type'         => $r['type'],
            'note'         => $r['note'],
            'orderNum'     => $r['order_num'],
            'balanceAfter' => (float) $r['balance_after'],
            'createdBy'    => $r['created_by'],
            'createdAt'    => WireTime::toWire($r['created_at']),
        ];
    }

    /* ── جلسة الحضور — attendance_sessions (VOCAB بند 12) ────────── */

    /**
     * ⚠️ استثناء التوقيت الوحيد في النظام كله: لو الانصراف تلقائي (انقطع
     * النبض) بيطلع **رقم epoch بالميلي ثانية** بدل نص ISO. النوع نفسه
     * بيتغيّر — number مش string. ده سلوك الأصل حرفيًا واللوحة بتفرّق بيهم
     * عشان تعرض الانصراف التلقائي بشكل مختلف.
     *
     * وكمان: مفتاح `autoCheckOut` **مش موجود خالص** لما الانصراف يدوي —
     * مش بيطلع false. ده الاستثناء الوحيد لقاعدة «ممنوع حذف اسم حقل»،
     * ومنقول زي ما هو لأن الواجهة بتفحصه بـ`if (s.autoCheckOut)`.
     */
    public static function attendanceSession(array|object $row): array
    {
        $r = CoreWire::row($row);

        $auto = (int) $r['auto_check_out'] === 1;
        $out = null;
        if ($r['check_out'] !== null) {
            $out = $auto
                ? ((int) (strtotime($r['check_out'] . ' UTC') * 1000))
                : WireTime::toWire($r['check_out']);
        }

        $wire = [
            'id'       => (int) $r['id'],
            'key'      => $r['legacy_key'],
            'username' => $r['username'],
            'role'     => Vocab::roleToAr($r['role']),
            'checkIn'  => WireTime::toWire($r['check_in']),
            'checkOut' => $out,
            'lastSeen' => WireTime::toWire($r['last_seen']),
        ];
        if ($auto) {
            $wire['autoCheckOut'] = true;
        }

        return $wire;
    }

    /* ── الموظف اليدوي — manual_employees ─────────────────────────
       موظف بيتسجّل له حضور من غير حساب في النظام (عامل نظافة، سواق…). */
    public static function manualEmployee(array|object $row): array
    {
        $r = CoreWire::row($row);

        return [
            'id'        => (int) $r['id'],
            'key'       => $r['legacy_key'],
            'name'      => $r['name'],
            'phone'     => $r['phone'],
            'jobTitle'  => $r['job_title'],
            'notes'     => $r['notes'],
            'createdAt' => WireTime::toWire($r['created_at']),
        ];
    }
}
