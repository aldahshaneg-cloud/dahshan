<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\ApiException;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         * منع الكتابة الجماعية الصامتة. السكيمة فيها أعمدة فلوس وحالات
         * (custody_balance، wallet_used، money_settled، status...) وأي
         * fill() مفتوح عليها = كارثة صامتة. Eloquent هيرمي استثناء لو
         * حصل fill لعمود مش معرّف صراحةً.
         */
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        /* الفاعل الحالي — المقابل لـ require_auth() القديمة */
        Request::macro('actor', function (): ?Actor {
            /** @var Request $this */
            $actor = $this->attributes->get(ResolveApiActor::ATTRIBUTE);

            return $actor instanceof Actor ? $actor : null;
        });

        /* نفس ده بس بيفشل 401 زي require_auth() بالظبط */
        Request::macro('actorOrFail', function (): Actor {
            /** @var Request $this */
            $actor = $this->actor();

            if ($actor === null) {
                throw ApiException::unauthenticated();
            }

            return $actor;
        });
    }
}
