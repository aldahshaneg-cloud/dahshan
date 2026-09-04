<?php
/**
 * 🔐 حارس صلاحيات الكول سنتر على الأوردر.
 *
 * ═══ القاعدة ═══
 * قرار صاحب النظام 2026-08-30: «الكول سنتر يتعامل مع العميل فقط في تلقي
 * الأوردر والشكاوى، أما باقي العمل من اختصاص باقي المنظومة».
 *
 * ═══ ليه حارس على الراوتس مش على الواجهة ═══
 * إخفاء الزرار من الـHTML بيمنع الموظف يدوس، مش بيمنعه يبعت الطلب.
 * أي حد يفتح الـconsole ويكتب سطر fetch بيعدّي لو الميدلوير سايبه.
 * فالفحص ده بيقرا `routes/api.php` نفسه ويتأكد إن `callcenter` مش في
 * قايمة الأدوار للمسارات الممنوعة — وإن اللي مسموح له لسه مسموح.
 *
 * التشغيل: php ops/test_cc_routes.php
 */
$src = file_get_contents(__DIR__ . '/../routes/api.php');
$pass = 0;
$fail = 0;

function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$what}\n"; }
    else { $fail++; echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n"; }
}

/** بيرجّع نص الأدوار المسموحة لمسار معيّن، أو null لو المسار مش موجود. */
function rolesOf(string $src, string $verb, string $uri): ?string
{
    $q = preg_quote($uri, '/');
    // الراوت ممكن يكون على سطر أو سطرين — بنقرا لحد أول `;`
    if (! preg_match("/Route::{$verb}\(\s*'{$q}'.*?;/s", $src, $m)) {
        return null;
    }
    if (! preg_match("/role:([a-z,]+)/", $m[0], $r)) {
        return '';                        // مفيش ميدلوير دور خالص
    }

    return $r[1];
}

/* ── ممنوع على الكول سنتر ── */
$BANNED = [
    ['post', 'orders/{id}/cancel',          'إلغاء الأوردر'],
    ['post', 'orders/{id}/postpone',        'تأجيل'],
    ['post', 'orders/{id}/unpostpone',      'فك التأجيل'],
    ['post', 'orders/{id}/transfer-branch', 'نقل لفرع'],
    ['put',  'orders/{id}',                 'تعديل بيانات الأوردر'],
    ['post', 'orders/{id}/assign',          'تحميل على طيار'],
    ['post', 'orders/assign-bulk',          'تحميل جماعي'],
    ['post', 'orders/{id}/transfer',        'نقل لطيار'],
    ['post', 'orders/{id}/deliver',         'تسليم'],
    ['post', 'orders/{id}/undeliver',       'عدم تسليم'],
    ['post', 'orders/{id}/split',           'تقسيم الأوردر'],
    ['post', 'orders/{id}/settle-money',    'تسوية مالية'],
];

echo "\n══ 1) 🔴 مسارات ممنوعة على الكول سنتر ══\n";
foreach ($BANNED as [$verb, $uri, $label]) {
    $roles = rolesOf($src, $verb, $uri);
    if ($roles === null) { ok("«{$label}» — المسار موجود", false, "مالقيتش {$verb} {$uri}"); continue; }
    $list = array_filter(explode(',', $roles));
    ok("«{$label}» مقفول", ! in_array('callcenter', $list, true), $roles !== '' ? $roles : '(مفيش قيد دور خالص)');
}

/* ── لازم يفضل مفتوح: ده شغله ── */
$ALLOWED = [
    ['post', 'orders',      'تسجيل أوردر جديد'],
    ['put',  'senders/{id}',   'تعديل بيانات المرسِل'],
    ['put',  'receivers/{id}', 'تعديل بيانات المستلم'],
];

echo "\n══ 2) اللي لازم يفضل شغّال للكول سنتر ══\n";
foreach ($ALLOWED as [$verb, $uri, $label]) {
    $roles = rolesOf($src, $verb, $uri);
    if ($roles === null) { ok("«{$label}» — المسار موجود", false, "مالقيتش {$verb} {$uri}"); continue; }
    ok("«{$label}» مفتوح", str_contains($roles, 'callcenter'), $roles);
}

echo "\n══ 3) الفرع والإدارة لسه عندهم اللي اتسحب ══\n";
foreach ([['post', 'orders/{id}/cancel', 'إلغاء'],
          ['post', 'orders/{id}/postpone', 'تأجيل'],
          ['post', 'orders/{id}/unpostpone', 'فك تأجيل'],
          ['post', 'orders/{id}/transfer-branch', 'نقل لفرع'],
          ['put',  'orders/{id}', 'تعديل البيانات']] as [$verb, $uri, $label]) {
    $roles = rolesOf($src, $verb, $uri) ?? '';
    ok("«{$label}» — الفرع والإدارة", str_contains($roles, 'branch') && str_contains($roles, 'admin'), $roles);
}

echo "\n══ 4) الشكاوى — الباب التاني للكول سنتر ══\n";
ok('تسجيل شكوى مفتوح', (bool) preg_match("/Route::post\(\s*'complaints'/", $src));

echo "\n════════════════════════════════════════\n";
echo "CC ROUTES: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
