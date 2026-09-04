<?php

declare(strict_types=1);

/**
 * ✂️💰 نموذج عملي حيّ — «تفريق الطرود بعد ما العميل دفع من محفظته».
 *
 * ═══ الفرق بين الملف ده و`ops/test_split_wallet.php` ═══
 * الاختبار بيلف جوه معاملة بترجع، فمفيش أثر يفضل. الملف ده **بيثبّت**
 * الصفوف في القاعدة عمدًا عشان تتفرّج عليها بنفسك بعد كده — في لوحة
 * الإدارة أو بالاستعلام اللي بيطبعه في الآخر.
 *
 * بيمشي على المسارات الحقيقية بالكامل، مفيش خطوة مكتوبة بالإيد:
 *   1. عميل حقيقي بمحفظة فيها رصيد
 *   2. `SupportController::applyWallet`  ⟵ الخصم الفعلي من المحفظة
 *   3. `OrdersController::split`         ⟵ التفريق (ده اللي اتصلّح)
 *
 * وبيحسب كمان **الكود القديم كان هيعمل إيه** على نفس الأرقام، عشان
 * الفرق يبان جنب بعضه مش بالكلام.
 *
 * التشغيل: php ops/demo_split_wallet.php
 * التنضيف (لو حبيت بعدين): php ops/demo_split_wallet.php --clean
 */

use App\Http\Controllers\Api\OrdersController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/* البثّ اللحظي (Reverb) مش شغّال على جهاز التطوير، والنموذج ده مالوش
   علاقة بالبثّ أصلًا — بيقيس الفلوس. من غير السطر ده الـexception
   بتاع الاتصال بيوقف السيناريو في نصّه. */
config(["broadcasting.default" => "null"]);

const TAG = 'DEMO-SPLIT-WALLET';

/* ── التنضيف ────────────────────────────────────────────────────────── */
if (in_array('--clean', $argv, true)) {
    /* الأولاد الأول: `fk_orders_split_from_id` بيمنع حذف الأب طالما فيه
       جزء مفصول بيشاور عليه. */
    $ids = array_map(fn ($r) => (int) $r->id, DB::select(
        'SELECT id FROM orders WHERE sender_name = ?', [TAG]
    ));
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        DB::delete("DELETE FROM orders WHERE split_from_id IN ({$ph})", $ids);
        DB::delete("DELETE FROM orders WHERE id IN ({$ph})", $ids);   // الطرود بتتمسح بالـFK
    }
    $cust = DB::selectOne("SELECT id FROM customers WHERE display_name = ? LIMIT 1", [TAG]);
    if ($cust) {
        DB::delete("DELETE FROM wallets WHERE owner_type = 'customer' AND owner_id = ?", [(int) $cust->id]);
        DB::delete('DELETE FROM customers WHERE id = ?', [(int) $cust->id]);
    }
    echo "🧹 اتمسح: " . count($ids) . " أوردر" . ($cust ? " + العميل والمحفظة" : '') . "\n";
    exit(0);
}

function money(float $v): string
{
    return number_format($v, 2) . ' ج.م';
}

function line(string $ch = '─'): void
{
    echo str_repeat($ch, 66) . "\n";
}

$now = date('Y-m-d H:i:s');

/* ── 1) عميل بمحفظة فيها 50 ─────────────────────────────────────────── */
echo "\n";
line('═');
echo "  ✂️  نموذج عملي: تفريق أوردر العميل دفع جزء منه من محفظته\n";
line('═');

$branch = (array) DB::selectOne('SELECT id, name FROM branches ORDER BY id LIMIT 1');
$pilots = array_map(fn ($r) => (array) $r, DB::select('SELECT id, name FROM pilots ORDER BY id LIMIT 2'));
if (count($pilots) < 1) {
    exit("محتاج طيار واحد على الأقل في القاعدة\n");
}
$pilot = $pilots[0];
DB::update("UPDATE pilots SET status = 'waiting' WHERE id = ?", [$pilot['id']]);

$existing = DB::selectOne('SELECT id FROM customers WHERE display_name = ? LIMIT 1', [TAG]);
if ($existing) {
    $cid = (int) $existing->id;
} else {
    DB::insert(
        "INSERT INTO customers (display_name, phone1, profile_completed, created_at) VALUES (?, ?, 1, ?)",
        [TAG, '01000000099', $now]
    );
    $cid = (int) DB::getPdo()->lastInsertId();
}

DB::delete("DELETE FROM wallets WHERE owner_type = 'customer' AND owner_id = ?", [$cid]);
DB::insert(
    "INSERT INTO wallets (owner_type, owner_id, balance, created_at) VALUES ('customer', ?, 50.00, ?)",
    [$cid, $now]
);
$walletId = (int) DB::getPdo()->lastInsertId();

echo "\n👤 العميل: #{$cid}   ·   💳 رصيد المحفظة: " . money(50) . "\n";

/* ── 2) أوردر بطردين: 40 + 30 = 70 ──────────────────────────────────── */
$orderNum = 'DSW-' . date('ymd') . '-' . substr((string) time(), -4);
DB::insert(
    "INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, sender_phone,
                         status, status_since, created_at, total_delivery_price, wallet_used,
                         pieces_count, customer_id, customer_name, customer_phone,
                         added_by, added_by_role, source)
     VALUES (?,?,?,?, '01000000099', 'processing', ?, ?, 70.00, 0, 2, ?, ?, '01000000099',
             'demo', 'admin', 'customer')",
    [$orderNum, $branch['id'], $branch['id'], TAG, $now, $now, $cid, TAG]
);
$orderId = (int) DB::getPdo()->lastInsertId();

foreach ([[1, 40.0, 'المستلم الأول'], [2, 30.0, 'المستلم التاني']] as [$no, $price, $name]) {
    DB::insert(
        'INSERT INTO order_deliveries (order_id, parcel_no, receiver_name, receiver_phone,
                                       zone_price, order_price, created_at)
         VALUES (?,?,?,?,?,0,?)',
        [$orderId, $no, $name, '0100000009' . $no, $price, $now]
    );
}

echo "📦 الأوردر: {$orderNum}   ·   طردين (" . money(40) . " + " . money(30) . ") = " . money(70) . "\n";

/* ── 3) العميل يستخدم محفظته — المسار الحقيقي ───────────────────────── */
$sess = new SessionStore('demo', new ArraySessionHandler(120));
$sess->put('role', 'customer');
$sess->put('customer_id', $cid);
$req = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
$req->setLaravelSession($sess);
$req->attributes->set(ResolveApiActor::ATTRIBUTE, Actor::customer($cid, 'cust:' . $cid, TAG));

(new SupportController())->applyWallet($req, (string) $orderId);

$o0 = (array) DB::selectOne('SELECT total_delivery_price, wallet_used FROM orders WHERE id = ?', [$orderId]);
$bal = (float) DB::selectOne('SELECT balance FROM wallets WHERE id = ?', [$walletId])->balance;

echo "\n";
line();
echo "  الخطوة 1 — العميل دفع من محفظته\n";
line();
echo "  سعر التوصيل            " . money((float) $o0['total_delivery_price']) . "\n";
echo "  اتخصم من المحفظة       " . money((float) $o0['wallet_used']) . "\n";
echo "  رصيد المحفظة بقى       " . money($bal) . "\n";
echo "  ⟵ المفروض الطيار يحصّل " . money(Money::netCollect($o0)) . " كاش\n";

$expected = Money::netCollect($o0);

/* ── 4) التفريق — المسار الحقيقي ────────────────────────────────────── */
$admin = Actor::staff(1, 'demo', 'admin', null, 'مدير');
$areq  = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'],
    json_encode(['parcelNos' => [1], 'pilotId' => (int) $pilot['id']]));
$areq->attributes->set(ResolveApiActor::ATTRIBUTE, $admin);

(new OrdersController())->split($areq, (string) $orderId);

$parent = (array) DB::selectOne(
    'SELECT order_num, total_delivery_price, wallet_used FROM orders WHERE id = ?', [$orderId]
);
$child = (array) DB::selectOne(
    'SELECT order_num, total_delivery_price, wallet_used FROM orders WHERE split_from_id = ?', [$orderId]
);

echo "\n";
line();
echo "  الخطوة 2 — الفرع فرّق الطرد الأول على «{$pilot['name']}»\n";
line();
$show = function (string $label, array $o): void {
    echo "\n  ▸ {$label}   ({$o['order_num']})\n";
    echo "      سعر التوصيل        " . money((float) $o['total_delivery_price']) . "\n";
    echo "      نصيبه من المحفظة   " . money((float) $o['wallet_used']) . "\n";
    echo "      الطيار هيحصّل       " . money(Money::netCollect($o)) . "\n";
};
$show('الجزء المفصول', $child);
$show('الأصل (الطرد الباقي)', $parent);

$after   = Money::netCollect($child) + Money::netCollect($parent);
$wSum    = (float) $child['wallet_used'] + (float) $parent['wallet_used'];

/* الكود القديم على نفس الأرقام: الجزء بياخد صفر، والأصل بيحتفظ بالخصم كله */
$oldChild  = ['total_delivery_price' => (float) $child['total_delivery_price'],  'wallet_used' => 0.0];
$oldParent = ['total_delivery_price' => (float) $parent['total_delivery_price'], 'wallet_used' => 50.0];
$oldAfter  = Money::netCollect($oldChild) + Money::netCollect($oldParent);

echo "\n";
line('═');
echo "  النتيجة\n";
line('═');
/* `printf` بيعدّ البايتات مش الحروف، والعربي 2 بايت — فأي محاذاة بأعمدة
   بتطلع مكسورة. سطر واحد بسهم أوضح من جدول متزحلق. */
$row = fn (string $k, string $v) => print("  {$k}  →  {$v}\n");

$row('المفروض يتحصّل كاش (قبل التفريق)', money($expected));
$row('اتحصّل فعلًا بعد التفريق', money($after));
$row('مجموع المخصوم من المحفظة', money($wSum) . ($wSum === 50.0 ? '   ✅ زي ما كان' : ''));
echo "\n";
$row('🔴 الكود القديم كان هيحصّل', money($oldAfter));
$row('   يعني العميل كان هيدفع زيادة', money($oldAfter - $expected));
echo "\n";
echo abs($after - $expected) < 0.005
    ? "  ✅ الفلوس مظبوطة — التفريق نقل، مش تحصيل زيادة.\n"
    : "  ❌ فيه فرق: " . money($after - $expected) . "\n";

/* ── 5) الحركات في دفتر المحفظة ─────────────────────────────────────── */
echo "\n";
line();
echo "  دفتر المحفظة\n";
line();
foreach (DB::select(
    'SELECT amount, type, note, order_num, balance_after FROM wallet_transactions WHERE wallet_id = ? ORDER BY id',
    [$walletId]
) as $t) {
    echo '  ' . number_format((float) $t->amount, 2) . '  [' . $t->type . ']  '
        . ($t->note ?? $t->order_num ?? '') . '  →  الرصيد بعدها: ' . money((float) $t->balance_after) . "\n";
}

echo "\n";
line('═');
echo "  📌 البيانات دي **متسابة في القاعدة** عمدًا. تشوفها بـ:\n";
line('═');
echo "\n  SELECT order_num, total_delivery_price, wallet_used, split_from_id, pilot_name\n";
echo "    FROM orders WHERE sender_name = '" . TAG . "' ORDER BY id;\n";
echo "\n  ولو حبيت تمسحها بعدين:  php ops/demo_split_wallet.php --clean\n\n";
