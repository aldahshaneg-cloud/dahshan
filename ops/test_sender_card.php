<?php
/**
 * 🧾 حارس: بطاقة العميل / التاجر في فورم الطلب — الكول سنتر والإدارة والفروع (2026-09-06).
 *
 * ═══ الطلب (صاحب النظام) ═══
 * «لما الكول سنتر يكتب بيانات المرسل اللي ممكن يكون تاجر مش بيعرف يعدّل عليها:
 * فورم بيانات العميل يتعدّل، ويضيف بيانات تانية زي عنوان آخر، وتحته كل الأوردرات
 * السابقة» — ونفس الشغل في الإدارة والفروع.
 *
 * ═══ العقود المثبتة ═══
 * • PUT senders/{id} **وreceivers/{id}** بيقبلوا extraAddresses [{label, address}] وnotes
 *   ويرجّعوهم؛ الفاضي بيتمسح؛ المفتاح الغايب مايلمسش (نفس البطاقة للمستلم — طلب تاني نفس اليوم).
 * • السلك بيطلّع extraAddresses/notes **بس** لما يكونوا موجودين (عقد السلك المجمّد).
 * • GET orders?senderId= / ?receiverId= للموظفين بيرجّعوا أوردرات العميل ده بس؛ مشرف الفرع على فرعه.
 * • التلات صفحات فيها زرار «بطاقة العميل» والمودال وقايمة الأوردرات السابقة.
 * كله جوه معاملة بتترجع.
 *
 * التشغيل: php ops/test_sender_card.php
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

use App\Wire\CoreWire;
use Illuminate\Support\Facades\DB;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
function hit($kernel, array $u, string $method, string $url, array $body = []): array
{
    $req = Illuminate\Http\Request::create($url, $method, [], [], [], [], $body ? json_encode($body, JSON_UNESCAPED_UNICODE) : null);
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

$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
$cc    = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'callcenter' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
if (! $admin) { echo "مافيش أدمن — تخطّي\n"; exit(0); }
$branches = array_map(fn ($r) => (int) $r->id, DB::select('SELECT id FROM branches ORDER BY id LIMIT 2'));
$bA = $branches[0]; $bB = $branches[1] ?? $bA;
$now = gmdate('Y-m-d H:i:s');

echo "══ 0) السلك ══\n";
$plain = CoreWire::sender(['id' => 1, 'name' => 'ن', 'phone1' => '01000000000', 'phone2' => null, 'address' => null, 'created_by' => null, 'source' => null, 'created_at' => $now, 'extra_addresses' => null, 'notes' => null]);
ok('🔴 صف من غير عناوين إضافية = نفس شكل السلك القديم (مافيش مفاتيح زيادة)', ! array_key_exists('extraAddresses', $plain) && ! array_key_exists('notes', $plain), implode(',', array_keys($plain)));
$rich = CoreWire::sender(['id' => 1, 'name' => 'ن', 'phone1' => '01000000000', 'phone2' => null, 'address' => null, 'created_by' => null, 'source' => null, 'created_at' => $now,
    'extra_addresses' => json_encode([['label' => 'المخزن', 'address' => 'شارع ١']], JSON_UNESCAPED_UNICODE), 'notes' => 'تاجر']);
ok('وبالعناوين والملاحظات بيطلّعهم', ($rich['extraAddresses'][0]['label'] ?? '') === 'المخزن' && ($rich['notes'] ?? '') === 'تاجر');

DB::beginTransaction();
try {
    DB::insert('INSERT INTO senders (name, phone1, phone2, address, created_by, created_at) VALUES (?,?,?,?,?,?)', ['تاجر فحص', '01099999999', null, 'العنوان الأصلي', 'test', $now]);
    $sid = (int) DB::getPdo()->lastInsertId();
    DB::insert('INSERT INTO senders (name, phone1, created_by, created_at) VALUES (?,?,?,?)', ['تاجر تاني', '01088888888', 'test', $now]);
    $sid2 = (int) DB::getPdo()->lastInsertId();

    echo "\n══ 1) التعديل بعناوين إضافية وملاحظات ══\n";
    [$c, $j] = hit($kernel, $admin, 'PUT', "/api/senders/{$sid}", ['name' => 'تاجر فحص', 'phone1' => '01099999999', 'phone2' => '01077777777', 'address' => 'العنوان الأصلي',
        'notes' => 'بيستلم بعد الظهر', 'extraAddresses' => [['label' => 'المخزن', 'address' => 'شارع المخزن ١'], ['label' => '', 'address' => '   '], ['label' => 'الفرع', 'address' => 'شارع الفرع ٢']]]);
    ok('التعديل 200', $c === 200, (string) $c . ' ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    $it = $j['item'] ?? [];
    ok('🔴 العناوين رجعت (الفاضي اتشال) والملاحظات', count($it['extraAddresses'] ?? []) === 2 && ($it['extraAddresses'][1]['label'] ?? '') === 'الفرع' && ($it['notes'] ?? '') === 'بيستلم بعد الظهر', json_encode($it, JSON_UNESCAPED_UNICODE));
    $row = DB::selectOne('SELECT extra_addresses, notes FROM senders WHERE id = ?', [$sid]);
    ok('واتخزنوا JSON في القاعدة', str_contains((string) $row->extra_addresses, 'المخزن') && $row->notes === 'بيستلم بعد الظهر');
    [$c, $j] = hit($kernel, $admin, 'PUT', "/api/senders/{$sid}", ['name' => 'تاجر فحص', 'phone1' => '01099999999']);
    ok('تعديل من غير المفتاحين مابيلمسهمش', $c === 200 && count($j['item']['extraAddresses'] ?? []) === 2);
    [$c, $j] = hit($kernel, $admin, 'PUT', "/api/senders/{$sid}", ['name' => 'تاجر فحص', 'phone1' => '01099999999', 'extraAddresses' => [], 'notes' => '']);
    ok('القايمة الفاضية بتمسح — والمفاتيح بتختفي من السلك', $c === 200 && ! array_key_exists('extraAddresses', $j['item']) && ! array_key_exists('notes', $j['item']), json_encode($j['item'] ?? null, JSON_UNESCAPED_UNICODE));
    DB::insert('INSERT INTO receivers (name, phone1, created_by, created_at) VALUES (?,?,?,?)', ['مستلم فحص', '01066666666', 'test', $now]);
    $rid = (int) DB::getPdo()->lastInsertId();
    [$c, $j] = hit($kernel, $admin, 'PUT', "/api/receivers/{$rid}", ['name' => 'مستلم فحص', 'phone1' => '01066666666', 'notes' => 'الدور التالت', 'extraAddresses' => [['label' => 'الشغل', 'address' => 'شارع الشغل']]]);
    ok('🔴 المستلم كمان ليه عناوين إضافية وملاحظات', $c === 200 && ($j['item']['extraAddresses'][0]['label'] ?? '') === 'الشغل' && ($j['item']['notes'] ?? '') === 'الدور التالت', json_encode($j['item'] ?? $j, JSON_UNESCAPED_UNICODE));
    $rw = DB::selectOne('SELECT extra_addresses, notes FROM receivers WHERE id = ?', [$rid]);
    ok('واتخزنوا في جدول المستلمين', str_contains((string) $rw->extra_addresses, 'الشغل') && $rw->notes === 'الدور التالت');

    echo "\n══ 2) أوردرات العميل السابقة ══\n";
    $mk = function (int $senderId, int $branch, string $num) use ($now): int {
        DB::insert("INSERT INTO orders (order_num, branch_id, origin_branch_id, sender_id, sender_name, sender_phone, status, status_since, created_at, total_delivery_price)
                    VALUES (?,?,?,?,'تاجر فحص','01099999999','delivered',?,?,25)", [$num, $branch, $branch, $senderId, $now, $now]);
        return (int) DB::getPdo()->lastInsertId();
    };
    $oid1 = $mk($sid, $bA, 'TST-SC-1'); $mk($sid, $bB, 'TST-SC-2'); $oid3 = $mk($sid2, $bA, 'TST-SC-3');
    DB::insert('INSERT INTO order_deliveries (order_id, parcel_no, receiver_id, receiver_name, receiver_phone, created_at) VALUES (?,?,?,?,?,?)', [$oid1, 1, $rid, 'مستلم فحص', '01066666666', $now]);
    DB::insert('INSERT INTO order_deliveries (order_id, parcel_no, receiver_id, receiver_name, receiver_phone, created_at) VALUES (?,?,?,?,?,?)', [$oid3, 1, $rid, 'مستلم فحص', '01066666666', $now]);
    [$c, $L] = hit($kernel, $admin, 'GET', "/api/orders?receiverId={$rid}&limit=100");
    $nums = array_map(fn ($o) => $o['orderNum'], $L['items'] ?? []);
    sort($nums);
    ok('🔴 أوردرات المستلم السابقة = اللي فيها طرد ليه (٢ من مرسلين مختلفين)', $c === 200 && $nums === ['TST-SC-1', 'TST-SC-3'], json_encode($nums));
    [$c, $L] = hit($kernel, $admin, 'GET', "/api/orders?senderId={$sid}&limit=100");
    $nums = array_map(fn ($o) => $o['orderNum'], $L['items'] ?? []);
    ok('🔴 الأدمن بيشوف أوردرات العميل ده بس (٢)', $c === 200 && count($nums) === 2 && ! in_array('TST-SC-3', $nums, true), json_encode($nums));
    if ($cc) {
        [$c, $L] = hit($kernel, $cc, 'GET', "/api/orders?senderId={$sid}&limit=100");
        ok('والكول سنتر كمان', $c === 200 && count($L['items'] ?? []) === 2, (string) $c);
    }
    $sup = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'branch' AND blocked = 0 AND branch_id = ? ORDER BY id LIMIT 1", [$bA])[0] ?? null);
    if ($sup && $bA !== $bB) {
        [$c, $L] = hit($kernel, $sup, 'GET', "/api/orders?senderId={$sid}&limit=100");
        $nums = array_map(fn ($o) => $o['orderNum'], $L['items'] ?? []);
        ok('مشرف الفرع بيشوف أوردرات العميل عند فرعه بس (١)', $c === 200 && $nums === ['TST-SC-1'], json_encode($nums));
    }
} finally {
    DB::rollBack();
}

echo "\n══ 3) الواجهات ══\n";
foreach (['callcenter', 'tiar', 'branch'] as $pg) {
    $html = file_get_contents($ROOT . "/public/{$pg}.html");
    ok("{$pg}: زرار «بطاقة العميل» بدل التعديل القديم", str_contains($html, 'onclick="openSenderCard()"') && ! str_contains($html, "onclick=\"openInlineEdit('sender')\""));
    ok("{$pg}: 🔴 ونفس البطاقة لعميل التسليم في كل طرد", str_contains($html, 'onclick="openReceiverCard(${n})"') && ! str_contains($html, "openInlineEdit('receiver'") && str_contains($html, 'window.openReceiverCard = function(n) { return openPartyCard("receivers", SC_RECV(n)); }'));
    ok("{$pg}: حقول المستلم بتشاور على صف الطرد الصح", str_contains($html, $pg === 'branch' ? 'search: `bDRecv-${n}`, phone: `bDPhone-${n}`, phone2: `bDPhone2-${n}`, addr: `bDAddr-${n}`' : 'search: `dRecv-${n}`, phone: `dPhone-${n}`, phone2: `dPhone2-${n}`, addr: `dAddr-${n}`'));
    ok("{$pg}: المودال فيه العناوين الإضافية والملاحظات والأوردرات السابقة", str_contains($html, 'id="modal-sender-card"') && str_contains($html, 'id="sc-extra"') && str_contains($html, 'id="sc-notes"') && str_contains($html, 'id="sc-orders"'));
    ok("{$pg}: الحفظ بيبعت extraAddresses وnotes وبيجيب الأوردرات بـsenderId", str_contains($html, '{ name, phone1, phone2, address, notes, extraAddresses }') && str_contains($html, '/api/orders?${kind === "receivers" ? "receiverId" : "senderId"}=') && str_contains($html, '/api/${window._sc.kind}/${window._sc.id}'));
    ok("{$pg}: «استخدم» بيحط العنوان في الطلب", str_contains($html, 'window.scUseExtra = function(i)'));
}

echo "\n════════════════════════════════════════\n";
echo "SENDER CARD: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
