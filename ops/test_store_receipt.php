<?php
/**
 * 🧾 حارس: «مش معايا بيانات المستلم» من بوابة المحل — لكل طرد لوحده.
 *
 * ═══ الطلب ═══
 * صاحب النظام 2026-08-31: «في تطبيق المحلات كمان عايز زرار مش معايا
 * بيانات المستلم، أحط المنطقة وصور الريسيت مرة واحدة».
 *
 * ═══ ليه المسار ده احتاج سيرفر والعميل لأ ═══
 * تطبيق العميل بيبعت على `POST /api/customer/orders` وده كان بيقرا
 * `fromReceipt` من الأصل. المحل بيبعت على `POST /api/orders`
 * (OrdersController::store) واللي كان:
 *   ① بيفرض اسم مستلم لكل طرد
 *   ② **مش كاتب** عمود `receiver_from_receipt` خالص
 * فطرد المحل كان هيتخزّن بعلامة صفر ولوحة الفرع ما توريش الشارة للطيار.
 *
 * ═══ الفحص بينفّذ الـSQL مش بيقراه ═══
 * البند ③ بيقصّ جملة الـINSERT من الكنترولر **نفسه** وبينفّذها على قاعدة
 * حقيقية جوه معاملة بترجع (rollback)، وبيقرا العمود بعدها. اختبار قبل
 * كده كتب الـSQL بإيده فعدّى على طفرة كسرت الكنترولر — عشان كده بنقص.
 *
 * ═══ الخطر الصامت ═══
 * زيادة عمود في INSERT من غير زيادة `?` (أو العكس) بتقع وقت التشغيل بس.
 * البند ② بيعدّهم قبل ما نوصل للقاعدة أصلًا.
 *
 * التشغيل: php ops/test_store_receipt.php
 */
$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;

function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$what}\n"; }
    else { $fail++; echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n"; }
}

$src = file_get_contents($ROOT . '/app/Http/Controllers/Api/OrdersController.php');

/* ══════════ ① الكنترولر بيقرا العلامة ══════════ */
echo "\n══ 1) قراءة fromReceipt ══\n";
ok('بيقراها من الطرد نفسه جوه الحلقة',
    (bool) preg_match('/\$fromReceipt = ! empty\(\$d\[\x27fromReceipt\x27\]\);/', $src));
ok('والاسم لسه إجباري للطرد العادي',
    (bool) preg_match('/if \(! \$fromReceipt\) \{\s*\n\s*throw new ApiException\(\x27يرجى إدخال اسم جهة التسليم لكل طرد\x27\);/', $src));
ok('والطرد اللي بريسيت بياخد نص واضح بدل الفاضي',
    str_contains($src, "\$recvName = '🧾 البيانات على صورة الريسيت';"));
ok('ونفس النص اللي تطبيق العميل بيبعته — عشان الاتنين يبانوا واحد',
    str_contains(file_get_contents($ROOT . '/public/customer.html'), '🧾 البيانات على صورة الريسيت'));
ok('العلامة داخلة مصفوفة الطرد',
    (bool) preg_match("/'from_receipt'\s*=> \\\$fromReceipt \? 1 : 0,/", $src));

/* ══════════ ② عدد الأعمدة = عدد العلامات = عدد الوسائط ══════════ */
echo "\n══ 2) اتساق جملة الإدراج ══\n";
if (! preg_match('/\'INSERT INTO order_deliveries\s*\n\s*\((.*?)\)\s*\n\s*VALUES \((.*?)\)\',\s*\n\s*\[(.*?)\n                        \]/s', $src, $m)) {
    echo "  ✗ مالقيتش جملة INSERT INTO order_deliveries\n";
    exit(1);
}
[$all, $colsRaw, $qsRaw, $bindRaw] = $m;
$cols  = array_values(array_filter(array_map('trim', preg_split('/,\s*/', preg_replace('/\s+/', ' ', $colsRaw)))));
$qs    = substr_count($qsRaw, '?');
$binds = array_values(array_filter(array_map('trim', explode(',', preg_replace('/\s+/', ' ', $bindRaw)))));

ok('عدد الأعمدة = عدد علامات الاستفهام', count($cols) === $qs, count($cols) . " ≠ {$qs}");
ok('عدد الوسائط = عدد الأعمدة', count($binds) === count($cols), count($binds) . ' ≠ ' . count($cols));
ok('العمود receiver_from_receipt موجود', in_array('receiver_from_receipt', $cols, true));
$idx = array_search('receiver_from_receipt', $cols, true);
ok('وترتيبه مطابق لترتيب وسيطه',
    $idx !== false && str_contains($binds[$idx] ?? '', "\$p['from_receipt']"),
    'العمود في الموضع ' . $idx . ' والوسيط هناك: ' . ($binds[$idx] ?? '—'));

/* ══════════ ③ تنفيذ فعلي على قاعدة حقيقية ══════════ */
echo "\n══ 3) تنفيذ الـSQL المقصوصة من الكنترولر ══\n";
require $ROOT . '/vendor/autoload.php';
$app = require $ROOT . '/bootstrap/app.php';
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


use Illuminate\Support\Facades\DB;

try {
    DB::connection()->getPdo();
} catch (Throwable $e) {
    echo "  ⚠️ مافيش اتصال بالقاعدة — تخطّي التنفيذ\n";
    echo "\n" . str_repeat('─', 46) . "\n";
    echo $fail === 0 ? "✅ عدّى {$pass} فحص (من غير تنفيذ)\n\n" : "🔴 وقع {$fail}\n\n";
    exit($fail === 0 ? 0 : 1);
}

/* بنبني الجملة من اللي اتقص فعلًا — مش بنكتبها بإيدنا */
$sql = 'INSERT INTO order_deliveries (' . implode(', ', $cols) . ') VALUES (' . $qsRaw . ')';

DB::beginTransaction();
try {
    /* أوردر مؤقت — FK بيفرض وجود order_id حقيقي */
    $branch = DB::select('SELECT id FROM branches LIMIT 1')[0]->id ?? null;
    $zone   = DB::select('SELECT id, area_name, price FROM zones LIMIT 1')[0] ?? null;
    if ($branch === null || $zone === null) {
        echo "  ⚠️ مافيش فروع/مناطق في القاعدة دي — تخطّي التنفيذ\n";
        DB::rollBack();
    } else {
        $now = date('Y-m-d H:i:s');
        DB::insert(
            'INSERT INTO orders (order_num, branch_id, status, status_since, created_at, total_delivery_price)
             VALUES (?,?,?,?,?,?)',
            ['TEST-RCPT-' . substr(md5($now . 'x'), 0, 6), $branch, 'processing', $now, $now, 0]
        );
        $orderId = (int) DB::getPdo()->lastInsertId();

        /* نفس ترتيب الوسائط اللي في الكنترولر بالحرف */
        $vals = [];
        foreach ($cols as $c) {
            $vals[] = match ($c) {
                'order_id'              => $orderId,
                'parcel_no'             => 1,
                'receiver_id'           => null,
                'receiver_name'         => '🧾 البيانات على صورة الريسيت',
                'receiver_phone'        => null,
                'receiver_phone2'       => null,
                'receiver_from_receipt' => 1,
                'zone_id'               => $zone->id,
                'zone_name'             => $zone->area_name,
                'zone_price'            => (float) $zone->price,
                'order_price'           => 0.0,
                'address'               => $zone->area_name,
                'note'                  => null,
                'status'                => 'processing',
                'lat'                   => null,
                'lng'                   => null,
                'created_at'            => $now,
                default                 => null,
            };
        }
        DB::insert($sql, $vals);
        $did = (int) DB::getPdo()->lastInsertId();

        $row = (array) DB::select('SELECT receiver_name, receiver_phone, receiver_from_receipt
                                     FROM order_deliveries WHERE id = ?', [$did])[0];

        ok('الإدراج عدّى بعدد الوسائط الصح', $did > 0);
        ok('receiver_from_receipt اتخزّنت 1', (int) $row['receiver_from_receipt'] === 1,
            var_export($row['receiver_from_receipt'], true));
        ok('والاسم البديل اتخزّن زي ما هو',
            $row['receiver_name'] === '🧾 البيانات على صورة الريسيت', (string) $row['receiver_name']);
        ok('والتليفون فاضي مقبول', $row['receiver_phone'] === null || $row['receiver_phone'] === '');

        /* والطرد العادي بصفر */
        $vals2 = $vals;
        $vals2[array_search('receiver_from_receipt', $cols, true)] = 0;
        $vals2[array_search('receiver_name', $cols, true)]         = 'أحمد';
        $vals2[array_search('parcel_no', $cols, true)]             = 2;
        DB::insert($sql, $vals2);
        $did2 = (int) DB::getPdo()->lastInsertId();
        $row2 = (array) DB::select('SELECT receiver_from_receipt FROM order_deliveries WHERE id = ?', [$did2])[0];
        ok('والطرد العادي في نفس الأوردر بيتخزّن بصفر',
            (int) $row2['receiver_from_receipt'] === 0, var_export($row2['receiver_from_receipt'], true));

        DB::rollBack();
        $left = DB::select('SELECT COUNT(*) c FROM order_deliveries WHERE id IN (?,?)', [$did, $did2])[0]->c;
        ok('والمعاملة رجعت — مافيش أثر في القاعدة', (int) $left === 0, "فاضل {$left}");
    }
} catch (Throwable $e) {
    DB::rollBack();
    ok('التنفيذ من غير أخطاء', false, $e->getMessage());
}

/* ══════════ ④ الواجهة ══════════ */
echo "\n══ 4) بوابة المحل (store.html) ══\n";
$ui = file_get_contents($ROOT . '/public/store.html');
ok('المفتاح موجود لكل طرد', str_contains($ui, 'id="rReceipt-${n}"'));
ok('وبينده onRowReceipt برقم الطرد', str_contains($ui, 'onchange="onRowReceipt(${n})"'));
ok('حقول الهوية ملفوفة في rIdent', str_contains($ui, '<div id="rIdent-${n}">'));
ok('والعنوان والدبوس في rAddrBlock', str_contains($ui, 'id="rAddrBlock-${n}"'));
ok('والاتنين بيتخفوا مع بعض',
    (bool) preg_match('/set\("rIdent-" \+ n, !on\);\s*\n\s*set\("rAddrBlock-" \+ n, !on\);/', $ui));
/* ── هل العنصر ده جوه اللفّة فعلًا؟ ──────────────────────────
   الفحص القديم كان بيدوّر على تعليق `</div><!-- /rIdent`، وده معيوب:
   أول ما حد يشيل سطر القفل ده (وهي بالظبط الطفرة اللي بتخفي المنطقة)
   العلامة بتختفي، فالفحص مايلاقيش حاجة ويعدّي — يعني كان بيحرس نفسه
   مش الكود. دلوقتي بنعدّ `<div>` و`</div>` من بداية اللفّة لحد ما
   العمق يرجع صفر، وده مكان القفل الحقيقي مهما كانت التعليقات. */
$closeOf = static function (string $html, string $openTag): int {
    $start = strpos($html, $openTag);
    if ($start === false) return -1;
    $i = $start + strlen($openTag);
    $depth = 1;
    $len = strlen($html);
    while ($i < $len && $depth > 0) {
        $nextOpen  = strpos($html, '<div', $i);
        $nextClose = strpos($html, '</div>', $i);
        if ($nextClose === false) return -1;
        if ($nextOpen !== false && $nextOpen < $nextClose) { $depth++; $i = $nextOpen + 4; }
        else { $depth--; $i = $nextClose + 6; }
    }
    return $depth === 0 ? $i : -1;
};

$identOpen  = '<div id="rIdent-${n}">';
$identClose = $closeOf($ui, $identOpen);
$identStart = strpos($ui, $identOpen);
ok('لفّة rIdent مقفولة صح', $identClose > 0);

/* العناصر دي **لازم** تفضل بره اللفّة — بتتشاف في الحالتين */
foreach ([
    'rZone-${n}'      => 'اختيار المنطقة',
    'rPrice-${n}'     => 'سعر التوصيل',
    'rOrderPrice-${n}'=> 'سعر الطلب (العهدة)',
    'rImgInput-${n}'  => 'رفع الصور',
] as $needle => $lbl) {
    $at = strpos($ui, $needle, $identStart);
    ok("🔴 {$lbl} بره لفّة الإخفاء — لازم يفضل ظاهر مع الريسيت",
        $identClose > 0 && $at !== false && $at > $identClose,
        $at === false ? 'مالقيتوش' : ($at > $identClose ? '' : 'جوه اللفّة!'));
}
/* والعناصر دي لازم تبقى **جوه** اللفّة — بتتخفي مع الريسيت */
foreach (['rName-${n}' => 'اسم المستلِم', 'rPhone-${n}' => 'هاتف المستلِم'] as $needle => $lbl) {
    $at = strpos($ui, $needle, $identStart);
    ok("{$lbl} جوه لفّة الإخفاء", $identClose > 0 && $at !== false && $at < $identClose);
}
ok('التحقق بيعفي الطرد اللي بريسيت من الاسم والتليفون',
    (bool) preg_match('/const isRcpt = window\.rowIsReceipt\(n\);\s*\n\s*if \(!isRcpt\) \{/', $ui));
/* اتغيّر 2026-08-31 بطلب صاحب النظام: الصورة كانت **إجبارية** مع الريسيت،
   وبقت اختيارية. السبب: المحل ساعات بيبعت الورقة نفسها مع الطيار بدل ما
   يصوّرها، وإجبار الصورة كان بيوقف شحنة سليمة.
   الشارة اللي الفرع والطيار بيشوفوها بتيجي من `fromReceipt` مش من وجود
   الصورة، فمافيش معلومة بتضيع. */
ok('🔴 والصورة **مش** إجبارية — الشارة بتيجي من fromReceipt مش من الصورة',
    ! str_contains($ui, 'const hasImg = (window._rowImages[n] || []).some(x => typeof x === "string");')
    && ! str_contains($ui, 'no-receipt-image'),
    'لسه بيطلب صورة');
ok('وعلامة الناقص مابتحسبش الصورة',
    ! preg_match('/if \(window\.rowIsReceipt\(n\)\) \{\s*\n\s*return !\(window\._rowImages\?\.\[n\] \|\| \[\]\)\.some/', $ui));
ok('والطرد اللي بريسيت خالص من فحوص المستلم',
    str_contains($ui, 'if (window.rowIsReceipt(n)) return false;'));
/* المقصود إن فحص المنطقة يفضل **قبل** فحص الريسيت — مش إن مافيش كود
   بينهم. المسافة بتكبر مع كل قاعدة جديدة (سعر الطلب، حد سعر التوصيل)،
   فمقارنة المواضع أمتن من مدى بعدد حروف بيبوظ مع كل إضافة. */
{
    $iZone = strpos($ui, 'if (!v("rZone-" + n)) return true;');
    $iRcpt = strpos($ui, 'if (window.rowIsReceipt(n)) return false;');
    ok('والمنطقة إجبارية في الحالتين قبل أي شرط تاني',
        $iZone !== false && $iRcpt !== false && $iZone < $iRcpt,
        $iZone === false ? 'فحص المنطقة مش موجود' : ($iRcpt === false ? 'فحص الريسيت مش موجود' : 'الترتيب مقلوب'));
}
ok('الحمولة بتبعت fromReceipt', str_contains($ui, 'fromReceipt: isRcpt,'));
ok('والاسم البديل والهاتف الفاضي', str_contains($ui, 'receiverName: isRcpt ? "🧾 البيانات على صورة الريسيت" : name,')
    && str_contains($ui, 'receiverPhone: isRcpt ? "" : phone,'));
ok('والعنوان بيبقى اسم المنطقة', str_contains($ui, 'address: isRcpt ? zoneName : addr,'));
ok('والدبوس مابيتبعتش مع الريسيت', str_contains($ui, 'const _rgeo = isRcpt ? null : (window._geoPins['));

echo "\n" . str_repeat('─', 46) . "\n";
echo $fail === 0
    ? "✅ عدّى {$pass} فحص — الريسيت لكل طرد من بوابة المحل\n\n"
    : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n\n";
exit($fail === 0 ? 0 : 1);
