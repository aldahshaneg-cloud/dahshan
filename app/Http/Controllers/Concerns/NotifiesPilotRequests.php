<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Events\PilotRequestChanged;
use Throwable;

/**
 * بثّ «طلب طيار اتغيّر» — نفس شكل ونفس سبب `BroadcastsOrders` بالحرف:
 * كنترولرات الـAPI مالهاش أب مشترك، والحماية لازم تبقى عند النداء عشان
 * البثّ عمره ما يوقّع إنشاء الطلب أو قراره.
 */
trait NotifiesPilotRequests
{
    /**
     * @param  string    $kind      leave أو shift
     * @param  int       $branchId  فرع الطيار
     * @param  int|null  $pilotId   الطيار المعني (null لو مش معروف)
     */
    protected function broadcastPilotRequest(string $kind, int $branchId, ?int $pilotId): void
    {
        if ($branchId <= 0) {
            return;
        }
        try {
            PilotRequestChanged::dispatch($kind, $branchId, $pilotId);
        } catch (Throwable $e) {
            // البثّ مابيكسرش الطلب أبدًا — لوج وبس (نفس قاعدة broadcastOrder)
            report($e);
        }
    }
}
