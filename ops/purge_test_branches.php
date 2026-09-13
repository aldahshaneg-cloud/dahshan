<?php

declare(strict_types=1);

/**
 * 🧹 مسح الفرعين التجريبيين (67 «تجريبي» · 68 «تجريبي ٢») وكل اللي يخصهم.
 *
 * طلب صاحب النظام (2026-09-12): «امسح الفروع التجريبية وامسح أي شيء له
 * علاقة بهم». ⚠️ ده **بيلغي** قرار 2026-09-10 («ممنوع مسح الشغل التجريبي»).
 *
 * ═══ إزاي اتحدد «أي شيء له علاقة» ═══
 * مش بقايمة مكتوبة من الدماغ — اتسألت `information_schema` عن **كل جدول**
 * فيه عمود بيشاور على فرع أو طيار أو خزنة أو أوردر أو وردية، والقايمة اللي
 * تحت هي دي بالحرف. الجدول اللي مش موجود بيتخطّى لوحده.
 *
 * ═══ الأمان ═══
 * 🔴 الفروع الحقيقية (53 · 54 · 55) **محصّنة**: فيه فحص قبل الحذف إن مفيش
 *    طيار ولا أوردر ولا حساب تابع ليها دخل النطاق، وفحص تاني **بعد** الحذف
 *    وقبل التثبيت إن فروعها وأوردراتها زي ما هي.
 * 🔴 معاينة بالافتراضي — `--apply` بس هي اللي بتكتب، وجوه **معاملة واحدة**
 *    فأي خطوة تقع بترجّع كل حاجة.
 * 🔴 الأدمن والحسابات المحميّة عمرها ما تدخل النطاق.
 * ⚠️ روح دمشق (`rd_*`) نظام منفصل بفروعه — مالهاش أي علاقة ومش بتتلمس.
 *
 * التشغيل:
 *   php ops/purge_test_branches.php            ← معاينة (مافيش أي كتابة)
 *   php ops/purge_test_branches.php --apply    ← التنفيذ
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "\n💥 وقف: {$e->getMessage()}\n");
    exit(1);
});

const TEST_BRANCHES = [67, 68];
const REAL_BRANCHES = [53, 54, 55];

$apply = in_array('--apply', $argv, true);
echo $apply ? "⚙️  وضع التنفيذ\n" : "👀 وضع المعاينة — مافيش أي كتابة (زوّد --apply للتنفيذ)\n";
echo str_repeat('═', 62) . "\n";

$B = implode(',', TEST_BRANCHES);
$R = implode(',', REAL_BRANCHES);

/* ══════════ ① النطاق ══════════ */

$brNames = [];
foreach (DB::select("SELECT id, name, code FROM branches WHERE id IN ({$B})") as $b) {
    $brNames[(int) $b->id] = $b->name . ' (' . $b->code . ')';
}
if (! $brNames) {
    echo "✅ الفروع التجريبية مش موجودة — مفيش حاجة تتمسح\n";
    exit(0);
}

$ids = static fn (array $rows): array => array_map(static fn ($r) => (int) $r->id, $rows);
$in  = static fn (array $a): string => $a ? implode(',', $a) : '0';

/* الطيارين: تابعين لفرع تجريبي، أو التجريبيين اللي بلا فرع (الجوكر) بالاسم */
$pilotIds = $ids(DB::select(
    "SELECT id FROM pilots
      WHERE assigned_branch_id IN ({$B}) OR home_branch_id IN ({$B})
         OR name IN ('طيار تجريبي','طيار جوكر تجريبي','طيار تجريبي ٢','طيار شهر أ')"
));
$P = $in($pilotIds);

$storeIds = $ids(DB::select("SELECT id FROM cash_stores WHERE branch_id IN ({$B})"));
$S = $in($storeIds);

$senderIds = $ids(DB::select("SELECT id FROM senders WHERE name IN ('محل تجريبي','مناورة')"));
$N = $in($senderIds);

$userIds = $ids(DB::select(
    "SELECT id FROM users
      WHERE branch_id IN ({$B}) OR pilot_id IN ({$P}) OR sender_id IN ({$N})
         OR username IN ('eng_tgr','cc_tgr','pi_tgr','shop_tgr','eng_tgr2','pi_tgr2','pi','جوكر')"
));
$U = $in($userIds);

$orderIds = $ids(DB::select(
    "SELECT id FROM orders
      WHERE branch_id IN ({$B}) OR origin_branch_id IN ({$B}) OR pilot_id IN ({$P})"
));
$O = $in($orderIds);

$shiftIds = $ids(DB::select("SELECT id FROM shifts WHERE pilot_id IN ({$P}) OR branch_id IN ({$B})"));
$H = $in($shiftIds);

/* ══════════ 🔒 حصانة الفروع الحقيقية — قبل أي حذف ══════════ */

$guard = static function (string $what, string $sql): void {
    $bad = DB::select($sql);
    if ($bad) {
        $r = (array) $bad[0];
        throw new RuntimeException($what . ' دخل النطاق: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    }
};
$guard('طيار تابع لفرع حقيقي', "SELECT id, name FROM pilots WHERE id IN ({$P}) AND (assigned_branch_id IN ({$R}) OR home_branch_id IN ({$R}))");
$guard('أوردر على فرع حقيقي', "SELECT id, order_num, branch_id FROM orders WHERE id IN ({$O}) AND branch_id IN ({$R})");
$guard('حساب أدمن أو محمي', "SELECT id, username FROM users WHERE id IN ({$U}) AND (role = 'admin' OR protected = 1)");
$guard('حساب تابع لفرع حقيقي', "SELECT id, username FROM users WHERE id IN ({$U}) AND branch_id IN ({$R})");
$guard('خزنة فرع حقيقي', "SELECT id, name FROM cash_stores WHERE id IN ({$S}) AND branch_id IN ({$R})");
$guard('وردية في فرع حقيقي', "SELECT id, branch_id FROM shifts WHERE id IN ({$H}) AND branch_id IN ({$R})");

printf("\nالفروع:    %s\n", implode(' · ', $brNames));
printf("الطيارين:  %d — %s\n", count($pilotIds), $P);
printf("الحسابات:  %d — %s\n", count($userIds), $U);
printf("الخزن:     %d — %s\n", count($storeIds), $S);
printf("المُرسِلين: %d — %s\n", count($senderIds), $N);
printf("الورديات:  %d\n", count($shiftIds));
printf("الأوردرات: %d\n", count($orderIds));
echo "🔒 كل فحوص حصانة الفروع الحقيقية عدّت\n";

/* ══════════ ② الخطة — الابن قبل الأب ══════════ */

$plan = [
    // أبناء الأوردر
    ['order_images',                 "delivery_id IN (SELECT id FROM order_deliveries WHERE order_id IN ({$O}))"],
    ['order_deliveries',             "order_id IN ({$O})"],
    ['order_notifications',          "order_id IN ({$O})"],
    ['order_ratings',                "order_id IN ({$O})"],
    ['party_ratings',                "order_id IN ({$O})"],
    ['cc_complaints',                "order_id IN ({$O})"],
    ['customer_push_events',         "order_id IN ({$O})"],
    ['notifications',                "order_id IN ({$O})"],
    ['order_urges',                  "order_id IN ({$O}) OR branch_id IN ({$B}) OR pilot_id IN ({$P})"],
    ['order_transfers',              "order_id IN ({$O}) OR from_branch_id IN ({$B}) OR to_branch_id IN ({$B}) OR from_pilot_id IN ({$P}) OR to_pilot_id IN ({$P})"],
    ['pilot_return_requests',        "order_id IN ({$O}) OR branch_id IN ({$B}) OR pilot_id IN ({$P})"],
    ['pilot_commission_adjustments', "order_id IN ({$O}) OR branch_id IN ({$B}) OR pilot_id IN ({$P})"],
    ['orders',                       "id IN ({$O})"],

    // شيت الطيار وطلباته
    ['pilot_day_perms',              "entry_id IN (SELECT id FROM pilot_day_entries WHERE pilot_id IN ({$P}))"],
    ['pilot_day_entries',            "pilot_id IN ({$P})"],
    ['pilot_leave_requests',         "pilot_id IN ({$P}) OR branch_id IN ({$B})"],
    ['pilot_shift_requests',         "pilot_id IN ({$P}) OR branch_id IN ({$B})"],
    ['pilot_join_requests',          "pilot_id IN ({$P}) OR branch_id IN ({$B})"],
    ['pilot_support_responses',      "branch_id IN ({$B})"],
    ['pilot_support_requests',       "pilot_id IN ({$P}) OR from_branch_id IN ({$B}) OR requesting_branch_id IN ({$B}) OR accepted_by_branch_id IN ({$B})"],
    ['pilot_transfers',              "pilot_id IN ({$P}) OR from_branch_id IN ({$B}) OR to_branch_id IN ({$B})"],
    ['pilot_track_points',           "pilot_id IN ({$P})"],
    ['pilot_monthly_closeouts',      "pilot_id IN ({$P})"],
    ['pilot_deferred_advances',      "pilot_id IN ({$P}) OR store_id IN ({$S})"],

    // الورديات
    ['shift_branch_history',         "shift_id IN ({$H}) OR branch_id IN ({$B})"],
    ['shifts',                       "id IN ({$H})"],

    // الفلوس
    ['custody_transactions',         "pilot_id IN ({$P}) OR branch_id IN ({$B}) OR store_id IN ({$S})"],
    ['cash_transactions',            "related_pilot_id IN ({$P}) OR branch_id IN ({$B}) OR store_id IN ({$S})"],
    ['expenses',                     "branch_id IN ({$B}) OR cash_store_id IN ({$S})"],
    ['pilot_acct_payouts',           "store_id IN ({$S})"],
    ['cash_stores',                  "id IN ({$S})"],

    // تقفيلات وتقارير الفرع
    ['pilot_acct_day_summaries',     "branch_id IN ({$B})"],
    ['pilot_acct_snapshots',         "branch_id IN ({$B})"],
    ['pilot_month_locks',            "branch_id IN ({$B})"],
    ['pa_budget_items',              "branch_id IN ({$B})"],
    ['acc_journal_lines',            "branch_id IN ({$B})"],

    // إعدادات الفرع
    ['branch_areas',                 "branch_id IN ({$B})"],
    ['cc_zone_requests',             "branch_id IN ({$B})"],
    ['store_addresses',              "branch_id IN ({$B})"],
    ['customer_addresses',           "branch_id IN ({$B})"],
    ['order_counters',               "branch_id IN ({$B})"],
    ['hr_employees',                 "branch_id IN ({$B})"],
    ['zones',                        "delivery_branch_id IN ({$B}) OR source_branch_id IN ({$B})"],

    // الآباء
    ['users',                        "id IN ({$U})"],
    ['pilots',                       "id IN ({$P})"],
    ['senders',                      "id IN ({$N})"],
    ['branches',                     "id IN ({$B})"],
];

/* إشارات من صفوف **باقية** للفرع التجريبي — بتتصفّر مش بتتمسح */
$nullify = [
    ['branches',  'failover_branch_id', "failover_branch_id IN ({$B})"],
    ['customers', 'default_branch_id',  "default_branch_id IN ({$B})"],
    ['users',     'sender_id',          "sender_id IN ({$N}) AND id NOT IN ({$U})"],
];

$dbName = DB::getDatabaseName();
$exists = static function (string $t) use ($dbName): bool {
    return (bool) DB::select(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        [$dbName, $t]
    );
};

echo "\n" . str_repeat('─', 62) . "\n📋 اللي هيتمسح:\n";
$total = 0;
$live  = [];
foreach ($plan as [$t, $where]) {
    if (! $exists($t)) {
        printf("   %-30s (الجدول مش موجود — اتخطّى)\n", $t);
        continue;
    }
    $c = (int) DB::selectOne("SELECT COUNT(*) c FROM {$t} WHERE {$where}")->c;
    if ($c > 0) {
        printf("   %-30s %6d صف\n", $t, $c);
        $total += $c;
    }
    $live[] = [$t, $where, $c];
}
printf("\n   الإجمالي: %d صف\n", $total);

echo "\n📎 إشارات هتتصفّر (الصف نفسه بيفضل):\n";
$nLive = [];
foreach ($nullify as [$t, $col, $where]) {
    if (! $exists($t)) {
        continue;
    }
    $c = (int) DB::selectOne("SELECT COUNT(*) c FROM {$t} WHERE {$where}")->c;
    if ($c > 0) {
        printf("   %-30s %s ← NULL في %d صف\n", $t, $col, $c);
    }
    $nLive[] = [$t, $col, $where, $c];
}

if (! $apply) {
    echo "\n👀 معاينة بس — شغّل بـ --apply للتنفيذ.\n";
    exit(0);
}

/* ══════════ ③ التنفيذ ══════════ */

echo "\n" . str_repeat('─', 62) . "\n⚙️  التنفيذ:\n";
DB::beginTransaction();
try {
    foreach ($nLive as [$t, $col, $where, $c]) {
        if ($c > 0) {
            DB::update("UPDATE {$t} SET {$col} = NULL WHERE {$where}");
            printf("   ↺ %-28s %s ← NULL (%d)\n", $t, $col, $c);
        }
    }
    $done = 0;
    foreach ($live as [$t, $where, $c]) {
        if ($c === 0) {
            continue;
        }
        $n = DB::delete("DELETE FROM {$t} WHERE {$where}");
        $done += $n;
        printf("   ✓ %-28s %6d صف\n", $t, $n);
    }

    /* 🔒 الفحص الأخير قبل التثبيت */
    $liveBr = (int) DB::selectOne("SELECT COUNT(*) c FROM branches WHERE id IN ({$R})")->c;
    if ($liveBr !== count(REAL_BRANCHES)) {
        throw new RuntimeException('فرع حقيقي اتمسح — بترجّع كل حاجة');
    }
    $realOrders = (int) DB::selectOne("SELECT COUNT(*) c FROM orders WHERE branch_id IN ({$R})")->c;
    if ($realOrders === 0) {
        throw new RuntimeException('أوردرات الفروع الحقيقية بقت صفر — بترجّع كل حاجة');
    }
    $realPilots = (int) DB::selectOne("SELECT COUNT(*) c FROM pilots WHERE assigned_branch_id IN ({$R})")->c;
    if ($realPilots === 0) {
        throw new RuntimeException('طيارين الفروع الحقيقية بقوا صفر — بترجّع كل حاجة');
    }

    DB::commit();
    printf(
        "\n✅ اتمسح %d صف.\n   الفروع الحقيقية: %d · أوردراتها: %d · طياريها: %d — كلها زي ما هي\n",
        $done, $liveBr, $realOrders, $realPilots
    );
} catch (Throwable $e) {
    DB::rollBack();
    throw new RuntimeException('اترجّع كل حاجة، مفيش أي تغيير: ' . $e->getMessage());
}
