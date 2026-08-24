<?php

declare(strict_types=1);

namespace App\Wire;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 🔴🔴 طبقة «روح دمشق» — نقل حرفي سطر بسطر من api/routes/damascus.php.
 *
 * الوحدة دي **حساباتها مجمّدة بفحص تفاضلي** (diff_damascus: 36,064 حقل
 * مقابل النظام القديم بصفر اختلاف). أي حرف بيتغير في `branchDayCloseout`
 * أو `pilotMonthTotals` أو الدوال اللي بيعتمدوا عليها = كسر الضمان ده.
 * ممنوع «تحسين» أي معادلة أو تقريب هنا.
 *
 * القواعد الحاكمة (منقولة من ترويسة الملف الأصلي عشان تفضل قدام العين):
 *   • ساعات اليوم: المكتوب بإيده لو موجود، وإلا (out − in) mod 24h ناقص
 *     دقايق الاستئذان، بأرضية صفر ومقرّبة لخانتين. الوردية اللي بتعدي نص
 *     الليل بتتحسب صح بالـ mod (اليوم التجاري بيبدأ 8 صباحًا).
 *   • خدمة الطيار = override لو مكتوب، وإلا أوردراته × سعر أوردره.
 *     صافي الخدمة = override لو مكتوب، وإلا (الخدمة − خدمة الطيار).
 *   • تقفيلة الفرع اليومي:
 *        outTotal = أجر الساعات + رسوم التطوير + النسبة + الخارجي + المصاريف
 *                   (السلف مش مصروف — فلوس الشركة مع الطيار)
 *        expected = النقدي − النسبة − الخارجي − المصاريف − السلف − رسوم التطوير
 *                   (المشرف بيدفع رسوم التطوير كاش من إيده)
 *        net      = النقدي − outTotal + فرق التوريد
 *     والهوية اللي لازم تتحقق دايمًا لما يكون فيه توريد مستلم:
 *        net === received + adv − hourPay
 *
 * ليه كلاس ساكن على مصفوفات مش Resource: نفس سبب باقي طبقة السلك —
 * الفحص التفاضلي بيقارن ناتج الدالة بالدالة الأصلية مباشرة، وأي غلاف
 * بينهم (data / when()) بيسرّب اختلاف صامت.
 *
 * ⚠️ الدوال اللي بتلمس القاعدة (تحميل السياق والصلاحيات والأقفال) مش هنا —
 * هي في DamascusController. اللي هنا **حساب خالص على مصفوفات**.
 */
final class DamascusWire
{
    /* ═══════════════════════════════════════════════════════════
       ثوابت المجال — rd_default_settings / rd_perm_groups
    ═══════════════════════════════════════════════════════════ */

    /** الإعدادات الافتراضية — نفس DEFAULT_SETTINGS في النظام القديم */
    public static function defaultSettings(): array
    {
        return [
            'hourRate'       => 30,   // أجر ساعة الطيار الافتراضي
            'orderRate'      => 2,    // رسوم التطوير على كل أوردر (بتاعة الشركة)
            'pilotOrderRate' => 0,    // خدمة الطيار على كل أوردر (الافتراضي)
            'restName'       => 'روح دمشق',
            'shiftHours'     => 10,   // ساعات الوردية (بتضرب في أيام الإجازة المدفوعة)
            'dayStart'       => 8,    // اليوم التجاري بيبدأ الساعة كام
            'devFeeBranchId' => '',   // الفرع المتحمّل رسوم التطوير كلها — فاضي = كل فرع بأوردراته
        ];
    }

    /** مجموعات الصلاحيات — نفس PERM_GROUPS في النظام القديم (شاشة الصلاحيات) */
    public static function permGroups(): array
    {
        return [
            ['title' => 'الشاشات', 'items' => [
                ['page.daily', 'التقفيل اليومي'],
                ['page.pilot', 'كشف الطيار'],
                ['page.month', 'تقفيل الشهر'],
                ['page.deferred', 'السلف المؤجلة'],
                ['page.setup', 'الفروع والطيارين'],
                ['page.settings', 'الإعدادات'],
            ]],
            ['title' => 'أعمدة الجدول', 'items' => [
                ['col.in', 'ساعة الحضور'],
                ['col.bout', 'خروج مؤقت (استئذان)'],
                ['col.bin', 'الرجوع من الاستئذان'],
                ['col.out', 'ساعة الانصراف'],
                ['col.h', 'عدد ساعات العمل'],
                ['col.o', 'عدد الأوردرات'],
                ['col.svc', 'إجمالي الخدمة'],
                ['col.psvc', 'خدمة الطيار'],
                ['col.net', 'صافي الخدمة'],
                ['col.adv', 'سلف'],
                ['col.ded', 'خصومات'],
                ['col.note', 'ملاحظات'],
            ]],
            ['title' => 'بلوك التقفيل تحت الجدول', 'items' => [
                ['blk.hourPay', 'أجر الساعات'],
                ['blk.devFee', 'رسوم التطوير'],
                ['blk.pct', 'نسبة روح دمشق'],
                ['blk.ext', 'الخارجي'],
                ['blk.exp', 'مصاريف'],
                ['blk.out', 'إجمالي الخارج'],
                ['blk.cash', 'إجمالي نقدي'],
                ['blk.net', 'صافي الفرع'],
                ['blk.adv', 'إجمالي سلف'],
                ['blk.recon', 'مطابقة توريد المشرف'],
                ['blk.branches', 'صافي كل الفروع'],
            ]],
            ['title' => 'حساب الراتب في كشف الطيار', 'items' => [
                ['sal.block', 'بلوك حساب الراتب كله'],
                ['sal.leave', 'الإجازة المدفوعة'],
                ['sal.def', 'قسط السلف المؤجلة'],
            ]],
            ['title' => 'صلاحيات عامة', 'items' => [
                ['act.edit', 'تعديل وكتابة البيانات (من غيرها بيتفرّج بس)'],
                ['act.dateNav', 'التنقل بين التواريخ والشهور'],
                ['act.export', 'تصدير إكسل'],
                ['act.print', 'طباعة'],
                ['act.pilots', 'إضافة وتعديل الطيارين'],
                ['act.branches', 'إضافة وتعديل وحذف الفروع (للإدارة عادةً)'],
                ['act.deferred', 'تسجيل وتعديل السلف المؤجلة'],
            ]],
        ];
    }

    /** أسماء كشوفها مخفية عن أي حد غير الأدمن (كشف الطيار وكشف الشهر بس) */
    public static function hiddenSheetNames(): array
    {
        return ['عبدالرحمن', 'علام', 'سمكه', 'سمكة'];
    }

    /** الحقول المسموح تعديلها في الخانة: مفتاح السلك → (عمود، مفتاح صلاحية، نوع) */
    public static function entryFields(): array
    {
        return [
            'in'   => ['time_in',       'col.in',   'time'],
            'out'  => ['time_out',      'col.out',  'time'],
            'h'    => ['hours',         'col.h',    'num'],
            'o'    => ['orders_count',  'col.o',    'num'],
            'svc'  => ['svc',           'col.svc',  'num'],
            'psvc' => ['psvc_override', 'col.psvc', 'num'],
            'net'  => ['net_override',  'col.net',  'num'],
            'adv'  => ['advance',       'col.adv',  'num'],
            'ded'  => ['deduction',     'col.ded',  'num'],
            'note' => ['note',          'col.note', 'text'],
        ];
    }

    /** بنود الملخص اليومي للفرع: مفتاح السلك (= اسم العمود) → مفتاح الصلاحية */
    public static function summaryFields(): array
    {
        return ['pct' => 'blk.pct', 'ext' => 'blk.ext', 'exp' => 'blk.exp', 'recv' => 'blk.recon'];
    }

    /* ═══════════════════════════════════════════════════════════
       أدوات صغيرة — rd_num / rd_round2 / rd_has
    ═══════════════════════════════════════════════════════════ */

    /**
     * ⚠️ منقولة بالحرف: `is_bool` بترجع 1.0/0.0، وأي حاجة مش رقمية بترجع 0.0.
     * الترتيب مهم — الفحص على null/'' قبل is_numeric.
     */
    public static function num(mixed $v): float
    {
        if ($v === null || $v === '' || is_bool($v)) {
            return is_bool($v) ? (float) $v : 0.0;
        }
        if (! is_numeric($v)) {
            return 0.0;
        }

        return (float) $v;
    }

    /** 🔴 التقريب الوحيد المسموح — خانتين، وفي نفس مواضع الأصل بالظبط */
    public static function round2(mixed $n): float
    {
        return round((float) $n, 2);
    }

    /** هل الحقل «مكتوب»؟ (مش null ولا نص فاضي) — نفس فحص النظام القديم */
    public static function has(mixed $v): bool
    {
        return $v !== null && $v !== '';
    }

    /* ═══════════════════════════════════════════════════════════
       التقويم والوقت
    ═══════════════════════════════════════════════════════════ */

    public static function daysInMonth(string $ym): int
    {
        [$y, $m] = array_map('intval', explode('-', $ym));

        return (int) cal_days_in_month(CAL_GREGORIAN, $m, $y);
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
     * اليوم الحالي بتوقيت القاهرة (نفس مرجع النظام القديم todayStr).
     * ⚠️ القاهرة مش UTC — «اليوم» هنا هو اليوم اللي الناس بتشتغله.
     */
    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo')))->format('Y-m-d');
    }

    public static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /** "HH:MM" → دقايق من نص الليل، أو null لو مش وقت صالح */
    public static function parseTime(mixed $t): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) ($t ?? '')), $m)) {
            return null;
        }
        $hh = (int) $m[1];
        $mm = (int) $m[2];
        if ($hh > 23 || $mm > 59) {
            return null;
        }

        return $hh * 60 + $mm;
    }

    /** بيقبل 1630 و 16.30 و 16:30 و 16 ويطلّعهم "16:30" — نفس normalizeTime القديمة */
    public static function normalizeTime(mixed $raw): string
    {
        $orig = trim((string) ($raw ?? ''));
        $v = preg_replace('/[.\s]/', ':', $orig);
        if ($v === '') {
            return '';
        }
        if (preg_match('/^\d{1,2}$/', $v)) {
            $v = $v . ':00';
        } elseif (preg_match('/^\d{3}$/', $v)) {
            $v = $v[0] . ':' . substr($v, 1);
        } elseif (preg_match('/^\d{4}$/', $v)) {
            $v = substr($v, 0, 2) . ':' . substr($v, 2);
        }
        if (! preg_match('/^(\d{1,2}):(\d{1,2})$/', $v, $m)) {
            return $orig;
        }
        $hh = (int) $m[1];
        $mm = (int) $m[2];
        if ($hh > 23 || $mm > 59) {
            return $orig;
        }

        return str_pad((string) $hh, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $mm, 2, '0', STR_PAD_LEFT);
    }

    /**
     * دقايق الاستئذان في نص اليوم — مجموع كل الفترات.
     * $e['perms'] = [ {out, in}, ... ]؛ ولو مفيش، بنقرا bout/bin القديمة.
     */
    public static function permsOfEntry(array $e): array
    {
        if (isset($e['perms']) && is_array($e['perms']) && $e['perms']) {
            $out = [];
            foreach ($e['perms'] as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $o = (string) ($p['out'] ?? '');
                $i = (string) ($p['in'] ?? '');
                if ($o === '' && $i === '') {
                    continue;
                }
                $out[] = ['out' => $o, 'in' => $i];
            }

            return $out;
        }
        $bo = (string) ($e['bout'] ?? '');
        $bi = (string) ($e['bin'] ?? '');
        if ($bo !== '' || $bi !== '') {
            return [['out' => $bo, 'in' => $bi]];
        }

        return [];
    }

    public static function breakMinutes(array $e): int
    {
        $total = 0;
        foreach (self::permsOfEntry($e) as $p) {
            $a = self::parseTime($p['out']);
            $b = self::parseTime($p['in']);
            if ($a === null || $b === null) {
                continue;
            }
            $d = $b - $a;
            if ($d < 0) {
                $d += 24 * 60;
            }
            $total += $d;
        }

        return $total;
    }

    /** الساعات المحسوبة من الحضور/الانصراف — null لو الوقتين مش كاملين */
    public static function autoHours(array $e): ?float
    {
        $a = self::parseTime($e['in'] ?? null);
        $b = self::parseTime($e['out'] ?? null);
        if ($a === null || $b === null) {
            return null;
        }
        $d = $b - $a;
        if ($d < 0) {
            $d += 24 * 60;                  // وردية عدّت نص الليل
        }
        $d -= self::breakMinutes($e);       // ناقص وقت الاستئذان

        return self::round2(max(0, $d) / 60);
    }

    /** ★ ساعات اليوم — المكتوب بإيده لو موجود، وإلا المحسوب */
    public static function hoursOf(array $e): float
    {
        if (self::has($e['h'] ?? null)) {
            return self::num($e['h']);
        }
        $a = self::autoHours($e);

        return $a === null ? 0.0 : $a;
    }

    /* ═══════════════════════════════════════════════════════════
       الأسعار والخدمة
    ═══════════════════════════════════════════════════════════ */

    /** سعر ساعة الطيار وسعر أوردره — بتوعه هو، وإلا الافتراضي من الإعدادات */
    public static function rateOf(?array $pilot, array $settings): array
    {
        $ph = self::num($pilot['hourRate'] ?? 0);
        $po = self::num($pilot['orderRate'] ?? 0);

        return [
            'h' => $ph > 0 ? $ph : self::num($settings['hourRate'] ?? 0),
            'o' => $po > 0 ? $po : self::num($settings['pilotOrderRate'] ?? 0),
        ];
    }

    /** رسوم التطوير على الأوردر — بتاعة الشركة، واحدة للكل */
    public static function devRate(array $settings): float
    {
        return self::num($settings['orderRate'] ?? 0);
    }

    /** ★ خدمة الطيار = المكتوب لو موجود، وإلا أوردراته × سعر أوردره */
    public static function pilotSvc(array $e, ?array $pilot, array $settings): float
    {
        if (self::has($e['psvc'] ?? null)) {
            return self::num($e['psvc']);
        }

        return self::round2(self::num($e['o'] ?? 0) * self::rateOf($pilot, $settings)['o']);
    }

    /** ★ صافي الخدمة = المكتوب لو موجود، وإلا (الخدمة − خدمة الطيار) */
    public static function netOf(array $e, ?array $pilot, array $settings): float
    {
        if (self::has($e['net'] ?? null)) {
            return self::num($e['net']);
        }

        return self::round2(self::num($e['svc'] ?? 0) - self::pilotSvc($e, $pilot, $settings));
    }

    /* ═══════════════════════════════════════════════════════════
       طبقة السلك — صفوف القاعدة → لسان النظام القديم
    ═══════════════════════════════════════════════════════════ */

    /** بيقبل صف كـarray أو stdClass — DB::select بيرجّع stdClass */
    public static function row(array|object $row): array
    {
        return is_array($row) ? $row : (array) $row;
    }

    public static function branch(array|object $row): array
    {
        $r = self::row($row);

        return [
            'id'     => (int) $r['id'],
            'key'    => $r['legacy_key'],
            'name'   => $r['name'],
            'active' => (bool) $r['active'],
        ];
    }

    public static function pilot(array|object $row): array
    {
        $r = self::row($row);

        return [
            'id'        => (int) $r['id'],
            'key'       => $r['legacy_key'],
            'branchId'  => (int) $r['branch_id'],
            'name'      => $r['name'],
            'active'    => (bool) $r['active'],
            'job'       => $r['job'],
            'hourRate'  => (float) $r['hour_rate'],
            'orderRate' => (float) $r['order_rate'],
            'leaveDays' => (int) $r['leave_days'],
        ];
    }

    /**
     * صف rd_entries → لسان النظام القديم (in/out/h/o/svc/psvc/net/adv/ded/note/perms).
     *
     * ⚠️ المفاتيح **بتتشال خالص** لما تكون فاضية — مش بترجع null. الواجهة
     * بتفرّق بين «العمود مش مكتوب» و«العمود بصفر»، والفرق ده هو اللي بيقرر
     * هل الساعات تتحسب أوتوماتيك ولا تتاخد من المكتوب.
     */
    public static function entry(array|object $row, array $perms = []): array
    {
        $r = self::row($row);

        $e = ['id' => (int) $r['id']];
        if (self::has($r['time_in']))        $e['in']   = $r['time_in'];
        if (self::has($r['time_out']))       $e['out']  = $r['time_out'];
        if ($r['hours'] !== null)            $e['h']    = (float) $r['hours'];
        if ($r['orders_count'] !== null)     $e['o']    = (int) $r['orders_count'];
        if ($r['svc'] !== null)              $e['svc']  = (float) $r['svc'];
        if ($r['psvc_override'] !== null)    $e['psvc'] = (float) $r['psvc_override'];
        if ($r['net_override'] !== null)     $e['net']  = (float) $r['net_override'];
        if ($r['advance'] !== null)          $e['adv']  = (float) $r['advance'];
        if ($r['deduction'] !== null)        $e['ded']  = (float) $r['deduction'];
        if (self::has($r['note']))           $e['note'] = $r['note'];
        if ($perms)                          $e['perms'] = $perms;

        return $e;
    }

    /* ═══════════════════════════════════════════════════════════
       الوصول للسياق — rd_entry_at / rd_summary_at / rd_pilot_by_id
    ═══════════════════════════════════════════════════════════ */

    public static function entryAt(array $ctx, int $pilotId, int $day): array
    {
        return $ctx['entries'][$pilotId][$day] ?? [];
    }

    public static function summaryAt(array $ctx, int $branchId, int $day): array
    {
        return $ctx['summaries'][$branchId][$day] ?? [];
    }

    public static function pilotById(array $ctx, int $pilotId): ?array
    {
        foreach ($ctx['pilots'] as $p) {
            if ($p['id'] === $pilotId) {
                return $p;
            }
        }

        return null;
    }

    /** طيارين الفرع الشغالين — الموقوفين مش داخلين في التقفيل اليومي (زي القديم) */
    public static function pilotsOfBranch(array $ctx, int $branchId): array
    {
        $out = [];
        foreach ($ctx['pilots'] as $p) {
            if ($p['branchId'] === $branchId && $p['active']) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /** Firebase مابيقبلش نقطة في اسم المفتاح — فبتتخزّن شرطة سفلية */
    public static function pkey(string $k): string
    {
        return str_replace('.', '_', $k);
    }

    /* ═══════════════════════════════════════════════════════════
       🔴 التقفيلة اليومية للفرع — قلب النظام
    ═══════════════════════════════════════════════════════════ */

    /** إجمالي أوردرات كل الفروع في يوم — مش متأثر بصلاحيات المستخدم */
    public static function allBranchesOrders(array $ctx, int $day): float
    {
        $o = 0.0;
        foreach ($ctx['pilots'] as $p) {
            $o += self::num(self::entryAt($ctx, $p['id'], $day)['o'] ?? 0);
        }

        return $o;
    }

    /**
     * 🔴 الفرع المتحمّل رسوم التطوير كلها — 0 يعني كل فرع بأوردراته.
     *
     * الإعداد ممكن يكون رقم (الشكل الجديد) أو مفتاح فايربيز قديم جاي من
     * الترحيل (rd/settings بتتنسخ زي ما هي). لو مفتاح قديم بنحوّله لـ id
     * رقمي من الفروع — من غير كده الـ (int) كان بيطلّع 0 والرسوم كانت
     * هتتوزّع على كل فرع بأوردراته بدل ما فرع واحد يتحمّلها، وده بيغيّر
     * «المفروض يورّده» لكل فرع (فروق فلوس حقيقية بعد الترحيل).
     */
    public static function devFeeBranch(array $ctx): int
    {
        $raw = $ctx['settings']['devFeeBranchId'] ?? 0;
        if ($raw === null || $raw === '') {
            return 0;
        }
        if (is_numeric($raw)) {
            return (int) self::num($raw);
        }
        $key = (string) $raw;
        foreach ($ctx['branches'] as $b) {
            if ((string) ($b['key'] ?? '') === $key) {
                return (int) $b['id'];
            }
        }

        /* 🔴 مفتاح متسجّل بس مش لاقي فرع: ده «فيه فرع متحمّل» بس مش أي فرع من دول —
           فبنرجّع قيمة موجبة الوجود ومستحيل تساوي id فرع، عشان كل الفروع تاخد صفر
           رسوم زي القديم بالظبط. لو رجّعنا 0 كان معناها «مفيش فرع متحمّل»
           وكل فرع كان هيتحمّل أوردراته — وده غلط في الفلوس.
           (السنتينل ‎−1‎ اتحط بعد باج فلوس حقيقي — ممنوع يتشال أو يتغيّر.) */
        return -1;
    }

    /** إجماليات طيارين الفرع في يوم */
    public static function branchDayTotals(array $ctx, int $branchId, int $day): array
    {
        $t = ['h' => 0.0, 'o' => 0.0, 'net' => 0.0, 'svc' => 0.0, 'psvc' => 0.0,
              'adv' => 0.0, 'ded' => 0.0, 'hourPay' => 0.0, 'devFee' => 0.0, 'count' => 0];
        $devRate = self::devRate($ctx['settings']);
        // كل طيار بسعره هو — عشان كده الأجر بيتجمّع طيار طيار مش بالضرب في الآخر
        foreach (self::pilotsOfBranch($ctx, $branchId) as $p) {
            $e = self::entryAt($ctx, $p['id'], $day);
            $R = self::rateOf($p, $ctx['settings']);
            $h = self::hoursOf($e);
            $net = self::netOf($e, $p, $ctx['settings']);
            $t['h']    += $h;
            $t['o']    += self::num($e['o'] ?? 0);
            $t['net']  += $net;
            $t['svc']  += self::num($e['svc'] ?? 0);
            $t['psvc'] += self::pilotSvc($e, $p, $ctx['settings']);
            $t['adv']  += self::num($e['adv'] ?? 0);
            $t['ded']  += self::num($e['ded'] ?? 0);
            $t['hourPay'] += $h * $R['h'];
            $t['devFee']  += self::num($e['o'] ?? 0) * $devRate;
            if ($h || self::num($e['o'] ?? 0) || $net || self::num($e['adv'] ?? 0) || self::num($e['svc'] ?? 0)) {
                $t['count']++;
            }
        }
        foreach (['h','o','net','svc','psvc','adv','ded','hourPay','devFee'] as $k) {
            $t[$k] = self::round2($t[$k]);
        }

        return $t;
    }

    /**
     * 🔴 ★ تقفيلة الفرع في اليوم — منقولة حرفيًا من branchDayCloseout.
     *
     *   outTotal = hourPay + devFee + pct + ext + exp     (السلف مش مصروف)
     *   expected = cash − pct − ext − exp − adv − devFee  (المشرف بيدفع الرسوم كاش)
     *   diff     = hasRecv ? received − expected : 0
     *   net      = cash − outTotal + diff  ≡  received + adv − hourPay
     *
     * ترتيب العمليات والتقريب هنا **مجمّد** — الفحص التفاضلي بيقارن كل حقل.
     */
    public static function branchDayCloseout(array $ctx, int $branchId, int $day): array
    {
        $t = self::branchDayTotals($ctx, $branchId, $day);
        $s = self::summaryAt($ctx, $branchId, $day);

        $hourPay = $t['hourPay'];
        /* رسوم التطوير: لو الإدارة حدّدت فرع بيتحمّلها، الفرع ده بياخد أوردرات
           كل الفروع × السعر، وباقي الفروع بصفر. */
        $dfb = self::devFeeBranch($ctx);
        $devFeeOrders = $dfb ? ($branchId === $dfb ? self::allBranchesOrders($ctx, $day) : 0.0) : $t['o'];
        $devFee = self::round2($devFeeOrders * self::devRate($ctx['settings']));

        $pct = self::num($s['pct'] ?? 0);
        $ext = self::num($s['ext'] ?? 0);
        $exp = self::num($s['exp'] ?? 0);

        $outTotal = self::round2($hourPay + $devFee + $pct + $ext + $exp);
        $cash     = $t['net'];                       // مجموع عمود «صافي»

        $expected = self::round2($cash - $pct - $ext - $exp - $t['adv'] - $devFee);
        $hasRecv  = array_key_exists('recv', $s) && self::has($s['recv']);
        $received = self::num($s['recv'] ?? 0);
        $diff     = $hasRecv ? self::round2($received - $expected) : 0.0;

        return [
            'branchId'     => $branchId,
            't'            => $t,
            's'            => $s,
            'hourPay'      => $hourPay,
            'devFee'       => $devFee,
            'devFeeOrders' => self::round2($devFeeOrders),
            'pct'          => $pct,
            'ext'          => $ext,
            'exp'          => $exp,
            'cash'         => $cash,
            'outTotal'     => $outTotal,
            'net'          => self::round2($cash - $outTotal + $diff),
            'adv'          => $t['adv'],
            'expected'     => $expected,
            'received'     => $received,
            'hasRecv'      => $hasRecv,
            'diff'         => $diff,
        ];
    }

    /** تجميع الفروع المسموحة في يوم واحد — نفس dayAllBranches */
    public static function dayAllBranches(array $ctx, array $branchIds, int $day): array
    {
        $per = [];
        $all = ['h' => 0.0, 'o' => 0.0, 'cash' => 0.0, 'adv' => 0.0, 'hourPay' => 0.0,
                'devFee' => 0.0, 'devFeeOrders' => 0.0, 'pct' => 0.0, 'ext' => 0.0,
                'exp' => 0.0, 'outTotal' => 0.0, 'net' => 0.0, 'received' => 0.0, 'expected' => 0.0];
        foreach ($branchIds as $bid) {
            $c = self::branchDayCloseout($ctx, $bid, $day);
            $per[] = $c;
            $all['h'] += $c['t']['h']; $all['o'] += $c['t']['o'];
            $all['cash'] += $c['cash']; $all['adv'] += $c['adv'];
            $all['hourPay'] += $c['hourPay']; $all['devFee'] += $c['devFee'];
            $all['devFeeOrders'] += $c['devFeeOrders'];
            $all['pct'] += $c['pct']; $all['ext'] += $c['ext']; $all['exp'] += $c['exp'];
            $all['outTotal'] += $c['outTotal']; $all['net'] += $c['net'];
            $all['received'] += $c['received']; $all['expected'] += $c['expected'];
        }
        /* لو فيه فرع بيتحمّل الرسوم كلها، الإجمالي بيتحسب من أوردرات الشركة كلها
           مباشرة — مش بجمع الفروع، لأن الفرع المتحمّل ممكن ميكونش من فروع المستخدم */
        if (self::devFeeBranch($ctx)) {
            $all['devFeeOrders'] = self::allBranchesOrders($ctx, $day);
            $all['devFee'] = self::round2($all['devFeeOrders'] * self::devRate($ctx['settings']));
        }
        foreach ($all as $k => $v) {
            $all[$k] = self::round2($v);
        }

        return ['per' => $per, 'all' => $all];
    }

    /* ═══════════════════════════════════════════════════════════
       السلف المؤجلة + إجماليات الشهر للطيار
    ═══════════════════════════════════════════════════════════ */

    /**
     * 🔴 قسط شهر معيّن + الرصيد قبله وبعده لسلفة واحدة — نفس deferredForMonth.
     *
     * بيمشي شهر بشهر من أول شهر خصم لحد الشهر المطلوب، وكل شهر بياخد
     * `min(القسط, الرصيد)` مع احترام الـoverride المسجّل للشهر ده.
     * الـ`$guard` حارس ضد حلقة لا نهائية لو التواريخ اتبهدلت.
     */
    public static function deferredForMonth(array $rec, string $ym): array
    {
        $total   = self::num($rec['amount']);
        $monthly = self::num($rec['monthly']) > 0 ? self::num($rec['monthly']) : $total;
        $start   = (string) ($rec['startMonth'] ?? '');
        if ($start === '' && self::has($rec['date'] ?? null)) {
            $start = substr((string) $rec['date'], 0, 7);
        }
        if ($start === '' || $ym < $start) {
            return ['due' => 0.0, 'before' => $total, 'after' => $total, 'started' => false];
        }
        $bal = $total;
        $cur = $start;
        $guard = 0;
        while ($guard++ < 400) {
            $ov  = $rec['paid'][$cur] ?? null;                 // override لشهر بعينه
            $amt = self::round2(min($ov !== null ? self::num($ov) : $monthly, $bal));
            if ($cur === $ym) {
                return ['due' => $amt, 'before' => self::round2($bal),
                        'after' => self::round2($bal - $amt), 'started' => true];
            }
            $bal = self::round2($bal - $amt);
            $cur = self::nextMonth($cur);
            if ($cur > $ym) {
                break;
            }
        }

        return ['due' => 0.0, 'before' => self::round2($bal), 'after' => self::round2($bal), 'started' => true];
    }

    public static function deferredOfPilot(array $deferred, int $pilotId, string $ym): array
    {
        $due = 0.0;
        $remaining = 0.0;
        $items = [];
        foreach ($deferred[$pilotId] ?? [] as $rec) {
            $c = self::deferredForMonth($rec, $ym);
            $due += $c['due'];
            $remaining += $c['after'];
            $items[] = ['rec' => $rec] + $c;
        }

        return ['items' => $items, 'due' => self::round2($due), 'remaining' => self::round2($remaining)];
    }

    /** آخر يوم يتحسب في الشهر — الشهر الحالي لحد النهارده بس */
    public static function countedDays(string $ym): int
    {
        $nd = self::daysInMonth($ym);
        $today = self::today();
        $cur = substr($today, 0, 7);
        if ($ym > $cur) {
            return 0;
        }
        if ($ym === $cur) {
            return min($nd, (int) substr($today, 8, 2));
        }

        return $nd;
    }

    /**
     * 🔴 ★ إجماليات الشهر للطيار + حساب الراتب — نفس pilotMonthTotals.
     *
     * ترتيب المفاتيح في `$t` جزء من عقد الرد (بيطلع كما هو في `totals`)،
     * فممنوع إعادة ترتيبه أو إضافة مفتاح في النص.
     */
    public static function pilotMonthTotals(array $ctx, int $pilotId, array $deferred): array
    {
        $days = $ctx['entries'][$pilotId] ?? [];
        $pil  = self::pilotById($ctx, $pilotId);
        $t = ['h' => 0.0, 'o' => 0.0, 'net' => 0.0, 'svc' => 0.0, 'psvc' => 0.0,
              'adv' => 0.0, 'ded' => 0.0, 'worked' => 0];
        foreach ($days as $d => $e) {
            $h = self::hoursOf($e);
            $t['h'] += $h;
            $t['o'] += self::num($e['o'] ?? 0);
            $t['net'] += self::netOf($e, $pil, $ctx['settings']);
            $t['svc'] += self::num($e['svc'] ?? 0);
            $t['psvc'] += self::pilotSvc($e, $pil, $ctx['settings']);
            $t['adv'] += self::num($e['adv'] ?? 0);
            $t['ded'] += self::num($e['ded'] ?? 0);
            if ($h > 0) {
                $t['worked']++;
            }
        }
        foreach (['h','o','net','svc','psvc','adv','ded'] as $k) {
            $t[$k] = self::round2($t[$k]);
        }

        $R = self::rateOf($pil, $ctx['settings']);
        $t['rate']    = $R;
        $t['hourPay'] = self::round2($t['h'] * $R['h']);
        $t['devFee']  = self::round2($t['o'] * self::devRate($ctx['settings']));  // على الشركة مش الطيار

        /* الإجازة المدفوعة: أي يوم ساعاته صفر = غياب، وأول أيام الغياب بتستهلك
           رصيد الإجازة المدفوعة والباقي غياب من غير أجر */
        $counted = self::countedDays($ctx['ym']);
        $workedIn = 0;
        foreach ($days as $d => $e) {
            if ((int) $d <= $counted && self::hoursOf($e) > 0) {
                $workedIn++;
            }
        }
        $t['counted']    = $counted;
        $t['absent']     = max(0, $counted - $workedIn);
        $t['leaveCap']   = max(0, (int) self::num($pil['leaveDays'] ?? 0));
        $t['leaveDays']  = min($t['absent'], $t['leaveCap']);
        $t['leaveLeft']  = self::round2($t['leaveCap'] - $t['leaveDays']);
        $t['shiftHours'] = self::num($ctx['settings']['shiftHours'] ?? 0) ?: 10.0;
        $t['leavePay']   = self::round2($t['leaveDays'] * $t['shiftHours'] * $R['h']);

        $t['gross'] = self::round2($t['hourPay'] + $t['leavePay']);
        $df = self::deferredOfPilot($deferred, $pilotId, $ctx['ym']);
        $t['defDue']  = $df['due'];
        $t['defLeft'] = $df['remaining'];
        $t['defItems'] = $df['items'];
        $t['salary'] = self::round2($t['gross'] - $t['adv'] - $t['ded'] - $t['defDue']);

        return $t;
    }
}
