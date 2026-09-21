<?php
/**
 * 🧭 حارس: الجيل الرابع (v4) — تطبيق الكول سنتر على Blade، موازي للقديم (طلب صاحب النظام 2026-09-21).
 *
 * «أريد التطوير الرابع… كل شيء على لارافل… نبدأ بتطبيق الكول سنتر فقط… تطبيق موازي للموجود وتترك
 *  الموجود كما هو حتى يكتمل الجديد».
 *
 * ═══ العقود (تنفيذ حقيقي عبر الكيرنل جوه معاملة بتترجع) ═══
 * • الدخول واحد: من غير جلسة = تحويل لـ/v4/login?next=… · دور مش مسموح = 403 بصفحة واضحة · كول سنتر/أدمن = 200.
 * • كل الشاشات الجاهزة بتترسم (Blade بيتكمبل) وفيها سكربتها؛ والصفحات اللي لسه = «قريبًا» بتفتح القديم.
 * • الرئيسية أرقامها من السيرفر بيوم العمل، وبتزيد لما يتسجّل أوردر.
 * • قوايم الأوردرات: فلترة وبحث وترقيم في السيرفر (30/صفحة + إجمالي حقيقي) بشكل OrderWire.
 * • بحث العملاء في السيرفر بالاسم وبالتليفون المنسوخ بمسافات · بيانات الفورم مخفّفة وفيها علامة «البيت».
 * • القديم ماتلمسش: callcenter.html موجود، ومسارات /api زي ما هي (بوابة route:coverage).
 * التشغيل: php ops/test_v4_callcenter.php
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
use Illuminate\Support\Facades\Event;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
function hit($kernel, ?array $u, string $method, string $url, array $body = []): array
{
    $req = Request::create($url, $method, [], [], [], ['HTTP_ACCEPT' => $body || str_contains($url, '-data') || str_contains($url, '/stats') || str_contains($url, '/contacts') ? 'application/json' : 'text/html',
        'CONTENT_TYPE' => 'application/json'], $body ? json_encode($body, JSON_UNESCAPED_UNICODE) : null);
    $ses = app('session')->driver();
    $ses->flush(); $ses->start();
    if ($u) {
        $ses->put(['user_id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role'], 'branch_id' => $u['branch_id'] ?? null, 'name' => $u['username']]);
    }
    $req->setLaravelSession($ses);
    $res = $kernel->handle($req);
    $c = (string) $res->getContent();

    return [$res->getStatusCode(), $c, json_decode($c, true), $res->headers->get('Location')];
}

$cc = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'callcenter' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
$br = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'branch' AND blocked = 0 AND branch_id IS NOT NULL ORDER BY id LIMIT 1")[0] ?? null);
$ad = (array) (DB::select("SELECT id, username, role, branch_id FROM users WHERE role = 'admin' AND blocked = 0 ORDER BY id LIMIT 1")[0] ?? null);
if (! $cc || ! $br || ! $ad) { echo "مافيش كول سنتر/مشرف/أدمن — تخطّي\n"; exit(0); }

echo "══ 0) القديم ماتلمسش والملفات في مكانها ══\n";
ok('callcenter.html القديم موجود زي ما هو', is_file($ROOT . '/public/callcenter.html') && filesize($ROOT . '/public/callcenter.html') > 500000);
foreach (['v4/css/app.css', 'v4/css/cairo.css', 'v4/css/fontawesome.css', 'v4/fonts/cairo-1.woff2', 'v4/webfonts/fa-solid-900.woff2', 'v4/js/core.js', 'v4/js/orders-common.js', 'v4/js/callcenter/embed.js'] as $f) {
    ok("public/{$f}", is_file($ROOT . '/public/' . $f));
}
$core = (string) file_get_contents($ROOT . '/public/v4/js/core.js');
ok('النافذة اللي فيها خانات مابتتقفلش بالضغط برّه · والإجبارية مقفولة', str_contains($core, 'V4.modal.hasFields(e.target)') && str_contains($core, 'hasAttribute("data-locked")'));

echo "\n══ 1) الدخول والصلاحيات ══\n";
[$c, , , $loc] = hit($kernel, null, 'GET', '/v4/callcenter');
ok('من غير جلسة = تحويل لشاشة الدخول ومعاها next', $c === 302 && str_contains((string) $loc, '/v4/login?next=' . urlencode('/v4/callcenter')), $c . ' ' . $loc);
[$c, $h] = hit($kernel, null, 'GET', '/v4/login');
ok('شاشة الدخول 200 وبتنده /api/login بتاع النظام', $c === 200 && str_contains($h, "/api/login") && str_contains($h, 'id="f-login"'));
[$c, , , $loc] = hit($kernel, $cc, 'GET', '/v4/login');
ok('اللي داخل فعلًا بيتحوّل لتطبيقه', $c === 302 && str_ends_with((string) $loc, '/v4/callcenter'), $c . ' ' . $loc);
[$c, $h] = hit($kernel, $br, 'GET', '/v4/callcenter');
ok('🔴 مشرف فرع = 403 بصفحة واضحة (مش حلقة دخول)', $c === 403 && str_contains($h, 'مش من صلاحيتك'), (string) $c);
[$c] = hit($kernel, $br, 'GET', '/v4/callcenter/orders-data?list=active');
ok('🔴 وبياناته كمان 403', $c === 403, (string) $c);
[$c, $h] = hit($kernel, $br, 'GET', '/v4/login');
ok('دور مالوش تطبيق v4 بيتقال له بوضوح', $c === 200 && str_contains($h, 'لسه مالوش شاشة'));
[$c] = hit($kernel, $ad, 'GET', '/v4/callcenter');
ok('الأدمن مسموح له', $c === 200, (string) $c);

echo "\n══ 2) الشاشات بتترسم ══\n";
$pages = ['/v4/callcenter' => 'home.js', '/v4/callcenter/search' => 'orders.js', '/v4/callcenter/pilots' => 'pilots.js',
    '/v4/callcenter/zones' => 'zones.js', '/v4/callcenter/clients' => 'clients.js'];
foreach (array_keys((array) config('v4.order_lists')) as $l) {
    if (empty(config("v4.apps.callcenter.pages.{$l}.embed"))) { $pages["/v4/callcenter/orders/{$l}"] = 'orders.js'; }
}
foreach ($pages as $url => $js) {
    [$c, $h] = hit($kernel, $cc, 'GET', $url);
    ok("{$url} = 200 وفيها {$js} والقائمة والبث", $c === 200 && str_contains($h, $js) && str_contains($h, 'class="side"') && str_contains($h, 'realtime.js') && str_contains($h, 'التطبيق القديم'), (string) $c);
}
[$c] = hit($kernel, $cc, 'GET', '/v4/callcenter/soon/home');
ok('صفحة جاهزة مالهاش «قريبًا» (404)', $c === 404, (string) $c);
[$c] = hit($kernel, $cc, 'GET', '/v4/callcenter/orders/nope');
ok('قايمة مش معروفة = 404', $c === 404, (string) $c);

/* ═══ 2.5) «أمور لا أريد تغييرها» (قرار صاحب النظام 2026-09-21 بعد أول عرض) ═══
   فورم الأوردر الجديد وصفحة الطلبات النشطة (وباقي الشاشات اللي لسه ماتنقلتش) بيتعرضوا **بكود
   التطبيق القديم نفسه** جوه غلاف v4 — مش إعادة تصميم. */
echo "\n══ 2.5) شاشات القديم زي ما هي جوه الغلاف ══\n";
$embeds = ['/v4/callcenter/new' => ['orders', true], '/v4/callcenter/orders/active' => ['orders', false], '/v4/callcenter/page/map' => ['pilotmap', false],
    '/v4/callcenter/page/notifs' => ['ccnotifs', false], '/v4/callcenter/page/complaints' => ['ccomplaints', false], '/v4/callcenter/page/perf' => ['ccperf', false]];
foreach ($embeds as $url => [$old, $new]) {
    [$c, $h] = hit($kernel, $cc, 'GET', $url);
    ok("{$url} = القديم «{$old}» جوه الغلاف" . ($new ? ' والمودال مفتوح' : ''), $c === 200 && str_contains($h, 'id="v4-embed"') && str_contains($h, 'data-page="' . $old . '"')
        && str_contains($h, 'data-new="' . ($new ? '1' : '') . '"') && str_contains($h, 'embed.js') && str_contains($h, 'class="side"'), (string) $c);
}
ok('🔴 مفيش فورم أوردر «متصمّم من جديد» في v4', ! is_file($ROOT . '/public/v4/js/callcenter/new-order.js') && ! is_file($ROOT . '/resources/views/v4/callcenter/new.blade.php'));
ok('كل صفحات الكول سنتر جاهزة (مفيش «قريبًا»)', ! array_filter((array) config('v4.apps.callcenter.pages'), fn ($p) => empty($p['ready'])));
[$c] = hit($kernel, $cc, 'GET', '/v4/callcenter/page/home');
ok('page/<صفحة مش embed> = 404', $c === 404, (string) $c);
$old = (string) file_get_contents($ROOT . '/public/callcenter.html');
ok('القديم: وضع التضمين خامل من غير ?embed (بيتفعّل بالباراميتر بس)', str_contains($old, 'var p = new URLSearchParams(location.search), pg = p.get("embed"); if (!pg) return;')
    && str_contains($old, 'var E = window.__V4_EMBED; if (!E) return;'));
ok('  وبيخفي قايمته هو بس ويفتح الصفحة والمودال ويبلّغ الغلاف', str_contains($old, 'html.v4-embed #sidebar, html.v4-embed #mobile-topbar') && str_contains($old, 'window.navigateTo(E.page)')
    && str_contains($old, 'window.openModal("order")') && str_contains($old, 'tell({ v4embed: "login" })'));
$ej = (string) file_get_contents($ROOT . '/public/v4/js/callcenter/embed.js');
ok('  والغلاف بيظبط كاش الجلسة ويوحّد الوضع الليلي ويفتح مودال القديم من زرار «طلب جديد»', str_contains($ej, 'localStorage.setItem("tiar-session"') && str_contains($ej, 'fr.contentWindow.openModal("order")') && str_contains($ej, '"&theme="'));

$zone = DB::selectOne('SELECT z.id, z.price, z.delivery_branch_id FROM zones z WHERE z.price > 0 AND (z.source_branch_id IS NULL OR z.source_branch_id = z.delivery_branch_id) ORDER BY z.id LIMIT 1');

DB::beginTransaction();
try {
    Event::fake();   // محليًا مفيش Reverb — أي بث حقيقي بيقع
    echo "\n══ 3) الرئيسية: أرقام من السيرفر ══\n";
    [$c, , $j] = hit($kernel, $cc, 'GET', '/v4/callcenter/stats');
    $before = $j['stats'] ?? [];
    ok('stats 200 وفيها المفاتيح', $c === 200 && isset($before['pending'], $before['todayTotal'], $before['revenueToday'], $j['branches'], $j['nav']['active']), (string) $c);
    ok('اليوم = يوم العمل (BizDay)', ($before['day'] ?? '') === App\Support\BizDay::key(), (string) ($before['day'] ?? ''));

    $phone = '0109' . random_int(1000000, 9999999);
    $rphone = '0111' . random_int(1000000, 9999999);
    /* زي ما الفورم الجديد بيعمل: المستلم الجديد بيتسجّل في الدفتر قبل الأوردر */
    [$cr, , $jr] = hit($kernel, $cc, 'POST', '/api/receivers', ['name' => 'مستلم حارس v4', 'phone1' => $rphone, 'address' => 'عنوان']);
    $rid = (int) ($jr['item']['id'] ?? 0);
    [$c, , $j] = hit($kernel, $cc, 'POST', '/api/orders', ['source' => 'callcenter', 'branchId' => (int) $zone->delivery_branch_id, 'senderZoneId' => (int) $zone->id,
        'senderName' => 'مرسل حارس v4', 'senderPhone' => $phone, 'senderAddress' => 'شارع الحارس',
        'deliveries' => [['parcelNo' => 1, 'receiverId' => $rid, 'receiverName' => 'مستلم حارس v4', 'receiverPhone' => $rphone, 'zoneId' => (int) $zone->id, 'zonePrice' => (float) $zone->price, 'orderPrice' => 0, 'address' => 'عنوان']]]);
    $o = $j['orders'][0] ?? null;
    ok('أوردر كول سنتر اتسجّل بنفس مسار النظام', $c === 200 && $o, $c . ' ' . mb_substr(json_encode($j, JSON_UNESCAPED_UNICODE), 0, 160));
    [, , $j2] = hit($kernel, $cc, 'GET', '/v4/callcenter/stats');
    ok('🔴 «قيد التنفيذ» و«طلبات اليوم» زادوا 1', ($j2['stats']['pending'] ?? -1) === $before['pending'] + 1 && ($j2['stats']['todayTotal'] ?? -1) === $before['todayTotal'] + 1,
        json_encode([$before['pending'], $j2['stats']['pending'] ?? null]));
    $brow = array_values(array_filter($j2['branches'], fn ($b) => $b['id'] === (int) $zone->delivery_branch_id))[0] ?? null;
    ok('  وصف الفرع فيه الأوردر', $brow && $brow['pending'] >= 1);

    echo "\n══ 4) القوايم: فلترة وبحث وترقيم في السيرفر ══\n";
    [$c, , $j] = hit($kernel, $cc, 'GET', '/v4/callcenter/orders-data?list=active');
    ok('active 200 بشكل {items,total,page,pages,perPage,sum}', $c === 200 && isset($j['items'], $j['total'], $j['pages'], $j['sum']) && $j['perPage'] === 30 && count($j['items']) <= 30, (string) $c);
    ok('  والصف بشكل OrderWire (حالة عربي + طرود)', ($j['items'][0]['status'] ?? '') !== '' && isset($j['items'][0]['deliveries'], $j['items'][0]['orderNum'], $j['items'][0]['branchName']));
    ok('  وكل اللي فيها نشط', ! array_filter($j['items'], fn ($x) => ! in_array($x['status'], ['قيد التنفيذ', 'مؤجل'], true)));
    [, , $j] = hit($kernel, $cc, 'GET', '/v4/callcenter/orders-data?list=all&q=' . urlencode($phone));
    ok('🔴 البحث بتليفون المرسل بيلاقي الأوردر', ($j['total'] ?? 0) === 1 && ($j['items'][0]['id'] ?? 0) === (int) $o['id'], (string) ($j['total'] ?? ''));
    [, , $j] = hit($kernel, $cc, 'GET', '/v4/callcenter/orders-data?list=all&q=' . urlencode('مستلم حارس v4'));
    ok('  وباسم المستلم', ($j['total'] ?? 0) >= 1);
    $today = App\Support\BizDay::key();
    [, , $j] = hit($kernel, $cc, 'GET', "/v4/callcenter/orders-data?list=active&from={$today}&to={$today}&branchId=" . (int) $zone->delivery_branch_id);
    ok('  وفلتر يوم العمل + الفرع', ($j['total'] ?? 0) >= 1 && ! array_filter($j['items'], fn ($x) => (int) $x['branchId'] !== (int) $zone->delivery_branch_id));
    [, , $j] = hit($kernel, $cc, 'GET', '/v4/callcenter/orders-data?list=active&from=2020-01-01&to=2020-01-02');
    ok('  وتاريخ قديم = صفر', ($j['total'] ?? -1) === 0 && $j['items'] === []);
    [, , $j] = hit($kernel, $cc, 'GET', '/v4/callcenter/orders-data?list=delivered&page=99999');
    ok('  ورقم صفحة خارج المدى بيتقصّ', ($j['page'] ?? 0) === ($j['pages'] ?? -1));
    [$c] = hit($kernel, $cc, 'GET', '/v4/callcenter/orders-data?list=nope');
    ok('  وقايمة مش معروفة 404', $c === 404, (string) $c);

    echo "\n══ 5) بحث العملاء وبيانات الفورم ══\n";
    [, , $j] = hit($kernel, $cc, 'GET', '/v4/callcenter/contacts?type=senders&q=' . urlencode(substr($phone, 0, 4) . ' ' . substr($phone, 4)));
    $hitS = array_values(array_filter($j['items'] ?? [], fn ($x) => $x['phone1'] === $phone))[0] ?? null;
    ok('🔴 بحث المرسل بتليفون منسوخ بمسافة', (bool) $hitS, json_encode(array_column($j['items'] ?? [], 'phone1')));
    ok('  ومعاه آخر منطقة استلام (الفرع بيتحدّد لوحده)', ($hitS['lastZoneId'] ?? null) === (int) $zone->id);
    [, , $j] = hit($kernel, $cc, 'GET', '/v4/callcenter/contacts?type=senders&q=' . urlencode('حارس مرسل'));
    ok('  وبالاسم بأي ترتيب كلمات', (bool) array_filter($j['items'] ?? [], fn ($x) => $x['phone1'] === $phone));
    [, , $j] = hit($kernel, $cc, 'GET', '/v4/callcenter/contacts?type=receivers&q=' . urlencode('مستلم حارس'));
    $hitR = $j['items'][0] ?? null;
    ok('  والمستلم معاه آخر منطقة وعنوان', $hitR && ($hitR['lastZoneId'] ?? null) === (int) $zone->id && ($hitR['lastAddress'] ?? '') === 'عنوان', json_encode($hitR, JSON_UNESCAPED_UNICODE));
    [, , $j] = hit($kernel, $cc, 'GET', '/v4/callcenter/contacts?type=senders&q=a');
    ok('  وأقل من حرفين = فاضي', ($j['items'] ?? null) === []);
    [$c, , $j] = hit($kernel, $cc, 'GET', '/v4/callcenter/form-data');
    $z0 = $j['zones'][0] ?? [];
    ok('form-data: فروع + مناطق مخفّفة بعلامة «البيت»', $c === 200 && count($j['branches'] ?? []) > 0 && array_keys($z0) === ['id', 'name', 'price', 'branchId', 'home'], json_encode(array_keys($z0)));
    ok('  وحجمها أصغر بكتير من السلك الكامل', strlen(json_encode($j['zones'])) < 120000, (string) strlen(json_encode($j['zones'])));
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "V4 CALLCENTER: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
