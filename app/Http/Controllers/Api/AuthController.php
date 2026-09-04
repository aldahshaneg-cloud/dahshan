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
     * التطبيقات المسموحة لمستخدم — **مصدر الحقيقة الوحيد** لبوابة الدخول
     * ولـ`GET /api/me` مع بعض.
     *
     * الترتيب: صفوف `user_app_permissions` الصريحة بتغلب، ولو مفيش بياخد
     * افتراضي دوره.
     *
     * 🔴 الافتراضي كان `[]` لأي دور غير admin/pilot_supervisor. ده كان
     * مقبول وقت ما البوابة كانت عرض كروت بس — بس دلوقتي بقى **قفل دخول**،
     * فمشرف فرع اتعمل من غير صفوف صريحة كان هيتقفل بره كل التطبيقات.
     * فكل دور بقى له افتراضي معقول.
     *
     * والتوسعات هنا هي **نفس** اللي في `home.html` (`openApp`) بالحرف —
     * لو الاتنين اختلفوا، البوابة هتعرض كارت والدخول يرفضه.
     *
     * @return string[]
     */
    public static function appsFor(?int $userId, string $role): array
    {
        $apps = $userId !== null
            ? DB::table('user_app_permissions')->where('user_id', $userId)->orderBy('id')->pluck('app')->all()
            : [];

        if (! $apps) {
            $apps = match ($role) {
                'admin'            => ['admin', 'branch', 'store', 'callcenter', 'hr', 'accounts', 'pilotacct'],
                'pilot_supervisor' => ['pilotsadmin'],
                'branch'           => ['branch'],
                'callcenter'       => ['callcenter'],
                'accountant'       => ['accounts'],
                'hr'               => ['hr'],
                'store'            => ['store'],
                default            => [],
            };
        }

        /* 🔒 سقف حسب الدور — صف صلاحيات غلط **مايرفعش** دور مقيّد.
           على الإنتاج كان فيه حساب محل (`roh`) عنده صف `admin` في
           `user_app_permissions`. `/api/me` كان بيتجاهله بالصدفة (بيستثني
           دور store من الكتلة كلها)، بس بوابة الدخول بتقرا الصفوف مباشرة —
           فمن غير السقف ده كان هيعدّي على `tiar.html` بصلاحية admin.
           `writeUserPerms` مابيتحققش إن التطبيقات متسقة مع الدور، فالسقف
           هنا هو اللي بيمنع الغلطة دي من إنها تبقى ثغرة. */
        $ceiling = match ($role) {
            'store'    => ['store', 'site'],
            'pilot'    => ['site'],
            'customer' => ['customer', 'site'],
            default    => null,   // الأدوار الإدارية بتتحكم بالصفوف الصريحة
        };
        if ($ceiling !== null) {
            $apps = array_values(array_intersect($apps, $ceiling));
        }

        /* التوسعات المشتقّة — نفس منطق `openApp` في home.html */
        $has = fn (string $a): bool => in_array($a, $apps, true);
        if ($has('hr'))       { $apps[] = 'hrOld'; $apps[] = 'perf'; }
        /* 🔑 `accounts` بيشتق الاتنين — المحاسب مابيفقدش حاجة.
           إنما `pilotacct` لوحده **مابيشتقش** `damascus`: ده كل
           الفرق، وهو اللي بيخلّي الأدمن يدّي «تقفيل الطيارين»
           لمشرف فرع من غير ما يفتح له تقفيلة روح دمشق. */
        if ($has('accounts')) { $apps[] = 'damascus'; $apps[] = 'pilotacct'; }
        if ($has('admin') || $has('callcenter')) { $apps[] = 'customer'; }
        if ($has('admin')) {
            array_push($apps, 'customers', 'siteadmin', 'perf', 'storesadmin', 'pilotsadmin');
        }
        $apps[] = 'site';   // الموقع العام للكل

        return array_values(array_unique($apps));
    }

    /**
     * POST /api/login — {username, password, client?, app?}
     * client:"pilot-app" بيرجّع كمان توكن للموبايل (توكن جديد بيلغي القديم).
     *
     * 🔒 `app` (إضافة 2026-08-31): كود التطبيق اللي الصفحة دي بتمثّله
     * (`callcenter` · `branch` · `admin` · `storesadmin` …). لو اتبعت،
     * السيرفر بيرفض الدخول لو المستخدم مش مصرّح له بالتطبيق ده.
     *
     * ═══ ليه ═══
     * كل صفحة تطبيق فيها فورم دخول بينده `/api/login` **وبيقبل أي دور
     * بيصادق بنجاح**. النتيجة اللي اكتشفها صاحب النظام: مشرف فرع بيفتح
     * `callcenter.aldahshan.cloud` ويدخل بحسابه عادي وياخد واجهة الكول
     * سنتر كاملة. و`allowedApps` كانت بتتقرا في `home.html` **بس** —
     * وهي بوابة عرض كروت، والصفحات التانية بتتفتح بالـURL المباشر من
     * غير ما تعدّي عليها.
     *
     * القاعدة: **كل دور مقفول على تطبيقه، وrole=admin بس بيفتح أي حاجة.**
     *
     * ⚠️ الباراميتر **اختياري عن قصد**: تطبيق الطيار (Flutter) المنشور على
     * تليفونات الطيارين بينده نفس المسار من غيره، وكذلك أي نسخة صفحة
     * مكاشة. غيابه = السلوك القديم بالظبط، فمفيش كسر للعقد.
     * الحماية الحقيقية للبيانات بتفضل `role:` على المسارات — ده قفل
     * **على باب التطبيق** فوقها.
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

        /* 🔒 بوابة التطبيق — بعد التحقق من الباسورد وقبل فتح الجلسة.
           بترجّع 403 مش 401 عشان الرسالة تفرّق: البيانات صح، الباب غلط.
           و`registerFailure` **مابتتندهش** هنا — المستخدم مش بيخمّن باسورد،
           وقفله على محاولات دخول صحيحة عقوبة على حاجة مش غلطته.

           🔴 التطبيق المطلوب بيتحدّد من **النطاق أولًا** بعدين من الجسم.
           الاعتماد على الجسم لوحده كان قفل بيتلف حواليه: العميل هو اللي
           بيبعت `app`، فحذفه = مفيش قفل. والنطاق بييجي من ترويسة `Host`
           اللي أباتشي بيوجّه بيها الـvhost أصلًا — العميل مايقدرش يزوّرها
           ويوصل نفس المكان. فـ`callcenter.aldahshan.cloud` بيفرض
           `callcenter` مهما بعت (أو ما بعتش) في الجسم.

           النطاقات المشتركة (`app.` بوابة الموظفين · الجذر للعميل والمحل)
           مش في الخريطة عن قصد — بتخدم أكتر من تطبيق، فالجسم هو اللي
           بيحدّد فيها. */
        $host    = strtolower((string) $request->getHost());
        $byHost  = [
            'callcenter.aldahshan.cloud' => 'callcenter',
            'branch.aldahshan.cloud'     => 'branch',
        ][$host] ?? null;

        $wantApp = $byHost ?? trim((string) $request->input('app', ''));
        if ($wantApp !== '' && $user->role !== 'admin') {
            if (! in_array($wantApp, self::appsFor((int) $user->id, (string) $user->role), true)) {
                throw ApiException::forbidden('حسابك مش مصرّح له بالتطبيق ده — ادخل من التطبيق بتاعك');
            }
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

        /* تطبيق الموبايل — توكن جديد بيلغي القديم (جهاز واحد نشط).
           🔒 للطيارين بس: التوكن ده **مالوش نهاية صلاحية** و`ResolveApiActor`
           بيبني منه actor كامل بدور صاحبه. قبل الشرط ده كان أي حساب —
           أدمن أو مشرف فرع أو محل — يقدر يطلّع لنفسه Bearer دائم بمجرد
           إضافة `client=pilot-app` للدخول، ويستعمله بره الجلسة والكوكيز
           وبره أي قفل تطبيق. */
        if ($request->input('client') === 'pilot-app' && $user->role === 'pilot') {
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

        /* 🔒 الإيميل لازم يكون **متحقّق منه** والدخول من **جوجل** فعلًا.
           التوكن بيتوقّع صح من نفس مشروع Firebase، بس المشروع ده بيفتح
           مسارات دخول تانية لتطبيق العميل (تليفون/إيميل بباسورد). فحد
           يسجّل بإيميل الإدارة على مسار تاني — من غير ما يملكه — وياخد
           توكن صالح شايل نفس الـclaim، ويدخل بيه هنا كإدارة. */
        if (($p['email_verified'] ?? false) !== true) {
            throw ApiException::forbidden('الإيميل ده مش متحقَّق منه');
        }
        if ((string) ($p['firebase']['sign_in_provider'] ?? '') !== 'google.com') {
            throw ApiException::forbidden('الدخول للإدارة بحساب جوجل بس');
        }

        /* 🔒 فرع الـbootstrap (أول إيميل بياخد الإدارة لو الجدول فاضي)
           **اتشال**: كان بيتسلّح من تاني لو آخر صف اتمسح — وساعتها أول
           واحد يوصل للمسار على دومين عام ياخد الإدارة. الجدول عليه صف
           فعلًا من 2026-08، والإضافة بقت من الإدارة نفسها
           (`GET/POST /api/admin-emails`) مش من مسار دخول عام. */
        if (! DB::select('SELECT 1 AS n FROM admin_emails WHERE email = ? LIMIT 1', [$email])) {
            throw ApiException::forbidden('الإيميل ده مش مصرّح له بالدخول كإدارة');
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
                'allowedApps' => ['admin', 'branch', 'store', 'callcenter', 'hr', 'accounts', 'pilotacct'],
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
            /* نفس دالة بوابة الدخول بالظبط — مصدر واحد للحقيقة.
               كانت متكرّرة هنا بمنطق أقصر (من غير التوسعات ومن غير
               افتراضيات branch/callcenter/hr/accounts)، فبوابة الدخول
               وكروت `home.html` كانوا ممكن يختلفوا. */
            $user['allowedApps'] = self::appsFor((int) $actor->userId, (string) $actor->role);

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
