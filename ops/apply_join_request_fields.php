<?php

/* تطبيق حقول «بيانات المتقدّم» على `pilot_join_requests` (2026-09-12) —
   محلي أو إنتاج. بيقرا اتصال لارافل من .env المجاور، وكل خطوة **idempotent**:
   بيفحص وجود العمود قبل ما يضيف، فتشغيله مرتين آمن.
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

/* كلها **اختيارية** (NULL) — المتقدّم ممكن مايملاش أي حاجة منها،
   والطلب بيتقبل عادي. ده مقصود: الحقول دي بتساعد في القرار مش بتمنعه. */
$cols = [
    'prev_employer'    => "varchar(255) DEFAULT NULL COMMENT 'أماكن اشتغل فيها قبل كده (اختياري — المتقدّم بيملاه)'",
    'leave_reason'     => "varchar(255) DEFAULT NULL COMMENT 'سبب ترك آخر شغل (اختياري)'",
    'last_salary'      => "decimal(12,2) DEFAULT NULL COMMENT 'آخر راتب كان بياخده (اختياري)'",
    'experience_years' => "decimal(4,1) DEFAULT NULL COMMENT 'سنين الخبرة في التوصيل (اختياري)'",
    'applicant_note'   => "text DEFAULT NULL COMMENT 'أي حاجة تانية المتقدّم حابب يقولها (اختياري)'",
];

$after = 'address';
$add   = [];
foreach ($cols as $name => $def) {
    if ($hasCol('pilot_join_requests', $name)) {
        echo "· pilot_join_requests.{$name} موجود\n";
    } else {
        $add[] = "ADD COLUMN `{$name}` {$def} AFTER `{$after}`";
    }
    $after = $name;
}

if ($add) {
    DB::statement('ALTER TABLE `pilot_join_requests` ' . implode(', ', $add));
    echo '✓ اتضاف ' . count($add) . " عمود لـ pilot_join_requests\n";
}

/* `source` موجود من الأصل بس التعليق كان بيقول `self` وهي في الكود `home`
   (الموقع العام) — بنصلّح التعليق عشان المخطط مايكدبش على اللي بيقراه. */
$src = DB::selectOne(
    'SELECT COLUMN_COMMENT c FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
    [$db, 'pilot_join_requests', 'source']
);
if ($src && ! str_contains((string) $src->c, 'home')) {
    DB::statement(
        "ALTER TABLE `pilot_join_requests` MODIFY COLUMN `source` varchar(20) DEFAULT NULL
         COMMENT 'مصدر الطلب: home (الموقع العام) / branch (مشرف فرع) / admin (الإدارة)'"
    );
    echo "✓ تعليق عمود source اتصحّح (كان بيقول self والكود بيكتب home)\n";
} else {
    echo "· تعليق source مظبوط\n";
}

echo "تمام ✅\n";
