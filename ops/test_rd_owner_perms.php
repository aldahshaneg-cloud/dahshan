<?php
/**
 * 🔐 حارس: روح دمشق — حسابات المُلّاك وصلاحيتا view.owner / act.editPilot.
 *
 * ═══ الطلب (صاحب النظام 2026-09-04) ═══
 * «تقفيل روح دمشق اللي على السيرفر يبقى زي البرنامج القديم بالظبط».
 * الفروق اللي كانت موجودة: الإخفاء بقايمة أسماء في الكود بدل وظيفة «مالك»،
 * ومافيش صلاحية «رؤية حسابات المُلّاك» ولا «تعديل كشف الطيار».
 *
 * ═══ الفحص بينفّذ الكود الحقيقي ═══
 * sheetHidden وrequireWriteWindow دوال خاصة في DamascusController — بنندهها
 * بالـReflection على مستخدم وهمي وصف صلاحيات حقيقي جوه معاملة بتترجع.
 * أي رجوع للفلترة بالاسم أو شيل الحارس هيقع هنا.
 *
 * التشغيل: php ops/test_rd_owner_perms.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;

function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$what}\n"; }
    else { $fail++; echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n"; }
}

require $ROOT . '/vendor/autoload.php';
$app = require $ROOT . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\DamascusController;
use App\Wire\DamascusWire as W;
use Illuminate\Support\Facades\DB;

echo "\n══ 1) الصلاحيات الجديدة معرّفة على السلك ══\n";
$keys = [];
$groupOf = [];
foreach (W::permGroups() as $g) {
    foreach ($g['items'] as [$k]) {
        $keys[] = $k;
        $groupOf[$k] = $g['title'];
    }
}
ok('view.owner موجودة', in_array('view.owner', $keys, true));
ok('act.editPilot موجودة', in_array('act.editPilot', $keys, true));
ok('الاتنين في مجموعة «صلاحيات خاصة» زي القديم',
    str_contains((string) ($groupOf['view.owner'] ?? ''), 'خاصة') && ($groupOf['view.owner'] ?? '') === ($groupOf['act.editPilot'] ?? '-'));
ok('مافيش قايمة أسماء مخفية بتتستخدم في الفلترة',
    ! method_exists(W::class, 'hiddenSheetNames'));

echo "\n══ 2) المالك بالوظيفة مش بالاسم ══\n";
ok('job=مالك → مالك', W::isOwnerPilot(['job' => 'مالك', 'name' => 'x']));
ok('الاسم القديم من غير وظيفة → مش مالك (الترحيل حوّله لوظيفة)', ! W::isOwnerPilot(['job' => 'طيار', 'name' => 'علام']));
ok('فراغات حوالين الوظيفة بتتقبل', W::isOwnerPilot(['job' => ' مالك ']));

echo "\n══ 3) sheetHidden على الكنترولر الحقيقي ══\n";
$ctl = app(DamascusController::class);
$ref = new ReflectionClass($ctl);
$sheetHidden = $ref->getMethod('sheetHidden');
$sheetHidden->setAccessible(true);
$writeWin = $ref->getMethod('requireWriteWindow');
$writeWin->setAccessible(true);

$owner = ['id' => 1, 'name' => 'علام', 'job' => 'مالك', 'branchId' => 1];
$plain = ['id' => 2, 'name' => 'أحمد', 'job' => 'طيار', 'branchId' => 1];
$admin = ['username' => 'admin', 'isAdmin' => true, 'role' => 'admin'];

DB::beginTransaction();
try {
    $u1 = 'rdtest_' . substr(bin2hex(random_bytes(3)), 0, 6);   // مشرف من غير view.owner
    $u2 = 'rdtest_' . substr(bin2hex(random_bytes(3)), 0, 6);   // مشرف معاه view.owner + editPilot + dateNav
    $u3 = 'rdtest_' . substr(bin2hex(random_bytes(3)), 0, 6);   // مشرف من غير dateNav
    DB::insert('INSERT INTO rd_perms (username, perm_keys, branches) VALUES (?, ?, NULL)',
        [$u1, json_encode(['page_daily' => true, 'act_edit' => true, 'act_dateNav' => true])]);
    DB::insert('INSERT INTO rd_perms (username, perm_keys, branches) VALUES (?, ?, NULL)',
        [$u2, json_encode(['page_daily' => true, 'act_edit' => true, 'view_owner' => true, 'act_editPilot' => true, 'act_dateNav' => true])]);
    DB::insert('INSERT INTO rd_perms (username, perm_keys, branches) VALUES (?, ?, NULL)',
        [$u3, json_encode(['page_daily' => true, 'act_edit' => true])]);
    $sup1 = ['username' => $u1, 'isAdmin' => false, 'role' => 'accountant'];
    $sup2 = ['username' => $u2, 'isAdmin' => false, 'role' => 'accountant'];
    $sup3 = ['username' => $u3, 'isAdmin' => false, 'role' => 'accountant'];

    ok('الأدمن بيشوف المالك', ! $sheetHidden->invoke($ctl, $admin, $owner));
    ok('مشرف من غير view.owner مايشوفش المالك', $sheetHidden->invoke($ctl, $sup1, $owner));
    ok('مشرف معاه view.owner بيشوف المالك', ! $sheetHidden->invoke($ctl, $sup2, $owner));
    ok('الطيار العادي ظاهر للكل', ! $sheetHidden->invoke($ctl, $sup1, $plain));
    ok('🔴 الاسم القديم من غير وظيفة مالك مش مخفي (الفلترة بالوظيفة بس)',
        ! $sheetHidden->invoke($ctl, $sup1, ['id' => 3, 'name' => 'عبدالرحمن', 'job' => 'طيار', 'branchId' => 1]));

    echo "\n══ 4) requireWriteWindow: النهارده بس + كشف الطيار ══\n";
    $settings = W::defaultSettings();
    $ctx = ['settings' => $settings];
    $today = W::bizToday($settings);
    [$ty, $tm, $td] = array_map('intval', explode('-', $today));
    $ymToday = sprintf('%04d-%02d', $ty, $tm);
    $other = $td > 1 ? $td - 1 : $td + 1;

    $throws = function (callable $fn): ?string {
        try { $fn(); return null; } catch (ApiException $e) { return $e->getMessage(); }
    };

    ok('من غير act.dateNav: النهارده بيعدّي', $throws(fn () => $writeWin->invoke($ctl, $sup3, $ctx, $ymToday, $td, [])) === null);
    $msg = $throws(fn () => $writeWin->invoke($ctl, $sup3, $ctx, $ymToday, $other, []));
    ok('من غير act.dateNav: يوم تاني بيترفض', $msg !== null && str_contains($msg, 'النهارده'), (string) $msg);
    ok('معاه act.dateNav: أي يوم بيعدّي', $throws(fn () => $writeWin->invoke($ctl, $sup1, $ctx, $ymToday, $other, [])) === null);
    $msg = $throws(fn () => $writeWin->invoke($ctl, $sup1, $ctx, $ymToday, $td, ['via' => 'pilot']));
    ok('via=pilot من غير act.editPilot بيترفض', $msg !== null && str_contains($msg, 'كشف الطيار'), (string) $msg);
    ok('via=pilot مع act.editPilot بيعدّي', $throws(fn () => $writeWin->invoke($ctl, $sup2, $ctx, $ymToday, $td, ['via' => 'pilot'])) === null);
    ok('via=daily من غير act.editPilot بيعدّي', $throws(fn () => $writeWin->invoke($ctl, $sup1, $ctx, $ymToday, $td, ['via' => 'daily'])) === null);
    ok('الأدمن بيعدّي في كل الحالات', $throws(fn () => $writeWin->invoke($ctl, $admin, $ctx, '2020-01', 15, ['via' => 'pilot'])) === null);

    echo "\n══ 5) bizToday: اليوم التجاري بيبدأ dayStart ══\n";
    $cairo = new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo'));
    $h = (int) $cairo->format('G');
    ok('dayStart=0 → التاريخ الميلادي', W::bizToday(['dayStart' => 0]) === $cairo->format('Y-m-d'));
    $exp23 = $h < 23 ? $cairo->modify('-1 day')->format('Y-m-d') : $cairo->format('Y-m-d');
    ok('dayStart=23 → قبل 11 مساءً لسه يوم إمبارح', W::bizToday(['dayStart' => 23]) === $exp23);
    ok('dayStart فاضي → 8 (مش 0)', W::bizToday(['dayStart' => '']) === ($h < 8 ? $cairo->modify('-1 day')->format('Y-m-d') : $cairo->format('Y-m-d')));
} finally {
    DB::rollBack();
}

echo "\n══ 6) الواجهة والمستورد ══\n";
$html = file_get_contents($ROOT . '/public/damascus.html');
ok('damascus.html: مافيش HIDDEN_SHEET_NAMES', ! str_contains($html, 'HIDDEN_SHEET_NAMES'));
ok('damascus.html: الإخفاء بالوظيفة + view.owner', str_contains($html, 'function isOwnerPilot') && str_contains($html, 'can("view.owner")'));
ok('damascus.html: كشف الطيار محتاج act.editPilot', str_contains($html, 'can("act.editPilot")'));
ok('damascus.html: الحفظ بيبعت via للسيرفر', substr_count($html, 'via: currentPage()') >= 2);
ok('damascus.html: خيار «مالك» في فورم الطيار', str_contains($html, '<option value="مالك">مالك</option>'));
$imp = file_get_contents($ROOT . '/ops/import_rd_firebase.php');
ok('المستورد بيقرا مفاتيح البرنامج القديم h/o/adv/ded (مش hours/orders/advance)',
    str_contains($imp, "\$e['h']") && str_contains($imp, "\$e['o']") && str_contains($imp, "\$e['adv']") && str_contains($imp, "\$e['ded']"));
ok('المستورد بيحوّل الأسماء القديمة لوظيفة مالك مرة واحدة', str_contains($imp, 'legacyOwnerNames'));

echo "\n════════════════════════════════════════\n";
echo "RD OWNER/PERMS: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
