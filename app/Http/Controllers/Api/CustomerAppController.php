<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\BroadcastsOrders;
use App\Http\Controllers\Concerns\NotifiesOrderReceivers;
use App\Support\ApiResponse;
use App\Support\OrderNumber;
use App\Support\PollableList;
use App\Support\WireTime;
use App\Wire\CustomerAppWire;
use App\Wire\OrderWire;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * تطبيق العميل (public/customer.html) — نقل حرفي لـ
 * api/routes/customer_app.php بالكامل (قراءة وكتابة).
 *
 * القرار المعماري المتسجّل في رأس الملف الأصلي وباقي زي ما هو: دخول
 * العميل بجوجل عبر Firebase Authentication (بيفضل شغال في المتصفح —
 * مجاني ومنفصل عن الداتابيز). التطبيق بيبعت `idToken` والسيرفر بيتحقق من
 * توقيع الـJWT **بنفسه** ضد مفاتيح جوجل العامة (securetoken) مع
 * audience = المشروع، وبيفتح جلسة عادية. الـuid القديم بيتحفظ في
 * `customers.legacy_key` عشان الترحيل يطابق.
 *
 * كل القراءة/الكتابة بعد الدخول REST خالص — صفر firebase-database.
 * (`CustomersController` المجاور = إدارة العملاء للوحات — دومين تاني.)
 *
 * ⚠️ **جلسة العميل مش جلسة موظف**: `role='customer'` + `customer_id`.
 * `ResolveApiActor` بيحلّها لـ`Actor::customer(...)`، لكن مسارات الملف ده
 * **مابتستخدمش `actorOrFail()`** — بتستخدم `customerRequire()` زي الأصل
 * بالظبط، لأن الأصل مابيندهش `require_auth()` هنا خالص (شوف التعليق على
 * `customerRequire`).
 */
class CustomerAppController
{
    use BroadcastsOrders;
    use NotifiesOrderReceivers;

    /** مشروع Firebase — الـaudience اللي التوكن لازم يكون متصدّر ليه */
    private const FIREBASE_PROJECT = 'aldahshaneg-92f66';

    /** شهادات جوجل العامة لتوكنات securetoken */
    private const GOOGLE_CERTS_URL =
        'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    /** سقف البحث الحر لعميل لسه بيسجّل — في الساعة، لكل حساب */
    private const INCOMING_HOURLY_LIMIT = 15;

    /* ═══════════════════════════════════════════════════════════════
       جلسة العميل
       ──────────────────────────────────────────────────────────────
       نفس جلسة النظام لكن بمفتاح إضافي customer_id و role='customer':
       - المسارات المشتركة (/api/zones /api/branches /api/upload) بتشتغل
       - ومسارات العميل بتتحقق من customer_id حصريًا
    ═══════════════════════════════════════════════════════════════ */

    /** المقابل لـ customer_session_id() */
    private function customerSessionId(Request $request): ?int
    {
        if ($request->session()->get('role') !== 'customer') {
            return null;
        }
        $cid = $request->session()->get('customer_id');

        return $cid ? (int) $cid : null;
    }

    /**
     * صف العميل من الجلسة — المقابل الحرفي لـ customer_require().
     * 401 لو مفيش جلسة، و403 لو محظور (إلا لو `$allowBlocked`).
     *
     * 🔴 **الفرق ده مقصود ولازم يفضل**: `require_auth()` القديمة بتقتل
     * جلسة المحظور فورًا بـ«هذا الحساب موقوف — تواصل مع الإدارة» 403،
     * لكن مسارات `customer_app.php` **مابتندهاش خالص** — بتنده الدالة دي.
     * فالمحظور بيعدّي على مسارات القراءة (`$allowBlocked = true`) عشان
     * الواجهة تعرض شاشة «الحساب موقوف» بدل ما الجلسة تتقفل من تحتها،
     * وبيترفض على مسارات الكتابة برسالة **تانية خالص**:
     * «حسابك موقوف مؤقتًا — كلّم خدمة العملاء».
     *
     * عشان كده `ResolveApiActor` بيتخطى قتل الجلسة على `api/customer/*`
     * (شوف التعليق هناك) — من غير الاستثناء ده كل المسارات دي كانت
     * هترجّع 403 بالرسالة الغلط للمحظور.
     */
    private function customerRequire(Request $request, bool $allowBlocked = false): array
    {
        $cid = $this->customerSessionId($request);
        if (! $cid) {
            throw new ApiException('يجب تسجيل الدخول أولًا', 401);
        }

        $row = DB::select('SELECT * FROM customers WHERE id = ? LIMIT 1', [$cid])[0] ?? null;
        if (! $row) {
            throw new ApiException('يجب تسجيل الدخول أولًا', 401);
        }
        $c = (array) $row;

        if (! $allowBlocked && (int) $c['blocked'] === 1) {
            throw new ApiException('حسابك موقوف مؤقتًا — كلّم خدمة العملاء', 403);
        }

        return $c;
    }

    /**
     * المقابل لـ body_json(): المصفوفة الخام مش مصدر مدخلات مدموج.
     *
     * `$request->json()->all()` مش `input()` عن قصد — مسارات التعديل هنا
     * بتستخدم `array_key_exists($k, $b)` عشان تفرّق بين «الحقل مش متبعت»
     * و«متبعت فاضي»، والتفرقة دي هي أساس التعديل الجزئي في العناوين.
     * (`TolerantJsonBody` ضامن إن الجسم الفاضي أو المكسور = `[]`.)
     */
    private function body(Request $request): array
    {
        return $request->json()->all();
    }

    /* ═══════════════════════════════════════════════════════════════
       التحقق من idToken بتاع Firebase (Google JWT — RS256)
    ═══════════════════════════════════════════════════════════════ */

    /** المقابل لـ customer_b64url_decode() */
    private static function b64UrlDecode(string $s): string|false
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }

        return base64_decode($s, true);
    }

    /**
     * شهادات جوجل العامة — بكاش ملف ساعة عشان مانضربش النداء مع كل دخول.
     *
     * ⚠️ الكاش في `sys_get_temp_dir()` مش في `storage/` — نفس مكان الأصل
     * بالحرف، عشان النظامين لو اشتغلوا جنب بعض على نفس السيرفر يشاركوا
     * نفس الملف بدل ما كل واحد يضرب جوجل لوحده.
     *
     * ⚠️ **النسخة القديمة أحسن من الفشل**: لو النداء وقع وفيه ملف كاش
     * قديم (أقدم من ساعة) بنستعمله بدل ما نمنع كل العملاء من الدخول.
     * مفاتيح جوجل بتفضل صالحة أيام، فالمخاطرة أقل بكتير من انقطاع الدخول.
     */
    private static function googleCerts(): array
    {
        $cacheFile = sys_get_temp_dir() . '/aldahshan_google_certs.json';
        if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < 3600) {
            $data = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($data) && $data) {
                return $data;
            }
        }

        $raw = false;
        if (function_exists('curl_init')) {
            $ch = curl_init(self::GOOGLE_CERTS_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
        }
        if ($raw === false || $raw === '') {
            $ctx = stream_context_create(['http' => ['timeout' => 8]]);
            $raw = @file_get_contents(self::GOOGLE_CERTS_URL, false, $ctx);
        }

        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data) || ! $data) {
            // آخر نسخة متكاشة حتى لو قديمة — أفضل من الفشل الكامل
            if (is_file($cacheFile)) {
                $data = json_decode((string) file_get_contents($cacheFile), true);
                if (is_array($data) && $data) {
                    return $data;
                }
            }
            throw new ApiException('تعذّر التحقق من الدخول — السيرفر مش قادر يوصل لجوجل دلوقتي', 503);
        }

        @file_put_contents($cacheFile, json_encode($data));

        return $data;
    }

    /**
     * 🔴 بيتحقق من التوقيع والادعاءات وبيرجّع الـpayload — نقل حرفي لـ
     * customer_verify_id_token(). ده **باب الدخول الوحيد** لتطبيق العميل،
     * فأي تراخي هنا = دخول بأي حساب.
     *
     * كل فحص وسببه:
     *  • 3 أجزاء بالظبط، وهيدر/بايلود JSON صالحين.
     *  • `alg` لازم `RS256` حرفيًا و`kid` موجود — من غير الفحص ده توكن
     *    بـ`alg:none` كان هيعدّي بلا توقيع أصلًا.
     *  • `kid` مش موجود في شهادات جوجل = التوكن قديم (جوجل بتدوّر
     *    المفاتيح)، فالرسالة «منتهي — سجّل دخول تاني» مش «غير صالح».
     *  • `openssl_verify(...) === 1` بالظبط — الدالة بترجّع `-1` عند الخطأ
     *    و`-1` قيمة صادقة، فمقارنة `!` كانت هتقبل التوكن الغلط.
     *  • `exp` بسماحية **60 ثانية** لفرق ساعة السيرفر.
     *  • `iat` في المستقبل بأكتر من **300 ثانية** = توكن مزوّر.
     *  • `aud` = المشروع، و`iss` = securetoken بتاع نفس المشروع —
     *    من غيرهم توكن من أي مشروع Firebase تاني كان بيفتح جلسة عندنا.
     */
    // ⚠️ `public` مش `private`: `AuthController::adminGoogleLogin()` بينده
    // عليها — الأصل كان بينده `customer_verify_id_token()` من auth.php
    // بالظبط كده (دالة عامة مشتركة بين المسارين). ممنوع تتكرّر نسخة تانية:
    // ده باب الدخول الوحيد، ونسختين معناها فحص أمان بيتحدّث في مكان واحد.
    public static function verifyIdToken(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new ApiException('توكن الدخول غير صالح', 401);
        }

        $header  = json_decode((string) self::b64UrlDecode($parts[0]), true);
        $payload = json_decode((string) self::b64UrlDecode($parts[1]), true);
        $sig     = self::b64UrlDecode($parts[2]);
        if (! is_array($header) || ! is_array($payload) || $sig === false) {
            throw new ApiException('توكن الدخول غير صالح', 401);
        }
        if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            throw new ApiException('توكن الدخول غير صالح', 401);
        }

        $certs = self::googleCerts();
        $pem = $certs[$header['kid']] ?? null;
        if (! $pem) {
            throw new ApiException('توكن الدخول منتهي — سجّل دخول تاني', 401);
        }

        $pub = openssl_pkey_get_public($pem);
        if ($pub === false) {
            throw new ApiException('تعذّر التحقق من الدخول', 500);
        }
        $ok = openssl_verify($parts[0] . '.' . $parts[1], $sig, $pub, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new ApiException('توقيع الدخول غير صحيح', 401);
        }

        $now = time();
        $sub = (string) ($payload['sub'] ?? '');
        if ((int) ($payload['exp'] ?? 0) < $now - 60) {
            throw new ApiException('جلسة الدخول انتهت — سجّل دخول تاني', 401);
        }
        if ((int) ($payload['iat'] ?? 0) > $now + 300) {
            throw new ApiException('توكن الدخول غير صالح', 401);
        }
        if (($payload['aud'] ?? '') !== self::FIREBASE_PROJECT) {
            throw new ApiException('توكن الدخول لمشروع تاني', 401);
        }
        if (($payload['iss'] ?? '') !== 'https://securetoken.google.com/' . self::FIREBASE_PROJECT) {
            throw new ApiException('مصدر توكن الدخول غير صحيح', 401);
        }
        if ($sub === '') {
            throw new ApiException('توكن الدخول غير صالح', 401);
        }

        return $payload;
    }

    /* ═══════════════════════════════════════════════════════════════
       قراءات مساعدة
    ═══════════════════════════════════════════════════════════════ */

    /** صف العميل مع أسماء الزون/الفرع الافتراضيين — customer_row_full() */
    private static function customerRowFull(int $id): ?array
    {
        $row = DB::select(
            'SELECT c.*, z.area_name AS _zone_name, b.name AS _branch_name
               FROM customers c
               LEFT JOIN zones z ON z.id = c.default_zone_id
               LEFT JOIN branches b ON b.id = c.default_branch_id
              WHERE c.id = ? LIMIT 1',
            [$id]
        )[0] ?? null;

        return $row !== null ? (array) $row : null;
    }

    /**
     * محفظة العميل: {balance, txns:[]} — نفس شكل readWallet القديم.
     * 🔴 فلوس (قراءة بس). مفيش محفظة = رصيد صفر وقايمة فاضية — **مش** خطأ
     * ومش إنشاء صف. الإنشاء بيتم في مسارات المالية بس.
     */
    private static function walletWire(int $customerId): array
    {
        $w = DB::select(
            "SELECT * FROM wallets WHERE owner_type = 'customer' AND owner_id = ? LIMIT 1",
            [$customerId]
        )[0] ?? null;

        $txns = [];
        if ($w) {
            foreach (DB::select(
                'SELECT * FROM wallet_transactions WHERE wallet_id = ? ORDER BY id DESC LIMIT 50',
                [(int) $w->id]
            ) as $r) {
                $txns[] = CustomerAppWire::walletTxn($r);
            }
        }

        return ['balance' => $w ? (float) $w->balance : 0.0, 'txns' => $txns];
    }

    /* ═══════════════════════════════════════════════════════════════
       POST /api/customer/login — {idToken}
    ═══════════════════════════════════════════════════════════════ */

    public function login(Request $request): JsonResponse
    {
        $b = $this->body($request);

        // الدخول حصريًا بتوكن جوجل موقّع ومتحقّق منه سيرفر-سايد — مفيش أي مسار bypass
        $idToken = trim((string) ($b['idToken'] ?? ''));
        if ($idToken === '') {
            throw new ApiException('توكن الدخول مطلوب', 400);
        }

        $p     = self::verifyIdToken($idToken);
        $uid   = (string) $p['sub'];
        $email = trim((string) ($p['email'] ?? ''));
        $name  = trim((string) ($p['name'] ?? ''));
        $photo = trim((string) ($p['picture'] ?? ''));

        // العميل لازم يكون داخل بجوجل تحديدًا (بوابة المحلات القديمة كانت بتدخل مجهول)
        $provider = $p['firebase']['sign_in_provider'] ?? '';
        if ($provider === 'anonymous') {
            throw new ApiException('الدخول لازم يكون بحساب جوجل', 401);
        }

        $now = WireTime::nowDb();

        // uid القديم الأول (الترحيل بيطابق عليه) وبعدين الإيميل
        $c = DB::select('SELECT * FROM customers WHERE legacy_key = ? LIMIT 1', [$uid])[0] ?? null;
        if (! $c && $email !== '') {
            $c = DB::select('SELECT * FROM customers WHERE email = ? LIMIT 1', [$email])[0] ?? null;
        }

        if (! $c) {
            DB::insert(
                'INSERT INTO customers (legacy_key, email, photo_url, display_name, created_at, last_login_at)
                 VALUES (?,?,?,?,?,?)',
                [$uid, $email !== '' ? $email : null, $photo ?: null, $name ?: null, $now, $now]
            );
            $cid = (int) DB::getPdo()->lastInsertId();
        } else {
            // المحظور مايفتحش جلسة أصلًا — 403 من باب الدخول
            if ((int) $c->blocked === 1) {
                throw new ApiException('حسابك موقوف مؤقتًا — كلّم خدمة العملاء', 403);
            }
            $cid = (int) $c->id;
            /* backfill: uid/الإيميل/الصورة من غير ما نمسح حاجة موجودة.
               COALESCE بيحافظ على القديم، وNULLIF بيمنع الفاضي إنه يدوس
               على قيمة موجودة. `photo_url` عليها NULLIF من الجهتين لأن
               الأصل كان بيخزّن '' فيها في نسخ قديمة. */
            DB::update(
                "UPDATE customers
                    SET legacy_key = COALESCE(legacy_key, ?),
                        email      = COALESCE(email, NULLIF(?, '')),
                        photo_url  = COALESCE(NULLIF(photo_url, ''), NULLIF(?, '')),
                        last_login_at = ?
                  WHERE id = ?",
                [$uid, $email, $photo, $now, $cid]
            );
        }

        $row = self::customerRowFull($cid);
        if (! $row) {
            throw new ApiException('تعذّر فتح الحساب — جرّب تاني', 500);
        }

        // session_regenerate_id(true) — تثبيت الجلسة ممنوع بعد الدخول
        $request->session()->regenerate(true);
        $request->session()->put([
            'customer_id' => $cid,
            'user_id'     => $cid,   // عشان require_auth() في المسارات المشتركة
            'username'    => $row['email'] ?: ('customer-' . $cid),
            'role'        => 'customer',
            'branch_id'   => null,
            'name'        => $row['display_name'] ?: $name,
        ]);

        return ApiResponse::out([
            'ok'       => true,
            'customer' => CustomerAppWire::customer($row),
            'wallet'   => self::walletWire($cid),
            'version'  => config('dahshan.version'),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════
       GET /api/customer/me — البروفايل + المحفظة
       (المحظور بيوصل هنا عشان الواجهة تعرض شاشة «الحساب موقوف» فورًا)
    ═══════════════════════════════════════════════════════════════ */

    public function me(Request $request): JsonResponse
    {
        $c = $this->customerRequire($request, true);

        return ApiResponse::out([
            'ok'       => true,
            'customer' => CustomerAppWire::customer(self::customerRowFull((int) $c['id'])),
            'wallet'   => self::walletWire((int) $c['id']),
            'version'  => config('dahshan.version'),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════
       PUT /api/customer/profile — استكمال/تعديل البيانات
    ═══════════════════════════════════════════════════════════════ */

    /** المقابل لـ customer_valid_phone() — مصري: 10 أرقام بعد 1، وصفر اختياري */
    private static function validPhone(string $p): bool
    {
        return (bool) preg_match('/^0?1[0-9]{9}$/', str_replace([' ', '-'], '', $p));
    }

    public function profileUpdate(Request $request): JsonResponse
    {
        $c = $this->customerRequire($request);
        $b = $this->body($request);

        $name   = trim((string) ($b['displayNameAr'] ?? ''));
        $phone1 = trim((string) ($b['phone1'] ?? ''));
        $phone2 = trim((string) ($b['phone2'] ?? ''));
        if ($name === '') {
            throw new ApiException('اكتب اسمك بالكامل');
        }
        if (! self::validPhone($phone1)) {
            throw new ApiException('رقم هاتف غير صحيح');
        }
        if ($phone2 !== '' && ! self::validPhone($phone2)) {
            throw new ApiException('الرقم الإضافي غير صحيح');
        }

        $zoneId = isset($b['defaultZoneId']) && $b['defaultZoneId'] !== '' && $b['defaultZoneId'] !== null
            ? (int) $b['defaultZoneId'] : null;
        $branchId = null;
        if ($zoneId !== null) {
            $z = DB::select('SELECT delivery_branch_id FROM zones WHERE id = ? LIMIT 1', [$zoneId])[0] ?? null;
            if (! $z) {
                throw new ApiException('المنطقة المختارة غير موجودة');
            }
            $branchId = (int) $z->delivery_branch_id;
        }

        /* «البيانات مكتملة» بتتعلّم أول ما يختار منطقة — ومابترجعش false
           بعدين لو بعت تعديل من غير منطقة (COALESCE تحت بيحافظ على
           الزون القديم كمان). ده اللي بيفتح له إنشاء الأوردرات. */
        $completed = ($zoneId !== null) ? 1 : (int) $c['profile_completed'];

        DB::update(
            "UPDATE customers
                SET display_name = ?, phone1 = ?, phone2 = ?, address = ?, lat = ?, lng = ?,
                    default_zone_id = COALESCE(?, default_zone_id),
                    default_branch_id = COALESCE(?, default_branch_id),
                    photo_url = COALESCE(NULLIF(?, ''), photo_url),
                    profile_completed = ?
              WHERE id = ?",
            [
                $name, $phone1, $phone2 !== '' ? $phone2 : null,
                trim((string) ($b['address'] ?? '')) ?: null,
                isset($b['lat']) && $b['lat'] !== '' && $b['lat'] !== null ? (float) $b['lat'] : null,
                isset($b['lng']) && $b['lng'] !== '' && $b['lng'] !== null ? (float) $b['lng'] : null,
                $zoneId, $branchId,
                isset($b['photoURL']) ? trim((string) $b['photoURL']) : '',
                $completed, (int) $c['id'],
            ]
        );

        $request->session()->put('name', $name);

        return ApiResponse::ok(['customer' => CustomerAppWire::customer(self::customerRowFull((int) $c['id']))]);
    }

    /* ═══════════════════════════════════════════════════════════════
       العناوين المحفوظة — CRUD
    ═══════════════════════════════════════════════════════════════ */

    /** المقابل لـ customer_addresses_fetch() */
    private static function addressesFetch(int $customerId): array
    {
        $rows = DB::select(
            'SELECT a.*, z.area_name AS _zone_name, b.name AS _branch_name
               FROM customer_addresses a
               LEFT JOIN zones z ON z.id = a.zone_id
               LEFT JOIN branches b ON b.id = a.branch_id
              WHERE a.customer_id = ?
              ORDER BY a.is_default DESC, a.id',
            [$customerId]
        );

        return array_map([CustomerAppWire::class, 'address'], $rows);
    }

    /**
     * GET /api/customer/addresses
     *
     * ⚠️ شذوذ في العقد منقول زي ما هو: الرد `{ok, changed, items}` **من
     * غير `serverNow`** — مخالف لغلاف الاستطلاع الموحد، فمقدرناش نستخدم
     * `PollableList` معاه (نفس شذوذ قوايم `support.php`).
     */
    public function addressesList(Request $request): JsonResponse
    {
        $c = $this->customerRequire($request, true);

        return ApiResponse::out([
            'ok'      => true,
            'changed' => true,
            'items'   => self::addressesFetch((int) $c['id']),
        ]);
    }

    /**
     * بيرجّع [zoneId|null, branchId|null] بعد التحقق من الزون —
     * المقابل لـ customer_addr_zone().
     */
    private static function addrZone(array $b): array
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

    /**
     * POST /api/customer/addresses
     *
     * جملتين كتابة (تصفير الافتراضي + الإدخال) فلازم معاملة — الأصل بيعمل
     * beginTransaction/commit يدوي هنا بالظبط.
     */
    public function addressesCreate(Request $request): JsonResponse
    {
        $c = $this->customerRequire($request);
        $b = $this->body($request);

        $full = trim((string) ($b['fullAddress'] ?? ''));
        if ($full === '') {
            throw new ApiException('اكتب العنوان');
        }
        [$zoneId, $branchId] = self::addrZone($b);
        if ($zoneId === null) {
            throw new ApiException('اختر المنطقة');
        }

        $isDefault = ! empty($b['isDefault']) ? 1 : 0;

        DB::transaction(function () use ($c, $b, $full, $zoneId, $branchId, $isDefault): void {
            // عنوان افتراضي واحد بس لكل عميل — التصفير قبل الإدخال
            if ($isDefault) {
                DB::update('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?', [(int) $c['id']]);
            }
            DB::insert(
                'INSERT INTO customer_addresses
                   (customer_id, label, full_address, lat, lng, zone_id, branch_id, is_default, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [
                    (int) $c['id'],
                    trim((string) ($b['label'] ?? '')) ?: 'عنوان',
                    $full,
                    isset($b['lat']) && $b['lat'] !== '' && $b['lat'] !== null ? (float) $b['lat'] : null,
                    isset($b['lng']) && $b['lng'] !== '' && $b['lng'] !== null ? (float) $b['lng'] : null,
                    $zoneId, $branchId, $isDefault, WireTime::nowDb(),
                ]
            );
        });

        return ApiResponse::ok(['items' => self::addressesFetch((int) $c['id'])]);
    }

    /**
     * PUT /api/customer/addresses/{id}
     *
     * تعديل جزئي: `array_key_exists` هي الفرق بين «الحقل مش متبعت» (نسيب
     * القديم) و«متبعت فاضي» (نصفّره). عشان كده الجسم بيتقرا خام.
     */
    public function addressesUpdate(Request $request, string $id): JsonResponse
    {
        $c = $this->customerRequire($request);
        $b = $this->body($request);

        $row = DB::select(
            'SELECT * FROM customer_addresses WHERE id = ? AND customer_id = ?',
            [(int) $id, (int) $c['id']]
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
            [$zoneId, $branchId] = self::addrZone($b);
        } else {
            $zoneId   = $a['zone_id'] !== null ? (int) $a['zone_id'] : null;
            $branchId = $a['branch_id'] !== null ? (int) $a['branch_id'] : null;
        }
        if ($zoneId === null) {
            throw new ApiException('اختر المنطقة');
        }

        $isDefault = array_key_exists('isDefault', $b) ? (int) ! empty($b['isDefault']) : (int) $a['is_default'];

        DB::transaction(function () use ($c, $b, $a, $id, $full, $zoneId, $branchId, $isDefault): void {
            // `id <> ?` — التصفير مابيلمسش الصف اللي بنعدّله هو نفسه
            if ($isDefault) {
                DB::update(
                    'UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ? AND id <> ?',
                    [(int) $c['id'], (int) $id]
                );
            }
            DB::update(
                'UPDATE customer_addresses
                    SET label = ?, full_address = ?, lat = ?, lng = ?, zone_id = ?, branch_id = ?, is_default = ?
                  WHERE id = ? AND customer_id = ?',
                [
                    trim((string) ($b['label'] ?? $a['label'])) ?: 'عنوان',
                    $full,
                    array_key_exists('lat', $b)
                        ? ($b['lat'] !== '' && $b['lat'] !== null ? (float) $b['lat'] : null)
                        : ($a['lat'] !== null ? (float) $a['lat'] : null),
                    array_key_exists('lng', $b)
                        ? ($b['lng'] !== '' && $b['lng'] !== null ? (float) $b['lng'] : null)
                        : ($a['lng'] !== null ? (float) $a['lng'] : null),
                    $zoneId, $branchId, $isDefault, (int) $id, (int) $c['id'],
                ]
            );
        });

        return ApiResponse::ok(['items' => self::addressesFetch((int) $c['id'])]);
    }

    /**
     * DELETE /api/customer/addresses/{id}
     *
     * `customer_id` في الـWHERE هو الحارس — عميل مايمسحش عنوان عميل تاني،
     * و«مش بتاعك» بيرجّع نفس رسالة «مش موجود» (مابنسربش وجود الصف).
     */
    public function addressesDelete(Request $request, string $id): JsonResponse
    {
        $c = $this->customerRequire($request);

        $n = DB::delete(
            'DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?',
            [(int) $id, (int) $c['id']]
        );
        if ($n === 0) {
            throw new ApiException('العنوان غير موجود', 404);
        }

        return ApiResponse::ok(['items' => self::addressesFetch((int) $c['id'])]);
    }

    /* ═══════════════════════════════════════════════════════════════
       المستلمون المحفوظون — قايمة + upsert بالتليفون (saveReceiver القديمة)
    ═══════════════════════════════════════════════════════════════ */

    /**
     * GET /api/customer/receivers
     * ⚠️ نفس شذوذ العناوين: `{ok, changed, items}` من غير `serverNow`.
     */
    public function receiversList(Request $request): JsonResponse
    {
        $c = $this->customerRequire($request, true);

        $rows = DB::select(
            'SELECT * FROM customer_saved_receivers WHERE customer_id = ?
              ORDER BY created_at DESC, id DESC LIMIT 50',
            [(int) $c['id']]
        );

        return ApiResponse::out([
            'ok'      => true,
            'changed' => true,
            'items'   => array_map([CustomerAppWire::class, 'savedReceiver'], $rows),
        ]);
    }

    /**
     * POST /api/customer/receivers —
     * {name, phone, phone2?, address?, zoneId?, lat?, lng?, overwrite?}
     *
     * رقم المستلم فريد داخل حساب العميل: التكرار بيترفض — إلا لو
     * `overwrite:true` (تحديث بيانات مستلم محفوظ = saveReceiver القديمة).
     *
     * 🔴 **الـupsert بالاعتماد على خطأ 1062 مقصود ومش «تنضيف» يستبدل
     * بـ`SELECT` قبل الإدخال**: الفهرس الفريد على (customer_id, phone) هو
     * اللي بيحسم السباق بين طلبين متزامنين. `SELECT` ثم `INSERT` كان
     * بيسيب فجوة. عشان كده **مفيش معاملة هنا** — جملة كتابة واحدة بتنفّذ
     * فعليًا في كل الحالات (يا إدخال يا تحديث)، زي الأصل بالحرف.
     *
     * ⚠️ الزون غير الموجود **بيتجاهل بصمت** (بيبقى null) — مابيرجعش خطأ.
     * سلوك الأصل، والسبب إن دفتر المستلمين بيتزامن من التطبيق ومنه بيانات
     * قديمة لزونات اتشالت.
     */
    public function receiversUpsert(Request $request): JsonResponse
    {
        $c = $this->customerRequire($request);
        $b = $this->body($request);

        $name  = trim((string) ($b['name'] ?? ''));
        $phone = trim((string) ($b['phone'] ?? ''));
        if ($name === '' || $phone === '') {
            throw new ApiException('اسم ورقم المستلم مطلوبين');
        }
        if (! self::validPhone($phone)) {
            throw new ApiException('رقم المستلم غير صحيح');
        }

        $zoneId = isset($b['zoneId']) && $b['zoneId'] !== '' && $b['zoneId'] !== null ? (int) $b['zoneId'] : null;
        if ($zoneId !== null) {
            $chk = DB::select('SELECT id FROM zones WHERE id = ?', [$zoneId]);
            if (! $chk) {
                $zoneId = null;
            }
        }

        $overwrite = ! empty($b['overwrite']);
        $vals = [
            trim((string) ($b['phone2'] ?? '')) ?: null,
            trim((string) ($b['address'] ?? '')) ?: null,
            $zoneId,
            isset($b['lat']) && $b['lat'] !== '' && $b['lat'] !== null ? (float) $b['lat'] : null,
            isset($b['lng']) && $b['lng'] !== '' && $b['lng'] !== null ? (float) $b['lng'] : null,
        ];

        try {
            DB::insert(
                'INSERT INTO customer_saved_receivers
                   (customer_id, name, phone, phone2, address, zone_id, lat, lng, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                array_merge([(int) $c['id'], $name, $phone], $vals, [WireTime::nowDb()])
            );
        } catch (QueryException $e) {
            // 1062 = Duplicate entry على الفهرس الفريد. أي خطأ تاني بيعدّي
            // للمعالج المركزي برسالة «خطأ في قاعدة البيانات» زي الأصل.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            if (! $overwrite) {
                throw new ApiException('في مستلم محفوظ بنفس الرقم بالفعل');
            }
            DB::update(
                'UPDATE customer_saved_receivers
                    SET name = ?, phone2 = ?, address = ?, zone_id = ?, lat = ?, lng = ?
                  WHERE customer_id = ? AND phone = ?',
                array_merge([$name], $vals, [(int) $c['id'], $phone])
            );
        }

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════════
       POST /api/customer/orders — إنشاء أوردر من التطبيق
       المُرسِل هو العميل نفسه، والفرع بيتحدد من زون المُرسِل
       (delivery_branch_id بتاع الزون) — نفس قاعدة «الفرع من زون المُرسِل»
    ═══════════════════════════════════════════════════════════════ */

    public function orderCreate(Request $request): JsonResponse
    {
        $c   = $this->customerRequire($request);
        $cid = (int) $c['id'];

        // البوابة: من غير بيانات مكتملة مفيش أوردر — الاسم والموبايل
        // والمنطقة هم اللي بيبنوا صف المُرسِل جوه الأوردر
        if ((int) $c['profile_completed'] !== 1) {
            throw new ApiException('اكمل بياناتك (الاسم ورقم الموبايل والمنطقة) الأول قبل إنشاء أوردر');
        }

        $b = $this->body($request);
        $deliveriesIn = $b['deliveries'] ?? [];
        if (! is_array($deliveriesIn) || ! count($deliveriesIn)) {
            throw new ApiException('أضف طردًا واحدًا على الأقل');
        }

        // عنوان الاستلام: addressId مبعوت أو العنوان الافتراضي (لو فيه)
        $addr = null;
        $addrId = isset($b['addressId']) && $b['addressId'] !== '' && $b['addressId'] !== null
            ? (int) $b['addressId'] : null;
        if ($addrId !== null) {
            $row = DB::select(
                'SELECT * FROM customer_addresses WHERE id = ? AND customer_id = ? LIMIT 1',
                [$addrId, $cid]
            )[0] ?? null;
            if (! $row) {
                throw new ApiException('العنوان المختار غير موجود', 404);
            }
            $addr = (array) $row;
        } else {
            $row = DB::select(
                'SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id LIMIT 1',
                [$cid]
            )[0] ?? null;
            $addr = $row !== null ? (array) $row : null;
        }

        // زون المُرسِل → الفرع. الترتيب: المبعوت ← زون العنوان ← زون البروفايل
        $senderZoneId = isset($b['senderZoneId']) && $b['senderZoneId'] !== '' && $b['senderZoneId'] !== null
            ? (int) $b['senderZoneId']
            : ($addr && $addr['zone_id'] !== null ? (int) $addr['zone_id']
                : ($c['default_zone_id'] !== null ? (int) $c['default_zone_id'] : null));
        if (! $senderZoneId) {
            throw new ApiException('حدّد منطقة الاستلام (اختر عنوانًا له منطقة)');
        }

        $zoneRow = DB::select(
            'SELECT z.*, b.code AS _branch_code FROM zones z
             JOIN branches b ON b.id = z.delivery_branch_id
             WHERE z.id = ? LIMIT 1',
            [$senderZoneId]
        )[0] ?? null;
        if (! $zoneRow) {
            throw new ApiException('منطقة الاستلام غير موجودة', 404);
        }
        $senderZone = (array) $zoneRow;
        $branchId   = (int) $senderZone['delivery_branch_id'];
        $branchCode = (string) $senderZone['_branch_code'] ?: 'ORD';

        $now = WireTime::nowDb();

        try {
            $orderId = DB::transaction(function () use (
                $c, $cid, $b, $deliveriesIn, $addr, $senderZoneId, $branchId, $branchCode, $now
            ): int {
                /* الترقيم الذري اليومي لكل فرع (نفس قاعدة orders_create):
                   الزيادة في جملة upsert واحدة، وبعدين قراية صريحة للعدّاد.
                   ⚠️ ممنوع LAST_INSERT_ID() هنا — في مسار الإدخال الجديد
                   (أول شحنة للفرع في اليوم) MySQL بيدوس عليها بالـid
                   المتولّد فأول رقم كان بيطلع = id الصف. (اتصلح 2026-08-19.)
                   الـFOR UPDATE على القراية منقول بالحرف من الأصل. */
                $dayKey = OrderNumber::cairoDayKeyCompact();
                DB::insert(
                    'INSERT INTO order_counters (branch_id, day_key, counter, created_at)
                     VALUES (?,?,1,?)
                     ON DUPLICATE KEY UPDATE counter = counter + 1',
                    [$branchId, $dayKey, $now]
                );
                $cnt = DB::select(
                    'SELECT counter FROM order_counters WHERE branch_id = ? AND day_key = ? FOR UPDATE',
                    [$branchId, $dayKey]
                );
                $orderNum = OrderNumber::format($branchCode, (int) ($cnt[0]->counter ?? 0));

                // الطرود: snapshot الزون + جمع الفلوس
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
                    $recvName  = trim((string) ($d['receiverName'] ?? ''));
                    $recvPhone = trim((string) ($d['receiverPhone'] ?? ''));
                    if ($recvName === '') {
                        throw new ApiException('اكتب اسم المستلم لكل طرد');
                    }
                    // ⚠️ التحقق من رقم المستلم **إجباري هنا** — على عكس
                    // orders_create بتاع الموظفين. سبب: منظومة الثقة بتبني
                    // على الرقم، وأوردر التطبيق مالوش موظف يراجعه.
                    if ($recvPhone === '' || ! self::validPhone($recvPhone)) {
                        throw new ApiException('رقم المستلم غير صحيح في الطرد رقم ' . $no);
                    }
                    $zoneId = isset($d['zoneId']) && $d['zoneId'] !== '' && $d['zoneId'] !== null
                        ? (int) $d['zoneId'] : null;
                    if (! $zoneId) {
                        throw new ApiException('اختر منطقة التسليم لكل طرد');
                    }
                    $zone = $zoneLookup($zoneId);
                    if (! $zone) {
                        throw new ApiException('منطقة التسليم غير موجودة في الطرد رقم ' . $no);
                    }

                    /* snapshot الزون: الاسم والسعر بيتخزّنوا على الطرد نفسه
                       عشان تغيير تسعيرة المنطقة بعدين مايغيّرش أوردر قديم.
                       ⚠️ هنا **السعر من الزون إجباري** — مفيش قبول لـ
                       zonePrice من الجسم زي مسار الموظفين، عشان العميل
                       مايسعّرش أوردره بنفسه. */
                    $parcels[] = [
                        'parcel_no'       => $no,
                        'receiver_name'   => $recvName,
                        'receiver_phone'  => $recvPhone,
                        'receiver_phone2' => trim((string) ($d['receiverPhone2'] ?? '')) ?: null,
                        'zone_id'         => $zoneId,
                        'zone_name'       => (string) $zone['area_name'],
                        'zone_price'      => (float) $zone['price'],
                        'order_price'     => (float) ($d['orderPrice'] ?? 0),
                        'address'         => trim((string) ($d['address'] ?? '')) ?: null,
                        'note'            => trim((string) ($d['note'] ?? '')) ?: null,
                    ];
                }
                // 🔴 فلوس: مجموع خام من غير round() — منقول بالحرف
                $totalPrice   = array_sum(array_column($parcels, 'zone_price'));
                $storePrepaid = array_sum(array_column($parcels, 'order_price'));

                DB::insert(
                    'INSERT INTO orders
                       (order_num, branch_id, sender_name, sender_phone, sender_phone2, sender_address,
                        sender_zone_id, sender_lat, sender_lng,
                        status, status_since, created_at,
                        total_delivery_price, store_prepaid,
                        source, added_by, added_by_role,
                        customer_id, customer_name, customer_phone,
                        pieces_count, qr_code, notes)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $orderNum, $branchId,
                        $c['display_name'] ?: 'عميل التطبيق',
                        $c['phone1'], $c['phone2'],
                        ($addr ? $addr['full_address'] : null) ?: $c['address'],
                        $senderZoneId,
                        $addr && $addr['lat'] !== null ? (float) $addr['lat'] : ($c['lat'] !== null ? (float) $c['lat'] : null),
                        $addr && $addr['lng'] !== null ? (float) $addr['lng'] : ($c['lng'] !== null ? (float) $c['lng'] : null),
                        'processing', $now, $now,
                        $totalPrice, $storePrepaid,
                        'customer',
                        $c['legacy_key'] ?: ('customer#' . $cid),   // زي القديم: added_by = uid العميل
                        'customer',
                        $cid, $c['display_name'], $c['phone1'],
                        count($parcels), $orderNum,
                        trim((string) ($b['notes'] ?? '')) ?: null,
                    ]
                );
                $orderId = (int) DB::getPdo()->lastInsertId();

                foreach ($parcels as $p) {
                    DB::insert(
                        'INSERT INTO order_deliveries
                           (order_id, parcel_no, receiver_name, receiver_phone, receiver_phone2,
                            zone_id, zone_name, zone_price, order_price, address, note, status, created_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [
                            $orderId, $p['parcel_no'], $p['receiver_name'], $p['receiver_phone'], $p['receiver_phone2'],
                            $p['zone_id'], $p['zone_name'], $p['zone_price'], $p['order_price'],
                            $p['address'], $p['note'], 'processing', $now,
                        ]
                    );
                }

                // أوردر جديد جاي من تطبيق العميل — لوحة الفرع اللي `branchId`
                // بتاعه اتحدد من زون الاستلام هي اللي هتشوفه
                $this->broadcastOrder($orderId);

                /* رسالة الواتساب للمستلمين. ⚠️ المسار ده بيفرض رقم مستلم
                   صالح لكل طرد فوق (`validPhone`)، فقواعد التخطّي بتاعة
                   «مفيش رقم» و«رقم مش صالح» عمليًا مابتتحققش هنا — بتفضل
                   شغّالة لأن الأوردر ممكن يتعدّل بعدين من مسار الموظفين. */
                $this->notifyOrderReceivers($orderId);

                return $orderId;
            });
        } catch (QueryException $e) {
            // نفس catch(PDOException) في الأصل — رسالة خاصة بالمسار
            Log::error('customer_order_create: ' . $e->getMessage());
            throw new ApiException('خطأ أثناء حفظ الأوردر — جرّب تاني', 500);
        }

        return ApiResponse::ok(['order' => OrderWire::full($orderId)]);
    }

    /* ═══════════════════════════════════════════════════════════════
       أوردرات العميل — بتاعته + الشحنات الجاية له (مطابقة برقم تليفونه)
    ═══════════════════════════════════════════════════════════════ */

    /**
     * كل صيغ الرقم المحتملة في `order_deliveries.receiver_phone` —
     * المقابل لـ customer_phone_variants().
     *
     * الأرقام في القاعدة اتكتبت على مدار سنين بصيغ مختلفة (بمسافات،
     * بشرطات، بصفر وبلا صفر)، فالمطابقة بتتعمل على **كل الصيغ** مش على
     * صيغة موحّدة — التوحيد كان هيحتاج تعديل بيانات تاريخية.
     * حد الـ8 أرقام بيمنع أرقام قصيرة (زي "0") إنها تطابق نص الجدول.
     */
    private static function phoneVariants(?string ...$phones): array
    {
        $out = [];
        foreach ($phones as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            $digits = preg_replace('/\D+/', '', $p);
            if (strlen($digits) < 8) {
                continue;
            }
            $noZero = ltrim($digits, '0');
            $out[$p] = true;
            $out[$digits] = true;
            $out[$noZero] = true;
            $out['0' . $noZero] = true;
        }

        return array_keys($out);
    }

    /** فحص إن رقم الطرد ده بتاع العميل — customer_phone_matches() */
    private static function phoneMatches(array $variantSet, ?string $phone): bool
    {
        $p = trim((string) $phone);
        if ($p === '') {
            return false;
        }
        $digits = preg_replace('/\D+/', '', $p);

        return isset($variantSet[$p]) || isset($variantSet[$digits]) || isset($variantSet[ltrim($digits, '0')]);
    }

    /**
     * WHERE بتاع «أوردراتي» — بيرجّع [sql, params, variants].
     *
     * تلات مصادر للملكية: `customer_id`، والأوردرات المرحّلة من فايربيز
     * (`source='customer' AND added_by = uid`)، والشحنات الجاية للعميل
     * (رقم تليفونه في أي طرد).
     */
    private static function ordersWhere(array $c): array
    {
        $mine   = '(o.customer_id = ?)';
        $params = [(int) $c['id']];

        // الأوردرات المرحّلة من فايربيز اتكتبت بـ added_by = uid القديم
        if (! empty($c['legacy_key'])) {
            $mine = "(o.customer_id = ? OR (o.source = 'customer' AND o.added_by = ?))";
            $params[] = $c['legacy_key'];
        }

        $variants = self::phoneVariants($c['phone1'] ?? null, $c['phone2'] ?? null);
        if ($variants) {
            $ph = implode(',', array_fill(0, count($variants), '?'));
            $sql = "($mine OR EXISTS (SELECT 1 FROM order_deliveries dm
                                       WHERE dm.order_id = o.id AND dm.receiver_phone IN ($ph)))";

            return [$sql, array_merge($params, $variants), $variants];
        }

        return ["($mine)", $params, []];
    }

    /**
     * بيعلّم الأوردر `_incoming`/`_myParcels` — زي recomputeOrders القديمة.
     *
     * المفتاحين بيتضافوا **في آخر كائن الأوردر** (إضافة على مصفوفة موجودة)
     * — ترتيب المفاتيح جزء من الرد فمتتحطش في النص.
     * `_myParcels` بيبقى `[0]` لو مفيش طرد مطابق: الواجهة بتعرض أول طرد
     * كـfallback بدل شاشة فاضية.
     */
    private static function annotateOrders(array $orders, array $c, array $variants): array
    {
        $variantSet = array_flip($variants);
        $cid = (int) $c['id'];
        $uid = (string) ($c['legacy_key'] ?? '');

        foreach ($orders as &$o) {
            $isMine = ((int) ($o['customerId'] ?? 0) === $cid)
                || ($uid !== '' && ($o['source'] ?? '') === 'customer' && ($o['addedBy'] ?? '') === $uid);
            if ($isMine) {
                $o['_incoming']  = false;
                $o['_myParcels'] = null;
                continue;
            }
            $mineIdx = [];
            foreach ($o['deliveries'] ?? [] as $i => $d) {
                if (self::phoneMatches($variantSet, $d['receiverPhone'] ?? null)) {
                    $mineIdx[] = $i;
                }
            }
            $o['_incoming']  = true;
            $o['_myParcels'] = $mineIdx ?: [0];
        }
        unset($o);

        return $orders;
    }

    /** GET /api/customer/orders?since= */
    public function ordersList(Request $request): JsonResponse
    {
        $c = $this->customerRequire($request, true);
        [$whereSql, $params, $variants] = self::ordersWhere($c);

        $sinceRaw = $request->query('since');
        $since = ($sinceRaw !== null && $sinceRaw !== '') ? (int) $sinceRaw : null;
        if ($since !== null) {
            $whereSql .= ' AND o.updated_at > FROM_UNIXTIME(? / 1000)';
            $params[] = $since;
        }

        $rows = DB::select(
            OrderWire::baseSql() . ' WHERE ' . $whereSql . ' ORDER BY o.created_at DESC LIMIT 300',
            $params
        );

        $serverNow = PollableList::serverNowMs();
        if ($since !== null && ! $rows) {
            return PollableList::unchanged($serverNow);
        }

        return PollableList::items(
            self::annotateOrders(OrderWire::batch($rows), $c, $variants),
            $serverNow
        );
    }

    /* ═══════════════════════════════════════════════════════════════
       GET /api/customer/incoming — لوكاب الشحنات
       ?phone=01xxxxxxxxx        → شحنات الرقم ده (ويزارد التسجيل قبل الحفظ)
       ?code=HAL-260804-001[-2]  → طرد الباركود (?t= جاي من صفحة التتبّع)
       من غير باراميتر           → شحنات أرقام البروفايل المحفوظة

       🔒 الخصوصية (اتقفلت 2026-08-19):
       المسار ده كان `customer_require()` وبس — من غير أي فحص ملكية. يعني
       أي حساب عميل كان يقدر:
         (1) يبعت ?code= بأي رقم أوردر — والأرقام متوقّعة (كود فرع + تاريخ +
             3 خانات) — ويستلم الأوردر **كامل**: اسم المُرسِل وتليفونه
             وعنوانه، وكل الطرود بأسماء وعناوين مستلميها، والأسعار.
         (2) يبعت ?phone= بأي رقم موبايل ويستلم آخر 10 أوردرات ليه.
       يعني سحب قاعدة العملاء بالترتيب — وده بالظبط اللي قاعدة الخصوصية
       بتاعة صاحب النظام بتمنعه.

       ليه مقدرناش نقفله بفحص ملكية وخلاص: المسار ده **مقصود** إنه يشتغل
       قبل ما العميل يكمّل بياناته — شاشة التسجيل بتقرا الباركود أو بتاخد
       الرقم اللي بيكتبه وتملّي بياناته من الشحنة. فالقفل الكامل كان هيكسر
       التسجيل.

       الحل المطبَّق — تفرقة بين حالتين:
         • **عميل كمّل بياناته** (الحالة الغالبة): `?phone=` بيتتجاهل تمامًا
           وبنستخدم أرقامه هو من الجلسة، و`?code=` لازم يكون أوردر يخصّه
           (بـ customer_id أو رقم تليفونه في أحد الطرود). ملكية كاملة.
         • **عميل لسه بيسجّل**: البحث الحر متاح — بس بسقف 15 عملية في
           الساعة (بيتسجّلوا في lookup_log زي منظومة الثقة) وبرد **مقنّع**:
           أسماء الحقول كلها باقية زي ما الدستور بيقول، لكن الأسعار
           وتليفون/عنوان المُرسِل بيرجعوا null، وبيرجع الطرد بتاعه هو بس
           مش باقي طرود الأوردر.
    ═══════════════════════════════════════════════════════════════ */

    /**
     * بيقنّع أوردر راجع من بحث حر (عميل لسه ما كمّلش بياناته).
     * الدستور: **ممنوع حذف اسم حقل** — بنصفّر القيمة بس، عشان الواجهة
     * تفضل تلاقي المفاتيح اللي بتقراها.
     * الطرد المطابق بيفضل كامل لأنه بيانات صاحب الشحنة نفسه.
     *
     * ⚠️ `netDeliveryPrice` في القايمة رغم إنه **مش مفتاح في كائن الأوردر**
     * — `array_key_exists` بيتخطاه بصمت. منقول بالحرف (حماية لو اتضاف
     * المفتاح بعدين).
     */
    private static function incomingMask(array $order, array $keepDelivery): array
    {
        foreach (['totalDeliveryPrice', 'storePrepaid', 'goodsValue', 'walletUsed', 'netDeliveryPrice'] as $k) {
            if (array_key_exists($k, $order)) {
                $order[$k] = null;
            }
        }
        foreach (['storePrepaidNote', 'senderPhone', 'senderPhone2', 'senderAddress',
                  'senderLat', 'senderLng', 'customerPhone', 'notes'] as $k) {
            if (array_key_exists($k, $order)) {
                $order[$k] = null;
            }
        }
        // طرود الأوردر التانية بيانات ناس تانية — بيتشالوا من القايمة
        $keep = $keepDelivery;
        foreach (['orderPrice', 'zonePrice'] as $k) {
            if (array_key_exists($k, $keep)) {
                $keep[$k] = null;
            }
        }
        $order['deliveries'] = [$keep];

        return [$order, $keep];
    }

    /**
     * بيسجّل عملية بحث حر ويرفض لو العميل عدّى السقف —
     * المقابل لـ customer_incoming_throttle().
     *
     * السقف بيتحسب من `lookup_log` نفسه (مش من كاش) عشان يفضل شغال عبر
     * أكتر من عامل/سيرفر، ونفس الجدول بيدّي أثر تدقيق لكل عملية بحث حر.
     * `full_access = 0` دايمًا — الرد مقنّع بحكم التعريف.
     */
    private function incomingThrottle(array $c, string $searched, bool $found): void
    {
        $actor = 'customer:' . (int) $c['id'];

        $n = (int) (DB::select(
            'SELECT COUNT(*) AS n FROM lookup_log
              WHERE actor_name = ? AND created_at >= (UTC_TIMESTAMP() - INTERVAL 1 HOUR)',
            [$actor]
        )[0]->n ?? 0);

        if ($n >= self::INCOMING_HOURLY_LIMIT) {
            throw new ApiException(
                'تجاوزت الحد المسموح للبحث (' . self::INCOMING_HOURLY_LIMIT
                . ' عملية في الساعة) — حاول بعد شوية',
                429
            );
        }

        DB::insert(
            'INSERT INTO lookup_log (actor_type, actor_name, searched_phone, found, full_access, created_at)
             VALUES (?,?,?,?,0,?)',
            ['customer_onboarding', $actor, mb_substr($searched, 0, 20), $found ? 1 : 0, WireTime::nowDb()]
        );
    }

    public function incoming(Request $request): JsonResponse
    {
        $c = $this->customerRequire($request, true);

        // أرقام العميل نفسه — الأساس اللي الملكية بتتحدد بيه
        $ownVariants = self::phoneVariants($c['phone1'] ?? null, $c['phone2'] ?? null);
        $ownSet      = array_flip($ownVariants);
        $completed   = (int) ($c['profile_completed'] ?? 0) === 1;

        /** الأوردر ده يخص العميل؟ (بحسابه أو برقم تليفونه في أحد الطرود) */
        $isOwned = function (array $order) use ($c, $ownSet): bool {
            if ((int) ($order['customerId'] ?? 0) === (int) $c['id']) {
                return true;
            }
            $uid = (string) ($c['legacy_key'] ?? '');
            if ($uid !== '' && ($order['source'] ?? '') === 'customer'
                && ($order['addedBy'] ?? '') === $uid) {
                return true;
            }
            foreach ($order['deliveries'] ?? [] as $d) {
                if (self::phoneMatches($ownSet, $d['receiverPhone'] ?? null)) {
                    return true;
                }
            }

            return false;
        };

        $code = strtoupper(trim((string) ($request->query('code') ?? '')));
        if ($code !== '') {
            /* الباركود ممكن يبقى رقم أوردر أو رقم أوردر + رقم طرد
               (HAL-260804-001-2). بنجرّب الاتنين في نفس الاستعلام: النسخة
               الكاملة (أوردر مفصول رقمه فيه لاحقة أصلًا) والنسخة المقصوصة. */
            $codes = [$code];
            $parcelNo = null;
            if (preg_match('/^(.*)-(\d+)$/', $code, $m)) {
                $codes[] = $m[1];
                $parcelNo = (int) $m[2];
            }
            $ph = implode(',', array_fill(0, count($codes), '?'));
            $row = DB::select(
                OrderWire::baseSql() . " WHERE UPPER(o.order_num) IN ($ph) LIMIT 1",
                $codes
            )[0] ?? null;

            if (! $row) {
                if (! $completed) {
                    $this->incomingThrottle($c, $code, false);
                }

                return ApiResponse::ok(['items' => []]);
            }

            $order = OrderWire::batch([$row])[0];
            $owned = $isOwned($order);

            // عميل كمّل بياناته: الباركود لازم يكون بتاع أوردر يخصّه.
            // من غير الشرط ده كان أي حساب يعدّ الأرقام ويسحب النظام كله.
            if ($completed && ! $owned) {
                return ApiResponse::ok(['items' => []]);
            }
            if (! $completed) {
                $this->incomingThrottle($c, $code, true);
            }

            // لو الرقم كامل اتطابق مباشرة (أوردر مفصول) اللاحقة مش لاحقة طرد
            if (strtoupper((string) $order['orderNum']) === $code) {
                $parcelNo = null;
            }
            $d = null;
            foreach ($order['deliveries'] as $dd) {
                if ($parcelNo === null || (int) $dd['parcelNo'] === $parcelNo) {
                    $d = $dd;
                    break;
                }
            }
            if (! $d) {
                $d = $order['deliveries'][0] ?? null;
            }
            if (! $d) {
                return ApiResponse::ok(['items' => []]);
            }

            if (! $owned) {
                [$order, $d] = self::incomingMask($order, $d);
            }

            return ApiResponse::ok(['items' => [['order' => $order, 'd' => $d]]]);
        }

        // ?phone= بحث حر — متاح للعميل اللي لسه بيسجّل بس. بعد اكتمال البيانات
        // بنتجاهله ونستخدم أرقامه من الجلسة، عشان مايبقاش أداة تعداد.
        $asked = trim((string) ($request->query('phone') ?? ''));
        $freeSearch = null;
        if ($asked !== '' && ! $completed) {
            $freeSearch = $asked;
            $variants = self::phoneVariants($asked);
        } else {
            $variants = $ownVariants;
        }
        if (! $variants) {
            return ApiResponse::ok(['items' => []]);
        }

        $ph = implode(',', array_fill(0, count($variants), '?'));
        $rows = DB::select(
            OrderWire::baseSql() .
            " WHERE EXISTS (SELECT 1 FROM order_deliveries dm
                             WHERE dm.order_id = o.id AND dm.receiver_phone IN ($ph))
              ORDER BY o.created_at DESC LIMIT 10",
            $variants
        );

        // ⚠️ التسجيل **بعد** الاستعلام عشان `found` تبقى حقيقية — يعني
        // البحث الفاشل بيتحسب من السقف برضه (وده المقصود: التعداد بيعتمد
        // على المحاولات الفاشلة أكتر من الناجحة).
        if ($freeSearch !== null) {
            $this->incomingThrottle($c, $freeSearch, (bool) $rows);
        }
        if (! $rows) {
            return ApiResponse::ok(['items' => []]);
        }

        $variantSet = array_flip($variants);
        $items = [];
        foreach (OrderWire::batch($rows) as $order) {
            $owned = $freeSearch === null || $isOwned($order);
            foreach ($order['deliveries'] as $d) {
                if (self::phoneMatches($variantSet, $d['receiverPhone'] ?? null)) {
                    if (! $owned) {
                        [$mo, $md] = self::incomingMask($order, $d);
                        $items[] = ['order' => $mo, 'd' => $md];
                        continue;
                    }
                    $items[] = ['order' => $order, 'd' => $d];
                }
            }
        }

        return ApiResponse::ok(['items' => $items]);
    }

    /* ═══════════════════════════════════════════════════════════════
       GET /api/customer/orders/{id}/track — التتبّع اللايف (Poller كل 8ث)
    ═══════════════════════════════════════════════════════════════ */

    /**
     * الأوردر لازم يخص العميل (بتاعه أو جايله) — بيرجّع كائن الأوردر الكامل.
     * المقابل لـ customer_owned_order().
     *
     * ⚠️ «مش موجود» 404 و«مش تابع لحسابك» 403 — رسالتين مختلفتين وأكواد
     * مختلفة. (على عكس مسار الإلغاء اللي بيرجّع 409 للاتنين — شوف هناك.)
     */
    private static function ownedOrder(array $c, int|string $idOrKey): array
    {
        $order = OrderWire::full($idOrKey);
        if (! $order) {
            throw new ApiException('الطلب غير موجود', 404);
        }

        $cid = (int) $c['id'];
        $uid = (string) ($c['legacy_key'] ?? '');
        if ((int) ($order['customerId'] ?? 0) === $cid) {
            return $order;
        }
        if ($uid !== '' && ($order['source'] ?? '') === 'customer' && ($order['addedBy'] ?? '') === $uid) {
            return $order;
        }
        $variantSet = array_flip(self::phoneVariants($c['phone1'] ?? null, $c['phone2'] ?? null));
        foreach ($order['deliveries'] ?? [] as $d) {
            if (self::phoneMatches($variantSet, $d['receiverPhone'] ?? null)) {
                return $order;
            }
        }

        throw new ApiException('الطلب ده مش تابع لحسابك', 403);
    }

    public function orderTrack(Request $request, string $id): JsonResponse
    {
        $c = $this->customerRequire($request, true);
        $order = self::ownedOrder($c, $id);

        /* تليفون الطيار وموقعه — بيتقروا **بعد** فحص الملكية عن قصد.

           🔴 الموقع بيتكشف **بس** والرحلة فعليًا باتجاه العميل ده:
           الحالة «جاري التوصيل» + الطيار داس «بدء الرحلة» في تطبيقه
           (tripStartedAt). قبل الشرط ده العميل كان بيشوف الطيار على
           الخريطة من لحظة الإسناد — بيلف المدينة على أوردرات ناس
           تانية. ده كان بيكشف خط سير الطيار وأماكن عملاء تانيين،
           وصاحب النظام طلب قفله بالنص: «يراه وهو يتحرك إليه فقط».
           التليفون بيفضل متاح من الإسناد — العميل محتاج يكلّم الطيار
           للتنسيق قبل الرحلة، ورقم موبايل مش بيكشف مكان حد. */
        $pilotPhone = null;
        $location   = null;
        $tripActive = ($order['status'] ?? '') === 'جاري التوصيل'
            && ! empty($order['tripStartedAt']);
        if (! empty($order['pilotId'])) {
            $p = DB::select(
                'SELECT phone1, lat, lng, location_updated_at FROM pilots WHERE id = ? LIMIT 1',
                [(int) $order['pilotId']]
            )[0] ?? null;
            if ($p) {
                $pilotPhone = $p->phone1 ?? null;
                if ($tripActive && $p->lat !== null && $p->lng !== null) {
                    $location = [
                        'lat'       => (float) $p->lat,
                        'lng'       => (float) $p->lng,
                        'updatedAt' => WireTime::toWire($p->location_updated_at),
                    ];
                }
            }
        }

        /* إحداثيات نقطة التسليم لخريطة التتبّع — الـwire بتاع الطرد مابيطلّعش lat/lng
           (عقد OrderWire ثابت)، فبنبعتها هنا. الطرد المختار: اللي رقم مستلمه بتاع
           العميل ده (الشحنات الجاية ليه) وإلا أول طرد له إحداثيات. */
        $destination = null;
        $variants = self::phoneVariants($c['phone1'] ?? null, $c['phone2'] ?? null);
        $dRows = DB::select(
            'SELECT receiver_phone, lat, lng FROM order_deliveries WHERE order_id = ? ORDER BY id',
            [(int) $order['id']]
        );
        foreach ($dRows as $r) {
            if ($r->lat === null || $r->lng === null) {
                continue;
            }
            $pick = ['lat' => (float) $r->lat, 'lng' => (float) $r->lng];
            if ($variants && in_array((string) $r->receiver_phone, $variants, true)) {
                $destination = $pick;
                break;
            }
            $destination ??= $pick;
        }

        return ApiResponse::out([
            'ok'            => true,
            'serverNow'     => PollableList::serverNowMs(),
            'order'         => $order,
            'pilotPhone'    => $pilotPhone,
            'pilotLocation' => $location,
            'destination'   => $destination,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════
       POST /api/customer/orders/{id}/cancel — إلغاء أوردر «قيد التنفيذ»
    ═══════════════════════════════════════════════════════════════ */

    /** جلب صف أوردر بالـ id الرقمي أو legacy_key مقفول — orders_lock_row() */
    private static function lockOrderRow(int|string $idOrKey): ?array
    {
        $col = (is_int($idOrKey) || ctype_digit((string) $idOrKey)) ? 'id' : 'legacy_key';
        $row = DB::select("SELECT * FROM orders WHERE {$col} = ? LIMIT 1 FOR UPDATE", [$idOrKey])[0] ?? null;

        return $row !== null ? (array) $row : null;
    }

    /** المقابل لـ finance_lock_wallet() — قفل صف المحفظة وإنشاؤه لو مش موجود */
    private static function lockWallet(string $ownerType, int $ownerId): array
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
     * POST /api/customer/orders/{id}/cancel
     *
     * ⚠️ **كل أخطاء المسار ده 409** — حتى «الطلب غير موجود» و«مش تابع
     * لحسابك». في الأصل الكتلة كلها بترمي `RuntimeException` والـcatch
     * بينده `fail($e->getMessage(), 409)`، فالكود واحد للكل. منقول بالحرف.
     * (قارن بـ`ownedOrder` اللي بترجّع 404/403.)
     *
     * 💰 رد رصيد المحفظة (اتصلح 2026-08-19)
     *   الإلغاء كان بيغيّر الحالة وبس — فالعميل اللي خصم من محفظته على
     *   الأوردر ثم ألغاه كان **بيخسر الرصيد نهائيًا**: مفيش حركة عكسية
     *   ومفيش تصفير لـ `wallet_used`.
     *   بنرجّع على **نفس** المحفظة اللي اتخصم منها فعلًا (بنجيبها من صف
     *   الحركة نفسه) مش على المحفظة المفترضة — لأن apply-wallet بيخصم من
     *   محفظة العميل أو محفظة المحل حسب مين نداه.
     *   الإلغاء المكرر مش خطر: الاستدعاء التاني بيقع على شرط
     *   `status !== 'processing'` فوق قبل ما يوصل هنا.
     */
    public function orderCancel(Request $request, string $id): JsonResponse
    {
        $c   = $this->customerRequire($request);
        $now = WireTime::nowDb();

        $orderId = DB::transaction(function () use ($c, $id, $now): int {
            $order = self::lockOrderRow($id);
            if (! $order) {
                throw new ApiException('الطلب غير موجود', 409);
            }
            if ((int) ($order['customer_id'] ?? 0) !== (int) $c['id']) {
                throw new ApiException('الطلب ده مش تابع لحسابك', 409);
            }
            if ($order['status'] !== 'processing') {
                throw new ApiException('الإلغاء متاح قبل تحميل الطلب على طيار بس — كلّم خدمة العملاء', 409);
            }

            DB::update(
                "UPDATE orders
                    SET prev_status = ?, status = 'cancelled', status_since = ?,
                        cancelled_at = ?, cancelled_by = ?, cancelled_reason = ?
                  WHERE id = ?",
                [
                    $order['status'] ?: 'processing', $now, $now, 'عميل', 'إلغاء من العميل', (int) $order['id'],
                ]
            );

            // 🔴 فلوس — التقريب على القيمة المخزّنة قبل المقارنة، زي الأصل
            $used = round((float) ($order['wallet_used'] ?? 0), 2);
            if ($used > 0) {
                $walletId = DB::select(
                    "SELECT wallet_id FROM wallet_transactions
                      WHERE order_num = ? AND type = 'use' ORDER BY id DESC LIMIT 1",
                    [$order['order_num']]
                )[0]->wallet_id ?? null;

                // بيانات مرحّلة قديمة ممكن يكون فيها wallet_used من غير صف حركة
                // — ساعتها بنرجّع على محفظة العميل صاحب الأوردر.
                if ($walletId) {
                    $w = DB::select('SELECT * FROM wallets WHERE id = ? FOR UPDATE', [(int) $walletId])[0] ?? null;
                    $wallet = $w !== null ? (array) $w : null;
                } else {
                    $wallet = self::lockWallet('customer', (int) $c['id']);
                }

                if ($wallet) {
                    $newBalance = round((float) $wallet['balance'] + $used, 2);
                    DB::update(
                        'UPDATE wallets SET balance = ? WHERE id = ?',
                        [$newBalance, (int) $wallet['id']]
                    );
                    DB::insert(
                        "INSERT INTO wallet_transactions
                            (wallet_id, amount, type, note, order_num, balance_after, created_by, created_at)
                         VALUES (?,?,'credit',?,?,?,?,?)",
                        [
                            (int) $wallet['id'],
                            $used,
                            'رجوع رصيد بعد إلغاء الطلب',
                            $order['order_num'],
                            $newBalance,
                            'عميل',
                            $now,
                        ]
                    );
                    DB::update('UPDATE orders SET wallet_used = 0 WHERE id = ?', [(int) $order['id']]);
                } else {
                    // محفظة مش لاقيينها = مانكملش الإلغاء بصمت وناكل الرصيد
                    throw new ApiException('تعذّر رد رصيد المحفظة — كلّم خدمة العملاء', 409);
                }
            }

            /* تغيير حالة → «ملغي». مقصود إنها **بعد** بلوك المحفظة مش قبله:
               بثّة واحدة للأوردر بعد ما كل تعديلاته تخلص (الحالة +
               `wallet_used = 0`)، ولو رد الرصيد فشل بيترمي استثناء فوق
               والمعاملة بترجع فمفيش حدث أصلًا. */
            $this->broadcastOrder((int) $order['id']);

            return (int) $order['id'];
        });

        return ApiResponse::ok(['order' => OrderWire::full($orderId)]);
    }

    /* ═══════════════════════════════════════════════════════════════
       POST /api/customer/orders/{id}/rating — {stars 1..5, note?}
       بيتخزن rater='customer' (منفصل عن تقييم المحل — ratings/customer)
    ═══════════════════════════════════════════════════════════════ */

    public function orderRating(Request $request, string $id): JsonResponse
    {
        $c = $this->customerRequire($request);
        $b = $this->body($request);

        $stars = (int) ($b['stars'] ?? 0);
        if ($stars < 1 || $stars > 5) {
            throw new ApiException('التقييم من 1 لـ 5 نجوم');
        }
        $order = self::ownedOrder($c, $id);

        // ⚠️ المقارنة على **الحالة العربية** بتاعة السلك مش الكود الإنجليزي
        // — لأن `ownedOrder` بترجّع كائن الأوردر المتسلسل مش صف القاعدة.
        if (($order['status'] ?? '') !== 'تم التسليم') {
            throw new ApiException('التقييم متاح بعد تسليم الطلب');
        }

        // DELETE ثم INSERT = «تقييم واحد لكل مقيّم» — إعادة التقييم بتستبدل
        // القديم. الجملتين لازم يبقوا في معاملة واحدة.
        DB::transaction(function () use ($order, $stars, $b, $c): void {
            DB::delete("DELETE FROM order_ratings WHERE order_id = ? AND rater = 'customer'", [(int) $order['id']]);
            DB::insert(
                "INSERT INTO order_ratings (order_id, rater, stars, note, rated_by, rated_by_id, rated_at)
                 VALUES (?,'customer',?,?,?,?,?)",
                [
                    (int) $order['id'], $stars,
                    trim((string) ($b['note'] ?? '')),
                    $c['display_name'] ?: ($c['email'] ?? ''),
                    (string) $c['id'],
                    WireTime::nowDb(),
                ]
            );
        });

        return ApiResponse::ok(['order' => OrderWire::full((int) $order['id'])]);
    }

    /* ═══════════════════════════════════════════════════════════════
       الإشعارات — مشتقّة من توقيتات الأوردرات (buildNotifs القديمة)
       GET  /api/customer/notifications      → {items, seenAt, unread}
       POST /api/customer/notifications/seen → بيعلّم الكل كمقروء
    ═══════════════════════════════════════════════════════════════ */

    /**
     * ⚠️ مفيش جدول إشعارات — الإشعارات **مشتقّة** من طوابع الأوردر نفسه.
     * ده مقصود: مفيش صف يتخزّن ولا يتزامن، والقايمة بتتبني من نفس البيانات
     * اللي شاشة الطلبات بتقراها فمستحيل تختلف عنها.
     * الترتيب تنازلي **بمقارنة نصية** على الطابع ISO — صالح لأن الصيغة
     * ثابتة الطول وUTC، والمقارنة النصية = المقارنة الزمنية.
     *
     * 🔴 قواعد الاشتقاق دي **منسوخة حرفيًا** في `App\Jobs\SendCustomerPush::latestState()`
     * (إشعار الستارة بيبعت أحدث حالة بنفس المفاتيح) — أي تعديل هنا لازم يتعمل هناك.
     */
    public function notifications(Request $request): JsonResponse
    {
        $c = $this->customerRequire($request, true);
        [$whereSql, $params] = self::ordersWhere($c);

        $rows = DB::select(
            "SELECT o.id, o.order_num, o.status, o.pilot_id, o.status_since, o.current_pilot_since,
                    o.created_at, o.received_at, o.trip_started_at, o.delivered_at,
                    o.undelivered_at, o.undelivered_reason, o.cancelled_at
               FROM orders o
              WHERE $whereSql
              ORDER BY o.created_at DESC LIMIT 120",
            $params
        );

        $items = [];
        foreach ($rows as $row) {
            $o = (array) $row;
            $oid = (int) $o['id'];
            $num = $o['order_num'];
            $push = function (string $key, ?string $at, string $title) use (&$items, $oid, $num): void {
                if ($at === null || $at === '') {
                    return;
                }
                $items[] = ['orderId' => $oid, 'num' => $num, 'key' => $key,
                            'title' => $title, 'at' => WireTime::toWire($at)];
            };

            // «اتسند لطيار»: بيتحسب من current_pilot_since لو موجود عشان
            // النقل بين الطيارين يطلع إشعار جديد، وfallback على status_since
            // «وصل الفرع» — أول إشعار، لحظة الإنشاء (نفس المفتاح في SendCustomerPush::latestState)
            $push('created', $o['created_at'] ?? null, 'تم استلام طلبك في الفرع — جارٍ تجهيزه');
            if ($o['pilot_id'] !== null || in_array($o['status'], ['delivering', 'delivered'], true)) {
                $push('assigned', $o['current_pilot_since'] ?: $o['status_since'], 'تم إسناد طلبك لطيار');
            }
            $push('received', $o['received_at'],     'الطيار استلم شحنتك');
            $push('out',      $o['trip_started_at'], 'الطيار في الطريق إليك');
            if ($o['status'] === 'delivered') {
                $push('delivered', $o['delivered_at'], 'تم التسليم بنجاح');
            }
            if ($o['status'] === 'undelivered') {
                $push('failed', $o['undelivered_at'],
                    'لم يتم التوصيل' . ($o['undelivered_reason'] ? ' — ' . $o['undelivered_reason'] : ''));
            }
            if ($o['status'] === 'cancelled') {
                $push('cancel', $o['cancelled_at'] ?: $o['status_since'], 'تم إلغاء الطلب');
            }
        }

        usort($items, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        $items = array_slice($items, 0, 60);

        $seenAt = WireTime::toWire($c['notif_seen_at']);
        $unread = 0;
        foreach ($items as $n) {
            if ($seenAt === null || $n['at'] > $seenAt) {
                $unread++;
            }
        }

        return ApiResponse::out([
            'ok' => true, 'serverNow' => PollableList::serverNowMs(),
            'changed' => true, 'items' => $items, 'seenAt' => $seenAt, 'unread' => $unread,
        ]);
    }

    /**
     * POST /api/customer/notifications/seen
     * ⚠️ `customerRequire(true)` — المحظور بيقدر يعلّم إشعاراته كمقروءة.
     * سلوك الأصل: المسار ده مالوش أثر على بيانات حد تاني.
     */
    public function notificationsSeen(Request $request): JsonResponse
    {
        $c = $this->customerRequire($request, true);
        $now = WireTime::nowDb();

        DB::update('UPDATE customers SET notif_seen_at = ? WHERE id = ?', [$now, (int) $c['id']]);

        return ApiResponse::ok(['seenAt' => WireTime::toWire($now)]);
    }

    /* ═══════════════════════════════════════════════════════════════
       إشعارات ستارة الهاتف (Web Push) — اشتراكات أجهزة العميل
       GET  /api/customer/push/key         → {publicKey}
       POST /api/customer/push/subscribe   → upsert على الـendpoint
       POST /api/customer/push/unsubscribe → حذف
       إضافة بعد الترحيل (2026-08-22) — النظام القديم كان بيستطلع بس.
       الإرسال نفسه في App\Jobs\SendCustomerPush (بتتدفع من broadcastOrder)،
       والإعداد في config/dahshan.php → push.

       ⚠️ التلاتة `customerRequire(true)` — المحظور بيقدر يشترك ويلغي:
       الإشعارات بتخص أوردراته هو (زي notifications)، ومفيش أثر على حد تاني.
    ═══════════════════════════════════════════════════════════════ */

    /** حد أقصى لطول الـendpoint — أطول عناوين خدمات الدفع المعروفة (WNS) حوالي 500 */
    private const PUSH_ENDPOINT_MAX = 2000;

    /**
     * GET /api/customer/push/key
     * مفتاح VAPID العام — المتصفح بيحتاجه في `pushManager.subscribe()`.
     *
     * لو VAPID مش متظبّط بنرجّع `ok:false` برسالة واضحة بدل مفتاح فاضي:
     * المتصفح كان هيرفض المفتاح الفاضي بخطأ غامض عند العميل. الكود 404
     * (المفتاح مش موجود) مش 503 عن قصد — `ApiException` بحالة ≥500 بتتسجّل
     * في اللوج بمكدس كامل (شوف bootstrap/app.php)، وده مسار بيتنده مع كل
     * فتحة تطبيق في الفترة اللي الإنتاج لسه من غير مفاتيح.
     */
    public function pushKey(Request $request): JsonResponse
    {
        $this->customerRequire($request, true);

        $key = (string) config('dahshan.push.public_key', '');
        // الزوج كامل مش العام بس: مفتاح خاص ناقص كان بيخلّي العميل يشترك ويشوف
        // «مفعّلة ✓» والمهمة تسكت من أول سطر — فشل صامت تمامًا
        if ($key === '' || ! \App\Services\Push\WebPushFactory::configured()) {
            throw new ApiException('الإشعارات الفورية مش متفعّلة على السيرفر حاليًا', 404);
        }

        return ApiResponse::ok(['publicKey' => $key]);
    }

    /**
     * POST /api/customer/push/subscribe — body {endpoint, keys:{p256dh, auth}, ua?}
     *
     * Upsert على الـendpoint (المفتاح الفريد `endpoint_hash`): إعادة الاشتراك
     * من نفس المتصفح بتحدّث المفاتيح وبتصفّر fail_count بدل ما تعمل صف تاني.
     * ولو نفس المتصفح سجّل دخول بعميل تاني، الصف **بيتنقل** له
     * (`customer_id = VALUES(customer_id)`) — الجهاز بيتبع آخر عميل اشترك منه،
     * ومفيش جهاز بيستقبل إشعارات حسابين.
     *
     * مفيش تحقق تشفيري من المفاتيح هنا — بنتأكد من الشكل بس (base64url وطول
     * معقول). المفتاح التالف بيفشل وقت الإرسال وبيزوّد fail_count، مش بيقع
     * الاشتراك.
     */
    public function pushSubscribe(Request $request): JsonResponse
    {
        $c = $this->customerRequire($request, true);
        $b = $this->body($request);

        // أي حاجة مش نص (مصفوفة/رقم/null) = فاضي — من غير ما cast يرمي تحذير
        $str = static fn ($v): string => is_string($v) ? trim($v) : '';

        $endpoint = $str($b['endpoint'] ?? null);
        $keys     = is_array($b['keys'] ?? null) ? $b['keys'] : [];
        $p256dh   = $str($keys['p256dh'] ?? null);
        $auth     = $str($keys['auth'] ?? null);
        $ua       = $str($b['ua'] ?? null);

        if ($endpoint === '' || ! str_starts_with($endpoint, 'https://')
            || strlen($endpoint) > self::PUSH_ENDPOINT_MAX) {
            throw new ApiException('عنوان الاشتراك غير صالح', 400);
        }
        // base64url زي ما المتصفح بيطلّعه (مع السماح بحشو = لو اتبعت)
        $b64 = '/^[A-Za-z0-9_-]+={0,2}$/';
        if ($p256dh === '' || $auth === '' || strlen($p256dh) > 255 || strlen($auth) > 255
            || ! preg_match($b64, $p256dh) || ! preg_match($b64, $auth)) {
            throw new ApiException('مفاتيح الاشتراك غير صالحة', 400);
        }

        DB::statement(
            'INSERT INTO customer_push_subscriptions
               (customer_id, endpoint_hash, endpoint, p256dh, auth, ua, created_at, last_ok_at, fail_count)
             VALUES (?,?,?,?,?,?,?,NULL,0)
             ON DUPLICATE KEY UPDATE
               customer_id = VALUES(customer_id),
               p256dh      = VALUES(p256dh),
               auth        = VALUES(auth),
               ua          = COALESCE(VALUES(ua), ua),
               fail_count  = 0',
            [
                (int) $c['id'],
                hash('sha256', $endpoint),
                $endpoint,
                $p256dh,
                $auth,
                $ua !== '' ? mb_substr($ua, 0, 255) : null,
                WireTime::nowDb(),
            ]
        );

        return ApiResponse::ok();
    }

    /**
     * POST /api/customer/push/unsubscribe — body {endpoint}
     *
     * الحذف بالـendpoint بس من غير تقييد بـcustomer_id عن قصد: الـendpoint
     * عنوان سري مايعرفوش غير المتصفح صاحبه، فاللي بيبعته هو صاحب الجهاز —
     * وده بيغطّي حالة جهاز مشترك اتسجّل عليه دخول بعميل تاني وعايز يوقف
     * إشعارات الأول. مفيش صف = ok برضه (الإلغاء idempotent — الواجهة بتنده
     * وهي مش متأكدة لو كان فيه اشتراك أصلًا).
     */
    public function pushUnsubscribe(Request $request): JsonResponse
    {
        $this->customerRequire($request, true);
        $b = $this->body($request);

        $endpoint = is_string($b['endpoint'] ?? null) ? trim($b['endpoint']) : '';
        if ($endpoint === '') {
            throw new ApiException('عنوان الاشتراك مطلوب', 400);
        }

        DB::delete('DELETE FROM customer_push_subscriptions WHERE endpoint_hash = ?', [hash('sha256', $endpoint)]);

        return ApiResponse::ok();
    }
}
