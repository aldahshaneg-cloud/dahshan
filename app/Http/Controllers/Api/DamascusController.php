<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Support\ApiResponse;
use App\Wire\DamascusWire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 🔴🔴 وحدة «روح دمشق» (تقفيل المطعم) — نقل حرفي لـ api/routes/damascus.php.
 *
 * كل الـ29 مسار /api/rd/* — قراءة وكتابة. الحسابات نفسها في
 * `App\Wire\DamascusWire` وهي **مجمّدة بفحص تفاضلي** (diff_damascus:
 * 36,064 حقل مقابل النظام القديم بصفر اختلاف). الكنترولر ده بيعمل حاجتين
 * بس: يحمّل السياق من القاعدة، ويفرض الصلاحيات. المعادلات مالهاش مكان هنا.
 *
 * البوابة الموحدة للوحدة كلها هي `rd_user()`: **موظف بحساب users بس** —
 * أي حساب من غير `user_id` (عميل التطبيق) بيترفض 403 بنص «غير مسموح لك
 * بهذه العملية». مفيش middleware دور على المسارات دي لأن التقييد الحقيقي
 * بيتم بمفاتيح `rd_perms` جوه الكنترولر (col.* / blk.* / act.* / page.*)
 * مش بدور المستخدم في النظام الأم.
 *
 * الاستعلامات خام بـ DB::select/DB::insert/DB::update/DB::delete — نفس
 * ترتيب الجُمل ونفس الأعمدة بالحرف، عشان الفحص التفاضلي يفضل ممكن.
 */
class DamascusController
{
    /**
     * كاش صلاحيات المستخدم لدورة الطلب الواحد — المقابل لـ `static $cache`
     * في `rd_perms_row()`. خليناه خاصية على الكنترولر (اللي بيتعمل نسخة
     * جديدة كل طلب) بدل `static` جوه دالة، عشان ميعيشش بين الطلبات لو
     * السيرفر بقى عامل طويل العمر.
     */
    private array $permsCache = [];

    /* ═══════════════════════════════════════════════════════════
       1) GET /api/rd/bootstrap — نداء واحد بدل 5
    ═══════════════════════════════════════════════════════════ */

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $this->user($request);
        /* نفس فحص الدخول في البرنامج القديم: غير المدير لازم يكون له صف
           صلاحيات فيه مفتاح واحد على الأقل، وإلا «لسه مالكش صلاحيات».
           المسارات بقت مفتوحة لدور branch كمان (مشرفي دمشق بيتعملوا من
           الشاشة بدور «مشرف فرع») — فالحارس ده هو اللي بيمنع مشرف فرع
           دهشان عادي إنه يقرا أسعار وأسماء روح دمشق. */
        if (empty($user['isAdmin']) && ! $this->permsRow((string) $user['username'])['keys']) {
            throw ApiException::forbidden('لسه مالكش صلاحيات على البرنامج ده — كلّم المدير');
        }
        $ctx  = $this->ctx(substr(DamascusWire::today(), 0, 7));
        $allowed = $this->allowedBranchIds($user, $ctx);

        $branches = array_values(array_filter(
            $ctx['branches'],
            fn ($b) => in_array($b['id'], $allowed, true)
        ));
        $pilots = array_values(array_filter(
            $ctx['pilots'],
            fn ($p) => in_array($p['branchId'], $allowed, true)
        ));

        $perms = ! empty($user['isAdmin'])
            ? ['keys' => [], 'branches' => []]
            : $this->permsRow((string) $user['username']);

        return ApiResponse::out([
            'ok'        => true,
            'serverNow' => DamascusWire::nowMs(),
            'settings'  => $ctx['settings'],
            'branches'  => $branches,
            'pilots'    => $pilots,
            'user'      => [
                'username' => $user['username'],
                'name'     => $user['name'] ?? '',
                'role'     => $user['role'],
                'isAdmin'  => (bool) $user['isAdmin'],
            ],
            'perms'            => $perms,
            'permGroups'       => DamascusWire::permGroups(),
            'allowedBranchIds' => $allowed,
            'today'            => DamascusWire::today(),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       2) الخانات (rd_entries)
    ═══════════════════════════════════════════════════════════ */

    /** GET /api/rd/entries?month=YYYY-MM[&branchId=] */
    public function entriesList(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $ym   = $this->monthArg($request->query('month', ''));
        $ctx  = $this->ctx($ym);
        $this->requireAny($user, ['page.daily', 'page.pilot', 'page.month'], 'لعرض الشيت');

        $allowed = $this->allowedBranchIds($user, $ctx);
        $rawBranch = $request->query('branchId');
        $branchId = ($rawBranch !== null && $rawBranch !== '') ? $this->intId($rawBranch) : null;
        if ($branchId !== null) {
            $this->requireBranch($user, $ctx, $branchId);
            $allowed = [$branchId];
        }

        // الأعمدة اللي المستخدم مش مصرّح له بيشوفها بتتشال من الرد (مش الواجهة بس)
        $hide = [];
        foreach (DamascusWire::entryFields() as $f => [$col, $perm, $type]) {
            if (! $this->can($user, $perm)) {
                $hide[] = $f;
            }
        }
        $hidePerms = ! $this->can($user, 'col.bout') && ! $this->can($user, 'col.bin');

        $out = [];
        foreach ($ctx['pilots'] as $p) {
            if (! in_array($p['branchId'], $allowed, true)) {
                continue;
            }
            $days = $ctx['entries'][$p['id']] ?? [];
            if (! $days) {
                continue;
            }
            $node = [];
            foreach ($days as $d => $e) {
                foreach ($hide as $f) {
                    unset($e[$f]);
                }
                if ($hidePerms) {
                    unset($e['perms']);
                }
                $node[(string) $d] = $e;
            }
            $out[(string) $p['id']] = $node;
        }

        return ApiResponse::out([
            'ok'        => true,
            'serverNow' => DamascusWire::nowMs(),
            'month'     => $ym,
            'locked'    => $this->monthLocked($ym, $branchId),
            'entries'   => (object) $out,
        ]);
    }

    /**
     * PUT /api/rd/entries — {month, pilotId, day, field, value}
     *
     * 💰 بتلمس فلوس: `svc`/`psvc`/`net`/`adv`/`ded` بتدخل مباشرة في تقفيلة
     * الفرع. الكتابة (إنشاء الصف + التحديث + تنضيف الصف الفاضي) كلها جوه
     * معاملة واحدة — الأصل كان بيعملها بلا معاملة، وأي فشل في النص كان
     * بيسيب صف نصّه مكتوب.
     */
    public function entriesSave(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $b = $this->body($request);
        $ym    = $this->monthArg($b['month'] ?? '');
        $day   = $this->dayArg($b['day'] ?? 0);
        $pid   = $this->intId($b['pilotId'] ?? 0);
        $field = (string) ($b['field'] ?? '');

        $fields = DamascusWire::entryFields();
        if (! isset($fields[$field])) {
            throw new ApiException('الحقل غير معروف');
        }
        [$col, $perm, $type] = $fields[$field];

        if ($day > DamascusWire::daysInMonth($ym)) {
            throw new ApiException('اليوم مش موجود في الشهر ده');
        }

        $ctx = $this->ctx($ym);
        $pilot = DamascusWire::pilotById($ctx, $pid);
        if (! $pilot) {
            throw ApiException::notFound('الطيار غير موجود');
        }

        $this->requirePerm($user, 'act.edit', 'التعديل');
        $this->requirePerm($user, $perm, 'العمود ده');
        $this->requireBranch($user, $ctx, $pilot['branchId']);
        $this->requireUnlocked($ym, $pilot['branchId']);
        $this->requireWriteWindow($user, $ctx, $ym, $day, $b);

        $raw = $b['value'] ?? '';
        // نفس saveCell: الوقت بيتظبط، الملاحظة نص، والباقي رقم — والفاضي/الصفر بيتمسح
        if ($type === 'time') {
            $v = DamascusWire::normalizeTime($raw);
            $store = $v === '' ? null : $v;
        } elseif ($type === 'text') {
            $v = (string) $raw;
            $store = $v === '' ? null : $v;
        } else {
            if ($raw === '' || $raw === null) {
                $store = null;
            } else {
                $n = DamascusWire::num($raw);
                $store = ($n === 0.0) ? null : $n;     // الصفر = مسح الخانة (زي القديم)
            }
        }

        $deleted = DB::transaction(function () use ($ym, $pid, $day, $col, $store): bool {
            $entryId = $this->entryRowId($ym, $pid, $day, true);
            DB::update("UPDATE rd_entries SET {$col} = ? WHERE id = ?", [$store, $entryId]);

            return $this->entryCleanup($entryId);
        });

        // الرد بالخانة بعد الحفظ + تقفيلة الفرع المحدّثة (عشان الواجهة تتحدث فورًا)
        $ctx2 = $this->ctx($ym);

        return ApiResponse::out([
            'ok'       => true,
            'deleted'  => $deleted,
            'entry'    => $deleted ? null : (object) DamascusWire::entryAt($ctx2, $pid, $day),
            'closeout' => DamascusWire::branchDayCloseout($ctx2, $pilot['branchId'], $day),
        ]);
    }

    /**
     * PUT /api/rd/entry-perms — {month, pilotId, day, perms:[{out,in}]} استبدال كامل
     *
     * دقايق الاستئذان بتتخصم من ساعات اليوم، وساعات اليوم × سعر الساعة =
     * أجر الساعات في التقفيلة. يعني الاستبدال ده **بيغيّر فلوس**، عشان كده
     * الأصل نفسه كان بيلفّه في معاملة يدوية.
     */
    public function entryPermsSave(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $b = $this->body($request);
        $ym  = $this->monthArg($b['month'] ?? '');
        $day = $this->dayArg($b['day'] ?? 0);
        $pid = $this->intId($b['pilotId'] ?? 0);
        if ($day > DamascusWire::daysInMonth($ym)) {
            throw new ApiException('اليوم مش موجود في الشهر ده');
        }

        $ctx = $this->ctx($ym);
        $pilot = DamascusWire::pilotById($ctx, $pid);
        if (! $pilot) {
            throw ApiException::notFound('الطيار غير موجود');
        }

        $this->requirePerm($user, 'act.edit', 'التعديل');
        $this->requireAny($user, ['col.bout', 'col.bin'], 'الاستئذان');
        $this->requireBranch($user, $ctx, $pilot['branchId']);
        $this->requireUnlocked($ym, $pilot['branchId']);
        $this->requireWriteWindow($user, $ctx, $ym, $day, $b);

        $list = [];
        foreach ((array) ($b['perms'] ?? []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $o = DamascusWire::normalizeTime($p['out'] ?? '');
            $i = DamascusWire::normalizeTime($p['in'] ?? '');
            if ($o === '' && $i === '') {
                continue;
            }
            $list[] = [$o !== '' ? $o : null, $i !== '' ? $i : null];
        }

        DB::transaction(function () use ($ym, $pid, $day, $list): void {
            $entryId = $this->entryRowId($ym, $pid, $day, true);
            DB::delete('DELETE FROM rd_entry_perms WHERE entry_id = ?', [$entryId]);
            foreach ($list as [$o, $i]) {
                DB::insert('INSERT INTO rd_entry_perms (entry_id, perm_out, perm_in) VALUES (?, ?, ?)', [$entryId, $o, $i]);
            }
            $this->entryCleanup($entryId);
        });

        $ctx2 = $this->ctx($ym);

        return ApiResponse::out([
            'ok'       => true,
            'entry'    => (object) DamascusWire::entryAt($ctx2, $pid, $day),
            'closeout' => DamascusWire::branchDayCloseout($ctx2, $pilot['branchId'], $day),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       3) ملخصات الفرع اليومية (rd_summaries)
    ═══════════════════════════════════════════════════════════ */

    /** GET /api/rd/summaries?month=[&branchId=] */
    public function summariesList(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $ym   = $this->monthArg($request->query('month', ''));
        $ctx  = $this->ctx($ym);
        $this->requireAny($user, ['page.daily', 'page.month'], 'لعرض الملخصات');

        $allowed = $this->allowedBranchIds($user, $ctx);
        $rawBranch = $request->query('branchId');
        if ($rawBranch !== null && $rawBranch !== '') {
            $bid = $this->intId($rawBranch);
            $this->requireBranch($user, $ctx, $bid);
            $allowed = [$bid];
        }

        $hide = [];
        foreach (DamascusWire::summaryFields() as $f => $perm) {
            if (! $this->can($user, $perm)) {
                $hide[] = $f;
            }
        }

        $out = [];
        foreach ($allowed as $bid) {
            $node = [];
            foreach ($ctx['summaries'][$bid] ?? [] as $d => $s) {
                foreach ($hide as $f) {
                    unset($s[$f]);
                }
                $node[(string) $d] = (object) $s;
            }
            $out[(string) $bid] = (object) $node;
        }

        return ApiResponse::out([
            'ok' => true, 'serverNow' => DamascusWire::nowMs(), 'month' => $ym,
            'summaries' => (object) $out,
        ]);
    }

    /**
     * PUT /api/rd/summaries — {month, branchId, day, field, value}
     *
     * 💰 البنود الأربعة (pct/ext/exp/recv) كلها بتدخل في `expected` و`net`
     * بتوع تقفيلة الفرع. `{$field}` آمن لأنه بيتفلتر من `summaryFields()`
     * اللي مفاتيحها **هي نفسها أسماء الأعمدة** — زي الأصل بالظبط.
     */
    public function summariesSave(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $b = $this->body($request);
        $ym    = $this->monthArg($b['month'] ?? '');
        $day   = $this->dayArg($b['day'] ?? 0);
        $bid   = $this->intId($b['branchId'] ?? 0);
        $field = (string) ($b['field'] ?? '');

        $fields = DamascusWire::summaryFields();
        if (! isset($fields[$field])) {
            throw new ApiException('الحقل غير معروف');
        }
        if ($day > DamascusWire::daysInMonth($ym)) {
            throw new ApiException('اليوم مش موجود في الشهر ده');
        }

        $ctx = $this->ctx($ym);
        $this->requirePerm($user, 'act.edit', 'التعديل');
        $this->requirePerm($user, $fields[$field], 'البند ده');
        $this->requireBranch($user, $ctx, $bid);
        $this->requireUnlocked($ym, $bid);
        $this->requireWriteWindow($user, $ctx, $ym, $day, $b);

        $raw = $b['value'] ?? '';
        $store = ($raw === '' || $raw === null)
            ? null
            : (DamascusWire::num($raw) === 0.0 ? null : DamascusWire::num($raw));

        $deleted = DB::transaction(function () use ($ym, $bid, $day, $field, $store): bool {
            $row = DB::select('SELECT id FROM rd_summaries WHERE month = ? AND branch_id = ? AND day = ? LIMIT 1', [$ym, $bid, $day])[0] ?? null;
            if (! $row) {
                DB::insert('INSERT INTO rd_summaries (month, branch_id, day) VALUES (?, ?, ?)', [$ym, $bid, $day]);
                $id = (int) DB::getPdo()->lastInsertId();
            } else {
                $id = (int) $row->id;
            }
            DB::update("UPDATE rd_summaries SET {$field} = ? WHERE id = ?", [$store, $id]);

            // صف فاضي = يتمسح
            $r = DB::select('SELECT pct, ext, exp, recv FROM rd_summaries WHERE id = ?', [$id])[0] ?? null;
            if ($r && $r->pct === null && $r->ext === null && $r->exp === null && $r->recv === null) {
                DB::delete('DELETE FROM rd_summaries WHERE id = ?', [$id]);

                return true;
            }

            return false;
        });

        $ctx2 = $this->ctx($ym);

        return ApiResponse::out([
            'ok' => true, 'deleted' => $deleted,
            'summary'  => (object) DamascusWire::summaryAt($ctx2, $bid, $day),
            'closeout' => DamascusWire::branchDayCloseout($ctx2, $bid, $day),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       4) ★ التقفيلة — محسوبة سيرفر-سايد
    ═══════════════════════════════════════════════════════════ */

    /** GET /api/rd/closeout/day?month=&day=[&branchId=] */
    public function closeoutDay(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $ym   = $this->monthArg($request->query('month', ''));
        $day  = $this->dayArg($request->query('day', 0));
        if ($day > DamascusWire::daysInMonth($ym)) {
            throw new ApiException('اليوم مش موجود في الشهر ده');
        }

        $ctx = $this->ctx($ym);
        $this->requirePerm($user, 'page.daily', 'التقفيل اليومي');

        $allowed = $this->allowedBranchIds($user, $ctx);
        $rawBranch = $request->query('branchId');
        if ($rawBranch !== null && $rawBranch !== '') {
            $bid = $this->intId($rawBranch);
            $this->requireBranch($user, $ctx, $bid);
            $allowed = [$bid];
        }

        $res = DamascusWire::dayAllBranches($ctx, $allowed, $day);
        $names = [];
        foreach ($ctx['branches'] as $b) {
            $names[$b['id']] = $b['name'];
        }

        // صفوف الطيارين لكل فرع (شيت اليوم) — الخانة الخام + المحسوب
        $rows = [];
        foreach ($allowed as $bid) {
            foreach (DamascusWire::pilotsOfBranch($ctx, $bid) as $p) {
                $e = DamascusWire::entryAt($ctx, $p['id'], $day);
                $rows[] = [
                    'pilotId'  => $p['id'],
                    'name'     => $p['name'],
                    'branchId' => $bid,
                    'entry'    => (object) $e,
                    'hours'    => DamascusWire::hoursOf($e),
                    'orders'   => DamascusWire::num($e['o'] ?? 0),
                    'svc'      => DamascusWire::num($e['svc'] ?? 0),
                    'pilotSvc' => DamascusWire::pilotSvc($e, $p, $ctx['settings']),
                    'net'      => DamascusWire::netOf($e, $p, $ctx['settings']),
                    'adv'      => DamascusWire::num($e['adv'] ?? 0),
                    'ded'      => DamascusWire::num($e['ded'] ?? 0),
                ];
            }
        }

        // اسم الفرع بيتضاف في آخر مصفوفة التقفيلة — ترتيب المفاتيح جزء من العقد
        foreach ($res['per'] as &$c) {
            $c['branchName'] = $names[$c['branchId']] ?? '—';
        }
        unset($c);

        return ApiResponse::out([
            'ok'        => true,
            'serverNow' => DamascusWire::nowMs(),
            'month'     => $ym,
            'day'       => $day,
            'settings'  => $ctx['settings'],
            'locked'    => $this->monthLocked($ym, count($allowed) === 1 ? $allowed[0] : null),
            'branches'  => $res['per'],
            'all'       => $res['all'],
            'rows'      => $rows,
        ]);
    }

    /** GET /api/rd/closeout/month?month=[&branchId=] */
    public function closeoutMonth(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $ym   = $this->monthArg($request->query('month', ''));
        $ctx  = $this->ctx($ym);
        $this->requirePerm($user, 'page.month', 'تقفيل الشهر');

        $allowed = $this->allowedBranchIds($user, $ctx);
        $bf = null;
        $rawBranch = $request->query('branchId');
        if ($rawBranch !== null && $rawBranch !== '') {
            $bf = $this->intId($rawBranch);
            $this->requireBranch($user, $ctx, $bf);
        }

        $nd = DamascusWire::daysInMonth($ym);
        $M = ['h' => 0.0, 'o' => 0.0, 'hourPay' => 0.0, 'devFee' => 0.0, 'devFeeOrders' => 0.0,
              'pct' => 0.0, 'ext' => 0.0, 'exp' => 0.0, 'cash' => 0.0, 'adv' => 0.0,
              'out' => 0.0, 'net' => 0.0, 'received' => 0.0, 'expected' => 0.0];
        $perBranch = [];
        foreach ($allowed as $bid) {
            $perBranch[$bid] = 0.0;
        }

        /* ⚠️ الحلقة دي بتلف على **كل فروع المستخدم** حتى لو فيه فلتر فرع —
           لأن `branchNets` بيرجّع صافي كل فرع، والفلتر بيأثر على `closeout` بس. */
        for ($d = 1; $d <= $nd; $d++) {
            foreach ($allowed as $bid) {
                $c = DamascusWire::branchDayCloseout($ctx, $bid, $d);
                $perBranch[$bid] += $c['net'];
                if ($bf !== null && $bid !== $bf) {
                    continue;
                }
                $M['h'] += $c['t']['h']; $M['o'] += $c['t']['o'];
                $M['hourPay'] += $c['hourPay']; $M['devFee'] += $c['devFee'];
                $M['devFeeOrders'] += $c['devFeeOrders'];
                $M['pct'] += $c['pct']; $M['ext'] += $c['ext']; $M['exp'] += $c['exp'];
                $M['cash'] += $c['cash']; $M['adv'] += $c['adv'];
                $M['out'] += $c['outTotal']; $M['net'] += $c['net'];
                $M['received'] += $c['received']; $M['expected'] += $c['expected'];
            }
        }
        foreach ($M as $k => $v) {
            $M[$k] = DamascusWire::round2($v);
        }

        $names = [];
        foreach ($ctx['branches'] as $b) {
            $names[$b['id']] = $b['name'];
        }
        $branchNets = [];
        $totalNet = 0.0;
        foreach ($perBranch as $bid => $v) {
            $branchNets[] = ['branchId' => $bid, 'name' => $names[$bid] ?? '—', 'net' => DamascusWire::round2($v)];
            $totalNet += $v;
        }

        // كشف رواتب الطيارين — الأسماء المحجوبة مخفية عن غير الأدمن
        $deferred = $this->deferredAll();
        $G = ['h' => 0.0, 'o' => 0.0, 'gross' => 0.0, 'hourPay' => 0.0, 'leavePay' => 0.0,
              'adv' => 0.0, 'ded' => 0.0, 'defDue' => 0.0, 'defLeft' => 0.0,
              'salary' => 0.0, 'owed' => 0.0];
        $pilots = [];
        foreach ($ctx['pilots'] as $p) {
            if ($bf !== null && $p['branchId'] !== $bf) {
                continue;
            }
            if (! in_array($p['branchId'], $allowed, true)) {
                continue;
            }
            if ($this->sheetHidden($user, $p)) {
                continue;
            }
            $t = DamascusWire::pilotMonthTotals($ctx, $p['id'], $deferred);
            $G['h'] += $t['h']; $G['o'] += $t['o']; $G['gross'] += $t['gross'];
            $G['hourPay'] += $t['hourPay']; $G['leavePay'] += $t['leavePay'];
            $G['adv'] += $t['adv']; $G['ded'] += $t['ded'];
            $G['defDue'] += $t['defDue']; $G['defLeft'] += $t['defLeft'];
            // الراتب السالب = الطيار مدين للشركة، بيتجمّع في خانة تانية مش بيقاصّ
            if ($t['salary'] >= 0) {
                $G['salary'] += $t['salary'];
            } else {
                $G['owed'] += -$t['salary'];
            }
            unset($t['defItems']);
            $pilots[] = [
                'pilotId'    => $p['id'],
                'name'       => $p['name'],
                'branchId'   => $p['branchId'],
                'branchName' => $names[$p['branchId']] ?? '—',
                'active'     => $p['active'],
            ] + $t;
        }
        foreach ($G as $k => $v) {
            $G[$k] = DamascusWire::round2($v);
        }

        return ApiResponse::out([
            'ok'         => true,
            'serverNow'  => DamascusWire::nowMs(),
            'month'      => $ym,
            'branchId'   => $bf,
            'settings'   => $ctx['settings'],
            'locked'     => $this->monthLocked($ym, $bf),
            'closeout'   => $M,
            'branchNets' => $branchNets,
            'totalNet'   => DamascusWire::round2($totalNet),
            'pilots'     => $pilots,
            'totals'     => $G,
        ]);
    }

    /** GET /api/rd/pilot-sheet?month=&pilotId= */
    public function pilotSheet(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $ym   = $this->monthArg($request->query('month', ''));
        $pid  = $this->intId($request->query('pilotId', 0));
        $ctx  = $this->ctx($ym);
        $this->requirePerm($user, 'page.pilot', 'كشف الطيار');

        $p = DamascusWire::pilotById($ctx, $pid);
        if (! $p) {
            throw ApiException::notFound('الطيار غير موجود');
        }
        $this->requireBranch($user, $ctx, $p['branchId']);
        if ($this->sheetHidden($user, $p)) {
            throw ApiException::forbidden('كشف الطيار ده للإدارة فقط');
        }

        $nd = DamascusWire::daysInMonth($ym);
        $days = [];
        for ($d = 1; $d <= $nd; $d++) {
            $e = DamascusWire::entryAt($ctx, $pid, $d);
            $days[] = [
                'day'      => $d,
                'entry'    => (object) $e,
                'hours'    => DamascusWire::hoursOf($e),
                'orders'   => DamascusWire::num($e['o'] ?? 0),
                'svc'      => DamascusWire::num($e['svc'] ?? 0),
                'pilotSvc' => DamascusWire::pilotSvc($e, $p, $ctx['settings']),
                'net'      => DamascusWire::netOf($e, $p, $ctx['settings']),
                'adv'      => DamascusWire::num($e['adv'] ?? 0),
                'ded'      => DamascusWire::num($e['ded'] ?? 0),
                'note'     => (string) ($e['note'] ?? ''),
            ];
        }

        $t = DamascusWire::pilotMonthTotals($ctx, $pid, $this->deferredAll());
        // بلوك الراتب محكوم بصلاحياته
        if (! $this->can($user, 'sal.block')) {
            foreach (['gross','salary','leavePay','leaveDays','leaveLeft','defDue','defLeft','defItems'] as $k) {
                unset($t[$k]);
            }
        } else {
            if (! $this->can($user, 'sal.leave')) {
                foreach (['leavePay','leaveDays','leaveLeft','leaveCap'] as $k) {
                    unset($t[$k]);
                }
            }
            if (! $this->can($user, 'sal.def')) {
                foreach (['defDue','defLeft','defItems'] as $k) {
                    unset($t[$k]);
                }
            }
        }

        $names = [];
        foreach ($ctx['branches'] as $b) {
            $names[$b['id']] = $b['name'];
        }

        return ApiResponse::out([
            'ok'        => true,
            'serverNow' => DamascusWire::nowMs(),
            'month'     => $ym,
            'locked'    => $this->monthLocked($ym, $p['branchId']),
            'pilot'     => $p + ['branchName' => $names[$p['branchId']] ?? '—'],
            'settings'  => $ctx['settings'],
            'days'      => $days,
            'totals'    => $t,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       5) CRUD الفروع (act.branches)
    ═══════════════════════════════════════════════════════════ */

    /** GET /api/rd/branches */
    public function branchesList(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $ctx  = $this->ctx(substr(DamascusWire::today(), 0, 7));
        $allowed = $this->allowedBranchIds($user, $ctx);
        $items = array_values(array_filter($ctx['branches'], fn ($b) => in_array($b['id'], $allowed, true)));

        return ApiResponse::out(['ok' => true, 'serverNow' => DamascusWire::nowMs(), 'items' => $items]);
    }

    /** POST /api/rd/branches */
    public function branchesCreate(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->requirePerm($user, 'act.branches', 'إضافة الفروع');
        $b = $this->body($request);
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            throw new ApiException('اكتب اسم الفرع');
        }

        $branch = DB::transaction(function () use ($b, $name): array {
            DB::insert('INSERT INTO rd_branches (name, active) VALUES (?, ?)', [
                $name,
                isset($b['active']) ? (int) (bool) $b['active'] : 1,
            ]);
            $id = (int) DB::getPdo()->lastInsertId();

            return DamascusWire::branch(DB::select('SELECT * FROM rd_branches WHERE id = ?', [$id])[0]);
        });

        return ApiResponse::out(['ok' => true, 'branch' => $branch]);
    }

    /** PUT /api/rd/branches/{id} */
    public function branchesUpdate(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $this->requirePerm($user, 'act.branches', 'تعديل الفروع');
        $bid = $this->intId($id);
        $b = $this->body($request);

        $branch = DB::transaction(function () use ($b, $bid): array {
            $row = DB::select('SELECT * FROM rd_branches WHERE id = ?', [$bid])[0] ?? null;
            if (! $row) {
                throw ApiException::notFound('الفرع غير موجود');
            }
            $name = isset($b['name']) ? trim((string) $b['name']) : $row->name;
            if ($name === '') {
                throw new ApiException('اكتب اسم الفرع');
            }
            $active = isset($b['active']) ? (int) (bool) $b['active'] : (int) $row->active;
            DB::update('UPDATE rd_branches SET name = ?, active = ? WHERE id = ?', [$name, $active, $bid]);

            return DamascusWire::branch(DB::select('SELECT * FROM rd_branches WHERE id = ?', [$bid])[0]);
        });

        return ApiResponse::out(['ok' => true, 'branch' => $branch]);
    }

    /**
     * DELETE /api/rd/branches/{id}
     *
     * الفرع اللي عليه طيارين أو ملخصات محفوظة **مايتحذفش** — الملخصات
     * تاريخ مالي، وحذف الفرع كان هيخلي التقفيلات القديمة بلا مالك.
     */
    public function branchesDelete(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $this->requirePerm($user, 'act.branches', 'حذف الفروع');
        $bid = $this->intId($id);

        DB::transaction(function () use ($bid): void {
            $c = (int) DB::select('SELECT COUNT(*) c FROM rd_pilots WHERE branch_id = ?', [$bid])[0]->c;
            if ($c > 0) {
                throw new ApiException('مينفعش تحذف فرع فيه طيارين — انقلهم أو أوقفهم الأول');
            }
            $c = (int) DB::select('SELECT COUNT(*) c FROM rd_summaries WHERE branch_id = ?', [$bid])[0]->c;
            if ($c > 0) {
                throw new ApiException('الفرع له ملخصات محفوظة — مينفعش يتحذف');
            }
            DB::delete('DELETE FROM rd_branches WHERE id = ?', [$bid]);
        });

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       6) CRUD الطيارين (act.pilots)
    ═══════════════════════════════════════════════════════════ */

    /** GET /api/rd/pilots */
    public function pilotsList(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $ctx  = $this->ctx(substr(DamascusWire::today(), 0, 7));
        $allowed = $this->allowedBranchIds($user, $ctx);
        $items = array_values(array_filter($ctx['pilots'], fn ($p) => in_array($p['branchId'], $allowed, true)));

        return ApiResponse::out(['ok' => true, 'serverNow' => DamascusWire::nowMs(), 'items' => $items]);
    }

    /** POST /api/rd/pilots — 💰 `hourRate`/`orderRate` بيدخلوا في أجر الساعات وخدمة الطيار */
    public function pilotsCreate(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->requirePerm($user, 'act.pilots', 'إضافة الطيارين');
        $b = $this->body($request);
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            throw new ApiException('اكتب اسم الطيار');
        }
        $branchId = $this->intId($b['branchId'] ?? 0);
        $ctx = $this->ctx(substr(DamascusWire::today(), 0, 7));
        $this->requireBranch($user, $ctx, $branchId);
        /* وظيفة «مالك» بيديها المدير العام بس — المشرف مايقدرش يعمل حساب
           مخفي (ولا يظهّر واحد موجود، شوف pilotsUpdate). */
        $job = trim((string) ($b['job'] ?? '')) ?: null;
        if ($job === DamascusWire::OWNER_JOB && empty($user['isAdmin'])) {
            throw ApiException::forbidden('وظيفة «مالك» للإدارة فقط');
        }

        $pilot = DB::transaction(function () use ($b, $branchId, $name, $job): array {
            DB::insert(
                'INSERT INTO rd_pilots (branch_id, name, active, job, hour_rate, order_rate, leave_days)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $branchId, $name,
                    isset($b['active']) ? (int) (bool) $b['active'] : 1,
                    $job,
                    DamascusWire::num($b['hourRate'] ?? 0), DamascusWire::num($b['orderRate'] ?? 0),
                    (int) DamascusWire::num($b['leaveDays'] ?? 0),
                ]
            );
            $id = (int) DB::getPdo()->lastInsertId();

            return DamascusWire::pilot(DB::select('SELECT * FROM rd_pilots WHERE id = ?', [$id])[0]);
        });

        return ApiResponse::out(['ok' => true, 'pilot' => $pilot]);
    }

    /**
     * PUT /api/rd/pilots/{id} — 💰 تعديل الأسعار بيغيّر تقفيلات الأيام كلها.
     *
     * ⚠️ نقل الطيار لفرع تاني بيتفحص على **الفرعين**: فرعه الحالي (عشان
     * يعرف يلمسه أصلًا) والفرع الجديد (عشان ميهرّبش طيار لبره نطاقه).
     */
    public function pilotsUpdate(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $this->requirePerm($user, 'act.pilots', 'تعديل الطيارين');
        $pid = $this->intId($id);
        $b = $this->body($request);

        $pilot = DB::transaction(function () use ($user, $b, $pid): array {
            $row = DB::select('SELECT * FROM rd_pilots WHERE id = ?', [$pid])[0] ?? null;
            if (! $row) {
                throw ApiException::notFound('الطيار غير موجود');
            }

            $ctx = $this->ctx(substr(DamascusWire::today(), 0, 7));
            $this->requireBranch($user, $ctx, (int) $row->branch_id);
            $branchId = isset($b['branchId']) ? $this->intId($b['branchId']) : (int) $row->branch_id;
            if ($branchId !== (int) $row->branch_id) {
                $this->requireBranch($user, $ctx, $branchId);
            }

            $name = isset($b['name']) ? trim((string) $b['name']) : $row->name;
            if ($name === '') {
                throw new ApiException('اكتب اسم الطيار');
            }

            /* وظيفة «مالك» بيغيّرها المدير العام بس — لو اللي بيحفظ مش أدمن
               الوظيفة بتفضل زي ما هي (زي القديم: مايقدرش يخفي حساب ولا
               يظهّر حساب مخفي بالغلط). */
            $job = array_key_exists('job', $b) ? (trim((string) $b['job']) ?: null) : $row->job;
            if (empty($user['isAdmin'])
                && ($job === DamascusWire::OWNER_JOB || (string) $row->job === DamascusWire::OWNER_JOB)) {
                $job = $row->job;
            }

            DB::update(
                'UPDATE rd_pilots SET branch_id = ?, name = ?, active = ?, job = ?,
                        hour_rate = ?, order_rate = ?, leave_days = ? WHERE id = ?',
                [
                    $branchId, $name,
                    isset($b['active']) ? (int) (bool) $b['active'] : (int) $row->active,
                    $job,
                    array_key_exists('hourRate', $b) ? DamascusWire::num($b['hourRate']) : (float) $row->hour_rate,
                    array_key_exists('orderRate', $b) ? DamascusWire::num($b['orderRate']) : (float) $row->order_rate,
                    array_key_exists('leaveDays', $b) ? (int) DamascusWire::num($b['leaveDays']) : (int) $row->leave_days,
                    $pid,
                ]
            );

            return DamascusWire::pilot(DB::select('SELECT * FROM rd_pilots WHERE id = ?', [$pid])[0]);
        });

        return ApiResponse::out(['ok' => true, 'pilot' => $pilot]);
    }

    /** DELETE /api/rd/pilots/{id} — الطيار اللي له بيانات في الشيت أو سلف مايتحذفش */
    public function pilotsDelete(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $this->requirePerm($user, 'act.pilots', 'حذف الطيارين');
        $pid = $this->intId($id);

        DB::transaction(function () use ($user, $pid): void {
            $row = DB::select('SELECT * FROM rd_pilots WHERE id = ?', [$pid])[0] ?? null;
            if (! $row) {
                throw ApiException::notFound('الطيار غير موجود');
            }
            $ctx = $this->ctx(substr(DamascusWire::today(), 0, 7));
            $this->requireBranch($user, $ctx, (int) $row->branch_id);

            $c = (int) DB::select('SELECT COUNT(*) c FROM rd_entries WHERE pilot_id = ?', [$pid])[0]->c;
            if ($c > 0) {
                throw new ApiException('الطيار له بيانات في الشيت — أوقفه بدل ما تحذفه');
            }
            $c = (int) DB::select('SELECT COUNT(*) c FROM rd_deferred_advances WHERE pilot_id = ?', [$pid])[0]->c;
            if ($c > 0) {
                throw new ApiException('الطيار عليه سلف مؤجلة — مينفعش يتحذف');
            }

            DB::delete('DELETE FROM rd_pilots WHERE id = ?', [$pid]);
        });

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       7) السلف المؤجلة (act.deferred / page.deferred)
    ═══════════════════════════════════════════════════════════ */

    /** GET /api/rd/deferred[?month=&pilotId=] */
    public function deferredList(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->requirePerm($user, 'page.deferred', 'السلف المؤجلة');
        $rawMonth = $request->query('month');
        $ym  = ($rawMonth !== null && $rawMonth !== '')
            ? $this->monthArg($rawMonth)
            : substr(DamascusWire::today(), 0, 7);
        $ctx = $this->ctx($ym);
        $allowed = $this->allowedBranchIds($user, $ctx);
        $rawPilot = $request->query('pilotId');
        $pilotFilter = ($rawPilot !== null && $rawPilot !== '') ? $this->intId($rawPilot) : null;

        $all = $this->deferredAll();
        $items = [];
        foreach ($ctx['pilots'] as $p) {
            if (! in_array($p['branchId'], $allowed, true)) {
                continue;
            }
            if ($pilotFilter !== null && $p['id'] !== $pilotFilter) {
                continue;
            }
            foreach ($all[$p['id']] ?? [] as $rec) {
                $c = DamascusWire::deferredForMonth($rec, $ym);
                $items[] = $rec + [
                    'pilotName' => $p['name'],
                    'branchId'  => $p['branchId'],
                    'due'       => $c['due'],
                    'before'    => $c['before'],
                    'after'     => $c['after'],
                    'started'   => $c['started'],
                ];
            }
        }

        return ApiResponse::out(['ok' => true, 'serverNow' => DamascusWire::nowMs(), 'month' => $ym, 'items' => $items]);
    }

    /** POST /api/rd/deferred — 💰 سلفة بتتقسّط على الشهور وبتتخصم من الراتب */
    public function deferredCreate(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->requirePerm($user, 'act.deferred', 'السلف المؤجلة');
        $b = $this->body($request);
        $pid = $this->intId($b['pilotId'] ?? 0);
        $ctx = $this->ctx(substr(DamascusWire::today(), 0, 7));
        $p = DamascusWire::pilotById($ctx, $pid);
        if (! $p) {
            throw ApiException::notFound('الطيار غير موجود');
        }
        $this->requireBranch($user, $ctx, $p['branchId']);

        $amount = DamascusWire::num($b['amount'] ?? 0);
        if ($amount <= 0) {
            throw new ApiException('اكتب مبلغ السلفة');
        }
        $date = trim((string) ($b['date'] ?? ''));
        if ($date !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ApiException('تاريخ السلفة غير صالح');
        }
        $start = trim((string) ($b['startMonth'] ?? ''));
        if ($start === '' && $date !== '') {
            $start = substr($date, 0, 7);
        }
        if ($start === '') {
            $start = substr(DamascusWire::today(), 0, 7);
        }
        $this->monthArg($start);

        $id = DB::transaction(function () use ($b, $pid, $date, $amount, $start): int {
            DB::insert(
                'INSERT INTO rd_deferred_advances (pilot_id, advance_date, amount, monthly, start_month, note)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $pid, $date !== '' ? $date : null, $amount,
                    DamascusWire::num($b['monthly'] ?? 0), $start,
                    trim((string) ($b['note'] ?? '')) ?: null,
                ]
            );

            return (int) DB::getPdo()->lastInsertId();
        });

        return ApiResponse::out(['ok' => true, 'id' => $id]);
    }

    /** PUT /api/rd/deferred/{id} — 💰 */
    public function deferredUpdate(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $this->requirePerm($user, 'act.deferred', 'السلف المؤجلة');
        $aid = $this->intId($id);
        $b = $this->body($request);

        DB::transaction(function () use ($user, $b, $aid): void {
            $row = DB::select('SELECT * FROM rd_deferred_advances WHERE id = ?', [$aid])[0] ?? null;
            if (! $row) {
                throw ApiException::notFound('السلفة غير موجودة');
            }
            $ctx = $this->ctx(substr(DamascusWire::today(), 0, 7));
            $p = DamascusWire::pilotById($ctx, (int) $row->pilot_id);
            if ($p) {
                $this->requireBranch($user, $ctx, $p['branchId']);
            }

            $amount = array_key_exists('amount', $b) ? DamascusWire::num($b['amount']) : (float) $row->amount;
            if ($amount <= 0) {
                throw new ApiException('اكتب مبلغ السلفة');
            }
            $start = array_key_exists('startMonth', $b) ? trim((string) $b['startMonth']) : (string) $row->start_month;
            $this->monthArg($start);
            $date = array_key_exists('date', $b) ? trim((string) $b['date']) : (string) ($row->advance_date ?? '');
            if ($date !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new ApiException('تاريخ السلفة غير صالح');
            }

            DB::update(
                'UPDATE rd_deferred_advances SET advance_date = ?, amount = ?, monthly = ?, start_month = ?, note = ?
                 WHERE id = ?',
                [
                    $date !== '' ? $date : null, $amount,
                    array_key_exists('monthly', $b) ? DamascusWire::num($b['monthly']) : (float) $row->monthly,
                    $start,
                    array_key_exists('note', $b) ? (trim((string) $b['note']) ?: null) : $row->note,
                    $aid,
                ]
            );
        });

        return ApiResponse::ok();
    }

    /** DELETE /api/rd/deferred/{id} — الأقساط بتتمسح معاها بـ CASCADE */
    public function deferredDelete(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $this->requirePerm($user, 'act.deferred', 'السلف المؤجلة');
        $aid = $this->intId($id);

        DB::transaction(function () use ($user, $aid): void {
            $row = DB::select('SELECT * FROM rd_deferred_advances WHERE id = ?', [$aid])[0] ?? null;
            if (! $row) {
                throw ApiException::notFound('السلفة غير موجودة');
            }
            $ctx = $this->ctx(substr(DamascusWire::today(), 0, 7));
            $p = DamascusWire::pilotById($ctx, (int) $row->pilot_id);
            if ($p) {
                $this->requireBranch($user, $ctx, $p['branchId']);
            }
            DB::delete('DELETE FROM rd_deferred_advances WHERE id = ?', [$aid]); // الأقساط CASCADE
        });

        return ApiResponse::ok();
    }

    /**
     * PUT /api/rd/deferred/{id}/payment — {month, amount} — override قسط شهر
     * (فاضي = مسح الـoverride).
     *
     * 💰 القسط ده بيتخصم من راتب الشهر مباشرةً (`salary = gross − adv − ded − defDue`).
     * ⚠️ `rd_require_unlocked($ym, null)` بـ branchId = null: القفل العام بس
     * هو اللي بيمنع — قفل فرع بعينه **مابيمنعش** تعديل القسط. منقول زي ما هو.
     */
    public function deferredPayment(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $this->requirePerm($user, 'act.deferred', 'السلف المؤجلة');
        $aid = $this->intId($id);
        $b = $this->body($request);
        $ym = $this->monthArg($b['month'] ?? '');
        $this->requireUnlocked($ym, null);

        $cleared = DB::transaction(function () use ($user, $b, $aid, $ym): bool {
            $row = DB::select('SELECT * FROM rd_deferred_advances WHERE id = ?', [$aid])[0] ?? null;
            if (! $row) {
                throw ApiException::notFound('السلفة غير موجودة');
            }
            $ctx = $this->ctx($ym);
            $p = DamascusWire::pilotById($ctx, (int) $row->pilot_id);
            if ($p) {
                $this->requireBranch($user, $ctx, $p['branchId']);
            }

            $raw = $b['amount'] ?? '';
            if ($raw === '' || $raw === null) {
                DB::delete('DELETE FROM rd_deferred_payments WHERE advance_id = ? AND month = ?', [$aid, $ym]);

                return true;
            }
            $amt = DamascusWire::num($raw);
            if ($amt < 0) {
                throw new ApiException('القسط مينفعش يكون بالسالب');
            }
            DB::insert(
                'INSERT INTO rd_deferred_payments (advance_id, month, amount) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE amount = VALUES(amount)',
                [$aid, $ym, $amt]
            );

            return false;
        });

        return ApiResponse::out(['ok' => true, 'cleared' => $cleared]);
    }

    /* ═══════════════════════════════════════════════════════════
       8) الصلاحيات (أدمن بس)
    ═══════════════════════════════════════════════════════════ */

    /** GET /api/rd/perms — كل الصلاحيات + مستخدمي النظام */
    public function permsList(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (empty($user['isAdmin'])) {
            throw ApiException::forbidden('الصلاحيات للإدارة فقط');
        }

        $perms = [];
        foreach (DB::select('SELECT * FROM rd_perms ORDER BY username') as $row) {
            $r = (array) $row;
            $keys = [];
            if (DamascusWire::has($r['perm_keys'])) {
                $d = json_decode((string) $r['perm_keys'], true);
                if (is_array($d)) {
                    $keys = $d;
                }
            }
            $branches = [];
            if (DamascusWire::has($r['branches'])) {
                foreach (explode(',', (string) $r['branches']) as $b) {
                    $b = (int) trim($b);
                    if ($b > 0) {
                        $branches[] = $b;
                    }
                }
            }
            $perms[$r['username']] = ['keys' => (object) $keys, 'branches' => $branches];
        }

        /* قطاع روح دمشق لوحده: القايمة فيها بس اللي بيقدر يفتح تطبيق دمشق
           (حسابات دمشق نفسها + محاسب الدهشان) — مش كل موظفي الشركة. */
        $users = array_values(array_filter(
            DB::select('SELECT id, username, name, role FROM users WHERE blocked = 0 ORDER BY username'),
            fn ($u) => $u->role !== 'admin'
                && in_array('damascus', AuthController::appsFor((int) $u->id, (string) $u->role), true)
        ));

        return ApiResponse::out([
            'ok'         => true,
            'serverNow'  => DamascusWire::nowMs(),
            'perms'      => (object) $perms,
            'users'      => $users,
            'permGroups' => DamascusWire::permGroups(),
            'preset'     => ['page.daily','col.in','col.bout','col.bin','col.out','col.h','col.o',
                             'col.svc','col.psvc','col.net','col.adv','col.ded','col.note','act.edit'],
        ]);
    }

    /**
     * PUT /api/rd/perms — {username, keys:{...}, branches:[ids]}
     *
     * ⚠️ القيم `true` بس هي اللي بتتخزّن — أي مفتاح بـfalse بيختفي خالص
     * (مش بيتخزّن false). ده اللي بيخلي `($p['keys'][$k] ?? null) === true`
     * في `can()` صح.
     */
    public function permsSave(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (empty($user['isAdmin'])) {
            throw ApiException::forbidden('الصلاحيات للإدارة فقط');
        }
        $b = $this->body($request);
        $username = trim((string) ($b['username'] ?? ''));
        if ($username === '') {
            throw new ApiException('اختار المستخدم');
        }

        // المفاتيح: النقط بتتحول شرطة سفلية زي القديم، والقيم true بس هي اللي بتتخزّن
        $keys = [];
        foreach ((array) ($b['keys'] ?? []) as $k => $v) {
            if ($v === true || $v === 1 || $v === '1') {
                $keys[DamascusWire::pkey((string) $k)] = true;
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

        DB::transaction(function () use ($username, $keys, $branches): void {
            if (! (DB::select('SELECT id FROM users WHERE username = ? LIMIT 1', [$username])[0] ?? null)) {
                throw ApiException::notFound('المستخدم غير موجود');
            }

            DB::insert(
                'INSERT INTO rd_perms (username, perm_keys, branches) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE perm_keys = VALUES(perm_keys), branches = VALUES(branches)',
                [
                    $username,
                    json_encode((object) $keys, JSON_UNESCAPED_UNICODE),
                    $branches ? implode(',', $branches) : null,
                ]
            );
        });

        return ApiResponse::out(['ok' => true, 'username' => $username,
                                 'perms' => ['keys' => (object) $keys, 'branches' => $branches]]);
    }

    /* ═══════════════════════════════════════════════════════════
       9) الإعدادات (أدمن)
    ═══════════════════════════════════════════════════════════ */

    /** GET /api/rd/settings */
    public function settingsGet(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->requirePerm($user, 'page.settings', 'الإعدادات');

        return ApiResponse::out(['ok' => true, 'serverNow' => DamascusWire::nowMs(), 'settings' => $this->settingsLoad()]);
    }

    /**
     * PUT /api/rd/settings — {hourRate, orderRate, pilotOrderRate, restName,
     * shiftHours, dayStart, devFeeBranchId}
     *
     * 🔴 `orderRate` = رسوم التطوير على كل أوردر و`hourRate` = أجر الساعة
     * الافتراضي — الاتنين بيدخلوا في تقفيلة كل يوم. و`devFeeBranchId`
     * بيقرر مين يتحمّل الرسوم كلها (شوف `DamascusWire::devFeeBranch`).
     *
     * ⚠️ فرق مقصود عن الأصل: الحلقة كلها جوه معاملة واحدة. الأصل كان بيكتب
     * مفتاح مفتاح بلا معاملة، فلو `devFeeBranchId` طلع فرع مش موجود كانت
     * المفاتيح اللي قبله تفضل متكتبة والباقي لأ (كتابة نصّها). عندنا
     * الاستثناء بيرجّع كله — كل الإعدادات تتكتب أو ولا واحد، والنجاح متطابق.
     */
    public function settingsPut(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (empty($user['isAdmin'])) {
            throw ApiException::forbidden('الإعدادات للإدارة فقط');
        }
        $b = $this->body($request);
        $allowedKeys = array_keys(DamascusWire::defaultSettings());

        DB::transaction(function () use ($b, $allowedKeys): void {
            foreach ($b as $k => $v) {
                if (! in_array($k, $allowedKeys, true)) {
                    continue;
                }
                if ($k === 'devFeeBranchId') {
                    $n = (int) DamascusWire::num($v);
                    if ($n > 0) {
                        if (! (DB::select('SELECT id FROM rd_branches WHERE id = ?', [$n])[0] ?? null)) {
                            throw new ApiException('الفرع المتحمّل لرسوم التطوير غير موجود');
                        }
                    }
                    $this->settingPut((string) $k, $n > 0 ? (string) $n : '');

                    continue;
                }
                if ($k === 'restName') {
                    $this->settingPut((string) $k, trim((string) $v));

                    continue;
                }
                $this->settingPut((string) $k, (string) DamascusWire::num($v));
            }
        });

        return ApiResponse::out(['ok' => true, 'settings' => $this->settingsLoad()]);
    }

    /* ═══════════════════════════════════════════════════════════
       10) قفل الشهر
    ═══════════════════════════════════════════════════════════ */

    /** GET /api/rd/month-locks?month= */
    public function monthLocksList(Request $request): JsonResponse
    {
        $this->user($request);
        $ym = $this->monthArg($request->query('month', ''));
        $items = [];
        foreach ($this->monthLocks($ym) as $l) {
            $items[] = [
                'id'       => (int) $l['id'],
                'month'    => $l['month'],
                'branchId' => $l['branch_id'] !== null ? (int) $l['branch_id'] : null,
                'lockedAt' => $l['locked_at'],
                'lockedBy' => $l['locked_by'],
            ];
        }

        return ApiResponse::out(['ok' => true, 'month' => $ym, 'items' => $items,
                                 'locked' => $this->monthLocked($ym, null)]);
    }

    /** POST /api/rd/month-lock — {month, branchId?} (فاضي = قفل عام) */
    public function monthLockCreate(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (empty($user['isAdmin'])) {
            throw ApiException::forbidden('قفل الشهر للإدارة فقط');
        }
        $b = $this->body($request);
        $ym = $this->monthArg($b['month'] ?? '');
        $bid = isset($b['branchId']) && $b['branchId'] !== '' && $b['branchId'] !== null
            ? $this->intId($b['branchId']) : null;

        DB::transaction(function () use ($user, $ym, $bid): void {
            if ($bid !== null) {
                if (! (DB::select('SELECT id FROM rd_branches WHERE id = ?', [$bid])[0] ?? null)) {
                    throw ApiException::notFound('الفرع غير موجود');
                }
            }
            DB::insert(
                'INSERT INTO rd_month_locks (month, branch_id, locked_by) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE locked_at = CURRENT_TIMESTAMP, locked_by = VALUES(locked_by)',
                [$ym, $bid, (string) $user['username']]
            );
        });

        return ApiResponse::out(['ok' => true, 'month' => $ym, 'branchId' => $bid, 'locked' => true]);
    }

    /**
     * DELETE /api/rd/month-lock?month=[&branchId=]
     *
     * بيقرا من الجسم **والـquery** الاتنين — الجسم الأول. الواجهة بتبعت
     * جسم في DELETE والاختبارات بتستخدم الـquery، فالاتنين مدعومين زي الأصل.
     */
    public function monthLockDelete(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (empty($user['isAdmin'])) {
            throw ApiException::forbidden('فتح الشهر للإدارة فقط');
        }
        $b = $this->body($request);
        $ym = $this->monthArg($b['month'] ?? ($request->query('month') ?? ''));
        $rawB = $b['branchId'] ?? ($request->query('branchId') ?? null);
        $bid = ($rawB !== null && $rawB !== '') ? $this->intId($rawB) : null;

        $locked = DB::transaction(function () use ($ym, $bid): bool {
            if ($bid === null) {
                DB::delete('DELETE FROM rd_month_locks WHERE month = ? AND branch_id IS NULL', [$ym]);
            } else {
                DB::delete('DELETE FROM rd_month_locks WHERE month = ? AND branch_id = ?', [$ym, $bid]);
            }

            return $this->monthLocked($ym, $bid);
        });

        return ApiResponse::out(['ok' => true, 'month' => $ym, 'branchId' => $bid, 'locked' => $locked]);
    }

    /* ═══════════════════════════════════════════════════════════
       أدوات داخلية — المقابل لدوال rd_* اللي بتلمس القاعدة والصلاحيات
    ═══════════════════════════════════════════════════════════ */

    /** المقابل لـ body_json() — الجسم كمصفوفة، والفاضي أو المكسور = [] */
    private function body(Request $request): array
    {
        return $request->json()->all();
    }

    /** المقابل لـ rd_int_id(): معرّف موجب، وغير الصالح 400 مش 404 */
    private function intId(mixed $id): int
    {
        $n = (int) $id;
        if ($n <= 0) {
            throw new ApiException('معرّف غير صالح');
        }

        return $n;
    }

    /** "YYYY-MM" — بيرمي خطأ لو الشكل غلط */
    private function monthArg(mixed $v): string
    {
        $m = trim((string) $v);
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
            throw new ApiException('الشهر غير صالح (الشكل YYYY-MM)');
        }

        return $m;
    }

    private function dayArg(mixed $v): int
    {
        $d = (int) $v;
        if ($d < 1 || $d > 31) {
            throw new ApiException('اليوم غير صالح');
        }

        return $d;
    }

    /**
     * المقابل لـ rd_user(): المستخدم الحالي — **موظفين بس** (مش عملاء التطبيق).
     * عميل التطبيق `user_id` بتاعه null، فبيترفض 403 بنفس نص الأصل.
     */
    private function user(Request $request): array
    {
        $actor = $request->actorOrFail();
        if (empty($actor->userId)) {
            throw ApiException::forbidden('غير مسموح لك بهذه العملية');
        }
        $u = $actor->toLegacyArray();
        $u['isAdmin'] = ($u['role'] ?? '') === 'admin';

        return $u;
    }

    /* ── الإعدادات والسياق ───────────────────────────────────── */

    /**
     * الإعدادات: الافتراضي أولًا وبعدين اللي متسجّل في القاعدة فوقه.
     *
     * ⚠️ القيم اللي من القاعدة **نصوص** والافتراضية أرقام — والفرق ده بيبان
     * في الرد (`"30"` مقابل `30`). منقول زي ما هو، الواجهة بتعمل Number()
     * على أي حال.
     */
    private function settingsLoad(): array
    {
        $s = DamascusWire::defaultSettings();
        foreach (DB::select('SELECT setting_key, setting_value FROM rd_settings') as $r) {
            $s[$r->setting_key] = $r->setting_value;
        }

        return $s;
    }

    private function settingPut(string $key, string $value): void
    {
        DB::insert(
            'INSERT INTO rd_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
    }

    /**
     * بيبني سياق شهر كامل: الإعدادات + الفروع + الطيارين + كل الخانات + الملخصات.
     * الخانات بتتحمّل لكل الطيارين (مش المسموحين بس) لأن رسوم التطوير بتتحسب
     * على أوردرات الشركة كلها.
     *
     * ⚠️ **مفيش كاش هنا عن قصد**: مسارات الحفظ بتنده `ctx()` تاني بعد الكتابة
     * عشان ترجّع التقفيلة المحدّثة. كاش كان هيرجّع الأرقام القديمة.
     */
    private function ctx(string $ym): array
    {
        $ctx = [
            'ym'        => $ym,
            'settings'  => $this->settingsLoad(),
            'branches'  => [],
            'pilots'    => [],
            'entries'   => [],   // [pilotId][day] => entry
            'summaries' => [],   // [branchId][day] => summary
        ];

        foreach (DB::select('SELECT * FROM rd_branches ORDER BY id') as $b) {
            $ctx['branches'][] = DamascusWire::branch($b);
        }
        foreach (DB::select('SELECT * FROM rd_pilots ORDER BY id') as $p) {
            $ctx['pilots'][] = DamascusWire::pilot($p);
        }

        // فترات الاستئذان مجمّعة باستعلام واحد (مفيش N+1)
        $permsByEntry = [];
        $permRows = DB::select(
            'SELECT ep.entry_id, ep.perm_out, ep.perm_in
             FROM rd_entry_perms ep JOIN rd_entries e ON e.id = ep.entry_id
             WHERE e.month = ? ORDER BY ep.id',
            [$ym]
        );
        foreach ($permRows as $p) {
            $permsByEntry[(int) $p->entry_id][] = [
                'out' => (string) ($p->perm_out ?? ''),
                'in'  => (string) ($p->perm_in ?? ''),
            ];
        }

        foreach (DB::select('SELECT * FROM rd_entries WHERE month = ?', [$ym]) as $r) {
            $ctx['entries'][(int) $r->pilot_id][(int) $r->day] =
                DamascusWire::entry($r, $permsByEntry[(int) $r->id] ?? []);
        }

        foreach (DB::select('SELECT * FROM rd_summaries WHERE month = ?', [$ym]) as $r) {
            $s = [];
            if ($r->pct  !== null) $s['pct']  = (float) $r->pct;
            if ($r->ext  !== null) $s['ext']  = (float) $r->ext;
            if ($r->exp  !== null) $s['exp']  = (float) $r->exp;
            if ($r->recv !== null) $s['recv'] = (float) $r->recv;
            $ctx['summaries'][(int) $r->branch_id][(int) $r->day] = $s;
        }

        return $ctx;
    }

    /** كل السلف المؤجلة بمدفوعاتها — [pilotId => [rec,...]] */
    private function deferredAll(): array
    {
        $pay = [];
        foreach (DB::select('SELECT * FROM rd_deferred_payments') as $p) {
            $pay[(int) $p->advance_id][$p->month] = (float) $p->amount;
        }
        $out = [];
        foreach (DB::select('SELECT * FROM rd_deferred_advances ORDER BY id') as $r) {
            $id = (int) $r->id;
            $out[(int) $r->pilot_id][] = [
                'id'         => $id,
                'key'        => $r->legacy_key,
                'pilotId'    => (int) $r->pilot_id,
                'date'       => $r->advance_date,
                'amount'     => (float) $r->amount,
                'monthly'    => (float) $r->monthly,
                'startMonth' => $r->start_month,
                'note'       => $r->note,
                'paid'       => $pay[$id] ?? [],
            ];
        }

        return $out;
    }

    /* ── الصلاحيات ───────────────────────────────────────────── */

    /** صلاحيات مستخدم من rd_perms — { keys:{}, branches:[ids] } */
    private function permsRow(string $username): array
    {
        if (isset($this->permsCache[$username])) {
            return $this->permsCache[$username];
        }
        $row = DB::select('SELECT perm_keys, branches FROM rd_perms WHERE username = ? LIMIT 1', [$username])[0] ?? null;
        $keys = [];
        if ($row && DamascusWire::has($row->perm_keys)) {
            $d = json_decode((string) $row->perm_keys, true);
            if (is_array($d)) {
                $keys = $d;
            }
        }
        $branches = [];
        if ($row && DamascusWire::has($row->branches)) {
            foreach (explode(',', (string) $row->branches) as $b) {
                $b = (int) trim($b);
                if ($b > 0) {
                    $branches[] = $b;
                }
            }
        }

        return $this->permsCache[$username] = ['keys' => $keys, 'branches' => $branches];
    }

    /** هل المستخدم مسموح له بالحاجة دي؟ (الأدمن بيشوف كل حاجة) */
    private function can(array $user, string $key): bool
    {
        if (! empty($user['isAdmin'])) {
            return true;
        }
        $p = $this->permsRow((string) $user['username']);

        // المقارنة `=== true` مقصودة — المفتاح الغايب أو أي قيمة تانية = ممنوع
        return ($p['keys'][DamascusWire::pkey($key)] ?? null) === true;
    }

    private function requirePerm(array $user, string $key, string $what = ''): void
    {
        if (! $this->can($user, $key)) {
            throw ApiException::forbidden($what !== '' ? ('مالكش صلاحية ' . $what) : 'مالكش صلاحية للعملية دي');
        }
    }

    /** أي مفتاح من اللستة يكفي */
    private function requireAny(array $user, array $keys, string $what = ''): void
    {
        foreach ($keys as $k) {
            if ($this->can($user, $k)) {
                return;
            }
        }
        throw ApiException::forbidden($what !== '' ? ('مالكش صلاحية ' . $what) : 'مالكش صلاحية للعملية دي');
    }

    /** الفروع المسموحة — الأدمن الكل، وغيره فروعه (لستة فاضية = الكل) */
    private function allowedBranchIds(array $user, array $ctx): array
    {
        $all = array_map(fn ($b) => $b['id'], $ctx['branches']);
        if (! empty($user['isAdmin'])) {
            return $all;
        }
        $list = $this->permsRow((string) $user['username'])['branches'];
        if (! $list) {
            return $all;   // ⚠️ لستة فاضية = كل الفروع، مش «ولا فرع»
        }

        return array_values(array_intersect($all, $list));
    }

    private function canSeeBranch(array $user, array $ctx, int $branchId): bool
    {
        return in_array($branchId, $this->allowedBranchIds($user, $ctx), true);
    }

    private function requireBranch(array $user, array $ctx, int $branchId): void
    {
        if (! $this->canSeeBranch($user, $ctx, $branchId)) {
            throw ApiException::forbidden('الفرع ده مش من فروعك');
        }
    }

    /**
     * كشف الطيار/الشهر مخفي لحسابات «المُلّاك» عن أي حد غير الأدمن — إلا
     * اللي معاه صلاحية `view.owner`. بيظهروا عادي في التقفيل اليومي عشان
     * إجمالي الفرع يفضل مطابق للنقدية اللي المشرف بيسلّمها (زي القديم).
     */
    private function sheetHidden(array $user, array $pilot): bool
    {
        if (! DamascusWire::isOwnerPilot($pilot)) {
            return false;
        }

        return ! $this->can($user, 'view.owner');
    }

    /**
     * حارسان على الكتابة بيطابقوا النسخة القديمة (كانوا في الواجهة بس):
     *   • من غير `act.dateNav` المستخدم بيشتغل على **النهارده** بس — أي يوم
     *     تاني بيترفض حتى لو الطلب جه من الكونسول.
     *   • الكتابة من كشف الطيار (`via: pilot`) محتاجة `act.editPilot` —
     *     الكشف بيحسب المرتّب، فمشرف بيسجّل اليوم مايعدّلش فيه.
     */
    private function requireWriteWindow(array $user, array $ctx, string $ym, int $day, array $body): void
    {
        if (! empty($user['isAdmin'])) {
            return;
        }
        if (! $this->can($user, 'act.dateNav')) {
            $today = DamascusWire::bizToday($ctx['settings']);
            if (sprintf('%s-%02d', $ym, $day) !== $today) {
                throw ApiException::forbidden('مالكش صلاحية تشتغل على يوم تاني — النهارده بس');
            }
        }
        if ((string) ($body['via'] ?? '') === 'pilot' && ! $this->can($user, 'act.editPilot')) {
            throw ApiException::forbidden('مالكش صلاحية تعديل كشف الطيار');
        }
    }

    /* ── قفل الشهر ───────────────────────────────────────────── */

    /** @return array<int,array<string,mixed>> */
    private function monthLocks(string $ym): array
    {
        return array_map(fn ($r) => (array) $r, DB::select('SELECT * FROM rd_month_locks WHERE month = ?', [$ym]));
    }

    /** القفل العام (branch_id = NULL) بيقفل كل الفروع؛ وقفل الفرع بيقفله هو بس */
    private function monthLocked(string $ym, ?int $branchId): bool
    {
        foreach ($this->monthLocks($ym) as $l) {
            if ($l['branch_id'] === null) {
                return true;                    // قفل عام
            }
            if ($branchId !== null && (int) $l['branch_id'] === $branchId) {
                return true;
            }
        }

        return false;
    }

    private function requireUnlocked(string $ym, ?int $branchId): void
    {
        if ($this->monthLocked($ym, $branchId)) {
            throw new ApiException('الشهر مقفول — مفيش تعديل بعد التقفيل', 409);
        }
    }

    /* ── صفوف الخانات ────────────────────────────────────────── */

    /** بيرجّع id صف الخانة (بينشئه لو مش موجود) */
    private function entryRowId(string $ym, int $pilotId, int $day, bool $create): ?int
    {
        $row = DB::select('SELECT id FROM rd_entries WHERE month = ? AND pilot_id = ? AND day = ? LIMIT 1', [$ym, $pilotId, $day])[0] ?? null;
        if ($row) {
            return (int) $row->id;
        }
        if (! $create) {
            return null;
        }
        DB::insert('INSERT INTO rd_entries (month, pilot_id, day) VALUES (?, ?, ?)', [$ym, $pilotId, $day]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /** لو الصف بقى فاضي خالص (كل الأعمدة NULL ومفيش فترات استئذان) بيتمسح */
    private function entryCleanup(int $entryId): bool
    {
        $r = DB::select('SELECT * FROM rd_entries WHERE id = ?', [$entryId])[0] ?? null;
        if (! $r) {
            return false;
        }
        foreach (['time_in','time_out','hours','orders_count','svc','psvc_override',
                  'net_override','advance','deduction'] as $c) {
            if ($r->{$c} !== null) {
                return false;
            }
        }
        if (DamascusWire::has($r->note)) {
            return false;
        }
        $cnt = (int) DB::select('SELECT COUNT(*) c FROM rd_entry_perms WHERE entry_id = ?', [$entryId])[0]->c;
        if ($cnt > 0) {
            return false;
        }
        DB::delete('DELETE FROM rd_entries WHERE id = ?', [$entryId]);

        return true;
    }
}
