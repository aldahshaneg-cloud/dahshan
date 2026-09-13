<?php

declare(strict_types=1);

/**
 * 🩹 مطابقة الأوردرات غير المسوّاة على فرع وردية الطيار المفتوحة.
 *
 * ═══ ليه ═══
 * قبل إصلاح 2026-09-10، `shiftTransfer` كانت بتنقل الوردية لفرع تاني
 * وتسيب الأوردرات ورا. والتقفيلة بتطلب من الطيار أوردراته بـ`pilot_id`
 * وبتودّي الكاش لفرع الوردية — فبيطلع **أوفر على فرع الوردية وعجز على
 * الفرع اللي دفتره فيه الأوردر**.
 *
 * السكربت ده بيصلّح الصفوف اللي اتعملت **قبل** الإصلاح: بيمشّي الورديات
 * **المفتوحة بس**، وبيرجّع أوردرات الطيار غير المسوّاة لفرع ورديته.
 *
 * 🔴 الورديات **المقفولة مابتتلمسش** — فلوسها دخلت الخزن فعلًا ودفترها
 *    اتقفل، ونقل أوردر بعد كده بيكسر مطابقة تمّت خلاص.
 * 🔴 والمسوّى (`money_settled = 1`) مابيتحركش لنفس السبب.
 *
 * التشغيل:
 *   php ops/fix_unsettled_order_branch.php            ← معاينة بس
 *   php ops/fix_unsettled_order_branch.php --apply    ← التنفيذ
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv, true);
echo $apply ? "⚙️  وضع التنفيذ\n\n" : "👀 وضع المعاينة — مافيش أي كتابة (زوّد --apply للتنفيذ)\n\n";

$brName = [];
foreach (DB::select('SELECT id, name FROM branches') as $b) {
    $brName[(int) $b->id] = $b->name;
}
$nm = fn (int $id): string => $brName[$id] ?? ('#' . $id);

$shifts = DB::select("SELECT id, pilot_id, branch_id FROM shifts WHERE status = 'active' ORDER BY id");
echo 'ورديات مفتوحة: ' . count($shifts) . "\n";

$total = 0;
$money = 0.0;
foreach ($shifts as $s) {
    $pid = (int) $s->pilot_id;
    $to  = (int) $s->branch_id;

    $rows = DB::select(
        "SELECT id, order_num, branch_id, status, total_delivery_price, wallet_used
           FROM orders
          WHERE pilot_id = ? AND branch_id <> ? AND (
                status IN ('processing','delivering','postponed')
             OR (status = 'delivered'   AND money_settled = 0)
             OR (status = 'undelivered' AND money_settled = 0
                 AND undelivered_fare_by IN ('receiver','sender'))
          )",
        [$pid, $to]
    );
    if (! $rows) {
        continue;
    }

    $p = DB::selectOne('SELECT name FROM pilots WHERE id = ?', [$pid]);
    printf(
        "\n🛵 %s (وردية #%d — فرعها %s)\n",
        $p->name ?? ('#' . $pid), (int) $s->id, $nm($to)
    );
    foreach ($rows as $o) {
        $net = max(0.0, (float) $o->total_delivery_price - (float) $o->wallet_used);
        $money += $net;
        $total++;
        printf(
            "   %-22s %-13s دفتره %s → %s   (تحصيل %.2f)\n",
            $o->order_num, $o->status, $nm((int) $o->branch_id), $nm($to), $net
        );
    }

    if ($apply) {
        DB::update(
            "UPDATE orders SET branch_id = ?
              WHERE pilot_id = ? AND branch_id <> ? AND (
                    status IN ('processing','delivering','postponed')
                 OR (status = 'delivered'   AND money_settled = 0)
                 OR (status = 'undelivered' AND money_settled = 0
                     AND undelivered_fare_by IN ('receiver','sender'))
              )",
            [$to, $pid, $to]
        );
    }
}

echo "\n" . str_repeat('─', 52) . "\n";
if ($total === 0) {
    echo "✅ كل الأوردرات غير المسوّاة على فرع وردياتها — مفيش فرق\n";
    exit(0);
}
printf(
    "%s %d أوردر · %.2f ج.م كانت هتطلع أوفر/عجز بين الفروع\n",
    $apply ? '✅ اتصلّح:' : '👀 المعاينة:',
    $total,
    $money
);
if (! $apply) {
    echo "   شغّل بـ --apply للتنفيذ.\n";
}
