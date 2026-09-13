<?php

declare(strict_types=1);

/**
 * 🧪 مناورة: تحميل أوردر على طيار **فرع تاني** بينقل الأوردر لفرعه.
 *
 * طلب صاحب النظام (2026-09-10): «مدير الفرع يشوف الطيارين الآخرين على
 * الخريطة … يحمّل عليه أوردر … والأوردر يذهب إلى الفرع التابع له هذا
 * الطيار … لأن في آخر اليوم سوف يتم محاسبة الطيار في الفرع التابع له».
 *
 * ═══ ليه مناورة مش حارس نصّي ═══
 * الحارس بيقرا الكود؛ ده بينفّذ `claimCore` الحقيقية على قاعدة حقيقية
 * وبيقرا `orders.branch_id` بعدها. الفرق مهم: نقل الفرع جوه نفس الـUPDATE
 * الذري، فلو الشرط أو ترتيب الـbindings اتلخبط الحارس النصّي ماياخدش باله.
 *
 * 🔴 **مافيش أي كتابة بتفضل**: كل حاجة جوه معاملة بتترجع في `finally`.
 *    النظام لايف — الأوردر التجريبي بيتولد وبيتقرا وبيتمسح في نفس النفس.
 *
 * التشغيل (على السيرفر):  php ops/drill_map_load.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\OrdersController;
use App\Support\Actor;
use App\Support\WireTime;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;
$ok = function (string $what, bool $cond, string $got = ''): void {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✓ {$what}\n";
    } else {
        $fail++;
        echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n";
    }
};

/* الطيار: تابع لفرع، مش مؤرشف، ومتاح. بندوّر على واحد وناخد فرع تاني غيره. */
$pilot = DB::selectOne(
    "SELECT id, name, assigned_branch_id FROM pilots
      WHERE archived_at IS NULL AND assigned_branch_id IS NOT NULL
        AND status IN ('waiting','delivering') ORDER BY id LIMIT 1"
);
if (! $pilot) {
    echo "⚠️  مافيش طيار متاح دلوقتي — المناورة محتاجة طيار في الانتظار أو بيوصّل\n";
    exit(0);
}
$pilotBranch = (int) $pilot->assigned_branch_id;

$other = DB::selectOne('SELECT id, name FROM branches WHERE id <> ? ORDER BY id LIMIT 1', [$pilotBranch]);
if (! $other) {
    echo "⚠️  مافيش فرع تاني — المناورة محتاجة فرعين\n";
    exit(0);
}
$fromBranch = (int) $other->id;

echo "🧪 الفرع الطالب #{$fromBranch} ({$other->name}) → الطيار #{$pilot->id} ({$pilot->name}) في الفرع #{$pilotBranch}\n\n";

$now   = WireTime::nowDb();
$actor = Actor::staff(0, 'drill', 'branch', $fromBranch, 'مناورة');

$claim = new ReflectionMethod(OrdersController::class, 'claimCore');
$claim->setAccessible(true);

DB::beginTransaction();
try {
    $code = 'DRILL-' . bin2hex(random_bytes(4));
    DB::insert(
        "INSERT INTO orders (order_num, branch_id, origin_branch_id, status, status_since, sender_name, sender_phone, created_at)
         VALUES (?,?,?,'processing',?,?,?,?)",
        [$code, $fromBranch, $fromBranch, $now, 'مناورة', '0100', $now]
    );
    $orderId = (int) DB::getPdo()->lastInsertId();

    $before = (int) DB::selectOne('SELECT branch_id FROM orders WHERE id = ?', [$orderId])->branch_id;
    $ok('الأوردر اتعمل على الفرع الطالب', $before === $fromBranch, (string) $before);

    $res = $claim->invoke(null, $actor, $orderId, (int) $pilot->id, $now);

    $row = DB::selectOne('SELECT branch_id, pilot_id, status FROM orders WHERE id = ?', [$orderId]);
    $ok('🔴 الأوردر اتنقل لفرع الطيار', (int) $row->branch_id === $pilotBranch, 'branch_id=' . $row->branch_id);
    $ok('واتحمّل على الطيار فعلًا', (int) $row->pilot_id === (int) $pilot->id, 'pilot_id=' . $row->pilot_id);
    $ok('والحالة بقت «جاري التوصيل»', $row->status === 'delivering', (string) $row->status);
    $ok('والرد بيقول إن الفرع اتغيّر', $res['movedBranch'] === true, var_export($res['movedBranch'], true));
    $ok('وبيقول من فين لفين', (int) $res['fromBranchId'] === $fromBranch && (int) $res['toBranchId'] === $pilotBranch,
        $res['fromBranchId'] . '→' . $res['toBranchId']);

    $ob = (int) DB::selectOne('SELECT origin_branch_id FROM orders WHERE id = ?', [$orderId])->origin_branch_id;
    $ok('⚠️ وفرع الإنشاء مااتلمسش (تاريخ مين عمل الأوردر)', $ob === $fromBranch, (string) $ob);
} catch (Throwable $e) {
    $fail++;
    echo '  💥 ' . $e->getMessage() . "\n";
} finally {
    DB::rollBack();   // 🔴 مفيش أي أثر بيفضل على القاعدة
}

$leak = DB::selectOne("SELECT COUNT(*) c FROM orders WHERE order_num LIKE 'DRILL-%'");
$ok('🔴 مفيش أي أثر فاضل على القاعدة', (int) $leak->c === 0, 'صفوف مناورة فاضلة: ' . $leak->c);

echo "\n" . str_repeat('─', 52) . "\n";
echo $fail === 0
    ? "✅ عدّى {$pass} فحص — التحميل بينقل الأوردر لفرع الطيار\n"
    : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
