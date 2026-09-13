<?php

declare(strict_types=1);

/**
 * 🧪 مناورة: المحل بيصحّح الاسم المحفوظ — وبالحدود الصح.
 *
 * طلب صاحب النظام (2026-09-12): «أه عايز المحل يقدر يصحّح الاسم المحفوظ».
 *
 * `party_identities` هو **أعلى** مصدر في ترتيب حسم الاسم، فاللي بيكتب فيه
 * بيحسم اسم الرقم في كل ردود `/api/lookup` **لكل الشركة** مش لصاحب التعديل
 * بس. عشان كده المحل عليه بوابتين، والمناورة دي بتتأكد إنهم شغّالين:
 *
 *   ① محل **مااتعاملش** مع الرقم → مرفوض.
 *   ② محل **اتعامل** مع الرقم → مسموح، والاسم بيتغيّر فعلًا في اللوك-أب.
 *   ③ الاسم اللي **موظف** صحّحه → المحل مايقدرش يدوس عليه.
 *   ④ والموظف بيقدر يدوس على تصحيح المحل في أي وقت.
 *
 * 🔴 **مافيش أي كتابة بتفضل** — كله جوه معاملة بتترجع في `finally`.
 *
 * التشغيل (على السيرفر):  php ops/drill_store_identity.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\TrustController;
use App\Support\Actor;
use App\Wire\TrustWire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;
$ok = function (string $what, bool $cond, string $got = '') use (&$pass, &$fail): void {
    if ($cond) {
        $pass++;
        echo "  ✓ {$what}\n";
    } else {
        $fail++;
        echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n";
    }
};

/* رقم مستلم حقيقي + المحل اللي بعتله فعلًا */
$row = DB::selectOne(
    "SELECT d.receiver_phone AS phone, o.added_by AS shop
       FROM order_deliveries d JOIN orders o ON o.id = d.order_id
      WHERE d.receiver_phone <> '' AND o.added_by IS NOT NULL AND o.added_by <> ''
      ORDER BY d.id DESC LIMIT 1"
);
if (! $row) {
    exit("⚠️ مفيش طرد بتليفون ومنشئ — المناورة محتاجة واحد\n");
}
$phone = $row->phone;
$shopU = $row->shop;

$mk = static fn (string $role, string $user): Actor => Actor::staff(999001, $user, $role, null, $user);
$cur = $mk('store', $shopU);
Request::macro('actorOrFail', function () use (&$cur) { return $cur; });

$ctl = new TrustController();
$put = static function (string $name) use ($ctl, $phone): array {
    $r = Request::create('/x', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'],
        json_encode(['name' => $name], JSON_UNESCAPED_UNICODE));

    return json_decode($ctl->identitySave($r, $phone)->getContent(), true);
};
$try = static function (callable $f): array {
    try {
        return [true, $f(), ''];
    } catch (Throwable $e) {
        return [false, null, $e->getMessage()];
    }
};
$nameNow = static fn (): ?string => TrustWire::identityInfo(TrustWire::normalizePhone($phone))['name'];

echo "🧪 الرقم {$phone} · المحل «{$shopU}» (بعتله فعلًا)\n";
echo str_repeat('═', 58) . "\n";

DB::beginTransaction();
try {
    DB::delete('DELETE FROM party_identities WHERE subject_phone = ?', [TrustWire::normalizePhone($phone)]);
    printf("\n   الاسم قبل أي تصحيح: «%s»\n", $nameNow() ?? '—');

    /* ① محل مااتعاملش مع الرقم */
    echo "\n── ① محل تاني مااتعاملش مع الرقم ──\n";
    $cur = $mk('store', 'shop_never_dealt_' . bin2hex(random_bytes(2)));
    [$okd, , $msg] = $try(fn () => $put('اسم من محل غريب'));
    $ok('🔴 اترفض', ! $okd && str_contains($msg, 'اتعاملت معاها'), $msg ?: 'عدّى!');

    /* ② المحل صاحب التعامل */
    echo "\n── ② المحل اللي اتعامل مع الرقم ──\n";
    $cur = $mk('store', $shopU);
    [$okd, , $msg] = $try(fn () => $put('الاسم الصح من المحل'));
    $ok('اتقبل', $okd, $msg);
    $ok('🔴 والاسم اتغيّر فعلًا في اللوك-أب', $nameNow() === 'الاسم الصح من المحل', (string) $nameNow());
    $r = DB::selectOne('SELECT verified_role, verified_by FROM party_identities WHERE subject_phone = ?',
        [TrustWire::normalizePhone($phone)]);
    $ok('واتسجّل إنه من محل مش موظف', ($r->verified_role ?? '') === 'store', (string) ($r->verified_role ?? ''));

    /* ③ موظف بيصحّح فوقه */
    echo "\n── ③ موظف بيصحّح فوق تصحيح المحل ──\n";
    $cur = $mk('branch', 'مشرف المناورة');
    [$okd, , $msg] = $try(fn () => $put('الاسم المعتمد من الفرع'));
    $ok('الموظف بيقدر يدوس على تصحيح المحل', $okd, $msg);
    $r = DB::selectOne('SELECT verified_role FROM party_identities WHERE subject_phone = ?',
        [TrustWire::normalizePhone($phone)]);
    $ok('واتعلّم staff', ($r->verified_role ?? '') === 'staff', (string) ($r->verified_role ?? ''));

    /* ④ المحل يحاول يدوس على تصحيح الموظف */
    echo "\n── ④ المحل يحاول يدوس على تصحيح الموظف ──\n";
    $cur = $mk('store', $shopU);
    [$okd, , $msg] = $try(fn () => $put('محاولة دوس'));
    $ok('🔴 اترفض — تصحيح الموظف محمي', ! $okd && str_contains($msg, 'متأكّد منه موظف'), $msg ?: 'عدّى!');
    $ok('والاسم فضل بتاع الموظف', $nameNow() === 'الاسم المعتمد من الفرع', (string) $nameNow());

    /* ⑤ العميل — نفس البوابتين */
    echo "\n── ⑤ العميل ──\n";
    $custRow = DB::selectOne(
        "SELECT o.customer_id AS cid, d.receiver_phone AS phone
           FROM order_deliveries d JOIN orders o ON o.id = d.order_id
          WHERE o.customer_id IS NOT NULL AND d.receiver_phone <> '' ORDER BY d.id DESC LIMIT 1"
    );
    if ($custRow) {
        /* عميل مابعتش للرقم ده */
        $cur = new Actor(null, 999777, 'customer:999777', 'customer', null, 'عميل مناورة');
        [$okd, , $msg] = $try(fn () => $put('اسم من عميل غريب'));
        $ok('🔴 عميل مابعتش للرقم ده → اترفض', ! $okd && str_contains($msg, 'اتعاملت معاها'), $msg ?: 'عدّى!');
    } else {
        echo "   (مفيش أوردر لعميل تطبيق — الجزء ده اتخطّى)\n";
    }

    /* ⑥ الاسم الفاضي */
    echo "\n── ⑥ اسم فاضي ──\n";
    $cur = $mk('branch', 'مشرف المناورة');
    [$okd, , $msg] = $try(fn () => $put('   '));
    $ok('اترفض', ! $okd && str_contains($msg, 'اكتب الاسم'), $msg ?: 'عدّى!');
} catch (Throwable $e) {
    $fail++;
    echo '  💥 ' . $e->getMessage() . "\n";
} finally {
    DB::rollBack();
}

$leak = DB::selectOne("SELECT COUNT(*) c FROM party_identities WHERE canonical_name LIKE '%المناورة%' OR canonical_name IN ('الاسم الصح من المحل','الاسم المعتمد من الفرع','محاولة دوس','اسم من محل غريب','اسم من عميل غريب')");
$ok('🔴 مفيش أي أثر فاضل على القاعدة', (int) $leak->c === 0, 'صفوف فاضلة: ' . $leak->c);

echo "\n" . str_repeat('─', 58) . "\n";
echo $fail === 0
    ? "✅ عدّى {$pass} فحص — المحل بيصحّح في حدوده، والموظف بيغلب\n"
    : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
