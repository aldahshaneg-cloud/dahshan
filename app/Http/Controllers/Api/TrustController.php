<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Support\ApiResponse;
use App\Support\WireTime;
use App\Wire\TrustWire;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * منظومة الثقة — نقل حرفي لمسارات القراءة من api/routes/trust.php.
 *
 * 🔒 قاعدة الخصوصية (قرار صاحب النظام — إلزامية، بتتطبق هنا مش في الواجهة):
 *   - التقييم وعدد الشحنات: يظهروا **دايمًا** لأي طرف (دي فايدة الأمان للكل).
 *   - الاسم الكامل + العنوان + التليفون الكامل: بس لو الطالب اتعامل مع الشخص
 *     قبل كده (فيه أوردر بينهم) أو كان موظف (admin/branch/callcenter).
 *   - غير كده: اسم مقنّع (أول حرف + نجوم) + التقييم + عدد الشحنات، بلا عنوان
 *     ولا تليفون كامل. السبب: منع أي محل من سحب قاعدة عملاء الشركة.
 *   - كل عملية بحث بتتسجّل في lookup_log.
 *
 * الاستعلامات هنا **مكتوبة خام زي الأصل** مش Eloquent — تعبيرات مطابقة
 * التليفون (RIGHT(REPLACE(...))) جزء من سلوك البحث نفسه.
 */
class TrustController
{
    /** أقصى عدد عمليات بحث للطرف الواحد في الساعة — منع السحب الجماعي */
    public const LOOKUP_HOURLY_LIMIT = 60;

    /* ═══════════════════════════════════════════════════════════
       GET /api/lookup?phone= — قلب الميزة
    ═══════════════════════════════════════════════════════════ */

    /**
     * الأدوار المسموح لها: store · customer · branch · callcenter · admin
     * (مفروضة على مستوى المسار — بلا دخول أصلًا الرد 401 مش 403).
     */
    public function lookup(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $phone = $this->reqPhone($request->query('phone', ''));

        /* ── rate-limit: 60 بحث في الساعة للطرف الواحد ──
           العدّ على `actor_name` مش على الـIP: الهدف منع **الحساب** من سحب
           دفتر العملاء بالتدريج، والحساب بيفضل هو هو مهما اتغيّر الاتصال. */
        $actorName = TrustWire::actorName($actor);
        $used = (int) (DB::select(
            'SELECT COUNT(*) AS c FROM lookup_log WHERE actor_name = ? AND created_at >= (UTC_TIMESTAMP() - INTERVAL 1 HOUR)',
            [$actorName]
        )[0]->c ?? 0);
        if ($used >= self::LOOKUP_HOURLY_LIMIT) {
            throw new ApiException(
                'تجاوزت الحد المسموح للبحث (' . self::LOOKUP_HOURLY_LIMIT . ' عملية في الساعة) — حاول بعد شوية',
                429
            );
        }

        $info       = TrustWire::identityInfo($phone);
        $reputation = TrustWire::reputation($phone);
        $dealt      = TrustWire::hasDealtWith($phone, $actor);
        $staff      = $actor->isStaff();
        $fullAccess = $staff || $dealt;
        $found      = $info['found'] || $reputation['totalOrders'] > 0;

        /* ── تسجيل البحث (مراقبة) ──
           آه، ده كتابة جوه مسار GET — مقصودة: السجل ده هو نفسه أساس الـ
           rate-limit فوق، فمن غيره الحد مابيتحسبش. سلوك الأصل بالحرف. */
        DB::insert(
            'INSERT INTO lookup_log (actor_type, actor_name, searched_phone, found, full_access, created_at)
             VALUES (?,?,?,?,?,?)',
            [
                $actor->role, $actorName, $phone,
                $found ? 1 : 0, $fullAccess ? 1 : 0, WireTime::nowDb(),
            ]
        );

        return ApiResponse::ok([
            'found'                => $found,
            'phone'                => $fullAccess ? $phone : TrustWire::maskPhone($phone),
            'name'                 => $fullAccess ? $info['name'] : ($info['name'] !== null ? TrustWire::maskName($info['name']) : null),
            'address'              => $fullAccess ? $info['address'] : null,
            'fullAccess'           => $fullAccess,
            'reputation'           => $reputation,
            'dealtBefore'          => $dealt,
            // 🔒 اللي مالوش صلاحية كاملة بياخد **التقييم وعدد الشحنات بس**.
            // isRegisteredCustomer و lastOrderAt بيانات عن الشخص نفسه (هل هو عميل
            // مسجّل عند الشركة؟ وإمتى آخر شحنة ليه؟) — بتترجّع null من غير تعامل سابق.
            // الحقول باقية بأسمائها (دستور الـAPI: ممنوع حذف اسم حقل).
            'isRegisteredCustomer' => $fullAccess ? ($info['customerId'] !== null) : null,
            'lastOrderAt'          => $fullAccess ? $info['lastOrderAt'] : null,
            // معلومات مساعدة للواجهة (مش سرّية)
            'nameSource'           => $fullAccess ? $info['source'] : null,
            // ⚠️ nameVerified بيطلع **من غير شرط الصلاحية** زي الأصل بالظبط —
            // هل الاسم موثّق من موظف؟ حقيقة عن جودة البيانات مش عن الشخص.
            'nameVerified'         => $info['verified'],
            'staffAccess'          => $staff,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       GET /api/trust/{phone}/ratings — التقييمات المسجّلة
       (للموظفين، وللطرف اللي اتعامل مع الرقم ده قبل كده)
    ═══════════════════════════════════════════════════════════ */

    /**
     * 🔒 هنا **مفيش تقنيع** — الرد فيه أسماء المُقيِّمين وملاحظاتهم كاملة،
     * فالبوابة قبل/بعد مش مقنّعة: يا موظف يا اتعاملت مع الرقم، غير كده 403.
     */
    public function ratings(Request $request, string $phone): JsonResponse
    {
        $actor = $request->actorOrFail();
        $p     = $this->reqPhone($phone);

        if (! $actor->isStaff() && ! TrustWire::hasDealtWith($p, $actor)) {
            throw ApiException::forbidden('التفاصيل دي متاحة بس لو اتعاملت مع الرقم ده قبل كده');
        }

        $rows = DB::select(
            'SELECT r.*, o.order_num
             FROM party_ratings r LEFT JOIN orders o ON o.id = r.order_id
             WHERE r.subject_phone = ?
             ORDER BY r.created_at DESC, r.id DESC LIMIT 200',
            [$p]
        );

        // الرد ده **مش** غلاف قوايم الاستطلاع — مفيش serverNow ولا changed.
        // شكله {ok, phone, reputation, items} زي الأصل بالحرف.
        return ApiResponse::ok([
            'phone'      => $p,
            'reputation' => TrustWire::reputation($p),
            'items'      => array_map(fn ($r) => TrustWire::rating($r), $rows),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       GET /api/trust/lookup-log — سجل البحث (admin بس)
    ═══════════════════════════════════════════════════════════ */

    /**
     * 🔒 السجل ده هو اللي بيكشف محاولات سحب قاعدة العملاء (مين بحث عن مين).
     * الأدمن بس — لأنه بيفضح أنماط بحث الشركاء نفسهم.
     */
    public function lookupLog(Request $request): JsonResponse
    {
        $q = $request->query();

        $where = [];
        $args  = [];
        if (! empty($q['actor'])) {
            $where[] = 'actor_name = ?';
            $args[]  = (string) $q['actor'];
        }
        if (! empty($q['phone'])) {
            // بنطبّع الرقم قبل الفلترة عشان العمود متخزن مطبَّع دايمًا
            $where[] = 'searched_phone = ?';
            $args[]  = TrustWire::normalizePhone((string) $q['phone']);
        }
        $limit = isset($q['limit']) ? max(1, min(1000, (int) $q['limit'])) : 200;

        $sql = 'SELECT * FROM lookup_log' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;

        return ApiResponse::ok([
            'items' => array_map(
                fn ($r) => TrustWire::lookupLogEntry($r),
                DB::select($sql, $args)
            ),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       POST /api/orders/{id}/rate-receiver — المُرسِل يقيّم المستلم
    ═══════════════════════════════════════════════════════════ */

    /**
     * 🔒 المسار ده `require_auth()` بس — **مفيش middleware أدوار** عن قصد،
     * لأن المسموح له بالتقييم مش دور بعينه: هو **صاحب الشحنة** أيًا كان
     * (عميل تطبيق أو محل أو موظف أضاف الشحنة باسمه). البوابة الحقيقية هي
     * مطابقة الملكية تحت (customer_id أو added_by) مش قايمة أدوار.
     *
     * الترتيب هنا حرفي من الأصل وله معنى: التحقق من النجوم بيسبق قراءة
     * الأوردر، فتقييم بقيمة غلط على أوردر مش موجود بيرد «التقييم من 1 لـ 5
     * نجوم» 400 مش «الأوردر غير موجود» 404.
     */
    public function rateReceiver(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $b     = $this->body($request);
        $stars = $this->reqStars($b);
        $note  = trim((string) ($b['note'] ?? '')) ?: null;

        // `(int) $id` زي الأصل — أي id مش رقمي بيبقى 0 فبيرجّع 404
        $row = DB::select('SELECT * FROM orders WHERE id = ?', [(int) $id])[0] ?? null;
        if (! $row) {
            throw ApiException::notFound('الأوردر غير موجود');
        }
        $order = (array) $row;

        // 1) الأوردر لازم يكون خلص (تم التسليم / لم يتم التوصيل)
        // التقييم على شحنة لسه جارية مالوش معنى — لسه محصلش تعامل فعلي
        if (! in_array($order['status'], ['delivered', 'undelivered'], true)) {
            throw new ApiException('التقييم متاح بعد ما الأوردر يخلص (تم التسليم أو لم يتم التوصيل)');
        }

        // 2) المُنادي لازم يكون صاحب الشحنة فعلًا
        $role = $actor->role;
        if ($role === 'customer') {
            if ((int) ($order['customer_id'] ?? 0) !== (int) ($actor->customerId ?? 0)) {
                throw ApiException::forbidden('لا يمكنك تقييم أوردر لا يخصك');
            }
            $raterType = 'customer';
        } else {
            if ((string) ($order['added_by'] ?? '') !== $actor->username) {
                throw ApiException::forbidden('لا يمكنك تقييم أوردر لا يخصك');
            }
            // أي دور موظف غير المحل بيتسجّل `sender` — هو أضاف الشحنة باسم المُرسِل
            $raterType = $role === 'store' ? 'store' : 'sender';
        }

        // 3) الطرد المقصود — المحدد بـ deliveryId أو الأول
        if (isset($b['deliveryId']) && (int) $b['deliveryId'] > 0) {
            $d = DB::select(
                'SELECT * FROM order_deliveries WHERE id = ? AND order_id = ?',
                [(int) $b['deliveryId'], (int) $order['id']]
            )[0] ?? null;
        } else {
            $d = DB::select(
                'SELECT * FROM order_deliveries WHERE order_id = ? ORDER BY parcel_no, id LIMIT 1',
                [(int) $order['id']]
            )[0] ?? null;
        }
        if (! $d) {
            throw ApiException::notFound('الطرد غير موجود في الأوردر ده');
        }
        $delivery = (array) $d;

        // المفتاح هو الرقم المطبَّع — نفس قاعدة TrustWire (الرقم = هوية الشخص)
        $phone = TrustWire::normalizePhone((string) ($delivery['receiver_phone'] ?? ''));
        if (strlen($phone) < 7) {
            throw new ApiException('الطرد ده مالوش رقم مستلم صالح');
        }

        $info = TrustWire::identityInfo($phone);

        $ratingId = $this->insertRating([
            'subject_phone'       => $phone,
            'subject_customer_id' => $info['customerId'],
            'subject_user_id'     => $info['userId'],
            'order_id'            => (int) $order['id'],
            'rater_type'          => $raterType,
            'rater_name'          => $actor->name !== '' ? $actor->name : $actor->username,
            'rater_user_id'       => ! empty($actor->userId) ? (int) $actor->userId : null,
            'rater_customer_id'   => ! empty($actor->customerId) ? (int) $actor->customerId : null,
            'stars'               => $stars,
            'note'                => $note,
        ]);

        return ApiResponse::ok([
            'ratingId'   => $ratingId,
            'phone'      => $phone,
            // السمعة بتترجّع **بعد** التسجيل عشان الواجهة تعرض الرقم الجديد فورًا
            'reputation' => TrustWire::reputation($phone),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       POST /api/trust/rate — الشركة تقيّم أي حد (admin فقط)
    ═══════════════════════════════════════════════════════════ */

    /**
     * 🔒 `role:admin` على المسار. ده تقييم **بلا أوردر** — يعني مافيش أي
     * إثبات تعامل وراه، فمقصور على الإدارة وحدها.
     *
     * الرقم هو المفتاح دايمًا: لو اتبعت `customerId` أو `userId` بنجيب رقمهم
     * منهم، ولو اتبعت الرقم لوحده بنكمّل الربط الناقص من `identityInfo`.
     * كده الصف بيتخزّن مربوط بالحسابات حتى لو المُقيِّم بعت الرقم بس.
     */
    public function rateDirect(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $b     = $this->body($request);
        $stars = $this->reqStars($b);
        $note  = trim((string) ($b['note'] ?? '')) ?: null;

        $customerId = isset($b['customerId']) && (int) $b['customerId'] > 0 ? (int) $b['customerId'] : null;
        $userId     = isset($b['userId'])     && (int) $b['userId']     > 0 ? (int) $b['userId']     : null;
        $phone      = TrustWire::normalizePhone((string) ($b['phone'] ?? ''));

        // الرقم هو المفتاح — لو اتبعت id بنجيب رقمه منه
        if ($phone === '' && $customerId !== null) {
            $c = DB::select('SELECT phone1, phone2 FROM customers WHERE id = ?', [$customerId])[0] ?? null;
            if (! $c) {
                throw ApiException::notFound('العميل غير موجود');
            }
            // `?:` مرن زي الأصل — الرقم الأول الفاضي بيسقط للتاني
            $phone = TrustWire::normalizePhone((string) ($c->phone1 ?: $c->phone2));
        }
        if ($phone === '' && $userId !== null) {
            $u = DB::select('SELECT shop_phone, shop_phone2 FROM users WHERE id = ?', [$userId])[0] ?? null;
            if (! $u) {
                throw ApiException::notFound('الحساب غير موجود');
            }
            $phone = TrustWire::normalizePhone((string) ($u->shop_phone ?: $u->shop_phone2));
        }
        if (strlen($phone) < 7) {
            throw new ApiException('اكتب رقم تليفون صحيح أو اختار عميل/محل له رقم مسجّل');
        }

        // كمّل الربط الناقص من الرقم نفسه
        $info = TrustWire::identityInfo($phone);
        if ($customerId === null) {
            $customerId = $info['customerId'];
        }
        if ($userId === null) {
            $userId = $info['userId'];
        }

        $ratingId = $this->insertRating([
            'subject_phone'       => $phone,
            'subject_customer_id' => $customerId,
            'subject_user_id'     => $userId,
            'order_id'            => null,   // تقييم مباشر مش مرتبط بأوردر
            'rater_type'          => 'admin',
            'rater_name'          => $actor->name !== '' ? $actor->name : $actor->username,
            'rater_user_id'       => ! empty($actor->userId) ? (int) $actor->userId : null,
            'rater_customer_id'   => null,
            'stars'               => $stars,
            'note'                => $note,
        ]);

        return ApiResponse::ok([
            'ratingId'   => $ratingId,
            'phone'      => $phone,
            'reputation' => TrustWire::reputation($phone),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       PUT /api/trust/{phone}/identity — تصحيح الاسم/العنوان المعتمد
    ═══════════════════════════════════════════════════════════ */

    /**
     * 🔒 `role:admin,branch,callcenter` — الموظفين بس.
     *
     * الصف ده **بيغلب كل مصادر الاسم التانية** في `TrustWire::identityInfo`
     * (أولوية 0 قبل حساب العميل وقبل أسماء الطرود). يعني اللي بيكتب هنا
     * بيحسم اسم وعنوان الرقم في كل ردود `/api/lookup` بعد كده — عشان كده
     * مقصور على الموظفين ومسجّل عليه `verified_by`/`verified_at`.
     *
     * جملة واحدة (upsert) فمفيش معاملة — القيد الفريد على `subject_phone`
     * هو اللي بيمتص كتابتين متزامنتين على نفس الرقم.
     */
    public function identitySave(Request $request, string $phone): JsonResponse
    {
        $actor = $request->actorOrFail();
        $p     = $this->reqPhone($phone);
        $b     = $this->body($request);

        // بيقبل الاسمين: `name` من اللوحة و`canonicalName` من اللسان القديم
        $name = trim((string) ($b['name'] ?? $b['canonicalName'] ?? ''));
        if ($name === '') {
            throw new ApiException('اكتب الاسم المعتمد');
        }
        $address = trim((string) ($b['address'] ?? $b['canonicalAddress'] ?? '')) ?: null;

        $by  = $actor->name !== '' ? $actor->name : $actor->username;
        $now = WireTime::nowDb();

        DB::insert(
            'INSERT INTO party_identities (subject_phone, canonical_name, canonical_address, verified_by, verified_at)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE canonical_name = VALUES(canonical_name),
                                     canonical_address = VALUES(canonical_address),
                                     verified_by = VALUES(verified_by),
                                     verified_at = VALUES(verified_at)',
            [$p, $name, $address, $by, $now]
        );

        // بنعيد القراءة من identityInfo مش من اللي كتبناه — عشان الرد يعكس
        // ترتيب الحسم الكامل (مثلًا العنوان الفاضي بيسقط لعنوان العميل)
        $info = TrustWire::identityInfo($p);

        return ApiResponse::ok([
            'phone'    => $p,
            'identity' => [
                'name'       => $info['name'],
                'address'    => $info['address'],
                'source'     => $info['source'],
                'verified'   => $info['verified'],
                'verifiedBy' => $info['verifiedBy'],
                'verifiedAt' => $info['verifiedAt'],
            ],
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       أدوات داخلية
    ═══════════════════════════════════════════════════════════ */

    /**
     * المقابل لـ body_json(): المصفوفة الخام مش مصدر مدخلات مدموج.
     * (`TolerantJsonBody` ضامن إن الجسم الفاضي أو المكسور = `[]`.)
     */
    private function body(Request $request): array
    {
        return $request->json()->all();
    }

    /**
     * نجوم التقييم — لازم **رقم صحيح** من 1 لـ 5.
     *
     * ⚠️ التعليق ده منقول من الأصل لأنه بيوثّق باج اتصلح:
     * «قبل كده كان `(int)$b['stars']` على طول، فـ 3.5 كانت بتتقبل وتتحوّل
     * صامتة لـ 3 (و«[5]» بتبقى 1) — يعني الدرجة بتتلوّث بقيمة المستخدم ما
     * بعتهاش. دلوقتي أي حاجة مش رقم صحيح بترفض برسالة عربية بدل ما تتقرّب
     * من غير ما حد يعرف.»
     *
     * ⚠️ `$f != floor($f)` مقارنة **مرنة** زي الأصل بالحرف — ممنوع تتحوّل
     * لـ`!==` لأن `floor()` بترجّع float والمقارنة الصارمة على النوع نفسه
     * صح هنا، بس الفرق بيظهر مع النص «3» (is_numeric true, (float) 3.0).
     *
     * 🔴 ملاحظة: `support_order_rating` (تقييم الأوردر) **مابيستخدمش الدالة
     * دي** — لسه على `(int)` القديمة. التفاوت ده في الأصل ومنقول زي ما هو.
     */
    private function reqStars(array $b): int
    {
        $raw = $b['stars'] ?? null;
        if (! is_numeric($raw)) {
            throw new ApiException('التقييم من 1 لـ 5 نجوم');
        }
        $f = (float) $raw;
        if ($f != floor($f)) {
            throw new ApiException('التقييم لازم يكون رقم صحيح من 1 لـ 5 نجوم');
        }
        $stars = (int) $f;
        if ($stars < 1 || $stars > 5) {
            throw new ApiException('التقييم من 1 لـ 5 نجوم');
        }

        return $stars;
    }

    /**
     * تسجيل تقييم — بيلتقط خرق المفتاح الفريد ويحوّله لرسالة عربية.
     *
     * الفريد مبني على **عمود محسوب** (`rater_uid`) عشان NULL في MariaDB
     * مايبطّلش القيد: لو الفريد كان على (order_id, rater_user_id) مباشرة،
     * صفين بـNULL كانوا هيعدّوا الاتنين. المحسوب بيحوّل الـNULL لقيمة ثابتة.
     *
     * أي خطأ قاعدة تاني بيتعاد رميه فيوصل للمعالج المركزي برسالة «خطأ في
     * قاعدة البيانات» 500 — زي `throw $e` في الأصل بالظبط.
     */
    private function insertRating(array $row): int
    {
        try {
            DB::insert(
                'INSERT INTO party_ratings
                   (subject_phone, subject_customer_id, subject_user_id, order_id,
                    rater_type, rater_name, rater_user_id, rater_customer_id, stars, note, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $row['subject_phone'], $row['subject_customer_id'], $row['subject_user_id'],
                    $row['order_id'], $row['rater_type'], $row['rater_name'],
                    $row['rater_user_id'], $row['rater_customer_id'],
                    $row['stars'], $row['note'], WireTime::nowDb(),
                ]
            );

            return (int) DB::getPdo()->lastInsertId();
        } catch (QueryException $e) {
            // SQLSTATE 23000 = خرق قيد فريد (نفس `$e->getCode() === '23000'` في الأصل)
            if ((string) $e->getCode() === '23000') {
                throw new ApiException('أنت قيّمت الأوردر ده قبل كده', 409);
            }

            throw $e;
        }
    }

    /**
     * رقم من الطلب → الشكل الموحّد، مع رفض الفاضي/القصير.
     * الحد 7 خانات: أقصر رقم أرضي مصري معقول — أقل من كده بحث فاضي بيرجّع
     * نتايج عشوائية لأن المطابقة بتتم على آخر 10 أرقام.
     */
    private function reqPhone(mixed $raw): string
    {
        $phone = TrustWire::normalizePhone((string) $raw);
        if (strlen($phone) < 7) {
            // النص حرفي زي الأصل — بوابة التطابق بتقارن الرسالة نصًا
            throw new ApiException('اكتب رقم تليفون صحيح');
        }

        return $phone;
    }
}
