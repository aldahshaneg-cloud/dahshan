<?php

declare(strict_types=1);

namespace App\Services\Push;

use GuzzleHttp\Client;
use Minishlink\WebPush\WebPush;

/**
 * بناء عميل Web Push من الإعداد — النقطة الوحيدة اللي بتقرا `dahshan.push`.
 *
 * ═══ ليه factory ساكن بيقرا config() عند كل نداء ═══
 * نفس سبب `Whatsapp\ProviderFactory`: المهمة بتتنده من عملية `queue:work`
 * عايشة لساعات، وsingleton في الحاوية كان هيتبني مرة بأول مهمة ويفضل ماسك
 * مفاتيح قديمة بعد تغيير الإعداد وإعادة النشر — التحوّل مايبانش غير بعد
 * `queue:restart`. القراية عند كل نداء بتخلّيه يبان من غير مفاجآت.
 *
 * ═══ التبديل في الاختبار ═══
 * لو فيه binding لـ`WebPush::class` في الحاوية بنرجّعه زي ما هو — الفحص
 * (tests/customer_push.php) بيحط عميل وهمي بـ`app()->instance(WebPush::class, ...)`
 * ويشيله بعدها. الكود الحقيقي مابيسجّل أي binding، فالشرط ده مابيتحققش
 * أبدًا في التشغيل الطبيعي.
 *
 * ═══ عميل HTTP صريح مش اكتشاف تلقائي ═══
 * المكتبة (v11) بتدوّر على أي عميل PSR-18 متركّب لو مابعتناش واحد. بنبعت
 * Guzzle صراحةً عشان المهلة تتظبط من الإعداد — من غيرها نداء خدمة دفع
 * واقعة كان هيعلّق العامل لحد المهلة الافتراضية للنظام.
 */
final class WebPushFactory
{
    /** المفتاحين موجودين = الميزة شغّالة. أي واحد فاضي = متعطّلة بالكامل. */
    public static function configured(): bool
    {
        return (string) config('dahshan.push.public_key', '') !== ''
            && (string) config('dahshan.push.private_key', '') !== '';
    }

    /**
     * ⚠️ اندهها بعد `configured()` — بمفاتيح فاضية المكتبة بترمي ErrorException
     * من `VAPID::validate`. المهمة بتمسكه وبتسجّله، بس ده نداء ضايع.
     */
    public static function make(): WebPush
    {
        if (app()->bound(WebPush::class)) {
            return app(WebPush::class);
        }

        $cfg = (array) config('dahshan.push', []);

        return new WebPush(
            [
                'VAPID' => [
                    'subject'    => (string) ($cfg['subject'] ?? 'mailto:info@aldahshan.cloud'),
                    'publicKey'  => (string) ($cfg['public_key'] ?? ''),
                    'privateKey' => (string) ($cfg['private_key'] ?? ''),
                ],
            ],
            [
                'TTL'     => (int) ($cfg['ttl'] ?? 21600),
                'urgency' => (string) ($cfg['urgency'] ?? 'high'),
            ],
            new Client(['timeout' => (int) ($cfg['timeout'] ?? 15)]),
        );
    }
}
