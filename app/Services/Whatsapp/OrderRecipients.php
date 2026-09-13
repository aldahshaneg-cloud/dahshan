<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use App\Wire\TrustWire;
use Illuminate\Support\Facades\DB;

/**
 * مين بياخد رسالة، وبأي نص — القرار كله في مكان واحد.
 *
 * ═══════════════════════════════════════════════════════════════════
 * 🔴 القرار: رسالة **لكل مستلم**، مش رسالة واحدة للأوردر
 * ═══════════════════════════════════════════════════════════════════
 * الأوردر الواحد ممكن يبقى فيه أكتر من طرد (`order_deliveries`)، وكل طرد
 * له `receiver_name` و`receiver_phone` و`zone_name` **بتوعه هو**. يعني
 * «الأوردر» مش بيروح لحد — الطرود هي اللي بتروح لناس.
 *
 * تلات أسباب بتخلّي الرسالة الواحدة غلط:
 *
 *  ١. **مافيش رقم واحد تبعت عليه.** لو الأوردر فيه تلات طرود لتلات ناس،
 *     مين اللي هياخد الرسالة؟ أي اختيار منهم بيسيب اتنين من غير خبر.
 *
 *  ٢. **تسريب خصوصية.** رسالة واحدة فيها بيانات التلاتة معناها إن كل واحد
 *     شاف اسم ومنطقة التانيين. النظام كله ماشي على العكس — حتى صفحة
 *     التتبّع العامة بتقنّع الأسماء والأرقام (`PublicWire::trackParcel`).
 *
 *  ٣. **الكود نفسه لكل طرد.** `parcelCodeOf()` في public/store.html بتدّي
 *     كل طرد كوده (`ORD-260819-001-2`)، وصفحة التتبّع بتفهم اللاحقة دي
 *     وبتفلتر على الطرد (`PublicSiteController::track`). كود واحد للأوردر
 *     كان هيلغي التفرقة دي.
 *
 * والأهم: الإرسال اليدوي الموجود دلوقتي **بيعمل كده بالظبط** —
 * `sendCodeWhatsApp(i)` بتتنده لكل طرد لوحده من `window._orderCodes`.
 * الأتمتة هنا بتقلّد السلوك القايم، مابتخترعش سلوك جديد.
 *
 * ═══════════════════════════════════════════════════════════════════
 * التجميع بالرقم — نتيجة مباشرة لقيد منع التكرار
 * ═══════════════════════════════════════════════════════════════════
 * القيد `uq_order_notifications_target(order_id, channel, recipient_phone)`
 * معناه **صف واحد لكل رقم في الأوردر**. فلو طردين في نفس الأوردر رايحين
 * لنفس الرقم، الاتنين بيبقوا رسالة واحدة — وده الصح: بعت رسالتين لنفس
 * الشخص عن نفس الأوردر ده إزعاج مش خدمة.
 *
 * ⚠️ **الأثر الجانبي:** المستلم اللي عنده أكتر من طرد بياخد **رقم الأوردر
 * الأساسي** مش لاحقة طرد واحد — لأن اللاحقة كانت هتخفي عنه طرده التاني.
 * صفحة التتبّع بالرقم الأساسي بتعرض كل الطرود بأرقامها، فمفيش معلومة
 * ضايعة. لو اتقرر بعدين إن كل طرد لازم رسالة مستقلة، ده **مش تغيير هنا** —
 * ده تغيير في القيد الفريد نفسه عشان يضم `parcel_no`.
 *
 * ═══════════════════════════════════════════════════════════════════
 * النص — نسخة حرفية من `_codeMsg()` في public/store.html
 * ═══════════════════════════════════════════════════════════════════
 * سطر بسطر، بما فيه إن سطر «المنطقة» **بيختفي** لما الزون يبقى فاضي
 * (`c.zone ? ... : ""` في الأصل). الرسالة اللي بتتبعت تلقائي لازم تبقى هي
 * هي اللي الموظف بيبعتها بإيده — غير كده المستلم بياخد شكلين مختلفين حسب
 * مين ضغط الزرار.
 */
final class OrderRecipients
{
    /** القناة الوحيدة دلوقتي — بتتخزّن في عمود channel */
    public const CHANNEL = 'whatsapp';

    /* أسباب التخطّي — نص عربي بيتخزّن في عمود error زي ما هو.
       ثابتة مش مبنية في المكان عشان أي شاشة بتفلتر عليها تلاقيها هنا. */
    public const SKIP_NO_PHONE  = 'مفيش رقم للمستلم';
    public const SKIP_BAD_PHONE = 'رقم المستلم مش صالح';
    public const SKIP_CANCELLED = 'الأوردر اتلغى قبل ما الرسالة تتبعت';

    /**
     * بيقرا طرود الأوردر ويرجّع مستلم واحد لكل رقم مختلف.
     *
     * الترتيب بأصغر رقم طرد — عشان صفوف الجدول تطلع بنفس ترتيب الطرود
     * اللي الموظف شايفه في الشاشة.
     *
     * @return Recipient[]
     */
    public static function forOrder(int $orderId, string $orderNum): array
    {
        $rows = DB::select(
            'SELECT parcel_no, receiver_phone, receiver_name, zone_name
               FROM order_deliveries
              WHERE order_id = ?
              ORDER BY parcel_no',
            [$orderId]
        );

        $total = count($rows);
        if ($total === 0) {
            return [];
        }
        /* اسم المُرسِل لسطر «من :» — من صف الأوردر نفسه (نسخته المتجمّدة
           وقت الإنشاء، مش من دفتر العملاء اللي ممكن يكون اتعدّل بعدين). */
        $senderName = trim((string) (DB::selectOne(
            'SELECT sender_name FROM orders WHERE id = ?',
            [$orderId]
        )->sender_name ?? ''));

        /* التجميع: المفتاح هو الرقم المطبّع. الطرد اللي مالوش رقم خالص
           بياخد مفتاح فاضي — وكلهم بيتجمّعوا في صف skipped واحد، وده
           اللي القيد الفريد بيفرضه أصلًا (recipient_phone = ''). */
        $groups = [];
        foreach ($rows as $row) {
            $raw  = (string) ($row->receiver_phone ?? '');
            $norm = TrustWire::normalizePhone($raw);
            $key  = self::phoneKey($raw);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'normalized' => $norm,
                    'raw'        => trim($raw),
                    'parcelNos'  => [],
                    'zones'      => [],
                    'names'      => [],
                ];
            }

            $groups[$key]['parcelNos'][] = (int) $row->parcel_no;

            $zone = trim((string) ($row->zone_name ?? ''));
            if ($zone !== '') {
                $groups[$key]['zones'][$zone] = true;
            }
            $rName = trim((string) ($row->receiver_name ?? ''));
            if ($rName !== '') {
                $groups[$key]['names'][$rName] = true;
            }
        }

        $out = [];
        foreach ($groups as $key => $g) {
            $out[] = self::build($orderId, $orderNum, (string) $key, $g, $total, $senderName);
        }

        return $out;
    }

    /**
     * مفتاح صف الجدول من رقم خام — الرقم المطبّع مقصوصًا على طول العمود
     * `recipient_phone varchar(20)`.
     *
     * 🔴 **عامة عن قصد.** الشاشة الإدارية بتقرا صفوف `order_notifications`
     * وبتحتاج ترجّع كل صف لمستلمه في `order_deliveries` عشان تعرض اسمه —
     * والوصلة الوحيدة بينهم هي المفتاح ده. لو الاشتقاق اتكرر في مكانين،
     * أي تغيير في التطبيع (أو في طول العمود) بيخلّي الشاشة تفشل في
     * المطابقة **بصمت** وتعرض «مستلم غير معروف» لصفوف سليمة.
     * مصدر واحد، والاتنين بينادوه.
     */
    public static function phoneKey(?string $raw): string
    {
        return mb_substr(TrustWire::normalizePhone($raw), 0, 20);
    }

    /**
     * @param  array{normalized:string,raw:string,parcelNos:int[],zones:array<string,bool>,names:array<string,bool>}  $g
     */
    private static function build(
        int $orderId,
        string $orderNum,
        string $key,
        array $g,
        int $total,
        string $senderName = ''
    ): Recipient {
        $code     = self::codeFor($orderNum, $g['parcelNos'], $total);
        $trackUrl = self::trackUrl($code);

        /* الزون بيطلع في الرسالة لو المستلم كله في منطقة واحدة. أكتر من
           منطقة = السطر بيتشال بدل ما نكتب واحدة ونسكت عن التانية —
           و`_codeMsg` أصلًا بتشيل السطر لما الزون يبقى فاضي، فالشكل ده
           معروف للمستلم. */
        $zoneNames = array_keys($g['zones']);
        /* 🔴 الكاست مش زيادة. `$groups[...]['zones'][$zone]` فوق بيستعمل اسم
           المنطقة كمفتاح مصفوفة، وPHP بيحوّل أي مفتاح نصّه رقم صحيح
           («5» · «10» · «0» · «-3») لـint بصمت. ساعتها `array_keys` بترجّع
           int، و`body()` بارامترها `?string` والملف عليه `strict_types` →
           TypeError بيتقفش في `handle()` ويتكتب في اللوج وبس. النتيجة كانت
           هتبقى: **الأوردر كله مايكتبش ولا صف إشعار** — لا رسايل ولا حتى
           صف pending يبان للموظف في الشاشة، فمحدش يعرف إن في حاجة ضاعت.
           ومفيش أي حاجة بتمنع اسم منطقة رقمي: `zonesCreate` بيرفض الفاضي
           بس، و`OrdersController` سطر 418 بياخد `zoneName` من جسم الطلب
           زي ما هو. نفس الكاست معمول في سطر 122 لمفتاح التليفون لنفس السبب. */
        $zone      = count($zoneNames) === 1 ? (string) $zoneNames[0] : null;
        /* اسم المستلم لسطر «الي :» — نفس قاعدة الزون بالظبط: بيطلع لما
           يكون اسم واحد. الرقم الواحد ممكن يشيل أكتر من طرد بأسماء مختلفة
           (المحل بيبعت لنفس الرقم باسمين)، وساعتها بنسكت بدل ما نختار
           واحد ونسكت عن التاني. والكاست لنفس سبب الزون فوق: مفتاح
           المصفوفة اللي نصّه رقم صحيح PHP بيحوّله int بصمت. */
        $nameKeys  = array_keys($g['names'] ?? []);
        $toName    = count($nameKeys) === 1 ? (string) $nameKeys[0] : null;

        $message = new OutgoingMessage(
            orderId:  $orderId,
            orderNum: $orderNum,
            code:     $code,
            phone:    $g['normalized'],
            zone:     $zone,
            trackUrl: $trackUrl,
            body:     self::body($code, $zone, $trackUrl, $senderName, $toName),
        );

        return new Recipient($key, $message, self::skipReason($g));
    }

    /**
     * قواعد المنع — بترجّع السبب أو null لو الرقم تمام.
     *
     * @param  array{normalized:string,raw:string,parcelNos:int[],zones:array<string,bool>}  $g
     */
    private static function skipReason(array $g): ?string
    {
        if ($g['normalized'] === '') {
            return self::SKIP_NO_PHONE;
        }

        /* ⚠️ الفحص هنا **هو نفسه** `CustomerAppController::validPhone()`
           (`/^0?1[0-9]{9}$/`) مطبّق على الرقم بعد التطبيع — والتطبيع بيحط
           الصفر البادئ، فالشكل الوحيد الباقي هو `01` + 9 أرقام.

           وعن قصد **مش** الفحص الأضيق بتاع `PublicSiteController`
           (`/^01[0125]\d{8}$/`): ده بيقبل بادئات الموبايل الأربعة بس، وفي
           الجدول أرقام قديمة اتقبلت من مسار إنشاء الأوردر بالقاعدة الأوسع.
           التضييق هنا كان هيعلّم أرقام شغّالة على إنها «مش صالحة». */
        if (preg_match('/^01\d{9}$/', $g['normalized']) !== 1) {
            return self::SKIP_BAD_PHONE . ': ' . ($g['raw'] !== '' ? $g['raw'] : $g['normalized']);
        }

        return null;
    }

    /**
     * كود التتبّع — نفس `parcelCodeOf()` في public/store.html:
     * أوردر بطرد واحد بياخد رقمه، وأكتر من طرد كل طرد بياخد لاحقته.
     *
     * الاستثناء الوحيد هو المستلم اللي عنده أكتر من طرد: بياخد الرقم
     * الأساسي (شوف شرح التجميع فوق).
     *
     * @param  int[]  $parcelNos
     */
    private static function codeFor(string $orderNum, array $parcelNos, int $total): string
    {
        if ($total <= 1 || count($parcelNos) !== 1) {
            return $orderNum;
        }

        $no = $parcelNos[0];

        return $no > 0 ? $orderNum . '-' . $no : $orderNum;
    }

    /**
     * لينك التتبّع — **مطلق ومثبّت** لأنه بيتطبع في QR على الطرد.
     * نظير `trackUrlOf()` في public/store.html.
     *
     * `rawurlencode` مش `urlencode`: التانية بتحوّل المسافة لـ`+` وده غلط
     * في الـquery بتاعة صفحة التتبّع. وأكواد الأوردرات حروف وأرقام وشرط
     * بس (`ORD-260819-001-2`)، فالناتج مطابق حرفيًا لـ`encodeURIComponent`
     * في الواجهة — الاتنين مابيلمسوش الشرط.
     */
    private static function trackUrl(string $code): string
    {
        $base = (string) config('dahshan.whatsapp.track_base', 'https://aldahshan.cloud/');

        return rtrim($base, '/') . '/?track=' . rawurlencode($code);
    }

    /**
     * النص. الشكل اتغيّر بطلب صاحب النظام (2026-09-10): بدل سطر «المنطقة»
     * بقى فيه **من / إلى** — المستلم بيعرف الشحنة جاية من مين ورايحة لمين،
     * والمنطقة بقت جنب اسم المستلم بدل سطر لوحدها.
     *
     *     📦 شحنتك مع الدهشان
     *     رقم الطلب: GISH-260910-025
     *     من : اسم المُرسِل
     *     الي : اسم المستلم — المنطقة
     *
     *     تابع شحنتك من هنا:
     *     https://aldahshan.cloud/?track=…
     *
     * أي سطر بياناته فاضية بيختفي خالص (زي ما سطر المنطقة كان بيعمل).
     *
     * ⚠️ `\n` مش `PHP_EOL` — الأخيرة بتطلع `\r\n` على ويندوز، وده بيدخل
     * محرف زيادة في نص الرسالة المتخزّن ويخلّيه مختلف عن اللي بيتبعت من
     * المتصفح.
     */
    private static function body(
        string $code,
        ?string $zone,
        string $trackUrl,
        ?string $from = null,
        ?string $to = null
    ): string {
        $from = trim((string) $from);
        $to   = trim((string) $to);
        $zone = trim((string) $zone);
        $toLine = $to !== '' ? $to . ($zone !== '' ? ' — ' . $zone : '') : $zone;

        return "📦 شحنتك مع الدهشان\n"
            . 'رقم الطلب: ' . $code . "\n"
            . ($from !== '' ? 'من : ' . $from . "\n" : '')
            . ($toLine !== '' ? 'الي : ' . $toLine . "\n" : '')
            . "\nتابع شحنتك من هنا:\n"
            . $trackUrl;
    }
}
