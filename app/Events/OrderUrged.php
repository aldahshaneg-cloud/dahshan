<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * ⏰ «الأوردر جاهز — برجاء الاستعجال» من بوابة المحل.
 *
 * ═══ لمين بيروح ═══
 * الأوردر لسه في المكتب (مالوش طيار) ⇒ الفرع بس.
 * متحمّل على طيار ⇒ **الطيار والفرع مع بعض** — الفرع لازم يشوف إن
 * المحل بيستعجل حتى لو الطيار في الطريق، لأنه هو اللي بيتصرّف لو
 * الطيار متأخر أو مش راد.
 *
 * ═══ ShouldDispatchAfterCommit ═══
 * نفس سبب `OrderChanged`: الاستعجال بيتسجّل جوه معاملة، ومن غير السطر
 * ده الحدث بيوصل للمشتركين قبل ما الصف يتثبّت — فاللوحة بتنده السيرفر
 * وتلاقي عدد استعجالات أقل من اللي التنبيه بيقول عليه.
 */
final class OrderUrged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  int      $orderId   الأوردر
     * @param  string   $orderNum  رقم الأوردر — التنبيه بيعرضه من غير نداء تاني
     * @param  int      $branchId  الفرع المسؤول
     * @param  int|null $pilotId   الطيار لو الأوردر متحمّل عليه
     * @param  string   $storeName اسم المحل اللي استعجل
     * @param  int      $times     الاستعجال رقم كام على نفس الأوردر
     * @param  string   $note      ملاحظة المحل (ممكن تكون فاضية)
     * @param  int      $urgeId    رقم صف الاستعجال — اللوحة بتستخدمه مفتاح
     *                             تكرار: نفس الصف ممكن يوصلها بالبثّ
     *                             وبالاستطلاع مع بعض، والمفتاح ده بيخلّي
     *                             الاتنين يكتبوا نفس البند مش بندين.
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNum,
        public readonly int $branchId,
        public readonly ?int $pilotId,
        public readonly string $storeName,
        public readonly int $times,
        public readonly string $note = '',
        public readonly int $urgeId = 0,
    ) {
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('branch.' . $this->branchId)];
        if ($this->pilotId !== null && $this->pilotId > 0) {
            $channels[] = new PrivateChannel('pilot.' . $this->pilotId);
        }

        return $channels;
    }

    /** اسم ثابت على السلك — الواجهات والتطبيق بيسمعوا الاسم ده بالحرف */
    public function broadcastAs(): string
    {
        return 'order.urged';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'urgeId'    => $this->urgeId,
            'orderId'   => $this->orderId,
            'orderNum'  => $this->orderNum,
            'branchId'  => $this->branchId,
            'pilotId'   => $this->pilotId,
            'storeName' => $this->storeName,
            'times'     => $this->times,
            'note'      => $this->note,
            // للطيار: الرسالة جاهزة عشان يعرضها ستارة من غير تركيب
            'title'     => 'استعجال من المحل',
            'body'      => 'المحل ' . $this->storeName . ' بيستعجل الطلب ' . $this->orderNum,
        ];
    }
}
