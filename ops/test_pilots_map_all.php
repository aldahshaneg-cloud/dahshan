<?php
/**
 * 🗺️ حارس: «كل الطيارين» لمشرف الفرع (2026-09-09).
 *
 * ═══ اللي حصل ═══
 * زرار «🏢 كل الطيارين» على خريطة الفرع (وقايمة «أضف طيارًا» للطيار الحرّ) بيعتمد على روستر
 * الشركة كله، لكن قفل النطاق (2026-09-04) خلّى `/api/pilots` يرجّع طياري الفرع بس — فالزرار
 * بقى مابيظهرش حاجة.
 *
 * ═══ العقد ═══
 * • مشرف الفرع بلا `?all=1` → طياري فرعه بس (النطاق زي ما هو).
 * • `?all=1` → الروستر كله، وطيار الفرع التاني **مقصوص**: اسم وحالة وفرع ومكان بس —
 *   من غير تليفونات ولا عناوين ولا فلوس. طيار فرعه بكامل بياناته.
 * • الأدمن مش بيتأثر. لوحة الفرع بتطلب `all: 1` مع `trail: 1`.
 *
 * التشغيل: php ops/test_pilots_map_all.php
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

use Illuminate\Support\Facades\DB;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
function hit($kernel, array $u, string $url): array
{
    $req = Illuminate\Http\Request::create($url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $ses = app('session')->driver();
    $ses->flush(); $ses->start();
    $ses->put(['user_id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role'],
               'branch_id' => $u['branch_id'] !== null ? (int) $u['branch_id'] : null, 'name' => $u['username']]);
    $req->setLaravelSession($ses);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$admin = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
$sup   = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'branch' AND blocked = 0 AND branch_id IS NOT NULL ORDER BY id LIMIT 1")[0] ?? null);
if (! $admin || ! $sup) { echo "مافيش أدمن/مشرف فرع — تخطّي\n"; exit(0); }
$own = (int) $sup['branch_id'];

DB::beginTransaction();
try {
    /* طيار في فرع تاني + طيار حرّ بلا فرع — عشان الفرق يبان مهما كانت بيانات القاعدة */
    $other = (int) (DB::select('SELECT id FROM branches WHERE id <> ? ORDER BY id LIMIT 1', [$own])[0]->id ?? 0);
    if ($other === 0) { echo "محتاج فرعين — تخطّي\n"; DB::rollBack(); exit(0); }
    DB::insert("INSERT INTO pilots (name, phone1, assigned_branch_id, home_branch_id, status, created_at) VALUES ('طيار فرع تاني — فحص', '01055555555', ?, ?, 'waiting', NOW())", [$other, $other]);
    $otherPid = (int) DB::getPdo()->lastInsertId();
    DB::insert("INSERT INTO pilots (name, phone1, assigned_branch_id, home_branch_id, status, created_at) VALUES ('طيار حرّ — فحص', '01044444444', NULL, NULL, NULL, NOW())");
    $freePid = (int) DB::getPdo()->lastInsertId();
    DB::insert("INSERT INTO pilots (name, phone1, assigned_branch_id, home_branch_id, status, created_at) VALUES ('طيار فرعي — فحص', '01033333333', ?, ?, 'waiting', NOW())", [$own, $own]);
    $minePid = (int) DB::getPdo()->lastInsertId();

    echo "══ 1) من غير all: النطاق زي ما هو ══\n";
    [$c, $j] = hit($kernel, $sup, '/api/pilots');
    $ids = array_map(fn ($p) => (int) $p['id'], $j['items'] ?? []);
    ok('🔴 مشرف الفرع بيشوف فرعه بس', $c === 200 && in_array($minePid, $ids, true) && ! in_array($otherPid, $ids, true), $c . ' ' . count($ids));

    echo "\n══ 2) all=1: الروستر كله بقصّ بيانات الغريب ══\n";
    [$c, $j] = hit($kernel, $sup, '/api/pilots?all=1&trail=1');
    $by = [];
    foreach ($j['items'] ?? [] as $p) { $by[(int) $p['id']] = $p; }
    ok('🔴 طيار الفرع التاني والطيار الحرّ ظهروا', $c === 200 && isset($by[$otherPid], $by[$freePid], $by[$minePid]), $c . ' ' . count($by));
    $o = $by[$otherPid] ?? [];
    ok('🔴 طيار الفرع التاني مقصوص: مفيش تليفون ولا عنوان ولا فلوس', $o && ! array_key_exists('phone1', $o) && ! array_key_exists('address', $o) && ! array_key_exists('custody', $o) && ! array_key_exists('hourRate', $o) && ! array_key_exists('cardNum', $o), json_encode(array_keys($o)));
    ok('وفيه اسم وحالة وفرع ومكان', $o && ($o['name'] ?? '') === 'طيار فرع تاني — فحص' && array_key_exists('pilotStatus', $o) && (int) ($o['assignedBranchId'] ?? 0) === $other && array_key_exists('location', $o));
    $m = $by[$minePid] ?? [];
    ok('طيار فرعه بكامل بياناته', $m && ($m['phone1'] ?? '') === '01033333333' && array_key_exists('custody', $m) || ($m && ($m['phone1'] ?? '') === '01033333333'), json_encode(array_keys($m)));

    echo "\n══ 3) الأدمن زي ما هو ══\n";
    [$c, $j] = hit($kernel, $admin, '/api/pilots');
    $ap = null; foreach ($j['items'] ?? [] as $p) { if ((int) $p['id'] === $otherPid) { $ap = $p; } }
    ok('الأدمن بياخد الكل بكامل البيانات', $c === 200 && $ap && ($ap['phone1'] ?? '') === '01055555555');
} finally {
    DB::rollBack();
}

echo "\n══ 4) الواجهة ══\n";
$b = file_get_contents($ROOT . '/public/branch.html');
ok('لوحة الفرع بتطلب all=1 مع الأثر', str_contains($b, 'reg(new P("/api/pilots", { interval: 15000, useSince: false, params: { trail: 1, all: 1 }'));

echo "\n════════════════════════════════════════\n";
echo "PILOTS MAP ALL: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail ? 1 : 0);
