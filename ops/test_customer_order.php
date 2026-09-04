<?php

declare(strict_types=1);

/**
 * 🔴 اختبار إنشاء أوردر من تطبيق العميل — المسار كامل من أول الجلسة
 * لحد الأوردر والطرود في القاعدة.
 *
 * ليه الملف ده موجود:
 *   المسار ده وقع على الإنتاج بـ`Undefined variable $codAllowed` —
 *   متغير اتنسي من قايمة `use` بتاعة معاملة الحفظ. `php -l` مابيمسكش
 *   النوع ده لأنه خطأ **تشغيل** مش تركيب، والمسار مالوش اختبار يشغّله
 *   فعلًا. الاختبار ده بيشغّله.
 *
 * كله جوه معاملة بتترجع فمفيش أثر على القاعدة.
 *
 * التشغيل: php ops/test_customer_order.php
 */

use App\Http\Controllers\Api\CustomerAppController;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;
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

/* جلسة عميل حقيقية — CustomerAppController بيقرا من الجلسة مباشرة
   (customerSessionId) مش من الـactor، فلازم نحطّها زي ما تسجيل الدخول
   بيعملها بالظبط. */
function creq(int $customerId, array $body): Request
{
    $r = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    $sess = new SessionStore('test', new ArraySessionHandler(120));
    $sess->put('role', 'customer');
    $sess->put('customer_id', $customerId);
    $r->setLaravelSession($sess);
    $r->attributes->set(ResolveApiActor::ATTRIBUTE, Actor::customer($customerId, 'cust:' . $customerId, 'عميل الاختبار'));

    return $r;
}

$ctl = new CustomerAppController();

DB::beginTransaction();
try {
    $now = date('Y-m-d H:i:s');

    /* منطقة حقيقية — «الصف البيتي» بيتفضّل لو موجود، وإلا أي منطقة
       ليها فرع توصيل. الشرط ده مش جزء من اللي بنختبره. */
    $zone = DB::select(
        'SELECT z.id, z.area_name, z.price, z.delivery_branch_id
           FROM zones z JOIN branches b ON b.id = z.delivery_branch_id
          ORDER BY (z.source_branch_id = z.delivery_branch_id) DESC, z.id LIMIT 1'
    )[0] ?? null;
    if (! $zone) {
        echo "مفيش مناطق — الاختبار اتخطى\n";
        DB::rollBack();
        exit(0);
    }
    $zoneId = (int) $zone->id;
    $price  = (float) $zone->price;

    // عميل اختبار مكتمل البيانات
    DB::insert(
        "INSERT INTO customers (email, display_name, phone1, default_zone_id, default_branch_id,
                                profile_completed, blocked, created_at)
         VALUES (?,'عميل الاختبار','01099999999',?,?,1,0,?)",
        ['acc-test-' . uniqid() . '@example.invalid', $zoneId, (int) $zone->delivery_branch_id, $now]
    );
    $cid = (int) DB::getPdo()->lastInsertId();

    DB::insert(
        "INSERT INTO customer_addresses (customer_id, label, full_address, zone_id, branch_id, is_default, created_at)
         VALUES (?,'المنزل','عنوان الاختبار',?,?,1,?)",
        [$cid, $zoneId, (int) $zone->delivery_branch_id, $now]
    );

    echo "\n══ 1) إنشاء أوردر بطرد واحد ══\n";
    $res = $ctl->orderCreate(creq($cid, [
        'deliveries' => [[
            'receiverName'  => 'مستلم الاختبار',
            'receiverPhone' => '01088888888',
            'zoneId'        => $zoneId,
            'orderPrice'    => 250,
            'address'       => 'شارع الاختبار',
            'note'          => 'ملاحظة',
        ]],
    ]));
    $out = json_decode($res->getContent(), true);
    ok('الرد ناجح', ! empty($out['ok']), json_encode($out, JSON_UNESCAPED_UNICODE));

    $o = DB::select("SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 1", [$cid])[0] ?? null;
    ok('الأوردر اتسجّل في القاعدة', $o !== null);
    if ($o) {
        $o = (array) $o;
        ok('المصدر customer', $o['source'] === 'customer', (string) $o['source']);
        ok('الحالة قيد التنفيذ', $o['status'] === 'processing', (string) $o['status']);
        ok('الفرع اتحدد من منطقة الاستلام',
            (int) $o['branch_id'] === (int) $zone->delivery_branch_id, (string) $o['branch_id']);
        ok('الفرع الأصلي اتكتب', (int) $o['origin_branch_id'] === (int) $o['branch_id'], var_export($o['origin_branch_id'], true));
        ok('سعر التوصيل من المنطقة', abs((float) $o['total_delivery_price'] - $price) < 0.005,
            $o['total_delivery_price'] . ' مقابل ' . $price);
        ok('رقم الأوردر فيه كود الفرع', str_contains((string) $o['order_num'], '-'), (string) $o['order_num']);

        $p = DB::select('SELECT * FROM order_deliveries WHERE order_id = ? ORDER BY parcel_no', [(int) $o['id']]);
        ok('الطرد اتسجّل', count($p) === 1, (string) count($p));
        if ($p) {
            $p0 = (array) $p[0];
            ok('اسم المستلم اتخزّن', $p0['receiver_name'] === 'مستلم الاختبار', (string) $p0['receiver_name']);
            ok('اسم المنطقة snapshot', $p0['zone_name'] === $zone->area_name, (string) $p0['zone_name']);
            /* 🔴 بوابة التحصيل: العميل الجديد (صفر أوردرات متسلّمة) بياخد
               order_price صفر مهما بعت. ده الحقل اللي المتغير المنسي
               `$codAllowed` بيتحكم فيه — فالاختبار ده بيغطّي الباج بالظبط. */
            ok('العميل الجديد مياخدش تحصيل (order_price = 0)',
                abs((float) $p0['order_price']) < 0.005, (string) $p0['order_price']);
        }
        ok('عهدة المحل صفر للعميل الجديد', abs((float) $o['store_prepaid']) < 0.005, (string) $o['store_prepaid']);
    }

    echo "\n══ 2) العميل اللي كمّل أوردرات بياخد التحصيل ══\n";
    /* بنعلّم أوردرات متسلّمة كفاية عشان بوابة التحصيل تفتح */
    $min = (new ReflectionClassConstant(CustomerAppController::class, 'COD_MIN_DELIVERED'))->getValue();
    for ($i = 0; $i < $min; $i++) {
        DB::insert(
            "INSERT INTO orders (order_num, branch_id, sender_name, sender_phone, status, status_since,
                                 delivered_at, created_at, total_delivery_price, customer_id, source)
             VALUES (?,?,'عميل الاختبار','01099999999','delivered',?,?,?,30,?,'customer')",
            ['CODTEST-' . $i . '-' . $cid, (int) $zone->delivery_branch_id, $now, $now, $now, $cid]
        );
    }
    $res2 = $ctl->orderCreate(creq($cid, [
        'deliveries' => [[
            'receiverName'  => 'مستلم 2',
            'receiverPhone' => '01077777777',
            'zoneId'        => $zoneId,
            'orderPrice'    => 250,
        ]],
    ]));
    ok('الأوردر التاني عدّى', ! empty(json_decode($res2->getContent(), true)['ok']));
    $o2 = (array) DB::select('SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$cid])[0];
    $p2 = (array) DB::select('SELECT * FROM order_deliveries WHERE order_id = ? LIMIT 1', [(int) $o2['id']])[0];
    ok("العميل بعد {$min} أوردر بياخد تحصيل 250", abs((float) $p2['order_price'] - 250) < 0.005, (string) $p2['order_price']);
    ok('عهدة المحل بقت 250', abs((float) $o2['store_prepaid'] - 250) < 0.005, (string) $o2['store_prepaid']);

    echo "\n══ 3) الحمايات ══\n";
    try {
        $ctl->orderCreate(creq($cid, ['deliveries' => []]));
        ok('أوردر بلا طرود مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('أوردر بلا طرود مرفوض', str_contains($e->getMessage(), 'طردًا واحدًا'), $e->getMessage());
    }
    try {
        $ctl->orderCreate(creq($cid, ['deliveries' => [['receiverName' => 'x', 'receiverPhone' => '123', 'zoneId' => $zoneId]]]));
        ok('رقم مستلم غلط مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('رقم مستلم غلط مرفوض', str_contains($e->getMessage(), 'رقم المستلم'), $e->getMessage());
    }
    try {
        $ctl->orderCreate(creq($cid, ['deliveries' => [['receiverName' => 'x', 'receiverPhone' => '01055555555']]]));
        ok('طرد بلا منطقة مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('طرد بلا منطقة مرفوض', str_contains($e->getMessage(), 'منطقة التسليم'), $e->getMessage());
    }

    echo "\n══ 4) أوردر بأكتر من طرد ══\n";
    $res3 = $ctl->orderCreate(creq($cid, [
        'deliveries' => [
            ['receiverName' => 'أول', 'receiverPhone' => '01011111111', 'zoneId' => $zoneId],
            ['receiverName' => 'تاني', 'receiverPhone' => '01022222222', 'zoneId' => $zoneId],
            ['receiverName' => 'تالت', 'receiverPhone' => '01033333333', 'zoneId' => $zoneId],
        ],
    ]));
    /* 🔴 قرار 2026-09-01: كل طرد = أوردر منفرد بذاته — حسابات الطيار
       بتعد الأوردرات مش الطرود، فالسيرفر بيفرّق تلقائيًا. التعاقد
       القديم (أوردر واحد بـ٣ طرود ومجموع أسعار) اتشال عن قصد. */
    $j3 = json_decode($res3->getContent(), true);
    ok('الأوردر عدّى', ! empty($j3['ok']));
    ok('الرد فيه ٣ أوردرات ناتجة', count($j3['orders'] ?? []) === 3,
        (string) count($j3['orders'] ?? []));
    $last3 = array_map(
        fn ($r) => (array) $r,
        DB::select('SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 3', [$cid])
    );
    $okOne = count($last3) === 3;
    $okPrice = $okOne;
    foreach ($last3 as $o3) {
        $n3 = (int) DB::select('SELECT COUNT(*) n FROM order_deliveries WHERE order_id = ?', [(int) $o3['id']])[0]->n;
        if ($n3 !== 1) {
            $okOne = false;
        }
        if (abs((float) $o3['total_delivery_price'] - $price) >= 0.005) {
            $okPrice = false;
        }
    }
    ok('كل أوردر فيه طرد واحد بس', $okOne);
    ok('وسعر كل أوردر = سعر منطقته هو (مش المجموع)', $okPrice);

    echo "\n══ 5) وضع «مش معايا بيانات المستلم» ══\n";
    /* 🔴 ده كان بيفشل 100% من المرات: الواجهة بتبعت الرقم فاضي والسيرفر
       بيرفض الفاضي، وخانة التليفون مخفية فمفيش مخرج للعميل. */
    $resR = $ctl->orderCreate(creq($cid, [
        'deliveries' => [[
            'receiverName'  => '🧾 البيانات على صورة الريسيت',
            'receiverPhone' => '',
            'fromReceipt'   => true,
            'zoneId'        => $zoneId,
            'images'        => ['/uploads/receipt-test.jpg'],
        ]],
    ]));
    ok('أوردر الريسيت عدّى من غير رقم مستلم', ! empty(json_decode($resR->getContent(), true)['ok']));
    $oR = (array) DB::select('SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$cid])[0];
    $pR = (array) DB::select('SELECT * FROM order_deliveries WHERE order_id = ? LIMIT 1', [(int) $oR['id']])[0];
    ok('الطرد متعلّم إن بياناته على الريسيت', (int) $pR['receiver_from_receipt'] === 1, (string) $pR['receiver_from_receipt']);
    $imgs = DB::select('SELECT * FROM order_images WHERE delivery_id = ?', [(int) $pR['id']]);
    ok('صورة الريسيت اتربطت بالطرد', count($imgs) === 1, (string) count($imgs));
    if ($imgs) {
        ok('رابط الصورة اتخزّن صح', ((array) $imgs[0])['url'] === '/uploads/receipt-test.jpg', ((array) $imgs[0])['url']);
    }

    // الرقم الغلط لسه مرفوض حتى مع الريسيت
    try {
        $ctl->orderCreate(creq($cid, [
            'deliveries' => [['receiverName' => 'x', 'receiverPhone' => '123', 'fromReceipt' => true, 'zoneId' => $zoneId]],
        ]));
        ok('رقم غلط مع الريسيت مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('رقم غلط مع الريسيت مرفوض', str_contains($e->getMessage(), 'رقم المستلم'), $e->getMessage());
    }
    // والوضع العادي لسه بيطلب الرقم
    try {
        $ctl->orderCreate(creq($cid, [
            'deliveries' => [['receiverName' => 'x', 'receiverPhone' => '', 'zoneId' => $zoneId]],
        ]));
        ok('الوضع العادي لسه بيطلب الرقم', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('الوضع العادي لسه بيطلب الرقم', str_contains($e->getMessage(), 'رقم المستلم'), $e->getMessage());
    }

    echo "\n══ 6) بيانات الراسل اللي العميل كتبها ══\n";
    /* كانت بتترمي بالكامل والأوردر بياخد بيانات صاحب الحساب — فالطيار
       بيروح لعنوان تاني وبيتصل برقم مالوش علاقة بمكان الاستلام. */
    $resS = $ctl->orderCreate(creq($cid, [
        'senderName'    => 'محل الورد',
        'senderPhone'   => '01234567890',
        'senderAddress' => 'شارع الجمهورية — أمام الصيدلية',
        'senderLat'     => 31.0351331,
        'senderLng'     => 31.3824087,
        'geoSrc'        => 'customer-pin',
        'deliveries'    => [[
            'receiverName'  => 'مستلم',
            'receiverPhone' => '01066666666',
            'zoneId'        => $zoneId,
            'lat'           => 31.04,
            'lng'           => 31.39,
            'geoSrc'        => 'map',
        ]],
    ]));
    ok('الأوردر عدّى', ! empty(json_decode($resS->getContent(), true)['ok']));
    $oS = (array) DB::select('SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$cid])[0];
    ok('اسم الراسل من الفورم', $oS['sender_name'] === 'محل الورد', (string) $oS['sender_name']);
    ok('رقم الراسل من الفورم', $oS['sender_phone'] === '01234567890', (string) $oS['sender_phone']);
    ok('عنوان الاستلام من الفورم', $oS['sender_address'] === 'شارع الجمهورية — أمام الصيدلية', (string) $oS['sender_address']);
    ok('دبوس الاستلام اتخزّن', abs((float) $oS['sender_lat'] - 31.0351331) < 0.0001, (string) $oS['sender_lat']);
    ok('مصدر الإحداثيات اتسجّل', $oS['geo_src'] === 'customer-pin', var_export($oS['geo_src'], true));

    $pS = (array) DB::select('SELECT * FROM order_deliveries WHERE order_id = ? LIMIT 1', [(int) $oS['id']])[0];
    ok('دبوس التسليم اتخزّن على الطرد', abs((float) $pS['lat'] - 31.04) < 0.0001, var_export($pS['lat'], true));
    ok('مصدر إحداثيات الطرد اتسجّل', $pS['geo_src'] === 'map', var_export($pS['geo_src'], true));

    echo "\n══ 7) لو الفورم فاضي بيقع على المحفوظ ══\n";
    $resF = $ctl->orderCreate(creq($cid, [
        'deliveries' => [['receiverName' => 'مستلم', 'receiverPhone' => '01055555555', 'zoneId' => $zoneId]],
    ]));
    ok('الأوردر عدّى', ! empty(json_decode($resF->getContent(), true)['ok']));
    $oF = (array) DB::select('SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$cid])[0];
    ok('الاسم رجع لصاحب الحساب', $oF['sender_name'] === 'عميل الاختبار', (string) $oF['sender_name']);
    ok('العنوان رجع للعنوان المحفوظ', $oF['sender_address'] === 'عنوان الاختبار', (string) $oF['sender_address']);

    echo "\n══ 8) رقم راسل غلط مرفوض ══\n";
    try {
        $ctl->orderCreate(creq($cid, [
            'senderPhone' => '99',
            'deliveries'  => [['receiverName' => 'x', 'receiverPhone' => '01044444444', 'zoneId' => $zoneId]],
        ]));
        ok('رقم راسل غلط مرفوض', false, 'عدّى!');
    } catch (Throwable $e) {
        ok('رقم راسل غلط مرفوض', str_contains($e->getMessage(), 'رقم المُرسِل'), $e->getMessage());
    }
    echo "\n══ 9) 🔒 مكان المحل مابيوصلش لجهاز العميل المستلم ══\n";
    /* 🔴 اللسعة: دبوس «الاستلام» اتشال من خريطة العميل — بس الإحداثيات
       كانت لسه بتتبعت مع كل استطلاع (كل ٨ ثواني)، وأي حد يفتح DevTools
       يشوف مكان المحل بالظبط. إخفاء الدبوس بيحلّ العرض، والتصفير هنا
       بيحلّ التسريب. الاختبار ده بيقرا **الرد الخارج من السيرفر** مش
       الشاشة، عشان الفرق ده بالظبط. */
    $branchIdT = (int) $zone->delivery_branch_id;
    DB::insert(
        "INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, sender_phone,
                             sender_address, sender_lat, sender_lng,
                             status, status_since, created_at, total_delivery_price,
                             added_by, added_by_role, source)
         VALUES (?,?,?,'مطعم الشام','01011111111','شارع المحل 12',30.05,31.24,
                 'delivering',?,?,30,'shop1','store','store')",
        ['LEAK-' . uniqid(), $branchIdT, $branchIdT, $now, $now]
    );
    $leakId = (int) DB::getPdo()->lastInsertId();
    DB::insert(
        "INSERT INTO order_deliveries (order_id, parcel_no, receiver_name, receiver_phone,
                                       zone_id, zone_name, address, lat, lng, status, created_at)
         VALUES (?,1,'عميل الاختبار','01099999999',?,?,'بيت العميل',30.08,31.30,'processing',?)",
        [$leakId, $zoneId, $zone->area_name, $now]
    );

    $greq = function (int $customerId): Request {
        $r = Request::create('/', 'GET');
        $sess = new SessionStore('test', new ArraySessionHandler(120));
        $sess->put('role', 'customer');
        $sess->put('customer_id', $customerId);
        $r->setLaravelSession($sess);
        $r->attributes->set(ResolveApiActor::ATTRIBUTE, Actor::customer($customerId, 'cust:' . $customerId, 'عميل الاختبار'));

        return $r;
    };

    $list = json_decode($ctl->ordersList($greq($cid))->getContent(), true);
    $mine = null;
    foreach ($list['items'] ?? [] as $it) {
        if ((int) $it['id'] === $leakId) {
            $mine = $it;
        }
    }
    ok('الشحنة الجاية ظهرت في القايمة', $mine !== null);
    if ($mine !== null) {
        ok('اتعلّمت شحنة جاية', ($mine['_incoming'] ?? null) === true, var_export($mine['_incoming'] ?? null, true));
        /* الدستور: المفتاح موجود والقيمة فاضية — مش المفتاح متشال.
           لو اتشال، الواجهة بتقرا undefined وده بابٌ لأخطاء صامتة. */
        foreach (['senderLat', 'senderLng', 'senderAddress'] as $k) {
            ok("القايمة: {$k} موجود بس فاضي",
                array_key_exists($k, $mine) && $mine[$k] === null,
                (array_key_exists($k, $mine) ? var_export($mine[$k], true) : 'المفتاح متشال!'));
        }
        ok('القايمة: geoSender اتقفل', ($mine['geoSender'] ?? null) === false,
            var_export($mine['geoSender'] ?? null, true));
        /* تليفون المحل بيتصفّر كمان: التطبيق مافيهوش أي شاشة بتعرضه
           (اتأكدت بالبحث في public/customer.html)، فوجوده على السلك
           تعرّض بلا مقابل. الاسم بيفضل — العميل لازم يعرف الشحنة
           جايّاله من مين. */
        foreach (['senderPhone', 'senderPhone2'] as $k) {
            ok("القايمة: {$k} موجود بس فاضي",
                array_key_exists($k, $mine) && $mine[$k] === null,
                (array_key_exists($k, $mine) ? var_export($mine[$k], true) : 'المفتاح متشال!'));
        }
        // اللي لازم يفضل: العميل محتاج يعرف مين باعت له
        ok('اسم المحل فضل ظاهر', ($mine['senderName'] ?? '') === 'مطعم الشام', (string) ($mine['senderName'] ?? ''));
        ok('دبوس التسليم بتاعه فضل موجود',
            (float) ($mine['deliveries'][0]['lat'] ?? 0) === 30.08, var_export($mine['deliveries'][0]['lat'] ?? null, true));
    }

    $trk = json_decode($ctl->orderTrack($greq($cid), (string) $leakId)->getContent(), true);
    ok('التتبّع: senderLat فاضي',
        array_key_exists('senderLat', $trk['order'] ?? []) && $trk['order']['senderLat'] === null,
        var_export($trk['order']['senderLat'] ?? 'المفتاح متشال!', true));
    /* ⚠️ `??` بيبلع الـnull — فحص «القيمة فاضية» **لازم** يبقى
       array_key_exists مش `?? 'x'`، وإلا الاختبار بيفشل على النجاح. */
    ok('التتبّع: senderAddress فاضي',
        array_key_exists('senderAddress', $trk['order'] ?? []) && $trk['order']['senderAddress'] === null,
        array_key_exists('senderAddress', $trk['order'] ?? []) ? var_export($trk['order']['senderAddress'], true) : 'المفتاح متشال!');
    ok('التتبّع: نقطة التسليم لسه بترجع', (float) ($trk['destination']['lat'] ?? 0) === 30.08,
        var_export($trk['destination'] ?? null, true));

    echo "\n══ 9.1) 🔒 طرود المستلمين التانيين في نفس الأوردر ══\n";
    /* 🔴 ده كان أوسع من مكان المحل: أوردر فيه طردين — واحد للعميل ده
       وواحد لحد تاني خالص. الشاشة بتعرض طرده هو بس (myParcel)، فالطرد
       التاني كان بيوصل الجهاز كامل: **اسم المستلم وتليفونه وعنوانه
       وإحداثيات بيته والمبلغ المطلوب تحصيله منه**، ومحدش شايفه لحد ما
       حد يفتح أدوات المطوّر. */
    DB::insert(
        "INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, sender_phone,
                             sender_address, sender_lat, sender_lng, total_delivery_price,
                             store_prepaid, goods_value, customer_phone, notes,
                             status, status_since, created_at, added_by, added_by_role, source)
         VALUES (?,?,?,'مطعم الشام','01011111111','شارع المحل 12',30.05,31.24,60,
                 25,500,'01055555555','ملاحظة المحل للفرع',
                 'delivering',?,?,'shop1','store','store')",
        ['MULTI-' . uniqid(), $branchIdT, $branchIdT, $now, $now]
    );
    $multiId = (int) DB::getPdo()->lastInsertId();
    // الطرد الأول لحد تاني خالص، والتاني لعميل الاختبار
    DB::insert(
        "INSERT INTO order_deliveries (order_id, parcel_no, receiver_name, receiver_phone, receiver_phone2,
                                       zone_id, zone_name, zone_price, order_price, address, note,
                                       lat, lng, status, created_at)
         VALUES (?,1,'مستلم غريب','01088888888','01077777777',?,?,40,250,'بيت الغريب 7','ملاحظة الغريب',
                 29.99,31.11,'processing',?)",
        [$multiId, $zoneId, $zone->area_name, $now]
    );
    DB::insert(
        "INSERT INTO order_deliveries (order_id, parcel_no, receiver_name, receiver_phone,
                                       zone_id, zone_name, zone_price, order_price, address,
                                       lat, lng, status, created_at)
         VALUES (?,2,'عميل الاختبار','01099999999',?,?,20,120,'بيت العميل',30.08,31.30,'processing',?)",
        [$multiId, $zoneId, $zone->area_name, $now]
    );

    $list2 = json_decode($ctl->ordersList($greq($cid))->getContent(), true);
    $mo = null;
    foreach ($list2['items'] ?? [] as $it) {
        if ((int) $it['id'] === $multiId) {
            $mo = $it;
        }
    }
    ok('الأوردر متعدد الطرود ظهر', $mo !== null);
    if ($mo !== null) {
        /* الطول بيفضل زي ما هو — الواجهة بتعد الطرود عشان تعرض رقم الطرد
           المركّب (CAI-...-2) اللي على الباركود اللي في إيد العميل. */
        ok('عدد الطرود ما اتغيّرش (الواجهة بتعدّهم)', count($mo['deliveries']) === 2,
            (string) count($mo['deliveries']));
        ok('_myParcels بيشاور على طرده هو', ($mo['_myParcels'] ?? []) === [1],
            json_encode($mo['_myParcels'] ?? null));

        $his   = $mo['deliveries'][1];
        $other = $mo['deliveries'][0];

        ok('طرده هو كامل — الاسم', ($his['receiverName'] ?? '') === 'عميل الاختبار', (string) ($his['receiverName'] ?? ''));
        ok('طرده هو كامل — مبلغ التحصيل', (float) ($his['orderPrice'] ?? 0) === 120.0, var_export($his['orderPrice'] ?? null, true));
        ok('طرده هو كامل — سعر التوصيل', (float) ($his['zonePrice'] ?? 0) === 20.0, var_export($his['zonePrice'] ?? null, true));
        ok('طرده هو كامل — دبوسه', (float) ($his['lat'] ?? 0) === 30.08, var_export($his['lat'] ?? null, true));
        ok('طرده هو كامل — رقم الطرد', (int) ($his['parcelNo'] ?? 0) === 2, var_export($his['parcelNo'] ?? null, true));

        foreach (['receiverName', 'receiverPhone', 'receiverPhone2', 'address', 'note',
                  'lat', 'lng', 'orderPrice', 'zonePrice', 'zoneName', 'id'] as $k) {
            ok("طرد الغريب: {$k} اتفضّى",
                array_key_exists($k, $other) && $other[$k] === null,
                (array_key_exists($k, $other) ? var_export($other[$k], true) : 'المفتاح متشال!'));
        }
        ok('طرد الغريب: الصور بقت مصفوفة فاضية', ($other['images'] ?? null) === [],
            json_encode($other['images'] ?? null));
        ok('طرد الغريب: رقم الطرد فضل (رقم ترتيبي مش بيانات)', (int) ($other['parcelNo'] ?? 0) === 1,
            var_export($other['parcelNo'] ?? null, true));

        // شروط المحل مع الشركة — مش شغل المستلم
        foreach (['totalDeliveryPrice', 'storePrepaid', 'customerPhone', 'notes'] as $k) {
            ok("الأوردر: {$k} اتفضّى",
                array_key_exists($k, $mo) && $mo[$k] === null,
                (array_key_exists($k, $mo) ? var_export($mo[$k], true) : 'المفتاح متشال!'));
        }
        /* قيمة البضاعة **للأوردر كله** — في أوردر متعدد الطرود دي بضاعة
           ناس تانية معاها، فبتتصفّر. سطر العرض شرطي فبيختفي لوحده. */
        ok('الأوردر: goodsValue اتفضّى',
            array_key_exists('goodsValue', $mo) && $mo['goodsValue'] === null,
            array_key_exists('goodsValue', $mo) ? var_export($mo['goodsValue'], true) : 'المفتاح متشال!');
        // والعلامتين اللي الواجهة بتعتمد عليهم في اختيار الطرد
        ok('_incoming متعلّمة', ($mo['_incoming'] ?? null) === true, var_export($mo['_incoming'] ?? null, true));
    }

    echo "\n══ 9.2) 🔒 دبوس التتبّع مابيقعش على بيت مستلم تاني ══\n";
    /* الحمولة اتقنّعت — بس `destination` في رد التتبّع بيتحسب من القاعدة
       مباشرة، وكان بيقع على **أول طرد له إحداثيات** لو رقم العميل ما
       طابقش. في أوردر زي ده ده معناه دبوس على بيت الغريب. */
    $trk2 = json_decode($ctl->orderTrack($greq($cid), (string) $multiId)->getContent(), true);
    ok('الدبوس على بيته هو مش على بيت الغريب',
        (float) ($trk2['destination']['lat'] ?? 0) === 30.08, json_encode($trk2['destination'] ?? null));
    $od0 = $trk2['order']['deliveries'][0] ?? [];
    ok('التتبّع: طرد الغريب اتفضّى هنا كمان',
        array_key_exists('receiverPhone', $od0) && $od0['receiverPhone'] === null,
        array_key_exists('receiverPhone', $od0) ? var_export($od0['receiverPhone'], true) : 'المفتاح متشال!');

    echo "\n══ 9.3) 🔒 رقم متخزّن بمسافات — الدبوس لازم يفضل على بيته ══\n";
    /* 🔴 الحالة اللي كسرت الدبوس فعلًا: الرقم متخزّن «010 9999 9999»
       بمسافات (شكل شايع جاي من نسخ/لصق أو استيراد). `phoneMatches`
       بيطبّعه فالعميل بيدخل على الأوردر عادي وبيشوف طرده صح، لكن حساب
       الدبوس كان بيستخدم مطابقة نص حرفية فبيسقط — ويقع على أول طرد له
       إحداثيات، يعني **بيت مستلم تاني** على الخريطة. */
    DB::insert(
        "INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, sender_phone,
                             status, status_since, created_at, total_delivery_price,
                             added_by, added_by_role, source)
         VALUES (?,?,?,'مطعم الشام','01011111111','delivering',?,?,30,'shop1','store','store')",
        ['SPACE-' . uniqid(), $branchIdT, $branchIdT, $now, $now]
    );
    $spaceId = (int) DB::getPdo()->lastInsertId();
    DB::insert(
        "INSERT INTO order_deliveries (order_id, parcel_no, receiver_name, receiver_phone,
                                       zone_id, zone_name, address, lat, lng, status, created_at)
         VALUES (?,1,'مستلم غريب','01088888888',?,?,'بيت الغريب',29.99,31.11,'processing',?)",
        [$spaceId, $zoneId, $zone->area_name, $now]
    );
    DB::insert(
        "INSERT INTO order_deliveries (order_id, parcel_no, receiver_name, receiver_phone,
                                       zone_id, zone_name, address, lat, lng, status, created_at)
         VALUES (?,2,'عميل الاختبار','010 9999 9999',?,?,'بيت العميل',30.08,31.30,'processing',?)",
        [$spaceId, $zoneId, $zone->area_name, $now]
    );

    $trk3 = json_decode($ctl->orderTrack($greq($cid), (string) $spaceId)->getContent(), true);
    ok('الدبوس على بيته (30.08) مش على بيت الغريب (29.99)',
        (float) ($trk3['destination']['lat'] ?? 0) === 30.08, json_encode($trk3['destination'] ?? null));
    $od = $trk3['order']['deliveries'][0] ?? [];
    ok('وطرد الغريب اتفضّى',
        array_key_exists('address', $od) && $od['address'] === null,
        array_key_exists('address', $od) ? var_export($od['address'], true) : 'المفتاح متشال!');

    echo "\n══ 9.4) 🔒 مسار incoming — التلات فروع ══\n";
    /* 🔴 الفتحة اللي المراجعة العدائية لقيتها: المسار ده كان بيجاوب على
       سؤال «يحق له يشوف الأوردر؟» ويستخدم نفس الإجابة لسؤال «الأوردر
       كله بتاعه؟». مستلم طرد واحد في أوردر خمس طرود كان بياخد الخمسة. */
    $gq = function (int $customerId, array $query): Request {
        $r = Request::create('/', 'GET', $query);
        $sess = new SessionStore('test', new ArraySessionHandler(120));
        $sess->put('role', 'customer');
        $sess->put('customer_id', $customerId);
        $r->setLaravelSession($sess);
        $r->attributes->set(ResolveApiActor::ATTRIBUTE, Actor::customer($customerId, 'cust:' . $customerId, 'عميل الاختبار'));

        return $r;
    };
    $multiNum = DB::selectOne('SELECT order_num FROM orders WHERE id = ?', [$multiId])->order_num;

    // ── (أ) من غير أي باراميتر ──
    $inc = json_decode($ctl->incoming($gq($cid, []))->getContent(), true);
    $row = null;
    foreach ($inc['items'] ?? [] as $it) {
        if ((int) ($it['order']['id'] ?? 0) === $multiId) {
            $row = $it;
        }
    }
    ok('(أ) بلا باراميتر: الشحنة رجعت', $row !== null, json_encode(array_map(fn ($x) => $x['order']['id'] ?? null, $inc['items'] ?? [])));
    if ($row !== null) {
        ok('(أ) الطرد المرجّع طرده هو', ($row['d']['receiverName'] ?? '') === 'عميل الاختبار', (string) ($row['d']['receiverName'] ?? ''));
        ok('(أ) طرد الغريب اتفضّى',
            array_key_exists('receiverPhone', $row['order']['deliveries'][0])
                && $row['order']['deliveries'][0]['receiverPhone'] === null,
            var_export($row['order']['deliveries'][0]['receiverPhone'] ?? 'المفتاح متشال!', true));
        ok('(أ) مكان المحل اتفضّى',
            array_key_exists('senderLat', $row['order']) && $row['order']['senderLat'] === null,
            var_export($row['order']['senderLat'] ?? 'المفتاح متشال!', true));
        ok('(أ) شروط المحل المالية اتفضّت',
            array_key_exists('totalDeliveryPrice', $row['order']) && $row['order']['totalDeliveryPrice'] === null,
            var_export($row['order']['totalDeliveryPrice'] ?? 'المفتاح متشال!', true));
    }

    // ── (ب) بالباركود مع لاحقة طرده هو ──
    $inc2 = json_decode($ctl->incoming($gq($cid, ['code' => $multiNum . '-2']))->getContent(), true);
    $r2 = $inc2['items'][0] ?? null;
    ok('(ب) الباركود بلاحقته رجّع الشحنة', $r2 !== null, json_encode($inc2));
    if ($r2 !== null) {
        ok('(ب) الطرد المرجّع طرده هو', ($r2['d']['receiverName'] ?? '') === 'عميل الاختبار', (string) ($r2['d']['receiverName'] ?? ''));
        ok('(ب) ومبلغ تحصيله ظاهر', (float) ($r2['d']['orderPrice'] ?? 0) === 120.0, var_export($r2['d']['orderPrice'] ?? null, true));
        $g2 = $r2['order']['deliveries'][0] ?? [];
        ok('(ب) طرد الغريب اتفضّى',
            array_key_exists('address', $g2) && $g2['address'] === null,
            array_key_exists('address', $g2) ? var_export($g2['address'], true) : 'المفتاح متشال!');
    }

    // ── (ج) بالباركود من غير لاحقة — اللسعة الأصلية ──
    /* من غير اللاحقة، الكود القديم كان بيقع على أول طرد = طرد الغريب.
       دلوقتي لازم يرجّع **طرده هو** لأنه مستلم فيه فعلًا. */
    $inc3 = json_decode($ctl->incoming($gq($cid, ['code' => $multiNum]))->getContent(), true);
    $r3 = $inc3['items'][0] ?? null;
    ok('(ج) الباركود بلا لاحقة رجّع الشحنة', $r3 !== null, json_encode($inc3));
    if ($r3 !== null) {
        ok('(ج) الطرد المرجّع طرده هو مش أول طرد',
            ($r3['d']['receiverName'] ?? '') === 'عميل الاختبار', (string) ($r3['d']['receiverName'] ?? ''));
        ok('(ج) مش رجّع بيانات الغريب',
            ($r3['d']['receiverPhone'] ?? '') !== '01088888888', (string) ($r3['d']['receiverPhone'] ?? ''));
    }

    // ── (د) أوردر العميل نفسه من نفس المسار — مايتقنّعش ──
    $ownNum = DB::selectOne(
        'SELECT order_num FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$cid]
    )->order_num ?? null;
    if ($ownNum === null) {
        ok('(د) اتخطّى — مفيش أوردر للعميل نفسه', true);
    } else {
        $inc4 = json_decode($ctl->incoming($gq($cid, ['code' => $ownNum]))->getContent(), true);
        $r4 = $inc4['items'][0] ?? null;
        ok('(د) أوردره هو رجع', $r4 !== null, json_encode($inc4));
        if ($r4 !== null) {
            ok('(د) وبيانات مستلمه ظاهرة زي ما هي',
                ! empty($r4['d']['receiverName']), var_export($r4['d']['receiverName'] ?? null, true));
        }
    }

    echo "\n══ 10) وأوردر العميل نفسه مابيتقنّعش ══\n";
    /* الحالة العكسية: العميل هو الراسل — نقطة الاستلام دي بيته هو،
       وتصفيرها كان هيكسر خريطته من غير أي مكسب. */
    $own = null;
    foreach ($list['items'] ?? [] as $it) {
        if (($it['_incoming'] ?? null) === false && ! empty($it['senderLat'])) {
            $own = $it;
            break;
        }
    }
    if ($own === null) {
        ok('مفيش أوردر للعميل نفسه بإحداثيات في القايمة — اتخطّى', true);
    } else {
        ok('أوردر العميل نفسه: senderLat لسه موجود', $own['senderLat'] !== null, var_export($own['senderLat'], true));
        ok('أوردر العميل نفسه: geoSender لسه شغّال', ($own['geoSender'] ?? null) === true,
            var_export($own['geoSender'] ?? null, true));
    }
} catch (Throwable $e) {
    $fail++;
    echo "\n💥 " . get_class($e) . ': ' . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "CUSTOMER ORDER: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
