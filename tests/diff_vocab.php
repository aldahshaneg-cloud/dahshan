<?php

declare(strict_types=1);

/**
 * فحص تفاضلي للقاموس: بيحمّل **الكود القديم نفسه** (api/constants.php من
 * مشروع aldahshan) جنب الكلاسات الجديدة، وبيقارن ناتج كل دالة على مجموعة
 * مدخلات واسعة — بما فيها المدخلات الغريبة (null، فاضي، كود مجهول، عربي
 * مش في الخريطة).
 *
 * ليه الفحص ده: القاموس هو اللي بيحوّل بين لسان الواجهات وأكواد القاعدة.
 * أي حرف مختلف في نص عربي = الواجهة بتقارن `o.status === "تم التسليم"`
 * وتفشل بصمت. المقارنة مع الأصل هي الدليل الوحيد المقبول.
 *
 * التشغيل: php tests/diff_vocab.php
 */

$laravelRoot = dirname(__DIR__);
$legacyRoot  = dirname($laravelRoot) . DIRECTORY_SEPARATOR . 'aldahshan';
$legacyFile  = $legacyRoot . '/api/constants.php';

if (! is_file($legacyFile)) {
    fwrite(STDERR, "مالقيتش الكود القديم: {$legacyFile}\n");
    exit(1);
}

date_default_timezone_set('UTC');   // زي api/config.php

require $legacyFile;                                   // الأصل
require $laravelRoot . '/app/Support/Vocab.php';        // الجديد
require $laravelRoot . '/app/Support/WireTime.php';
require $laravelRoot . '/app/Support/OrderNumber.php';
require $laravelRoot . '/app/Support/Commission.php';

use App\Support\Commission;
use App\Support\OrderNumber;
use App\Support\Vocab;
use App\Support\WireTime;

$pass = 0;
$fail = 0;
$failures = [];

function cmp(string $fn, $input, $old, $new): void
{
    global $pass, $fail, $failures;
    if ($old === $new) {
        $pass++;
        return;
    }
    $fail++;
    $failures[] = sprintf(
        "  %s(%s)\n     الأصل : %s\n     الجديد: %s",
        $fn,
        var_export($input, true),
        var_export($old, true),
        var_export($new, true)
    );
}

/* ── مدخلات الاختبار: الصالح + الغريب + المجهول ─────────────── */
$statusInputs = array_merge(
    array_keys(ORDER_STATUS_AR),
    array_values(ORDER_STATUS_AR),
    array_keys(ORDER_STATUS_CODE),
    ['', null, 'كود_مش_موجود', 'unknown_code', 'لم يتم التسليم', 'DELIVERED', ' delivered ']
);

foreach ($statusInputs as $s) {
    cmp('status_to_ar', $s, status_to_ar($s), Vocab::statusToAr($s));
    cmp('status_to_code', $s, status_to_code($s), Vocab::statusToCode($s));
    cmp('parcel_status_to_ar', $s, parcel_status_to_ar($s), Vocab::parcelStatusToAr($s));
}

$pilotInputs = ['waiting', 'delivering', 'on_leave', 'onLeave', '', null, 'حاجة_تانية'];
foreach ($pilotInputs as $s) {
    cmp('pilot_status_to_wire', $s, pilot_status_to_wire($s), Vocab::pilotStatusToWire($s));
    cmp('pilot_status_to_code', $s, pilot_status_to_code($s), Vocab::pilotStatusToCode($s));
}

$roleInputs = array_merge(
    array_keys(ROLE_AR),
    array_values(ROLE_AR),
    array_keys(ROLE_AR_ALIASES),
    ['', null, 'دور_مجهول', 'superadmin']
);
foreach ($roleInputs as $s) {
    cmp('role_to_ar', $s, role_to_ar($s), Vocab::roleToAr($s));
    cmp('role_to_code', $s, role_to_code($s), Vocab::roleToCode($s));
}

/* ── الوقت ──────────────────────────────────────────────────── */
$times = [
    null, '', '0000-00-00 00:00:00',
    '2026-08-07 14:03:25', '2026-01-01 00:00:00', '2026-12-31 23:59:59',
    '2026-08-07 14:03:25.123', '1999-06-15 08:30:00',
    'مش تاريخ',
];
foreach ($times as $t) {
    cmp('dt_to_wire', $t, dt_to_wire($t), WireTime::toWire($t));
}

$isos = [
    null, '', '2026-08-07T14:03:25.000Z', '2026-08-07T14:03:25.123456Z',
    '2026-08-07T16:03:25+02:00', '2026-08-07T14:03:25Z', '2026-08-07',
    'not-a-date', '2026-13-45T99:99:99Z',
];
foreach ($isos as $t) {
    cmp('wire_to_dt', $t, wire_to_dt($t), WireTime::toDb($t));
}

/* ── مفاتيح اليوم (توقيت القاهرة) ───────────────────────────── */
$stamps = [
    0, 1, 1754575405,
    mktime(23, 30, 0, 8, 6, 2026),   // قرب منتصف الليل — أخطر حالة
    mktime(0, 30, 0, 1, 1, 2026),
    mktime(22, 5, 0, 12, 31, 2026),
];
foreach ($stamps as $ts) {
    cmp('cairo_day_key', $ts, cairo_day_key($ts), OrderNumber::cairoDayKey($ts));
    cmp('cairo_day_key_compact', $ts, cairo_day_key_compact($ts), OrderNumber::cairoDayKeyCompact($ts));
}

/* ── رقم الأوردر ────────────────────────────────────────────── */
foreach ([['HAL', 1], ['CAI', 999], ['', 5], ['ZZI', 0], ['ABCDEF', 1234]] as [$code, $n]) {
    foreach ($stamps as $ts) {
        cmp(
            'format_order_num',
            "{$code}/{$n}/{$ts}",
            format_order_num($code, $n, $ts),
            OrderNumber::format($code, $n, $ts)
        );
    }
}

foreach ([[[]], [[2, 3]], [[1]], [[0, 2]], [['2', '3']], [[null, 4]]] as $case) {
    $nos = $case[0];
    cmp(
        'parcel_suffix_num',
        json_encode($nos),
        parcel_suffix_num('HAL-260804-001', $nos),
        OrderNumber::parcelSuffix('HAL-260804-001', $nos)
    );
}

/* ── العمولة (فلوس) ─────────────────────────────────────────── */
$types  = ['percent', 'fixed', null, '', 'per_order', 'حاجة_غريبة'];
$values = [0.0, 0.001, 1.0, 7.5, 10.0, 100.0, 250.0, -5.0];
$prices = [0.0, 35.0, 100.5, 1234.56, 99999.99];

foreach ($types as $t) {
    foreach ($values as $v) {
        foreach ($prices as $p) {
            cmp(
                'pilot_commission_for',
                "{$t}/{$v}/{$p}",
                pilot_commission_for($t, $v, $p),
                Commission::forPilot($t, $v, $p)
            );
        }
    }
}

/* ── الخرائط نفسها لازم تكون متطابقة حرفيًا ─────────────────── */
$maps = [
    'ORDER_STATUS_AR'          => [ORDER_STATUS_AR, Vocab::ORDER_STATUS_AR],
    'ORDER_STATUS_CODE'        => [ORDER_STATUS_CODE, Vocab::ORDER_STATUS_CODE],
    'PARCEL_STATUS_AR'         => [PARCEL_STATUS_AR, Vocab::PARCEL_STATUS_AR],
    'PILOT_STATUS_WIRE'        => [PILOT_STATUS_WIRE, Vocab::PILOT_STATUS_WIRE],
    'PILOT_STATUS_CODE'        => [PILOT_STATUS_CODE, Vocab::PILOT_STATUS_CODE],
    'PILOT_STATUS_AR'          => [PILOT_STATUS_AR, Vocab::PILOT_STATUS_AR],
    'LEAVE_TYPE_WIRE'          => [LEAVE_TYPE_WIRE, Vocab::LEAVE_TYPE_WIRE],
    'LEAVE_TYPE_AR'            => [LEAVE_TYPE_AR, Vocab::LEAVE_TYPE_AR],
    'SHIFT_STATUS_WIRE'        => [SHIFT_STATUS_WIRE, Vocab::SHIFT_STATUS_WIRE],
    'SHIFT_SETTLE_WIRE'        => [SHIFT_SETTLE_WIRE, Vocab::SHIFT_SETTLE_WIRE],
    'REQUEST_STATUS_WIRE'      => [REQUEST_STATUS_WIRE, Vocab::REQUEST_STATUS_WIRE],
    'ORDER_RETURN_STATUS_WIRE' => [ORDER_RETURN_STATUS_WIRE, Vocab::ORDER_RETURN_STATUS_WIRE],
    'COMMISSION_TYPE_WIRE'     => [COMMISSION_TYPE_WIRE, Vocab::COMMISSION_TYPE_WIRE],
    'ORDER_SOURCE_WIRE'        => [ORDER_SOURCE_WIRE, Vocab::ORDER_SOURCE_WIRE],
    'ROLE_AR'                  => [ROLE_AR, Vocab::ROLE_AR],
    'ROLE_AR_ALIASES'          => [ROLE_AR_ALIASES, Vocab::ROLE_AR_ALIASES],
];

foreach ($maps as $name => [$old, $new]) {
    cmp("خريطة {$name}", $name, $old, $new);
}

/* ── النتيجة ────────────────────────────────────────────────── */
echo "\n";
if ($failures) {
    echo "الاختلافات:\n" . implode("\n", $failures) . "\n\n";
}
echo "════════════════════════════════════════════\n";
printf("DIFF VOCAB: %d مطابق / %d مختلف   (إجمالي %d)\n", $pass, $fail, $pass + $fail);
echo "════════════════════════════════════════════\n";

exit($fail > 0 ? 1 : 0);
