<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Whatsapp\OutgoingMessage;
use App\Services\Whatsapp\ProviderFactory;
use App\Services\Whatsapp\Recipient;
use App\Support\WireTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 🚨 بيبعت تنبيه عطل على الواتساب لأرقام الإدارة.
 *
 * ═══ ليه مهمة طابور ═══
 * النداء بيجي من **مسار معالجة الأعطال**. الإرسال المباشر معناه إن رد
 * الخطأ للمستخدم بيستنى نداء HTTP لـMeta — يعني عطل بسيط بيبقى طلب بطيء
 * كمان. الطابور بيخلّي الرد فوري والإرسال في العملية التانية.
 *
 * ═══ 🔴 ممنوع ترمي ═══
 * زي `SendOrderWhatsapp` بالظبط: كل حاجة ملفوفة، وأسوأ نتيجة «مافيش
 * تنبيه». تنبيه فاشل ماينفعش يتحوّل لعطل جديد يولّد تنبيه تاني — دي حلقة
 * لا نهائية بتغرق الطابور.
 *
 * ═══ الوضع اليدوي ═══
 * لو `WHATSAPP_PROVIDER` مش متظبط (الوضع الحالي على الإنتاج) المزوّد
 * بيرجّع «مستنية إنسان» ومفيش رسالة بتوصل. الصف في `error_alerts` بيتعلّم
 * بالحالة دي، والتنبيه بيفضل ظاهر في لوحة الإدارة. أول ما الإرسال
 * التلقائي يتفعّل الرسايل بتوصل من غير أي تغيير في الكود.
 */
class SendErrorAlert implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(private readonly string $signature)
    {
        /* 🔴 بتتظبط هنا مش كخاصية `public bool $afterCommit = true;` —
           `Queueable` معرّفاها `public $afterCommit;` من غير نوع، وPHP
           بيعتبر إعادة التعريف بنوع **تعارض غير متوافق** فالكلاس مابيتحمّلش
           أصلًا. نفس المصيدة متوثّقة في SendOrderWhatsapp. */
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        try {
            $row = DB::select('SELECT * FROM error_alerts WHERE signature = ? LIMIT 1', [$this->signature])[0] ?? null;
            if (! $row) {
                return;
            }
            $a = (array) $row;

            $to = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) config('dahshan.alerts.whatsapp_to', ''))
            )));
            if (! $to) {
                DB::update('UPDATE error_alerts SET notify_status = ? WHERE id = ?',
                    ['مفيش رقم متظبط', (int) $a['id']]);

                return;
            }

            $text = $this->compose($a);
            $provider = ProviderFactory::make();
            $status = [];

            foreach ($to as $phone) {
                try {
                    /* OutgoingMessage متبنية أصلًا للأوردرات، فالحقول
                       الخاصة بالأوردر بتتملى بقيم محايدة والنص كله في
                       `body` — ده المسار اللي المزوّد بيبعت بيه النص
                       الحر. مابنعملش نوع رسالة تاني عشان مانكسرش عقد
                       المزوّد اللي شغّال ومختبَر. */
                    $out = $provider->send(new OutgoingMessage(
                        orderId:  0,
                        orderNum: 'ALERT',
                        code:     'ALERT',
                        phone:    $phone,
                        zone:     null,
                        trackUrl: '',
                        body:     $text,
                    ));
                    $status[] = $phone . ':' . $out->status;
                } catch (Throwable $e) {
                    // المزوّد متعاقد إنه مايرميش، بس الحزام والحمّالة
                    $status[] = $phone . ':failed';
                    Log::warning('تنبيه عطل: فشل الإرسال لـ' . $phone . ' — ' . $e->getMessage());
                }
            }

            DB::update(
                'UPDATE error_alerts SET notify_status = ?, notified_at = ? WHERE id = ?',
                [mb_substr($provider->key() . ' · ' . implode(' · ', $status), 0, 190),
                 WireTime::nowDb(), (int) $a['id']]
            );
        } catch (Throwable $e) {
            Log::warning('تنبيه عطل: المهمة نفسها فشلت — ' . $e->getMessage());
        }
    }

    /** نص الرسالة — قصير ومباشر، الموبايل مش شاشة لوج */
    private function compose(array $a): string
    {
        $n = (int) $a['occurrences'];
        $when = $a['last_seen_at'] ?? '';

        $lines = [
            '🚨 عطل في نظام الدهشان',
            '',
            (string) $a['title'],
        ];
        if (! empty($a['url'])) {
            $lines[] = '📍 ' . $a['method'] . ' ' . $a['url'];
        }
        if (! empty($a['actor'])) {
            $lines[] = '👤 ' . $a['actor'];
        }
        $lines[] = '🔁 اتكرر ' . $n . ' مرة';
        $lines[] = '🕐 ' . $when . ' (UTC)';
        $lines[] = '';
        $lines[] = 'التفاصيل في لوحة الإدارة ← الأعطال.';

        return implode("\n", $lines);
    }
}
