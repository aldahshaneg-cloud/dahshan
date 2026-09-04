<?php
/**
 * 🔒 حارس نطاق الفرع — «مشرف الفرع مقفول على فرعه».
 *
 * ═══ من فين جه ═══
 * مراجعة صلاحيات عدائية (2026-08-31، ١٣٤ وكيل) طلّعت ٣٠ ثغرة مؤكَّدة،
 * أغلبها نوع واحد: **مشرف الفرع مالوش حدود فرع في المسارات المالية وشؤون
 * الطيارين**. كان بيقرا خزن وعُهد ومصروفات كل الفروع، ويكتب حركات نقدية
 * على خزنة أي فرع، ويسوّي فلوس ورديات طيارين فروع تانية، ويكتب تقفيلة
 * شهرية ومرتب لأي طيار، ويسحب طيار فرع تاني لفرعه.
 *
 * الفحص هنا **بيقرا الكود نفسه** (مش بيحاكيه) ويتأكد إن كل مسار متحصّن.
 * الحرّاس الحقيقية بتتجرّب في الاختبارات المالية والتشغيلية التانية.
 *
 * التشغيل: php ops/test_branch_scope.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pass = 0;
$fail = 0;
function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$what}\n"; }
    else { $fail++; echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n"; }
}
set_exception_handler(function (Throwable $e): void {
    echo "\n🔴 استثناء مش متمسك: " . get_class($e) . ' — ' . $e->getMessage() . "\n"; exit(1);
});
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) { exit(1); }
});

$src = fn (string $p): string => file_get_contents(__DIR__ . '/../' . $p);
$BOARD   = $src('app/Http/Controllers/Api/BoardController.php');
$FIN     = $src('app/Http/Controllers/Api/FinanceController.php');
$ENT     = $src('app/Http/Controllers/Api/EntitiesController.php');
$SUP     = $src('app/Http/Controllers/Api/SupportController.php');
$ROUTES  = $src('routes/api.php');
$ACTOR   = $src('app/Http/Middleware/ResolveApiActor.php');
$THROT   = $src('app/Services/Auth/LoginThrottle.php');
$AUTH    = $src('app/Http/Controllers/Api/AuthController.php');
$WIRE    = $src('app/Wire/CoreWire.php');

/** جسم دالة — من توقيعها لحد توقيع الدالة اللي بعدها */
$body = function (string $file, string $fn): string {
    $at = strpos($file, "function {$fn}(");
    if ($at === false) { return ''; }
    $next = preg_match('/\n    (?:public|private|protected) function /', substr($file, $at + 20), $m, PREG_OFFSET_CAPTURE)
        ? $at + 20 + $m[0][1] : strlen($file);

    return substr($file, $at, $next - $at);
};

echo "\n══ 1) 🔴 المالية — القراءات ══\n";
ok('فيه دالة نطاق واحدة `scopeBranch`', str_contains($FIN, 'private function scopeBranch(Actor $actor'));
ok('وبترفض مشرف الفرع اللي مالوش فرع (فخ branchId=0)',
   str_contains($FIN, "throw ApiException::forbidden('حسابك مش مربوط بفرع')"),
   'صفر بيتخطّى الفلترة فيشوف كل الفروع');
ok('وفيه حارس خزنة `assertStoreInScope`', str_contains($FIN, 'private function assertStoreInScope(Actor $actor'));
foreach (['cashStoresList' => 'scopeBranch', 'expensesList' => 'scopeBranch',
          'custodyList' => 'scopeBranch', 'cashTxnsList' => 'assertStoreInScope'] as $fn => $needs) {
    ok("«{$fn}» متحصّن بـ{$needs}", str_contains($body($FIN, $fn), $needs));
}
ok('«pilotCustody» بيفحص فرع الطيار',
   str_contains($body($FIN, 'pilotCustody'), 'الطيار ده مش تابع لفرعك'));
ok('و`custodyList` بيعمل JOIN على الطيار عشان يفلتر بفرعه',
   str_contains($body($FIN, 'custodyList'), 'JOIN pilots p ON p.id = ct.pilot_id'));

echo "\n══ 2) 🔴 المالية — الكتابات ══\n";
$create = $body($FIN, 'cashTxnsCreate');
ok('«cashTxnsCreate» بيتحقق من نطاق الخزنة', str_contains($create, 'assertStoreInScope'));
ok('  ورقم الفرع مابيتقراش من جسم الطلب لمشرف الفرع',
   (bool) preg_match('/\$user->role === .branch.\s*\n\s*\?\s*\$user->branchId/', $create),
   'مشرف فرع بيقيّد حركة على أي فرع يكتبه');
$approve = $body($FIN, 'cashTxnsApprove');
ok('«cashTxnsApprove» بقى بيقرا الفاعل', str_contains($approve, 'actorOrFail'),
   'كان مافيهوش ولا قراءة للفاعل خالص');
ok('  وبيتحقق من نطاق الخزنة قبل تعديل الرصيد', str_contains($approve, 'assertStoreInScope'));
foreach (['expensesUpdate', 'expensesDelete'] as $fn) {
    ok("«{$fn}» بيفحص فرع المصروف",
       str_contains($body($FIN, $fn), 'المصروف ده مش تابع لفرعك'));
}

echo "\n══ 3) 🔴 اللوحة — تسويات وتقفيلات وطيارين ══\n";
ok('فيه حارس طلب `assertRequestBranch`', str_contains($BOARD, 'private function assertRequestBranch(Actor $actor'));
$G = [
    'shiftSettlement'      => 'assertPilotInScope',
    'shiftEnd'             => 'assertPilotInScope',
    'pilotReturn'          => 'assertPilotInScope',
    'closeoutGet'          => 'assertPilotInScope',
    'closeoutSave'         => 'assertPilotInScope',
    'queueLeave'           => 'assertPilotInScope',
    'queueEnter'           => 'assertPilotInScope',
    'leaveRequestApprove'  => 'assertPilotInScope',
    'leaveRequestEnd'      => 'assertPilotInScope',
    'shiftRequestApprove'  => 'assertPilotInScope',
    'supportSendPilot'     => 'assertPilotInScope',
    'returnRequestApprove' => 'assertRequestBranch',
    'returnRequestReject'  => 'assertRequestBranch',
    'leaveRequestReject'   => 'assertRequestBranch',
    'shiftRequestReject'   => 'assertRequestBranch',
    'transferReject'       => 'assertRequestBranch',
    'joinRequestApprove'   => 'assertRequestBranch',
];
foreach ($G as $fn => $needs) {
    ok("«{$fn}» متحصّن", str_contains($body($BOARD, $fn), $needs), 'ناقصه ' . $needs);
}
/* النقل الدائم استثناء: الفرع **المستقبِل** هو اللي بيوافق، فالحارس العادي
   كان هيرفض الموافقة المشروعة. */
ok('«transferApprove» بيفحص فرع الوجهة مش فرع الطيار',
   str_contains($body($BOARD, 'transferApprove'), 'الموافقة من الفرع المستقبِل'));
ok('«supportSendPilot» بيتأكد إن طلب الدعم على فرعه',
   str_contains($body($BOARD, 'supportSendPilot'), 'طلب الدعم ده مش على فرعك'));

echo "\n══ 4) الكيانات والتقييم ══\n";
$pu = $body($ENT, 'pilotsUpdate');
ok('«pilotsUpdate» بيفحص فرع الطيار', str_contains($pu, 'الطيار ده مش تابع لفرعك'));
ok('  ومشرف الفرع مايقدرش ينقله لفرع تاني',
   str_contains($pu, "unset(\$b['homeBranchId'], \$b['branchId'], \$b['assignedBranchId']);"));
ok('وحقول الفلوس بتتشال من مشرف الفرع كمان',
   (bool) preg_match("/\['pilot_supervisor', 'branch'\]/", $body($ENT, 'stripPilotMoney')),
   'مشرف الفرع بيغيّر مرتب وعمولة الطيار');
$pl = $body($ENT, 'pilotsList');
ok('«pilotsList» بيلزّم مشرف الفرع بفرعه',
   (bool) preg_match('/\$actor->role === .branch.[\s\S]{0,120}\$branchId = \(int\) \(\$actor->branchId/', $pl),
   'كان بيشيل ?branchId ويسحب كل طياري الشركة');
ok('  وبيرفض اللي مالوش فرع بدل ما يفتح', str_contains($pl, 'حسابك مش مربوط بفرع'));
ok('«zonesDelete» بيفحص نطاق المنطقة', str_contains($body($ENT, 'zonesDelete'), 'assertZoneInScope'));
ok('«orderRating» بيفحص فرع الأوردر',
   str_contains($body($SUP, 'orderRating'), 'الأوردر ده مش تابع لفرعك'),
   'قناة قراءة لأي أوردر كامل بحجة التقييم');
ok('والكول سنتر مابياخدش فلوس الطيار على السلك',
   (bool) preg_match("/\['pilot_supervisor', 'callcenter'\]/", $WIRE));

echo "\n══ 5) المسارات — قيود الأدوار ══\n";
/** جملة مسار واحدة لحد أول `;` */
$stmt = function (string $verb, string $uri) use ($ROUTES): string {
    $needle = "Route::{$verb}('{$uri}'";
    $at = strpos($ROUTES, $needle);

    return $at === false ? '' : substr($ROUTES, $at, strpos($ROUTES, ';', $at) - $at);
};
foreach ([['get', 'leave-requests'], ['get', 'shift-requests'], ['get', 'return-requests']] as [$v, $u]) {
    $s = $stmt($v, $u);
    ok("«{$u}» عليه قيد دور", str_contains($s, "role:admin,branch,pilot,pilot_supervisor"), $s);
}
$rating = $stmt('post', 'orders/{id}/rating');
ok('«orders/{id}/rating» عليه قيد دور', str_contains($rating, '->middleware(\'role:'), $rating);
ok('  والعميل بيعدّي من جلسته مش من الميدلوير', ! str_contains($rating, 'customer'));
$pilotsPut = $stmt('put', 'pilots/{id}');
ok('«PUT pilots/{id}» الكول سنتر اتشال منه', ! str_contains($pilotsPut, 'callcenter'), $pilotsPut);
foreach ([['post', 'zones'], ['put', 'zones/{id}'], ['delete', 'zones/{id}']] as [$v, $u]) {
    ok("«{$v} {$u}» الكول سنتر اتشال منه", ! str_contains($stmt($v, $u), 'callcenter'));
}
/* روح دمشق: ٢٩ مسار كانوا بلا أي قيد — وأربعة بلا فحص جوّه الكنترولر كمان */
preg_match_all("/Route::(?:get|post|put|delete|patch)\(\s*'rd\/[^']*'[\s\S]*?;/", $ROUTES, $rd);
$rdTotal  = count($rd[0]);
$rdGuard  = count(array_filter($rd[0], fn ($s) => str_contains($s, "role:admin,accountant")));
ok("كل مسارات rd/* عليها قيد ({$rdGuard}/{$rdTotal})", $rdTotal > 0 && $rdGuard === $rdTotal);

echo "\n══ 6) الجلسة والتوكن والدخول ══\n";
ok('الدور والفرع بيتقروا من القاعدة مش من الجلسة',
   str_contains($ACTOR, 'private function staffRow(int $uid)')
   && (bool) preg_match('/role:\s*\(string\) \$row->role/', $ACTOR),
   'تضييق صلاحية موظف مابيوصلش لجلسته المفتوحة');
ok('والاستعلام واحد مش اتنين (نفس صف فحص الحظر)',
   str_contains($ACTOR, "->select('username', 'role', 'branch_id', 'name', 'blocked')"));
ok('LoginThrottle فيه عدّاد لكل IP لوحده',
   str_contains($THROT, "\$this->bump(\$ip, '', \$now, \$this->maxFails * 4);"),
   'رش الباسوردات على ٥٠ اسم = فشل واحد لكل مفتاح، مفيش قفل');
ok('والقفل بيفحص المفتاحين', str_contains($THROT, "whereIn('username', [\$username, ''])"));
$g = $body($AUTH, 'adminGoogleLogin');
ok('دخول جوجل بيفرض email_verified', str_contains($g, "(\$p['email_verified'] ?? false) !== true"));
ok('  وبيفرض إن المزوّد جوجل فعلًا', str_contains($g, "sign_in_provider'] ?? '') !== 'google.com'"));
ok('  وفرع الـbootstrap اتشال', ! str_contains($g, 'INSERT IGNORE INTO admin_emails'),
   'لو آخر صف اتمسح، أول واحد يوصل للمسار ياخد الإدارة');

echo "\n══════════════════════════════════════════════\n";
echo "BRANCH SCOPE: {$pass} ناجح · {$fail} فاشل\n";
echo "══════════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
