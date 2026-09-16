<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════
   💵 تصحيح: سلفة وردية اتسجّلت قبل ما التقفيلة تبقى بتصرفها من الخزنة (2026-09-16)

   بلاغ صاحب النظام: «سيف 55 سجّلتهم سلفة عليه في التقفيلة ومنزلوش من الخزنة» —
   الوردية شايلة advance_amount ومفيش advance_txn_id. السكربت بيعمل حركة منصرف
   «سلفة وردية: الطيار» من الخزنة المحدّدة (أو خزنة عمولة الوردية نفسها لو
   ماتحدّدتش) ويربطها بالوردية. مرة واحدة لكل وردية (بيرفض لو advance_txn_id موجود).

   التشغيل: php ops/fix_shift_advance_txn.php <shiftId> [storeId] [--apply]
            من غير --apply = معاينة بس.
═══════════════════════════════════════════════════════════════ */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\WireTime;
use Illuminate\Support\Facades\DB;

$args    = array_values(array_filter(array_slice($argv, 1), fn ($a) => ! str_starts_with($a, '--')));
$apply   = in_array('--apply', $argv, true);
$shiftId = (int) ($args[0] ?? 0);
$storeIn = isset($args[1]) ? (int) $args[1] : 0;
if ($shiftId <= 0) {
    fwrite(STDERR, "الاستعمال: php ops/fix_shift_advance_txn.php <shiftId> [storeId] [--apply]\n");
    exit(1);
}

$s = DB::selectOne('SELECT s.*, p.name AS pilot_name FROM shifts s JOIN pilots p ON p.id = s.pilot_id WHERE s.id = ?', [$shiftId]);
if (! $s) {
    fwrite(STDERR, "الوردية {$shiftId} مش موجودة\n");
    exit(1);
}
$amount = round((float) $s->advance_amount, 2);
if ($amount <= 0) {
    fwrite(STDERR, "الوردية مافيهاش سلفة (advance_amount = {$amount})\n");
    exit(1);
}
if ($s->advance_txn_id !== null) {
    fwrite(STDERR, "السلفة دي اتصرفت خلاص (حركة #{$s->advance_txn_id}) — مفيش حاجة تتعمل\n");
    exit(2);
}
$storeId = $storeIn;
if (! $storeId && $s->commission_paid_at !== null) {
    $ct = DB::selectOne("SELECT store_id FROM cash_transactions WHERE related_pilot_id = ? AND type = 'out' AND reason LIKE 'عمولة وردية%' AND created_at = ? ORDER BY id DESC LIMIT 1",
        [(int) $s->pilot_id, $s->commission_paid_at]);
    $storeId = $ct ? (int) $ct->store_id : 0;
}
$store = $storeId ? DB::selectOne('SELECT id, name, branch_id, balance FROM cash_stores WHERE id = ?', [$storeId]) : null;
if (! $store) {
    fwrite(STDERR, "ماقدرتش أحدّد الخزنة — مرّر رقمها كوسيطة تانية\n");
    exit(1);
}
echo "الوردية #{$shiftId} · {$s->pilot_name} · سلفة {$amount} ج.م (سبب: " . ($s->advance_reason ?? '—') . ") · انتهت {$s->ended_at} بواسطة {$s->ended_by}\n";
echo "الخزنة: #{$store->id} {$store->name} (رصيد " . number_format((float) $store->balance, 2) . ")\n";
if (! $apply) {
    echo "(معاينة — زوّد --apply للتنفيذ)\n";
    exit(0);
}
DB::transaction(function () use ($s, $store, $amount, $shiftId): void {
    DB::select('SELECT id FROM cash_stores WHERE id = ? FOR UPDATE', [(int) $store->id]);
    $now = WireTime::nowDb();
    DB::update('UPDATE cash_stores SET balance = balance - ? WHERE id = ?', [$amount, (int) $store->id]);
    DB::insert(
        'INSERT INTO cash_transactions (store_id, type, amount, reason, notes, related_pilot_id, branch_id, created_by, created_at)
         VALUES (?,?,?,?,?,?,?,?,?)',
        [(int) $store->id, 'out', $amount, 'سلفة وردية: ' . $s->pilot_name,
         'تصحيح: السلفة اتسجّلت في تقفيلة الوردية #' . $shiftId . ' يوم ' . substr((string) $s->ended_at, 0, 10) . ' قبل ما التقفيلة تبقى بتصرفها من الخزنة',
         (int) $s->pilot_id, $s->branch_id !== null ? (int) $s->branch_id : null, 'ops/fix_shift_advance_txn', $now]
    );
    $txnId = (int) DB::getPdo()->lastInsertId();
    DB::update('UPDATE shifts SET advance_txn_id = ? WHERE id = ?', [$txnId, $shiftId]);
    echo "✅ حركة منصرف #{$txnId} بمبلغ {$amount} من الخزنة {$store->name} — الرصيد بقى " .
        number_format((float) DB::selectOne('SELECT balance FROM cash_stores WHERE id = ?', [(int) $store->id])->balance, 2) . "\n";
});
