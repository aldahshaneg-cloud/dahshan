<?php

declare(strict_types=1);

namespace App\Wire;

use App\Support\WireTime;

/**
 * طبقة السلك لرسايل «اتصل بنا» الجاية من فورم الموقع التسويقي.
 *
 * ⚠️ الحالة هنا **إنجليزية على السلك** زي SupportWire بالظبط — بترجع
 * `pending` / `read` / `archived` زي ما هي في العمود، من غير أي مرور على
 * Vocab. القاموس بتاع Vocab بيترجم حالات **الأوردرات**، والقوايم الإدارية
 * في النظام بتقارن الحالة نصًا (`m.status === 'pending'`). أي ترجمة هنا =
 * فلترة اللوحة بتوقف بالصمت.
 *
 * قواعد ملزمة (نفس قواعد CoreWire/SupportWire):
 *  • كل مفتاح موجود دايمًا حتى لو القيمة null — الواجهة مابتفحصش الوجود.
 *  • الأسماء camelCase على السلك مش أسماء الأعمدة: `ip` مش `ip_address`،
 *    و`readBy` مش `read_by`.
 *  • التواريخ كلها بتعدّي على WireTime::toWire — ISO بـZ زي باقي النظام.
 *
 * 🔒 **الدالة دي بترجّع الرسالة كاملة بلا أي تقنيع** — بعكس PublicWire.
 * ده مقصود: هي بتتنده من مسار admin بس (`GET /api/contact-messages`)،
 * والأدمن محتاج الاسم والتليفون والإيميل عشان يرد على صاحب الرسالة.
 * لو المسار ده اتفتح لأي دور أوسع من admin يبقى لازم تتعمل نسخة مقنّعة —
 * الحد الأمني هنا على المسار مش على السلك.
 */
final class ContactWire
{
    /** بيقبل صف كـarray أو stdClass ويرجّع مصفوفة — DB::select بيرجع stdClass */
    public static function row(array|object $row): array
    {
        return is_array($row) ? $row : (array) $row;
    }

    /* ── رسالة تواصل واحدة ──────────────────────────────────────
       الجدول بيتقرا بـ SELECT * والدالة بتاخد منه المفاتيح دي بالترتيب ده.
       مفيش عمود في الجدول متسايب بره السلك — كله بيطلع للأدمن. */
    public static function message(array|object $row): array
    {
        $r = self::row($row);

        return [
            'id'      => (int) $r['id'],
            'name'    => $r['name'],
            'phone'   => $r['phone'],
            'email'   => $r['email'],
            'subject' => $r['subject'],
            'message' => $r['message'],
            // 🔒 الـIP بيطلع للأدمن عشان يقدر يتتبّع السبام — المسار admin بس
            'ip'      => $r['ip_address'],
            'status'  => $r['status'],          // pending / read / archived — إنجليزي
            'readBy'  => $r['read_by'],
            'readAt'  => WireTime::toWire($r['read_at']),
            'createdAt' => WireTime::toWire($r['created_at']),
        ];
    }
}
