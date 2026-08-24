<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\BroadcastActor;
use App\Http\Middleware\ResolveApiActor;
use App\Http\Middleware\TolerantJsonBody;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
     * البث (Reverb). **مش** `channels:` جوه withRouting ولا
     * `->withBroadcasting()` — تلات أسباب، كل واحد منهم لوحده كافي:
     *
     *  1. `Broadcast::routes()` الافتراضية بتحط /broadcasting/auth على
     *     مجموعة **web**، وفيها `EncryptCookies`. كوكي الجلسة عندنا
     *     (ALDAHSHAN_SESS) **مش مشفّر** — مجموعة `api` مافيهاش تشفير كوكيز
     *     عشان تطابق جلسة PHP الأصلية. يعني المسار الافتراضي كان هيفشل
     *     يقرا الجلسة، وكل اشتراك في قناة خاصة كان هيترفض 403.
     *     فبنحطه على مجموعة `api` نفسها: نفس الجلسة ونفس الفاعل.
     *
     *  2. المسار متسايب **بره بادئة /api** عن قصد. `php artisan
     *     route:coverage` بيقارن كل مسار `api/*` بجدول المسارات الأصلي،
     *     فأي مسار جديد تحت البادئة دي كان هيتحسب «زيادة» ويكسّر بوابة
     *     الـ219/219 — والبوابة دي مش المفروض تتعدّل عشان إضافة عندنا.
     *
     *  3. 🔴 **الأهم**: `withBroadcasting()` بتعمل `require` لملف القنوات
     *     على **كل طلب**، و`Broadcast::channel()` بتعدّي على
     *     `BroadcastManager::__call` اللي بيبني السائق (Pusher + Guzzle)
     *     عشان يسجّل القناة. يعني الـ219 مسار كلهم كانوا هيبنوا عميل
     *     Pusher على كل طلب، **وأي مفتاح Reverb ناقص أو غلط كان بيرمي 500
     *     على كل مسار في النظام حتى /api/health** (اتشاف فعليًا ساعة
     *     التثبيت قبل ما المفاتيح تتحط في .env). تحميل القنوات اتأجّل
     *     لـ`BroadcastActor` — يعني الارتباط بـReverb محصور في مسار
     *     /broadcasting/auth وحده. `Broadcast::routes()` نفسها رخيصة:
     *     دالة معرّفة على المدير ومابتلمسش السائق.
     */
    ->booted(function (Application $app): void {
        Broadcast::routes(['middleware' => ['api', BroadcastActor::class]]);

        /* في الكونسول بس بنحمّل القنوات بدري، عشان `php artisan channel:list`
           يقدر يعرضها. الطلبات العادية مابتعدّيش من هنا. */
        if ($app->runningInConsole()) {
            require __DIR__.'/../routes/channels.php';
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * مجموعة الـapi عمدًا **مش** مجموعة web:
         *  • مفيش CSRF — النظام الحالي مافيهوش توكن CSRF خالص، والواجهات
         *    وتطبيق الطيار بينادوا مباشرةً. إضافته دلوقتي = كسر كل العملاء.
         *  • مفيش تشفير كوكيز — الجلسة بتتقرا بالكوكي زي ما هي.
         * لكن الجلسة **مطلوبة**: المصادقة الأساسية في النظام كوكي جلسة
         * (ALDAHSHAN_SESS)، مش توكن. فبنضيف StartSession يدويًا.
         */
        $middleware->group('api', [
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            TolerantJsonBody::class,
            ResolveApiActor::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);

        /*
         * 🔴 لازم يتشالوا — الاتنين دول بيشتغلوا **عامًّا** في لارافل 11+
         * على كل طلب، وبيغيّروا المدخلات قبل ما الكنترولر يشوفها:
         *
         *  • ConvertEmptyStringsToNull: بيحوّل "" إلى null. النظام القديم
         *    بيفرّق بين الاتنين في أماكن كتير — النمط `$_GET['x'] ?? 'افتراضي'`
         *    بيرجّع "" لو الباراميتر موجود وفاضي (ويترفض)، ويرجّع الافتراضي
         *    لو مش موجود خالص. اتكشف على GET /api/wallets?ownerType= :
         *    الأصل بيرد «نوع المالك غير صالح» ولارافل كان بيرجّع القايمة.
         *
         *  • TrimStrings: بيقص المسافات من كل مدخل. الأصل بيعمل trim()
         *    صراحةً في الأماكن اللي عايزها بس — والباقي بيتخزّن زي ما جه.
         *
         * الاتنين «تحسينات» في مشروع جديد، لكن هنا أي فرق = تغيير عقد.
         */
        $middleware->remove([
            \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
            \Illuminate\Foundation\Http\Middleware\TrimStrings::class,
        ]);

        // بيمنع لارافل من تحويل 401 لصفحة تسجيل دخول
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * المقابل الحرفي لكتلة الـtry/catch في public/index.php:
         *
         *     catch (PDOException $e) { fail('خطأ في قاعدة البيانات', 500); }
         *     catch (Throwable  $e)   { fail('حدث خطأ غير متوقع', 500); }
         *     ... ولو مفيش مسار: fail('المسار غير موجود', 404);
         *
         * الرسايل التلاتة دي جزء من العقد — الاختبارات بتتحقق منها نصًا.
         */
        /*
         * 🔴 الاستثناءات المتوقعة **مش** أعطال — دي مسار تحكّم عادي.
         *
         * من غير الكتلة دي كل طلب غير مصرّح كان بيكتب ~20 كيلوبايت مكدس في
         * `laravel.log`: أي ماسح بورتات بيملّي القرص ويدفن الأعطال الحقيقية
         * تحت آلاف الأسطر. اتكشفت على أول طلب حقيقي بعد النشر (2026-08-19).
         *
         * الحد عند 500 **مقصود ومحسوب**: 46 موضع في الكود بيرمي ApiException
         * بحالة 500 وواحد بـ503، وكلهم أعطال فعلية في عمليات بتمسّ فلوس
         * (حفظ أوردر · محفظة · تقفيلة شهرية · تسوية عودة طيار). دول لازم
         * يفضلوا في السجل. الكتم للـ4xx/409 بس.
         *
         * الرد نفسه ما اتغيّرش — `render()` تحت هو اللي بيبنيه، ودي بتأثّر
         * على التسجيل بس.
         */
        $exceptions->dontReportWhen(fn (Throwable $e): bool => (
            ($e instanceof ApiException && $e->status() < 500)
            || $e instanceof AuthenticationException
            || $e instanceof NotFoundHttpException
        ));
        $exceptions->render(function (Throwable $e, Request $request) {
            /* مسار تفويض البث (/broadcasting/auth) سايب بره بادئة /api عشان
               بوابة route:coverage، بس بيرد JSON زي باقي الـAPI — فبنضمّه
               هنا صراحةً. إضافة بحتة: المسار ده مكانش موجود أصلًا، فمفيش
               أي سلوك قايم بيتغيّر. */
            if (! $request->is('api/*') && ! $request->is('broadcasting/*')) {
                return null;   // مسارات الويب تفضل بسلوك لارافل الافتراضي
            }

            if ($e instanceof ApiException) {
                return $e->render();
            }

            if ($e instanceof AuthenticationException) {
                return ApiResponse::fail('يجب تسجيل الدخول أولًا', 401);
            }

            if ($e instanceof NotFoundHttpException) {
                return ApiResponse::fail('المسار غير موجود', 404);
            }

            if ($e instanceof QueryException) {
                report($e);
                return ApiResponse::fail('خطأ في قاعدة البيانات', 500);
            }

            /* رفض الاشتراك في قناة بث. `PusherBroadcaster` بيرمي
               AccessDenied **برسالة فاضية**، والفرع العام تحت كان بيرجّع
               «حدث خطأ غير متوقع» على رفض صلاحية سليم — رسالة بتضيّع وقت
               اللي بيصلّح. مقيّد بـbroadcasting/* عشان يفضل صفر أثر على
               الـ219 مسار (ولا واحد فيهم بيرمي الاستثناء ده أصلًا —
               كلهم `ApiException::forbidden()`). */
            if ($e instanceof AccessDeniedHttpException && $request->is('broadcasting/*')) {
                return ApiResponse::fail('غير مسموح لك بالاشتراك في هذه القناة', 403);
            }

            if ($e instanceof HttpExceptionInterface) {
                $code = $e->getStatusCode();
                $msg  = $e->getMessage() !== '' ? $e->getMessage() : 'حدث خطأ غير متوقع';
                return ApiResponse::fail($msg, $code);
            }

            report($e);
            return ApiResponse::fail('حدث خطأ غير متوقع', 500);
        });
    })->create();
