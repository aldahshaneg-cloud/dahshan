<?php

declare(strict_types=1);

namespace App\Services\V4;

use App\Support\Vocab;
use App\Wire\OrderWire;
use App\Wire\PilotAccountingWire;
use App\Support\BizDay;
use Illuminate\Support\Facades\DB;

/**
 * قوايم الأوردرات لشاشات v4 — **الفلترة والبحث والترقيم في السيرفر**.
 *
 * القديم: الصفحة بتسحب لحد 1000 أوردر كاملين وتفلترهم في المتصفح (وبعد الألف الأقدم بيختفي،
 * وفلتر التاريخ كان بيوم التقويم). هنا:
 *   • صفحة = 30 صف + الإجمالي الحقيقي (COUNT) — فالمتصفح بيستلم اللي قدامه بس.
 *   • التاريخ **بيوم العمل** (٩ص → ٩ص القاهرة) وعلى عمود الحالة نفسها: المسلّمة بوقت التسليم،
 *     المرتجعة بوقت المرتجع، الملغاة بوقت الإلغاء، والباقي بوقت الإنشاء.
 *   • البحث: رقم الأوردر · اسم/تليفون المرسل · اسم/تليفون المستلم · تليفون العميل.
 *   • شكل الصف = `OrderWire::batch` بالحرف — نفس لسان باقي النظام (الحالة عربي، الوقت ISO).
 *
 * قراءة بس. مفيش نطاق فرع: الكول سنتر والأدمن شايفين كل الفروع (نفس نطاق GET /api/orders ليهم).
 */
final class OrderListQuery
{
    public const PER_PAGE = 30;

    /** الحالة (كود) → عمود الوقت اللي الفلتر والترتيب بيمشوا عليه */
    private const TIME_COL = [
        'delivered'   => 'o.delivered_at',
        'undelivered' => 'o.undelivered_at',
        'cancelled'   => 'o.cancelled_at',
    ];

    /**
     * @param  list<string>        $statusesAr  حالات بالعربي (زي config/v4.php) — فاضية = كل الحالات
     * @param  array<string,mixed> $f           q · branchId · pilotId · source · from · to (YYYY-MM-DD يوم عمل) · page
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,perPage:int,sum:float}
     */
    public static function run(array $statusesAr, array $f): array
    {
        $codes = array_values(array_filter(array_map(
            static fn (string $s): string => Vocab::statusToCode($s),
            $statusesAr
        )));

        $where = [];
        $args  = [];
        if ($codes) {
            $where[] = 'o.status IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
            array_push($args, ...$codes);
        }

        $timeCol = count($codes) === 1 ? (self::TIME_COL[$codes[0]] ?? 'o.created_at') : 'o.created_at';

        $from = self::day($f['from'] ?? null);
        $to   = self::day($f['to'] ?? null);
        if ($from !== null || $to !== null) {
            $start = PilotAccountingWire::bizWindowUtc($from ?? $to, BizDay::startHour())[0];
            $end   = PilotAccountingWire::bizWindowUtc($to ?? $from, BizDay::startHour())[1];
            $where[] = "{$timeCol} >= ? AND {$timeCol} < ?";
            array_push($args, $start, $end);
        }

        if (! empty($f['branchId'])) {
            $where[] = 'o.branch_id = ?';
            $args[]  = (int) $f['branchId'];
        }
        if (! empty($f['pilotId'])) {
            $where[] = 'o.pilot_id = ?';
            $args[]  = (int) $f['pilotId'];
        }
        if (! empty($f['source'])) {
            $where[] = 'o.source = ?';
            $args[]  = (string) $f['source'];
        }

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where[] = '(o.order_num LIKE ? OR o.sender_name LIKE ? OR o.sender_phone LIKE ? OR o.customer_phone LIKE ?
                         OR EXISTS (SELECT 1 FROM order_deliveries dq WHERE dq.order_id = o.id
                                     AND (dq.receiver_phone LIKE ? OR dq.receiver_name LIKE ? OR dq.receiver_phone2 LIKE ?)))';
            array_push($args, $like, $like, $like, $like, $like, $like, $like);
        }

        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $agg = DB::selectOne('SELECT COUNT(*) AS c, COALESCE(SUM(o.total_delivery_price), 0) AS s FROM orders o' . $sqlWhere, $args);
        $total = (int) ($agg->c ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = max(1, min($pages, (int) ($f['page'] ?? 1)));

        $rows = $total === 0 ? [] : DB::select(
            OrderWire::baseSql() . $sqlWhere
            . " ORDER BY {$timeCol} DESC, o.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $args
        );

        return [
            'items'   => OrderWire::batch($rows),
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'perPage' => self::PER_PAGE,
            'sum'     => round((float) ($agg->s ?? 0), 2),
        ];
    }

    private static function day(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1 ? $s : null;
    }
}
