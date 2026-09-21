<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════
   📍 دبوس الخريطة في دفتر عملاء المحل (طلب صاحب النظام 2026-09-22):
   «إذا اختار من القائمة يجب أن يملأ كل الأماكن التي تخص العنوان المحفوظ من قبل».

   الدفتر كان بيحفظ الاسم والتليفون والعنوان والمنطقة — من غير نقطة الخريطة،
   فالمحل كان بيعيد تحديدها لنفس العميل مع كل شحنة.
   store_contacts.lat / lng (الاتنين NULL = مفيش دبوس محفوظ).
   بيتخطّى لو الأعمدة موجودة. التشغيل: php ops/apply_store_contact_pin.php
═══════════════════════════════════════════════════════════════ */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$db = (string) DB::connection()->getDatabaseName();
$has = fn (string $col): bool => (bool) DB::selectOne(
    'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
    [$db, 'store_contacts', $col]
);

if ($has('lat')) {
    echo "  = store_contacts.lat موجود\n";
} else {
    DB::statement("ALTER TABLE `store_contacts` ADD COLUMN `lat` decimal(10,7) DEFAULT NULL COMMENT 'نقطة التسليم على الخريطة (خط العرض) — بتتحفظ مع العميل عشان اختياره يملّي الدبوس كمان (طلب 2026-09-22)' AFTER `zone_id`");
    echo "  ✓ store_contacts.lat اتضاف\n";
}
if ($has('lng')) {
    echo "  = store_contacts.lng موجود\n";
} else {
    DB::statement("ALTER TABLE `store_contacts` ADD COLUMN `lng` decimal(10,7) DEFAULT NULL COMMENT 'نقطة التسليم على الخريطة (خط الطول)' AFTER `lat`");
    echo "  ✓ store_contacts.lng اتضاف\n";
}
echo "تمام ✅\n";
