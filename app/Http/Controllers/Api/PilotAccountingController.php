<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Support\Actor;
use App\Support\ApiResponse;
use App\Support\BizDay;
use App\Support\WireTime;
use App\Wire\PilotAccountingWire as W;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 💰🔴 قسم حسابات الطيارين — الشيت اليومي والتقفيلة الشهرية والسلف المؤجلة.
 *
 * النموذج مأخوذ من «روح دمشق» (`DamascusController`) بالحرف. الفرق الجوهري
 * إن كل خانة هنا **بتتحسب لايف** من الورديات والأوردرات والعمولة، والمكتوب
 * بالإيد بيتخزّن كـoverride منفصل في `pilot_day_entries`.
 *
 * ═══ قاعدة القفل ═══
 * أي كتابة (صف، استئذان، سلفة، قسط) بتترفض لو الشهر مقفول
 * (`pilot_month_locks`) — القفل بيبقى للفرع أو للشركة كلها.
 *
 * ═══ نطاق الأدوار ═══
 * مشرف الفرع بيشوف ويعدّل طياري فرعه بس. الإدارة والمحاسب على الكل.
 * القفل والسلف المؤجلة **للإدارة بس** — دول قرارات مالية مش تشغيلية.
 */
class PilotAccountingController
{
    /* ═══════════════════════════════════════════════════════════
       الإعدادات
    ═══════════════════════════════════════════════════════════ */

    private const SETTING_KEY = 'pilotAccounting';

    /**
     * إعدادات القسم من `acc_settings` — مفتاح واحد فيه JSON.
     * `dayStartHour` = بداية اليوم التجاري (صاحب النظام حددها 9 ص).
     */
    private function settings(): array
    {
        $row = DB::select('SELECT setting_value FROM acc_settings WHERE setting_key = ?', [self::SETTING_KEY])[0] ?? null;
        $v = $row ? json_decode((string) $row->setting_value, true) : null;
        $v = is_array($v) ? $v : [];

        return [
            'dayStartHour' => max(0, min(23, (int) ($v['dayStartHour'] ?? W::DEFAULT_DAY_START))),
            'shiftHours'   => (float) ($v['shiftHours'] ?? W::DEFAULT_SHIFT_HOURS) ?: W::DEFAULT_SHIFT_HOURS,
            // سعر ساعة موحّد للكل — وسعر الطيار الشخصي بيغلبه لو > 0
            'hourRate'     => max(0, (float) ($v['hourRate'] ?? 0)),
            /* بلوك التقفيل (زي روح دمشق): رسوم التطوير على كل أوردر — بتاعة الشركة،
               والفرع اللي بيتحمّلها كلها (0 = كل فرع بأوردراته) */
            'orderRate'      => max(0, round((float) ($v['orderRate'] ?? 2), 2)),
            'devFeeBranchId' => max(0, (int) ($v['devFeeBranchId'] ?? 0)),
        ];
    }

    /** GET /api/pilot-accounting/settings */
    public function settingsGet(Request $request): JsonResponse
    {
        $request->actorOrFail();

        return ApiResponse::out(['ok' => true, 'settings' => $this->settings()]);
    }

    /** PUT /api/pilot-accounting/settings — الإدارة بس */
    public function settingsSave(Request $request): JsonResponse
    {
        $this->need($this->aclOf($request->actorOrFail()), 'act.settings', 'على إعدادات البرنامج');

        $cur = $this->settings();
        $in  = $request->json()->all();
        $val = [
            'dayStartHour' => array_key_exists('dayStartHour', $in)
                ? max(0, min(23, (int) $in['dayStartHour'])) : $cur['dayStartHour'],
            'shiftHours' => array_key_exists('shiftHours', $in)
                ? (max(0.5, min(24, (float) $in['shiftHours'])) ?: W::DEFAULT_SHIFT_HOURS) : $cur['shiftHours'],
            'hourRate' => array_key_exists('hourRate', $in)
                ? max(0, round((float) $in['hourRate'], 2)) : $cur['hourRate'],
            'orderRate' => array_key_exists('orderRate', $in)
                ? max(0, round((float) $in['orderRate'], 2)) : $cur['orderRate'],
            'devFeeBranchId' => array_key_exists('devFeeBranchId', $in)
                ? max(0, (int) $in['devFeeBranchId']) : $cur['devFeeBranchId'],
        ];
        if ($val['devFeeBranchId'] > 0 && ! DB::select('SELECT id FROM branches WHERE id = ?', [$val['devFeeBranchId']])) {
            throw new ApiException('الفرع المتحمّل لرسوم التطوير غير موجود');
        }

        $now = WireTime::nowDb();
        DB::statement(
            'INSERT INTO acc_settings (setting_key, setting_value, updated_at, created_at)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)',
            [self::SETTING_KEY, json_encode($val, JSON_UNESCAPED_UNICODE), $now, $now]
        );

        return ApiResponse::out(['ok' => true, 'settings' => $val]);
    }

    /* ═══════════════════════════════════════════════════════════
       الشهر كامل — نداء واحد بيغذّي الشاشة كلها
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/pilot-accounting/month?month=YYYY-MM&branchId=&pilotId=
     *
     * بيرجّع كل اللي الشاشة محتاجاه في نداء واحد: الطيارين، ومصفوفة
     * الأيام لكل طيار، وإجماليات الشهر، والسلف المؤجلة، وحالة القفل.
     *
     * ليه نداء واحد مش تلاتة: الشيت اليومي وكشف الطيار والتقفيلة الشهرية
     * كلهم **نفس** الحسبة معروضة بتلات أشكال. لو كل شاشة حسبت لوحدها
     * كانوا هيختلفوا مع أول تعديل، وده بالظبط اللي بيخلي حد يسأل «ليه
     * الرقم هنا غير الرقم هناك».
     */
    public function month(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $ym    = $this->monthArg((string) $request->query('month', ''));
        $set   = $this->settings();
        $ds    = $set['dayStartHour'];

        // ── نطاق الفرع ──
        $branchId = $request->query('branchId');
        $branchId = $branchId !== null && $branchId !== '' ? (int) $branchId : null;
        if ($actor->role === 'branch') {
            $branchId = (int) ($actor->branchId ?? 0);   // مشرف الفرع مقفول على فرعه
        }

        /* 🔐 صلاحيات الشخص. بتتقرا مرة واحدة هنا وبتتمرّر على كل صف. */
        $acl = $this->aclOf($actor);

        /* قصّ الفروع المسموحة. لو طلب فرع مش في قايمته يترفض صراحةً —
           مش يترد فاضي، عشان يعرف إن ده منع مش «مافيش بيانات». */
        if ($acl['branches']) {
            if ($branchId !== null && ! in_array($branchId, $acl['branches'], true)) {
                throw ApiException::forbidden('الفرع ده مش مسموحلك بيه');
            }
        }

        $pilotFilter = $request->query('pilotId');
        $pilotFilter = $pilotFilter !== null && $pilotFilter !== '' ? (int) $pilotFilter : null;

        // ── الطيارين ──
        $sql = 'SELECT p.id, p.name, p.assigned_branch_id, b.name AS branch_name,
                       p.commission_type, p.commission_value, p.hour_rate,
                       p.paid_leave_days, p.monthly_salary
                  FROM pilots p LEFT JOIN branches b ON b.id = p.assigned_branch_id';
        $where = [];
        $args  = [];
        if ($branchId !== null) {
            $where[] = 'p.assigned_branch_id = ?';
            $args[]  = $branchId;
        } elseif ($acl['branches']) {
            /* مافيش فرع مطلوب بس الصلاحية محدودة — بنقصّ على فروعه
               بدل ما يشوف الشركة كلها. */
            $where[] = 'p.assigned_branch_id IN (' . implode(',', array_fill(0, count($acl['branches']), '?')) . ')';
            $args    = array_merge($args, $acl['branches']);
        }
        if ($pilotFilter !== null) {
            $where[] = 'p.id = ?';
            $args[]  = $pilotFilter;
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $pilots = array_map(fn ($r) => (array) $r, DB::select($sql . ' ORDER BY p.name', $args));
        if (! $pilots) {
            return ApiResponse::out([
                'ok' => true, 'month' => $ym, 'settings' => $set, 'locked' => $this->monthLocked($ym, $branchId),
                'daysInMonth' => W::daysInMonth($ym), 'countedDays' => W::countedDays($ym, $ds),
                'pilots' => [], 'deferred' => [], 'closeout' => null,
                'acl' => ['keys' => (object) $acl['keys'], 'branches' => $acl['branches'],
                          'full' => $acl['full'], 'isAdmin' => $actor->role === 'admin'],
            ]);
        }
        $ids = array_map(fn ($p) => (int) $p['id'], $pilots);

        // ── نافذة الشهر بالـUTC (اليوم التجاري بيزحزح الحدود) ──
        $nd = W::daysInMonth($ym);
        [$winFrom]  = W::bizWindowUtc($ym . '-01', $ds);
        [, $winTo]  = W::bizWindowUtc($ym . '-' . sprintf('%02d', $nd), $ds);

        $auto = $this->autoMatrix($ids, $pilots, $winFrom, $winTo, $ds);
        $entries = $this->entriesOf($ym, $ids);
        $deferred = $this->deferredOf($ids);

        $counted = W::countedDays($ym, $ds);
        $out = [];
        $rawDays = [];
        $pilotMeta = [];
        foreach ($pilots as $p) {
            $pid  = (int) $p['id'];
            $days = [];
            for ($d = 1; $d <= $nd; $d++) {
                $e     = $entries[$pid][$d] ?? [];
                $perms = $e['perms'] ?? ($auto[$pid][$d]['perms'] ?? []);
                $days[] = W::dayRow($d, $auto[$pid][$d] ?? [], $e, $perms);
            }

            $df = $this->deferredDue($deferred[$pid] ?? [], $ym);
            $totals = W::monthTotals($days, $p, [
                'settings'     => $set,
                'countedDays'  => $counted,
                'shiftHours'   => $set['shiftHours'],
                'deferredDue'  => $df['due'],
                'deferredLeft' => $df['remaining'],
            ]);

            /* صفوف اليوم قبل القصّ — بلوك تقفيلة الفرع بيتحسب منها على السيرفر */
            $rawDays[$pid]  = $days;
            $pilotMeta[$pid] = [
                'branchId' => $p['assigned_branch_id'] !== null ? (int) $p['assigned_branch_id'] : 0,
                'hourRate' => W::hourRateOf($p, $set),
            ];

            /* 🔐 القصّ. `$acl['full']` معناها إن مافيش صف صلاحيات
               فمافيش داعي نلف على كل يوم في الشهر لكل طيار. */
            if (! $acl['full']) {
                $days   = array_map(fn (array $r): array => W::filterDay($r, $acl['keys']), $days);
                $totals = W::filterTotals($totals, $acl['keys']);
            }

            $out[] = [
                'pilotId'    => $pid,
                'name'       => $p['name'],
                'branchId'   => $p['assigned_branch_id'] !== null ? (int) $p['assigned_branch_id'] : null,
                'branchName' => $p['branch_name'] ?? null,
                'hourRate'   => W::hourRateOf($p, $set),
            'ownHourRate' => round((float) $p['hour_rate'], 2),
                'days'       => $days,
                'totals'     => $totals,
            ];
        }

        /* 💰 بلوك تقفيلة الفرع اليومي (زي روح دمشق) — الفروع في نطاق المستخدم */
        $closeout = $this->closeoutBlock($ym, $nd, $ds, $rawDays, $pilotMeta, $set, $acl, $branchId, $ids, $winFrom, $winTo);

        return ApiResponse::out([
            'ok'          => true,
            'serverNow'   => WireTime::toWire(WireTime::nowDb()),
            'month'       => $ym,
            'branchId'    => $branchId,
            'settings'    => $set,
            'locked'      => $this->monthLocked($ym, $branchId),
            'daysInMonth' => $nd,
            'countedDays' => $counted,
            'pilots'      => $out,
            'closeout'    => $closeout,
            /* 🔐 السلف المؤجلة شاشة لوحدها — لو مقفولة مابتخرجش أصلًا */
            'deferred'    => $this->can($acl, 'page.deferred') ? $this->deferredWire($deferred, $ym) : [],
            /* الواجهة بتبني شاشتها من دي — مصدر واحد للمفاتيح */
            'acl'         => ['keys' => (object) $acl['keys'], 'branches' => $acl['branches'],
                              'full' => $acl['full'], 'isAdmin' => $actor->role === 'admin'],
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       بناء الأرقام المحسوبة
    ═══════════════════════════════════════════════════════════ */

    /**
     * 🔴 قلب القسم: بيبني [pilotId][day] من الورديات والأوردرات والعمولة.
     *
     * ثلاث استعلامات بس لكل الشهر — مش استعلام لكل يوم لكل طيار. مع 30
     * يوم و20 طيار ده الفرق بين 3 و1200 استعلام.
     */
    private function autoMatrix(array $ids, array $pilots, string $from, string $to, int $ds): array
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $byId = [];
        foreach ($pilots as $p) {
            $byId[(int) $p['id']] = $p;
        }
        $m = [];

        // ── 1) الورديات: وقت الحضور والانصراف والساعات والسلف والخصومات ──
        $shifts = DB::select(
            "SELECT id, pilot_id, started_at, ended_at, status,
                    advance_amount, deduction_amount, bonus_amount,
                    commission_settle, bonus_settle, deduction_settle, advance_settle
               FROM shifts
              WHERE pilot_id IN ({$ph}) AND started_at >= ? AND started_at < ?
              ORDER BY started_at",
            array_merge($ids, [$from, $to])
        );
        foreach ($shifts as $s) {
            $s   = (array) $s;
            $pid = (int) $s['pilot_id'];
            $bm  = W::bizMoment($s['started_at'], $ds);
            if (! $bm) {
                continue;
            }
            $day = (int) substr($bm['date'], 8, 2);
            $cell = &$m[$pid][$day];
            $cell ??= self::emptyCell();

            // أول حضور وآخر انصراف في اليوم — الطيار ممكن يفتح أكتر من وردية
            if ($cell['in'] === null || $bm['hm'] < $cell['in']) {
                $cell['in'] = $bm['hm'];
            }
            $outM = W::bizMoment($s['ended_at'] ?? null, $ds);
            if ($outM) {
                if ($cell['out'] === null || $outM['hm'] > $cell['out']) {
                    $cell['out'] = $outM['hm'];
                }
                $h = (strtotime($s['ended_at'] . ' UTC') - strtotime($s['started_at'] . ' UTC')) / 3600;
                $cell['hours'] += max(0, $h);
            } else {
                $cell['openShift'] = true;   // وردية لسه مفتوحة — الساعات ناقصة
            }

            $cell['adv']   += (float) $s['advance_amount'];
            $cell['ded']   += (float) $s['deduction_amount'];
            $cell['bonus'] += (float) $s['bonus_amount'];

            /* المرحّل للشهر = اللي مكتوب عليه `monthly` بس. الافتراضي في
               المخطط `daily` يعني اتصفّى مع الطيار عند قفل الوردية.
               نفس شرط `BoardController::buildMonthlyData` بالحرف عشان
               التقفيلتين مايختلفوش. */
            if (($s['advance_settle']   ?: 'monthly') === 'monthly') { $cell['advCarry']   += (float) $s['advance_amount']; }
            if (($s['deduction_settle'] ?: 'monthly') === 'monthly') { $cell['dedCarry']   += (float) $s['deduction_amount']; }
            if (($s['bonus_settle']     ?: 'monthly') === 'monthly') { $cell['bonusCarry'] += (float) $s['bonus_amount']; }
            $cell['commMonthly'] = $cell['commMonthly'] || (($s['commission_settle'] ?: 'monthly') === 'monthly');

            $cell['shiftIds'][] = (int) $s['id'];
            unset($cell);
        }

        // ── 2) الأوردرات المسلَّمة: العدد وإجمالي الخدمة ──
        $orders = DB::select(
            "SELECT id, pilot_id, delivered_at, total_delivery_price
               FROM orders
              WHERE pilot_id IN ({$ph}) AND status = 'delivered'
                AND delivered_at >= ? AND delivered_at < ?",
            array_merge($ids, [$from, $to])
        );

        // ── 3) تعديلات العمولة اليدوية ──
        /* المصروف كاش من الخزنة (paid_amount — طلب 2026-09-03) خرج
           للطيار خلاص، فالمرحَّل للمستحقات هو الباقي بس:
           GREATEST(amount − paid, 0) — نفس قاعدة buildMonthlyData. */
        $ovr     = [];    // [orderId => amount] — للعرض (العمولة المتفق عليها)
        $ovrRem  = [];    // [orderId => الباقي غير المصروف]
        $ovrMeta = [];    // [orderId => [pilot, day]] لأوردر مش متسلّم (مرتجع)
        $ext     = [];    // [pilotId][day] => [amt, rem]
        foreach (DB::select(
            "SELECT pilot_id, order_id, kind, amount, paid_amount, effective_date
               FROM pilot_commission_adjustments
              WHERE pilot_id IN ({$ph}) AND effective_date >= ? AND effective_date <= ?",
            array_merge($ids, [substr($from, 0, 10), substr($to, 0, 10)])
        ) as $a) {
            $a   = (array) $a;
            $rem = max(0.0, (float) $a['amount'] - (float) $a['paid_amount']);
            if ($a['kind'] === 'override' && $a['order_id'] !== null) {
                $oid = (int) $a['order_id'];
                $ovr[$oid]     = (float) $a['amount'];
                $ovrRem[$oid]  = $rem;
                $ovrMeta[$oid] = [(int) $a['pilot_id'], (int) substr((string) $a['effective_date'], 8, 2)];
            } elseif ($a['kind'] === 'extra') {
                $d = (int) substr((string) $a['effective_date'], 8, 2);
                $cur = $ext[(int) $a['pilot_id']][$d] ?? [0.0, 0.0];
                $ext[(int) $a['pilot_id']][$d] = [$cur[0] + (float) $a['amount'], $cur[1] + $rem];
            }
        }

        $ovrUsed = [];
        foreach ($orders as $o) {
            $o   = (array) $o;
            $pid = (int) $o['pilot_id'];
            $bm  = W::bizMoment($o['delivered_at'], $ds);
            if (! $bm) {
                continue;
            }
            $day = (int) substr($bm['date'], 8, 2);
            $cell = &$m[$pid][$day];
            $cell ??= self::emptyCell();
            $cell['orders']++;
            $cell['svc']  += (float) $o['total_delivery_price'];
            $oid  = (int) $o['id'];
            $comm = W::orderCommission($o, $byId[$pid] ?? [], $ovr);
            $cell['psvc'] += $comm;
            // العمولة بترحّل بس لو وردية اليوم متعلّم عليها monthly —
            // والمكتوبة بالإيد بترحّل بالباقي غير المصروف كاش
            if ($cell['commMonthly']) {
                $cell['psvcCarry'] += isset($ovr[$oid]) ? $ovrRem[$oid] : $comm;
            }
            $ovrUsed[$oid] = true;
            unset($cell);
        }

        /* override على أوردر مش متسلّم — عمولة «من جيب الشركة» على مرتجع
           (طلب 2026-09-03). بتدخل يوم تاريخها زي المستقلة: العرض بالمبلغ
           والترحيل بالباقي غير المصروف. */
        foreach ($ovrMeta as $oid => [$pid, $day]) {
            if (isset($ovrUsed[$oid])) {
                continue;   // اتحسب مع أوردره المتسلّم فوق
            }
            $cell = &$m[$pid][$day];
            $cell ??= self::emptyCell();
            $cell['psvc']      += $ovr[$oid];
            $cell['psvcCarry'] += $ovrRem[$oid];
            unset($cell);
        }

        // عمولات بلا أوردر (تعويض شكوى مثلًا) بتتضاف على يومها
        foreach ($ext as $pid => $days) {
            foreach ($days as $day => [$amt, $rem]) {
                $cell = &$m[$pid][$day];
                $cell ??= self::emptyCell();
                /* العمولة المستقلة بترحّل دايمًا — بس بالباقي غير المصروف
                   كاش (المصروفة من الخزنة خرجت للطيار خلاص). نفس قاعدة
                   buildMonthlyData بالحرف. */
                $cell['psvc']      += $amt;
                $cell['psvcCarry'] += $rem;
                unset($cell);
            }
        }

        // ── 4) الاستئذان من طلبات الراحة اللي بدأت وخلصت جوه اليوم ──
        foreach (DB::select(
            "SELECT pilot_id, responded_at, ended_at
               FROM pilot_leave_requests
              WHERE pilot_id IN ({$ph}) AND status IN ('approved','ended')
                AND responded_at IS NOT NULL AND ended_at IS NOT NULL
                AND responded_at >= ? AND responded_at < ?",
            array_merge($ids, [$from, $to])
        ) as $lr) {
            $lr = (array) $lr;
            $a = W::bizMoment($lr['responded_at'], $ds);
            $b = W::bizMoment($lr['ended_at'], $ds);
            if (! $a || ! $b || $a['date'] !== $b['date']) {
                continue;   // إجازة عدّت اليوم = غياب مش استئذان
            }
            $day = (int) substr($a['date'], 8, 2);
            $cell = &$m[(int) $lr['pilot_id']][$day];
            $cell ??= self::emptyCell();
            $cell['perms'][] = ['out' => $a['hm'], 'in' => $b['hm']];
            unset($cell);
        }

        /* 🔴 الساعات هنا **قبل** خصم الاستئذان عن قصد.
           كانت بتتخصم هنا، وبعدها dayRow بتخصمها **تاني** (hours =
           auto.hours×60 − permMinutes) — يعني ساعة الإذن كانت بتتخصم
           مرتين من أجر الطيار. اتلقت بتجربة تنفيذية: وردية ٦ ساعات
           وإذن ساعة طلعت ٤ بدل ٥. الخصم مكانه الوحيد dayRow، لأنها
           هي اللي بتعرف الاستئذان الساري فعلًا (اليدوي بيستبدل
           التلقائي هناك). */
        foreach ($m as $pid => $days) {
            foreach ($days as $day => $c) {
                $m[$pid][$day]['hours'] = round(max(0, $c['hours']), 2);
                $m[$pid][$day]['svc']       = round($c['svc'], 2);
                $m[$pid][$day]['psvc']      = round($c['psvc'], 2);
                $m[$pid][$day]['psvcCarry'] = round($c['psvcCarry'], 2);
            }
        }

        return $m;
    }

    private static function emptyCell(): array
    {
        return ['in' => null, 'out' => null, 'hours' => 0.0, 'orders' => 0, 'svc' => 0.0,
                'psvc' => 0.0, 'adv' => 0.0, 'ded' => 0.0, 'bonus' => 0.0,
                // المرحّل للشهر — منفصل عن المعروض
                'psvcCarry' => 0.0, 'advCarry' => 0.0, 'dedCarry' => 0.0, 'bonusCarry' => 0.0,
                'commMonthly' => false,
                'perms' => [], 'shiftIds' => [], 'openShift' => false];
    }

    /** صفوف التدخّل اليدوي + فترات الاستئذان اليدوية */
    private function entriesOf(string $ym, array $ids): array
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select(
            "SELECT * FROM pilot_day_entries WHERE month = ? AND pilot_id IN ({$ph})",
            array_merge([$ym], $ids)
        );
        $out = [];
        $byEntry = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $out[(int) $r['pilot_id']][(int) $r['day']] = $r;
            $byEntry[(int) $r['id']] = [(int) $r['pilot_id'], (int) $r['day']];
        }
        if ($byEntry) {
            $eph = implode(',', array_fill(0, count($byEntry), '?'));
            foreach (DB::select(
                "SELECT * FROM pilot_day_perms WHERE entry_id IN ({$eph}) ORDER BY id",
                array_keys($byEntry)
            ) as $p) {
                $p = (array) $p;
                [$pid, $day] = $byEntry[(int) $p['entry_id']];
                $out[$pid][$day]['perms'][] = ['out' => $p['perm_out'], 'in' => $p['perm_in']];
            }
        }

        return $out;
    }

    /* ═══════════════════════════════════════════════════════════
       السلف المؤجلة
    ═══════════════════════════════════════════════════════════ */

    private function deferredOf(array $ids): array
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $recs = array_map(fn ($r) => (array) $r, DB::select(
            "SELECT * FROM pilot_deferred_advances WHERE pilot_id IN ({$ph}) ORDER BY id",
            $ids
        ));
        if (! $recs) {
            return [];
        }
        $pays = [];
        $aph  = implode(',', array_fill(0, count($recs), '?'));
        foreach (DB::select(
            "SELECT * FROM pilot_deferred_payments WHERE advance_id IN ({$aph})",
            array_map(fn ($r) => (int) $r['id'], $recs)
        ) as $p) {
            $p = (array) $p;
            $pays[(int) $p['advance_id']][$p['month']] = (float) $p['amount'];
        }
        $out = [];
        foreach ($recs as $r) {
            $r['_pays'] = $pays[(int) $r['id']] ?? [];
            $out[(int) $r['pilot_id']][] = $r;
        }

        return $out;
    }

    /** مجموع أقساط الشهر والرصيد المتبقي بعده لطيار واحد */
    private function deferredDue(array $recs, string $ym): array
    {
        $due = 0.0;
        $rem = 0.0;
        foreach ($recs as $r) {
            $c = W::deferredForMonth($r, $ym, $r['_pays'] ?? []);
            $due += $c['due'];
            $rem += $c['after'];
        }

        return ['due' => round($due, 2), 'remaining' => round($rem, 2)];
    }

    private function deferredWire(array $deferred, string $ym): array
    {
        $out = [];
        foreach ($deferred as $pid => $recs) {
            foreach ($recs as $r) {
                $c = W::deferredForMonth($r, $ym, $r['_pays'] ?? []);
                $out[] = [
                    'id'          => (int) $r['id'],
                    'pilotId'     => (int) $pid,
                    'advanceDate' => $r['advance_date'],
                    'amount'      => round((float) $r['amount'], 2),
                    'monthly'     => round((float) $r['monthly'], 2),
                    'startMonth'  => $r['start_month'],
                    'note'        => (string) ($r['note'] ?? ''),
                    'createdBy'   => $r['created_by'] ?? null,
                    'monthDue'    => $c['due'],
                    'paidBefore'  => round((float) $r['amount'] - $c['before'], 2),
                    'remaining'   => $c['after'],
                    'done'        => $c['started'] && $c['after'] <= 0.005,
                ];
            }
        }

        return $out;
    }

    /** GET /api/pilot-accounting/deferred?month= */
    public function deferredList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $this->need($this->aclOf($actor), 'page.deferred', 'على شاشة السلف المؤجلة');
        $ym    = $this->monthArg((string) $request->query('month', ''));

        $sql  = 'SELECT p.id FROM pilots p';
        $args = [];
        if ($actor->role === 'branch') {
            $sql .= ' WHERE p.assigned_branch_id = ?';
            $args[] = (int) ($actor->branchId ?? 0);
        }
        $ids = array_map(fn ($r) => (int) $r->id, DB::select($sql, $args));
        if (! $ids) {
            return ApiResponse::out(['ok' => true, 'month' => $ym, 'items' => []]);
        }

        return ApiResponse::out([
            'ok' => true, 'month' => $ym,
            'items' => $this->deferredWire($this->deferredOf($ids), $ym),
        ]);
    }

    /** POST /api/pilot-accounting/deferred — الإدارة بس */
    public function deferredSave(Request $request): JsonResponse
    {
        $this->need($this->aclOf($request->actorOrFail()), 'act.deferred', 'على السلف المؤجلة');
        $b = $request->json()->all();

        $pilotId = (int) ($b['pilotId'] ?? 0);
        if ($pilotId <= 0 || ! DB::select('SELECT id FROM pilots WHERE id = ?', [$pilotId])) {
            throw ApiException::notFound('الطيار غير موجود');
        }
        $amount = round((float) ($b['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new ApiException('مبلغ السلفة لازم يكون أكبر من صفر');
        }
        $monthly = round(max(0, (float) ($b['monthly'] ?? 0)), 2);
        if ($monthly > $amount) {
            throw new ApiException('القسط الشهري مينفعش يكون أكبر من السلفة');
        }
        $start = $this->monthArg((string) ($b['startMonth'] ?? ''));
        $this->assertUnlocked($start, null);

        $note = trim((string) ($b['note'] ?? ''));
        $date = trim((string) ($b['advanceDate'] ?? ''));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : substr($start, 0, 7) . '-01';

        $id = (int) ($b['id'] ?? 0);
        if ($id > 0) {
            DB::update(
                'UPDATE pilot_deferred_advances
                    SET pilot_id = ?, advance_date = ?, amount = ?, monthly = ?, start_month = ?, note = ?
                  WHERE id = ?',
                [$pilotId, $date, $amount, $monthly, $start, $note ?: null, $id]
            );
        } else {
            DB::insert(
                'INSERT INTO pilot_deferred_advances
                   (pilot_id, advance_date, amount, monthly, start_month, note, created_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$pilotId, $date, $amount, $monthly, $start, $note ?: null,
                 $request->actorOrFail()->username, WireTime::nowDb()]
            );
            $id = (int) DB::getPdo()->lastInsertId();
        }

        return ApiResponse::out(['ok' => true, 'id' => $id]);
    }

    /** DELETE /api/pilot-accounting/deferred/{id} — الإدارة بس */
    public function deferredDelete(Request $request, string $id): JsonResponse
    {
        $this->need($this->aclOf($request->actorOrFail()), 'act.deferred', 'على السلف المؤجلة');
        $request->actorOrFail();
        $n = DB::delete('DELETE FROM pilot_deferred_advances WHERE id = ?', [(int) $id]);
        if (! $n) {
            throw ApiException::notFound('السلفة غير موجودة');
        }

        return ApiResponse::ok();
    }

    /**
     * POST /api/pilot-accounting/deferred/{id}/payment — {month, amount}
     * قسط شهر بعينه — بيغلب القسط الافتراضي. amount فاضي = رجوع للافتراضي.
     */
    public function deferredPayment(Request $request, string $id): JsonResponse
    {
        $this->need($this->aclOf($request->actorOrFail()), 'act.deferred', 'على السلف المؤجلة');
        $request->actorOrFail();
        $b  = $request->json()->all();
        $ym = $this->monthArg((string) ($b['month'] ?? ''));
        $this->assertUnlocked($ym, null);

        $rec = DB::select('SELECT * FROM pilot_deferred_advances WHERE id = ?', [(int) $id])[0] ?? null;
        if (! $rec) {
            throw ApiException::notFound('السلفة غير موجودة');
        }

        $raw = $b['amount'] ?? null;
        if ($raw === null || $raw === '') {
            DB::delete('DELETE FROM pilot_deferred_payments WHERE advance_id = ? AND month = ?', [(int) $id, $ym]);

            return ApiResponse::ok();
        }
        $amt = round(max(0, (float) $raw), 2);
        if ($amt > (float) $rec->amount) {
            throw new ApiException('القسط مينفعش يكون أكبر من السلفة');
        }
        DB::statement(
            'INSERT INTO pilot_deferred_payments (advance_id, month, amount, created_at)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE amount = VALUES(amount)',
            [(int) $id, $ym, $amt, WireTime::nowDb()]
        );

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       التدخّل اليدوي على الشيت
    ═══════════════════════════════════════════════════════════ */

    /**
     * POST /api/pilot-accounting/entry
     * body: {month, pilotId, day, field, value}
     *
     * `value` فاضية = **شيل الـoverride** والرقم يرجع محسوب من النظام.
     * ودي أهم حتة: المشرف لازم يقدر يتراجع عن تدخّله ويرجّع رقم النظام.
     */
    public function entrySave(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $b     = $request->json()->all();

        $ym    = $this->monthArg((string) ($b['month'] ?? ''));
        $pid   = (int) ($b['pilotId'] ?? 0);
        $day   = (int) ($b['day'] ?? 0);
        $field = (string) ($b['field'] ?? '');

        $COLS = [
            'in'     => 'time_in_override',
            'out'    => 'time_out_override',
            'hours'  => 'hours_override',
            'orders' => 'orders_override',
            'svc'    => 'svc_override',
            'psvc'   => 'psvc_override',
            'net'    => 'net_override',
            'adv'    => 'advance_extra',
            'ded'    => 'deduction_extra',
            'bonus'  => 'bonus_extra',
            'note'   => 'note',
        ];
        if (! isset($COLS[$field])) {
            throw new ApiException('خانة مش معروفة');
        }
        if ($day < 1 || $day > W::daysInMonth($ym)) {
            throw new ApiException('اليوم مش في الشهر ده');
        }

        /* 🔐 شرطين مش واحد: الفعل، وبعدين العمود نفسه. اللي شايف
           «سلف» بس مايقدرش يكتب في «خصومات». اسم الخانة هو نفسه
           اللي بعد `col.` في المفتاح — الجدولين اتكتبوا مع بعض. */
        $acl = $this->aclOf($actor);
        $this->need($acl, 'act.edit', 'تعديل الخانات');
        $this->need($acl, 'col.' . $field, 'الكتابة في الخانة دي');

        $pilot = $this->pilotInScope($actor, $pid);
        $this->assertUnlocked($ym, $pilot['assigned_branch_id'] !== null ? (int) $pilot['assigned_branch_id'] : null);

        // ── تنظيف القيمة حسب نوع الخانة ──
        $raw = $b['value'] ?? null;
        $val = null;
        if ($raw !== null && $raw !== '') {
            if (in_array($field, ['in', 'out'], true)) {
                if (W::parseHm((string) $raw) === null) {
                    throw new ApiException('الوقت لازم يكون بصيغة HH:MM');
                }
                $val = trim((string) $raw);
            } elseif ($field === 'note') {
                $val = mb_substr(trim((string) $raw), 0, 2000);
            } elseif ($field === 'orders') {
                $val = max(0, (int) $raw);
            } else {
                $val = round((float) $raw, 2);
            }
        }

        $now = WireTime::nowDb();
        DB::statement(
            "INSERT INTO pilot_day_entries (month, pilot_id, day, `{$COLS[$field]}`, updated_by, updated_at, created_at)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE `{$COLS[$field]}` = VALUES(`{$COLS[$field]}`),
                                     updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
            [$ym, $pid, $day, $val, $actor->username, $now, $now]
        );

        if ($val === null) {
            $this->cleanupEntry($ym, $pid, $day);
        }

        return ApiResponse::out(['ok' => true, 'cleared' => $val === null]);
    }

    /**
     * الصف اللي كل خاناته فاضية ومفيش استئذان بيتحذف خالص — نفس
     * `entryCleanup` في روح دمشق. من غيره الجدول بيتملى صفوف مالهاش أي
     * معنى، و«فيه صف» بيبطّل يدل على «حد تدخّل هنا».
     */
    private function cleanupEntry(string $ym, int $pid, int $day): void
    {
        $r = DB::select(
            'SELECT * FROM pilot_day_entries WHERE month = ? AND pilot_id = ? AND day = ?',
            [$ym, $pid, $day]
        )[0] ?? null;
        if (! $r) {
            return;
        }
        $r = (array) $r;
        foreach (['time_in_override','time_out_override','hours_override','orders_override',
                  'svc_override','psvc_override','net_override','advance_extra',
                  'deduction_extra','bonus_extra'] as $c) {
            if ($r[$c] !== null) {
                return;
            }
        }
        if (trim((string) ($r['note'] ?? '')) !== '') {
            return;
        }
        if (DB::select('SELECT id FROM pilot_day_perms WHERE entry_id = ? LIMIT 1', [(int) $r['id']])) {
            return;
        }
        DB::delete('DELETE FROM pilot_day_entries WHERE id = ?', [(int) $r['id']]);
    }

    /* ═══════════════════════════════════════════════════════════
       💰 بلوك تقفيلة الفرع اليومي — نفس بلوك «تقفيل روح دمشق» بدون النسبة
    ═══════════════════════════════════════════════════════════ */

    /** اللي المشرف كتبه في البلوك: [branchId][day] => ['ext','exp','recv'] */
    private function summariesOf(string $ym): array
    {
        $out = [];
        foreach (DB::select('SELECT branch_id, day, ext, exp, recv FROM pilot_acct_day_summaries WHERE month = ?', [$ym]) as $r) {
            $s = [];
            if ($r->ext  !== null) { $s['ext']  = (float) $r->ext; }
            if ($r->exp  !== null) { $s['exp']  = (float) $r->exp; }
            if ($r->recv !== null) { $s['recv'] = (float) $r->recv; }
            $out[(int) $r->branch_id][(int) $r->day] = $s;
        }

        return $out;
    }

    /**
     * تقفيلة كل فرع في نطاق المستخدم لكل يوم في الشهر + مجموع الشهر.
     *
     * رسوم التطوير لو فيه فرع متحمّلها بتتحسب على **أوردرات الشركة كلها**
     * في اليوم — مش على اللي المستخدم شايفه. لو النطاق أضيق من الشركة
     * (فرع واحد أو صلاحية محدودة) بنحسب أوردرات كل الطيارين تاني هنا.
     */
    private function closeoutBlock(string $ym, int $nd, int $ds, array $rawDays, array $pilotMeta, array $set,
                                   array $acl, ?int $branchId, array $ids, string $winFrom, string $winTo): array
    {
        $names = [];
        foreach (DB::select('SELECT id, name FROM branches ORDER BY name') as $b) {
            $names[(int) $b->id] = $b->name;
        }
        $sums = $this->summariesOf($ym);
        if ($branchId !== null) {
            $branchIds = [$branchId];
        } else {
            /* الفروع اللي فيها طيارين (أو اتكتب لها بند في الشهر) بس — فرع إداري
               أو تجريبي من غير طيارين كان بيطلع بلوك فاضي ويبان إن البلوك متكرر */
            $active = [];
            foreach ($pilotMeta as $m) {
                $active[(int) $m['branchId']] = true;
            }
            foreach (array_keys($sums) as $sb) {
                $active[(int) $sb] = true;
            }
            $pool = $acl['branches'] ? $acl['branches'] : array_keys($names);
            $branchIds = array_values(array_filter($pool, fn ($b) => isset($names[$b]) && isset($active[(int) $b])));
        }

        /* أوردرات الشركة كلها في كل يوم — للفرع المتحمّل رسوم التطوير */
        $allOrders = array_fill(1, $nd, 0.0);
        $dfb = (int) ($set['devFeeBranchId'] ?? 0);
        if ($dfb > 0) {
            $allPilots = array_map(fn ($r) => (array) $r, DB::select(
                'SELECT id, commission_type, commission_value, hour_rate FROM pilots WHERE archived_at IS NULL'
            ));
            $allIds = array_map(fn ($p) => (int) $p['id'], $allPilots);
            $missing = array_diff($allIds, $ids);
            foreach ($rawDays as $days) {
                foreach ($days as $d) {
                    $allOrders[(int) $d['day']] += (float) $d['orders'];
                }
            }
            if ($missing) {
                $missing = array_values($missing);
                $mp = array_values(array_filter($allPilots, fn ($p) => in_array((int) $p['id'], $missing, true)));
                $auto = $this->autoMatrix($missing, $mp, $winFrom, $winTo, $ds);
                $entries = $this->entriesOf($ym, $missing);
                foreach ($missing as $pid) {
                    for ($d = 1; $d <= $nd; $d++) {
                        $e = $entries[$pid][$d] ?? [];
                        $ov = ($e['orders_override'] ?? null) !== null && $e['orders_override'] !== '';
                        $allOrders[$d] += $ov ? (int) $e['orders_override'] : (int) ($auto[$pid][$d]['orders'] ?? 0);
                    }
                }
            }
        }

        $branches = [];
        $all = ['hours' => 0.0, 'orders' => 0.0, 'hourPay' => 0.0, 'devFeeOrders' => 0.0, 'devFee' => 0.0, 'ext' => 0.0,
                'exp' => 0.0, 'outTotal' => 0.0, 'cash' => 0.0, 'adv' => 0.0, 'received' => 0.0, 'expected' => 0.0, 'net' => 0.0];
        $keysM = array_keys($all);
        foreach ($branchIds as $bid) {
            $days = [];
            $m = array_fill_keys($keysM, 0.0);
            for ($d = 1; $d <= $nd; $d++) {
                $rows = [];
                foreach ($rawDays as $pid => $pd) {
                    if (($pilotMeta[$pid]['branchId'] ?? 0) !== $bid) {
                        continue;
                    }
                    $rows[] = ['row' => $pd[$d - 1], 'hourRate' => $pilotMeta[$pid]['hourRate']];
                }
                $c = W::branchDayCloseout($rows, $sums[$bid][$d] ?? [], $set, $bid, (float) $allOrders[$d]);
                foreach ($keysM as $k) {
                    $m[$k] += (float) ($c[$k] ?? 0);
                    $all[$k] += (float) ($c[$k] ?? 0);
                }
                $c['day'] = $d;
                $days[] = $acl['full'] ? $c : W::filterCloseout($c, $acl['keys']);
            }
            foreach ($m as $k => $v) {
                $m[$k] = round($v, 2);
            }
            $branches[] = [
                'branchId' => $bid,
                'name'     => $names[$bid] ?? '—',
                'days'     => $days,
                'month'    => $acl['full'] ? $m : W::filterCloseout($m, $acl['keys']),
            ];
        }
        foreach ($all as $k => $v) {
            $all[$k] = round($v, 2);
        }

        return [
            'orderRate'      => (float) ($set['orderRate'] ?? 0),
            'devFeeBranchId' => $dfb,
            'branches'       => $branches,
            'all'            => $acl['full'] ? $all : W::filterCloseout($all, $acl['keys']),
        ];
    }

    /**
     * POST /api/pilot-accounting/day-summary — {month, branchId, day, field, value}
     * الحقول: ext (الخارجي) · exp (مصاريف) · recv (المستلم فعلًا من المشرف).
     * فاضي = مسح الخانة. كل حقل وراه صلاحيته في البلوك (blk.*) + act.edit.
     */
    public function daySummarySave(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $b     = $request->json()->all();
        $ym    = $this->monthArg((string) ($b['month'] ?? ''));
        $bid   = (int) ($b['branchId'] ?? 0);
        $day   = (int) ($b['day'] ?? 0);
        $field = (string) ($b['field'] ?? '');

        $PERM = ['ext' => 'blk.ext', 'exp' => 'blk.exp', 'recv' => 'blk.recon'];
        if (! isset($PERM[$field])) {
            throw new ApiException('خانة مش معروفة');
        }
        if ($day < 1 || $day > W::daysInMonth($ym)) {
            throw new ApiException('اليوم مش في الشهر ده');
        }
        if ($bid <= 0 || ! DB::select('SELECT id FROM branches WHERE id = ?', [$bid])) {
            throw ApiException::notFound('الفرع غير موجود');
        }

        $acl = $this->aclOf($actor);
        $this->need($acl, 'act.edit', 'تعديل الخانات');
        $this->need($acl, $PERM[$field], 'الكتابة في البند ده');
        if ($actor->role === 'branch' && (int) ($actor->branchId ?? 0) !== $bid) {
            throw ApiException::forbidden('الفرع ده مش فرعك');
        }
        if ($acl['branches'] && ! in_array($bid, $acl['branches'], true)) {
            throw ApiException::forbidden('الفرع ده مش مسموحلك بيه');
        }
        $this->assertUnlocked($ym, $bid);

        $raw = $b['value'] ?? null;
        $val = ($raw === null || $raw === '') ? null : round((float) $raw, 2);
        $now = WireTime::nowDb();
        DB::statement(
            "INSERT INTO pilot_acct_day_summaries (month, branch_id, day, `{$field}`, updated_by, updated_at, created_at)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE `{$field}` = VALUES(`{$field}`), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
            [$ym, $bid, $day, $val, $actor->username, $now, $now]
        );
        if ($val === null) {
            // الصف اللي بقى فاضي خالص بيتمسح — زي entryCleanup
            DB::delete(
                'DELETE FROM pilot_acct_day_summaries WHERE month = ? AND branch_id = ? AND day = ?
                    AND ext IS NULL AND exp IS NULL AND recv IS NULL',
                [$ym, $bid, $day]
            );
        }

        return ApiResponse::out(['ok' => true, 'cleared' => $val === null]);
    }

    /**
     * POST /api/pilot-accounting/perms — {month, pilotId, day, periods:[{out,in}]}
     * بيستبدل فترات الاستئذان اليدوية لليوم كلها (مش بيضيف عليها).
     */
    public function permsSave(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        /* 🔐 الاستئذان بيتعرض في عمودين (خروج ورجوع). الكتابة مسموحة
           لو أي واحد فيهم مفتوح — نفس شرط القصّ في `filterDay`، عشان
           مايحصلش إن حد يشوف العمود ومايقدرش يكتب فيه. */
        $acl = $this->aclOf($actor);
        $this->need($acl, 'act.edit', 'تعديل الخانات');
        if (! $this->can($acl, 'col.bout') && ! $this->can($acl, 'col.bin')) {
            throw ApiException::forbidden('مالكش صلاحية على خانات الاستئذان — كلّم الإدارة');
        }
        $b     = $request->json()->all();

        $ym  = $this->monthArg((string) ($b['month'] ?? ''));
        $pid = (int) ($b['pilotId'] ?? 0);
        $day = (int) ($b['day'] ?? 0);
        if ($day < 1 || $day > W::daysInMonth($ym)) {
            throw new ApiException('اليوم مش في الشهر ده');
        }
        $pilot = $this->pilotInScope($actor, $pid);
        $this->assertUnlocked($ym, $pilot['assigned_branch_id'] !== null ? (int) $pilot['assigned_branch_id'] : null);

        $periods = is_array($b['periods'] ?? null) ? $b['periods'] : [];
        $clean = [];
        foreach ($periods as $p) {
            $o = trim((string) ($p['out'] ?? ''));
            $i = trim((string) ($p['in'] ?? ''));
            if ($o === '' && $i === '') {
                continue;
            }
            if (($o !== '' && W::parseHm($o) === null) || ($i !== '' && W::parseHm($i) === null)) {
                throw new ApiException('وقت الاستئذان لازم يكون بصيغة HH:MM');
            }
            $clean[] = [$o ?: null, $i ?: null];
        }
        if (count($clean) > 20) {
            throw new ApiException('أقصى عدد فترات استئذان لليوم 20');
        }

        try {
            DB::transaction(function () use ($ym, $pid, $day, $clean, $actor): void {
                $now = WireTime::nowDb();
                DB::statement(
                    'INSERT INTO pilot_day_entries (month, pilot_id, day, updated_by, updated_at, created_at)
                     VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
                    [$ym, $pid, $day, $actor->username, $now, $now]
                );
                $eid = (int) DB::select(
                    'SELECT id FROM pilot_day_entries WHERE month = ? AND pilot_id = ? AND day = ?',
                    [$ym, $pid, $day]
                )[0]->id;

                DB::delete('DELETE FROM pilot_day_perms WHERE entry_id = ?', [$eid]);
                foreach ($clean as [$o, $i]) {
                    DB::insert(
                        'INSERT INTO pilot_day_perms (entry_id, perm_out, perm_in, created_at) VALUES (?,?,?,?)',
                        [$eid, $o, $i, $now]
                    );
                }
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('تعذّر حفظ الاستئذان', 500);
        }

        // مسح كل الفترات ممكن يسيب صف فاضي — نفس تنظيف entrySave
        if (! $clean) {
            $this->cleanupEntry($ym, $pid, $day);
        }

        return ApiResponse::out(['ok' => true, 'count' => count($clean)]);
    }

    /* ═══════════════════════════════════════════════════════════
       قفل الشهر
    ═══════════════════════════════════════════════════════════ */

    /** POST /api/pilot-accounting/lock — {month, branchId?} — الإدارة بس */
    public function lockMonth(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $this->need($this->aclOf($actor), 'act.lock', 'على قفل الشهر');
        $b  = $request->json()->all();
        $ym = $this->monthArg((string) ($b['month'] ?? ''));
        $bid = isset($b['branchId']) && $b['branchId'] !== '' ? (int) $b['branchId'] : null;

        DB::statement(
            'INSERT INTO pilot_month_locks (month, branch_id, locked_at, locked_by)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE locked_at = VALUES(locked_at), locked_by = VALUES(locked_by)',
            [$ym, $bid, WireTime::nowDb(), $actor->username]
        );

        return ApiResponse::ok();
    }

    /** DELETE /api/pilot-accounting/lock?month=&branchId= — الإدارة بس */
    public function unlockMonth(Request $request): JsonResponse
    {
        $this->need($this->aclOf($request->actorOrFail()), 'act.lock', 'على فتح الشهر');
        $ym  = $this->monthArg((string) $request->query('month', ''));
        $bid = $request->query('branchId');
        $bid = $bid !== null && $bid !== '' ? (int) $bid : null;

        DB::delete(
            $bid === null
                ? 'DELETE FROM pilot_month_locks WHERE month = ? AND branch_id IS NULL'
                : 'DELETE FROM pilot_month_locks WHERE month = ? AND branch_id = ?',
            $bid === null ? [$ym] : [$ym, $bid]
        );

        return ApiResponse::ok();
    }

    /** الشهر مقفول لو فيه قفل عام أو قفل على الفرع ده */
    private function monthLocked(string $ym, ?int $branchId): bool
    {
        $rows = DB::select(
            'SELECT branch_id FROM pilot_month_locks WHERE month = ? AND (branch_id IS NULL' .
            ($branchId !== null ? ' OR branch_id = ?)' : ')'),
            $branchId !== null ? [$ym, $branchId] : [$ym]
        );

        return count($rows) > 0;
    }

    private function assertUnlocked(string $ym, ?int $branchId): void
    {
        if ($this->monthLocked($ym, $branchId)) {
            throw ApiException::forbidden('الشهر ده مقفول — مفيش تعديل');
        }
    }

    /* ═══════════════════════════════════════════════════════════
       أدوات
    ═══════════════════════════════════════════════════════════ */

    /** "YYYY-MM" — والفاضي بيرجّع شهر النهارده بتوقيت القاهرة */
    private function monthArg(string $v): string
    {
        $v = trim($v);
        if (preg_match('/^\d{4}-\d{2}$/', $v)) {
            return $v;
        }

        /* شهر تجاري: فجر أول يوم في الشهر لسه تبع الشهر اللي فات */
        return substr(BizDay::key(), 0, 7);
    }

    /** 🔒 مشرف الفرع مقفول على طياري فرعه */
    /* ═══════════════════════════════════════════════════════════
       🔐 الصلاحيات
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/pilot-accounting/acl — شاشة الصلاحيات (الأدمن بس).
     *
     * بترجّع تعريف المجموعات كمان مش المحفوظ بس: الواجهة بتبني
     * الشاشة من الرد، فمفتاح جديد في الـWire بيظهر في الشاشة من
     * غير أي تعديل في الـHTML — ومافيش فرصة إن الاتنين يختلفوا.
     */
    public function aclList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        if ($actor->role !== 'admin') {
            throw ApiException::forbidden('شاشة الصلاحيات للإدارة بس');
        }

        $rows = [];
        foreach (DB::select('SELECT user_id, perm_keys, branches, updated_by, updated_at FROM pilot_acct_perms') as $r) {
            $r    = (array) $r;
            $keys = [];
            $d    = json_decode((string) ($r['perm_keys'] ?? ''), true);
            if (is_array($d)) {
                foreach ($d as $k => $v) {
                    if ($v === true) {
                        $keys[(string) $k] = true;
                    }
                }
            }
            $branches = [];
            foreach (explode(',', (string) ($r['branches'] ?? '')) as $b) {
                $b = (int) trim($b);
                if ($b > 0) {
                    $branches[] = $b;
                }
            }
            $rows[(string) (int) $r['user_id']] = [
                'keys'      => (object) $keys,
                'branches'  => $branches,
                'updatedBy' => $r['updated_by'],
                'updatedAt' => $r['updated_at'] !== null ? WireTime::toWire((string) $r['updated_at']) : null,
            ];
        }

        /* الأدمن مابيظهرش في القايمة — عنده كل حاجة دايمًا ومافيش
           معنى إن صاحب النظام يقفل على نفسه بالغلط. */
        /* 🍽️ حسابات روح دمشق بس مش من موظفي البرنامج ده — صلاحياتها في شاشة دمشق */
        $rd = AuthController::damascusOnlyUserIds();
        $users = DB::select(
            "SELECT u.id, u.username, u.name, u.role, u.branch_id, b.name AS branch_name
               FROM users u LEFT JOIN branches b ON b.id = u.branch_id
              WHERE u.blocked = 0 AND u.role NOT IN ('admin', 'pilot', 'store', 'customer')"
            . ($rd ? ' AND u.id NOT IN (' . implode(',', array_map('intval', $rd)) . ')' : '')
            . ' ORDER BY u.role, u.username'
        );

        return ApiResponse::out([
            'ok'         => true,
            'users'      => array_map(fn ($u): array => [
                'id'         => (int) $u->id,
                'username'   => $u->username,
                'name'       => $u->name,
                'role'       => $u->role,
                'branchId'   => $u->branch_id !== null ? (int) $u->branch_id : null,
                'branchName' => $u->branch_name,
            ], $users),
            'perms'      => (object) $rows,
            'permGroups' => W::permGroups(),
            'presets'    => W::permPresets(),
            'branches'   => array_map(fn ($b): array => ['id' => (int) $b->id, 'name' => $b->name],
                DB::select('SELECT id, name FROM branches ORDER BY name')),
        ]);
    }

    /**
     * PUT /api/pilot-accounting/acl — {userId, keys:{...}, branches:[ids]}
     *
     * ⚠️ القيمة `true` بس هي اللي بتتخزّن. المفتاح المقفول **بيختفي**
     * مش بيتخزّن `false` — ده اللي بيخلّي `=== true` في `can()` كافية.
     *
     * والمفاتيح بتتفلتر على `W::permKeys()`: مفتاح مش معروف بيتترمي
     * بدل ما يتخزّن ويفضل قاعد في الجدول بلا معنى.
     */
    public function aclSave(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        if ($actor->role !== 'admin') {
            throw ApiException::forbidden('شاشة الصلاحيات للإدارة بس');
        }

        $b   = $request->json()->all();
        $uid = (int) ($b['userId'] ?? 0);
        if ($uid <= 0) {
            throw new ApiException('اختار المستخدم');
        }

        $u = DB::select('SELECT id, role FROM users WHERE id = ? LIMIT 1', [$uid])[0] ?? null;
        if (! $u) {
            throw ApiException::notFound('المستخدم غير موجود');
        }
        if ((string) $u->role === 'admin') {
            throw new ApiException('الأدمن عنده كل الصلاحيات أصلًا');
        }

        $known = array_flip(W::permKeys());
        $keys  = [];
        foreach ((array) ($b['keys'] ?? []) as $k => $v) {
            $k = (string) $k;
            if (isset($known[$k]) && ($v === true || $v === 1 || $v === '1')) {
                $keys[$k] = true;
            }
        }

        $branches = [];
        foreach ((array) ($b['branches'] ?? []) as $x) {
            $n = (int) $x;
            if ($n > 0) {
                $branches[] = $n;
            }
        }
        $branches = array_values(array_unique($branches));

        DB::insert(
            'INSERT INTO pilot_acct_perms (user_id, perm_keys, branches, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE perm_keys = VALUES(perm_keys),
                 branches = VALUES(branches), updated_by = VALUES(updated_by)',
            [$uid, json_encode((object) $keys, JSON_UNESCAPED_UNICODE),
                $branches ? implode(',', $branches) : null, $actor->username]
        );

        return ApiResponse::out(['ok' => true, 'userId' => $uid,
                                 'keys' => (object) $keys, 'branches' => $branches]);
    }

    /**
     * DELETE /api/pilot-accounting/acl/{userId} — يرجّع للافتراضي.
     *
     * مسح الصف **مش** بيقفل عليه — بيرجّعه لـ«كل حاجة» زي ما هو
     * دلوقتي. الشاشة بتقول ده صراحةً عشان محدش يفتكر إن المسح قفل.
     */
    public function aclReset(Request $request, string $userId): JsonResponse
    {
        $actor = $request->actorOrFail();
        if ($actor->role !== 'admin') {
            throw ApiException::forbidden('شاشة الصلاحيات للإدارة بس');
        }
        DB::delete('DELETE FROM pilot_acct_perms WHERE user_id = ?', [(int) $userId]);

        return ApiResponse::out(['ok' => true]);
    }

    /**
     * صلاحيات الشخص ده: `{keys, branches, full}`.
     *
     * الأدمن بياخد كل حاجة دايمًا ومابيتقريش له صف — قرار زي دمشق:
     * صاحب النظام مايقدرش يقفل على نفسه بالغلط.
     *
     * 🔴 اللي مالوش صف بياخد **كل حاجة** كمان. مش سهو: البرنامج
     * شغال لايف من غير صفوف، والمنع الافتراضي كان هيقفله على
     * المحاسب ومشرفي الفروع في نص يوم شغل. الصف = تضييق.
     */
    private function aclOf(Actor $actor): array
    {
        if ($actor->role === 'admin') {
            return ['keys' => W::fullPermKeys(), 'branches' => [], 'full' => true];
        }

        $uid = $actor->userId;
        $row = $uid !== null
            ? (DB::select('SELECT perm_keys, branches FROM pilot_acct_perms WHERE user_id = ? LIMIT 1', [$uid])[0] ?? null)
            : null;

        if (! $row) {
            /* مالوش صف = اللي بيعمله النهاردة بالظبط: كل حاجة ماعدا
               المحجوز للإدارة (السلف والقفل والإعدادات). */
            return ['keys' => W::defaultPermKeys(), 'branches' => [], 'full' => false];
        }

        $row  = (array) $row;
        $keys = [];
        $d    = json_decode((string) ($row['perm_keys'] ?? ''), true);
        if (is_array($d)) {
            foreach ($d as $k => $v) {
                if ($v === true) {
                    $keys[(string) $k] = true;
                }
            }
        }

        $branches = [];
        foreach (explode(',', (string) ($row['branches'] ?? '')) as $b) {
            $b = (int) trim($b);
            if ($b > 0) {
                $branches[] = $b;
            }
        }

        return ['keys' => $keys, 'branches' => array_values(array_unique($branches)), 'full' => false];
    }

    /** المقارنة `=== true` مقصودة — المفتاح الغايب أو أي قيمة تانية = ممنوع */
    private function can(array $acl, string $key): bool
    {
        return ($acl['keys'][$key] ?? null) === true;
    }

    /** بيرمي 403 برسالة بتقول المفتاح الناقص إيه بالعربي */
    private function need(array $acl, string $key, string $what): void
    {
        if (! $this->can($acl, $key)) {
            throw ApiException::forbidden('مالكش صلاحية ' . $what . ' — كلّم الإدارة');
        }
    }

    private function pilotInScope(Actor $actor, int $pilotId): array
    {
        $p = DB::select('SELECT id, name, assigned_branch_id FROM pilots WHERE id = ?', [$pilotId])[0] ?? null;
        if (! $p) {
            throw ApiException::notFound('الطيار غير موجود');
        }
        $p = (array) $p;
        if ($actor->role === 'branch' && (int) ($p['assigned_branch_id'] ?? 0) !== (int) ($actor->branchId ?? -1)) {
            throw ApiException::forbidden('الطيار ده مش في فرعك');
        }

        /* 🔐 قصّ الفروع من الصلاحيات. مشرف الفرع مقفول بدوره فوق،
           إنما المحاسب ممكن يتحدّدله فروع بعينها من شاشة الصلاحيات.
           القايمة الفاضية = كل الفروع. */
        $acl = $this->aclOf($actor);
        if ($acl['branches'] && ! in_array((int) ($p['assigned_branch_id'] ?? 0), $acl['branches'], true)) {
            throw ApiException::forbidden('الطيار ده في فرع مش مسموحلك بيه');
        }

        return $p;
    }

    /* ═══════════════════════════════════════════════════════════
       👥 تقفيل الموظفين — الحضور من جلسات المتصفح

       نفس نموذج تقفيل الطيارين حرفيًا، بفرقين:
       ① المصدر `attendance_sessions` (بيتكتب تلقائيًا من فتح صفحة
          الفرع/الكول سنتر/الإدارة) بدل الورديات والأوردرات.
       ② اليوم = اليوم الميلادي بتوقيت القاهرة (نفس شاشة الحضور
          الموجودة في الإدارة) — مش يوم الطيارين التجاري (٩ص).

       الحسابات نفسها (`dayRow`/`monthTotals`) — معادلات دمشق اللي
       اتنقلت للطيارين بتشتغل هنا زي ما هي: الموظف = طيار بلا
       أوردرات (عمولة صفر وخدمة صفر).
    ═══════════════════════════════════════════════════════════ */

    /** الأدوار اللي بتدخل تقفيلة الموظفين — الأدمن مستثنى بطلب صريح */
    private const STAFF_ROLES = ['branch', 'callcenter', 'accountant', 'hr', 'pilot_supervisor'];

    /** GET /api/pilot-accounting/staff-month?month=&branchId= */
    public function staffMonth(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $acl   = $this->aclOf($actor);
        $this->need($acl, 'page.staff', 'على تقفيل الموظفين');

        $ym  = $this->monthArg((string) $request->query('month', ''));
        $set = $this->settings();

        $branchId = $request->query('branchId');
        $branchId = $branchId !== null && $branchId !== '' ? (int) $branchId : null;
        if ($actor->role === 'branch') {
            $branchId = (int) ($actor->branchId ?? 0);   // مشرف الفرع مقفول على فرعه
        }
        if ($acl['branches'] && $branchId !== null && ! in_array($branchId, $acl['branches'], true)) {
            throw ApiException::forbidden('الفرع ده مش مسموحلك بيه');
        }

        $ph  = implode(',', array_fill(0, count(self::STAFF_ROLES), '?'));
        $sql = "SELECT u.id, u.username, u.name, u.role, u.branch_id, b.name AS branch_name,
                       u.hour_rate, u.monthly_salary, u.paid_leave_days
                  FROM users u LEFT JOIN branches b ON b.id = u.branch_id
                 WHERE u.blocked = 0 AND u.role IN ({$ph})";
        $args = self::STAFF_ROLES;
        /* 🍽️ مشرفي روح دمشق قطاع لوحده — مش موظفين عند الدهشان فمايدخلوش التقفيلة */
        $rd = AuthController::damascusOnlyUserIds();
        if ($rd) {
            $sql .= ' AND u.id NOT IN (' . implode(',', array_fill(0, count($rd), '?')) . ')';
            $args = array_merge($args, $rd);
        }
        if ($branchId !== null) {
            $sql .= ' AND u.branch_id = ?';
            $args[] = $branchId;
        } elseif ($acl['branches']) {
            /* الصلاحية محدودة بفروع — الموظف بلا فرع (كول سنتر مثلًا)
               بيختفي، وده مقصود: مالوش فرع = مش من فروعه. */
            $sql .= ' AND u.branch_id IN (' . implode(',', array_fill(0, count($acl['branches']), '?')) . ')';
            $args = array_merge($args, $acl['branches']);
        }
        $sql .= ' ORDER BY u.role, u.username';
        $staff = array_map(fn ($r) => (array) $r, DB::select($sql, $args));

        $nd      = W::daysInMonth($ym);
        /* اليوم التجاري بقى للنظام كله (بلاغ 2026-09-02) — الموظفين زي
           الطيارين: مشرف شغال لـ5 الفجر يومه لسه ماخلصش. */
        $counted = W::countedDays($ym, $set['dayStartHour']);
        $auto    = $this->staffAutoMatrix(array_column($staff, 'username'), $ym);
        $entries = $this->staffEntriesOf($ym, array_map(fn ($u) => (int) $u['id'], $staff));

        /* سعر الساعة الافتراضي بيسري على الموظفين كمان (قرار صاحب النظام
           2026-09-04: «خلي الموظفين ياخدوا السعر الافتراضي لو سعرهم فاضي»).
           كان مقفول قبل كده عمدًا — دلوقتي نفس قاعدة الطيارين: سعره لو
           أكبر من صفر، وإلا الافتراضي من الإعدادات. */
        $staffSet = $set;

        $out = [];
        foreach ($staff as $u) {
            $uid = (int) $u['id'];
            $days = [];
            for ($d = 1; $d <= $nd; $d++) {
                $e     = $entries[$uid][$d] ?? [];
                $perms = $e['perms'] ?? ($auto[$u['username']][$d]['perms'] ?? []);
                $days[] = W::dayRow($d, $auto[$u['username']][$d] ?? [], $e, $perms);
            }

            /* الموظف = طيار بلا أوردرات — نفس معادلات دمشق بالظبط */
            $totals = W::monthTotals($days, [
                'commission_type'  => 'fixed',
                'commission_value' => 0,
                'hour_rate'        => (float) $u['hour_rate'],
                'monthly_salary'   => (float) $u['monthly_salary'],
                'paid_leave_days'  => (int) $u['paid_leave_days'],
            ], [
                'settings'     => $staffSet,
                'countedDays'  => $counted,
                'shiftHours'   => $set['shiftHours'],
                'deferredDue'  => 0.0,
                'deferredLeft' => 0.0,
            ]);

            if (! $acl['full']) {
                $days   = array_map(fn (array $r): array => W::filterDay($r, $acl['keys']), $days);
                $totals = W::filterTotals($totals, $acl['keys']);
            }

            $out[] = [
                'userId'     => $uid,
                'username'   => $u['username'],
                'name'       => $u['name'],
                'role'       => $u['role'],
                'branchId'   => $u['branch_id'] !== null ? (int) $u['branch_id'] : null,
                'branchName' => $u['branch_name'],
                // السعر الساري فعلًا (بتاعه أو الافتراضي) — والواجهة بتعرضه في ملخص الموظف
                'hourRate'   => round(W::hourRateOf(['hour_rate' => $u['hour_rate']], $set), 2),
                'ownHourRate' => round((float) $u['hour_rate'], 2),
                'monthlySalary'  => round((float) $u['monthly_salary'], 2),
                'paidLeaveDays'  => (int) $u['paid_leave_days'],
                'days'       => $days,
                'totals'     => $totals,
            ];
        }

        return ApiResponse::out([
            'ok'          => true,
            'serverNow'   => WireTime::toWire(WireTime::nowDb()),
            'month'       => $ym,
            'branchId'    => $branchId,
            'settings'    => $set,
            'locked'      => $this->monthLocked($ym, $branchId),
            'daysInMonth' => $nd,
            'countedDays' => $counted,
            'staff'       => $out,
            'acl'         => ['keys' => (object) $acl['keys'], 'branches' => $acl['branches'],
                              'full' => $acl['full'], 'isAdmin' => $actor->role === 'admin'],
        ]);
    }

    /**
     * حضور الشهر من جلسات المتصفح: [username][day] = {in, out, hours, perms}.
     *
     * `in` أول حضور و`out` آخر انصراف (أو آخر نبضة للجلسة المفتوحة —
     * نفس اللي cron الإغلاق التلقائي بيقفل عليه). `hours` هي الـspan
     * الكامل، والفجوات بين الجلسات بتطلع `perms` — فـ`dayRow` لما
     * يطرحها بيرجع مجموع الجلسات الفعلية، والاستئذان باين في عموده.
     */
    private function staffAutoMatrix(array $usernames, string $ym): array
    {
        if (! $usernames) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($usernames), '?'));
        $rows = DB::select(
            "SELECT username, session_date, check_in, check_out, last_seen
               FROM attendance_sessions
              WHERE session_date LIKE ? AND username IN ({$ph})
              ORDER BY username, check_in",
            array_merge([$ym . '-%'], $usernames)
        );

        /* اليوم التجاري بقى للنظام كله (بلاغ 2026-09-02): session_date
           بيتكتب بمفتاح اليوم التجاري (BizDay في FinanceController)،
           فجلسة مشرف من 11 مساءً لـ2 فجرًا كلها صف يوم واحد. الحساب هنا
           بـ«دقايق من بداية اليوم التجاري» مش دقايق الساعة — من غير كده
           02:00 كانت بتطلع «أقل» من 23:00 والمدى بيطلع صفر. العرض (in/
           out/perms) بيفضل بساعة الساعة الحقيقية. */
        $ds = $this->settings()['dayStartHour'];
        $bizMin = function (?string $hm) use ($ds): int {
            $m = W::parseHm($hm) ?? 0;

            return ($m - $ds * 60 + 1440) % 1440;
        };

        $sessions = [];
        foreach ($rows as $r) {
            $day = (int) substr((string) $r->session_date, 8, 2);
            $in  = W::bizMoment((string) $r->check_in, 0);
            $out = W::bizMoment((string) ($r->check_out ?? $r->last_seen ?? $r->check_in), 0);
            if ($in === null || $out === null) {
                continue;
            }
            $sessions[$r->username][$day][] = ['in' => $in['hm'], 'out' => $out['hm']];
        }

        $matrix = [];
        foreach ($sessions as $uname => $days) {
            foreach ($days as $day => $list) {
                /* مترتبين بـcheck_in من الاستعلام */
                $first = $list[0]['in'];
                $last  = $first;
                $perms = [];
                foreach ($list as $i => $s) {
                    if ($bizMin($s['out']) >= $bizMin($last)) {
                        $last = $s['out'];
                    }
                    if ($i > 0) {
                        $prevOut = $list[$i - 1]['out'];
                        /* فجوة حقيقية بس — دقيقة فأكتر */
                        if ($bizMin($s['in']) > $bizMin($prevOut)) {
                            $perms[] = ['out' => $prevOut, 'in' => $s['in']];
                        }
                    }
                }
                $span = ($bizMin($last) - $bizMin($first)) / 60;
                $matrix[$uname][$day] = [
                    'in'    => $first,
                    'out'   => $last,
                    'hours' => round(max(0, $span), 2),
                    'perms' => $perms,
                ];
            }
        }

        return $matrix;
    }

    /** صفوف الشيت اليدوية + فترات استئذانها — مرآة entriesOf */
    private function staffEntriesOf(string $ym, array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select(
            "SELECT * FROM staff_day_entries WHERE month = ? AND user_id IN ({$ph})",
            array_merge([$ym], $ids)
        );
        $out = [];
        $byEntry = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $out[(int) $r['user_id']][(int) $r['day']] = $r;
            $byEntry[(int) $r['id']] = [(int) $r['user_id'], (int) $r['day']];
        }
        if ($byEntry) {
            $eph = implode(',', array_fill(0, count($byEntry), '?'));
            foreach (DB::select(
                "SELECT * FROM staff_day_perms WHERE entry_id IN ({$eph}) ORDER BY id",
                array_keys($byEntry)
            ) as $p) {
                $p = (array) $p;
                [$uid, $day] = $byEntry[(int) $p['entry_id']];
                $out[$uid][$day]['perms'][] = ['out' => $p['perm_out'], 'in' => $p['perm_in']];
            }
        }

        return $out;
    }

    /** الموظف موجود ومن أدوار التقفيلة وفي نطاق الطالب — مرآة pilotInScope */
    private function staffInScope(Actor $actor, int $userId): array
    {
        $u = DB::select('SELECT id, username, role, branch_id FROM users WHERE id = ? AND blocked = 0', [$userId])[0] ?? null;
        if (! $u || ! in_array((string) $u->role, self::STAFF_ROLES, true)) {
            throw ApiException::notFound('الموظف غير موجود في التقفيلة');
        }
        $u = (array) $u;
        if ($actor->role === 'branch' && (int) ($u['branch_id'] ?? 0) !== (int) ($actor->branchId ?? -1)) {
            throw ApiException::forbidden('الموظف ده مش في فرعك');
        }
        $acl = $this->aclOf($actor);
        if ($acl['branches'] && ! in_array((int) ($u['branch_id'] ?? 0), $acl['branches'], true)) {
            throw ApiException::forbidden('الموظف ده في فرع مش مسموحلك بيه');
        }

        return $u;
    }

    /**
     * POST /api/pilot-accounting/staff-entry — {month, userId, day, field, value}
     * نفس عقد entrySave: القيمة الفاضية بتمسح التدخّل ويرجع التلقائي.
     */
    public function staffEntrySave(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $b     = $request->json()->all();

        $ym    = $this->monthArg((string) ($b['month'] ?? ''));
        $uid   = (int) ($b['userId'] ?? 0);
        $day   = (int) ($b['day'] ?? 0);
        $field = (string) ($b['field'] ?? '');

        /* خانات الموظف — مافيش أوردرات ولا خدمة هنا */
        $COLS = [
            'in'    => 'time_in_override',
            'out'   => 'time_out_override',
            'hours' => 'hours_override',
            'adv'   => 'advance_extra',
            'ded'   => 'deduction_extra',
            'bonus' => 'bonus_extra',
            'note'  => 'note',
        ];
        if (! isset($COLS[$field])) {
            throw new ApiException('خانة مش معروفة');
        }
        if ($day < 1 || $day > W::daysInMonth($ym)) {
            throw new ApiException('اليوم مش في الشهر ده');
        }

        $acl = $this->aclOf($actor);
        $this->need($acl, 'act.edit', 'تعديل الخانات');
        $this->need($acl, 'col.' . $field, 'الكتابة في الخانة دي');

        $u = $this->staffInScope($actor, $uid);
        $this->assertUnlocked($ym, $u['branch_id'] !== null ? (int) $u['branch_id'] : null);

        $raw = $b['value'] ?? null;
        $val = null;
        if ($raw !== null && $raw !== '') {
            if (in_array($field, ['in', 'out'], true)) {
                if (W::parseHm((string) $raw) === null) {
                    throw new ApiException('الوقت لازم يكون بصيغة HH:MM');
                }
                $val = trim((string) $raw);
            } elseif ($field === 'note') {
                $val = mb_substr(trim((string) $raw), 0, 2000);
            } else {
                $val = round((float) $raw, 2);
            }
        }

        $now = WireTime::nowDb();
        DB::statement(
            "INSERT INTO staff_day_entries (month, user_id, day, `{$COLS[$field]}`, updated_by, updated_at, created_at)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE `{$COLS[$field]}` = VALUES(`{$COLS[$field]}`),
                                     updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
            [$ym, $uid, $day, $val, $actor->username, $now, $now]
        );

        if ($val === null) {
            $this->staffCleanupEntry($ym, $uid, $day);
        }

        return ApiResponse::out(['ok' => true, 'cleared' => $val === null]);
    }

    /** الصف الفاضي بيتحذف — «فيه صف» لازم تفضل تدل على «حد تدخّل» */
    private function staffCleanupEntry(string $ym, int $uid, int $day): void
    {
        $r = DB::select(
            'SELECT * FROM staff_day_entries WHERE month = ? AND user_id = ? AND day = ?',
            [$ym, $uid, $day]
        )[0] ?? null;
        if (! $r) {
            return;
        }
        $r = (array) $r;
        foreach (['time_in_override', 'time_out_override', 'hours_override',
                  'advance_extra', 'deduction_extra', 'bonus_extra'] as $c) {
            if ($r[$c] !== null) {
                return;
            }
        }
        if (trim((string) ($r['note'] ?? '')) !== '') {
            return;
        }
        if (DB::select('SELECT id FROM staff_day_perms WHERE entry_id = ? LIMIT 1', [(int) $r['id']])) {
            return;
        }
        DB::delete('DELETE FROM staff_day_entries WHERE id = ?', [(int) $r['id']]);
    }

    /**
     * POST /api/pilot-accounting/staff-perms — {month, userId, day, periods:[{out,in}]}
     * بيستبدل فترات الاستئذان اليدوية لليوم كلها — نفس عقد permsSave.
     */
    public function staffPermsSave(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();

        $acl = $this->aclOf($actor);
        $this->need($acl, 'act.edit', 'تعديل الخانات');
        if (! $this->can($acl, 'col.bout') && ! $this->can($acl, 'col.bin')) {
            throw ApiException::forbidden('مالكش صلاحية على خانات الاستئذان — كلّم الإدارة');
        }
        $b = $request->json()->all();

        $ym  = $this->monthArg((string) ($b['month'] ?? ''));
        $uid = (int) ($b['userId'] ?? 0);
        $day = (int) ($b['day'] ?? 0);
        if ($day < 1 || $day > W::daysInMonth($ym)) {
            throw new ApiException('اليوم مش في الشهر ده');
        }
        $u = $this->staffInScope($actor, $uid);
        $this->assertUnlocked($ym, $u['branch_id'] !== null ? (int) $u['branch_id'] : null);

        $periods = is_array($b['periods'] ?? null) ? $b['periods'] : [];
        $clean = [];
        foreach ($periods as $p) {
            $o = trim((string) ($p['out'] ?? ''));
            $i = trim((string) ($p['in'] ?? ''));
            if ($o === '' && $i === '') {
                continue;
            }
            if (($o !== '' && W::parseHm($o) === null) || ($i !== '' && W::parseHm($i) === null)) {
                throw new ApiException('وقت الاستئذان لازم يكون بصيغة HH:MM');
            }
            $clean[] = [$o ?: null, $i ?: null];
        }
        if (count($clean) > 20) {
            throw new ApiException('أقصى عدد فترات استئذان لليوم 20');
        }

        DB::transaction(function () use ($ym, $uid, $day, $clean, $actor): void {
            $now = WireTime::nowDb();
            DB::statement(
                'INSERT INTO staff_day_entries (month, user_id, day, updated_by, updated_at, created_at)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
                [$ym, $uid, $day, $actor->username, $now, $now]
            );
            $eid = (int) DB::select(
                'SELECT id FROM staff_day_entries WHERE month = ? AND user_id = ? AND day = ?',
                [$ym, $uid, $day]
            )[0]->id;

            DB::delete('DELETE FROM staff_day_perms WHERE entry_id = ?', [$eid]);
            foreach ($clean as [$o, $i]) {
                DB::insert(
                    'INSERT INTO staff_day_perms (entry_id, perm_out, perm_in) VALUES (?,?,?)',
                    [$eid, $o, $i]
                );
            }
        });

        $this->staffCleanupEntry($ym, $uid, $day);

        return ApiResponse::out(['ok' => true, 'count' => count($clean)]);
    }
}
