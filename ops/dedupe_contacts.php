<?php

declare(strict_types=1);

/**
 * 🧹 دمج المكرر في دفتر العملاء (senders) والمستلمين (receivers) — المفتاح: التليفون.
 *
 * ═══ المشكلة (بلاغ صاحب النظام 2026-09-10) ═══
 * قايمة العملاء فيها نفس الاسم متكرر عشرات المرات. السبب:
 * `OrdersController::store` كان بيعمل **صف مُرسِل جديد مع كل أوردر** لما
 * الموظف يكتب الاسم بدل ما يختار من الدفتر — من غير أي بحث عن الموجود.
 * («روح دمشق» بقى ٣٧ صف بنفس الرقم و٣١١ أوردر موزّعين عليهم.)
 * و`partyCreate` بيعمل upsert صح، بس المسار ده كان بيتخطّاه.
 *
 * ═══ اللي السكربت ده بيعمله ═══
 * بيجمّع الصفوف على **الرقم بعد التطبيع** (أرقام بس — عشان «0100 123» و
 * «0100123» يبقوا واحد)، بيختار **أقدم صف** (أصغر id) كأصل، بيحوّل كل
 * الإشارات ليه (`orders.sender_id` · `users.sender_id` ·
 * `order_deliveries.receiver_id`)، بيكمّل الناقص فيه من الصفوف التانية
 * (عنوان · تليفون ٢ · ملاحظات) وبيحفظ العناوين المختلفة في
 * `extra_addresses` (نفس العمود اللي الواجهة بتقراه) عشان **مفيش بيانات
 * تضيع**، وبعدين بيمسح المكرر.
 *
 * ⚠️ `customers` (حسابات تطبيق العميل) **مش داخلة** — دي هويات دخول
 *    بمحافظ وأوردرات، ودمجها قرار بشري مش آلي.
 *
 * التشغيل:
 *   php ops/dedupe_contacts.php            ← معاينة بس (مافيش أي كتابة)
 *   php ops/dedupe_contacts.php --apply    ← التنفيذ (جوه معاملة واحدة)
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "💥 فشل: {$e->getMessage()}\n");
    exit(1);
});

$apply = in_array('--apply', $argv, true);
echo $apply ? "⚙️  وضع التنفيذ\n" : "👀 وضع المعاينة — مافيش أي كتابة (زوّد --apply للتنفيذ)\n";

/** الرقم بعد التطبيع — أرقام بس، زي EntitiesController::partyPhone */
$norm = static fn (?string $p): string => preg_replace('/\D+/', '', (string) $p) ?? '';

/** الأطول/غير الفاضي بيغلب — بنكمّل الناقص في الأصل من المكرر */
$pick = static function (?string $keep, ?string $other): ?string {
    $k = trim((string) $keep);
    $o = trim((string) $other);
    if ($k !== '') {
        return $k;
    }

    return $o !== '' ? $o : null;
};

$TABLES = [
    'senders'   => [['orders', 'sender_id'], ['users', 'sender_id']],
    'receivers' => [['order_deliveries', 'receiver_id']],
];

$plan = [];
foreach ($TABLES as $table => $refs) {
    $rows = DB::select("SELECT * FROM {$table} ORDER BY id");
    $byPhone = [];
    foreach ($rows as $r) {
        $key = $norm($r->phone1);
        if ($key === '') {
            continue;   // بلا رقم = مش قابل للمقارنة، بنسيبه
        }
        $byPhone[$key][] = (array) $r;
    }
    foreach ($byPhone as $key => $group) {
        if (count($group) < 2) {
            continue;
        }
        $plan[$table][] = ['phone' => $key, 'rows' => $group];
    }
}

if (! $plan) {
    echo "✅ مفيش تكرار — القوايم نضيفة\n";
    exit(0);
}

$total = ['groups' => 0, 'delete' => 0, 'moved' => 0];
foreach ($plan as $table => $groups) {
    $refs = $TABLES[$table];
    echo "\n══ {$table} — " . count($groups) . " مجموعة مكررة ══\n";
    foreach ($groups as $g) {
        $rows = $g['rows'];
        $keep = array_shift($rows);          // أقدم صف
        $ids  = array_column($rows, 'id');
        $moved = 0;
        foreach ($refs as [$rt, $rc]) {
            $n = (int) (DB::selectOne(
                "SELECT COUNT(*) c FROM {$rt} WHERE {$rc} IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
                $ids
            )->c ?? 0);
            $moved += $n;
        }
        $total['groups']++;
        $total['delete'] += count($ids);
        $total['moved']  += $moved;
        printf(
            "  %-16s «%s» — %d صف → 1 (بيتشال %d، وبيتحوّل %d ارتباط)\n",
            $g['phone'],
            mb_substr((string) $keep['name'], 0, 28),
            count($ids) + 1,
            count($ids),
            $moved
        );

        if (! $apply) {
            continue;
        }

        DB::transaction(function () use ($table, $refs, $keep, $rows, $ids, $g, $pick): void {
            // ① كل الإشارات تروح للأصل
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach ($refs as [$rt, $rc]) {
                DB::update("UPDATE {$rt} SET {$rc} = ? WHERE {$rc} IN ({$ph})", array_merge([$keep['id']], $ids));
            }

            // ② الناقص في الأصل بيتكمّل من المكرر — مفيش بيانات تضيع
            $addr   = $keep['address'];
            $extras = json_decode((string) ($keep['extra_addresses'] ?? ''), true) ?: [];
            $seen   = array_map(static fn ($x) => trim((string) ($x['address'] ?? '')), $extras);
            $phone2 = $keep['phone2'];
            $notes  = $keep['notes'];
            foreach ($rows as $r) {
                $phone2 = $pick($phone2, $r['phone2']);
                $notes  = $pick($notes, $r['notes']);
                $a = trim((string) ($r['address'] ?? ''));
                if ($a === '') {
                    continue;
                }
                if (trim((string) $addr) === '') {
                    $addr = $a;
                    continue;
                }
                // عنوان مختلف → يتحفظ كعنوان إضافي بدل ما يترمي
                if ($a !== trim((string) $addr) && ! in_array($a, $seen, true) && count($extras) < 10) {
                    $extras[] = ['label' => 'من صف مكرر', 'address' => mb_substr($a, 0, 300)];
                    $seen[] = $a;
                }
            }

            DB::update(
                "UPDATE {$table} SET phone1 = ?, phone2 = ?, address = ?, notes = ?, extra_addresses = ? WHERE id = ?",
                [
                    $g['phone'],                      // الرقم مطبّع — عشان الفهرس الفريد يمسك
                    $phone2,
                    $addr,
                    $notes,
                    $extras ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null,
                    $keep['id'],
                ]
            );

            // ③ المكرر يتشال
            DB::delete("DELETE FROM {$table} WHERE id IN ({$ph})", $ids);
        }, 3);
    }
}

echo "\n" . str_repeat('─', 52) . "\n";
printf(
    "%s %d مجموعة · %d صف مكرر · %d ارتباط اتحوّل للأصل\n",
    $apply ? '✅ اتنفّذ:' : '👀 المعاينة:',
    $total['groups'],
    $total['delete'],
    $total['moved']
);

if ($apply) {
    foreach (array_keys($TABLES) as $t) {
        $r = DB::selectOne("SELECT COUNT(*) c, COUNT(DISTINCT phone1) d FROM {$t}");
        printf("   %-10s %d صف · %d رقم مختلف%s\n", $t, $r->c, $r->d, $r->c === $r->d ? '  ✓' : '  🔴 لسه فيه تكرار');
    }
} else {
    echo "   شغّل بـ --apply للتنفيذ (جوه معاملة لكل مجموعة).\n";
}
