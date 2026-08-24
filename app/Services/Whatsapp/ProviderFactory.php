<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

/**
 * نقطة الاختيار الوحيدة بين المزوّدين — `WHATSAPP_PROVIDER` في .env.
 *
 * ═══ ليه factory ساكن مش binding في الـcontainer ═══
 * المزوّد بيتنده من مهمة طابور بتشتغل في عملية `queue:work` عايشة لساعات.
 * الـbinding المفرد (`singleton`) كان هيتبني مرة واحدة عند أول مهمة ويفضل
 * محتفظ بالإعداد القديم بعد `config:cache` وإعادة النشر — يعني تغيير
 * الإعداد مايبانش غير بعد `queue:restart`. القراية من `config()` عند كل
 * نداء بتخلّي التحوّل يبان من غير مفاجآت.
 *
 * وكمان: الاختبار محتاج يبدّل المزوّد من غير ما يلمس الـcontainer، وده
 * مضمون هنا لأن المهمة بتقبل مزوّد صريح في الـconstructor وبتنده الfactory
 * بس لما مايتبعتش حاجة.
 *
 * ═══ الاسم المجهول = يدوي ═══
 * قيمة غلط في .env بترجّع `ManualProvider` مش استثناء. غلطة كتابة في
 * الإعداد لازم تبقى «مافيش إرسال تلقائي» — مش «إنشاء الأوردر بيقع».
 */
final class ProviderFactory
{
    public static function make(?string $key = null): WhatsappProvider
    {
        $key ??= (string) config('dahshan.whatsapp.provider', 'manual');

        return match (strtolower(trim($key))) {
            'cloud_api', 'cloudapi', 'cloud' => CloudApiProvider::fromConfig(),
            default                          => new ManualProvider(),
        };
    }
}
