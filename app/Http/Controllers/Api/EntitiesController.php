<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Support\Actor;
use App\Support\ApiResponse;
use App\Support\PollableList;
use App\Support\Vocab;
use App\Support\WireTime;
use App\Wire\CoreWire;
use App\Wire\TrustWire;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * الكيانات الأساسية — نقل حرفي لمسارات القراءة من api/routes/entities.php.
 *
 * الاستعلامات هنا **مكتوبة خام زي الأصل** مش Eloquent. السبب: ترتيب الأعمدة
 * في الـSELECT وأسماء الأعمدة المشتقة من الـjoin (زي `delivery_branch_name`)
 * جزء من عقد طبقة السلك — `CoreWire` بيقراها بالاسم ده بالظبط.
 */
class EntitiesController
{
    /* ═══════════════════════════════════════════════════════════
       الفروع
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/branches
     *
     * 🔒 المسار ده بينده عليه **المحل والعميل كمان** مش الموظفين بس — عشان
     * اسم الفرع المسؤول و`paused`/`failoverBranchId` (تحويل الأوردر لو الفرع
     * موقوف). فمنقدرش نقفله على الموظفين. اللي اتقنّع هو `manager` بس
     * (اسم مدير الفرع = بيانات موظفين). الحقل باقي باسمه — دستور الـAPI.
     */
    public function branchesList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $isStaff = $actor->isStaff();

        $rows = DB::select(
            'SELECT b.*, f.name AS failover_branch_name
               FROM branches b
               LEFT JOIN branches f ON f.id = b.failover_branch_id
              ORDER BY b.id'
        );

        // مناطق كل الفروع مرة واحدة
        $areasByBranch = [];
        foreach (DB::select('SELECT branch_id, area_name FROM branch_areas ORDER BY id') as $a) {
            $areasByBranch[(int) $a->branch_id][] = $a->area_name;
        }

        $items = [];
        foreach ($rows as $row) {
            $item = CoreWire::branch($row, $areasByBranch[(int) $row->id] ?? []);
            if (! $isStaff) {
                $item['manager'] = null;
            }
            $items[] = $item;
        }

        return PollableList::items($items);
    }

    /* ═══════════════════════════════════════════════════════════
       المناطق
    ═══════════════════════════════════════════════════════════ */

    /** GET /api/zones */
    public function zonesList(Request $request): JsonResponse
    {
        $request->actorOrFail();

        $rows = DB::select(
            'SELECT z.*, d.name AS delivery_branch_name, s.name AS source_branch_name
               FROM zones z
               JOIN branches d ON d.id = z.delivery_branch_id
               LEFT JOIN branches s ON s.id = z.source_branch_id
              ORDER BY z.area_name'
        );

        return PollableList::items(array_map(
            fn ($r) => CoreWire::zone($r),
            $rows
        ));
    }

    /* ═══════════════════════════════════════════════════════════
       الطيارين
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/pilots
     *
     * 🔒 كان `require_auth()` بس، فأي حساب مسجّل (محل/عميل/طيار) كان يقدر
     * يسحب روستر الأسطول كامل: تليفونات، مرتبات، عمولات، رصيد عُهدة، عنوان،
     * وموقع GPS حي. المسار بتناديه لوحات الموظفين بس.
     */
    public function pilotsList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        $sql = "SELECT p.*, b.name AS assigned_branch_name, u.username,
                       (SELECT COUNT(*) FROM orders o
                         WHERE o.pilot_id = p.id AND o.status = 'delivering') AS active_orders
                  FROM pilots p
                  LEFT JOIN branches b ON b.id = p.assigned_branch_id
                  LEFT JOIN users u ON u.pilot_id = p.id";

        $vals = [];
        $branchId = $request->query('branchId');
        if ($branchId !== null && $branchId !== '') {
            $sql .= ' WHERE p.assigned_branch_id = ?';
            $vals[] = (int) $branchId;
        }
        $sql .= ' ORDER BY p.name';

        // مشرف الطيارين بياخد الكارت بلا عهدة/مرتب/عمولة — شوف CoreWire::pilotFor
        return PollableList::items(array_map(
            fn ($r) => CoreWire::pilotFor($actor->role, $r),
            DB::select($sql, $vals)
        ));
    }

    /* ═══════════════════════════════════════════════════════════
       المستخدمين
    ═══════════════════════════════════════════════════════════ */

    /** GET /api/users — الأدمن بس */
    public function usersList(Request $request): JsonResponse
    {
        $rows = DB::select(
            'SELECT u.*, b.name AS branch_name
               FROM users u
               LEFT JOIN branches b ON b.id = u.branch_id
              ORDER BY u.id'
        );

        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        $perms = $this->usersPerms($ids);

        $items = [];
        foreach ($rows as $row) {
            $p = $perms[(int) $row->id] ?? [];
            $items[] = CoreWire::user($row, $p['apps'] ?? [], $p['pages'] ?? []);
        }

        return PollableList::items($items);
    }

    /**
     * صلاحيات مجموعة مستخدمين مرة واحدة: [userId => ['apps'=>[], 'pages'=>[]]]
     * استعلامين مش استعلام لكل مستخدم — زي الأصل.
     */
    private function usersPerms(array $userIds): array
    {
        if (! $userIds) {
            return [];
        }

        $out = [];
        foreach (DB::table('user_app_permissions')->whereIn('user_id', $userIds)->orderBy('id')->get() as $r) {
            $out[(int) $r->user_id]['apps'][] = $r->app;
        }
        foreach (DB::table('user_page_permissions')->whereIn('user_id', $userIds)->orderBy('id')->get() as $r) {
            $out[(int) $r->user_id]['pages'][$r->app][$r->page] = true;
        }

        return $out;
    }

    /* ═══════════════════════════════════════════════════════════
       إيميلات الإدارة
    ═══════════════════════════════════════════════════════════ */

    /** GET /api/admin-emails — الأدمن بس */
    public function adminEmailsList(): JsonResponse
    {
        return PollableList::items(array_map(fn ($r) => [
            'id'        => (int) $r->id,
            'email'     => $r->email,
            'createdAt' => WireTime::toWire($r->created_at),
        ], DB::select('SELECT * FROM admin_emails ORDER BY id')));
    }

    /* ═══════════════════════════════════════════════════════════
       المُرسِلين والمستلمين (الدفاتر)
    ═══════════════════════════════════════════════════════════ */

    /** GET /api/senders */
    public function sendersList(Request $request): JsonResponse
    {
        return $this->partyList($request, 'senders');
    }

    /** GET /api/receivers */
    public function receiversList(Request $request): JsonResponse
    {
        return $this->partyList($request, 'receivers');
    }

    /**
     * 🔒 خصوصية: ده تفريغ لدفتر العملاء كله (لحد 5000 صف باسم وتليفون
     * وعنوان). موظفين بس — أي دور تاني كان يسحب الدفتر بنداء واحد.
     *
     * `?q=` بحث سيرفر-سايد: قبل كده الواجهات كانت بتنزّل الدفتر كله عشان
     * قائمة اقتراحات بتعرض 10. وأسوأ — بعد 5000 صف كان البحث بيبطّل يلاقي
     * الأقدم **بالصمت** لأن السقف بيقص من الآخر.
     *
     * الـdelta على `updated_at` مش `created_at`: تصحيح اسم أو تليفون لعميل
     * موجود عمره ما كان بيطلع في الدلتا لأن `created_at` قديم.
     */
    private function partyList(Request $request, string $table): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if ($q !== '') {
            if (mb_strlen($q) < 2) {
                return PollableList::items([]);
            }
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $rows = DB::select(
                "SELECT * FROM {$table}
                  WHERE name LIKE ? OR phone1 LIKE ? OR phone2 LIKE ?
                  ORDER BY id DESC LIMIT 20",
                [$like, $like, $like]
            );

            return PollableList::items($this->serParty($table, $rows));
        }

        $sinceRaw = $request->query('since');
        $since = ($sinceRaw !== null && $sinceRaw !== '') ? max(0, (int) $sinceRaw) : 0;

        $sql = "SELECT * FROM {$table}";
        $params = [];
        if ($since > 0) {
            $sql .= ' WHERE updated_at > FROM_UNIXTIME(? / 1000)';
            $params[] = $since;
        }
        $sql .= ' ORDER BY id DESC LIMIT 5000';

        $rows = DB::select($sql, $params);
        $serverNow = PollableList::serverNowMs();

        if ($since > 0 && ! $rows) {
            return PollableList::unchanged($serverNow);
        }

        return PollableList::items($this->serParty($table, $rows), $serverNow);
    }

    private function serParty(string $table, array $rows): array
    {
        return $table === 'senders'
            ? array_map(fn ($r) => CoreWire::sender($r), $rows)
            : array_map(fn ($r) => CoreWire::receiver($r), $rows);
    }

    /* ═══════════════════════════════════════════════════════════
       دفتر جهات اتصال المحل
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/store-contacts
     * 🔒 المحل بيشوف دفتره هو بس. غيره لازم يكون موظف ويحدد `?store=`.
     */
    public function storeContactsList(Request $request): JsonResponse
    {
        $owner = $this->storeContactsOwner($request);

        return PollableList::items(array_map(
            fn ($r) => CoreWire::storeContact($r),
            DB::select('SELECT * FROM store_contacts WHERE store_username = ? ORDER BY name', [$owner])
        ));
    }

    private function storeContactsOwner(Request $request): string
    {
        $actor = $request->actorOrFail();

        if ($actor->role === 'store') {
            return $actor->username;
        }

        if (! $actor->isStaff()) {
            throw ApiException::forbidden();
        }

        $store = trim((string) $request->query('store', ''));
        if ($store === '') {
            // النص حرفي زي الأصل — بوابة التطابق بتقارن الرسالة نصًا
            throw new ApiException('اسم مستخدم المحل مطلوب (?store=)');
        }

        return $store;
    }

    /* ═══════════════════════════════════════════════════════════
       مصر — قايمة عامة بلا مصادقة
    ═══════════════════════════════════════════════════════════ */

    /** GET /api/egypt — بتتطلب في صفحات التتبع العام كمان، فمفيش require_auth */
    public function egyptList(): JsonResponse
    {
        $govRows = DB::select('SELECT id, name FROM egypt_governorates ORDER BY id');
        $cityRows = DB::select(
            'SELECT c.name, g.name AS gov FROM egypt_cities c
               JOIN egypt_governorates g ON g.id = c.governorate_id ORDER BY c.id'
        );

        $cities = [];
        foreach ($cityRows as $c) {
            $cities[$c->gov][] = $c->name;
        }

        return ApiResponse::out([
            'ok'           => true,
            'governorates' => array_map(fn ($g) => $g->name, $govRows),
            'cities'       => $cities ?: new stdClass(),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       ▓▓▓ مسارات الكتابة — نقل حرفي من api/routes/entities.php ▓▓▓
    ═══════════════════════════════════════════════════════════ */

    /* ── الفروع: إنشاء / تعديل ───────────────────────────────── */

    /**
     * POST /api/branches — الأدمن بس (entities_require_admin).
     *
     * معاملة عشان الفرع ومناطقه لازم يدخلوا مع بعض: لو صف branch_areas وقع،
     * الفرع نفسه مايفضلش موجود بمناطق ناقصة. الأصل كان بيعمل beginTransaction/
     * commit/rollBack يدوي — هنا DB::transaction بتعمل الrollback لوحدها لأن
     * ApiException استثناء مش exit.
     */
    public function branchesCreate(Request $request): JsonResponse
    {
        $b = $this->body($request);

        $name = trim((string) ($b['name'] ?? ''));
        $code = strtoupper(trim((string) ($b['code'] ?? '')));
        if ($name === '' || $code === '') {
            throw new ApiException('اسم الفرع وكوده مطلوبان');
        }
        if (! preg_match('/^[A-Z0-9]{2,6}$/', $code)) {
            throw new ApiException('كود الفرع 2-6 حروف/أرقام إنجليزية');
        }

        $areas = is_array($b['areas'] ?? null) ? $b['areas'] : [];

        try {
            $id = DB::transaction(function () use ($b, $name, $code, $areas): int {
                DB::insert(
                    'INSERT INTO branches (name, code, phone, manager, address, paused, failover_branch_id, created_at)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [
                        $name,
                        $code,
                        trim((string) ($b['phone'] ?? '')) ?: null,
                        trim((string) ($b['manager'] ?? '')) ?: null,
                        trim((string) ($b['address'] ?? '')) ?: null,
                        ! empty($b['paused']) ? 1 : 0,
                        isset($b['failoverBranchId']) && $b['failoverBranchId'] !== null
                            ? $this->intId($b['failoverBranchId']) : null,
                        WireTime::nowDb(),
                    ]
                );
                $id = (int) DB::getPdo()->lastInsertId();

                // INSERT IGNORE عشان تكرار اسم منطقة في نفس النداء مايكسرش الطلب
                foreach ($areas as $area) {
                    $area = trim((string) $area);
                    if ($area !== '') {
                        DB::insert(
                            'INSERT IGNORE INTO branch_areas (branch_id, area_name, created_at) VALUES (?,?,?)',
                            [$id, $area, WireTime::nowDb()]
                        );
                    }
                }

                return $id;
            });
        } catch (QueryException $e) {
            // 1062 = Duplicate entry — الكود عليه UNIQUE
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new ApiException('كود الفرع مستخدم قبل كده');
            }
            Log::error('branches_create: ' . $e->getMessage());
            throw new ApiException('خطأ أثناء حفظ الفرع', 500);
        }

        return ApiResponse::out(['ok' => true, 'id' => $id]);
    }

    /**
     * PUT /api/branches/{id} — الأدمن بس.
     *
     * ⚠️ منقول بالحرف: `($v === '' && $col !== 'name') ? null : $v` — يعني
     * `name: ""` بيتخزّن نص فاضي مش null ومفيش تحقق عليه (بعكس الإنشاء اللي
     * بيرفضه). باج في الأصل، منقول زي ما هو.
     *
     * `areas` بتتستبدل بالكامل (DELETE ثم INSERT) — مش دمج. جوه نفس المعاملة
     * عشان الفرع مايفضلش بلا مناطق لو الإدخال وقع في النص.
     */
    public function branchesUpdate(Request $request, string $id): JsonResponse
    {
        $id = $this->intId($id);
        $b = $this->body($request);

        $branch = DB::select('SELECT * FROM branches WHERE id = ?', [$id])[0] ?? null;
        if (! $branch) {
            throw ApiException::notFound('الفرع غير موجود');
        }

        $fields = [];
        $vals = [];
        foreach (['name' => 'name', 'phone' => 'phone', 'manager' => 'manager', 'address' => 'address'] as $wire => $col) {
            if (array_key_exists($wire, $b)) {
                $fields[] = "$col = ?";
                $v = trim((string) ($b[$wire] ?? ''));
                $vals[] = ($v === '' && $col !== 'name') ? null : $v;
            }
        }
        if (array_key_exists('code', $b)) {
            $code = strtoupper(trim((string) $b['code']));
            if (! preg_match('/^[A-Z0-9]{2,6}$/', $code)) {
                throw new ApiException('كود الفرع 2-6 حروف/أرقام إنجليزية');
            }
            $fields[] = 'code = ?';
            $vals[] = $code;
        }
        if (array_key_exists('paused', $b)) {
            $fields[] = 'paused = ?';
            $vals[] = ! empty($b['paused']) ? 1 : 0;
        }
        if (array_key_exists('failoverBranchId', $b)) {
            $fo = $b['failoverBranchId'] !== null ? $this->intId($b['failoverBranchId']) : null;
            // الفرع البديل لنفسه = حلقة تحويل لا نهائية وقت الإيقاف
            if ($fo === $id) {
                throw new ApiException('الفرع البديل ميقدرش يكون هو نفس الفرع');
            }
            $fields[] = 'failover_branch_id = ?';
            $vals[] = $fo;
        }

        try {
            DB::transaction(function () use ($b, $fields, $vals, $id): void {
                if ($fields) {
                    $vals[] = $id;
                    DB::update('UPDATE branches SET ' . implode(', ', $fields) . ' WHERE id = ?', $vals);
                }
                if (array_key_exists('areas', $b) && is_array($b['areas'])) {
                    DB::delete('DELETE FROM branch_areas WHERE branch_id = ?', [$id]);
                    foreach ($b['areas'] as $area) {
                        $area = trim((string) $area);
                        if ($area !== '') {
                            DB::insert(
                                'INSERT IGNORE INTO branch_areas (branch_id, area_name, created_at) VALUES (?,?,?)',
                                [$id, $area, WireTime::nowDb()]
                            );
                        }
                    }
                }
            });
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new ApiException('كود الفرع مستخدم قبل كده');
            }
            Log::error('branches_update: ' . $e->getMessage());
            throw new ApiException('خطأ أثناء تعديل الفرع', 500);
        }

        return ApiResponse::ok();
    }

    /* ── المناطق: إنشاء / تعديل / حذف ────────────────────────── */

    /**
     * POST /api/zones — موظف (admin/branch/callcenter).
     *
     * `sourceBranchId` لو مش متبعت: حساب الفرع بيتبصم بفرعه هو تلقائيًا،
     * وأي دور تاني بيسيبها NULL (= إضافة يدوية). ده اللي بيخلي منطقة أضافها
     * مشرف فرع تفضل في نطاقه في التعديل بعد كده.
     */
    public function zonesCreate(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $b = $this->body($request);

        $areaName = trim((string) ($b['areaName'] ?? ''));
        if ($areaName === '') {
            throw new ApiException('اسم المنطقة مطلوب');
        }
        $deliveryBranchId = $this->intId($b['deliveryBranchId'] ?? 0);
        if ($this->branchName($deliveryBranchId) === null) {
            throw ApiException::notFound('فرع التوصيل غير موجود');
        }
        $price = (float) ($b['price'] ?? 0);
        if ($price < 0) {
            throw new ApiException('السعر غير صالح');
        }
        $sourceBranchId = isset($b['sourceBranchId']) && $b['sourceBranchId'] !== null
            ? $this->intId($b['sourceBranchId'])
            : ($actor->role === 'branch' ? $actor->branchId : null);

        DB::insert(
            'INSERT INTO zones (area_name, price, delivery_branch_id, source_branch_id, created_at) VALUES (?,?,?,?,?)',
            [$areaName, $price, $deliveryBranchId, $sourceBranchId, WireTime::nowDb()]
        );

        return ApiResponse::out(['ok' => true, 'id' => (int) DB::getPdo()->lastInsertId()]);
    }

    /**
     * PUT /api/zones/{id} — موظف، ومشرف الفرع مقفول على مناطق فرعه.
     * (تعليق الأصل: «كان أي مشرف يقدر يعدّل منطقة أي فرع».)
     *
     * `sourceBranchId` **مش قابل للتعديل** من هنا عن قصد — لو كان، مشرف الفرع
     * كان يقدر ينقل المنطقة لبره نطاقه ويقفل على نفسه/يفتح لغيره.
     */
    public function zonesUpdate(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $id = $this->intId($id);
        $b = $this->body($request);

        $zone = DB::select('SELECT * FROM zones WHERE id = ?', [$id])[0] ?? null;
        if (! $zone) {
            throw ApiException::notFound('المنطقة غير موجودة');
        }
        $this->assertZoneInScope($actor, (array) $zone);

        $fields = [];
        $vals = [];
        if (array_key_exists('areaName', $b)) {
            $areaName = trim((string) $b['areaName']);
            if ($areaName === '') {
                throw new ApiException('اسم المنطقة مطلوب');
            }
            $fields[] = 'area_name = ?';
            $vals[] = $areaName;
        }
        if (array_key_exists('price', $b)) {
            $price = (float) $b['price'];
            if ($price < 0) {
                throw new ApiException('السعر غير صالح');
            }
            $fields[] = 'price = ?';
            $vals[] = $price;
        }
        if (array_key_exists('deliveryBranchId', $b)) {
            $dbid = $this->intId($b['deliveryBranchId']);
            if ($this->branchName($dbid) === null) {
                throw ApiException::notFound('فرع التوصيل غير موجود');
            }
            $fields[] = 'delivery_branch_id = ?';
            $vals[] = $dbid;
        }
        if (! $fields) {
            throw new ApiException('مفيش حاجة تتعدل');
        }

        $vals[] = $id;
        DB::update('UPDATE zones SET ' . implode(', ', $fields) . ' WHERE id = ?', $vals);

        return ApiResponse::ok();
    }

    /**
     * DELETE /api/zones/{id} — موظف.
     *
     * ⚠️ **مفيش فحص نطاق فرع هنا** (بعكس التعديل): مشرف أي فرع يقدر يمسح
     * منطقة أي فرع. الفرق ده موجود في الأصل ومنقول زي ما هو — مش تصحيح.
     *
     * 1451 = FK constraint — المنطقة متعلّق بيها أوردرات/عملاء.
     */
    public function zonesDelete(string $id): JsonResponse
    {
        $id = $this->intId($id);

        try {
            $n = DB::delete('DELETE FROM zones WHERE id = ?', [$id]);
            if ($n === 0) {
                throw ApiException::notFound('المنطقة غير موجودة');
            }
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1451) {
                throw new ApiException('المنطقة مستخدمة في بيانات تانية — ممنوع حذفها');
            }
            Log::error('zones_delete: ' . $e->getMessage());
            throw new ApiException('خطأ أثناء حذف المنطقة', 500);
        }

        return ApiResponse::ok();
    }

    /* ── الطيارين: إنشاء / تعديل / حذف ───────────────────────── */

    /** POST /api/pilots — موظف */
    public function pilotsCreate(Request $request): JsonResponse
    {
        $b = $this->stripPilotMoney($request, $this->body($request));

        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            throw new ApiException('اسم الطيار مطلوب');
        }

        // 🔴 نوع العمولة فلوس — لازم يفضل percent/fixed بس، شوف Support\Commission
        $commissionType = (string) ($b['commissionType'] ?? 'percent');
        if (! isset(Vocab::COMMISSION_TYPE_WIRE[$commissionType])) {
            throw new ApiException('نوع العمولة غير صالح (percent أو fixed)');
        }

        DB::insert(
            'INSERT INTO pilots (name, phone1, phone2, card_num, vehicle_no, address,
                                 commission_type, commission_value, monthly_salary, required_daily_hours,
                                 notes, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $name,
                trim((string) ($b['phone1'] ?? '')) ?: null,
                trim((string) ($b['phone2'] ?? '')) ?: null,
                trim((string) ($b['cardNum'] ?? '')) ?: null,
                trim((string) ($b['vehicleNo'] ?? '')) ?: null,
                trim((string) ($b['address'] ?? '')) ?: null,
                $commissionType,
                (float) ($b['commissionValue'] ?? 0),
                (float) ($b['monthlySalary'] ?? 0),
                isset($b['requiredDailyHours']) && $b['requiredDailyHours'] !== null
                    ? (float) $b['requiredDailyHours'] : null,
                trim((string) ($b['notes'] ?? '')) ?: null,
                WireTime::nowDb(),
            ]
        );

        return ApiResponse::out(['ok' => true, 'id' => (int) DB::getPdo()->lastInsertId()]);
    }

    /**
     * PUT /api/pilots/{id} — موظف. بيانات الطيار الأساسية بس.
     * الحالة/الطابور/الإذن ليهم مسارات التشغيل بتاعتهم (board/pilot) — عمدًا
     * مش من هنا عشان تعديل بيانات مايغيّرش حالة تشغيلية.
     */
    public function pilotsUpdate(Request $request, string $id): JsonResponse
    {
        $id = $this->intId($id);
        $b = $this->stripPilotMoney($request, $this->body($request));

        if (! (DB::select('SELECT * FROM pilots WHERE id = ?', [$id])[0] ?? null)) {
            throw ApiException::notFound('الطيار غير موجود');
        }

        $map = [
            'name'      => 'name',
            'phone1'    => 'phone1',
            'phone2'    => 'phone2',
            'cardNum'   => 'card_num',
            'vehicleNo' => 'vehicle_no',
            'address'   => 'address',
            'notes'     => 'notes',
        ];
        $fields = [];
        $vals = [];
        foreach ($map as $wire => $col) {
            if (array_key_exists($wire, $b)) {
                $v = trim((string) ($b[$wire] ?? ''));
                if ($wire === 'name' && $v === '') {
                    throw new ApiException('اسم الطيار مطلوب');
                }
                $fields[] = "$col = ?";
                $vals[] = $v === '' ? null : $v;
            }
        }
        if (array_key_exists('commissionType', $b)) {
            if (! isset(Vocab::COMMISSION_TYPE_WIRE[(string) $b['commissionType']])) {
                throw new ApiException('نوع العمولة غير صالح (percent أو fixed)');
            }
            $fields[] = 'commission_type = ?';
            $vals[] = (string) $b['commissionType'];
        }
        // 🔴 فلوس: القيمة بتتخزّن كما هي بلا حد أدنى/أقصى — زي الأصل بالحرف
        foreach (['commissionValue' => 'commission_value', 'monthlySalary' => 'monthly_salary'] as $wire => $col) {
            if (array_key_exists($wire, $b)) {
                $fields[] = "$col = ?";
                $vals[] = (float) $b[$wire];
            }
        }
        if (array_key_exists('requiredDailyHours', $b)) {
            $fields[] = 'required_daily_hours = ?';
            $vals[] = $b['requiredDailyHours'] !== null ? (float) $b['requiredDailyHours'] : null;
        }
        if (array_key_exists('assignedBranchId', $b)) {
            $abid = $b['assignedBranchId'] !== null ? $this->intId($b['assignedBranchId']) : null;
            if ($abid !== null && $this->branchName($abid) === null) {
                throw ApiException::notFound('الفرع غير موجود');
            }
            $fields[] = 'assigned_branch_id = ?';
            $vals[] = $abid;
        }
        if (! $fields) {
            throw new ApiException('مفيش حاجة تتعدل');
        }

        $vals[] = $id;
        DB::update('UPDATE pilots SET ' . implode(', ', $fields) . ' WHERE id = ?', $vals);

        return ApiResponse::ok();
    }

    /**
     * بيشيل حقول الفلوس من جسم إنشاء/تعديل الطيار لو اللي بينده
     * `pilot_supervisor`.
     *
     * 🔴 من غير ده كان قفل المسارات المالية بلا معنى: `PUT /api/pilots/{id}`
     * بيكتب `commission_value` و`monthly_salary` مباشرة على صف الطيار، يعني
     * مشرف الطيارين كان هيقدر يرفع عمولة أو مرتب من غير ما يعدّي على أي
     * مسار متعلّم 💰.
     *
     * الحقول **بتتشال بصمت مش بترمي 403**: الدور أصلًا مش شايفها في الرد
     * (CoreWire::pilotFor)، فوصولها في الجسم معناه لوحة بتبعت الشكل الكامل
     * مش محاولة صريحة. الإنشاء بيقع على الافتراضي (percent · 0 · 0) والأدمن
     * أو المحاسب هو اللي بيحط الأرقام بعدين.
     */
    private function stripPilotMoney(Request $request, array $b): array
    {
        if ($request->actorOrFail()->role !== 'pilot_supervisor') {
            return $b;
        }

        unset($b['commissionType'], $b['commissionValue'], $b['monthlySalary']);

        return $b;
    }

    /** DELETE /api/pilots/{id} — الأدمن بس (أضيق من الإنشاء/التعديل عن قصد) */
    public function pilotsDelete(string $id): JsonResponse
    {
        $id = $this->intId($id);

        try {
            $n = DB::delete('DELETE FROM pilots WHERE id = ?', [$id]);
            if ($n === 0) {
                throw ApiException::notFound('الطيار غير موجود');
            }
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1451) {
                throw new ApiException('الطيار مرتبط ببيانات (حساب/أوردرات/ورديات) — ممنوع حذفه');
            }
            Log::error('pilots_delete: ' . $e->getMessage());
            throw new ApiException('خطأ أثناء حذف الطيار', 500);
        }

        return ApiResponse::ok();
    }

    /* ── المستخدمين: إنشاء / تعديل / حظر / حذف ───────────────── */

    /**
     * POST /api/users — الأدمن بس.
     * الحساب + صلاحياته جوه معاملة واحدة: حساب اتخلق بصلاحيات ناقصة =
     * موظف بيدخل ويلاقي نص اللوحات مقفولة من غير ما حد يعرف ليه.
     */
    public function usersCreate(Request $request): JsonResponse
    {
        $b = $this->body($request);

        $username = trim((string) ($b['username'] ?? ''));
        $password = (string) ($b['password'] ?? '');
        $roleAr   = trim((string) ($b['role'] ?? ''));
        if ($username === '' || $password === '' || $roleAr === '') {
            throw new ApiException('اسم المستخدم والباسورد والدور مطلوبين');
        }
        if (mb_strlen($password) < 4) {
            throw new ApiException('الباسورد قصير جدًا');
        }
        // الدور بيتبعت عربي على السلك وبيتخزّن كود إنجليزي — التحويل في Vocab حصريًا
        $role = Vocab::roleToCode($roleAr);
        if (! isset(Vocab::ROLE_AR[$role])) {
            throw new ApiException('دور غير معروف: ' . $roleAr);
        }

        $branchId = isset($b['branchId']) && $b['branchId'] !== null ? $this->intId($b['branchId']) : null;
        if ($branchId !== null && $this->branchName($branchId) === null) {
            throw ApiException::notFound('الفرع غير موجود');
        }

        try {
            $id = DB::transaction(function () use ($b, $username, $password, $role, $branchId): int {
                DB::insert(
                    'INSERT INTO users (username, password_hash, role, name, branch_id, pilot_id, sender_id,
                                        shop_name, shop_phone, shop_phone2, shop_address, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $username,
                        password_hash($password, PASSWORD_DEFAULT),
                        $role,
                        trim((string) ($b['name'] ?? '')) ?: null,
                        $branchId,
                        isset($b['pilotId']) && $b['pilotId'] !== null ? $this->intId($b['pilotId']) : null,
                        isset($b['senderId']) && $b['senderId'] !== null ? $this->intId($b['senderId']) : null,
                        trim((string) ($b['shopName'] ?? '')) ?: null,
                        trim((string) ($b['shopPhone'] ?? '')) ?: null,
                        trim((string) ($b['shopPhone2'] ?? '')) ?: null,
                        trim((string) ($b['shopAddress'] ?? '')) ?: null,
                        WireTime::nowDb(),
                    ]
                );
                $id = (int) DB::getPdo()->lastInsertId();

                $this->writeUserPerms(
                    $id,
                    is_array($b['allowedApps'] ?? null) ? $b['allowedApps'] : null,
                    is_array($b['pagePerms'] ?? null) ? $b['pagePerms'] : null
                );

                return $id;
            });
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new ApiException('اسم المستخدم موجود قبل كده');
            }
            Log::error('users_create: ' . $e->getMessage());
            throw new ApiException('خطأ أثناء إنشاء الحساب', 500);
        }

        return ApiResponse::out(['ok' => true, 'id' => $id]);
    }

    /**
     * PUT /api/users/{id} — الأدمن بس، وحساب protected=1 محمي.
     *
     * ⚠️ الباسورد بيتغيّر **بس لو اتبعت وهو مش فاضي** — `password: ""` بيتجاهل
     * تمامًا بدل ما يمسح الهاش. مقصود: نماذج اللوحة بتبعت الحقل فاضي دايمًا.
     */
    public function usersUpdate(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $id = $this->intId($id);
        $this->fetchUserGuarded($id, $actor);
        $b = $this->body($request);

        $fields = [];
        $vals = [];
        if (array_key_exists('password', $b) && (string) $b['password'] !== '') {
            if (mb_strlen((string) $b['password']) < 4) {
                throw new ApiException('الباسورد قصير جدًا');
            }
            $fields[] = 'password_hash = ?';
            $vals[] = password_hash((string) $b['password'], PASSWORD_DEFAULT);
        }
        if (array_key_exists('role', $b)) {
            $role = Vocab::roleToCode(trim((string) $b['role']));
            if (! isset(Vocab::ROLE_AR[$role])) {
                // ⚠️ الرسالة هنا **بدون اسم الدور** بعكس الإنشاء — فرق في الأصل، منقول
                throw new ApiException('دور غير معروف');
            }
            $fields[] = 'role = ?';
            $vals[] = $role;
        }
        if (array_key_exists('name', $b)) {
            $fields[] = 'name = ?';
            $vals[] = trim((string) ($b['name'] ?? '')) ?: null;
        }
        if (array_key_exists('branchId', $b)) {
            $branchId = $b['branchId'] !== null ? $this->intId($b['branchId']) : null;
            if ($branchId !== null && $this->branchName($branchId) === null) {
                throw ApiException::notFound('الفرع غير موجود');
            }
            $fields[] = 'branch_id = ?';
            $vals[] = $branchId;
        }
        foreach (['pilotId' => 'pilot_id', 'senderId' => 'sender_id'] as $wire => $col) {
            if (array_key_exists($wire, $b)) {
                $fields[] = "$col = ?";
                $vals[] = $b[$wire] !== null ? $this->intId($b[$wire]) : null;
            }
        }
        foreach (['shopName' => 'shop_name', 'shopPhone' => 'shop_phone',
                  'shopPhone2' => 'shop_phone2', 'shopAddress' => 'shop_address'] as $wire => $col) {
            if (array_key_exists($wire, $b)) {
                $fields[] = "$col = ?";
                $vals[] = trim((string) ($b[$wire] ?? '')) ?: null;
            }
        }

        try {
            DB::transaction(function () use ($b, $fields, $vals, $id): void {
                if ($fields) {
                    $vals[] = $id;
                    DB::update('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?', $vals);
                }
                $this->writeUserPerms(
                    $id,
                    is_array($b['allowedApps'] ?? null) ? $b['allowedApps'] : null,
                    is_array($b['pagePerms'] ?? null) ? $b['pagePerms'] : null
                );
            });
        } catch (QueryException $e) {
            // مفيش فحص 1062 هنا (بعكس الإنشاء) — username مش قابل للتعديل أصلًا
            Log::error('users_update: ' . $e->getMessage());
            throw new ApiException('خطأ أثناء تعديل الحساب', 500);
        }

        return ApiResponse::ok();
    }

    /**
     * POST /api/users/{id}/block — {blocked: true/false}. الأدمن بس.
     *
     * 🔒 الأدمن مايقدرش يوقف حساب نفسه — من غير الشرط ده أدمن واحد يقدر يقفل
     * على نفسه بره النظام ومفيش طريق رجوع من الواجهة.
     *
     * `blocked_at`/`blocked_by` بيترجعوا NULL عند فك الحظر (مش بيفضلوا) —
     * يعني مفيش سجل تاريخي للحظر السابق. سلوك الأصل.
     */
    public function usersBlock(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $id = $this->intId($id);
        $this->fetchUserGuarded($id, $actor);
        if ($id === (int) $actor->userId) {
            throw new ApiException('متقدرش توقف حسابك انت');
        }

        $blocked = ! empty($this->body($request)['blocked']) ? 1 : 0;
        DB::update(
            'UPDATE users SET blocked = ?, blocked_at = ?, blocked_by = ? WHERE id = ?',
            [$blocked, $blocked ? WireTime::nowDb() : null, $blocked ? $actor->username : null, $id]
        );

        return ApiResponse::out(['ok' => true, 'blocked' => (bool) $blocked]);
    }

    /**
     * DELETE /api/users/{id} — الأدمن بس.
     *
     * 🔒 حساب protected=1 **ممنوع حذفه من أي حد بما فيهم الأدمن** — الفحص
     * الصريح ده بيجي بعد fetchUserGuarded (اللي بيسمح للأدمن يعدّي)، فالنتيجة
     * إن الحماية على الحذف مطلقة مش نسبية للدور.
     */
    public function usersDelete(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $id = $this->intId($id);
        $target = $this->fetchUserGuarded($id, $actor);
        if ((int) $target['protected'] === 1) {
            throw ApiException::forbidden('هذا الحساب محمي — ممنوع حذفه');
        }
        if ($id === (int) $actor->userId) {
            throw new ApiException('متقدرش تمسح حسابك انت');
        }

        DB::delete('DELETE FROM users WHERE id = ?', [$id]);

        return ApiResponse::ok();
    }

    /* ── إيميلات الإدارة: إضافة / حذف ────────────────────────── */

    /**
     * POST /api/admin-emails — الأدمن بس.
     *
     * ⚠️ أي استثناء قاعدة غير 1062 بيتعاد رميه (`throw $e`) بدل ما يتلفّ برسالة
     * خاصة — فبيوصل للمعالج العام ويرجع «خطأ في قاعدة البيانات» 500. منقول زي
     * ما هو (بعكس باقي المسارات هنا اللي ليها رسالة خاصة).
     */
    public function adminEmailsCreate(Request $request): JsonResponse
    {
        $email = mb_strtolower(trim((string) ($this->body($request)['email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('إيميل غير صالح');
        }

        try {
            DB::insert('INSERT INTO admin_emails (email, created_at) VALUES (?,?)', [$email, WireTime::nowDb()]);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new ApiException('الإيميل موجود قبل كده');
            }
            throw $e;
        }

        return ApiResponse::out(['ok' => true, 'id' => (int) DB::getPdo()->lastInsertId()]);
    }

    /**
     * DELETE /api/admin-emails/{id} — الأدمن بس.
     * ملاحظة الأصل: «ممنوع مسح آخر إيميل إدارة؟ — القرار متروك للوحة؛ الـAPI بيسمح».
     */
    public function adminEmailsDelete(string $id): JsonResponse
    {
        $id = $this->intId($id);

        $n = DB::delete('DELETE FROM admin_emails WHERE id = ?', [$id]);
        if ($n === 0) {
            throw ApiException::notFound('الإيميل غير موجود');
        }

        return ApiResponse::ok();
    }

    /* ── المُرسِلين / المستلمين: lookup + upsert + تعديل ──────── */

    /** GET /api/senders/lookup?phone= */
    public function sendersLookup(Request $request): JsonResponse
    {
        return $this->partyLookup($request, 'senders');
    }

    /** GET /api/receivers/lookup?phone= */
    public function receiversLookup(Request $request): JsonResponse
    {
        return $this->partyLookup($request, 'receivers');
    }

    /** POST /api/senders */
    public function sendersCreate(Request $request): JsonResponse
    {
        return $this->partyCreate($request, 'senders');
    }

    /** POST /api/receivers */
    public function receiversCreate(Request $request): JsonResponse
    {
        return $this->partyCreate($request, 'receivers');
    }

    /** PUT /api/senders/{id} */
    public function sendersUpdate(Request $request, string $id): JsonResponse
    {
        return $this->partyUpdate($request, 'senders', $id);
    }

    /** PUT /api/receivers/{id} */
    public function receiversUpdate(Request $request, string $id): JsonResponse
    {
        return $this->partyUpdate($request, 'receivers', $id);
    }

    /**
     * بحث مفهرس بالتليفون في senders أو receivers — **موظفين بس**.
     *
     * 🔒 خصوصية (قرار صاحب النظام، منقول بالحرف من الأصل): دفتر المرسلين/
     * المستلمين هو قاعدة عملاء الشركة. المسار ده بيرجّع الاسم الكامل والعنوان
     * والتليفون من غير أي شرط تعامل سابق — فلو اتفتح لغير الموظفين بيبقى
     * **التفاف كامل** حوالين قاعدة الخصوصية في GET /api/lookup. المحلات وعملاء
     * التطبيق والطيارين بيستخدموا /api/lookup (بيقنّع الاسم ويخفي العنوان لغير
     * المتعاملين). مفيش واجهة غير لوحات الموظفين بتنده المسار ده أصلًا.
     *
     * ⚠️ شكل الرد هنا `{ok, serverNow, items}` — **من غير `changed`**، مخالف
     * لغلاف قوايم الاستطلاع الموحّد. شذوذ في الأصل، فمابنستخدمش PollableList.
     */
    private function partyLookup(Request $request, string $table): JsonResponse
    {
        $phone = trim((string) $request->query('phone', ''));
        if ($phone === '') {
            throw new ApiException('رقم التليفون مطلوب');
        }

        // مفهرس على phone1 و phone2 — مطابقة تامة (زي القديم)، مش تطبيع
        $rows = DB::select(
            "SELECT * FROM {$table} WHERE phone1 = ? OR phone2 = ? ORDER BY id DESC LIMIT 20",
            [$phone, $phone]
        );

        return ApiResponse::out([
            'ok'        => true,
            'serverNow' => PollableList::serverNowMs(),
            'items'     => $this->serParty($table, $rows),
        ]);
    }

    /**
     * 🔒🔒 صف مرسل/مستلم موجود → لسان الواجهات **مع تطبيق قاعدة الخصوصية**.
     * نقل حرفي لـ entities_party_wire_private() بكل شروطها.
     *
     * موظف (admin/branch/callcenter) أو طرف اتعامل مع الرقم ده قبل كده = الصف
     * كامل. غير كده = **نفس أسماء الحقول بالظبط**، بس الاسم مقنّع والتليفون
     * مقنّع والعنوان والتليفون التاني null (ولا createdBy عشان ميعرفش مين
     * المحل الأصلي).
     *
     * الترتيب مضمون: المفاتيح موجودة أصلًا في $wire والإسناد بيستبدل القيمة
     * في مكانها — فترتيب مفاتيح الـJSON مايتغيرش بين المقنّع والكامل.
     */
    private function partyWirePrivate(Actor $user, array $row, string $table): array
    {
        $wire  = $this->serOne($table, $row);
        $phone = TrustWire::normalizePhone((string) ($row['phone1'] ?? ''));
        $staff = $user->isStaff();   // نفس in_array(role, [admin,branch,callcenter], true)

        if ($staff || ($phone !== '' && TrustWire::hasDealtWith($phone, $user))) {
            return $wire;
        }

        $wire['name']      = TrustWire::maskName($wire['name'] ?? '');
        $wire['phone1']    = TrustWire::maskPhone((string) ($wire['phone1'] ?? ''));
        $wire['phone2']    = null;
        $wire['address']   = null;
        $wire['createdBy'] = null;

        return $wire;
    }

    /**
     * POST /api/senders | /api/receivers — upsert: لو التليفون موجود بيرجّع
     * الموجود بدل ما يكرّر. **أي حساب مسجّل** (require_auth) — مش موظفين بس.
     *
     * 🔒🔒 تعليق الأصل منقول كما هو لأنه بيوثّق ثغرة اتصلحت:
     *   «خصوصية: المسار ده upsert — لو الرقم موجود بيرجّع الصف الموجود.
     *    ده كان التفاف كامل حوالين قاعدة الخصوصية: أي محل/طيار/عميل يبعت
     *    {"name":"اي حاجة","phone1":"<رقم أي حد>"} ويستلم الاسم الكامل والعنوان
     *    والتليفون التاني لشخص ما اتعاملش معاه — من غير ما يعدّي على /api/lookup.
     *    القاعدة هنا نفس قاعدة /api/lookup: موظف أو اتعامل قبل كده = الصف كامل،
     *    غير كده = نفس أسماء الحقول باسم مقنّع وبلا عنوان ولا تليفون كامل
     *    (دستور الـAPI: ممنوع حذف اسم حقل — القيمة بس هي اللي بتتقنّع).»
     *
     * ⚠️ الصف الجديد بيرجع **كامل بلا تقنيع** — منطقيًا هو من إنشاء الطالب
     * نفسه فمفيش بيانات غيره فيه. سلوك الأصل بالحرف.
     *
     * ملاحظة: مفيش معاملة هنا — الأصل جملة كتابة واحدة (INSERT) وبعدها قراءة
     * رجوع. لفّها في معاملة كان هيبقى تغيير مش نقل.
     */
    private function partyCreate(Request $request, string $table): JsonResponse
    {
        $actor = $request->actorOrFail();
        $b = $this->body($request);

        $name = trim((string) ($b['name'] ?? ''));
        // `phone1` الأول وبعدها `phone` — الواجهات القديمة بتبعت الاسمين
        $phone = trim((string) ($b['phone1'] ?? ($b['phone'] ?? '')));
        if ($name === '' || $phone === '') {
            throw new ApiException('الاسم ورقم التليفون مطلوبين');
        }

        $existing = DB::select("SELECT * FROM {$table} WHERE phone1 = ? LIMIT 1", [$phone])[0] ?? null;
        if ($existing) {
            return ApiResponse::out([
                'ok'       => true,
                'existing' => true,
                'item'     => $this->partyWirePrivate($actor, (array) $existing, $table),
            ]);
        }

        // مصدر غير معروف = بيتحدّد من دور الطالب (مش بيتقبل من الجسم كما هو)
        $source = (string) ($b['source'] ?? '');
        if (! isset(Vocab::ORDER_SOURCE_WIRE[$source])) {
            $source = $actor->role === 'branch' ? 'branch'
                : ($actor->role === 'callcenter' ? 'callcenter'
                : ($actor->role === 'store' ? 'store' : 'admin'));
        }

        DB::insert(
            "INSERT INTO {$table} (name, phone1, phone2, address, created_by, source, created_at) VALUES (?,?,?,?,?,?,?)",
            [
                $name,
                $phone,
                trim((string) ($b['phone2'] ?? '')) ?: null,
                trim((string) ($b['address'] ?? '')) ?: null,
                $actor->username,
                $source,
                WireTime::nowDb(),
            ]
        );

        $row = DB::select("SELECT * FROM {$table} WHERE id = ?", [(int) DB::getPdo()->lastInsertId()])[0] ?? null;

        return ApiResponse::out([
            'ok'       => true,
            'existing' => false,
            'item'     => $this->serOne($table, (array) $row),
        ]);
    }

    /**
     * PUT /api/senders/{id} | /api/receivers/{id} — **موظفين بس**.
     *
     * 🔒 تعليق الأصل: «خصوصية + سلامة الهوية: التعديل بيكتب على الصف وبيرجّعه
     * كامل بعد الكتابة، فكان قناة قراية كمان (أي id → الاسم والتليفون والعنوان)
     * وكمان تلاعب بهوية شخص في منصة ثقة. التعديل السطري ده بيتعمل من لوحات
     * الموظفين بس.»
     *
     * ⚠️ الـUPDATE بيتنفّذ **قبل** التأكد إن الصف موجود — فـid مش موجود بيعمل
     * كتابة على صفر صفوف وبعدها 404. منقول زي ما هو (مفيش معاملة في الأصل،
     * وجملة الكتابة واحدة).
     */
    private function partyUpdate(Request $request, string $table, string $id): JsonResponse
    {
        $id = $this->intId($id);
        $b = $this->body($request);

        $name = trim((string) ($b['name'] ?? ''));
        $phone = trim((string) ($b['phone1'] ?? ''));
        if ($name === '' || $phone === '') {
            throw new ApiException('الاسم ورقم التليفون مطلوبين');
        }

        DB::update(
            "UPDATE {$table} SET name = ?, phone1 = ?, phone2 = ?, address = ? WHERE id = ?",
            [
                $name,
                $phone,
                trim((string) ($b['phone2'] ?? '')) ?: null,
                trim((string) ($b['address'] ?? '')) ?: null,
                $id,
            ]
        );

        $row = DB::select("SELECT * FROM {$table} WHERE id = ?", [$id])[0] ?? null;
        if (! $row) {
            throw ApiException::notFound($table === 'senders' ? 'المرسل غير موجود' : 'المستلم غير موجود');
        }

        return ApiResponse::ok(['item' => $this->serOne($table, (array) $row)]);
    }

    /* ── دفتر جهات اتصال المحل: إضافة / حذف ──────────────────── */

    /**
     * POST /api/store-contacts — أي حساب مسجّل بيعدّي على require_auth،
     * والتقييد جوه: المحل على دفتره، وغير المحل لازم يكون موظف.
     *
     * 🔒 «الكتابة في دفتر محل تاني للموظفين بس (زي القراية)» — تعليق الأصل.
     * (عشان كده المسار مالوش middleware دور: الطيار والعميل لازم ياخدوا 403
     * من جوه مش 403 من الميدلوير — نفس الكود بس نفس المسار بيخدم الاتنين.)
     */
    public function storeContactsCreate(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $b = $this->body($request);

        if ($actor->role !== 'store' && ! $actor->isStaff()) {
            throw ApiException::forbidden();
        }

        $owner = $actor->role === 'store' ? $actor->username : trim((string) ($b['store'] ?? ''));
        if ($owner === '') {
            // ⚠️ نص مختلف عن نص القراية («اسم مستخدم المحل مطلوب (?store=)») — منقول
            throw new ApiException('اسم مستخدم المحل مطلوب');
        }

        $name = trim((string) ($b['name'] ?? ''));
        $phone = trim((string) ($b['phone'] ?? ''));
        if ($name === '' || $phone === '') {
            throw new ApiException('الاسم ورقم التليفون مطلوبين');
        }

        DB::insert(
            'INSERT INTO store_contacts (store_username, name, phone, phone2, address, created_at) VALUES (?,?,?,?,?,?)',
            [
                $owner,
                $name,
                $phone,
                trim((string) ($b['phone2'] ?? '')) ?: null,
                trim((string) ($b['address'] ?? '')) ?: null,
                WireTime::nowDb(),
            ]
        );

        return ApiResponse::out(['ok' => true, 'id' => (int) DB::getPdo()->lastInsertId()]);
    }

    /**
     * DELETE /api/store-contacts/{id}
     * 🔒 المحل بيمسح من دفتره هو بس — وغير المحل لازم يكون موظف.
     * الترتيب مهم: 404 على الصف المفقود **قبل** فحص الملكية (زي الأصل).
     */
    public function storeContactsDelete(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $id = $this->intId($id);

        $row = DB::select('SELECT * FROM store_contacts WHERE id = ?', [$id])[0] ?? null;
        if (! $row) {
            throw ApiException::notFound('جهة الاتصال غير موجودة');
        }

        if ($actor->role === 'store') {
            if ($row->store_username !== $actor->username) {
                throw ApiException::forbidden();
            }
        } elseif (! $actor->isStaff()) {
            throw ApiException::forbidden();
        }

        DB::delete('DELETE FROM store_contacts WHERE id = ?', [$id]);

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       أدوات داخلية للكتابة — نقل حرفي لدوال entities_* المساعدة
    ═══════════════════════════════════════════════════════════ */

    /**
     * المقابل لـ body_json(): الجسم كمصفوفة، والفاضي أو المكسور = [].
     *
     * `$request->json()->all()` مش `input()` عن قصد: الكود الأصلي بيستخدم
     * `array_key_exists($wire, $b)` عشان يفرّق بين «الحقل مش متبعت» و«متبعت
     * بقيمة null/فاضية» — والتفرقة دي هي أساس التعديل الجزئي (PATCH-like).
     * لازم نمسك المصفوفة الخام مش مصدر مدخلات مدموج.
     */
    private function body(Request $request): array
    {
        return $request->json()->all();
    }

    /** المقابل لـ entities_int_id(): معرّف موجب، وغير الصالح 400 مش 404 */
    private function intId(mixed $id): int
    {
        $n = (int) $id;
        if ($n <= 0) {
            throw new ApiException('معرّف غير صالح');
        }

        return $n;
    }

    /** اسم فرع بالـ id (أو null) — المقابل لـ entities_branch_name() */
    private function branchName(?int $branchId): ?string
    {
        if ($branchId === null) {
            return null;
        }

        $name = DB::select('SELECT name FROM branches WHERE id = ?', [$branchId])[0]->name ?? null;

        return $name === null ? null : (string) $name;
    }

    /**
     * 🔒 المقابل لـ entities_assert_zone_in_scope():
     * مشرف الفرع مقفول على مناطق فرعه (اللي بيوصّلها **أو** اللي منها).
     * الإدارة والكول سنتر: كل المناطق.
     *
     * ⚠️ `$ub === 0` (مشرف فرع بلا branch_id) = مرفوض دايمًا — مش مسموح له
     * بكل المناطق. الترتيب في الشرط ده جزء من الصح.
     */
    private function assertZoneInScope(Actor $user, array $zone): void
    {
        if ($user->role !== 'branch') {
            return;
        }
        $ub = (int) ($user->branchId ?? 0);
        $deliv = $zone['delivery_branch_id'] !== null ? (int) $zone['delivery_branch_id'] : 0;
        $src   = $zone['source_branch_id']   !== null ? (int) $zone['source_branch_id']   : 0;
        if ($ub === 0 || ($ub !== $deliv && $ub !== $src)) {
            throw ApiException::forbidden('المنطقة دي مش تابعة لفرعك');
        }
    }

    /**
     * كتابة صلاحيات المستخدم — **استبدال كامل**، جوه معاملة مفتوحة.
     * نقل حرفي لـ entities_users_write_perms().
     *
     * `null` = «الحقل مش متبعت» → مايتلمسش خالص. `[]` = «امسح كله».
     * التفرقة دي هي اللي بتخلي PUT جزئي مايمسحش صلاحيات موظف بالغلط، وعشان
     * كده الاستدعاء بيعمل `is_array($b['allowedApps'] ?? null) ? ... : null`.
     *
     * pagePerms بيقبل الشكلين: {app: {page: true}} أو {app: [page, page]} —
     * لأن الواجهات القديمة بتبعت الاتنين.
     */
    private function writeUserPerms(int $userId, ?array $allowedApps, ?array $pagePerms): void
    {
        if ($allowedApps !== null) {
            DB::delete('DELETE FROM user_app_permissions WHERE user_id = ?', [$userId]);
            foreach ($allowedApps as $app) {
                $app = trim((string) $app);
                if ($app !== '') {
                    DB::insert(
                        'INSERT IGNORE INTO user_app_permissions (user_id, app, created_at) VALUES (?,?,?)',
                        [$userId, $app, WireTime::nowDb()]
                    );
                }
            }
        }

        if ($pagePerms !== null) {
            DB::delete('DELETE FROM user_page_permissions WHERE user_id = ?', [$userId]);
            foreach ($pagePerms as $app => $pages) {
                $app = trim((string) $app);
                if ($app === '' || ! is_array($pages)) {
                    continue;
                }
                foreach ($pages as $k => $v) {
                    $page = is_int($k) ? trim((string) $v) : trim((string) $k);
                    $on   = is_int($k) ? true : (bool) $v;
                    if ($page !== '' && $on) {
                        DB::insert(
                            'INSERT IGNORE INTO user_page_permissions (user_id, app, page, created_at) VALUES (?,?,?,?)',
                            [$userId, $app, $page, WireTime::nowDb()]
                        );
                    }
                }
            }
        }

        // ملاحظة: الاستبدال الكامل ده بيمسح صف الصلاحية ويعيد إدخاله، يعني
        // created_at بيتجدّد على كل حفظ. سلوك الأصل — مش سجل تاريخي.
    }

    /**
     * 🔒 المقابل لـ entities_users_fetch_guarded(): بيجيب المستخدم ويطبّق
     * قاعدة protected — «حساب protected=1 ميتعدلش/ميتمسحش/ميتبلكش إلا من admin».
     *
     * ملاحظة: التلات مسارات اللي بتنده الدالة دي (update/block/delete) كلها
     * محمية بـrole:admin أصلًا، فشرط `role !== 'admin'` جواها **فرع ميت**
     * عمليًا. منقول زي ما هو — لو اتفتح المسار لدور تاني في المستقبل، الحماية
     * بتفضل شغالة من جوه.
     */
    private function fetchUserGuarded(int $id, Actor $actor): array
    {
        $target = DB::select('SELECT * FROM users WHERE id = ?', [$id])[0] ?? null;
        if (! $target) {
            throw ApiException::notFound('المستخدم غير موجود');
        }
        $target = (array) $target;
        if ((int) $target['protected'] === 1 && $actor->role !== 'admin') {
            throw ApiException::forbidden('هذا الحساب محمي — التعديل من الإدارة فقط');
        }

        return $target;
    }

    /** صف واحد على السلك حسب الجدول — المستلم نفس بنية المُرسِل بالحرف */
    private function serOne(string $table, array $row): array
    {
        return $table === 'senders' ? CoreWire::sender($row) : CoreWire::receiver($row);
    }
}
