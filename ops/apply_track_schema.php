<?php
/* تطبيق تغييرات مخطط «أثر الطيار» (2026-09-07) على قاعدة حقيقية — محلي
   أو إنتاج. بيقرا اتصال لارافل من .env المجاور، وكل خطوة **idempotent**:
   بيفحص وجود العمود/الجدول قبل ما يضيف، فتشغيله مرتين آمن.
   المصدر الوحيد للحقيقة لسه database/schema/mysql-schema.sql —
   ده بس بينقل اللي فيه لقاعدة شغّالة (مافيش migrations في المشروع). */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "💥 فشل: {$e->getMessage()}\n");
    exit(1);
});

use Illuminate\Support\Facades\DB;

$db = DB::getDatabaseName();
echo "القاعدة: {$db}\n";

$hasCol = fn (string $t, string $c): bool => (bool) DB::select(
    'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
    [$db, $t, $c]
);
$hasTable = fn (string $t): bool => (bool) DB::select(
    'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
    [$db, $t]
);

if (! $hasCol('pilots', 'heading')) {
    DB::statement("ALTER TABLE `pilots`
        ADD COLUMN `heading` decimal(5,1) DEFAULT NULL COMMENT 'اتجاه الحركة بالدرجات (0-360) من آخر نقطة — سهم الخريطة' AFTER `location_updated_at`,
        ADD COLUMN `speed` decimal(6,2) DEFAULT NULL COMMENT 'السرعة م/ث من آخر نقطة' AFTER `heading`");
    echo "✓ pilots.heading + pilots.speed اتضافوا\n";
} else {
    echo "· pilots.heading موجود\n";
}

if (! $hasTable('pilot_track_points')) {
    DB::statement("CREATE TABLE `pilot_track_points` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pilot_id` bigint(20) unsigned NOT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `heading` decimal(5,1) DEFAULT NULL COMMENT 'اتجاه الحركة بالدرجات',
  `speed` decimal(6,2) DEFAULT NULL COMMENT 'م/ث',
  `accuracy` decimal(6,1) DEFAULT NULL COMMENT 'دقة القراءة بالمتر',
  `at` datetime(3) NOT NULL COMMENT 'وقت القراءة على الجهاز (UTC) — مش وقت الوصول',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pilot_track_points_pilot_at` (`pilot_id`,`at`),
  CONSTRAINT `fk_pilot_track_points_pilot_id` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أثر حركة الطيار وقت الشيل — نقطة كل ~٥ث بتوصل في دفعات كل ١٥ث، بتتمسح بعد ٢٤ ساعة'");
    echo "✓ pilot_track_points اتعمل\n";
} else {
    echo "· pilot_track_points موجود\n";
}
echo "تمام\n";
