<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * «طلب طيار (إذن/وردية) اتغيّر» — الحدث التاني في طبقة التحديث الفوري.
 *
 * السبب المباشر: طلب الإذن كان بياخد **دقيقة كاملة** يظهر في اللوحة
 * (مستطلع leave-requests عندها كل ٦٠ث)، وقرار الموافقة كان بياخد نفس
 * الدقيقة يرجع للطيار (مزامنة الحالة في التطبيق كل ٦٠ث). الحدث ده
 * بيقفل الاتجاهين: الإنشاء بينبّه اللوحات، والقرار بينبّه التطبيق.
 *
 * ═══ نفس قواعد `OrderChanged` بالحرف ═══
 *  • إشعار مش بيانات: المستمع بيقرر يجيب النسخة الكاملة من الـAPI.
 *  • `ShouldDispatchAfterCommit`: مسارات الطلبات جوه `DB::transaction()`.
 *  • قناة الفرع دايمًا + قناة الطيار لو معروف — نفس تفويض
 *    `routes/channels.php` الموجود، مفيش قنوات جديدة.
 *  • بيتبعت من `NotifiesPilotRequests::broadcastPilotRequest()` اللي بيمسك
 *    أي استثناء — البثّ عمره ما يوقّع الطلب نفسه.
 */
final class PilotRequestChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  string    $kind      نوع الطلب: leave (إذن) أو shift (وردية)
     * @param  int       $branchId  فرع الطيار — قناة اللوحات
     * @param  int|null  $pilotId   الطيار — قناته لو القرار يخصّه
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $branchId,
        public readonly ?int $pilotId,
    ) {
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('branch.' . $this->branchId)];
        // pilotId صفر/سالب بيبني قناة بيترفض تفويضها — حدث بلا مشتركين، مش تسريب
        if ($this->pilotId !== null) {
            $channels[] = new PrivateChannel('pilot.' . $this->pilotId);
        }

        return $channels;
    }

    /** اسم ثابت على السلك — نفس سبب `OrderChanged::broadcastAs()`. */
    public function broadcastAs(): string
    {
        return 'pilot.request.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'kind'     => $this->kind,
            'branchId' => $this->branchId,
            'pilotId'  => $this->pilotId,
        ];
    }
}
