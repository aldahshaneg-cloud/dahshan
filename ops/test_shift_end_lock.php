<?php
/**
 * 🔒 حارس: الطيار مايقدرش ينهي ورديته.
 *
 * ═══ القاعدة ═══
 * قرار صاحب النظام 2026-08-30: «امنع الطيار إنه يخرج من الوردية — اللي
 * يخرجه من الوردية هو الفرع أو الإدارة».
 *
 * ═══ ليه الحارس على السيرفر مش على التطبيق ═══
 * شيل الزرار من التطبيق بيمنع الطيار يدوس، مابيمنعوش يبعت الطلب. وأهم من
 * كده: **مفيش فرض تحديث** على الإنتاج (`site_settings.pilotApp` مش موجود)،
 * فالنسخة القديمة اللي على تليفونات الطيارين دلوقتي لسه بتنده على المسار.
 * فالرفض لازم يكون على السيرفر عشان يشتغل من غير ما حد ينزّل حاجة.
 *
 * ═══ الفحص بينفّذ مش بيحاكي ═══
 * البند ② بينده `shiftEnd` **فعلًا** من الكنترولر المحمّل. لو حد رجّع الجسم
 * القديم، الفحص هيقع لأنه هيلاقي نفسه بينفّذ SQL بدل ما ياخد استثناء.
 *
 * ═══ الجانب المالي ═══
 * المسار القديم كان بيقفل الوردية **من غير تسوية** — لا عهدة ولا خزنة.
 * الفرع بينده `settlePilotMoney`. فالبند ④ بيتأكد إن الطريق ده لسه سليم:
 * لو اتكسر، بنكون قفلنا الباب الوحيد اللي بيسوّي الفلوس.
 *
 * التشغيل: php ops/test_shift_end_lock.php
 */
$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;

function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$what}\n"; }
    else { $fail++; echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n"; }
}

/* ══════════ ① المسار لسه موجود — العقد مجمّد ══════════ */
echo "\n══ 1) المسار موجود ومحطوط عليه role:pilot ══\n";
$routes = file_get_contents($ROOT . '/routes/api.php');

preg_match("/Route::post\('pilot\/shift\/end'.*?;/s", $routes, $m);
$routeDef = $m[0] ?? '';
ok('المسار pilot/shift/end لسه متسجّل (route:coverage بيعدّ عليه)', $routeDef !== '');
ok('لسه role:pilot — مش متشال ولا متغيّر لدور تاني',
    str_contains($routeDef, 'role:pilot'), $routeDef);

/* ══════════ ② تنفيذ فعلي: بيرفض بـ403 ══════════ */
echo "\n══ 2) نداء حقيقي على الكنترولر ══\n";
require $ROOT . '/vendor/autoload.php';
$app = require $ROOT . '/bootstrap/app.php';
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


$ctrl = new App\Http\Controllers\Api\PilotAppController();
$req  = Illuminate\Http\Request::create('/api/pilot/shift/end', 'POST');

$thrown = null;
try {
    $ctrl->shiftEnd($req);
    ok('shiftEnd بترمي استثناء', false, 'رجّعت من غير ما ترمي — الباب مفتوح!');
} catch (App\Exceptions\ApiException $e) {
    $thrown = $e;
    ok('shiftEnd بترمي ApiException', true);
} catch (Throwable $e) {
    ok('shiftEnd بترمي ApiException', false, get_class($e) . ': ' . $e->getMessage());
}

if ($thrown !== null) {
    ok('الحالة 403 مش 500 ولا 404', $thrown->status() === 403, (string) $thrown->status());
    ok('الرسالة بتقول مين اللي بينهي الوردية',
        str_contains($thrown->getMessage(), 'الفرع') && str_contains($thrown->getMessage(), 'الإدارة'),
        $thrown->getMessage());
    ok('الرسالة عربية للمستخدم النهائي مش نص تقني',
        (bool) preg_match('/^[^A-Za-z]*$/u', $thrown->getMessage()), $thrown->getMessage());
}

/* ══════════ ③ الجسم القديم مش موجود بأي صورة ══════════ */
echo "\n══ 3) مافيش كود بيقفل وردية جوه كنترولر الطيار ══\n";
$pac = file_get_contents($ROOT . '/app/Http/Controllers/Api/PilotAppController.php');

ok("مافيش UPDATE shifts SET status = 'ended'",
    ! preg_match("/UPDATE\s+shifts\s+SET\s+status\s*=\s*'ended'/i", $pac));
ok("مافيش ended_by = 'pilot'", ! str_contains($pac, "'pilot'\]") && ! str_contains($pac, "ended_by = 'pilot'"));
ok('مافيش releasePilot متسابة يتيمة', ! str_contains($pac, 'function releasePilot'));
ok('مافيش lockPilot متسابة يتيمة', ! str_contains($pac, 'function lockPilot'));
ok('مافيش جسم قديم متساب باسم تاني',
    ! preg_match('/function\s+shiftEnd\w+/i', $pac));

/* ══════════ ④ طريق الفرع/الإدارة لسه سليم — وبيسوّي فلوس ══════════ */
echo "\n══ 4) الفرع والإدارة لسه بيقدروا ينهوا ══\n";
preg_match("/Route::post\('shifts\/\{id\}\/end'.*?;/s", $routes, $m2);
$branchRoute = $m2[0] ?? '';
ok('مسار shifts/{id}/end موجود', $branchRoute !== '');
ok('أدواره admin و branch بس', str_contains($branchRoute, 'role:admin,branch'), $branchRoute);
ok('الطيار مش من ضمن أدواره', ! preg_match('/role:[a-z,]*\bpilot\b/', $branchRoute), $branchRoute);

$board = file_get_contents($ROOT . '/app/Http/Controllers/Api/BoardController.php');
ok('BoardController::shiftEnd لسه موجودة',
    (bool) preg_match('/public function shiftEnd\(Request \$request, string \$id\)/', $board));
ok('ولسه بتنده settlePilotMoney — التسوية المالية مش ضايعة',
    substr_count($board, '$this->settlePilotMoney(') >= 1);

/* ══════════ ⑤ تطبيق الطيار مافيهوش نداء إنهاء ══════════ */
echo "\n══ 5) تطبيق الطيار (Flutter) ══\n";
$appDir = dirname($ROOT) . '/aldahshan/lib';
if (! is_dir($appDir)) {
    echo "  ⚠️ مجلد التطبيق مش موجود على الجهاز ده — تخطّي\n";
} else {
    $dart = file_get_contents($appDir . '/main.dart') . "\n" . file_get_contents($appDir . '/api_client.dart');
    // بنشيل التعليقات الأول عشان ذِكر الاسم في تعليق مايعديش كأنه نداء
    $code = preg_replace('#^\s*(///?|\*|/\*).*$#m', '', $dart);

    ok('مافيش نداء Api.endShift', ! str_contains($code, 'Api.endShift'));
    ok('مافيش نداء ShiftService.endShift(', ! str_contains($code, 'ShiftService.endShift('));
    ok('مافيش تعريف endShift (غير endShiftLocalOnly)',
        ! preg_match('/Future<void>\s+endShift\s*\(/', $code));
    ok('مافيش أي إشارة للمسار /api/pilot/shift/end', ! str_contains($code, '/api/pilot/shift/end'));
    ok('مافيش زرار «إنهاء الوردية» في الواجهة',
        ! preg_match("/Text\(\s*'إنهاء الوردية'/u", $code));
    ok('endShiftLocalOnly لسه موجودة — دي مزامنة مش إنهاء',
        str_contains($code, 'endShiftLocalOnly'));
    ok('الخروج بينضّف الحالة المحلية (مايسيبهاش لطيار تاني على نفس التليفون)',
        str_contains($code, 'await ShiftService.endShiftLocalOnly();'));
}

echo "\n" . str_repeat('─', 46) . "\n";
echo $fail === 0
    ? "✅ عدّى {$pass} فحص — الطيار مايقدرش ينهي ورديته\n\n"
    : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n\n";
exit($fail === 0 ? 0 : 1);
