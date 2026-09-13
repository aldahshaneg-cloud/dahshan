<?php
/* تطبيق أعمدة «أرشيف طيارين روح دمشق» (2026-09-12) على قاعدة حقيقية — محلي
   أو إنتاج. بيقرا اتصال لارافل من .env المجاور، وكل خطوة **idempotent**:
   بيفحص وجود العمود/المفتاح قبل ما يضيف، فتشغيله مرتين آمن.
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
$hasIdx = fn (string $t, string $i): bool => (bool) DB::select(
    'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
    [$db, $t, $i]
);

if (! $hasCol('rd_pilots', 'archived_at')) {
    DB::statement("ALTER TABLE `rd_pilots`
        ADD COLUMN `archived_at` datetime DEFAULT NULL COMMENT 'وقت الترحيل للأرشيف — خرج من الشغل وبياناته القديمة محفوظة زي ما هي' AFTER `created_at`,
        ADD COLUMN `archived_by` varchar(190) DEFAULT NULL COMMENT 'اسم مستخدم اللي رحّله' AFTER `archived_at`,
        ADD COLUMN `archive_note` varchar(255) DEFAULT NULL COMMENT 'سبب الترحيل (اختياري)' AFTER `archived_by`");
    echo "✓ rd_pilots.archived_at + archived_by + archive_note اتضافوا\n";
} else {
    echo "· rd_pilots.archived_at موجود\n";
}

if (! $hasIdx('rd_pilots', 'idx_rd_pilots_archived')) {
    DB::statement('ALTER TABLE `rd_pilots` ADD KEY `idx_rd_pilots_archived` (`archived_at`)');
    echo "✓ مفتاح idx_rd_pilots_archived اتعمل\n";
} else {
    echo "· idx_rd_pilots_archived موجود\n";
}

/* مافيش أي ترحيل بيانات هنا عن قصد: الطيارين الموقوفين (active = 0) **مش**
   بيتحوّلوا للأرشيف أوتوماتيك — «موقوف» حاجة و«خرج من الشغل» حاجة تانية،
   والقرار ده للمدير من الشاشة نفسها. */
echo "تمام ✅\n";
