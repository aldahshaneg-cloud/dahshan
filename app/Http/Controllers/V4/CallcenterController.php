<?php

declare(strict_types=1);

namespace App\Http\Controllers\V4;

use App\Http\Middleware\ResolveApiActor;
use App\Services\V4\CallcenterStats;
use App\Services\V4\ContactSearch;
use App\Services\V4\OrderListQuery;
use Illuminate\Support\Facades\DB;
use App\Support\Actor;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * شاشات الكول سنتر — الجيل الرابع (Blade).
 *
 * القاعدة: الكنترولر ده **بيعرض شاشات وبس**. كل كتابة (أوردر جديد، إلغاء، تعديل…) بتعدّي على
 * نفس مسارات `/api/*` بقواعدها وحرّاسها — فمنطق الشغل يفضل في مكان واحد والتطبيق القديم والجديد
 * مايختلفوش في حاجة. اللي اتنقل للسيرفر هنا هو **القراءات التقيلة**: أرقام الرئيسية بقت
 * استعلامات تجميع (CallcenterStats) بدل سحب ألف أوردر للمتصفح.
 */
class CallcenterController
{
    private const APP = 'callcenter';

    public function home(Request $request): View
    {
        return $this->page($request, 'home', 'v4.callcenter.home', [
            'stats'    => CallcenterStats::summary(),
            'branches' => CallcenterStats::branches(),
        ]);
    }

    /** JSON حي للرئيسية والشارات — نفس مصدر الشاشة. */
    public function stats(): JsonResponse
    {
        return ApiResponse::ok([
            'stats'    => CallcenterStats::summary(),
            'branches' => CallcenterStats::branches(),
            'nav'      => CallcenterStats::navCounts(),
        ]);
    }

    /** «طلب جديد» = صفحة الطلبات القديمة نفسها ومودال الأوردر مفتوح (قرار صاحب النظام: مايتغيّرش). */
    public function newOrder(Request $request): View
    {
        return $this->embedded($request, 'new');
    }

    /** أي صفحة `embed` في config/v4.php من غير مسار خاص بيها (الخريطة، رسايل العملاء، الشكاوى، أدائي). */
    public function legacyPage(Request $request, string $page): View
    {
        $def = config('v4.apps.' . self::APP . ".pages.{$page}");
        if (! is_array($def) || empty($def['embed']) || empty($def['ready'])) {
            throw new NotFoundHttpException();
        }

        return $this->embedded($request, $page);
    }

    /**
     * الشاشة بكود التطبيق القديم نفسه جوه غلاف v4 (callcenter.html?embed=…): صفر تغيير في الشكل
     * والسلوك — وده المطلوب للشاشات اللي صاحب النظام قال «لا أريد تغييرها». الغلاف بيظبط كاش
     * الجلسة المحلي (`tiar-session`) اللي القديم بيستأنف منه، فالموظف مايشوفش شاشة دخول تانية.
     */
    private function embedded(Request $request, string $key): View
    {
        $def = (array) config('v4.apps.' . self::APP . ".pages.{$key}");

        return $this->page($request, $key, 'v4.callcenter.embed', [
            'embedPage' => (string) $def['embed'],
            'embedNew'  => ! empty($def['embedNew']),
        ]);
    }

    public function search(Request $request): View
    {
        /* البحث السريع = نفس شاشة القوايم على «كل الحالات» — مصدر واحد للفلترة والترقيم */
        return $this->page($request, 'search', 'v4.callcenter.orders', [
            'list'    => 'all',
            'listDef' => ['title' => 'بحث سريع في كل الطلبات', 'statuses' => []],
        ]);
    }

    public function orders(Request $request, string $list): View
    {
        $def = config("v4.order_lists.{$list}");
        if (! is_array($def)) {
            throw new NotFoundHttpException();
        }

        /* القايمة اللي عليها `embed` (النشطة) بتتعرض بشاشة القديم نفسها */
        if (! empty(config('v4.apps.' . self::APP . ".pages.{$list}.embed"))) {
            return $this->embedded($request, $list);
        }

        return $this->page($request, $list, 'v4.callcenter.orders', [
            'list'     => $list,
            'listDef'  => $def,
        ]);
    }

    /**
     * بيانات قايمة أوردرات — صفحة واحدة (30) + الإجمالي الحقيقي، بالفلترة والبحث في السيرفر.
     * `list` من config/v4.php (أو `all` للبحث السريع في كل الحالات).
     */
    public function ordersData(Request $request): JsonResponse
    {
        $list = (string) $request->query('list', 'active');
        $def  = $list === 'all' ? ['statuses' => []] : config("v4.order_lists.{$list}");
        if (! is_array($def)) {
            throw new NotFoundHttpException();
        }

        return ApiResponse::ok(OrderListQuery::run($def['statuses'], $request->query()));
    }

    /** بحث دفتر العملاء في السيرفر — `type=senders|receivers` و`q` (حرفين على الأقل). */
    public function contacts(Request $request): JsonResponse
    {
        return ApiResponse::ok(['items' => ContactSearch::run(
            (string) $request->query('type', 'senders'),
            (string) $request->query('q', '')
        )]);
    }

    /**
     * بيانات فورم الأوردر: الفروع + المناطق **مخفّفة** (5 حقول بدل السلك الكامل ~178 كيلو).
     * `home` = صف «البيت» للمنطقة (عند الفرع المالك) — منطقة الاستلام بتتعرض منه بس، وإلا الاسم
     * بيتكرر مرة لكل فرع بيوصّل والأوردر يروح لفرع غلط (نفس قاعدة القديم).
     */
    public function formData(): JsonResponse
    {
        $branches = array_map(static fn ($b): array => [
            'id' => (int) $b->id, 'name' => (string) $b->name, 'paused' => (int) ($b->paused ?? 0) === 1,
        ], DB::select('SELECT id, name, paused FROM branches ORDER BY id'));

        $zones = array_map(static fn ($z): array => [
            'id'       => (int) $z->id,
            'name'     => (string) $z->area_name,
            'price'    => (float) $z->price,
            'branchId' => (int) $z->delivery_branch_id,
            'home'     => $z->source_branch_id === null || (int) $z->source_branch_id === (int) $z->delivery_branch_id,
        ], DB::select('SELECT id, area_name, price, delivery_branch_id, source_branch_id FROM zones ORDER BY area_name'));

        return ApiResponse::ok(['branches' => $branches, 'zones' => $zones]);
    }

    public function pilots(Request $request): View
    {
        return $this->page($request, 'pilots', 'v4.callcenter.pilots');
    }

    public function zones(Request $request): View
    {
        return $this->page($request, 'zones', 'v4.callcenter.zones');
    }

    public function clients(Request $request): View
    {
        return $this->page($request, 'clients', 'v4.callcenter.clients');
    }

    /** صفحة لسه ماتنقلتش — بتقول كده بوضوح وبتفتح مكانها في التطبيق القديم. */
    public function soon(Request $request, string $page): View
    {
        $def = config('v4.apps.' . self::APP . ".pages.{$page}");
        if (! is_array($def) || ! empty($def['ready'])) {
            throw new NotFoundHttpException();
        }

        return $this->page($request, $page, 'v4.callcenter.soon', ['pageDef' => $def]);
    }

    /** @param array<string,mixed> $data */
    private function page(Request $request, string $key, string $view, array $data = []): View
    {
        /** @var Actor $actor */
        $actor = $request->attributes->get(ResolveApiActor::ATTRIBUTE);
        $app   = (array) config('v4.apps.' . self::APP);

        return view($view, $data + [
            'v4App'    => self::APP,
            'v4Def'    => $app,
            'pageKey'  => $key,
            'pageDef'  => $app['pages'][$key] ?? ['label' => ''],
            'actor'    => $actor,
            'navCounts' => CallcenterStats::navCounts(),
        ]);
    }
}
