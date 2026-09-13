<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════
   💵 سلفة الوردية بتخرج من الخزنة (طلب صاحب النظام 2026-09-13):
   «موضوع السلف الخاصة بالطيارين لازم تتخصم من الخزنة».

   كانت `shifts.advance_amount` رقم على الوردية بس — الطيار بياخد الكاش فعلًا
   والخزنة مابتنقصش. دلوقتي إنهاء الوردية بيسجّل حركة منصرف مرة واحدة،
   ومعرّفها بيتحفظ هنا عشان مايتصرفش مرتين (زي commission_paid_at).
   بيتخطّى لو العمود موجود. التشغيل: php ops/apply_shift_advance_txn.php
═══════════════════════════════════════════════════════════════ */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$db = (string) DB::connection()->getDatabaseName();
$has = (bool) DB::selectOne(
    'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
    [$db, 'shifts', 'advance_txn_id']
);
if ($has) {
    echo "  = shifts.advance_txn_id موجود\n";
} else {
    DB::statement("ALTER TABLE `shifts` ADD COLUMN `advance_txn_id` bigint(20) unsigned DEFAULT NULL COMMENT 'حركة الخزنة لسلفة الوردية (cash_transactions.id) — وجودها بيمنع صرفها مرتين (2026-09-13)' AFTER `advance_settle`");
    echo "  ✓ shifts.advance_txn_id اتضاف\n";
}
echo "تمام ✅\n";
