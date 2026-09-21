<?php

declare(strict_types=1);

namespace App\Services\V4;

use App\Http\Controllers\Api\OrdersController;
use Illuminate\Support\Facades\DB;

/**
 * بحث دفتر العملاء (عملاء الاستلام `senders` / عملاء التسليم `receivers`) — **في السيرفر**.
 *
 * القديم كان بيحمّل الدفترين كاملين (لحد 5000 صف لكل واحد) للمتصفح ويفلتر هناك. هنا الموظف بيكتب
 * حرفين والسيرفر بيرجّع أحسن 12 نتيجة:
 *   • رقم (أو معظمه أرقام) → بحث بالتليفون بعد تنظيفه (`cleanPhone`) على phone1/phone2 — بادئة
 *     الأول عشان الفهرس، وبعدين «يحتوي».
 *   • نص → كل كلمة لازم تكون في الاسم (أي ترتيب).
 * وبيرجّع آخر عنوان ومنطقة اتبعتلهم للمستلم من أوردراته السابقة — فالعنوان يتملى لوحده.
 */
final class ContactSearch
{
    public const LIMIT = 12;

    /** @return list<array<string,mixed>> */
    public static function run(string $type, string $q): array
    {
        $table = $type === 'receivers' ? 'receivers' : 'senders';
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $digits = OrdersController::cleanPhone($q);
        $isPhone = $digits !== '' && mb_strlen(preg_replace('/\D/u', '', $q) ?? '') >= max(3, (int) floor(mb_strlen($q) * 0.6));

        if ($isPhone) {
            $d = ltrim($digits, '+');
            $rows = DB::select(
                "SELECT id, name, phone1, phone2, address FROM {$table}
                  WHERE phone1 LIKE ? OR phone2 LIKE ? OR phone1 LIKE ? OR phone2 LIKE ?
                  ORDER BY (phone1 LIKE ?) DESC, updated_at DESC LIMIT " . self::LIMIT,
                [$d . '%', $d . '%', '%' . $d . '%', '%' . $d . '%', $d . '%']
            );
        } else {
            $words = array_slice(array_values(array_filter(preg_split('/\s+/u', $q) ?: [])), 0, 4);
            $where = [];
            $args  = [];
            foreach ($words as $w) {
                $where[] = 'name LIKE ?';
                $args[]  = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $w) . '%';
            }
            $args[] = $words[0] . '%';
            $rows = DB::select(
                "SELECT id, name, phone1, phone2, address FROM {$table}
                  WHERE " . implode(' AND ', $where) . '
                  ORDER BY (name LIKE ?) DESC, updated_at DESC LIMIT ' . self::LIMIT,
                $args
            );
        }

        $out = [];
        foreach ($rows as $r) {
            $item = [
                'id'      => (int) $r->id,
                'name'    => (string) $r->name,
                'phone1'  => (string) ($r->phone1 ?? ''),
                'phone2'  => (string) ($r->phone2 ?? ''),
                'address' => (string) ($r->address ?? ''),
            ];
            if ($table === 'receivers') {
                $item += self::lastDelivery((int) $r->id, (string) ($r->phone1 ?? ''));
            } else {
                $item += self::lastPickup((int) $r->id);
            }
            $out[] = $item;
        }

        return $out;
    }

    /** آخر منطقة وعنوان اتسلّم فيهم للمستلم ده — بيتعبّوا لوحدهم في الطرد. @return array<string,mixed> */
    private static function lastDelivery(int $receiverId, string $phone): array
    {
        $d = DB::selectOne(
            'SELECT d.zone_id, d.zone_name, d.address FROM order_deliveries d
              WHERE d.receiver_id = ? OR (? <> \'\' AND d.receiver_phone = ?)
              ORDER BY d.id DESC LIMIT 1',
            [$receiverId, $phone, $phone]
        );

        return $d ? ['lastZoneId' => $d->zone_id !== null ? (int) $d->zone_id : null, 'lastZoneName' => (string) ($d->zone_name ?? ''), 'lastAddress' => (string) ($d->address ?? '')] : [];
    }

    /** آخر منطقة استلام للمرسل ده — منها بيتحدّد الفرع من غير ما الموظف يختار تاني. @return array<string,mixed> */
    private static function lastPickup(int $senderId): array
    {
        $o = DB::selectOne(
            'SELECT o.sender_zone_id FROM orders o WHERE o.sender_id = ? AND o.sender_zone_id IS NOT NULL ORDER BY o.id DESC LIMIT 1',
            [$senderId]
        );

        return $o ? ['lastZoneId' => (int) $o->sender_zone_id] : [];
    }
}
