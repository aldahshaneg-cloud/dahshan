<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\Actor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * المقابل لـ require_auth() في api/helpers.php — بنفس **ترتيب الأولوية
 * التلاتي** بالظبط. الترتيب ده مش تفصيلة: جلسة العميل بتتفحص الأول عشان
 * المسارات المشتركة (زي إنشاء أوردر وخصم المحفظة) تعرف تتعامل مع دور
 * customer بدل ما ترفضه.
 *
 *   0) جلسة عميل التطبيق  — $_SESSION['role']==='customer' + customer_id
 *   1) جلسة موظف بالكوكي   — $_SESSION['user_id']
 *   2) توكن الموبايل       — هيدر X-Auth-Token (تطبيق الطيار)
 *
 * فرق مهم عن الأصل: الدالة القديمة كانت **بتفشل 401** لو مفيش حد. هنا
 * بنحلّ الفاعل وبس ومنفشلش — لأن فيه مسارات عامة بالفعل (/api/health،
 * /api/egypt، /api/settings/site، /api/track/{num}، /api/public/*،
 * GET /api/partners، /api/login). فرض الدخول بيتم على مستوى المسار
 * بـ middleware 'role' أو بـ Request::actorOrFail() في الكنترولر.
 */
class ResolveApiActor
{
    public const ATTRIBUTE = 'api_actor';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, $this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): ?Actor
    {
        $session = $request->session();

        // ── 0) جلسة عميل التطبيق ──────────────────────────────────
        if ($session->get('role') === 'customer' && $session->get('customer_id')) {
            $cid = (int) $session->get('customer_id');

            /* الحظر لازم يقطع الجلسة المفتوحة فورًا — بنراجع القاعدة كل
               طلب مش بنثق في السيشن (باج اتصلح في النظام القديم 2026-08-15)

               🔴 **استثناء مسارات تطبيق العميل** (`/api/customer/*`):
               مسارات `customer_app.php` في الأصل **مابتندهش
               `require_auth()` خالص** — بتنده `customer_require()` اللي
               ليها سلوك مختلف تمامًا للمحظور:
                 • مسارات القراءة (`customer_require(true)`) بتسيبه يعدّي
                   عشان الواجهة تعرض شاشة «الحساب موقوف» بدل ما الجلسة
                   تتقفل من تحته وهو مش فاهم حصل إيه.
                 • مسارات الكتابة بترفضه برسالة **تانية**:
                   «حسابك موقوف مؤقتًا — كلّم خدمة العملاء».
               فقتل الجلسة هنا كان هيرجّع «هذا الحساب موقوف — تواصل مع
               الإدارة» 403 على كل الـ17 مسار = تغيير سلوك على 17 مسار.
               الفاعل بيتحلّ عادي، والفحص بيتم في `CustomerAppController`. */
            if (! $request->is('api/customer/*') && $this->isBlocked('customers', $cid)) {
                $session->flush();
                $session->invalidate();
                throw ApiException::blocked();
            }

            return Actor::customer(
                customerId: $cid,
                username: (string) $session->get('username', ''),
                name: (string) $session->get('name', ''),
            );
        }

        // ── 1) جلسة موظف بالكوكي ──────────────────────────────────
        if ($session->get('user_id')) {
            $uid = (int) $session->get('user_id');

            if ($this->isBlocked('users', $uid)) {
                $session->flush();
                $session->invalidate();
                throw ApiException::blocked();
            }

            return Actor::staff(
                userId: $uid,
                username: (string) $session->get('username', ''),
                role: (string) $session->get('role', ''),
                branchId: $session->get('branch_id') !== null ? (int) $session->get('branch_id') : null,
                name: (string) $session->get('name', ''),
            );
        }

        // ── 2) توكن الموبايل (تطبيق الطيار) ───────────────────────
        $token = trim((string) $request->header('X-Auth-Token', ''));
        if ($token === '' || strlen($token) > 64) {
            return null;
        }

        $row = DB::table('users')->where('api_token', $token)->first();
        if (! $row || (int) $row->blocked === 1) {
            return null;
        }

        return Actor::staff(
            userId: (int) $row->id,
            username: (string) $row->username,
            role: (string) $row->role,
            branchId: $row->branch_id !== null ? (int) $row->branch_id : null,
            name: (string) ($row->name ?? ''),
        );
    }

    /**
     * المقابل لـ auth_session_blocked().
     * القاعدة المهمة: **الصف اللي اتحذف = محظور** — الجلسة ما تكملش.
     * الكاش لدورة الطلب الواحد بس، زي static $cache في الأصل.
     */
    private function isBlocked(string $table, int $id): bool
    {
        static $cache = [];
        $key = $table . ':' . $id;

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $row = DB::table($table)->select('blocked')->where('id', $id)->first();

        return $cache[$key] = ($row === null) || ((int) $row->blocked === 1);
    }
}
