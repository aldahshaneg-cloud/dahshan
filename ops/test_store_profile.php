<?php
/**
 * 🏪 اختبار «بيانات المحل = بيانات المُرسِل».
 *
 * ═══ الطلب بالحرف (صاحب النظام 2026-08-31) ═══
 * «عايز بيانات المحل اللي هي بيانات المرسل تكون ثابتة عشان الفورم بتاعها
 *  لما بعمل طلب جديد ميظهرش غير لما اكون عايز اغير بيانات المرسل، عشان
 *  المحل ممكن يكون هو المستقبل ويطلب اوردر من مرسل اخر فيكتب بياناته…
 *  والتطبيق ميعملش اي حاجة غير لما المحل يكمل البيانات — لا الشركة هي
 *  اللي هتفتح له حساب ولكن المحل هو اللي هيكمل بياناته».
 *
 * ═══ اللي كان ناقص ═══
 * ملف الاستلام الدائم كان موجود، بس `shop_name` كان **الحقل الوحيد** في
 * هوية المُرسِل اللي `PUT /api/store/pickup-profile` مابيقبلوش — الإدارة بس
 * تقدر تكتبه من لوحة المحلات. والاسم ده بالذات بيتبعت كـ`senderName` مع
 * كل شحنة، و`OrdersController::store` **بيرفض** الأوردر من غيره. يعني محل
 * الشركة فتحت له حساب من غير اسم كان بيعدّي كل الحرّاس وبعدين أول شحنة
 * ترجع «يرجى اختيار أو إدخال العميل استلام» وهو مش فاهم ليه ومش قادر
 * يصلّحها بنفسه.
 *
 * ═══ الاختبار ═══
 * بينده `pickupProfileSave` و`pickupProfile` **الحقيقيتين** على قاعدة
 * مؤقتة — مش بيحاكي الـSQL بإيده. (اختبار محاكي عدّى قبل كده على طفرة
 * كسرت كنترولر — شوف رأس ops/test_home_branch.php.)
 *
 * التشغيل: php ops/test_store_profile.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\CustomersController;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** actor لحساب محل — نفس شكل اللي `ResolveApiActor` بتبنيه من الجلسة */
function storeActor(int $userId, string $username): Actor
{
    return new Actor(
        userId:     $userId,
        customerId: null,
        username:   $username,
        role:       'store',
        branchId:   null,
        name:       $username,
    );
}

$pass = 0;
$fail = 0;
function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$what}\n"; }
    else { $fail++; echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n"; }
}

/* 🔴 أي استثناء مش متمسك لازم يبقى **فشل** — مش خروج بكود 0.
   لولا ده، أي طفرة بتخلي الكنترولر يرمي في نص الاختبار كانت بتقتل
   السكريبت قبل سطر `exit($fail > 0 ...)` في آخره، فمعالج أخطاء لارافل
   بيطبع الاستثناء **ويخرج بصفر** — يعني الاختبار «ناجح» وهو واقع.
   طلعت في تجربة كسر الحرّاس: تلات طفرات حقيقية عدّت كلها بالسبب ده. */
set_exception_handler(function (Throwable $e): void {
    echo "\n🔴 استثناء مش متمسك: " . get_class($e) . ' — ' . $e->getMessage() . "\n";
    echo '   ' . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
});
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        exit(1);
    }
});

/* ── قاعدة مؤقتة: القاعدة الحقيقية مابتتلمسش ── */
$live = DB::connection()->getDatabaseName();
$tmp  = 'aldahshan_store_profile_test';
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

// نوجّه اتصال لارافل على القاعدة المؤقتة عشان الكنترولر يشتغل عليها
config(['database.connections.mysql.database' => $tmp]);
DB::purge('mysql');
DB::reconnect('mysql');

/* ── بيانات: فرع + منطقة + حساب محل زي ما الشركة بتفتحه ── */
DB::insert("INSERT INTO branches (id, name, code, created_at) VALUES (901, 'فرع الاختبار', 'TST', NOW())");
DB::insert("INSERT INTO zones (id, area_name, price, delivery_branch_id, created_at)
            VALUES (801, 'منطقة الاختبار', 25.00, 901, NOW())");
/* الشركة بتفتح الحساب: اسم مستخدم وباسورد ودور — **وبس**. كل حقول
   `shop_*` بتفضل NULL والمحل هو اللي بيكمّلها. */
DB::insert("INSERT INTO users (id, username, password_hash, role, created_at)
            VALUES (701, 'teststore', 'x', 'store', NOW())");

/** بينده الكنترولر الحقيقي بجسم JSON وactor لحساب المحل */
function callSave(array $body): array
{
    $req = Request::create('/api/store/pickup-profile', 'PUT', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode($body, JSON_UNESCAPED_UNICODE));
    // الـactor بيتحقن زي ما ResolveApiActor بتعمل بالظبط
    $req->attributes->set(ResolveApiActor::ATTRIBUTE, storeActor(701, 'teststore'));

    return json_decode((new CustomersController())->pickupProfileSave($req)->getContent(), true);
}

echo "\n══ 1) الحساب الجديد: كل بيانات المُرسِل فاضية ══\n";
$row = DB::select('SELECT shop_name, shop_phone, shop_address, shop_zone_id FROM users WHERE id = 701')[0];
ok('الشركة فتحت الحساب من غير اسم محل', $row->shop_name === null);
ok('ومن غير هاتف',                      $row->shop_phone === null);
ok('ومن غير عنوان',                     $row->shop_address === null);
ok('ومن غير منطقة',                     $row->shop_zone_id === null);

echo "\n══ 2) 🔴 المحل بيكتب اسمه بنفسه — ده اللي كان ناقص ══\n";
try {
    $r = callSave(['shopName' => 'سوبر ماركت النور']);
    ok('المسار قبل shopName', true);
    ok('والرد بيرجّع الاسم على السلك', ($r['profile']['shopName'] ?? null) === 'سوبر ماركت النور',
       var_export($r['profile']['shopName'] ?? null, true));
    $db = DB::select('SELECT shop_name FROM users WHERE id = 701')[0]->shop_name;
    ok('واتكتب في العمود فعلًا', $db === 'سوبر ماركت النور', var_export($db, true));
} catch (Throwable $e) {
    ok('المسار قبل shopName', false, get_class($e) . ': ' . $e->getMessage());
}

echo "\n══ 3) الاسم مايتمسحش — عكس باقي الحقول ══\n";
/* الأوردر بيرفض من غير senderName. لو الفاضي اتقبل واتكتب NULL، الشحنات
   بتقف على المحل برسالة مالهاش علاقة بالسبب. */
foreach ([['', 'فاضي'], ['  ', 'مسافات'], ['م', 'حرف واحد']] as [$bad, $label]) {
    $threw = false; $status = 0;
    try { callSave(['shopName' => $bad]); }
    catch (ApiException $e) { $threw = true; $status = $e->status(); }
    catch (Throwable $e) { $threw = true; }
    ok("اسم «{$label}» بيترفض", $threw);
    if ($threw) { ok("  والحالة 400 مش 500", $status === 400, (string) $status); }
}
$still = DB::select('SELECT shop_name FROM users WHERE id = 701')[0]->shop_name;
ok('والاسم المحفوظ مالوش خدش بعد المحاولات الفاشلة', $still === 'سوبر ماركت النور', var_export($still, true));

echo "\n══ 4) التعديل الجزئي لسه شغّال — الاسم مابيتلمسش ══\n";
/* `array_key_exists` هو أساس التعديل الجزئي: المفتاح المش مبعوت مابيتغيّرش.
   لو الاسم اتضاف غلط لقايمة الحقول اللي بتتكتب دايمًا، أي حفظ إعدادات
   عادي كان هيدهسه. */
callSave(['phone' => '01012345678', 'address' => 'شارع الجمهورية', 'zoneId' => 801]);
$row = DB::select('SELECT shop_name, shop_phone, shop_address, shop_zone_id FROM users WHERE id = 701')[0];
ok('الهاتف اتحفظ',   $row->shop_phone === '01012345678');
ok('والعنوان',       $row->shop_address === 'شارع الجمهورية');
ok('والمنطقة',       (int) $row->shop_zone_id === 801);
ok('🔴 والاسم زي ما هو — الحفظ من غير shopName مابيمسحوش',
   $row->shop_name === 'سوبر ماركت النور', var_export($row->shop_name, true));

echo "\n══ 5) الملف كامل = هوية مُرسِل جاهزة للشحنة ══\n";
callSave(['lat' => 31.0409, 'lng' => 31.3785]);
$req = Request::create('/api/store/pickup-profile', 'GET');
$req->attributes->set(ResolveApiActor::ATTRIBUTE, storeActor(701, 'teststore'));
$p = json_decode((new CustomersController())->pickupProfile($req)->getContent(), true)['profile'];
/* دي بالظبط الحقول اللي `storeProfileIncomplete()` في store.html بتفحصها —
   لو اتغيّر اسم أي مفتاح هنا الواجهة بتقرا undefined وتفضل تقول «ناقص». */
foreach (['shopName', 'shopPhone', 'address', 'zoneId', 'lat', 'lng'] as $k) {
    ok("السلك بيرجّع «{$k}»", isset($p[$k]) && $p[$k] !== null && $p[$k] !== '',
       var_export($p[$k] ?? null, true));
}
ok('والفرع اتشتق من المنطقة لوحده', (int) ($p['branchId'] ?? 0) === 901, var_export($p['branchId'] ?? null, true));
ok('واسم المنطقة كمان',            ($p['zoneName'] ?? '') === 'منطقة الاختبار');

echo "\n══ 5.5) 🔒 القفل على السيرفر كمان مش في الواجهة بس ══\n";
/* الواجهة بتقفل الحقل بعد التسجيل لأن الاسم مبصوم على الشحنات القديمة.
   بس إخفاء حقل مش قفل — `fetch` من الـconsole كان بيعدّي.
   (مسكتها المراجعة العدائية بعد ما التعديل كان مرفوع فعلًا.) */
$threw = false; $status = 0; $msg = '';
try { callSave(['shopName' => 'اسم تاني خالص']); }
catch (ApiException $e) { $threw = true; $status = $e->status(); $msg = $e->getMessage(); }
ok('إعادة تسمية المحل بعد التسجيل بترفض', $threw);
ok('  والحالة 403', $status === 403, (string) $status);
ok('  والرسالة بتقول التعديل من الإدارة', str_contains($msg, 'الإدارة'), $msg);
$same = DB::select('SELECT shop_name FROM users WHERE id = 701')[0]->shop_name;
ok('  والاسم المحفوظ ما اتغيّرش', $same === 'سوبر ماركت النور', var_export($same, true));
/* نفس القيمة مسموحة — الواجهة بتبعت الاسم أحيانًا زي ما هو، ولازم ماتقعش */
$again = true;
try { callSave(['shopName' => 'سوبر ماركت النور']); } catch (Throwable $e) { $again = false; }
ok('وإعادة إرسال **نفس** الاسم مابترفضش', $again);

echo "\n══ 5.6) المسافات غير المرئية مابتعدّيش كاسم ══\n";
/* trim() بيشيل مسافات ASCII بس — اسم كله NBSP كان بيتحفظ «فاضي بصريًا»
   وبعدين يتقفل ومفيش طريق لتصحيحه من التطبيق. */
DB::update('UPDATE users SET shop_name = NULL WHERE id = 701');
foreach ([["\u{00A0}\u{00A0}\u{00A0}", 'NBSP'], ["\u{200B}\u{200B}", 'zero-width'],
          ["\u{FEFF} \u{00A0}", 'BOM+مسافات']] as [$bad, $label]) {
    $t = false;
    try { callSave(['shopName' => $bad]); } catch (Throwable $e) { $t = true; }
    ok("اسم «{$label}» بيترفض", $t);
}
$still = DB::select('SELECT shop_name FROM users WHERE id = 701')[0]->shop_name;
ok('والعمود فضل NULL — ما اتكتبش اسم فاضي بصريًا', $still === null, var_export($still, true));
callSave(['shopName' => 'سوبر ماركت النور']);   // نرجّع الحالة للاختبارات اللي بعده

echo "\n══ 6) الاسم بيتقص على طول العمود (190) مش بيقع ══\n";
// نفضّي الاسم الأول — القفل الجديد بيرفض إعادة التسمية بعد التسجيل
DB::update('UPDATE users SET shop_name = NULL WHERE id = 701');
$long = str_repeat('م', 400);
callSave(['shopName' => $long]);
$db = DB::select('SELECT shop_name FROM users WHERE id = 701')[0]->shop_name;
ok('الاسم الطويل اتقص مش رمى خطأ قاعدة', mb_strlen($db) === 190, 'الطول = ' . mb_strlen($db));

echo "\n══ 7) محل تاني مايقدرش يلمس ملف الأول ══\n";
/* المسار مالوش باراميتر بيحدد محل — بيشتغل على `WHERE u.id = <الجلسة>` بس.
   الفحص ده بيحرس القاعدة دي من أي تعديل مستقبلي بيضيف `?userId`. */
DB::insert("INSERT INTO users (id, username, password_hash, role, created_at)
            VALUES (702, 'otherstore', 'x', 'store', NOW())");
$req2 = Request::create('/api/store/pickup-profile', 'PUT', [], [], [], [
    'CONTENT_TYPE' => 'application/json',
], json_encode(['shopName' => 'محل تاني خالص', 'userId' => 701, 'id' => 701], JSON_UNESCAPED_UNICODE));
$req2->attributes->set(ResolveApiActor::ATTRIBUTE, storeActor(702, 'otherstore'));
(new CustomersController())->pickupProfileSave($req2);
$a = DB::select('SELECT shop_name FROM users WHERE id = 701')[0]->shop_name;
$b = DB::select('SELECT shop_name FROM users WHERE id = 702')[0]->shop_name;
ok('محل ٧٠١ ما اتلمسش رغم إرسال id بتاعه', mb_strlen($a) === 190, var_export(mb_substr($a, 0, 20), true));
ok('والاسم اتكتب على المحل صاحب الجلسة',   $b === 'محل تاني خالص', var_export($b, true));

echo "\n══ 8) 🔴 مسار الإدارة مايقدرش يقفل المحل بره التطبيق ══\n";
/* من 2026-08-31 `shop_name` و`shop_address` بقوا **شرط تشغيل**: البوابة
   الإجبارية (مالهاش زرار إغلاق) بتفتح لو أي واحد فيهم فاضي.
   ولوحة المحلات بتبعت الحقول دي **دايمًا** حتى لو فاضية — فأدمن بيصلّح
   رقم تليفون ويفضّي خانة العنوان بالغلط كان بيقفل المحل تاني يوم. */
function callUserUpdate(int $id, array $body): void
{
    $req = Request::create("/api/users/{$id}", 'PUT', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode($body, JSON_UNESCAPED_UNICODE));
    $req->attributes->set(ResolveApiActor::ATTRIBUTE, new Actor(
        userId: 1, customerId: null, username: 'admin', role: 'admin', branchId: null, name: 'admin'));
    (new App\Http\Controllers\Api\EntitiesController())->usersUpdate($req, (string) $id);
}

DB::update("UPDATE users SET shop_name = 'سوبر ماركت النور', shop_address = 'شارع الجمهورية' WHERE id = 701");
callUserUpdate(701, ['shopPhone' => '01099999999', 'shopName' => '', 'shopAddress' => '']);
$row = DB::select('SELECT shop_name, shop_address, shop_phone FROM users WHERE id = 701')[0];
ok('الهاتف اتحدّث عادي', $row->shop_phone === '01099999999', var_export($row->shop_phone, true));
ok('🔴 الاسم الفاضي مامسحش المحفوظ', $row->shop_name === 'سوبر ماركت النور', var_export($row->shop_name, true));
ok('🔴 والعنوان الفاضي كمان',        $row->shop_address === 'شارع الجمهورية', var_export($row->shop_address, true));

/* اسم من حرف واحد من الإدارة كان بيخلي البوابة تعرضه **مقفول** وترفض
   الحفظ (الحد الأدنى حرفين) — بوابة مالهاش مخرج نهائيًا. */
$threw = false;
try { callUserUpdate(701, ['shopName' => 'م']); } catch (Throwable $e) { $threw = true; }
ok('واسم من حرف واحد بيترفض من الإدارة كمان', $threw,
   'المحل هيتحبس في بوابة بتعرض اسم مقفول وترفض حفظه');
ok('  والاسم المحفوظ سليم', DB::select('SELECT shop_name FROM users WHERE id = 701')[0]->shop_name === 'سوبر ماركت النور');

/* والتعديل الحقيقي لسه شغّال — القفل مش مانع الإدارة */
callUserUpdate(701, ['shopName' => 'روح دمشق', 'shopAddress' => 'شارع جديد']);
$row = DB::select('SELECT shop_name, shop_address FROM users WHERE id = 701')[0];
ok('والإدارة لسه بتقدر تعدّل الاسم فعلًا', $row->shop_name === 'روح دمشق', var_export($row->shop_name, true));
ok('والعنوان',                              $row->shop_address === 'شارع جديد');

/* ── تنضيف ── */
DB::purge('mysql');
$pdo->exec("DROP DATABASE `{$tmp}`");

echo "\n══════════════════════════════════════════════\n";
echo "STORE PROFILE: {$pass} ناجح · {$fail} فاشل\n";
echo "══════════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
