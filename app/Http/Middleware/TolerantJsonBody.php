<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * المقابل لـ body_json() في النظام الحالي:
 *
 *     function body_json(): array {
 *         $raw = file_get_contents('php://input');
 *         if ($raw === '' || $raw === false) return [];
 *         $data = json_decode($raw, true);
 *         return is_array($data) ? $data : [];
 *     }
 *
 * يعني **جسم فاضي أو JSON مكسور = مصفوفة فاضية، مش خطأ 400**. لارافل
 * الافتراضي بيرمي 400 على JSON مكسور، وده تغيير سلوك: مسارات كتير في
 * النظام بتتنادى من غير جسم خالص (زي POST /api/logout و
 * POST /api/orders/{id}/cancel) والواجهات بتعتمد على ده.
 */
class TolerantJsonBody
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return $next($request);
        }

        $raw = $request->getContent();

        if ($raw === '' || $raw === false) {
            $request->setJson(new \Symfony\Component\HttpFoundation\ParameterBag([]));
            return $next($request);
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            // مكسور أو قيمة مش كائن (رقم/نص) — نفس سلوك القديم: []
            $request->setJson(new \Symfony\Component\HttpFoundation\ParameterBag([]));
        }

        return $next($request);
    }
}
