<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Support\ApiResponse;
use App\Support\PollableList;
use App\Support\WireTime;
use App\Wire\CustomerWire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * عملاء التطبيق + ملف استلام المحل — نقل حرفي لمسارات القراءة من
 * api/routes/customers.php.
 *
 * الملف القديم بيلمّ مجالين مع بعض (لوحة customers.html + ملف المحل)،
 * وسايبينهم مع بعض هنا برضه عشان المقارنة مع الأصل تفضل سطر بسطر.
 *
 * الاستعلامات **مكتوبة خام زي الأصل** مش Eloquent: أسماء الأعمدة المشتقة
 * من الـjoin (`zone_name` / `branch_name` / `_zone_name` / `_zone_price`)
 * جزء من عقد طبقة السلك — CustomerWire بيقراها بالاسم ده بالظبط.
 */
class CustomersController
{
    /* ═══════════════════════════════════════════════════════════
       عملاء التطبيق
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/customers — كل العملاء بعناوينهم ومستلمينهم المحفوظين
     *
     * 🔒 الإدارة والكول سنتر بس (`role:admin,callcenter` على المسار) — ده
     * تفريغ لدفتر عملاء التطبيق كامل: أسماء وإيميلات وتليفونات وعناوين
     * ودبابيس GPS.
     */
    public function index(Request $request): JsonResponse
    {
        /* ?since — لوحة العملاء بتستطلع كل 10 ثواني، والرد على 32 ألف عميل بيوصل
           1.85 ميجا. من غير الفحص ده كان بيتبعت كامل في كل دورة (~665 ميجا في
           الساعة لكل تاب مفتوح) لبيانات نادرًا ما بتتغير. الفحص بيشمل الأبناء
           كمان لأن إضافة عنوان أو مستلم محفوظ تعتبر تغيير للعميل.
           وعلامة الحذف داخلة في الفحص كمان لأن الحذف حقيقي (DELETE) ومابيسيبش أي
           أثر في updated_at/created_at — من غيرها التاب المفتوح عند مدير تاني كان
           يفضل شايف العميل المحذوف (وأي إجراء عليه يرجّع 404) لحد reload. */
        $sinceRaw = $request->query('since');
        $since = ($sinceRaw !== null && $sinceRaw !== '') ? (int) $sinceRaw : null;

        if ($since !== null) {
            /* الأربع فحوص بتتجمع بـ`+` في استعلام واحد زي الأصل — رحلة واحدة
               للقاعدة بدل أربعة. الاسم `n` هو الفرق الوحيد عن الأصل (لارافل
               بيرجّع كائن بأسماء الأعمدة، والأصل كان بيقرا العمود بالموضع). */
            $chk = DB::selectOne(
                "SELECT
                   EXISTS(SELECT 1 FROM customers               WHERE updated_at > FROM_UNIXTIME(? / 1000))
                 + EXISTS(SELECT 1 FROM customer_addresses       WHERE created_at > FROM_UNIXTIME(? / 1000))
                 + EXISTS(SELECT 1 FROM customer_saved_receivers WHERE created_at > FROM_UNIXTIME(? / 1000))
                 + EXISTS(SELECT 1 FROM site_settings WHERE setting_key = 'customersDeletedAt'
                            AND CAST(setting_value AS UNSIGNED) > ?) AS n",
                /* العلامة مخزّنة بالمللي ثانية بنفس ساعة serverNow اللي الكلاينت
                   بيرجّعها في since — فالمقارنة مباشرة من غير تحويل توقيت. */
                [$since, $since, $since, $since]
            );

            if (! (int) ($chk->n ?? 0)) {
                return PollableList::unchanged();
            }
        }

        $rows = DB::select(
            'SELECT c.*, z.area_name AS zone_name, b.name AS branch_name
             FROM customers c
             LEFT JOIN zones z ON z.id = c.default_zone_id
             LEFT JOIN branches b ON b.id = c.default_branch_id
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT 2000'
        );

        /* أبناء العميل — استعلامين مجمعين بدل N+1، **ومقصورين على العملاء اللي
           رجعوا فعلًا**. قبل كده كانوا `SELECT *` على الجدولين كاملين من غير أي
           WHERE ولا LIMIT: الأب مقصور بـ2000 عميل، لكن الأبناء بيتحمّلوا كلهم في
           ذاكرة PHP وبعدين اللي مش تابع للـ2000 بيتحرق. يعني الاستهلاك بينمو مع
           إجمالي العملاء مش مع المعروض — وبيتخطى memory_limit عند ~14 ألف عميل
           على الإعداد الافتراضي (128M). */
        $ids = array_map(static fn ($r) => (int) $r->id, $rows);

        $addresses = [];
        $receivers = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));

            foreach (DB::select(
                "SELECT * FROM customer_addresses WHERE customer_id IN ($ph)
                 ORDER BY is_default DESC, id",
                $ids
            ) as $a) {
                $addresses[(int) $a->customer_id][] = CustomerWire::address($a);
            }

            foreach (DB::select(
                "SELECT * FROM customer_saved_receivers WHERE customer_id IN ($ph)
                 ORDER BY id DESC",
                $ids
            ) as $r) {
                $receivers[(int) $r->customer_id][] = CustomerWire::savedReceiver($r);
            }
        }

        $items = [];
        foreach ($rows as $r) {
            $cid = (int) $r->id;
            $items[] = CustomerWire::customer($r, $addresses[$cid] ?? [], $receivers[$cid] ?? []);
        }

        return PollableList::items($items);
    }

    /* ═══════════════════════════════════════════════════════════
       عملاء التطبيق — الكتابة (إدارة فقط)
    ═══════════════════════════════════════════════════════════ */

    /**
     * PUT /api/customers/{id} — {name|displayNameAr, phone1?, phone2?, address?}
     * 🔒 `role:admin` — الكتابة على دفتر العملاء للإدارة وحدها (القراءة
     * بتوصل للكول سنتر كمان).
     *
     * ⚠️ **مش تعديل جزئي**: الأربع أعمدة بتتكتب كلها في كل نداء، فالحقل
     * المش مبعوت بيتمسح (`null`). ده سلوك الأصل بالحرف — اللوحة بتبعت
     * الفورم كامل. (متسجّل في notes)
     *
     * التحقق من الموبايل `^0?1[0-9]{9}$` بعد شيل المسافات والشرط — بيقبل
     * `01012345678` و`1012345678`. الرقم الفاضي مسموح (مسح الرقم).
     *
     * ⚠️ فحص الوجود بيتم **بعد** الـUPDATE مش قبله، وبس لو مفيش صف اتغيّر:
     * تعديل بنفس القيم بيرجّع 0 صف من غير ما يكون العميل ناقص، فلازم نفرّق.
     * منقول زي ما هو (بيوفّر SELECT على المسار الشائع).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $request->actorOrFail();
        $cid = $this->intId($id);
        $b   = $this->body($request);

        $name = trim((string) ($b['name'] ?? $b['displayNameAr'] ?? ''));
        if ($name === '') {
            throw new ApiException('اكتب اسم العميل');
        }
        $phone1 = trim((string) ($b['phone1'] ?? ''));
        if ($phone1 !== '' && ! preg_match('/^0?1[0-9]{9}$/', str_replace([' ', '-'], '', $phone1))) {
            throw new ApiException('رقم الهاتف غير صحيح');
        }

        $affected = DB::update(
            'UPDATE customers SET display_name = ?, phone1 = ?, phone2 = ?, address = ? WHERE id = ?',
            [
                $name,
                $phone1 !== '' ? $phone1 : null,
                trim((string) ($b['phone2'] ?? '')) ?: null,
                trim((string) ($b['address'] ?? '')) ?: null,
                $cid,
            ]
        );
        if ($affected === 0) {
            // ممكن يكون التعديل بنفس القيم — نتأكد إن العميل موجود أصلًا
            if (! DB::select('SELECT id FROM customers WHERE id = ?', [$cid])) {
                throw ApiException::notFound('العميل غير موجود');
            }
        }

        return ApiResponse::ok(['customer' => $this->fetchWire($cid)]);
    }

    /**
     * POST /api/customers/{id}/block — {blocked: true/false} · 🔒 `role:admin`
     *
     * الحظر بيوصل جلسة العميل **في الطلب اللي بعده** — `ResolveApiActor`
     * بيقرا العمود من القاعدة كل طلب مش من السيشن.
     *
     * ⚠️ **مفيش فحص وجود هنا**: الـUPDATE على id مش موجود بيعدّي بصمت،
     * وبعدين `fetchWire()` هي اللي بترمي 404. يعني الرد النهائي 404 برضه
     * بس بعد كتابة فاضية. منقول زي ما هو.
     *
     * `blocked_at`/`blocked_by` بيترجّعوا `null` عند فك الحظر — مش بيفضلوا
     * كأثر تاريخي (سلوك الأصل).
     */
    public function block(Request $request, string $id): JsonResponse
    {
        $user = $request->actorOrFail();
        $cid  = $this->intId($id);
        $b    = $this->body($request);

        // الافتراضي **حظر** لو المفتاح مش مبعوت — الزر بيبعته صريح دايمًا
        $on = (bool) ($b['blocked'] ?? true);

        DB::update(
            'UPDATE customers SET blocked = ?, blocked_at = ?, blocked_by = ? WHERE id = ?',
            [
                $on ? 1 : 0,
                $on ? WireTime::nowDb() : null,
                // `?: 'الإدارة'` احتياط لجلسة من غير اسم ولا username
                $on ? (($user->name !== '' ? $user->name : $user->username) ?: 'الإدارة') : null,
                $cid,
            ]
        );

        return ApiResponse::ok(['customer' => $this->fetchWire($cid)]);
    }

    /**
     * POST /api/customers/{id}/zone — {zoneId: <id|null>} · 🔒 `role:admin`
     *
     * الفرع **مشتق من الزون** مش مبعوت: كل منطقة ليها فرع مسؤول
     * (`zones.delivery_branch_id`)، فتحديد المنطقة بيحدد الفرع تلقائيًا.
     * تصفير المنطقة (`zoneId: null`) بيصفّر الفرع معاها.
     *
     * ⚠️ نفس ملاحظة `block()`: الـUPDATE مابيتأكدش من وجود العميل،
     * و`fetchWire()` هي اللي بترمي 404.
     */
    public function setZone(Request $request, string $id): JsonResponse
    {
        $request->actorOrFail();
        $cid = $this->intId($id);
        $b   = $this->body($request);

        $zoneId = isset($b['zoneId']) && $b['zoneId'] !== null && $b['zoneId'] !== ''
            ? (int) $b['zoneId'] : null;

        $branchId = null;
        if ($zoneId !== null) {
            $zone = DB::select('SELECT id, delivery_branch_id FROM zones WHERE id = ?', [$zoneId])[0] ?? null;
            if (! $zone) {
                throw ApiException::notFound('المنطقة غير موجودة');
            }
            $branchId = $zone->delivery_branch_id !== null ? (int) $zone->delivery_branch_id : null;
        }

        DB::update(
            'UPDATE customers SET default_zone_id = ?, default_branch_id = ? WHERE id = ?',
            [$zoneId, $branchId, $cid]
        );

        return ApiResponse::ok(['customer' => $this->fetchWire($cid)]);
    }

    /**
     * DELETE /api/customers/{id} · 🔒 `role:admin`
     *
     * 🔴 **حذف نهائي — مش soft delete.** العناوين والمستلمين المحفوظين
     * بيتشالوا بالـCASCADE، والأوردرات بتفضل في النظام بعد فك ربطها.
     *
     * ترتيب الجُمل جوه المعاملة **جزء من الصح** وممنوع يتغيّر:
     *   1. قفل صف العميل (`FOR UPDATE`) — حذفين متوازيين واحد بس بيكمّل
     *   2. `orders.customer_id = NULL` — الشحنات تفضل، بس بلا FK لصف رايح
     *   3. `DELETE notifications` — الـFK عليها **RESTRICT** مش CASCADE،
     *      فمن غير الجملة دي حذف العميل بيضرب خطأ قاعدة بيانات
     *   4. المحفظة (وحركاتها CASCADE منها)
     *   5. توكنات الأجهزة
     *   6. صف العميل نفسه
     *   7. علامة وقت الحذف في `site_settings`
     *
     * 🔴 **بند 7 مش soft delete** — هو **علامة استطلاع**: الصف بيختفي خالص
     * فمفيش `updated_at` يشهد على الحذف، والتاب المفتوح على
     * `/api/customers?since` كان يفضل عارض العميل المحذوف (وأي إجراء عليه
     * يرجّع 404) لحد reload. مفتاح واحد بيتدهس في كل حذفة يكفي: أي تاب
     * `since` بتاعه أقدم من العلامة بيتحمّل اللستة كاملة تاني. العلامة
     * مخزّنة **بالمللي ثانية** بنفس ساعة `serverNow` — عشان المقارنة في
     * `index()` تتم مباشرة من غير تحويل توقيت.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->actorOrFail();
        $cid  = $this->intId($id);

        DB::transaction(function () use ($cid, $user): void {
            if (! DB::select('SELECT id FROM customers WHERE id = ? FOR UPDATE', [$cid])) {
                throw ApiException::notFound('العميل غير موجود');
            }

            // الأوردرات القديمة بتفضل زي ما هي — بس من غير FK للعميل المحذوف
            DB::update('UPDATE orders SET customer_id = NULL WHERE customer_id = ?', [$cid]);
            // إشعاراته المخزنة (المرحّلة) — FK عليها RESTRICT: من غير الحذف ده
            // حذف العميل بيضرب خطأ قاعدة بيانات
            DB::delete('DELETE FROM notifications WHERE customer_id = ?', [$cid]);
            // المحفظة وحركاتها (wallet_transactions عليها CASCADE من wallets)
            DB::delete("DELETE FROM wallets WHERE owner_type = 'customer' AND owner_id = ?", [$cid]);
            // توكنات أجهزته
            DB::delete("DELETE FROM device_tokens WHERE owner_type = 'customer' AND owner_id = ?", [$cid]);
            // الحساب نفسه (customer_addresses + customer_saved_receivers عليهم CASCADE)
            DB::delete('DELETE FROM customers WHERE id = ?', [$cid]);

            DB::insert(
                "INSERT INTO site_settings (setting_key, setting_value, updated_by, updated_at)
                 VALUES ('customersDeletedAt', ?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                         updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
                [(string) PollableList::serverNowMs(), $user->username, WireTime::nowDb()]
            );
        });

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       ملف استلام المحل
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/store/pickup-profile — ملف استلام المحل الدائم
     *
     * المحل بيحدده مرة والنظام بيعبّي بيه فورم الشحنة كل مرة، فمش محتاج
     * يعيد كتابة عنوانه ومنطقته ودبوسه في كل شحنة.
     *
     * 🔒 دور `store` بس — الملف ده بيتقرا من جلسة صاحب المحل نفسه
     * (`WHERE u.id = <حساب الجلسة>`) فمفيش باراميتر يحدد محل تاني أصلًا.
     */
    public function pickupProfile(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        return ApiResponse::ok(['profile' => $this->pickupProfileWire((int) $actor->userId)]);
    }

    /**
     * صف ملف الاستلام بصيغة السلك — متفصول في دالة لوحده لأن الأصل بيعيد
     * استعماله بعد الحفظ (PUT بينده على الـGET في آخر سطر).
     */
    private function pickupProfileWire(int $userId): array
    {
        $row = DB::select(
            'SELECT u.shop_name, u.shop_phone, u.shop_phone2, u.shop_address,
                    u.shop_zone_id, u.shop_lat, u.shop_lng,
                    z.area_name AS _zone_name, z.price AS _zone_price, z.delivery_branch_id
               FROM users u LEFT JOIN zones z ON z.id = u.shop_zone_id
              WHERE u.id = ? LIMIT 1',
            [$userId]
        )[0] ?? null;

        if (! $row) {
            throw ApiException::notFound('الحساب غير موجود');
        }

        $r = (array) $row;

        /* الفرع مش عمود في users — بيتشتق من الزون (`z.delivery_branch_id`)
           عشان الفورم يعرف الفرع المسؤول من غير نداء تاني. */
        return [
            'shopName'    => $r['shop_name'],
            'shopPhone'   => $r['shop_phone'],
            'shopPhone2'  => $r['shop_phone2'],
            'address'     => $r['shop_address'],
            'zoneId'      => $r['shop_zone_id'] !== null ? (int) $r['shop_zone_id'] : null,
            'zoneName'    => $r['_zone_name'],
            'zonePrice'   => $r['_zone_price'] !== null ? (float) $r['_zone_price'] : null,
            'branchId'    => $r['delivery_branch_id'] !== null ? (int) $r['delivery_branch_id'] : null,
            'lat'         => $r['shop_lat'] !== null ? (float) $r['shop_lat'] : null,
            'lng'         => $r['shop_lng'] !== null ? (float) $r['shop_lng'] : null,
        ];
    }

    /**
     * PUT /api/store/pickup-profile — {phone?, phone2?, address?, zoneId?, lat?, lng?}
     * 🔒 `role:store` — المحل بيعدّل ملف نفسه بس. مفيش باراميتر يحدد محل
     * تاني أصلًا (`WHERE u.id = <حساب الجلسة>`).
     *
     * تعديل جزئي حقيقي بـ`array_key_exists`: بيتبني `SET` من المفاتيح
     * المبعوتة بس، والمش مبعوت مابيتلمسش. لو مفيش أي مفتاح معروف → 400.
     *
     * تفاصيل منقولة بالحرف:
     *  • هاتف المحل الأول **إجباري لو اتبعت** (8-15 رقم) — بيتبعت كهاتف
     *    المُرسِل في كل شحنة والطيار بيتصل بيه. التاني اختياري بالكامل
     *    وممكن يتمسح (فاضي = NULL).
     *  • الدبوس بيتحفظ **بس لو المفتاحين `lat` و`lng` الاتنين مبعوتين** —
     *    نص دبوس مالوش معنى. وأي واحد فيهم فاضي/null = مسح الاتنين.
     *  • العنوان بيتقص على 190 حرف (طول العمود) والفاضي بيبقى NULL.
     *
     * جملة كتابة واحدة (بعد فحص الزون) — فمفيش معاملة، زي الأصل.
     * الرد هو **نفس رد الـGET** بالظبط (الأصل بينده الدالة التانية).
     */
    public function pickupProfileSave(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $b     = $this->body($request);

        $sets = [];
        $args = [];

        if (array_key_exists('phone', $b)) {
            $p1 = (string) preg_replace('/[^\d]/', '', (string) $b['phone']);
            if (strlen($p1) < 8 || strlen($p1) > 15) {
                throw new ApiException('اكتب رقم هاتف صحيح للمحل');
            }
            $sets[] = 'shop_phone = ?';
            $args[] = $p1;
        }
        if (array_key_exists('phone2', $b)) {
            $p2 = (string) preg_replace('/[^\d]/', '', (string) $b['phone2']);
            if ($p2 !== '' && (strlen($p2) < 8 || strlen($p2) > 15)) {
                throw new ApiException('الهاتف الإضافي غير صحيح — امسحه أو صحّحه');
            }
            $sets[] = 'shop_phone2 = ?';
            $args[] = $p2 !== '' ? $p2 : null;
        }

        if (array_key_exists('address', $b)) {
            $sets[] = 'shop_address = ?';
            $args[] = mb_substr(trim((string) $b['address']), 0, 190) ?: null;
        }
        if (array_key_exists('zoneId', $b)) {
            $zid = $b['zoneId'] !== null && $b['zoneId'] !== '' ? (int) $b['zoneId'] : null;
            if ($zid !== null) {
                if (! DB::select('SELECT id FROM zones WHERE id = ? LIMIT 1', [$zid])) {
                    throw ApiException::notFound('المنطقة غير موجودة');
                }
            }
            $sets[] = 'shop_zone_id = ?';
            $args[] = $zid;
        }
        // الدبوس اختياري بالكامل — بيتحفظ لو اتبعت وبيتمسح لو اتبعت null
        if (array_key_exists('lat', $b) && array_key_exists('lng', $b)) {
            $hasPin = $b['lat'] !== null && $b['lng'] !== null && $b['lat'] !== '' && $b['lng'] !== '';
            $sets[] = 'shop_lat = ?';
            $args[] = $hasPin ? (float) $b['lat'] : null;
            $sets[] = 'shop_lng = ?';
            $args[] = $hasPin ? (float) $b['lng'] : null;
        }
        if (! $sets) {
            throw new ApiException('مفيش بيانات للحفظ');
        }

        $args[] = (int) $actor->userId;
        DB::update('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $args);

        // نفس رد الـGET بالحرف — الأصل بينده store_pickup_profile_get()
        return ApiResponse::ok(['profile' => $this->pickupProfileWire((int) $actor->userId)]);
    }

    /* ═══════════════════════════════════════════════════════════
       أدوات داخلية
    ═══════════════════════════════════════════════════════════ */

    /**
     * المقابل لـ body_json(): المصفوفة الخام مش مصدر مدخلات مدموج.
     * `array_key_exists()` في `pickupProfileSave` بيفرّق بين «مش متبعت»
     * و«متبعت null» — والتفرقة دي أساس التعديل الجزئي.
     */
    private function body(Request $request): array
    {
        return $request->json()->all();
    }

    /** المقابل لـ customers_int_id(): معرّف موجب، وغير الصالح **400 مش 404** */
    private function intId(mixed $id): int
    {
        $n = (int) $id;
        if ($n <= 0) {
            throw new ApiException('معرّف غير صالح');
        }

        return $n;
    }

    /**
     * عميل واحد بصيغة السلك بعد أي تعديل — المقابل لـ customers_fetch_wire().
     *
     * ⚠️ الأبناء (العناوين والمستلمين المحفوظين) **بيرجعوا فاضيين** هنا —
     * الأصل بينده `customers_wire($row)` بلا الوسيطين. يعني رد التعديل فيه
     * `addresses: []` و`savedReceivers: []` حتى لو العميل عنده عناوين.
     * اللوحة بتعيد تحميل اللستة بعدين. منقول زي ما هو (متسجّل في notes).
     */
    private function fetchWire(int $id): array
    {
        $row = DB::select(
            'SELECT c.*, z.area_name AS zone_name, b.name AS branch_name
             FROM customers c
             LEFT JOIN zones z ON z.id = c.default_zone_id
             LEFT JOIN branches b ON b.id = c.default_branch_id
             WHERE c.id = ?',
            [$id]
        )[0] ?? null;

        if (! $row) {
            throw ApiException::notFound('العميل غير موجود');
        }

        return CustomerWire::customer($row);
    }
}
