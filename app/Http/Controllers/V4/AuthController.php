<?php

declare(strict_types=1);

namespace App\Http\Controllers\V4;

use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * شاشة دخول الجيل الرابع.
 *
 * الدخول نفسه **مش هنا**: النموذج بينده `POST /api/login` بتاع النظام (نفس حدّ المحاولات والحظر
 * وتسجيل الحضور) فالجلسة واحدة للقديم والجديد. الكنترولر ده بيعرض الشاشة ويوجّه اللي داخل فعلًا.
 */
class AuthController
{
    public function show(Request $request): View|RedirectResponse
    {
        $actor = $request->attributes->get(ResolveApiActor::ATTRIBUTE);
        if ($actor instanceof Actor && $actor->userId !== null) {
            $home = self::homeFor($actor->role);
            if ($home !== null) {
                return redirect()->to($home);
            }
        }

        return view('v4.auth.login', [
            'next'  => self::safeNext((string) $request->query('next', '')),
            /* داخل فعلًا بس دوره مالوش تطبيق v4 لسه — نقولها بدل ما الشاشة تلفّ على نفسها */
            'noApp' => ($actor instanceof Actor && $actor->userId !== null) ? (\App\Support\Vocab::ROLE_AR[$actor->role] ?? $actor->role) : null,
        ]);
    }

    /** دور → صفحته الرئيسية في v4 (null = مالوش تطبيق v4 لسه). */
    public static function homeFor(string $role): ?string
    {
        return self::homes()[$role] ?? null;
    }

    /** @return array<string,string> */
    private static function homes(): array
    {
        $out = [];
        foreach ((array) config('v4.apps', []) as $key => $app) {
            foreach ((array) ($app['roles'] ?? []) as $role) {
                $out[$role] ??= url("/v4/{$key}");
            }
        }

        return $out;
    }

    /** `next` لازم يكون مسار داخلي تحت /v4 — وإلا بيتجاهل (منع تحويل مفتوح). */
    private static function safeNext(string $next): string
    {
        return preg_match('#^/v4/[A-Za-z0-9/_\-]*$#', $next) === 1 ? $next : '';
    }
}
