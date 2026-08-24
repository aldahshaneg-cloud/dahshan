<?php

declare(strict_types=1);

/**
 * فحص بنية التحديث الفوري — تفويض قناتي الفرع والطيار، وحمولة الحدث وقنواته.
 *
 * ليه الفحص ده: `routes/channels.php` **بوابة صلاحيات مش تسمية**. المشترك
 * هو اللي بيبعت اسم القناة اللي عايزها، فأي تساهل في الدالة دي يخلي طيار
 * أو محل يسمع أوردرات أي فرع — من غير ما يعدّي على أي كنترولر ومن غير أي
 * أثر في اللوج. القاعدتين المفحوصتين:
 *   • `branch.{branchId}` → نفس نطاق الأدوار بتاع `GET /api/orders` بالحرف.
 *   • `pilot.{pilotId}`   → نفس قاعدة `PilotAppController::pilotCtx()`
 *     بالحرف: الطيار بيتحدّد من `users.pilot_id` بتاع الحساب **مش من
 *     الباراميتر**. الفرق بين القاعدتين إن دي بتلمس القاعدة، فالفحص لازم
 *     يلمسها هو كمان — تفويض متفحوص بمُوك مش متفحوص.
 *
 * وبيتأكد كمان إن حمولة `OrderChanged` بتتكلم **نفس لغة `OrderWire`**:
 * الحالة عربي والتوقيت ISO-8601 بحرف Z. الواجهات بتقارن الحالة نصًا، فأي
 * كود إنجليزي على السلك بيكسّرها بصمت. وإن `broadcastOn()` بترجّع القناتين
 * لما الأوردر عليه طيار وقناة الفرع بس لما مافيش.
 *
 * ⚠️ **صفر أثر في القاعدة.** قسم قناة الطيار محتاج حسابات مربوطة بطيارين
 * فعلًا، فبيعملها جوه `DB::transaction` **بترجع دايمًا** — في المسار
 * الطبيعي، وفي أي استثناء، وفي أي خروج مفاجئ (`register_shutdown_function`).
 * الباقي كله قراءة.
 *
 * التشغيل: php tests/realtime_channels.php
 */

use App\Events\OrderChanged;
use App\Support\Actor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/* القنوات بتتحمّل في الكونسول من bootstrap/app.php (شوف docs/REALTIME.md
   §5.2 — التحميل مؤجّل على الويب عن قصد)، فهي متسجّلة على السائق دلوقتي. */
$broadcaster = Broadcast::driver();

$ref = new ReflectionClass($broadcaster);
while ($ref !== false && ! $ref->hasProperty('channels')) {
    $ref = $ref->getParentClass();
}
if ($ref === false) {
    fwrite(STDERR, "مالقيتش قايمة القنوات على السائق\n");
    exit(1);
}
$prop = $ref->getProperty('channels');
$prop->setAccessible(true);

$registered = $prop->getValue($broadcaster);

/** بيجيب دالة تفويض قناة أو بيقف — قناة مش متسجّلة = فحص بيعدّي على فراغ */
$authFor = static function (string $pattern) use ($registered): callable {
    $cb = $registered[$pattern] ?? null;
    if ($cb === null) {
        fwrite(STDERR, "القناة {$pattern} مش متسجّلة\n");
        exit(1);
    }

    return $cb;
};

$branchAuth = $authFor('branch.{branchId}');
$pilotAuth  = $authFor('pilot.{pilotId}');

$pass = 0;
$fail = 0;

/** عرض مختصر — var_export بيفرد المصفوفات على أسطر وبيغرّق المخرج */
$show = static fn ($v): string => is_array($v)
    ? '[' . implode(', ', array_map('strval', $v)) . ']'
    : var_export($v, true);

/** @param mixed $expected */
$check = function (string $label, $expected, $got) use (&$pass, &$fail, $show): void {
    $ok = ($got === $expected);
    printf("%s  %-40s  توقّع=%-6s  طلع=%s\n", $ok ? '  ok' : 'FAIL', $label,
        $show($expected), $show($got));
    $ok ? $pass++ : $fail++;
};

echo "── تفويض private-branch.{branchId} ──\n";

$admin  = Actor::staff(1, 'admin', 'admin',      1,    'المدير');
$cc     = Actor::staff(2, 'cc',    'callcenter', 1,    'كول سنتر');
$br7    = Actor::staff(3, 'br7',   'branch',     7,    'فرع ٧');
$brNull = Actor::staff(4, 'brx',   'branch',     null, 'فرع بلا رقم');

$cases = [
    // [الوصف, الفاعل, اسم القناة, المتوقع]
    ['admin على فرع تاني',              $admin,  '7',   true],
    ['callcenter على أي فرع',           $cc,     '7',   true],
    ['branch(7) على فرعه',              $br7,    '7',   true],
    ['branch(7) على فرع تاني',          $br7,    '9',   false],
    ['branch(7) على "07" (شكل مش قانوني)', $br7, '07',  false],
    ['branch بلا branch_id',            $brNull, '7',   false],
    ['pilot',      Actor::staff(5, 'pil', 'pilot',      7, 'طيار'),   '7', false],
    ['store',      Actor::staff(6, 'sto', 'store',      7, 'محل'),    '7', false],
    ['accountant', Actor::staff(7, 'acc', 'accountant', 7, 'محاسب'),  '7', false],
    ['hr',         Actor::staff(8, 'hr',  'hr',         7, 'موارد'),  '7', false],
    ['customer',   Actor::customer(9, 'cus', 'عميل'),               '7', false],
    ['بلا دخول',   null,                                            '7', false],
    ['admin على اسم مش رقم',            $admin,  'abc', false],
    ['admin على صفر',                   $admin,  '0',   false],
    ['admin على رقم سالب',              $admin,  '-3',  false],
    ['admin على اسم فاضي',              $admin,  '',    false],
];

foreach ($cases as [$label, $actor, $channel, $expected]) {
    $check($label, $expected, $branchAuth($actor, $channel));
}

echo "\n── تفويض private-pilot.{pilotId} ──\n";

/* القاعدة هنا بتتقرا من القاعدة مش من الفاعل: `Actor` مافيهوش `pilotId`
   بالمرة (وده مقصود — الفاعل صورة من `require_auth()` بالحرف)، فالتفويض
   بيروح لـ`users.pilot_id` زي `pilotCtx()` بالظبط. يعني الفحص محتاج
   حسابات مربوطة بطيارين حقيقيين. */
$pilotIds = array_map(
    static fn ($r): int => (int) $r->id,
    DB::select('SELECT id FROM pilots ORDER BY id LIMIT 2')
);

if (count($pilotIds) < 2) {
    echo "  (محتاج طيارين على الأقل في القاعدة — القسم ده اتخطى)\n";
} else {
    [$pidA, $pidB] = $pilotIds;

    DB::beginTransaction();

    /* شبكة أمان: لو حاجة عملت exit جوه القسم ده (زي `$authFor` فوق) المعاملة
       المفتوحة كانت هتقفل مع الاتصال — MySQL بيرجّعها لوحده، بس التصريح
       الصريح أوضح من الاعتماد على ده. */
    register_shutdown_function(static function (): void {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    });

    try {
        /** حساب مؤقت — `password_hash` فاضي عشان مايبقاش قابل لتسجيل دخول */
        $mkUser = static fn (string $username, string $role, ?int $pilotId): int => (int) DB::table('users')
            ->insertGetId([
                'username'      => $username,
                'password_hash' => '',
                'role'          => $role,
                'name'          => $username,
                'pilot_id'      => $pilotId,
            ]);

        $uA       = $mkUser('__rt_pilot_a', 'pilot', $pidA);
        $uB       = $mkUser('__rt_pilot_b', 'pilot', $pidB);
        $uLoose   = $mkUser('__rt_pilot_unlinked', 'pilot', null);
        $uAdmin   = $mkUser('__rt_admin', 'admin', null);
        $uCc      = $mkUser('__rt_cc', 'callcenter', null);
        $uAdmLink = $mkUser('__rt_admin_linked', 'admin', $pidA);

        $pilotA   = Actor::staff($uA, '__rt_pilot_a', 'pilot', 3, 'طيار أ');
        $pilotB   = Actor::staff($uB, '__rt_pilot_b', 'pilot', 3, 'طيار ب');
        $unlinked = Actor::staff($uLoose, '__rt_pilot_unlinked', 'pilot', 3, 'حساب طيار غير مربوط');
        $admin2   = Actor::staff($uAdmin, '__rt_admin', 'admin', null, 'مدير');
        $cc2      = Actor::staff($uCc, '__rt_cc', 'callcenter', null, 'كول سنتر');
        $admLink  = Actor::staff($uAdmLink, '__rt_admin_linked', 'admin', null, 'مدير مربوط بطيار');
        $ghost    = Actor::staff(987654321, '__rt_ghost', 'pilot', 3, 'حساب اتمسح');

        $A = (string) $pidA;
        $B = (string) $pidB;

        $pilotCases = [
            // [الوصف, الفاعل, اسم القناة, المتوقع]
            ['طيار على قناته هو',                  $pilotA,   $A,        true],
            ['طيار على قناة طيار تاني',            $pilotA,   $B,        false],
            ['الطيار التاني على قناته هو',         $pilotB,   $B,        true],
            ['حساب مش مربوط بطيار',                $unlinked, $A,        false],

            /* 🔴 القرار المكتوب فوق الدالة: الأدمن والكول سنتر مالهمش
               قنوات الطيارين. مش سطر رفض صريح — حسابهم مش مربوط بطيار. */
            ['أدمن على قناة طيار',                 $admin2,   $A,        false],
            ['callcenter على قناة طيار',           $cc2,      $A,        false],

            /* والقاعدة **ربط مش دور**: لو حساب أدمن اتربط بطيار (حالة نادرة
               بس ممكنة في القاعدة) بياخد قناة الطيار ده بس — بالظبط زي ما
               `pilotCtx()` هتديله مسارات تطبيق الطيار بتاع نفس الطيار.
               المهم إن التعرّض يفضل **محدود بطيار واحد** مش مفتوح. */
            ['أدمن مربوط بطيار → قناة الطيار ده',  $admLink,  $A,        true],
            ['أدمن مربوط بطيار → قناة طيار تاني',  $admLink,  $B,        false],

            ['حساب اتمسح والتوكن لسه معاه',        $ghost,    $A,        false],
            ['عميل تطبيق (user_id = null)',        Actor::customer(9, 'cus', 'عميل'), $A, false],
            ['بلا دخول',                           null,      $A,        false],

            // مدخلات غريبة — نفس صرامة filter_var بتاعة قناة الفرع
            ['pilot.0',                            $pilotA,   '0',       false],
            ['pilot.-1',                           $pilotA,   '-1',      false],
            ['pilot.07',                           $pilotA,   '07',      false],
            ['الصفر البادئ لرقمه هو',              $pilotA,   '0' . $A,  false],
            ['pilot.abc',                          $pilotA,   'abc',     false],
            ['اسم فاضي',                           $pilotA,   '',        false],
        ];

        foreach ($pilotCases as [$label, $actor, $channel, $expected]) {
            $check($label, $expected, $pilotAuth($actor, $channel));
        }

        echo "\n── الصرامة واحدة في القناتين ──\n";

        /* المطلوب مش «الرفض» في المطلق — المطلوب إن قناة الطيار تبقى **نفس**
           صرامة قناة الفرع بالحرف، عشان مايبقاش في النظام قاعدتين مختلفتين
           للشكل القانوني لرقم القناة. الفحص ده بيعدّي نفس أشكال الرقم على
           الدالتين — فاعل مصرّح له تمامًا في كل واحدة، والمتغيّر الوحيد هو
           شكل الرقم — وبيقارن **القرار بالقرار** بدل ما يثبّت قيمة متوقعة.

           ⚠️ **فجوة موروثة، والفحص ده بيوثّقها مش بيباركها:** `filter_var`
           بتشيل الفراغ المحيط وبتقبل علامة الزائد، فـ«7 » و«+7» بيعدّوا في
           **الاتنين**. يعني الحماية من انقسام القناة اللي التعليق فوق
           `branch.{branchId}` بيتكلم عنها مش كاملة: «pilot.7» و«pilot.+7»
           قناتين مختلفتين عند Reverb بنفس التفويض بالظبط. الفجوة دي في قناة
           الفرع من الأصل — مانضيّقهاش هنا لوحدها عشان القناتين مايفترقوش،
           ولو اتقفلت يومًا الفحص ده هو اللي بيضمن إنها تتقفل في الاتنين
           مرة واحدة. */
        $brSame = Actor::staff(3, '__rt_branch', 'branch', $pidA, 'فرع بنفس الرقم');

        foreach (['07', '+' . $A, $A . ' ', ' ' . $A, $A . '.0', '0', '-' . $A, 'abc', ''] as $form) {
            $check(
                'نفس القرار على ' . var_export($form, true),
                $branchAuth($brSame, $form),
                $pilotAuth($pilotA, $form)
            );
        }
    } finally {
        DB::rollBack();
    }

    // تأكيد إن القاعدة رجعت زي ما كانت — الفحص مايسيبش وراه حسابات
    $check('صفر أثر في القاعدة', 0, (int) DB::table('users')
        ->where('username', 'like', '\_\_rt\_%')->count());
}

echo "\n── قنوات OrderChanged ──\n";

/** أسماء القنوات اللي الحدث بيتبعت عليها، بالترتيب */
$channelNames = static fn (OrderChanged $e): array => array_map(
    static fn ($c): string => $c->name,
    $e->broadcastOn()
);

$onPilot = new OrderChanged(
    orderId: 1, orderNum: 'CAI-000000-001', statusCode: 'delivering',
    branchId: 3, pilotId: 7, updatedAtDb: null,
);

$noPilot = new OrderChanged(
    orderId: 2, orderNum: 'CAI-000000-002', statusCode: 'pending',
    branchId: 3, pilotId: null, updatedAtDb: null,
);

$check('أوردر عليه طيار → قناتين',
    ['private-branch.3', 'private-pilot.7'], $channelNames($onPilot));

$check('أوردر بلا طيار → قناة الفرع بس',
    ['private-branch.3'], $channelNames($noPilot));

// قناة الفرع تفضل الأولى — الفحص تحت وواجهات اللوحات بتعتمد على الترتيب ده
$check('الفرع أول قناة دايمًا', 'private-branch.3', $channelNames($onPilot)[0]);

echo "\n── حمولة OrderChanged ──\n";

$row = DB::selectOne(
    'SELECT id, order_num, status, branch_id, pilot_id, updated_at
       FROM orders ORDER BY id DESC LIMIT 1'
);

if ($row === null) {
    echo "  (مفيش أوردرات في القاعدة — الفحص ده اتخطى)\n";
} else {
    $event   = OrderChanged::fromRow($row);
    $payload = $event->broadcastWith();

    echo '  قناة : ' . $event->broadcastOn()[0]->name . "\n";
    echo '  اسم  : ' . $event->broadcastAs() . "\n";
    echo '  حمولة: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    $check('القناة خاصة وعلى الفرع',
        'private-branch.' . (int) $row->branch_id,
        $event->broadcastOn()[0]->name);

    $check('اسم الحدث مثبّت', 'order.changed', $event->broadcastAs());

    $check('المفاتيح الستة بالترتيب',
        ['id', 'orderNum', 'status', 'branchId', 'pilotId', 'updatedAt'],
        array_keys($payload));

    // الحالة لازم تبقى عربي زي OrderWire مش كود إنجليزي زي العمود
    $check('الحالة عربي مش كود',
        true,
        $payload['status'] === null || preg_match('/[\x{0600}-\x{06FF}]/u', (string) $payload['status']) === 1);

    // التوقيت ISO-8601 UTC بميلي ثانية وبحرف Z — نفس WireTime::toWire
    $check('التوقيت بصيغة السلك',
        true,
        $payload['updatedAt'] === null
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', (string) $payload['updatedAt']) === 1);
}

echo "\n════════════════════════════════════════════\n";
printf("REALTIME: %d مطابق / %d مختلف   (إجمالي %d)\n", $pass, $fail, $pass + $fail);
echo "════════════════════════════════════════════\n";

exit($fail === 0 ? 0 : 1);
