<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\BroadcastsOrders;
use App\Http\Controllers\Concerns\NotifiesPilotRequests;
use App\Support\Actor;
use App\Support\ApiResponse;
use App\Support\PollableList;
use App\Support\Vocab;
use App\Support\WireTime;
use App\Wire\BoardWire;
use App\Wire\CoreWire;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * لوحة العمليات — نقل حرفي لمسارات القراءة من api/routes/board.php.
 *
 * الملف الأصلي أكبر ملف في النظام (2149 سطر) وبيغطي دور الانتظار والورديات
 * والتقفيلة الشهرية وستة أنواع من الطلبات الإدارية.
 *
 * الموجود هنا: مسارات القراءة + **مسارات كتابة الدور والورديات والتقفيلة
 * الشهرية** (queue/* · shifts/* · pilots/{id}/return · force-leave ·
 * closeouts/monthly). كتابة الطلبات الإدارية (انضمام/أذونات/نقل/دعم) لسه.
 *
 * 🔴 **الغلاف هنا مش غلاف الاستطلاع العادي.** باقي النظام بيعمل `?since`
 * على مستوى الـSQL (`WHERE updated_at > FROM_UNIXTIME(?/1000)`)، لكن جداول
 * اللوحة **ملهاش عمود updated_at** أصلًا. فالأصل بيعمل الفلترة بعد الاستعلام:
 * بيحسب لكل صف أقصى طابع وقت من مجموعة أعمدة DATETIME (`_ts`)، وبيرجّع
 * `changed:false` بس لو **أقصى `_ts` في كل النتيجة ≤ since**.
 *
 * نتيجتان لازم يفضلوا زي ما هما:
 *  • القايمة بترجع **كاملة** لو فيها أي صف أحدث من since — مش الدلتا بس.
 *  • قايمة **فاضية** بترجع `changed:true` مع `items:[]` (لأن maxTs = 0
 *    والشرط `$maxTs > 0` بيفشل)، مش `changed:false`.
 *
 * الاستعلامات خام بـ DB::select زي الأصل — نفس الـjoins ونفس أسماء الأعمدة
 * المشتقة (`pilot_name` / `branch_name` / `from_branch_name` …) لأن
 * `BoardWire` بيقراها بالاسم ده بالظبط، ونفس السقوف (500 للورديات، 300
 * للطلبات، 200 لطلبات الدعم).
 */
class BoardController
{
    use BroadcastsOrders;
    use NotifiesPilotRequests;

    /* ═══════════════════════════════════════════════════════════
       اللوحة المجمّعة
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/board?branch=X&since=
     *
     * قلب اللوحة: الطيارين بحالاتهم + الدور + أعداد الطلبات المعلقة.
     * الرد **مش** غلاف قايمة — كائن مركّب بمفاتيح ثابتة:
     * ok · serverNow · changed · pilots · queue · pending.
     *
     * الأدوار: admin · branch (board_require_staff).
     * 🔒 مشرف الفرع مقفول على فرعه عبر `branchScope` — الـ`?branch=` بتاعه
     * بيتجاهل تمامًا. الإدارة **لازم** تحدد `?branch=` وإلا 400.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        $branchId = $this->branchScope($actor, $this->queryBranch($request));
        if (! $branchId) {
            throw new ApiException('حدّد الفرع ?branch=');
        }

        /* ترتيب اللوحة: اللي في الدور الأول بترتيب رقمه، وبعدين الباقي
           بالاسم. `(p.queue_no IS NULL)` بيرمي الـNULL آخر الحتة. */
        $pilotRows = DB::select(
            "SELECT p.*, b.name AS assigned_branch_name, u.username,
                    (SELECT COUNT(*) FROM orders o WHERE o.pilot_id = p.id AND o.status = 'delivering') AS active_orders
               FROM pilots p
               LEFT JOIN branches b ON b.id = p.assigned_branch_id
               LEFT JOIN users u ON u.pilot_id = p.id
              WHERE p.assigned_branch_id = ?
              ORDER BY (p.queue_no IS NULL), p.queue_no, p.name",
            [$branchId]
        );

        // أعداد الطلبات المعلقة — COUNTs خفيفة، مش تحميل الطلبات نفسها
        $pending = [
            'shiftRequests'  => $this->countOf("SELECT COUNT(*) FROM pilot_shift_requests WHERE branch_id = ? AND status = 'pending'", [$branchId]),
            'leaveRequests'  => $this->countOf("SELECT COUNT(*) FROM pilot_leave_requests WHERE branch_id = ? AND status = 'pending'", [$branchId]),
            'returnRequests' => $this->countOf("SELECT COUNT(*) FROM pilot_return_requests WHERE branch_id = ? AND status = 'pending'", [$branchId]),
            'joinRequests'   => $this->countOf("SELECT COUNT(*) FROM pilot_join_requests WHERE branch_id = ? AND status = 'pending'", [$branchId]),
            'transfers'      => $this->countOf("SELECT COUNT(*) FROM pilot_transfers WHERE (from_branch_id = ? OR to_branch_id = ?) AND status = 'pending'", [$branchId, $branchId]),
            /* الدعم: الفرع بيشوف بس الإنذارات اللي **مش بتاعته**، واللي
               موجّهة له أو broadcast، واللي **لسه مردّش عليها**. من غير
               الشرط الأخير كان العدّاد يفضل شغّال بعد ما الفرع يعتذر. */
            'support'        => $this->countOf(
                "SELECT COUNT(*) FROM pilot_support_requests r
                  WHERE r.status = 'pending' AND r.requesting_branch_id <> ?
                    AND (r.from_branch_id IS NULL OR r.from_branch_id = ?)
                    AND NOT EXISTS (SELECT 1 FROM pilot_support_responses x WHERE x.request_id = r.id AND x.branch_id = ?)",
                [$branchId, $branchId, $branchId]
            ),
            // الطيارين اللي الفرع طلبهم واتقبل الطلب وفعلًا اتحدد طيار
            'sentPilots'     => $this->countOf(
                "SELECT COUNT(*) FROM pilot_support_requests WHERE requesting_branch_id = ? AND status = 'accepted' AND pilot_id IS NOT NULL",
                [$branchId]
            ),
        ];

        /* دعم ?since هنا مختلف عن القوايم: بيجمع أقصى طابع وقت من صفوف
           الطيارين **و** من كل جداول الطلبات في استعلام GREATEST واحد،
           عشان لوحة بتستطلع كل 8 ثواني ما تسحبش الطيارين كلهم على الفاضي. */
        $since = $this->sinceParam($request);
        if ($since > 0) {
            $maxTs = 0;
            foreach ($pilotRows as $r) {
                $maxTs = max($maxTs, $this->rowTs((array) $r, ['status_since', 'break_started_at', 'location_updated_at', 'created_at']));
            }

            $dt = $this->firstColumn(
                "SELECT GREATEST(
                    COALESCE((SELECT MAX(GREATEST(requested_at, COALESCE(responded_at, requested_at))) FROM pilot_shift_requests  WHERE branch_id = ?), '1970-01-01'),
                    COALESCE((SELECT MAX(GREATEST(requested_at, COALESCE(responded_at, requested_at), COALESCE(ended_at, requested_at))) FROM pilot_leave_requests WHERE branch_id = ?), '1970-01-01'),
                    COALESCE((SELECT MAX(requested_at) FROM pilot_return_requests WHERE branch_id = ?), '1970-01-01'),
                    COALESCE((SELECT MAX(created_at) FROM pilot_join_requests WHERE branch_id = ?), '1970-01-01'),
                    COALESCE((SELECT MAX(GREATEST(requested_at, COALESCE(resolved_at, requested_at))) FROM pilot_transfers WHERE from_branch_id = ? OR to_branch_id = ?), '1970-01-01'),
                    COALESCE((SELECT MAX(created_at) FROM pilot_support_requests), '1970-01-01'),
                    COALESCE((SELECT MAX(responded_at) FROM pilot_support_responses), '1970-01-01')
                )",
                [$branchId, $branchId, $branchId, $branchId, $branchId, $branchId]
            );
            if ($dt) {
                $t = strtotime($dt . ' UTC');
                if ($t !== false) {
                    $maxTs = max($maxTs, $t * 1000);
                }
            }

            if ($maxTs > 0 && $maxTs <= $since) {
                return PollableList::unchanged();
            }
        }

        $pilots = [];
        $queue  = [];
        foreach ($pilotRows as $row) {
            $r = (array) $row;
            // مشرف الطيارين بياخد الكارت بلا عهدة/مرتب/عمولة — CoreWire::pilotFor
            $pilots[] = CoreWire::pilotFor($actor->role, $r);
            // الدور = اللي حالته waiting **وله رقم دور** فعلًا
            if ($r['status'] === 'waiting' && $r['queue_no'] !== null) {
                $queue[] = ['pilotId' => (int) $r['id'], 'name' => $r['name'], 'queueNo' => (int) $r['queue_no']];
            }
        }
        usort($queue, fn ($a, $b) => $a['queueNo'] <=> $b['queueNo']);

        return ApiResponse::out([
            'ok'        => true,
            'serverNow' => PollableList::serverNowMs(),
            'changed'   => true,
            'pilots'    => $pilots,
            'queue'     => $queue,
            'pending'   => $pending,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       الورديات
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/shifts?branch=&pilot=&status=&since=
     *
     * 🔒 `require_auth()` بس — **أي حساب مسجّل** (طيار/محل/عميل) يقدر يسحب
     * آخر 500 وردية في الشركة بأسماء الطيارين والحوافز والخصومات والسلف.
     * التوسّع ده في الأصل بالحرف؛ التضييق قرار منفصل عن الترحيل.
     *
     * `?status` بتتقبل active/ended بس — أي قيمة تانية **بتتجاهل** (مش خطأ).
     */
    public function shiftsList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        $where = [];
        $args  = [];

        $branchId = $this->branchScope($actor, $this->queryBranch($request));
        if ($branchId) {
            $where[] = 's.branch_id = ?';
            $args[]  = $branchId;
        }
        $pilot = $request->query('pilot');
        if (! empty($pilot)) {
            $where[] = 's.pilot_id = ?';
            $args[]  = (int) $pilot;
        }
        $status = $request->query('status');
        if (! empty($status) && in_array($status, ['active', 'ended'], true)) {
            $where[] = 's.status = ?';
            $args[]  = $status;
        }

        $rows = DB::select(
            'SELECT s.*, p.name AS pilot_name, b.name AS branch_name
               FROM shifts s
               JOIN pilots p ON p.id = s.pilot_id
               JOIN branches b ON b.id = s.branch_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY s.id DESC LIMIT 500',
            $args
        );

        // بصمة الفروع لكل الورديات المعروضة دفعة واحدة — مش استعلام لكل وردية
        $histByShift = [];
        if ($rows) {
            $ids = array_map(fn ($r) => (int) $r->id, $rows);
            foreach (DB::select(
                'SELECT h.*, b.name AS branch_name FROM shift_branch_history h
                   JOIN branches b ON b.id = h.branch_id
                  WHERE h.shift_id IN (' . $this->placeholders($ids) . ') ORDER BY h.shift_id, h.moved_at, h.id',
                $ids
            ) as $h) {
                $h = (array) $h;
                $histByShift[(int) $h['shift_id']][] = $h;
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $r  = (array) $row;
            $it = BoardWire::shift($r, $histByShift[(int) $r['id']] ?? []);
            $it['_ts'] = $this->rowTs($r, ['started_at', 'ended_at', 'transferred_at', 'created_at']);
            $items[] = $it;
        }

        return $this->listOut($request, $items);
    }

    /* ═══════════════════════════════════════════════════════════
       التقفيلة الشهرية
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/closeouts/monthly?pilot=X&month=YYYY-MM
     *
     * بيرجّع مفتاحين: `computed` (محسوب لحظيًا من الورديات) و`saved`
     * (اللقطة المحفوظة أو null). الاتنين موجودين عمدًا — الواجهة بتعرض
     * الفرق بينهم قبل ما المسؤول يقفل الشهر.
     *
     * الأدوار: admin · branch. ⚠️ مفيش أي فحص نطاق على الطيار هنا —
     * مشرف أي فرع يقدر يشوف تقفيلة أي طيار (بمرتبه وسلفه). الأصل كده
     * بالحرف: `board_assert_pilot_in_scope` مش متندى عليها في المسار ده.
     */
    public function closeoutGet(Request $request): JsonResponse
    {
        $pilotId = $this->intId($request->query('pilot', 0));
        $month   = (string) $request->query('month', '');

        $data = $this->buildMonthlyData($pilotId, $month);
        $p    = $data['pilot'];

        $saved = DB::select(
            'SELECT c.*, p.name AS pilot_name FROM pilot_monthly_closeouts c JOIN pilots p ON p.id = c.pilot_id WHERE c.pilot_id = ? AND c.month = ?',
            [$pilotId, $month]
        )[0] ?? null;

        return ApiResponse::out([
            'ok' => true,
            'computed' => [
                'pilotId'     => (int) $p['id'],
                'pilotName'   => $p['name'],
                'monthKey'    => $data['monthKey'],
                'daysInMonth' => $data['daysInMonth'],
                'shiftsCount' => $data['shiftsCount'],
                'workDays'    => $data['workDays'],
                'totalHours'  => $data['totalHours'],
                'totalCommission' => $data['totalCommission'],
                'deliveredCount'  => $data['deliveredCount'],
                'totalOrders'     => $data['totalOrders'],
                'totalBonus'      => $data['totalBonus'],
                'totalDeduction'  => $data['totalDeduction'],
                'totalAdvance'    => $data['totalAdvance'],
                'monthlySalary'   => (float) $p['monthly_salary'],
                'requiredDailyHours' => $p['required_daily_hours'] !== null ? (float) $p['required_daily_hours'] : null,
            ],
            'saved' => $saved ? BoardWire::closeout($saved) : null,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       الطلبات الإدارية — قوايم
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/join-requests?branch=&status=&since=
     * الأدوار: admin · branch. الـjoin على branches شمالي (LEFT) لأن
     * التقديم الذاتي من التطبيق ممكن يكون من غير فرع محدد.
     */
    public function joinRequestsList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        [$where, $args] = $this->branchAndStatusFilters($request, $actor, 'r.branch_id');

        $rows = DB::select(
            'SELECT r.*, b.name AS branch_name FROM pilot_join_requests r
               LEFT JOIN branches b ON b.id = r.branch_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY r.id DESC LIMIT 300',
            $args
        );

        $items = [];
        foreach ($rows as $row) {
            $r  = (array) $row;
            $it = BoardWire::joinRequest($r);
            $it['_ts'] = $this->rowTs($r, ['created_at']);
            $items[] = $it;
        }

        return $this->listOut($request, $items);
    }

    /**
     * GET /api/leave-requests?branch=&status=&since=
     *
     * 🔒 `require_auth()` — والطيار بيتقفل على طلباته هو عبر `pilot_id`
     * المربوط بحسابه. أي دور تاني (محل/عميل/كول سنتر) بيمشي على مسار
     * الفرع، يعني بيشوف طلبات الفرع اللي في `?branch=` — أو **الكل**
     * لو مابعتش الباراميتر. الأصل كده بالحرف.
     */
    public function leaveRequestsList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        [$where, $args] = $this->pilotOrBranchFilters($request, $actor);

        $rows = DB::select(
            'SELECT r.*, p.name AS pilot_name, b.name AS branch_name
               FROM pilot_leave_requests r
               JOIN pilots p ON p.id = r.pilot_id
               JOIN branches b ON b.id = r.branch_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY r.id DESC LIMIT 300',
            $args
        );

        $items = [];
        foreach ($rows as $row) {
            $r  = (array) $row;
            $it = BoardWire::leaveRequest($r);
            $it['_ts'] = $this->rowTs($r, ['requested_at', 'responded_at', 'ended_at', 'created_at']);
            $items[] = $it;
        }

        return $this->listOut($request, $items);
    }

    /** GET /api/shift-requests?branch=&status=&since= — نفس نطاق الأذونات */
    public function shiftRequestsList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        [$where, $args] = $this->pilotOrBranchFilters($request, $actor);

        $rows = DB::select(
            'SELECT r.*, p.name AS pilot_name, b.name AS branch_name
               FROM pilot_shift_requests r
               JOIN pilots p ON p.id = r.pilot_id
               JOIN branches b ON b.id = r.branch_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY r.id DESC LIMIT 300',
            $args
        );

        $items = [];
        foreach ($rows as $row) {
            $r  = (array) $row;
            $it = BoardWire::shiftRequest($r);
            $it['_ts'] = $this->rowTs($r, ['requested_at', 'responded_at', 'created_at']);
            $items[] = $it;
        }

        return $this->listOut($request, $items);
    }

    /** GET /api/return-requests?branch=&status=&since= — نفس نطاق الأذونات */
    public function returnRequestsList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        [$where, $args] = $this->pilotOrBranchFilters($request, $actor);

        $rows = DB::select(
            'SELECT r.*, p.name AS pilot_name, b.name AS branch_name
               FROM pilot_return_requests r
               JOIN pilots p ON p.id = r.pilot_id
               JOIN branches b ON b.id = r.branch_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY r.id DESC LIMIT 300',
            $args
        );

        $items = [];
        foreach ($rows as $row) {
            $r  = (array) $row;
            $it = BoardWire::returnRequest($r);
            // بس requested_at و created_at — السكيمة ملهاش عمود رد على الطلب
            $it['_ts'] = $this->rowTs($r, ['requested_at', 'created_at']);
            $items[] = $it;
        }

        return $this->listOut($request, $items);
    }

    /**
     * GET /api/pilot-transfers?branch=&status=&since=
     * الأدوار: admin · branch.
     * الفرع بيشوف النقلات **الداخلة والخارجة** — الشرط على العمودين.
     */
    public function transfersList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        $where = [];
        $args  = [];

        $branchId = $this->branchScope($actor, $this->queryBranch($request));
        if ($branchId) {
            $where[] = '(t.from_branch_id = ? OR t.to_branch_id = ?)';
            $args[]  = $branchId;
            $args[]  = $branchId;
        }
        $status = $request->query('status');
        if (! empty($status)) {
            $where[] = 't.status = ?';
            $args[]  = $status;
        }

        $rows = DB::select(
            'SELECT t.*, p.name AS pilot_name, fb.name AS from_branch_name, tb.name AS to_branch_name
               FROM pilot_transfers t
               JOIN pilots p ON p.id = t.pilot_id
               JOIN branches fb ON fb.id = t.from_branch_id
               JOIN branches tb ON tb.id = t.to_branch_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY t.id DESC LIMIT 300',
            $args
        );

        $items = [];
        foreach ($rows as $row) {
            $r  = (array) $row;
            $it = BoardWire::transfer($r);
            $it['_ts'] = $this->rowTs($r, ['requested_at', 'resolved_at', 'created_at']);
            $items[] = $it;
        }

        return $this->listOut($request, $items);
    }

    /**
     * GET /api/support-requests?status=&since=
     *
     * الأدوار: admin · branch. ⚠️ **مفيش فلترة بالفرع خالص** — كل فرع
     * بيشوف طلبات الدعم كلها (آخر 200) وبيفلتر عنده. ده الأصل بالحرف،
     * وهو نفس السبب اللي خلّى عدّاد `pending.support` في اللوحة يتحسب
     * بشروط الفرع في الـSQL بدل ما يتحسب من القايمة دي.
     *
     * الردود بترجع **جوه** كل طلب في `respondedBranches`، وبتتحمّل دفعة
     * واحدة لكل الطلبات المعروضة.
     */
    public function supportRequestsList(Request $request): JsonResponse
    {
        $request->actorOrFail();

        $where = [];
        $args  = [];
        $status = $request->query('status');
        if (! empty($status)) {
            $where[] = 'r.status = ?';
            $args[]  = $status;
        }

        $rows = DB::select(
            'SELECT r.*, rb.name AS requesting_branch_name, ab.name AS accepted_by_branch_name, p.name AS pilot_name
               FROM pilot_support_requests r
               JOIN branches rb ON rb.id = r.requesting_branch_id
               LEFT JOIN branches ab ON ab.id = r.accepted_by_branch_id
               LEFT JOIN pilots p ON p.id = r.pilot_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY r.id DESC LIMIT 200',
            $args
        );

        $respByReq = [];
        if ($rows) {
            $ids = array_map(fn ($r) => (int) $r->id, $rows);
            foreach (DB::select(
                'SELECT * FROM pilot_support_responses WHERE request_id IN (' . $this->placeholders($ids) . ') ORDER BY id',
                $ids
            ) as $resp) {
                $resp = (array) $resp;
                $respByReq[(int) $resp['request_id']][] = $resp;
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $r     = (array) $row;
            $resps = $respByReq[(int) $r['id']] ?? [];
            $it    = BoardWire::supportRequest($r, $resps);
            // طابع الوقت بياخد **رد الفروع** في الحسبان كمان — رد جديد على
            // طلب قديم لازم يكسر الـsince وإلا الإنذار بيفضل شكله متعلّق
            $ts = $this->rowTs($r, ['created_at']);
            foreach ($resps as $resp) {
                $ts = max($ts, $this->rowTs($resp, ['responded_at', 'created_at']));
            }
            $it['_ts'] = $ts;
            $items[] = $it;
        }

        return $this->listOut($request, $items);
    }

    /* ═══════════════════════════════════════════════════════════
       دور الانتظار — مسارات الكتابة
       (كل عملية جوه معاملة واحدة: القفل والإزاحة والترقيم لازم يبقوا ذرّيين)
    ═══════════════════════════════════════════════════════════ */

    /**
     * POST /api/queue/enter — {pilotId, branchId?} دخول الطيار آخر الدور.
     * الأدوار: admin · branch.
     *
     * ⚠️ نمط الـcatch هنا منقول من الأصل بالحرف: الأصل بيلف كل حاجة في
     * try/catch(Throwable) وبيرجّع رسالة واحدة عامة 500. لكن `fail()` القديمة
     * بتعمل **exit** فمابتقعش في الـcatch أبدًا — عشان كده بنعيد رمي
     * `ApiException` زي ما هي قبل الـcatch العام، وإلا كنا هنحوّل كل 400/404
     * لـ500 وده تغيير سلوك.
     */
    public function queueEnter(Request $request): JsonResponse
    {
        $actor    = $request->actorOrFail();
        $pilotId  = $this->intId($request->input('pilotId', 0));
        $branchIn = $request->input('branchId');

        try {
            $queueNo = DB::transaction(function () use ($actor, $pilotId, $branchIn): int {
                $pilot = $this->lockPilot($pilotId);
                if ($pilot['status'] === 'waiting') {
                    throw new ApiException('الطيار في الدور بالفعل');
                }
                // فرع اللوحة يغلب المبعوت، وبعده فرع الطيار المسجّل
                $branchId = $this->branchScope($actor, $branchIn !== null ? $this->intId($branchIn) : null)
                    ?? ($pilot['assigned_branch_id'] !== null ? (int) $pilot['assigned_branch_id'] : null);
                if (! $branchId) {
                    throw new ApiException('حدّد الفرع');
                }
                $now = WireTime::nowDb();

                return $this->enterQueue($pilotId, $branchId, $now);
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر إدخال الطيار في الدور', 500);
        }

        return ApiResponse::out(['ok' => true, 'queueNo' => $queueNo]);
    }

    /**
     * POST /api/queue/reorder — {branchId, pilotIds:[..]} إعادة ترتيب الدور بالكامل.
     * الأدوار: admin · branch.
     *
     * المنطق: بيقفل كل المنتظرين في الفرع، بيرقّم اللي في القايمة المبعوتة
     * بترتيبها، وبعدين **أي منتظر مش في القايمة بياخد آخر الدور بترتيبه القديم**
     * (مش بيتساب برقمه القديم) — كده الأرقام تفضل متراصة 1..n من غير فجوات.
     *
     * ⚠️ `array_filter` من غير callback بتشيل الأصفار — يعني `pilotIds:[0,5]`
     * بيبقى `[5]` بلا خطأ. سلوك الأصل بالحرف.
     */
    public function queueReorder(Request $request): JsonResponse
    {
        $actor    = $request->actorOrFail();
        $branchIn = $request->input('branchId');

        $branchId = $this->branchScope($actor, $branchIn !== null ? $this->intId($branchIn) : null);
        if (! $branchId) {
            throw new ApiException('حدّد الفرع');
        }
        $ids = array_values(array_filter(array_map('intval', (array) ($request->input('pilotIds') ?? []))));
        if (! $ids) {
            throw new ApiException('ابعت ترتيب الطيارين pilotIds');
        }

        try {
            DB::transaction(function () use ($branchId, $ids): void {
                // قفل كل المنتظرين في الفرع
                $waiting = array_map(
                    fn ($r) => (int) $r->id,
                    DB::select("SELECT id FROM pilots WHERE assigned_branch_id = ? AND status = 'waiting' FOR UPDATE", [$branchId])
                );
                $sql = "UPDATE pilots SET queue_no = ? WHERE id = ? AND assigned_branch_id = ? AND status = 'waiting'";
                $no  = 1;
                foreach ($ids as $pid) {
                    if (in_array($pid, $waiting, true)) {
                        DB::update($sql, [$no, $pid, $branchId]);
                        $no++;
                    }
                }
                // أي منتظر مش في القائمة المبعوتة بياخد آخر الدور بترتيبه القديم
                foreach ($waiting as $pid) {
                    if (! in_array($pid, $ids, true)) {
                        DB::update($sql, [$no, $pid, $branchId]);
                        $no++;
                    }
                }
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر إعادة ترتيب الدور', 500);
        }

        return ApiResponse::ok();
    }

    /**
     * POST /api/queue/leave — {pilotId} خروج من الدور وتحرير كامل من الفرع
     * (المقابل لـ removePilotFromPanel). الأدوار: admin · branch.
     *
     * الطيار «جاري التوصيل» **مايتشالش** — فلوس أوردراته لسه معاه، والتحرير
     * كان هيضيّع الرابط بين الأوردر والفرع قبل التسوية.
     */
    public function queueLeave(Request $request): JsonResponse
    {
        $request->actorOrFail();
        $pilotId = $this->intId($request->input('pilotId', 0));

        try {
            DB::transaction(function () use ($pilotId): void {
                $pilot = $this->lockPilot($pilotId);
                if ($pilot['status'] === 'delivering') {
                    throw new ApiException('الطيار جارٍ التوصيل — سوّي أوردراته الأول');
                }
                $this->releasePilot($pilot);
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر إخراج الطيار من الدور', 500);
        }

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       الورديات — مسارات الكتابة
    ═══════════════════════════════════════════════════════════ */

    /**
     * POST /api/shifts/open — {pilotId, branchId?} فتح يدوي من اللوحة
     * (branchOpenShiftDirect). الأدوار: admin · branch.
     *
     * الشرط `status !== null && status !== ''` معناه **الطيار لازم يكون حر
     * تمامًا** — لا في دور ولا بيوصّل ولا في إذن. النص فاضي بيتعامل كحر كمان
     * (صفوف قديمة اتكتبت بـ'' بدل NULL).
     *
     * الترتيب مهم: الطيار بيدخل الدور الأول ثم الوردية بتتفتح — لأن
     * `openOrTransferShift` ممكن **تنقل** وردية مفتوحة من فرع تاني بدل ما
     * تفتح جديدة، والقفل على صف الطيار لازم يكون واخد قبلها.
     */
    public function shiftOpen(Request $request): JsonResponse
    {
        $actor    = $request->actorOrFail();
        $pilotId  = $this->intId($request->input('pilotId', 0));
        $branchIn = $request->input('branchId');

        try {
            $shiftId = DB::transaction(function () use ($actor, $pilotId, $branchIn): int {
                $pilot = $this->lockPilot($pilotId);
                if ($pilot['status'] !== null && $pilot['status'] !== '') {
                    throw new ApiException('الطيار عنده وردية مفتوحة بالفعل أو في إذن');
                }
                $branchId = $this->branchScope($actor, $branchIn !== null ? $this->intId($branchIn) : null)
                    ?? ($pilot['assigned_branch_id'] !== null ? (int) $pilot['assigned_branch_id'] : null);
                if (! $branchId) {
                    throw new ApiException('حدّد الفرع');
                }
                $now = WireTime::nowDb();
                $this->enterQueue($pilotId, $branchId, $now);

                return $this->openOrTransferShift($pilotId, $branchId, $actor, true, $now);
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر فتح الوردية', 500);
        }

        return ApiResponse::out(['ok' => true, 'shiftId' => $shiftId]);
    }

    /**
     * POST /api/shifts/{id}/transfer — {branchId} نقل الوردية النشطة لفرع تاني.
     * الأدوار: admin · branch.
     *
     * الوردية **مابتتقفلش وتتفتح تاني** — نفس الصف بيتنقل عشان `shift_id`
     * بتاع الأوردرات يفضل واحد فتجميع التقفيلة يشمل شغل الفرعين. وصف
     * `shift_branch_history` هو اللي بيحفظ بصمة الفروع.
     *
     * ⚠️ النقل لنفس الفرع = لا شيء (ولا حتى صف تاريخ) لكن الرد `ok:true`.
     * ⚠️ مفيش أي فحص نطاق على الفرع الجديد ولا على الطيار — مشرف أي فرع يقدر
     * ينقل أي وردية لأي فرع. الأصل كده بالحرف؛ الإصلاح قرار منفصل.
     */
    public function shiftTransfer(Request $request, string $id): JsonResponse
    {
        $actor       = $request->actorOrFail();
        $shiftId     = $this->intId($id);
        $toBranchId  = $this->intId($request->input('branchId', 0));

        try {
            DB::transaction(function () use ($actor, $shiftId, $toBranchId): void {
                $row = DB::select('SELECT * FROM shifts WHERE id = ? FOR UPDATE', [$shiftId])[0] ?? null;
                if (! $row) {
                    throw ApiException::notFound('الوردية غير موجودة');
                }
                $shift = (array) $row;
                if ($shift['status'] !== 'active') {
                    throw new ApiException('الوردية مقفولة — مينفعش نقلها');
                }
                $now = WireTime::nowDb();
                if ((int) $shift['branch_id'] !== $toBranchId) {
                    DB::update(
                        'UPDATE shifts SET branch_id = ?, transferred_at = ?, transferred_by = ? WHERE id = ?',
                        [$toBranchId, $now, $actor->username, $shiftId]
                    );
                    DB::insert(
                        'INSERT INTO shift_branch_history (shift_id, branch_id, moved_at, created_at) VALUES (?,?,?,?)',
                        [$shiftId, $toBranchId, $now, $now]
                    );
                }
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر نقل الوردية', 500);
        }

        return ApiResponse::ok();
    }

    /**
     * POST /api/shifts/{id}/settlement — حفظ بنود التقفيلة على الوردية
     * (bonus/deduction/advance + نوع التسوية). الأدوار: admin · branch.
     *
     * 💰 **جملة UPDATE واحدة بلا معاملة ولا قفل** — زي الأصل بالحرف. البنود
     * دي بتتقرا بعدين في التقفيلة الشهرية، فالقيم بتتكتب كاملة كل مرة (مش
     * فرقية) واللي مابيتبعتش بياخد صفر/فاضي مش قيمته القديمة. مختلف عن
     * `shiftEnd` اللي بيستخدم قيمة الصف كافتراضي.
     *
     * ⚠️ الافتراضي هنا **monthly** لأي قيمة غير daily/monthly (بما فيها
     * الغياب) — عكس السلك اللي بيقول daily. تناقض موروث ومنقول زي ما هو.
     *
     * ⚠️ `rowCount()` في MySQL بيرجّع **الصفوف اللي اتغيّرت فعلًا** مش
     * المطابقة، فحفظ نفس القيم تاني بيدي 0 — عشان كده الأصل بيتأكد إن الصف
     * موجود قبل ما يقول 404، ولو موجود بيرجّع ok:true.
     */
    public function shiftSettlement(Request $request, string $id): JsonResponse
    {
        $request->actorOrFail();
        $shiftId = $this->intId($id);

        // الافتراضي القديم monthly
        $settle = fn ($v) => in_array($v, ['daily', 'monthly'], true) ? $v : 'monthly';

        $n = DB::update(
            'UPDATE shifts SET bonus_amount = ?, bonus_reason = ?, deduction_amount = ?, deduction_reason = ?,
                advance_amount = ?, advance_reason = ?,
                commission_settle = ?, bonus_settle = ?, deduction_settle = ?, advance_settle = ?
          WHERE id = ?',
            [
                (float) ($request->input('bonusAmount') ?? 0),
                trim((string) ($request->input('bonusReason') ?? '')) ?: null,
                (float) ($request->input('deductionAmount') ?? 0),
                trim((string) ($request->input('deductionReason') ?? '')) ?: null,
                (float) ($request->input('advanceAmount') ?? 0),
                trim((string) ($request->input('advanceReason') ?? '')) ?: null,
                $settle($request->input('commissionSettle', 'monthly')),
                $settle($request->input('bonusSettle', 'monthly')),
                $settle($request->input('deductionSettle', 'monthly')),
                $settle($request->input('advanceSettle', 'monthly')),
                $shiftId,
            ]
        );

        if (! $n) {
            $chk = DB::select('SELECT id FROM shifts WHERE id = ?', [$shiftId]);
            if (! $chk) {
                throw ApiException::notFound('الوردية غير موجودة');
            }
        }

        return ApiResponse::ok();
    }

    /**
     * POST /api/shifts/{id}/end — إنهاء الوردية بتسوية إجبارية
     * (branchEndPilotShift + finalizeShiftEnd). الأدوار: admin · branch.
     *
     * body: { orders:[{orderId,choice,reason}], collectedAmount, cashStoreId,
     *         bonusAmount/bonusReason/deductionAmount/deductionReason/
     *         advanceAmount/advanceReason,
     *         commissionSettle/bonusSettle/deductionSettle/advanceSettle }
     *
     * 🔴 التسلسل ده **فلوس** ولازم يفضل بترتيبه: (1) تسوية الأوردرات والخزنة
     * والعهدة، (2) بنود التقفيلة + قفل الوردية، (3) تحرير الطيار من الفرع.
     * الترتيب مش تجميلي: `settlePilotMoney` بيقفل صفوف الأوردرات وهو شايف
     * الطيار لسه مربوط بالفرع، والتحرير بيمسح الربط ده.
     *
     * 💰 الفرق عن `shiftSettlement`: البند اللي **مش مبعوت** بياخد **قيمته
     * الحالية من صف الوردية** مش صفر — عشان إنهاء الوردية من شاشة مالهاش
     * حقول التقفيلة ما يمسحش مكافأة اتسجّلت قبل كده.
     */
    public function shiftEnd(Request $request, string $id): JsonResponse
    {
        $actor   = $request->actorOrFail();
        $shiftId = $this->intId($id);

        try {
            [$deliveredCount, $undeliveredCount, $settledCount] = DB::transaction(
                function () use ($request, $actor, $shiftId): array {
                    $row = DB::select('SELECT * FROM shifts WHERE id = ? FOR UPDATE', [$shiftId])[0] ?? null;
                    if (! $row) {
                        throw ApiException::notFound('الوردية غير موجودة');
                    }
                    $shift = (array) $row;
                    if ($shift['status'] !== 'active') {
                        throw new ApiException('الوردية مقفولة بالفعل');
                    }

                    $pilot    = $this->lockPilot((int) $shift['pilot_id']);
                    $now      = WireTime::nowDb();
                    $branchId = $this->branchScope($actor, (int) $shift['branch_id']);

                    // 1) تسوية الأوردرات والفلوس (إجبارية لو فيه جاري/غير مُسوّى)
                    $ordersIn    = $request->input('orders');
                    $cashStoreIn = $request->input('cashStoreId');
                    [, $deliveredCount, $undeliveredCount, $settledCount] = $this->settlePilotMoney(
                        $pilot,
                        is_array($ordersIn) ? $ordersIn : [],
                        (float) ($request->input('collectedAmount') ?? 0),
                        $cashStoreIn ? $this->intId($cashStoreIn) : null,
                        $branchId,
                        $actor,
                        $now
                    );

                    // 2) بنود التقفيلة على الوردية (لو اتبعتت) + القفل
                    $settle = fn ($v, $cur) => in_array($v, ['daily', 'monthly'], true) ? $v : $cur;
                    DB::update(
                        'UPDATE shifts SET bonus_amount = ?, bonus_reason = ?, deduction_amount = ?, deduction_reason = ?,
                    advance_amount = ?, advance_reason = ?,
                    commission_settle = ?, bonus_settle = ?, deduction_settle = ?, advance_settle = ?,
                    status = \'ended\', ended_at = ?, ended_by = ?
              WHERE id = ?',
                        [
                            (float) $request->input('bonusAmount', $shift['bonus_amount']),
                            trim((string) $request->input('bonusReason', $shift['bonus_reason'] ?? '')) ?: null,
                            (float) $request->input('deductionAmount', $shift['deduction_amount']),
                            trim((string) $request->input('deductionReason', $shift['deduction_reason'] ?? '')) ?: null,
                            (float) $request->input('advanceAmount', $shift['advance_amount']),
                            trim((string) $request->input('advanceReason', $shift['advance_reason'] ?? '')) ?: null,
                            $settle($request->input('commissionSettle'), $shift['commission_settle']),
                            $settle($request->input('bonusSettle'), $shift['bonus_settle']),
                            $settle($request->input('deductionSettle'), $shift['deduction_settle']),
                            $settle($request->input('advanceSettle'), $shift['advance_settle']),
                            $now,
                            $actor->username,
                            $shiftId,
                        ]
                    );

                    // 3) تحرير الطيار بالكامل (زي إزالة الطيار من اللوحة) + إزاحة الدور
                    $this->releasePilot($pilot);

                    return [$deliveredCount, $undeliveredCount, $settledCount];
                }
            );
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر إنهاء الوردية', 500);
        }

        return ApiResponse::out([
            'ok' => true,
            'deliveredCount'   => $deliveredCount,
            'undeliveredCount' => $undeliveredCount,
            'settledCount'     => $settledCount,
        ]);
    }

    /**
     * POST /api/pilots/{id}/return — عودة الطيار للفرع بتسوية
     * (المودال الأصلي «عودة الطيار»). الأدوار: admin · branch.
     * body: { orders:[{orderId,choice,reason}], collectedAmount, cashStoreId }
     *
     * 🔴 نفس تسوية الفلوس بتاعة إنهاء الوردية بالحرف، بس **من غير قفل
     * الوردية ولا تحرير الطيار**: الوردية بتفضل مفتوحة والطيار بيرجع
     * `waiting` في آخر الدور.
     *
     * ⚠️ فرع التسوية هنا **فرع الطيار المسجّل أولًا** مش فرع المشرف — عكس
     * `shiftEnd` اللي بياخد `branchScope`. يعني مشرف فرع بيسوّي طيار تابع
     * لفرع تاني، حركة الخزنة والعهدة بتتقيّد على **فرع الطيار**. منقول زي
     * ما هو. ومفيش `assertPilotInScope` هنا خالص.
     */
    public function pilotReturn(Request $request, string $id): JsonResponse
    {
        $actor   = $request->actorOrFail();
        $pilotId = $this->intId($id);

        try {
            [$next, $deliveredCount, $undeliveredCount, $settledCount] = DB::transaction(
                function () use ($request, $actor, $pilotId): array {
                    $pilot    = $this->lockPilot($pilotId);
                    $branchId = $pilot['assigned_branch_id'] !== null
                        ? (int) $pilot['assigned_branch_id']
                        : $this->branchScope($actor, null);
                    if (! $branchId) {
                        throw new ApiException('الطيار مش تابع لفرع');
                    }
                    $now = WireTime::nowDb();

                    $ordersIn    = $request->input('orders');
                    $cashStoreIn = $request->input('cashStoreId');
                    [, $deliveredCount, $undeliveredCount, $settledCount] = $this->settlePilotMoney(
                        $pilot,
                        is_array($ordersIn) ? $ordersIn : [],
                        (float) ($request->input('collectedAmount') ?? 0),
                        $cashStoreIn ? $this->intId($cashStoreIn) : null,
                        $branchId,
                        $actor,
                        $now
                    );

                    // الطيار بيرجع الانتظار في آخر الدور (منطق الأصل حرفيًا)
                    $next = $this->nextQueueNo($branchId);
                    DB::update(
                        "UPDATE pilots SET status = 'waiting', queue_no = ?, status_since = ? WHERE id = ?",
                        [$next, $now, $pilotId]
                    );

                    return [$next, $deliveredCount, $undeliveredCount, $settledCount];
                }
            );
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّرت تسوية عودة الطيار', 500);
        }

        return ApiResponse::out([
            'ok' => true,
            'queueNo'          => $next,
            'deliveredCount'   => $deliveredCount,
            'undeliveredCount' => $undeliveredCount,
            'settledCount'     => $settledCount,
        ]);
    }

    /**
     * POST /api/pilots/{id}/force-leave — {type, reason} إيقاف إجباري
     * (forcePilotLeave). الأدوار: admin · branch.
     *
     * الطلب بيتكتب `approved` فورًا ومعاه `forced_by`، والطيار بياخد
     * `leave_forced = 1` — وده اللي بيمنعه ينهي الإذن بنفسه من التطبيق
     * (الفحص في `POST /api/leave-requests/{id}/end`).
     *
     * 🔒 ده المسار الوحيد في المجموعة دي اللي بينده `assertPilotInScope`:
     * التعليق الأصلي بيقول إن الفحص **كان ناقص** فمشرف فرع كان يقدر يوقف
     * طيار فرع تاني. منقول بالحرف.
     *
     * ⚠️ نوع غير معروف بيتحوّل لـ`rest` بصمت (مش 400).
     * ⚠️ `leave_reason` على صف الطيار بياخد النص الفاضي زي ما هو، بينما صف
     * الطلب بياخد `null` (`?: null`). فرق موروث ومنقول.
     */
    public function pilotForceLeave(Request $request, string $id): JsonResponse
    {
        $actor   = $request->actorOrFail();
        $pilotId = $this->intId($id);

        $typeIn = $request->input('type');
        $type   = isset(Vocab::LEAVE_TYPE_WIRE[(string) ($typeIn ?? '')]) ? (string) $typeIn : 'rest';
        $reason = trim((string) ($request->input('reason') ?? ''));

        try {
            $newId = DB::transaction(function () use ($actor, $pilotId, $type, $reason): int {
                $pilot = $this->lockPilot($pilotId);
                $this->assertPilotInScope($actor, $pilot);   // فرع تاني = 403
                $now = WireTime::nowDb();
                $branchId = $pilot['assigned_branch_id'] !== null
                    ? (int) $pilot['assigned_branch_id']
                    : (int) ($actor->branchId ?? 0);
                if (! $branchId) {
                    throw new ApiException('الطيار مش تابع لفرع');
                }
                if ($pilot['status'] === 'waiting') {
                    $this->queueShiftAfterRemoval(
                        $branchId,
                        $pilot['queue_no'] !== null ? (int) $pilot['queue_no'] : null
                    );
                }
                DB::update(
                    "UPDATE pilots SET status = 'on_leave', queue_no = NULL, leave_type = ?, leave_reason = ?, break_started_at = ?, leave_forced = 1 WHERE id = ?",
                    [$type, $reason, $now, $pilotId]
                );
                DB::insert(
                    "INSERT INTO pilot_leave_requests (pilot_id, branch_id, type, reason, status, requested_at, responded_at, responded_by, forced_by, created_at)
             VALUES (?,?,?,?,'approved',?,?,?,?,?)",
                    [
                        $pilotId, $branchId, $type, $reason ?: null,
                        $now, $now, $actor->username,
                        $actor->role === 'admin' ? 'admin' : 'branch', $now,
                    ]
                );

                return (int) DB::getPdo()->lastInsertId();
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر إيقاف الطيار', 500);
        }

        return ApiResponse::out(['ok' => true, 'id' => $newId]);
    }

    /**
     * POST /api/closeouts/monthly — حفظ لقطة التقفيلة (saveMonthlyCloseout).
     * الأدوار: admin · branch.
     * body: {pilotId, month, salary, requiredDailyHours, paidLeaveDays, unpaidLeaveDays}
     *
     * 💰 معادلة الصافي بالحرف:
     *   net = salary + totalCommission + totalBonus
     *       − totalDeduction − totalAdvance − (unpaidLeaveDays × dailyRate)
     *   dailyRate = salary ÷ daysInMonth   (صفر لو الشهر بصفر أيام)
     *
     * والتقريب على **خانتين** بيتم على `dailyRate` و`net` **بس** وقت التخزين —
     * أما `totalCommission`/`totalBonus`/… فبتيجي مقرّبة أصلًا من
     * `buildMonthlyData` وبتتخزن كما هي. أي تقريب زيادة أو ناقص = فرق في
     * مستحقات الطيار.
     *
     * ⚠️ `paidLeaveDays` بيتخزّن لكنه **مش داخل في الحساب خالص** — بس أيام
     * «على حسابه» هي اللي بتخصم. سلوك الأصل.
     *
     * ⚠️ التخزين `ON DUPLICATE KEY UPDATE` — إعادة الحفظ لنفس (pilot, month)
     * بتدوس على اللقطة القديمة بالكامل، مفيش نسخ.
     *
     * ⚠️ المرتب والساعات بيتكتبوا على صف الطيار كافتراضي للشهر الجاي، و
     * `required_daily_hours = 0` بيتخزن **NULL** (`?: null`).
     */
    public function closeoutSave(Request $request): JsonResponse
    {
        $actor   = $request->actorOrFail();
        $pilotId = $this->intId($request->input('pilotId', 0));
        $month   = (string) ($request->input('month') ?? '');
        $data    = $this->buildMonthlyData($pilotId, $month);

        $salary      = (float) ($request->input('salary') ?? 0);
        $reqHours    = (float) ($request->input('requiredDailyHours') ?? 0);
        $paidLeave   = max(0, (int) ($request->input('paidLeaveDays') ?? 0));
        $unpaidLeave = max(0, (int) ($request->input('unpaidLeaveDays') ?? 0));
        $dailyRate   = $data['daysInMonth'] > 0 ? $salary / $data['daysInMonth'] : 0.0;
        $net = $salary + $data['totalCommission'] + $data['totalBonus']
             - $data['totalDeduction'] - $data['totalAdvance'] - $unpaidLeave * $dailyRate;

        try {
            DB::transaction(function () use (
                $actor, $pilotId, $month, $data, $salary, $reqHours, $paidLeave, $unpaidLeave, $dailyRate, $net
            ): void {
                $now = WireTime::nowDb();
                DB::insert(
                    'INSERT INTO pilot_monthly_closeouts
                (pilot_id, month, work_days, hours, delivered_count, commission, bonus, deductions, advances,
                 salary, required_daily_hours, paid_leave_days, unpaid_leave_days, daily_rate, net_due,
                 closed_at, closed_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                work_days = VALUES(work_days), hours = VALUES(hours), delivered_count = VALUES(delivered_count),
                commission = VALUES(commission), bonus = VALUES(bonus), deductions = VALUES(deductions),
                advances = VALUES(advances), salary = VALUES(salary),
                required_daily_hours = VALUES(required_daily_hours),
                paid_leave_days = VALUES(paid_leave_days), unpaid_leave_days = VALUES(unpaid_leave_days),
                daily_rate = VALUES(daily_rate), net_due = VALUES(net_due),
                closed_at = VALUES(closed_at), closed_by = VALUES(closed_by)',
                    [
                        $pilotId, $month, $data['workDays'], $data['totalHours'], $data['deliveredCount'],
                        $data['totalCommission'], $data['totalBonus'], $data['totalDeduction'], $data['totalAdvance'],
                        $salary, $reqHours, $paidLeave, $unpaidLeave,
                        round($dailyRate, 2), round($net, 2),
                        $now, $actor->username, $now,
                    ]
                );
                // المرتب والساعات بيتخزنوا كافتراضي على الطيار للشهر الجاي (زي الأصل)
                DB::update(
                    'UPDATE pilots SET monthly_salary = ?, required_daily_hours = ? WHERE id = ?',
                    [$salary, $reqHours ?: null, $pilotId]
                );
            });

            // القراءة **بعد** الـcommit زي الأصل — الرد هو اللقطة المخزّنة فعلًا
            $saved = DB::select(
                'SELECT c.*, p.name AS pilot_name FROM pilot_monthly_closeouts c JOIN pilots p ON p.id = c.pilot_id WHERE c.pilot_id = ? AND c.month = ?',
                [$pilotId, $month]
            )[0] ?? null;

            return ApiResponse::out(['ok' => true, 'closeout' => BoardWire::closeout($saved)]);
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر حفظ التقفيلة الشهرية', 500);
        }
    }

    /* ═══════════════════════════════════════════════════════════
       طلبات الانضمام — كتابة
       (الأصل: board_join_request_create / _approve / _reject)
    ═══════════════════════════════════════════════════════════ */

    /**
     * POST /api/join-requests — تقديم طيار جديد من الفرع/الإدارة
     * (submitPilotJoinRequest). الأدوار: admin · branch.
     *
     * ⚠️ التليفونان بيتخزنوا في **عمود واحد** `phones` بصيغة "p1,p2" —
     * والفاصلة بتتكتب بس لو التاني مش فاضي. `BoardWire::joinRequest`
     * بيفكّها تاني. ممنوع تتحوّل لعمودين هنا لأن السكيمة مجمّدة.
     *
     * ⚠️ مفيش معاملة — جملة INSERT واحدة زي الأصل بالحرف.
     */
    public function joinRequestCreate(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        $name   = trim((string) ($request->input('name') ?? ''));
        $phone1 = trim((string) ($request->input('phone1') ?? ''));
        if ($name === '') {
            throw new ApiException('يرجى إدخال اسم الطيار');
        }
        if ($phone1 === '') {
            throw new ApiException('يرجى إدخال رقم الهاتف');
        }
        $phone2 = trim((string) ($request->input('phone2') ?? ''));

        // فرع المشرف بيغلب المبعوت — والإدارة ممكن تسيبه فاضي (تقديم بلا فرع)
        $branchIn = $request->input('branchId');
        $branchId = $this->branchScope($actor, $branchIn ? $this->intId($branchIn) : null);

        DB::insert(
            "INSERT INTO pilot_join_requests (name, phones, card_num, vehicle_no, address, branch_id, requested_by, status, source, created_at)
             VALUES (?,?,?,?,?,?,?,'pending',?,?)",
            [
                $name,
                $phone2 !== '' ? $phone1 . ',' . $phone2 : $phone1,
                trim((string) ($request->input('cardNum') ?? '')) ?: null,
                trim((string) ($request->input('vehicleNo') ?? '')) ?: null,
                trim((string) ($request->input('address') ?? '')) ?: null,
                $branchId,
                $actor->username,
                $actor->role === 'admin' ? 'admin' : 'branch',
                WireTime::nowDb(),
            ]
        );

        return ApiResponse::out(['ok' => true, 'id' => (int) DB::getPdo()->lastInsertId()]);
    }

    /**
     * POST /api/join-requests/{id}/approve — {username, password}
     * (confirmApproveJoinRequest). الدور: admin **بس**.
     *
     * القبول بيولّد **كيانين**: صف في `pilots` وحساب في `users` مربوط بيه —
     * وعشان كده لازم معاملة واحدة: حساب من غير طيار (أو العكس) بيكسّر
     * تسجيل دخول التطبيق.
     *
     * ⚠️ `status` بتاع الطيار الجديد **NULL** مش 'waiting' — يعني حر تمامًا
     * لسه ما دخلش دور ولا فرع. ده اللي بيخلي `shifts/open` تقبله بعدين.
     * ⚠️ التجزئة هنا `PASSWORD_BCRYPT` صراحةً — بينما إنشاء المستخدمين في
     * `EntitiesController` بيستخدم `PASSWORD_DEFAULT`. الفرق موروث ومنقول
     * زي ما هو (الاتنين بيتحققوا بـ`password_verify` فمفيش أثر عملي دلوقتي).
     * ⚠️ فحص تكرار اسم المستخدم **جوه المعاملة** — بس من غير قفل، فسباق
     * نظري لسه ممكن ويتمسك بقيد الـUNIQUE على العمود.
     */
    public function joinRequestApprove(Request $request, string $id): JsonResponse
    {
        $request->actorOrFail();
        $reqId = $this->intId($id);

        $username = trim((string) ($request->input('username') ?? ''));
        $password = (string) ($request->input('password') ?? '');
        if ($username === '' || $password === '') {
            throw new ApiException('يرجى إدخال اسم المستخدم وكلمة المرور');
        }
        if (mb_strlen($password) < 4) {
            throw new ApiException('كلمة المرور يجب أن تكون 4 أحرف على الأقل');
        }

        try {
            $pilotId = DB::transaction(function () use ($reqId, $username, $password): int {
                $row = DB::select('SELECT * FROM pilot_join_requests WHERE id = ? FOR UPDATE', [$reqId])[0] ?? null;
                if (! $row) {
                    throw ApiException::notFound('الطلب غير موجود');
                }
                $req = (array) $row;
                if ($req['status'] !== 'pending') {
                    throw new ApiException('الطلب اتبتّ فيه بالفعل');
                }
                if (DB::select('SELECT id FROM users WHERE username = ?', [$username])) {
                    throw new ApiException("اسم المستخدم '{$username}' موجود مسبقاً، اختر اسماً آخر");
                }

                $now = WireTime::nowDb();
                // فك عمود phones المدموج لعمودي الطيار
                $phones = array_map('trim', explode(',', (string) ($req['phones'] ?? '')));

                DB::insert(
                    'INSERT INTO pilots (name, phone1, phone2, card_num, vehicle_no, address, assigned_branch_id, status, created_at)
                     VALUES (?,?,?,?,?,?,?,NULL,?)',
                    [
                        $req['name'],
                        $phones[0] ?? null,
                        ($phones[1] ?? '') !== '' ? $phones[1] : null,
                        $req['card_num'], $req['vehicle_no'], $req['address'],
                        $req['branch_id'] !== null ? (int) $req['branch_id'] : null,
                        $now,
                    ]
                );
                $pilotId = (int) DB::getPdo()->lastInsertId();

                DB::insert(
                    "INSERT INTO users (username, password_hash, role, name, pilot_id, created_at)
                     VALUES (?,?,'pilot',?,?,?)",
                    [$username, password_hash($password, PASSWORD_BCRYPT), $req['name'], $pilotId, $now]
                );

                DB::update("UPDATE pilot_join_requests SET status = 'approved', pilot_id = ? WHERE id = ?", [$pilotId, $reqId]);

                return $pilotId;
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر قبول طلب الانضمام', 500);
        }

        return ApiResponse::out(['ok' => true, 'pilotId' => $pilotId]);
    }

    /**
     * POST /api/join-requests/{id}/reject — الدور: admin بس.
     *
     * ⚠️ جملة واحدة مشروطة بـ`status = 'pending'` — ده الحجز الذري نفسه:
     * موافقة ورفض متوازيين مايقدروش يعدّوا سوا. و`rowCount()` صفر معناه
     * «مش موجود **أو** اتبتّ فيه» — نفس الرسالة والكود 404 للحالتين.
     */
    public function joinRequestReject(Request $request, string $id): JsonResponse
    {
        $request->actorOrFail();

        $n = DB::update(
            "UPDATE pilot_join_requests SET status = 'rejected' WHERE id = ? AND status = 'pending'",
            [$this->intId($id)]
        );
        if (! $n) {
            throw ApiException::notFound('الطلب غير موجود أو اتبتّ فيه بالفعل');
        }

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       الأذونات — كتابة (rest/dayoff/incident)
       (الأصل: board_leave_request_create / _approve / _reject / _end)
    ═══════════════════════════════════════════════════════════ */

    /**
     * POST /api/leave-requests — {pilotId?, type, reason}
     * الأدوار: pilot · admin · branch.
     *
     * الطيار بيطلب لنفسه (الـ`pilotId` المبعوت **بيتجاهل**)، والمشرف بيطلب
     * لطيار محدد.
     *
     * ⚠️ مفيش `assertPilotInScope` هنا — مشرف فرع يقدر يسجّل إذن لطيار فرع
     * تاني. ⚠️ ولو الطيار مش موجود خالص، `fetchColumn()` بيرجّع false
     * فالفرع بيقع على فرع المشرف والـINSERT بيتكسر على الـFK ويطلع «خطأ في
     * قاعدة البيانات» 500. الاتنين باج موروث ومنقول زي ما هو.
     * ⚠️ مفيش معاملة — قراءات + INSERT واحد زي الأصل.
     */
    public function leaveRequestCreate(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        $type = (string) ($request->input('type') ?? '');
        if (! isset(Vocab::LEAVE_TYPE_WIRE[$type])) {
            throw new ApiException('نوع الإذن rest أو dayoff أو incident');
        }

        if ($actor->role === 'pilot') {
            $pilotId = (int) DB::table('users')->where('id', $actor->userId)->value('pilot_id');
            if (! $pilotId) {
                throw new ApiException('الحساب مش مربوط بطيار');
            }
        } else {
            $pilotId = $this->intId($request->input('pilotId') ?? 0);
        }

        // فرع الطيار المسجّل الأول، وبعده فرع المشرف
        $branchId = (int) (DB::table('pilots')->where('id', $pilotId)->value('assigned_branch_id') ?: 0)
            ?: (int) ($actor->branchId ?? 0);
        if (! $branchId) {
            throw new ApiException('الطيار مش تابع لفرع');
        }

        $now = WireTime::nowDb();
        DB::insert(
            "INSERT INTO pilot_leave_requests (pilot_id, branch_id, type, reason, status, requested_at, created_at)
             VALUES (?,?,?,?,'pending',?,?)",
            [$pilotId, $branchId, $type, trim((string) ($request->input('reason') ?? '')) ?: null, $now, $now]
        );
        // 🔴 نفس ترتيب shiftRequestCreate: id قبل البثّ (البثّ بيدهس lastInsertId)
        $newId = (int) DB::getPdo()->lastInsertId();

        // بثّ فوري — الطلب كان بياخد دقيقة كاملة يبان في اللوحة
        $this->broadcastPilotRequest('leave', $branchId, $pilotId);

        return ApiResponse::out(['ok' => true, 'id' => $newId]);
    }

    /**
     * POST /api/leave-requests/{id}/approve (approveLeaveRequest).
     * الأدوار: admin · branch.
     *
     * الترتيب جزء من الصح: الطلب بيتقفل ويتعلّم `approved` **الأول**، وبعدين
     * صف الطيار بيتقفل ويتغيّر — كده موافقتين متوازيتين على نفس الطلب
     * بيتسلسلوا على قفل صف الطلب قبل ما يوصلوا للطيار.
     *
     * ⚠️ `leave_forced = 0` — الإذن ده طلب من الطيار مش إيقاف، فيقدر ينهيه
     * بنفسه بعدين. ⚠️ و`leave_reason` بياخد `$req['reason'] ?? ''` يعني
     * النص الفاضي مش null (عكس صف الطلب). فرق موروث.
     */
    public function leaveRequestApprove(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $reqId = $this->intId($id);

        try {
            DB::transaction(function () use ($actor, $reqId): void {
                $row = DB::select('SELECT * FROM pilot_leave_requests WHERE id = ? FOR UPDATE', [$reqId])[0] ?? null;
                if (! $row) {
                    throw ApiException::notFound('الطلب غير موجود');
                }
                $req = (array) $row;
                if ($req['status'] !== 'pending') {
                    throw new ApiException('الطلب اتبتّ فيه بالفعل');
                }

                $now = WireTime::nowDb();
                DB::update(
                    "UPDATE pilot_leave_requests SET status = 'approved', responded_at = ?, responded_by = ? WHERE id = ?",
                    [$now, $actor->username, $reqId]
                );

                $pilot = $this->lockPilot((int) $req['pilot_id']);
                // خروج من الدور لو كان مستني + إزاحة الباقيين
                if ($pilot['status'] === 'waiting') {
                    $this->queueShiftAfterRemoval(
                        $pilot['assigned_branch_id'] !== null ? (int) $pilot['assigned_branch_id'] : null,
                        $pilot['queue_no'] !== null ? (int) $pilot['queue_no'] : null
                    );
                }
                DB::update(
                    "UPDATE pilots SET status = 'on_leave', queue_no = NULL, leave_type = ?, leave_reason = ?, break_started_at = ?, leave_forced = 0 WHERE id = ?",
                    [$req['type'] ?: 'rest', $req['reason'] ?? '', $now, (int) $req['pilot_id']]
                );

                // بثّ الموافقة (afterCommit) — بيوصل للوحات ولقناة الطيار
                $this->broadcastPilotRequest(
                    'leave',
                    $pilot['assigned_branch_id'] !== null ? (int) $pilot['assigned_branch_id'] : (int) $req['branch_id'],
                    (int) $req['pilot_id']
                );
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّرت الموافقة على الإذن', 500);
        }

        return ApiResponse::ok();
    }

    /** POST /api/leave-requests/{id}/reject — الأدوار: admin · branch. جملة واحدة مشروطة بـpending. */
    public function leaveRequestReject(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();

        $n = DB::update(
            "UPDATE pilot_leave_requests SET status = 'rejected', responded_at = ?, responded_by = ? WHERE id = ? AND status = 'pending'",
            [WireTime::nowDb(), $actor->username, $this->intId($id)]
        );
        if (! $n) {
            throw ApiException::notFound('الطلب غير موجود أو اتبتّ فيه بالفعل');
        }

        // بثّ الرفض — التطبيق بيزامن حالة الطيار فورًا بدل دورة الدقيقة
        $lr = DB::selectOne('SELECT pilot_id, branch_id FROM pilot_leave_requests WHERE id = ?', [$this->intId($id)]);
        if ($lr) {
            $this->broadcastPilotRequest('leave', (int) $lr->branch_id, (int) $lr->pilot_id);
        }

        return ApiResponse::ok();
    }

    /**
     * POST /api/leave-requests/{id}/end — إنهاء الإذن والطيار يرجع آخر الدور
     * (endPilotLeaveAdmin). الأدوار: pilot · admin · branch.
     *
     * 🔒 الطيار يقدر ينهي **إذنه هو بس**، و**مايقدرش** لو الإيقاف إجباري —
     * الفحص مزدوج: `leave_forced = 1` على صف الطيار **أو** `forced_by` مش
     * فاضي على صف الطلب. الاتنين بالحرف: صف الطيار ممكن يكون اتصفّر بدخول
     * دور بينهم، فبصمة الطلب هي الضمانة التانية.
     *
     * ⚠️ `ended_by` بياخد **النص الحرفي 'pilot'** لو المنهي هو الطيار مش
     * اسم المستخدم — الواجهة بتفرّق بيهم.
     */
    public function leaveRequestEnd(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $reqId = $this->intId($id);

        try {
            DB::transaction(function () use ($actor, $reqId): void {
                $row = DB::select('SELECT * FROM pilot_leave_requests WHERE id = ? FOR UPDATE', [$reqId])[0] ?? null;
                if (! $row) {
                    throw ApiException::notFound('الطلب غير موجود');
                }
                $req = (array) $row;
                if ($req['status'] !== 'approved') {
                    throw new ApiException('الإذن مش ساري');
                }

                $pilot = $this->lockPilot((int) $req['pilot_id']);
                if ($actor->role === 'pilot') {
                    $mine = (int) DB::table('users')->where('id', $actor->userId)->value('pilot_id');
                    if ($mine !== (int) $req['pilot_id']) {
                        throw ApiException::forbidden('غير مسموح لك بهذه العملية');
                    }
                    if ((int) $pilot['leave_forced'] === 1 || $req['forced_by']) {
                        throw ApiException::forbidden('تم إيقافك من الإدارة/الفرع — مينفعش تنهي الإذن بنفسك');
                    }
                }

                $now = WireTime::nowDb();
                // فرع الطيار المسجّل الأول، وبعده الفرع المسجّل على الطلب نفسه
                $branchId = $pilot['assigned_branch_id'] !== null
                    ? (int) $pilot['assigned_branch_id']
                    : (int) $req['branch_id'];
                $this->enterQueue((int) $req['pilot_id'], $branchId, $now);

                DB::update(
                    "UPDATE pilot_leave_requests SET status = 'ended', ended_at = ?, ended_by = ? WHERE id = ?",
                    [$now, $actor->role === 'pilot' ? 'pilot' : $actor->username, $reqId]
                );

                // بثّ إنهاء الإذن — اللوحات والتطبيق يشوفوه فورًا
                $this->broadcastPilotRequest('leave', $branchId, (int) $req['pilot_id']);
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر إنهاء الإذن', 500);
        }

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       طلبات فتح الوردية — كتابة
       (الأصل: board_shift_request_create / _approve / _reject)
    ═══════════════════════════════════════════════════════════ */

    /**
     * POST /api/shift-requests — {branchId} من تطبيق الطيار. الدور: pilot بس.
     *
     * ⚠️ مفيش معاملة ولا قفل على فحص «طلب معلّق موجود» — طلبين في نفس
     * اللحظة ممكن يعدّوا الاتنين. منقول زي ما هو.
     */
    public function shiftRequestCreate(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        $pilotId = (int) DB::table('users')->where('id', $actor->userId)->value('pilot_id');
        if (! $pilotId) {
            throw new ApiException('الحساب مش مربوط بطيار');
        }
        $branchId = $this->intId($request->input('branchId') ?? 0);

        // مفيش داعي لطلب مكرر معلّق لنفس الطيار
        if (DB::select("SELECT id FROM pilot_shift_requests WHERE pilot_id = ? AND status = 'pending'", [$pilotId])) {
            throw new ApiException('عندك طلب فتح وردية معلّق بالفعل');
        }

        $now = WireTime::nowDb();
        DB::insert(
            "INSERT INTO pilot_shift_requests (pilot_id, branch_id, status, requested_at, created_at)
             VALUES (?,?,'pending',?,?)",
            [$pilotId, $branchId, $now, $now]
        );
        /* 🔴 الترتيب مش شكلي: id الطلب لازم يتمسك **قبل** البثّ.
           الدفع هنا بره أي معاملة، فبيكتب صف في جدول `jobs` فورًا —
           و`lastInsertId()` بعده بيرجّع رقم صف الطابور مش رقم الطلب،
           والتطبيق بيستخدم الرقم ده يتابع طلبه. */
        $newId = (int) DB::getPdo()->lastInsertId();

        // بثّ فوري للوحات — الطلب كان بيستنى مستطلعها
        $this->broadcastPilotRequest('shift', $branchId, $pilotId);

        return ApiResponse::out(['ok' => true, 'id' => $newId]);
    }

    /**
     * POST /api/shift-requests/{id}/approve (approveShiftRequest).
     * الأدوار: admin · branch.
     *
     * الموافقة بتعمل تلات حاجات مترابطة: الطلب يتعلّم `approved`، الطيار
     * يدخل آخر الدور، والوردية تتفتح **أو تتنقل** لو معاه وحدة مفتوحة من
     * فرع تاني. `opened_manually = 0` = «فتح بطلب» مش فتح يدوي من اللوحة —
     * التفرقة دي بتظهر في تقارير الورديات.
     *
     * ⚠️ `lockPilot` متندى لجانبه الوحيد (القفل) والنتيجة مترمية — الأصل
     * كده بالحرف، والقفل هو الغرض.
     */
    public function shiftRequestApprove(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $reqId = $this->intId($id);

        try {
            $shiftId = DB::transaction(function () use ($actor, $reqId): int {
                $row = DB::select('SELECT * FROM pilot_shift_requests WHERE id = ? FOR UPDATE', [$reqId])[0] ?? null;
                if (! $row) {
                    throw ApiException::notFound('الطلب غير موجود');
                }
                $req = (array) $row;
                if ($req['status'] !== 'pending') {
                    throw new ApiException('الطلب اتبتّ فيه بالفعل');
                }

                $now = WireTime::nowDb();
                DB::update(
                    "UPDATE pilot_shift_requests SET status = 'approved', responded_at = ?, responded_by = ? WHERE id = ?",
                    [$now, $actor->username, $reqId]
                );

                $branchId = (int) $req['branch_id'];
                $this->lockPilot((int) $req['pilot_id']);
                $this->enterQueue((int) $req['pilot_id'], $branchId, $now);

                // بثّ الموافقة (afterCommit فبيتأجل لما المعاملة تنجح)
                $this->broadcastPilotRequest('shift', $branchId, (int) $req['pilot_id']);
                return $this->openOrTransferShift((int) $req['pilot_id'], $branchId, $actor, false, $now);
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّرت الموافقة على فتح الوردية', 500);
        }

        return ApiResponse::out(['ok' => true, 'shiftId' => $shiftId]);
    }

    /** POST /api/shift-requests/{id}/reject — الأدوار: admin · branch. */
    public function shiftRequestReject(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();

        $n = DB::update(
            "UPDATE pilot_shift_requests SET status = 'rejected', responded_at = ?, responded_by = ? WHERE id = ? AND status = 'pending'",
            [WireTime::nowDb(), $actor->username, $this->intId($id)]
        );
        if (! $n) {
            throw ApiException::notFound('الطلب غير موجود أو اتبتّ فيه بالفعل');
        }

        // بثّ القرار — التطبيق بيسمع على قناة الطيار ويزامن حالته فورًا
        $rr = DB::selectOne('SELECT pilot_id, branch_id FROM pilot_shift_requests WHERE id = ?', [$this->intId($id)]);
        if ($rr) {
            $this->broadcastPilotRequest('shift', (int) $rr->branch_id, (int) $rr->pilot_id);
        }

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       طلبات إرجاع الأوردر بإذن — كتابة
       (الأصل: board_return_request_create / _approve / _reject
        + board_order_clear_return_flag)
    ═══════════════════════════════════════════════════════════ */

    /**
     * POST /api/return-requests — {orderId, reason} من تطبيق الطيار. الدور: pilot.
     *
     * الطلب بيتكتب في جدوله **و** بصمة `return_status = 'pending'` بتتحط
     * على الأوردر نفسه — الاتنين في معاملة واحدة عشان التطبيق مايشوفش
     * أوردر بعلامة إرجاع بلا طلب (أو العكس).
     *
     * `order_num` بيتنسخ على صف الطلب (مش join) عشان الطلب يفضل عارف رقم
     * الأوردر حتى لو الأوردر اتفرّق أو اتغيّر بعدين.
     */
    public function returnRequestCreate(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        $orderId = $this->intId($request->input('orderId') ?? 0);
        $reason  = trim((string) ($request->input('reason') ?? ''));

        $pilotId = (int) DB::table('users')->where('id', $actor->userId)->value('pilot_id');
        if (! $pilotId) {
            throw new ApiException('الحساب مش مربوط بطيار');
        }

        try {
            $newId = DB::transaction(function () use ($orderId, $reason, $pilotId): int {
                $row = DB::select(
                    'SELECT id, order_num, branch_id, pilot_id, status FROM orders WHERE id = ? FOR UPDATE',
                    [$orderId]
                )[0] ?? null;
                if (! $row) {
                    throw ApiException::notFound('الأوردر غير موجود');
                }
                $order = (array) $row;
                if ((int) $order['pilot_id'] !== $pilotId) {
                    throw ApiException::forbidden('الأوردر مش محمّل عليك');
                }
                if ($order['status'] !== 'delivering') {
                    throw new ApiException('الأوردر مش جاري التوصيل');
                }

                $now = WireTime::nowDb();
                DB::update("UPDATE orders SET return_status = 'pending', return_reason = ? WHERE id = ?", [$reason ?: null, $orderId]);
                DB::insert(
                    "INSERT INTO pilot_return_requests (order_id, order_num, pilot_id, branch_id, reason, status, requested_at, created_at)
                     VALUES (?,?,?,?,?,'pending',?,?)",
                    [$orderId, $order['order_num'], $pilotId, (int) $order['branch_id'], $reason ?: null, $now, $now]
                );

                // علامة الإرجاع بتتحط على الأوردر نفسه، ولوحة الفرع بتعرضها —
                // ودي الحالة اللي الفرع بيستنى يشوفها فورًا عشان يوافق أو يرفض
                $this->broadcastOrder($orderId);

                return (int) DB::getPdo()->lastInsertId();
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر إرسال طلب الإرجاع', 500);
        }

        return ApiResponse::out(['ok' => true, 'id' => $newId]);
    }

    /**
     * POST /api/return-requests/{id}/approve (approveReturnRequest).
     * الأدوار: admin · branch.
     *
     * الأوردر بيرجع «لم يتم التوصيل» وعلامة الإرجاع بتتمسح، وبعدها قاعدة
     * «آخر أوردر بيرجّع الطيار للانتظار» بتتطبق باستثناء الأوردر ده نفسه
     * (لأنه لسه في الطريق لتغيير حالته في نفس المعاملة).
     *
     * ⚠️ سبب عدم التسليم: `($req['reason'] ?: null) ?? 'إرجاع من الطيار'`
     * — يعني السبب الفاضي أو الناقص بياخد النص الافتراضي. الشكل الغريب
     * منقول بالحرف. ⚠️ ومفيش تعليم `pilot_name` ولا `money_settled` هنا،
     * على عكس التسوية في `settlePilotMoney`.
     */
    public function returnRequestApprove(Request $request, string $id): JsonResponse
    {
        $request->actorOrFail();
        $reqId = $this->intId($id);

        try {
            DB::transaction(function () use ($reqId): void {
                $row = DB::select('SELECT * FROM pilot_return_requests WHERE id = ? FOR UPDATE', [$reqId])[0] ?? null;
                if (! $row) {
                    throw ApiException::notFound('الطلب غير موجود');
                }
                $req = (array) $row;
                if ($req['status'] !== 'pending') {
                    throw new ApiException('الطلب اتبتّ فيه بالفعل');
                }

                $now = WireTime::nowDb();
                DB::update("UPDATE pilot_return_requests SET status = 'approved' WHERE id = ?", [$reqId]);
                DB::update(
                    "UPDATE orders SET status = 'undelivered', status_since = ?, undelivered_at = ?, undelivered_reason = ?,
                            return_status = NULL, return_reason = NULL
                      WHERE id = ?",
                    [$now, $now, ($req['reason'] ?: null) ?? 'إرجاع من الطيار', (int) $req['order_id']]
                );
                // تغيير حالة → «لم يتم التوصيل» + مسح علامة الإرجاع
                $this->broadcastOrder((int) $req['order_id']);
                // تحرير الطيار للانتظار لو ده كان آخر أوردر جاري معاه
                $this->pilotBackToWaitingIfFree((int) $req['pilot_id'], (int) $req['order_id'], $now);
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّرت الموافقة على الإرجاع', 500);
        }

        return ApiResponse::ok();
    }

    /**
     * POST /api/return-requests/{id}/reject — الأدوار: admin · branch.
     *
     * الأوردر بياخد `return_status = 'rejected'` — علامة **مؤقتة** التطبيق
     * بيعرضها للطيار وبعدين بيمسحها بـ`clear-return-flag`.
     */
    public function returnRequestReject(Request $request, string $id): JsonResponse
    {
        $request->actorOrFail();
        $reqId = $this->intId($id);

        try {
            DB::transaction(function () use ($reqId): void {
                $row = DB::select('SELECT * FROM pilot_return_requests WHERE id = ? FOR UPDATE', [$reqId])[0] ?? null;
                if (! $row) {
                    throw ApiException::notFound('الطلب غير موجود');
                }
                $req = (array) $row;
                if ($req['status'] !== 'pending') {
                    throw new ApiException('الطلب اتبتّ فيه بالفعل');
                }
                DB::update("UPDATE pilot_return_requests SET status = 'rejected' WHERE id = ?", [$reqId]);
                DB::update("UPDATE orders SET return_status = 'rejected' WHERE id = ?", [(int) $req['order_id']]);
                // علامة الإرجاع على الأوردر اتغيّرت — نفس منطق `returnRequestCreate`
                $this->broadcastOrder((int) $req['order_id']);
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر رفض طلب الإرجاع', 500);
        }

        return ApiResponse::ok();
    }

    /**
     * POST /api/orders/{id}/clear-return-flag — التطبيق بيمسح علامة الرفض
     * بعد ما الطيار يشوفها. الأدوار: pilot · admin · branch.
     *
     * ⚠️ **مفيش أي فحص ملكية** — أي طيار يقدر يمسح علامة إرجاع أي أوردر
     * في النظام بالـid. وأوردر مش موجود بيرجّع `ok:true` برضه (مفيش فحص
     * `rowCount`). الاتنين باج موروث ومنقول زي ما هو.
     */
    public function orderClearReturnFlag(Request $request, string $id): JsonResponse
    {
        $request->actorOrFail();

        /* 📡 **مفيش بثّ هنا بالقصد** — لسببين، والاتنين مكتوبين فوق:
           1. المسار مالوش أي فحص ملكية ولا فحص `rowCount`، فأي طيار يقدر
              يندهه بأي id في النظام. البثّ كان هيحوّل الباج الموروث ده
              لمولّد أحداث مفتوح على قنوات فروع مالوش علاقة بيها.
           2. اللي بيتمسح علامة **رفض** التطبيق عرضها للطيار خلاص — لوحة
              الفرع مابتعرضهاش، فمفيش حاجة تتحدّث عندها. */
        DB::update('UPDATE orders SET return_status = NULL, return_reason = NULL WHERE id = ?', [$this->intId($id)]);

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       النقل بين الفروع — كتابة
       (الأصل: board_transfer_create / _approve / _reject / _end)
    ═══════════════════════════════════════════════════════════ */

    /**
     * POST /api/pilot-transfers — {pilotId, toBranchId, type?} (submitTransferRequest).
     * الأدوار: admin · branch.
     *
     * ⚠️ `type` في القاعدة **temp/permanent** بينما السلك بيقول `direct`
     * بدل temp (شوف `BoardWire::transfer`) — الدخول هنا بيقرا **temp** مش
     * direct، فأي قيمة غير `permanent` بتبقى `temp`.
     *
     * ⚠️ مشرف فرع بـ`branch_id` فاضي بياخد 0 من `branchScope` — و0 مش null
     * فالـ`??` مابتشتغلش وبيقع على «حدّد الفرع المنقول منه». منقول بالحرف.
     */
    public function transferCreate(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        $pilotId    = $this->intId($request->input('pilotId') ?? 0);
        $toBranchId = $this->intId($request->input('toBranchId') ?? 0);

        /* الأصل بيفرّق بين `fetchColumn() === false` (الطيار مش موجود) و
           `null` (موجود بس بلا فرع). بنقرا الصف نفسه عشان نحافظ على
           التفرقة دي — `firstColumn` هنا كانت هتخلط الحالتين. */
        $row = DB::select('SELECT assigned_branch_id FROM pilots WHERE id = ?', [$pilotId])[0] ?? null;
        if ($row === null) {
            throw ApiException::notFound('الطيار غير موجود');
        }
        $cur = $row->assigned_branch_id;

        $fromIn = $request->input('fromBranchId');
        $fromBranchId = $this->branchScope($actor, $fromIn ? $this->intId($fromIn) : null)
            ?? ($cur !== null ? (int) $cur : null);
        if (! $fromBranchId) {
            throw new ApiException('حدّد الفرع المنقول منه');
        }
        if ($fromBranchId === $toBranchId) {
            throw new ApiException('اختر فرعًا مختلفًا');
        }

        $type = ($request->input('type') ?? 'temp') === 'permanent' ? 'permanent' : 'temp';
        $now  = WireTime::nowDb();
        DB::insert(
            "INSERT INTO pilot_transfers (pilot_id, from_branch_id, to_branch_id, type, status, requested_at, created_at)
             VALUES (?,?,?,?,'pending',?,?)",
            [$pilotId, $fromBranchId, $toBranchId, $type, $now, $now]
        );

        return ApiResponse::out(['ok' => true, 'id' => (int) DB::getPdo()->lastInsertId()]);
    }

    /**
     * POST /api/pilot-transfers/{id}/approve (approveAdminTransfer). الدور: admin بس.
     *
     * التسلسل: إزاحة من دور الفرع القديم → دخول آخر دور الفرع الجديد →
     * **نقل** الوردية المفتوحة معاه (مش قفل وفتح جديدة) → تعليم الطلب.
     *
     * الشرط `if (findActiveShift(...))` مقصود: الطيار اللي مالوش وردية
     * مفتوحة **مابتتفتحلوش** وحدة بالنقل — النقل مش بداية شغل.
     */
    public function transferApprove(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $reqId = $this->intId($id);

        try {
            DB::transaction(function () use ($actor, $reqId): void {
                $row = DB::select('SELECT * FROM pilot_transfers WHERE id = ? FOR UPDATE', [$reqId])[0] ?? null;
                if (! $row) {
                    throw ApiException::notFound('الطلب غير موجود');
                }
                $req = (array) $row;
                if ($req['status'] !== 'pending') {
                    throw new ApiException('الطلب اتبتّ فيه بالفعل');
                }

                $now   = WireTime::nowDb();
                $pilot = $this->lockPilot((int) $req['pilot_id']);
                if ($pilot['status'] === 'waiting') {
                    $this->queueShiftAfterRemoval(
                        $pilot['assigned_branch_id'] !== null ? (int) $pilot['assigned_branch_id'] : null,
                        $pilot['queue_no'] !== null ? (int) $pilot['queue_no'] : null
                    );
                }
                $toBranchId = (int) $req['to_branch_id'];
                $this->enterQueue((int) $req['pilot_id'], $toBranchId, $now);

                // الوردية المفتوحة بتتنقل مع الطيار (مش بتتقفل ولا بتتكرر)
                if ($this->findActiveShift((int) $req['pilot_id'], true)) {
                    $this->openOrTransferShift((int) $req['pilot_id'], $toBranchId, $actor, false, $now);
                }

                DB::update(
                    "UPDATE pilot_transfers SET status = 'approved', resolved_at = ?, resolved_by = ? WHERE id = ?",
                    [$now, $actor->username, $reqId]
                );
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّرت الموافقة على النقل', 500);
        }

        return ApiResponse::ok();
    }

    /** POST /api/pilot-transfers/{id}/reject — الدور: admin بس. */
    public function transferReject(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();

        $n = DB::update(
            "UPDATE pilot_transfers SET status = 'rejected', resolved_at = ?, resolved_by = ? WHERE id = ? AND status = 'pending'",
            [WireTime::nowDb(), $actor->username, $this->intId($id)]
        );
        if (! $n) {
            throw ApiException::notFound('الطلب غير موجود أو اتبتّ فيه بالفعل');
        }

        return ApiResponse::ok();
    }

    /**
     * POST /api/pilot-transfers/{id}/end — إنهاء نقل مؤقت. الأدوار: admin · branch.
     *
     * ⚠️ العلامة بس — **الطيار مابيرجعش لفرعه القديم تلقائيًا**، لازم
     * يتنقل يدويًا. والرسالة هنا مختلفة عن باقي المسارات: «النقل غير موجود
     * أو مش ساري». ⚠️ ومفيش فحص إن النقل `temp` أصلًا — النقل الدائم يقدر
     * يتعلّم `ended` برضه. منقول زي ما هو.
     */
    public function transferEnd(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();

        $n = DB::update(
            "UPDATE pilot_transfers SET status = 'ended', resolved_at = ?, resolved_by = ? WHERE id = ? AND status = 'approved'",
            [WireTime::nowDb(), $actor->username, $this->intId($id)]
        );
        if (! $n) {
            throw ApiException::notFound('النقل غير موجود أو مش ساري');
        }

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       الدعم بين الفروع — كتابة
       (الأصل: board_support_request_create / _respond / _send_pilot /
        _accept_pilot / _cancel)
    ═══════════════════════════════════════════════════════════ */

    /**
     * POST /api/support-requests — {notes, fromBranchId?, pilotId?}
     * الأدوار: admin · branch.
     *
     * `from_branch_id` فاضي = **إنذار broadcast** لكل الفروع، ولو متحدد =
     * طلب موجّه لفرع بعينه. الفرق ده هو اللي `BoardWire::supportRequest`
     * بيطلّعه في `broadcast`، وهو كمان اللي بيحدد لو الرفض بيقفل الطلب.
     */
    public function supportRequestCreate(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        $branchIn = $request->input('branchId');
        $requestingBranchId = $this->branchScope($actor, $branchIn ? $this->intId($branchIn) : null);
        if (! $requestingBranchId) {
            throw new ApiException('حدّد الفرع الطالب');
        }

        $fromIn  = $request->input('fromBranchId');
        $pilotIn = $request->input('pilotId');
        DB::insert(
            "INSERT INTO pilot_support_requests (requesting_branch_id, from_branch_id, pilot_id, notes, status, created_at)
             VALUES (?,?,?,?,'pending',?)",
            [
                $requestingBranchId,
                $fromIn ? $this->intId($fromIn) : null,
                $pilotIn ? $this->intId($pilotIn) : null,
                trim((string) ($request->input('notes') ?? '')) ?: null,
                WireTime::nowDb(),
            ]
        );

        return ApiResponse::out(['ok' => true, 'id' => (int) DB::getPdo()->lastInsertId()]);
    }

    /**
     * POST /api/support-requests/{id}/respond — {response: accepted|rejected}
     * الأدوار: admin · branch.
     *
     * الرد بيتسجّل صف في `pilot_support_responses` — وده اللي بيوقف الإنذار
     * عند الفرع اللي رد (عدّاد اللوحة بيستثني الفروع اللي ليها صف رد).
     *
     * ⚠️ الرفض بيقفل الطلب **بس لو موجّه** (`from_branch_id` مش null): رفض
     * فرع واحد لإنذار broadcast مايقفلش الإنذار على باقي الفروع.
     * ⚠️ مفيش فحص إن الطلب لسه pending قبل تسجيل الرد، ولا منع تكرار الرد
     * من نفس الفرع — صفوف ردود مكررة ممكنة. منقول زي ما هو.
     */
    public function supportRequestRespond(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $reqId = $this->intId($id);

        $response = ($request->input('response') ?? 'rejected') === 'accepted' ? 'accepted' : 'rejected';
        $branchIn = $request->input('branchId');
        $branchId = $this->branchScope($actor, $branchIn ? $this->intId($branchIn) : null);
        if (! $branchId) {
            throw new ApiException('حدّد الفرع');
        }

        try {
            DB::transaction(function () use ($reqId, $response, $branchId): void {
                $row = DB::select('SELECT * FROM pilot_support_requests WHERE id = ? FOR UPDATE', [$reqId])[0] ?? null;
                if (! $row) {
                    throw ApiException::notFound('طلب الدعم غير موجود');
                }
                $req = (array) $row;

                $now = WireTime::nowDb();
                DB::insert(
                    'INSERT INTO pilot_support_responses (request_id, branch_id, response, responded_at, created_at)
                     VALUES (?,?,?,?,?)',
                    [$reqId, $branchId, $response, $now, $now]
                );
                if ($response === 'rejected' && $req['from_branch_id'] !== null && $req['status'] === 'pending') {
                    DB::update("UPDATE pilot_support_requests SET status = 'rejected' WHERE id = ?", [$reqId]);
                }
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر تسجيل الرد', 500);
        }

        return ApiResponse::ok();
    }

    /**
     * POST /api/support-requests/{id}/send-pilot — {pilotId} (submitSendPilot).
     * الأدوار: admin · branch.
     *
     * الفرع القابل بيبعت طيار: الطيار **بيتحرّر من فرعه بالكامل** لكن
     * ورديته المفتوحة بتفضل مفتوحة زي ما هي — لحد ما الفرع الطالب يضمّه
     * بـ`accept-pilot` وساعتها الوردية بتتنقل. كده `shift_id` بتاع
     * الأوردرات يفضل واحد على طول الرحلة.
     *
     * الطيار «جاري التوصيل» **مايتبعتش** — فلوس أوردراته لسه معاه.
     */
    public function supportSendPilot(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $reqId = $this->intId($id);

        $pilotId  = $this->intId($request->input('pilotId') ?? 0);
        $branchIn = $request->input('branchId');
        $myBranchId = $this->branchScope($actor, $branchIn ? $this->intId($branchIn) : null);
        if (! $myBranchId) {
            throw new ApiException('حدّد الفرع المُرسِل');
        }

        try {
            DB::transaction(function () use ($reqId, $pilotId, $myBranchId): void {
                $row = DB::select('SELECT * FROM pilot_support_requests WHERE id = ? FOR UPDATE', [$reqId])[0] ?? null;
                if (! $row) {
                    throw ApiException::notFound('طلب الدعم غير موجود');
                }
                $req = (array) $row;
                if ($req['status'] !== 'pending') {
                    throw new ApiException('الطلب اتبتّ فيه بالفعل');
                }

                $pilot = $this->lockPilot($pilotId);
                if ($pilot['status'] === 'delivering') {
                    throw new ApiException('الطيار جارٍ التوصيل — مينفعش إرساله دلوقتي');
                }

                $now = WireTime::nowDb();
                // تحرير الطيار من فرعه — الوردية المفتوحة بتفضل زي ما هي لحد ما الفرع المستقبِل يضمّه
                $this->releasePilot($pilot);
                DB::update(
                    "UPDATE pilot_support_requests SET status = 'accepted', accepted_by_branch_id = ?, pilot_id = ? WHERE id = ?",
                    [$myBranchId, $pilotId, $reqId]
                );
                DB::insert(
                    "INSERT INTO pilot_support_responses (request_id, branch_id, response, responded_at, created_at)
                     VALUES (?,?,'accepted',?,?)",
                    [$reqId, $myBranchId, $now, $now]
                );
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر إرسال الطيار', 500);
        }

        return ApiResponse::ok();
    }

    /**
     * POST /api/support-requests/{id}/accept-pilot — (acceptSentPilot).
     * الأدوار: admin · branch.
     *
     * الفرع الطالب بيضم الطيار المُرسَل: دخول آخر الدور + **نقل** ورديته
     * المفتوحة لفرعه (ولو مالوش وحدة بتتفتح جديدة بـ`opened_manually = 1`).
     * الطلب بيتقفل `ended`.
     *
     * 🔒 مشرف الفرع لازم يكون هو الفرع الطالب — والإدارة معفية.
     * ⚠️ `branchScope` هنا بتتنده بـ`requesting_branch_id` كـ«المطلوب»،
     * فالإدارة بتاخده كما هو ومشرف الفرع بياخد فرعه — والمقارنة بعدها هي
     * الحارس الفعلي. الشكل ده منقول بالحرف.
     */
    public function supportAcceptPilot(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $reqId = $this->intId($id);

        try {
            DB::transaction(function () use ($actor, $reqId): void {
                $row = DB::select('SELECT * FROM pilot_support_requests WHERE id = ? FOR UPDATE', [$reqId])[0] ?? null;
                if (! $row) {
                    throw ApiException::notFound('طلب الدعم غير موجود');
                }
                $req = (array) $row;
                if ($req['status'] !== 'accepted' || $req['pilot_id'] === null) {
                    throw new ApiException('مفيش طيار مُرسَل على الطلب ده');
                }

                $myBranchId = $this->branchScope($actor, (int) $req['requesting_branch_id']);
                if ($actor->role === 'branch' && $myBranchId !== (int) $req['requesting_branch_id']) {
                    throw ApiException::forbidden('الطلب مش لفرعك');
                }

                $now = WireTime::nowDb();
                $this->lockPilot((int) $req['pilot_id']);
                $this->enterQueue((int) $req['pilot_id'], (int) $req['requesting_branch_id'], $now);
                // لو معاه وردية مفتوحة من فرعه القديم بتتنقل لفرعنا — ولو لأ بتتفتح واحدة جديدة
                $this->openOrTransferShift((int) $req['pilot_id'], (int) $req['requesting_branch_id'], $actor, true, $now);

                DB::update("UPDATE pilot_support_requests SET status = 'ended' WHERE id = ?", [$reqId]);
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر ضم الطيار', 500);
        }

        return ApiResponse::ok();
    }

    /**
     * POST /api/support-requests/{id}/cancel — الفرع الطالب بيلغي الإنذار.
     * الأدوار: admin · branch.
     *
     * ⚠️ **مفيش فحص إن الملغي هو الفرع الطالب فعلًا** — أي فرع يقدر يلغي
     * أي إنذار معلّق. باج موروث ومنقول زي ما هو.
     */
    public function supportRequestCancel(Request $request, string $id): JsonResponse
    {
        $request->actorOrFail();

        $n = DB::update(
            "UPDATE pilot_support_requests SET status = 'cancelled' WHERE id = ? AND status = 'pending'",
            [$this->intId($id)]
        );
        if (! $n) {
            throw ApiException::notFound('الطلب غير موجود أو اتبتّ فيه بالفعل');
        }

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       أدوات داخلية — نقل حرفي لدوال board_* المساعدة
    ═══════════════════════════════════════════════════════════ */

    /**
     * المقابل لـ board_list_out(): غلاف قايمة اللوحة بمنطق `?since` اللي
     * بيشتغل **بعد** الاستعلام على `_ts` المحسوب لكل صف. شوف شرح الكلاس فوق
     * للسبب (الجداول دي ملهاش عمود updated_at).
     */
    private function listOut(Request $request, array $items): JsonResponse
    {
        $since = $this->sinceParam($request);
        if ($since > 0) {
            $maxTs = 0;
            foreach ($items as $it) {
                $ts = (int) ($it['_ts'] ?? 0);
                if ($ts > $maxTs) {
                    $maxTs = $ts;
                }
            }
            if ($maxTs > 0 && $maxTs <= $since) {
                return PollableList::unchanged();
            }
        }

        // `_ts` حقل داخلي — بيتشال قبل ما يتبعت على السلك
        foreach ($items as &$it) {
            unset($it['_ts']);
        }
        unset($it);

        return PollableList::items($items);
    }

    /** المقابل لـ board_since() — `?since=` فاضية = 0 (يعني بلا فلترة) */
    private function sinceParam(Request $request): int
    {
        return $request->query->has('since') ? max(0, (int) $request->query('since')) : 0;
    }

    /**
     * المقابل لـ board_row_ts(): أقصى تعديل بالميلي ثانية من مجموعة أعمدة
     * DATETIME (UTC). عمود ناقص أو فاضي بيتتخطى — عشان نفس الدالة تشتغل
     * على جداول مختلفة الأعمدة.
     */
    private function rowTs(array $row, array $cols): int
    {
        $max = 0;
        foreach ($cols as $c) {
            if (! empty($row[$c])) {
                $t = strtotime($row[$c] . ' UTC');
                if ($t !== false && $t * 1000 > $max) {
                    $max = $t * 1000;
                }
            }
        }

        return $max;
    }

    /**
     * المقابل لـ board_branch_scope(): مشرف الفرع مقيّد بفرعه، والإدارة
     * بتبعت `?branch=` صراحة.
     *
     * ⚠️ مشرف فرع بـ `branch_id` فاضي بيرجّع 0 (falsy) — يعني **بلا فلترة
     * خالص** في القوايم، مش قايمة فاضية. باج موروث؛ متنقول زي ما هو.
     */
    private function branchScope(Actor $actor, ?int $requested): ?int
    {
        if ($actor->role === 'branch') {
            return (int) ($actor->branchId ?? 0);
        }

        return $requested;
    }

    /** `?branch=` كرقم — null لو الباراميتر مش موجود أصلًا (مش لو = 0) */
    private function queryBranch(Request $request): ?int
    {
        return $request->query->has('branch') ? (int) $request->query('branch') : null;
    }

    /** فلاتر الفرع + الحالة المشتركة بين قوايم الطلبات */
    private function branchAndStatusFilters(Request $request, Actor $actor, string $branchCol): array
    {
        $where = [];
        $args  = [];

        $branchId = $this->branchScope($actor, $this->queryBranch($request));
        if ($branchId) {
            $where[] = $branchCol . ' = ?';
            $args[]  = $branchId;
        }
        $status = $request->query('status');
        if (! empty($status)) {
            $where[] = 'r.status = ?';
            $args[]  = $status;
        }

        return [$where, $args];
    }

    /**
     * نطاق الطيار أو الفرع — نفس الكتلة المكررة في الأذونات وطلبات
     * الورديات وطلبات الإرجاع بالحرف.
     *
     * ⚠️ لو حساب الطيار مش مربوط بصف في `pilots` (أو الحساب اتمسح)
     * الفلتر بيبقى `pilot_id = 0` — يعني **قايمة فاضية**، مش قايمة الكل.
     * ده أمان الأصل بالصدفة (`(int)false === 0`) وسايبينه زي ما هو.
     */
    private function pilotOrBranchFilters(Request $request, Actor $actor): array
    {
        $where = [];
        $args  = [];

        if ($actor->role === 'pilot') {
            $where[] = 'r.pilot_id = ?';
            $args[]  = (int) DB::table('users')->where('id', $actor->userId)->value('pilot_id');
        } else {
            $branchId = $this->branchScope($actor, $this->queryBranch($request));
            if ($branchId) {
                $where[] = 'r.branch_id = ?';
                $args[]  = $branchId;
            }
        }

        $status = $request->query('status');
        if (! empty($status)) {
            $where[] = 'r.status = ?';
            $args[]  = $status;
        }

        return [$where, $args];
    }

    /** المقابل لـ board_int_id() — الصفر والسالب مرفوضين بنفس النص */
    private function intId(mixed $id): int
    {
        $n = (int) $id;
        if ($n <= 0) {
            throw new ApiException('معرّف غير صالح');
        }

        return $n;
    }

    /** ?,?,? بعدد المعرّفات — نفس نمط OrderWire::ph() */
    private function placeholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /**
     * أول عمود من أول صف — المقابل لـ PDOStatement::fetchColumn().
     * بنقراه كده عشان نسيب نص الـSQL **حرفيًا زي الأصل** من غير ما نضطر
     * نضيف alias لـ COUNT(*) أو GREATEST(...).
     */
    private function firstColumn(string $sql, array $args): mixed
    {
        $row = DB::select($sql, $args)[0] ?? null;
        if ($row === null) {
            return null;
        }

        return array_values((array) $row)[0] ?? null;
    }

    private function countOf(string $sql, array $args): int
    {
        return (int) $this->firstColumn($sql, $args);
    }

    /* ═══════════════════════════════════════════════════════════
       التقفيلة الشهرية — الحساب
    ═══════════════════════════════════════════════════════════ */

    /**
     * المقابل لـ board_month_range(): حدود الشهر **بتوقيت القاهرة** متحوّلة
     * لـUTC → [fromUtc, toUtc, daysInMonth].
     *
     * 💰 الحدود بتتحسب على القاهرة مش UTC عشان وردية بتفتح 1 بالليل يوم 1
     * من الشهر ما تقعش على الشهر اللي فات. `toUtc` هو أول لحظة في الشهر
     * الجاي والمقارنة `< toUtc` — يعني الحد الأعلى **مفتوح**.
     */
    private function monthRange(string $month): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new ApiException('الشهر بصيغة YYYY-MM');
        }

        try {
            $start = new DateTimeImmutable($month . '-01 00:00:00', new DateTimeZone('Africa/Cairo'));
        } catch (Exception) {
            throw new ApiException('شهر غير صالح');
        }

        $end = $start->modify('first day of next month');
        $utc = new DateTimeZone('UTC');

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
            (int) $start->format('t'),
        ];
    }

    /**
     * المقابل لـ board_build_monthly_data(): تجميع الشهر من الورديات.
     *
     * 💰 قواعد الفلوس اللي ممنوع تتغير:
     *  • **البنود `monthly` بس هي اللي بتتجمّع هنا** — بنود `daily` اتحاسبت
     *    خلاص في تقفيلة الوردية، فتجميعها تاني = دفع مرتين.
     *  • الافتراضي لو العمود فاضي هنا **`monthly`** (بينما السلك بيقول
     *    `daily`) — تناقض موروث، منقول بالحرف.
     *  • الوردية **اللي لسه مفتوحة** ساعاتها بتتحسب لحد **دلوقتي** — يعني
     *    الرقم بيتحرك بين نداءين. مقصود: اللوحة بتعرض الشهر لايف.
     *  • يوم الشغل بيتحسب من **بداية** الوردية بتوقيت القاهرة، فوردية
     *    بتقفل بعد نص الليل بتتحسب على يوم بدايتها.
     *  • العمولة: fixed = قيمة ثابتة × عدد المُسلّم، percent = نسبة من
     *    **سعر توصيل المُسلّم بس** مش كل الأوردرات.
     */
    private function buildMonthlyData(int $pilotId, string $month): array
    {
        [$fromUtc, $toUtc, $daysInMonth] = $this->monthRange($month);

        $pilotRow = DB::select('SELECT * FROM pilots WHERE id = ?', [$pilotId])[0] ?? null;
        if (! $pilotRow) {
            throw ApiException::notFound('الطيار غير موجود');
        }
        $pilot = (array) $pilotRow;

        $shifts = array_map(
            fn ($s) => (array) $s,
            DB::select('SELECT * FROM shifts WHERE pilot_id = ? AND started_at >= ? AND started_at < ?', [$pilotId, $fromUtc, $toUtc])
        );

        $totalHoursMs   = 0.0;
        $totalBonus     = 0.0;
        $totalDeduction = 0.0;
        $totalAdvance   = 0.0;
        $totalCommission = 0.0;
        $deliveredCount = 0;
        $totalOrders    = 0;
        $workDays = [];
        $cairo = new DateTimeZone('Africa/Cairo');
        $nowTs = time();

        if ($shifts) {
            $ids = array_map(fn ($s) => (int) $s['id'], $shifts);

            // مجاميع أوردرات كل وردية دفعة واحدة — مش استعلام لكل وردية
            $byShift = [];
            foreach (DB::select(
                "SELECT shift_id,
                        COUNT(*) AS cnt,
                        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_cnt,
                        SUM(CASE WHEN status = 'delivered' THEN total_delivery_price ELSE 0 END) AS delivered_price
                   FROM orders WHERE shift_id IN (" . $this->placeholders($ids) . ') GROUP BY shift_id',
                $ids
            ) as $r) {
                $r = (array) $r;
                $byShift[(int) $r['shift_id']] = $r;
            }

            foreach ($shifts as $s) {
                $startTs = strtotime($s['started_at'] . ' UTC');
                // الوردية المفتوحة بتتحسب لحد دلوقتي
                $endTs = $s['status'] === 'active' || ! $s['status']
                    ? $nowTs
                    : (strtotime(($s['ended_at'] ?? $s['started_at']) . ' UTC') ?: $startTs);
                if ($startTs) {
                    $totalHoursMs += max(0, ($endTs - $startTs) * 1000);
                    $workDays[(new DateTimeImmutable('@' . $startTs))->setTimezone($cairo)->format('Y-m-d')] = true;
                }
                if (($s['bonus_settle'] ?: 'monthly') === 'monthly') {
                    $totalBonus += (float) $s['bonus_amount'];
                }
                if (($s['deduction_settle'] ?: 'monthly') === 'monthly') {
                    $totalDeduction += (float) $s['deduction_amount'];
                }
                if (($s['advance_settle'] ?: 'monthly') === 'monthly') {
                    $totalAdvance += (float) $s['advance_amount'];
                }

                $agg = $byShift[(int) $s['id']] ?? null;
                if ($agg) {
                    $totalOrders += (int) $agg['cnt'];
                    $deliveredCount += (int) $agg['delivered_cnt'];
                    if (($s['commission_settle'] ?: 'monthly') === 'monthly') {
                        $ct = $pilot['commission_type'] ?: 'percent';
                        $cv = (float) $pilot['commission_value'];
                        $totalCommission += $ct === 'fixed'
                            ? $cv * (int) $agg['delivered_cnt']
                            : (float) $agg['delivered_price'] * $cv / 100.0;
                    }
                }
            }
        }

        return [
            'pilot' => $pilot,
            'monthKey' => $month,
            'daysInMonth' => $daysInMonth,
            'shiftsCount' => count($shifts),
            'workDays' => count($workDays),
            'totalHours' => round($totalHoursMs / 3600000, 2),
            'totalCommission' => round($totalCommission, 2),
            'deliveredCount' => $deliveredCount,
            'totalOrders' => $totalOrders,
            'totalBonus' => round($totalBonus, 2),
            'totalDeduction' => round($totalDeduction, 2),
            'totalAdvance' => round($totalAdvance, 2),
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       أدوات الكتابة — نقل حرفي لدوال board_* اللي بتلمس الحالة
       🔴 كلها **لازم** تتندى جوه DB::transaction: فيها أقفال صفوف
          (FOR UPDATE) وكتابات مترابطة مايصحش تتقسم.
    ═══════════════════════════════════════════════════════════ */

    /**
     * المقابل لـ board_lock_pilot(): صف الطيار بقفل FOR UPDATE.
     * القفل ده هو اللي بيمنع تسويتين متوازيتين لنفس الطيار من إنهما
     * يقروا نفس `custody_balance` ويكتبوا فوق بعض.
     */
    private function lockPilot(int $pilotId): array
    {
        $row = DB::select('SELECT * FROM pilots WHERE id = ? FOR UPDATE', [$pilotId])[0] ?? null;
        if (! $row) {
            throw ApiException::notFound('الطيار غير موجود');
        }

        return (array) $row;
    }

    /**
     * المقابل لـ board_next_queue_no(): الرقم التالي في آخر الدور.
     *
     * الـ`FOR UPDATE` على **كل صفوف المنتظرين** في الفرع مش تفصيلة: من غيره
     * طيارين بيدخلوا الدور في نفس اللحظة كانوا بياخدوا نفس الرقم.
     */
    private function nextQueueNo(int $branchId): int
    {
        return (int) $this->firstColumn(
            "SELECT COALESCE(MAX(queue_no),0) FROM pilots
          WHERE assigned_branch_id = ? AND status = 'waiting' FOR UPDATE",
            [$branchId]
        ) + 1;
    }

    /**
     * المقابل لـ board_queue_shift_after_removal(): خروج طيار من الدور معناه
     * إن اللي بعده كلهم بينقصوا 1 — الأرقام تفضل متراصة من غير فجوات.
     *
     * ⚠️ فرع فاضي أو رقم دور فاضي = **لا شيء** (وكمان `queue_no = 0` بيتعامل
     * كفاضي بسبب `!` مش `=== null`). سلوك الأصل بالحرف.
     */
    private function queueShiftAfterRemoval(?int $branchId, ?int $removedQueueNo): void
    {
        if (! $branchId || ! $removedQueueNo) {
            return;
        }
        DB::update(
            "UPDATE pilots SET queue_no = queue_no - 1
          WHERE assigned_branch_id = ? AND status = 'waiting' AND queue_no > ?",
            [$branchId, $removedQueueNo]
        );
    }

    /**
     * المقابل لـ board_enter_queue(): دخول الطيار آخر الدور.
     * بيمسح حالة الإذن والبريك كمان (`leave_*` و`break_started_at`) — الدخول
     * الدور معناه إنه رجع الشغل فعليًا.
     */
    private function enterQueue(int $pilotId, int $branchId, string $now): int
    {
        $next = $this->nextQueueNo($branchId);
        DB::update(
            "UPDATE pilots SET assigned_branch_id = ?, status = 'waiting', queue_no = ?,
                status_since = ?, break_started_at = NULL, leave_type = NULL,
                leave_reason = NULL, leave_forced = 0
          WHERE id = ?",
            [$branchId, $next, $now, $pilotId]
        );

        return $next;
    }

    /**
     * المقابل لـ board_release_pilot(): التحرير الكامل من الفرع (VOCAB بند 6).
     * كل حقول الحالة بتتصفّر، والإزاحة بتحصل **بس** لو كان منتظر — الطيار
     * اللي في إذن مالوش رقم دور أصلًا.
     */
    private function releasePilot(array $pilotRow): void
    {
        DB::update(
            "UPDATE pilots SET status = NULL, assigned_branch_id = NULL, queue_no = NULL,
                status_since = NULL, break_started_at = NULL, leave_type = NULL,
                leave_reason = NULL, leave_forced = 0
          WHERE id = ?",
            [(int) $pilotRow['id']]
        );
        if (($pilotRow['status'] ?? null) === 'waiting') {
            $this->queueShiftAfterRemoval(
                $pilotRow['assigned_branch_id'] !== null ? (int) $pilotRow['assigned_branch_id'] : null,
                $pilotRow['queue_no'] !== null ? (int) $pilotRow['queue_no'] : null
            );
        }
    }

    /**
     * المقابل لـ board_pilot_back_to_waiting_if_free(): قاعدة «آخر أوردر
     * بيرجّع الطيار waiting في آخر الدور».
     *
     * `$exceptOrderId` هو الأوردر اللي بيتغيّر في نفس المعاملة دلوقتي —
     * بيتستثنى من العد لأن صفه لسه ممكن يكون بحالته القديمة عند القراية.
     *
     * ⚠️ بيرجّع false من غير أي تغيير لو الطيار **مالوش فرع** — حتى لو مفيش
     * أوردرات جارية معاه. الطيار المحرَّر مايترجّعش لدور فرع مش تابع له.
     * ⚠️ ومفيش استثناء لـ`on_leave` هنا (على عكس `syncPilotStatus` في
     * الأوردرات) — الطيار اللي في إذن بيترجّع `waiting` عادي. فرق موروث.
     */
    private function pilotBackToWaitingIfFree(int $pilotId, ?int $exceptOrderId, string $now): bool
    {
        $pilot = $this->lockPilot($pilotId);

        $sql  = "SELECT COUNT(*) FROM orders WHERE pilot_id = ? AND status = 'delivering'";
        $args = [$pilotId];
        if ($exceptOrderId) {
            $sql .= ' AND id <> ?';
            $args[] = $exceptOrderId;
        }
        if ($this->countOf($sql, $args) > 0) {
            return false;
        }

        $branchId = $pilot['assigned_branch_id'] !== null ? (int) $pilot['assigned_branch_id'] : null;
        if (! $branchId) {
            return false;
        }
        $next = $this->nextQueueNo($branchId);
        DB::update(
            "UPDATE pilots SET status = 'waiting', queue_no = ?, status_since = ? WHERE id = ?",
            [$next, $now, $pilotId]
        );

        return true;
    }

    /**
     * 💰 المقابل لـ board_apply_cash_txn() (applyCashTxn الأصلية):
     * حركة خزنة بقفل صف الرصيد.
     *
     * الرصيد بيتحدّث بـ`balance = balance + ?` (مش قراءة ثم كتابة) —
     * والقفل قبله بيخلي الحركتين المتوازيتين يتسلسلوا. `type` بيحدد
     * الإشارة: `in` موجب وأي حاجة تانية سالب.
     *
     * ⚠️ مفيش أي فحص إن الخزنة تابعة للفرع اللي بيسوّي. منقول زي ما هو.
     */
    private function applyCashTxn(
        int $storeId,
        string $type,
        float $amount,
        string $reason,
        ?int $pilotId,
        ?int $branchId,
        string $by,
        string $now,
    ): void {
        $store = DB::select('SELECT id, balance FROM cash_stores WHERE id = ? FOR UPDATE', [$storeId])[0] ?? null;
        if (! $store) {
            throw new ApiException('الخزنة غير موجودة');
        }
        $delta = $type === 'in' ? $amount : -$amount;
        DB::update('UPDATE cash_stores SET balance = balance + ? WHERE id = ?', [$delta, $storeId]);
        DB::insert(
            'INSERT INTO cash_transactions (store_id, type, amount, reason, notes, related_pilot_id, branch_id, created_by, created_at)
         VALUES (?,?,?,?,?,?,?,?,?)',
            [$storeId, $type, $amount, $reason, null, $pilotId, $branchId, $by, $now]
        );
    }

    /**
     * 💰🔴 المقابل لـ board_apply_custody_delta() (منطق custodyDelta الأصلي):
     * فرق التحصيل بين المطلوب والمدفوع بيروح على عهدة الطيار.
     *
     *   • موجب = فلوس أوردرات لسه معاه → حركة `order_pending` تضاف لعهدته.
     *   • سالب = سدّد أكتر من المطلوب → حركة `order_extra` تتخصم منها.
     *
     * تفاصيل ممنوع تتغير:
     *  • العتبة **`abs($delta) < 0.005`** (نص قرش): أقل من كده = صفر ومفيش
     *    حركة أصلًا. ده اللي بيمنع صفوف عهدة وهمية من أخطاء الفاصلة العائمة.
     *  • العهدة **مبتنزلش تحت الصفر** — بتتقص عند 0. يعني تسديد زيادة أكبر
     *    من العهدة بيضيع الفرق (مابيتحوّلش لرصيد للطيار). سلوك الأصل.
     *  • قيمة الحركة المسجّلة هي **`abs($delta)` الكاملة** مش المقصوصة —
     *    فالمجموع من `custody_transactions` ممكن مايطابقش `custody_balance`.
     *    باج موروث ومنقول بالحرف.
     */
    private function applyCustodyDelta(array $pilotRow, float $delta, ?int $branchId, string $by, string $now): void
    {
        if (abs($delta) < 0.005) {
            return;
        }
        $newCustody = (float) $pilotRow['custody_balance'] + $delta;
        if ($newCustody < 0) {
            $newCustody = 0.0;
        }
        DB::update('UPDATE pilots SET custody_balance = ? WHERE id = ?', [$newCustody, (int) $pilotRow['id']]);
        DB::insert(
            'INSERT INTO custody_transactions (pilot_id, type, amount, store_id, branch_id, created_by, created_at)
         VALUES (?,?,?,?,?,?,?)',
            [
                (int) $pilotRow['id'],
                $delta > 0 ? 'order_pending' : 'order_extra',
                abs($delta),
                null, $branchId, $by, $now,
            ]
        );
    }

    /** المقابل لـ board_find_active_shift(): آخر وردية نشطة للطيار (بقفل اختياري) */
    private function findActiveShift(int $pilotId, bool $lock = false): ?array
    {
        $sql = "SELECT * FROM shifts WHERE pilot_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1";
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $row = DB::select($sql, [$pilotId])[0] ?? null;

        return $row ? (array) $row : null;
    }

    /**
     * المقابل لـ board_open_or_transfer_shift() (منطق openOrTransferShift):
     * **وردية نشطة واحدة بس لكل طيار**. لو فيه وحدة مفتوحة على فرع تاني
     * بتتنقل هي نفسها (مش بتتقفل وتتفتح جديدة) — عشان `shift_id` بتاع
     * الأوردرات يفضل واحد فالتقفيلة تشمل شغل الوردية كله.
     *
     * أول صف في `shift_branch_history` = الفرع اللي اتفتحت عليه، وكل نقلة
     * بتضيف صف. `BoardWire::shift` بيقرا القايمة دي ويطلّع `branchHistory`.
     */
    private function openOrTransferShift(int $pilotId, int $branchId, Actor $actor, bool $manual, string $now): int
    {
        $existing = $this->findActiveShift($pilotId, true);
        if ($existing) {
            if ((int) $existing['branch_id'] !== $branchId) {
                DB::update(
                    'UPDATE shifts SET branch_id = ?, transferred_at = ?, transferred_by = ? WHERE id = ?',
                    [$branchId, $now, $actor->username, (int) $existing['id']]
                );
                DB::insert(
                    'INSERT INTO shift_branch_history (shift_id, branch_id, moved_at, created_at) VALUES (?,?,?,?)',
                    [(int) $existing['id'], $branchId, $now, $now]
                );
            }

            return (int) $existing['id'];
        }

        DB::insert(
            "INSERT INTO shifts (pilot_id, branch_id, status, started_at, opened_by, opened_manually, created_at)
         VALUES (?,?,'active',?,?,?,?)",
            [$pilotId, $branchId, $now, $actor->username, $manual ? 1 : 0, $now]
        );
        $shiftId = (int) DB::getPdo()->lastInsertId();
        // أول صف في بصمة الفروع = الفرع اللي اتفتحت عليه
        DB::insert(
            'INSERT INTO shift_branch_history (shift_id, branch_id, moved_at, created_at) VALUES (?,?,?,?)',
            [$shiftId, $branchId, $now, $now]
        );

        return $shiftId;
    }

    /**
     * 💰🔴 المقابل لـ board_settle_pilot_money(): الجزء المشترك بين «إنهاء
     * الوردية» و«عودة الطيار» — نفس الحسبة حرفيًا في الاتنين.
     *
     * الخطوات بترتيبها (والترتيب جزء من الصح لأن كل خطوة بتقفل صفوف):
     *  1) كل أوردر «جاري التوصيل» على الطيار بياخد قرار من `orders[]`
     *     — **الافتراضي `delivered`** لو الأوردر مش مذكور في القرارات خالص.
     *     المُسلَّم بيضيف سعر توصيله لـ`expected`، وغير المُسلَّم لأ.
     *  2) الأوردرات اللي الطيار سلّمها من تطبيقه ولسه `money_settled = 0`
     *     بتتعلّم مُسوّاة وأسعارها بتضاف لـ`expected` كمان.
     *  3) المحصَّل بيدخل الخزنة (لو > 0 — والخزنة إجبارية ساعتها).
     *  4) `expected − collected` بيروح على العهدة عبر `applyCustodyDelta`.
     *
     * ملاحظات ممنوع تتغير:
     *  • المقارنة `$collectedAmount > 0` — تحصيل بصفر **مابيعملش حركة خزنة
     *    خالص** حتى لو الخزنة متبعوتة، لكن فرق العهدة بيتحسب عادي.
     *  • تعليق الأصل منقول: «الأصل مكانش بيعلّم moneySettled هنا رغم تحصيل
     *    الفلوس في نفس اللحظة — بنعلّمها 1 منعًا لعدّ الأوردر تاني في تسوية
     *    لاحقة (الفلوس اتحصّلت فعلًا دلوقتي)». يعني الـ1 دي إصلاح متسجّل
     *    في الأصل مش سهو.
     *  • سبب عدم التسليم الفاضي بيتخزن `—` (شرطة) مش null.
     *  • `pilot_name` بيتكتب لقطة على الأوردر — عشان الأوردر يفضل عارف
     *    مين وصّله حتى لو الطيار اتمسح بعدين.
     *
     * بترجع [expectedCollect, deliveredCount, undeliveredCount, settledCount]
     */
    private function settlePilotMoney(
        array $pilot,
        array $decisions,
        float $collectedAmount,
        ?int $cashStoreId,
        ?int $branchId,
        Actor $actor,
        string $now,
    ): array {
        $pilotId = (int) $pilot['id'];
        $decMap  = [];
        foreach ($decisions as $d) {
            if (isset($d['orderId'])) {
                $decMap[(int) $d['orderId']] = $d;
            }
        }

        // الأوردرات الجارية على الطيار — بقفل
        $active = array_map(
            fn ($r) => (array) $r,
            DB::select("SELECT id, total_delivery_price FROM orders WHERE pilot_id = ? AND status = 'delivering' FOR UPDATE", [$pilotId])
        );

        $expected         = 0.0;
        $deliveredCount   = 0;
        $undeliveredCount = 0;
        foreach ($active as $o) {
            $d = $decMap[(int) $o['id']] ?? [];
            $choice = ($d['choice'] ?? 'delivered') === 'undelivered' ? 'undelivered' : 'delivered';
            if ($choice === 'delivered') {
                DB::update(
                    "UPDATE orders SET status = 'delivered', status_since = ?, delivered_at = ?, money_settled = 1, pilot_name = ? WHERE id = ?",
                    [$now, $now, $pilot['name'], (int) $o['id']]
                );
                $expected += (float) $o['total_delivery_price'];
                $deliveredCount++;
            } else {
                $reason = trim((string) ($d['reason'] ?? '')) ?: '—';
                DB::update(
                    "UPDATE orders SET status = 'undelivered', status_since = ?, undelivered_at = ?, undelivered_reason = ?, pilot_name = ? WHERE id = ?",
                    [$now, $now, $reason, $pilot['name'], (int) $o['id']]
                );
                $undeliveredCount++;
            }

            /* تغيير حالة حقيقي لكل صف (delivered/undelivered) — بيتبثّ حتى
               وإحنا جوه أخطر قسم فلوس في النظام، لأن ده بالظبط اللي اللوحة
               مستنياه. العدد محدود بأوردرات طيار واحد الجارية، وكل صف
               اتحدّث فعلًا (UPDATE بالـid مش مشروط). */
            $this->broadcastOrder((int) $o['id']);
        }

        // أوردرات سلّمها الطيار من تطبيقه ولسه فلوسها مش متسوّاة
        $pending = array_map(
            fn ($r) => (array) $r,
            DB::select(
                "SELECT id, total_delivery_price FROM orders
          WHERE pilot_id = ? AND status = 'delivered' AND money_settled = 0 FOR UPDATE",
                [$pilotId]
            )
        );
        $settledCount = 0;
        foreach ($pending as $o) {
            /* 📡 مفيش بثّ في الحلقة دي — `money_settled` بس، من غير أي تغيير
               حالة. نفس قاعدة `OrdersController::settleMoney`، وكمان كل بثّة
               = SELECT زيادة جوه قسم ماسك أقفال على الأوردرات والمحافظ
               والخزنة. الاستطلاع بيمسكها من `updated_at`. */
            DB::update('UPDATE orders SET money_settled = 1 WHERE id = ?', [(int) $o['id']]);
            $expected += (float) $o['total_delivery_price'];
            $settledCount++;
        }

        // إيداع المحصَّل في الخزنة
        if ($collectedAmount > 0) {
            if (! $cashStoreId) {
                throw new ApiException('اختر الخزنة لإيداع المبلغ المحصَّل من الطيار');
            }
            $this->applyCashTxn(
                $cashStoreId,
                'in',
                $collectedAmount,
                'تحصيل من الطيار: ' . $pilot['name'],
                $pilotId,
                $branchId,
                $actor->username,
                $now
            );
        }

        // فرق التحصيل → عهدة
        $this->applyCustodyDelta($pilot, $expected - $collectedAmount, $branchId, $actor->username, $now);

        return [$expected, $deliveredCount, $undeliveredCount, $settledCount];
    }

    /**
     * 🔒 المقابل لـ board_assert_pilot_in_scope(): مشرف الفرع مقفول على
     * طياري فرعه، والإدارة على الكل.
     *
     * التعليق الأصلي منقول لأنه بيوثّق ثغرة اتصلحت: «كان ناقص، فمشرف فرع
     * كان يقدر يوقف إجباريًا (أو يتحكم في) طيار فرع تاني».
     *
     * ⚠️ مشرف فرع بـ`branch_id` فاضي (= 0) بيترفض على طول — حتى لو الطيار
     * كمان بلا فرع. الشرط `$userBranch === 0 ||` متعمّد.
     */
    private function assertPilotInScope(Actor $actor, array $pilot): void
    {
        if ($actor->role !== 'branch') {
            return;   // الإدارة: كل الفروع
        }
        $userBranch  = (int) ($actor->branchId ?? 0);
        $pilotBranch = $pilot['assigned_branch_id'] !== null ? (int) $pilot['assigned_branch_id'] : 0;
        if ($userBranch === 0 || $userBranch !== $pilotBranch) {
            throw ApiException::forbidden('الطيار ده مش تابع لفرعك');
        }
    }
}
