<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════
   حارس «كل طرد = أوردر منفرد بذاته» (قرار صاحب النظام 2026-09-01)

   القصة: أوردر HAL-260901-001 اتعمل من الكول سنتر بطردين لعنوانين
   مختلفين، والطيار وصّلهم الاتنين واتحسبله مشوار واحد — لأن حسابات
   الطيار بتعد الأوردرات مش الطرود. القرار: السيرفر بيفرّق تلقائيًا،
   أوردر مستقل بترقيمه وفلوسه لكل طرد، في المسارين (الموظفين والعميل).

   الحارس بيقرا الكود الحقيقي مش بيحاكيه — أي رجوع لإنشاء «أوردر واحد
   بطرود متعددة» لازم يقع هنا.
═══════════════════════════════════════════════════════════════ */

/* كود خروج اختبارات PHP بيكدب: استثناء في نص السكربت = خروج بصفر
   والحارس يبان ناجح. المعالجين دول بيضمنوا إن أي انفجار = فشل صريح. */
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "💥 استثناء غير ممسوك: {$e->getMessage()}\n");
    exit(1);
});
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) {
        exit(1);
    }
});

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function ok(string $label, bool $cond, string $hint = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        echo "  ✗ 🔴 {$label}" . ($hint !== '' ? " — {$hint}" : '') . "\n";
    }
}

/* الحارس بيمسك تعليقه هو: تعليقاتي العربية بتقتبس الكود القديم نصًا،
   فأي فحص «الحاجة دي مش موجودة» لازم يشتغل على الكود بعد شيل التعليقات. */
function code(string $src): string
{
    $src = preg_replace('~/\*.*?\*/~s', '', $src);
    $src = preg_replace('~^\s*//.*$~m', '', $src);

    return preg_replace('~(?<=[;{})\s])//[^\n]*~', '', $src);
}

$oc  = file_get_contents($root . '/app/Http/Controllers/Api/OrdersController.php');
$occ = code($oc);
$ca  = file_get_contents($root . '/app/Http/Controllers/Api/CustomerAppController.php');
$cac = code($ca);

echo "══ 1) مسار الموظفين (OrdersController::store) ══\n";

/* نحصر الفحص جوه store() نفسها — عشان split() اللي بعدها فيها إنشاء
   orders مشروع (التفريق اليدوي للأوردرات القديمة) ومايتحسبش علينا. */
$sStart = strpos($occ, 'public function store(');
$sEnd   = strpos($occ, 'public function split(');
ok('store() و split() موجودين', $sStart !== false && $sEnd !== false && $sEnd > $sStart);
$store = substr($occ, (int) $sStart, (int) $sEnd - (int) $sStart);

/* بندوّر على **نداء** الدالة `$allocOrderNum()` مش على السطر الحرفي —
   تمويه برئ زي `. ""` مايكسرش الحارس (اتعلمناها بطفرة تمويه). النداء
   مابيطابقش التعريف لأن التعريف شكله `= function (`. */
$loopPos  = strpos($store, 'foreach ($parcels as $pi => $p) {');
$allocPos = strpos($store, '$allocOrderNum()');
$insPos   = strpos($store, 'INSERT INTO orders');
ok('فيه حلقة على الطرود بتعمل أوردر لكل طرد',
    $loopPos !== false && $allocPos !== false && $insPos !== false
    && $loopPos < $allocPos && $allocPos < $insPos,
    'رجعنا لأوردر واحد بترقيمة واحدة قبل الحلقة');
ok('الترقيم جوه الحلقة مش قبلها',
    substr_count($store, '$allocOrderNum()') === 1 && $allocPos > $loopPos);
ok('فلوس الأوردر = فلوس طرده هو',
    str_contains($store, "\$p['zone_price'], \$p['order_price'],"),
    'رجع بياخد المجموع الكلي');
ok('  ومافيش مجموع كلي بيتسجّل على أوردر', ! str_contains($store, '$totalPrice'));
ok('صف الطرد بيتسجّل برقم ١ ثابت',
    str_contains($store, "\$orderId, 1, \$p['receiver_id']"),
    'رجع parcel_no تسلسلي = أوردر متعدد الطرود');
ok('المعاملة بترجّع كل الأوردرات', str_contains($store, 'return $orderIds;')
    && str_contains($occ, '$orderIds = DB::transaction'));
ok('الرد فيه orders بكل الناتج',
    (bool) preg_match('~orderOut\(\$orderIds\[0\],\s*\[\s*\'orders\'\s*=>~s', $store),
    'الواجهات مش هتعرف تعرض كل الأرقام');
ok('سقف عهدة الكول سنتر بقى على كل أوردر ناتج',
    str_contains($store, "\$p['order_price'] > self::CC_CUSTODY_MAX"));
ok('عدد القطع مش بيتضاعف مع التفريق',
    str_contains($store, 'count($parcels) === 1 && $reqPieces'));

echo "\n══ 2) مسار تطبيق العميل (CustomerAppController::orderCreate) ══\n";

$cStart = strpos($cac, 'public function orderCreate(');
ok('orderCreate() موجودة', $cStart !== false);
/* نهاية الدالة = أول دالة بعدها — مش شريحة بأحرف ثابتة (اتعلمناها) */
$cEnd = strpos($cac, 'private static function phoneVariants(');
$oc2  = substr($cac, (int) $cStart, ($cEnd !== false ? (int) $cEnd - (int) $cStart : null));

ok('الترقيم بقى دالة بتتنادى لكل أوردر',
    str_contains($oc2, '$allocOrderNum = function () use ($branchId, $dayKey'),
    'رجع رقم واحد لكل الطلب');
/* الحلقة بقت `$pi => $p` بعد مانع التكرار (client_ref بلاحقة #طرد
   2026-09-02) — المرساة بتقبل الشكلين، والمقصود ثابت: النداء جوه الحلقة */
$cLoop = strpos($oc2, 'foreach ($parcels as $pi => $p) {');
if ($cLoop === false) {
    $cLoop = strpos($oc2, 'foreach ($parcels as $p) {');
}
$cAlloc = strpos($oc2, '$allocOrderNum()');   // النداء مش السطر الحرفي
ok('والنداء جوه حلقة الطرود',
    $cLoop !== false && $cAlloc !== false && $cLoop < $cAlloc);
ok('فلوس الأوردر = فلوس طرده هو',
    str_contains($oc2, "\$p['zone_price'], \$p['order_price'],"));
ok('  ومافيش مجموع كلي', ! str_contains($oc2, '$totalPrice'));
ok('صف الطرد بيتسجّل برقم ١ ثابت',
    str_contains($oc2, "\$orderId, 1, \$p['receiver_name']"));
ok('المعاملة بترجّع كل الأوردرات', str_contains($oc2, 'return $orderIds;')
    && str_contains($oc2, '$orderIds = DB::transaction'));
ok('الرد فيه orders بكل الناتج',
    (bool) preg_match('~\'orders\'\s*=>\s*array_map~', $oc2));

echo "\n══ 3) الواجهات بتعرض كل الأرقام ══\n";

foreach (['callcenter.html', 'tiar.html', 'branch.html'] as $f) {
    $ui = file_get_contents($root . '/public/' . $f);
    ok($f . ' بتقرا res.orders وتعرض كل الأرقام',
        str_contains($ui, 'const _created = (res.orders')
        && str_contains($ui, '_created.join("، ")'),
        'رجعت تعرض رقم واحد بس');
}

$st = file_get_contents($root . '/public/store.html');
ok('بوابة المحلات بتلزق رقم كل أوردر على طرده',
    str_contains($st, 'deliveries[i]._orderNum = o.orderNum'),
    'الباركود هيرجع للاحقة -2 القديمة');
ok('  وparcelCodeOf بيقدّم الرقم الحقيقي',
    (bool) preg_match('~if \(d && d\._orderNum\) return d\._orderNum;~', $st));

$cu = file_get_contents($root . '/public/customer.html');
ok('تطبيق العميل بيدمج كل الأوردرات الناتجة',
    str_contains($cu, 'mergeOrders(allOrders);'));
ok('  وخصم المحفظة بيلف عليهم واحد واحد',
    str_contains($cu, 'for (const o of allOrders) {')
    && str_contains($cu, 'walletUsed += w.walletUsed'),
    'رجع بيخصم من أول أوردر بس والباقي بيدفع كاش بالغلط');
ok('  وشاشة النجاح بترسم كل طرد برقم أوردره',
    str_contains($cu, '_orderNum: o.orderNum')
    && (bool) preg_match('~if \(d && d\._orderNum\) return d\._orderNum;~', $cu));

/* ═══ 4) تنفيذ فعلي — الاختبار لازم ينفّذ الكود مش يقراه بس ═══
   بننده store() بطردين لزونين مختلفين على القاعدة المحلية جوه معاملة
   بترجع (rollback). البثّ والواتساب afterCommit — مع الرجوع مافيش
   حاجة بتتبعت. مسار العميل ليه تنفيذ مماثل في test_customer_order.php. */
echo "\n══ 4) تنفيذ فعلي: طردين → أوردرين (قاعدة محلية، بترجع) ══\n";

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/* 🔴 إعادة تركيب المعالجات **بعد** البوتستراب — لارافل بيدوس على اللي
   اتركّب في أول الملف، وساعتها استثناء في قسم التنفيذ بيتطبع جميل
   ويخرج بكود 0 والحارس يبان ناجح وهو ميت (اكتشاف dahshaneg-b1 اليوم). */
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "💥 استثناء غير ممسوك: {$e->getMessage()}\n   {$e->getFile()}:{$e->getLine()}\n");
    exit(1);
});

use App\Http\Controllers\Api\OrdersController;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();
try {
    $zones = DB::select('SELECT id, price, delivery_branch_id FROM zones ORDER BY id LIMIT 2');
    if (count($zones) < 2) {
        ok('فيه زونين على الأقل في القاعدة المحلية', false, 'القاعدة فاضية');
    } else {
        $req = Request::create('/api/orders', 'POST', [
            'branchId'      => (int) $zones[0]->delivery_branch_id,
            'senderName'    => 'حارس التفريق',
            'senderPhone'   => '01000000099',
            'senderAddress' => 'عنوان الحارس',
            'source'        => 'callcenter',
            'deliveries'    => [
                ['receiverName' => 'مستلم أ', 'receiverPhone' => '01011111111', 'zoneId' => (int) $zones[0]->id],
                ['receiverName' => 'مستلم ب', 'receiverPhone' => '01022222222', 'zoneId' => (int) $zones[1]->id],
            ],
        ]);
        $req->attributes->set(ResolveApiActor::ATTRIBUTE, new Actor(
            userId: 1, customerId: null, username: 'guard', role: 'admin', branchId: null, name: 'حارس'
        ));

        $res = (new OrdersController())->store($req);
        $j   = json_decode($res->getContent(), true);

        ok('الطلب عدّى', ! empty($j['ok']));
        ok('طردين طلعوا أوردرين في الرد', count($j['orders'] ?? []) === 2,
            (string) count($j['orders'] ?? []));
        $nums = array_map(fn ($o) => (string) ($o['orderNum'] ?? ''), $j['orders'] ?? []);
        ok('وكل أوردر برقم مختلف', count(array_unique($nums)) === 2, implode(' / ', $nums));
        $allOk = true;
        foreach ($j['orders'] ?? [] as $i => $o) {
            $oid = (int) ($o['id'] ?? 0);
            $n   = (int) (DB::select('SELECT COUNT(*) n FROM order_deliveries WHERE order_id = ?', [$oid])[0]->n ?? 0);
            $row = DB::select('SELECT total_delivery_price FROM orders WHERE id = ?', [$oid])[0] ?? null;
            $exp = (float) $zones[$i]->price;
            if ($n !== 1 || $row === null || abs((float) $row->total_delivery_price - $exp) >= 0.005) {
                $allOk = false;
            }
        }
        ok('كل أوردر فيه طرد واحد وبسعر زونه هو', $allOk);
    }
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "PARCEL=ORDER: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
