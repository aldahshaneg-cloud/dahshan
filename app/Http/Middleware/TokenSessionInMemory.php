<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * جلسة في الذاكرة لطلبات التوكن (2026-09-08).
 *
 * تطبيق الطيار بيصادق بهيدر `X-Auth-Token` مش بكوكي، لكن `StartSession`
 * كان بيفتح جلسة ملفّات جديدة **لكل طلب** ويكتبها على القرص ويبعت
 * Set-Cookie محدش بيستعمله — ٤٧ ألف ملف في اليوم (٣٬٦٢٩ ملف كانوا
 * موجودين وقت المراجعة) ومسح دوري للمجلد كله.
 *
 * لما الطلب بتوكن ومن غير كوكي جلسة: بنحوّل سواقة الجلسة لـ`array`
 * (ذاكرة بس) قبل ما `StartSession` تشتغل — الجلسة تتقرا فاضية زي
 * قبل كده بالظبط (`ResolveApiActor` بيلاقي `user_id` فاضي فبيروح
 * لمسار التوكن)، ومفيش ملف ولا كوكي. الكوكي لو موجود بنسيب كل حاجة
 * زي ما هي — اللوحات بتشتغل بالكوكي ومحتاجة تفضل على الملفات.
 */
class TokenSessionInMemory
{
    public function handle(Request $request, Closure $next): Response
    {
        if (trim((string) $request->header('X-Auth-Token', '')) !== ''
            && ! $request->cookies->has((string) config('session.cookie'))) {
            config(['session.driver' => 'array']);
        }

        return $next($request);
    }
}
