<?php

declare(strict_types=1);

/**
 * 🧪 مناورة: ترحيل طيار روح دمشق للأرشيف — والحسابات ماتتغيّرش ولا رقم.
 *
 * طلب صاحب النظام (2026-09-12): «عايز في تقفيل روح دمشق مكان أرحّل فيه
 * الطيارين اللي ما بقوش بيشتغلوا — لأني لو مسحته هيأثر على الحسابات.
 * وخلّي ده كمان له صلاحيات».
 *
 * بتشغّل **النداءات الحقيقية** (`pilotsArchive` / `pilotsUnarchive` /
 * `archiveList` / `pilotsList`) وبتقارن **تقفيلة الشهر قبل وبعد** بالحرف.
 * دي الحتة المهمة: الحذف بيغيّر تقفيلات شهور فاتت، والترحيل المفروض لأ.
 *
 * وبتتأكد من الصلاحيات: حساب من غير `act.archive` بيترفض، ومن غير
 * `page.archive` مايشوفش الشاشة.
 *
 * 🔴 **مافيش أي كتابة بتفضل** — كله جوه معاملة بتترجع في `finally`.
 *
 * التشغيل (على السيرفر):  php ops/drill_rd_archive.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\DamascusController;
use App\Support\Actor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;
$ok = function (string $what, bool $cond, string $got = '') use (&$pass, &$fail): void {
    if ($cond) {
        $pass++;
        echo "  ✓ {$what}\n";
    } else {
        $fail++;
        echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n";
    }
};

$admin = DB::selectOne("SELECT id, username, name FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1");
if (! $admin) {
    exit("🛑 مافيش حساب أدمن\n");
}
$adminActor = Actor::staff((int) $admin->id, $admin->username, 'admin', null, (string) ($admin->name ?? ''));
$cur = $adminActor;
Request::macro('actorOrFail', function () use (&$cur) { return $cur; });

/* ⚠️ `DamascusController::body()` بتقرا `$request->json()` بس — الحقول
   المبعوتة كـform params مابتوصلش. المناورة لازم تبعت JSON زي الواجهة. */
$post = static function (string $path, array $json = []): Illuminate\Http\Request {
    return Illuminate\Http\Request::create(
        $path, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($json, JSON_UNESCAPED_UNICODE)
    );
};

$ctl = new DamascusController();
$get = function (string $m, array $q = [], ?string $id = null) use ($ctl) {
    $r = Request::create('/x', 'GET', $q);

    return json_decode(($id === null ? $ctl->$m($r) : $ctl->$m($r, $id))->getContent(), true);
};

/* طيار عليه شغل فعلي — عشان المقارنة تبقى ليها معنى */
$p = DB::selectOne(
    'SELECT p.id, p.name, p.branch_id, COUNT(e.id) n
       FROM rd_pilots p JOIN rd_entries e ON e.pilot_id = p.id
      WHERE p.archived_at IS NULL
      GROUP BY p.id, p.name, p.branch_id ORDER BY n DESC LIMIT 1'
);
if (! $p) {
    exit("⚠️ مفيش طيار روح دمشق عليه خانات — المناورة محتاجة واحد\n");
}
/* ⚠️ `rd_entries.month` عمود لوحده و`day` رقم اليوم في الشهر — مش تاريخ */
$month = DB::selectOne('SELECT month FROM rd_entries WHERE pilot_id = ? ORDER BY month DESC, day DESC LIMIT 1', [$p->id])->month;

echo "🧪 «{$p->name}» (#{$p->id}) — عليه {$p->n} خانة · شهر المقارنة {$month}\n";
echo str_repeat('═', 58) . "\n";

/** بصمة تقفيلة الشهر — **الأرقام بس**.
 *
 * 🔴 مهم إنها أرقام بس: الرد بيحمل كمان علامة `archived` على الطيار،
 *    ولو دخلت البصمة كانت هتقول «الحسابات اتغيّرت» وهي ما اتغيّرتش —
 *    إنذار كاذب بيخفي إن الاختبار مش بيقيس اللي المفروض يقيسه. */
$nums = function ($v) use (&$nums): array {
    $out = [];
    foreach ((array) $v as $k => $x) {
        if (is_array($x)) { $out = array_merge($out, $nums($x)); }
        elseif (is_int($x) || is_float($x)) { $out[] = $k . '=' . round((float) $x, 2); }
        elseif (is_string($x) && is_numeric($x)) { $out[] = $k . '=' . round((float) $x, 2); }
    }

    return $out;
};
$fingerprint = function () use ($get, $month, $nums): string {
    $d = $get('closeoutMonth', ['month' => $month]);
    $out = [];
    foreach ($d['pilots'] ?? $d['rows'] ?? [] as $row) {
        $f = $nums($row['totals'] ?? $row);
        sort($f);
        $out[] = ($row['name'] ?? '?') . ':' . implode(',', $f);
    }
    sort($out);

    return md5(implode('|', $out)) . ' (' . count($out) . ' صف)';
};

DB::beginTransaction();
try {
    $before = $fingerprint();
    echo "\n   📊 بصمة تقفيلة {$month} قبل: {$before}\n";

    $activeBefore = count($get('pilotsList')['items'] ?? []);
    $arcBefore    = count($get('archiveList')['items'] ?? []);

    /* ══ ① الترحيل ══ */
    echo "\n── ① الترحيل ──\n";
    $ctl->pilotsArchive($post('/x', ['note' => 'خرج من الشغل — مناورة']), (string) $p->id);

    $row = DB::selectOne('SELECT archived_at, archived_by, archive_note, active FROM rd_pilots WHERE id = ?', [$p->id]);
    $ok('اتسجّل وقت الترحيل', $row->archived_at !== null, (string) ($row->archived_at ?? 'NULL'));
    $ok('واتسجّل مين رحّله', ! empty($row->archived_by), (string) $row->archived_by);
    $ok('وسبب الترحيل', $row->archive_note === 'خرج من الشغل — مناورة', (string) $row->archive_note);

    /* ⚠️ `pilotsList` بترجّع المؤرشف كمان **عن قصد** — الشهور القديمة
       محتاجة اسمه عشان ترسم صفوفه. الواجهة هي اللي بتفلتر بـ`!p.archived`
       في قوايم الاختيار. فالفحص على **العلامة** مش على العدد. */
    $items = $get('pilotsList')['items'] ?? [];
    $mine  = null;
    foreach ($items as $it) { if ((int) $it['id'] === (int) $p->id) { $mine = $it; } }
    $ok('🔴 اتعلّم «مُرحَّل» في قايمة الطيارين (الواجهة بتشيله من الاختيار)',
        ($mine['archived'] ?? false) === true, json_encode($mine['archived'] ?? null));
    $ok('وعددها ما نقصش — الشهور القديمة محتاجة اسمه', count($items) === $activeBefore,
        $activeBefore . ' → ' . count($items));
    $arcAfter = count($get('archiveList')['items'] ?? []);
    $ok('🔴 وظهر في شاشة الأرشيف', $arcAfter === $arcBefore + 1, "{$arcBefore} → {$arcAfter}");

    /* ══ ② الحسابات ما اتغيّرتش ══ */
    echo "\n── ② الحسابات ──\n";
    $after = $fingerprint();
    printf("   📊 بصمة تقفيلة %s بعد:  %s\n", $month, $after);
    $ok('🔴 تقفيلة الشهر ما اتغيّرش فيها ولا رقم', $before === $after, 'البصمة اتغيّرت!');
    $ent = (int) DB::selectOne('SELECT COUNT(*) c FROM rd_entries WHERE pilot_id = ?', [$p->id])->c;
    $ok('وخاناته القديمة كلها مكانها', $ent === (int) $p->n, "{$ent} من {$p->n}");

    /* ══ ③ الرجوع من الأرشيف ══ */
    echo "\n── ③ الرجوع من الأرشيف ──\n";
    $ctl->pilotsUnarchive(Request::create('/x', 'POST'), (string) $p->id);
    $back = DB::selectOne('SELECT archived_at, archived_by, archive_note FROM rd_pilots WHERE id = ?', [$p->id]);
    $ok('رجع شغّال تاني', $back->archived_at === null);
    $ok('وبيانات الترحيل اتمسحت', $back->archived_by === null && $back->archive_note === null);
    $items2 = $get('pilotsList')['items'] ?? [];
    $mine2  = null;
    foreach ($items2 as $it) { if ((int) $it['id'] === (int) $p->id) { $mine2 = $it; } }
    $ok('ورجع لقايمة الشغّالين (العلامة اتشالت)', empty($mine2['archived']));
    $ok('واختفى من شاشة الأرشيف', count($get('archiveList')['items'] ?? []) === $arcBefore);
    $ok('🔴 والتقفيلة برضه زي ما هي', $fingerprint() === $before);

    /* ══ ④ الصلاحيات ══ */
    echo "\n── ④ الصلاحيات ──\n";
    $limited = Actor::staff(999999, 'drill_limited', 'accountant', null, 'محاسب بلا صلاحية');
    $cur = $limited;
    foreach ([['pilotsArchive', 'ترحيل', (string) $p->id], ['archiveList', 'شاشة الأرشيف', null]] as [$m, $lbl, $id]) {
        $threw = false;
        $msg = '';
        try {
            $r = Request::create('/x', $id === null ? 'GET' : 'POST');
            $id === null ? $ctl->$m($r) : $ctl->$m($r, $id);
        } catch (Throwable $e) {
            $threw = true;
            $msg = $e->getMessage();
        }
        $ok("🔴 حساب من غير صلاحية بيترفض — {$lbl}", $threw && str_contains($msg, 'صلاحية'), $msg ?: 'عدّى من غير رفض!');
    }
    $cur = $adminActor;
    $ok('⚠️ والمفتاحين في كتالوج الصلاحيات (ينفع يتدّوا لحد)',
        str_contains(json_encode(App\Wire\DamascusWire::permGroups(), JSON_UNESCAPED_UNICODE), 'page.archive')
        && str_contains(json_encode(App\Wire\DamascusWire::permGroups(), JSON_UNESCAPED_UNICODE), 'act.archive'));
} catch (Throwable $e) {
    $fail++;
    echo '  💥 ' . $e->getMessage() . "\n";
} finally {
    DB::rollBack();
}

$leak = DB::selectOne("SELECT COUNT(*) c FROM rd_pilots WHERE archive_note = 'خرج من الشغل — مناورة'");
$ok('🔴 مفيش أي أثر فاضل على القاعدة', (int) $leak->c === 0, 'صفوف فاضلة: ' . $leak->c);

echo "\n" . str_repeat('─', 58) . "\n";
echo $fail === 0
    ? "✅ عدّى {$pass} فحص — الترحيل بيشيله من القوايم ومابيغيّرش ولا رقم\n"
    : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
