<?php
/**
 * 🧹 تنضيف ما قبل الإطلاق الرسمي.
 *
 * ═══ القرار (صاحب النظام 2026-08-30) ═══
 * «احذف أي شيء في البرنامج من أوردرات قديمة وأي شغل قديم، لا تترك سوى
 *  الحسابات والمستخدمين الموجودين حاليًا — ولكن بحذر».
 * وبالتفصيل: سيب مستخدمي النظام (حقيقيين)، وامسح الأرقام والأوردرات
 * والمحلات والعملاء (كلهم وهميين)، وصفّر كل الأرصدة، واحذف خزنة «تجريبي».
 *
 * ═══ الأمان ═══
 *  • بيشتغل **جافّ** افتراضيًا: بيعدّ ويطبع بس. المسح محتاج --execute.
 *  • كل المسح جوه معاملة واحدة — أي خطأ بيرجّع كل حاجة.
 *  • الترتيب بيحترم المفاتيح الأجنبية (الأبناء الأول).
 *  • بعد المسح بيتأكد إن مافيش صفوف يتيمة ولا رصيد فاضل.
 *
 * التشغيل:
 *   php ops/reset_for_launch.php              ← معاينة بس
 *   php ops/reset_for_launch.php --execute    ← تنفيذ فعلي
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$EXECUTE = in_array('--execute', $argv, true);

/* ══ اللي بيتساب ══ */
$KEEP = [
    'users' => 'حسابات الدخول — المطلوب صراحةً',
    'user_page_permissions' => 'صلاحيات الصفحات لنفس الحسابات',
    'user_app_permissions'  => 'صلاحيات التطبيق لنفس الحسابات',
    'pilots'   => 'الطيارين — ليهم ١٤ حساب دخول، فهم مستخدمين',
    'branches' => 'الفروع — إعداد مش شغل',
    'zones' => 'المناطق وأسعارها — ٤١٧ منطقة، دي خريطة التسعير',
    'branch_areas' => 'ربط المناطق بالفروع',
    'egypt_cities' => 'مرجع ثابت', 'egypt_governorates' => 'مرجع ثابت',
    'site_settings' => 'إعدادات الموقع', 'site_partners' => 'شركاء الموقع',
    'admin_emails' => 'إيميلات التنبيه',
    'cash_stores' => 'الخزن نفسها (بأرصدة مصفّرة، وخزنة «تجريبي» بتتحذف)',
];

/* ══ اللي بيتمسح — بالترتيب: الأبناء قبل الآباء ══ */
$WIPE = [
    // أبناء الأوردر
    'order_images', 'order_deliveries', 'order_notifications', 'order_ratings',
    'order_transfers', 'order_urges', 'party_ratings', 'cc_complaints',
    'cc_zone_requests', 'customer_push_events', 'notifications',
    'pilot_commission_adjustments', 'pilot_return_requests',
    // الأوردرات نفسها (split_from_id بيشاور على نفسه — بنمسح مرة واحدة)
    'orders', 'order_counters',
    // الورديات
    'shift_branch_history', 'shifts',
    // الحضور والطلبات
    'attendance_sessions', 'pilot_shift_requests', 'pilot_leave_requests',
    'pilot_support_responses', 'pilot_support_requests',
    'pilot_day_perms', 'pilot_day_entries', 'pilot_monthly_closeouts',
    'pilot_deferred_payments', 'pilot_deferred_advances',
    /* اتضافوا 2026-09-01 (يوم الإطلاق): كانوا ناقصين من القائمة الأصلية.
       pilot_join_requests فيه ٤ طلبات تجربة «معتمدة» — نفس عيلة طلبات
       الطيار اللي فوق، وغيابه كان بيسيبها ظاهرة في شاشة الانضمام يوم
       الإطلاق. التلاتة الباقيين فاضيين دلوقتي بس عملياتيين في وحدات
       نشطة — بنضيفهم عشان لو اتعمل اختبار تاني النهاردة مايفضلش أثر.
       الأربعة أوراق (مافيش جدول ابن) وبيشاوروا على جداول محفوظة بس. */
    'pilot_join_requests', 'pilot_transfers', 'pilot_month_locks',
    // الفلوس
    'cash_transactions', 'custody_transactions', 'wallet_transactions', 'wallets',
    'expenses',
    // دفتر العملاء (وهمي)
    'store_contacts', 'receivers', 'senders',
    // عملاء التطبيق (تجارب)
    'customer_saved_receivers', 'customer_addresses',
    'customer_push_subscriptions', 'customers',
    // سجلات
    'lookup_log', 'login_attempts', 'error_alerts', 'contact_messages',
];

/* ══ أرصدة لازم تتصفّر مع الحركات ══ */
$ZERO = [
    ['pilots', 'custody_balance', 'عهدة الطيارين'],
    ['cash_stores', 'balance', 'أرصدة الخزن'],
];

$exists = fn (string $t): bool => count(DB::select(
    'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
    [DB::connection()->getDatabaseName(), $t])) > 0;

echo "\n" . ($EXECUTE ? "🔴 وضع التنفيذ" : "👁 معاينة بس — مافيش أي مسح") . "\n";
echo str_repeat('═', 60) . "\n";

echo "\n══ هيتساب ══\n";
foreach ($KEEP as $t => $why) {
    if (! $exists($t)) { printf("  ⚠️  %-24s (الجدول مش موجود)\n", $t); continue; }
    printf("  ✔ %-24s %6d صف   — %s\n", $t, DB::table($t)->count(), $why);
}

echo "\n══ هيتمسح ══\n";
$total = 0; $plan = [];
foreach ($WIPE as $t) {
    if (! $exists($t)) continue;
    $c = DB::table($t)->count();
    if ($c === 0) continue;
    $plan[$t] = $c; $total += $c;
    printf("  ✖ %-30s %6d صف\n", $t, $c);
}
printf("\n  الإجمالي: %d صف من %d جدول\n", $total, count($plan));

echo "\n══ أرصدة هتتصفّر ══\n";
foreach ($ZERO as [$t, $col, $label]) {
    if (! $exists($t)) continue;
    printf("  ⊘ %-22s %s\n", $label, DB::table($t)->sum($col));
}
$testStore = DB::table('cash_stores')->where('name', 'like', '%تجريبي%')->first();
echo "  ⊘ خزنة «تجريبي»            " . ($testStore ? "هتتحذف (رصيدها {$testStore->balance})" : "مش موجودة") . "\n";

if (! $EXECUTE) {
    echo "\n" . str_repeat('═', 60) . "\n";
    echo "معاينة خلصت — مافيش حاجة اتغيّرت.\n";
    echo "للتنفيذ:  php ops/reset_for_launch.php --execute\n";
    exit(0);
}

/* ══════════ التنفيذ ══════════ */
echo "\n" . str_repeat('═', 60) . "\n🔴 بيتنفّذ…\n\n";

DB::transaction(function () use ($plan, $ZERO, $testStore, $exists) {
    /* orders.split_from_id بيشاور على نفس الجدول (أوردر منقسم عن أوردر
       أصله). `DELETE FROM orders` بيقع على قيد FK ذاتي لأن الصف الأب
       بيتحذف والابن لسه بيشاور عليه. بنفكّ الإشارة الأول — كلهم بيتحذفوا
       تحت على أي حال. (اتكشف وقت أول تنفيذ فعلي 2026-09-01؛ التعليق
       القديم «بنمسح مرة واحدة» مكانش كفاية للـFK الذاتي.) */
    if (isset($plan['orders'])) {
        $unlinked = DB::table('orders')->whereNotNull('split_from_id')->update(['split_from_id' => null]);
        if ($unlinked) printf("  ↝ %-30s %6d إشارة انقسام اتفكّت\n", 'orders.split_from_id', $unlinked);
    }
    foreach (array_keys($plan) as $t) {
        $n = DB::table($t)->delete();
        printf("  ✖ %-30s %6d اتمسح\n", $t, $n);
    }
    foreach ($ZERO as [$t, $col, $label]) {
        if (! $exists($t)) continue;
        DB::table($t)->update([$col => 0]);
        printf("  ⊘ %-30s اتصفّر\n", $label);
    }
    /* الطيار مايبدأش الإطلاق وهو في نص وردية */
    DB::table('pilots')->update([
        'status' => null, 'queue_no' => null, 'status_since' => null,
        'break_started_at' => null, 'leave_type' => null, 'leave_reason' => null,
        'leave_forced' => 0,
    ]);
    /* والفرع الجاري يرجع للثابت — نفس اللي releasePilot بتعمله */
    DB::statement('UPDATE pilots SET assigned_branch_id = home_branch_id');
    echo "  ⊘ حالة كل الطيارين اتصفّرت ورجعوا لفروعهم\n";

    if ($testStore) {
        DB::table('cash_stores')->where('id', $testStore->id)->delete();
        echo "  ✖ خزنة «تجريبي» اتحذفت\n";
    }
});

/* ══════════ تحقق بعد المسح ══════════ */
echo "\n══ تحقق ══\n";
$bad = 0;
foreach (array_keys($plan) as $t) {
    $c = DB::table($t)->count();
    if ($c !== 0) { printf("  ✗ %-30s لسه فيه %d\n", $t, $c); $bad++; }
}
if (! $bad) echo "  ✓ كل الجداول المستهدفة فاضية\n";

foreach ($ZERO as [$t, $col, $label]) {
    $s = (float) DB::table($t)->sum($col);
    if (abs($s) > 0.001) { printf("  ✗ %s لسه %s\n", $label, $s); $bad++; }
}
if (! $bad) echo "  ✓ كل الأرصدة صفر\n";

foreach ($KEEP as $t => $_) {
    if (! $exists($t)) continue;
    printf("  ✔ %-24s %6d صف باقي\n", $t, DB::table($t)->count());
}

/* صفوف يتيمة: حساب بيشاور على طيار مش موجود مثلًا */
$orphans = [
    'حساب طيار بلا طيار' => "SELECT COUNT(*) c FROM users u WHERE u.pilot_id IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM pilots p WHERE p.id = u.pilot_id)",
    'حساب على فرع مش موجود' => "SELECT COUNT(*) c FROM users u WHERE u.branch_id IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM branches b WHERE b.id = u.branch_id)",
    'طيار على فرع مش موجود' => "SELECT COUNT(*) c FROM pilots p WHERE p.home_branch_id IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM branches b WHERE b.id = p.home_branch_id)",
    'منطقة على فرع مش موجود' => "SELECT COUNT(*) c FROM zones z WHERE z.delivery_branch_id IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM branches b WHERE b.id = z.delivery_branch_id)",
];
echo "\n══ صفوف يتيمة ══\n";
foreach ($orphans as $label => $sql) {
    $c = (int) (DB::select($sql)[0]->c ?? 0);
    if ($c) { printf("  ✗ %s: %d\n", $label, $c); $bad++; }
}
if (! $bad) echo "  ✓ مافيش\n";

echo "\n" . str_repeat('═', 60) . "\n";
echo $bad ? "🔴 {$bad} مشكلة — راجع\n" : "✅ التنضيف خلص والنظام متّسق\n";
exit($bad ? 1 : 0);
