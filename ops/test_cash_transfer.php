<?php

declare(strict_types=1);

/**
 * 🏦 اختبار التحويل بين الخزن + إدارة الخزن (إضافة · تعديل · حذف).
 *
 * ═══ ليه التحويل مسار واحد ═══
 * لو الواجهة عملت «صادر» من خزنة و«وارد» في التانية بنداءين، أي فشل بين
 * النداءين بيسيب فلوس طالعة من خزنة وما دخلتش التانية — فرق في الدفاتر
 * محدش هيعرف يفسّره. هنا الاتنين في معاملة واحدة.
 *
 * كله جوه معاملة بتترجع فمفيش أثر.
 *
 * التشغيل: php ops/test_cash_transfer.php
 */

use App\Http\Controllers\Api\FinanceController;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
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


$pass = 0;
$fail = 0;
function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✓ {$what}\n";
    } else {
        $fail++;
        echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n";
    }
}

function req(Actor $a, array $body = []): Request
{
    $r = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    $r->attributes->set(ResolveApiActor::ATTRIBUTE, $a);

    return $r;
}

function bal(int $id): float
{
    return (float) DB::selectOne('SELECT balance FROM cash_stores WHERE id = ?', [$id])->balance;
}

$ctl = new FinanceController();

DB::beginTransaction();
try {
    $branchId = (int) DB::selectOne('SELECT id FROM branches ORDER BY id LIMIT 1')->id;
    $admin    = Actor::staff(901, 'admin', 'admin', null, 'المدير');

    echo "\n══ 1) إضافة خزنة — للفرع وللإدارة ══\n";
    $r1 = json_decode($ctl->cashStoresCreate(req($admin, ['name' => 'خزنة فرع الاختبار', 'branchId' => $branchId]))->getContent(), true);
    $branchStore = (int) ($r1['store']['id'] ?? 0);
    ok('خزنة الفرع اتعملت', $branchStore > 0, json_encode($r1, JSON_UNESCAPED_UNICODE));
    ok('رصيدها بيبدأ صفر', abs(bal($branchStore)) < 0.005, (string) bal($branchStore));

    /* 🔴 دي اللي صاحب النظام طلبها: خزنة مالهاش فرع = خزنة الإدارة.
       العمود `branch_id` بيقبل NULL أصلًا والتعليق في المخطط بيقول
       «NULL للخزنة الرئيسية» — فالقدرة موجودة، الناقص كان الواجهة. */
    $r2 = json_decode($ctl->cashStoresCreate(req($admin, ['name' => 'خزنة الإدارة', 'branchId' => null]))->getContent(), true);
    $adminStore = (int) ($r2['store']['id'] ?? 0);
    ok('خزنة الإدارة (بلا فرع) اتعملت', $adminStore > 0, json_encode($r2, JSON_UNESCAPED_UNICODE));
    ok('فرعها فاضي فعلًا',
        DB::selectOne('SELECT branch_id FROM cash_stores WHERE id = ?', [$adminStore])->branch_id === null);

    try {
        $ctl->cashStoresCreate(req($admin, ['name' => '   ']));
        ok('اسم فاضي مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('اسم فاضي مرفوض', str_contains($e->getMessage(), 'اسم الخزنة'), $e->getMessage());
    }

    echo "\n══ 2) تعديل الخزنة ══\n";
    $ctl->cashStoresUpdate(req($admin, ['name' => 'خزنة الإدارة العامة']), (string) $adminStore);
    ok('الاسم اتغيّر',
        DB::selectOne('SELECT name FROM cash_stores WHERE id = ?', [$adminStore])->name === 'خزنة الإدارة العامة');
    $ctl->cashStoresUpdate(req($admin, ['branchId' => $branchId]), (string) $adminStore);
    ok('الفرع اتربط', (int) DB::selectOne('SELECT branch_id FROM cash_stores WHERE id = ?', [$adminStore])->branch_id === $branchId);
    $ctl->cashStoresUpdate(req($admin, ['branchId' => null]), (string) $adminStore);
    ok('ورجع بلا فرع (خزنة إدارة تاني)',
        DB::selectOne('SELECT branch_id FROM cash_stores WHERE id = ?', [$adminStore])->branch_id === null);

    echo "\n══ 3) التحويل ══\n";
    // نحط رصيد ابتدائي بحركة وارد حقيقية عشان المسار كله يتجرّب
    $ctl->cashTxnsCreate(req($admin, ['type' => 'in', 'amount' => 5000, 'reason' => 'رصيد افتتاحي']), (string) $branchStore);
    ok('رصيد خزنة الفرع 5000', abs(bal($branchStore) - 5000) < 0.005, (string) bal($branchStore));

    $tr = json_decode($ctl->cashStoresTransfer(req($admin, [
        'fromId' => $branchStore, 'toId' => $adminStore, 'amount' => 1500, 'reason' => 'توريد للإدارة',
    ]))->getContent(), true);
    ok('التحويل عدّى', ! empty($tr['ok']), json_encode($tr, JSON_UNESCAPED_UNICODE));
    ok('المصدر نقص 1500', abs(bal($branchStore) - 3500) < 0.005, (string) bal($branchStore));
    ok('الوجهة زادت 1500', abs(bal($adminStore) - 1500) < 0.005, (string) bal($adminStore));
    /* 🔴 أهم فحص: المجموع ما اتغيّرش. التحويل نقل مش خلق ولا حرق. */
    ok('إجمالي الخزنتين زي ما هو (5000)',
        abs((bal($branchStore) + bal($adminStore)) - 5000) < 0.005,
        (string) (bal($branchStore) + bal($adminStore)));

    echo "\n══ 4) الحركتين مربوطين ببعض في الدفتر ══\n";
    $outT = (array) DB::selectOne(
        "SELECT * FROM cash_transactions WHERE store_id = ? AND type = 'out' ORDER BY id DESC LIMIT 1", [$branchStore]
    );
    $inT = (array) DB::selectOne(
        "SELECT * FROM cash_transactions WHERE store_id = ? AND type = 'in' ORDER BY id DESC LIMIT 1", [$adminStore]
    );
    ok('حركة صادر مبلغها صح', abs((float) $outT['amount'] - 1500) < 0.005, (string) $outT['amount']);
    ok('حركة وارد مبلغها صح', abs((float) $inT['amount'] - 1500) < 0.005, (string) $inT['amount']);
    /* من غير اسم الخزنة التانية في السبب، الدفتر بيبقى «صادر 1500»
       و«وارد 1500» من غير أي رابط بينهم. */
    ok('سبب الصادر فيه اسم الوجهة', str_contains((string) $outT['reason'], 'خزنة الإدارة العامة'), (string) $outT['reason']);
    ok('سبب الوارد فيه اسم المصدر', str_contains((string) $inT['reason'], 'خزنة فرع الاختبار'), (string) $inT['reason']);
    ok('ملاحظة المدير اتكتبت في الاتنين',
        str_contains((string) $outT['reason'], 'توريد للإدارة') && str_contains((string) $inT['reason'], 'توريد للإدارة'));
    ok('اسم اللي حوّل اتسجّل', $outT['created_by'] === 'admin' && $inT['created_by'] === 'admin');

    echo "\n══ 5) الحمايات ══\n";
    try {
        $ctl->cashStoresTransfer(req($admin, ['fromId' => $branchStore, 'toId' => $adminStore, 'amount' => 999999]));
        ok('رصيد مايكفيش مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('رصيد مايكفيش مرفوض', str_contains($e->getMessage(), 'مايكفيش'), $e->getMessage());
    }
    ok('والأرصدة ما اتلمستش', abs(bal($branchStore) - 3500) < 0.005 && abs(bal($adminStore) - 1500) < 0.005);

    try {
        $ctl->cashStoresTransfer(req($admin, ['fromId' => $branchStore, 'toId' => $branchStore, 'amount' => 100]));
        ok('نفس الخزنة مرفوضة', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('نفس الخزنة مرفوضة', str_contains($e->getMessage(), 'خزنتين مختلفتين'), $e->getMessage());
    }

    foreach ([0, -50] as $bad) {
        try {
            $ctl->cashStoresTransfer(req($admin, ['fromId' => $branchStore, 'toId' => $adminStore, 'amount' => $bad]));
            ok("مبلغ {$bad} مرفوض", false, 'عدّى!');
        } catch (Throwable $e) {
            ok("مبلغ {$bad} مرفوض", true);
        }
    }

    try {
        $ctl->cashStoresTransfer(req($admin, ['fromId' => 99999999, 'toId' => $adminStore, 'amount' => 10]));
        ok('خزنة مش موجودة مرفوضة', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('خزنة مش موجودة مرفوضة', str_contains($e->getMessage(), 'غير موجودة'), $e->getMessage());
    }

    echo "\n══ 6) 🔴 ترتيب القفل ثابت — الحارس ضد التعليق ══\n";
    /* تحويل من 5 لـ7 مع تحويل من 7 لـ5 في نفس اللحظة = كل معاملة ماسكة
       قفل والتانية مستنياه (deadlock). القفل بترتيب الرقم بيمنع ده.
       مانقدرش نعمل تزامن حقيقي في اختبار بمعاملة واحدة، فبنحرس النص. */
    $src = file_get_contents((new ReflectionClass(FinanceController::class))->getFileName());
    ok('القفل بترتيب الرقم مش بترتيب من/إلى',
        str_contains($src, '[$lo, $hi] = $fromId < $toId ? [$fromId, $toId] : [$toId, $fromId];'),
        'السطر مش موجود');
    ok('الخزنتين بيتقفلوا قبل قراءة الرصيد',
        strpos($src, '$b = $this->lockStore($hi);') < strpos($src, "if ((float) \$from['balance'] < \$amount)"));

    echo "\n══ 7) الحذف: الحمايات القديمة لسه شغّالة ══\n";
    try {
        $ctl->cashStoresDelete((string) $adminStore);
        ok('خزنة فيها رصيد مايتحذفش', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('خزنة فيها رصيد مايتحذفش', str_contains($e->getMessage(), 'فيها رصيد'), $e->getMessage());
    }
    // نفضّيها بتحويل راجع، وبعدين نجرّب الحذف — المفروض يترفض للحركات
    $ctl->cashStoresTransfer(req($admin, ['fromId' => $adminStore, 'toId' => $branchStore, 'amount' => 1500]));
    ok('التحويل الراجع صفّرها', abs(bal($adminStore)) < 0.005, (string) bal($adminStore));
    try {
        $ctl->cashStoresDelete((string) $adminStore);
        ok('خزنة عليها حركات مايتحذفش', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('خزنة عليها حركات مايتحذفش', str_contains($e->getMessage(), 'حركات'), $e->getMessage());
    }
    // خزنة نضيفة خالص — دي بس اللي بتتحذف
    $r3 = json_decode($ctl->cashStoresCreate(req($admin, ['name' => 'خزنة للحذف']))->getContent(), true);
    $ctl->cashStoresDelete((string) $r3['store']['id']);
    ok('خزنة فاضية بلا حركات اتحذفت',
        DB::selectOne('SELECT id FROM cash_stores WHERE id = ?', [$r3['store']['id']]) === null);

    echo "\n══ 8) توجيه المسار والأدوار ══\n";
    $routes = app('router')->getRoutes();
    $act = 'مفيش';
    try {
        $act = (string) $routes->match(Request::create('/api/cash-stores/transfer', 'POST'))->getActionName();
    } catch (Throwable $e) {
        $act = 'مفيش مسار: ' . $e->getMessage();
    }
    ok('POST /api/cash-stores/transfer → cashStoresTransfer',
        str_contains($act, 'cashStoresTransfer'), $act);
    $mw = null;
    foreach ($routes->getRoutes() as $r) {
        if ($r->uri() === 'api/cash-stores/transfer') {
            $mw = implode(' · ', $r->gatherMiddleware());
        }
    }
    ok('الأدوار: المدير والمحاسب بس', $mw !== null && str_contains($mw, 'role:admin,accountant'), (string) $mw);
} catch (Throwable $e) {
    $fail++;
    echo "\n💥 " . get_class($e) . ': ' . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "CASH TRANSFER: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
