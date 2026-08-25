<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\BroadcastsOrders;
use App\Http\Controllers\Concerns\NotifiesOrderReceivers;
use App\Support\Actor;
use App\Support\ApiResponse;
use App\Support\OrderNumber;
use App\Support\PollableList;
use App\Support\Vocab;
use App\Support\WireTime;
use App\Wire\OrderWire;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * الأوردرات — نقل حرفي لمسارات القراءة والكتابة من api/routes/orders.php.
 */
class OrdersController
{
    use BroadcastsOrders;
    use NotifiesOrderReceivers;

    /**
     * GET /api/orders
     *
     * 🔒 نطاق الأدوار على مستوى الـAPI — مش على مستوى الواجهة. كل دور مقفول
     * على بياناته: الفرع على فرعه، المحل على `added_by` بتاعه، الطيار على
     * أوردراته، عميل التطبيق على `customer_id` بتاعه.
     *
     * ⚡ `$selective` مش تفصيلة أداء بسيطة: لو مفيش فلتر مساواة انتقائي ولا
     * `?since`، المخطّط بيفضّل **مسح كامل للجدول + فرز** بدل ما يقرا آخر N
     * من `idx_orders_created`. اتقاس على 64 ألف صف تحت ضغط الكتابة — الكول
     * سنتر نزل من 10.4 لـ4.3 ثانية p99 بعد `FORCE INDEX`. مع فلتر انتقائي
     * أو `since` المخطّط بيختار صح لوحده فمابنلمسوش.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $q = $request->query();

        $where = [];
        $params = [];
        $selective = false;

        // ── نطاق الأدوار ──
        if ($actor->role === 'branch') {
            $selective = true;
            $where[] = 'o.branch_id = ?';
            $params[] = (int) ($actor->branchId ?? 0);
        } elseif ($actor->role === 'store') {
            $selective = true;
            $where[] = 'o.added_by = ?';
            $params[] = $actor->username;
        } elseif ($actor->role === 'pilot') {
            $selective = true;
            $where[] = 'o.pilot_id = ?';
            $params[] = $this->sessionPilotId($actor) ?? -1;
        } elseif ($actor->role === 'customer') {
            $selective = true;
            // جلسة عميل التطبيق — أوردراته هو بس
            $where[] = 'o.customer_id = ?';
            $params[] = (int) ($actor->customerId ?? -1);
        } elseif (! empty($q['branchId'])) {
            $selective = true;
            $where[] = 'o.branch_id = ?';
            $params[] = (int) $q['branchId'];
        }

        /* فلترة بمالك الشحنة — للأدمن والكول سنتر بس. لوحة المحلات ولوحة
           العملاء كانت بتحسب أرقامها من نافذة «آخر N أوردر في الشركة». */
        $isBackOffice = $actor->hasRole('admin', 'callcenter');
        if ($isBackOffice && ! empty($q['addedBy'])) {
            $selective = true;
            $where[] = 'o.added_by = ?';
            $params[] = (string) $q['addedBy'];
        }
        if ($isBackOffice && ! empty($q['customerId'])) {
            $selective = true;
            $where[] = 'o.customer_id = ?';
            $params[] = (int) $q['customerId'];
        }

        if (! empty($q['status'])) {
            $where[] = 'o.status = ?';
            $params[] = Vocab::statusToCode((string) $q['status']);   // بيقبل عربي أو كود
        }
        if (! empty($q['pilotId']) && $actor->role !== 'pilot') {
            $selective = true;
            $where[] = 'o.pilot_id = ?';
            $params[] = (int) $q['pilotId'];
        }
        if (! empty($q['shiftId'])) {
            $selective = true;
            $where[] = 'o.shift_id = ?';
            $params[] = (int) $q['shiftId'];
        }
        if (isset($q['moneySettled']) && $q['moneySettled'] !== '') {
            $where[] = 'o.money_settled = ?';
            $params[] = (int) (bool) $q['moneySettled'];
        }

        // فترة على createdAt — بيقبل ISO كامل أو "YYYY-MM-DD" (يوم قاهرة)
        if (! empty($q['from'])) {
            $from = strlen((string) $q['from']) === 10
                ? WireTime::toDb($q['from'] . 'T00:00:00+02:00')
                : WireTime::toDb((string) $q['from']);
            if ($from) {
                $where[] = 'o.created_at >= ?';
                $params[] = $from;
            }
        }
        if (! empty($q['to'])) {
            $to = strlen((string) $q['to']) === 10
                ? WireTime::toDb($q['to'] . 'T23:59:59.999+02:00')
                : WireTime::toDb((string) $q['to']);
            if ($to) {
                $where[] = 'o.created_at <= ?';
                $params[] = $to;
            }
        }

        // بحث برقم الأوردر أو تليفون (مُرسِل/عميل/مستلم)
        if (! empty($q['q'])) {
            $like = '%' . trim((string) $q['q']) . '%';
            $where[] = '(o.order_num LIKE ? OR o.sender_phone LIKE ? OR o.customer_phone LIKE ?
                         OR EXISTS (SELECT 1 FROM order_deliveries dq
                                    WHERE dq.order_id = o.id AND dq.receiver_phone LIKE ?))';
            array_push($params, $like, $like, $like, $like);
        }

        // ?since=<server_ms> — delta حسب الدستور بند 5
        $since = isset($q['since']) && $q['since'] !== '' ? (int) $q['since'] : null;
        if ($since !== null) {
            $where[] = 'o.updated_at > FROM_UNIXTIME(? / 1000)';
            $params[] = $since;
        }

        $limit = isset($q['limit']) ? max(1, min(1000, (int) $q['limit'])) : 300;

        /* ?offset — تصفّح الأقدم على دفعات. مع ?since مفيش معنى للتصفّح
           لأن الرد أصلًا هو المتغيّر فقط. */
        $offset = isset($q['offset']) ? max(0, min(100000, (int) $q['offset'])) : 0;
        if ($since !== null) {
            $offset = 0;
        }

        $base = OrderWire::baseSql();
        if (! $selective && $since === null) {
            $base = preg_replace('/\bFROM orders o\b/', 'FROM orders o FORCE INDEX (idx_orders_created)', $base, 1);
        }

        $sql = $base
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY o.created_at DESC LIMIT ' . $limit
            . ($offset > 0 ? ' OFFSET ' . $offset : '');

        $rows = DB::select($sql, $params);
        $serverNow = PollableList::serverNowMs();

        if ($since !== null && ! $rows) {
            return PollableList::unchanged($serverNow);
        }

        return PollableList::items(OrderWire::batch($rows), $serverNow);
    }

    /**
     * GET /api/orders/stats?groupBy=addedBy|customerId
     *
     * تجميع على السيرفر بدل ما الواجهة تحسب من نافذة الأوردرات. `byStatus`
     * بمفاتيح **عربية** زي حقل status في كائن الأوردر، عشان الواجهة تفضل
     * تحسب بنفس منطقها بالظبط.
     */
    public function stats(Request $request): JsonResponse
    {
        $groupBy = (string) $request->query('groupBy', '');

        if ($groupBy === 'addedBy') {
            $col = 'o.added_by';
            $scope = "o.source = 'store' AND o.added_by IS NOT NULL AND o.added_by <> ''";
        } elseif ($groupBy === 'customerId') {
            $col = 'o.customer_id';
            $scope = 'o.customer_id IS NOT NULL';
        } else {
            throw new ApiException('groupBy لازم يكون addedBy أو customerId');
        }

        $rows = DB::select(
            "SELECT {$col} AS gk, o.status AS st, COUNT(*) AS n,
                    SUM(o.total_delivery_price)      AS deliv,
                    SUM(COALESCE(o.store_prepaid,0)) AS prepaid
               FROM orders o
              WHERE {$scope}
              GROUP BY gk, st"
        );

        $stats = [];
        foreach ($rows as $r) {
            $k = (string) $r->gk;
            if (! isset($stats[$k])) {
                $stats[$k] = ['orders' => 0, 'delivery' => 0.0, 'prepaid' => 0.0, 'byStatus' => []];
            }
            $stats[$k]['orders'] += (int) $r->n;
            $stats[$k]['delivery'] += (float) $r->deliv;
            $stats[$k]['prepaid'] += (float) $r->prepaid;
            $ar = Vocab::statusToAr((string) $r->st) ?? (string) $r->st;
            $stats[$k]['byStatus'][$ar] = ($stats[$k]['byStatus'][$ar] ?? 0) + (int) $r->n;
        }
        foreach ($stats as &$s) {
            $s['delivery'] = round($s['delivery'], 2);
            $s['prepaid'] = round($s['prepaid'], 2);
            if (! $s['byStatus']) {
                $s['byStatus'] = new stdClass();
            }
        }
        unset($s);

        return ApiResponse::out([
            'ok'      => true,
            'groupBy' => $groupBy,
            'stats'   => $stats ?: new stdClass(),
        ]);
    }

    /**
     * GET /api/orders/{id}
     *
     * 🔒 النطاق هنا **نفس نطاق index بالظبط**. قبل كده كان الفرع بس هو
     * المتحقَّق منه، فأي محل/طيار/عميل كان يقدر يعدّ الـids (1,2,3…) ويسحب
     * كل أوردر في النظام باسم المستلم وتليفونه وعنوانه — أخطر التفاف على
     * قاعدة الخصوصية لأنه بيدّي القاعدة كلها **مرتّبة**.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();

        $order = OrderWire::full(ctype_digit($id) ? (int) $id : $id);
        if (! $order) {
            throw ApiException::notFound('الأوردر ده مابقاش موجود — اعمل تحديث للصفحة');
        }

        $role = $actor->role;
        if ($role === 'branch') {
            if ((int) $order['branchId'] !== (int) ($actor->branchId ?? 0)) {
                throw ApiException::forbidden('الأوردر ده تابع لفرع تاني');
            }
        } elseif ($role === 'store') {
            if ((string) ($order['addedBy'] ?? '') !== $actor->username) {
                throw ApiException::forbidden('الأوردر ده مش بتاعك');
            }
        } elseif ($role === 'pilot') {
            $pid = $this->sessionPilotId($actor);
            if ($pid === null || (int) ($order['pilotId'] ?? 0) !== $pid) {
                throw ApiException::forbidden('الأوردر ده مش متحمّل عليك');
            }
        } elseif ($role === 'customer') {
            if ((int) ($order['customerId'] ?? 0) !== (int) ($actor->customerId ?? 0)) {
                throw ApiException::forbidden('الأوردر ده مش بتاعك');
            }
        }

        return ApiResponse::ok(['order' => $order]);
    }

    /* ═══════════════════════════════════════════════════════════════
       إنشاء أوردر — ترقيم ذري + snapshot الزون + الطرود دفعة واحدة
    ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /api/orders — أخطر مسار كتابة في النظام (orders_create).
     *
     * الترتيب هنا **مقصود**: كل التحقق وبحث المناطق بيخلص الأول، وبعده بس
     * بيتخصّص رقم الأوردر. كده القفل على صف العدّاد بيتمسك لأقل وقت ممكن،
     * ولو التحقق فشل مكانش اتخصّص رقم أصلًا فمفيش فجوة في الترقيم.
     *
     * 🔴 الفلوس: `totalDeliveryPrice` = مجموع أسعار الزونات،
     * و`storePrepaid` = مجموع عهدة الطرود. الاتنين **من غير round()** —
     * زي الأصل بالحرف (شوف notes).
     */
    /** أقل عدد أوردرات **متسلّمة** لجهة الاستلام قبل ما الكول سنتر يحط عهدة. */
    public const CC_CUSTODY_MIN_DELIVERED = 10;

    /** أقصى عهدة للأوردر الواحد من الكول سنتر (ج.م). */
    public const CC_CUSTODY_MAX = 3000.0;

    /**
     * عدد أوردرات جهة الاستلام دي اللي اتسلّمت فعلًا.
     *
     * «اتسلّمت» مش «اتعملت» عن قصد — الأوردر الملغي أو الوهمي مايثبتش
     * تعامل، ومن غير الشرط ده البوابة بتتفتح بعشر إلغاءات.
     */
    public static function senderDeliveredCount(?int $senderId): int
    {
        if (! $senderId) {
            return 0;
        }

        return (int) (DB::selectOne(
            "SELECT COUNT(*) AS c FROM orders WHERE sender_id = ? AND status = 'delivered'",
            [$senderId]
        )->c ?? 0);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        // الفرع: مشرف الفرع مقفول على فرعه — الباقي من الـ body
        $branchId = $actor->role === 'branch'
            ? (int) ($actor->branchId ?? 0)
            : (int) ($request->input('branchId') ?? ($actor->branchId ?? 0));
        if (! $branchId) {
            throw new ApiException('الفرع مطلوب لإنشاء الأوردر');
        }

        $deliveriesIn = $request->input('deliveries') ?? [];
        if (! is_array($deliveriesIn) || ! count($deliveriesIn)) {
            throw new ApiException('أضف وجهة توصيل واحدة على الأقل');
        }

        $senderId   = self::intOrNull($request->input('senderId'));
        $senderName = trim((string) ($request->input('senderName') ?? ''));
        if (! $senderId && $senderName === '') {
            throw new ApiException('يرجى اختيار أو إدخال العميل استلام');
        }

        $now = WireTime::nowDb();

        // أدوار الإضافة والمصدر باللسان القديم (VOCAB بند 1.7 و 1.8)
        $roleCode = $actor->role;
        $source   = (string) ($request->input('source') ?? $roleCode);
        if (! isset(Vocab::ORDER_SOURCE_WIRE[$source]) && $source !== 'callcenter') {
            $source = 'branch';
        }

        try {
            $orderId = DB::transaction(function () use (
                $request, $actor, $branchId, $deliveriesIn, $senderId, $senderName, $now, $roleCode, $source
            ): int {
                // كود الفرع للترقيم (fallback "ORD" حسب VOCAB بند 3).
                // بنستعمل DB::select مش value() عشان نفرّق بين «مفيش صف»
                // (= fetchColumn() === false) و«العمود نفسه NULL».
                $branchRow = DB::select('SELECT code FROM branches WHERE id = ? LIMIT 1', [$branchId]);
                if (! $branchRow) {
                    throw new ApiException('الفرع غير موجود');
                }
                $branchCode = (string) $branchRow[0]->code ?: 'ORD';

                // إنشاء مُرسِل جديد لو مش محفوظ (زي saveBranchOrder القديمة)
                if (! $senderId && $senderName !== '') {
                    $sPhone = trim((string) ($request->input('senderPhone') ?? ''));
                    $sAddr  = trim((string) ($request->input('senderAddress') ?? ''));
                    if ($sPhone !== '' && $sAddr !== '') {
                        DB::insert(
                            'INSERT INTO senders (name, phone1, phone2, address, created_by, source, created_at)
                             VALUES (?,?,?,?,?,?,?)',
                            [
                                $senderName, $sPhone,
                                self::trimOrNull($request->input('senderPhone2')),
                                $sAddr, $actor->username, $source, $now,
                            ]
                        );
                        $senderId = (int) DB::getPdo()->lastInsertId();
                    }
                }

                // ═══ الترقيم الذري: عداد يومي لكل فرع بمفتاح يوم القاهرة ═══
                // نمط الـupsert: الزيادة في جملة واحدة، والصف بيفضل مقفول
                // لحد الـcommit فالقراية بعده آمنة تحت التزامن.
                $dayKey = OrderNumber::cairoDayKeyCompact();
                // ⚠️ ممنوع الاعتماد على LAST_INSERT_ID() هنا. جدول order_counters
                // فيه `id` AUTO_INCREMENT، وفي مسار **الإدخال الجديد** (أول شحنة
                // للفرع في اليوم) MySQL بيدوس على القيمة اللي بعتناها بـ
                // LAST_INSERT_ID(1) ويحط مكانها الـid المتولّد للصف. النتيجة كانت
                // إن أول شحنة كل يوم بتاخد رقم زي CAI-260819-2177 (= id الصف) بدل
                // CAI-260819-001. مسار ON DUPLICATE كان شغال صح، عشان كده الباج
                // كان بيختفي من الشحنة التانية وطالع. (اتكشف بـ e2e_phase1 يوم
                // 2026-08-19 — الاختبار مكانش اتشغّل بعد تحسين الأداء اللي أدخله.)
                // الحل المنقول هنا هو **النسخة المصلّحة**: نقرا العدّاد صراحةً.
                $allocOrderNum = function () use ($branchId, $dayKey, $now, $branchCode): string {
                    DB::insert(
                        'INSERT INTO order_counters (branch_id, day_key, counter, created_at)
                         VALUES (?,?,1,?)
                         ON DUPLICATE KEY UPDATE counter = counter + 1',
                        [$branchId, $dayKey, $now]
                    );

                    $sel = DB::select(
                        'SELECT counter FROM order_counters WHERE branch_id = ? AND day_key = ?',
                        [$branchId, $dayKey]
                    );
                    $n = (int) ($sel[0]->counter ?? 0);

                    return OrderNumber::format($branchCode, $n);
                };

                // ═══ تجهيز الطرود: ترقيم ثابت + snapshot الزون + جمع الفلوس ═══
                $zoneCache  = [];
                $zoneLookup = function (int $zoneId) use (&$zoneCache): ?array {
                    if (! isset($zoneCache[$zoneId])) {
                        $z = DB::select('SELECT area_name, price FROM zones WHERE id = ? LIMIT 1', [$zoneId]);
                        $zoneCache[$zoneId] = $z ? (array) $z[0] : null;
                    }

                    return $zoneCache[$zoneId];
                };

                $parcels = [];
                $no = 0;
                foreach ($deliveriesIn as $d) {
                    $no++;
                    $recvName = trim((string) ($d['receiverName'] ?? ''));
                    if ($recvName === '') {
                        throw new ApiException('يرجى إدخال اسم جهة التسليم لكل طرد');
                    }
                    $zoneId = self::intOrNull($d['zoneId'] ?? null);
                    if (! $zoneId) {
                        throw new ApiException('يرجى اختيار المنطقة لكل طرد');
                    }
                    $zone = $zoneLookup($zoneId);
                    if (! $zone) {
                        throw new ApiException('المنطقة المختارة غير موجودة');
                    }

                    $parcels[] = [
                        'parcel_no'       => isset($d['parcelNo']) && (int) $d['parcelNo'] > 0 ? (int) $d['parcelNo'] : $no,
                        'receiver_id'     => self::intOrNull($d['receiverId'] ?? null),
                        'receiver_name'   => $recvName,
                        'receiver_phone'  => self::trimOrNull($d['receiverPhone'] ?? null),
                        'receiver_phone2' => self::trimOrNull($d['receiverPhone2'] ?? null),
                        'zone_id'         => $zoneId,
                        // snapshot الزون: الاسم والسعر بيتخزّنوا على الطرد نفسه
                        // عشان تغيير تسعيرة المنطقة بعدين ما يغيّرش أوردر قديم
                        'zone_name'       => (string) ($d['zoneName'] ?? $zone['area_name']),
                        'zone_price'      => isset($d['zonePrice']) && $d['zonePrice'] !== ''
                            ? (float) $d['zonePrice'] : (float) $zone['price'],
                        'order_price'     => (float) ($d['orderPrice'] ?? 0),   // عهدة الطرد — جوّاه عشان تتنقل معاه لو اتفرّق
                        'address'         => self::trimOrNull($d['address'] ?? null),
                        'note'            => self::trimOrNull($d['note'] ?? null),
                        'lat'             => self::floatOrNull($d['lat'] ?? null),
                        'lng'             => self::floatOrNull($d['lng'] ?? null),
                        'images'          => is_array($d['images'] ?? null) ? $d['images'] : [],
                    ];
                }
                // 🔴 فلوس: مجموع خام من غير round() — منقول بالحرف
                $totalPrice   = array_sum(array_column($parcels, 'zone_price'));
                $storePrepaid = array_sum(array_column($parcels, 'order_price'));

                /* ── بوابة العهدة للكول سنتر ──────────────────────────────
                   العهدة بتطلع من جيب الطيار للمحل وبيحصّلها من المستلم،
                   فالشركة ضامنة المبلغ. الفرع شايف العميل قدامه فمش داخل
                   البوابة؛ الكول سنتر بياخد الطلب على التليفون من حد
                   مايعرفوش، فشرطين:
                     • جهة الاستلام كمّلت 10 أوردرات **متسلّمة**.
                     • وسقف 3000 ج.م للأوردر الواحد.

                   🔴 رفض صريح مش تصفير صامت — عكس تطبيق العميل. هناك
                   العميل نفسه بيكتب الرقم والواجهة بتشرح له ليه مقفول؛
                   هنا موظف بيكتب رقم اتقاله على التليفون، ولو صفّرناه في
                   السكات الطيار مش هيدفع للمحل ومحدش هيعرف ليه.

                   المكان مقصود: قبل allocOrderNum() — الرفض هنا مابيحرقش
                   رقم أوردر من عدّاد اليوم. */
                if ($storePrepaid > 0 && ($source === 'callcenter' || $actor->role === 'callcenter')) {
                    if ($storePrepaid > self::CC_CUSTODY_MAX) {
                        throw new ApiException(
                            'أقصى عهدة مسموحة من الكول سنتر ' . (int) self::CC_CUSTODY_MAX . ' ج.م — المبلغ ده لازم يتسجّل من الفرع'
                        );
                    }
                    $done = self::senderDeliveredCount($senderId);
                    if ($done < self::CC_CUSTODY_MIN_DELIVERED) {
                        throw new ApiException(
                            'العميل ده كمّل ' . $done . ' أوردر متسلّم بس — العهدة بتتفتح بعد '
                            . self::CC_CUSTODY_MIN_DELIVERED . ' أوردرات متسلّمة'
                        );
                    }
                }

                // كل التحقق وبحث المناطق خلص — دلوقتي بس نخصّص الرقم
                $orderNum = $allocOrderNum();

                // ═══ الأوردر نفسه ═══
                DB::insert(
                    'INSERT INTO orders
                       (order_num, branch_id, sender_id, sender_name, sender_phone, sender_phone2,
                        sender_address, sender_zone_id, sender_lat, sender_lng, geo_src,
                        status, status_since, created_at,
                        total_delivery_price, store_prepaid, store_prepaid_note, goods_value,
                        source, added_by, added_by_role,
                        customer_id, customer_name, customer_phone,
                        order_kind, payment_method, pieces_count, qr_code, notes)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $orderNum, $branchId, $senderId,
                        $senderName !== '' ? $senderName : null,
                        self::trimOrNull($request->input('senderPhone')),
                        self::trimOrNull($request->input('senderPhone2')),
                        self::trimOrNull($request->input('senderAddress')),
                        self::intOrNull($request->input('senderZoneId')),
                        self::floatOrNull($request->input('senderLat')),
                        self::floatOrNull($request->input('senderLng')),
                        self::trimOrNull($request->input('geoSrc')),
                        'processing', $now, $now,
                        $totalPrice, $storePrepaid,
                        self::trimOrNull($request->input('storePrepaidNote')),
                        self::floatOrNull($request->input('goodsValue')) ?? 0,
                        $source, $actor->username, $roleCode,
                        self::intOrNull($request->input('customerId')),
                        self::trimOrNull($request->input('customerName')),
                        self::trimOrNull($request->input('customerPhone')),
                        self::trimOrNull($request->input('orderKind')),
                        self::trimOrNull($request->input('paymentMethod')),
                        $request->input('piecesCount') !== null && (int) $request->input('piecesCount') > 0
                            ? (int) $request->input('piecesCount') : count($parcels),
                        $orderNum,   // qrCode بيساوي رقم الأوردر (VOCAB بند 3)
                        self::trimOrNull($request->input('notes')),
                    ]
                );
                $orderId = (int) DB::getPdo()->lastInsertId();

                // ═══ الطرود دفعة واحدة في نفس المعاملة ═══
                foreach ($parcels as $p) {
                    DB::insert(
                        'INSERT INTO order_deliveries
                           (order_id, parcel_no, receiver_id, receiver_name, receiver_phone, receiver_phone2,
                            zone_id, zone_name, zone_price, order_price, address, note, status, lat, lng, created_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [
                            $orderId, $p['parcel_no'], $p['receiver_id'], $p['receiver_name'],
                            $p['receiver_phone'], $p['receiver_phone2'],
                            $p['zone_id'], $p['zone_name'], $p['zone_price'], $p['order_price'],
                            $p['address'], $p['note'], 'processing', $p['lat'], $p['lng'], $now,
                        ]
                    );
                    $deliveryId = (int) DB::getPdo()->lastInsertId();
                    foreach ($p['images'] as $url) {
                        if (is_string($url) && $url !== '') {
                            DB::insert(
                                'INSERT INTO order_images (delivery_id, url, created_at) VALUES (?,?,?)',
                                [$deliveryId, $url, $now]
                            );
                        }
                    }
                }

                // أوردر جديد على لوحة الفرع — أهم حدث في الطابور
                $this->broadcastOrder($orderId);

                /* رسالة الواتساب للمستلمين — مهمة طابور بتتدفع بعد الـcommit
                   زي البثّ بالظبط. المسار ده هو باب الكول سنتر والإدارة
                   والفرع وتطبيق المحلات كلهم (المصدر بيتحدد من `source`)،
                   فنداء واحد هنا بيغطّي أربع مصادر من الخمسة. */
                $this->notifyOrderReceivers($orderId);

                return $orderId;
            });
        } catch (QueryException $e) {
            // نفس catch(PDOException) في الأصل — رسالة خاصة بالمسار مش
            // رسالة «خطأ في قاعدة البيانات» العامة بتاعة المعالج المركزي
            report($e);
            throw new ApiException('خطأ أثناء حفظ الأوردر — جرّب تاني', 500);
        }

        // TODO (مرحلة 4): إشعار FCM للفرع بأوردر جديد
        return self::orderOut($orderId);
    }

    /* ═══════════════════════════════════════════════════════════════
       تفريق طرود — فصل طرود لأوردر فرعي جديد (نفس المعاملة)
    ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /api/orders/{id}/split — orders_split
     *
     * 🔴 حساب فلوس دقيق: الأوردر الفرعي بياخد مجموع أسعار الطرود المختارة
     * وعهدتها، والأصل بيتحدّث بمجموع الباقي. **منقول بالحرف بما فيه العيوب**
     * (من غير round، وبيدوس على `store_prepaid` اليدوي) — شوف notes.
     *
     * ترتيب الأقفال جزء من الصح: صف الأوردر → صفوف الطرود → صف الطيار.
     */
    public function split(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();

        $parcelNosIn = $request->input('parcelNos');
        $parcelNos = is_array($parcelNosIn)
            ? array_values(array_filter(array_map('intval', $parcelNosIn)))
            : [];
        $pilotId = (int) ($request->input('pilotId') ?? 0);
        if (! $parcelNos) {
            throw new ApiException('اختر طردًا واحدًا على الأقل');
        }
        if (! $pilotId) {
            throw new ApiException('اختر طيارًا');
        }

        $now = WireTime::nowDb();

        try {
            [$newId, $orderId, $shiftId, $pilotName] = DB::transaction(function () use (
                $actor, $id, $parcelNos, $pilotId, $now
            ): array {
                $order = self::lockOrderRow($id);
                if (! $order) {
                    throw new ApiException('الأوردر ده مابقاش موجود — اعمل تحديث للصفحة', 409);
                }
                self::guardBranch($actor, $order);
                // التفريق كمان لازم يتأكد إن الأوردر لسه متاح — من غير كده موظفين
                // ممكن يفرّقوا نفس الأوردر في نفس اللحظة فتطلع نسختين
                if ($order['status'] === 'delivering') {
                    throw new ApiException(
                        'الأوردر ده اتحمّل على ' . ($order['pilot_name'] ?: 'طيار تاني') . ' من ثانية — اعمل تحديث للصفحة',
                        409
                    );
                }
                if (! in_array($order['status'], ['processing', 'undelivered'], true)) {
                    throw new ApiException('حالة الأوردر الحالية لا تسمح بالتفريق', 409);
                }
                $orderId = (int) $order['id'];

                $all = array_map(
                    fn ($r) => (array) $r,
                    DB::select('SELECT * FROM order_deliveries WHERE order_id = ? ORDER BY parcel_no FOR UPDATE', [$orderId])
                );
                $selected  = array_values(array_filter($all, fn ($d) => in_array((int) $d['parcel_no'], $parcelNos, true)));
                $remaining = array_values(array_filter($all, fn ($d) => ! in_array((int) $d['parcel_no'], $parcelNos, true)));
                if (! $selected) {
                    throw new ApiException('الطرود المختارة مش موجودة في الأوردر', 409);
                }
                if (! $remaining) {
                    throw new ApiException('دي كل طرود الأوردر — استخدم التحميل العادي بدل التفريق', 409);
                }

                $pilotRow = DB::select('SELECT * FROM pilots WHERE id = ? FOR UPDATE', [$pilotId]);
                if (! $pilotRow) {
                    throw new ApiException('الطيار غير موجود', 409);
                }
                $pilot = (array) $pilotRow[0];
                if (! in_array($pilot['status'], ['waiting', 'delivering'], true)) {
                    throw new ApiException('الطيار غير متاح للتحميل حاليًا', 409);
                }
                $shiftId = self::activeShiftId($pilotId);

                // رقم الجزء المفصول من أرقام الطرود الثابتة: HAL-260804-001-2+3
                $newNum = OrderNumber::parcelSuffix(
                    (string) $order['order_num'],
                    array_map(fn ($d) => (int) $d['parcel_no'], $selected)
                );

                // 🔴 الفلوس — جمع خام من غير round()، منقول بالحرف
                $selTotal   = array_sum(array_map(fn ($d) => (float) $d['zone_price'], $selected));
                $selPrepaid = array_sum(array_map(fn ($d) => (float) $d['order_price'], $selected));
                $remTotal   = array_sum(array_map(fn ($d) => (float) $d['zone_price'], $remaining));
                $remPrepaid = array_sum(array_map(fn ($d) => (float) $d['order_price'], $remaining));

                // الأوردر الفرعي: بيورث createdAt من الأصل وبياخد الحالة «جاري التوصيل» مباشرة
                DB::insert(
                    'INSERT INTO orders
                       (order_num, branch_id, sender_id, sender_name, sender_phone, sender_phone2,
                        sender_address, sender_zone_id, sender_lat, sender_lng, geo_src,
                        status, status_since, created_at,
                        pilot_id, pilot_name, shift_id, current_pilot_since,
                        total_delivery_price, store_prepaid, store_prepaid_note,
                        split_from_id, source, added_by, added_by_role,
                        customer_id, customer_name, customer_phone,
                        order_kind, payment_method, pieces_count, qr_code, notes)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $newNum, $order['branch_id'], $order['sender_id'], $order['sender_name'],
                        $order['sender_phone'], $order['sender_phone2'], $order['sender_address'],
                        $order['sender_zone_id'], $order['sender_lat'], $order['sender_lng'], $order['geo_src'],
                        'delivering', $now, $order['created_at'],
                        $pilotId, $pilot['name'], $shiftId, $now,
                        $selTotal, $selPrepaid, $order['store_prepaid_note'],
                        $orderId, $order['source'], $order['added_by'], $order['added_by_role'],
                        $order['customer_id'], $order['customer_name'], $order['customer_phone'],
                        $order['order_kind'], $order['payment_method'], count($selected),
                        $order['qr_code'] !== null ? $newNum : null,   // QR بيساوي رقم الأوردر — الجزء بياخد رقمه الجديد
                        $order['notes'],
                    ]
                );
                $newId = (int) DB::getPdo()->lastInsertId();

                // نقل صفوف الطرود المختارة للأوردر الجديد — الصور بتتبعها بالـ FK
                $ph = implode(',', array_fill(0, count($selected), '?'));
                DB::update(
                    "UPDATE order_deliveries SET order_id = ? WHERE id IN ({$ph})",
                    array_merge([$newId], array_map(fn ($d) => (int) $d['id'], $selected))
                );

                // الأصل بيفضل «قيد التنفيذ» بطروده المتبقية وعهدتها بس
                DB::update(
                    'UPDATE orders SET total_delivery_price = ?, store_prepaid = ?, pieces_count = ? WHERE id = ?',
                    [$remTotal, $remPrepaid, count($remaining), $orderId]
                );

                // حالة الطيار زي التحميل العادي
                if ($pilot['status'] === 'waiting') {
                    DB::update(
                        "UPDATE pilots SET status = 'delivering', queue_no = NULL, status_since = ? WHERE id = ?",
                        [$now, $pilotId]
                    );
                    $branchId = $pilot['assigned_branch_id'] !== null
                        ? (int) $pilot['assigned_branch_id'] : (int) ($actor->branchId ?? 0);
                    if ($branchId && $pilot['queue_no'] !== null) {
                        self::queueCompact($branchId, (int) $pilot['queue_no']);
                    }
                }

                // أوردرين اتغيّروا: الجزء المفصول (جديد + متحمّل) والأصل
                // (اتغيّرت طروده وفلوسه). الاتنين على نفس قناة الفرع.
                $this->broadcastOrder($newId);
                $this->broadcastOrder($orderId);

                /* 🔴 **مفيش `notifyOrderReceivers()` هنا — وده قرار مش سهو.**
                   التفريق بيعمل صف `orders` جديد، بس **مابيعملش شحنة جديدة**:
                   الطرود بتتنقل بالـUPDATE فوق من الأصل للجزء، والمستلمين
                   دول أخدوا رسايلهم خلاص وقت إنشاء الأوردر الأصلي.

                   ونداء هنا كان هيبعت تاني فعلًا: مفتاح منع التكرار
                   `(order_id, channel, recipient_phone)` فيه `order_id`،
                   والـid هنا **جديد** — يعني الصف القديم مش هيمنع حاجة،
                   والمستلم بياخد رسالتين عن نفس الطرد. ده بالظبط التكرار
                   اللي الجدول اتعمل عشان يمنعه.

                   ⚠️ الأثر المعروف: كود التتبّع القديم بتاع الطرد المنقول
                   (`ORD-260819-001-2`) بيدوّر على الطرد جوه الأوردر الأصلي —
                   والطرد بقى في الجزء الجديد، فصفحة التتبّع بترجّع قايمة
                   فاضية. ده **سلوك التفريق الأصلي زي ما هو** ومالوش علاقة
                   بالرسايل؛ إصلاحه قرار منفصل في `PublicSiteController::track`
                   (يدوّر بالـ`split_from_id` كمان)، مش هنا. */

                return [$newId, $orderId, $shiftId, (string) $pilot['name']];
            });
        } catch (QueryException $e) {
            report($e);
            throw new ApiException('التفريق ما تمّش — جرّب تاني', 500);
        }

        // TODO (مرحلة 4): إشعار FCM للطيار
        return ApiResponse::out([
            'ok'      => true,
            'order'   => OrderWire::full($newId),      // الجزء المفصول (المتحمّل)
            'parent'  => OrderWire::full($orderId),    // الأصل بطروده المتبقية
            'warning' => $shiftId === null
                ? '⚠️ ' . $pilotName . ' مالوش وردية مفتوحة — الأوردر مش هيتحسب في تقفيلة وردية'
                : null,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════
       تعديل بيانات الأوردر + صور الطرود + الرفع العام
    ═══════════════════════════════════════════════════════════════ */

    /**
     * PUT /api/orders/{id} — orders_update_details
     * body: { senderName?, senderPhone?, senderPhone2?, senderAddress?,
     *         deliveries?: [{id, receiverName?, receiverPhone?, receiverPhone2?, address?}] }
     * تعديل snapshot بيانات الاتصال بس — مش الحالة ولا الفلوس.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();

        try {
            $orderId = DB::transaction(function () use ($request, $actor, $id): int {
                $order = self::lockOrderRow($id);
                if (! $order) {
                    throw new ApiException('الأوردر ده مابقاش موجود — اعمل تحديث للصفحة', 409);
                }
                self::guardBranch($actor, $order);
                $orderId = (int) $order['id'];

                DB::update(
                    'UPDATE orders SET sender_name = ?, sender_phone = ?, sender_phone2 = ?, sender_address = ? WHERE id = ?',
                    [
                        self::trimOrNull($request->input('senderName') ?? $order['sender_name'] ?? ''),
                        self::trimOrNull($request->input('senderPhone') ?? $order['sender_phone'] ?? ''),
                        self::trimOrNull($request->input('senderPhone2') ?? $order['sender_phone2'] ?? ''),
                        self::trimOrNull($request->input('senderAddress') ?? $order['sender_address'] ?? ''),
                        $orderId,
                    ]
                );

                $deliveries = $request->input('deliveries');
                if (is_array($deliveries)) {
                    foreach ($deliveries as $d) {
                        if (! is_array($d) || empty($d['id'])) {
                            continue;
                        }
                        // ⚠️ الحقول اللي مش مبعوتة بتتكتب null — مفيش fallback
                        // للقيمة القديمة هنا (على عكس حقول المُرسِل فوق). ده
                        // سلوك الأصل بالحرف (باج متسجّل في notes).
                        DB::update(
                            'UPDATE order_deliveries SET receiver_name = ?, receiver_phone = ?, receiver_phone2 = ?, address = ?
                             WHERE id = ? AND order_id = ?',
                            [
                                self::trimOrNull($d['receiverName'] ?? null),
                                self::trimOrNull($d['receiverPhone'] ?? null),
                                self::trimOrNull($d['receiverPhone2'] ?? null),
                                self::trimOrNull($d['address'] ?? null),
                                (int) $d['id'], $orderId,
                            ]
                        );
                    }
                }

                // لمسة updated_at عشان delta polling يشوف التغيير حتى لو الطرود بس اتعدلت
                DB::update('UPDATE orders SET updated_at = NOW(3) WHERE id = ?', [$orderId]);

                // المسار ده بيلمس updated_at صراحةً عشان الاستطلاع يشوفه —
                // فالبثّ لازم يشوفه كمان، وإلا يبقى الدفع أضيق من الاستطلاع.
                $this->broadcastOrder($orderId);

                return $orderId;
            });
        } catch (QueryException $e) {
            report($e);
            throw new ApiException('التعديل ما تمّش — جرّب تاني', 500);
        }

        return self::orderOut($orderId);
    }

    /**
     * POST /api/orders/{id}/images — إضافة صور (روابط مرفوعة عبر /api/upload) لطرد.
     *
     * ⚠️ التحقق هنا `require_auth()` بس + نطاق الفرع — مفيش تحقق للمحل ولا
     * للطيار ولا للعميل (باج متسجّل في notes، اتنقل زي ما هو).
     */
    public function addImages(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();

        $deliveryId = (int) ($request->input('deliveryId') ?? 0);
        $urlsIn = $request->input('urls');
        $urls = is_array($urlsIn) ? $urlsIn : [];
        if (! $deliveryId || ! $urls) {
            throw new ApiException('حدد الطرد والصور');
        }

        // القراءة من غير قفل — زي الأصل (orders_lock_row(..., false))
        $order = self::lockOrderRow($id, false);
        if (! $order) {
            throw ApiException::notFound('الأوردر ده مابقاش موجود — اعمل تحديث للصفحة');
        }
        self::guardBranch($actor, $order);

        $belongs = DB::select(
            'SELECT id FROM order_deliveries WHERE id = ? AND order_id = ? LIMIT 1',
            [$deliveryId, (int) $order['id']]
        );
        if (! $belongs) {
            throw new ApiException('الطرد ده مش تابع للأوردر');
        }

        $now = WireTime::nowDb();

        // الأصل مكانش بيلفّ الكتابات دي في معاملة. لفّيناها هنا عشان
        // الاستطلاع ما يشوفش نص الصور (الرد نفسه ما اتغيرش).
        DB::transaction(function () use ($urls, $deliveryId, $now, $order): void {
            foreach ($urls as $u) {
                if (is_string($u) && $u !== '') {
                    DB::insert(
                        'INSERT INTO order_images (delivery_id, url, created_at) VALUES (?,?,?)',
                        [$deliveryId, $u, $now]
                    );
                }
            }
            // لمسة updated_at عشان delta polling يشوف التغيير (الصور جدول ابن)
            DB::update('UPDATE orders SET updated_at = NOW(3) WHERE id = ?', [(int) $order['id']]);

            // نفس منطق `update()`: اللمسة دي موجودة عشان الاستطلاع، فالبثّ يتبعها
            $this->broadcastOrder((int) $order['id']);
        });

        return self::orderOut((int) $order['id']);
    }

    /**
     * POST /api/upload — رفع صور عام (دستور الـ API بند 10)
     * multipart حقل file — jpeg/png/webp بس، حد 5MB،
     * بيتحفظ في public/uploads/YYYYMM/ باسم عشوائي وبيرجع {url}
     *
     * النوع بيتقرا من **محتوى الملف** بـfinfo مش من الامتداد — ده اللي
     * بيمنع رفع سكريبت باسم .jpg.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->actorOrFail();

        $f = $request->file('file') ?? $request->file('image');
        $tmp = $f !== null ? (string) $f->getPathname() : '';
        if ($f === null || $tmp === '' || ! is_uploaded_file($tmp)) {
            throw new ApiException('مفيش ملف مرفوع');
        }
        if ($f->getError() !== UPLOAD_ERR_OK) {
            throw new ApiException('فشل رفع الملف — جرّب تاني');
        }
        if ((int) filesize($tmp) > 5 * 1024 * 1024) {
            throw new ApiException('حجم الصورة أكبر من 5 ميجا — اضغطها الأول');
        }

        // النوع من محتوى الملف نفسه مش من الامتداد
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $extByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (! isset($extByMime[$mime])) {
            throw new ApiException('نوع الملف غير مسموح — jpeg أو png أو webp بس');
        }

        $monthDir = gmdate('Ym');
        $dirFs    = public_path('uploads/' . $monthDir);
        if (! is_dir($dirFs) && ! mkdir($dirFs, 0775, true) && ! is_dir($dirFs)) {
            report(new \RuntimeException('orders_upload: mkdir failed for ' . $dirFs));
            throw new ApiException('تعذّر حفظ الصورة — بلّغ الإدارة', 500);
        }

        $name = bin2hex(random_bytes(16)) . '.' . $extByMime[$mime];
        if (! move_uploaded_file($tmp, $dirFs . '/' . $name)) {
            report(new \RuntimeException('orders_upload: move_uploaded_file failed'));
            throw new ApiException('تعذّر حفظ الصورة — جرّب تاني', 500);
        }

        return ApiResponse::out(['ok' => true, 'url' => '/uploads/' . $monthDir . '/' . $name]);
    }

    /* ═══════════════════════════════════════════════════════════════
       التحميل على طيار — حجز ذري (claimOrderForPilot)
    ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /api/orders/{id}/assign — orders_assign · أدوار: branch, admin
     *
     * كل الشغل في `claimCore`. هنا بس التحقق من المدخلات وتحويل خطأ
     * القاعدة لرسالة المسار (مش رسالة المعالج المركزي العامة).
     */
    public function assign(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();

        $pilotId = (int) ($request->input('pilotId') ?? 0);
        if (! $pilotId) {
            throw new ApiException('اختر طيارًا');
        }

        $now = WireTime::nowDb();

        try {
            $res = DB::transaction(
                fn (): array => self::claimCore($actor, $id, $pilotId, $now)
            );
        } catch (QueryException $e) {
            report($e);
            throw new ApiException('التحميل ما تمّش — جرّب تاني', 500);
        }

        /* بعد المعاملة مش جواها: `claimCore` **ساكنة** (`self::`) فمفيش
           `$this` جواها. المعاملة خلصت بنجاح خلاص، فالبثّ هنا بيحصل على
           صف متأكدين إنه اتكتب — نفس ضمانة ShouldDispatchAfterCommit. */
        $this->broadcastOrder($res['orderId']);

        // TODO (مرحلة 4): إشعار FCM للطيار بأوردر متحمّل عليه
        return self::orderOut($res['orderId'], ['warning' => $res['warning']]);
    }

    /**
     * POST /api/orders/assign-bulk — orders_assign_bulk · أدوار: branch, admin
     *
     * ⚠️ **كل أوردر بمعاملته المستقلة** — ده مقصود: فشل أوردر مايرجّعش
     * اللي قبله. الفشل بيتجمّع في `failed` والنجاح في `loaded`.
     *
     * 🔴 الفرق بين نوعي الفشل منقول بالحرف: خطأ الحجز (409) بيتسجّل في
     * `failed` والحلقة بتكمّل، لكن **حارس الفرع (403) بيقطع الطلب كله**
     * — في الأصل `orders_guard_branch` بتنده `fail()` اللي بتعمل exit جوه
     * الحلقة، فالأوردرات اللي اتحمّلت قبلها بتفضل متحمّلة والرد بيبقى 403.
     */
    public function assignBulk(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        $idsIn = $request->input('orderIds');
        $ids = is_array($idsIn) ? $idsIn : [];
        $pilotId = (int) ($request->input('pilotId') ?? 0);
        if (! $ids || ! $pilotId) {
            throw new ApiException('اختر أوردرات وطيارًا');
        }

        $now = WireTime::nowDb();
        $loaded = [];
        $failed = [];
        $warning = null;

        foreach ($ids as $id) {
            try {
                $res = DB::transaction(
                    fn (): array => self::claimCore(
                        $actor,
                        is_numeric($id) ? (int) $id : (string) $id,
                        $pilotId,
                        $now
                    )
                );
                $loaded[] = $res['orderId'];
                // كل أوردر بمعاملته المستقلة، فالبثّ كمان لكل واحد لوحده
                // بعد ما معاملته تنجح — الفاشل مابيتبثّش
                $this->broadcastOrder($res['orderId']);
                // `?? $warning` مش `=` — التحذير الفاضي مايمسحش تحذير سابق
                $warning = $res['warning'] ?? $warning;
            } catch (ApiException $e) {
                // 403 حارس الفرع = خروج فوري زي fail() في الأصل
                if ($e->status() !== 409) {
                    throw $e;
                }
                $failed[] = ['id' => $id, 'error' => $e->getMessage()];
            } catch (QueryException $e) {
                report($e);
                $failed[] = ['id' => $id, 'error' => 'التحميل ما تمّش — جرّب تاني'];
            }
        }

        // TODO (مرحلة 4): إشعار FCM للطيار
        return ApiResponse::out([
            'ok'      => true,
            'loaded'  => $loaded,
            'failed'  => $failed,
            'warning' => $warning,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════
       النقل بين الطيارين — 3 قواعد VOCAB بند 13.2 (applyOrderTransfer)
    ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /api/orders/{id}/transfer — orders_transfer_pilot · أدوار: branch, admin
     *
     * القواعد التلاتة اللي ممنوع تتكسر:
     *  1. `status_since` **ممنوع لمسه** — الوقت التراكمي بيكمّل من أول
     *     تحميل، ووقت الطيار الحالي بيتسجّل منفصل في `current_pilot_since`.
     *  2. حالة الطيارين بتتحسب من **الأوردرات الفعلية** مش من قيمة مخزّنة
     *     (`syncPilotStatus` للطيار الجديد ثم القديم — بالترتيب ده).
     *  3. الوردية بتنتقل لوردية الطيار الجديد المفتوحة — أو NULL + تحذير.
     */
    public function transfer(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();

        $toPilotId = (int) ($request->input('toPilotId') ?? $request->input('pilotId') ?? 0);
        if (! $toPilotId) {
            throw new ApiException('اختر الطيار الجديد');
        }

        $now = WireTime::nowDb();

        try {
            [$orderId, $newShiftId, $toPilotName] = DB::transaction(function () use (
                $actor, $id, $toPilotId, $now
            ): array {
                $order = self::lockOrderRow($id);
                if (! $order) {
                    throw new ApiException('الأوردر ده مابقاش موجود — اعمل تحديث للصفحة', 409);
                }
                self::guardBranch($actor, $order);
                if ($order['status'] !== 'delivering' || $order['pilot_id'] === null) {
                    throw new ApiException('النقل متاح للأوردرات الجارية المحمّلة على طيار بس', 409);
                }
                $fromPilotId = (int) $order['pilot_id'];
                if ($fromPilotId === $toPilotId) {
                    throw new ApiException('الأوردر محمّل على نفس الطيار بالفعل', 409);
                }

                $toPilotRow = DB::select('SELECT * FROM pilots WHERE id = ? FOR UPDATE', [$toPilotId]);
                if (! $toPilotRow) {
                    throw new ApiException('الطيار الجديد غير موجود', 409);
                }
                $toPilot = (array) $toPilotRow[0];
                if (! in_array($toPilot['status'], ['waiting', 'delivering'], true)) {
                    throw new ApiException('الطيار الجديد غير متاح للنقل حاليًا', 409);
                }

                // قاعدة 3: الوردية بتنتقل لوردية الطيار الجديد المفتوحة — أو NULL + تحذير
                $newShiftId = self::activeShiftId($toPilotId);

                // قاعدة 1: statusSince ممنوع لمسه — الوقت التراكمي بيكمّل من أول تحميل،
                // ووقت الطيار الحالي بيتسجل منفصل في current_pilot_since
                DB::update(
                    'UPDATE orders
                     SET pilot_id = ?, pilot_name = ?, shift_id = ?,
                         current_pilot_since = ?, transfer_count = transfer_count + 1
                     WHERE id = ?',
                    [$toPilotId, $toPilot['name'], $newShiftId, $now, (int) $order['id']]
                );

                // سجل النقل — منه بيتبني transferHistory وtransferredFrom/At على السلك
                DB::insert(
                    'INSERT INTO order_transfers
                       (order_id, from_pilot_id, to_pilot_id, from_shift_id, to_shift_id, transferred_at, transferred_by)
                     VALUES (?,?,?,?,?,?,?)',
                    [
                        (int) $order['id'], $fromPilotId, $toPilotId,
                        $order['shift_id'] !== null ? (int) $order['shift_id'] : null,
                        $newShiftId, $now, $actor->username,
                    ]
                );

                // قاعدة 2: حالة الطيارين من الأوردرات الفعلية (لون الخريطة)
                self::syncPilotStatus($toPilotId, $now, ((int) ($actor->branchId ?? 0)) ?: null);
                self::syncPilotStatus($fromPilotId, $now, ((int) ($actor->branchId ?? 0)) ?: null);

                // تغيير تعيين: pilot_id/pilot_name/shift_id — والـpilotId جوه
                // حمولة الحدث نفسها، فاللوحة تقدر تحدّث الصف محليًا
                $this->broadcastOrder((int) $order['id']);

                return [(int) $order['id'], $newShiftId, (string) $toPilot['name']];
            });
        } catch (QueryException $e) {
            report($e);
            throw new ApiException('النقل ما تمّش — جرّب تاني', 500);
        }

        // TODO (مرحلة 4): إشعار FCM للطيارين الاتنين
        return self::orderOut($orderId, [
            'warning' => $newShiftId === null
                ? '⚠️ ' . $toPilotName . ' مالوش وردية مفتوحة — الأوردر مش هيتحسب في تقفيلة وردية'
                : null,
        ]);
    }

    /**
     * POST /api/orders/{id}/transfer-branch — orders_transfer_branch
     * أدوار: branch, admin, callcenter
     *
     * تصحيح فرع خاطئ (submitTransferOrder القديمة).
     *
     * ⚠️ `created_at` **بيتصفّر** = وقت وصول الأوردر للفرع الجديد. ده سلوك
     * الأصل بالحرف: طابور الفرع الجديد بيترتب بوقت الوصول مش وقت الإنشاء.
     */
    public function transferBranch(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();

        $newBranchId = (int) ($request->input('branchId') ?? 0);
        if (! $newBranchId) {
            throw new ApiException('اختر الفرع الصحيح');
        }

        try {
            $orderId = DB::transaction(function () use ($actor, $id, $newBranchId): int {
                $order = self::lockOrderRow($id);
                if (! $order) {
                    throw new ApiException('الأوردر ده مابقاش موجود — اعمل تحديث للصفحة', 409);
                }
                self::guardBranch($actor, $order);
                if ((int) $order['branch_id'] === $newBranchId) {
                    throw new ApiException('الأوردر في الفرع ده بالفعل', 409);
                }
                $exists = DB::select('SELECT id FROM branches WHERE id = ? LIMIT 1', [$newBranchId]);
                if (! $exists) {
                    throw new ApiException('الفرع المختار غير موجود', 409);
                }

                // زي القديم: createdAt بيتصفّر = وقت وصول الأوردر للفرع الجديد
                DB::update(
                    'UPDATE orders SET branch_id = ?, created_at = ? WHERE id = ?',
                    [$newBranchId, WireTime::nowDb(), (int) $order['id']]
                );

                /* ⚠️ الحدث بيروح لقناة **الفرع الجديد** بس، لأن `broadcastOrder`
                   بتقرا الصف بعد التعديل و`broadcastOn()` بيبني القناة من
                   `branch_id` اللي فيه. الفرع القديم مش هيوصله إن الأوردر خرج
                   من عنده — بيمسكه بالاستطلاع زي دلوقتي بالظبط. حدث تاني
                   للفرع القديم كان هيحتاج توقيع تاني للدالة المشتركة، وده
                   قرار مرحلة تحويل الواجهة مش دلوقتي. */
                $this->broadcastOrder((int) $order['id']);

                return (int) $order['id'];
            });
        } catch (QueryException $e) {
            report($e);
            throw new ApiException('النقل ما تمّش — جرّب تاني', 500);
        }

        return self::orderOut($orderId);
    }

    /* ═══════════════════════════════════════════════════════════════
       دورة الحالة — استلام / بدء رحلة / تسليم / عدم تسليم / إلغاء / تأجيل
    ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /api/orders/{id}/receive — orders_receive · أدوار: pilot, branch, admin
     *
     * استلام الطيار للطرود من المُرسِل — بيسجل receivedAt.
     *
     * ⚠️ الحارس هنا **حارس الطيار بس** — مفيش `orders_guard_branch`. يعني
     * مشرف فرع يقدر يسجّل استلام أوردر فرع تاني. منقول زي ما هو (شوف notes).
     * ⚠️ ومفيش `catch (PDOException)` في الأصل — خطأ القاعدة بيطلع للمعالج
     * المركزي برسالة «خطأ في قاعدة البيانات» 500. سيبناه كده بالظبط.
     */
    public function receive(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $now = WireTime::nowDb();

        $orderId = DB::transaction(function () use ($actor, $id, $now): int {
            $order = self::lockOrderRow($id);
            if (! $order) {
                throw new ApiException('الأوردر ده مابقاش موجود — اعمل تحديث للصفحة', 409);
            }
            self::guardPilot($actor, $order);
            if ($order['status'] !== 'delivering') {
                throw new ApiException('الأوردر مش في حالة توصيل', 409);
            }
            // مرة واحدة بس — إعادة الاستلام مابتغيّرش الطابع الأول
            if ($order['received_at'] === null) {
                DB::update('UPDATE orders SET received_at = ? WHERE id = ?', [$now, (int) $order['id']]);
                // جوه الـif: إعادة الاستلام مابتكتبش صف، فمابتبثّش حدث كمان
                $this->broadcastOrder((int) $order['id']);
            }

            return (int) $order['id'];
        });

        return self::orderOut($orderId);
    }

    /**
     * POST /api/orders/{id}/start-trip — orders_start_trip · أدوار: pilot, branch, admin
     *
     * بدء رحلة التوصيل — tripStartedAt (+ receivedAt بنفس اللحظة لو اتنسي
     * — شبكة أمان: `COALESCE(received_at, ?)` مابيدوسش على استلام موجود).
     */
    public function startTrip(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $now = WireTime::nowDb();

        $orderId = DB::transaction(function () use ($actor, $id, $now): int {
            $order = self::lockOrderRow($id);
            if (! $order) {
                throw new ApiException('الأوردر ده مابقاش موجود — اعمل تحديث للصفحة', 409);
            }
            self::guardPilot($actor, $order);
            if ($order['status'] !== 'delivering') {
                throw new ApiException('الأوردر مش في حالة توصيل', 409);
            }
            DB::update(
                'UPDATE orders SET trip_started_at = ?, received_at = COALESCE(received_at, ?) WHERE id = ?',
                [$now, $now, (int) $order['id']]
            );
            $this->broadcastOrder((int) $order['id']);

            return (int) $order['id'];
        });

        return self::orderOut($orderId);
    }

    /**
     * POST /api/orders/{id}/deliver — orders_deliver · أدوار: pilot, branch, admin
     *
     * «تم التسليم» + deliveredAt، و`moneySettled` بيفضل false لحد ما الفرع
     * يسوّي (VOCAB بند 4).
     *
     * 🔴 الفلوس: `net_delivery_price = max(0, totalDeliveryPrice - walletUsed)`
     * — **من غير round()**، منقول بالحرف. القيمتين نصوص decimal من القاعدة
     * والطرح بيتم بعد cast لـfloat زي الأصل.
     */
    public function deliver(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $now = WireTime::nowDb();

        try {
            $orderId = DB::transaction(function () use ($actor, $id, $now): int {
                $order = self::lockOrderRow($id);
                if (! $order) {
                    throw new ApiException('الأوردر ده مابقاش موجود — اعمل تحديث للصفحة', 409);
                }
                self::guardBranch($actor, $order);
                self::guardPilot($actor, $order);
                if ($order['status'] !== 'delivering') {
                    throw new ApiException('الأوردر مش في حالة توصيل', 409);
                }

                // صافي سعر التوصيل بعد المخصوم من المحفظة
                $net = max(0.0, (float) $order['total_delivery_price'] - (float) $order['wallet_used']);
                DB::update(
                    "UPDATE orders
                     SET status = 'delivered', delivered_at = ?, money_settled = 0, net_delivery_price = ?
                     WHERE id = ?",
                    [$now, $net, (int) $order['id']]
                );
                // الطرود اللي لسه معلّقة بتتقفل «تم التسليم»
                DB::update(
                    "UPDATE order_deliveries SET status = 'delivered'
                     WHERE order_id = ? AND status NOT IN ('delivered','undelivered','cancelled')",
                    [(int) $order['id']]
                );

                self::syncPilotStatus(
                    $order['pilot_id'] !== null ? (int) $order['pilot_id'] : null,
                    $now,
                    ((int) ($actor->branchId ?? 0)) ?: null
                );

                // تغيير حالة → «تم التسليم»
                $this->broadcastOrder((int) $order['id']);

                return (int) $order['id'];
            });
        } catch (QueryException $e) {
            report($e);
            throw new ApiException('التسليم ما تمّش — جرّب تاني', 500);
        }

        return self::orderOut($orderId);
    }

    /**
     * POST /api/orders/{id}/undeliver — orders_undeliver · أدوار: pilot, branch, admin
     *
     * «لم يتم التوصيل» (markOrderNotDelivered القديمة). السبب الفاضي بيبقى
     * «—» (شرطة) مش خطأ — على عكس الإلغاء اللي بيلزم سبب.
     * `return_status` و`return_reason` بيتصفّروا: طلب المرتجع القديم بيتلغي
     * مع كل عدم تسليم جديد.
     */
    public function undeliver(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $reason = trim((string) ($request->input('reason') ?? '')) ?: '—';
        $now = WireTime::nowDb();

        try {
            $orderId = DB::transaction(function () use ($actor, $id, $reason, $now): int {
                $order = self::lockOrderRow($id);
                if (! $order) {
                    throw new ApiException('الأوردر ده مابقاش موجود — اعمل تحديث للصفحة', 409);
                }
                self::guardBranch($actor, $order);
                self::guardPilot($actor, $order);
                if ($order['status'] !== 'delivering') {
                    throw new ApiException('الأوردر مش في حالة توصيل', 409);
                }
                DB::update(
                    "UPDATE orders SET status = 'undelivered', undelivered_at = ?, undelivered_reason = ?,
                            return_status = NULL, return_reason = NULL
                     WHERE id = ?",
                    [$now, $reason, (int) $order['id']]
                );
                DB::update(
                    "UPDATE order_deliveries SET status = 'undelivered'
                     WHERE order_id = ? AND status NOT IN ('delivered','undelivered','cancelled')",
                    [(int) $order['id']]
                );

                self::syncPilotStatus(
                    $order['pilot_id'] !== null ? (int) $order['pilot_id'] : null,
                    $now,
                    ((int) ($actor->branchId ?? 0)) ?: null
                );

                // تغيير حالة → «لم يتم التوصيل»
                $this->broadcastOrder((int) $order['id']);

                return (int) $order['id'];
            });
        } catch (QueryException $e) {
            report($e);
            throw new ApiException('التسجيل ما تمّش — جرّب تاني', 500);
        }

        return self::orderOut($orderId);
    }

    /**
     * POST /api/orders/{id}/cancel — orders_cancel · أدوار: branch, admin, callcenter
     *
     * إلغاء بسبب — بيسجل `prev_status` قبل «ملغي» (cancelOrderBranch القديمة).
     * `prev_status` بياخد `order.status ?: 'processing'` (VOCAB بند 4).
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();

        $reason = trim((string) ($request->input('reason') ?? ''));
        if ($reason === '') {
            throw new ApiException('يجب كتابة سبب للإلغاء');
        }

        $now = WireTime::nowDb();

        try {
            $orderId = DB::transaction(function () use ($actor, $id, $reason, $now): int {
                $order = self::lockOrderRow($id);
                if (! $order) {
                    throw new ApiException('الأوردر ده مابقاش موجود — اعمل تحديث للصفحة', 409);
                }
                self::guardBranch($actor, $order);
                if ($order['status'] === 'cancelled') {
                    throw new ApiException('الأوردر ملغي بالفعل', 409);
                }
                if ($order['status'] === 'delivered') {
                    throw new ApiException('الأوردر اتسلّم بالفعل — مينفعش يتلغي', 409);
                }

                // prevStatus: order.status || «قيد التنفيذ» (VOCAB بند 4)
                DB::update(
                    "UPDATE orders
                     SET prev_status = ?, status = 'cancelled',
                         cancelled_at = ?, cancelled_by = ?, cancelled_reason = ?
                     WHERE id = ?",
                    [
                        $order['status'] ?: 'processing', $now, $actor->username, $reason, (int) $order['id'],
                    ]
                );

                // كان جاري التوصيل → الطيار يرجع للانتظار لو مفيش غيره
                if ($order['status'] === 'delivering' && $order['pilot_id'] !== null) {
                    self::syncPilotStatus(
                        (int) $order['pilot_id'],
                        $now,
                        ((int) ($actor->branchId ?? 0)) ?: null
                    );
                }

                // تغيير حالة → «ملغي»
                $this->broadcastOrder((int) $order['id']);

                return (int) $order['id'];
            });
        } catch (QueryException $e) {
            report($e);
            throw new ApiException('الإلغاء ما تمّش — جرّب تاني', 500);
        }

        return self::orderOut($orderId);
    }

    /**
     * POST /api/orders/{id}/postpone — orders_postpone · أدوار: branch, admin, callcenter
     *
     * تأجيل — بيسجل `prev_status` وبيرجع لها عند فك التأجيل. متاح للأوردرات
     * **غير المحمّلة بس** (قيد التنفيذ أو لم يتم التوصيل).
     */
    public function postpone(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $now = WireTime::nowDb();

        $orderId = DB::transaction(function () use ($actor, $id, $now): int {
            $order = self::lockOrderRow($id);
            if (! $order) {
                throw new ApiException('الأوردر ده مابقاش موجود — اعمل تحديث للصفحة', 409);
            }
            self::guardBranch($actor, $order);
            if (! in_array($order['status'], ['processing', 'undelivered'], true)) {
                throw new ApiException('التأجيل متاح للأوردرات غير المحمّلة بس', 409);
            }
            DB::update(
                "UPDATE orders SET prev_status = ?, status = 'postponed', status_since = ? WHERE id = ?",
                [$order['status'], $now, (int) $order['id']]
            );
            // تغيير حالة → «مؤجل»
            $this->broadcastOrder((int) $order['id']);

            return (int) $order['id'];
        });

        return self::orderOut($orderId);
    }

    /**
     * POST /api/orders/{id}/unpostpone — orders_unpostpone · أدوار: branch, admin, callcenter
     *
     * فك التأجيل — الرجوع للحالة السابقة، و`prev_status` بيترجّع NULL.
     */
    public function unpostpone(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $now = WireTime::nowDb();

        $orderId = DB::transaction(function () use ($actor, $id, $now): int {
            $order = self::lockOrderRow($id);
            if (! $order) {
                throw new ApiException('الأوردر ده مابقاش موجود — اعمل تحديث للصفحة', 409);
            }
            self::guardBranch($actor, $order);
            if ($order['status'] !== 'postponed') {
                throw new ApiException('الأوردر مش مؤجل', 409);
            }
            DB::update(
                'UPDATE orders SET status = ?, prev_status = NULL, status_since = ? WHERE id = ?',
                [$order['prev_status'] ?: 'processing', $now, (int) $order['id']]
            );
            // تغيير حالة → الرجوع للحالة السابقة
            $this->broadcastOrder((int) $order['id']);

            return (int) $order['id'];
        });

        return self::orderOut($orderId);
    }

    /* ═══════════════════════════════════════════════════════════════
       تسوية الفلوس — moneySettled (الفرع بيسوّي فلوس أوردرات متسلّمة)
    ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /api/orders/{id}/settle-money — orders_settle_money · أدوار: branch, admin
     *
     * 🔴 فلوس: علم `money_settled` بس — مفيش أي حساب. التسوية متاحة
     * للأوردرات **المتسلّمة بس**، والعملية idempotent (إعادة النداء بتكتب 1
     * تاني من غير أثر).
     */
    public function settleMoney(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();

        $orderId = DB::transaction(function () use ($actor, $id): int {
            $order = self::lockOrderRow($id);
            if (! $order) {
                throw new ApiException('الأوردر ده مابقاش موجود — اعمل تحديث للصفحة', 409);
            }
            self::guardBranch($actor, $order);
            if ($order['status'] !== 'delivered') {
                throw new ApiException('التسوية متاحة للأوردرات المتسلّمة بس', 409);
            }
            /* 📡 **مفيش بثّ هنا بالقصد.** `money_settled` علم مكتب خلفي —
               مش حالة ولا تعيين، ومش جوه حمولة `OrderChanged` أصلًا. مسارات
               التسوية كلها (دي و`settleMoneyBulk` وحلقة `$pending` في
               `BoardController::settlePilotMoney`) سايبة للاستطلاع:
               `orders.updated_at` فيها `ON UPDATE current_timestamp(3)`
               فأي UPDATE بيتمسك بـ`?since=` من غير أي زيادة. */
            DB::update('UPDATE orders SET money_settled = 1 WHERE id = ?', [(int) $order['id']]);

            return (int) $order['id'];
        });

        return self::orderOut($orderId);
    }

    /**
     * POST /api/orders/settle-money-bulk — orders_settle_money_bulk · أدوار: branch, admin
     *
     * تسوية جماعية بقائمة `orderIds` أو بـ`pilotId` (كل المتسلّم غير
     * المتسوّي بتاع الطيار — مودال تسوية إنهاء الوردية في اللوحة القديمة).
     *
     * 🔴 فلوس + قفل: لو جه `pilotId` بيتعمل `SELECT ... FOR UPDATE` على
     * صفوف الطيار الأول **جوه نفس المعاملة**، وبعدين UPDATE واحد. القفل ده
     * هو اللي بيمنع تسويتين متوازيتين تعدّوا نفس الأوردر مرتين.
     *
     * ⚠️ `pilotId` **بيدوس على** `orderIds` لو الاتنين اتبعتوا — سلوك الأصل.
     * ⚠️ `branch_id` بيتحط في الـSQL بالتضمين النصي (بعد cast لـint) في
     * الاستعلام الأول — منقول بالحرف. الـcast هو اللي بيمنع الحقن.
     * ⚠️ الرد `settled` = عدد الصفوف اللي **اتغيّرت فعلًا**، فأوردر متسوّي
     * قبل كده مابيتعدّش.
     */
    public function settleMoneyBulk(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        $idsIn = $request->input('orderIds');
        $ids = is_array($idsIn)
            ? array_values(array_filter(array_map('intval', $idsIn)))
            : [];
        $pilotId = (int) ($request->input('pilotId') ?? 0);
        if (! $ids && ! $pilotId) {
            throw new ApiException('حدد أوردرات أو طيارًا للتسوية');
        }

        try {
            [$count, $ids] = DB::transaction(function () use ($actor, $ids, $pilotId): array {
                if ($pilotId) {
                    $rows = DB::select(
                        "SELECT id FROM orders
                         WHERE pilot_id = ? AND status = 'delivered' AND money_settled = 0"
                        . ($actor->role === 'branch' ? ' AND branch_id = ' . (int) $actor->branchId : '')
                        . ' FOR UPDATE',
                        [$pilotId]
                    );
                    $ids = array_map(fn ($r) => (int) $r->id, $rows);
                }
                /* 📡 مفيش بثّ — بالإضافة لسبب `settleMoney` فوق، الـUPDATE ده
                   **مشروط** (`AND status='delivered' AND money_settled=0`)
                   وبيرجّع عدد بس، فمش عارفين **أنهي** صفوف اتغيّرت فعلًا من
                   غير استعلام زيادة. والقايمة مفتوحة الطول (تقفيلة وردية
                   كاملة)، فالبثّ كان هيبقى دفعة أحداث لكل ضغطة زرار. */
                $count = 0;
                if ($ids) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $sql = "UPDATE orders SET money_settled = 1
                            WHERE id IN ({$ph}) AND status = 'delivered' AND money_settled = 0";
                    $params = $ids;
                    if ($actor->role === 'branch') {
                        $sql .= ' AND branch_id = ?';
                        $params[] = (int) $actor->branchId;
                    }
                    $count = DB::update($sql, $params);
                }

                return [$count, $ids];
            });
        } catch (QueryException $e) {
            report($e);
            throw new ApiException('التسوية ما تمّتش — جرّب تاني', 500);
        }

        return ApiResponse::out(['ok' => true, 'settled' => $count, 'orderIds' => $ids]);
    }

    /* ═══════════════════════════════════════════════════════════════
       أدوات داخلية — المقابل لأدوات orders.php الداخلية
    ═══════════════════════════════════════════════════════════════ */

    /** جلب صف أوردر بالـ id الرقمي أو legacy_key (مع قفل اختياري جوه معاملة) */
    private static function lockOrderRow(int|string $idOrKey, bool $forUpdate = true): ?array
    {
        $col = (is_int($idOrKey) || ctype_digit((string) $idOrKey)) ? 'id' : 'legacy_key';
        $sql = "SELECT * FROM orders WHERE {$col} = ? LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');

        $row = DB::select($sql, [$idOrKey])[0] ?? null;

        return $row !== null ? (array) $row : null;
    }

    /** فرض نطاق الفرع: مشرف الفرع مايلمسش أوردرات فرع تاني (صلاحية API-level) */
    private static function guardBranch(Actor $actor, array $order): void
    {
        if ($actor->role === 'branch'
            && (int) $order['branch_id'] !== (int) ($actor->branchId ?? 0)) {
            throw ApiException::forbidden('الأوردر ده تابع لفرع تاني');
        }
    }

    /** الوردية المفتوحة للطيار (findActivePilotShift) — null لو ملوش */
    private static function activeShiftId(int $pilotId): ?int
    {
        $id = DB::select(
            "SELECT id FROM shifts WHERE pilot_id = ? AND status = 'active'
             ORDER BY started_at DESC LIMIT 1",
            [$pilotId]
        )[0]->id ?? null;

        return $id !== null ? (int) $id : null;
    }

    /** رصّ الدور بعد خروج طيار (shiftQueueAfterRemoval) */
    private static function queueCompact(int $branchId, ?int $removedNo): void
    {
        if (! $removedNo) {
            return;
        }

        DB::update(
            "UPDATE pilots SET queue_no = queue_no - 1
             WHERE assigned_branch_id = ? AND status = 'waiting' AND queue_no > ?",
            [$branchId, $removedNo]
        );
    }

    /**
     * الطيار بيتمنع من لمس أوردر مش محمّل عليه — orders_guard_pilot().
     *
     * ⚠️ الرسالة هنا «مش **محمّل** عليك» — مختلفة عن رسالة `show()` اللي
     * هي «مش **متحمّل** عليك». الاتنين حرفيين من الأصل، متوحّدوش.
     */
    private static function guardPilot(Actor $actor, array $order): void
    {
        if ($actor->role !== 'pilot') {
            return;
        }
        $pid = DB::table('users')->where('id', $actor->userId)->value('pilot_id');
        $pid = $pid !== null ? (int) $pid : null;
        if ($pid === null || (int) ($order['pilot_id'] ?? 0) !== $pid) {
            throw ApiException::forbidden('الأوردر ده مش محمّل عليك');
        }
    }

    /**
     * آخر رقم دور + 1 في الفرع (getNextQueueNo) — **جوه معاملة**.
     *
     * الـ`FOR UPDATE` على الـaggregate مقصود: بيقفل نطاق صفوف المنتظرين
     * فطيارين اتنين مابياخدوش نفس رقم الدور تحت التزامن.
     */
    private static function nextQueueNo(int $branchId): int
    {
        $row = DB::select(
            "SELECT COALESCE(MAX(queue_no), 0) + 1 AS n FROM pilots
             WHERE assigned_branch_id = ? AND status = 'waiting' FOR UPDATE",
            [$branchId]
        );

        return (int) ($row[0]->n ?? 0);
    }

    /**
     * ضبط حالة الطيار من أوردراته الفعلية الجارية (syncPilotStatus) —
     * مصدر لون الخريطة هو الأوردرات مش الحالة المخزنة (VOCAB بند 13.2 قاعدة 2).
     * بيتنده **جوه معاملة مفتوحة**.
     *
     * `on_leave` استثناء: الإجازة مابتتكسرش لا بتحميل ولا بتسليم.
     */
    private static function syncPilotStatus(?int $pilotId, string $nowDt, ?int $fallbackBranchId = null): void
    {
        if (! $pilotId) {
            return;
        }
        $pilotRow = DB::select('SELECT * FROM pilots WHERE id = ? FOR UPDATE', [$pilotId]);
        $pilot = $pilotRow ? (array) $pilotRow[0] : null;
        if (! $pilot || $pilot['status'] === 'on_leave') {
            return;
        }

        $busy = (int) (DB::select(
            "SELECT COUNT(*) AS n FROM orders WHERE pilot_id = ? AND status = 'delivering'",
            [$pilotId]
        )[0]->n ?? 0) > 0;

        $branchId = $pilot['assigned_branch_id'] !== null
            ? (int) $pilot['assigned_branch_id'] : $fallbackBranchId;

        if ($busy) {
            if ($pilot['status'] === 'delivering') {
                return;
            }
            DB::update(
                "UPDATE pilots SET status = 'delivering', queue_no = NULL, status_since = ? WHERE id = ?",
                [$nowDt, $pilotId]
            );
            if ($branchId && $pilot['queue_no'] !== null) {
                self::queueCompact($branchId, (int) $pilot['queue_no']);
            }
        } else {
            if ($pilot['status'] === 'waiting' || ! $branchId) {
                return;
            }
            $next = self::nextQueueNo($branchId);
            DB::update(
                "UPDATE pilots SET status = 'waiting', queue_no = ?, status_since = ? WHERE id = ?",
                [$next, $nowDt, $pilotId]
            );
        }
    }

    /**
     * جوهر الحجز الذري — orders_claim_core() · بيتنده **جوه معاملة مفتوحة**.
     *
     * 🔴 ترتيب الجُمل هنا هو الصح نفسه، ممنوع يتغيّر:
     *   1. قفل صف الأوردر (`FOR UPDATE`)
     *   2. حارس الفرع (بيرمي 403 — بيقطع الطلب مش بيتحوّل لـ409)
     *   3. فحص «متحمّل على طيار تاني» بالرسالة الحرفية
     *   4. فحص الحالة المسموحة (قيد التنفيذ + لم يتم التوصيل = إعادة تحميل مشروعة)
     *   5. قفل صف الطيار (`FOR UPDATE`) وفحص إتاحته
     *   6. الوردية المفتوحة
     *   7. **UPDATE مشروط بالحالة** — الحجز الذري نفسه: لو صف تاني كسبنا
     *      بيرجّع 0 صف، وساعتها بنعيد قراية الصف عشان نطلّع اسم الطيار اللي
     *      كسب في الرسالة. الشرط ده هو اللي بيمنع تحميل نفس الأوردر مرتين.
     *   8. إزاحة الطيار من الدور لو كان منتظر
     *
     * بيرجّع ['ok','orderId','warning'] أو بيرمي ApiException بـ409
     * (ما عدا حارس الفرع = 403).
     */
    private static function claimCore(Actor $actor, int|string $orderIdOrKey, int $pilotId, string $now): array
    {
        $order = self::lockOrderRow($orderIdOrKey);
        if (! $order) {
            throw new ApiException('الأوردر ده مابقاش موجود — اعمل تحديث للصفحة', 409);
        }
        self::guardBranch($actor, $order);

        // الرسالة الحرفية من الكود القديم (VOCAB بند 13.1)
        if ($order['status'] === 'delivering') {
            throw new ApiException(
                'الأوردر ده اتحمّل على ' . ($order['pilot_name'] ?: 'طيار تاني') . ' من ثانية — اعمل تحديث للصفحة',
                409
            );
        }
        // المسموح تحميله: قيد التنفيذ + لم يتم التوصيل (إعادة تحميل مشروعة)
        if (! in_array($order['status'], ['processing', 'undelivered'], true)) {
            throw new ApiException('حالة الأوردر الحالية لا تسمح بالتحميل', 409);
        }

        $pilotRow = DB::select('SELECT * FROM pilots WHERE id = ? FOR UPDATE', [$pilotId]);
        if (! $pilotRow) {
            throw new ApiException('الطيار غير موجود', 409);
        }
        $pilot = (array) $pilotRow[0];
        if (! in_array($pilot['status'], ['waiting', 'delivering'], true)) {
            throw new ApiException('الطيار غير متاح للتحميل حاليًا', 409);
        }

        $shiftId = self::activeShiftId($pilotId);

        // الحجز الذري: UPDATE مشروط بالحالة — لو صف تاني كسبنا هيرجع 0 صف
        $affected = DB::update(
            "UPDATE orders
             SET pilot_id = ?, pilot_name = ?, shift_id = ?,
                 status = 'delivering', status_since = ?, current_pilot_since = ?
             WHERE id = ? AND status IN ('processing','undelivered')",
            [$pilotId, $pilot['name'], $shiftId, $now, $now, (int) $order['id']]
        );
        if ($affected === 0) {
            $cur = self::lockOrderRow((int) $order['id']);
            throw new ApiException(
                'الأوردر ده اتحمّل على ' . (($cur['pilot_name'] ?? '') ?: 'طيار تاني') . ' من ثانية — اعمل تحديث للصفحة',
                409
            );
        }

        // كان في الانتظار → بيوصّل ويُزاح من الدور. كان بيوصّل → تحميل إضافي بس.
        if ($pilot['status'] === 'waiting') {
            DB::update(
                "UPDATE pilots SET status = 'delivering', queue_no = NULL, status_since = ? WHERE id = ?",
                [$now, $pilotId]
            );
            $branchId = $pilot['assigned_branch_id'] !== null
                ? (int) $pilot['assigned_branch_id'] : (int) ($actor->branchId ?? 0);
            if ($branchId && $pilot['queue_no'] !== null) {
                self::queueCompact($branchId, (int) $pilot['queue_no']);
            }
        }

        return [
            'ok'      => true,
            'orderId' => (int) $order['id'],
            'warning' => $shiftId === null
                ? '⚠️ ' . $pilot['name'] . ' مالوش وردية مفتوحة — الأوردر مش هيتحسب في تقفيلة وردية'
                : null,
        ];
    }

    /** إخراج الأوردر الكامل بالشكل القديم — orders_out() */
    private static function orderOut(int $orderId, array $extra = []): JsonResponse
    {
        $order = OrderWire::full($orderId);

        return ApiResponse::out(array_merge(['ok' => true, 'order' => $order], $extra));
    }

    /** المقابل لـ `isset($b[k]) && $b[k] !== '' && $b[k] !== null ? (int)$b[k] : null` */
    private static function intOrNull(mixed $v): ?int
    {
        return $v !== null && $v !== '' ? (int) $v : null;
    }

    /** المقابل لـ `isset($b[k]) && $b[k] !== '' ? (float)$b[k] : null` */
    private static function floatOrNull(mixed $v): ?float
    {
        return $v !== null && $v !== '' ? (float) $v : null;
    }

    /** المقابل لـ `trim((string)($b[k] ?? '')) ?: null` */
    private static function trimOrNull(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s !== '' ? $s : null;
    }

    /** pilot_id المرتبط بحساب الجلسة — null لو الحساب مش مربوط بطيار */
    private function sessionPilotId(Actor $actor): ?int
    {
        $pid = DB::table('users')->where('id', $actor->userId)->value('pilot_id');

        return $pid !== null ? (int) $pid : null;
    }
}
