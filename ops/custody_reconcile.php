<?php

declare(strict_types=1);

/**
 * 💰 مراجعة العهدة: مجموع سجل الحركات لازم يساوي رصيد الطيار المسجّل.
 *
 * ليه الملف ده موجود:
 *   `pilots.custody_balance` رقم حي بيتغيّر من مسارين، و`custody_transactions`
 *   سجل بيتكتب في نفس المعاملة. لو الاتنين اختلفوا يبقى فيه فلوس على طيار
 *   مالهاش أثر — أو أثر مالوش فلوس. الاختلاف ده مابيرميش أي خطأ وقت
 *   التشغيل، فمن غير الفحص ده بيعدّي بصمت لشهور.
 *
 * الحسبة: give + order_pending بيزوّدوا · return + order_extra بينقّصوا.
 *
 * ⚠️ الحركات اللي اتسجّلت قبل 2026-08-27 ممكن تكون فيها فروق موروثة:
 *    الكود القديم كان بيقص الرصيد عند الصفر وبيسجّل الدلتا كاملة. الفحص
 *    بيقول على الفرق ومابيصلّحوش — الإصلاح بأثر رجعي قرار إداري.
 *
 * التشغيل: php ops/custody_reconcile.php
 */

use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = DB::select(
    "SELECT p.id, p.name, p.custody_balance,
            COALESCE(SUM(CASE
                WHEN t.type IN ('give','order_pending')  THEN  t.amount
                WHEN t.type IN ('return','order_extra')  THEN -t.amount
                ELSE 0 END), 0) AS from_log,
            COUNT(t.id) AS n
       FROM pilots p
       LEFT JOIN custody_transactions t ON t.pilot_id = p.id
      GROUP BY p.id, p.name, p.custody_balance
      ORDER BY p.id"
);

$bad = 0;
$unknown = DB::select(
    "SELECT DISTINCT type FROM custody_transactions
      WHERE type NOT IN ('give','return','order_pending','order_extra')"
);

foreach ($rows as $r) {
    $bal  = (float) $r->custody_balance;
    $log  = (float) $r->from_log;
    $diff = round($bal - $log, 2);
    $ok   = abs($diff) < 0.005;
    if (! $ok) {
        $bad++;
    }
    printf("  %s #%-5s %-20s رصيد=%-12s سجل=%-12s حركات=%-4s%s\n",
        $ok ? '✓' : '✗', $r->id, $r->name, number_format($bal, 2),
        number_format($log, 2), $r->n,
        $ok ? '' : '  ← فرق ' . number_format($diff, 2));
}

if ($unknown) {
    $bad++;
    echo "\n  ✗ أنواع حركات مش معروفة في السجل: "
       . implode(', ', array_map(fn ($u) => $u->type, $unknown)) . "\n";
}

echo "\n════════════════════════════════════════════\n";
$line = sprintf('CUSTODY RECONCILE: %d طيار · %d مختلف', count($rows), $bad);
echo ($bad > 0 ? "\033[31m{$line}\033[39m" : "\033[32m{$line}\033[39m") . "\n";
echo "════════════════════════════════════════════\n";

exit($bad > 0 ? 1 : 0);
