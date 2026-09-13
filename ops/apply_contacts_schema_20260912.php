<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════
   مطابقة القاعدة المحلية لتغييرات 2026-09-10/12 اللي اتعملت على الإنتاج من
   الجهاز التاني من غير سكربت في المستودع (اتلقت بمقارنة مخطط الإنتاج
   2026-09-13 بعد تلف القرص الخارجي):
     • `address` من varchar(190) لـ varchar(500) في customers / order_deliveries
       (address) / orders (sender_address) / receivers / senders / store_contacts
     • senders.phone1 و receivers.phone1: الفهرس العادي idx_*_phone1 بقى
       UNIQUE uq_*_phone1 (بعد دمج المكرر بـ ops/dedupe_contacts.php --apply)
   بيتخطّى اللي متطبّق. التشغيل: php ops/apply_contacts_schema_20260912.php
═══════════════════════════════════════════════════════════════ */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$db = (string) DB::connection()->getDatabaseName();
$colType = function (string $table, string $col) use ($db): string {
    $r = DB::selectOne(
        'SELECT COLUMN_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$db, $table, $col]
    );

    return $r ? (string) $r->t : '';
};
$hasIndex = function (string $table, string $idx) use ($db): bool {
    return (bool) DB::selectOne(
        'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
        [$db, $table, $idx]
    );
};

foreach ([
    ['customers', 'address', "varchar(500) DEFAULT NULL COMMENT 'العنوان الافتراضي المختصر'"],
    ['order_deliveries', 'address', 'varchar(500) DEFAULT NULL'],
    ['orders', 'sender_address', 'varchar(500) DEFAULT NULL'],
    ['receivers', 'address', 'varchar(500) DEFAULT NULL'],
    ['senders', 'address', 'varchar(500) DEFAULT NULL'],
    ['store_contacts', 'address', 'varchar(500) DEFAULT NULL'],
] as [$t, $c, $def]) {
    if ($colType($t, $c) === 'varchar(500)') {
        echo "  = {$t}.{$c} varchar(500) موجود\n";
        continue;
    }
    DB::statement("ALTER TABLE `{$t}` MODIFY COLUMN `{$c}` {$def}");
    echo "  ✓ {$t}.{$c} → varchar(500)\n";
}

foreach (['senders', 'receivers'] as $t) {
    if ($hasIndex($t, "uq_{$t}_phone1")) {
        echo "  = {$t}: uq_{$t}_phone1 موجود\n";
        continue;
    }
    $dup = (int) DB::selectOne("SELECT COUNT(*) n FROM (SELECT phone1 FROM `{$t}` WHERE phone1 IS NOT NULL AND phone1 <> '' GROUP BY phone1 HAVING COUNT(*) > 1) d")->n;
    if ($dup > 0) {
        fwrite(STDERR, "⛔ {$t}: فيه {$dup} رقم مكرر — شغّل php ops/dedupe_contacts.php --apply الأول\n");
        exit(1);
    }
    if ($hasIndex($t, "idx_{$t}_phone1")) {
        DB::statement("ALTER TABLE `{$t}` DROP INDEX `idx_{$t}_phone1`");
    }
    DB::statement("ALTER TABLE `{$t}` ADD UNIQUE KEY `uq_{$t}_phone1` (`phone1`)");
    echo "  ✓ {$t}: uq_{$t}_phone1 اتعمل بدل idx_{$t}_phone1\n";
}
echo "تمام ✅\n";
