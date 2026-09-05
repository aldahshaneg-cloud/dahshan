<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Support\Actor;
use App\Support\ApiResponse;
use App\Support\BizDay;
use App\Support\PollableList;
use App\Support\WireTime;
use App\Wire\FinanceWire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * المالية والحضور — نقل حرفي لمسارات القراءة من api/routes/finance.php.
 * (الخزن · حركات النقدية · العهدة · المصروفات · المحافظ · الحضور · الموظفين اليدويين)
 *
 * 🔴 ده مجال الفلوس. الاستعلامات مكتوبة **خام زي الأصل** مش Eloquent، وكل
 * cast وكل حد (LIMIT) وكل شرط فلترة منقول بالحرف. الأرقام اللي بترجع من هنا
 * بتتعرض في لوحات الحسابات وبتتقفل عليها الورديات، فأي فرق في تقريب أو في
 * ترتيب مفتاح = فرق في كشف حساب.
 *
 * ملاحظة على السقوف: 500 صف للقوايم و200 للسجلات التفصيلية. الأرقام دي
 * **مش تعسفية ومش قابلة للرفع بالمزاج** — الواجهات بتحسب مجاميعها من النافذة
 * دي، ورفع السقف بيغيّر أرقام معروضة. منقولة زي ما هي.
 *
 * ملاحظة على الأدوار: فرض الدور بيتم على مستوى المسار بـ`->middleware('role:…')`
 * — نفس require_role() بالظبط، وبنفس الفرق المهم إن **بلا دخول أصلًا الرد 401
 * مش 403**. الاستثناء الوحيد هنا walletGet: بيبدأ بـactorOrFail() لأن بوابته
 * مش بالدور وحده (تحت).
 */
class FinanceController
{
    /**
     * المقابل لـ FINANCE_CUSTODY_TYPES في الأصل.
     * كلها بتزوّد اللي على الطيار ما عدا `return` بتنقّص — والإشارة دي
     * بتتحسب في مسار الكتابة، والمبلغ المخزّن **موجب دايمًا**.
     */
    private const CUSTODY_TYPES = ['give', 'return', 'order_pending', 'order_extra'];

    /* ═══════════════════════════════════════════════════════════
       1) الخزن cash_stores
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/cash-stores?branchId=
     * 🔒 أدوار الفلوس: admin · branch · accountant (finance_require_money_roles).
     *
     * ⚠️ الفلتر هنا `isset && !== ''` مش `!empty` — يعني `?branchId=0` بيدخل
     * فعلًا كشرط `branch_id = 0` (نتيجة فاضية) بدل ما يتجاهل. مختلف عن باقي
     * القوايم في نفس الملف، ومنقول زي ما هو.
     */
    public function cashStoresList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $q     = $request->query();

        // 🔒 مشرف الفرع مقفول على فرعه — شوف scopeBranch
        $branchId = $this->scopeBranch($actor, $q['branchId'] ?? null);

        // اسم الفرع بيتجاب هنا عشان السلك يبعته — الواجهة بتعرضه في الجدول
        $sql = 'SELECT s.*, b.name AS _branch_name FROM cash_stores s
                LEFT JOIN branches b ON b.id = s.branch_id';
        $params = [];
        if ($branchId !== null) {
            $sql .= ' WHERE branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY id';

        return PollableList::items(array_map(
            fn ($r) => FinanceWire::store($r),
            DB::select($sql, $params)
        ));
    }

    /* ═══════════════════════════════════════════════════════════
       2) حركات النقدية cash_transactions
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/cash-stores/{id}/transactions?from=&to=&type=
     * 🔒 أدوار الفلوس.
     *
     * ⚠️ `from`/`to` بيتلزقوا بالوقت نصًا (`. ' 00:00:00'`) ومش بيتحققوا
     * خالص — أي نص غير تاريخ بيروح لـMariaDB وبيرجّع نتيجة فاضية بدل خطأ.
     * سلوك الأصل بالحرف. القيمة بتتبعت كباراميتر مربوط فمفيش حقن.
     *
     * ⚠️ المقارنة على `created_at` وهو **UTC** بينما اليوم اللي المستخدم
     * بيكتبه قاهرة — يعني حدود اليوم بتزحف ساعتين. باج قديم منقول زي ما هو.
     */
    public function cashTxnsList(Request $request, string $id): JsonResponse
    {
        $actor   = $request->actorOrFail();
        $storeId = $this->intId($id);
        // 🔒 كان بيرجّع حركات أي خزنة في الشركة بمجرد رقمها
        $this->assertStoreInScope($actor, $storeId);
        $q = $request->query();

        $sql = 'SELECT * FROM cash_transactions WHERE store_id = ?';
        $params = [$storeId];
        if (! empty($q['from'])) {
            $sql .= ' AND created_at >= ?';
            $params[] = $q['from'] . ' 00:00:00';
        }
        if (! empty($q['to'])) {
            $sql .= ' AND created_at <= ?';
            $params[] = $q['to'] . ' 23:59:59';
        }
        if (! empty($q['type'])) {
            $sql .= ' AND type = ?';
            $params[] = $q['type'];
        }
        $sql .= ' ORDER BY id DESC LIMIT 500';

        return PollableList::items(array_map(
            fn ($r) => FinanceWire::cashTxn($r),
            DB::select($sql, $params)
        ));
    }

    /* ═══════════════════════════════════════════════════════════
       3) العهدة custody_transactions
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/custody?pilotId=&from=&to=
     * 🔒 أدوار الفلوس.
     *
     * `WHERE 1=1` متسابة زي الأصل: بتخلي كل شرط بعدها يتلزق بـAND من غير
     * فرع خاص لأول شرط. شكل SQL نفسه جزء من اللي بيتفحص في المقارنة.
     */
    public function custodyList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $q     = $request->query();

        /* 🔒 كانت `WHERE 1=1` — كل حركات عهدة كل طيارين الشركة.
           الفلترة على فرع الطيار الجاري (`assigned_branch_id`) زي باقي
           استعلامات النطاق في اللوحة. */
        $sql = 'SELECT ct.* FROM custody_transactions ct
                JOIN pilots p ON p.id = ct.pilot_id WHERE 1=1';
        $params = [];
        $scope  = $this->scopeBranch($actor, $q['branchId'] ?? null);
        if ($scope !== null) {
            $sql .= ' AND p.assigned_branch_id = ?';
            $params[] = $scope;
        }
        if (! empty($q['pilotId'])) {
            $sql .= ' AND ct.pilot_id = ?';
            $params[] = (int) $q['pilotId'];
        }
        if (! empty($q['from'])) {
            $sql .= ' AND ct.created_at >= ?';
            $params[] = $q['from'] . ' 00:00:00';
        }
        if (! empty($q['to'])) {
            $sql .= ' AND ct.created_at <= ?';
            $params[] = $q['to'] . ' 23:59:59';
        }
        $sql .= ' ORDER BY ct.id DESC LIMIT 500';

        return PollableList::items(array_map(
            fn ($r) => FinanceWire::custody($r),
            DB::select($sql, $params)
        ));
    }

    /**
     * GET /api/pilots/{id}/custody
     * 🔒 أدوار الفلوس — الطيار **نفسه مش مسموح له** يشوف كشف عهدته من هنا.
     *
     * الرد ده **مش قايمة استطلاع**: مفيش serverNow ولا changed، وفيه
     * `custodyBalance` (الرصيد الحي من صف الطيار) جنب آخر 200 حركة.
     * الرصيد بيتقرا من `pilots.custody_balance` مش بجمع الحركات — الجمع
     * كان هيدي رقم تاني لأن السقف 200.
     */
    public function pilotCustody(Request $request, string $id): JsonResponse
    {
        $actor   = $request->actorOrFail();
        $pilotId = $this->intId($id);

        $pilot = DB::select('SELECT id, name, custody_balance, assigned_branch_id FROM pilots WHERE id = ?', [$pilotId])[0] ?? null;
        if (! $pilot) {
            throw ApiException::notFound('الطيار غير موجود');
        }
        // 🔒 كان بيرجّع رصيد وحركات عهدة أي طيار في الشركة بمجرد رقمه
        if ($actor->role === 'branch') {
            $mine = (int) ($actor->branchId ?? 0);
            if ($mine === 0 || $mine !== (int) $pilot->assigned_branch_id) {
                throw ApiException::forbidden('الطيار ده مش تابع لفرعك');
            }
        }

        $tx = DB::select(
            'SELECT * FROM custody_transactions WHERE pilot_id = ? ORDER BY id DESC LIMIT 200',
            [$pilotId]
        );

        return ApiResponse::out([
            'ok'             => true,
            'pilotId'        => (int) $pilot->id,
            'pilotName'      => $pilot->name,
            'custodyBalance' => (float) $pilot->custody_balance,
            'items'          => array_map(fn ($r) => FinanceWire::custody($r), $tx),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       4) المصروفات expenses
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/expenses?from=&to=&branchId=
     * 🔒 أدوار الفلوس.
     *
     * هنا الفلترة على `expense_date` (عمود DATE) مش على `created_at`،
     * فالقيمة بتتبعت **من غير أي لزق وقت** — عكس حركات النقدية والعهدة.
     * الفرق ده مقصود في الأصل ومنقول زي ما هو.
     */
    public function expensesList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $q     = $request->query();

        // اسم الخزنة بيتجاب هنا عشان شارة «مدفوع» تبان بمصدر الدفع
        $sql = 'SELECT e.*, s.name AS _cash_store_name FROM expenses e
                LEFT JOIN cash_stores s ON s.id = e.cash_store_id WHERE 1=1';
        $params = [];
        if (! empty($q['from'])) {
            $sql .= ' AND e.expense_date >= ?';
            $params[] = $q['from'];
        }
        if (! empty($q['to'])) {
            $sql .= ' AND e.expense_date <= ?';
            $params[] = $q['to'];
        }
        /* 🔒 كان `!empty($q['branchId'])` — يعني من غير الباراميتر بترجّع
           مصروفات الشركة كلها لمشرف أي فرع. `scopeBranch` بيلزّمه بفرعه. */
        $scope = $this->scopeBranch($actor, $q['branchId'] ?? null);
        if ($scope !== null) {
            /* 🔴 كان `branch_id` من غير e. — والـJOIN على cash_stores فيه branch_id
               برضه، فالاستعلام كان بيقع «ambiguous» لأي طلب بفرع (اتلقى بحارس
               الميزانية 2026-09-05). */
            $sql .= ' AND e.branch_id = ?';
            $params[] = $scope;
        }
        $sql .= ' ORDER BY e.expense_date DESC, e.id DESC LIMIT 500';

        return PollableList::items(array_map(
            fn ($r) => FinanceWire::expense($r),
            DB::select($sql, $params)
        ));
    }

    /* ═══════════════════════════════════════════════════════════
       5) المحافظ wallets
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/wallets?ownerType=store|customer
     * 🔒 admin · accountant · callcenter — **مش نفس أدوار الفلوس**، مافيهاش
     * branch. (تعليق الأصل بيقول إن بوابة المحفظة بتساوي أدوار المسار ده،
     * والواقع إن الاتنين مختلفين — منقول زي ما هو، مش تصحيح.)
     *
     * ⚠️ الرد ده **من غير serverNow** — `{ok, changed, items}` بس. باقي
     * القوايم في الملف بتطلع serverNow. شذوذ في الأصل ومنقول بالحرف، عشان
     * كده مابنعدّيش على PollableList::items هنا.
     *
     * كل عنصر فيه `ownerId` و`balance` بس — مافيش أسماء ولا أي بيانات
     * شخصية، عمدًا: ده تفريغ لكل محافظ النوع دفعة واحدة للوحات الإدارة.
     */
    public function walletsList(Request $request): JsonResponse
    {
        $q = $request->query();

        $ownerType = $q['ownerType'] ?? 'store';
        if (! in_array($ownerType, ['store', 'customer'], true)) {
            throw new ApiException('نوع المالك غير صالح');
        }

        $items = [];
        foreach (DB::select('SELECT owner_id, balance FROM wallets WHERE owner_type = ?', [$ownerType]) as $r) {
            $items[] = [
                'ownerId' => (int) $r->owner_id,
                'balance' => (float) $r->balance,
            ];
        }

        return ApiResponse::out([
            'ok'      => true,
            'changed' => true,
            'items'   => $items,
        ]);
    }

    /**
     * GET /api/wallets/{ownerType}/{ownerId}
     *
     * 🔒 المسار ده **مالوش middleware دور** عن قصد: بوابته مش بالدور وحده،
     * لأن المحل والعميل لازم يوصلوا لمحفظتهم هم. الترتيب مهم ومنقول بالحرف:
     *   1) actorOrFail()  → 401 بلا دخول
     *   2) تحقق النوع/المعرّف → 400
     *   3) بوابة الوصول    → 403
     *
     * لو المحفظة لسه ما اتخلقتش، الرد بيرجع `walletId:null` و`balance:0.0`
     * و`items:[]` — **مش 404**. الإنشاء بيحصل في مسارات الحركة بس، فقراءة
     * محفظة عميل جديد لازم تشتغل عادي.
     */
    public function walletGet(Request $request, string $ownerType, string $ownerId): JsonResponse
    {
        $actor = $request->actorOrFail();

        [$type, $oid] = $this->walletOwner($ownerType, $ownerId);
        $this->requireWalletAccess($type, $oid, $actor);

        $wallet = DB::select(
            'SELECT * FROM wallets WHERE owner_type = ? AND owner_id = ?',
            [$type, $oid]
        )[0] ?? null;

        $items = [];
        if ($wallet) {
            $items = array_map(
                fn ($r) => FinanceWire::walletTxn($r),
                DB::select(
                    'SELECT * FROM wallet_transactions WHERE wallet_id = ? ORDER BY id DESC LIMIT 200',
                    [(int) $wallet->id]
                )
            );
        }

        return ApiResponse::out([
            'ok'        => true,
            'ownerType' => $type,
            'ownerId'   => $oid,
            'walletId'  => $wallet ? (int) $wallet->id : null,
            'balance'   => $wallet ? (float) $wallet->balance : 0.0,
            'items'     => $items,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       6) الحضور attendance_sessions
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/attendance?day= | ?from=&to= [&username=]
     * 🔒 admin · branch · hr · accountant — **قايمة أدوار رابعة مختلفة**
     * (فيها hr ومافيهاش callcenter). منقولة زي ما هي.
     *
     * لو مافيش `day` ولا `from` الافتراضي **يوم القاهرة الحالي** مش UTC —
     * وردية بتقفل بعد نص الليل بتوقيت القاهرة لازم تقع على يومها الصح.
     *
     * الشكل متجمّع يومين مستويات: `days[التاريخ][اسم المستخدم].sessions[]`
     * (VOCAB بند 12). و`days` بترجع `{}` لو فاضية مش `[]` — الواجهة بتعمل
     * عليها Object.keys().
     */
    public function attendanceList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $q = $request->query();

        $day  = ! empty($q['day'])  ? trim((string) $q['day'])  : null;
        $from = ! empty($q['from']) ? trim((string) $q['from']) : null;
        $to   = ! empty($q['to'])   ? trim((string) $q['to'])   : null;
        if ($day === null && $from === null) {
            $day = BizDay::key();
        }

        $sql = 'SELECT * FROM attendance_sessions WHERE 1=1';
        $params = [];
        if ($day !== null) {
            // `day` بتغلب `from`/`to` لو الاتنين اتبعتوا — ترتيب الأصل
            $sql .= ' AND session_date = ?';
            $params[] = $day;
        } else {
            $sql .= ' AND session_date >= ?';
            $params[] = $from;
            if ($to !== null) {
                $sql .= ' AND session_date <= ?';
                $params[] = $to;
            }
        }
        if (! empty($q['username'])) {
            $sql .= ' AND username = ?';
            $params[] = trim((string) $q['username']);
        }
        /* 🔒 الكول سنتر بيشوف حضوره هو بس. الشرط بيتضاف **بعد** فلتر
           username مش بدله — فلو بعت ?username=حد_تاني بيبقى الشرطين مع
           بعض والنتيجة صفر صفوف، مش بيانات حد تاني. */
        if ($actor->role === 'callcenter') {
            $sql .= ' AND username = ?';
            $params[] = $actor->username;
        }
        // مفيش LIMIT هنا — الفلترة باليوم هي السقف الفعلي (سلوك الأصل)
        $sql .= ' ORDER BY session_date, username, id';

        $days = [];
        foreach (DB::select($sql, $params) as $row) {
            $r = (array) $row;
            $days[$r['session_date']][$r['username']]['sessions'][] = FinanceWire::attendanceSession($r);
        }

        return ApiResponse::out([
            'ok'        => true,
            'serverNow' => PollableList::serverNowMs(),
            'changed'   => true,
            'days'      => $days ?: new stdClass(),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       7) الموظفين اليدويين manual_employees
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/manual-employees
     * 🔒 admin · hr · branch — قايمة أدوار خامسة مختلفة، منقولة زي ما هي.
     * مفيش فلاتر ولا سقف: الجدول صغير (موظفين بلا حسابات نظام).
     */
    public function manualEmployeesList(): JsonResponse
    {
        return PollableList::items(array_map(
            fn ($r) => FinanceWire::manualEmployee($r),
            DB::select('SELECT * FROM manual_employees ORDER BY name')
        ));
    }

    /* ═══════════════════════════════════════════════════════════
       ▓▓▓ مسارات الكتابة — نقل حرفي من api/routes/finance.php ▓▓▓

       🔴 كله فلوس. القاعدة الحاكمة منقولة من رأس الملف الأصلي:
       «CONVENTIONS بند 3: كل تعديل رصيد جوه معاملة SQL بـ
        SELECT ... FOR UPDATE — بلا استثناء».

       الأصل كان بيعمل beginTransaction/commit/rollBack يدوي جوه
       finance_tx()، وكل fail() جواها كانت مسبوقة بـrollBack() يدوي.
       هنا DB::transaction بتعمل الrollback لوحدها لأن ApiException
       استثناء مش exit — فالـrollBack اليدوي اتشال، والباقي بالحرف.
    ═══════════════════════════════════════════════════════════ */

    /* ── 1) الخزن cash_stores: إنشاء / تعديل / حذف ───────────── */

    /**
     * POST /api/cash-stores
     * 🔒 admin · accountant · branch — الأصل بينده require_role() صراحةً
     * بالتلاتة دول (مش finance_require_money_roles()). نفس المجموعة بترتيب
     * مختلف، فالسلوك واحد.
     *
     * ⚠️ مشرف الفرع بيتدوس على `branchId` اللي بعته ويتفرض عليه فرعه هو —
     * حتى لو بعت فرع تاني. ده سلوك «خزن الفرع» في النظام القديم.
     *
     * 🔴 الرصيد بيتخلق **صفر حرفيًا في نص الـSQL** (`VALUES (?,?,0,?)`) مش
     * من الجسم. مقصود: مفيش باب لرصيد ابتدائي من غير حركة نقدية تقابله.
     */
    public function cashStoresCreate(Request $request): JsonResponse
    {
        // بوابة الدخول — الدور نفسه مفروض على المسار (role:admin)
        $request->actorOrFail();
        $body = $this->body($request);

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new ApiException('اسم الخزنة مطلوب');
        }
        $branchId = isset($body['branchId']) && $body['branchId'] !== null && $body['branchId'] !== ''
            ? (int) $body['branchId'] : null;
        /* 🔴 المسار بقى للمدير العام بس (role:admin) — قرار صاحب النظام
           2026-08-26. قبل كده كان مشرف الفرع بيقدر يفتح خزنة لفرعه،
           والسطرين اللي كانوا هنا كانوا بيقفلوا الفرع على فرعه.
           بقوا كود ميت فاتشالوا: مشرف الفرع مابيوصلش للمسار أصلًا،
           والمدير بيختار الفرع بنفسه من الـbody. */

        DB::insert(
            'INSERT INTO cash_stores (name, branch_id, balance, created_at) VALUES (?,?,0,?)',
            [$name, $branchId, WireTime::nowDb()]
        );
        $id = (int) DB::getPdo()->lastInsertId();

        $row = DB::select('SELECT * FROM cash_stores WHERE id = ?', [$id])[0];

        return ApiResponse::out(['ok' => true, 'store' => FinanceWire::store($row)]);
    }

    /**
     * PUT /api/cash-stores/{id}
     * 🔒 admin · accountant — **من غير branch**: مشرف الفرع ينشئ خزنة بس
     * ومايعدّلهاش. منقول زي ما هو.
     *
     * ملحوظة حاكمة من الأصل: **`balance` ممنوع تعديله من هنا** — التعديل
     * عبر `cash_transactions` حصريًا. عشان كده مفيش فرع للـbalance تحت.
     *
     * ⚠️ الرد 404 بيتفحص **بعد** الـUPDATE مش قبله: لو rowCount صفر بنشوف
     * الصف موجود ولا لأ. يعني تعديل بنفس القيم (صفر صفوف متغيّرة) بيرجّع
     * الخزنة عادي مش 404. سلوك الأصل بالحرف.
     */
    public function cashStoresUpdate(Request $request, string $id): JsonResponse
    {
        $id = $this->intId($id);
        $body = $this->body($request);

        $fields = [];
        $params = [];
        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                throw new ApiException('اسم الخزنة مطلوب');
            }
            $fields[] = 'name = ?';
            $params[] = $name;
        }
        if (array_key_exists('branchId', $body)) {
            $fields[] = 'branch_id = ?';
            $params[] = $body['branchId'] !== null && $body['branchId'] !== '' ? (int) $body['branchId'] : null;
        }
        if (! $fields) {
            throw new ApiException('مفيش حاجة تتعدل');
        }
        $params[] = $id;

        $affected = DB::update('UPDATE cash_stores SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
        if ($affected === 0) {
            $chk = DB::select('SELECT id FROM cash_stores WHERE id = ?', [$id]);
            if (! $chk) {
                throw new ApiException('الخزنة غير موجودة', 404);
            }
        }

        $row = DB::select('SELECT * FROM cash_stores WHERE id = ?', [$id])[0];

        return ApiResponse::out(['ok' => true, 'store' => FinanceWire::store($row)]);
    }

    /**
     * DELETE /api/cash-stores/{id}
     * 🔒 admin بس.
     *
     * 🔴 بوابتين قبل الحذف، والاتنين جوه المعاملة والصف مقفول:
     *   • رصيد ≠ صفر → مرفوض (فلوس هتضيع من الدفاتر).
     *   • عليها أي حركة → مرفوض (**الأرشيف المالي مايتحذفش أبدًا**).
     *
     * ⚠️ `!= 0.0` مقارنة **مرنة عن قصد** — الرصيد جاي من الدرايفر نصًا
     * ("0.00")، والصرامة كانت هتخلي خزنة صفر تتعامل كأن فيها رصيد. نفس
     * سبب Commission.php بالظبط.
     */
    /**
     * POST /api/cash-stores/transfer — تحويل فلوس من خزنة لخزنة.
     * الجسم: {fromId, toId, amount, reason?}
     *
     * ═══ ليه مسار مستقل مش حركتين ═══
     * لو الواجهة عملت «صادر» من خزنة و«وارد» في التانية بنداءين منفصلين،
     * أي فشل بين النداءين (نت قطع · التاب اتقفل · السيرفر رجع خطأ) بيسيب
     * فلوس **طالعة من خزنة وما دخلتش التانية** — وده فرق في الدفاتر محدش
     * هيعرف يفسّره. هنا الاتنين جوه معاملة واحدة: يا الاتنين يا ولا واحد.
     *
     * ═══ ترتيب القفل ═══
     * 🔴 الخزنتين بيتقفلوا **بترتيب الرقم تصاعديًا** مش بترتيب (من/إلى).
     * من غير كده، تحويل من خزنة 5 لـ7 في نفس لحظة تحويل من 7 لـ5 =
     * كل معاملة ماسكة قفل والتانية مستنياه = تعليق (deadlock) والقاعدة
     * بتقتل واحدة منهم. الترتيب الثابت بيمنع الحالة دي من أصلها.
     *
     * ═══ الأدوار ═══
     * المدير والمحاسب. مشرف الفرع **مقصود** إنه برّه: التحويل بين الخزن
     * حركة على مستوى الشركة مش على مستوى فرع، وصاحب النظام قفل إنشاء
     * الخزن على المدير لنفس السبب.
     */
    public function cashStoresTransfer(Request $request): JsonResponse
    {
        $user = $request->actorOrFail();
        $body = $this->body($request);

        $fromId = $this->intId((string) ($body['fromId'] ?? 0));
        $toId   = $this->intId((string) ($body['toId'] ?? 0));
        if ($fromId === $toId) {
            throw new ApiException('اختر خزنتين مختلفتين');
        }
        $amount = $this->amount($body);
        $note   = isset($body['reason']) ? trim((string) $body['reason']) : '';

        [$outId, $inId] = $this->tx(function () use ($fromId, $toId, $amount, $note, $user): array {
            /* القفل بترتيب الرقم — الشرح فوق. بنقفل الاتنين قبل أي قراءة
               للرصيد عشان محدش يغيّره بينا وبين الكتابة. */
            [$lo, $hi] = $fromId < $toId ? [$fromId, $toId] : [$toId, $fromId];
            $a = $this->lockStore($lo);
            $b = $this->lockStore($hi);
            $from = (int) $a['id'] === $fromId ? $a : $b;
            $to   = (int) $a['id'] === $fromId ? $b : $a;

            if ((float) $from['balance'] < $amount) {
                throw new ApiException(
                    'رصيد «' . $from['name'] . '» ' . number_format((float) $from['balance'], 2)
                    . ' ج.م — مايكفيش للتحويل'
                );
            }

            /* السبب بيتكتب على الحركتين وفيه اسم الخزنة التانية: من غيره
               الدفتر بيبقى «صادر 5000» و«وارد 5000» من غير أي رابط بينهم. */
            $tail = $note !== '' ? ' — ' . $note : '';

            $outTxn = $this->applyCashTxn(
                $from, 'out', $amount,
                mb_substr('تحويل إلى «' . $to['name'] . '»' . $tail, 0, 190),
                null, null, null,
                $from['branch_id'] !== null ? (int) $from['branch_id'] : null,
                $user->username
            );
            $inTxn = $this->applyCashTxn(
                $to, 'in', $amount,
                mb_substr('تحويل من «' . $from['name'] . '»' . $tail, 0, 190),
                null, null, null,
                $to['branch_id'] !== null ? (int) $to['branch_id'] : null,
                $user->username
            );

            return [$outTxn, $inTxn];
        });

        $rows = DB::select(
            'SELECT * FROM cash_stores WHERE id IN (?,?) ORDER BY FIELD(id, ?, ?)',
            [$fromId, $toId, $fromId, $toId]
        );

        return ApiResponse::out([
            'ok'      => true,
            'from'    => FinanceWire::store($rows[0]),
            'to'      => FinanceWire::store($rows[1]),
            'outTxnId' => $outId,
            'inTxnId'  => $inId,
            'message' => 'اتحوّل ' . number_format($amount, 2) . ' ج.م',
        ]);
    }

    public function cashStoresDelete(string $id): JsonResponse
    {
        $id = $this->intId($id);

        $this->tx(function () use ($id): void {
            $store = $this->lockStore($id);
            if ((float) $store['balance'] != 0.0) {
                throw new ApiException('مينفعش حذف خزنة فيها رصيد — صفّر الرصيد الأول');
            }
            $chk = DB::select('SELECT id FROM cash_transactions WHERE store_id = ? LIMIT 1', [$id]);
            if ($chk) {
                throw new ApiException('مينفعش حذف خزنة عليها حركات — الأرشيف لازم يفضل');
            }
            DB::delete('DELETE FROM cash_stores WHERE id = ?', [$id]);
        });

        return ApiResponse::out(['ok' => true, 'message' => 'تم حذف الخزنة']);
    }

    /* ── 2) حركات النقدية: إنشاء + اعتماد المعلّق ─────────────── */

    /**
     * POST /api/cash-stores/{id}/transactions
     * 🔒 أدوار الفلوس (admin · branch · accountant).
     *
     * 🔴 `out` بيتفحص عليه رصيد الخزنة **جوه القفل** — الفحص برّه المعاملة
     * كان هيسمح لمنصرفين متوازيين ينزّلوا الرصيد تحت الصفر.
     *
     * ⚠️ `pending` مبيلمسش الرصيد خالص لحد الاعتماد (approve تحت).
     * ⚠️ `branchId` لو مش متبعت بياخد فرع الفاعل — والأدمن فرعه null فبيتخزن
     * NULL. سلوك الأصل.
     */
    public function cashTxnsCreate(Request $request, string $id): JsonResponse
    {
        $user = $request->actorOrFail();
        $storeId = $this->intId($id);
        $body = $this->body($request);

        $type = (string) ($body['type'] ?? '');
        if (! in_array($type, ['in', 'out', 'pending'], true)) {
            throw new ApiException('نوع الحركة لازم يكون in أو out أو pending');
        }
        $amount = $this->amount($body);
        $reason = isset($body['reason']) ? trim((string) $body['reason']) : null;
        $notes  = isset($body['notes'])  ? trim((string) $body['notes'])  : null;
        $pilotId = isset($body['pilotId']) && $body['pilotId'] !== '' ? (int) $body['pilotId'] : null;
        /* 🔒 رقم الفرع كان بيتاخد من **جسم الطلب** زي ما هو — فمشرف فرع
           كان بيقيّد حركة على أي فرع يكتبه. بقى من الجلسة للدور branch. */
        $branchId = $user->role === 'branch'
            ? $user->branchId
            : (isset($body['branchId']) && $body['branchId'] !== ''
                ? (int) $body['branchId'] : $user->branchId);

        // 🔒 والخزنة نفسها لازم تكون في نطاقه — كان بياخد أي رقم خزنة
        $this->assertStoreInScope($user, $storeId);

        $txnId = $this->tx(function () use (
            $storeId, $type, $amount, $reason, $notes, $pilotId, $branchId, $user
        ): int {
            $store = $this->lockStore($storeId);
            if ($type === 'out' && (float) $store['balance'] < $amount) {
                throw new ApiException('رصيد الخزنة لا يكفي لهذا المنصرف');
            }

            return $this->applyCashTxn(
                $store, $type, $amount, $reason, $notes, $pilotId, null, $branchId, $user->username
            );
        });

        $row = DB::select('SELECT * FROM cash_transactions WHERE id = ?', [$txnId])[0];

        return ApiResponse::out(['ok' => true, 'transaction' => FinanceWire::cashTxn($row)]);
    }

    /**
     * POST /api/cash-transactions/{id}/approve — اعتماد حركة معلّقة
     * 🔒 أدوار الفلوس. pending → in، والمبلغ بيدخل الرصيد.
     *
     * 🔴 ترتيب الأقفال **جزء من الصح** ومنقول زي ما هو:
     *   1) صف الحركة FOR UPDATE
     *   2) صف الخزنة FOR UPDATE (`finance_lock_store` — قيمتها متسابة عمدًا،
     *      الغرض القفل مش القراءة)
     *
     * 🔴 الحارس المزدوج: `UPDATE ... WHERE id = ? AND type = 'pending'` وبعده
     * فحص rowCount. القفل لوحده كان كفاية نظريًا، بس الشرط في الـWHERE هو
     * اللي بيضمن إن اعتمادين متوازيين مايزوّدوش الرصيد مرتين لو القفل
     * اتفلت لأي سبب. الرسالة «اتعتمدت من ثانية» بتوصل للتاني.
     */
    public function cashTxnsApprove(Request $request, string $id): JsonResponse
    {
        /* 🔒 الدالة دي مكانش فيها **ولا قراءة واحدة للفاعل** — أي حساب
           عدّى الميدلوير كان بيعتمد أي حركة معلّقة في الشركة، والاعتماد
           بيضيف المبلغ لرصيد خزنة ممكن تكون مش خزنته. */
        $actor = $request->actorOrFail();
        $txnId = $this->intId($id);

        $this->tx(function () use ($actor, $txnId): void {
            $txn = DB::select('SELECT * FROM cash_transactions WHERE id = ? FOR UPDATE', [$txnId])[0] ?? null;
            if (! $txn) {
                throw new ApiException('الحركة غير موجودة', 404);
            }
            $txn = (array) $txn;
            if ($txn['type'] !== 'pending') {
                throw new ApiException('الحركة دي مش معلّقة');
            }

            // 🔒 خزنة الحركة لازم تكون في نطاق الفاعل قبل أي تعديل رصيد
            $this->assertStoreInScope($actor, (int) $txn['store_id']);
            // القفل بس — الصف الراجع مش مستعمل، زي الأصل بالحرف
            $this->lockStore((int) $txn['store_id']);

            $affected = DB::update(
                "UPDATE cash_transactions SET type = 'in' WHERE id = ? AND type = 'pending'",
                [$txnId]
            );
            if ($affected === 0) {
                throw new ApiException('الحركة اتعتمدت من ثانية — اعمل تحديث');
            }

            DB::update(
                'UPDATE cash_stores SET balance = balance + ? WHERE id = ?',
                [(float) $txn['amount'], (int) $txn['store_id']]
            );
        });

        return ApiResponse::out([
            'ok'      => true,
            'message' => 'تم اعتماد الحركة ودخول المبلغ في رصيد الخزنة',
        ]);
    }

    /* ── 3) العهدة custody_transactions: إنشاء ────────────────── */

    /**
     * POST /api/custody
     * 🔒 أدوار الفلوس.
     *
     * الأنواع الأربعة: give / return / order_pending / order_extra — كلها
     * بتزوّد اللي على الطيار **ما عدا return** بتنقّص.
     *
     * 🔴 ترتيب الأقفال ثابت ومنقول بالحرف: **pilots ثم cash_stores**.
     * لو اتعكس، مسار العهدة ومسار التسوية هيعملوا deadlock تحت التزامن.
     *
     * حركة الخزنة اختيارية وبتحصل لـ`give`/`return` بس: تسليم عهدة = خروج
     * نقدية (`out`)، ردّ عهدة = دخول نقدية (`in`). و`order_pending`/
     * `order_extra` جايين من تسوية الأوردرات فمالهمش نقدية مقابلة.
     *
     * ⚠️ الرصيد بيتحدّث بـ`custody_balance + ?` (مش قراءة ثم كتابة) —
     * الصف مقفول فالجمع في القاعدة آمن ومتطابق مع الأصل.
     */
    public function custodyCreate(Request $request): JsonResponse
    {
        $user = $request->actorOrFail();
        $body = $this->body($request);

        $pilotId = (int) ($body['pilotId'] ?? 0);
        if ($pilotId <= 0) {
            throw new ApiException('معرّف الطيار مطلوب');
        }
        $type = (string) ($body['type'] ?? '');
        if (! in_array($type, self::CUSTODY_TYPES, true)) {
            throw new ApiException('نوع حركة العهدة لازم يكون give أو return أو order_pending أو order_extra');
        }
        /* 🔴 order_pending و order_extra **بيولّدهم النظام لوحده** من تسوية
           الأوردرات (BoardController::applyCustodyDelta بيعمل INSERT مباشر).
           المسار ده كان بيقبلهم من الإنترنت زي give/return بالظبط — و
           النوعين دول بيتخطّوا حركة الخزنة تمامًا (الشرط تحت on give/return
           بس). يعني كان ينفع حد يزوّد عهدة طيار بأي رقم **من غير ما جنيه
           يتحرك من أي درج**، والصف اللي بيتسجّل شكله بالظبط زي اللي النظام
           بيطلّعه — مفيش حاجة تفرّق بينهم في المراجعة.

           مفيش أي واجهة بتبعت النوعين دول (اتفحصت كل ملفات public/) فالقفل
           ده مابيكسرش حاجة شغّالة. */
        if (! in_array($type, ['give', 'return'], true)) {
            throw new ApiException('النوع ده بيتسجّل تلقائي من تسوية الأوردرات — مينفعش يتكتب بالإيد');
        }
        $amount  = $this->amount($body);
        /* 🔴 الواجهة بتبعت السبب من أول يوم (branch.html: خانة السبب في
           مودال العهدة) والكنترولر مكانش بيقراها خالص — كانت بتترمي
           وبتتكتب null في حركة الخزنة. النتيجة: تسليم عهدة 50 ألف على
           السيرفر مالوش سبب مكتوب، وصاحب النظام سأل «العهدة دي إزاي
           مكتوبة». السبب ده هو الإجابة، فلازم يوصل ويتخزّن.

           بيتقرا من reason وبيقع على notes للتوافق مع أي نسخة واجهة
           قديمة لسه شغّالة في متصفّح مفتوح. */
        $reason = trim((string) ($body['reason'] ?? $body['notes'] ?? ''));
        if ($reason === '') {
            throw new ApiException('اكتب سبب حركة العهدة');
        }
        $reason = mb_substr($reason, 0, 190);
        $storeId = isset($body['storeId']) && $body['storeId'] !== '' ? (int) $body['storeId'] : null;
        $branchId = isset($body['branchId']) && $body['branchId'] !== ''
            ? (int) $body['branchId'] : $user->branchId;

        $custodyId = $this->tx(function () use ($pilotId, $type, $amount, $reason, $storeId, $branchId, $user): int {
            // قفل صف الطيار الأول (ترتيب قفل ثابت: pilots ثم cash_stores)
            $pilot = DB::select(
                'SELECT id, name, custody_balance FROM pilots WHERE id = ? FOR UPDATE',
                [$pilotId]
            )[0] ?? null;
            if (! $pilot) {
                throw new ApiException('الطيار غير موجود', 404);
            }
            $pilot = (array) $pilot;

            // return بتنقّص اللي على الطيار — الباقي بيزوّد
            $delta = $type === 'return' ? -$amount : $amount;
            if ($type === 'return' && (float) $pilot['custody_balance'] < $amount) {
                throw new ApiException('المبلغ المردود أكبر من عهدة الطيار الحالية');
            }

            // حركة الخزنة الاختيارية: تسليم عهدة = خروج نقدية، ردّ عهدة = دخول نقدية
            if ($storeId !== null && in_array($type, ['give', 'return'], true)) {
                $store = $this->lockStore($storeId);
                $cashType = $type === 'give' ? 'out' : 'in';
                if ($cashType === 'out' && (float) $store['balance'] < $amount) {
                    throw new ApiException('رصيد الخزنة لا يكفي لتسليم العهدة');
                }
                /* عنوان الحركة في الخزنة بيفضل النص التلقائي (الكشف
                   بيتقرا بالعين)، والسبب اللي كتبه الموظف بيروح في خانة
                   الملاحظات جنبه. */
                $cashReason = $type === 'give'
                    ? 'تسليم عهدة للطيار ' . $pilot['name']
                    : 'ردّ عهدة من الطيار ' . $pilot['name'];
                $this->applyCashTxn(
                    $store, $cashType, $amount, $cashReason, $reason, $pilotId, null, $branchId, $user->username
                );
            }

            DB::insert(
                'INSERT INTO custody_transactions (pilot_id, type, amount, reason, store_id, branch_id, created_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$pilotId, $type, $amount, $reason, $storeId, $branchId, $user->username, WireTime::nowDb()]
            );
            $custodyId = (int) DB::getPdo()->lastInsertId();

            DB::update('UPDATE pilots SET custody_balance = custody_balance + ? WHERE id = ?', [$delta, $pilotId]);

            return $custodyId;
        });

        // القراءتين دول **برّه المعاملة** زي الأصل بالحرف
        $row = DB::select('SELECT * FROM custody_transactions WHERE id = ?', [$custodyId])[0];
        $bal = DB::select('SELECT custody_balance FROM pilots WHERE id = ?', [$pilotId])[0]->custody_balance ?? null;

        return ApiResponse::out([
            'ok'             => true,
            'transaction'    => FinanceWire::custody($row),
            'custodyBalance' => (float) $bal,
        ]);
    }

    /* ── 4) المصروفات expenses: إنشاء / تعديل / حذف ──────────── */

    /**
     * POST /api/expenses
     * 🔒 أدوار الفلوس.
     *
     * 🔴 الربط بخزنة اختياري: لو `cashStoreId` متبعت، المصروف بيولّد حركة
     * نقدية `out` بنفس المبلغ، و`expenses.cash_txn_id` بيتربط بيها — كل ده
     * في **معاملة واحدة**، فمستحيل يفضل مصروف بلا حركته أو العكس.
     *
     * ⚠️ ترتيب الجُمل منقول بالحرف: **الـINSERT بتاع المصروف قبل قفل
     * الخزنة**. عكسه بيغيّر ترتيب الأقفال تحت التزامن.
     *
     * التاريخ: لو مش متبعت = يوم القاهرة الحالي (مش UTC) — المصروف اللي
     * اتسجّل الساعة 1 بالليل بتوقيت القاهرة يقع على يومه الصح.
     */
    /** تصنيف المصروف — من قايمة الميزانية بس، وإلا NULL (غير مصنّف) */
    private function expenseCategory(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));

        return $v !== '' && in_array($v, \App\Wire\PilotAccountingWire::EXPENSE_CATEGORIES, true) ? $v : null;
    }

    public function expensesCreate(Request $request): JsonResponse
    {
        $user = $request->actorOrFail();
        $body = $this->body($request);

        $item = trim((string) ($body['item'] ?? ''));
        if ($item === '') {
            throw new ApiException('بند المصروف مطلوب');
        }
        $amount = $this->amount($body);
        $date = trim((string) ($body['date'] ?? '')) ?: BizDay::key();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ApiException('تاريخ المصروف غير صالح');
        }
        $notes = isset($body['notes']) ? trim((string) $body['notes']) : null;
        $category = $this->expenseCategory($body['category'] ?? null);
        $branchId = isset($body['branchId']) && $body['branchId'] !== ''
            ? (int) $body['branchId'] : $user->branchId;
        $cashStoreId = isset($body['cashStoreId']) && $body['cashStoreId'] !== ''
            ? (int) $body['cashStoreId'] : null;

        $expenseId = $this->tx(function () use (
            $item, $amount, $date, $notes, $branchId, $cashStoreId, $user, $category
        ): int {
            DB::insert(
                'INSERT INTO expenses (expense_date, item, category, amount, branch_id, notes, cash_store_id, created_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [$date, $item, $category, $amount, $branchId, $notes, $cashStoreId, $user->username, WireTime::nowDb()]
            );
            $expenseId = (int) DB::getPdo()->lastInsertId();

            if ($cashStoreId !== null) {
                $store = $this->lockStore($cashStoreId);
                if ((float) $store['balance'] < $amount) {
                    throw new ApiException('رصيد الخزنة لا يكفي لهذا المصروف');
                }
                $txnId = $this->applyCashTxn(
                    $store, 'out', $amount,
                    'مصروف: ' . $item, $notes, null, $expenseId, $branchId, $user->username
                );
                DB::update('UPDATE expenses SET cash_txn_id = ? WHERE id = ?', [$txnId, $expenseId]);
            }

            return $expenseId;
        });

        $row = DB::select('SELECT * FROM expenses WHERE id = ?', [$expenseId])[0];

        return ApiResponse::out(['ok' => true, 'expense' => FinanceWire::expense($row)]);
    }

    /**
     * PUT /api/expenses/{id}
     * 🔒 أدوار الفلوس.
     *
     * 🔴 تعديل مبلغ مصروف **مربوط بخزنة** بيولّد حركة تسوية بالفرق — مش
     * تعديل للحركة القديمة. الأرشيف المالي مايتغيّرش أبدًا، بيتزاد عليه:
     *   • الفرق موجب (المصروف زاد) → حركة `out` بالفرق، بشرط الرصيد يكفي.
     *   • الفرق سالب (المصروف قلّ)  → حركة `in` بـ`-$diff`.
     *
     * ⚠️ **المبلغ هنا مش بيمر على `finance_amount()`**: بيتقرّب الأول
     * (`round(...,2)`) وبعدين يتفحص `<= 0`. يعني `amount: 0.001` بيترفض هنا
     * بينما بيعدّي في مسار الإنشاء (باج الأصل — شوف notes).
     *
     * ⚠️ `!=` مرنة في `$amount != $oldAmount` — القيمة القديمة نص من الدرايفر.
     * ⚠️ `branch_id` بتتاخد من **المصروف** مش من الفاعل في حركة التسوية.
     * ⚠️ `cash_store_id` نفسه مش قابل للتعديل من هنا — الربط مابيتغيّرش.
     */
    public function expensesUpdate(Request $request, string $id): JsonResponse
    {
        $user = $request->actorOrFail();
        $id = $this->intId($id);
        $body = $this->body($request);

        $this->tx(function () use ($id, $body, $user): void {
            $exp = DB::select('SELECT * FROM expenses WHERE id = ? FOR UPDATE', [$id])[0] ?? null;
            if (! $exp) {
                throw new ApiException('المصروف غير موجود', 404);
            }
            $exp = (array) $exp;
            /* 🔒 كان بياخد {id} أي مصروف في الشركة — مشرف فرع يعدّل أو
               يمسح مصروف فرع تاني. */
            if ($user->role === 'branch') {
                $mine = (int) ($user->branchId ?? 0);
                if ($mine === 0 || $mine !== (int) $exp['branch_id']) {
                    throw ApiException::forbidden('المصروف ده مش تابع لفرعك');
                }
            }

            $item = array_key_exists('item', $body) ? trim((string) $body['item']) : $exp['item'];
            if ($item === '') {
                throw new ApiException('بند المصروف مطلوب');
            }
            $date = array_key_exists('date', $body) ? trim((string) $body['date']) : $exp['expense_date'];
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new ApiException('تاريخ المصروف غير صالح');
            }
            $notes  = array_key_exists('notes', $body)  ? trim((string) $body['notes']) : $exp['notes'];
            $amount = array_key_exists('amount', $body) ? round((float) $body['amount'], 2) : (float) $exp['amount'];
            if ($amount <= 0) {
                throw new ApiException('المبلغ لازم يكون رقم أكبر من صفر');
            }

            // تعديل المبلغ لمصروف مربوط بخزنة = تسوية فرق على الخزنة بنفس المعاملة
            $oldAmount = (float) $exp['amount'];
            if ($exp['cash_store_id'] !== null && $amount != $oldAmount) {
                $store = $this->lockStore((int) $exp['cash_store_id']);
                $diff = $amount - $oldAmount; // موجب = مصروف زاد = خروج نقدية إضافي
                if ($diff > 0) {
                    if ((float) $store['balance'] < $diff) {
                        throw new ApiException('رصيد الخزنة لا يكفي لزيادة المصروف');
                    }
                    $this->applyCashTxn(
                        $store, 'out', $diff,
                        'تسوية زيادة مصروف: ' . $item, null, null, $id,
                        $exp['branch_id'] !== null ? (int) $exp['branch_id'] : null, $user->username
                    );
                } else {
                    $this->applyCashTxn(
                        $store, 'in', -$diff,
                        'تسوية تخفيض مصروف: ' . $item, null, null, $id,
                        $exp['branch_id'] !== null ? (int) $exp['branch_id'] : null, $user->username
                    );
                }
            }

            $category = array_key_exists('category', $body) ? $this->expenseCategory($body['category']) : ($exp['category'] ?? null);
            DB::update(
                'UPDATE expenses SET item = ?, expense_date = ?, notes = ?, amount = ?, category = ? WHERE id = ?',
                [$item, $date, $notes, $amount, $category, $id]
            );
        });

        $row = DB::select('SELECT * FROM expenses WHERE id = ?', [$id])[0];

        return ApiResponse::out(['ok' => true, 'expense' => FinanceWire::expense($row)]);
    }

    /**
     * DELETE /api/expenses/{id}
     * 🔒 admin · accountant — **من غير branch** (عكس الإنشاء والتعديل).
     *
     * 🔴 لو المصروف مربوط بخزنة: حركة `in` عكسية بترجّع المبلغ للخزنة، ثم
     * صف المصروف بيتحذف. **حركة النقدية مبتتحذفش** — الأرشيف بيفضل.
     * التعليق ده حرفي من الأصل: «الأرشيف بيفضل — مفيش حذف حركات».
     */
    public function expensesDelete(Request $request, string $id): JsonResponse
    {
        $user = $request->actorOrFail();
        $id = $this->intId($id);

        $this->tx(function () use ($id, $user): void {
            $exp = DB::select('SELECT * FROM expenses WHERE id = ? FOR UPDATE', [$id])[0] ?? null;
            if (! $exp) {
                throw new ApiException('المصروف غير موجود', 404);
            }
            $exp = (array) $exp;
            /* 🔒 كان بياخد {id} أي مصروف في الشركة — مشرف فرع يعدّل أو
               يمسح مصروف فرع تاني. */
            if ($user->role === 'branch') {
                $mine = (int) ($user->branchId ?? 0);
                if ($mine === 0 || $mine !== (int) $exp['branch_id']) {
                    throw ApiException::forbidden('المصروف ده مش تابع لفرعك');
                }
            }

            // لو مربوط بخزنة: حركة عكسية بترجّع المبلغ (الأرشيف بيفضل — مفيش حذف حركات)
            if ($exp['cash_store_id'] !== null) {
                $store = $this->lockStore((int) $exp['cash_store_id']);
                $this->applyCashTxn(
                    $store, 'in', (float) $exp['amount'],
                    'إلغاء مصروف: ' . $exp['item'], null, null, $id,
                    $exp['branch_id'] !== null ? (int) $exp['branch_id'] : null, $user->username
                );
            }
            DB::delete('DELETE FROM expenses WHERE id = ?', [$id]);
        });

        return ApiResponse::out(['ok' => true, 'message' => 'تم حذف المصروف']);
    }

    /* ── 5) المحافظ wallets: إضافة / خصم / استخدام ───────────── */

    /**
     * POST /api/wallets/{ownerType}/{ownerId}/credit
     * 🔒 أدوار الفلوس — **مافيش بوابة محفظة هنا**: الأصل مابيندهش
     * finance_require_wallet_access() في credit/debit لأن الدور نفسه هو
     * البوابة (محل/عميل أصلًا مش داخلين). منقول زي ما هو.
     *
     * `type` بيقبل `credit` أو `discount`، وأي حاجة تانية **بتتحوّل لـ
     * `credit` بدل ما ترفض** — سلوك الأصل بالحرف (مش تحقق، تطبيع).
     */
    public function walletCredit(Request $request, string $ownerType, string $ownerId): JsonResponse
    {
        $user = $request->actorOrFail();
        [$type, $oid] = $this->walletOwner($ownerType, $ownerId);
        $body = $this->body($request);
        $amount = $this->amount($body);
        $txnType = (string) ($body['type'] ?? 'credit');
        if (! in_array($txnType, ['credit', 'discount'], true)) {
            $txnType = 'credit';
        }
        $note = isset($body['note']) ? trim((string) $body['note']) : null;
        $orderNum = isset($body['orderNum']) ? trim((string) $body['orderNum']) : null;

        $res = $this->walletMove($type, $oid, $txnType, $amount, $note, $orderNum, $user->username);

        return ApiResponse::out(['ok' => true, 'walletId' => $res['walletId'], 'balance' => $res['balance']]);
    }

    /**
     * POST /api/wallets/{ownerType}/{ownerId}/debit
     * 🔒 أدوار الفلوس. `type` بيقبل `debit` أو `settle`، وأي حاجة تانية
     * بتتحوّل لـ`debit`.
     */
    public function walletDebit(Request $request, string $ownerType, string $ownerId): JsonResponse
    {
        $user = $request->actorOrFail();
        [$type, $oid] = $this->walletOwner($ownerType, $ownerId);
        $body = $this->body($request);
        $amount = $this->amount($body);
        $txnType = (string) ($body['type'] ?? 'debit');
        if (! in_array($txnType, ['debit', 'settle'], true)) {
            $txnType = 'debit';
        }
        $note = isset($body['note']) ? trim((string) $body['note']) : null;
        $orderNum = isset($body['orderNum']) ? trim((string) $body['orderNum']) : null;

        $res = $this->walletMove($type, $oid, $txnType, $amount, $note, $orderNum, $user->username);

        return ApiResponse::out(['ok' => true, 'walletId' => $res['walletId'], 'balance' => $res['balance']]);
    }

    /**
     * POST /api/wallets/{ownerType}/{ownerId}/use — استخدام رصيد في أوردر
     *
     * 🔒 المسار ده **مالوش middleware دور** عن قصد: الأصل بينده require_auth()
     * لأنه بيتنده من مسارات إنشاء الأوردر (المحل والعميل نفسهم). البوابة هي
     * finance_require_wallet_access() جوه الكنترولر، وترتيبها بالحرف:
     *   1) actorOrFail() → 401
     *   2) walletOwner()  → 400
     *   3) بوابة الوصول   → 403
     *
     * التعليق الأصلي منقول لأنه بيوثّق ثغرة اتصلحت: «كان مفتوح لأي حساب
     * مسجّل — فمحل كان يقدر يفرّغ محفظة محل تاني (IDOR كتابة على فلوس، مؤكّد
     * بالاختبار). دلوقتي المحل/العميل محفظته هو بس».
     *
     * الفرق عن debit: النوع `use` ثابت، ورقم الأوردر **إلزامي**، والملاحظة
     * الافتراضية بتتولّد منه.
     */
    public function walletUse(Request $request, string $ownerType, string $ownerId): JsonResponse
    {
        $user = $request->actorOrFail();
        [$type, $oid] = $this->walletOwner($ownerType, $ownerId);
        $this->requireWalletAccess($type, $oid, $user);
        $body = $this->body($request);
        $amount = $this->amount($body);
        $orderNum = trim((string) ($body['orderNum'] ?? ''));
        if ($orderNum === '') {
            throw new ApiException('رقم الأوردر مطلوب لاستخدام رصيد المحفظة');
        }
        $note = isset($body['note']) ? trim((string) $body['note']) : ('استخدام في أوردر ' . $orderNum);

        $res = $this->walletMove($type, $oid, 'use', $amount, $note, $orderNum, $user->username);

        return ApiResponse::out(['ok' => true, 'walletId' => $res['walletId'], 'balance' => $res['balance']]);
    }

    /* ── 6) الحضور: دخول / نبض / انصراف ──────────────────────── */

    /**
     * POST /api/attendance/check-in
     * 🔒 require_auth() بس — **أي حساب مسجّل** بيسجّل حضوره. مفيش
     * middleware دور، منقول زي ما هو.
     *
     * الإدارة تقدر تسجّل حضور لموظف يدوي بالاسم (`username` في الجسم):
     *   • `role` وقتها بيتاخد من الجسم بافتراضي `manual`.
     *   • `entry_type` بيبقى `manual` بدل `auto`.
     * أي دور تاني بيبعت `username` بيتجاهل تمامًا — الجلسة لصاحبها.
     *
     * ⚠️ **idempotent**: لو فيه جلسة مفتوحة النهارده بنحدّث النبض ونرجّعها
     * بدل ما نفتح تانية — والقفل `FOR UPDATE` هو اللي بيمنع نداءين
     * متوازيين من فتح جلستين. اليوم بمفتاح **القاهرة** مش UTC.
     */
    public function attendanceCheckIn(Request $request): JsonResponse
    {
        $user = $request->actorOrFail();
        $body = $this->body($request);

        // الإدارة تقدر تسجل حضور لموظف يدوي بالاسم — غير كده الجلسة لصاحبها
        $username = $user->role === 'admin' && ! empty($body['username'])
            ? trim((string) $body['username']) : $user->username;
        $role = $username === $user->username ? $user->role : trim((string) ($body['role'] ?? 'manual'));
        $entryType = $username === $user->username ? 'auto' : 'manual';

        $day = BizDay::key();
        $now = WireTime::nowDb();

        $sessionId = $this->tx(function () use ($day, $username, $role, $entryType, $now): int {
            // قفل جلسات اليوم للمستخدم ده — منع فتح جلستين متوازيتين
            $open = DB::select(
                'SELECT id FROM attendance_sessions
                 WHERE session_date = ? AND username = ? AND check_out IS NULL
                 ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [$day, $username]
            )[0] ?? null;
            if ($open) {
                // جلسة مفتوحة بالفعل — نحدث النبض ونرجعها (idempotent)
                DB::update('UPDATE attendance_sessions SET last_seen = ? WHERE id = ?', [$now, (int) $open->id]);

                return (int) $open->id;
            }
            DB::insert(
                'INSERT INTO attendance_sessions (session_date, username, role, check_in, last_seen, entry_type, created_at)
                 VALUES (?,?,?,?,?,?,?)',
                [$day, $username, $role, $now, $now, $entryType, $now]
            );

            return (int) DB::getPdo()->lastInsertId();
        });

        $row = DB::select('SELECT * FROM attendance_sessions WHERE id = ?', [$sessionId])[0];

        return ApiResponse::out([
            'ok'      => true,
            'day'     => $day,
            'session' => FinanceWire::attendanceSession($row),
        ]);
    }

    /**
     * POST /api/attendance/heartbeat — نبضة الجلسة المفتوحة
     * 🔒 require_auth() بس. **مفيش معاملة ومفيش قفل** — جملة SELECT وجملة
     * UPDATE مستقلتين، وده سلوك الأصل بالحرف (النبضة مش فلوس).
     *
     * ⚠️ التعليق الأصلي منقول لأنه بيوثّق باج اتصلح: «SELECT ثم UPDATE —
     * الاعتماد على rowCount في UPDATE بيرجّع 0 لو last_seen متغيّرش (نبضة في
     * نفس الثانية) فكان بيبان غلط إن مفيش جلسة مفتوحة».
     *
     * الرد بيفرّق: `open:false` (الواجهة المفروض تعمل check-in) أو `open:true`.
     * الحالتين **200** — مفيش خطأ هنا خالص.
     */
    public function attendanceHeartbeat(Request $request): JsonResponse
    {
        $user = $request->actorOrFail();
        $day = BizDay::key();
        $now = WireTime::nowDb();

        $open = DB::select(
            'SELECT id FROM attendance_sessions
             WHERE session_date = ? AND username = ? AND check_out IS NULL
             ORDER BY id DESC LIMIT 1',
            [$day, $user->username]
        )[0] ?? null;

        if (! $open) {
            // مفيش جلسة مفتوحة النهارده — الواجهة المفروض تعمل check-in
            return ApiResponse::out(['ok' => true, 'open' => false]);
        }

        DB::update('UPDATE attendance_sessions SET last_seen = ? WHERE id = ?', [$now, (int) $open->id]);

        return ApiResponse::out(['ok' => true, 'open' => true]);
    }

    /**
     * POST /api/attendance/check-out — انصراف يدوي
     * 🔒 require_auth() بس. الإدارة تقدر تنصرف لموظف بالاسم زي check-in.
     *
     * ⚠️ `auto_check_out = 0` بيتكتب صراحةً — عشان الانصراف اليدوي بعد
     * انصراف تلقائي يمسح العلامة. والفرق ده بيغيّر **نوع** `checkOut` على
     * السلك (نص ISO بدل رقم epoch) — شوف FinanceWire::attendanceSession.
     */
    public function attendanceCheckOut(Request $request): JsonResponse
    {
        $user = $request->actorOrFail();
        $body = $this->body($request);
        $username = $user->role === 'admin' && ! empty($body['username'])
            ? trim((string) $body['username']) : $user->username;

        $day = BizDay::key();
        $now = WireTime::nowDb();

        $sessionId = $this->tx(function () use ($day, $username, $now): int {
            $open = DB::select(
                'SELECT id FROM attendance_sessions
                 WHERE session_date = ? AND username = ? AND check_out IS NULL
                 ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [$day, $username]
            )[0] ?? null;
            if (! $open) {
                throw new ApiException('مفيش جلسة حضور مفتوحة النهارده');
            }

            DB::update(
                'UPDATE attendance_sessions SET check_out = ?, last_seen = ?, auto_check_out = 0 WHERE id = ?',
                [$now, $now, (int) $open->id]
            );

            return (int) $open->id;
        });

        $row = DB::select('SELECT * FROM attendance_sessions WHERE id = ?', [$sessionId])[0];

        return ApiResponse::out(['ok' => true, 'session' => FinanceWire::attendanceSession($row)]);
    }

    /* ── 7) الموظفين اليدويين: إنشاء / تعديل / حذف ───────────── */

    /**
     * POST /api/manual-employees
     * 🔒 admin · hr — **من غير branch** (القراءة بتسمح للفرع، الكتابة لأ).
     * مفيش معاملة: صف واحد في جدول واحد.
     *
     * ⚠️ `isset(...) ? trim(...) : null` — يعني `phone: null` بيتخزّن NULL
     * (isset بترجّع false مع null)، و`phone: ""` بيتخزّن نص فاضي.
     */
    public function manualEmployeesCreate(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new ApiException('اسم الموظف مطلوب');
        }

        DB::insert(
            'INSERT INTO manual_employees (name, phone, job_title, notes, created_at) VALUES (?,?,?,?,?)',
            [
                $name,
                isset($body['phone'])    ? trim((string) $body['phone'])    : null,
                isset($body['jobTitle']) ? trim((string) $body['jobTitle']) : null,
                isset($body['notes'])    ? trim((string) $body['notes'])    : null,
                WireTime::nowDb(),
            ]
        );
        $id = (int) DB::getPdo()->lastInsertId();

        $row = DB::select('SELECT * FROM manual_employees WHERE id = ?', [$id])[0];

        return ApiResponse::out(['ok' => true, 'employee' => FinanceWire::manualEmployee($row)]);
    }

    /**
     * PUT /api/manual-employees/{id}
     * 🔒 admin · hr. تعديل جزئي: `array_key_exists` بيفرّق بين «مش متبعت»
     * (تفضل زي ما هي) و«متبعت» (تتكتب حتى لو فاضية).
     *
     * ⚠️ `trim((string)$body['phone'])` على `null` بيدّي **نص فاضي مش NULL** —
     * يعني `phone: null` في التعديل بيخزّن "" بينما في الإنشاء بيخزّن NULL.
     * تفاوت في الأصل، منقول زي ما هو.
     */
    public function manualEmployeesUpdate(Request $request, string $id): JsonResponse
    {
        $id = $this->intId($id);
        $body = $this->body($request);

        $emp = DB::select('SELECT * FROM manual_employees WHERE id = ?', [$id])[0] ?? null;
        if (! $emp) {
            throw new ApiException('الموظف غير موجود', 404);
        }
        $emp = (array) $emp;

        $name = array_key_exists('name', $body) ? trim((string) $body['name']) : $emp['name'];
        if ($name === '') {
            throw new ApiException('اسم الموظف مطلوب');
        }

        DB::update(
            'UPDATE manual_employees SET name = ?, phone = ?, job_title = ?, notes = ? WHERE id = ?',
            [
                $name,
                array_key_exists('phone', $body)    ? trim((string) $body['phone'])    : $emp['phone'],
                array_key_exists('jobTitle', $body) ? trim((string) $body['jobTitle']) : $emp['job_title'],
                array_key_exists('notes', $body)    ? trim((string) $body['notes'])    : $emp['notes'],
                $id,
            ]
        );

        $row = DB::select('SELECT * FROM manual_employees WHERE id = ?', [$id])[0];

        return ApiResponse::out(['ok' => true, 'employee' => FinanceWire::manualEmployee($row)]);
    }

    /**
     * DELETE /api/manual-employees/{id}
     * 🔒 admin · hr. حذف حقيقي — الموظف اليدوي مالوش أرشيف مالي.
     * (جلسات حضوره في `attendance_sessions` بتفضل، مربوطة بالاسم مش بالـid.)
     */
    public function manualEmployeesDelete(string $id): JsonResponse
    {
        $id = $this->intId($id);

        $affected = DB::delete('DELETE FROM manual_employees WHERE id = ?', [$id]);
        if ($affected === 0) {
            throw new ApiException('الموظف غير موجود', 404);
        }

        return ApiResponse::out(['ok' => true, 'message' => 'تم حذف الموظف']);
    }

    /* ═══════════════════════════════════════════════════════════
       أدوات داخلية — نقل حرفي لدوال finance_* المساعدة
    ═══════════════════════════════════════════════════════════ */

    /**
     * المقابل لـ finance_int_id(): المعرّف لازم يبقى موجب.
     * الـcast بيخلي "abc" → 0 و"-5" → -5، والاتنين بيرجعوا 400 مش 404.
     */
    /**
     * 🔒 نطاق الفرع للمسارات المالية — **مشرف الفرع مقفول على فرعه**.
     *
     * كل قراءات وكتابات الملف ده كانت بلا أي فلترة فرع: `?branchId=` بيتقرا
     * من الطلب وبس، ومن غيره بترجّع الشركة كلها. يعني مشرف فرع كان بيقرا
     * أرصدة كل الخزن وحركاتها وعُهد كل الطيارين ومصروفات كل الفروع —
     * ويكتب عليها كمان.
     *
     * بيرجّع رقم الفرع الملزِم، أو `null` للأدمن/المحاسب لو مطلبش فرع بعينه.
     *
     * ⚠️ فخ `branchId = 0`: مشرف فرع بلا `branch_id` كان بياخد صفر، والشرط
     * `if ($branchId)` كان بيتخطّى الفلترة **خالص** فيشوف كل الفروع.
     * هنا بنرفض على طول بدل ما نفتح.
     */
    private function scopeBranch(Actor $actor, mixed $requested): ?int
    {
        $req = $requested !== null && $requested !== '' ? (int) $requested : null;

        if ($actor->role !== 'branch') {
            return $req;   // الإدارة والمحاسب: اللي بيطلبوه
        }
        $mine = (int) ($actor->branchId ?? 0);
        if ($mine === 0) {
            throw ApiException::forbidden('حسابك مش مربوط بفرع');
        }

        return $mine;   // المطلوب بيتتجاهل — الفرع بتاعه هو الحاكم
    }

    /** 🔒 الخزنة دي في نطاق الفاعل؟ — بتتنده قبل أي قراءة أو كتابة عليها */
    private function assertStoreInScope(Actor $actor, int $storeId): array
    {
        $row = DB::select('SELECT * FROM cash_stores WHERE id = ? LIMIT 1', [$storeId])[0] ?? null;
        if (! $row) {
            throw ApiException::notFound('الخزنة غير موجودة');
        }
        if ($actor->role === 'branch') {
            $mine = (int) ($actor->branchId ?? 0);
            if ($mine === 0 || $mine !== (int) $row->branch_id) {
                throw ApiException::forbidden('الخزنة دي مش تابعة لفرعك');
            }
        }

        return (array) $row;
    }

    private function intId(string $id): int
    {
        $n = (int) $id;
        if ($n <= 0) {
            throw new ApiException('معرّف غير صالح');
        }

        return $n;
    }

    /** المقابل لـ finance_wallet_owner() — النوع أول، وبعده المعرّف */
    private function walletOwner(string $ownerType, string $ownerId): array
    {
        if (! in_array($ownerType, ['customer', 'store'], true)) {
            throw new ApiException('نوع المحفظة لازم يكون customer أو store');
        }

        return [$ownerType, $this->intId($ownerId)];
    }

    /**
     * بوابة الوصول للمحفظة — المقابل لـ finance_require_wallet_access().
     *
     * 🔒 التعليق الأصلي منقول كما هو لأنه بيوثّق ثغرة اتصلحت: «كانت مفقودة
     * تمامًا، فأي حساب مسجّل كان يقدر يقرا أو يحرّك محفظة أي محل/عميل بتعداد
     * المعرّفات (IDOR مالي مؤكّد بالاختبار)».
     *
     *   • الإدارة/الفرع/الكول سنتر/الحسابات: أي محفظة
     *   • المحل: محفظته هو بس — owner_id لازم يساوي user_id بتاعه
     *   • العميل: محفظته هو بس — owner_id لازم يساوي customer_id بتاعه
     *   • أي دور تاني (طيار مثلًا): مرفوض
     *
     * ⚠️ المطابقة **بالنوع كمان مش بالمعرّف بس**: محل رقمه 7 مايشوفش محفظة
     * customer/7. من غير شرط النوع كان الرقم لوحده هيفتح محفظة حد تاني.
     */
    private function requireWalletAccess(string $type, int $oid, Actor $actor): void
    {
        if ($actor->hasRole('admin', 'branch', 'callcenter', 'accountant')) {
            return;
        }
        if ($actor->role === 'store' && $type === 'store' && (int) ($actor->userId ?? 0) === $oid) {
            return;
        }
        if ($actor->role === 'customer' && $type === 'customer' && (int) ($actor->customerId ?? 0) === $oid) {
            return;
        }

        throw ApiException::forbidden('غير مسموح لك بالوصول لهذه المحفظة');
    }

    /* ── أدوات الكتابة ───────────────────────────────────────── */

    /**
     * المقابل لـ body_json(): الجسم كمصفوفة، والفاضي أو المكسور = [].
     *
     * `$request->json()->all()` مش `input()` عن قصد: كل مسارات التعديل هنا
     * بتستخدم `array_key_exists($k, $body)` عشان تفرّق بين «الحقل مش متبعت»
     * و«متبعت بقيمة فاضية/null» — والتفرقة دي هي أساس التعديل الجزئي.
     * لازم نمسك المصفوفة الخام مش مصدر مدخلات مدموج.
     */
    private function body(Request $request): array
    {
        return $request->json()->all();
    }

    /**
     * 🔴 المقابل لـ finance_amount(): مبلغ موجب إجباري.
     *
     *     $a = (float)($body[$field] ?? 0);
     *     if ($a <= 0) fail('المبلغ لازم يكون رقم أكبر من صفر');
     *     return round($a, 2);
     *
     * ⚠️ **باج معروف منقول زي ما هو**: الفحص `> 0` بيحصل **قبل** التقريب،
     * فمبلغ زي `0.001` بيعدّي الفحص وبيتخزّن `0.00` في العمود
     * DECIMAL(12,2). النتيجة: صف حركة بمبلغ صفر (وفي مسار المحفظة رصيد
     * مابيتغيّرش مع سجل حركة موجود). الإصلاح تغيير سلوك، فاتساب.
     *
     * ملاحظة: `finance_expenses_update` **مابتندهش الدالة دي** — بتقرّب
     * الأول وتفحص بعدين، فبترفض 0.001. التفاوت ده في الأصل ومنقول.
     */
    private function amount(array $body, string $field = 'amount'): float
    {
        $a = (float) ($body[$field] ?? 0);
        if ($a <= 0) {
            throw new ApiException('المبلغ لازم يكون رقم أكبر من صفر');
        }

        return round($a, 2);
    }

    /**
     * المقابل لـ finance_tx(): تنفيذ عمل جوه معاملة مع rollback تلقائي.
     *
     * الأصل:
     *     $pdo->beginTransaction();
     *     try { $r = $work($pdo); $pdo->commit(); return $r; }
     *     catch (Throwable $e) { rollBack; error_log; fail('حصل خطأ …', 500); }
     *
     * 🔴 **إعادة رمي ApiException مش تفصيلة**: في الأصل `fail()` بتعمل exit،
     * فرسالة المسار الخاصة (زي «رصيد الخزنة لا يكفي») مكانتش بتوصل للـcatch
     * أبدًا. لو سيبناها تتبلع هنا، كل أخطاء التحقق كانت هتتحوّل لرسالة الـ500
     * العامة — وده أوضح تغيير سلوك ممكن. اللي بيوصل للـcatch أخطاء تقنية بس.
     */
    private function tx(callable $work): mixed
    {
        try {
            return DB::transaction($work);
        } catch (ApiException $e) {
            throw $e;   // fail() القديمة كانت exit — مكانتش بتعدي على الـcatch
        } catch (Throwable $e) {
            Log::error('finance tx error: ' . $e->getMessage());
            throw new ApiException('حصل خطأ أثناء تنفيذ العملية المالية — حاول تاني', 500);
        }
    }

    /**
     * 💰 المقابل لـ finance_lock_store(): قفل صف خزنة وإرجاعه.
     * **لازم يتندى جوه معاملة مفتوحة** — القفل بيفضل لحد الـcommit.
     *
     * `SELECT *` مش `SELECT id, balance` — الأصل بيرجّع الصف كامل والمناديين
     * بيقروا `id` و`balance` منه. سيبناه زي ما هو.
     */
    private function lockStore(int $storeId): array
    {
        $store = DB::select('SELECT * FROM cash_stores WHERE id = ? FOR UPDATE', [$storeId])[0] ?? null;
        if (! $store) {
            throw new ApiException('الخزنة غير موجودة', 404);
        }

        return (array) $store;
    }

    /**
     * 💰 المقابل لـ finance_apply_cash_txn(): إدراج حركة نقدية + تعديل
     * رصيد الخزنة. جوه معاملة مفتوحة **والصف مقفول** بـlockStore().
     *
     * تفاصيل ممنوع تتغير:
     *  • الإشارة: `in` موجب · `out` سالب · `pending` **صفر** (مبيلمسش
     *    الرصيد لحد الاعتماد عبر cashTxnsApprove).
     *  • الرصيد بيتعدّل بـ`balance = balance + ?` مش قراءة ثم كتابة.
     *  • `if ($delta != 0.0)` مقارنة **مرنة** زي الأصل — مع pending
     *    بتتخطى جملة الـUPDATE بالكامل فمفيش لمسة على الصف أصلًا.
     *  • ترتيب الجُمل: **الحركة تتسجّل الأول ثم الرصيد يتعدّل**.
     */
    private function applyCashTxn(
        array $store,
        string $type,
        float $amount,
        ?string $reason,
        ?string $notes,
        ?int $relatedPilotId,
        ?int $relatedExpenseId,
        ?int $branchId,
        string $createdBy,
    ): int {
        DB::insert(
            'INSERT INTO cash_transactions
               (store_id, type, amount, reason, notes, related_pilot_id, related_expense_id, branch_id, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                (int) $store['id'], $type, $amount, $reason, $notes,
                $relatedPilotId, $relatedExpenseId, $branchId, $createdBy, WireTime::nowDb(),
            ]
        );
        $txnId = (int) DB::getPdo()->lastInsertId();

        if ($type === 'in') {
            $delta = $amount;
        } elseif ($type === 'out') {
            $delta = -$amount;
        } else { // pending — مبيلمسش الرصيد لحد الاعتماد
            $delta = 0.0;
        }
        if ($delta != 0.0) {
            DB::update('UPDATE cash_stores SET balance = balance + ? WHERE id = ?', [$delta, (int) $store['id']]);
        }

        return $txnId;
    }

    /**
     * 💰 المقابل لـ finance_lock_wallet(): صف المحفظة مقفول — **وبينشئها
     * برصيد صفر لو مش موجودة**. جوه معاملة مفتوحة.
     *
     * 🔴 `INSERT IGNORE` مش «تكاسل»: هو اللي بيمتص سباق الإنشاء المتزامن على
     * الفهرس الفريد `uq_wallets_owner`. طلبين بيحرّكوا محفظة عميل جديد في
     * نفس اللحظة — الاتنين بيلاقوا الصف مش موجود، واحد بيدخّله والتاني
     * الـINSERT بتاعه بيتجاهل بدل ما يرمي 1062، وبعدين الاتنين بيقروه
     * مقفول. التعليق ده حرفي من الأصل.
     *
     * الـSELECT التاني **بـFOR UPDATE برضه** — من غيره كنا هنمسك صف غير
     * مقفول ونحسب عليه رصيد.
     */
    private function lockWallet(string $ownerType, int $ownerId): array
    {
        $sql = 'SELECT * FROM wallets WHERE owner_type = ? AND owner_id = ? FOR UPDATE';

        $wallet = DB::select($sql, [$ownerType, $ownerId])[0] ?? null;
        if ($wallet) {
            return (array) $wallet;
        }

        // INSERT IGNORE بيمتص سباق الإنشاء المتزامن على uq_wallets_owner
        DB::insert(
            'INSERT IGNORE INTO wallets (owner_type, owner_id, balance, created_at) VALUES (?,?,0,?)',
            [$ownerType, $ownerId, WireTime::nowDb()]
        );

        $wallet = DB::select($sql, [$ownerType, $ownerId])[0] ?? null;
        if (! $wallet) {
            throw new ApiException('تعذر إنشاء المحفظة', 500);
        }

        return (array) $wallet;
    }

    /**
     * 🔴 المحرك المشترك لكل حركات المحفظة — المقابل لـ finance_wallet_move().
     *
     * الإشارة حسب السكيمة: `credit`/`discount` موجب — `debit`/`use`/`settle`
     * سالب. المبلغ الداخل **موجب دايمًا** (finance_amount ضامنة كده)
     * والإشارة بتتحدد هنا من النوع.
     *
     * تفاصيل فلوس ممنوع تتغير:
     *  • `round($balance + $signed, 2)` — التقريب على **الناتج** مش على
     *    الطرفين. ده الرقم اللي بيتخزّن في `wallets.balance` وفي
     *    `wallet_transactions.balance_after` سوا، فكشف الحساب متسق.
     *  • الرصيد السالب مرفوض بخطأ عربي — الفحص **بعد** التقريب، فرصيد
     *    -0.001 بيتقرّب لـ-0.0 وبيعدّي (0.0 مش < 0).
     *  • `UPDATE wallets` **قبل** `INSERT wallet_transactions` — ترتيب الأصل.
     *  • `amount` في السجل بيتخزّن **بالإشارة** مش مطلق.
     */
    private function walletMove(
        string $ownerType,
        int $ownerId,
        string $type,
        float $amount,
        ?string $note,
        ?string $orderNum,
        string $createdBy,
    ): array {
        // الإشارة حسب السكيمة: credit/discount موجب — debit/use/settle سالب
        $positive = in_array($type, ['credit', 'discount'], true);
        $signed = $positive ? $amount : -$amount;

        return $this->tx(function () use ($ownerType, $ownerId, $type, $signed, $note, $orderNum, $createdBy): array {
            $wallet = $this->lockWallet($ownerType, $ownerId);
            $newBalance = round((float) $wallet['balance'] + $signed, 2);
            if ($newBalance < 0) {
                throw new ApiException('رصيد المحفظة لا يكفي لإتمام العملية');
            }

            DB::update('UPDATE wallets SET balance = ? WHERE id = ?', [$newBalance, (int) $wallet['id']]);

            DB::insert(
                'INSERT INTO wallet_transactions
                   (wallet_id, amount, type, note, order_num, balance_after, created_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?)',
                [
                    (int) $wallet['id'], $signed, $type, $note, $orderNum,
                    $newBalance, $createdBy, WireTime::nowDb(),
                ]
            );

            return [
                'walletId' => (int) $wallet['id'],
                'balance'  => $newBalance,
                'txnId'    => (int) DB::getPdo()->lastInsertId(),
            ];
        });
    }
}
