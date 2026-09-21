<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Actor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * بوابة شاشات الجيل الرابع (v4): `v4.auth:<app>`.
 *
 * الهوية هي **نفس جلسة النظام** بالظبط (كوكي ALDAHSHAN_SESS اللي `POST /api/login` بيحطّه،
 * و`ResolveApiActor` بيقراه قبلنا في مجموعة `v4`) — فالموظف اللي داخل على القديم داخل على
 * الجديد، والعكس. مفيش نظام دخول تاني ولا جدول صلاحيات تاني.
 *
 * - مفيش جلسة → تحويل لشاشة الدخول ومعاها الصفحة المطلوبة (`next`).
 * - دور مش من أدوار التطبيق (config/v4.php) → 403 بصفحة واضحة، مش تحويل (وإلا المشرف يلف في حلقة دخول).
 */
class V4Auth
{
    public function handle(Request $request, Closure $next, string $app = 'callcenter'): Response
    {
        $actor = $request->attributes->get(ResolveApiActor::ATTRIBUTE);
        if (! $actor instanceof Actor || $actor->userId === null) {
            return redirect()->to(url('/v4/login') . '?next=' . urlencode('/' . ltrim($request->path(), '/')));
        }

        $roles = (array) config("v4.apps.{$app}.roles", []);
        if (! in_array($actor->role, $roles, true)) {
            return response()->view('v4.errors.forbidden', [
                'app'   => config("v4.apps.{$app}.label", $app),
                'actor' => $actor,
            ], 403);
        }

        $request->attributes->set('v4_app', $app);

        return $next($request);
    }
}
