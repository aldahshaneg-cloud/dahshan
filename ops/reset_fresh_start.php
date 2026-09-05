<?php
/**
 * 🧹 بداية جديدة — مسح شغل الأيام الأولى والبدء من النهارده.
 *
 * ═══ القرار (صاحب النظام 2026-09-05) ═══
 * «الأيام اللي فاتت كانت غير جيدة بالنسبة للحسابات: احذف الـ٤ أيام ونبدأ من
 *  النهارده — لكن لا تحذف العملاء ولا الموظفين ولا الطيارين ولا سجل الحضور،
 *  احذف الأوردرات اللي حصلت فقط. خذ نسخة احتياطية. واحذف الفرع التجريبي،
 *  والفروع الـ٣ اجعل في كل فرع ٣٠,٠٠٠ عهدة في خزنته بتحويل من خزنة الإدارة».
 *
 * ═══ اللي بيتساب ═══
 *  العملاء (customers/senders/receivers/store_contacts والمحافظ) · المستخدمين ·
 *  الطيارين (ما عدا «طيار تجريبي» بتاع الفرع التجريبي) · سجل الحضور: الورديات
 *  (shifts + shift_branch_history) وجلسات الحضور والإجازات — بس **فلوس** الوردية
 *  (حافز/خصم/سلفة/عمولة مصروفة/عهدة) بتتصفّر لأن مصدرها اتمسح.
 *  المناطق والأسعار · الإعدادات · الميزانية.
 *
 * ═══ اللي بيتمسح ═══
 *  الأوردرات وكل أبنائها · عدّادات الأوردرات (الترقيم يبدأ من ١) · كل حركات
 *  الخزن والعهدة والمصروفات · تعديلات التقفيل اليدوية (pilot_day_entries) ·
 *  عمولات التقفيل · الفرع التجريبي بكل ما يخصه (خزنته، منطقتيه، مشرفه،
 *  الطيار التجريبي وحسابه، ورديته).
 *
 * ═══ بعد المسح ═══
 *  كل الخزن صفر ← خزنة «الاداره» رصيد افتتاحي ٩٠,٠٠٠ ← تحويل ٣٠,٠٠٠ لخزنة كل
 *  فرع من التلاتة (نفس شكل حركات التحويل في البرنامج: out «تحويل إلى» + in
 *  «تحويل من»). النتيجة: كل فرع ٣٠,٠٠٠ وخزنة الإدارة صفر — صاحب النظام يكتب
 *  رصيدها الحقيقي من شاشة الخزنة.
 *
 * ═══ الأمان ═══
 *  • جافّ افتراضيًا (بيعدّ ويطبع). التنفيذ محتاج --execute.
 *  • كل حاجة جوه معاملة واحدة — أي خطأ بيرجّع الكل.
 *  • تحقق بعد التنفيذ: الجداول المستهدفة فاضية، الأرصدة زي المطلوب، مافيش
 *    صفوف يتيمة، والمحفوظ عدده زي ما كان.
 *
 * التشغيل:  php ops/reset_fresh_start.php            ← معاينة
 *           php ops/reset_fresh_start.php --execute  ← تنفيذ (بعد باك أب)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\WireTime;
use Illuminate\Support\Facades\DB;

$EXECUTE = in_array('--execute', $argv, true);
$TEST_BRANCH_NAME = 'تجريبي';
$OPENING = 90000.0;
$PER_BRANCH = 30000.0;
$ADMIN_STORE_NAME = 'الاداره';

$exists = fn (string $t): bool => count(DB::select(
    'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
    [DB::connection()->getDatabaseName(), $t])) > 0;
$count = fn (string $t): int => $exists($t) ? (int) DB::table($t)->count() : 0;

/* ══ الفرع التجريبي وما يخصه ══ */
$testBranch = DB::table('branches')->where('name', $TEST_BRANCH_NAME)->first();
$tb = $testBranch ? (int) $testBranch->id : 0;
$testPilots = $tb ? DB::table('pilots')->where('assigned_branch_id', $tb)->orWhere('home_branch_id', $tb)->pluck('id')->map(fn ($v) => (int) $v)->all() : [];
$testUsers  = $tb ? DB::table('users')->where('branch_id', $tb)->orWhereIn('pilot_id', $testPilots ?: [0])->pluck('id')->map(fn ($v) => (int) $v)->all() : [];
$testZones  = $tb ? DB::table('zones')->where('delivery_branch_id', $tb)->pluck('id')->map(fn ($v) => (int) $v)->all() : [];
$testStores = $tb ? DB::table('cash_stores')->where('branch_id', $tb)->pluck('id')->map(fn ($v) => (int) $v)->all() : [];

/* ══ الخزن ══ */
$adminStore = DB::table('cash_stores')->where('name', $ADMIN_STORE_NAME)->whereNull('branch_id')->first();
$branchStores = $tb
    ? DB::table('cash_stores')->whereNotNull('branch_id')->where('branch_id', '<>', $tb)->orderBy('id')->get()
    : DB::table('cash_stores')->whereNotNull('branch_id')->orderBy('id')->get();
$branchNames = DB::table('branches')->pluck('name', 'id');

/* ══ اللي بيتمسح كله — أبناء قبل آباء ══ */
$WIPE = [
    'order_images', 'order_deliveries', 'order_notifications', 'order_ratings', 'order_transfers',
    'order_urges', 'party_ratings', 'cc_complaints', 'cc_zone_requests', 'customer_push_events',
    'notifications', 'pilot_commission_adjustments', 'pilot_return_requests',
    'orders', 'order_counters',
    'cash_transactions', 'custody_transactions', 'expenses', 'wallet_transactions',
    'pilot_day_perms', 'pilot_day_entries', 'pilot_acct_day_summaries', 'pilot_acct_payouts',
    'pilot_acct_snapshots', 'pilot_monthly_closeouts', 'pilot_month_locks',
    'pilot_deferred_payments', 'pilot_deferred_advances',
    'acc_journal_lines', 'acc_journal_entries', 'acc_post_log', 'acc_posted_refs',
    'staff_day_perms', 'staff_day_entries',
];
$KEEP = ['users', 'pilots', 'customers', 'senders', 'receivers', 'store_contacts', 'wallets',
         'shifts', 'shift_branch_history', 'attendance_sessions', 'hr_attendance', 'pilot_leave_requests',
         'zones', 'branch_areas', 'branches', 'cash_stores', 'pa_budget_items'];

echo "\n" . ($EXECUTE ? '🔴 وضع التنفيذ' : '👁 معاينة بس — مافيش أي مسح') . "\n" . str_repeat('═', 60) . "\n";
echo "\n══ هيتمسح ══\n";
$plan = []; $total = 0;
foreach ($WIPE as $t) {
    $c = $count($t);
    if ($c) { $plan[$t] = $c; $total += $c; printf("  ✖ %-30s %6d صف\n", $t, $c); }
}
printf("  الإجمالي: %d صف من %d جدول\n", $total, count($plan));

echo "\n══ الفرع التجريبي ══\n";
if ($tb) {
    printf("  ✖ الفرع #%d «%s»\n", $tb, $testBranch->name);
    printf("  ✖ طيارين: %s · حسابات: %s · مناطق: %s · خزن: %s\n",
        json_encode($testPilots), json_encode($testUsers), json_encode($testZones), json_encode($testStores));
    printf("  ✖ ورديات على الفرع/الطيار: %d\n", DB::table('shifts')->where('branch_id', $tb)->orWhereIn('pilot_id', $testPilots ?: [0])->count());
} else {
    echo "  (مش موجود)\n";
}

echo "\n══ الخزن بعد التنفيذ ══\n";
if (! $adminStore) { echo "  ✗ مافيش خزنة «{$ADMIN_STORE_NAME}» للإدارة — وقف\n"; exit(1); }
printf("  الإدارة: «%s» #%d — رصيد افتتاحي %s ثم يخرج منها %s\n", $adminStore->name, $adminStore->id, number_format($OPENING), number_format($PER_BRANCH * count($branchStores)));
foreach ($branchStores as $st) {
    printf("  فرع «%s»: «%s» #%d — من %s إلى %s\n", $branchNames[$st->branch_id] ?? '?', $st->name, $st->id, number_format((float) $st->balance, 2), number_format($PER_BRANCH));
}
if (abs($OPENING - $PER_BRANCH * count($branchStores)) > 0.001) {
    printf("  ⚠️ الافتتاحي %s مش مساوي لمجموع التحويلات %s — الإدارة هتفضل %s\n", number_format($OPENING), number_format($PER_BRANCH * count($branchStores)), number_format($OPENING - $PER_BRANCH * count($branchStores)));
}

echo "\n══ هيتساب (عدد الصفوف دلوقتي) ══\n";
$before = [];
foreach ($KEEP as $t) { $before[$t] = $count($t); printf("  ✔ %-24s %6d\n", $t, $before[$t]); }
$shiftsBefore = $count('shifts');

if (! $EXECUTE) {
    echo "\n" . str_repeat('═', 60) . "\nمعاينة خلصت — مافيش حاجة اتغيّرت.\nللتنفيذ:  php ops/reset_fresh_start.php --execute\n";
    exit(0);
}

/* ══════════ التنفيذ ══════════ */
echo "\n" . str_repeat('═', 60) . "\n🔴 بيتنفّذ…\n\n";
$adminUser = (string) (DB::table('users')->where('role', 'admin')->where('blocked', 0)->orderBy('id')->value('username') ?? 'admin');
$now = WireTime::nowDb();

DB::transaction(function () use ($plan, $tb, $testPilots, $testUsers, $testZones, $testStores, $adminStore, $branchStores, $OPENING, $PER_BRANCH, $adminUser, $now, $exists) {
    /* ١) الأوردرات وأبناؤها وكل الفلوس */
    if (isset($plan['orders'])) {
        DB::table('orders')->whereNotNull('split_from_id')->update(['split_from_id' => null]);
    }
    foreach (array_keys($plan) as $t) {
        printf("  ✖ %-30s %6d اتمسح\n", $t, DB::table($t)->delete());
    }

    /* ٢) فلوس الورديات تتصفّر — الورديات نفسها (الحضور) بتفضل */
    $n = DB::table('shifts')->update([
        'bonus_amount' => 0, 'bonus_reason' => null, 'deduction_amount' => 0, 'deduction_reason' => null,
        'advance_amount' => 0, 'advance_reason' => null, 'commission_paid_amount' => 0, 'commission_paid_at' => null,
        'custody_returned' => 0, 'custody_carried' => 0,
        'commission_settle' => 'monthly', 'bonus_settle' => 'monthly', 'deduction_settle' => 'monthly', 'advance_settle' => 'monthly',
    ]);
    echo "  ⊘ فلوس {$n} وردية اتصفّرت (الحضور باقي)\n";

    /* ٣) الفرع التجريبي */
    if ($tb) {
        $shiftIds = DB::table('shifts')->where('branch_id', $tb)->orWhereIn('pilot_id', $testPilots ?: [0])->pluck('id')->all();
        if ($shiftIds) {
            DB::table('shift_branch_history')->whereIn('shift_id', $shiftIds)->delete();
            DB::table('shift_branch_history')->where('branch_id', $tb)->delete();
            DB::table('shifts')->whereIn('id', $shiftIds)->delete();
        }
        foreach (['attendance_sessions', 'pilot_leave_requests', 'pilot_shift_requests', 'pilot_join_requests', 'pilot_transfers',
                  'pilot_support_requests'] as $t) {
            if ($exists($t) && $testPilots) { DB::table($t)->whereIn('pilot_id', $testPilots)->delete(); }
        }
        foreach (['pilot_leave_requests', 'pilot_shift_requests', 'pilot_join_requests', 'pilot_support_responses'] as $t) {
            if ($exists($t)) { DB::table($t)->where('branch_id', $tb)->delete(); }
        }
        if ($testUsers) {
            foreach (['user_app_permissions', 'user_page_permissions', 'pilot_acct_perms', 'staff_day_entries'] as $t) {
                if ($exists($t)) { DB::table($t)->whereIn('user_id', $testUsers)->delete(); }
            }
            DB::table('users')->whereIn('id', $testUsers)->delete();
        }
        if ($testPilots) { DB::table('pilots')->whereIn('id', $testPilots)->delete(); }
        DB::table('branch_areas')->where('branch_id', $tb)->delete();
        if ($testZones) {
            DB::table('zones')->whereIn('id', $testZones)->delete();
        }
        if ($testStores) { DB::table('cash_stores')->whereIn('id', $testStores)->delete(); }
        DB::table('order_counters')->where('branch_id', $tb)->delete();
        DB::table('branches')->where('id', $tb)->delete();
        echo "  ✖ الفرع التجريبي #{$tb} اتحذف بكل ما يخصه\n";
    }

    /* ٤) الطيارين: العهدة صفر والحالة صفر ورجوع لفرعه الثابت */
    DB::table('pilots')->update(['custody_balance' => 0, 'status' => null, 'queue_no' => null, 'status_since' => null,
        'break_started_at' => null, 'leave_type' => null, 'leave_reason' => null, 'leave_forced' => 0]);
    DB::statement('UPDATE pilots SET assigned_branch_id = home_branch_id');
    echo "  ⊘ عهدة وحالة الطيارين اتصفّرت\n";

    /* ٥) الخزن: صفر ← افتتاحي للإدارة ← ٣٠,٠٠٠ لكل فرع */
    DB::table('cash_stores')->update(['balance' => 0]);
    $txn = function (int $storeId, string $type, float $amount, string $reason, ?int $branchId) use ($adminUser, $now): void {
        DB::table('cash_transactions')->insert(['store_id' => $storeId, 'type' => $type, 'amount' => $amount, 'reason' => mb_substr($reason, 0, 190),
            'branch_id' => $branchId, 'created_by' => $adminUser, 'created_at' => $now]);
        DB::statement('UPDATE cash_stores SET balance = balance ' . ($type === 'in' ? '+' : '-') . ' ? WHERE id = ?', [$amount, $storeId]);
    };
    $txn((int) $adminStore->id, 'in', $OPENING, 'رصيد افتتاحي — بداية جديدة ' . substr($now, 0, 10), null);
    foreach ($branchStores as $st) {
        $txn((int) $adminStore->id, 'out', $PER_BRANCH, 'تحويل إلى «' . $st->name . '» — عهدة بداية جديدة', null);
        $txn((int) $st->id, 'in', $PER_BRANCH, 'تحويل من «' . $adminStore->name . '» — عهدة بداية جديدة', (int) $st->branch_id);
    }
    echo "  💰 الافتتاحي والتحويلات اتسجّلت\n";
});

/* ══════════ تحقق ══════════ */
echo "\n══ تحقق ══\n";
$bad = 0;
foreach (array_keys($plan) as $t) {
    if ($t === 'cash_transactions') { continue; }
    $c = $count($t);
    if ($c) { printf("  ✗ %-30s لسه فيه %d\n", $t, $c); $bad++; }
}
$txnN = $count('cash_transactions');
$expectedTxn = 1 + 2 * count($branchStores);
if ($txnN !== $expectedTxn) { printf("  ✗ cash_transactions فيه %d بدل %d\n", $txnN, $expectedTxn); $bad++; }
foreach ($branchStores as $st) {
    $b = (float) DB::table('cash_stores')->where('id', $st->id)->value('balance');
    if (abs($b - $PER_BRANCH) > 0.001) { printf("  ✗ خزنة «%s» رصيدها %s\n", $st->name, $b); $bad++; }
}
$ab = (float) DB::table('cash_stores')->where('id', $adminStore->id)->value('balance');
if (abs($ab - ($OPENING - $PER_BRANCH * count($branchStores))) > 0.001) { printf("  ✗ خزنة الإدارة رصيدها %s\n", $ab); $bad++; }
if ($tb && DB::table('branches')->where('id', $tb)->exists()) { echo "  ✗ الفرع التجريبي لسه موجود\n"; $bad++; }
if ((float) DB::table('pilots')->sum('custody_balance') != 0.0) { echo "  ✗ عهدة على طيارين\n"; $bad++; }
$orphans = [
    'حساب طيار بلا طيار' => 'SELECT COUNT(*) c FROM users u WHERE u.pilot_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM pilots p WHERE p.id = u.pilot_id)',
    'حساب على فرع مش موجود' => 'SELECT COUNT(*) c FROM users u WHERE u.branch_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM branches b WHERE b.id = u.branch_id)',
    'طيار على فرع مش موجود' => 'SELECT COUNT(*) c FROM pilots p WHERE NOT EXISTS (SELECT 1 FROM branches b WHERE b.id = p.home_branch_id)',
    'منطقة على فرع مش موجود' => 'SELECT COUNT(*) c FROM zones z WHERE NOT EXISTS (SELECT 1 FROM branches b WHERE b.id = z.delivery_branch_id)',
    'وردية على فرع مش موجود' => 'SELECT COUNT(*) c FROM shifts s WHERE NOT EXISTS (SELECT 1 FROM branches b WHERE b.id = s.branch_id)',
    'خزنة على فرع مش موجود' => 'SELECT COUNT(*) c FROM cash_stores s WHERE s.branch_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM branches b WHERE b.id = s.branch_id)',
];
foreach ($orphans as $label => $sql) {
    $c = (int) (DB::select($sql)[0]->c ?? 0);
    if ($c) { printf("  ✗ %s: %d\n", $label, $c); $bad++; }
}
echo "\n══ المحفوظ (قبل ← بعد) ══\n";
foreach ($KEEP as $t) { printf("  ✔ %-24s %6d ← %6d\n", $t, $before[$t], $count($t)); }
printf("  الورديات: %d ← %d (اتحذفت ورديات الفرع التجريبي بس)\n", $shiftsBefore, $count('shifts'));
echo "\n" . str_repeat('═', 60) . "\n" . ($bad ? "✗ فيه {$bad} مشكلة — راجع فوق\n" : "✅ تم — بداية جديدة\n");
exit($bad ? 1 : 0);
