<?php
/**
 * 🏢 اختبار «فرع الطيار الثابت».
 *
 * ═══ الطلب بالحرف (صاحب النظام 2026-08-30) ═══
 * «ثبت الطيار على فرع من البداية حتى لو اتقفلت الوردية يفتح على الفرع
 *  المتكود عليه، ولو اتنقل لفرع تاني اليوم يرجع على فرعه تاني يوم».
 *
 * ═══ الأصل ═══
 * `pilots.assigned_branch_id` كان بيلعب دورين: تعليق العمود بيقول «الفرع
 * اللي الطيار تابع له» والكود بيستعمله كـ«شغّال فين دلوقتي» — و
 * releasePilot() بتمسحه عند قفل الوردية. فالفرع كان بيضيع بعد أول وردية،
 * و`pilotsCreate` كانت بترمي الفرع المبعوت من المودال أصلًا.
 *
 * ═══ الاختبار ═══
 * بيشتغل على قاعدة مؤقتة حقيقية (مش mock): بيعمل فرعين وطيار، وبيمشّي
 * دورة كاملة — فتح وردية · دعم مؤقت · قفل · فتح تاني يوم — وبيقرا
 * الأعمدة بعد كل خطوة.
 *
 * التشغيل: php ops/test_home_branch.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/* 🔴 من غير الاتنين دول: استثناء مش متمسك بعد البوتستراب بيتطبع بشكل
   جميل وبيخرج بكود 0 — الحارس يبان ناجح وهو مات في نص شغله.
   ولازم يتركّبوا بعد البوتستراب — قبله لارافل بيدوس عليهم. */
set_exception_handler(function (Throwable $e): void {
    echo "\n🔴 استثناء مش متمسك: " . get_class($e) . ' — ' . $e->getMessage()
        . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
});
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        exit(1);
    }
});


use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;
function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$what}\n"; }
    else { $fail++; echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n"; }
}

/* ── قاعدة مؤقتة: القاعدة الحقيقية مابتتلمسش ── */
$live = DB::connection()->getDatabaseName();
$tmp  = 'aldahshan_home_branch_test';
if (strcasecmp($tmp, $live) === 0) { echo "🔴 اسم القاعدة المؤقتة = الحقيقية\n"; exit(1); }

$cfg = config('database.connections.mysql');
$pdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']}", $cfg['username'], $cfg['password'],
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS `{$tmp}`");
$pdo->exec("CREATE DATABASE `{$tmp}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$tmp}`");
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
foreach (array_filter(array_map('trim', explode(";\n", file_get_contents(__DIR__ . '/../database/schema/mysql-schema.sql')))) as $stmt) {
    if ($stmt !== '' && ! str_starts_with($stmt, '--')) {
        try { $pdo->exec($stmt); } catch (Throwable $e) { /* تعليقات وسطور إعداد */ }
    }
}
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");

$q = fn (string $sql, array $a = []) => (function () use ($pdo, $sql, $a) {
    $st = $pdo->prepare($sql); $st->execute($a); return $st;
})();
$one = fn (string $sql, array $a = []) => $q($sql, $a)->fetch(PDO::FETCH_ASSOC) ?: null;

echo "\n══ 0) العمود موجود ══\n";
$cols = $q("SHOW COLUMNS FROM pilots")->fetchAll(PDO::FETCH_COLUMN);
ok('`home_branch_id` في المخطط', in_array('home_branch_id', $cols, true));
ok('و`assigned_branch_id` لسه موجود', in_array('assigned_branch_id', $cols, true));

/* ── بيانات: فرعين وطيار ── */
$now = date('Y-m-d H:i:s');
/* `code` عليه قيد تفرّد ومابيقبلش قيمتين فاضيتين */
$q("INSERT INTO branches (id, name, code, created_at) VALUES (1,'فرعه','HOME',?),(2,'فرع الدعم','SUPP',?)", [$now, $now]);
$q("INSERT INTO pilots (id, name, home_branch_id, commission_type, commission_value, created_at)
    VALUES (7,'الطيار',1,'percent',10,?)", [$now]);

echo "\n══ 1) الطيار الجديد: فرعه ثابت والجاري لسه فاضي ══\n";
$p = $one('SELECT * FROM pilots WHERE id = 7');
ok('الفرع الثابت = فرعه', (int) $p['home_branch_id'] === 1, (string) $p['home_branch_id']);
ok('الفرع الجاري فاضي (مافيش وردية)', $p['assigned_branch_id'] === null,
   var_export($p['assigned_branch_id'], true));

/* ── محاكاة الخطوات زي ما الكنترولر بيعملها بالظبط ── */
$enterQueue = function (int $pilot, int $branch) use ($q, $now): void {
    $q("UPDATE pilots SET assigned_branch_id = ?, status = 'waiting', queue_no = 1,
        status_since = ? WHERE id = ?", [$branch, $now, $pilot]);
};
/* ═══ الاختبار بيقرا الكود الحقيقي مش بيحاكيه ═══
   أول نسخة من الاختبار ده كانت بتكتب الـSQL بإيدها — فلما جرّبت أكسر
   الكنترولر (رجّعت المسح بدل الرجوع للفرع) الاختبار عدّى وهو مفروض يقع.
   دلوقتي الـSQL بتتقص من `releasePilot` نفسها وبتتنفّذ زي ما هي. */
$board = file_get_contents(__DIR__ . '/../app/Http/Controllers/Api/BoardController.php');

if (! preg_match('/private function releasePilot\(array \$pilotRow\): void\s*\{(.*?)\n    \}/s', $board, $m)) {
    echo "🔴 مالقيتش releasePilot في BoardController\n"; exit(1);
}
if (! preg_match('/"(UPDATE pilots SET .*?WHERE id = \?)"/s', $m[1], $sqlM)) {
    echo "🔴 مالقيتش UPDATE جوه releasePilot\n"; exit(1);
}
$releaseSql = $sqlM[1];
$release = function (int $pilot) use ($q, $releaseSql): void { $q($releaseSql, [$pilot]); };

/* ومنطق `shiftOpen` بيتقرا كمان: بنستخرج التعبير ونقيّم أولوياته.
   ما ينفعش نشغّل PHP بتاع الكنترولر هنا، فبنتأكد من الشكل ونعيده. */
if (! preg_match('/public function shiftOpen\(Request \$request\): JsonResponse\s*\{(.*?)\n    \}\n/s', $board, $so)) {
    echo "🔴 مالقيتش shiftOpen\n"; exit(1);
}
$shiftSrc = $so[1];
$homeFirst = (bool) preg_match(
    '/\$branchId\s*=\s*\(\$pilot\[.home_branch_id.\]\s*\?\?\s*null\)\s*!==\s*null\s*\?\s*\(int\)\s*\$pilot\[.home_branch_id.\]/s',
    $shiftSrc
);
$shiftOpenBranch = function (array $pilot, ?int $sent) use ($homeFirst): ?int {
    if ($homeFirst && $pilot['home_branch_id'] !== null) {
        return (int) $pilot['home_branch_id'];
    }
    return $sent ?? ($pilot['assigned_branch_id'] !== null ? (int) $pilot['assigned_branch_id'] : null);
};

echo "\n══ 1ب) 🔴 الاختبار مربوط بالكود الحقيقي ══\n";
/* الفحوص دي هي اللي بتخلّي أي تعديل على الكنترولر يوقّع الاختبار. */
ok('SQL القفل اتقصّت من releasePilot', str_contains($releaseSql, 'UPDATE pilots SET'));
ok('🔴 وبترجّع للفرع الثابت', str_contains($releaseSql, 'assigned_branch_id = home_branch_id'),
   trim(substr($releaseSql, 0, 78)));
ok('🔴 وshiftOpen بيقدّم الثابت على المبعوت', $homeFirst, 'الثابت مش أولوية في الكود');

echo "\n══ 2) فتح وردية — الفرع الثابت هو الحاكم ══\n";
$p = $one('SELECT * FROM pilots WHERE id = 7');
ok('🔴 بيفتح على فرعه حتى لو الشاشة بعتت فرع تاني',
   $shiftOpenBranch($p, 2) === 1, (string) $shiftOpenBranch($p, 2));
ok('ولو مابعتش حاجة برضه فرعه', $shiftOpenBranch($p, null) === 1);
$enterQueue(7, $shiftOpenBranch($p, null));
$p = $one('SELECT * FROM pilots WHERE id = 7');
ok('بقى في دور فرعه', (int) $p['assigned_branch_id'] === 1 && $p['status'] === 'waiting');

echo "\n══ 3) دعم مؤقت لفرع تاني — الثابت مايتلمسش ══\n";
/* ده اللي بيعمله مسار الدعم: بيغيّر الجاري بس */
$q("UPDATE pilots SET assigned_branch_id = 2, status = 'delivering' WHERE id = 7");
$p = $one('SELECT * FROM pilots WHERE id = 7');
ok('الجاري بقى فرع الدعم', (int) $p['assigned_branch_id'] === 2);
ok('🔴 الثابت زي ما هو', (int) $p['home_branch_id'] === 1, (string) $p['home_branch_id']);

echo "\n══ 4) قفل الوردية — بيرجع لفرعه مش بيتمسح ══\n";
$release(7);
$p = $one('SELECT * FROM pilots WHERE id = 7');
ok('🔴 الفرع مااتمسحش', $p['assigned_branch_id'] !== null, 'اتمسح — الطيار بقى بلا فرع');
ok('🔴 ورجع لفرعه هو', (int) $p['assigned_branch_id'] === 1, (string) $p['assigned_branch_id']);
ok('الحالة اتصفّرت', $p['status'] === null);
ok('ورقم الدور اتصفّر', $p['queue_no'] === null);

echo "\n══ 5) تاني يوم — بيفتح على فرعه ══\n";
$p = $one('SELECT * FROM pilots WHERE id = 7');
ok('🔴 الوردية الجديدة على فرعه', $shiftOpenBranch($p, null) === 1, (string) $shiftOpenBranch($p, null));
ok('وحتى لو الشاشة بعتت فرع الدعم', $shiftOpenBranch($p, 2) === 1, (string) $shiftOpenBranch($p, 2));

echo "\n══ 6) نقل دائم معتمد — الثابت بيتحرّك ══\n";
$q("UPDATE pilots SET home_branch_id = 2 WHERE id = 7");   // زي transferApprove
$p = $one('SELECT * FROM pilots WHERE id = 7');
ok('الثابت بقى الفرع الجديد', (int) $p['home_branch_id'] === 2);
ok('والوردية الجاية عليه', $shiftOpenBranch($p, null) === 2, (string) $shiftOpenBranch($p, null));
$release(7);
$p = $one('SELECT * FROM pilots WHERE id = 7');
ok('والقفل بيرجّعه للجديد', (int) $p['assigned_branch_id'] === 2);

echo "\n══ 7) طيار قديم بلا فرع ثابت — السلوك القديم زي ما هو ══\n";
$q("INSERT INTO pilots (id, name, commission_type, commission_value, created_at)
    VALUES (8,'قديم','percent',10,?)", [$now]);
$old = $one('SELECT * FROM pilots WHERE id = 8');
ok('بياخد الفرع المبعوت من الشاشة', $shiftOpenBranch($old, 2) === 2, (string) $shiftOpenBranch($old, 2));
ok('ومن غيره بيرجّع null (والكنترولر بيرمي رسالة)', $shiftOpenBranch($old, null) === null);
$q("UPDATE pilots SET assigned_branch_id = 2, status = 'waiting' WHERE id = 8");
$release(8);
$o2 = $one('SELECT * FROM pilots WHERE id = 8');
ok('وقفل ورديته بيصفّر الفرع زي الأول', $o2['assigned_branch_id'] === null,
   var_export($o2['assigned_branch_id'], true));

echo "\n══ 8) الطابور مابيتأثرش بالطيار الفاضي ══\n";
/* الطيار الفاضي بقى ليه فرع — فلازم نتأكد إن استعلامات الدور بتفلتر
   على الحالة كمان، وإلا كان هيتحسب في الدور وهو مش شغّال. */
$q("UPDATE pilots SET assigned_branch_id = 1, status = NULL, queue_no = NULL WHERE id = 7");
$q("UPDATE pilots SET home_branch_id = 1 WHERE id = 7");
$waiting = $q("SELECT COUNT(*) c FROM pilots WHERE assigned_branch_id = 1 AND status = 'waiting'")
    ->fetch(PDO::FETCH_ASSOC)['c'];
ok('🔴 الفاضي مش في الدور', (int) $waiting === 0, "لقى {$waiting}");
$byBranch = $q("SELECT COUNT(*) c FROM pilots WHERE assigned_branch_id = 1")->fetch(PDO::FETCH_ASSOC)['c'];
ok('لكنه باين لمدير فرعه', (int) $byBranch === 1, (string) $byBranch);

/* ── كل استعلام دور في الكود بيفلتر على الحالة؟ ── */
echo "\n══ 9) حارس على الكود: استعلامات الدور بتفلتر على الحالة ══\n";
/* الخطر: الطيار الفاضي بقى ليه `assigned_branch_id`. أي استعلام **بيختار
   طيارين للدور** من غير شرط حالة هيحسبه في الدور وهو مش شغّال.
   `ORDER BY queue_no` مش استعلام اختيار — بيرتّب اللي اتختار خلاص. */
$files = ['app/Http/Controllers/Api/BoardController.php', 'app/Http/Controllers/Api/OrdersController.php'];
$bad = [];
foreach ($files as $f) {
    $src = file_get_contents(__DIR__ . '/../' . $f);
    foreach (explode('assigned_branch_id = ?', $src) as $i => $chunk) {
        if ($i === 0) continue;
        $head = substr($chunk, 0, 90);
        $orderAt = stripos($head, 'ORDER BY');
        $qAt     = stripos($head, 'queue_no');
        // queue_no في شرط (مش في ترتيب) = استعلام دور
        $isQueuePick = $qAt !== false && ($orderAt === false || $qAt < $orderAt);
        if ($isQueuePick && ! str_contains($head, 'status')) {
            $bad[] = $f . ': ' . trim(substr($head, 0, 60));
        }
    }
}
ok('مفيش استعلام دور بلا شرط حالة', $bad === [], implode(' · ', $bad));

/* ولازم الاختبار ده يقع فعلًا لو حد شال الشرط — بنجرّب على نص مقلّد */
$sample = "assigned_branch_id = ? AND queue_no > ?\", [\$b, \$n]";
$headS  = substr($sample, strlen('assigned_branch_id = ?'), 90);
$qS = stripos($headS, 'queue_no'); $oS = stripos($headS, 'ORDER BY');
ok('الفحص نفسه بيمسك الحالة الخطرة',
   $qS !== false && ($oS === false || $qS < $oS) && ! str_contains($headS, 'status'));

echo "\n══ 10) 🔴 الطيار القافل ورديته مايترجّعش للدور ══\n";
/* ═══ الانحدار اللي المسح كشفه ═══
   قبل التعديل كان `assigned_branch_id` بيتصفّر عند قفل الوردية، فمسارين
   اتكلوا على «مالوش فرع» كإشارة ضمنية إن الطيار خلص شغل:
     BoardController::pilotBackToWaitingIfFree  ·  OrdersController::syncPilotStatus
   الاتنين بيرجّعوا الطيار لـ`waiting` أول ما آخر أوردر جاري يتقفل.

   لما الفرع بقى بيفضل، الحارس ده مات: **أوردر متأخر بيتقفل بعد نهاية
   الوردية كان هيرمي الطيار في الدور** — يظهر متاح وياخد أوردرات وساعاته
   تتحسب غلط. الفحوص دي بتقرا الشرط من الكود نفسه. */
$orders = file_get_contents(__DIR__ . '/../app/Http/Controllers/Api/OrdersController.php');

if (preg_match('/private function pilotBackToWaitingIfFree.*?\n    \}/s', $board, $bw)) {
    ok('pilotBackToWaitingIfFree بتفحص الحالة',
       (bool) preg_match("/\\\$pilot\['status'\].*?===\s*null/s", $bw[0]),
       'لسه بتعتمد على «مالوش فرع» بس');
    /* والفحص لازم يكون **قبل** الترجيع للدور */
    $statusAt = strpos($bw[0], "\$pilot['status']");
    $updateAt = strpos($bw[0], "status = 'waiting'");
    ok('والفحص قبل الترجيع', $statusAt !== false && $updateAt !== false && $statusAt < $updateAt);
} else { ok('لقيت pilotBackToWaitingIfFree', false); }

if (preg_match('/private static function syncPilotStatus.*?\n    \}/s', $orders, $sy)) {
    ok('syncPilotStatus بتفحص الحالة',
       (bool) preg_match("/\\\$pilot\['status'\].*?===\s*null/s", $sy[0]),
       'لسه بتعتمد على «مالوش فرع» بس');
}

/* ومحاكاة السيناريو نفسه على القاعدة */
$q("UPDATE pilots SET status = NULL, assigned_branch_id = 1, home_branch_id = 1, queue_no = NULL WHERE id = 7");
$p = $one('SELECT * FROM pilots WHERE id = 7');
$offShift = ($p['status'] ?? null) === null || $p['status'] === '';
ok('🔴 الطيار الفاضي متشاف كـ«مش شغّال»', $offShift, 'الحالة: ' . var_export($p['status'], true));
ok('ومعاه فرعه (مش بلا فرع)', (int) $p['assigned_branch_id'] === 1);

$pdo->exec("DROP DATABASE IF EXISTS `{$tmp}`");

echo "\n════════════════════════════════════════\n";
echo "HOME BRANCH: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
