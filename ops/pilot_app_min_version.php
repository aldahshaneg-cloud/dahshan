<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════
   📱 أقل إصدار مسموح لتطبيق الطيار — بعد النشر (2026-09-09)

   طلب صاحب النظام: «خلي 2.5.8 أقل إصدار مسموح بعد ما أنشر». الإعداد عايش في
   `site_settings.pilotApp` (JSON: minVersion/latestVersion/updateUrl/message)
   وتطبيق الطيار بيقراه في السبلاش (`checkAppUpdate`) — اللي تحت minVersion
   بيتوقف بشاشة تحديث إجباري.

   الأمان: مابنرفعش الحد قبل ما الـAPK يبقى **منشور فعلًا** — يعني الرابط
   `public/downloads/dahshan-pilot-latest.apk` بيشاور على ملف باسم النسخة دي.
   من غير كده الطيارين كلهم يتقفلوا على شاشة تحديث مالهاش ملف.

   التشغيل:
     php ops/pilot_app_min_version.php 2.5.8            → بيطبّق لو الـAPK منشور، وإلا بيرفض (كود 2)
     php ops/pilot_app_min_version.php 2.5.8 --dry      → بيطبع من غير كتابة
     php ops/pilot_app_min_version.php 2.5.8 --auto     → للكرون: ساكت لو مش منشور/متطبّق، وبيكتب علامة بعد التطبيق
     php ops/pilot_app_min_version.php 2.5.8 --force    → بيتخطّى فحص الرابط (مش للاستعمال العادي)
     php ops/pilot_app_min_version.php 2.5.8 --refresh  → بيعيد كتابة الرسالة/الرابط حتى لو الحد متطبّق
═══════════════════════════════════════════════════════════════ */

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$flags = array_values(array_filter($args, fn ($a) => str_starts_with($a, '--')));
$ver = array_values(array_filter($args, fn ($a) => ! str_starts_with($a, '--')))[0] ?? '';
$dry = in_array('--dry', $flags, true);
$auto = in_array('--auto', $flags, true);
$force = in_array('--force', $flags, true);
$refresh = in_array('--refresh', $flags, true); // إعادة كتابة الرسالة حتى لو الحد متطبّق

if (! preg_match('/^\d+\.\d+\.\d+$/', $ver)) {
    fwrite(STDERR, "الاستعمال: php ops/pilot_app_min_version.php <x.y.z> [--dry|--auto|--force]\n");
    exit(1);
}

// كرون: اتطبّقت قبل كده؟ خلاص
$marker = sys_get_temp_dir() . "/dahshan-minver-{$ver}.done";
if ($auto && is_file($marker)) {
    exit(0);
}

/* 1) الـAPK منشور؟ */
$link = $root . '/public/downloads/dahshan-pilot-latest.apk';
$target = is_link($link) ? (string) readlink($link) : (is_file($link) ? basename($link) : '');
$published = $target !== '' && str_contains($target, "-{$ver}-");
if (! $published && ! $force) {
    if ($auto) {
        exit(0); // لسه — نستنى الدورة الجاية
    }
    fwrite(STDERR, "⛔ الـAPK {$ver} مش منشور: dahshan-pilot-latest.apk → " . ($target ?: 'مفيش') . "\n");
    exit(2);
}

/* 2) الإعداد الحالي */
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$row = DB::selectOne("SELECT setting_value FROM site_settings WHERE setting_key = 'pilotApp' LIMIT 1");
$cur = $row && $row->setting_value ? json_decode((string) $row->setting_value, true) : [];
if (! is_array($cur)) {
    $cur = [];
}
$cmp = fn (string $a, string $b): int => version_compare($a, $b);
if (! $refresh && ($cur['minVersion'] ?? '') === $ver && ($cur['latestVersion'] ?? '') === $ver) {
    if ($auto) {
        touch($marker);
        exit(0);
    }
    echo "✓ متطبّق بالفعل: minVersion = latestVersion = {$ver}\n";
    exit(0);
}
if (! $force && $cmp((string) ($cur['minVersion'] ?? '0.0.0'), $ver) > 0) {
    fwrite(STDERR, "⛔ الحد الحالي ({$cur['minVersion']}) أعلى من {$ver} — مش هنرجّعه لورا\n");
    exit(3);
}

$new = $cur;
$new['minVersion'] = $ver;
$new['latestVersion'] = $ver;
$new['updateUrl'] = $cur['updateUrl'] ?? 'https://aldahshan.cloud/downloads/dahshan-pilot-latest.apk';
/* 2.5.8 اتبنت بمفتاح توقيع جديد (المفتاح القديم كان على جهاز hp ومش موجود) — أندرويد مابيركّبش نسخة بمفتاح
   مختلف فوق القديمة، فلازم الطيار يمسح القديمة الأول. الرسالة بتقول كده صراحة وفي الأول. */
$new['message'] = "نسخة جديدة من تطبيق الطيار {$ver} — مهم جدًا: امسح التطبيق القديم الأول (الإعدادات ← التطبيقات ← الدهشان ← إلغاء التثبيت) وبعدين نزّل النسخة الجديدة وركّبها وسجّل دخول تاني. النسخة دي مش هتتركّب فوق القديمة. لو ظهرت رسالة «Google Play للحماية: تم حظر التطبيق»: اضغط «مزيد من التفاصيل» ثم «التثبيت على أي حال». ولو الزرار ده مش موجود: افتح متجر Play ← صورة الحساب ← Play للحماية ← الترس ← اقفل «فحص التطبيقات» مؤقتًا، ركّب التطبيق، ورجّعه. الجديد فيها: الأزرار والتوقيتات اتظبطت، تنبيه فوري لو الـGPS مقفول، والوردية بتتزامن مع الفرع أول ما تفتح التطبيق. لو التثبيت مش راضي يكمّل نزّل نسخة 32 بت من موقع aldahshan.cloud";

echo "الحالي: " . json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo "الجديد: " . json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
if ($dry) {
    echo "(--dry: مفيش كتابة)\n";
    exit(0);
}

$json = json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$now = gmdate('Y-m-d H:i:s');
if ($row) {
    DB::update(
        "UPDATE site_settings SET setting_value = ?, updated_by = 'ops/pilot_app_min_version', updated_at = ? WHERE setting_key = 'pilotApp'",
        [$json, $now]
    );
} else {
    DB::insert(
        "INSERT INTO site_settings (setting_key, setting_value, updated_by, updated_at, created_at) VALUES ('pilotApp', ?, 'ops/pilot_app_min_version', ?, ?)",
        [$json, $now, $now]
    );
}
if ($auto) {
    touch($marker);
}
echo "✅ اتطبّق: minVersion = latestVersion = {$ver} ({$now} UTC)\n";
exit(0);
