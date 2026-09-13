<?php

/* تطبيق عمود `verified_role` على `party_identities` (2026-09-12) — محلي أو إنتاج.
   بيقرا اتصال لارافل من .env المجاور، والخطوة **idempotent**: بيفحص وجود
   العمود قبل ما يضيف، فتشغيله مرتين آمن.

   ليه العمود ده: `party_identities` هو **أعلى** مصدر في ترتيب حسم الاسم،
   فاللي بيكتب فيه اسمه بيغلب على الكل. لما بقى المحل يقدر يصحّح الاسم
   (طلب صاحب النظام 2026-09-12) لازم نفرّق: تصحيح موظف مايتدوسش بتصحيح محل.

   المصدر الوحيد للحقيقة لسه database/schema/mysql-schema.sql. */
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

$has = (bool) DB::select(
    'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
    [$db, 'party_identities', 'verified_role']
);

if ($has) {
    echo "· party_identities.verified_role موجود\n";
} else {
    DB::statement(
        "ALTER TABLE `party_identities`
         ADD COLUMN `verified_role` varchar(32) DEFAULT NULL
           COMMENT 'دور اللي صحّح الاسم — تصحيح الموظف مايتدوسش بتصحيح محل'
         AFTER `verified_by`"
    );
    echo "✓ party_identities.verified_role اتضاف\n";

    /* الصفوف القديمة كلها كانت من موظفين (المسار كان مقفول على
       admin/branch/callcenter) — فبتتعلّم كده عشان مايتدوسش عليها. */
    $n = DB::update("UPDATE `party_identities` SET `verified_role` = 'staff' WHERE `verified_role` IS NULL");
    echo "✓ {$n} صف قديم اتعلّم 'staff' (المسار كان مقفول على الموظفين وقتها)\n";
}

echo "تمام ✅\n";
