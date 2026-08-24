<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Support\ApiResponse;
use App\Support\PollableList;
use App\Support\WireTime;
use App\Wire\BoardWire;
use App\Wire\CoreWire;
use App\Wire\OrderWire;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * API تطبيق الطيار (Flutter · lib/main.dart) — نقل حرفي لمسارات القراءة
 * من api/routes/pilot.php.
 *
 * 🔴 دي **أخف وأسخن** مسارات النظام: التطبيق بيستطلعها كل 8 ثواني ليل
 * نهار لكل طيار شغّال. التعليق في رأس الملف الأصلي بيقول: كل مسار هنا
 * لازم يكون استعلامات مفهرسة قليلة + دعم `?since` (دستور الـAPI بند 5).
 * أي استعلام زيادة هنا بيتضرب في (عدد الطيارين × 450 مرة في الساعة).
 *
 * 🔒 كل المسارات مقفولة على دور `pilot` وبتشتغل **حصريًا** على
 * `pilot_id` المربوط بحساب الجلسة/التوكن — مفيش أي باراميتر بيحدد طيار
 * تاني. فرض الدور على مستوى المسار بـ `->middleware('role:pilot')`
 * عشان بلا دخول أصلًا الرد يبقى 401 مش 403 (زي require_auth الأولانية).
 *
 * الاستعلامات خام بـ DB::select زي الأصل — أسماء الأعمدة المشتقة من
 * الـjoin (`pilot_name` · `branch_name` · `assigned_branch_name`) جزء من
 * عقد طبقة السلك، `BoardWire`/`CoreWire` بيقروها بالاسم ده بالظبط.
 *
 * التطبيق مبني ومنشور على تليفونات الطيارين — أي مفتاح بيتغيّر = تطبيق
 * واقع من غير أي أثر في اللوج.
 *
 * 📡 **مفيش `BroadcastsOrders` هنا بالقصد.** docs/REALTIME.md §6 خطوة 1
 * بتقول «حط بثّة بعد كل تعديل حالة أوردر في OrdersController
 * وPilotAppController» — بس الملف ده **مابيكتبش في جدول `orders` خالص**.
 * كل كتاباته على `pilots` و`shifts` (الموقع، نسخة التطبيق، إنهاء الوردية
 * من التطبيق — والأخيرة «من غير أي تسوية فلوس» زي ما مكتوب على
 * `shiftEnd()`). ومسارات الطيار اللي **بتلمس** أوردر (تحميل/تسليم/عدم
 * تسليم/طلب إرجاع) بتتوجّه أصلًا لـ`OrdersController` و`BoardController`
 * وهناك البثّ موجود — زي `leaveEnd()` تحت اللي بتنده اللوحة بدل ما تكرّر
 * منطقها. استيراد الـtrait هنا كان هيبقى كود ميت.
 */
class PilotAppController
{
    /* ═══════════════════════════════════════════════════════════
       GET /api/pilot/state?since=&sig=
       النداء الموحّد الخفيف: حالة الطيار + الوردية المفتوحة + الإذن
       الحالي + طلب الوردية المعلّق + طلبات الإرجاع المعلّقة + نسخة
       التطبيق المطلوبة
    ═══════════════════════════════════════════════════════════ */

    /**
     * ⚠️ `?sig=` مش تكرار لـ`?since`. السبب من تعليق الأصل حرفيًا: فيه
     * تغييرات حالة **بتصفّر الأعمدة كلها NULL** (تحرير الطيار من الفرع —
     * board_release_pilot) فمفيش أي timestamp بيتحرك، و`since` لوحده
     * عمره ما هيحس بيها والتطبيق كان بيفضل شايف حالة قديمة للأبد.
     * التطبيق بيبعت آخر بصمة شافها، ولو اتغيرت بنرجّع الحالة كاملة حتى
     * لو التوقيتات ما اتحركتش.
     */
    public function state(Request $request): JsonResponse
    {
        $pilot   = $this->pilotCtx($request);
        $pilotId = (int) $pilot['id'];

        // عدد الأوردرات الجارية — activeOrders في اللسان القديم
        $pilot['active_orders'] = (int) DB::select(
            "SELECT COUNT(*) AS n FROM orders WHERE pilot_id = ? AND status = 'delivering'",
            [$pilotId]
        )[0]->n;

        // الوردية المفتوحة (لو فيه) + بصمة فروعها
        $shiftRow = $this->firstRow(
            "SELECT s.*, p.name AS pilot_name, b.name AS branch_name
               FROM shifts s
               JOIN pilots p ON p.id = s.pilot_id
               JOIN branches b ON b.id = s.branch_id
              WHERE s.pilot_id = ? AND s.status = 'active'
              ORDER BY s.id DESC LIMIT 1",
            [$pilotId]
        );
        $shiftHistory = [];
        if ($shiftRow) {
            $shiftHistory = array_map(fn ($h) => (array) $h, DB::select(
                'SELECT h.*, b.name AS branch_name FROM shift_branch_history h
                   JOIN branches b ON b.id = h.branch_id
                  WHERE h.shift_id = ? ORDER BY h.moved_at, h.id',
                [(int) $shiftRow['id']]
            ));
        }

        // الإذن الحالي (pending = مستني موافقة / approved = ساري) — الأحدث
        $leaveRow = $this->firstRow(
            "SELECT r.*, p.name AS pilot_name, b.name AS branch_name
               FROM pilot_leave_requests r
               JOIN pilots p ON p.id = r.pilot_id
               JOIN branches b ON b.id = r.branch_id
              WHERE r.pilot_id = ? AND r.status IN ('pending','approved')
              ORDER BY r.id DESC LIMIT 1",
            [$pilotId]
        );

        // طلب فتح الوردية المعلّق (لو فيه)
        $shiftReqRow = $this->firstRow(
            "SELECT r.*, p.name AS pilot_name, b.name AS branch_name
               FROM pilot_shift_requests r
               JOIN pilots p ON p.id = r.pilot_id
               JOIN branches b ON b.id = r.branch_id
              WHERE r.pilot_id = ? AND r.status = 'pending'
              ORDER BY r.id DESC LIMIT 1",
            [$pilotId]
        );

        // طلبات الإرجاع المعلّقة (الأوردر نفسه شايل returnStatus برضه)
        $returnRows = array_map(fn ($r) => (array) $r, DB::select(
            "SELECT r.*, p.name AS pilot_name, b.name AS branch_name
               FROM pilot_return_requests r
               JOIN pilots p ON p.id = r.pilot_id
               JOIN branches b ON b.id = r.branch_id
              WHERE r.pilot_id = ? AND r.status = 'pending'
              ORDER BY r.id DESC LIMIT 20",
            [$pilotId]
        ));

        // نسخة التطبيق المطلوبة — site_settings.pilotApp
        // (JSON: minVersion/latestVersion/updateUrl/message)
        $appRow = $this->firstRow(
            "SELECT setting_value, updated_at FROM site_settings WHERE setting_key = 'pilotApp' LIMIT 1"
        );
        $pilotApp = null;
        if ($appRow && $appRow['setting_value'] !== null && $appRow['setting_value'] !== '') {
            $decoded = json_decode((string) $appRow['setting_value'], true);
            if (is_array($decoded)) {
                $pilotApp = $decoded;
            }
        }

        /* بصمة الحالة — بتلقط التغييرات اللي بتصفّر الأعمدة (التحرير الكامل).
           ⚠️ ترتيب العناصر وأنواعها **جزء من العقد**: التطبيق بيخزّن البصمة
           ويبعتها تاني، فأي عنصر يتزحزح أو (int) يتشال = كل الأجهزة
           المنصّبة بتشوف البصمة اتغيرت مرة واحدة. وكمان `json_encode` هنا
           بـ JSON_UNESCAPED_UNICODE **لوحده** (من غير UNESCAPED_SLASHES
           بتاعة ApiResponse) — العلم الناقص ده بيغيّر النص فبيغيّر الـmd5. */
        $sig = substr(md5((string) json_encode([
            $pilot['status'], $pilot['queue_no'], $pilot['assigned_branch_id'],
            $pilot['leave_type'], $pilot['leave_reason'], $pilot['leave_forced'],
            $shiftRow ? [(int) $shiftRow['id'], $shiftRow['status'], (int) $shiftRow['branch_id']] : null,
            $leaveRow ? [(int) $leaveRow['id'], $leaveRow['status'], $leaveRow['forced_by']] : null,
            $shiftReqRow ? [(int) $shiftReqRow['id'], $shiftReqRow['status']] : null,
            array_map(fn ($r) => [(int) $r['id'], $r['status']], $returnRows),
            $appRow['setting_value'] ?? null,
            $pilot['active_orders'],
        ], JSON_UNESCAPED_UNICODE)), 0, 12);

        // ?since — تخطي الرد الكامل لو مفيش تغيير (والبصمة متطابقة لو اتبعتت)
        $since = $this->since($request);
        if ($since > 0) {
            $maxTs = $this->rowTs($pilot, ['status_since', 'break_started_at', 'app_version_at', 'created_at']);
            if ($shiftRow) {
                $maxTs = max($maxTs, $this->rowTs($shiftRow, ['started_at', 'ended_at', 'transferred_at', 'created_at']));
            }
            if ($leaveRow) {
                $maxTs = max($maxTs, $this->rowTs($leaveRow, ['requested_at', 'responded_at', 'ended_at', 'created_at']));
            }
            if ($shiftReqRow) {
                $maxTs = max($maxTs, $this->rowTs($shiftReqRow, ['requested_at', 'responded_at', 'created_at']));
            }
            foreach ($returnRows as $r) {
                $maxTs = max($maxTs, $this->rowTs($r, ['requested_at', 'created_at']));
            }
            if ($appRow) {
                $maxTs = max($maxTs, $this->rowTs($appRow, ['updated_at']));
            }

            $sentSig = trim((string) $request->query('sig', ''));
            // البصمة الفاضية = عميل قديم مابيبعتش sig — بنكتفي بـsince
            if ($maxTs > 0 && $maxTs <= $since && ($sentSig === '' || $sentSig === $sig)) {
                return ApiResponse::out([
                    'ok'        => true,
                    'serverNow' => PollableList::serverNowMs(),
                    'changed'   => false,
                    'sig'       => $sig,
                ]);
            }
        }

        // ⚠️ الغلاف هنا مكتوب بالإيد مش PollableList: فيه مفتاح `sig` بين
        // `changed` و`pilot`، والترتيب ده جزء من المقارنة الحرفية.
        return ApiResponse::out([
            'ok'        => true,
            'serverNow' => PollableList::serverNowMs(),
            'changed'   => true,
            'sig'       => $sig,
            'pilot'     => CoreWire::pilot($pilot),
            'shift'     => $shiftRow ? BoardWire::shift($shiftRow, $shiftHistory) : null,
            'currentLeave'          => $leaveRow ? BoardWire::leaveRequest($leaveRow) : null,
            'pendingShiftRequest'   => $shiftReqRow ? BoardWire::shiftRequest($shiftReqRow) : null,
            'pendingReturnRequests' => array_map(fn ($r) => BoardWire::returnRequest($r), $returnRows),
            'pilotApp'  => $pilotApp,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       GET /api/pilot/active-orders?since=
       الأوردرات المحمّلة عليه «جاري التوصيل» بشكل VOCAB الكامل
    ═══════════════════════════════════════════════════════════ */

    /**
     * ⚡ فحص التغيّر قبل تحميل أي حاجة — استعلام EXISTS واحد على فهرسين:
     *  1) أي أوردر لسه باسم الطيار اتعدّل بعد since (orders.updated_at بدقة ms)
     *  2) أي أوردر **اتنقل منه** لطيار تاني بعد since — النقل بيغيّر
     *     `pilot_id` فالصف بيختفي من مجموعة الطيار من غير ما يسيب أثر
     *     فيها، و`order_transfers.from_pilot_id` هو الأثر الوحيد.
     *     السماحية ثانية كاملة (`- 1000`) لأن `transferred_at` دقته ثانية
     *     مش ميلي ثانية — من غيرها النقلة اللي حصلت في نفس الثانية
     *     بتضيع والتطبيق بيفضل عارض أوردر مش بتاعه.
     */
    public function activeOrders(Request $request): JsonResponse
    {
        $pilot   = $this->pilotCtx($request);
        $pilotId = (int) $pilot['id'];

        $since = $this->since($request);
        if ($since > 0) {
            $changed = DB::select(
                'SELECT EXISTS(SELECT 1 FROM orders
                                WHERE pilot_id = ? AND updated_at > FROM_UNIXTIME(? / 1000))
                     OR EXISTS(SELECT 1 FROM order_transfers
                                WHERE from_pilot_id = ? AND transferred_at > FROM_UNIXTIME((? - 1000) / 1000)) AS c',
                [$pilotId, $since, $pilotId, $since]
            )[0]->c;

            if (! (int) $changed) {
                return PollableList::unchanged();
            }
        }

        // الفرز على status_since ثم id: الأقدم دخولًا لحالة «جاري التوصيل»
        // فوق — ترتيب شاشة التطبيق، وid بيثبّت اللي في نفس الثانية
        $rows = DB::select(
            OrderWire::baseSql() .
            " WHERE o.pilot_id = ? AND o.status = 'delivering'
              ORDER BY o.status_since, o.id",
            [$pilotId]
        );

        return PollableList::items(OrderWire::batch($rows));
    }

    /* ═══════════════════════════════════════════════════════════
       قوايم الطلبات — إرجاع / أذونات / ورديات
       (الأصل بينده board_*_requests_list وبيفلترها على الطيار)
    ═══════════════════════════════════════════════════════════ */

    /** GET /api/pilot/return-requests?status=&since= — طلباته هو بس */
    public function returnRequests(Request $request): JsonResponse
    {
        return $this->pilotRequestsList(
            $request,
            'pilot_return_requests',
            fn ($r) => BoardWire::returnRequest($r),
            ['requested_at', 'created_at']
        );
    }

    /** GET /api/pilot/leave-requests?status=&since= */
    public function leaveRequests(Request $request): JsonResponse
    {
        return $this->pilotRequestsList(
            $request,
            'pilot_leave_requests',
            fn ($r) => BoardWire::leaveRequest($r),
            ['requested_at', 'responded_at', 'ended_at', 'created_at']
        );
    }

    /** GET /api/pilot/shift-requests?status=&since= */
    public function shiftRequests(Request $request): JsonResponse
    {
        return $this->pilotRequestsList(
            $request,
            'pilot_shift_requests',
            fn ($r) => BoardWire::shiftRequest($r),
            ['requested_at', 'responded_at', 'created_at']
        );
    }

    /* ═══════════════════════════════════════════════════════════
       GET /api/pilot/finished-orders?day=YYYY-MM-DD&shift=&since=
       تاب «الطلبات المنتهية» بإحصائيات الوردية
    ═══════════════════════════════════════════════════════════ */

    /**
     * الافتراضي: أوردرات الوردية المفتوحة الحالية، ولو مفيش وردية فيوم
     * القاهرة الحالي. `?day=` بيفلتر بيوم قاهرة معيّن، `?shift=` بوردية
     * معيّنة (لازم تكون بتاعته هو — الفحص ده هو اللي بيمنع طيار يقرا
     * وردية زميله بتعديل رقم في الرابط).
     *
     * ⚠️ نافذة اليوم بتتبني على **توقيت القاهرة** ثم تتحوّل UTC، مش على
     * UTC مباشرة: `+1 day` بيتحسب على الكائن بتوقيت القاهرة عشان يوم
     * التغيير الصيفي يفضل 24 ساعة قاهرة مش 24 ساعة UTC.
     *
     * والفلترة على `COALESCE(delivered_at, undelivered_at, status_since)`
     * مش على `created_at`: أوردر اتعمل امبارح واتسلّم النهارده بيتحسب
     * على وردية النهارده — ده اللي بيخلي إحصائية الطيار مطابقة لكشفه.
     */
    public function finishedOrders(Request $request): JsonResponse
    {
        $pilot   = $this->pilotCtx($request);
        $pilotId = (int) $pilot['id'];

        $where = ['o.pilot_id = ?', "o.status IN ('delivered','undelivered')"];
        $args  = [$pilotId];

        $day     = trim((string) $request->query('day', ''));
        $shiftId = $request->query('shift') !== null ? (int) $request->query('shift') : 0;

        if ($shiftId > 0) {
            // وردية معيّنة — لازم تكون بتاعته هو
            $own = DB::select('SELECT id FROM shifts WHERE id = ? AND pilot_id = ?', [$shiftId, $pilotId]);
            if (! $own) {
                throw ApiException::forbidden('الوردية مش بتاعتك');
            }
            $where[] = 'o.shift_id = ?';
            $args[]  = $shiftId;
        } elseif ($day !== '') {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                throw new ApiException('اليوم بصيغة YYYY-MM-DD');
            }
            try {
                $start = new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('Africa/Cairo'));
            } catch (Exception) {
                // الشكل عدّى الـregex بس التاريخ نفسه مستحيل (2026-13-45)
                throw new ApiException('يوم غير صالح');
            }
            $utc     = new DateTimeZone('UTC');
            $fromUtc = $start->setTimezone($utc)->format('Y-m-d H:i:s');
            $toUtc   = $start->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
            $where[] = 'COALESCE(o.delivered_at, o.undelivered_at, o.status_since) >= ?
                AND COALESCE(o.delivered_at, o.undelivered_at, o.status_since) < ?';
            array_push($args, $fromUtc, $toUtc);
        } else {
            // الافتراضي: الوردية المفتوحة الحالية (زي الشاشة الأصلية)، وإلا يوم القاهرة الحالي
            $activeShift = DB::select(
                "SELECT id FROM shifts WHERE pilot_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
                [$pilotId]
            )[0]->id ?? null;

            if ($activeShift !== null) {
                $where[] = 'o.shift_id = ?';
                $args[]  = (int) $activeShift;
            } else {
                $utc     = new DateTimeZone('UTC');
                $start   = new DateTimeImmutable(WireTime::cairoDayKey() . ' 00:00:00', new DateTimeZone('Africa/Cairo'));
                $where[] = 'COALESCE(o.delivered_at, o.undelivered_at, o.status_since) >= ?
                    AND COALESCE(o.delivered_at, o.undelivered_at, o.status_since) < ?';
                array_push(
                    $args,
                    $start->setTimezone($utc)->format('Y-m-d H:i:s'),
                    $start->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')
                );
            }
        }

        /* ?since — المسار ده كان الوحيد في pilot.php اللي مابيقراش since،
           وبيرجّع changed:true ثابت. التطبيق بيستطلعه كل 5 ثواني، فكل طيار
           كان بيحمّل 200 أوردر كامل بالطرود والصور كل دورة (~43 ميجا من
           باقته في وردية 12 ساعة) — وهي بيانات شبه ثابتة أصلًا لأنها
           أوردرات خلصت. الفحص EXISTS الرخيص ده هو الإصلاح، منقول زي ما هو.

           ملاحظة: القراءة هنا **مش** زي pilot_since() في باقي المسارات —
           مفيش max(0,…)، فـ`?since=-5` بيعدّي بقيمة سالبة والفحص بيلاقي
           كل حاجة أحدث منها. سلوك الأصل بالحرف. */
        $sinceRaw = $request->query('since');
        $since = ($sinceRaw !== null && $sinceRaw !== '') ? (int) $sinceRaw : null;
        if ($since !== null) {
            $changed = DB::select(
                'SELECT EXISTS(SELECT 1 FROM orders o WHERE ' . implode(' AND ', $where)
                . ' AND o.updated_at > FROM_UNIXTIME(? / 1000)) AS c',
                array_merge($args, [$since])
            )[0]->c;

            if (! (int) $changed) {
                return PollableList::unchanged();
            }
        }

        $rows = DB::select(
            OrderWire::baseSql()
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY o.status_since DESC, o.id DESC LIMIT 200',
            $args
        );

        /* الإحصائيات — نفس حسبة الأصل (شريط StatCell + عمولة VOCAB بند 13.4).
           🔴 دي فلوس: fixed = القيمة × **عدد المُسلَّم**، percent = نسبة من
           إجمالي التحصيل. لاحظ إن الحسبة دي تجميعية على القايمة، فمش هي
           نفسها Commission::forPilot() اللي بتحسب أوردر واحد — ممنوع
           استبدالها بيها.

           ⚠️ الإجمالي بيتجمّع من **المُسلَّم بس**؛ «لم يتم التوصيل» بيتعد في
           undeliveredCount لكن مابيدخلش التحصيل ولا العمولة. */
        $deliveredCount = 0;
        $deliveredTotal = 0.0;
        foreach ($rows as $r) {
            if ($r->status === 'delivered') {
                $deliveredCount++;
                $deliveredTotal += (float) $r->total_delivery_price;
            }
        }
        $commissionType  = $pilot['commission_type'] ?: 'percent';
        $commissionValue = (float) $pilot['commission_value'];
        $commission = $commissionType === 'fixed'
            ? $commissionValue * $deliveredCount
            : $deliveredTotal * $commissionValue / 100.0;

        // غلاف مكتوب بالإيد: فيه `stats` بعد `items` — مش شكل PollableList
        return ApiResponse::out([
            'ok'        => true,
            'serverNow' => PollableList::serverNowMs(),
            'changed'   => true,
            'items'     => OrderWire::batch($rows),
            'stats'     => [
                'totalOrders'      => count($rows),
                'deliveredCount'   => $deliveredCount,
                'undeliveredCount' => count($rows) - $deliveredCount,
                'totalCollected'   => round($deliveredTotal, 2),
                'commissionType'   => $commissionType,
                'commissionValue'  => $commissionValue,
                'commission'       => round($commission, 2),
            ],
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       GET /api/pilot/closeouts — تقفيلاته الشهرية المحفوظة
    ═══════════════════════════════════════════════════════════ */

    /**
     * ⚠️ الرد هنا `{ok, items}` **من غير `serverNow` ولا `changed`** — على
     * عكس كل قوايم النظام. الأصل بيبني المصفوفة بإيده وبيسيب المفتاحين،
     * والمسار مابيدعمش `?since` أصلًا (36 شهر بيانات شبه ساكنة). مخالف
     * لبند 5 في CONVENTIONS.md بس العقد مجمّد — استعمال PollableList هنا
     * كان هيضيف مفتاحين ويكسّر المقارنة الحرفية.
     */
    public function closeouts(Request $request): JsonResponse
    {
        $pilot = $this->pilotCtx($request);

        return ApiResponse::ok([
            'items' => array_map(fn ($r) => BoardWire::closeout($r), DB::select(
                'SELECT c.*, p.name AS pilot_name
                   FROM pilot_monthly_closeouts c
                   JOIN pilots p ON p.id = c.pilot_id
                  WHERE c.pilot_id = ?
                  ORDER BY c.month DESC LIMIT 36',
                [(int) $pilot['id']]
            )),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       مسارات الكتابة
       ⚠️ تلات مسارات كتابة من pilot.php **مالهاش دوال هنا خالص** لأن
       الأصل بيعيد استخدام منطق board.php/orders.php حرفيًا من غير أي
       تكرار (تعليق رأس الملف: «منطق التسليم/الإرجاع/الأذونات/الورديات
       بيعاد استخدامه من orders.php و board.php — مفيش تكرار منطق»):

         POST /api/pilot/orders/{id}/deliver   → OrdersController@deliver
         POST /api/pilot/orders/{id}/undeliver → OrdersController@undeliver
         POST /api/pilot/return-requests       → BoardController@returnRequestCreate
         POST /api/pilot/leave-requests        → BoardController@leaveRequestCreate
         POST /api/pilot/shift-requests        → BoardController@shiftRequestCreate

       الأصل بيلف كل واحدة فيهم بـ`require_role('pilot')` قبل ما ينده
       دالة المجال، والدالة نفسها بتعمل require_role أوسع (أو نفس الدور).
       الصافي = **الطيار بس**، وده بيتحقق هنا بـ`->middleware('role:pilot')`
       على المسار. أي تكرار للمنطق هنا كان هيخلي معاملتين وأقفالًا
       مختلفة لنفس العملية — وده بالظبط اللي الأصل بيتجنبه.
    ═══════════════════════════════════════════════════════════ */

    /**
     * POST /api/pilot/leave-requests/end — «عدت للعمل» (pilot_leave_end)
     *
     * المسار الوحيد في العيلة دي اللي محتاج طبقة خاصة: التطبيق مابيبعتش
     * رقم الطلب أصلًا (شاشة «عدت للعمل» فيها زرار واحد)، فبندوّر على
     * الإذن الساري بتاع **صاحب الحساب** وبعدين نندي نفس منطق اللوحة.
     *
     * ⚠️ الترتيب مقصود: `pilotCtx()` الأول (403 «الحساب مش مربوط بطيار»)،
     * وبعدين الدوران على الإذن (400 «مفيش إذن ساري عليك دلوقتي»)، وبعدين
     * بس المعاملة. رفض الإيقاف الإجباري (leaveForced) بيحصل **جوه**
     * `BoardController::leaveRequestEnd` مش هنا — بالحرف زي الأصل.
     */
    public function leaveEnd(Request $request): JsonResponse
    {
        $pilot = $this->pilotCtx($request);

        /* الأحدث `id` مش الأحدث `requested_at` — زي الأصل بالحرف.
           الفحص في الأصل `=== false` (fetchColumn) يعني «مفيش صف»؛
           هنا المقابل هو الصف الغايب. */
        $reqId = DB::select(
            "SELECT id FROM pilot_leave_requests
              WHERE pilot_id = ? AND status = 'approved'
              ORDER BY id DESC LIMIT 1",
            [(int) $pilot['id']]
        )[0]->id ?? null;

        if ($reqId === null) {
            throw new ApiException('مفيش إذن ساري عليك دلوقتي');
        }

        // نفس المعاملة ونفس الأقفال بتاعة اللوحة — مفيش تكرار منطق
        return (new BoardController())->leaveRequestEnd($request, (string) (int) $reqId);
    }

    /**
     * POST /api/pilot/location — {lat, lng} · التطبيق بيبعتها كل ≤60 ثانية.
     *
     * جملة UPDATE واحدة من غير معاملة — زي الأصل. المسار ده بيتنده من كل
     * طيار شغّال كل دقيقة، فأي قفل أو معاملة هنا بتتضرب في العدد ده.
     *
     * ⚠️ التحقق `isset($b['lat'], $b['lng'])` معناه إن **null بيترفض** زي
     * الغياب بالظبط، و`is_numeric` بيقبل النص «30.1» زي الرقم — سلوك
     * الأصل بالحرف (التطبيق بيبعت أرقام JSON خام).
     */
    public function location(Request $request): JsonResponse
    {
        $pilot = $this->pilotCtx($request);

        $latIn = $request->input('lat');
        $lngIn = $request->input('lng');
        if ($latIn === null || $lngIn === null || ! is_numeric($latIn) || ! is_numeric($lngIn)) {
            throw new ApiException('ابعت lat و lng أرقام');
        }

        $lat = (float) $latIn;
        $lng = (float) $lngIn;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new ApiException('إحداثيات غير صالحة');
        }

        DB::update(
            'UPDATE pilots SET lat = ?, lng = ?, location_updated_at = ? WHERE id = ?',
            [$lat, $lng, WireTime::nowDb(), (int) $pilot['id']]
        );

        return ApiResponse::ok();
    }

    /**
     * POST /api/pilot/version — {version} بصمة نسخة التطبيق (stampAppVersion)
     *
     * الإدارة بتشوف كل طيار على أنهي نسخة، ومنها بتتقرر رسالة التحديث
     * الإجباري في `/api/pilot/state` (site_settings.pilotApp).
     *
     * ⚠️ `strlen` **بالبايت** مش بالحرف — والنمط بيحصر المدخل في ASCII
     * أصلًا فمفيش فرق عملي. منقول زي ما هو.
     */
    public function version(Request $request): JsonResponse
    {
        $pilot = $this->pilotCtx($request);

        $version = trim((string) ($request->input('version') ?? ''));
        if ($version === '' || strlen($version) > 20 || ! preg_match('/^[0-9][0-9.+\-a-zA-Z]*$/', $version)) {
            throw new ApiException('ابعت version بصيغة صحيحة (مثال 1.9.2)');
        }

        DB::update(
            'UPDATE pilots SET app_version = ?, app_version_at = ? WHERE id = ?',
            [$version, WireTime::nowDb(), (int) $pilot['id']]
        );

        return ApiResponse::ok();
    }

    /**
     * POST /api/pilot/shift/end — إنهاء الوردية من التطبيق نفسه.
     *
     * نفس سلوك التطبيق القديم حرفيًا (closeShift + releasePilotFromBranch):
     * قفل سجل الوردية → تعليمها `ended` → قفل صف الطيار → التحرير الكامل
     * من الفرع وإزاحة الدور.
     *
     * 🔴 **من غير أي تسوية فلوس** — لا عهدة ولا خزنة ولا `money_settled`.
     * التسوية بتفضل شغلة الفرع من اللوحة (`POST /api/shifts/{id}/end`
     * اللي بينده `settlePilotMoney`). ده مش سهو: الطيار بيقفل ورديته من
     * التطبيق وهو في الشارع، والفلوس بتتسلّم في الفرع بعدين.
     *
     * ⚠️ بيشتغل **حتى لو مفيش وردية مفتوحة** — بيحرّر الطيار بس. زي القديم.
     * ⚠️ `ended_by = 'pilot'` نص حرفي مش اسم المستخدم — الواجهة بتفرّق.
     * ⚠️ ترتيب القفلين (الوردية ثم الطيار) جزء من الصح، متغيّرش.
     */
    public function shiftEnd(Request $request): JsonResponse
    {
        $pilot   = $this->pilotCtx($request);
        $pilotId = (int) $pilot['id'];

        try {
            DB::transaction(function () use ($pilotId): void {
                $shiftId = DB::select(
                    "SELECT id FROM shifts WHERE pilot_id = ? AND status = 'active'
                      ORDER BY id DESC LIMIT 1 FOR UPDATE",
                    [$pilotId]
                )[0]->id ?? null;

                $now = WireTime::nowDb();
                if ($shiftId !== null) {
                    DB::update(
                        "UPDATE shifts SET status = 'ended', ended_at = ?, ended_by = 'pilot' WHERE id = ?",
                        [$now, (int) $shiftId]
                    );
                }

                $this->releasePilot($this->lockPilot($pilotId));
            });
        } catch (ApiException $e) {
            /* الأصل بينده fail() اللي بتعمل exit، فرسالة الدالة الداخلية
               (زي «الطيار غير موجود» 404) بتوصل للعميل زي ما هي **من غير**
               ما تتحوّل لرسالة الـ500 العامة. هنا الاستثناء بيعدّي بنفس
               الطريقة — والمعاملة بترجع لوحدها. */
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر إنهاء الوردية — جرّب تاني', 500);
        }

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       أدوات داخلية — المقابل لـ pilot_ctx / pilot_since / pilot_row_ts
    ═══════════════════════════════════════════════════════════ */

    /**
     * صف pilots كامل لحساب الجلسة + اسم الفرع واسم المستخدم.
     *
     * 🔒 القاعدة الحاكمة في الملف كله: **الطيار بيتحدّد من الحساب مش من
     * باراميتر**. الربط users.pilot_id → pilots.id، ولو الحساب مش مربوط
     * بطيار الرد 403 بالنص ده حرفيًا.
     */
    private function pilotCtx(Request $request): array
    {
        $actor = $request->actorOrFail();

        $row = $this->firstRow(
            'SELECT p.*, b.name AS assigned_branch_name, u.username
               FROM users u
               JOIN pilots p ON p.id = u.pilot_id
               LEFT JOIN branches b ON b.id = p.assigned_branch_id
              WHERE u.id = ? LIMIT 1',
            [$actor->userId]
        );

        if (! $row) {
            throw ApiException::forbidden('الحساب مش مربوط بطيار');
        }

        return $row;
    }

    /**
     * قايمة طلبات الطيار — المقابل لفرع `role === 'pilot'` في
     * board_*_requests_list() + غلاف board_list_out().
     *
     * فرع الموظفين (board_branch_scope و`?branch=`) **مش منقول هنا عمدًا**:
     * المسار مقفول على دور pilot بالـmiddleware فالفرع ده ميت. هيتنقل مع
     * board.php على مساراته هو (GET /api/leave-requests وإخوانه).
     *
     * ⚠️ `pilot_id` بييجي من `users.pilot_id` مباشرة من غير أي فحص: لو
     * الحساب مش مربوط بطيار بيبقى **0** والقايمة بترجع فاضية بدل 403 —
     * على عكس المسارات اللي بتعدي على pilotCtx(). فرق حقيقي في الأصل
     * ومنقول زي ما هو.
     *
     * @param  callable(array): array  $wire
     * @param  string[]  $tsCols
     */
    private function pilotRequestsList(Request $request, string $table, callable $wire, array $tsCols): JsonResponse
    {
        $actor = $request->actorOrFail();

        $pilotId = (int) (DB::select('SELECT pilot_id FROM users WHERE id = ?', [$actor->userId])[0]->pilot_id ?? 0);

        $where = ['r.pilot_id = ?'];
        $args  = [$pilotId];

        /* `?status` بيتحط في WHERE **كما هو** من غير أي تحقق من القايمة
           المسموحة — قيمة مش موجودة = قايمة فاضية مش خطأ. وكمان الفحص
           `empty()` مش `!== ''`، يعني `?status=0` بيتجاهل. سلوك الأصل. */
        $status = $request->query('status');
        if (! empty($status)) {
            $where[] = 'r.status = ?';
            $args[]  = $status;
        }

        $rows = DB::select(
            "SELECT r.*, p.name AS pilot_name, b.name AS branch_name
               FROM {$table} r
               JOIN pilots p ON p.id = r.pilot_id
               JOIN branches b ON b.id = r.branch_id"
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY r.id DESC LIMIT 300',
            $args
        );

        /* غلاف board_list_out: الدلتا محسوبة من **أعمدة الصفوف نفسها** مش
           من استعلام تاني — لو أحدث توقيت في القايمة أقدم من `?since`
           بنرجّع changed:false من غير items. لاحظ الشرط `maxTs > 0`:
           قايمة فاضية (أو أعمدة توقيت كلها NULL) بترجع الرد الكامل — مش
           changed:false — عشان التطبيق يعرف إن القايمة اتفضّت. */
        $items = [];
        $maxTs = 0;
        foreach ($rows as $row) {
            $row = (array) $row;
            $items[] = $wire($row);
            $maxTs = max($maxTs, $this->rowTs($row, $tsCols));
        }

        $since = $this->since($request);
        if ($since > 0 && $maxTs > 0 && $maxTs <= $since) {
            return PollableList::unchanged();
        }

        return PollableList::items($items);
    }

    /** ?since=<server_ms> — المقابل لـ pilot_since() / board_since() */
    private function since(Request $request): int
    {
        $raw = $request->query('since');

        return $raw === null ? 0 : max(0, (int) $raw);
    }

    /**
     * أكبر توقيت (ms) من أعمدة DATETIME بتوقيت UTC في صف — المقابل لـ
     * pilot_row_ts() / board_row_ts().
     *
     * الفحص `!empty()` مش `!== null`: العمود اللي فيه نص فاضي أو
     * "0000-00-00" بيتتخطى بدل ما يرمي تاريخ خرافي في الحسبة.
     *
     * @param  string[]  $cols
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

    /** أول صف كمصفوفة — المقابل لـ $st->fetch() ?: null */
    private function firstRow(string $sql, array $bindings = []): ?array
    {
        $row = DB::select($sql, $bindings)[0] ?? null;

        return $row !== null ? (array) $row : null;
    }

    /* ═══════════════════════════════════════════════════════════
       أدوات الطيار المشتركة مع اللوحة — board_lock_pilot /
       board_release_pilot / board_queue_shift_after_removal

       ⚠️ الجُمل دي **مكرّرة** من `BoardController` عن قصد: نظائرها هناك
       `private` ومفيش طريقة نناديها بيها من هنا، و`pilot_shift_end` في
       الأصل بينده `board_release_pilot` مباشرةً. البديل كان تغيير رؤية
       دوال `BoardController` — ملف بيتكتب فيه بالتوازي. لو الجُمل دي
       اتغيّرت هناك لازم تتغيّر هنا (كلها منقولة من نفس المصدر:
       api/routes/board.php سطور 113 و 146 و 171).
    ═══════════════════════════════════════════════════════════ */

    /** board_lock_pilot(): صف الطيار بقفل FOR UPDATE — لازم جوه معاملة */
    private function lockPilot(int $pilotId): array
    {
        $row = DB::select('SELECT * FROM pilots WHERE id = ? FOR UPDATE', [$pilotId])[0] ?? null;
        if (! $row) {
            throw ApiException::notFound('الطيار غير موجود');
        }

        return (array) $row;
    }

    /**
     * board_queue_shift_after_removal(): خروج طيار من الدور معناه إن اللي
     * بعده كلهم بينقصوا 1 — الأرقام تفضل متراصة من غير فجوات.
     *
     * ⚠️ فرع فاضي أو رقم دور فاضي = **لا شيء** (و`queue_no = 0` بيتعامل
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
     * board_release_pilot(): التحرير الكامل من الفرع (VOCAB بند 6).
     * كل حقول الحالة بتتصفّر، والإزاحة بتحصل **بس** لو كان منتظر — الطيار
     * اللي في إذن مالوش رقم دور أصلًا.
     *
     * التصفير الكامل ده هو السبب في وجود `?sig=` في `GET /api/pilot/state`:
     * مفيش أي عمود توقيت بيتحرك هنا، فـ`?since` لوحده عمره ما هيحس بيه.
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
}
