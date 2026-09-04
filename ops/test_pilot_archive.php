<?php
/**
 * 🗄️ حارس أرشفة الطيار — بديل الحذف.
 *
 * ═══ الطلب بالحرف (صاحب النظام 2026-09-01) ═══
 * «الطيار لا أستطيع مسحه من الصفحة الخاصة بالطيارين. أريد بدل مسحه أن يتم
 *  نفيه إلى صفحة أخرى لكي يكون غير فعّال، بحيث إذا كانت هناك بيانات مرتبطة
 *  به لا تؤثر على شيء في البيانات السابقة».
 *
 * ═══ ليه الحذف مكانش شغّال أصلًا ═══
 * `pilots` عليه **١٨ مفتاح أجنبي** كلها RESTRICT (أوردرات · ورديات · عهدة ·
 * حركات نقدية · تقفيلات شهرية · أذونات · طلبات إرجاع)، **وكل طيار عنده حساب
 * دخول مربوط** (`users.pilot_id`) — فحتى الطيار اللي مالوش ولا أوردر مكانش
 * بيتحذف. ولو اشتغل كان أسوأ: اسم الطيار مبصوم على حركات عهدة وتقفيلات،
 * والحذف كان هيفضّي التقارير المالية بأثر رجعي.
 *
 * الفحص بينده الكنترولر **الحقيقي** على قاعدة مؤقتة — مش بيحاكي الـSQL.
 *
 * التشغيل: php ops/test_pilot_archive.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\EntitiesController;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;
function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$what}\n"; }
    else { $fail++; echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n"; }
}

/* استثناء مش متمسك = فشل، مش خروج بصفر (شوف [[php-test-exit-code-lies]]) */
set_exception_handler(function (Throwable $e): void {
    echo "\n🔴 استثناء مش متمسك: " . get_class($e) . ' — ' . $e->getMessage() . "\n";
    exit(1);
});
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        exit(1);
    }
});

/* ── قاعدة مؤقتة ── */
$live = DB::connection()->getDatabaseName();
$tmp  = 'aldahshan_pilot_archive_test';
if (strcasecmp($tmp, $live) === 0) { echo "🔴 اسم القاعدة المؤقتة = الحقيقية\n"; exit(1); }
$cfg = config('database.connections.mysql');
$pdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']}", $cfg['username'], $cfg['password'],
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS `{$tmp}`");
$pdo->exec("CREATE DATABASE `{$tmp}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$tmp}`");
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (array_filter(array_map('trim', explode(";\n", file_get_contents(__DIR__ . '/../database/schema/mysql-schema.sql')))) as $stmt) {
    if ($stmt !== '' && ! str_starts_with($stmt, '--')) {
        try { $pdo->exec($stmt); } catch (Throwable $e) { /* تعليقات وسطور إعداد */ }
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
config(['database.connections.mysql.database' => $tmp]);
DB::purge('mysql');
DB::reconnect('mysql');

$admin = new Actor(userId: 1, customerId: null, username: 'admin', role: 'admin', branchId: null, name: 'admin');
$call  = function (string $method, int $id) use ($admin) {
    $req = Request::create("/api/pilots/{$id}/x", 'POST');
    $req->attributes->set(ResolveApiActor::ATTRIBUTE, $admin);

    return (new EntitiesController())->{$method}($req, (string) $id);
};
$archive   = fn (int $id) => $call('pilotsArchive', $id);
$unarchive = fn (int $id) => (new EntitiesController())->pilotsUnarchive((string) $id);
$grab      = function (callable $fn): ?string {
    try { $fn(); return null; } catch (ApiException $e) { return $e->getMessage(); }
};

/* ── بيانات ── */
DB::insert("INSERT INTO branches (id, name, code, created_at) VALUES (53,'حي الجامعة','HAL',NOW()),(54,'المدير','MODIR',NOW())");
$mkPilot = function (int $id, string $name, float $custody = 0, ?int $branch = 53): void {
    DB::insert('INSERT INTO pilots (id, name, home_branch_id, assigned_branch_id, custody_balance, created_at)
                VALUES (?,?,?,?,?,NOW())', [$id, $name, $branch, $branch, $custody]);
    DB::insert('INSERT INTO users (id, username, password_hash, role, pilot_id, created_at)
                VALUES (?,?,?,?,?,NOW())', [900 + $id, 'u' . $id, 'x', 'pilot', $id]);
};
$mkPilot(1, 'طيار نضيف');
$mkPilot(2, 'طيار عليه عهدة', 60.00);
$mkPilot(3, 'طيار بوردية');
$mkPilot(4, 'طيار بأوردر جاري');
$mkPilot(5, 'طيار للرجوع');
DB::insert("INSERT INTO shifts (id, pilot_id, branch_id, status, started_at, created_at)
            VALUES (1,3,53,'active',NOW(),NOW())");
DB::insert("INSERT INTO senders (id,name,phone1,address,created_at) VALUES (1,'م','01000000000','ش',NOW())");
DB::insert("INSERT INTO orders (id, order_num, branch_id, pilot_id, status, status_since, created_at, total_delivery_price)
            VALUES (1,'T-1',53,4,'delivering',NOW(),NOW(),0)");
/* أوردر **مسلَّم** لطيار نضيف — التاريخ ده هو اللي المفروض مايتأثرش */
DB::insert("INSERT INTO orders (id, order_num, branch_id, pilot_id, status, status_since, created_at, total_delivery_price)
            VALUES (2,'T-2',53,1,'تم التسليم',NOW(),NOW(),50)");

echo "\n══ 1) 🔴 الحذف اتلغى والبديل الأرشفة ══\n";
$msg = $grab(fn () => (new EntitiesController())->pilotsDelete('1'));
ok('DELETE بيرفض', $msg !== null);
ok('  والرسالة بتوجّه للأرشفة', $msg !== null && str_contains($msg, 'أرشفة'), (string) $msg);
ok('  والطيار لسه موجود', (bool) DB::select('SELECT id FROM pilots WHERE id = 1'));

echo "\n══ 2) الحرّاس التلاتة قبل الأرشفة ══\n";
$m2 = $grab(fn () => $archive(2));
ok('طيار عليه عهدة بيترفض', $m2 !== null);
ok('  والرسالة بتقول المبلغ', $m2 !== null && str_contains($m2, '60.00'), (string) $m2);
$m3 = $grab(fn () => $archive(3));
ok('طيار بوردية مفتوحة بيترفض', $m3 !== null && str_contains($m3, 'وردية'), (string) $m3);
$m4 = $grab(fn () => $archive(4));
ok('طيار بأوردر جاري بيترفض', $m4 !== null && str_contains($m4, 'أوردر'), (string) $m4);
foreach ([2, 3, 4] as $id) {
    ok("  والطيار #{$id} فضل فعّال",
       DB::select('SELECT archived_at FROM pilots WHERE id = ?', [$id])[0]->archived_at === null);
}

echo "\n══ 3) الأرشفة نفسها ══\n";
$archive(1);
$p = DB::select('SELECT * FROM pilots WHERE id = 1')[0];
ok('الطيار اتأرشف',            $p->archived_at !== null);
ok('واتسجّل مين أرشفه',        $p->archived_by === 'admin', (string) $p->archived_by);
ok('واتحرّر من الفرع الجاري',   $p->assigned_branch_id === null);
ok('🔴 وفرعه الثابت **فضل**',  (int) $p->home_branch_id === 53, (string) $p->home_branch_id);
ok('وحالته اتصفّرت (مافيش وردية)', $p->status === null);
ok('وخرج من الدور',            $p->queue_no === null);
ok('🔒 وحساب دخوله اتقفل',
   (int) DB::select('SELECT blocked FROM users WHERE pilot_id = 1')[0]->blocked === 1);

echo "\n══ 4) 🔴 البيانات السابقة ما اتأثرتش — ده جوهر الطلب ══\n";
$o = DB::select('SELECT pilot_id, status, total_delivery_price FROM orders WHERE id = 2')[0];
ok('الأوردر القديم لسه مربوط بالطيار', (int) $o->pilot_id === 1);
ok('وحالته زي ما هي',                  $o->status === 'تم التسليم');
ok('وقيمته زي ما هي',                  (float) $o->total_delivery_price === 50.0);
ok('والصف نفسه لسه في pilots (مش محذوف)',
   (bool) DB::select('SELECT id FROM pilots WHERE id = 1'));
ok('واسمه لسه مقروء للتقارير',
   DB::select('SELECT name FROM pilots WHERE id = 1')[0]->name === 'طيار نضيف');

echo "\n══ 5) المؤرشف مش بيظهر في القوايم التشغيلية ══\n";
$listReq = Request::create('/api/pilots', 'GET');
$listReq->attributes->set(ResolveApiActor::ATTRIBUTE, $admin);
$items = json_decode((new EntitiesController())->pilotsList($listReq)->getContent(), true)['items'];
$ids   = array_map(fn ($x) => (int) $x['id'], $items);
ok('قايمة الطيارين مافيهاش المؤرشف', ! in_array(1, $ids, true), implode(',', $ids));
ok('وفيها الفعّالين',                in_array(2, $ids, true) && in_array(5, $ids, true));

$archReq = Request::create('/api/pilots?archived=1', 'GET');
$archReq->attributes->set(ResolveApiActor::ATTRIBUTE, $admin);
$aItems = json_decode((new EntitiesController())->pilotsList($archReq)->getContent(), true)['items'];
$aIds   = array_map(fn ($x) => (int) $x['id'], $aItems);
ok('و`?archived=1` بترجّع المؤرشفين بس', $aIds === [1], implode(',', $aIds));
ok('والسلك فيه archivedAt',              ($aItems[0]['archivedAt'] ?? null) !== null);
ok('وفيه archivedBy',                    ($aItems[0]['archivedBy'] ?? null) === 'admin');

echo "\n══ 6) الأرشفة مرتين وحالات الحافة ══\n";
ok('أرشفة المؤرشف بترفض', $grab(fn () => $archive(1)) !== null);
ok('أرشفة طيار مش موجود بترفض', $grab(fn () => $archive(999)) !== null);
ok('رجوع طيار فعّال بيرفض', $grab(fn () => $unarchive(5)) !== null);

echo "\n══ 7) الرجوع للخدمة ══\n";
$unarchive(1);
$p = DB::select('SELECT * FROM pilots WHERE id = 1')[0];
ok('اتشال من الأرشيف',           $p->archived_at === null);
ok('و`archived_by` اتصفّى',      $p->archived_by === null);
ok('🔴 ورجع لفرعه الثابت',       (int) $p->assigned_branch_id === 53, (string) $p->assigned_branch_id);
ok('وحالته لسه NULL (الفرع بيفتح الوردية)', $p->status === null);
ok('🔒 وحساب دخوله اتفك',
   (int) DB::select('SELECT blocked FROM users WHERE pilot_id = 1')[0]->blocked === 0);
$items = json_decode((new EntitiesController())->pilotsList($listReq)->getContent(), true)['items'];
ok('ورجع يظهر في القايمة', in_array(1, array_map(fn ($x) => (int) $x['id'], $items), true));

echo "\n══ 8) الأفعال مقفولة على المؤرشف (مش القوايم بس) ══\n";
/* الأرشفة بتشيله من القوايم، بس التحميل وفتح الوردية بياخدوا `id` مباشرة —
   فلازم فحص عند الفعل نفسه كمان، وإلا أوردر بيتعلّق على طيار مش شغّال. */
$archive(5);
$ordersSrc = file_get_contents(__DIR__ . '/../app/Http/Controllers/Api/OrdersController.php');
$boardSrc  = file_get_contents(__DIR__ . '/../app/Http/Controllers/Api/BoardController.php');
ok('تحميل أوردر على مؤرشف مقفول',
   substr_count($ordersSrc, 'الطيار مؤرشف — مايتحملش عليه أوردرات') >= 2,
   'عدد الحرّاس: ' . substr_count($ordersSrc, 'الطيار مؤرشف — مايتحملش عليه أوردرات'));
ok('وفتح وردية لمؤرشف مقفول',
   str_contains($boardSrc, 'الطيار مؤرشف — رجّعه للخدمة'));
ok('ولوحة الفرع بتفلتر المؤرشفين',
   str_contains($boardSrc, 'p.assigned_branch_id = ? AND p.archived_at IS NULL'));

echo "\n══ 9) الحذف اتشال من الواجهة ══\n";
$tiar = file_get_contents(__DIR__ . '/../public/tiar.html');
ok('مافيش `deletePilot` خالص',  ! str_contains($tiar, 'deletePilot'));
ok('وفيه `archivePilot`',        str_contains($tiar, 'window.archivePilot = async function'));
ok('وفيه `unarchivePilot`',      str_contains($tiar, 'window.unarchivePilot = async function'));
ok('وصندوق المؤرشفين موجود',     str_contains($tiar, 'id="archivedPilotsBox"'));
ok('وبيتحمّل مع فتح الصفحة',
   (bool) preg_match('/page === "pilots" && window\.loadArchivedPilots/', $tiar));
/* الواجهة مابقتش تمسح حساب الدخول بإيدها — السيرفر بيقفله جوه المعاملة */
ok('والواجهة مابتمسحش حساب الدخول بإيدها',
   ! (bool) preg_match('/archivePilot[\s\S]{0,900}api\.del\("\/api\/users\//', $tiar));

/* ── تنضيف ── */
DB::purge('mysql');
$pdo->exec("DROP DATABASE `{$tmp}`");

echo "\n══════════════════════════════════════════════\n";
echo "PILOT ARCHIVE: {$pass} ناجح · {$fail} فاشل\n";
echo "══════════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
