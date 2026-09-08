<?php
/* مزامنة القاعدة **المحلية** مع ملف المخطط — جداول/أعمدة اتضافت في
   الملف (من جلسات تانية) وماتطبّقتش محليًا، فبوابة schema:verify (وبوابات
   السلك اللي بتقرا القاعدة المحلية) بتقع على حاجات مش بتاعة الشغل الجاري.

   الطريقة: بيشغّل schema:verify، بياخد أسماء الجداول المختلفة، ولكل
   جدول بيمشي على أعمدته في الملف بالترتيب: العمود الناقص بيتضاف في
   مكانه (AFTER اللي قبله)، والموجود بيتعمله MODIFY بتعريف الملف.
   الجداول الناقصة كلها بتتعمل من تعريف الملف.

   **محلي بس** — الإنتاج بيتعمل له تطبيق مقصود لكل تغيير. */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "💥 فشل: {$e->getMessage()}\n");
    exit(1);
});

use Illuminate\Support\Facades\DB;

if (is_dir('/var/www/dahshan')) {
    fwrite(STDERR, "⛔ ده سكربت محلي — ماينفعش على السيرفر\n");
    exit(1);
}
$db = DB::getDatabaseName();
echo "القاعدة المحلية: {$db}\n";

$sql = (string) file_get_contents(__DIR__ . '/../database/schema/mysql-schema.sql');
preg_match_all('/CREATE TABLE `([^`]+)` \((.*?)\) ENGINE=[^;]+;/s', $sql, $m, PREG_SET_ORDER);
$defs = [];
foreach ($m as [$stmt, $table, $body]) {
    $defs[$table] = ['stmt' => $stmt, 'body' => $body];
}

$hasTable = fn (string $t): bool => (bool) DB::select(
    'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]
);

/* ① الجداول الناقصة */
DB::statement('SET FOREIGN_KEY_CHECKS = 0');
foreach ($defs as $table => $d) {
    if ($hasTable($table)) {
        continue;
    }
    DB::statement(preg_replace('/ AUTO_INCREMENT=\d+/', '', $d['stmt']));
    echo "✓ اتعمل: {$table}\n";
}

/* ② الجداول المختلفة حسب البوابة نفسها */
exec('php ' . escapeshellarg(__DIR__ . '/../artisan') . ' schema:verify 2>&1', $out);
$diff = [];
foreach ($out as $ln) {
    if (preg_match('/^\s*•\s+(\S+)\s*$/u', $ln, $mm)) {
        $diff[] = $mm[1];
    }
}
foreach (array_unique($diff) as $table) {
    if (! isset($defs[$table]) || ! $hasTable($table)) {
        continue;
    }
    $local = array_map(fn ($c) => $c->Field, DB::select("SHOW COLUMNS FROM `{$table}`"));
    $prev = null;
    foreach (preg_split('/\r?\n/', $defs[$table]['body']) as $line) {
        if (! preg_match('/^\s*`([^`]+)` (.+?),?\s*$/', $line, $cm)) {
            continue;   // PRIMARY KEY / KEY / CONSTRAINT — مش أعمدة
        }
        [$col, $def] = [$cm[1], rtrim($cm[2], ',')];
        if (in_array($col, $local, true)) {
            DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `{$col}` {$def}");
        } else {
            DB::statement("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}" . ($prev ? " AFTER `{$prev}`" : ' FIRST'));
            $local[] = $col;
            echo "✓ عمود اتضاف: {$table}.{$col}\n";
        }
        $prev = $col;
    }
    /* ③ الفهارس والمفاتيح الأجنبية الناقصة — بالاسم */
    $idx = [];
    foreach (DB::select("SHOW INDEX FROM `{$table}`") as $i) {
        $idx[$i->Key_name] = true;
    }
    $fks = [];
    foreach (DB::select(
        'SELECT CONSTRAINT_NAME n FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ?',
        [$db, $table, 'FOREIGN KEY']
    ) as $f) {
        $fks[$f->n] = true;
    }
    foreach (preg_split('/\r?\n/', $defs[$table]['body']) as $line) {
        $line = trim($line, " ,\t");
        if (preg_match('/^(UNIQUE KEY|KEY) `([^`]+)` (.+)$/', $line, $km)) {
            if (! isset($idx[$km[2]])) {
                DB::statement("ALTER TABLE `{$table}` ADD {$km[1]} `{$km[2]}` {$km[3]}");
                echo "✓ فهرس اتضاف: {$table}.{$km[2]}\n";
            }
        } elseif (preg_match('/^CONSTRAINT `([^`]+)` (FOREIGN KEY .+)$/', $line, $fm)) {
            if (! isset($fks[$fm[1]])) {
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$fm[1]}` {$fm[2]}");
                echo "✓ مفتاح أجنبي اتضاف: {$table}.{$fm[1]}\n";
            }
        }
    }
    echo "✓ اتزامن: {$table}\n";
}
DB::statement('SET FOREIGN_KEY_CHECKS = 1');
echo "تمام\n";
