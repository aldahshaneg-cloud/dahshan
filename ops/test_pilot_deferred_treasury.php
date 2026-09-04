<?php
/**
 * 💰 حارس: السلفة المؤجلة مربوطة بحركة خزنة — تنفيذ حقيقي.
 *
 * ═══ الطلب (صاحب النظام 2026-09-04) ═══
 * من مراجعة «إيه الناقص عشان يُعتمد عليه في حسابات الشركة»: السلفة كانت
 * بتتسجّل في الدفتر والفلوس بتخرج من الخزنة من غير ما الخزنة تعرف.
 *
 * ═══ العقود المثبتة ═══
 * • تسجيل بخزنة: رصيدها بينقص بالمبلغ وحركة out بسبب «سلفة مؤجلة: <الطيار>»
 *   مربوطة بالطيار، والسجل بيشاور على الحركة، والرد بيقول storeName.
 * • تسجيل من غير خزنة: زي زمان — ولا حركة.
 * • خزنة رصيدها مايكفيش → مرفوض 400 والسجل مابيتكتبش (معاملة واحدة).
 * • تعديل مبلغ/طيار سلفة خرجت من خزنة → 409؛ تعديل القسط/البداية عادي.
 * • الحذف بيرجّع الفلوس بحركة in «إلغاء سلفة مؤجلة» ويشيل السجل.
 * • مشرف فرع/محاسب: 403 حتى بمفتاح act.deferred مكتوب — مفتاح إداري.
 * كله جوه معاملة بتترجع.
 *
 * التشغيل: php ops/test_pilot_deferred_treasury.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;

function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$what}\n"; }
    else { $fail++; echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n"; }
}

require $ROOT . '/vendor/autoload.php';
$app = require $ROOT . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): void {
    echo "\n🔴 استثناء مش متمسك: " . get_class($e) . ' — ' . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
});
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        exit(1);
    }
});

use Illuminate\Support\Facades\DB;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
function hit($kernel, array $u, string $method, string $url, array $body = []): array
{
    $req = Illuminate\Http\Request::create($url, $method, [], [], [], [], $body ? json_encode($body) : null);
    $req->headers->set('Accept', 'application/json');
    if ($body) { $req->headers->set('Content-Type', 'application/json'); }
    $ses = app('session')->driver();
    $ses->start();
    $ses->put(['user_id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role'],
               'branch_id' => $u['branch_id'] ?? null, 'name' => $u['username']]);
    $req->setLaravelSession($ses);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}
$near = fn ($a, $b) => abs((float) $a - (float) $b) < 0.011;

$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
if (! $admin) { echo "مافيش أدمن — تخطّي\n"; exit(0); }
$ym = date('Y-m', strtotime('+1 month'));   // شهر مستقبلي — مش مقفول أبدًا

DB::beginTransaction();
try {
    $branches = array_map(fn ($r) => (int) $r->id, DB::select('SELECT id FROM branches ORDER BY id LIMIT 2'));
    $b1 = $branches[0] ?? 0;
    $b2 = $branches[1] ?? $b1;
    $pilot = DB::selectOne('SELECT id, name, assigned_branch_id FROM pilots ORDER BY id LIMIT 1');
    ok('فيه طيار', $pilot !== null);
    $pid = (int) $pilot->id;
    DB::delete('DELETE FROM pilot_deferred_advances WHERE pilot_id = ?', [$pid]);

    DB::insert('INSERT INTO cash_stores (name, branch_id, balance, created_at) VALUES (?,?,?,NOW())', ['خزنة فحص السلف', $b1 ?: null, 10000]);
    $store = (int) DB::getPdo()->lastInsertId();
    DB::insert('INSERT INTO cash_stores (name, branch_id, balance, created_at) VALUES (?,?,?,NOW())', ['خزنة فاضية', $b1 ?: null, 100]);
    $empty = (int) DB::getPdo()->lastInsertId();
    $bal = fn (int $id) => (float) DB::selectOne('SELECT balance FROM cash_stores WHERE id = ?', [$id])->balance;
    $txnCount = fn () => (int) DB::selectOne('SELECT COUNT(*) c FROM cash_transactions WHERE store_id IN (?,?)', [$store, $empty])->c;

    echo "\n══ 1) سلفة من خزنة ══\n";
    [$c, $j] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/deferred', [
        'pilotId' => $pid, 'amount' => 3000, 'monthly' => 500, 'startMonth' => $ym, 'note' => 'فحص', 'cashStoreId' => $store,
    ]);
    ok('التسجيل 200', $c === 200, (string) $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    $advId = (int) ($j['id'] ?? 0);
    ok('🔴 رصيد الخزنة نقص 3000', $near($bal($store), 7000), (string) $bal($store));
    $t = DB::selectOne('SELECT id, type, amount, reason, notes, related_pilot_id, branch_id, created_by FROM cash_transactions WHERE store_id = ? ORDER BY id DESC LIMIT 1', [$store]);
    ok('حركة out بسبب «سلفة مؤجلة» وباسم الطيار', $t && $t->type === 'out' && $near($t->amount, 3000) && str_starts_with($t->reason, 'سلفة مؤجلة: ') && str_contains($t->reason, (string) $pilot->name), $t ? $t->reason : 'null');
    ok('الحركة مربوطة بالطيار وفرعه ومين سجّلها', $t && (int) $t->related_pilot_id === $pid && (int) $t->branch_id === (int) $pilot->assigned_branch_id && $t->created_by === $admin['username'] && $t->notes === 'فحص');
    $rec = DB::selectOne('SELECT store_id, txn_id FROM pilot_deferred_advances WHERE id = ?', [$advId]);
    ok('🔴 السجل بيشاور على الخزنة والحركة', $rec && (int) $rec->store_id === $store && (int) $rec->txn_id === (int) $t->id);
    [, $lst] = hit($kernel, $admin, 'GET', "/api/pilot-accounting/deferred?month={$ym}");
    $item = null;
    foreach ($lst['items'] ?? [] as $x) { if ((int) $x['id'] === $advId) { $item = $x; } }
    ok('الرد فيه storeName وtxnId', $item && $item['storeName'] === 'خزنة فحص السلف' && (int) $item['storeId'] === $store && (int) $item['txnId'] === (int) $t->id, json_encode($item, JSON_UNESCAPED_UNICODE));
    ok('وأقساطها شغّالة زي ماهي (500 في الشهر)', $item && $near($item['monthDue'], 500) && $near($item['remaining'], 2500));

    echo "\n══ 2) من غير خزنة = زي زمان ══\n";
    $n0 = $txnCount();
    [$c, $j] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/deferred', ['pilotId' => $pid, 'amount' => 200, 'monthly' => 100, 'startMonth' => $ym]);
    ok('التسجيل 200', $c === 200, (string) $c);
    ok('🔴 ولا حركة خزنة', $txnCount() === $n0 && $near($bal($store), 7000));
    $plainId = (int) $j['id'];
    $r2 = DB::selectOne('SELECT store_id, txn_id FROM pilot_deferred_advances WHERE id = ?', [$plainId]);
    ok('السجل من غير خزنة', $r2 && $r2->store_id === null && $r2->txn_id === null);

    echo "\n══ 3) الرصيد مايكفيش ══\n";
    $cnt = (int) DB::selectOne('SELECT COUNT(*) c FROM pilot_deferred_advances WHERE pilot_id = ?', [$pid])->c;
    [$c, $j] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/deferred', ['pilotId' => $pid, 'amount' => 500, 'monthly' => 100, 'startMonth' => $ym, 'cashStoreId' => $empty]);
    ok('🔴 مرفوض 400 والرسالة عن الرصيد', $c === 400 && str_contains((string) json_encode($j, JSON_UNESCAPED_UNICODE), 'مايكفيش'), (string) $c);
    ok('🔴 والسجل مااتكتبش والرصيد زي ماهو (معاملة واحدة)', (int) DB::selectOne('SELECT COUNT(*) c FROM pilot_deferred_advances WHERE pilot_id = ?', [$pid])->c === $cnt && $near($bal($empty), 100));
    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/deferred', ['pilotId' => $pid, 'amount' => 50, 'monthly' => 10, 'startMonth' => $ym, 'cashStoreId' => 999999]);
    ok('خزنة مش موجودة → 404', $c === 404, (string) $c);

    echo "\n══ 4) التعديل بعد ما الفلوس خرجت ══\n";
    [$c, $j] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/deferred', ['id' => $advId, 'pilotId' => $pid, 'amount' => 3500, 'monthly' => 500, 'startMonth' => $ym]);
    ok('🔴 تغيير المبلغ → 409', $c === 409 && str_contains((string) json_encode($j, JSON_UNESCAPED_UNICODE), 'خرجت من الخزنة'), (string) $c);
    ok('والمبلغ فضل 3000', $near(DB::selectOne('SELECT amount FROM pilot_deferred_advances WHERE id = ?', [$advId])->amount, 3000));
    [$c] = hit($kernel, $admin, 'POST', '/api/pilot-accounting/deferred', ['id' => $advId, 'pilotId' => $pid, 'amount' => 3000, 'monthly' => 750, 'startMonth' => $ym, 'note' => 'قسط أكبر']);
    ok('تغيير القسط والملاحظة بنفس المبلغ → 200', $c === 200, (string) $c);
    $r3 = DB::selectOne('SELECT monthly, note, store_id, txn_id FROM pilot_deferred_advances WHERE id = ?', [$advId]);
    ok('القسط اتغيّر والربط بالخزنة فضل', $r3 && $near($r3->monthly, 750) && $r3->note === 'قسط أكبر' && (int) $r3->store_id === $store && $r3->txn_id !== null);
    ok('ولا حركة خزنة جديدة من التعديل', $near($bal($store), 7000));

    echo "\n══ 5) الحذف بيرجّع الفلوس ══\n";
    [$c] = hit($kernel, $admin, 'DELETE', '/api/pilot-accounting/deferred/' . $advId);
    ok('الحذف 200', $c === 200, (string) $c);
    ok('🔴 الرصيد رجع 10000', $near($bal($store), 10000), (string) $bal($store));
    $t2 = DB::selectOne('SELECT type, amount, reason, related_pilot_id FROM cash_transactions WHERE store_id = ? ORDER BY id DESC LIMIT 1', [$store]);
    ok('بحركة in «إلغاء سلفة مؤجلة» باسم الطيار', $t2 && $t2->type === 'in' && $near($t2->amount, 3000) && str_starts_with($t2->reason, 'إلغاء سلفة مؤجلة #') && str_contains($t2->reason, (string) $pilot->name) && (int) $t2->related_pilot_id === $pid, $t2 ? $t2->reason : 'null');
    ok('والسجل اتشال', DB::selectOne('SELECT id FROM pilot_deferred_advances WHERE id = ?', [$advId]) === null);
    ok('حركة الصرف الأصلية لسه موجودة (التاريخ مابيتمسحش)', DB::selectOne('SELECT id FROM cash_transactions WHERE id = ?', [(int) $t->id]) !== null);
    $n1 = $txnCount();
    [$c] = hit($kernel, $admin, 'DELETE', '/api/pilot-accounting/deferred/' . $plainId);
    ok('حذف سلفة من غير خزنة → 200 ولا حركة', $c === 200 && $txnCount() === $n1);
    [$c] = hit($kernel, $admin, 'DELETE', '/api/pilot-accounting/deferred/' . $advId);
    ok('حذف مش موجودة → 404', $c === 404, (string) $c);

    echo "\n══ 6) مشرف فرع ══\n";
    /* act.deferred مفتاح إداري (ADMIN_ONLY_KEYS) — حتى لو اتكتب في صلاحيات المشرف
       السيرفر بيرفضه. يعني الفلوس مش هتخرج من خزنة بسلفة إلا بإيد الإدارة. */
    $un = 'dfs_' . substr(bin2hex(random_bytes(3)), 0, 6);
    DB::insert('INSERT INTO users (username, password_hash, role, name, branch_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())', [$un, password_hash('x', PASSWORD_BCRYPT), 'branch', 'فحص', $b1 ?: null]);
    $sup = ['id' => (int) DB::getPdo()->lastInsertId(), 'username' => $un, 'role' => 'branch', 'branch_id' => $b1 ?: null];
    DB::statement('INSERT INTO pilot_acct_perms (user_id, perm_keys, branches, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE perm_keys = VALUES(perm_keys)', [$sup['id'], json_encode(['page.deferred', 'act.deferred']), '[]', 'test']);
    $b4 = $bal($store);
    [$c] = hit($kernel, $sup, 'POST', '/api/pilot-accounting/deferred', ['pilotId' => $pid, 'amount' => 100, 'monthly' => 50, 'startMonth' => $ym, 'cashStoreId' => $store]);
    ok('🔴 مشرف فرع حتى لو اتكتبله act.deferred → 403 (مفتاح إداري)', $c === 403, (string) $c);
    ok('والخزنة ماتحرّكتش', $near($bal($store), $b4));
    $acct = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'accountant' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
    if ($acct) {
        [$c] = hit($kernel, $acct, 'POST', '/api/pilot-accounting/deferred', ['pilotId' => $pid, 'amount' => 100, 'monthly' => 50, 'startMonth' => $ym, 'cashStoreId' => $store]);
        ok('المحاسب كمان 403', $c === 403, (string) $c);
    }
} finally {
    DB::rollBack();
}

echo "\n══ 7) الواجهة ══\n";
$html = file_get_contents($ROOT . '/public/accounts.html');
ok('مودال السلفة فيه اختيار الخزنة', str_contains($html, 'id="dfStore"') && str_contains($html, 'بدون حركة خزنة'));
ok('الحفظ بيبعت cashStoreId', preg_match('/pilot-accounting\/deferred", \{\s*pilotId, amount, monthly, startMonth,[^}]*cashStoreId/s', $html) === 1);
ok('الجدول بيقول من خزنة إيه', str_contains($html, 'a.storeName'));
ok('تنبيه الحذف بيقول إن الفلوس بترجع', str_contains($html, 'هيرجع لخزنة'));
$sql = file_get_contents($ROOT . '/database/schema/mysql-schema.sql');
ok('السكيمة فيها store_id/txn_id على pilot_deferred_advances مع FK', preg_match('/CREATE TABLE `pilot_deferred_advances`.*?`store_id`.*?`txn_id`.*?fk_pilot_deferred_advances_store_id.*?ENGINE/s', $sql) === 1);

echo "\n════════════════════════════════════════\n";
echo "DEFERRED TREASURY: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
