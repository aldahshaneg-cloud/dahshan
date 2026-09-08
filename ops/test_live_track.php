<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════
   حارس التتبّع الحي — السيرفر (2026-09-07)

   البلاغ: «الطيار بيثبت في مكان على الخريطة». الحل على السيرفر:
   ① `POST /api/pilot/location` بيقبل دفعة `points[]` (نقطة كل ٥ث من تيار
      الـGPS، دفعة كل ١٥ث) وبيخزّنها في `pilot_track_points` وآخرها
      بيبقى موقع الطيار.
   ② `GET /api/pilots?trail=1` بيرجّع أثر آخر دقيقتين لكل طيار شغّال.
   ③ `heading`/`speed`/`trail` على سلك الطيار — أعلى مستوى، مسجّلين في
      بوابتي السلك.

   القسم ٤ **بينفّذ** التخزين والقراءة على القاعدة المحلية جوه معاملة
   بترجع — مش بيقرا الكود بس.
═══════════════════════════════════════════════════════════════ */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function ok(string $label, bool $cond, string $hint = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        echo "  ✗ 🔴 {$label}" . ($hint !== '' ? " — {$hint}" : '') . "\n";
    }
}
function code(string $src): string
{
    $src = preg_replace('~/\*.*?\*/~s', '', $src);
    $src = preg_replace('~^\s*//.*$~m', '', $src);

    return preg_replace('~(?<=[;{})\s])//[^\n]*~', '', $src);
}

$pc  = code(file_get_contents($root . '/app/Http/Controllers/Api/PilotAppController.php'));
$cw  = code(file_get_contents($root . '/app/Wire/CoreWire.php'));
$ec  = code(file_get_contents($root . '/app/Http/Controllers/Api/EntitiesController.php'));
$sch = file_get_contents($root . '/database/schema/mysql-schema.sql');
$vp  = file_get_contents($root . '/app/Console/Commands/VerifyWireParity.php');
$we  = file_get_contents($root . '/app/Console/Commands/WireEdgeCases.php');

echo "══ 1) استقبال الدفعة ══\n";
$loc = substr($pc, (int) strpos($pc, 'public function location('), 6000);
ok('location() بيفرّق بوجود points', str_contains($loc, "\$pointsIn = \$request->input('points');")
    && str_contains($loc, '$this->storeTrackBatch($pilotId, $pointsIn);'),
    'الدفعة رجعت تتجاهل — التطبيق الجديد هيبعت ومحدش يخزّن');
ok('  والشكل القديم {lat,lng} لسه شغّال', str_contains($loc, "\$latIn = \$request->input('lat');"));
$sb = substr($pc, (int) strpos($pc, 'private function storeTrackBatch('), 5000);
ok('الدفعة بتتخزّن في pilot_track_points', str_contains($sb, 'INSERT INTO pilot_track_points'));
ok('  وآخر نقطة زمنيًا بتبقى موقع الطيار (heading/speed كمان)',
    str_contains($sb, "usort(\$rows, fn (\$a, \$b) => \$a['_ms'] <=> \$b['_ms']);")
    && str_contains($sb, 'UPDATE pilots SET lat = ?, lng = ?, heading = ?, speed = ?, location_updated_at = ?'));
ok('  ووقت الجهاز المضروب بيتبدّل بوقت السيرفر',
    str_contains($sb, '$atMs > $nowMs + 60_000 || $atMs < $nowMs - 3_600_000'));
ok('  وسقف ٦٠ نقطة في الدفعة', str_contains($sb, 'array_slice($pointsIn, 0, 60)'));
ok('  والأثر بيتنضّف بعد ٢٤ ساعة', str_contains($sb, 'INTERVAL 24 HOUR'));

echo "\n══ 2) السلك والمخطط ══\n";
ok('CoreWire::pilot فيه heading/speed/trail أعلى مستوى',
    (bool) preg_match("~'heading' => isset\(\\\$r\['heading'\]\)~", $cw)
    && (bool) preg_match("~'trail'\s+=> isset\(\\\$r\['_trail'\]\)~", $cw));
ok('  ومش جوه location (بوابة الحواف بتقارنه ككائن)',
    ! (bool) preg_match("~'updatedAt' => WireTime::toWire\(\\\$r\['location_updated_at'\][^\]]*\],\s*'heading'~s", $cw));
ok('pilotsList بيحقن الأثر مع ?trail=1', str_contains($ec, "\$request->query('trail', '') === '1'")
    && str_contains($ec, 'FROM pilot_track_points'));
ok('  استعلام واحد لكل الطيارين (مش N+1)', str_contains($ec, 'WHERE pilot_id IN ({$ph})'));
ok('  وبسقف ٤٠ نقطة للطيار', str_contains($ec, '>= 40'));
ok('المخطط فيه pilot_track_points', str_contains($sch, 'CREATE TABLE `pilot_track_points`')
    && str_contains($sch, 'KEY `idx_pilot_track_points_pilot_at` (`pilot_id`,`at`)'));
ok('  وpilots.heading + speed', str_contains($sch, '`heading` decimal(5,1) DEFAULT NULL COMMENT \'اتجاه الحركة بالدرجات (0-360)'));
foreach (['heading', 'speed', 'trail'] as $f) {
    ok("«{$f}» مسجّل في wire:verify و wire:edge",
        (bool) preg_match("~'{$f}'\s*=>\s*'~", $vp) && (bool) preg_match("~'{$f}'\s*=> true~", $we));
}

/* ═══ 3) تنفيذ فعلي على القاعدة المحلية (بترجع) ═══ */
echo "\n══ 3) تنفيذ: دفعة ٣ نقاط → أثر + موقع + قراءة بالأثر ══\n";
/* محلي بس — القسم ده بيكتب في pilots وpilot_track_points (جوه معاملة
   بترجع)، ومافيش داعي يلمس قاعدة الإنتاج أصلًا. */
if (is_dir('/var/www/dahshan')) {
    echo "⛔ القسم التنفيذي محلي بس — اتخطّى على الإنتاج\n";
    exit($fail > 0 ? 1 : 0);
}
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/* لازم بعد البوتستراب — لارافل بيدوس على اللي قبله */
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "💥 استثناء غير ممسوك: {$e->getMessage()}\n   {$e->getFile()}:{$e->getLine()}\n");
    exit(1);
});
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) {
        exit(1);
    }
});

use App\Http\Controllers\Api\EntitiesController;
use App\Http\Controllers\Api\PilotAppController;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();
try {
    $pilot = DB::select("SELECT id FROM pilots WHERE archived_at IS NULL ORDER BY id LIMIT 1")[0] ?? null;
    if (! $pilot) {
        ok('فيه طيار في القاعدة المحلية', false, 'القاعدة فاضية');
    } else {
        $pid = (int) $pilot->id;
        // نضمن إنه «شغّال» عشان يدخل في الأثر
        DB::update("UPDATE pilots SET status = 'waiting' WHERE id = ?", [$pid]);
        $now = (int) round(microtime(true) * 1000);
        $pts = [
            ['lat' => 31.0400, 'lng' => 31.3800, 'at' => $now - 10000, 'heading' => 90, 'speed' => 6.5, 'acc' => 8],
            ['lat' => 31.0410, 'lng' => 31.3810, 'at' => $now - 5000,  'heading' => 45, 'speed' => 7.0, 'acc' => 6],
            ['lat' => 31.0420, 'lng' => 31.3820, 'at' => $now,         'heading' => 30, 'speed' => 5.0, 'acc' => 5],
            ['lat' => 'x', 'lng' => 1],                       // مضروبة — لازم تتجاهل
            ['lat' => 0, 'lng' => 0, 'at' => $now],            // صفر/صفر — تتجاهل
        ];
        $m = new ReflectionMethod(PilotAppController::class, 'storeTrackBatch');
        $m->setAccessible(true);
        $m->invoke(new PilotAppController(), $pid, $pts);

        $n = (int) DB::select('SELECT COUNT(*) n FROM pilot_track_points WHERE pilot_id = ?', [$pid])[0]->n;
        ok('٣ نقاط صالحة اتخزّنت (المضروبة اتجاهلت)', $n === 3, (string) $n);
        $p = DB::select('SELECT lat, lng, heading, speed FROM pilots WHERE id = ?', [$pid])[0];
        ok('موقع الطيار = آخر نقطة زمنيًا',
            abs((float) $p->lat - 31.0420) < 1e-6 && abs((float) $p->lng - 31.3820) < 1e-6,
            $p->lat . ',' . $p->lng);
        ok('  والاتجاه/السرعة منها', (float) $p->heading == 30.0 && abs((float) $p->speed - 5.0) < 0.01);

        // القراءة بالأثر من نفس المسار اللي اللوحات بتنده عليه
        $req = Request::create('/api/pilots', 'GET', ['trail' => '1']);
        $req->attributes->set(ResolveApiActor::ATTRIBUTE, new Actor(
            userId: 1, customerId: null, username: 'guard', role: 'admin', branchId: null, name: 'حارس'
        ));
        $res = json_decode((new EntitiesController())->pilotsList($req)->getContent(), true);
        $row = null;
        foreach ($res['items'] ?? [] as $it) {
            if ((int) ($it['id'] ?? 0) === $pid) {
                $row = $it;
                break;
            }
        }
        ok('الطيار ظهر في /api/pilots?trail=1', $row !== null);
        ok('  ومعاه trail بـ٣ نقاط', is_array($row['trail'] ?? null) && count($row['trail']) === 3,
            (string) count($row['trail'] ?? []));
        ok('  وكل نقطة {lat,lng,t} بوقت ملي ثانية',
            isset($row['trail'][0]['t']) && is_int($row['trail'][0]['t']) && $row['trail'][0]['t'] > 1_700_000_000_000);
        ok('  وheading أعلى مستوى في السلك', (float) ($row['heading'] ?? -1) == 30.0);

        // من غير trail=1 — مفيش حمولة أثر
        $req2 = Request::create('/api/pilots', 'GET', []);
        $req2->attributes->set(ResolveApiActor::ATTRIBUTE, new Actor(
            userId: 1, customerId: null, username: 'guard', role: 'admin', branchId: null, name: 'حارس'
        ));
        $res2 = json_decode((new EntitiesController())->pilotsList($req2)->getContent(), true);
        $row2 = null;
        foreach ($res2['items'] ?? [] as $it) {
            if ((int) ($it['id'] ?? 0) === $pid) {
                $row2 = $it;
            }
        }
        ok('ومن غير ?trail=1 الأثر null (مفيش حمولة زيادة على باقي اللوحات)',
            $row2 !== null && ($row2['trail'] ?? null) === null);
    }
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "LIVE TRACK (server): {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
