<?php

declare(strict_types=1);

namespace App\Wire;

use App\Support\WireTime;
use stdClass;

/**
 * طبقة السلك للوحة العمليات — نقل حرفي لدوال board_ser_* في
 * api/routes/board.php (VOCAB.md بند 7 و 11 و 13).
 *
 * نفس قواعد CoreWire/OrderWire الملزمة:
 *  • كل كيان بيرجع `id` الرقمي + `key` = legacy_key (عن طريق CoreWire::wireId).
 *  • **ممنوع حذف اسم حقل** حتى لو قيمته null، وممنوع تغيير ترتيب المفاتيح.
 *  • التوقيتات كلها ISO-8601 UTC بميلي ثانية عبر WireTime::toWire.
 *
 * ⚠️ فيه هنا حقول **بتطلع null على طول** لأنها مش متخزنة في السكيمة أصلًا
 * (approvedAt/rejectedAt في طلب الانضمام، respondedAt/respondedBy في طلب
 * الإرجاع). سايبينها بالحرف زي الأصل: الشكل القديم هو العقد، والواجهات
 * بتقرا المفاتيح دي حتى لو قيمتها فاضية.
 */
final class BoardWire
{
    /* ═══════════════════════════════════════════════════════════
       الوردية — VOCAB بند 7
    ═══════════════════════════════════════════════════════════ */

    /**
     * @param array $historyRows صفوف shift_branch_history للوردية دي مرتبة بـ moved_at
     *
     * ⚠️ `branchHistory` القديم = **الفروع السابقة بس** مش كلها: الحلقة
     * بتقف عند n-1، وكل عنصر `until` بياخد `moved_at` بتاع اللي بعده.
     * يعني الفرع الحالي (آخر صف) مابيظهرش في القايمة — هو أصلًا في
     * `branchId`. ورديه اتفتحت ومااتنقلتش = مصفوفة فاضية.
     */
    public static function shift(array|object $row, array $historyRows = []): array
    {
        $r = CoreWire::row($row);

        $history = [];
        $n = count($historyRows);
        for ($i = 0; $i < $n - 1; $i++) {
            $cur  = CoreWire::row($historyRows[$i]);
            $next = CoreWire::row($historyRows[$i + 1]);
            $history[] = [
                'branchId'   => (int) $cur['branch_id'],
                'branchName' => $cur['branch_name'] ?? null,
                'until'      => WireTime::toWire($next['moved_at']),
            ];
        }

        return CoreWire::wireId($r) + [
            'pilotId'    => (int) $r['pilot_id'],
            'pilotName'  => $r['pilot_name'] ?? null,
            'branchId'   => (int) $r['branch_id'],
            'branchName' => $r['branch_name'] ?? null,
            // غياب الحالة قديمًا بيتعامل كـ active (VOCAB بند 7)
            'status'     => $r['status'] ?: 'active',
            'startedAt'  => WireTime::toWire($r['started_at']),
            'endedAt'    => WireTime::toWire($r['ended_at'] ?? null),
            'endedBy'    => $r['ended_by'] ?? null,
            'openedBy'   => $r['opened_by'] ?? null,
            'openedManually' => (bool) ($r['opened_manually'] ?? 0),
            'branchHistory'  => $history,
            'transferredAt'  => WireTime::toWire($r['transferred_at'] ?? null),
            'transferredBy'  => $r['transferred_by'] ?? null,
            'bonusAmount'     => (float) ($r['bonus_amount'] ?? 0),
            'bonusReason'     => $r['bonus_reason'] ?? '',
            'deductionAmount' => (float) ($r['deduction_amount'] ?? 0),
            'deductionReason' => $r['deduction_reason'] ?? '',
            'advanceAmount'   => (float) ($r['advance_amount'] ?? 0),
            'advanceReason'   => $r['advance_reason'] ?? '',
            /* ⚠️ الافتراضي هنا `daily` — بينما تجميع التقفيلة الشهرية
               بيستخدم `?: 'monthly'` لنفس الأعمدة. التناقض ده في الأصل
               بالحرف؛ عمليًا مابيظهرش لأن السكيمة NOT NULL DEFAULT 'daily'
               فالـ`?:` مابتشتغلش. متنقول زي ما هو — الإصلاح قرار منفصل. */
            'commissionSettle' => $r['commission_settle'] ?: 'daily',
            'bonusSettle'      => $r['bonus_settle'] ?: 'daily',
            'deductionSettle'  => $r['deduction_settle'] ?: 'daily',
            'advanceSettle'    => $r['advance_settle'] ?: 'daily',
            /* 💵 صرف عمولة الوردية كاش من الخزنة (طلب 2026-09-03) —
               وجود التاريخ = اتصرفت، والواجهة بتقفل الرجوع لشهري */
            'commissionPaidAmount' => (float) ($r['commission_paid_amount'] ?? 0),
            'commissionPaidAt'     => WireTime::toWire($r['commission_paid_at'] ?? null),
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       طلبات الانضمام — VOCAB بند 11
    ═══════════════════════════════════════════════════════════ */

    /**
     * ⚠️ التليفونات متخزنة في **عمود واحد** `phones` بصيغة "phone1,phone2"،
     * والسلك بيفكّها لحقلين. غياب الفاصلة = phone2 فاضي (مش null).
     */
    public static function joinRequest(array|object $row): array
    {
        $r = CoreWire::row($row);

        $phones = array_map('trim', explode(',', (string) ($r['phones'] ?? '')));

        return CoreWire::wireId($r) + [
            'name'       => $r['name'],
            'phone1'     => $phones[0] ?? '',
            'phone2'     => $phones[1] ?? '',
            'cardNum'    => $r['card_num'] ?? '',
            'vehicleNo'  => $r['vehicle_no'] ?? '',
            'address'    => $r['address'] ?? '',
            'branchId'   => $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
            'branchName' => $r['branch_name'] ?? null,
            'requestedBy' => $r['requested_by'] ?? null,
            /* 🔴 مصدر الطلب — الإدارة لازم تفرّق بين اللي جه من الموقع
               (مجهول، محدش شافه) واللي مشرف فرع سجّله بنفسه. العمود موجود
               في القاعدة من الأصل بس ماكانش بيطلع على السلك خالص، فالشاشة
               ماكانش قدّامها أي طريقة تعرف (طلب صاحب النظام 2026-09-12). */
            'source'     => $r['source'] ?? null,

            /* بيانات المتقدّم — كلها اختيارية، والفاضي بيرجع فاضي مش null
               عشان الواجهة تتعامل معاها زي باقي الحقول من غير فحص زيادة. */
            'prevEmployer'    => $r['prev_employer'] ?? '',
            'leaveReason'     => $r['leave_reason'] ?? '',
            'lastSalary'      => $r['last_salary'] !== null ? (float) $r['last_salary'] : null,
            'experienceYears' => $r['experience_years'] !== null ? (float) $r['experience_years'] : null,
            'applicantNote'   => $r['applicant_note'] ?? '',
            'status'     => $r['status'],
            'createdAt'  => WireTime::toWire($r['created_at']),
            // مش متخزنين في السكيمة — الحقول موجودة حفاظًا على الشكل القديم
            'approvedAt' => null,
            'rejectedAt' => null,
            'pilotId'    => $r['pilot_id'] !== null ? (int) $r['pilot_id'] : null,
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       طلبات الأذونات — rest/dayoff/incident (VOCAB بند 13)
    ═══════════════════════════════════════════════════════════ */

    /**
     * `forcedBy` مش فاضي = إيقاف إجباري من الإدارة/الفرع مش طلب من الطيار.
     * ده اللي الواجهة بتفرّق بيه بين «إذن» و«إيقاف».
     */
    public static function leaveRequest(array|object $row): array
    {
        $r = CoreWire::row($row);

        return CoreWire::wireId($r) + [
            'pilotId'    => (int) $r['pilot_id'],
            'pilotName'  => $r['pilot_name'] ?? null,
            'branchId'   => (int) $r['branch_id'],
            'branchName' => $r['branch_name'] ?? null,
            'type'       => $r['type'],
            'reason'     => $r['reason'] ?? '',
            'status'     => $r['status'],
            'requestedAt' => WireTime::toWire($r['requested_at']),
            'respondedAt' => WireTime::toWire($r['responded_at'] ?? null),
            'respondedBy' => $r['responded_by'] ?? null,
            'endedAt'    => WireTime::toWire($r['ended_at'] ?? null),
            'endedBy'    => $r['ended_by'] ?? null,
            'forcedBy'   => $r['forced_by'] ?? null,
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       طلبات فتح الوردية
    ═══════════════════════════════════════════════════════════ */

    public static function shiftRequest(array|object $row): array
    {
        $r = CoreWire::row($row);

        return CoreWire::wireId($r) + [
            'pilotId'    => (int) $r['pilot_id'],
            'pilotName'  => $r['pilot_name'] ?? null,
            'branchId'   => (int) $r['branch_id'],
            'branchName' => $r['branch_name'] ?? null,
            'status'     => $r['status'],
            'requestedAt' => WireTime::toWire($r['requested_at']),
            'respondedAt' => WireTime::toWire($r['responded_at'] ?? null),
            'respondedBy' => $r['responded_by'] ?? null,
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       طلبات إرجاع الأوردر بإذن
    ═══════════════════════════════════════════════════════════ */

    /**
     * `orderNum` نسخة محفوظة في صف الطلب نفسه (مش join على orders) —
     * عشان الطلب يفضل يعرض رقم الأوردر حتى لو الأوردر اتغيّر.
     */
    public static function returnRequest(array|object $row): array
    {
        $r = CoreWire::row($row);

        return CoreWire::wireId($r) + [
            'orderId'    => (int) $r['order_id'],
            'orderNum'   => $r['order_num'],
            'pilotId'    => (int) $r['pilot_id'],
            'pilotName'  => $r['pilot_name'] ?? null,
            'branchId'   => (int) $r['branch_id'],
            'branchName' => $r['branch_name'] ?? null,
            'reason'     => $r['reason'] ?? '',
            'status'     => $r['status'],
            'requestedAt' => WireTime::toWire($r['requested_at']),
            // مش متخزنة في السكيمة — الحقول موجودة حفاظًا على الشكل القديم
            'respondedAt' => null,
            'respondedBy' => null,
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       النقل بين الفروع
    ═══════════════════════════════════════════════════════════ */

    /**
     * ⚠️ `type`: القاعدة بتخزّن temp/permanent، واللسان القديم بيقول
     * **direct** بدل temp. الترجمة في اتجاه واحد بس (خروج) — أي تغيير
     * في الاسم ده بيكسّر تفرقة الواجهة بين النقل المؤقت والدائم.
     */
    public static function transfer(array|object $row): array
    {
        $r = CoreWire::row($row);

        return CoreWire::wireId($r) + [
            'pilotId'    => (int) $r['pilot_id'],
            'pilotName'  => $r['pilot_name'] ?? null,
            'fromBranchId'   => (int) $r['from_branch_id'],
            'fromBranchName' => $r['from_branch_name'] ?? null,
            'toBranchId'     => (int) $r['to_branch_id'],
            'toBranchName'   => $r['to_branch_name'] ?? null,
            'type'       => $r['type'] === 'temp' ? 'direct' : $r['type'],
            'status'     => $r['status'],
            'requestedAt' => WireTime::toWire($r['requested_at']),
            'resolvedAt'  => WireTime::toWire($r['resolved_at'] ?? null),
            'resolvedBy'  => $r['resolved_by'] ?? null,
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       طلبات الدعم بين الفروع
    ═══════════════════════════════════════════════════════════ */

    /**
     * @param array $responses صفوف pilot_support_responses بتاعة الطلب ده (مرتبة بـ id)
     *
     * `respondedBranches` القديمة = خريطة {branchId: true} لكل فرع رد —
     * **قبل أو اعتذر، الاتنين بيتحطوا**. الواجهة بتستخدمها عشان تخفي
     * الإنذار عن الفرع اللي رد خلاص. فاضية = `{}` مش `[]`.
     *
     * `resolvedAt` = وقت **آخر** رد بالقبول (الحلقة بتكتب فوق بعضها) —
     * مش أول واحد. سلوك الأصل بالحرف.
     */
    public static function supportRequest(array|object $row, array $responses = []): array
    {
        $r = CoreWire::row($row);

        $responded = [];
        $resolvedAt = null;
        /* أسباب الرفض — الفرع الطالب لازم يشوفها. مصفوفة مش قيمة واحدة
           لأن الإنذار العام ممكن يترفض من أكتر من فرع وكل واحد له سببه. */
        $rejections = [];
        foreach ($responses as $resp) {
            $x = CoreWire::row($resp);
            $responded[(string) (int) $x['branch_id']] = true;
            if ($x['response'] === 'accepted') {
                $resolvedAt = WireTime::toWire($x['responded_at']);
            } elseif ($x['response'] === 'rejected') {
                $rejections[] = [
                    'branchId'    => (int) $x['branch_id'],
                    'reason'      => $x['reason'] ?? null,
                    'respondedAt' => WireTime::toWire($x['responded_at']),
                ];
            }
        }

        return CoreWire::wireId($r) + [
            'requestingBranchId'   => (int) $r['requesting_branch_id'],
            'requestingBranchName' => $r['requesting_branch_name'] ?? null,
            // broadcast = مفيش فرع محدد مطلوب منه الدعم
            'broadcast'  => $r['from_branch_id'] === null,
            'fromBranchId'   => $r['from_branch_id'] !== null ? (int) $r['from_branch_id'] : null,
            'fromBranchName' => $r['from_branch_name'] ?? null,
            'notes'      => $r['notes'] ?? '',
            'status'     => $r['status'],
            'respondedBranches' => $responded ?: new stdClass(),
            'rejections' => $rejections,
            'requestedAt' => WireTime::toWire($r['created_at']),
            'acceptedByBranchId'   => $r['accepted_by_branch_id'] !== null ? (int) $r['accepted_by_branch_id'] : null,
            'acceptedByBranchName' => $r['accepted_by_branch_name'] ?? null,
            'pilotId'    => $r['pilot_id'] !== null ? (int) $r['pilot_id'] : null,
            'pilotName'  => $r['pilot_name'] ?? null,
            'pilotPhone' => $r['pilot_phone'] ?? null,
            /* عهدة الطيار وأوردراته الشغّالة — الفرعين بيشوفوهم قبل القرار.
               صاحب النظام اختار «تحذير واضح والنقل يعدّي»، فدي بيانات
               للتحذير مش مانع. */
            'pilotCustody'      => isset($r['pilot_custody']) ? (float) $r['pilot_custody'] : null,
            'pilotActiveOrders' => isset($r['pilot_active_orders']) ? (int) $r['pilot_active_orders'] : 0,
            'resolvedAt' => $resolvedAt,
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       التقفيلة الشهرية المحفوظة
    ═══════════════════════════════════════════════════════════ */

    /**
     * 💰 لقطة نهائية محفوظة — الأسماء على السلك **مختلفة عن أسماء الأعمدة**:
     * hours→totalHours · commission→totalCommission · bonus→totalBonus ·
     * deductions→totalDeduction · advances→totalAdvance · month→monthKey.
     * الأسماء دي هي اللي الواجهة بتقراها، فممنوع تتوحّد مع أسماء الأعمدة.
     */
    public static function closeout(array|object $row): array
    {
        $r = CoreWire::row($row);

        return CoreWire::wireId($r) + [
            'pilotId'   => (int) $r['pilot_id'],
            'pilotName' => $r['pilot_name'] ?? null,
            'monthKey'  => $r['month'],
            'workDays'  => (int) $r['work_days'],
            'totalHours' => (float) $r['hours'],
            'deliveredCount'  => (int) $r['delivered_count'],
            'totalCommission' => (float) $r['commission'],
            'totalBonus'      => (float) $r['bonus'],
            'totalDeduction'  => (float) $r['deductions'],
            'totalAdvance'    => (float) $r['advances'],
            'salary'             => (float) $r['salary'],
            'requiredDailyHours' => (float) $r['required_daily_hours'],
            'paidLeaveDays'   => (int) $r['paid_leave_days'],
            'unpaidLeaveDays' => (int) $r['unpaid_leave_days'],
            'dailyRate'       => (float) $r['daily_rate'],
            'netDue'          => (float) $r['net_due'],
            'closedAt'  => WireTime::toWire($r['closed_at'] ?? null),
            'closedBy'  => $r['closed_by'] ?? null,
        ];
    }
}
