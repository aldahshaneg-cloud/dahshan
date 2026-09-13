<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Support\ApiResponse;
use App\Support\BizDay;
use App\Support\Money;
use App\Support\PollableList;
use App\Support\WireTime;
use App\Wire\BoardWire;
use App\Wire\PilotAccountingWire as W;
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
            $maxTs = $this->rowTs($pilot, ['status_since', 'break_started_at', 'location_updated_at', 'app_version_at', 'created_at']);
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

        // الطابع قبل الفحص والاستعلام (2026-09-08): أوردر يتحمّل وسط النداء كان بيقع في الفجوة ويختفي لحد ريستارت
        $now = PollableList::serverNowMs();
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
                return PollableList::unchanged($now);
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

        return PollableList::items(self::netCollectView(OrderWire::batch($rows)), $now);
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
       يوم الطيار = يوم **ورديته**، مش يوم النتيجة
    ═══════════════════════════════════════════════════════════ */

    /**
     * التعبير اللي بيحدّد «الأوردر ده بتاع أنهي يوم».
     *
     * ═══ ليه الوردية مش الساعة ═══
     * وردية الشركة بتبدأ 9 ص وبتنتهي 4 الفجر — يعني اليوم بيعدّي نص
     * الليل. الكود القديم كان بيفلتر من 00:00 لـ 00:00 بتوقيت القاهرة،
     * فآخر أربع ساعات من كل وردية كانت **بتتنقل لليوم اللي بعده**:
     *
     *   الطيار سلّم أوردر 2 الفجر وهو لسه في وردية بدأت 9 ص إمبارح
     *     • كشف الإدارة (PilotAccountingController) بيحسبه على إمبارح
     *     • تطبيق الطيار كان بيحسبه على النهارده
     *   ⟵ الرقمين مايطلعوش مع بعض ومحدش يعرف مين الغلطان.
     *
     * صاحب النظام حدّد القاعدة بالنص: «لو لسه في الوردية المفتوحة يبقى
     * اليوم القديم، أما لو فتح وردية جديدة يبقى اليوم الجديد». يعني
     * الفاصل هو **فتح وردية**، مش ساعة ثابتة — وده بيغطّي الحالات كلها
     * من غير استثناءات: الوردية اللي اتنست مفتوحة 25 ساعة كلها يوم
     * واحد، والوردية اللي اتفتحت 6 ص بعد ما اللي قبلها قفلت يوم جديد.
     *
     * الرجوع لوقت الأوردر نفسه بيحصل بس لو مفيش `shift_id` — وده مش
     * موجود في أي صف على الإنتاج (0 من 31)، بس الحارس أرخص من الثقة.
     */
    private const DAY_ANCHOR =
        "COALESCE((SELECT sh.started_at FROM shifts sh WHERE sh.id = o.shift_id),
                  o.delivered_at, o.undelivered_at, o.status_since)";

    /**
     * حدود نافذة UTC لمدى تواريخ قاهرة **شامل الطرفين**.
     *
     * ⚠️ الحساب بيتعمل على كائن بتوقيت القاهرة الأول وبعدين بيتحوّل UTC،
     * مش بإزاحة ثابتة: مصر رجّعت التوقيت الصيفي، فـ`+03:00` صح نص السنة
     * وغلط النص التاني. `DateTimeZone('Africa/Cairo')` عارف الفرق.
     *
     * @return array{0: string, 1: string}  [من (شامل), إلى (غير شامل)]
     */
    private static function cairoWindow(string $fromDate, string $toDate): array
    {
        $tz  = new DateTimeZone('Africa/Cairo');
        $utc = new DateTimeZone('UTC');
        $a   = new DateTimeImmutable($fromDate . ' 00:00:00', $tz);
        $b   = (new DateTimeImmutable($toDate . ' 00:00:00', $tz))->modify('+1 day');

        return [
            $a->setTimezone($utc)->format('Y-m-d H:i:s'),
            $b->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * «يوم الطيار الحالي» = يوم بداية وردية**ه** المفتوحة، ولو مقفولة
     * فيوم آخر وردية، ولو عمره ما اشتغل فيوم القاهرة الحالي.
     *
     * 🔴 السيرفر هو اللي بيحسب ده مش التطبيق. سببين:
     *   1. التليفون ممكن يكون على منطقة زمنية غلط أو ساعة مظبوطة بالإيد —
     *      وساعتها «النهارده» في التطبيق تفرق عن «النهارده» في الكشف.
     *   2. القاعدة نفسها بتعتمد على الورديات، والتطبيق مش شايفها كلها.
     *
     * ومنه بتتبني «الشهر» و«السنة» كمان: لو الطيار فاتح الشاشة 2 الفجر
     * أول سبتمبر وهو في وردية بدأت 31 أغسطس، «الشهر ده» = **أغسطس**.
     * أي حساب تاني كان هيوريه شهر فاضي وهو لسه بيشتغل.
     */
    private function anchorDay(int $pilotId): string
    {
        $row = DB::select(
            "SELECT started_at FROM shifts WHERE pilot_id = ?
              ORDER BY (status = 'active') DESC, started_at DESC LIMIT 1",
            [$pilotId]
        )[0]->started_at ?? null;

        if ($row === null) {
            return BizDay::key();
        }

        /* يوم **تجاري** (بلاغ 2026-09-02): وردية بدأت 00:30 بليل — نادرة
           بس واردة — تتبع يوم امبارح زي كل حسابات النظام. */
        return \App\Wire\PilotAccountingWire::bizMoment($row, BizDay::startHour())['date']
            ?? BizDay::key();
    }

    /** تاريخ قاهرة صالح؟ (الصيغة **و** التاريخ نفسه — 2026-13-45 بيعدّي الregex) */
    private static function validDate(string $d): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
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
        $from    = trim((string) $request->query('from', ''));
        $to      = trim((string) $request->query('to', ''));
        $period  = trim((string) $request->query('period', ''));

        /* الاختصارات بتتحوّل لمدى تواريخ هنا عشان يبقى فيه مسار حساب
           واحد. `anchorDay` هو نقطة الارتكاز — مش تاريخ النهارده. */
        if ($period !== '' && $day === '' && $from === '' && $to === '') {
            $anchor = $this->anchorDay($pilotId);
            if ($period === 'today') {
                $day = $anchor;
            } elseif ($period === 'month') {
                $from = substr($anchor, 0, 7) . '-01';
                $to   = $anchor;
            } elseif ($period === 'year') {
                $from = substr($anchor, 0, 4) . '-01-01';
                $to   = $anchor;
            } else {
                throw new ApiException('الفترة لازم تكون today أو month أو year');
            }
        }
        $shiftId = $request->query('shift') !== null ? (int) $request->query('shift') : 0;

        /* وصف اللي بيتعرض — بيرجع في الرد عشان الشاشة تكتب للطيار
           «انت شايف إيه» بدل ما يفتكر الرقم إجمالي عمره كله. */
        $range = ['mode' => 'shift', 'from' => null, 'to' => null];

        if ($shiftId > 0) {
            // وردية معيّنة — لازم تكون بتاعته هو
            $own = DB::select('SELECT id FROM shifts WHERE id = ? AND pilot_id = ?', [$shiftId, $pilotId]);
            if (! $own) {
                throw ApiException::forbidden('الوردية مش بتاعتك');
            }
            $where[] = 'o.shift_id = ?';
            $args[]  = $shiftId;
        } elseif ($day !== '' || $from !== '' || $to !== '') {
            /* `day=` القديم = مدى من يوم واحد. الاتنين بيمشوا على نفس
               الكود عشان مايفرقوش في السلوك مع الوقت. */
            $a = $day !== '' ? $day : ($from !== '' ? $from : $to);
            $b = $day !== '' ? $day : ($to !== '' ? $to : $from);
            if (! self::validDate($a) || ! self::validDate($b)) {
                throw new ApiException('التاريخ بصيغة YYYY-MM-DD');
            }
            /* المقلوب بيتظبط بدل ما يترمي: الطيار اللي اختار «من 30 إلى 1»
               قصده واضح، والخطأ هنا بيوقّف شاشة مش بيحمي حاجة. */
            if ($a > $b) {
                [$a, $b] = [$b, $a];
            }
            /* حد أقصى سنتين: من غيره `from=1900-01-01` بيمسح كل الجدول
               في استعلام واحد على مسار بيتنده من كل طيار. */
            if ((strtotime($b) - strtotime($a)) > 366 * 2 * 86400) {
                throw new ApiException('المدى أكبر من سنتين — قلّل الفترة');
            }
            [$fromUtc, $toUtc] = self::cairoWindow($a, $b);
            $where[] = self::DAY_ANCHOR . ' >= ? AND ' . self::DAY_ANCHOR . ' < ?';
            array_push($args, $fromUtc, $toUtc);
            $range = [
                'mode' => $period !== '' ? $period : ($day !== '' ? 'day' : 'range'),
                'from' => $a,
                'to'   => $b,
            ];
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
                /* 🔴 مفيش وردية مفتوحة — بنرجع ليوم **الوردية الأخيرة**
                   مش ليوم النهارده من نص الليل.

                   السيناريو اللي كان بيكسر: الطيار شغّال من 9 ص، الساعة
                   2 الفجر الوردية اتقفلت لأي سبب. يفتح الشاشة يلاقي
                   يوم شغل كامل اتمسح — لأن «النهارده» ابتدت من نص الليل
                   قبلها بساعتين. */
                $anchorDay = $this->anchorDay($pilotId);
                [$fromUtc, $toUtc] = self::cairoWindow($anchorDay, $anchorDay);
                $where[] = self::DAY_ANCHOR . ' >= ? AND ' . self::DAY_ANCHOR . ' < ?';
                array_push($args, $fromUtc, $toUtc);
                $range = ['mode' => 'day', 'from' => $anchorDay, 'to' => $anchorDay];
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
        /* 🔴 رقمين مختلفين عن قصد — كانوا رقم واحد وده كان غلط:
             • `$collectedTotal` = اللي الطيار **حصّله كاش** فعلًا. لو
               العميل دفع جزء من محفظته، الجزء ده مش في إيد الطيار،
               فعرضه على إنه «إجمالي التحصيل» بيخلّيه يفتكر إن عليه فلوس
               للفرع مش معاه.
             • `$feeTotal` = سعر التوصيل **الخام**، وهو أساس العمولة:
               المحل مدين للشركة بالأجرة كاملة مهما كانت طريقة دفع
               العميل، فعمولة الطيار مالهاش دعوة بالمحفظة. */
        /* 🔴 الإحصائيات بتتحسب على **كل** الصفوف اللي في المدى، مش على
           الـ200 اللي رجعوا للعرض.

           اللسعة اللي اتشالت هنا: الحلقة كانت بتلف على `$rows` — وهي
           `LIMIT 200`. طول ما الشاشة كانت بتعرض وردية واحدة الرقم كان
           بيطلع صح بالصدفة. أول ما بقى فيه فلتر شهر أو سنة، «إجمالي
           التحصيل» كان هيحسب أول 200 أوردر بس **ويعرضه على إنه
           الإجمالي** — رقم فلوس ناقص من غير أي علامة إنه ناقص.

           الاستعلام ده بيجيب تلات أعمدة أرقام بس، فحتى سنة كاملة رخيصة.
           والحساب لسه بيعدّي على `Money::netCollect` — تعريف واحد للفلوس
           بدل نسخة تانية مكتوبة SQL تفرق عنها مع الوقت. */
        $allRows = DB::select(
            'SELECT o.status, o.total_delivery_price, o.wallet_used FROM orders o WHERE '
            . implode(' AND ', $where),
            $args
        );

        $deliveredCount = 0;
        $collectedTotal = 0.0;
        $feeTotal       = 0.0;
        foreach ($allRows as $r) {
            if ($r->status === 'delivered') {
                $deliveredCount++;
                $collectedTotal += Money::netCollect((array) $r);
                $feeTotal       += (float) $r->total_delivery_price;
            }
        }
        $matchedTotal = count($allRows);
        $commissionType  = $pilot['commission_type'] ?: 'percent';
        $commissionValue = (float) $pilot['commission_value'];
        $commission = $commissionType === 'fixed'
            ? $commissionValue * $deliveredCount
            : $feeTotal * $commissionValue / 100.0;

        // غلاف مكتوب بالإيد: فيه `stats` بعد `items` — مش شكل PollableList
        return ApiResponse::out([
            'ok'        => true,
            'serverNow' => PollableList::serverNowMs(),
            'changed'   => true,
            'items'     => self::netCollectView(OrderWire::batch($rows)),
            'range'     => $range,
            'stats'     => [
                'totalOrders'      => $matchedTotal,
                'deliveredCount'   => $deliveredCount,
                'undeliveredCount' => $matchedTotal - $deliveredCount,
                /* الشاشة محتاجة تفرّق بين «دول كل الأوردرات» و«دي أول 200».
                   من غير الحقل ده الطيار بيعدّ الكروت ويقارنها بالإجمالي
                   ويفتكر إن فيه أوردرات ضاعت. */
                'itemsShown'       => count($rows),
                'itemsTruncated'   => $matchedTotal > count($rows),
                'totalCollected'   => round($collectedTotal, 2),
                'commissionType'   => $commissionType,
                'commissionValue'  => $commissionValue,
                'commission'       => round($commission, 2),
            ],
        ]);
    }

    /**
     * 💰 كل أوردر خارج للطيار: `totalDeliveryPrice` = اللي هيحصّله فعلًا.
     *
     * 🔴 المسارات دي هي **الشاشة الرئيسية** لتطبيق الطيار (كارت الأوردر
     * «💵 قيمة التحصيل» وإجمالي الوردية). الإصلاح الأول اتحط في
     * `OrdersController` بس — وهو اللي التطبيق بينده عليه لشاشة الحساب
     * فقط. يعني الشاشة الأهم فضلت بتعرض السعر الخام.
     *
     * العميل ممكن يكون دفع جزء من محفظته والرصيد اتخصم وقت الطلب، فالخام
     * معناه إن الطيار بيطلب من العميل فلوس اتدفعت خلاص. الشرح الكامل في
     * `Money::netCollect`.
     *
     * الدالة **مش** مشروطة بالدور: المسارات دي كلها تحت مجموعة
     * `role:pilot` أصلًا (routes/api.php)، فمفيش دور تاني بيوصلها.
     *
     * @param  array<int, array<string, mixed>>  $orders
     * @return array<int, array<string, mixed>>
     */
    private static function netCollectView(array $orders): array
    {
        foreach ($orders as $i => $o) {
            if (! is_array($o) || ((float) ($o['walletUsed'] ?? 0)) <= 0) {
                continue;   // الحالة الغالبة — مفيش خصم، مفيش فرق
            }
            $orders[$i]['totalDeliveryPrice'] = Money::netCollectWire($o);
        }

        return $orders;
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
    /**
     * GET /api/pilot/finance?month=YYYY-MM — صفحة «المالية» في التطبيق (طلب صاحب النظام 2026-09-13):
     * «كل شيء متعلق بالمالية الخاصة به: السلف والخصومات والمكافآت وعدد الأوردرات في الشهر
     * والعمولات وعدد ساعات كل يوم اشتغله».
     *
     * الأرقام من **نفس** تقفيلة الطيارين (PilotAccountingController::month بعين الإدارة
     * ومقصورة على الطيار ده) — مش معادلة تانية تختلف عن اللي المحاسب شايفه.
     */
    public function finance(Request $request): JsonResponse
    {
        $pilot = $this->pilotCtx($request);
        $pid   = (int) $pilot['id'];
        $ym    = (string) $request->query('month', '');
        if (! preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $ym = substr(BizDay::key(), 0, 7);
        }
        /** @var \App\Http\Controllers\Api\PilotAccountingController $acct */
        $acct = app(PilotAccountingController::class);
        $data = $acct->monthDataFor($ym, $pid);
        $row  = null;
        foreach ($data['pilots'] ?? [] as $r) {
            if ((int) ($r['pilotId'] ?? 0) === $pid) {
                $row = $r;
                break;
            }
        }
        $totals = $row['totals'] ?? [];
        $days   = [];
        foreach ($row['days'] ?? [] as $d) {
            $days[] = [
                'day'    => (int) ($d['day'] ?? 0),
                'in'     => $d['in'] ?? null,
                'out'    => $d['out'] ?? null,
                'hours'  => round((float) ($d['hours'] ?? 0), 2),
                'orders' => (int) ($d['orders'] ?? 0),
                'commission' => round((float) ($d['psvc'] ?? 0), 2),
                'advance'    => round((float) ($d['adv'] ?? 0), 2),
                'deduction'  => round((float) ($d['ded'] ?? 0), 2),
                'bonus'      => round((float) ($d['bonus'] ?? 0), 2),
                'perms'      => array_values(array_map(fn ($p) => is_array($p) ? [
                    'from' => $p['from'] ?? null, 'to' => $p['to'] ?? null, 'type' => $p['type'] ?? null,
                ] : $p, $d['perms'] ?? [])),
                'note'   => (string) ($d['note'] ?? ''),
                'open'   => (bool) ($d['openShift'] ?? false),
            ];
        }
        $payouts = array_values(array_filter($data['payouts'] ?? [], fn ($p) => (int) ($p['refId'] ?? $p['ref_id'] ?? 0) === $pid));
        $paid    = round(array_sum(array_map(fn ($p) => (float) ($p['amount'] ?? 0), $payouts)), 2);
        $netDue  = round((float) ($totals['netDue'] ?? 0), 2);
        $advances = array_values(array_filter($data['deferred'] ?? [], fn ($a) => (int) ($a['pilotId'] ?? 0) === $pid));

        // ── البنود بأسبابها في نافذة الشهر التجاري ──
        $ds = (int) ($data['settings']['dayStartHour'] ?? W::DEFAULT_DAY_START);
        $nd = (int) ($data['daysInMonth'] ?? W::daysInMonth($ym));
        [$winFrom]  = W::bizWindowUtc($ym . '-01', $ds);
        [, $winTo]  = W::bizWindowUtc($ym . '-' . sprintf('%02d', $nd), $ds);
        $ops = [];
        foreach (DB::select(
            "SELECT id, started_at, ended_at, bonus_amount, bonus_reason, deduction_amount, deduction_reason,
                    advance_amount, advance_reason, bonus_settle, deduction_settle, advance_settle
               FROM shifts WHERE pilot_id = ? AND started_at >= ? AND started_at < ?
                AND (bonus_amount > 0 OR deduction_amount > 0 OR advance_amount > 0)
              ORDER BY started_at",
            [$pid, $winFrom, $winTo]
        ) as $s) {
            $at = W::bizMoment((string) ($s->ended_at ?? $s->started_at), $ds);
            foreach ([['bonus', 'bonus_amount', 'bonus_reason', 'bonus_settle'],
                      ['deduction', 'deduction_amount', 'deduction_reason', 'deduction_settle'],
                      ['advance', 'advance_amount', 'advance_reason', 'advance_settle']] as [$t, $ac, $rc, $sc]) {
                if ((float) $s->{$ac} > 0) {
                    $ops[] = ['type' => $t, 'source' => 'shift', 'date' => $at['date'] ?? null,
                              'amount' => round((float) $s->{$ac}, 2), 'reason' => (string) ($s->{$rc} ?? ''),
                              'settle' => $s->{$sc} ?: 'monthly'];
                }
            }
        }
        foreach (DB::select(
            'SELECT day, advance_extra, deduction_extra, bonus_extra, note FROM pilot_day_entries
              WHERE month = ? AND pilot_id = ? AND (advance_extra <> 0 OR deduction_extra <> 0 OR bonus_extra <> 0) ORDER BY day',
            [$ym, $pid]
        ) as $e) {
            $date = $ym . '-' . sprintf('%02d', (int) $e->day);
            foreach ([['advance', 'advance_extra'], ['deduction', 'deduction_extra'], ['bonus', 'bonus_extra']] as [$t, $c]) {
                if ((float) $e->{$c} != 0.0) {
                    $ops[] = ['type' => $t, 'source' => 'sheet', 'date' => $date,
                              'amount' => round((float) $e->{$c}, 2), 'reason' => (string) ($e->note ?? ''), 'settle' => 'monthly'];
                }
            }
        }
        foreach (DB::select(
            "SELECT amount, reason, effective_date FROM pilot_commission_adjustments
              WHERE pilot_id = ? AND kind = 'extra' AND effective_date >= ? AND effective_date <= ? ORDER BY effective_date",
            [$pid, substr($winFrom, 0, 10), substr($winTo, 0, 10)]
        ) as $x) {
            $ops[] = ['type' => 'extra', 'source' => 'commission', 'date' => $x->effective_date,
                      'amount' => round((float) $x->amount, 2), 'reason' => (string) ($x->reason ?? ''), 'settle' => 'monthly'];
        }
        foreach ($advances as $a) {
            $ops[] = ['type' => 'advance', 'source' => 'store', 'date' => $a['advanceDate'] ?? null,
                      'amount' => round((float) ($a['amount'] ?? 0), 2),
                      'reason' => trim(($a['storeName'] ? 'من خزنة ' . $a['storeName'] : '') . ' ' . ($a['note'] ?? '')),
                      'settle' => 'monthly', 'monthDue' => $a['monthDue'] ?? null, 'remaining' => $a['remaining'] ?? null];
        }
        usort($ops, fn ($x, $y) => strcmp((string) ($y['date'] ?? ''), (string) ($x['date'] ?? '')));

        // ── الشهور المتاحة: من أول وردية للطيار لحد الشهر الحالي ──
        $first = DB::selectOne('SELECT MIN(started_at) m FROM shifts WHERE pilot_id = ?', [$pid]);
        $months = [];
        $cur = substr(BizDay::key(), 0, 7);
        $m = $first && $first->m ? substr((string) (W::bizMoment((string) $first->m, $ds)['date'] ?? $first->m), 0, 7) : $cur;
        $guard = 0;
        while ($m <= $cur && $guard++ < 36) {
            $months[] = $m;
            $m = W::nextMonth($m);
        }
        if (! in_array($ym, $months, true)) {
            $months[] = $ym;
        }

        return ApiResponse::out([
            'ok'        => true,
            'serverNow' => WireTime::toWire(WireTime::nowDb()),
            'month'     => $ym,
            'months'    => array_values(array_unique($months)),
            'locked'    => (bool) ($data['locked'] ?? false),
            'pilot'     => ['id' => $pid, 'name' => $pilot['name'],
                            'commissionType' => $pilot['commission_type'] ?: 'percent',
                            'commissionValue' => round((float) $pilot['commission_value'], 2),
                            'hourRate' => round((float) ($row['hourRate'] ?? 0), 2),
                            'monthlySalary' => round((float) ($pilot['monthly_salary'] ?? 0), 2)],
            'days'      => $days,
            'totals'    => $totals,
            'paid'      => $paid,
            'remaining' => round($netDue - $paid, 2),
            'payouts'   => array_map(fn ($p) => [
                'id' => (int) ($p['id'] ?? 0), 'amount' => round((float) ($p['amount'] ?? 0), 2),
                'paidAt' => $p['paidAt'] ?? null, 'storeName' => $p['storeName'] ?? null, 'note' => (string) ($p['note'] ?? ''),
            ], $payouts),
            'advances'  => array_map(fn ($a) => [
                'id' => (int) ($a['id'] ?? 0), 'date' => $a['advanceDate'] ?? null, 'amount' => round((float) ($a['amount'] ?? 0), 2),
                'monthly' => round((float) ($a['monthly'] ?? 0), 2), 'startMonth' => $a['startMonth'] ?? null,
                'monthDue' => round((float) ($a['monthDue'] ?? 0), 2), 'remaining' => round((float) ($a['remaining'] ?? 0), 2),
                'storeName' => $a['storeName'] ?? null, 'note' => (string) ($a['note'] ?? ''), 'done' => (bool) ($a['done'] ?? false),
            ], $advances),
            'ops'       => $ops,
        ]);
    }

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
        $pilotId = (int) $pilot['id'];

        /* ═══ دفعة نقاط (2.5.5+، 2026-09-07) ═══
           التطبيق وهو شايل أوردر بيجمّع نقطة كل ~٥ث من تيار الـGPS
           وبيبعتها دفعة كل ١٥ث: `points: [{lat,lng,at,heading,speed,acc}]`
           — طلب واحد بدل تلاتة، والخريطة بتاخد الأثر كامل. الشكل القديم
           `{lat,lng}` لسه شغّال زي ما هو للنسخ الأقدم. */
        $pointsIn = $request->input('points');
        if (is_array($pointsIn) && count($pointsIn)) {
            $this->storeTrackBatch($pilotId, $pointsIn);

            return ApiResponse::ok();
        }

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
            [$lat, $lng, WireTime::nowDb(), $pilotId]
        );

        return ApiResponse::ok();
    }

    /**
     * تخزين دفعة نقاط أثر — الصالح منها بس، وآخرها زمنيًا بيبقى موقع
     * الطيار الحالي (lat/lng/heading/speed على صف الطيار).
     *
     * ⚠️ `at` وقت **الجهاز** — ممكن يكون ساعته مضبوطة غلط. لو النقطة
     * في المستقبل بأكتر من دقيقة أو أقدم من ساعة بنستبدلها بوقت السيرفر
     * عشان الأثر مايطلعش ملخبط على الخريطة. سقف ٦٠ نقطة في الدفعة —
     * التطبيق بيبعت ٣، والسقف حماية من جسم مفبرك.
     */
    private function storeTrackBatch(int $pilotId, array $pointsIn): void
    {
        $nowMs  = (int) round(microtime(true) * 1000);
        $rows   = [];
        foreach (array_slice($pointsIn, 0, 60) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $lat = $p['lat'] ?? null;
            $lng = $p['lng'] ?? null;
            if (! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }
            $lat = (float) $lat;
            $lng = (float) $lng;
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
                continue;
            }
            $atMs = is_numeric($p['at'] ?? null) ? (int) $p['at'] : $nowMs;
            if ($atMs > $nowMs + 60_000 || $atMs < $nowMs - 3_600_000) {
                $atMs = $nowMs;
            }
            $heading = is_numeric($p['heading'] ?? null) ? fmod((float) $p['heading'] + 360.0, 360.0) : null;
            $speed   = is_numeric($p['speed'] ?? null) ? max(0.0, (float) $p['speed']) : null;
            $acc     = is_numeric($p['acc'] ?? null) ? max(0.0, (float) $p['acc']) : null;
            $rows[] = [
                'pilot_id' => $pilotId,
                'lat'      => $lat,
                'lng'      => $lng,
                'heading'  => $heading,
                'speed'    => $speed,
                'accuracy' => $acc,
                'at'       => gmdate('Y-m-d H:i:s', intdiv($atMs, 1000)) . '.' . sprintf('%03d', $atMs % 1000),
                '_ms'      => $atMs,
            ];
        }
        if (! $rows) {
            throw new ApiException('مافيش نقاط صالحة في الدفعة');
        }
        usort($rows, fn ($a, $b) => $a['_ms'] <=> $b['_ms']);
        $last = $rows[count($rows) - 1];

        /* 3 محاولات (2026-09-09): دفعتين من نفس الطيار (الخدمة والواجهة) بيتقابلوا على صف pilots
           → deadlock 1213 كان بيرمي 500 والدفعة كلها بتضيع (اتلقى في laravel.log 2026-09-08). */
        DB::transaction(function () use ($pilotId, $rows, $last): void {
            foreach ($rows as $r) {
                DB::insert(
                    'INSERT INTO pilot_track_points (pilot_id, lat, lng, heading, speed, accuracy, at) VALUES (?,?,?,?,?,?,?)',
                    [$r['pilot_id'], $r['lat'], $r['lng'], $r['heading'], $r['speed'], $r['accuracy'], $r['at']]
                );
            }
            /* الموقع الحالي = آخر نقطة زمنيًا في الدفعة. `location_updated_at`
               بوقت السيرفر (زي المسار القديم) — ده اللي LocFresh بيقيس
               عليه «قديم/بايت»، ولازم يفضل بساعة السيرفر مش الجهاز. */
            DB::update(
                'UPDATE pilots SET lat = ?, lng = ?, heading = ?, speed = ?, location_updated_at = ? WHERE id = ?',
                [$last['lat'], $last['lng'], $last['heading'], $last['speed'], WireTime::nowDb(), $pilotId]
            );
            /* تنضيف: الأثر مالوش لازمة بعد ٢٤ ساعة — DELETE بالفهرس
               (pilot_id, at) رخيص، وبيتعمل مع كل دفعة فالجدول عمره ما
               يكبر. */
            DB::delete(
                'DELETE FROM pilot_track_points WHERE pilot_id = ? AND at < (UTC_TIMESTAMP(3) - INTERVAL 24 HOUR)',
                [$pilotId]
            );
        }, 3);
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
     * POST /api/pilot/shift/end — **مقفول**.
     *
     * ═══ القرار (صاحب النظام 2026-08-30) ═══
     * «امنع الطيار إنه يخرج من الوردية — اللي يخرجه هو الفرع أو الإدارة».
     *
     * ═══ ليه ده أصح ماليًا ═══
     * الدالة دي كانت بتقفل الوردية **من غير أي تسوية فلوس** — لا عهدة ولا
     * خزنة ولا `money_settled`. يعني الطيار يقفل ورديته وفلوسه معلّقة،
     * والفرع يكتشفها في التقفيلة. البديل (`POST /api/shifts/{id}/end` →
     * `BoardController::shiftEnd`) بينده `settlePilotMoney` وبيفرض تسوية
     * الأوردرات المسلّمة الأول. فكل قفل دلوقتي بيعدّي على التسوية.
     *
     * ═══ ليه المسار فاضل موجود بدل ما يتشال ═══
     * ① جزء من **العقد الأصلي** — شيله بيخلّي `route:coverage` يقول «ناقص ١».
     * ② مافيش فرض تحديث على الإنتاج (`site_settings.pilotApp` مش موجود)،
     *    فالتطبيقات القديمة على تليفونات الطيارين لسه بتنده عليه. رسالة
     *    واضحة أحسن من 404 غامض.
     *
     * التطبيق الجديد شال زر «إنهاء الوردية» وشال النداء من تسجيل الخروج،
     * فالمسار ده مابيتندهش منه أصلًا — هو بس شبكة أمان للنسخ القديمة.
     *
     * 🔒 الحارس: ops/test_shift_end_lock.php
     */
    public function shiftEnd(Request $request): JsonResponse
    {
        /* 🔒 مقفول. مابنندهش `pilotCtx` هنا: الرفض واحد لأي طيار، وهي
           كانت هترمي «الطيار غير موجود» (404) للحساب المش مربوط بصف
           طيار — رسالة بتخفي السبب الحقيقي. الوسيطة `role:pilot` على
           المسار كافية للتحقق من الدور. */
        throw ApiException::forbidden('إنهاء الوردية بقى من الفرع أو الإدارة — كلّم المشرف بتاعك');
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

        // سماحية ثانية (2026-09-08): أعمدة بدقة ثانية ضد طابع بالميلي — رد في نفس الثانية كان بيضيع
        return $raw === null ? 0 : max(0, (int) $raw - 1000);
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
}
