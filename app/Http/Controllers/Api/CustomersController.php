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
     * التحقق (2026-09-09): أي رقم من 8 لـ 15 رقم بعد شيل المسافات والشرط والأقواس و+
     * (010/011/012/015/050/أرضي/دولي) — نفس قاعدة تطبيق العملاء. كان موبايل مصري
     * أو أرضي بس، وصاحب النظام طلب قبول أي رقم. الرقم الفاضي مسموح (مسح الرقم).
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
        $p1d    = str_replace([' ', '-', '(', ')', '+'], '', $phone1);
        // أي رقم من 8 لـ 15 رقم (طلب صاحب النظام 2026-09-09) — نفس قاعدة CustomerAppController::validPhone
        if ($phone1 !== '' && ! preg_match('/^[0-9]{8,15}$/', $p1d)) {
            throw new ApiException('رقم الهاتف غير صحيح — من 8 لـ 15 رقم');
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
     * POST /api/customers/{id}/price-edit — {enabled: true/false} · 🔒 `role:admin`
     *
     * (طلب صاحب النظام 2026-09-02): فتح خاصية تعديل سعر التوصيل للعميل
     * — زي المحلات، يرفع السعر ولا ينزل عن سعر المنطقة. البوابة الفعلية
     * في CustomerAppController::customerDeliveryPrice؛ هنا مفتاح المنح بس.
     */
    public function priceEdit(Request $request, string $id): JsonResponse
    {
        $request->actorOrFail();
        $cid = $this->intId($id);
        $on  = ! empty($this->body($request)['enabled']);

        DB::update('UPDATE customers SET can_edit_price = ? WHERE id = ?', [$on ? 1 : 0, $cid]);

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
                    u.shop_zone_id, u.shop_lat, u.shop_lng, u.can_edit_price, u.can_track_pilot,
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
            /* 🏪 خانة سعر التوصيل في البوابة بتتفتح بيه (طلب 2026-09-03) */
            'canEditPrice' => (int) ($r['can_edit_price'] ?? 0) === 1,
            /* 🛵 زرار «فين الطيار؟» في البوابة بيتفتح بيه (طلب 2026-09-16) */
            'canTrackPilot' => (int) ($r['can_track_pilot'] ?? 0) === 1,
        ];
    }

    /**
     * GET /api/store/orders/{id}/pickup-track — موقع الطيار اللي جاي يستلم.
     *
     * 🛵 طلب صاحب النظام 2026-09-16: «تتبّع لبوابة المحلات للمندوب اللي هيجي
     * يرفع منها وتبقى خاصية تتفتح وتتقفل». 🔒 role:store + users.can_track_pilot
     * لازم يكون مفتوح (403 لو مقفول) + الأوردر بتاع المحل نفسه (added_by =
     * اسم المستخدم). التتبّع **وقت الشيل بس**: من تعيين الطيار لحد ما المحل
     * يسلّمه (handed_over_at) — بعدها 409، عشان المحل مايتابعش الطيار عند
     * العملاء. الموقع من pilots.lat/lng (آخر نقطة من التطبيق).
     */
    public function pickupTrack(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $on = (int) (DB::table('users')->where('id', (int) $actor->userId)->value('can_track_pilot') ?? 0) === 1;
        if (! $on) {
            throw ApiException::forbidden('تتبّع الطيار مش مفتوح لمحلك — اطلبه من الإدارة');
        }
        $oid = (int) $id;
        $o = $oid > 0 ? DB::selectOne(
            'SELECT o.id, o.order_num, o.added_by, o.status, o.pilot_id, o.handed_over_at, o.sender_lat, o.sender_lng
               FROM orders o WHERE o.id = ? LIMIT 1', [$oid]) : null;
        if (! $o) {
            throw ApiException::notFound('الأوردر مش موجود');
        }
        if ((string) $o->added_by !== $actor->username) {
            throw ApiException::forbidden('الأوردر ده مش بتاع محلك');
        }
        if ($o->pilot_id === null) {
            throw new ApiException('لسه ما اتعيّنش طيار للأوردر', 409);
        }
        if ($o->handed_over_at !== null || ! in_array((string) $o->status, ['processing', 'delivering', 'postponed'], true)) {
            throw new ApiException('الأوردر خرج من عندك خلاص — التتبّع وقت الاستلام بس', 409);
        }
        $p = DB::selectOne('SELECT id, name, lat, lng, location_updated_at, heading, speed FROM pilots WHERE id = ? LIMIT 1', [(int) $o->pilot_id]);
        if (! $p) {
            throw ApiException::notFound('الطيار مش موجود');
        }
        $shop = DB::selectOne('SELECT shop_lat, shop_lng FROM users WHERE id = ? LIMIT 1', [(int) $actor->userId]);
        $sLat = $o->sender_lat ?? $shop->shop_lat ?? null;
        $sLng = $o->sender_lng ?? $shop->shop_lng ?? null;

        return ApiResponse::ok(['track' => [
            'orderId'  => (int) $o->id,
            'orderNum' => (string) $o->order_num,
            'pilot'    => [
                'id'        => (int) $p->id,
                'name'      => (string) $p->name,
                'phone'     => null,   // مفيش عمود تليفون للطيار في المخطط — الاتصال من الفرع
                'lat'       => $p->lat !== null ? (float) $p->lat : null,
                'lng'       => $p->lng !== null ? (float) $p->lng : null,
                'heading'   => $p->heading !== null ? (float) $p->heading : null,
                'speed'     => $p->speed !== null ? (float) $p->speed : null,
                'updatedAt' => WireTime::toWire($p->location_updated_at),
            ],
            'store' => [
                'lat' => $sLat !== null ? (float) $sLat : null,
                'lng' => $sLng !== null ? (float) $sLng : null,
            ],
        ]]);
    }

    /**
     * PUT /api/store/pickup-profile — {shopName?, phone?, phone2?, address?, zoneId?, lat?, lng?}
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
     *  • العنوان بيتقص على 500 حرف (طول العمود بعد التوسيع 2026-09-10) والفاضي بيبقى NULL.
     *
     * 🆕 `shopName` (2026-08-31): الشركة بتفتح الحساب والمحل بيكمّل بياناته
     * بنفسه — والاسم ده بالذات **بيتبعت كاسم المُرسِل في كل شحنة**
     * (`store.html` بتقراه من الملف وتحطه في `senderName`). كان الحقل
     * الوحيد في هوية المُرسِل اللي الإدارة بس بتقدر تكتبه، فالمحل اللي
     * الإدارة سجّلت اسمه ناقص أو غلط مكانش يقدر يصلّحه.
     *
     * عكس الباقي **مايتمسحش**: فاضي = 400 مش NULL. الأوردر بيرفض من غير
     * `senderName` (`OrdersController::store`)، فمسحه كان هيقفل الشحنات
     * على المحل برسالة مالهاش علاقة («يرجى اختيار أو إدخال العميل استلام»).
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

        if (array_key_exists('shopName', $b)) {
            /* `trim()` بيشيل مسافات ASCII بس. اسم كله NBSP (`\u{00A0}`) أو
               مسافات عربية/يونيكود كان بيعدّي التحقق ويتحفظ «فاضي بصريًا»،
               وبعدين يتقفل ومفيش طريق لتصحيحه من التطبيق. */
            $name = preg_replace('/^[\s\x{00A0}\x{200B}-\x{200D}\x{FEFF}]+|[\s\x{00A0}\x{200B}-\x{200D}\x{FEFF}]+$/u',
                                 '', (string) ($b['shopName'] ?? ''));
            if (mb_strlen($name) < 2) {
                throw new ApiException('اكتب اسم المحل — بيظهر للطيار وللعميل كاسم المُرسِل');
            }

            /* 🔒 القفل على السيرفر كمان، مش في الواجهة بس.
               الواجهة بتقفل الحقل بعد ما الاسم يتسجّل (`applyShopNameLock`)
               لأنه مبصوم كـ`senderName` على كل شحنة قديمة. بس إخفاء الحقل
               مش قفل — أي حد يفتح الـconsole ويكتب `fetch` كان بيعدّي.
               نفس قاعدة الكول سنتر: الواجهة والسيرفر مع بعض.
               التعديل بعد التسجيل من الإدارة (`EntitiesController`). */
            $cur = (string) (DB::select('SELECT shop_name FROM users WHERE id = ? LIMIT 1',
                                        [(int) $actor->userId])[0]->shop_name ?? '');
            if ($cur !== '' && $cur !== $name) {
                throw ApiException::forbidden('اسم المحل مسجّل — التعديل بيتم من الإدارة');
            }

            $sets[] = 'shop_name = ?';
            $args[] = mb_substr($name, 0, 190);
        }

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
            $args[] = mb_substr(trim((string) $b['address']), 0, 500) ?: null;
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

    /* ═══════════════════════════════════════════════════════════
       دفتر عناوين بوابة المحلات — «عناويني» (طلب صاحب النظام 2026-09-02:
       «في تطبيق المحلات اعمل عناويني زي تطبيق العملاء»).

       مرآة عناوين تطبيق العميل بالحرف (CustomerAppController::addresses*)
       بس المفتاح هنا صف المحل في `users` (actor->userId) — نفس مفتاح ملف
       الاستلام فوق. الحراسة على المسارات role:store، والـWHERE على
       user_id هو حارس الملكية: محل مايشوفش/مايمسحش عناوين محل تاني.
    ═══════════════════════════════════════════════════════════ */

    /** قايمة عناوين المحل بصيغة السلك — الافتراضي الأول */
    private static function storeAddressesFetch(int $userId): array
    {
        $rows = DB::select(
            'SELECT a.*, z.area_name AS _zone_name, b.name AS _branch_name
               FROM store_addresses a
               LEFT JOIN zones z ON z.id = a.zone_id
               LEFT JOIN branches b ON b.id = a.branch_id
              WHERE a.user_id = ?
              ORDER BY a.is_default DESC, a.id',
            [$userId]
        );

        return array_map(fn ($r) => [
            'id'          => (string) $r->id,
            'label'       => (string) ($r->label ?? ''),
            'fullAddress' => (string) ($r->full_address ?? ''),
            'lat'         => $r->lat !== null ? (float) $r->lat : null,
            'lng'         => $r->lng !== null ? (float) $r->lng : null,
            'zoneId'      => $r->zone_id !== null ? (string) $r->zone_id : null,
            'branchId'    => $r->branch_id !== null ? (string) $r->branch_id : null,
            'zoneName'    => (string) ($r->_zone_name ?? ''),
            'branchName'  => (string) ($r->_branch_name ?? ''),
            'isDefault'   => (int) $r->is_default === 1,
        ], $rows);
    }

    /** [zoneId, branchId] بعد التحقق — نفس customer addrZone */
    private static function storeAddrZone(array $b): array
    {
        $zoneId = isset($b['zoneId']) && $b['zoneId'] !== '' && $b['zoneId'] !== null ? (int) $b['zoneId'] : null;
        if ($zoneId === null) {
            return [null, null];
        }
        $z = DB::select('SELECT delivery_branch_id FROM zones WHERE id = ? LIMIT 1', [$zoneId])[0] ?? null;
        if (! $z) {
            throw new ApiException('المنطقة المختارة غير موجودة');
        }

        return [$zoneId, (int) $z->delivery_branch_id];
    }

    /** GET /api/store/addresses — نفس شذوذ عقد قايمة العميل: {ok, changed, items} */
    public function storeAddressesList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        return ApiResponse::out([
            'ok'      => true,
            'changed' => true,
            'items'   => self::storeAddressesFetch((int) $actor->userId),
        ]);
    }

    /** POST /api/store/addresses */
    public function storeAddressesCreate(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $b     = $this->body($request);

        $full = trim((string) ($b['fullAddress'] ?? ''));
        if ($full === '') {
            throw new ApiException('اكتب العنوان');
        }
        [$zoneId, $branchId] = self::storeAddrZone($b);
        if ($zoneId === null) {
            throw new ApiException('اختر المنطقة');
        }
        $isDefault = ! empty($b['isDefault']) ? 1 : 0;

        DB::transaction(function () use ($actor, $b, $full, $zoneId, $branchId, $isDefault): void {
            // عنوان افتراضي واحد بس لكل محل — التصفير قبل الإدخال
            if ($isDefault) {
                DB::update('UPDATE store_addresses SET is_default = 0 WHERE user_id = ?', [(int) $actor->userId]);
            }
            DB::insert(
                'INSERT INTO store_addresses
                   (user_id, label, full_address, lat, lng, zone_id, branch_id, is_default, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [
                    (int) $actor->userId,
                    trim((string) ($b['label'] ?? '')) ?: 'عنوان',
                    $full,
                    isset($b['lat']) && $b['lat'] !== '' && $b['lat'] !== null ? (float) $b['lat'] : null,
                    isset($b['lng']) && $b['lng'] !== '' && $b['lng'] !== null ? (float) $b['lng'] : null,
                    $zoneId, $branchId, $isDefault, WireTime::nowDb(),
                ]
            );
        });

        return ApiResponse::ok(['items' => self::storeAddressesFetch((int) $actor->userId)]);
    }

    /** PUT /api/store/addresses/{id} — تعديل جزئي بنفس قاعدة array_key_exists */
    public function storeAddressesUpdate(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $b     = $this->body($request);

        $row = DB::select(
            'SELECT * FROM store_addresses WHERE id = ? AND user_id = ?',
            [(int) $id, (int) $actor->userId]
        )[0] ?? null;
        if (! $row) {
            throw new ApiException('العنوان غير موجود', 404);
        }
        $a = (array) $row;

        $full = trim((string) ($b['fullAddress'] ?? $a['full_address']));
        if ($full === '') {
            throw new ApiException('اكتب العنوان');
        }
        if (array_key_exists('zoneId', $b)) {
            [$zoneId, $branchId] = self::storeAddrZone($b);
        } else {
            $zoneId   = $a['zone_id'] !== null ? (int) $a['zone_id'] : null;
            $branchId = $a['branch_id'] !== null ? (int) $a['branch_id'] : null;
        }
        if ($zoneId === null) {
            throw new ApiException('اختر المنطقة');
        }
        $isDefault = array_key_exists('isDefault', $b) ? (int) ! empty($b['isDefault']) : (int) $a['is_default'];

        DB::transaction(function () use ($actor, $b, $a, $id, $full, $zoneId, $branchId, $isDefault): void {
            // `id <> ?` — التصفير مابيلمسش الصف اللي بنعدّله هو نفسه
            if ($isDefault) {
                DB::update(
                    'UPDATE store_addresses SET is_default = 0 WHERE user_id = ? AND id <> ?',
                    [(int) $actor->userId, (int) $id]
                );
            }
            DB::update(
                'UPDATE store_addresses
                    SET label = ?, full_address = ?, lat = ?, lng = ?, zone_id = ?, branch_id = ?, is_default = ?
                  WHERE id = ? AND user_id = ?',
                [
                    trim((string) ($b['label'] ?? $a['label'])) ?: 'عنوان',
                    $full,
                    array_key_exists('lat', $b)
                        ? ($b['lat'] !== '' && $b['lat'] !== null ? (float) $b['lat'] : null)
                        : ($a['lat'] !== null ? (float) $a['lat'] : null),
                    array_key_exists('lng', $b)
                        ? ($b['lng'] !== '' && $b['lng'] !== null ? (float) $b['lng'] : null)
                        : ($a['lng'] !== null ? (float) $a['lng'] : null),
                    $zoneId, $branchId, $isDefault, (int) $id, (int) $actor->userId,
                ]
            );
        });

        return ApiResponse::ok(['items' => self::storeAddressesFetch((int) $actor->userId)]);
    }

    /** DELETE /api/store/addresses/{id} — «مش بتاعك» = نفس «مش موجود» */
    public function storeAddressesDelete(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();

        $n = DB::delete(
            'DELETE FROM store_addresses WHERE id = ? AND user_id = ?',
            [(int) $id, (int) $actor->userId]
        );
        if ($n === 0) {
            throw new ApiException('العنوان غير موجود', 404);
        }

        return ApiResponse::ok(['items' => self::storeAddressesFetch((int) $actor->userId)]);
    }
}
