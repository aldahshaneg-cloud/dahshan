<?php

declare(strict_types=1);

/**
 * فاحص تحميل الكلاسات — بوابة نشر.
 *
 * `php -l` بيفحص النحو بس؛ تريتة أو كلاس من غير import بيعدّي منه وبيكسر
 * الإنتاج وقت أول طلب (حصل فعلًا 2026-08-22 مع BoardController). السكربت ده
 * بيعمل bootstrap للارافل ويحمّل الكلاسات فعليًا بالـautoloader.
 *
 * الاستخدام:
 *   php ops/classload.php App\\Http\\Controllers\\Api\\BoardController App\\Jobs\\SendCustomerPush
 * من غير وسائط بيفحص قايمة الكنترولرات والمهام الافتراضية. بيخرج بكود 1 لو
 * أي واحد فشل — فينفع يوقف سكربت النشر.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$targets = array_slice($argv, 1);
if ($targets === []) {
    $targets = [];
    foreach (glob(__DIR__ . '/../app/Http/Controllers/Api/*.php') as $f) {
        $targets[] = 'App\\Http\\Controllers\\Api\\' . basename($f, '.php');
    }
    foreach (glob(__DIR__ . '/../app/Jobs/*.php') as $f) {
        $targets[] = 'App\\Jobs\\' . basename($f, '.php');
    }
    foreach (glob(__DIR__ . '/../app/Events/*.php') as $f) {
        $targets[] = 'App\\Events\\' . basename($f, '.php');
    }
    foreach (glob(__DIR__ . '/../app/Http/Controllers/Concerns/*.php') as $f) {
        $targets[] = 'App\\Http\\Controllers\\Concerns\\' . basename($f, '.php');
    }
}

$bad = 0;
foreach ($targets as $c) {
    $ok = class_exists($c) || trait_exists($c) || interface_exists($c);
    printf("  %s %s\n", $ok ? 'OK ' : 'BAD', $c);
    if (! $ok) {
        $bad++;
    }
}
printf("CLASSLOAD: %d فحص · %d فشل\n", count($targets), $bad);
exit($bad === 0 ? 0 : 1);
