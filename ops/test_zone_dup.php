<?php
/**
 * 🗺 حارس: رسالة تكرار اسم المنطقة.
 *
 * ═══ حصل على الإنتاج — أول يوم شغل (2026-09-01) ═══
 * سجل لارافل فيه ٨ أخطاء، كلهم:
 *     Duplicate entry '...' for key 'uq_zones_area_branch'
 * يعني حد بيضيف مناطق والنظام بيرفض وهو بيعيد — ٨ مرات لمنطقتين.
 *
 * السبب إن `zonesCreate` مكانتش بتمسك الخطأ، فبيوصل للمعالج العام
 * ويرجّع «خطأ في قاعدة البيانات» — رسالة مابتقولش الغلط ولا الحل.
 *
 * ═══ الفحص بينفّذ على قاعدة حقيقية ═══
 * البند ٣ بيدخل منطقة مرتين فعلًا جوه معاملة بترجع، ويقرا الرسالة اللي
 * بتطلع. فحص نصّي كان هيعدّي على أي إعادة صياغة بترجّع الرسالة العامة.
 *
 * التشغيل: php ops/test_zone_dup.php
 */
$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;

function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$what}\n"; }
    else { $fail++; echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n"; }
}

$src = file_get_contents($ROOT . '/app/Http/Controllers/Api/EntitiesController.php');
/* التعليقات بتتشال قبل أي فحص نصّي — التعليق الجديد بيشرح الباج وبيذكر
   «خطأ في قاعدة البيانات»، والفحص كان هيمسك شرحه هو. */
$code = preg_replace('#/\*[\s\S]*?\*/|//[^\n]*#', '', $src);

echo "\n══ 1) المصيدة موجودة ══\n";
ok('الإنشاء بيمسك QueryException',
    (bool) preg_match('/INSERT INTO zones[\s\S]{0,400}catch \(QueryException \$e\)/', $code));
ok('والتعديل كمان',
    (bool) preg_match('/UPDATE zones SET[\s\S]{0,400}catch \(QueryException \$e\)/', $code));
ok('والاتنين بيفرّقوا 1062 عن غيره',
    substr_count($code, "(int) (\$e->errorInfo[1] ?? 0) === 1062") >= 2,
    (string) substr_count($code, "(int) (\$e->errorInfo[1] ?? 0) === 1062"));
ok('وأي خطأ تاني بيتسجّل ويرجع 500 — مش بيتبلع',
    substr_count($code, "Log::error('zones_create") === 1
    && substr_count($code, "Log::error('zones_update") === 1);

echo "\n══ 2) الرسالة بتقول الغلط والحل ══\n";
ok('بتقول اسم المنطقة نفسه', str_contains($code, "'«' . \$areaName . '»"));
ok('وبتقول إن المشكلة في نفس الفرع', str_contains($code, 'موجودة قبل كده في نفس فرع التوصيل'));
ok('وبتقول الحل', str_contains($code, 'غيّر الاسم أو اختار فرع تاني'));
ok('🔴 ومش بترجّع الرسالة العامة',
    ! preg_match("/INSERT INTO zones[\s\S]{0,500}خطأ في قاعدة البيانات/", $code),
    'لسه بيرجع رسالة المعالج العام');

echo "\n══ 3) تنفيذ فعلي ══\n";
require $ROOT . '/vendor/autoload.php';
$app = require $ROOT . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/* 🔴 من غير الاتنين دول: استثناء مش متمسك بعد البوتستراب بيتطبع بشكل
   جميل وبيخرج بكود 0 — الحارس يبان ناجح وهو مات في نص شغله.
   ولازم يتركّبوا بعد البوتستراب — قبله لارافل بيدوس عليهم. */
set_exception_handler(function (Throwable $e): void {
    echo "\n🔴 استثناء مش متمسك: " . get_class($e) . ' — ' . $e->getMessage()
        . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
});
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        exit(1);
    }
});


use Illuminate\Support\Facades\DB;

try { DB::connection()->getPdo(); }
catch (Throwable $e) {
    echo "  ⚠️ مافيش اتصال بالقاعدة — تخطّي التنفيذ\n";
    echo "\n" . str_repeat('─', 46) . "\n";
    echo $fail === 0 ? "✅ عدّى {$pass} فحص (من غير تنفيذ)\n\n" : "🔴 وقع {$fail}\n\n";
    exit($fail === 0 ? 0 : 1);
}

$branch = DB::select('SELECT id FROM branches LIMIT 1')[0]->id ?? null;
if ($branch === null) {
    echo "  ⚠️ مافيش فروع في القاعدة دي — تخطّي\n";
} else {
    DB::beginTransaction();
    try {
        $name = 'منطقة-فحص-' . substr(md5((string) $branch), 0, 8);
        $ins = fn () => DB::insert(
            'INSERT INTO zones (area_name, price, delivery_branch_id, source_branch_id, created_at) VALUES (?,?,?,?,?)',
            [$name, 10, $branch, null, date('Y-m-d H:i:s')]
        );

        $ins();
        ok('الإدخال الأول عدّى', true);

        /* التكرار: نفس الاسم ونفس الفرع */
        $code1062 = 0;
        try { $ins(); }
        catch (Illuminate\Database\QueryException $e) { $code1062 = (int) ($e->errorInfo[1] ?? 0); }
        ok('🔴 والتاني بيرمي 1062 — القيد شغّال', $code1062 === 1062, (string) $code1062);

        /* ونفس الاسم في فرع تاني **لازم** يعدّي — القيد على الاتنين مع بعض */
        $other = DB::select('SELECT id FROM branches WHERE id <> ? LIMIT 1', [$branch])[0]->id ?? null;
        if ($other !== null) {
            $okOther = true;
            try {
                DB::insert(
                    'INSERT INTO zones (area_name, price, delivery_branch_id, source_branch_id, created_at) VALUES (?,?,?,?,?)',
                    [$name, 10, $other, null, date('Y-m-d H:i:s')]
                );
            } catch (Throwable $e) { $okOther = false; }
            ok('ونفس الاسم في فرع تاني مسموح — عشان الرسالة تكون صادقة', $okOther);
        } else {
            echo "  ⚠️ فرع واحد بس — مافيش فرع تاني نجرّب عليه\n";
        }

        DB::rollBack();
        $left = DB::select('SELECT COUNT(*) c FROM zones WHERE area_name = ?', [$name])[0]->c;
        ok('والمعاملة رجعت — مافيش أثر', (int) $left === 0, "فاضل {$left}");
    } catch (Throwable $e) {
        DB::rollBack();
        ok('التنفيذ من غير أخطاء', false, $e->getMessage());
    }
}

echo "\n" . str_repeat('─', 46) . "\n";
echo $fail === 0
    ? "✅ عدّى {$pass} فحص — الرسالة بتقول الغلط والحل\n\n"
    : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n\n";
exit($fail === 0 ? 0 : 1);
