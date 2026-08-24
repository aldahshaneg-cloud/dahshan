<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * الإرسال الحقيقي عن طريق WhatsApp Cloud API بتاعة Meta.
 *
 * 🔴 **مكتوب كامل ومش مفعّل.** `config('dahshan.whatsapp.provider')` لسه
 * `manual`، و`token`/`phone_id` فاضيين. الكلاس ده موجود عشان يوم ما الحساب
 * يجهز يبقى التشغيل **سطرين في .env** مش مرحلة تطوير جديدة.
 *
 * ═══ الفشل هنا بيرجع، مابيرميش ═══
 * كل اللي تحت ملفوف في try/catch واحد بيرجّع `SendOutcome::failed()`.
 * ده عقد الواجهة (`WhatsappProvider`) مش دفاع زيادة: النداء بيحصل جوه مهمة
 * طابور، والاستثناء اللي بيطلع من هنا كان هيمنع كتابة سبب الفشل في الجدول.
 *
 * ═══ نافذة الـ24 ساعة — أهم حاجة تتعرف قبل التفعيل ═══
 * Meta بتسمح بالنص الحر (`type: text`) للعميل اللي **راسلك** في آخر 24 ساعة
 * بس. والمستلم عندنا **عمره ما راسلنا** — هو مستلم شحنة مش عميل بيكلّمنا.
 * يعني:
 *
 *   • من غير `WHATSAPP_TEMPLATE` = الرسايل هتترفض بـ131047 لكل مستلم جديد.
 *   • التشغيل الحقيقي **لازم** قالب معتمد من Meta (Utility category)،
 *     وموافقة القالب دي بتاخد أيام وبتتعمل من لوحة Meta مش من الكود.
 *
 * مسار النص الحر متساب لأنه بيشتغل فعلًا في حالتين حقيقيتين: الاختبار على
 * رقم دخل نافذة الـ24 ساعة، والرد على مستلم بادر بالتواصل. تشيله يبقى
 * الاختبار مايبقاش ممكن غير بقالب معتمد.
 *
 * ═══ ترتيب متغيّرات القالب ═══
 * `{{1}}=code` · `{{2}}=zone` · `{{3}}=trackUrl` — بالترتيب ده بالحرف،
 * وهو نفس ترتيب سطور الرسالة اليدوية. القالب المعتمد عند Meta لازم يتكتب
 * بنفس الترتيب، لأن Meta بتطابق بالموضع مش بالاسم.
 *
 * ⚠️ متغيّر القالب **ممنوع يبقى فيه سطر جديد ولا tab ولا 4 مسافات
 * متتالية** — Meta بترفض الرسالة كلها بـ132000. القيم التلاتة سطر واحد
 * كل واحدة أصلًا، بس الزون بيتنضّف تحت للأمان لأنه بيتخزّن من إدخال بشري.
 */
final class CloudApiProvider implements WhatsappProvider
{
    public function __construct(
        private readonly string $token,
        private readonly string $phoneId,
        private readonly string $template,
        private readonly string $templateLang,
        private readonly string $graphVersion,
        private readonly int $timeout,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('dahshan.whatsapp.token', ''),
            (string) config('dahshan.whatsapp.phone_id', ''),
            (string) config('dahshan.whatsapp.template', ''),
            (string) config('dahshan.whatsapp.template_lang', 'ar'),
            (string) config('dahshan.whatsapp.graph_version', 'v21.0'),
            (int) config('dahshan.whatsapp.timeout', 15),
        );
    }

    public function key(): string
    {
        return 'cloud_api';
    }

    public function send(OutgoingMessage $message): SendOutcome
    {
        /* الحارس ده هو اللي بيخلّي «مكتوب ومش مفعّل» حالة آمنة: لو حد غيّر
           WHATSAPP_PROVIDER من غير ما يملّي البيانات، الصف بيتسجّل failed
           بسبب مفهوم بدل ما ينضرب نداء HTTP فاضي على Meta. */
        if ($this->token === '' || $this->phoneId === '') {
            return SendOutcome::notAttempted(
                'إعداد واتساب ناقص — WHATSAPP_TOKEN أو WHATSAPP_PHONE_ID فاضي'
            );
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->graphVersion,
            $this->phoneId
        );

        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout)
                ->post($url, $this->payload($message));
        } catch (Throwable $e) {
            /* شبكة واقعة أو مهلة خلصت. الاستثناء **مابيطلعش** من هنا —
               بيتحوّل لسبب مكتوب في عمود error. ⚠️ رسالة الاستثناء بس،
               من غير trace ومن غير أي إشارة للـtoken. */
            return SendOutcome::failed('تعذّر الوصول لـMeta: ' . $e->getMessage());
        }

        if ($response->failed()) {
            return SendOutcome::failed($this->readError($response->json(), $response->status()));
        }

        /* الرد الناجح: {"messages":[{"id":"wamid...."}]}
           المعرّف ده هو اللي تقارير التسليم (webhooks) بترجع بيه بعدين،
           فهو اللي بيتخزّن في provider_message_id. */
        $wamid = $response->json('messages.0.id');

        return SendOutcome::sent(is_string($wamid) && $wamid !== '' ? $wamid : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(OutgoingMessage $m): array
    {
        $base = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $m->waPhone(),
        ];

        if ($this->template === '') {
            /* النص الحر: نفس `body` المتخزّن في الجدول بالحرف — اللي في
               الجدول لازم يفضل هو اللي وصل. `preview_url` عشان لينك
               التتبّع يطلع ببطاقة معاينة زي ما بيحصل في الإرسال اليدوي. */
            return $base + [
                'type' => 'text',
                'text' => ['preview_url' => true, 'body' => $m->body],
            ];
        }

        return $base + [
            'type'     => 'template',
            'template' => [
                'name'       => $this->template,
                'language'   => ['code' => $this->templateLang],
                'components' => [[
                    'type'       => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => self::param($m->code)],
                        ['type' => 'text', 'text' => self::param($m->zone ?? '—')],
                        ['type' => 'text', 'text' => self::param($m->trackUrl)],
                    ],
                ]],
            ],
        ];
    }

    /**
     * تنضيف متغيّر القالب: أي مسافة بيضا (سطر/tab/مسافات متتالية) بتبقى
     * مسافة واحدة. Meta بترفض **الرسالة كلها** لو المتغيّر فيه أي من دول،
     * والزون جاي من إدخال بشري فممكن يكون فيه مسافات زيادة.
     * الفاضي بيبقى «—» لأن Meta بترفض المتغيّر الفاضي كمان.
     */
    private static function param(string $v): string
    {
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? $v);

        return $v !== '' ? $v : '—';
    }

    /**
     * بيطلّع سبب مفهوم من رد الخطأ.
     * شكل خطأ Meta: {"error":{"message":"...","code":131047,"error_subcode":..}}
     *
     * الكود الرقمي بيتحط في الأول عن قصد: هو اللي بيتبحث عنه في توثيق Meta،
     * ورسالة النص بتتغيّر عندهم من غير إشعار.
     */
    private function readError(mixed $json, int $status): string
    {
        $err = is_array($json) && isset($json['error']) && is_array($json['error'])
            ? $json['error'] : null;

        if ($err === null) {
            return 'Meta رجّعت HTTP ' . $status . ' من غير تفاصيل';
        }

        $code = isset($err['code']) ? (string) $err['code'] : (string) $status;
        $msg  = isset($err['message']) && is_string($err['message']) ? $err['message'] : 'بلا رسالة';
        $sub  = isset($err['error_subcode']) ? ' / ' . (string) $err['error_subcode'] : '';

        return 'Meta ' . $code . $sub . ': ' . $msg;
    }
}
