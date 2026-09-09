<?php
declare(strict_types=1);

/* حارس `ops/pilot_app_min_version.php` (2026-09-09): مايرفعش الحد قبل ما الـAPK يبقى منشور،
   وبيتخطّى فحص النشر بـ--force بس، و--dry مابيكتبش. بيشتغل على القاعدة المحلية بس (بيقرا فقط
   من غير --force). التشغيل: php ops/test_min_version_script.php */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
function ok(string $label, bool $cond, string $hint = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        echo "  ✗ 🔴 {$label}" . ($hint !== '' ? " — {$hint}" : '') . "\n";
    }
}
$php = PHP_BINARY;
$run = function (string $args) use ($php, $root): array {
    $out = [];
    $code = 0;
    exec("\"{$php}\" \"{$root}/ops/pilot_app_min_version.php\" {$args} 2>&1", $out, $code);

    return [$code, implode("\n", $out)];
};

$src = file_get_contents($root . '/ops/pilot_app_min_version.php');
ok('بيتحقق إن الرابط dahshan-pilot-latest.apk بيشاور على النسخة', str_contains($src, "str_contains(\$target, \"-{\$ver}-\")"));
ok('  ومابيرجّعش الحد لورا', str_contains($src, 'مش هنرجّعه لورا'));
ok('  وبيحدّث updated_at (التطبيق بيعتمد عليه في ?since)', str_contains($src, 'updated_at = ? WHERE setting_key'));

[$c, $o] = $run('abc');
ok('رقم نسخة مش صالح = كود 1', $c === 1, (string) $c);

// نسخة مش منشورة (رقم خيالي) = رفض بكود 2 من غير ما يلمس القاعدة
[$c, $o] = $run('9.9.9');
ok('نسخة مش منشورة = رفض (كود 2)', $c === 2 && str_contains($o, 'مش منشور'), $c . ' ' . $o);
[$c, $o] = $run('9.9.9 --auto');
ok('  و--auto ساكت (كود 0) لحد ما تتنشر', $c === 0 && trim($o) === '', $c . ' ' . $o);

// --dry --force: بيطبع الحالي/الجديد ومابيكتبش
[$c, $o] = $run('9.9.9 --dry --force');
ok('--dry --force بيطبع من غير كتابة', $c === 0 && str_contains($o, 'الجديد:') && str_contains($o, '--dry'), $c . ' ' . mb_substr($o, 0, 200));
ok('  والرسالة فيها رقم النسخة', str_contains($o, 'تطبيق الطيار 9.9.9'));

echo "\n════════════════════════════════════════\n";
echo "MIN VERSION SCRIPT: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
