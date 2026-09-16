<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════
   🛵 تتبّع الطيار من بوابة المحلات (طلب صاحب النظام 2026-09-16):
   «عايز أفتح تتبّع لبوابة المحلات للمندوب اللي هيجي يرفع منها وتبقى
   خاصية تتفتح وتتقفل».

   users.can_track_pilot (للمحل بس) — بيتفتح/بيتقفل من إدارة المحلات زي
   can_edit_price. المفتوح له بيشوف زرار «فين الطيار؟» على الأوردر اللي
   اتعيّن له طيار ولسه ما اتسلّمش، وبيفتح خريطة بموقع الطيار الحي.
   بيتخطّى لو العمود موجود. التشغيل: php ops/apply_store_track_pilot.php
═══════════════════════════════════════════════════════════════ */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$db = (string) DB::connection()->getDatabaseName();
$has = (bool) DB::selectOne(
    'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
    [$db, 'users', 'can_track_pilot']
);
if ($has) {
    echo "  = users.can_track_pilot موجود\n";
} else {
    DB::statement("ALTER TABLE `users` ADD COLUMN `can_track_pilot` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'للمحل (role=store): مفتوح له تتبّع الطيار على الخريطة وهو جاي يستلم — بيتفتح من إدارة المحلات (طلب 2026-09-16)' AFTER `can_edit_price`");
    echo "  ✓ users.can_track_pilot اتضاف\n";
}
echo "تمام ✅\n";
