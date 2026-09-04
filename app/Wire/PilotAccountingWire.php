<?php

declare(strict_types=1);

namespace App\Wire;

use App\Support\Commission;
use DateTimeImmutable;
use DateTimeZone;

/**
 * 💰🔴 حسابات قسم حسابات الطيارين — الشيت اليومي والتقفيلة الشهرية.
 *
 * النموذج مأخوذ من «روح دمشق» (`DamascusWire`) بالحرف، بفرق جوهري واحد:
 * هناك كل خانة بتتكتب بالإيد، وهنا كل خانة **بتتحسب لايف** من المنظومة —
 * الوردية والأوردرات والعمولة — والمكتوب بالإيد بيبقى **override** فوقها.
 *
 * ═══ ليه الحساب لايف مش متخزّن ═══
 * لو خزّنا الأرقام، أي تعديل بأثر رجعي (أوردر اتسلّم متأخر، عمولة اتعدّلت،
 * وردية اتقفلت غلط واتصلّحت) بيسيب الشيت بيقول رقم والقاعدة بتقول رقم
 * تاني، ومحدش بياخد باله. الحساب لايف معناه إن الشيت **مرآة** للحقيقة
 * دايمًا، والصف المتخزّن مالوش غير معنى واحد: «حد تدخّل هنا».
 *
 * ═══ اليوم التجاري ═══
 * بيبدأ الساعة `dayStartHour` **بتوقيت القاهرة** (صاحب النظام حددها 9 ص).
 * وردية بتقفل 2 بعد نص الليل بتتسجّل على اليوم اللي فات — زي كشف الشيفت
 * الورقي بالظبط. التخزين كله UTC، والتحويل بيحصل هنا في مكان واحد.
 */
final class PilotAccountingWire
{
    /** بداية اليوم التجاري بتوقيت القاهرة — قرار صاحب النظام 2026-08-27 */
    public const DEFAULT_DAY_START = 9;

    /** ساعات الوردية المعيارية — بتستخدم في حساب أجر يوم الإجازة المدفوعة */
    public const DEFAULT_SHIFT_HOURS = 10.0;

    private const TZ = 'Africa/Cairo';

    /* ═══════════════════════════════════════════════════════════
       الوقت واليوم التجاري
    ═══════════════════════════════════════════════════════════ */

    /**
     * DATETIME مخزّن (UTC) → ["يوم الشهر", "HH:MM"] بتوقيت القاهرة وحسب
     * بداية اليوم التجاري.
     *
     * القاعدة: أي لحظة قبل `dayStart` بتتبع اليوم اللي قبلها. يعني
     * `dayStart = 9` معناها إن اللحظة 02:30 يوم 12 بتتسجّل على يوم 11.
     *
     * بترجّع `['date' => 'Y-m-d', 'hm' => 'HH:MM']` — و`date` هو **اليوم
     * التجاري** مش التاريخ الميلادي، و`hm` هو وقت الساعة الحقيقي.
     */
    public static function bizMoment(?string $utcDatetime, int $dayStart): ?array
    {
        if ($utcDatetime === null || $utcDatetime === '') {
            return null;
        }
        $ts = strtotime($utcDatetime . ' UTC');
        if ($ts === false) {
            return null;
        }
        $d = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(self::TZ));
        $hm = $d->format('H:i');
        if ((int) $d->format('G') < $dayStart) {
            $d = $d->modify('-1 day');
        }

        return ['date' => $d->format('Y-m-d'), 'hm' => $hm];
    }

    /** حدود اليوم التجاري بالـUTC — للاستعلامات: [من, لـ) */
    public static function bizWindowUtc(string $date, int $dayStart): array
    {
        $tz    = new DateTimeZone(self::TZ);
        $start = new DateTimeImmutable($date . ' ' . sprintf('%02d:00:00', $dayStart), $tz);
        $end   = $start->modify('+1 day');
        $utc   = new DateTimeZone('UTC');

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /** "YYYY-MM" → عدد أيامه */
    public static function daysInMonth(string $ym): int
    {
        [$y, $m] = array_map('intval', explode('-', $ym));

        return (int) cal_days_in_month(CAL_GREGORIAN, $m, $y);
    }

    /**
     * آخر يوم بيتحسب في الشهر — نفس `DamascusWire::countedDays`.
     * الشهر اللي فات بيتحسب كامل، والشهر الحالي لحد النهارده بس، والجاي صفر.
     * ده اللي بيمنع إن كل باقي أيام الشهر تتحسب «غياب» من أول يوم فيه.
     */
    public static function countedDays(string $ym, int $dayStart): int
    {
        $nd  = self::daysInMonth($ym);
        $now = self::bizMoment(gmdate('Y-m-d H:i:s'), $dayStart);
        $today = $now['date'] ?? gmdate('Y-m-d');
        $cur = substr($today, 0, 7);

        if ($ym > $cur) {
            return 0;
        }
        if ($ym === $cur) {
            return min($nd, (int) substr($today, 8, 2));
        }

        return $nd;
    }

    /* ═══════════════════════════════════════════════════════════
       الساعات
    ═══════════════════════════════════════════════════════════ */

    /** "HH:MM" → دقايق من نص الليل، أو null */
    public static function parseHm(?string $t): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) $t), $m)) {
            return null;
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    /** مجموع دقايق الاستئذان — الفترة الناقصة طرفها بتتجاهل */
    public static function permMinutes(array $perms): int
    {
        $sum = 0;
        foreach ($perms as $p) {
            $a = self::parseHm($p['out'] ?? null);
            $b = self::parseHm($p['in'] ?? null);
            if ($a === null || $b === null) {
                continue;
            }
            $d = $b - $a;
            if ($d < 0) {
                $d += 24 * 60;         // الاستئذان عدّى نص الليل
            }
            $sum += max(0, $d);
        }

        return $sum;
    }

    /**
     * ★ ساعات اليوم المحسوبة — نفس `DamascusWire::autoHours` بالحرف:
     * (انصراف − حضور)، وزائد 24 ساعة لو الوردية عدّت نص الليل، ناقص
     * وقت الاستئذان، وبالسالب بتتقص عند صفر.
     */
    public static function autoHours(?string $in, ?string $out, array $perms): ?float
    {
        $a = self::parseHm($in);
        $b = self::parseHm($out);
        if ($a === null || $b === null) {
            return null;
        }
        $d = $b - $a;
        if ($d < 0) {
            $d += 24 * 60;
        }
        $d -= self::permMinutes($perms);

        return round(max(0, $d) / 60, 2);
    }

    /* ═══════════════════════════════════════════════════════════
       السلف المؤجلة
    ═══════════════════════════════════════════════════════════ */

    /**
     * 💰 قسط شهر معيّن من سلفة مؤجلة — نقل حرفي لـ
     * `DamascusWire::deferredForMonth`.
     *
     * بيمشي شهر بشهر من `start_month` وبياخد كل مرة
     * `min(قسط الشهر ده, الرصيد المتبقي)` — فآخر قسط بياخد الباقي بس
     * والسلفة مابتتخصمش زيادة عن مبلغها أبدًا.
     *
     * `$overrides` = ["YYYY-MM" => مبلغ] من `pilot_deferred_payments`.
     *
     * بترجّع: due (قسط الشهر ده) · before (الرصيد قبله) · after (بعده)
     */
    public static function deferredForMonth(array $rec, string $ym, array $overrides): array
    {
        $total   = (float) ($rec['amount'] ?? 0);
        $monthly = (float) ($rec['monthly'] ?? 0);
        $monthly = $monthly > 0 ? $monthly : $total;     // صفر = تتخصم مرة واحدة
        $start   = (string) ($rec['start_month'] ?? '');

        if ($start === '' && ! empty($rec['advance_date'])) {
            $start = substr((string) $rec['advance_date'], 0, 7);
        }
        if ($start === '' || $ym < $start) {
            return ['due' => 0.0, 'before' => round($total, 2), 'after' => round($total, 2), 'started' => false];
        }

        $bal   = $total;
        $cur   = $start;
        $guard = 0;
        while ($guard++ < 400) {
            $ov  = $overrides[$cur] ?? null;
            $amt = round(min($ov !== null ? (float) $ov : $monthly, $bal), 2);
            if ($cur === $ym) {
                return ['due' => $amt, 'before' => round($bal, 2), 'after' => round($bal - $amt, 2), 'started' => true];
            }
            $bal = round($bal - $amt, 2);
            $cur = self::nextMonth($cur);
            if ($cur > $ym) {
                break;
            }
        }

        return ['due' => 0.0, 'before' => round($bal, 2), 'after' => round($bal, 2), 'started' => true];
    }

    public static function nextMonth(string $ym): string
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        $m++;
        if ($m > 12) {
            $m = 1;
            $y++;
        }

        return sprintf('%04d-%02d', $y, $m);
    }

    /**
     * 💰 سعر ساعة الطيار — **بتاعه هو لو أكبر من صفر، وإلا الافتراضي**.
     *
     * نفس قاعدة `DamascusWire::rateOf` بالحرف: الصفر معناه «مش متحدد»
     * مش «بالبلاش». من غير القاعدة دي كان لازم تكتب السعر على كل طيار
     * واحد واحد، ونسيان طيار كان معناه إن أجر ساعاته يطلع صفر بالصمت.
     */
    public static function hourRateOf(array $pilot, array $settings): float
    {
        $own = (float) ($pilot['hour_rate'] ?? 0);

        return $own > 0 ? $own : (float) ($settings['hourRate'] ?? 0);
    }

    /* ═══════════════════════════════════════════════════════════
       العمولة
    ═══════════════════════════════════════════════════════════ */

    /**
     * 💰 عمولة الطيار على أوردر واحد.
     *
     * الترتيب مقصود: **تعديل الـoverride اليدوي بيغلب المعادلة** — ده
     * اللي بيخلي أوردر السفر ياخد نص سعر الخدمة بدل الثابت (طلب صاحب
     * النظام 2026-08-26). لو مفيش تعديل بترجع لـ`Commission::forPilot`
     * وهي **نفس** الدالة اللي بيستخدمها باقي النظام — ممنوع تتكرر هنا
     * بمعادلة تانية.
     */
    public static function orderCommission(array $order, array $pilot, array $overrides): float
    {
        $oid = (int) $order['id'];
        if (array_key_exists($oid, $overrides)) {
            return round((float) $overrides[$oid], 2);
        }

        return round(Commission::forPilot(
            $pilot['commission_type'] ?? null,
            (float) ($pilot['commission_value'] ?? 0),
            (float) ($order['total_delivery_price'] ?? 0),
        ), 2);
    }

    /* ═══════════════════════════════════════════════════════════
       صف اليوم
    ═══════════════════════════════════════════════════════════ */

    /**
     * ★ صف يوم واحد لطيار واحد: المحسوب من المنظومة + الـoverride اللي
     * فوقه. كل خانة بترجع بقيمتها النهائية **وبقيمتها المحسوبة** عشان
     * الواجهة تقدر تبيّن إن ده رقم متعدّل بالإيد.
     *
     * $auto: ['in','out','hours','orders','svc','psvc','adv','ded','bonus']
     *        المحسوبة من الورديات والأوردرات.
     * $entry: صف `pilot_day_entries` أو [] لو مفيش تدخّل.
     * $perms: فترات الاستئذان [['out'=>'HH:MM','in'=>'HH:MM'], ...]
     */
    public static function dayRow(int $day, array $auto, array $entry, array $perms): array
    {
        $ov = fn (string $k) => ($entry[$k] ?? null) !== null && $entry[$k] !== '' ? $entry[$k] : null;

        $in  = $ov('time_in_override')  ?? ($auto['in']  ?? null);
        $out = $ov('time_out_override') ?? ($auto['out'] ?? null);

        /* الساعات — تلات مصادر بالترتيب:

           ① المكتوب بالإيد (`hours_override`) — كلمة المشرف الأخيرة.

           ② لو المشرف كتب وقت حضور أو انصراف بإيده، فهو بيصف يوم مختلف
              عن الورديات المسجّلة — ساعتها المدى بين وقتيه هو المقصود.

           ③ وإلا: **مجموع مدد الورديات** اللي الكنترولر حسبه.

           🔴 التالتة دي كانت غلط: الكود كان بياخد المدى دايمًا
           (`autoHours($in,$out)`)، و`$in`/`$out` هما **أول حضور وآخر
           انصراف** في اليوم. طيار بوردية ٩ص→١ظ وتانية ٦م→١٠م شغل ٨
           ساعات، والمدى بيقول ١٣ — خمس ساعات راحة بتتدفع كأنها شغل.
           والمجموع الصح كان موجود في `$auto['hours']` وبيترمي، لأن
           `autoHours` مابترجّعش null غير لو وقت ناقص.

           ⚠️ الاستئذان: `autoHours` بتطرحه، ومجموع الورديات لأ —
           الكنترولر بيجمع المدد الخام. فالمسار التالت بيطرحه بنفسه،
           وإلا الطيار اللي أخد استئذان ساعة بياخدها مدفوعة. */
        $hoursOv = $ov('hours_override');
        $manualTime = $ov('time_in_override') !== null || $ov('time_out_override') !== null;

        if ($hoursOv !== null) {
            $hours = round((float) $hoursOv, 2);
        } elseif ($manualTime || ($auto['hours'] ?? null) === null) {
            $hours = self::autoHours($in, $out, $perms) ?? 0.0;
        } else {
            $mins  = ((float) $auto['hours']) * 60 - self::permMinutes($perms);
            $hours = round(max(0, $mins) / 60, 2);
        }

        $orders = $ov('orders_override') !== null
            ? (int) $ov('orders_override')
            : (int) ($auto['orders'] ?? 0);

        $svc = $ov('svc_override') !== null
            ? round((float) $ov('svc_override'), 2)
            : round((float) ($auto['svc'] ?? 0), 2);

        $psvc = $ov('psvc_override') !== null
            ? round((float) $ov('psvc_override'), 2)
            : round((float) ($auto['psvc'] ?? 0), 2);

        // صافي الخدمة = إجمالي − خدمة الطيار (أو المكتوب بالإيد)
        $net = $ov('net_override') !== null
            ? round((float) $ov('net_override'), 2)
            : round($svc - $psvc, 2);

        // السلف والخصومات والحوافز: اللي من الوردية + اللي اتكتب على الشيت
        $adv   = round((float) ($auto['adv']   ?? 0) + (float) ($ov('advance_extra')   ?? 0), 2);
        $ded   = round((float) ($auto['ded']   ?? 0) + (float) ($ov('deduction_extra') ?? 0), 2);
        $bonus = round((float) ($auto['bonus'] ?? 0) + (float) ($ov('bonus_extra')     ?? 0), 2);

        /* 🔴 الترحيل: إيه اللي **لسه مستحق** للطيار من اليوم ده.
           ورديات الشركة فيها `commission_settle` و`bonus_settle` و
           `deduction_settle` و`advance_settle`، وقيمتها الافتراضية
           `daily` — يعني الفلوس دي **اتصفّت مع الطيار عند قفل الوردية**.
           لو جمعناها في تقفيلة الشهر كمان يبقى الطيار خد فلوسه مرتين.

           الشيت بيعرض اللي حصل في اليوم كامل (ده سجل)، والتقفيلة بتاخد
           المرحّل بس. ونفس القاعدة في `buildMonthlyData` الموجودة أصلًا
           في النظام — فالرقمين بيتطابقوا. */
        $paidDailyPsvc = max(0.0, round((float) ($auto['psvc'] ?? 0) - (float) ($auto['psvcCarry'] ?? 0), 2));
        $carry = [
            // التدخّل اليدوي بيصحّح إجمالي اليوم — واللي اتدفع كاش يفضل مدفوع
            'psvc'  => round(max(0, $psvc - $paidDailyPsvc), 2),
            // المكتوب على الشيت بالإيد لسه مااتصفّاش فبيترحّل دايمًا
            'adv'   => round((float) ($auto['advCarry']   ?? 0) + (float) ($ov('advance_extra')   ?? 0), 2),
            'ded'   => round((float) ($auto['dedCarry']   ?? 0) + (float) ($ov('deduction_extra') ?? 0), 2),
            'bonus' => round((float) ($auto['bonusCarry'] ?? 0) + (float) ($ov('bonus_extra')     ?? 0), 2),
        ];

        return [
            'day'    => $day,
            'in'     => $in,
            'out'    => $out,
            'perms'  => array_values($perms),
            'hours'  => $hours,
            'orders' => $orders,
            'svc'    => $svc,
            'psvc'   => $psvc,
            'net'    => $net,
            'adv'    => $adv,
            'ded'    => $ded,
            'bonus'  => $bonus,
            // المرحّل للشهر — اللي لسه مستحق بعد تصفية الوردية اليومية
            'carry'  => $carry,
            'note'   => (string) ($entry['note'] ?? ''),
            // اللي النظام حسبه قبل أي تدخّل — الواجهة بتعرضه كتلميح
            'auto'   => [
                'in'     => $auto['in']     ?? null,
                'out'    => $auto['out']    ?? null,
                'hours'  => round((float) ($auto['hours']  ?? 0), 2),
                'orders' => (int) ($auto['orders'] ?? 0),
                'svc'    => round((float) ($auto['svc']    ?? 0), 2),
                'psvc'   => round((float) ($auto['psvc']   ?? 0), 2),
            ],
            // أنهي خانات فيها تدخّل بشري — عشان تتلوّن في الشاشة
            'edited' => array_values(array_filter([
                $ov('time_in_override')  !== null ? 'in'     : null,
                $ov('time_out_override') !== null ? 'out'    : null,
                $ov('hours_override')    !== null ? 'hours'  : null,
                $ov('orders_override')   !== null ? 'orders' : null,
                $ov('svc_override')      !== null ? 'svc'    : null,
                $ov('psvc_override')     !== null ? 'psvc'   : null,
                $ov('net_override')      !== null ? 'net'    : null,
            ])),
            'shiftIds' => array_values($auto['shiftIds'] ?? []),
            'openShift' => (bool) ($auto['openShift'] ?? false),
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       إجماليات الشهر
    ═══════════════════════════════════════════════════════════ */

    /**
     * 💰🔴 تقفيلة الشهر للطيار — المعادلة اللي صاحب النظام أقرّها:
     *
     *   أجر الساعات   = ساعات الشهر × سعر ساعته
     *   العمولة       = مجموع خدمة الطيار من الأوردرات
     *   نصيب الراتب   = المرتب الشهري ÷ الأيام المحسوبة × أيام الشغل
     *   إجازة مدفوعة  = min(الغياب, رصيده) × ساعات الوردية × سعر الساعة
     *   ───────────────────────────────────────────────
     *   الإجمالي      = أجر الساعات + العمولة + نصيب الراتب + الإجازة + الحوافز
     *   الصافي        = الإجمالي − السلف − الخصومات − قسط السلفة المؤجلة
     *
     * الفروق عن روح دمشق (كلها بقرار صاحب النظام):
     *  • دمشق بتحسب بسعر الساعة بس — هنا **ساعات + عمولة** مع بعض.
     *  • المرتب الشهري بيتقسّم على أيام الشغل (دمشق مالهاش مرتب أصلًا).
     *  • فيه **حوافز**: ورديات الشركة فيها `bonus_amount` ودمشق مافيهاش،
     *    وإهمالها كان هيضيّع فلوس مستحقة للطيار.
     */
    public static function monthTotals(array $days, array $pilot, array $opts): array
    {
        $t = ['hours' => 0.0, 'orders' => 0, 'svc' => 0.0, 'psvc' => 0.0,
              'net' => 0.0, 'adv' => 0.0, 'ded' => 0.0, 'bonus' => 0.0, 'worked' => 0];

        /* 🔴 مجموعتين مختلفتين عن قصد:
             • `$t[...]`      = اللي حصل في الشهر كله — ده **عرض**.
             • `$c[...]`      = اللي لسه **مستحق** بعد تصفية الورديات اليومية،
                                  وده **الوحيد** اللي بيدخل حسبة الفلوس تحت.
           الخلط بينهم بيدفع للطيار مرتين. */
        $c = ['psvc' => 0.0, 'adv' => 0.0, 'ded' => 0.0, 'bonus' => 0.0];

        foreach ($days as $d) {
            $t['hours']  += (float) $d['hours'];
            $t['orders'] += (int) $d['orders'];
            $t['svc']    += (float) $d['svc'];
            $t['psvc']   += (float) $d['psvc'];
            $t['net']    += (float) $d['net'];
            $t['adv']    += (float) $d['adv'];
            $t['ded']    += (float) $d['ded'];
            $t['bonus']  += (float) $d['bonus'];
            foreach (['psvc','adv','ded','bonus'] as $k) {
                $c[$k] += (float) ($d['carry'][$k] ?? 0);
            }
            if ((float) $d['hours'] > 0) {
                $t['worked']++;
            }
        }
        foreach (['hours','svc','psvc','net','adv','ded','bonus'] as $k) {
            $t[$k] = round($t[$k], 2);
        }
        foreach ($c as $k => $v) {
            $c[$k] = round($v, 2);
        }
        // اللي اتصفّى كاش مع الطيار يوم بيوم — بيتعرض عشان يبان ليه الفرق
        $t['settledDaily'] = [
            'psvc'  => round($t['psvc']  - $c['psvc'], 2),
            'adv'   => round($t['adv']   - $c['adv'], 2),
            'ded'   => round($t['ded']   - $c['ded'], 2),
            'bonus' => round($t['bonus'] - $c['bonus'], 2),
        ];

        $hourRate   = self::hourRateOf($pilot, $opts['settings'] ?? []);
        $shiftHours = (float) ($opts['shiftHours'] ?? self::DEFAULT_SHIFT_HOURS) ?: self::DEFAULT_SHIFT_HOURS;
        $counted    = (int) ($opts['countedDays'] ?? 0);

        $t['hourRate']    = round($hourRate, 2);
        $t['hourPay']     = round($t['hours'] * $hourRate, 2);
        // العمولة المستحقة = المرحّلة بس. اللي اتاخد كاش مش بيتدفع تاني.
        $t['commission']  = $c['psvc'];

        /* نصيب الراتب الشهري — قرار صاحب النظام: «بيتقسّم على أيام الشغل».
           لو الأيام المحسوبة صفر (شهر لسه مابدأش) النصيب صفر مش قسمة على صفر. */
        $salary = (float) ($pilot['monthly_salary'] ?? 0);
        $t['monthlySalary'] = round($salary, 2);
        $t['salaryShare']   = $counted > 0 ? round($salary / $counted * $t['worked'], 2) : 0.0;

        /* الإجازة المدفوعة: أي يوم ساعاته صفر جوه الأيام المحسوبة = غياب،
           وأول أيام الغياب بتستهلك رصيد الإجازة والباقي غياب من غير أجر. */
        $t['countedDays'] = $counted;
        $t['absent']      = max(0, $counted - $t['worked']);
        $t['leaveCap']    = max(0, (int) ($pilot['paid_leave_days'] ?? 0));
        $t['leaveDays']   = min($t['absent'], $t['leaveCap']);
        $t['leaveLeft']   = $t['leaveCap'] - $t['leaveDays'];
        $t['shiftHours']  = $shiftHours;
        $t['leavePay']    = round($t['leaveDays'] * $shiftHours * $hourRate, 2);

        $t['gross'] = round($t['hourPay'] + $t['commission'] + $t['salaryShare'] + $t['leavePay'] + $c['bonus'], 2);

        $t['deferredDue']  = round((float) ($opts['deferredDue']  ?? 0), 2);
        $t['deferredLeft'] = round((float) ($opts['deferredLeft'] ?? 0), 2);

        // السلف والخصومات المرحّلة بس — اللي اتخصم من الوردية خلاص اتخصم
        $t['advanceDue']   = $c['adv'];
        $t['deductionDue'] = $c['ded'];
        $t['bonusDue']     = $c['bonus'];

        $t['netDue'] = round($t['gross'] - $c['adv'] - $c['ded'] - $t['deferredDue'], 2);

        return $t;
    }

    /* ═══════════════════════════════════════════════════════════
       🔐 الصلاحيات — الشاشات والأعمدة والأفعال

       المفاتيح دي هي **العقد**: الاسم اللي هنا هو نفسه اللي في
       `pilot_acct_perms.perm_keys` ونفسه اللي الواجهة بتتحكم بيه.
       أي مفتاح جديد لازم يتضاف هنا الأول — الواجهة بتبني شاشة
       الصلاحيات من الرد ده مش من قايمة عندها، عشان مايحصلش اختلاف
       بين اللي الأدمن بيعلّم عليه واللي السيرفر بيفهمه.
    ═══════════════════════════════════════════════════════════ */

    public static function permGroups(): array
    {
        return [
            ['title' => 'الشاشات', 'items' => [
                ['page.daily',    'تقفيل يومي — كل الطيارين في يوم'],
                ['page.pilot',    'كشف الطيار — شهر طيار واحد'],
                ['page.month',    'تقفيلة الشهر والرواتب'],
                ['page.deferred', 'السلف المؤجلة'],
                ['page.staff',    'تقفيل الموظفين — الحضور من المتصفح'],
                /* الخزنة جوه برنامج التقفيل (طلب صاحب النظام 2026-09-04) — نفس خزن
                   لوحة الإدارة ونفس مساراتها، مشرف الفرع بيشوف خزنة فرعه بس */
                ['page.treasury', 'الخزنة — الخزن وحركاتها'],
            ]],
            ['title' => 'أعمدة الشيت اليومي وكشف الطيار', 'items' => [
                ['col.in',     'ساعة الحضور'],
                ['col.bout',   'خروج مؤقت (استئذان)'],
                ['col.bin',    'الرجوع من الاستئذان'],
                ['col.out',    'ساعة الانصراف'],
                ['col.hours',  'عدد ساعات العمل'],
                ['col.orders', 'عدد الأوردرات'],
                ['col.svc',    'إجمالي الخدمة'],
                ['col.psvc',   'خدمة الطيار (عمولته)'],
                ['col.net',    'صافي الخدمة'],
                ['col.adv',    'سلف'],
                ['col.ded',    'خصومات'],
                ['col.bonus',  'حوافز'],
                ['col.note',   'ملاحظات'],
            ]],
            ['title' => 'أعمدة تقفيلة الشهر', 'items' => [
                ['mon.hours',      'الساعات وأيام الشغل'],
                ['mon.orders',     'عدد الأوردرات'],
                ['mon.hourPay',    'أجر الساعات'],
                ['mon.commission', 'العمولة'],
                ['mon.salary',     'نصيب الراتب الشهري'],
                ['mon.leave',      'الإجازة المدفوعة'],
                ['mon.bonus',      'الحوافز'],
                ['mon.adv',        'السلف'],
                ['mon.ded',        'الخصومات'],
                ['mon.deferred',   'قسط السلفة المؤجلة'],
                ['mon.net',        'صافي الراتب'],
            ]],
            /* بلوك التقفيل تحت شيت اليوم — نفس بلوك «تقفيل روح دمشق» بعد ما
               صاحب النظام طلبه هنا (2026-09-04) من غير «نسبة روح دمشق». */
            ['title' => 'بلوك التقفيل تحت الجدول', 'items' => [
                ['blk.hourPay',  'أجر الساعات'],
                ['blk.devFee',   'رسوم التطوير'],
                ['blk.ext',      'الخارجي'],
                ['blk.exp',      'مصاريف'],
                ['blk.out',      'إجمالي الخارج'],
                ['blk.cash',     'إجمالي نقدي'],
                ['blk.net',      'صافي الفرع'],
                ['blk.adv',      'سلف مع الطيارين'],
                ['blk.recon',    'مطابقة توريد المشرف'],
                ['blk.branches', 'صافي كل الفروع'],
            ]],
            ['title' => 'الأفعال', 'items' => [
                ['act.edit',     'تعديل الخانات (من غيرها بيتفرّج بس)'],
                ['act.dateNav',  'التنقل بين الأيام والشهور'],
                ['act.deferred', 'تسجيل وتعديل وتحصيل السلف المؤجلة'],
                ['act.lock',     'قفل الشهر وفتحه'],
                ['act.settings', 'تعديل إعدادات البرنامج'],
                /* صرف الراتب من الخزنة (طلب صاحب النظام 2026-09-04) — فلوس بتخرج فعلًا، للإدارة افتراضيًا */
                ['act.payout',   'صرف الرواتب من الخزنة'],
            ]],
        ];
    }

    /** كل المفاتيح المعروفة مسطّحة — أي مفتاح برّه دي بيتترمي عند الحفظ */
    public static function permKeys(): array
    {
        $out = [];
        foreach (self::permGroups() as $g) {
            foreach ($g['items'] as $it) {
                $out[] = $it[0];
            }
        }

        return $out;
    }

    /**
     * صلاحيات كاملة — الشكل اللي الأدمن (وأي حد لسه مالوش صف) بيشتغل بيه.
     *
     * 🔴 الافتراضي «افتح» مش «اقفل»، وده **مقصود**: البرنامج شغال لايف
     * والمحاسب ومشرفي الفروع بيستعملوه دلوقتي من غير أي صف صلاحيات.
     * منع افتراضي كان معناه إن أول رفعة تقفل عليهم البرنامج في نص يوم
     * شغل. الصف لما يتحفظ هو اللي بيضيّق — مش غيابه.
     */
    public static function fullPermKeys(): array
    {
        $out = [];
        foreach (self::permKeys() as $k) {
            $out[$k] = true;
        }

        return $out;
    }

    /**
     * 🔴 المفاتيح المحجوزة للإدارة **افتراضيًا**.
     *
     * دي التلاتة اللي كانت مسارها `role:admin` لوحده قبل شاشة الصلاحيات.
     * لما وسّعنا المسار عشان الأدمن يقدر يمنحها، بقى لازم اللي مالوش صف
     * **ما ياخدهاش** — وإلا أول رفعة كانت هتدي كل مشرف فرع قفل الشهر
     * وتعديل السلف، وهو مش عنده الحقين دول النهاردة.
     *
     * يعني: الافتراضي = اللي بيقدر يعمله دلوقتي بالظبط. لا أكتر ولا أقل.
     */
    public const ADMIN_ONLY_KEYS = ['act.deferred', 'act.lock', 'act.settings', 'act.payout'];

    /** الافتراضي لمن مالوش صف: كل حاجة ماعدا المحجوز للإدارة */
    public static function defaultPermKeys(): array
    {
        $out = self::fullPermKeys();
        foreach (self::ADMIN_ONLY_KEYS as $k) {
            unset($out[$k]);
        }

        return $out;
    }

    /** قوالب جاهزة — الواجهة بتعرضها كأزرار، والسيرفر هو مصدرها */
    public static function permPresets(): array
    {
        return [
            'supervisor' => [
                'label' => 'مشرف فرع — يسجّل بس',
                'keys'  => ['page.daily', 'page.pilot',
                    'col.in', 'col.bout', 'col.bin', 'col.out', 'col.hours',
                    'col.orders', 'col.adv', 'col.ded', 'col.bonus', 'col.note',
                    /* اللي المشرف بيكتبه ويسلّمه: الخارجي والمصاريف والمستلم — من غير
                       أجر الساعات ولا صافي الفرع (زي مشرفي دمشق على Firebase) */
                    'blk.ext', 'blk.exp', 'blk.cash', 'blk.adv', 'blk.recon',
                    'act.edit', 'act.dateNav'],
            ],
            'accountant' => [
                'label' => 'محاسب — يشوف الفلوس كلها',
                'keys'  => array_values(array_diff(self::permKeys(), ['act.settings'])),
            ],
            'viewer' => [
                'label' => 'متفرّج — يقرا ومايكتبش',
                'keys'  => ['page.daily', 'page.pilot', 'page.month',
                    'col.in', 'col.out', 'col.hours', 'col.orders', 'col.note',
                    'mon.hours', 'mon.orders', 'act.dateNav'],
            ],
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       💰🔴 تقفيلة الفرع في اليوم — نفس branchDayCloseout في «تقفيل روح دمشق»
       من غير «نسبة روح دمشق» (قرار صاحب النظام 2026-09-04: «شيل النسبة بس
       لأن رسوم التطوير معانا»).

         أجر الساعات    = Σ ساعات كل طيار × سعر ساعته (كل طيار بسعره)
         رسوم التطوير   = أوردرات × orderRate — لو فيه فرع متحمّل، هو بياخد
                          أوردرات كل الفروع وباقي الفروع صفر
         إجمالي الخارج  = أجر الساعات + رسوم التطوير + الخارجي + المصاريف
                          (السلف مش مصروف — فلوس الشركة مع الطيار)
         إجمالي نقدي    = Σ عمود «صافي الخدمة»
         المفروض يورّده = النقدي − الخارجي − المصاريف − السلف − رسوم التطوير
         الفرق          = المستلم − المفروض (لو المستلم مكتوب)
         صافي الفرع     = النقدي − إجمالي الخارج + الفرق
       والهوية اللي لازم تتحقق لما المستلم مكتوب: net === received + adv − hourPay.
    ═══════════════════════════════════════════════════════════ */

    /**
     * @param array $rows     صفوف اليوم لطياري الفرع: [['row' => dayRow, 'hourRate' => float], ...]
     * @param array $summary  اللي المشرف كتبه: ['ext' => ?, 'exp' => ?, 'recv' => ?]
     * @param array $settings إعدادات القسم (orderRate, devFeeBranchId)
     * @param float $allOrders أوردرات الشركة كلها في اليوم — للفرع المتحمّل رسوم التطوير
     */
    public static function branchDayCloseout(array $rows, array $summary, array $settings, int $branchId, float $allOrders): array
    {
        $t = ['h' => 0.0, 'o' => 0.0, 'net' => 0.0, 'adv' => 0.0, 'hourPay' => 0.0, 'count' => 0];
        foreach ($rows as $r) {
            $d = $r['row'];
            $h = (float) ($d['hours'] ?? 0);
            $t['h']       += $h;
            $t['o']       += (float) ($d['orders'] ?? 0);
            $t['net']     += (float) ($d['net'] ?? 0);
            $t['adv']     += (float) ($d['adv'] ?? 0);
            $t['hourPay'] += $h * (float) ($r['hourRate'] ?? 0);
            if ($h || (float) ($d['orders'] ?? 0) || (float) ($d['net'] ?? 0) || (float) ($d['adv'] ?? 0) || (float) ($d['svc'] ?? 0)) {
                $t['count']++;
            }
        }
        foreach (['h', 'o', 'net', 'adv', 'hourPay'] as $k) {
            $t[$k] = round($t[$k], 2);
        }

        $devRate = max(0.0, (float) ($settings['orderRate'] ?? 0));
        $dfb     = (int) ($settings['devFeeBranchId'] ?? 0);
        $devFeeOrders = $dfb > 0 ? ($branchId === $dfb ? $allOrders : 0.0) : $t['o'];
        $devFee  = round($devFeeOrders * $devRate, 2);

        $ext = round((float) ($summary['ext'] ?? 0), 2);
        $exp = round((float) ($summary['exp'] ?? 0), 2);
        $outTotal = round($t['hourPay'] + $devFee + $ext + $exp, 2);
        $cash     = $t['net'];
        $expected = round($cash - $ext - $exp - $t['adv'] - $devFee, 2);
        $hasRecv  = array_key_exists('recv', $summary) && $summary['recv'] !== null && $summary['recv'] !== '';
        $received = $hasRecv ? round((float) $summary['recv'], 2) : 0.0;
        $diff     = $hasRecv ? round($received - $expected, 2) : 0.0;

        return [
            'branchId'     => $branchId,
            'hours'        => $t['h'],
            'orders'       => $t['o'],
            'count'        => $t['count'],
            'hourPay'      => $t['hourPay'],
            'devFeeOrders' => round($devFeeOrders, 2),
            'devFee'       => $devFee,
            'ext'          => $ext,
            'exp'          => $exp,
            'outTotal'     => $outTotal,
            'cash'         => $cash,
            'adv'          => $t['adv'],
            'expected'     => $expected,
            'received'     => $received,
            'hasRecv'      => $hasRecv,
            'diff'         => $diff,
            'net'          => round($cash - $outTotal + $diff, 2),
        ];
    }

    /** أنهي بنود البلوك مسموحة — العمود الممنوع بيتشال من الـJSON خالص (نفس قاعدة القصّ) */
    public static function filterCloseout(array $c, array $keys): array
    {
        $map = [
            'hourPay' => 'blk.hourPay', 'hours' => 'blk.hourPay',
            'devFee' => 'blk.devFee', 'devFeeOrders' => 'blk.devFee',
            'ext' => 'blk.ext', 'exp' => 'blk.exp', 'outTotal' => 'blk.out',
            'cash' => 'blk.cash', 'net' => 'blk.net', 'adv' => 'blk.adv',
            'expected' => 'blk.recon', 'received' => 'blk.recon', 'hasRecv' => 'blk.recon', 'diff' => 'blk.recon',
        ];
        foreach ($map as $field => $perm) {
            if (($keys[$perm] ?? null) !== true) {
                unset($c[$field]);
            }
        }

        return $c;
    }

    /* ═══ القصّ ═══

       العمود الممنوع بيتشال من الـJSON **خالص** — مش بيترجع صفر ولا
       null. ده فرق مهم: الواجهة بتعرف الفرق بين «صفر» و«مش مسموح»،
       ولو حد فتح أدوات المتصفح مش هيلاقي الرقم مستني في الشبكة.
    ═══ */

    /** بيقص صف اليوم على الأعمدة المسموحة. `day` و`carry` بيفضلوا دايمًا. */
    public static function filterDay(array $row, array $keys): array
    {
        $map = [
            'in'     => 'col.in',
            'out'    => 'col.out',
            'hours'  => 'col.hours',
            'orders' => 'col.orders',
            'svc'    => 'col.svc',
            'psvc'   => 'col.psvc',
            'net'    => 'col.net',
            'adv'    => 'col.adv',
            'ded'    => 'col.ded',
            'bonus'  => 'col.bonus',
            'note'   => 'col.note',
        ];
        foreach ($map as $field => $key) {
            if (($keys[$key] ?? null) !== true) {
                unset($row[$field]);
            }
        }

        /* الاستئذان عمودين في الشاشة وحقل واحد في الرد. بيتشال لما
           الاتنين مقفولين — لو واحد منهم مفتوح الشاشة محتاجة القيمة. */
        if (($keys['col.bout'] ?? null) !== true && ($keys['col.bin'] ?? null) !== true) {
            unset($row['perms']);
        }

        /* 🔴 `auto` و`edited` بيشاوروا على **نفس** الأعمدة بأسمائها.
           قصّ العمود من غيرهم = الرقم بيخرج من الباب الخلفي: `auto.svc`
           فيه إجمالي الخدمة اللي النظام حسبه، و`edited` بيقول إن الخانة
           اللي مالوش صلاحية عليها فيها تدخّل. اتلقت في اختبار HTTP
           حقيقي بعد ما القصّ الأساسي كان شغّال — الرد كان نضيف والفلوس
           خارجة جوه `auto`. */
        foreach (array_keys($row['auto'] ?? []) as $f) {
            if (! array_key_exists($f, $row)) {
                unset($row['auto'][$f]);
            }
        }
        if (isset($row['edited'])) {
            $row['edited'] = array_values(array_filter(
                $row['edited'],
                fn ($f): bool => array_key_exists($f, $row)
            ));
        }

        return $row;
    }

    /** بيقص إجماليات الشهر على الأعمدة المسموحة */
    public static function filterTotals(array $t, array $keys): array
    {
        $map = [
            'hours'         => 'mon.hours',
            'worked'        => 'mon.hours',
            'countedDays'   => 'mon.hours',
            'absent'        => 'mon.hours',
            'orders'        => 'mon.orders',
            'hourRate'      => 'mon.hourPay',
            'hourPay'       => 'mon.hourPay',
            'commission'    => 'mon.commission',
            'monthlySalary' => 'mon.salary',
            'salaryShare'   => 'mon.salary',
            'leaveCap'      => 'mon.leave',
            'leaveDays'     => 'mon.leave',
            'leaveLeft'     => 'mon.leave',
            'leavePay'      => 'mon.leave',
            'bonusDue'      => 'mon.bonus',
            'advanceDue'    => 'mon.adv',
            'deductionDue'  => 'mon.ded',
            'deferredDue'   => 'mon.deferred',
            'deferredLeft'  => 'mon.deferred',
            'gross'         => 'mon.net',
            'netDue'        => 'mon.net',
        ];
        foreach ($map as $field => $key) {
            if (($keys[$key] ?? null) !== true) {
                unset($t[$field]);
            }
        }

        return $t;
    }
}
