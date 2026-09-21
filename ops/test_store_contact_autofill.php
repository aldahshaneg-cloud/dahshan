<?php
/**
 * 🏪 حارس: تطبيق المحلات — قائمة اقتراحات الرقم · ملء كل أماكن العنوان · الرفريش اللي بيطلّع لفوق
 * (بلاغ صاحب النظام 2026-09-22).
 *
 * ═══ العقود ═══
 * • قائمة اقتراحات **الرقم** تحت صف التليفون (acDropP) مش تحت الاسم — كانت بتغطّي الرقم وهو بيتكتب.
 * • الاختيار من القائمة / «ابعت له» / كتابة الرقم كامل → `applyContactToRow`: الاسم · هاتف ٢ ·
 *   المنطقة · العنوان · دبوس الخريطة. الكتابة الكاملة بتملّي الفاضي بس ومرة واحدة لكل رقم.
 * • POST /api/store-contacts + upsert: نفس الرقم في نفس الدفتر بيتحدّث (مفيش تكرار)، والقيمة الفاضية
 *   ماتمسحش المحفوظ، والدبوس بيتحفظ ويرجع على السلك. من غير upsert السلوك القديم (صف جديد).
 * • /api/zones و/api/store-contacts ترتيبهم **حتمي** (… , id) — الترتيب المتقلّب بين المناطق اللي
 *   بنفس الاسم كان بيغيّر بصمة الرد كل دقيقة فكل التطبيقات تعيد الرسم والصفحة تنط لفوق.
 * • `renderParcelTabs` مابتندهش scrollIntoView (تمرير أفقي جوه الشريط بس) · بولر المناطق بـ?since.
 * التشغيل: php ops/test_store_contact_autofill.php   (تنفيذ حقيقي جوه معاملة بتترجع)
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

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

echo "══ 0) الواجهة ══\n";
$S = str_replace("\r\n", "\n", file_get_contents($ROOT . '/public/store.html'));
ok('قائمة الرقم ليها عنصرها تحت صف التليفون', str_contains($S, '<div class="ac-drop" id="acDropP-${n}"></div>')
    && str_contains($S, '<div class="ac-anchor">') && str_contains($S, '.ac-anchor { position: relative; }'));
$phoneRow = substr($S, (int) strpos($S, '<div class="ac-anchor">'), 900);
ok('  وهي **بعد** خانة الرقم في القالب (مش قبلها)', strpos($phoneRow, 'id="rPhone-${n}"') !== false
    && strpos($phoneRow, 'id="acDropP-${n}"') > strpos($phoneRow, 'id="rPhone-${n}"'));
ok('  و`filterContacts` بتختار القائمة حسب الخانة وبتقفل التانية', str_contains($S, 'const drop    = field === "phone" ? drops.phone : drops.name;')
    && str_contains($S, 'if (other) { other.innerHTML = ""; other.style.display = "none"; }'));
ok('  والرقم بيتقارن أرقام بأرقام (عربي/مسافات/+20)', str_contains($S, '_acDigits(c.phone).includes(q)') && str_contains($S, 'window.cleanPhone(v)'));
ok('ملّاية واحدة لكل أماكن العميل', str_contains($S, 'window.applyContactToRow = function (n, c, opts) {')
    && str_contains($S, 'window._geoPins[`r${n}`] = { lat, lng };')
    && str_contains($S, 'zEl.dispatchEvent(new Event("change"))')
    && str_contains($S, 'window.setAddressValue(`rAddrWidget-${n}`, c.address);'));
ok('  بتتنده من الاختيار ومن «ابعت له» ومن الرقم الكامل', substr_count($S, 'window.applyContactToRow(n, c, { onlyEmpty: false })') === 2
    && str_contains($S, 'window.applyContactToRow(n, exact, { onlyEmpty: true })'));
ok('  والرقم الكامل بيملّي مرة واحدة بس لكل رقم', str_contains($S, 'phoneEl.dataset.acFilled !== q') && str_contains($S, 'phoneEl.dataset.acFilled = q;'));
ok('الحفظ بقى upsert ومعاه الدبوس', str_contains($S, 'upsert: true,') && str_contains($S, '...(lat != null && lng != null ? { lat, lng } : {})')
    && str_contains($S, 'lat: d.lat, lng: d.lng,'));
$tabs = substr($S, (int) strpos($S, 'window.renderParcelTabs = function'), 3200);
ok('🔴 `renderParcelTabs` مابتندهش scrollIntoView (كانت بتسحب الصفحة لفوق مع كل إعادة رسم)',
    ! preg_match('/\.scrollIntoView\(/', $tabs) && str_contains($tabs, 'box.scrollLeft +='));
ok('🔴 ردّ بحث الثقة القديم مايكتبش في طرد جديد بنفس الـid بعد الإرسال', str_contains($S, 'const _phEl = document.getElementById(`rPhone-${n}`);')
    && str_contains($S, 'if (_phEl && !_phEl.isConnected) return;'));
ok('بولر المناطق بـ?since', str_contains($S, 'reg(new api.Poller("/api/zones", { interval: 60000, onChange: d => {')
    && ! str_contains($S, 'api.Poller("/api/zones", { interval: 60000, useSince: false'));

echo "\n══ 1) السيرفر ══\n";
$C = file_get_contents($ROOT . '/app/Http/Controllers/Api/EntitiesController.php');
ok('ترتيب المناطق ودفتر المحل والطيارين حتمي', str_contains($C, 'ORDER BY z.area_name, z.id')
    && str_contains($C, 'WHERE store_username = ? ORDER BY name, id') && str_contains($C, 'ORDER BY p.name, p.id'));

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
function hit($kernel, array $u, string $method, string $url, array $body = []): array
{
    $req = Request::create($url, $method, [], [], [], [], $body ? json_encode($body) : null);
    $req->headers->set('Accept', 'application/json');
    if ($body) { $req->headers->set('Content-Type', 'application/json'); }
    $ses = app('session')->driver();
    $ses->flush();
    $ses->start();
    $ses->put(['user_id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role'],
               'branch_id' => $u['branch_id'] ?? null, 'name' => $u['username']]);
    $req->setLaravelSession($ses);
    $res = $kernel->handle($req);

    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
}

$store = (array) (DB::selectOne("SELECT id, username, role, branch_id FROM users WHERE role = 'store' AND blocked = 0 ORDER BY id LIMIT 1") ?? []);
$zone  = DB::selectOne('SELECT id FROM zones ORDER BY id LIMIT 1');
if (! $store || ! $zone) {
    echo "  ⚠ مفيش محل/منطقة في القاعدة دي — فحوص التنفيذ اتخطّت\n";
} else {
    DB::beginTransaction();
    try {
        $phone = '0109' . random_int(1000000, 9999999);
        $mine  = fn () => DB::select('SELECT * FROM store_contacts WHERE store_username = ? AND phone = ? ORDER BY id', [$store['username'], $phone]);

        [$c1, $r1] = hit($kernel, $store, 'POST', '/api/store-contacts', ['upsert' => true, 'name' => 'عميل حارس', 'phone' => $phone]);
        ok('أول شحنة (من غير عنوان ولا دبوس) بتضيف صف', $c1 === 200 && ! empty($r1['id']) && empty($r1['updated']) && count($mine()) === 1, json_encode($r1, JSON_UNESCAPED_UNICODE));

        [$c2, $r2] = hit($kernel, $store, 'POST', '/api/store-contacts', ['upsert' => true, 'name' => 'عميل حارس ٢', 'phone' => $phone,
            'phone2' => '0502222222', 'address' => 'شارع الحارس ٥', 'zoneId' => (int) $zone->id, 'lat' => 31.0412345, 'lng' => 31.3654321]);
        $row = (array) ($mine()[0] ?? []);
        ok('تاني شحنة لنفس الرقم بتحدّث نفس الصف — مفيش تكرار', $c2 === 200 && ($r2['updated'] ?? false) === true
            && (int) $r2['id'] === (int) $r1['id'] && count($mine()) === 1, json_encode($r2, JSON_UNESCAPED_UNICODE));
        ok('  وكل أماكن العنوان اتحفظت', ($row['name'] ?? '') === 'عميل حارس ٢' && ($row['address'] ?? '') === 'شارع الحارس ٥'
            && (int) ($row['zone_id'] ?? 0) === (int) $zone->id && ($row['phone2'] ?? '') === '0502222222'
            && abs((float) $row['lat'] - 31.0412345) < 1e-6 && abs((float) $row['lng'] - 31.3654321) < 1e-6, json_encode($row, JSON_UNESCAPED_UNICODE));

        [$c3] = hit($kernel, $store, 'POST', '/api/store-contacts', ['upsert' => true, 'name' => 'عميل حارس ٢', 'phone' => $phone, 'address' => '', 'zoneId' => null]);
        $row = (array) ($mine()[0] ?? []);
        ok('🔴 شحنة من غير عنوان/منطقة/دبوس ماتمسحش المحفوظ', $c3 === 200 && ($row['address'] ?? '') === 'شارع الحارس ٥'
            && (int) ($row['zone_id'] ?? 0) === (int) $zone->id && $row['lat'] !== null, json_encode($row, JSON_UNESCAPED_UNICODE));

        [$c4] = hit($kernel, $store, 'POST', '/api/store-contacts', ['upsert' => true, 'name' => 'عميل حارس ٢', 'phone' => $phone, 'lat' => 999, 'lng' => 31]);
        $row = (array) ($mine()[0] ?? []);
        ok('دبوس بره حدود الكرة بيتسقط بصمت والمحفوظ يفضل', $c4 === 200 && abs((float) $row['lat'] - 31.0412345) < 1e-6);

        [$c5, $r5] = hit($kernel, $store, 'GET', '/api/store-contacts');
        $w = array_values(array_filter($r5['items'] ?? [], fn ($x) => ($x['phone'] ?? '') === $phone))[0] ?? [];
        ok('الدبوس راجع على السلك (lat/lng) مع المنطقة', $c5 === 200 && isset($w['lat'], $w['lng']) && abs($w['lat'] - 31.0412345) < 1e-6
            && (int) ($w['zoneId'] ?? 0) === (int) $zone->id, json_encode($w, JSON_UNESCAPED_UNICODE));

        [$c6, $r6] = hit($kernel, $store, 'POST', '/api/store-contacts', ['name' => 'من غير العلم', 'phone' => $phone]);
        ok('من غير `upsert` السلوك القديم بالحرف: صف جديد', $c6 === 200 && empty($r6['updated']) && count($mine()) === 2);

        /* الترتيب الحتمي: نداءين ورا بعض لازم يرجّعوا نفس ترتيب المعرّفات بالظبط */
        [, $z1] = hit($kernel, $store, 'GET', '/api/zones');
        [, $z2] = hit($kernel, $store, 'GET', '/api/zones');
        $ids = fn ($d) => array_map(fn ($x) => $x['id'], $d['items'] ?? []);
        ok('/api/zones نفس الترتيب في كل نداء', $ids($z1) === $ids($z2) && count($ids($z1)) > 0);
        $items = $z1['items'] ?? [];
        $sorted = true;
        for ($i = 1, $n = count($items); $i < $n; $i++) {
            if (($items[$i]['areaName'] ?? null) === ($items[$i - 1]['areaName'] ?? null) && (int) $items[$i]['id'] < (int) $items[$i - 1]['id']) { $sorted = false; break; }
        }
        ok('  والمناطق اللي بنفس الاسم مترتبة بالمعرّف', $sorted);
    } finally {
        DB::rollBack();
    }
}

echo "\n" . str_repeat('─', 52) . "\n";
echo ($fail ? '❌' : '✅') . " محلات — الاقتراحات والملء والرفريش: {$pass} نجح · {$fail} فشل\n";
exit($fail ? 1 : 0);
