<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\Actor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * جسر بين مصادقة النظام وطبقة البث — بيوصّل الفاعل اللي `ResolveApiActor`
 * حلّه لـ`$request->user()`.
 *
 * ليه لازم: `Broadcast::auth()` بتجيب المشترك بـ`$request->user()` — يعني
 * حارس Auth بتاع لارافل. النظام ده **مالوش حارس أصلًا**: المصادقة كوكي
 * جلسة (ALDAHSHAN_SESS) أو هيدر X-Auth-Token، والناتج بيتحط ككائن `Actor`
 * في `Request::attributes` تحت المفتاح `api_actor`. من غير الجسر ده
 * `$request->user()` بترجّع null، و`PusherBroadcaster::auth()` بترمي 403
 * **قبل** ما دالة التفويض في routes/channels.php تتنادى خالص — يعني كل
 * اشتراك في أي قناة خاصة بيترفض من غير ما نعرف ليه.
 *
 * 🔒 الوسيط ده متحطّ على مسار /broadcasting/auth **بس**، مش على مجموعة
 * `api` كلها. السبب: تغيير محلّل المستخدم عامًّا بيغيّر معنى
 * `$request->user()` على الـ219 مسار القايمين، والترحيل ده صفر تغيير سلوك.
 */
class BroadcastActor
{
    public function handle(Request $request, Closure $next): Response
    {
        /* بندعم القنوات الخاصة (`private-`) بس، والباقي بيترفض هنا بدري.
           السبب مش تشدد: القناة العامة مش محتاجة تفويض من أساسه، وقناة
           الحضور (`presence-`) بيحاول لارافل يطلع منها معرّف المستخدم بـ
           `getAuthIdentifier()` — و`Actor` مش موديل Auth فمعندهوش الدالة
           دي. من غير الرفض ده الطلب كان بيطلّع **500** بدل 403 نضيف
           (اتقاس فعليًا على `channel_name=branch.9`). */
        $channel = (string) $request->input('channel_name', '');
        if ($channel !== '' && ! str_starts_with($channel, 'private-')) {
            throw ApiException::forbidden();
        }

        $actor = $request->attributes->get(ResolveApiActor::ATTRIBUTE);

        /* بلا دخول = **401** مش 403 — نفس قاعدة `EnsureRole` بالحرف
           (`require_auth()` بتضرب قبل `require_role()`). لو سيبناها للارافل
           كان `PusherBroadcaster` بيرمي AccessDenied 403 برسالة فاضية،
           فالواجهة مكانتش هتفرّق بين «جلستك خلصت» و«مش من حقك القناة دي». */
        if (! $actor instanceof Actor) {
            throw ApiException::unauthenticated();
        }

        /* تعريفات القنوات بتتحمّل **هنا** مش في الإقلاع، وده مقصود:
           `Broadcast::channel()` بتعدّي على `BroadcastManager::__call` اللي
           **بيبني السائق** (Pusher + Guzzle) عشان يسجّل القناة. لو الملف
           اتحمّل في bootstrap/app.php زي ما `install:broadcasting` بيعمل،
           كل الـ219 مسار كانوا هيبنوا عميل Pusher على كل طلب — وأي مفتاح
           Reverb ناقص كان بيرمي 500 على **كل** مسار في النظام حتى
           /api/health. كده الارتباط بـReverb محصور في المسار ده وحده.

           وبيتحمّل بعد الفحوص اللي فوق مش قبلها، عشان الطلب المرفوض
           (قناة غلط أو بلا دخول) مايبنيش السائق من أساسه. */
        require base_path('routes/channels.php');

        /* الـ$guard اللي لارافل بيبعته بيتجاهل عن قصد: عندنا فاعل واحد
           مصدره ترتيب المصادقة التلاتي في ResolveApiActor، مش حراس متعددة. */
        $request->setUserResolver(static fn () => $actor);

        return $next($request);
    }
}
