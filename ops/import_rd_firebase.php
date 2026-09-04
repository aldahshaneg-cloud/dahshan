<?php
/**
 * 🔁 نقل بيانات «تقفيل روح دمشق» من Firebase (الجذر rd/*) لجداول rd_* — قابل للإعادة.
 *
 * ═══ ليه الملف ده موجود ═══
 * النسخة اللي على السيرفر من روح دمشق اتنقلت من نفس كود البرنامج القديم،
 * بس **البيانات** فضلت في Firebase: على الإنتاج rd_entries وrd_summaries
 * صفر صف، والفروع والطيارين اللي هناك مجرد ١٣ مشرف من غير مفاتيح Firebase.
 * الملف ده بياخد تصدير rd (JSON) ويحطه في القاعدة **من غير ما يعمل صفوف
 * مكرّرة**: الفرع والطيار الموجودين بيتعرفوا بالاسم (والفرع) ويتحط عليهم
 * `legacy_key`، وبعد كده المفتاح هو المرجع.
 *
 * ═══ ليه مش import_firebase.php القديم ═══
 * أداة الترحيل العامة (aldahshan/db/migrate/import_firebase.php) بتقرا خانات
 * الشيت بمفاتيح `hours/orders/advance/deduction` — والبرنامج القديم بيخزّنها
 * فعلًا `h/o/adv/ded`. يعني كانت هتنقل الساعات والأوردرات والسلف والخصومات
 * **صفر** بصمت. المفاتيح هنا هي اللي في الكود القديم حرفيًا (FIELDS في
 * index.html): in · out · h · o · svc · psvc · net · adv · ded · note · perms.
 *
 * ═══ الشكل اللي بييجي من Firebase ═══
 * أيام الطيار ممكن تيجي **مصفوفة** (لما المفاتيح أرقام متتالية: [null, يوم١,
 * يوم٢…]) أو **كائن** {"3": {...}} — الاتنين بيتعالجوا في days().
 * Firebase مابيقبلش نقطة في اسم المفتاح، فمفاتيح الصلاحيات جاية بشرطة سفلية
 * (page_daily) وده نفس الشكل اللي DamascusController::can() بيدوّر عليه.
 *
 * ═══ التشغيل ═══
 *   php ops/import_rd_firebase.php <export.json> [--dry-run] [--accounts]
 *     --dry-run   يعمل كل حاجة جوه معاملة ويرجّعها — عشان تشوف الأرقام الأول
 *     --accounts  يعمل حسابات دخول للمشرفين اللي في users (بدور accountant
 *                 وتطبيق damascus بس) بكلمة سر عشوائية — الإدارة بتحطها من
 *                 شاشة الصلاحيات. الحساب الموجود مابيتلمسش.
 *
 * ⚠️ على الإنتاج: خد نسخة من القاعدة الأول، وشغّله --dry-run قبل الحقيقي.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
require $ROOT . '/vendor/autoload.php';
$app = require $ROOT . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Wire\DamascusWire as W;
use Illuminate\Support\Facades\DB;

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "\n🔴 " . get_class($e) . ' — ' . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
});

/* ── الوسائط ─────────────────────────────────────────────────── */
$file = null;
$dry = false;
$accounts = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') { $dry = true; } elseif ($a === '--accounts') { $accounts = true; } else { $file = $a; }
}
if ($file === null || ! is_file($file)) {
    fwrite(STDERR, "الاستخدام: php ops/import_rd_firebase.php <export.json> [--dry-run] [--accounts]\n");
    exit(2);
}
$data = json_decode((string) file_get_contents($file), true);
$rd = $data['rd'] ?? $data;   // بيقبل الملف كامل {rd, users} أو الجذر rd لوحده
if (! is_array($rd) || ! isset($rd['branches'])) {
    fwrite(STDERR, "الملف مافيهوش rd/branches — ده مش تصدير روح دمشق\n");
    exit(2);
}
$fbUsers = is_array($data['users'] ?? null) ? $data['users'] : [];

/* ── أدوات ───────────────────────────────────────────────────── */
$issues = [];
$issue = function (string $what) use (&$issues): void { $issues[] = $what; };

/** أيام الطيار/الفرع من Firebase: مصفوفة [null, يوم١…] أو كائن {"3": …} → [يوم => سجل] */
function days(mixed $node): array
{
    if (! is_array($node)) {
        return [];
    }
    $out = [];
    foreach ($node as $k => $v) {
        $d = (int) $k;
        if ($d < 1 || $d > 31 || ! is_array($v) || ! $v) {
            continue;
        }
        $out[$d] = $v;
    }
    ksort($out);

    return $out;
}

/** اسم للمقارنة: مسافات مضغوطة + توحيد الألف والياء والتاء المربوطة */
function normName(string $s): string
{
    $s = preg_replace('/\s+/u', ' ', trim($s)) ?? $s;
    $s = str_replace(['أ', 'إ', 'آ'], 'ا', $s);
    $s = str_replace(['ى'], 'ي', $s);
    $s = str_replace(['ة'], 'ه', $s);

    return $s;
}

/** رقم أو null — الصفر بيتسجّل NULL زي ما saveCell القديمة كانت بتمسح الخانة */
function numOrNull(mixed $v): ?float
{
    if ($v === null || $v === '' || ! is_numeric($v)) {
        return null;
    }
    $n = (float) $v;

    return $n === 0.0 ? null : $n;
}

/** نص أو null */
function strOrNull(mixed $v, int $max = 8): ?string
{
    $s = trim((string) ($v ?? ''));

    return $s === '' ? null : mb_substr($s, 0, $max);
}

$rep = ['branches' => [0, 0], 'pilots' => [0, 0], 'perms' => [0, 0], 'settings' => 0,
        'entries' => [0, 0], 'entry_perms' => 0, 'summaries' => [0, 0], 'deferred' => [0, 0],
        'payments' => 0, 'locks' => 0, 'owners' => 0, 'accounts' => [0, 0]];
$now = date('Y-m-d H:i:s');

DB::beginTransaction();

/* ══════════ 1) الفروع ══════════ */
$branchMap = [];   // مفتاح Firebase → id
$branches = $rd['branches'] ?? [];
uasort($branches, fn ($a, $b) => (int) ($a['order'] ?? 99) <=> (int) ($b['order'] ?? 99));
foreach ($branches as $key => $b) {
    $name = trim((string) ($b['name'] ?? ''));
    if ($name === '') {
        $issue("rd/branches/$key بلا اسم — اتعدّى");
        continue;
    }
    $row = DB::select('SELECT id FROM rd_branches WHERE legacy_key = ? LIMIT 1', [$key])[0] ?? null;
    if (! $row) {
        foreach (DB::select('SELECT id, name FROM rd_branches WHERE legacy_key IS NULL') as $r) {
            if (normName($r->name) === normName($name)) {
                $row = $r;
                break;
            }
        }
    }
    if ($row) {
        DB::update('UPDATE rd_branches SET legacy_key = ?, name = ?, active = 1 WHERE id = ?', [$key, $name, $row->id]);
        $branchMap[$key] = (int) $row->id;
        $rep['branches'][1]++;
    } else {
        DB::insert('INSERT INTO rd_branches (legacy_key, name, active, created_at) VALUES (?, ?, 1, ?)', [$key, $name, $now]);
        $branchMap[$key] = (int) DB::getPdo()->lastInsertId();
        $rep['branches'][0]++;
    }
}

/* ══════════ 2) الطيارين ══════════ */
$pilotMap = [];   // مفتاح Firebase → id
$pilots = $rd['pilots'] ?? [];
uasort($pilots, fn ($a, $b) => (int) ($a['order'] ?? 999) <=> (int) ($b['order'] ?? 999));
$legacyOwners = W::legacyOwnerNames();
foreach ($pilots as $key => $p) {
    $name = trim((string) ($p['name'] ?? ''));
    $bid = $branchMap[(string) ($p['branchId'] ?? '')] ?? null;
    if ($name === '' || $bid === null) {
        $issue("rd/pilots/$key ($name): فرع مفقود أو اسم فاضي — اتعدّى");
        continue;
    }
    $job = trim((string) ($p['job'] ?? ''));
    /* ترحيل المُلّاك مرة واحدة — زي migrateLegacyOwners في البرنامج القديم:
       العلامة القديمة isOwner أو الاسم من القايمة القديمة → وظيفة «مالك».
       بعد كده الوظيفة هي المصدر الوحيد. */
    if ($job !== W::OWNER_JOB) {
        $wasOwner = ! empty($p['isOwner']);
        foreach ($legacyOwners as $x) {
            if (str_contains($name, $x)) {
                $wasOwner = true;
            }
        }
        if ($wasOwner) {
            $job = W::OWNER_JOB;
            $rep['owners']++;
        }
    }
    $vals = [
        'branch_id'  => $bid,
        'name'       => $name,
        'active'     => array_key_exists('active', $p) ? (int) (bool) $p['active'] : 1,
        'job'        => $job !== '' ? $job : null,
        'hour_rate'  => (float) W::num($p['hourRate'] ?? 0),
        'order_rate' => (float) W::num($p['orderRate'] ?? 0),
        'leave_days' => (int) W::num($p['leaveDays'] ?? 0),
    ];

    $row = DB::select('SELECT id FROM rd_pilots WHERE legacy_key = ? LIMIT 1', [$key])[0] ?? null;
    if (! $row) {
        /* الموجودين من غير مفتاح: مطابقة بالاسم جوه نفس الفرع. الاسم الناقص
           («حمادة» ↔ «احمد حمادة») بيتقبل لو مافيش غيره في الفرع بيحتويه. */
        $cands = DB::select('SELECT id, name FROM rd_pilots WHERE legacy_key IS NULL AND branch_id = ?', [$bid]);
        $n = normName($name);
        foreach ($cands as $r) {
            if (normName($r->name) === $n) {
                $row = $r;
                break;
            }
        }
        if (! $row) {
            $partial = array_values(array_filter($cands, function ($r) use ($n): bool {
                $m = normName($r->name);

                return $m !== '' && (str_contains($n, $m) || str_contains($m, $n));
            }));
            if (count($partial) === 1) {
                $row = $partial[0];
                $issue("rd/pilots/$key: «{$name}» اتطابق جزئيًا مع «{$row->name}» (id {$row->id}) — الاسم اتحدّث");
            }
        }
    }
    if ($row) {
        DB::update(
            'UPDATE rd_pilots SET legacy_key = ?, branch_id = ?, name = ?, active = ?, job = ?,
                    hour_rate = ?, order_rate = ?, leave_days = ? WHERE id = ?',
            [$key, $vals['branch_id'], $vals['name'], $vals['active'], $vals['job'],
             $vals['hour_rate'], $vals['order_rate'], $vals['leave_days'], $row->id]
        );
        $pilotMap[$key] = (int) $row->id;
        $rep['pilots'][1]++;
    } else {
        DB::insert(
            'INSERT INTO rd_pilots (legacy_key, branch_id, name, active, job, hour_rate, order_rate, leave_days, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$key, $vals['branch_id'], $vals['name'], $vals['active'], $vals['job'],
             $vals['hour_rate'], $vals['order_rate'], $vals['leave_days'], $now]
        );
        $pilotMap[$key] = (int) DB::getPdo()->lastInsertId();
        $rep['pilots'][0]++;
    }
}

/* ══════════ 3) الصلاحيات ══════════ */
foreach (($rd['perms'] ?? []) as $username => $p) {
    $username = trim((string) $username);
    if ($username === '' || ! is_array($p)) {
        continue;
    }
    $keys = [];
    foreach ((array) ($p['keys'] ?? []) as $k => $v) {
        if ($v === true) {
            $keys[W::pkey((string) $k)] = true;   // بيوصل بشرطة سفلية أصلًا — pkey للأمان
        }
    }
    $ids = [];
    foreach ((array) ($p['branches'] ?? []) as $bk) {
        if (isset($branchMap[(string) $bk])) {
            $ids[] = $branchMap[(string) $bk];
        }
    }
    $json = json_encode($keys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $br = $ids ? implode(',', $ids) : null;
    $row = DB::select('SELECT id FROM rd_perms WHERE username = ? LIMIT 1', [$username])[0] ?? null;
    if ($row) {
        DB::update('UPDATE rd_perms SET perm_keys = ?, branches = ? WHERE id = ?', [$json, $br, $row->id]);
        $rep['perms'][1]++;
    } else {
        DB::insert('INSERT INTO rd_perms (username, perm_keys, branches, created_at) VALUES (?, ?, ?, ?)', [$username, $json, $br, $now]);
        $rep['perms'][0]++;
    }
}

/* ══════════ 4) الإعدادات ══════════ */
/* نفس تخزين settingsPut: أرقام كنص، restName نص، devFeeBranchId رقم الفرع أو فاضي */
$allowed = array_keys(W::defaultSettings());
foreach ((array) ($rd['settings'] ?? []) as $k => $v) {
    if (! in_array($k, $allowed, true)) {
        continue;
    }
    if ($k === 'devFeeBranchId') {
        $v = (string) $v === '' ? '' : (string) ($branchMap[(string) $v] ?? '');
        if ($v === '' && (string) ($rd['settings'][$k] ?? '') !== '') {
            $issue('rd/settings/devFeeBranchId بيشاور على فرع مش موجود — اتساب فاضي');
        }
    } elseif ($k === 'restName') {
        $v = trim((string) $v);
    } else {
        $v = (string) W::num($v);
    }
    DB::insert(
        'INSERT INTO rd_settings (setting_key, setting_value, created_at) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        [$k, $v, $now]
    );
    $rep['settings']++;
}

/* ══════════ 5) الخانات — rd/entries/{ym}/{pilotKey}/{day} ══════════ */
foreach (($rd['entries'] ?? []) as $ym => $monthNode) {
    if (! preg_match('/^\d{4}-\d{2}$/', (string) $ym) || ! is_array($monthNode)) {
        $issue("rd/entries/$ym: مفتاح شهر غير صالح — اتعدّى");
        continue;
    }
    foreach ($monthNode as $pk => $daysNode) {
        $pid = $pilotMap[(string) $pk] ?? null;
        if ($pid === null) {
            $issue("rd/entries/$ym/$pk: طيار مش معروف — اتعدّى");
            continue;
        }
        foreach (days($daysNode) as $day => $e) {
            /* الاستئذان: الشكل الجديد perms[] أو القديم bout/bin */
            $perms = [];
            if (isset($e['perms']) && is_array($e['perms'])) {
                foreach ($e['perms'] as $pr) {
                    if (! is_array($pr)) {
                        continue;
                    }
                    $o = strOrNull($pr['out'] ?? null);
                    $i = strOrNull($pr['in'] ?? null);
                    if ($o !== null || $i !== null) {
                        $perms[] = [$o, $i];
                    }
                }
            } elseif (strOrNull($e['bout'] ?? null) !== null || strOrNull($e['bin'] ?? null) !== null) {
                $perms[] = [strOrNull($e['bout'] ?? null), strOrNull($e['bin'] ?? null)];
            }
            $cols = [
                'time_in'       => strOrNull($e['in'] ?? null),
                'time_out'      => strOrNull($e['out'] ?? null),
                'hours'         => numOrNull($e['h'] ?? null),
                'orders_count'  => numOrNull($e['o'] ?? null) !== null ? (int) $e['o'] : null,
                'svc'           => numOrNull($e['svc'] ?? null),
                'psvc_override' => numOrNull($e['psvc'] ?? null),
                'net_override'  => numOrNull($e['net'] ?? null),
                'advance'       => numOrNull($e['adv'] ?? null),
                'deduction'     => numOrNull($e['ded'] ?? null),
                'note'          => ($n = trim((string) ($e['note'] ?? ''))) !== '' ? $n : null,
            ];
            $empty = ! $perms;
            foreach ($cols as $v) {
                if ($v !== null) {
                    $empty = false;
                }
            }
            if ($empty) {
                continue;   // خانة فاضية = مافيش صف (زي entryCleanup)
            }
            $lk = "$ym/$pk/$day";
            $row = DB::select('SELECT id FROM rd_entries WHERE month = ? AND pilot_id = ? AND day = ? LIMIT 1', [$ym, $pid, $day])[0] ?? null;
            if ($row) {
                DB::update(
                    'UPDATE rd_entries SET legacy_key = ?, time_in = ?, time_out = ?, hours = ?, orders_count = ?, svc = ?,
                            psvc_override = ?, net_override = ?, advance = ?, deduction = ?, note = ? WHERE id = ?',
                    [$lk, ...array_values($cols), $row->id]
                );
                $eid = (int) $row->id;
                $rep['entries'][1]++;
            } else {
                DB::insert(
                    'INSERT INTO rd_entries (legacy_key, month, pilot_id, day, time_in, time_out, hours, orders_count, svc,
                            psvc_override, net_override, advance, deduction, note, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$lk, $ym, $pid, $day, ...array_values($cols), $now]
                );
                $eid = (int) DB::getPdo()->lastInsertId();
                $rep['entries'][0]++;
            }
            DB::delete('DELETE FROM rd_entry_perms WHERE entry_id = ?', [$eid]);
            foreach ($perms as [$o, $i]) {
                DB::insert('INSERT INTO rd_entry_perms (entry_id, perm_out, perm_in, created_at) VALUES (?, ?, ?, ?)', [$eid, $o, $i, $now]);
                $rep['entry_perms']++;
            }
        }
    }
}

/* ══════════ 6) الملخص اليومي — rd/summary/{ym}/{branchKey}/{day} ══════════ */
foreach (($rd['summary'] ?? []) as $ym => $monthNode) {
    if (! preg_match('/^\d{4}-\d{2}$/', (string) $ym) || ! is_array($monthNode)) {
        $issue("rd/summary/$ym: مفتاح شهر غير صالح — اتعدّى");
        continue;
    }
    if (! empty($monthNode['locked'])) {
        if (! DB::select('SELECT id FROM rd_month_locks WHERE month = ? AND branch_id IS NULL LIMIT 1', [$ym])) {
            DB::insert('INSERT INTO rd_month_locks (month, branch_id, locked_at, locked_by) VALUES (?, NULL, ?, NULL)', [$ym, $now]);
            $rep['locks']++;
        }
    }
    foreach ($monthNode as $bk => $daysNode) {
        $bid = $branchMap[(string) $bk] ?? null;
        if ($bid === null) {
            continue;   // مفاتيح غير فرعية (locked…) أو فرع مش معروف
        }
        if (is_array($daysNode) && ! empty($daysNode['locked'])) {
            if (! DB::select('SELECT id FROM rd_month_locks WHERE month = ? AND branch_id = ? LIMIT 1', [$ym, $bid])) {
                DB::insert('INSERT INTO rd_month_locks (month, branch_id, locked_at, locked_by) VALUES (?, ?, ?, ?)',
                    [$ym, $bid, $now, strOrNull($daysNode['lockedBy'] ?? null, 100)]);
                $rep['locks']++;
            }
        }
        foreach (days($daysNode) as $day => $s) {
            $vals = ['pct' => numOrNull($s['pct'] ?? null), 'ext' => numOrNull($s['ext'] ?? null),
                     'exp' => numOrNull($s['exp'] ?? null), 'recv' => numOrNull($s['recv'] ?? null)];
            /* «المستلم فعلًا» صفر معناه المشرف ورّد صفر فعلًا — مش خانة فاضية.
               numOrNull بتصفّر، فبنرجّعه لو كان مكتوب بصفر صراحةً. */
            if (array_key_exists('recv', $s) && is_numeric($s['recv']) && (float) $s['recv'] === 0.0) {
                $vals['recv'] = 0.0;
            }
            if ($vals['pct'] === null && $vals['ext'] === null && $vals['exp'] === null && $vals['recv'] === null) {
                continue;
            }
            $lk = "$ym/$bk/$day";
            $row = DB::select('SELECT id FROM rd_summaries WHERE month = ? AND branch_id = ? AND day = ? LIMIT 1', [$ym, $bid, $day])[0] ?? null;
            if ($row) {
                DB::update('UPDATE rd_summaries SET legacy_key = ?, pct = ?, ext = ?, exp = ?, recv = ? WHERE id = ?',
                    [$lk, $vals['pct'], $vals['ext'], $vals['exp'], $vals['recv'], $row->id]);
                $rep['summaries'][1]++;
            } else {
                DB::insert('INSERT INTO rd_summaries (legacy_key, month, branch_id, day, pct, ext, exp, recv, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$lk, $ym, $bid, $day, $vals['pct'], $vals['ext'], $vals['exp'], $vals['recv'], $now]);
                $rep['summaries'][0]++;
            }
        }
    }
}

/* ══════════ 7) السلف المؤجلة — rd/deferred/{key} ══════════ */
foreach (($rd['deferred'] ?? []) as $key => $d) {
    if (! is_array($d)) {
        continue;
    }
    $pid = $pilotMap[(string) ($d['pilotId'] ?? '')] ?? null;
    if ($pid === null) {
        $issue("rd/deferred/$key: طيار مش معروف — اتعدّت");
        continue;
    }
    $date = strOrNull($d['date'] ?? null, 10);
    $start = strOrNull($d['startMonth'] ?? null, 7) ?? ($date !== null ? substr($date, 0, 7) : null);
    if ($start === null || ! preg_match('/^\d{4}-\d{2}$/', $start)) {
        $issue("rd/deferred/$key: مافيش شهر بداية — اتعدّت");
        continue;
    }
    $vals = [$pid, $date, (float) W::num($d['amount'] ?? 0), (float) W::num($d['monthly'] ?? 0), $start, strOrNull($d['note'] ?? null, 500)];
    $row = DB::select('SELECT id FROM rd_deferred_advances WHERE legacy_key = ? LIMIT 1', [$key])[0] ?? null;
    if ($row) {
        DB::update('UPDATE rd_deferred_advances SET pilot_id = ?, advance_date = ?, amount = ?, monthly = ?, start_month = ?, note = ? WHERE id = ?', [...$vals, $row->id]);
        $aid = (int) $row->id;
        $rep['deferred'][1]++;
    } else {
        DB::insert('INSERT INTO rd_deferred_advances (legacy_key, pilot_id, advance_date, amount, monthly, start_month, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$key, ...$vals, $now]);
        $aid = (int) DB::getPdo()->lastInsertId();
        $rep['deferred'][0]++;
    }
    DB::delete('DELETE FROM rd_deferred_payments WHERE advance_id = ?', [$aid]);
    foreach ((array) ($d['paid'] ?? []) as $pm => $amt) {
        if (! preg_match('/^\d{4}-\d{2}$/', (string) $pm) || ! is_numeric($amt)) {
            continue;
        }
        DB::insert('INSERT INTO rd_deferred_payments (advance_id, month, amount, created_at) VALUES (?, ?, ?, ?)', [$aid, $pm, (float) $amt, $now]);
        $rep['payments']++;
    }
}

/* ══════════ 8) حسابات دخول المشرفين (اختياري) ══════════ */
if ($accounts) {
    foreach ($fbUsers as $username => $u) {
        $username = trim((string) $username);
        if ($username === '' || ! is_array($u)) {
            continue;
        }
        $apps = (array) ($u['allowedApps'] ?? []);
        if (! in_array('damascus', $apps, true) || (string) ($u['source'] ?? '') !== 'rd') {
            continue;   // حسابات الإدارة والمحلات لها مكانها في النظام الأساسي
        }
        if (DB::select('SELECT id FROM users WHERE username = ? LIMIT 1', [$username])) {
            $rep['accounts'][1]++;
            continue;   // موجود — مانلمسش دوره ولا كلمة سره
        }
        /* الدور accountant هو اللي المسارات rd/* بتقبله (role:admin,accountant)،
           وصف user_app_permissions بـdamascus بس بيمنعه من باقي تطبيقات
           الحسابات (appsFor بيقرا الصفوف الصريحة قبل افتراضي الدور). */
        DB::insert(
            'INSERT INTO users (username, password_hash, role, name, created_at) VALUES (?, ?, ?, ?, ?)',
            [$username, password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), 'accountant',
             trim((string) ($u['displayName'] ?? $username)), $now]
        );
        $uid = (int) DB::getPdo()->lastInsertId();
        DB::insert('INSERT INTO user_app_permissions (user_id, app, created_at) VALUES (?, ?, ?)', [$uid, 'damascus', $now]);
        $rep['accounts'][0]++;
    }
}

/* ══════════ التقرير ══════════ */
$fmt = fn (array $c) => "جديد {$c[0]} · محدّث {$c[1]}";
echo "\n═══ نقل روح دمشق من Firebase" . ($dry ? ' — تجربة (هيترجع)' : '') . " ═══\n";
echo "  الفروع        : {$fmt($rep['branches'])}\n";
echo "  الطيارين      : {$fmt($rep['pilots'])}   (اتعلّم كمالك: {$rep['owners']})\n";
echo "  الصلاحيات     : {$fmt($rep['perms'])}\n";
echo "  الإعدادات     : {$rep['settings']} مفتاح\n";
echo "  الخانات       : {$fmt($rep['entries'])}   (استئذانات {$rep['entry_perms']})\n";
echo "  الملخص اليومي : {$fmt($rep['summaries'])}   (أقفال {$rep['locks']})\n";
echo "  السلف المؤجلة : {$fmt($rep['deferred'])}   (أقساط {$rep['payments']})\n";
if ($accounts) {
    echo "  حسابات الدخول : جديد {$rep['accounts'][0]} · موجود {$rep['accounts'][1]}\n";
}
if ($issues) {
    echo "\n  ⚠️ ملاحظات (" . count($issues) . "):\n";
    foreach ($issues as $i) {
        echo "   - $i\n";
    }
}
$cnt = fn (string $t) => (int) DB::select("SELECT COUNT(*) c FROM {$t}")[0]->c;
echo "\n  الجداول بعد النقل: rd_branches {$cnt('rd_branches')} · rd_pilots {$cnt('rd_pilots')} · rd_entries {$cnt('rd_entries')} · rd_summaries {$cnt('rd_summaries')} · rd_perms {$cnt('rd_perms')}\n";

if ($dry) {
    DB::rollBack();
    echo "\n↩ تجربة — اترجّع كل حاجة.\n";
} else {
    DB::commit();
    echo "\n✅ اتحفظ.\n";
}
