<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use App\Support\ApiResponse;
use App\Support\BizDay;
use App\Support\WireTime;
use App\Http\Controllers\Api\AuthController;
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
            /* حدود توصيات التقارير (المرحلة ٤) — الافتراضي في الـWire وبتتعدّل من الإعدادات */
            'reportThresholds' => W::reportThresholds(is_array($v['reportThresholds'] ?? null) ? $v['reportThresholds'] : null),
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
            'reportThresholds' => array_key_exists('reportThresholds', $in) && is_array($in['reportThresholds'])
                ? W::reportThresholds($in['reportThresholds']) : $cur['reportThresholds'],
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

        /* 📸 الشهر المقفول بيتعرض من لقطته — مش من الحساب الحي */
        if ($this->monthLocked($ym, $branchId) && ($snap = $this->snapshotFor($ym, $branchId)) !== null) {
            return $this->monthFromSnapshot($snap, $actor, $acl, $branchId, $pilotFilter);
        }

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
            'payouts'     => $this->payoutsOf($ym, 'pilot'),
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
        $shiftHours = (float) $this->settings()['shiftHours'];
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
                /* 🔴 وردية مقطوعة (فضلت مفتوحة أكتر من LONG_SHIFT_HOURS): محدش اشتغل ٥٧
                   ساعة — بتتحسب بساعات الوردية وبتتعلّم عشان المشرف يراجع ويعدّل. */
                if ($h > W::LONG_SHIFT_HOURS) {
                    $cell['longShift'] = true;
                    $h = min($h, $shiftHours);
                }
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

        /* ── 1ب) 💵 اللي سلّمه الطيار للخزنة فعلًا (طلب صاحب النظام 2026-09-04:
           «المفروض الشغل يظهر في تقفيلة الطيارين») — حركات الخزنة المربوطة
           بالطيار: تحصيل الأوردرات وردّ العهدة داخلين، وعمولته «في نفس اليوم»
           خارجة. الصافي = اللي دخل الخزنة من إيده في يوم الشغل ده. الحركة
           بتتنسب ليوم شغلها بنفس قاعدة الورديات (bizMoment). */
        foreach (DB::select(
            "SELECT related_pilot_id AS pilot_id, type, amount, created_at
               FROM cash_transactions
              WHERE related_pilot_id IN ({$ph}) AND type IN ('in', 'out')
                AND created_at >= ? AND created_at < ?",
            array_merge($ids, [$from, $to])
        ) as $ct) {
            $ct = (array) $ct;
            $bm = W::bizMoment($ct['created_at'], $ds);
            if (! $bm) {
                continue;
            }
            $day  = (int) substr($bm['date'], 8, 2);
            $cell = &$m[(int) $ct['pilot_id']][$day];
            $cell ??= self::emptyCell();
            $cell['handed'] += $ct['type'] === 'in' ? (float) $ct['amount'] : -(float) $ct['amount'];
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
            /* ضغطة بالغلط: موافقة وإنهاء في ثواني — مش استئذان (مراجعة 2026-09-05: ١٨ من ٣٥) */
            if ((strtotime($lr['ended_at'] . ' UTC') - strtotime($lr['responded_at'] . ' UTC')) / 60 < W::MIN_PERM_MINUTES) {
                continue;
            }
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
                $m[$pid][$day]['handed']    = round($c['handed'], 2);
            }
        }

        return $m;
    }

    private static function emptyCell(): array
    {
        return ['in' => null, 'out' => null, 'hours' => 0.0, 'orders' => 0, 'svc' => 0.0,
                'psvc' => 0.0, 'adv' => 0.0, 'ded' => 0.0, 'bonus' => 0.0,
                // اللي سلّمه للخزنة فعلًا في اليوم (تحصيل + عهدة − عمولته)
                'handed' => 0.0,
                // المرحّل للشهر — منفصل عن المعروض
                'psvcCarry' => 0.0, 'advCarry' => 0.0, 'dedCarry' => 0.0, 'bonusCarry' => 0.0,
                'commMonthly' => false,
                'perms' => [], 'shiftIds' => [], 'openShift' => false, 'longShift' => false];
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
        /* اسم الخزنة يظهر في الشاشة — «من خزنة X» — عشان المحاسب يعرف الفلوس خرجت منين */
        $storeIds = [];
        foreach ($deferred as $recs) {
            foreach ($recs as $r) {
                if (! empty($r['store_id'])) {
                    $storeIds[(int) $r['store_id']] = true;
                }
            }
        }
        $storeNames = [];
        if ($storeIds) {
            $ph = implode(',', array_fill(0, count($storeIds), '?'));
            foreach (DB::select("SELECT id, name FROM cash_stores WHERE id IN ({$ph})", array_keys($storeIds)) as $st) {
                $storeNames[(int) $st->id] = (string) $st->name;
            }
        }
        $out = [];
        foreach ($deferred as $pid => $recs) {
            foreach ($recs as $r) {
                $c = W::deferredForMonth($r, $ym, $r['_pays'] ?? []);
                $sid = ! empty($r['store_id']) ? (int) $r['store_id'] : null;
                $out[] = [
                    'id'          => (int) $r['id'],
                    'pilotId'     => (int) $pid,
                    'storeId'     => $sid,
                    'storeName'   => $sid !== null ? ($storeNames[$sid] ?? null) : null,
                    'txnId'       => ! empty($r['txn_id']) ? (int) $r['txn_id'] : null,
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

        $actor   = $request->actorOrFail();
        $storeId = (int) ($b['cashStoreId'] ?? 0);
        $id      = (int) ($b['id'] ?? 0);
        if ($id > 0) {
            $cur = DB::selectOne('SELECT pilot_id, amount, txn_id FROM pilot_deferred_advances WHERE id = ?', [$id]);
            if (! $cur) {
                throw ApiException::notFound('السلفة غير موجودة');
            }
            /* الفلوس خرجت من الخزنة بمبلغ معيّن لطيار معيّن — تغييرهم هيخلّي الخزنة تكذب.
               القسط والبداية والملاحظة عادي. عايز تغيّر المبلغ؟ الغيها (بترجع للخزنة) وسجّلها تاني. */
            if ($cur->txn_id !== null && (abs((float) $cur->amount - $amount) > 0.004 || (int) $cur->pilot_id !== $pilotId)) {
                throw new ApiException('السلفة دي خرجت من الخزنة فعلًا — مينفعش تغيّر مبلغها أو طيارها. الغيها (الفلوس بترجع للخزنة) وسجّلها من جديد', 409);
            }
            DB::update(
                'UPDATE pilot_deferred_advances
                    SET pilot_id = ?, advance_date = ?, amount = ?, monthly = ?, start_month = ?, note = ?
                  WHERE id = ?',
                [$pilotId, $date, $amount, $monthly, $start, $note ?: null, $id]
            );

            return ApiResponse::out(['ok' => true, 'id' => $id]);
        }

        if ($storeId <= 0) {
            /* من غير خزنة: تسجيل محاسبي بس (سلفة قديمة اتصرفت قبل النظام مثلًا) */
            DB::insert(
                'INSERT INTO pilot_deferred_advances
                   (pilot_id, advance_date, amount, monthly, start_month, note, created_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$pilotId, $date, $amount, $monthly, $start, $note ?: null, $actor->username, WireTime::nowDb()]
            );

            return ApiResponse::out(['ok' => true, 'id' => (int) DB::getPdo()->lastInsertId()]);
        }

        /* من خزنة: السلفة فلوس خرجت فعلًا — حركة منصرف بنفس اللحظة، والسجل بيشاور عليها */
        $store = DB::selectOne('SELECT id, name, branch_id FROM cash_stores WHERE id = ?', [$storeId]);
        if (! $store) {
            throw ApiException::notFound('الخزنة غير موجودة');
        }
        if ($actor->role === 'branch' && (int) ($store->branch_id ?? 0) !== (int) ($actor->branchId ?? 0)) {
            throw ApiException::forbidden('الخزنة دي مش على فرعك');
        }
        $pilot = DB::selectOne('SELECT name, assigned_branch_id FROM pilots WHERE id = ?', [$pilotId]);
        $now   = WireTime::nowDb();
        $id = DB::transaction(function () use ($storeId, $amount, $pilotId, $pilot, $date, $monthly, $start, $note, $actor, $now): int {
            $sRow = DB::select('SELECT id, balance FROM cash_stores WHERE id = ? FOR UPDATE', [$storeId])[0];
            if ((float) $sRow->balance < $amount) {
                throw new ApiException('رصيد الخزنة (' . number_format((float) $sRow->balance, 2) . ') مايكفيش لسلفة ' . number_format($amount, 2));
            }
            DB::update('UPDATE cash_stores SET balance = balance - ? WHERE id = ?', [$amount, $storeId]);
            DB::insert(
                'INSERT INTO cash_transactions (store_id, type, amount, reason, notes, related_pilot_id, branch_id, created_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [$storeId, 'out', $amount, mb_substr('سلفة مؤجلة: ' . ($pilot->name ?? $pilotId), 0, 190),
                 $note !== '' ? mb_substr($note, 0, 500) : null, $pilotId,
                 $pilot && $pilot->assigned_branch_id !== null ? (int) $pilot->assigned_branch_id : null, $actor->username, $now]
            );
            $txnId = (int) DB::getPdo()->lastInsertId();
            DB::insert(
                'INSERT INTO pilot_deferred_advances
                   (pilot_id, advance_date, amount, monthly, start_month, note, store_id, txn_id, created_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$pilotId, $date, $amount, $monthly, $start, $note ?: null, $storeId, $txnId, $actor->username, $now]
            );

            return (int) DB::getPdo()->lastInsertId();
        });

        return ApiResponse::out(['ok' => true, 'id' => $id, 'fromStore' => true]);
    }

    /** DELETE /api/pilot-accounting/deferred/{id} — الإدارة بس */
    public function deferredDelete(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $this->need($this->aclOf($actor), 'act.deferred', 'على السلف المؤجلة');
        $aid = (int) $id;
        DB::transaction(function () use ($aid, $actor): void {
            $rec = DB::select('SELECT a.*, p.name AS pilot_name, p.assigned_branch_id
                                 FROM pilot_deferred_advances a LEFT JOIN pilots p ON p.id = a.pilot_id
                                WHERE a.id = ? FOR UPDATE', [$aid])[0] ?? null;
            if (! $rec) {
                throw ApiException::notFound('السلفة غير موجودة');
            }
            /* السلفة اللي خرجت من الخزنة مابتتمسحش في صمت — الفلوس بترجع بحركة وارد
               مكتوب عليها إنها إلغاء، فسجل الخزنة بيحكي القصة كاملة. */
            if ($rec->txn_id !== null && $rec->store_id !== null) {
                DB::select('SELECT id FROM cash_stores WHERE id = ? FOR UPDATE', [(int) $rec->store_id]);
                DB::update('UPDATE cash_stores SET balance = balance + ? WHERE id = ?', [(float) $rec->amount, (int) $rec->store_id]);
                DB::insert(
                    'INSERT INTO cash_transactions (store_id, type, amount, reason, notes, related_pilot_id, branch_id, created_by, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?)',
                    [(int) $rec->store_id, 'in', (float) $rec->amount,
                     mb_substr('إلغاء سلفة مؤجلة #' . $aid . ': ' . ($rec->pilot_name ?? $rec->pilot_id), 0, 190),
                     null, (int) $rec->pilot_id, $rec->assigned_branch_id !== null ? (int) $rec->assigned_branch_id : null,
                     $actor->username, WireTime::nowDb()]
                );
            }
            DB::delete('DELETE FROM pilot_deferred_advances WHERE id = ?', [$aid]);
        });

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
       📦 أوردرات الطيار في يوم — عشان العمولة تتحط على كل أوردر لوحده
       (طلب صاحب النظام 2026-09-04: «إذا ضغطت على أي موظف تظهر صفحة بها
       كل الأوردرات لكي أستطيع وضع العمولة لكل أوردر»).
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/pilot-accounting/pilot-orders?month=&pilotId=&day=
     *
     * أوردرات الطيار في اليوم التجاري (المسلَّمة والمرتجعة) مع عمولة كل
     * واحد: التلقائية من معادلة الطيار، والـoverride المكتوب لو موجود.
     * الكتابة نفسها على مسار pilot-commission-adjustments الموجود — مافيش
     * معادلة تانية هنا: نفس Commission::forPilot اللي التقفيلة بتحسب بيها.
     */
    public function pilotOrders(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $ym    = $this->monthArg((string) $request->query('month', ''));
        $pid   = (int) $request->query('pilotId', 0);
        $day   = (int) $request->query('day', 0);
        if ($day < 1 || $day > W::daysInMonth($ym)) {
            throw new ApiException('اليوم مش في الشهر ده');
        }
        $acl = $this->aclOf($actor);
        if (! $this->can($acl, 'page.daily') && ! $this->can($acl, 'page.pilot')) {
            throw ApiException::forbidden('مالكش صلاحية عرض الشيت — كلّم الإدارة');
        }
        $this->pilotInScope($actor, $pid);
        $pilot = (array) DB::selectOne('SELECT id, name, commission_type, commission_value FROM pilots WHERE id = ?', [$pid]);

        $set  = $this->settings();
        $ds   = $set['dayStartHour'];
        $date = sprintf('%s-%02d', $ym, $day);
        [$from, $to] = W::bizWindowUtc($date, $ds);

        $orders = array_map(fn ($r) => (array) $r, DB::select(
            "SELECT o.id, o.order_num, o.status, o.delivered_at, o.undelivered_at, o.total_delivery_price,
                    o.pieces_count, o.sender_name,
                    d.receiver_name, d.zone_name
               FROM orders o
               LEFT JOIN order_deliveries d ON d.order_id = o.id AND d.parcel_no = 1
              WHERE o.pilot_id = ?
                AND ((o.status = 'delivered' AND o.delivered_at >= ? AND o.delivered_at < ?)
                  OR (o.status = 'undelivered' AND o.undelivered_at >= ? AND o.undelivered_at < ?))
              ORDER BY COALESCE(o.delivered_at, o.undelivered_at), o.id",
            [$pid, $from, $to, $from, $to]
        ));

        $ids = array_map(fn ($o) => (int) $o['id'], $orders);
        $ovr = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (DB::select("SELECT id, order_id, amount, reason, paid_amount, created_by FROM pilot_commission_adjustments WHERE kind = 'override' AND order_id IN ({$ph})", $ids) as $a) {
                $ovr[(int) $a->order_id] = (array) $a;
            }
        }
        $extras = array_map(fn ($a) => [
            'id' => (int) $a->id, 'amount' => round((float) $a->amount, 2), 'reason' => (string) $a->reason,
            'paidAmount' => round((float) $a->paid_amount, 2), 'createdBy' => $a->created_by,
        ], DB::select("SELECT id, amount, reason, paid_amount, created_by FROM pilot_commission_adjustments
                        WHERE kind = 'extra' AND pilot_id = ? AND effective_date = ? ORDER BY id", [$pid, $date]));

        $STATUS = ['delivered' => 'تم التسليم', 'undelivered' => 'لم يتم التوصيل'];
        $out = [];
        $tAuto = 0.0;
        $tEff = 0.0;
        foreach ($orders as $o) {
            $oid  = (int) $o['id'];
            $auto = $o['status'] === 'delivered' ? W::orderCommission($o, $pilot, []) : 0.0;
            $ov   = $ovr[$oid] ?? null;
            $eff  = $ov ? round((float) $ov['amount'], 2) : $auto;
            $bm   = W::bizMoment($o['delivered_at'] ?: $o['undelivered_at'], $ds);
            $tAuto += $auto;
            $tEff  += $eff;
            $out[] = [
                'orderId'        => $oid,
                'orderNum'       => (string) ($o['order_num'] ?: $oid),
                'status'         => $o['status'],
                'statusAr'       => $STATUS[$o['status']] ?? $o['status'],
                'time'           => $bm['hm'] ?? null,
                'sender'         => (string) ($o['sender_name'] ?? ''),
                'receiver'       => (string) ($o['receiver_name'] ?? ''),
                'zone'           => (string) ($o['zone_name'] ?? ''),
                'price'          => round((float) $o['total_delivery_price'], 2),
                'pieces'         => (int) ($o['pieces_count'] ?? 1),
                'autoCommission' => $auto,
                'commission'     => $eff,
                'override'       => $ov ? ['id' => (int) $ov['id'], 'amount' => round((float) $ov['amount'], 2),
                                           'reason' => (string) $ov['reason'], 'paidAmount' => round((float) $ov['paid_amount'], 2),
                                           'createdBy' => $ov['created_by']] : null,
            ];
        }
        foreach ($extras as $x) {
            $tEff += $x['amount'];
        }

        return ApiResponse::out([
            'ok'       => true,
            'month'    => $ym,
            'day'      => $day,
            'date'     => $date,
            'pilot'    => ['id' => $pid, 'name' => $pilot['name'],
                           'commissionType' => $pilot['commission_type'], 'commissionValue' => round((float) $pilot['commission_value'], 2)],
            'orders'   => $out,
            'extras'   => $extras,
            'totals'   => ['auto' => round($tAuto, 2), 'commission' => round($tEff, 2), 'orders' => count($out)],
            /* الكتابة على pilot-commission-adjustments للإدارة ومدير الفرع بس (بيدخل المستحقات فعلًا) */
            'canWrite' => in_array($actor->role, ['admin', 'branch'], true) && $this->can($acl, 'act.edit') && ! $this->monthLocked($ym, null),
        ]);
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

        $now = WireTime::nowDb();
        DB::statement(
            'INSERT INTO pilot_month_locks (month, branch_id, locked_at, locked_by)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE locked_at = VALUES(locked_at), locked_by = VALUES(locked_by)',
            [$ym, $bid, $now, $actor->username]
        );

        /* 🔒📸 اللقطة: القفل لوحده كان بيمنع الكتابة في الشيت بس، وأي تغيير
           بعده في سعر الساعة أو رسوم التطوير أو عمولة طيار كان بيعيد حساب
           الشهر المقفول (بلاغ صاحب النظام 2026-09-04: «الشهر المقفول أرقامه
           بتتغيّر بعد القفل»). بنحفظ رد month وstaff-month كاملين لحظة القفل
           وبنعرضهم بعد كده بدل الحساب الحي. بيتحسب **بعد** صف القفل وقبل ما
           صف اللقطة يتكتب، فالمسارين بيمشوا على الحساب الحي مرة أخيرة. */
        $this->writeSnapshot($request, $actor, $ym, $bid, $now);

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
        /* فتح الشهر = رجوع للحساب الحي: اللقطة وصفوف التقفيلة الشهرية اللي
           اتكتبت عند القفل بيتشالوا — القفل التاني بيكتبهم من جديد. */
        DB::delete('DELETE FROM pilot_acct_snapshots WHERE month = ? AND branch_id = ?', [$ym, $bid ?? 0]);
        if ($bid === null) {
            DB::delete("DELETE FROM pilot_monthly_closeouts WHERE month = ? AND closed_by LIKE 'pilotacct:%'", [$ym]);
        } else {
            DB::delete(
                "DELETE FROM pilot_monthly_closeouts WHERE month = ? AND closed_by LIKE 'pilotacct:%'
                    AND pilot_id IN (SELECT id FROM pilots WHERE assigned_branch_id = ?)",
                [$ym, $bid]
            );
        }

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       💸 صرف الرواتب من الخزنة
       (طلب صاحب النظام 2026-09-04 بعد مراجعة «إيه الناقص عشان يُعتمد عليه»)
       كل صرفة = صف في pilot_acct_payouts + حركة `out` في نفس خزن الإدارة،
       فالخزنة بتعرف إن الرواتب خرجت. الصرف على الأرقام **المعتمدة** بس:
       الشهر لازم يكون مقفول (له لقطة) عشان الصافي مايتغيّرش بعد الصرف.
    ═══════════════════════════════════════════════════════════ */

    /** صرفات الشهر: [refId => [ {id, amount, storeId, storeName, note, paidBy, paidAt}, ... ]] */
    private function payoutsOf(string $ym, string $kind): array
    {
        $out = [];
        foreach (DB::select(
            'SELECT p.*, s.name AS store_name FROM pilot_acct_payouts p LEFT JOIN cash_stores s ON s.id = p.store_id
              WHERE p.month = ? AND p.kind = ? ORDER BY p.id',
            [$ym, $kind]
        ) as $r) {
            $out[(string) (int) $r->ref_id][] = [
                'id'        => (int) $r->id,
                'amount'    => round((float) $r->amount, 2),
                'storeId'   => (int) $r->store_id,
                'storeName' => $r->store_name,
                'txnId'     => $r->txn_id !== null ? (int) $r->txn_id : null,
                'note'      => (string) ($r->note ?? ''),
                'paidBy'    => $r->paid_by,
                'paidAt'    => WireTime::toWire((string) $r->paid_at),
            ];
        }

        return $out;
    }

    /**
     * POST /api/pilot-accounting/payout — {month, kind: pilot|staff, refId, amount, cashStoreId, note?}
     * الشهر لازم يكون مقفول، والمبلغ ≤ الباقي من الصافي المعتمد، والخزنة رصيدها يكفي.
     */
    public function payoutSave(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $acl   = $this->aclOf($actor);
        $this->need($acl, 'act.payout', 'على صرف الرواتب');
        $b     = $request->json()->all();
        $ym    = $this->monthArg((string) ($b['month'] ?? ''));
        $kind  = (string) ($b['kind'] ?? 'pilot');
        $refId = (int) ($b['refId'] ?? 0);
        $amount = round((float) ($b['amount'] ?? 0), 2);
        $storeId = (int) ($b['cashStoreId'] ?? 0);
        $note   = trim((string) ($b['note'] ?? ''));
        if (! in_array($kind, ['pilot', 'staff'], true) || $refId <= 0) {
            throw new ApiException('حدّد الطيار أو الموظف');
        }
        if ($amount <= 0) {
            throw new ApiException('المبلغ لازم يكون أكبر من صفر');
        }

        /* الأرقام المعتمدة = اللقطة. من غير قفل الصافي ممكن يتغيّر بعد ما الفلوس خرجت. */
        $snap = $this->snapshotFor($ym, null);
        if (! $this->monthLocked($ym, null) || $snap === null) {
            throw new ApiException('اقفل الشهر الأول — الصرف بيتم على الأرقام المعتمدة بعد القفل', 409);
        }
        $rows = $kind === 'pilot' ? ($snap['payload']['month']['pilots'] ?? []) : ($snap['payload']['staff']['staff'] ?? []);
        $row = null;
        foreach ($rows as $r) {
            if ((int) ($kind === 'pilot' ? $r['pilotId'] : $r['userId']) === $refId) {
                $row = $r;
                break;
            }
        }
        if (! $row) {
            throw ApiException::notFound($kind === 'pilot' ? 'الطيار مش في تقفيلة الشهر ده' : 'الموظف مش في تقفيلة الشهر ده');
        }
        $branchId = $row['branchId'] !== null ? (int) $row['branchId'] : null;
        if ($actor->role === 'branch' && $branchId !== (int) ($actor->branchId ?? 0)) {
            throw ApiException::forbidden('مش من فرعك');
        }
        if ($acl['branches'] && ! in_array((int) $branchId, $acl['branches'], true)) {
            throw ApiException::forbidden('الفرع ده مش مسموحلك بيه');
        }
        $netDue = round((float) ($row['totals']['netDue'] ?? 0), 2);
        $paid   = (float) (DB::select('SELECT COALESCE(SUM(amount),0) s FROM pilot_acct_payouts WHERE month = ? AND kind = ? AND ref_id = ?', [$ym, $kind, $refId])[0]->s ?? 0);
        $remaining = round($netDue - $paid, 2);
        if ($remaining <= 0.004) {
            throw new ApiException('الراتب ده اتصرف بالكامل');
        }
        if ($amount > $remaining + 0.004) {
            throw new ApiException('المبلغ أكبر من الباقي من الصافي المعتمد (' . number_format($remaining, 2) . ' ج.م)');
        }
        $store = DB::selectOne('SELECT id, name, branch_id FROM cash_stores WHERE id = ?', [$storeId]);
        if (! $store) {
            throw ApiException::notFound('الخزنة غير موجودة');
        }
        if ($actor->role === 'branch' && (int) ($store->branch_id ?? 0) !== (int) ($actor->branchId ?? 0)) {
            throw ApiException::forbidden('الخزنة دي مش على فرعك');
        }

        $name = (string) ($row['name'] ?? $row['username'] ?? $refId);
        $now  = WireTime::nowDb();
        $id = DB::transaction(function () use ($storeId, $amount, $ym, $kind, $refId, $name, $note, $branchId, $actor, $now): int {
            $s = DB::select('SELECT id, balance FROM cash_stores WHERE id = ? FOR UPDATE', [$storeId])[0];
            if ((float) $s->balance < $amount) {
                throw new ApiException('رصيد الخزنة (' . number_format((float) $s->balance, 2) . ') مايكفيش لصرف ' . number_format($amount, 2));
            }
            DB::update('UPDATE cash_stores SET balance = balance - ? WHERE id = ?', [$amount, $storeId]);
            DB::insert(
                'INSERT INTO cash_transactions (store_id, type, amount, reason, notes, related_pilot_id, branch_id, created_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [$storeId, 'out', $amount, mb_substr(($kind === 'pilot' ? 'صرف راتب ' : 'صرف مرتب موظف ') . $ym . ': ' . $name, 0, 190),
                 $note !== '' ? mb_substr($note, 0, 500) : null, $kind === 'pilot' ? $refId : null, $branchId, $actor->username, $now]
            );
            $txnId = (int) DB::getPdo()->lastInsertId();
            DB::insert(
                'INSERT INTO pilot_acct_payouts (month, kind, ref_id, amount, store_id, txn_id, note, paid_by, paid_at, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$ym, $kind, $refId, $amount, $storeId, $txnId, $note !== '' ? $note : null, $actor->username, $now, $now]
            );

            return (int) DB::getPdo()->lastInsertId();
        });

        return ApiResponse::out(['ok' => true, 'id' => $id, 'paid' => round($paid + $amount, 2), 'remaining' => round($remaining - $amount, 2)]);
    }

    /** DELETE /api/pilot-accounting/payout/{id} — إلغاء صرفة: الفلوس بترجع للخزنة بحركة `in` */
    public function payoutDelete(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $this->need($this->aclOf($actor), 'act.payout', 'على صرف الرواتب');
        $pid = (int) $id;
        DB::transaction(function () use ($pid, $actor): void {
            $p = DB::select('SELECT * FROM pilot_acct_payouts WHERE id = ? FOR UPDATE', [$pid])[0] ?? null;
            if (! $p) {
                throw ApiException::notFound('الصرفة غير موجودة');
            }
            DB::select('SELECT id FROM cash_stores WHERE id = ? FOR UPDATE', [(int) $p->store_id]);
            DB::update('UPDATE cash_stores SET balance = balance + ? WHERE id = ?', [(float) $p->amount, (int) $p->store_id]);
            $now = WireTime::nowDb();
            DB::insert(
                'INSERT INTO cash_transactions (store_id, type, amount, reason, notes, related_pilot_id, branch_id, created_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [(int) $p->store_id, 'in', (float) $p->amount, mb_substr('إلغاء صرف راتب ' . $p->month . ' (صرفة #' . $pid . ')', 0, 190),
                 null, $p->kind === 'pilot' ? (int) $p->ref_id : null, null, $actor->username, $now]
            );
            DB::delete('DELETE FROM pilot_acct_payouts WHERE id = ?', [$pid]);
        });

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       📸 لقطة الشهر المقفول
    ═══════════════════════════════════════════════════════════ */

    /** نداء داخلي على مسار في نفس الكنترولر بنفس الفاعل — عشان اللقطة تبقى نفس الرد حرفيًا */
    private function internalGet(Request $orig, string $path, array $query): array
    {
        $req = Request::create($path, 'GET', $query);
        $req->attributes->set(ResolveApiActor::ATTRIBUTE, $orig->attributes->get(ResolveApiActor::ATTRIBUTE));
        $method = str_contains($path, 'staff-month') ? 'staffMonth' : 'month';

        return json_decode($this->{$method}($req)->getContent(), true) ?: [];
    }

    private function writeSnapshot(Request $request, Actor $actor, string $ym, ?int $bid, string $now): void
    {
        $q = ['month' => $ym];
        if ($bid !== null) {
            $q['branchId'] = (string) $bid;
        }
        $month = $this->internalGet($request, '/api/pilot-accounting/month', $q);
        $staff = $this->internalGet($request, '/api/pilot-accounting/staff-month', $q);
        unset($month['snapshot'], $staff['snapshot']);
        $payload = json_encode(['month' => $month, 'staff' => $staff], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        DB::statement(
            'INSERT INTO pilot_acct_snapshots (month, branch_id, payload, locked_by, locked_at)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), locked_by = VALUES(locked_by), locked_at = VALUES(locked_at)',
            [$ym, $bid ?? 0, $payload, $actor->username, $now]
        );

        /* 📱 تطبيق الطيار بيقرا تقفيلته من pilot_monthly_closeouts (عقد
           مجمّد) — الجدول كان فاضي من يوم الإطلاق فالطيار ماكانش بيشوف
           حاجة. القفل بيملاه بأرقام اللقطة نفسها. `closed_by` ببادئة
           pilotacct: عشان فتح الشهر يشيل صفوفنا بس. */
        $set = $month['settings'] ?? [];
        foreach ($month['pilots'] ?? [] as $p) {
            $t = $p['totals'] ?? [];
            DB::statement(
                'INSERT INTO pilot_monthly_closeouts
                   (pilot_id, month, work_days, hours, delivered_count, commission, bonus, deductions, advances,
                    salary, required_daily_hours, paid_leave_days, unpaid_leave_days, daily_rate, net_due, closed_at, closed_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE work_days = VALUES(work_days), hours = VALUES(hours), delivered_count = VALUES(delivered_count),
                    commission = VALUES(commission), bonus = VALUES(bonus), deductions = VALUES(deductions), advances = VALUES(advances),
                    salary = VALUES(salary), required_daily_hours = VALUES(required_daily_hours), paid_leave_days = VALUES(paid_leave_days),
                    unpaid_leave_days = VALUES(unpaid_leave_days), daily_rate = VALUES(daily_rate), net_due = VALUES(net_due),
                    closed_at = VALUES(closed_at), closed_by = VALUES(closed_by)',
                [
                    (int) $p['pilotId'], $ym,
                    (int) ($t['worked'] ?? 0), round((float) ($t['hours'] ?? 0), 2), (int) ($t['orders'] ?? 0),
                    round((float) ($t['commission'] ?? 0), 2), round((float) ($t['bonusDue'] ?? 0), 2),
                    round((float) ($t['deductionDue'] ?? 0), 2),
                    // السلف = سلف الشهر + قسط السلفة المؤجلة — الاتنين اتخصموا من الصافي
                    round((float) ($t['advanceDue'] ?? 0) + (float) ($t['deferredDue'] ?? 0), 2),
                    // «salary» في العقد القديم = إجمالي المستحق قبل الخصومات
                    round((float) ($t['gross'] ?? 0), 2),
                    round((float) ($set['shiftHours'] ?? 0), 2),
                    (int) ($t['leaveDays'] ?? 0), max(0, (int) ($t['absent'] ?? 0) - (int) ($t['leaveDays'] ?? 0)),
                    round((float) ($t['hourRate'] ?? 0), 2), round((float) ($t['netDue'] ?? 0), 2),
                    $now, 'pilotacct:' . $actor->username, $now,
                ]
            );
        }
    }

    /** اللقطة المناسبة للطلب: لقطة الفرع نفسه لو موجودة، وإلا لقطة الشركة (0) */
    private function snapshotFor(string $ym, ?int $branchId): ?array
    {
        $rows = DB::select(
            'SELECT branch_id, payload, locked_by, locked_at FROM pilot_acct_snapshots
              WHERE month = ? AND branch_id IN (?, 0) ORDER BY branch_id DESC LIMIT 1',
            [$ym, $branchId ?? 0]
        );
        if (! $rows) {
            return null;
        }
        $r = (array) $rows[0];
        $p = json_decode((string) $r['payload'], true);
        if (! is_array($p) || ! isset($p['month'])) {
            return null;
        }

        return ['scope' => (int) $r['branch_id'], 'payload' => $p,
                'meta' => ['lockedBy' => $r['locked_by'], 'lockedAt' => WireTime::toWire((string) $r['locked_at'])]];
    }

    /** رد month من اللقطة — مقصوص على نطاق الطالب وصلاحياته */
    private function monthFromSnapshot(array $snap, Actor $actor, array $acl, ?int $branchId, ?int $pilotFilter): JsonResponse
    {
        $d = $snap['payload']['month'];
        $inScope = function (?int $b) use ($branchId, $acl): bool {
            if ($branchId !== null && $b !== $branchId) { return false; }
            if ($acl['branches'] && ! in_array((int) $b, $acl['branches'], true)) { return false; }

            return true;
        };
        $pilots = [];
        foreach ($d['pilots'] ?? [] as $p) {
            if (! $inScope($p['branchId'] !== null ? (int) $p['branchId'] : null)) { continue; }
            if ($pilotFilter !== null && (int) $p['pilotId'] !== $pilotFilter) { continue; }
            if (! $acl['full']) {
                $p['days']   = array_map(fn (array $r): array => W::filterDay($r, $acl['keys']), $p['days'] ?? []);
                $p['totals'] = W::filterTotals($p['totals'] ?? [], $acl['keys']);
            }
            $pilots[] = $p;
        }
        $keep = array_flip(array_map(fn ($p) => (int) $p['pilotId'], $pilots));
        $deferred = array_values(array_filter($d['deferred'] ?? [], fn ($a) => isset($keep[(int) $a['pilotId']])));

        $closeout = $d['closeout'] ?? null;
        if (is_array($closeout)) {
            $branches = array_values(array_filter($closeout['branches'] ?? [], fn ($b) => $inScope((int) $b['branchId'])));
            $all = [];
            foreach ($branches as $i => $b) {
                foreach ($b['month'] ?? [] as $k => $v) { $all[$k] = ($all[$k] ?? 0) + (float) $v; }
                if (! $acl['full']) {
                    $branches[$i]['days']  = array_map(fn ($c) => W::filterCloseout($c, $acl['keys']), $b['days'] ?? []);
                    $branches[$i]['month'] = W::filterCloseout($b['month'] ?? [], $acl['keys']);
                }
            }
            foreach ($all as $k => $v) { $all[$k] = round($v, 2); }
            $closeout['branches'] = $branches;
            $closeout['all'] = $acl['full'] ? $all : W::filterCloseout($all, $acl['keys']);
        }

        return ApiResponse::out([
            'ok'          => true,
            'serverNow'   => WireTime::toWire(WireTime::nowDb()),
            'month'       => $d['month'],
            'branchId'    => $branchId,
            'settings'    => $d['settings'] ?? [],
            'locked'      => true,
            'snapshot'    => $snap['meta'],
            'daysInMonth' => $d['daysInMonth'] ?? W::daysInMonth($d['month']),
            'countedDays' => $d['countedDays'] ?? 0,
            'pilots'      => $pilots,
            'closeout'    => $closeout,
            'payouts'     => $this->payoutsOf($d['month'], 'pilot'),   // الصرف حي — مش من اللقطة
            'deferred'    => ($acl['keys']['page.deferred'] ?? null) === true ? $deferred : [],
            'acl'         => ['keys' => (object) $acl['keys'], 'branches' => $acl['branches'],
                              'full' => $acl['full'], 'isAdmin' => $actor->role === 'admin'],
        ]);
    }

    /** رد staff-month من اللقطة */
    private function staffFromSnapshot(array $snap, Actor $actor, array $acl, ?int $branchId): JsonResponse
    {
        $d = $snap['payload']['staff'] ?? [];
        $staff = [];
        foreach ($d['staff'] ?? [] as $u) {
            $b = $u['branchId'] !== null ? (int) $u['branchId'] : null;
            if ($branchId !== null && $b !== $branchId) { continue; }
            if ($acl['branches'] && ! in_array((int) $b, $acl['branches'], true)) { continue; }
            if (! $acl['full']) {
                $u['days']   = array_map(fn (array $r): array => W::filterDay($r, $acl['keys']), $u['days'] ?? []);
                $u['totals'] = W::filterTotals($u['totals'] ?? [], $acl['keys']);
            }
            $staff[] = $u;
        }

        return ApiResponse::out([
            'ok'          => true,
            'serverNow'   => WireTime::toWire(WireTime::nowDb()),
            'month'       => $d['month'] ?? $snap['payload']['month']['month'],
            'branchId'    => $branchId,
            'settings'    => $d['settings'] ?? [],
            'locked'      => true,
            'snapshot'    => $snap['meta'],
            'daysInMonth' => $d['daysInMonth'] ?? 0,
            'countedDays' => $d['countedDays'] ?? 0,
            'staff'       => $staff,
            'payouts'     => $this->payoutsOf((string) ($d['month'] ?? $snap['payload']['month']['month']), 'staff'),
            'acl'         => ['keys' => (object) $acl['keys'], 'branches' => $acl['branches'],
                              'full' => $acl['full'], 'isAdmin' => $actor->role === 'admin'],
        ]);
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

        /* 📸 الشهر المقفول بيتعرض من لقطته */
        if ($this->monthLocked($ym, $branchId) && ($snap = $this->snapshotFor($ym, $branchId)) !== null) {
            return $this->staffFromSnapshot($snap, $actor, $acl, $branchId);
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
            'payouts'     => $this->payoutsOf($ym, 'staff'),
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
    /* ═══════════════════════════════════════════════════════════
       📈 التقارير — الميزانية المتوقعة (طلب صاحب النظام 2026-09-05)
    ═══════════════════════════════════════════════════════════ */

    /** الفرع المطلوب بعد قصّ النطاق: مشرف الفرع مقفول على فرعه، والمحاسب على فروعه المسموحة */
    private function budgetBranchScope(Actor $actor, array $acl, ?int $branchId): ?int
    {
        if ($actor->role === 'branch') {
            return (int) ($actor->branchId ?? 0);
        }
        if ($acl['branches'] && $branchId !== null && ! in_array($branchId, $acl['branches'], true)) {
            throw ApiException::forbidden('الفرع ده مش مسموحلك بيه');
        }

        return $branchId;
    }

    private function budgetRows(string $ym, ?int $branchId): array
    {
        $sql  = 'SELECT * FROM pa_budget_items WHERE month = ?';
        $args = [$ym];
        if ($branchId !== null) {
            $sql .= ' AND branch_id = ?';
            $args[] = $branchId;
        }

        return array_map(fn ($r) => (array) $r, DB::select($sql . ' ORDER BY branch_id, id', $args));
    }

    /** الافتراضات (أوردرات/يوم ومتوسط السعر) متخزنة كبنود category=assumption */
    private function budgetAssumptions(array $rows): array
    {
        $a = ['ordersPerDay' => 0.0, 'avgPrice' => 0.0];
        foreach ($rows as $r) {
            if ($r['category'] === 'assumption' && array_key_exists($r['label'], $a)) {
                $a[$r['label']] = (float) $r['amount'];
            }
        }

        return $a;
    }

    private function budgetItemWire(array $r, float $ordersPerDay): array
    {
        $kind = in_array($r['kind'], W::BUDGET_KINDS, true) ? $r['kind'] : 'fixed';

        return [
            'id'       => (int) $r['id'],
            'branchId' => $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
            'category' => $r['category'],
            'kind'     => $kind,
            'label'    => (string) $r['label'],
            'refType'  => $r['ref_type'],
            'refId'    => $r['ref_id'] !== null ? (int) $r['ref_id'] : null,
            'qty'      => $kind === 'per_order' ? round($ordersPerDay * W::BUDGET_WORK_DAYS, 2) : round((float) $r['qty'], 2),
            'rate'     => round((float) $r['rate'], 2),
            'amount'   => W::budgetAmount($kind, (float) $r['qty'], (float) $r['rate'], (float) $r['amount'], $ordersPerDay),
            'note'     => (string) ($r['note'] ?? ''),
        ];
    }

    /** بلوك فرع واحد: البنود مرتّبة بالتصنيف + الإجماليات ونقطة التعادل */
    private function budgetBranchBlock(int $branchId, string $name, array $rows): array
    {
        $a     = $this->budgetAssumptions($rows);
        $items = [];
        foreach ($rows as $r) {
            if ($r['category'] === 'assumption') {
                continue;
            }
            $items[] = $this->budgetItemWire($r, $a['ordersPerDay']);
        }
        usort($items, function ($x, $y) {
            $ox = array_search($x['category'], array_keys(W::BUDGET_CATEGORIES), true);
            $oy = array_search($y['category'], array_keys(W::BUDGET_CATEGORIES), true);

            return [$ox, $x['id']] <=> [$oy, $y['id']];
        });

        return [
            'branchId'    => $branchId,
            'name'        => $name,
            'assumptions' => $a,
            'items'       => $items,
            'totals'      => W::budgetTotals($items, $a['ordersPerDay'], $a['avgPrice']),
        ];
    }

    /** GET /api/pilot-accounting/budget?month=&branchId= */
    public function budgetList(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $acl   = $this->aclOf($actor);
        $this->need($acl, 'page.reports', 'على صفحة التقارير');
        $ym = $this->monthArg((string) $request->query('month', ''));
        $bq = $request->query('branchId');
        $branchId = $this->budgetBranchScope($actor, $acl, $bq !== null && $bq !== '' ? (int) $bq : null);

        $branchesSql = 'SELECT id, name FROM branches';
        $bArgs = [];
        if ($branchId !== null) {
            $branchesSql .= ' WHERE id = ?';
            $bArgs[] = $branchId;
        } elseif ($acl['branches']) {
            $branchesSql .= ' WHERE id IN (' . implode(',', array_fill(0, count($acl['branches']), '?')) . ')';
            $bArgs = $acl['branches'];
        }
        $branches = array_map(fn ($r) => (array) $r, DB::select($branchesSql . ' ORDER BY id', $bArgs));
        $rows = $this->budgetRows($ym, $branchId);
        $byBranch = [];
        foreach ($rows as $r) {
            $byBranch[(int) ($r['branch_id'] ?? 0)][] = $r;
        }
        $blocks = [];
        foreach ($branches as $b) {
            $blocks[] = $this->budgetBranchBlock((int) $b['id'], (string) $b['name'], $byBranch[(int) $b['id']] ?? []);
        }
        $set = $this->settings();

        return ApiResponse::out([
            'ok'         => true,
            'month'      => $ym,
            'branches'   => $blocks,
            /* الإجمالي بعد الفروع (طلب صاحب النظام) — للي شايف أكتر من فرع */
            'company'    => count($blocks) > 1 || $branchId === null
                ? W::budgetCompany(array_map(fn ($b) => $b['totals'], $blocks)) : null,
            'categories' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v[0], 'kind' => $v[1], 'source' => $v[2]],
                array_keys(W::BUDGET_CATEGORIES), W::BUDGET_CATEGORIES),
            'kinds'      => W::BUDGET_KINDS,
            'settings'   => ['workDays' => W::BUDGET_WORK_DAYS, 'orderRate' => $set['orderRate'], 'hourRate' => $set['hourRate'],
                             'shiftHours' => $set['shiftHours'], 'devFeeBranchId' => $set['devFeeBranchId']],
            'canWrite'   => $this->can($acl, 'act.budget'),
        ]);
    }

    /**
     * POST /api/pilot-accounting/budget — بند واحد (إنشاء أو تعديل)
     * body: {month, branchId, id?, category, kind, label, refType?, refId?, qty, rate, amount, note}
     * الافتراضات: category=assumption و label=ordersPerDay|avgPrice و amount=القيمة.
     */
    public function budgetSave(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $acl   = $this->aclOf($actor);
        $this->need($acl, 'act.budget', 'على كتابة الميزانية');
        $b  = $request->json()->all();
        $ym = $this->monthArg((string) ($b['month'] ?? ''));
        $branchId = isset($b['branchId']) && $b['branchId'] !== '' && $b['branchId'] !== null ? (int) $b['branchId'] : null;
        $branchId = $this->budgetBranchScope($actor, $acl, $branchId);
        if ($branchId === null || ! DB::selectOne('SELECT id FROM branches WHERE id = ?', [$branchId])) {
            throw new ApiException('حدّد الفرع — الميزانية لكل فرع لوحده');
        }

        $category = (string) ($b['category'] ?? '');
        if ($category === 'assumption') {
            $label = (string) ($b['label'] ?? '');
            if (! in_array($label, ['ordersPerDay', 'avgPrice'], true)) {
                throw new ApiException('الافتراض لازم يكون أوردرات/يوم أو متوسط السعر');
            }
            $val = round(max(0, (float) ($b['amount'] ?? 0)), 2);
            $cur = DB::selectOne('SELECT id FROM pa_budget_items WHERE month = ? AND branch_id = ? AND category = ? AND label = ?', [$ym, $branchId, 'assumption', $label]);
            if ($cur) {
                DB::update('UPDATE pa_budget_items SET amount = ?, updated_by = ? WHERE id = ?', [$val, $actor->username, (int) $cur->id]);
                $id = (int) $cur->id;
            } else {
                DB::insert('INSERT INTO pa_budget_items (month, branch_id, category, kind, label, amount, created_by, updated_by, created_at)
                            VALUES (?,?,?,?,?,?,?,?,?)', [$ym, $branchId, 'assumption', 'fixed', $label, $val, $actor->username, $actor->username, WireTime::nowDb()]);
                $id = (int) DB::getPdo()->lastInsertId();
            }

            return ApiResponse::out(['ok' => true, 'id' => $id]);
        }

        if (! isset(W::BUDGET_CATEGORIES[$category])) {
            throw new ApiException('تصنيف البند غير معروف');
        }
        $kind = (string) ($b['kind'] ?? W::BUDGET_CATEGORIES[$category][1]);
        if (! in_array($kind, W::BUDGET_KINDS, true)) {
            throw new ApiException('نوع البند لازم يكون ثابت أو لكل أوردر أو لكل ساعة');
        }
        $label = trim((string) ($b['label'] ?? '')) ?: W::BUDGET_CATEGORIES[$category][0];
        $qty    = round(max(0, (float) ($b['qty'] ?? 0)), 2);
        $rate   = round(max(0, (float) ($b['rate'] ?? 0)), 2);
        $amount = round(max(0, (float) ($b['amount'] ?? 0)), 2);
        $note   = trim((string) ($b['note'] ?? ''));
        $refType = in_array($b['refType'] ?? null, ['user', 'pilot'], true) ? $b['refType'] : null;
        $refId   = $refType !== null && (int) ($b['refId'] ?? 0) > 0 ? (int) $b['refId'] : null;
        $rows = $this->budgetRows($ym, $branchId);
        $ordersPerDay = $this->budgetAssumptions($rows)['ordersPerDay'];
        $stored = $kind === 'fixed' ? $amount : W::budgetAmount($kind, $qty, $rate, $amount, $ordersPerDay);

        $id = (int) ($b['id'] ?? 0);
        if ($id > 0) {
            $cur = DB::selectOne('SELECT id FROM pa_budget_items WHERE id = ? AND month = ? AND branch_id = ?', [$id, $ym, $branchId]);
            if (! $cur) {
                throw ApiException::notFound('البند غير موجود');
            }
            DB::update('UPDATE pa_budget_items SET category = ?, kind = ?, label = ?, ref_type = ?, ref_id = ?, qty = ?, rate = ?, amount = ?, note = ?, updated_by = ? WHERE id = ?',
                [$category, $kind, mb_substr($label, 0, 190), $refType, $refId, $qty, $rate, $stored, $note !== '' ? mb_substr($note, 0, 500) : null, $actor->username, $id]);
        } else {
            DB::insert('INSERT INTO pa_budget_items (month, branch_id, category, kind, label, ref_type, ref_id, qty, rate, amount, note, created_by, updated_by, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$ym, $branchId, $category, $kind, mb_substr($label, 0, 190), $refType, $refId, $qty, $rate, $stored, $note !== '' ? mb_substr($note, 0, 500) : null,
                 $actor->username, $actor->username, WireTime::nowDb()]);
            $id = (int) DB::getPdo()->lastInsertId();
        }
        $row = (array) DB::selectOne('SELECT * FROM pa_budget_items WHERE id = ?', [$id]);

        return ApiResponse::out(['ok' => true, 'item' => $this->budgetItemWire($row, $ordersPerDay)]);
    }

    /** DELETE /api/pilot-accounting/budget/{id} */
    public function budgetDelete(Request $request, string $id): JsonResponse
    {
        $actor = $request->actorOrFail();
        $acl   = $this->aclOf($actor);
        $this->need($acl, 'act.budget', 'على كتابة الميزانية');
        $row = DB::selectOne('SELECT id, branch_id FROM pa_budget_items WHERE id = ?', [(int) $id]);
        if (! $row) {
            throw ApiException::notFound('البند غير موجود');
        }
        $this->budgetBranchScope($actor, $acl, $row->branch_id !== null ? (int) $row->branch_id : null);
        DB::delete('DELETE FROM pa_budget_items WHERE id = ?', [(int) $row->id]);

        return ApiResponse::ok();
    }

    /**
     * POST /api/pilot-accounting/budget/prefill — {month, branchId}
     * بيملا الفرع بالبنود الافتراضية من الحقيقي: كل موظف وكل طيار بسعره،
     * العمولة ورسوم التطوير لكل أوردر، وصفوف فاضية للإيجار والمرافق —
     * من غير ما يلمس بند موجود (اللي المستخدم كتبه بيفضل).
     */
    public function budgetPrefill(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $acl   = $this->aclOf($actor);
        $this->need($acl, 'act.budget', 'على كتابة الميزانية');
        $b  = $request->json()->all();
        $ym = $this->monthArg((string) ($b['month'] ?? ''));
        $branchId = isset($b['branchId']) && $b['branchId'] !== '' && $b['branchId'] !== null ? (int) $b['branchId'] : null;
        $branchId = $this->budgetBranchScope($actor, $acl, $branchId);
        if ($branchId === null || ! DB::selectOne('SELECT id FROM branches WHERE id = ?', [$branchId])) {
            throw new ApiException('حدّد الفرع — الميزانية لكل فرع لوحده');
        }
        $set   = $this->settings();
        $rows  = $this->budgetRows($ym, $branchId);
        $have  = [];
        foreach ($rows as $r) {
            $have[$r['category'] . ':' . ($r['ref_type'] ?? '') . ':' . ($r['ref_id'] ?? '')] = true;
        }
        $hours = round(W::BUDGET_WORK_DAYS * (float) $set['shiftHours'], 2);
        $now   = WireTime::nowDb();
        $added = 0;
        $ins = function (string $cat, string $kind, string $label, ?string $refType, ?int $refId, float $qty, float $rate, float $amount, ?string $note) use (&$added, &$have, $ym, $branchId, $actor, $now): void {
            $k = $cat . ':' . ($refType ?? '') . ':' . ($refId ?? '');
            if (isset($have[$k])) {
                return;
            }
            DB::insert('INSERT INTO pa_budget_items (month, branch_id, category, kind, label, ref_type, ref_id, qty, rate, amount, note, created_by, updated_by, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$ym, $branchId, $cat, $kind, $label, $refType, $refId, $qty, $rate, $amount, $note, $actor->username, $actor->username, $now]);
            $have[$k] = true;
            $added++;
        };

        /* الافتراضات: أوردرات/يوم من الشهر اللي فات (أو الحالي) ومتوسط السعر الفعلي */
        $from = W::bizWindowUtc($ym . '-01', (int) $set['dayStartHour'])[0];
        $to   = W::bizWindowUtc($ym . '-' . W::daysInMonth($ym), (int) $set['dayStartHour'])[1];
        $act = DB::selectOne("SELECT COUNT(*) c, COALESCE(AVG(total_delivery_price),0) p, COUNT(DISTINCT DATE(delivered_at)) d
                                FROM orders WHERE status = 'delivered' AND branch_id = ? AND delivered_at >= ? AND delivered_at < ?", [$branchId, $from, $to]);
        $perDay = (int) $act->d > 0 ? round((int) $act->c / (int) $act->d, 2) : 0.0;
        $avg    = round((float) $act->p, 2);
        if (! isset($have['assumption::']) ) {
            foreach ([['ordersPerDay', $perDay], ['avgPrice', $avg]] as [$lbl, $val]) {
                if (! DB::selectOne('SELECT id FROM pa_budget_items WHERE month = ? AND branch_id = ? AND category = ? AND label = ?', [$ym, $branchId, 'assumption', $lbl])) {
                    DB::insert('INSERT INTO pa_budget_items (month, branch_id, category, kind, label, amount, created_by, updated_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
                        [$ym, $branchId, 'assumption', 'fixed', $lbl, $val, $actor->username, $actor->username, $now]);
                    $added++;
                }
            }
        }

        /* الموظفين: راتب شهري ⇒ ثابت، وإلا سعر ساعة × ساعات الشهر */
        $ph = implode(',', array_fill(0, count(self::STAFF_ROLES), '?'));
        $args = array_merge(self::STAFF_ROLES, [$branchId]);
        $rd = AuthController::damascusOnlyUserIds();
        $rdSql = $rd ? ' AND id NOT IN (' . implode(',', array_fill(0, count($rd), '?')) . ')' : '';
        foreach (DB::select("SELECT id, name, username, hour_rate, monthly_salary FROM users WHERE blocked = 0 AND role IN ({$ph}) AND branch_id = ?{$rdSql} ORDER BY id", array_merge($args, $rd)) as $u) {
            $name = (string) ($u->name ?: $u->username);
            if ((float) $u->monthly_salary > 0) {
                $ins('staff', 'fixed', $name, 'user', (int) $u->id, 0, 0, round((float) $u->monthly_salary, 2), 'راتب شهري');
            } else {
                $rate = (float) $u->hour_rate > 0 ? (float) $u->hour_rate : (float) $set['hourRate'];
                $ins('staff', 'per_hour', $name, 'user', (int) $u->id, $hours, round($rate, 2), round($hours * $rate, 2), (float) $u->hour_rate > 0 ? null : 'بالسعر الافتراضي');
            }
        }

        /* الطيارين: ساعات الشهر × سعر ساعته + عمولته لكل أوردر (متوسط) */
        $pilots = array_map(fn ($r) => (array) $r, DB::select('SELECT id, name, hour_rate, commission_type, commission_value FROM pilots WHERE archived_at IS NULL AND assigned_branch_id = ? ORDER BY id', [$branchId]));
        $commSum = 0.0;
        foreach ($pilots as $p) {
            $rate = W::hourRateOf($p, $set);
            $ins('pilot_hours', 'per_hour', (string) $p['name'], 'pilot', (int) $p['id'], $hours, round($rate, 2), round($hours * $rate, 2), (float) $p['hour_rate'] > 0 ? null : 'بالسعر الافتراضي');
            $commSum += ($p['commission_type'] ?: 'percent') === 'fixed' ? (float) $p['commission_value'] : $avg * (float) $p['commission_value'] / 100;
        }
        if ($pilots) {
            $ins('commission', 'per_order', 'عمولة الطيار لكل أوردر (متوسط)', null, null, 0, round($commSum / count($pilots), 2), 0, count($pilots) . ' طيار');
        }
        $dfb = (int) $set['devFeeBranchId'];
        if ($dfb === 0 || $dfb === $branchId) {
            $ins('dev_fee', 'per_order', 'رسوم التطوير لكل أوردر', null, null, 0, round((float) $set['orderRate'], 2), 0, $dfb ? 'على أوردرات كل الفروع' : null);
        }
        foreach (['rent', 'utilities'] as $cat) {
            $ins($cat, 'fixed', W::BUDGET_CATEGORIES[$cat][0], null, null, 0, 0, 0, 'اكتب المبلغ');
        }

        return ApiResponse::out(['ok' => true, 'added' => $added]);
    }
    /**
     * GET /api/pilot-accounting/reports?month=&branchId= — الواقع قصاد المتوقع (المرحلة ٢).
     *
     * الفعلي بييجي من نفس التقفيلات الموجودة (مش حساب جديد): أجر الطيارين
     * والعمولة والإيراد من `month`، رواتب الموظفين من `staff-month`، رسوم التطوير
     * من بلوك التقفيل اليومي، وباقي البنود من المصروفات المصنّفة. بيرجع كمان
     * سلسلة يومية (أوردرات/إيراد/عمولة) للرسوم.
     */
    public function reportsMonth(Request $request): JsonResponse
    {
        $actor = $request->actorOrFail();
        $acl   = $this->aclOf($actor);
        $this->need($acl, 'page.reports', 'على صفحة التقارير');
        $ym = $this->monthArg((string) $request->query('month', ''));
        $bq = $request->query('branchId');
        $branchId = $this->budgetBranchScope($actor, $acl, $bq !== null && $bq !== '' ? (int) $bq : null);
        $set = $this->settings();
        $ds  = (int) $set['dayStartHour'];
        $nd  = W::daysInMonth($ym);
        $elapsed = W::countedDays($ym, $ds);

        $branchesSql = 'SELECT id, name FROM branches';
        $bArgs = [];
        if ($branchId !== null) {
            $branchesSql .= ' WHERE id = ?';
            $bArgs[] = $branchId;
        } elseif ($acl['branches']) {
            $branchesSql .= ' WHERE id IN (' . implode(',', array_fill(0, count($acl['branches']), '?')) . ')';
            $bArgs = $acl['branches'];
        }
        $branches = array_map(fn ($r) => (array) $r, DB::select($branchesSql . ' ORDER BY id', $bArgs));
        $rows = $this->budgetRows($ym, $branchId);
        $byBranch = [];
        foreach ($rows as $r) {
            $byBranch[(int) ($r['branch_id'] ?? 0)][] = $r;
        }
        /* المصروفات المصنّفة في الشهر (بتاريخ المصروف) */
        $expByBranch = [];
        foreach (DB::select("SELECT branch_id, category, SUM(amount) s FROM expenses
                              WHERE expense_date >= ? AND expense_date <= ? GROUP BY branch_id, category",
            [$ym . '-01', $ym . '-' . $nd]) as $e) {
            $expByBranch[(int) ($e->branch_id ?? 0)][$e->category ?? ''] = round((float) $e->s, 2);
        }

        /* حقائق للتوصيات: الشهر اللي فات، العهدة الواقفة، موظفين بلا سعر */
        $thr = $set['reportThresholds'];
        $prevYm = date('Y-m', strtotime($ym . '-01 -1 month'));
        $pFrom = W::bizWindowUtc($prevYm . '-01', $ds)[0];
        $pTo   = W::bizWindowUtc($prevYm . '-' . W::daysInMonth($prevYm), $ds)[1];
        $prevBy = [];
        foreach (DB::select("SELECT branch_id, COUNT(*) c, COALESCE(SUM(total_delivery_price),0) s, COUNT(DISTINCT DATE(delivered_at)) d
                               FROM orders WHERE status = 'delivered' AND delivered_at >= ? AND delivered_at < ? GROUP BY branch_id", [$pFrom, $pTo]) as $r) {
            $prevBy[(int) ($r->branch_id ?? 0)] = ['perDay' => (int) $r->d > 0 ? round((int) $r->c / (int) $r->d, 2) : 0.0, 'revPerDay' => (int) $r->d > 0 ? round((float) $r->s / (int) $r->d, 2) : 0.0];
        }
        $custodyBy = [];
        foreach (DB::select('SELECT assigned_branch_id b, COALESCE(SUM(custody_balance),0) s FROM pilots WHERE archived_at IS NULL GROUP BY assigned_branch_id') as $r) {
            $custodyBy[(int) ($r->b ?? 0)] = round((float) $r->s, 2);
        }
        $staffNoRateBy = [];
        if ((float) $set['hourRate'] <= 0) {
            $ph = implode(',', array_fill(0, count(self::STAFF_ROLES), '?'));
            foreach (DB::select("SELECT branch_id b, COUNT(*) c FROM users WHERE blocked = 0 AND role IN ({$ph}) AND hour_rate = 0 AND monthly_salary = 0 GROUP BY branch_id", self::STAFF_ROLES) as $r) {
                $staffNoRateBy[(int) ($r->b ?? 0)] = (int) $r->c;
            }
        }
        $expCountBy = [];
        foreach (DB::select('SELECT branch_id b, COUNT(*) c FROM expenses WHERE expense_date >= ? AND expense_date <= ? GROUP BY branch_id', [$ym . '-01', $ym . '-' . $nd]) as $r) {
            $expCountBy[(int) ($r->b ?? 0)] = (int) $r->c;
        }

        $blocks = [];
        foreach ($branches as $b) {
            $bid = (int) $b['id'];
            $bud = $this->budgetBranchBlock($bid, (string) $b['name'], $byBranch[$bid] ?? []);
            $m   = $this->internalGet($request, '/api/pilot-accounting/month', ['month' => $ym, 'branchId' => (string) $bid]);
            $st  = $this->internalGet($request, '/api/pilot-accounting/staff-month', ['month' => $ym, 'branchId' => (string) $bid]);
            $daily = [];
            for ($d = 1; $d <= $nd; $d++) {
                $daily[$d] = ['day' => $d, 'orders' => 0, 'revenue' => 0.0, 'commission' => 0.0, 'hours' => 0.0];
            }
            $pilotHours = 0.0; $commission = 0.0; $revenue = 0.0; $orders = 0;
            foreach ($m['pilots'] ?? [] as $p) {
                $t = $p['totals'] ?? [];
                $pilotHours += (float) ($t['hourPay'] ?? 0) + (float) ($t['salaryShare'] ?? 0) + (float) ($t['leavePay'] ?? 0) + (float) ($t['bonusDue'] ?? 0);
                $commission += (float) ($t['psvc'] ?? 0);
                $revenue    += (float) ($t['svc'] ?? 0);
                $orders     += (int) ($t['orders'] ?? 0);
                foreach ($p['days'] ?? [] as $row) {
                    $d = (int) ($row['day'] ?? 0);
                    if (! isset($daily[$d])) {
                        continue;
                    }
                    $daily[$d]['orders']     += (int) ($row['orders'] ?? 0);
                    $daily[$d]['revenue']    += (float) ($row['svc'] ?? 0);
                    $daily[$d]['commission'] += (float) ($row['psvc'] ?? 0);
                    $daily[$d]['hours']      += (float) ($row['hours'] ?? 0);
                }
            }
            $staff = 0.0;
            foreach ($st['staff'] ?? [] as $u) {
                $t = $u['totals'] ?? [];
                $staff += isset($t['gross']) ? (float) $t['gross']
                    : (float) ($t['hourPay'] ?? 0) + (float) ($t['salaryShare'] ?? 0) + (float) ($t['leavePay'] ?? 0) + (float) ($t['bonusDue'] ?? 0);
            }
            $devFee = 0.0;
            foreach ($m['closeout']['branches'] ?? [] as $cb) {
                if ((int) ($cb['branchId'] ?? 0) !== $bid) {
                    continue;
                }
                foreach ($cb['days'] ?? [] as $cd) {
                    $devFee += (float) ($cd['devFee'] ?? 0);
                }
            }
            $exp = $expByBranch[$bid] ?? [];
            $actualBy = ['staff' => round($staff, 2), 'pilot_hours' => round($pilotHours, 2), 'commission' => round($commission, 2), 'dev_fee' => round($devFee, 2)];
            foreach (W::EXPENSE_CATEGORIES as $c) {
                $actualBy[$c] = round((float) ($exp[$c] ?? 0), 2);
            }
            $actual = ['byCategory' => $actualBy, 'revenue' => round($revenue, 2), 'orders' => $orders, 'uncategorized' => round((float) ($exp[''] ?? 0), 2)];
            $blk = [
                'branchId' => $bid,
                'name'     => (string) $b['name'],
                'budget'   => $bud['totals'],
                'hasBudget' => count($bud['items']) > 0,
                'compare'  => W::reportCompare($bud['totals'], $actual, $elapsed, $nd),
                'daily'    => array_values(array_map(fn ($x) => ['day' => $x['day'], 'orders' => $x['orders'], 'revenue' => round($x['revenue'], 2), 'commission' => round($x['commission'], 2), 'hours' => round($x['hours'], 2)], $daily)),
            ];
            $blk['recommendations'] = W::reportRecommendations($blk, [
                'prevOrdersPerDay'  => $prevBy[$bid]['perDay'] ?? 0,
                'prevRevenuePerDay' => $prevBy[$bid]['revPerDay'] ?? 0,
                'custody'           => $custodyBy[$bid] ?? 0,
                'staffNoRate'       => $staffNoRateBy[$bid] ?? 0,
                'expensesCount'     => $expCountBy[$bid] ?? 0,
            ], $thr);
            $blocks[] = $blk;
        }

        /* الإجمالي بعد الفروع */
        $company = null;
        if (count($blocks) > 1 || $branchId === null) {
            $budC = W::budgetCompany(array_map(fn ($b) => $b['budget'], $blocks));
            $actC = ['byCategory' => [], 'revenue' => 0.0, 'orders' => 0, 'uncategorized' => 0.0];
            $dailyC = [];
            for ($d = 1; $d <= $nd; $d++) {
                $dailyC[$d] = ['day' => $d, 'orders' => 0, 'revenue' => 0.0, 'commission' => 0.0, 'hours' => 0.0];
            }
            foreach ($blocks as $bl) {
                foreach ($bl['compare']['rows'] as $r) {
                    $actC['byCategory'][$r['category']] = round(($actC['byCategory'][$r['category']] ?? 0) + $r['actual'], 2);
                }
                $actC['revenue'] += (float) $bl['compare']['totals']['revenue'];
                $actC['orders']  += (int) $bl['compare']['totals']['orders'];
                $actC['uncategorized'] += (float) $bl['compare']['uncategorized'];
                foreach ($bl['daily'] as $x) {
                    $dailyC[$x['day']]['orders'] += $x['orders'];
                    $dailyC[$x['day']]['revenue'] += $x['revenue'];
                    $dailyC[$x['day']]['commission'] += $x['commission'];
                    $dailyC[$x['day']]['hours'] += $x['hours'];
                }
            }
            $company = [
                'name'    => 'الشركة',
                'budget'  => $budC,
                'hasBudget' => (bool) array_filter($blocks, fn ($bl) => $bl['hasBudget']),
                'compare' => W::reportCompare($budC, $actC, $elapsed, $nd),
                'daily'   => array_values(array_map(fn ($x) => ['day' => $x['day'], 'orders' => $x['orders'], 'revenue' => round($x['revenue'], 2), 'commission' => round($x['commission'], 2), 'hours' => round($x['hours'], 2)], $dailyC)),
            ];
            $prevAll = 0.0;
            foreach ($blocks as $bl) {
                $prevAll += (float) ($prevBy[$bl['branchId']]['perDay'] ?? 0);
            }
            $company['recommendations'] = W::reportRecommendations($company, [
                'prevOrdersPerDay' => $prevAll,
                'custody'          => array_sum($custodyBy),
                'staffNoRate'      => array_sum($staffNoRateBy),
                'expensesCount'    => array_sum($expCountBy),
                'branches'         => array_map(fn ($bl) => ['name' => $bl['name'], 'profit' => $bl['compare']['totals']['profit'], 'breakEven' => $bl['compare']['totals']['breakEven']], $blocks),
            ], $thr);
        }

        return ApiResponse::out([
            'ok'          => true,
            'month'       => $ym,
            'elapsedDays' => $elapsed,
            'daysInMonth' => $nd,
            'branches'    => $blocks,
            'company'     => $company,
            'thresholds'  => $thr,
            'categories'  => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v[0], 'kind' => $v[1], 'source' => $v[2]],
                array_keys(W::BUDGET_CATEGORIES), W::BUDGET_CATEGORIES),
        ]);
    }
}
