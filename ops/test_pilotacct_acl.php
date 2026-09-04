<?php
/**
 * 🔐 حارس: صلاحيات «تقفيل الطيارين» — السيرفر.
 *
 * ═══ ليه التنفيذ مش الفحص النصّي ═══
 * القصّ ده بيقرر أرقام فلوس تخرج ولا لأ. فحص نصّي بيعدّي على أي إعادة
 * صياغة بترجّع العمود من غير ما ينتبه. الملف ده **بينفّذ** `filterDay`
 * و`filterTotals` و`aclOf` على قيم حقيقية ويشوف الخارج بعينه.
 *
 * ═══ كود الخروج بيكدب ═══
 * استثناء في نص السكربت بيخلّي PHP يخرج بصفر، فالحارس يبان ناجح وهو
 * وقع. عشان كده الملف كله جوّه try/catch والخروج صريح في الآخر.
 *
 * ═══ اللي بيغلط ═══
 * ① `unset` على مفتاح ومفتاح تاني بيفضل — الحارس بيعدّ الخارج مش بيدوّر
 *    على الغايب، فأي حقل جديد في `dayRow` بلا مفتاح بيبان.
 * ② الافتراضي «كل حاجة» بيدي مشرف الفرع قفل الشهر — الفحص بيتأكد إن
 *    التلاتة المحجوزة مش في الافتراضي.
 * ③ قالب فيه مفتاح مش معروف: الشاشة بتعلّم عليه والسيرفر بيرميه.
 *
 * التشغيل: php ops/test_pilotacct_acl.php
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

try {
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


    /** @var class-string $W */
    $W = App\Wire\PilotAccountingWire::class;

    /* ══ 1) المفاتيح ══ */
    echo "\n══ 1) المفاتيح ══\n";
    $keys = $W::permKeys();
    ok('فيه مفاتيح', count($keys) > 0);
    ok('🔴 مافيش مفتاح مكرر — التكرار بيخلّي عدّاد المجموعة يكدب',
        count($keys) === count(array_unique($keys)),
        implode(',', array_diff_assoc($keys, array_unique($keys))));

    $groups = $W::permGroups();
    $flat = [];
    foreach ($groups as $g) {
        ok('مجموعة «' . $g['title'] . '» فيها بنود', ! empty($g['items']));
        foreach ($g['items'] as $it) {
            ok('  البند «' . $it[0] . '» ليه اسم عربي', isset($it[1]) && trim($it[1]) !== '');
            $flat[] = $it[0];
        }
    }
    ok('permKeys هي بالظبط اللي في المجموعات', $flat === $keys);

    /* ══ 2) الافتراضي ══ */
    echo "\n══ 2) الافتراضي ══\n";
    $full = $W::fullPermKeys();
    $def  = $W::defaultPermKeys();
    ok('الكامل فيه كل المفاتيح', count($full) === count($keys));
    ok('وكلهم `true` مش `1`', array_values(array_unique(array_values($full))) === [true]);

    /* 🔴 التلاتة مكتوبين هنا بالنص **مقصود**. أول نسخة من الفحص كانت
       بتلف على `$W::ADMIN_ONLY_KEYS` نفسها — يعني بتقيس الكود بنفسه.
       طفرة شالت `act.settings` من الثابت وعدّت: الحلقة قصرت والفحوص
       نجحت والصلاحية الإدارية بقت في الافتراضي للكل.
       القايمة دي هي العقد، والثابت لازم يطابقها. */
    $RESERVED = ['act.deferred', 'act.lock', 'act.settings', 'act.payout'];
    ok('🔴 الثابت مطابق للعقد المكتوب هنا',
        $W::ADMIN_ONLY_KEYS === $RESERVED,
        'الثابت: ' . implode(',', $W::ADMIN_ONLY_KEYS));
    foreach ($RESERVED as $k) {
        ok("🔴 «{$k}» مش في الافتراضي — وإلا كل مشرف فرع هياخدها فجأة",
            ! isset($def[$k]),
            'الافتراضي بيمنح صلاحية إدارية');
    }
    ok('والباقي كله في الافتراضي',
        count($def) === count($keys) - count($RESERVED));

    /* الافتراضي لازم يشمل كل شاشة وكل عمود — ده اللي بيخلّيه «زي دلوقتي» */
    foreach ($keys as $k) {
        if (! in_array($k, $W::ADMIN_ONLY_KEYS, true)) {
            if (! isset($def[$k])) {
                ok("🔴 «{$k}» ناقص من الافتراضي", false, 'حد هيفقد صلاحية عنده دلوقتي');
            }
        }
    }
    ok('مافيش صلاحية شغّالة دلوقتي هتضيع', true);

    /* ══ 3) القوالب ══ */
    echo "\n══ 3) القوالب ══\n";
    foreach ($W::permPresets() as $name => $p) {
        ok("قالب «{$name}» ليه اسم عربي", isset($p['label']) && trim($p['label']) !== '');
        $unknown = array_diff($p['keys'], $keys);
        ok("🔴 وكل مفاتيحه معروفة — المفتاح الغريب بيتعلّم عليه ويترمي",
            $unknown === [],
            implode(',', $unknown));
    }

    /* ══ 4) قصّ صف اليوم — تنفيذ فعلي ══ */
    echo "\n══ 4) قصّ صف اليوم ══\n";
    $row = [
        'day' => 7, 'in' => '09:00', 'out' => '19:00',
        'perms' => [['out' => '12:00', 'in' => '12:30']],
        'hours' => 9.5, 'orders' => 14, 'svc' => 210.0, 'psvc' => 84.0,
        'net' => 126.0, 'adv' => 50.0, 'ded' => 5.0, 'bonus' => 20.0,
        'note' => 'نص سري', 'handed' => 3022.0, 'carry' => [], 'edited' => [], 'auto' => [],
    ];

    /* 🔴 كل حقل في الصف لازم يكون: يا إما مربوط بمفتاح، يا إما في القايمة
       البيضا. حقل جديد يتضاف لـ`dayRow` من غير مفتاح هيخرج للكل من غير
       ما حد ياخد باله — الفحص ده هو اللي بيمسكها. */
    $ALWAYS = ['day', 'carry', 'edited', 'auto', 'shiftIds', 'openShift'];
    $cut = $W::filterDay($row, []);   // مافيش أي صلاحية خالص
    $leaked = array_diff(array_keys($cut), $ALWAYS);
    ok('🔴 بلا أي صلاحية: مافيش حقل بيخرج غير المسموح دايمًا',
        $leaked === [],
        'تسرّب: ' . implode(', ', $leaked));

    /* 🔴 الباب الخلفي.
       أول نسخة من الفحص حطّت `auto` و`edited` في القايمة البيضا وخلاص،
       فعدّت. اختبار HTTP حقيقي على الراوتر بيّن إن الرد «النضيف» كان
       لسه بيبعت `auto.svc` و`auto.psvc` — يعني إجمالي الخدمة وخدمة
       الطيار خارجين من ورا العمود المقفول. الفحوص دي على المحتوى
       نفسه مش على وجود المفتاح. */
    $rowFull = $row;
    $rowFull['auto'] = ['in' => '09:00', 'out' => '19:00', 'hours' => 9.5,
                        'orders' => 14, 'svc' => 210.0, 'psvc' => 84.0];
    $rowFull['edited'] = ['in', 'svc', 'psvc', 'orders'];

    $c0 = $W::filterDay($rowFull, []);
    ok('🔴 `auto` بيتفضّى مع أعمدته — مش بيسرّب الفلوس من الباب الخلفي',
        ($c0['auto'] ?? []) === [],
        'خرج: ' . implode(',', array_keys($c0['auto'] ?? [])));
    ok('🔴 و`edited` مابيقولش إن فيه تدخّل في خانة مالوش صلاحية عليها',
        ($c0['edited'] ?? []) === [],
        'خرج: ' . implode(',', $c0['edited'] ?? []));

    $c1 = $W::filterDay($rowFull, ['col.in' => true, 'col.orders' => true]);
    ok('و`auto` بيسيب المسموح بس',
        array_keys($c1['auto'] ?? []) === ['in', 'orders'],
        implode(',', array_keys($c1['auto'] ?? [])));
    ok('و`edited` كمان',
        ($c1['edited'] ?? []) === ['in', 'orders'],
        implode(',', $c1['edited'] ?? []));

    $c2 = $W::filterDay($rowFull, $W::defaultPermKeys());
    ok('وبالافتراضي كله بيفضل زي ما هو — مافيش حاجة بتضيع',
        array_keys($c2['auto']) === array_keys($rowFull['auto'])
        && $c2['edited'] === $rowFull['edited']);

    $money = ['svc', 'psvc', 'net', 'adv', 'ded', 'bonus', 'handed'];
    foreach ($money as $f) {
        ok("  «{$f}» اتشال", ! array_key_exists($f, $cut));
    }
    ok('  والملاحظات اتشالت', ! array_key_exists('note', $cut));
    ok('  والاستئذان اتشال', ! array_key_exists('perms', $cut));
    ok('  و«day» فضل — الصف من غيره مالوش معنى', array_key_exists('day', $cut));

    /* كل مفتاح لوحده بيرجّع حقله هو بس */
    $pairs = ['col.in' => 'in', 'col.out' => 'out', 'col.hours' => 'hours',
              'col.orders' => 'orders', 'col.svc' => 'svc', 'col.psvc' => 'psvc',
              'col.net' => 'net', 'col.adv' => 'adv', 'col.ded' => 'ded',
              'col.bonus' => 'bonus', 'col.handed' => 'handed', 'col.note' => 'note'];
    $bad = [];
    foreach ($pairs as $key => $field) {
        $r = $W::filterDay($row, [$key => true]);
        if (! array_key_exists($field, $r)) { $bad[] = "{$key} مابيفتحش {$field}"; }
        $extra = array_diff(array_keys($r), array_merge($ALWAYS, [$field]));
        if ($extra) { $bad[] = "{$key} بيفتح كمان: " . implode('/', $extra); }
    }
    ok('🔴 كل مفتاح بيفتح حقله هو بس — لا أكتر ولا أقل',
        $bad === [], implode(' · ', $bad));

    /* الاستئذان: عمودين ومفتاحين */
    ok('الاستئذان بيبان لو «الخروج» مفتوح',
        array_key_exists('perms', $W::filterDay($row, ['col.bout' => true])));
    ok('وبيبان لو «الرجوع» مفتوح',
        array_key_exists('perms', $W::filterDay($row, ['col.bin' => true])));
    ok('🔴 وبيتشال لما الاتنين مقفولين',
        ! array_key_exists('perms', $W::filterDay($row, ['col.in' => true])));

    /* ══ 5) قصّ الإجماليات ══ */
    echo "\n══ 5) قصّ الإجماليات ══\n";
    $t = [
        'hours' => 180.0, 'worked' => 22, 'countedDays' => 26, 'absent' => 4,
        'orders' => 300, 'hourRate' => 10.0, 'hourPay' => 1800.0,
        'commission' => 900.0, 'monthlySalary' => 0.0, 'salaryShare' => 0.0,
        'leaveCap' => 2, 'leaveDays' => 2, 'leaveLeft' => 0, 'leavePay' => 200.0,
        'bonusDue' => 30.0, 'advanceDue' => 200.0, 'deductionDue' => 10.0,
        'deferredDue' => 100.0, 'deferredLeft' => 400.0,
        'gross' => 2930.0, 'netDue' => 2620.0, 'shiftHours' => 10.0,
    ];
    $ct = $W::filterTotals($t, []);
    $MONEY_FIELDS = ['hourPay', 'commission', 'salaryShare', 'leavePay', 'bonusDue',
                     'advanceDue', 'deductionDue', 'deferredDue', 'deferredLeft',
                     'gross', 'netDue', 'hourRate', 'monthlySalary'];
    $out = array_intersect($MONEY_FIELDS, array_keys($ct));
    ok('🔴 بلا صلاحية: مافيش رقم فلوس بيخرج',
        $out === [], 'خرج: ' . implode(', ', $out));

    $ct2 = $W::filterTotals($t, ['mon.net' => true]);
    ok('«صافي الراتب» بيفتح الصافي والإجمالي',
        array_key_exists('netDue', $ct2) && array_key_exists('gross', $ct2));
    ok('🔴 ومابيفتحش العمولة ولا أجر الساعات',
        ! array_key_exists('commission', $ct2) && ! array_key_exists('hourPay', $ct2),
        'مفتاح واحد بيفتح أكتر من عمود');

    $ct3 = $W::filterTotals($t, ['mon.hours' => true]);
    ok('«الساعات» بتفتح أيام الشغل والغياب معاها — دول نفس المعنى',
        array_key_exists('worked', $ct3) && array_key_exists('absent', $ct3));

    /* ══ 6) القصّ مابيلمسش الأصل ══ */
    echo "\n══ 6) الأصل مابيتغيّرش ══\n";
    $before = $row;
    $W::filterDay($row, ['col.in' => true]);
    ok('🔴 filterDay مابتعدّلش الصف الأصلي',
        $row === $before, 'بتعدّل بالمرجع — الطيار اللي بعده هياخد صف مقصوص');
    $tb = $t;
    $W::filterTotals($t, []);
    ok('وfilterTotals كمان', $t === $tb);

    /* ══ 7) الكنترولر — الحرّاس موجودة فعلًا ══ */
    echo "\n══ 7) الحرّاس في الكنترولر ══\n";
    $src = file_get_contents($ROOT . '/app/Http/Controllers/Api/PilotAccountingController.php');
    /* التعليقات بتتشال — الشرح فوق كل حارس بيذكر أسماء المفاتيح،
       والفحص كان هيمسك شرحه هو. */
    $code = preg_replace('#/\*[\s\S]*?\*/|//[^\n]*#', '', $src);

    $guards = [
        "need(\$acl, 'act.edit'"                 => 'تعديل الخانات',
        "need(\$acl, 'col.' . \$field"           => 'العمود المكتوب فيه',
        "'page.deferred'"                        => 'شاشة السلف',
        "'act.deferred'"                         => 'الكتابة على السلف',
        "'act.lock'"                             => 'قفل الشهر',
        "'act.settings'"                         => 'الإعدادات',
        "'act.payout'"                           => 'صرف الرواتب من الخزنة',
    ];
    foreach ($guards as $needle => $what) {
        ok("حارس {$what}", str_contains($code, $needle));
    }
    ok('🔴 والسلف بتاعتها تلات نقط كتابة كلهم محروسين',
        substr_count($code, "'act.deferred'") === 3,
        substr_count($code, "'act.deferred'") . ' من ٣');
    ok('وقفل الشهر نقطتين (قفل وفتح)',
        substr_count($code, "'act.lock'") === 2,
        substr_count($code, "'act.lock'") . ' من ٢');

    ok('🔴 القصّ بيتطبّق على الأيام والإجماليات في month',
        str_contains($code, 'W::filterDay($r, $acl[\'keys\'])')
        && str_contains($code, 'W::filterTotals($totals, $acl[\'keys\'])'),
        'الصفوف بتخرج كاملة');
    ok('والـacl بتترسل للواجهة', str_contains($code, "'acl'         => ["));
    ok('🔴 والسلف مابتخرجش في رد month لو الشاشة مقفولة',
        str_contains($code, "\$this->can(\$acl, 'page.deferred') ? \$this->deferredWire"),
        'السلف بتخرج للكل');
    ok('وشاشة الصلاحيات للأدمن بس — في الكنترولر مش في المسار بس',
        substr_count($code, "شاشة الصلاحيات للإدارة بس") === 3);
    ok('والمقارنة `=== true` في can()',
        str_contains($code, "(\$acl['keys'][\$key] ?? null) === true"));
    ok('🔴 واللي مالوش صف بياخد الافتراضي مش الكامل',
        str_contains($code, 'W::defaultPermKeys()'),
        'بياخد كل حاجة — يعني قفل الشهر كمان');
    ok('والمفتاح المش معروف بيتترمي عند الحفظ',
        str_contains($code, 'array_flip(W::permKeys())'));
    ok('والأدمن مايتحفظلوش صف', str_contains($code, 'الأدمن عنده كل الصلاحيات أصلًا'));

    /* ══ 8) المسارات ══ */
    echo "\n══ 8) المسارات ══\n";
    $rt = file_get_contents($ROOT . '/routes/api.php');
    foreach ([
        "Route::get('pilot-accounting/acl'"           => 'قراءة الصلاحيات',
        "Route::put('pilot-accounting/acl'"           => 'حفظ الصلاحيات',
        "Route::delete('pilot-accounting/acl/{userId}'" => 'الرجوع للافتراضي',
    ] as $needle => $what) {
        ok("مسار {$what}", str_contains($rt, $needle));
    }
    ok('🔴 والتلاتة `role:admin` — الكنترولر بيتأكد تاني',
        preg_match_all("/pilot-accounting\/acl[^\n]*\n\s*->middleware\('role:admin'\);/", $rt) === 3,
        'واحد منهم مفتوح لدور تاني');

    /* ══ 9) الجدول ══ */
    echo "\n══ 9) الجدول ══\n";

    $has = Illuminate\Support\Facades\DB::select(
        "SELECT COUNT(*) c FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = 'pilot_acct_perms'"
    );
    ok('الجدول موجود', (int) $has[0]->c === 1);

    $cols = Illuminate\Support\Facades\DB::select('SHOW COLUMNS FROM pilot_acct_perms');
    $names = array_map(fn ($c) => $c->Field, $cols);
    foreach (['user_id', 'perm_keys', 'branches', 'updated_by'] as $c) {
        ok("  عمود {$c}", in_array($c, $names, true));
    }
    $idx = Illuminate\Support\Facades\DB::select('SHOW INDEX FROM pilot_acct_perms');
    $uniq = array_filter($idx, fn ($i) => $i->Key_name === 'uq_pilot_acct_perms_user');
    ok('🔴 وقيد فريد على المستخدم — بدونه ON DUPLICATE مابتشتغلش وبيتعمل صفين',
        count($uniq) === 1);
} catch (Throwable $e) {
    $fail++;
    echo "\n  ✗ استثناء: " . $e->getMessage() . "\n     " . $e->getFile() . ':' . $e->getLine() . "\n";
}

echo "\n" . str_repeat('─', 52) . "\n";
echo $fail === 0
    ? "✅ عدّى {$pass} فحص — الصلاحيات مفروضة على السيرفر\n\n"
    : "🔴 وقع {$fail} من " . ($pass + $fail) . "\n\n";
exit($fail === 0 ? 0 : 1);
