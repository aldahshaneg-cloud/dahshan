<?php

declare(strict_types=1);

/**
 * 📞 اختبار توريث أرقام دعم الطيار من أرقام الموقع.
 *
 * ═══ المشكلة ═══
 * الأرقام متخزّنة في مكانين: `site.info` (الموقع · تطبيق العميل · بوابة
 * المحلات) و`pilotAppContent.support` (تطبيق الطيار). التاني عمره ما
 * اتملى، فالطيار كان بيفتح «تواصل مع الدعم» ويلاقي «لسه ما اتظبطتش»
 * والأرقام موجودة في اللوحة طول الوقت.
 *
 * الحل: توريث في السيرفر — بيوصل كل الطيارين فورًا من غير نسخة جديدة.
 *
 * كله جوه معاملة بتترجع فمفيش أثر.
 *
 * التشغيل: php ops/test_support_inherit.php
 */

use App\Http\Controllers\Api\SupportController;
use App\Http\Middleware\ResolveApiActor;
use App\Support\Actor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
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


$pass = 0;
$fail = 0;
function ok(string $what, bool $cond, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✓ {$what}\n";
    } else {
        $fail++;
        echo "  ✗ {$what}" . ($got !== '' ? "   ← {$got}" : '') . "\n";
    }
}

/** بيحط قيمة إعداد ويرجّع رد settingsGet بعدها */
function put(string $key, mixed $value): void
{
    DB::insert(
        'INSERT INTO site_settings (setting_key, setting_value, updated_by, updated_at)
         VALUES (?,?,?,NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        [$key, json_encode($value, JSON_UNESCAPED_UNICODE), 'test']
    );
}

function settings(): array
{
    $ctl = new SupportController();
    $r = Request::create('/', 'GET');
    $r->attributes->set(ResolveApiActor::ATTRIBUTE, Actor::staff(1, 'admin', 'admin', null, 'مدير'));

    return json_decode($ctl->settingsGet($r)->getContent(), true)['settings'] ?? [];
}

DB::beginTransaction();
try {
    $siteInfo = [
        'phone'     => '01040065651',
        'whatsapp'  => '201092253214',
        'phones'    => [['n' => '01040065651', 'label' => 'خدمة العملاء']],
        'whatsapps' => [['n' => '201092253214', 'label' => 'الدعم']],
        'email'     => 'x@y.z',
    ];

    echo "\n══ 1) خانات الطيار فاضية → بيورّث أرقام الموقع ══\n";
    put('site', ['info' => $siteInfo]);
    put('pilotAppContent', ['support' => ['whatsapp' => '', 'phone' => ''], 'faq' => [['q' => 'س', 'a' => 'ج']]]);

    $sup = settings()['pilotAppContent']['support'] ?? [];
    ok('التليفون اتورّث', ($sup['phone'] ?? '') === '01040065651', var_export($sup['phone'] ?? null, true));
    ok('الواتساب اتورّث', ($sup['whatsapp'] ?? '') === '201092253214', var_export($sup['whatsapp'] ?? null, true));
    ok('القوايم اتورّثت كمان', ($sup['phones'][0]['label'] ?? '') === 'خدمة العملاء',
        json_encode($sup['phones'] ?? null, JSON_UNESCAPED_UNICODE));
    /* العلامة دي هي اللي بتمنع لوحة التحكم من نسخ الأرقام وكسر التوريث.
       بقت **قايمة قنوات** مش true/false، عشان كل قناة تتحاسب لوحدها. */
    ok('العلامة بتقول القنّاتين اتورّثوا', ($sup['inherited'] ?? []) === ['phone', 'whatsapp'],
        json_encode($sup['inherited'] ?? null));
    ok('الأسئلة الشائعة ما اتلمستش',
        (settings()['pilotAppContent']['faq'][0]['q'] ?? '') === 'س',
        json_encode(settings()['pilotAppContent']['faq'] ?? null, JSON_UNESCAPED_UNICODE));

    echo "\n══ 2) خانات الطيار متكتوبة → مابيلمسهاش ══\n";
    put('pilotAppContent', ['support' => ['whatsapp' => '201111111111', 'phone' => '01011111111']]);
    $sup2 = settings()['pilotAppContent']['support'] ?? [];
    ok('التليفون بتاع الطيار زي ما هو', ($sup2['phone'] ?? '') === '01011111111', (string) ($sup2['phone'] ?? ''));
    ok('الواتساب بتاع الطيار زي ما هو', ($sup2['whatsapp'] ?? '') === '201111111111', (string) ($sup2['whatsapp'] ?? ''));
    ok('مفيش علامة توريث', ! isset($sup2['inherited']), var_export($sup2['inherited'] ?? null, true));

    echo "\n══ 3) رقم واحد متكتوب → القناة التانية بتورّث لوحدها ══\n";
    /* ⚠️ السلوك ده **اتغيّر عن قصد**: قبل كده رقم واحد في أي قناة كان
       بيقفل التوريث على الاتنين، فأرقام القناة التانية كانت بتختفي من
       التطبيق من غير رسالة. دلوقتي كل قناة لوحدها. */
    put('pilotAppContent', ['support' => ['whatsapp' => '', 'phone' => '01022222222']]);
    $sup3 = settings()['pilotAppContent']['support'] ?? [];
    ok('التليفون المتكتوب زي ما هو', ($sup3['phone'] ?? '') === '01022222222', (string) ($sup3['phone'] ?? ''));
    ok('والواتساب الفاضي ورث من الموقع', ($sup3['whatsapp'] ?? '') === '201092253214',
        var_export($sup3['whatsapp'] ?? null, true));
    ok('العلامة بتقول الواتساب بس', ($sup3['inherited'] ?? []) === ['whatsapp'],
        json_encode($sup3['inherited'] ?? null));

    echo "\n══ 4) كلام من غير أرقام = فاضي ══\n";
    /* الخانة نص حر. لو المدير كتب «كلّمنا» من غير رقم، التطبيق بيرفضها
       ويعرض «لسه ما اتظبطتش» — فلازم نعتبرها فاضية ونورّث. */
    put('pilotAppContent', ['support' => ['whatsapp' => 'كلّمنا', 'phone' => '   ']]);
    $sup4 = settings()['pilotAppContent']['support'] ?? [];
    ok('الكلام من غير أرقام اتعامل كفاضي واتورّث',
        ($sup4['phone'] ?? '') === '01040065651' && ($sup4['inherited'] ?? []) === ['phone', 'whatsapp'],
        json_encode($sup4, JSON_UNESCAPED_UNICODE));

    echo "\n══ 5) الموقع نفسه مالوش أرقام → مفيش توريث وهمي ══\n";
    put('site', ['info' => ['phone' => '', 'whatsapp' => '', 'email' => 'x@y.z']]);
    put('pilotAppContent', ['support' => ['whatsapp' => '', 'phone' => '']]);
    $sup5 = settings()['pilotAppContent']['support'] ?? [];
    ok('مفيش علامة توريث', ! isset($sup5['inherited']), var_export($sup5['inherited'] ?? null, true));
    ok('الخانات فضلت فاضية', ($sup5['phone'] ?? 'x') === '', var_export($sup5['phone'] ?? null, true));

    echo "\n══ 6) مفتاح الطيار مش موجود خالص ══\n";
    put('site', ['info' => $siteInfo]);
    DB::delete("DELETE FROM site_settings WHERE setting_key = 'pilotAppContent'");
    $sup6 = settings()['pilotAppContent']['support'] ?? [];
    ok('اتورّث برضه', ($sup6['phone'] ?? '') === '01040065651', var_export($sup6['phone'] ?? null, true));

    echo "\n══ 6.1) 🔴 التوريث قناة قناة — مش «كل أو لا شيء» ══\n";
    /* اللسعة اللي المراجعة العدائية مسكتها: أول نسخة كانت بتفحص القنّاتين
       مع بعض. المدير يحط واتساب لدعم الطيارين ← التوريث يتقفل ← أرقام
       **التليفون** الموروثة تختفي من التطبيق من غير ولا رسالة. */
    put('site', ['info' => $siteInfo]);
    put('pilotAppContent', ['support' => [
        'whatsapp' => '201111111111', 'whatsapps' => [['n' => '201111111111', 'label' => 'دعم الطيارين']],
        'phone' => '', 'phones' => [],
    ]]);
    $s61 = settings()['pilotAppContent']['support'] ?? [];
    ok('الواتساب المتكتوب زي ما هو', ($s61['whatsapp'] ?? '') === '201111111111', (string) ($s61['whatsapp'] ?? ''));
    ok('🔒 التليفون اتورّث برغم إن الواتساب متكتوب',
        ($s61['phone'] ?? '') === '01040065651', var_export($s61['phone'] ?? null, true));
    ok('العلامة بتقول التليفون بس', ($s61['inherited'] ?? []) === ['phone'],
        json_encode($s61['inherited'] ?? null));

    // والعكس
    put('pilotAppContent', ['support' => [
        'phone' => '01011111111', 'phones' => [['n' => '01011111111', 'label' => '']],
        'whatsapp' => '', 'whatsapps' => [],
    ]]);
    $s61b = settings()['pilotAppContent']['support'] ?? [];
    ok('التليفون المتكتوب زي ما هو', ($s61b['phone'] ?? '') === '01011111111', (string) ($s61b['phone'] ?? ''));
    ok('🔒 الواتساب اتورّث', ($s61b['whatsapp'] ?? '') === '201092253214', var_export($s61b['whatsapp'] ?? null, true));
    ok('العلامة بتقول الواتساب بس', ($s61b['inherited'] ?? []) === ['whatsapp'],
        json_encode($s61b['inherited'] ?? null));

    echo "\n══ 6.2) 🔴 الأرقام العربية بتتقرا أرقام ══\n";
    /* `preg_replace('/\D+/','')` بيرجّع فاضي للأرقام العربية — فرقم متكتوب
       بالكيبورد العربي كان بيتعامل كـ«مفيش رقم»: السيرفر يورّث فوقه،
       واللوحة تفضّي الخانة، وأول حفظ يمسحه نهائي. */
    put('pilotAppContent', ['support' => ['phone' => '٠١٠٢٢٢٢٢٢٢٢', 'whatsapp' => '', 'phones' => [], 'whatsapps' => []]]);
    $s62 = settings()['pilotAppContent']['support'] ?? [];
    ok('الرقم العربي اتحسب رقم — ما اتورّثش فوقه',
        ($s62['phone'] ?? '') === '٠١٠٢٢٢٢٢٢٢٢', var_export($s62['phone'] ?? null, true));
    ok('والواتساب الفاضي اتورّث عادي', ($s62['whatsapp'] ?? '') === '201092253214',
        var_export($s62['whatsapp'] ?? null, true));
    ok('العلامة بتقول الواتساب بس', ($s62['inherited'] ?? []) === ['whatsapp'],
        json_encode($s62['inherited'] ?? null));

    // ونفس الحكاية في القوايم
    put('pilotAppContent', ['support' => ['phones' => [['n' => '۰۱۲۳۴۵۶۷۸۹', 'label' => 'فارسي']]]]);
    $s62b = settings()['pilotAppContent']['support'] ?? [];
    ok('الأرقام الفارسية في القايمة كمان',
        ($s62b['phones'][0]['n'] ?? '') === '۰۱۲۳۴۵۶۷۸۹', json_encode($s62b['phones'] ?? null, JSON_UNESCAPED_UNICODE));

    echo "\n══ 7) باقي الإعدادات ما اتلمستش ══\n";
    put('workHours', ['from' => '09:00', 'to' => '04:00', 'on' => true]);
    $all = settings();
    ok('workHours زي ما هي', ($all['workHours']['from'] ?? '') === '09:00', json_encode($all['workHours'] ?? null));
    ok('site.info زي ما هي', ($all['site']['info']['phone'] ?? '') === '01040065651',
        var_export($all['site']['info']['phone'] ?? null, true));
} catch (Throwable $e) {
    $fail++;
    echo "\n💥 " . get_class($e) . ': ' . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    DB::rollBack();
}

echo "\n════════════════════════════════════════\n";
echo "SUPPORT INHERIT: {$pass} ناجح · {$fail} فاشل\n";
echo "════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
