<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Services\Auth\LoginThrottle;
use App\Support\ApiResponse;
use App\Support\WireTime;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * مسارات الدخول والخروج والجلسة — نقل حرفي لـ api/routes/auth.php.
 * شكل الردود مجمّد: الواجهات وتطبيق الطيار بيقروا نفس المفاتيح.
 */
class AuthController
{
    /**
     * GET /api/health — بيتنده من smoke_all قبل أي حاجة تانية
     */
    public function health(): JsonResponse
    {
        try {
            DB::select('SELECT 1');

            return ApiResponse::ok([
                'db'      => true,
                'version' => config('dahshan.version'),
            ]);
        } catch (\Throwable) {
            return ApiResponse::out([
                'ok'    => false,
                'db'    => false,
                'error' => 'قاعدة البيانات غير متاحة',
            ], 500);
        }
    }

    /**
     * POST /api/login — {username, password, client?}
     * client:"pilot-app" بيرجّع كمان توكن للموبايل (توكن جديد بيلغي القديم).
     */
    public function login(Request $request): JsonResponse
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === '') {
            throw new ApiException('اسم المستخدم وكلمة المرور مطلوبان');
        }

        $ip  = substr((string) ($request->server('REMOTE_ADDR') ?? '0.0.0.0'), 0, 45);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $throttle = LoginThrottle::fromConfig();

        // 1) القفل بيتفحص قبل أي لمسة للباسورد
        if ($throttle->isLocked($ip, $username, $now)) {
            throw new ApiException('حاولت كتير — استنى شويّة وجرّب تاني', 429);
        }

        $user = DB::table('users')->where('username', $username)->first();

        /*
         * 2) حماية التوقيت — لو المستخدم مش موجود بنعمل password_verify على
         * هاش وهمي عشان زمن الرد يتساوى ويمنع تعداد أسماء المستخدمين.
         *
         * ملاحظة: الهاش الوهمي في النظام القديم كان **61 حرف** والصالح 60.
         * اتقاس فعليًا (2026-08-19): الزمنين 69.3 و71.6 مللي — يعني crypt()
         * بينفّذ جولات bcrypt برضه والحماية كانت شغالة رغم الطول الغلط.
         * هنا بنستخدم هاش صالح 60 حرف عشان النية تبقى واضحة في الكود.
         */
        $dummy = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $ok = $user
            ? password_verify($password, (string) $user->password_hash)
            : (password_verify($password, $dummy) && false);

        if (! $ok) {
            $throttle->registerFailure($ip, $username, $now);
            throw new ApiException('اسم المستخدم أو كلمة المرور غير صحيحة', 401);
        }

        if ((int) $user->blocked === 1) {
            throw ApiException::blocked();
        }

        // نجاح — نصفّر عدّاد الفشل للمفتاح ده
        $throttle->clear($ip, $username);

        $request->session()->regenerate(true);
        $request->session()->put([
            'user_id'   => (int) $user->id,
            'username'  => $user->username,
            'role'      => $user->role,
            'branch_id' => $user->branch_id !== null ? (int) $user->branch_id : null,
            'name'      => $user->name,
        ]);

        $out = [
            'ok'   => true,
            'user' => [
                'user_id'   => (int) $user->id,
                'username'  => $user->username,
                'role'      => $user->role,
                'branch_id' => $user->branch_id !== null ? (int) $user->branch_id : null,
                'name'      => $user->name,
                'pilot_id'  => $user->pilot_id !== null ? (int) $user->pilot_id : null,
            ],
            'version' => config('dahshan.version'),
        ];

        // تطبيق الموبايل — توكن جديد بيلغي القديم (جهاز واحد نشط)
        if ($request->input('client') === 'pilot-app') {
            $token = bin2hex(random_bytes(32));   // 64 hex
            DB::table('users')->where('id', (int) $user->id)->update([
                'api_token'    => $token,
                'api_token_at' => $now->format('Y-m-d H:i:s'),
            ]);
            $out['token'] = $token;
        }

        return ApiResponse::out($out);
    }

    /**
     * POST /api/admin/google-login — {idToken}
     *
     * دخول الإدارة بحساب جوجل: بيتحقق من التوكن **سيرفر-سايد** (نفس آلية
     * تطبيق العميل — RS256 + شهادات جوجل)، وبيتأكد إن الإيميل مسجّل في
     * `admin_emails`، وبعدين بيفتح جلسة إدارة.
     *
     * 🔴 **bootstrap أول مدير**: لو جدول `admin_emails` **فاضي تمامًا**،
     * أول إيميل بيدخل بيتسجّل فيه ويعدّي. ده مقصود — نظام جديد مالوش أي
     * مدير مسجّل مايبقاش مقفول على نفسه. بس معناه إن **أول واحد يوصل
     * للمسار ده على نشر جديد بياخد الإدارة**، فالخطوة دي لازم تتعمل قبل
     * ما الدومين يبقى عام. (سلوك الأصل بالحرف.)
     *
     * ⚠️ الجلسة بتترتبط بأول حساب `admin` في `users` (عشان الصلاحيات
     * والاسم). لو مفيش حساب admin أصلًا، الجلسة بتفتح بـ`user_id = 0`
     * والاسم من التوكن — منقول زي ما هو.
     *
     * ⚠️ الرد هنا **مالوش `branch_id`** في كائن الـuser (على عكس
     * `/api/login`)، وفيه `email` و`allowedApps` بدلها. مفتاح `via='google'`
     * بيتخزّن في الجلسة بس ومابيطلعش على السلك.
     */
    public function adminGoogleLogin(Request $request): JsonResponse
    {
        $b = $request->json()->all();

        $idToken = trim((string) ($b['idToken'] ?? ''));
        if ($idToken === '') {
            throw new ApiException('توكن الدخول مطلوب', 400);
        }

        // نفس التحقق الآمن RS256 بتاع تطبيق العميل — دالة مشتركة زي الأصل
        $p = CustomerAppController::verifyIdToken($idToken);

        // الإيميل بيتخزّن ويتقارن **lowercase** — جوجل بيرجّعه بأي حالة أحرف
        $email = strtolower(trim((string) ($p['email'] ?? '')));
        if ($email === '') {
            throw new ApiException('الحساب لازم يكون ليه إيميل', 401);
        }

        $cnt = (int) (DB::select('SELECT COUNT(*) AS n FROM admin_emails')[0]->n ?? 0);
        if ($cnt === 0) {
            // bootstrap — أول مدير. INSERT IGNORE بيمتص سباق دخولين متزامنين
            DB::insert(
                'INSERT IGNORE INTO admin_emails (email, created_at) VALUES (?, ?)',
                [$email, WireTime::nowDb()]
            );
        } else {
            if (! DB::select('SELECT 1 AS n FROM admin_emails WHERE email = ? LIMIT 1', [$email])) {
                throw ApiException::forbidden('الإيميل ده مش مصرّح له بالدخول كإدارة');
            }
        }

        // نربط الجلسة بحساب admin الموجود (عشان الصلاحيات والاسم) — أو admin الافتراضي
        $adminUser = DB::select("SELECT * FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")[0] ?? null;

        /* `$adminUser['username'] ?? $email` في الأصل بيقع على `$email` لما
           الـfetch يرجّع false (تحذير PHP بس، مش خطأ). مكتوبة هنا صريحة. */
        $userId   = $adminUser !== null ? (int) $adminUser->id : 0;
        $username = $adminUser !== null && $adminUser->username !== null ? $adminUser->username : $email;
        $name     = $adminUser !== null && $adminUser->name !== null
            ? $adminUser->name
            : ($p['name'] ?? 'الإدارة');

        $request->session()->regenerate(true);
        $request->session()->put([
            'user_id'   => $userId,
            'username'  => $username,
            'role'      => 'admin',
            'branch_id' => null,
            'name'      => $name,
            // علامة مصدر الدخول — بتتخزّن في الجلسة بس ومابتطلعش على السلك
            'via'       => 'google',
        ]);

        return ApiResponse::out([
            'ok'   => true,
            'user' => [
                'user_id'     => $userId,
                'username'    => $username,
                'role'        => 'admin',
                'name'        => $name,
                'email'       => $email,
                // دخول جوجل بيفتح كل التطبيقات — مفيش صفوف صلاحيات وراه
                'allowedApps' => ['admin', 'branch', 'store', 'callcenter', 'hr', 'accounts'],
            ],
            'version' => config('dahshan.version'),
        ]);
    }

    /**
     * GET /api/me — الجلسة الحالية. نقل حرفي لـ route_me().
     *
     * الرد بيرجّع مصفوفة require_auth() كما هي، وعليها إضافات حسب الدور:
     *  • المحل: بيانات المحل + ملف الاستلام الدائم + حالة الحظر (بتتقرا من
     *    القاعدة كل مرة عشان الحظر يوصل الواجهة فورًا).
     *  • الموظفين: allowedApps (بوابة الدخول بتوجّه بيها) و pagePerms
     *    (صلاحيات الصفحات — من غيرها الواجهة مش قادرة تقفل صفحة على موظف).
     */
    public function me(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $user  = $actor->toLegacyArray();

        if ($actor->role === 'store') {
            $row = DB::table('users')
                ->select('shop_name', 'shop_phone', 'shop_phone2', 'shop_address',
                         'shop_zone_id', 'shop_lat', 'shop_lng', 'sender_id', 'blocked')
                ->where('id', $actor->userId)
                ->first();

            if ($row) {
                $user['shopName']    = $row->shop_name;
                $user['shopPhone']   = $row->shop_phone;
                $user['shopPhone2']  = $row->shop_phone2;
                $user['shopAddress'] = $row->shop_address;
                // ملف الاستلام الدائم — الواجهة بتعبّي بيه فورم الشحنة كل مرة
                $user['shopZoneId']  = $row->shop_zone_id !== null ? (int) $row->shop_zone_id : null;
                $user['shopLat']     = $row->shop_lat !== null ? (float) $row->shop_lat : null;
                $user['shopLng']     = $row->shop_lng !== null ? (float) $row->shop_lng : null;
                $user['senderId']    = $row->sender_id !== null ? (int) $row->sender_id : null;
                $user['blocked']     = (int) $row->blocked === 1;
            }
        }

        if ($actor->userId !== null && $actor->role !== 'store') {
            $apps = DB::table('user_app_permissions')
                ->where('user_id', $actor->userId)
                ->orderBy('id')
                ->pluck('app')
                ->all();

            /* الافتراضي لما مفيش صفوف صلاحيات صريحة. المدير بيشوف كل
               التطبيقات، ومشرف الطيارين بياخد لوحته هو بس — من غير الافتراضي
               ده كان هيدخل ويلاقي البوابة فاضية لحد ما الأدمن يفتحله يدوي.
               أي صفوف صريحة في user_app_permissions بتغلب الاتنين. */
            if (! $apps) {
                $apps = match ($actor->role) {
                    'admin'            => ['admin', 'branch', 'store', 'callcenter', 'hr', 'accounts'],
                    'pilot_supervisor' => ['pilotsadmin'],
                    default            => [],
                };
            }
            $user['allowedApps'] = $apps;

            // نفس شكل ser_user: {app: {page: true}} و {} لو فاضية
            $pages = [];
            foreach (DB::table('user_page_permissions')
                        ->where('user_id', $actor->userId)
                        ->orderBy('id')
                        ->get(['app', 'page']) as $p) {
                $pages[$p->app][$p->page] = true;
            }
            $user['pagePerms'] = $pages ?: new \stdClass();
        }

        return ApiResponse::ok([
            'user'    => $user,
            'version' => config('dahshan.version'),
        ]);
    }

    /**
     * POST /api/logout — بينهي الجلسة. مابيفشلش لو مفيش جلسة أصلًا.
     *
     * خروج تطبيق الموبايل: بيلغي التوكن اللي جه في هيدر X-Auth-Token
     * **من غير ما يلمس جلسات الويب** بتاعة نفس الحساب.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = trim((string) $request->header('X-Auth-Token', ''));
        if ($token !== '' && strlen($token) <= 64) {
            DB::table('users')->where('api_token', $token)->update([
                'api_token'    => null,
                'api_token_at' => null,
            ]);
        }

        $request->session()->flush();
        $request->session()->invalidate();

        return ApiResponse::ok(['message' => 'تم تسجيل الخروج']);
    }
}
