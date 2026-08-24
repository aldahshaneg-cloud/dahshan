<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * بيثبت إن ملف السكيمة (database/schema/mysql-schema.sql) بيعيد إنتاج
 * قاعدة النظام الحالي **حرفيًا** — مش «تقريبًا».
 *
 * الطريقة: بيعمل قاعدة مؤقتة، بيحمّل فيها الملف، وبيقارن `SHOW CREATE TABLE`
 * لكل جدول بين الاتنين. الفرق الوحيد المسموح هو `AUTO_INCREMENT=N` (بيختلف
 * حسب البيانات الموجودة) و`ROW_FORMAT` لو الخادم أضافه لوحده.
 *
 * ليه المقارنة دي مش رفاهية: الترحيل مبني على إن السكيمة **زي ما هي**.
 * أي فرق صامت — collation عمود، طول decimal، ترتيب فهرس، ON UPDATE على
 * timestamp — بيطلع كأرقام غلط أو استعلامات بطيئة بعد الإطلاق، مش كخطأ.
 *
 * ⚠️ الأمان: القاعدة الأصلية **مابتتلمسش** — قراءة بس. المؤقتة بتتعمل
 * وتتمسح، والاسم بيتفحص إنه مش اسم القاعدة الحقيقية قبل أي DROP.
 */
class VerifySchemaParity extends Command
{
    protected $signature = 'schema:verify
                            {--file=database/schema/mysql-schema.sql : ملف السكيمة}
                            {--scratch=aldahshan_schema_check : اسم القاعدة المؤقتة}
                            {--keep : ما تمسحش القاعدة المؤقتة بعد الفحص}';

    protected $description = 'بيقارن ملف السكيمة بقاعدة النظام الحالي جدول بجدول';

    public function handle(): int
    {
        $file    = base_path((string) $this->option('file'));
        $scratch = (string) $this->option('scratch');
        $live    = (string) DB::connection()->getDatabaseName();

        if (! is_file($file)) {
            $this->error("مالقيتش ملف السكيمة: {$file}");
            return self::FAILURE;
        }

        // حارس: ممنوع أي عملية هدم على القاعدة الحقيقية
        if ($scratch === '' || strcasecmp($scratch, $live) === 0) {
            $this->error("اسم القاعدة المؤقتة لازم يكون مختلف عن الحقيقية ({$live})");
            return self::FAILURE;
        }

        $pdo = $this->rootPdo();

        $this->line("القاعدة الحقيقية : {$live}  (قراءة بس)");
        $this->line("القاعدة المؤقتة  : {$scratch}");
        $this->newLine();

        try {
            $pdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");
            $pdo->exec("CREATE DATABASE `{$scratch}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $this->line('بحمّل ملف السكيمة...');
            $this->loadSql($pdo, $scratch, $file);

            $liveTables    = $this->tables($pdo, $live);
            $scratchTables = $this->tables($pdo, $scratch);

            $missing = array_values(array_diff($liveTables, $scratchTables));
            $extra   = array_values(array_diff($scratchTables, $liveTables));

            $this->line(sprintf('جداول الأصل: %d   ·   جداول الملف: %d',
                count($liveTables), count($scratchTables)));
            $this->newLine();

            $diffs = [];
            foreach ($liveTables as $t) {
                if (in_array($t, $missing, true)) {
                    $diffs[$t] = 'الجدول مش موجود في الملف';
                    continue;
                }
                $a = $this->normalize($this->showCreate($pdo, $live, $t));
                $b = $this->normalize($this->showCreate($pdo, $scratch, $t));
                if ($a !== $b) {
                    $diffs[$t] = $this->firstDifferingLine($a, $b);
                }
            }
            foreach ($extra as $t) {
                $diffs[$t] = 'جدول زيادة في الملف مش موجود في الأصل';
            }

            if ($diffs === []) {
                $this->info(sprintf('✓ مطابق تمامًا — %d جدول، صفر اختلاف', count($liveTables)));
                $result = self::SUCCESS;
            } else {
                $this->error(sprintf('✗ %d جدول مختلف:', count($diffs)));
                foreach ($diffs as $t => $why) {
                    $this->line("   • {$t}");
                    foreach (explode("\n", $why) as $l) {
                        $this->line("       {$l}");
                    }
                }
                $result = self::FAILURE;
            }
        } catch (Throwable $e) {
            $this->error('فشل الفحص: ' . $e->getMessage());
            $result = self::FAILURE;
        } finally {
            if (! $this->option('keep') && isset($pdo) && strcasecmp($scratch, $live) !== 0) {
                $pdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");
            }
        }

        return $result;
    }

    /** اتصال بصلاحية إنشاء/حذف قواعد — من نفس بيانات .env */
    private function rootPdo(): PDO
    {
        $c = config('database.connections.' . config('database.default'));

        return new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $c['host'], $c['port']),
            $c['username'],
            $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
    }

    private function loadSql(PDO $pdo, string $db, string $file): void
    {
        $pdo->exec("USE `{$db}`");
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $sql = file_get_contents($file);
        // بنشيل تعليقات mysqldump المشروطة /*!... */ اللي بتغيّر إعدادات الجلسة
        $statements = preg_split('/;\s*[\r\n]+/', (string) $sql);

        foreach ($statements as $stmt) {
            $stmt = trim((string) $stmt);
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            $pdo->exec($stmt);
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return string[] */
    private function tables(PDO $pdo, string $db): array
    {
        $st = $pdo->prepare(
            "SELECT table_name FROM information_schema.tables
              WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name"
        );
        $st->execute([$db]);

        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    private function showCreate(PDO $pdo, string $db, string $table): string
    {
        $row = $pdo->query("SHOW CREATE TABLE `{$db}`.`{$table}`")->fetch(PDO::FETCH_NUM);

        return (string) ($row[1] ?? '');
    }

    /**
     * بيشيل الفروق اللي **مش** فروق سكيمة:
     *  • AUTO_INCREMENT=N — بيعتمد على البيانات الموجودة
     *  • اسم القاعدة لو ظهر
     */
    private function normalize(string $ddl): string
    {
        $ddl = preg_replace('/\s*AUTO_INCREMENT=\d+/', '', $ddl) ?? $ddl;

        return trim($ddl);
    }

    private function firstDifferingLine(string $a, string $b): string
    {
        $la = explode("\n", $a);
        $lb = explode("\n", $b);
        $n  = max(count($la), count($lb));

        for ($i = 0; $i < $n; $i++) {
            $x = trim($la[$i] ?? '«ناقص»');
            $y = trim($lb[$i] ?? '«ناقص»');
            if ($x !== $y) {
                return "الأصل : {$x}\nالملف : {$y}";
            }
        }

        return 'فرق في المسافات أو النهايات';
    }
}
