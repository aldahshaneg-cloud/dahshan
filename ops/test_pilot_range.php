<?php

declare(strict_types=1);

/**
 * 📅 اختبار «يوم الطيار = يوم ورديته» + فلاتر المدى في «الطلبات المنتهية».
 *
 * ═══ اللسعة الأولى: اليوم بيتقطع نص الوردية ═══
 * وردية الشركة 9 ص ← 4 الفجر، يعني بتعدّي نص الليل. الكود كان بيفلتر من
 * 00:00 لـ 00:00 بتوقيت القاهرة، فآخر أربع ساعات من كل وردية كانت
 * بتتنقل لليوم اللي بعده في تطبيق الطيار — بينما كشف الإدارة بيحسبها
 * على اليوم الصح. الرقمين مايطلعوش مع بعض ومحدش يعرف مين الغلطان.
 *
 * القاعدة اللي صاحب النظام حدّدها بالنص: «لو لسه في الوردية المفتوحة
 * يبقى اليوم القديم، أما لو فتح وردية جديدة يبقى اليوم الجديد».
 * يعني الفاصل = **فتح وردية**، مش ساعة ثابتة.
 *
 * ═══ اللسعة التانية: إحصائية مقصوصة ═══
 * الاستعلام بيرجّع `LIMIT 200` للعرض، والإحصائيات كانت بتتحسب بحلقة على
 * نفس الـ200. طول ما الشاشة كانت وردية واحدة الرقم كان بيطلع صح بالصدفة.
 * أول ما بقى فيه فلتر شهر أو سنة، «إجمالي التحصيل» كان هيحسب أول 200
 * أوردر **ويعرضه على إنه الإجمالي** — رقم فلوس ناقص بلا أي علامة.
 *
 * كله جوه معاملة بتترجع فمفيش أثر.
 *
 * التشغيل: php ops/test_pilot_range.php
 */

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\PilotAppController;
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

/** لحظة بتوقيت القاهرة → نص UTC للقاعدة (بيحترم التوقيت الصيفي) */
function cairoUtc(string $when): string
{
    return (new DateTimeImmutable($when, new DateTimeZone('Africa/Cairo')))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

$ctl = new PilotAppController();

/** نداء المسار بالبارامترات المطلوبة، ورد مفكوك */
function call(PilotAppController $ctl, int $userId, array $query): array
{
    $r = Request::create('/', 'GET', $query);
    $r->attributes->set(
        ResolveApiActor::ATTRIBUTE,
        Actor::staff($userId, 'range_test_pilot', 'pilot', null, 'طيار الاختبار')
    );

    return json_decode($ctl->finishedOrders($r)->getContent(), true);
}

DB::beginTransaction();
try {
    $branchId = (int) DB::selectOne('SELECT id FROM branches ORDER BY id LIMIT 1')->id;
    $pilotId  = (int) DB::selectOne('SELECT id FROM pilots ORDER BY id LIMIT 1')->id;

    /* حساب الطيار — تجهيزة اختبار جوه معاملة بترجع، مش حساب حقيقي.
       `password_hash` مكتوب فيه علامة **مش** هاش bcrypt صالح، فمستحيل
       يتسجّل بيها دخول حتى لو الصف فضل بالغلط. */
    DB::insert(
        "INSERT INTO users (username, password_hash, role, name, pilot_id, created_at)
         VALUES ('range_test_pilot', 'NOT-A-VALID-HASH', 'pilot', 'طيار الاختبار', ?, ?)",
        [$pilotId, date('Y-m-d H:i:s')]
    );
    $userId = (int) DB::getPdo()->lastInsertId();

    /* صفحة نضيفة. ترتيب مهم: `fk_orders_shift_id` بيمنع حذف وردية
       طالما فيه أوردر بيشاور عليها، فلازم نفكّ الأوردرات الأول. */
    DB::update('UPDATE orders SET pilot_id = NULL, shift_id = NULL WHERE pilot_id = ?', [$pilotId]);
    DB::delete('DELETE FROM shifts WHERE pilot_id = ?', [$pilotId]);

    $mkShift = function (string $cairoStart, ?string $cairoEnd) use ($pilotId, $branchId): int {
        DB::insert(
            "INSERT INTO shifts (pilot_id, branch_id, started_at, ended_at, status, created_at)
             VALUES (?,?,?,?,?,?)",
            [$pilotId, $branchId, cairoUtc($cairoStart),
             $cairoEnd !== null ? cairoUtc($cairoEnd) : null,
             $cairoEnd !== null ? 'ended' : 'active', cairoUtc($cairoStart)]
        );

        return (int) DB::getPdo()->lastInsertId();
    };

    $mkOrder = function (int $shift, string $cairoDelivered, float $price = 30.0, float $wallet = 0.0)
        use ($pilotId, $branchId): int {
        $t = cairoUtc($cairoDelivered);
        DB::insert(
            "INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_name, sender_phone,
                                 status, status_since, created_at, delivered_at,
                                 total_delivery_price, wallet_used, pilot_id, shift_id,
                                 added_by, added_by_role, source)
             VALUES (?,?,?,'محل الاختبار','01000000000','delivered',?,?,?,?,?,?,?,'t','admin','store')",
            ['PR-' . uniqid(), $branchId, $branchId, $t, $t, $t, $price, $wallet, $pilotId, $shift]
        );

        return (int) DB::getPdo()->lastInsertId();
    };

    echo "\n══ 1) 🔴 الوردية اللي بتعدّي نص الليل ══\n";
    /* وردية بدأت 9 ص يوم 20 وخلصت 4 الفجر يوم 21 — زي الوردية الحقيقية.
       الأوردر اللي اتسلّم 2 الفجر يوم 21 لسه جوه الوردية دي. */
    $s1 = $mkShift('2026-08-20 09:00:00', '2026-08-21 04:00:00');
    $mkOrder($s1, '2026-08-20 14:00:00');   // وسط الوردية
    $mkOrder($s1, '2026-08-21 02:00:00');   // بعد نص الليل — لسه يوم 20

    /* وردية جديدة اتفتحت 6 ص يوم 21 — يعني **قبل** 9 ص. القاعدة القديمة
       (dayStartHour=9) كانت هتحطها على يوم 20؛ قاعدة صاحب النظام
       بتحطها على يوم 21 لأنها وردية جديدة. */
    $s2 = $mkShift('2026-08-21 06:00:00', '2026-08-21 08:00:00');
    $mkOrder($s2, '2026-08-21 07:00:00');

    $s3 = $mkShift('2026-08-21 09:00:00', null);
    $mkOrder($s3, '2026-08-21 12:00:00');

    $d20 = call($ctl, $userId, ['day' => '2026-08-20']);
    $d21 = call($ctl, $userId, ['day' => '2026-08-21']);

    ok('يوم 20 فيه أوردرين (بما فيهم اللي 2 الفجر)',
        (int) $d20['stats']['totalOrders'] === 2, (string) $d20['stats']['totalOrders']);
    ok('يوم 21 فيه أوردرين (الوردية الجديدة + اللي بعدها)',
        (int) $d21['stats']['totalOrders'] === 2, (string) $d21['stats']['totalOrders']);

    /* الحارس الحقيقي: الحساب القديم (نص الليل) كان هيدّي 1 و3.
       لو حد رجّع الفلترة لوقت الأوردر بدل وقت الوردية، الرقمين دول
       بيتقلبوا والاختبارين اللي فوق بيقعوا. */
    $byClock20 = (int) DB::selectOne(
        'SELECT COUNT(*) c FROM orders WHERE pilot_id = ? AND delivered_at >= ? AND delivered_at < ?',
        [$pilotId, cairoUtc('2026-08-20 00:00:00'), cairoUtc('2026-08-21 00:00:00')]
    )->c;
    ok('الحساب القديم كان هيدّي 1 بس — الفرق ده هو اللسعة',
        $byClock20 === 1, (string) $byClock20);

    echo "\n══ 2) اللي بعد نص الليل مع ورديته مش لوحده ══\n";
    $nums20 = array_map(fn ($i) => $i['orderNum'] ?? '', $d20['items']);
    $nums21 = array_map(fn ($i) => $i['orderNum'] ?? '', $d21['items']);
    ok('مفيش أوردر ظهر في اليومين', count(array_intersect($nums20, $nums21)) === 0);
    ok('مجموع اليومين = 4', count($nums20) + count($nums21) === 4,
        count($nums20) . ' + ' . count($nums21));

    echo "\n══ 3) المدى: من / إلى ══\n";
    $rng = call($ctl, $userId, ['from' => '2026-08-20', 'to' => '2026-08-21']);
    ok('المدى بيجمع اليومين', (int) $rng['stats']['totalOrders'] === 4,
        (string) $rng['stats']['totalOrders']);
    ok('الرد بيوصف اللي بيتعرض',
        ($rng['range']['mode'] ?? '') === 'range'
        && ($rng['range']['from'] ?? '') === '2026-08-20'
        && ($rng['range']['to'] ?? '') === '2026-08-21',
        json_encode($rng['range'] ?? null, JSON_UNESCAPED_UNICODE));

    /* الطيار اللي اختار «من 21 إلى 20» قصده واضح — رمي خطأ هنا بيوقّف
       شاشة مش بيحمي حاجة. */
    $rev = call($ctl, $userId, ['from' => '2026-08-21', 'to' => '2026-08-20']);
    ok('المقلوب بيتظبط مش بيترمي', (int) $rev['stats']['totalOrders'] === 4,
        (string) $rev['stats']['totalOrders']);

    $one = call($ctl, $userId, ['from' => '2026-08-20', 'to' => '2026-08-20']);
    ok('من وإلى نفس اليوم = يوم واحد', (int) $one['stats']['totalOrders'] === 2,
        (string) $one['stats']['totalOrders']);

    $none = call($ctl, $userId, ['from' => '2026-07-01', 'to' => '2026-07-31']);
    ok('شهر فاضي بيرجّع صفر مش خطأ', (int) $none['stats']['totalOrders'] === 0
        && ($none['ok'] ?? false) === true, (string) $none['stats']['totalOrders']);

    echo "\n══ 4) الحراسات ══\n";
    $bad = function (array $q) use ($ctl, $userId): ?string {
        try {
            call($ctl, $userId, $q);

            return null;
        } catch (ApiException $e) {
            return $e->getMessage();
        }
    };
    ok('صيغة غلط بترفض', $bad(['from' => '20-08-2026']) !== null, 'عدّت');
    ok('تاريخ مستحيل بيرفض', $bad(['from' => '2026-13-45']) !== null, 'عدّت');
    ok('مدى أكبر من سنتين بيرفض', $bad(['from' => '2000-01-01', 'to' => '2026-01-01']) !== null, 'عدّت');
    ok('سنة كاملة بتعدّي', $bad(['from' => '2026-01-01', 'to' => '2026-12-31']) === null);

    echo "\n══ 5) 🔴 الإحصائية مش مقصوصة على 200 ══\n";
    /* اللسعة: الحلقة كانت بتلف على `$rows` وهي LIMIT 200. مع فلتر شهر
       أو سنة الرقم كان هيبقى ناقص من غير أي علامة. */
    $sBig = $mkShift('2026-09-10 09:00:00', '2026-09-11 04:00:00');
    for ($i = 0; $i < 205; $i++) {
        $mkOrder($sBig, '2026-09-10 12:00:00', 10.0, 0.0);
    }
    $big = call($ctl, $userId, ['day' => '2026-09-10']);

    ok('العدد الحقيقي 205 مش 200', (int) $big['stats']['totalOrders'] === 205,
        (string) $big['stats']['totalOrders']);
    ok('التحصيل على الـ205 كلهم = 2050',
        abs((float) $big['stats']['totalCollected'] - 2050.0) < 0.005,
        (string) $big['stats']['totalCollected']);
    ok('المعروض 200 بس', (int) $big['stats']['itemsShown'] === 200,
        (string) $big['stats']['itemsShown']);
    /* من غير العلم ده الطيار بيعدّ الكروت (200)، يقارنها بالإجمالي (205)،
       ويفتكر إن فيه خمس أوردرات ضاعت. */
    ok('الشاشة بتعرف إن القايمة مقصوصة', ($big['stats']['itemsTruncated'] ?? null) === true);
    ok('عدد الكروت الفعلي 200', count($big['items']) === 200, (string) count($big['items']));

    echo "\n══ 6) التوافق مع القديم ══\n";
    /* التطبيق المنشور دلوقتي بينده المسار **من غير أي بارامتر**. لو
       السلوك ده اتغيّر، كل طيار على النسخة القديمة هيشوف شاشة غلط. */
    DB::update("UPDATE shifts SET status = 'ended', ended_at = ? WHERE pilot_id = ?",
        [cairoUtc('2026-08-21 20:00:00'), $pilotId]);
    DB::update("UPDATE shifts SET status = 'active', ended_at = NULL WHERE id = ?", [$s3]);

    $def = call($ctl, $userId, []);
    ok('من غير بارامترات = الوردية المفتوحة',
        ($def['range']['mode'] ?? '') === 'shift' && (int) $def['stats']['totalOrders'] === 1,
        json_encode([$def['range']['mode'] ?? null, $def['stats']['totalOrders']]));

    /* مفيش وردية مفتوحة: الرجوع لـ«يوم الوردية الأخيرة» مش لـ«النهارده».
       السيناريو اللي كان بيكسر: الوردية اتقفلت 2 الفجر، الطيار يفتح
       الشاشة يلاقي يوم شغل كامل اتمسح. */
    DB::update("UPDATE shifts SET status = 'ended', ended_at = ? WHERE id = ?",
        [cairoUtc('2026-09-11 04:00:00'), $s3]);
    $noShift = call($ctl, $userId, []);
    ok('من غير وردية = يوم آخر وردية مش يوم النهارده',
        ($noShift['range']['from'] ?? '') === '2026-09-10',
        json_encode($noShift['range'] ?? null, JSON_UNESCAPED_UNICODE));
    ok('واللي فيها كل الـ205', (int) $noShift['stats']['totalOrders'] === 205,
        (string) $noShift['stats']['totalOrders']);

    echo "\n══ 7) وردية معيّنة بالرقم لسه شغّالة ══\n";
    $byShift = call($ctl, $userId, ['shift' => (string) $s1]);
    ok('الوردية بالرقم بترجّع أوردراتها', (int) $byShift['stats']['totalOrders'] === 2,
        (string) $byShift['stats']['totalOrders']);

    $other = (int) DB::selectOne('SELECT id FROM pilots WHERE id <> ? LIMIT 1', [$pilotId])->id;
    DB::insert("INSERT INTO shifts (pilot_id, branch_id, started_at, status, created_at)
                VALUES (?,?,?,'active',?)",
        [$other, $branchId, cairoUtc('2026-08-20 09:00:00'), cairoUtc('2026-08-20 09:00:00')]);
    $foreign = (int) DB::getPdo()->lastInsertId();
    $err = null;
    try {
        call($ctl, $userId, ['shift' => (string) $foreign]);
    } catch (ApiException $e) {
        $err = $e->getMessage();
    }
    ok('وردية زميله مرفوضة', $err !== null && str_contains($err, 'مش بتاعتك'), (string) $err);

    echo "\n══ 9) الاختصارات: النهارده / الشهر / السنة ══\n";
    /* 🔴 السيرفر هو اللي بيحسبها، مش التطبيق. لو التليفون على منطقة
       زمنية غلط أو ساعة متظبطة بالإيد، «النهارده» في التطبيق كان
       هيفرق عن «النهارده» في كشف الإدارة. */
    DB::update("UPDATE shifts SET status = 'ended', ended_at = ? WHERE pilot_id = ?",
        [cairoUtc('2026-09-11 04:00:00'), $pilotId]);
    DB::update("UPDATE shifts SET status = 'active', ended_at = NULL WHERE id = ?", [$s1]);

    /* الوردية المفتوحة بدأت 20 أغسطس ⟵ نقطة الارتكاز 2026-08-20،
       مش تاريخ النهارده الحقيقي. */
    $pToday = call($ctl, $userId, ['period' => 'today']);
    ok('today = يوم الوردية المفتوحة مش يوم النهارده',
        ($pToday['range']['from'] ?? '') === '2026-08-20'
        && (int) $pToday['stats']['totalOrders'] === 2,
        json_encode([$pToday['range']['from'] ?? null, $pToday['stats']['totalOrders']]));
    ok('today بيتسمّى today في الرد', ($pToday['range']['mode'] ?? '') === 'today',
        (string) ($pToday['range']['mode'] ?? ''));

    /* الشهر بيتبني من نقطة الارتكاز: أغسطس مش سبتمبر. أوردرات سبتمبر
       الـ205 **مش** المفروض تدخل. */
    $pMonth = call($ctl, $userId, ['period' => 'month']);
    ok('month = شهر نقطة الارتكاز (أغسطس)',
        ($pMonth['range']['from'] ?? '') === '2026-08-01'
        && ($pMonth['range']['to'] ?? '') === '2026-08-20',
        json_encode($pMonth['range'] ?? null, JSON_UNESCAPED_UNICODE));
    ok('شهر أغسطس فيه أوردرين — مش 205 بتوع سبتمبر',
        (int) $pMonth['stats']['totalOrders'] === 2, (string) $pMonth['stats']['totalOrders']);

    $pYear = call($ctl, $userId, ['period' => 'year']);
    ok('year = من أول السنة لنقطة الارتكاز',
        ($pYear['range']['from'] ?? '') === '2026-01-01'
        && ($pYear['range']['to'] ?? '') === '2026-08-20',
        json_encode($pYear['range'] ?? null, JSON_UNESCAPED_UNICODE));

    /* الوردية اللي بدأت 10 سبتمبر: الشهر بقى سبتمبر والسنة بقت شاملة
       الـ205 — نفس الكود، نقطة ارتكاز مختلفة. */
    DB::update("UPDATE shifts SET status = 'ended', ended_at = ? WHERE pilot_id = ?",
        [cairoUtc('2026-09-11 04:00:00'), $pilotId]);
    DB::update("UPDATE shifts SET status = 'active', ended_at = NULL WHERE id = ?", [$sBig]);
    $sepMonth = call($ctl, $userId, ['period' => 'month']);
    ok('نقطة ارتكاز سبتمبر → الشهر بقى سبتمبر بالـ205',
        ($sepMonth['range']['from'] ?? '') === '2026-09-01'
        && (int) $sepMonth['stats']['totalOrders'] === 205,
        json_encode([$sepMonth['range']['from'] ?? null, $sepMonth['stats']['totalOrders']]));
    $sepYear = call($ctl, $userId, ['period' => 'year']);
    ok('السنة بتجمع الشهرين = 209', (int) $sepYear['stats']['totalOrders'] === 209,
        (string) $sepYear['stats']['totalOrders']);
    ok('وتحصيل السنة = 2050 + 60 + 30 (الغير مسلّم مابيدخلش)',
        abs((float) $sepYear['stats']['totalCollected'] - 2170.0) < 0.005,
        (string) $sepYear['stats']['totalCollected']);

    $badP = null;
    try { call($ctl, $userId, ['period' => 'week']); } catch (ApiException $e) { $badP = $e->getMessage(); }
    ok('فترة مش معروفة بترفض', $badP !== null, 'عدّت');

    /* الأولوية: التواريخ الصريحة بتغلب الاختصار عشان مايتلغيش اختيار
       الطيار اليدوي من ورا ظهره. */
    $mix = call($ctl, $userId, ['period' => 'year', 'from' => '2026-08-20', 'to' => '2026-08-20']);
    ok('التواريخ الصريحة بتغلب الاختصار', (int) $mix['stats']['totalOrders'] === 2,
        (string) $mix['stats']['totalOrders']);

    echo "\n══ 8) حارس الرجوع في الكود ══\n";
    $src = file_get_contents((new ReflectionClass(PilotAppController::class))->getFileName());
    ok('الفلترة على بداية الوردية مش على وقت الأوردر',
        str_contains($src, 'SELECT sh.started_at FROM shifts sh WHERE sh.id = o.shift_id'));
    ok('الإحصائية بتتحسب على استعلام مستقل مش على $rows',
        str_contains($src, '$allRows = DB::select(') && str_contains($src, 'foreach ($allRows as $r)'));
    ok('مفيش حلقة إحصائية على $rows المحدودة',
        ! preg_match('/foreach \(\$rows as \$r\) \{\s*if \(\$r->status/', $src));
    ok('حساب الفلوس لسه من Money::netCollect', str_contains($src, 'Money::netCollect((array) $r)'));
} catch (Throwable $e) {
    $fail++;
    echo "\n💥 " . get_class($e) . ': ' . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "PILOT RANGE: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
