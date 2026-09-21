<?php

declare(strict_types=1);

namespace App\Services\V4;

use App\Support\BizDay;
use App\Wire\PilotAccountingWire;
use Illuminate\Support\Facades\DB;

/**
 * أرقام لوحة الكول سنتر — **بتتحسب في السيرفر باستعلامات تجميع**.
 *
 * ليه مش في المتصفح زي القديم: `callcenter.html` كانت بتسحب لحد 1000 أوردر كاملين (ميجا+) وتعدّهم
 * في الـJS مع كل تحديث، و«اليوم» عندها يوم تقويم المتصفح. هنا:
 *   • اليوم = **يوم العمل** (٩ص → ٩ص بتوقيت القاهرة) — نفس أساس الحسابات والتقفيلات (BizDay).
 *   • كل رقم استعلام COUNT/SUM واحد على فهارس موجودة — مفيش صفوف بتتنقل.
 *   • المصدر الوحيد لأرقام الرئيسية: الشاشة والـJSON الحي (`/v4/callcenter/stats`) بيقروا من هنا.
 */
final class CallcenterStats
{
    /** @return array{day:string,from:string,to:string} حدود يوم العمل الحالي بالـUTC */
    public static function window(): array
    {
        $day = BizDay::key();
        [$from, $to] = PilotAccountingWire::bizWindowUtc($day, BizDay::startHour());

        return ['day' => $day, 'from' => $from, 'to' => $to];
    }

    /** @return array<string,mixed> */
    public static function summary(): array
    {
        $w = self::window();

        $o = DB::selectOne(
            "SELECT
                SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS today_total,
                SUM(CASE WHEN status IN ('processing','postponed') THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'delivering' THEN 1 ELSE 0 END) AS delivering,
                SUM(CASE WHEN status = 'delivered' AND delivered_at >= ? AND delivered_at < ? THEN 1 ELSE 0 END) AS delivered_today,
                SUM(CASE WHEN status = 'delivered' AND delivered_at >= ? AND delivered_at < ? THEN total_delivery_price ELSE 0 END) AS revenue_today,
                SUM(CASE WHEN status = 'undelivered' AND undelivered_at >= ? AND undelivered_at < ? THEN 1 ELSE 0 END) AS undelivered_today,
                SUM(CASE WHEN status = 'cancelled' AND cancelled_at >= ? AND cancelled_at < ? THEN 1 ELSE 0 END) AS cancelled_today
               FROM orders
              WHERE status IN ('processing','postponed','delivering')
                 OR created_at >= ? OR delivered_at >= ? OR undelivered_at >= ? OR cancelled_at >= ?",
            [$w['from'], $w['to'], $w['from'], $w['to'], $w['from'], $w['to'], $w['from'], $w['to'], $w['from'], $w['to'],
             $w['from'], $w['from'], $w['from'], $w['from']]
        );

        $p = DB::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) AS waiting,
                    SUM(CASE WHEN status = 'delivering' THEN 1 ELSE 0 END) AS delivering
               FROM pilots WHERE archived_at IS NULL"
        );

        return [
            'day'             => $w['day'],
            'todayTotal'      => (int) ($o->today_total ?? 0),
            'pending'         => (int) ($o->pending ?? 0),
            'delivering'      => (int) ($o->delivering ?? 0),
            'deliveredToday'  => (int) ($o->delivered_today ?? 0),
            'undeliveredToday' => (int) ($o->undelivered_today ?? 0),
            'cancelledToday'  => (int) ($o->cancelled_today ?? 0),
            'revenueToday'    => round((float) ($o->revenue_today ?? 0), 2),
            'pilotsTotal'     => (int) ($p->total ?? 0),
            'pilotsWaiting'   => (int) ($p->waiting ?? 0),
            'pilotsDelivering' => (int) ($p->delivering ?? 0),
        ];
    }

    /** إحصائيات كل فرع — صف لكل فرع حتى لو أرقامه صفر. @return list<array<string,mixed>> */
    public static function branches(): array
    {
        $w = self::window();

        $rows = DB::select(
            "SELECT b.id, b.name, b.paused,
                    COALESCE(o.pending, 0) AS pending, COALESCE(o.delivering, 0) AS delivering,
                    COALESCE(o.delivered_today, 0) AS delivered_today, COALESCE(o.today_total, 0) AS today_total,
                    COALESCE(o.revenue_today, 0) AS revenue_today,
                    COALESCE(p.pilots, 0) AS pilots, COALESCE(p.waiting, 0) AS pilots_waiting
               FROM branches b
               LEFT JOIN (
                    SELECT branch_id,
                           SUM(CASE WHEN status IN ('processing','postponed') THEN 1 ELSE 0 END) AS pending,
                           SUM(CASE WHEN status = 'delivering' THEN 1 ELSE 0 END) AS delivering,
                           SUM(CASE WHEN status = 'delivered' AND delivered_at >= ? AND delivered_at < ? THEN 1 ELSE 0 END) AS delivered_today,
                           SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS today_total,
                           SUM(CASE WHEN status = 'delivered' AND delivered_at >= ? AND delivered_at < ? THEN total_delivery_price ELSE 0 END) AS revenue_today
                      FROM orders
                     WHERE status IN ('processing','postponed','delivering') OR created_at >= ? OR delivered_at >= ?
                     GROUP BY branch_id
               ) o ON o.branch_id = b.id
               LEFT JOIN (
                    SELECT assigned_branch_id, COUNT(*) AS pilots,
                           SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) AS waiting
                      FROM pilots WHERE archived_at IS NULL GROUP BY assigned_branch_id
               ) p ON p.assigned_branch_id = b.id
              ORDER BY b.id",
            [$w['from'], $w['to'], $w['from'], $w['to'], $w['from'], $w['to'], $w['from'], $w['from']]
        );

        return array_map(static fn ($r): array => [
            'id'             => (int) $r->id,
            'name'           => (string) $r->name,
            'paused'         => (int) ($r->paused ?? 0) === 1,
            'pending'        => (int) $r->pending,
            'delivering'     => (int) $r->delivering,
            'deliveredToday' => (int) $r->delivered_today,
            'todayTotal'     => (int) $r->today_total,
            'revenueToday'   => round((float) $r->revenue_today, 2),
            'pilots'         => (int) $r->pilots,
            'pilotsWaiting'  => (int) $r->pilots_waiting,
        ], $rows);
    }

    /** عدّادات القائمة الجانبية (الشارات جنب «النشطة» و«قيد التوصيل»). @return array<string,int> */
    public static function navCounts(): array
    {
        $r = DB::selectOne(
            "SELECT SUM(CASE WHEN status IN ('processing','postponed') THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN status = 'delivering' THEN 1 ELSE 0 END) AS delivering
               FROM orders WHERE status IN ('processing','postponed','delivering')"
        );

        return ['active' => (int) ($r->active ?? 0), 'delivering' => (int) ($r->delivering ?? 0)];
    }
}
