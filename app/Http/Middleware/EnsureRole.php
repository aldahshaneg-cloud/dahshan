<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\Actor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * المقابل لـ require_role() في api/helpers.php:
 *
 *     function require_role(string ...$roles): array {
 *         $user = require_auth();
 *         if (!in_array($user['role'], $roles, true)) {
 *             fail('غير مسموح لك بهذه العملية', 403);
 *         }
 *         return $user;
 *     }
 *
 * ملاحظتان لازم يفضلوا زي ما هما:
 *  • المقارنة **صارمة** (in_array بـ true) وعلى **الكود الإنجليزي** مش
 *    الاسم العربي. الأدوار في الجلسة كودات: admin/branch/callcenter/
 *    pilot/store/customer/accountant/hr/pilot_supervisor.
 *  • من غير دخول أصلًا الرد **401** مش 403 — require_auth() بتضرب الأول.
 *
 * الاستخدام: ->middleware('role:admin,branch,callcenter')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $actor = $request->attributes->get(ResolveApiActor::ATTRIBUTE);

        if (! $actor instanceof Actor) {
            throw ApiException::unauthenticated();
        }

        if ($roles !== [] && ! $actor->hasRole(...$roles)) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
