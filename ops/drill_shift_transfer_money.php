<?php

declare(strict_types=1);

/**
 * 🧪 مناورة: الأوردرات غير المسوّاة بتمشي مع الطيار لفرعه الجديد — والمسوّاة لأ.
 *
 * بينفّذ `moveUnsettledOrdersToBranch` الحقيقية على قاعدة حقيقية بأوردرات
 * تجريبية في كل الحالات، وبيتأكد إن اللي اتحرك هو **بالظبط** اللي التقفيلة
 * هتطلبه من الطيار — لا أكتر ولا أقل.
 *
 * 🔴 **مافيش أي كتابة بتفضل**: كل حاجة جوه معاملة بتترجع في `finally`.
 *
 * التشغيل (على السيرفر):  php ops/drill_shift_transfer_money.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\BoardController;
use App\Support\WireTime;
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

$pilot = DB::selectOne('SELECT id, name FROM pilots WHERE archived_at IS NULL ORDER BY id LIMIT 1');
$brs   = DB::select('SELECT id, name FROM branches ORDER BY id LIMIT 2');
if (! $pilot || count($brs) < 2) {
    echo "⚠️  المناورة محتاجة طيار وفرعين\n";
    exit(0);
}
[$A, $B] = [(int) $brs[0]->id, (int) $brs[1]->id];
echo "🧪 الطيار #{$pilot->id} ({$pilot->name}) — من الفرع #{$A} للفرع #{$B}\n\n";

$now = WireTime::nowDb();
$mv  = new ReflectionMethod(BoardController::class, 'moveUnsettledOrdersToBranch');
$mv->setAccessible(true);
$ctl = new BoardController();

/* الحالات: [وصف، الحالة، مسوّى؟، مين دفع التوصيل، المفروض يتحرك؟] */
$CASES = [
  ['في إيده (جاري التوصيل)',        'delivering',  0, null,       true],
  ['لسه في الفرع (قيد التنفيذ)',     'processing',  0, null,       true],
  ['مؤجل',                          'postponed',   0, null,       true],
  ['اتسلّم ولسه مش مسوّى',           'delivered',   0, null,       true],
  ['🔴 اتسلّم و**اتسوّى خلاص**',      'delivered',   1, null,       false],
  ['مرتجع والتوصيل على المستلم',      'undelivered', 0, 'receiver', true],
  ['مرتجع مسوّى',                   'undelivered', 1, 'receiver', false],
  ['مرتجع والتوصيل على الشركة',      'undelivered', 0, null,       false],
  ['ملغي',                          'cancelled',   0, null,       false],
];

DB::beginTransaction();
try {
    $ids = [];
    foreach ($CASES as $i => [$label, $status, $settled, $fareBy]) {
        DB::insert(
            "INSERT INTO orders (order_num, branch_id, origin_branch_id, pilot_id, status, status_since,
                                 money_settled, undelivered_fare_by, total_delivery_price, sender_name, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            ['DRILL2-' . $i . '-' . bin2hex(random_bytes(3)), $A, $A, (int) $pilot->id, $status, $now,
             $settled, $fareBy, 30, 'مناورة', $now]
        );
        $ids[$i] = (int) DB::getPdo()->lastInsertId();
    }

    $moved = $mv->invoke($ctl, (int) $pilot->id, $B);
    echo "اتحرّك {$moved} صف\n\n";

    foreach ($CASES as $i => [$label, , , , $shouldMove]) {
        $br = (int) DB::selectOne('SELECT branch_id FROM orders WHERE id = ?', [$ids[$i]])->branch_id;
        $did = $br === $B;
        $ok(
            ($shouldMove ? 'بيتحرّك: ' : 'مايتحركش: ') . $label,
            $did === $shouldMove,
            'فرعه بقى ' . $br . ' (المفروض ' . ($shouldMove ? $B : $A) . ')'
        );
    }

    $orig = DB::selectOne('SELECT COUNT(*) c FROM orders WHERE id IN (' . implode(',', $ids) . ') AND origin_branch_id <> ?', [$A]);
    $ok('⚠️ و origin_branch_id مااتلمسش في أي صف', (int) $orig->c === 0, 'اتغيّر في ' . $orig->c . ' صف');

    $again = $mv->invoke($ctl, (int) $pilot->id, $B);
    $ok('🔁 وندهة تانية لنفس الفرع مابتعملش حاجة', $again === 0, 'اتحرّك ' . $again . ' تاني');
} catch (Throwable $e) {
    $fail++;
    echo '  💥 ' . $e->getMessage() . "\n";
} finally {
    DB::rollBack();
}

$leak = DB::selectOne("SELECT COUNT(*) c FROM orders WHERE order_num LIKE 'DRILL2-%'");
$ok('🔴 مفيش أي أثر فاضل على القاعدة', (int) $leak->c === 0, 'صفوف فاضلة: ' . $leak->c);

echo "\n" . str_repeat('─', 52) . "\n";
echo $fail === 0
    ? "✅ عدّى {$pass} فحص — اللي بيتحرك هو بالظبط اللي التقفيلة بتطلبه\n"
    : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
