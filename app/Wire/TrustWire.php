<?php

declare(strict_types=1);

namespace App\Wire;

use App\Support\Actor;
use App\Support\WireTime;
use Illuminate\Support\Facades\DB;

/**
 * نواة منظومة الثقة — نقل حرفي لـ api/trust.php.
 *
 * المبدأ (من رأس الملف الأصلي): **رقم التليفون بعد التطبيع هو المفتاح الموحّد
 * للشخص عبر النظام كله**. نفس الشخص ممكن يكون له صف في customers وصف في
 * receivers وعشرات الطرود في order_deliveries — والأرقام متخزنة بأشكال مختلفة
 * (+2، 002، بمسافات، بشرط). كل الدوال هنا بتقارن بالشكل الموحّد، ودوال SQL
 * بتقارن بآخر 10 أرقام عشان تلاقي الأشكال القديمة المخزّنة **من غير ما نلمس
 * البيانات**.
 *
 * الكلاس ده **دوال مشتركة بس** — قاعدة الخصوصية بتتطبق في الكنترولر
 * سيرفر-سايد (متعتمدش على الواجهة).
 *
 * ليه هنا في Wire مش Support: ده نظير api/trust.php اللي بيغذّي طبقة السلك
 * (identityInfo/reputation بيطلعوا كما هم في ردود /api/lookup)، فمكانه جنب
 * CoreWire و OrderWire عشان الفحص التفاضلي يفضل ملف-لملف.
 */
final class TrustWire
{
    /* ═══════════════════════════════════════════════════════════════
       1) تطبيع رقم التليفون المصري — المفتاح الموحّد
    ═══════════════════════════════════════════════════════════════ */

    /**
     * تطبيع رقم مصري لشكل واحد ثابت.
     * شيل المسافات والشرط والأقواس و+ و00، وحوّل كود مصر (2 / +2 / 002) لبداية 0.
     *
     * وحدة اختبار (كلهم لازم يطلعوا "01012345678"):
     *   01012345678 | +201012345678 | 0020101 2345678 | 201012345678 | 1012345678
     *   0100-123-4567 → 01001234567
     */
    public static function normalizePhone(?string $p): string
    {
        if ($p === null) {
            return '';
        }

        // 1) سيب الأرقام و+ بس (بيشيل المسافات والشرط والأقواس والحروف العربية)
        $s = preg_replace('/[^0-9+]/', '', $p) ?? '';
        if ($s === '') {
            return '';
        }

        // 2) بادئة دولية: + أو 00
        if ($s[0] === '+') {
            $s = substr($s, 1);
        }
        if (str_starts_with($s, '00')) {
            $s = substr($s, 2);
        }
        $s = preg_replace('/[^0-9]/', '', $s) ?? '';   // أي + جوه الرقم مالهوش معنى
        if ($s === '') {
            return '';
        }

        // 3) كود مصر 20 في الأول (بعد ما شلنا + / 00) — كل الأرقام المحلية بتبدأ بصفر
        if (str_starts_with($s, '20') && strlen($s) > 10) {
            $rest = substr($s, 2);
            $s = str_starts_with($rest, '0') ? $rest : ('0' . $rest);
        }

        // 4) موبايل مكتوب من غير الصفر: 1012345678 → 01012345678
        if (strlen($s) === 10 && $s[0] === '1') {
            $s = '0' . $s;
        }

        return $s;
    }

    /** آخر 10 أرقام — مفتاح المطابقة مع الأشكال القديمة المخزّنة في القاعدة */
    public static function phoneKey(string $normalized): string
    {
        return strlen($normalized) > 10 ? substr($normalized, -10) : $normalized;
    }

    /**
     * تعبير SQL بيطلّع مفتاح المطابقة من عمود تليفون مهما كان شكله المخزّن.
     * (بيشيل الفواصل الشائعة وبياخد آخر 10 أرقام — فبيلاقي +2 و002 و0 بنفس الاستعلام)
     * ملحوظة أداء: تعبير مش مفهرس — الاستعلامات هنا مقيّدة بـ LIMIT ومعقولة الحجم.
     */
    public static function phoneSql(string $col): string
    {
        return 'RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE('
             . $col . ",' ',''),'-',''),'+',''),'(',''),')',''),'.',''), 10)";
    }

    /** تقنيع الاسم: أول حرف + نجوم — «أحمد فوزي» → «أ***» */
    public static function maskName(?string $name): string
    {
        $n = trim((string) $name);
        if ($n === '') {
            return 'غير معروف';
        }

        return mb_substr($n, 0, 1, 'UTF-8') . '***';
    }

    /**
     * تقنيع التليفون: آخر 4 أرقام بس — «01012345678» → «•••••••5678»
     * ⚠️ العدّ بالبايت (strlen) زي الأصل بالحرف — الرقم المطبَّع أرقام لاتينية
     * فالبايت = الحرف، وتغييرها لـ mb_strlen ممكن يغيّر عدد النقط في حالة شاذة.
     */
    public static function maskPhone(?string $p): string
    {
        $s = trim((string) $p);
        if ($s === '') {
            return '';
        }
        if (strlen($s) <= 4) {
            return str_repeat('•', strlen($s));
        }

        return str_repeat('•', strlen($s) - 4) . substr($s, -4);
    }

    /* ═══════════════════════════════════════════════════════════════
       2) محرك السمعة — الحساب التلقائي + اليدوي
    ═══════════════════════════════════════════════════════════════ */

    /**
     * درجة المصداقية للرقم.
     *
     * المعادلة (حرفيًا زي المواصفة):
     *   نسبة النجاح  = delivered / (delivered + undelivered)
     *   successStars = نسبة النجاح × 5
     *   manualAvg    = متوسط نجوم party_ratings المسجّلة على الرقم
     *   score        = فيه يدوي ؟ (0.6 × successStars) + (0.4 × manualAvg) : successStars
     *   مفيش تاريخ خالص → score = null («عميل جديد» — مش صفر)
     *
     * حالة حدّية موثّقة: لو فيه تقييمات يدوية بس مفيش شحنات منتهية خالص،
     * successStars = null فالمعادلة المركّبة مالهاش معنى → score = manualAvg
     * (فيه تاريخ فعلًا، فمش «جديد»).
     *
     * ⚠️ الرقم اللي بيدخل المعادلة وبيدخل level() هو **غير المقرّب**، بس اللي
     * بيطلع على السلك مقرّب لخانتين. يعني درجة 4.499 بتطلع 4.5 ومستواها «جيد»
     * مش «ممتاز». ده سلوك الأصل بالحرف — ممنوع نوحّدهم.
     *
     * @return array{delivered:int,undelivered:int,totalOrders:int,successStars:?float,
     *               manualAvg:?float,manualCount:int,score:?float,level:string,isNew:bool}
     */
    public static function reputation(string $phone): array
    {
        $key  = self::phoneKey($phone);
        $expr = self::phoneSql('d.receiver_phone');

        // الشحنات الفعلية — حالة الطرد هي المرجع، وحالة الأوردر شبكة أمان
        // (الأوردر الملغي مش شحنة حصلت — بيتشال من العدّ كله)
        $row = self::one(
            "SELECT
                SUM(CASE WHEN d.status = 'delivered'
                          OR (d.status NOT IN ('delivered','undelivered') AND o.status = 'delivered')
                         THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN d.status = 'undelivered'
                          OR (d.status NOT IN ('delivered','undelivered') AND o.status = 'undelivered')
                         THEN 1 ELSE 0 END) AS undelivered,
                COUNT(*) AS total
             FROM order_deliveries d
             JOIN orders o ON o.id = d.order_id
             WHERE o.status <> 'cancelled' AND {$expr} = ?",
            [$key]
        ) ?? [];

        $delivered   = (int) ($row['delivered'] ?? 0);
        $undelivered = (int) ($row['undelivered'] ?? 0);
        $totalOrders = (int) ($row['total'] ?? 0);

        $finished = $delivered + $undelivered;
        $successStars = $finished > 0 ? ($delivered / $finished) * 5.0 : null;

        // الجزء اليدوي — subject_phone متخزن مطبَّع دايمًا (مفهرس)
        $m = self::one('SELECT COUNT(*) AS c, AVG(stars) AS a FROM party_ratings WHERE subject_phone = ?', [$phone]) ?? [];
        $manualCount = (int) ($m['c'] ?? 0);
        $manualAvg   = $manualCount > 0 ? (float) $m['a'] : null;

        if ($manualCount > 0 && $successStars !== null) {
            $score = (0.6 * $successStars) + (0.4 * $manualAvg);
        } elseif ($manualCount > 0) {
            $score = $manualAvg;                 // تقييمات بلا شحنات منتهية
        } elseif ($successStars !== null) {
            $score = $successStars;              // شحنات بلا تقييم يدوي
        } else {
            $score = null;                       // مفيش تاريخ خالص
        }

        return [
            'delivered'    => $delivered,
            'undelivered'  => $undelivered,
            'totalOrders'  => $totalOrders,
            'successStars' => $successStars !== null ? round($successStars, 2) : null,
            'manualAvg'    => $manualAvg !== null ? round($manualAvg, 2) : null,
            'manualCount'  => $manualCount,
            'score'        => $score !== null ? round($score, 2) : null,
            'level'        => self::level($score),
            'isNew'        => $score === null,
        ];
    }

    /** مستوى العرض حسب المواصفة — null = «جديد» (مش سيّئ) */
    public static function level(?float $score): string
    {
        if ($score === null) {
            return 'جديد';
        }
        if ($score >= 4.5) {
            return 'ممتاز';
        }
        if ($score >= 3.5) {
            return 'جيد';
        }
        if ($score >= 2.5) {
            return 'متوسط';
        }

        return 'محتاج حذر';
    }

    /* ═══════════════════════════════════════════════════════════════
       3) الاسم المعتمد — قاعدة الحسم
    ═══════════════════════════════════════════════════════════════ */

    /**
     * الاسم الرسمي المعتمد للرقم، بالأولوية دي:
     *   0) تصحيح الموظف اليدوي في party_identities (لو موجود — بيغلب كل حاجة)
     *   أ) اسم العميل المسجّل في customers (هو صاحب هويته)
     *   ب) الاسم الأكتر تكرارًا في الأوردرات **اللي اتسلّمت فعلًا**
     *   ج) آخر اسم مكتوب على أي طرد/صف مستلم
     * بيرجع null لو مفيش أي اسم.
     */
    public static function canonicalName(string $phone): ?string
    {
        return self::identityInfo($phone)['name'];
    }

    /**
     * كل معلومات الهوية في استعلامات مجمّعة (الاسم + العنوان + المصدر + آخر أوردر).
     *
     * @return array{name:?string,address:?string,source:string,verified:bool,
     *               customerId:?int,userId:?int,lastOrderAt:?string,found:bool,
     *               verifiedBy:?string,verifiedAt:?string}
     */
    public static function identityInfo(string $phone): array
    {
        $key   = self::phoneKey($phone);
        $found = false;

        $out = [
            'name' => null, 'address' => null, 'source' => 'none', 'verified' => false,
            'customerId' => null, 'userId' => null, 'lastOrderAt' => null, 'found' => false,
            'verifiedBy' => null, 'verifiedAt' => null,
        ];

        // 0) التصحيح اليدوي المعتمد
        $ident = self::one('SELECT * FROM party_identities WHERE subject_phone = ?', [$phone]);

        // أ) عميل مسجّل في التطبيق (صاحب هويته)
        $expr1 = self::phoneSql('phone1');
        $expr2 = self::phoneSql('phone2');
        $cust = self::one(
            "SELECT id, display_name, address FROM customers
             WHERE {$expr1} = ? OR ({$expr2} IS NOT NULL AND phone2 <> '' AND {$expr2} = ?)
             ORDER BY id LIMIT 1",
            [$key, $key]
        );
        if ($cust) {
            $found = true;
            $out['customerId'] = (int) $cust['id'];
        }

        // حساب محل مربوط بنفس الرقم (المُقيَّم ممكن يكون محل)
        $exprShop = self::phoneSql('shop_phone');
        $shop = self::one(
            "SELECT id, shop_name, shop_address FROM users WHERE role = 'store' AND {$exprShop} = ? ORDER BY id LIMIT 1",
            [$key]
        );
        if ($shop) {
            $found = true;
            $out['userId'] = (int) $shop['id'];
        }

        // ب) الاسم الأكتر تكرارًا في الطرود المتسلّمة فعلًا + (ج) آخر اسم مكتوب
        $exprD = self::phoneSql('d.receiver_phone');
        $topDelivered = self::one(
            "SELECT d.receiver_name AS nm, COUNT(*) AS c
             FROM order_deliveries d JOIN orders o ON o.id = d.order_id
             WHERE {$exprD} = ? AND d.receiver_name IS NOT NULL AND d.receiver_name <> ''
               AND (d.status = 'delivered' OR o.status = 'delivered')
             GROUP BY d.receiver_name ORDER BY c DESC, nm ASC LIMIT 1",
            [$key]
        );

        $lastDelivery = self::one(
            "SELECT d.receiver_name AS nm, d.address AS addr, o.created_at AS at
             FROM order_deliveries d JOIN orders o ON o.id = d.order_id
             WHERE {$exprD} = ?
             ORDER BY o.created_at DESC, d.id DESC LIMIT 1",
            [$key]
        );
        if ($lastDelivery) {
            $found = true;
            $out['lastOrderAt'] = WireTime::toWire($lastDelivery['at']);
        }

        // صف دفتر المستلمين (آخر اسم مكتوب لو مفيش أوردرات)
        $recv = self::one(
            "SELECT name, address FROM receivers
             WHERE {$expr1} = ? OR ({$expr2} IS NOT NULL AND phone2 <> '' AND {$expr2} = ?)
             ORDER BY id DESC LIMIT 1",
            [$key, $key]
        );
        if ($recv) {
            $found = true;
        }

        // ترتيب الحسم
        if ($ident && trim((string) $ident['canonical_name']) !== '') {
            $out['name']       = $ident['canonical_name'];
            $out['source']     = 'verified';
            $out['verified']   = $ident['verified_at'] !== null;
            $out['verifiedBy'] = $ident['verified_by'];
            $out['verifiedAt'] = WireTime::toWire($ident['verified_at']);
            $found = true;
        } elseif ($cust && trim((string) $cust['display_name']) !== '') {
            $out['name']   = $cust['display_name'];
            $out['source'] = 'customer';
        } elseif ($topDelivered && trim((string) $topDelivered['nm']) !== '') {
            $out['name']   = $topDelivered['nm'];
            $out['source'] = 'delivered_orders';
        } elseif ($lastDelivery && trim((string) $lastDelivery['nm']) !== '') {
            $out['name']   = $lastDelivery['nm'];
            $out['source'] = 'last_order';
        } elseif ($recv && trim((string) $recv['name']) !== '') {
            $out['name']   = $recv['name'];
            $out['source'] = 'receiver_book';
        } elseif ($shop && trim((string) $shop['shop_name']) !== '') {
            $out['name']   = $shop['shop_name'];
            $out['source'] = 'store';
        }

        // العنوان — نفس ترتيب الحسم
        if ($ident && trim((string) $ident['canonical_address']) !== '') {
            $out['address'] = $ident['canonical_address'];
        } elseif ($cust && trim((string) $cust['address']) !== '') {
            $out['address'] = $cust['address'];
        } elseif ($lastDelivery && trim((string) ($lastDelivery['addr'] ?? '')) !== '') {
            $out['address'] = $lastDelivery['addr'];
        } elseif ($recv && trim((string) ($recv['address'] ?? '')) !== '') {
            $out['address'] = $recv['address'];
        } elseif ($shop && trim((string) ($shop['shop_address'] ?? '')) !== '') {
            $out['address'] = $shop['shop_address'];
        }

        $out['found'] = $found || $out['name'] !== null;

        return $out;
    }

    /* ═══════════════════════════════════════════════════════════════
       4) هل الطرف الطالب اتعامل مع الرقم ده قبل كده؟
    ═══════════════════════════════════════════════════════════════ */

    /**
     * 🔒 أساس قاعدة الخصوصية: الاسم الكامل والعنوان يظهروا بس لو فيه تعامل سابق.
     * الموظف (admin/branch/callcenter) بياخد صلاحية كاملة بحكم دوره.
     */
    public static function hasDealtWith(string $phone, Actor $actor): bool
    {
        // نفس تعريف in_array($role, ['admin','branch','callcenter'], true) بالحرف
        if ($actor->isStaff()) {
            return true;
        }

        $key  = self::phoneKey($phone);
        $expr = self::phoneSql('d.receiver_phone');

        // عميل التطبيق: بعت للرقم ده قبل كده
        if ($actor->role === 'customer' && ! empty($actor->customerId)) {
            return (bool) DB::select(
                "SELECT 1 FROM order_deliveries d JOIN orders o ON o.id = d.order_id
                 WHERE o.customer_id = ? AND {$expr} = ? LIMIT 1",
                [(int) $actor->customerId, $key]
            );
        }

        // محل/مستخدم: أوردر من إنشائه هو فيه طرد للرقم ده
        $username = $actor->username;
        if ($username === '') {
            return false;
        }

        return (bool) DB::select(
            "SELECT 1 FROM order_deliveries d JOIN orders o ON o.id = d.order_id
             WHERE o.added_by = ? AND {$expr} = ? LIMIT 1",
            [$username, $key]
        );
    }

    /** اسم الباحث في السجل — الموظف باسم المستخدم، وعميل التطبيق بـ customer:{id} */
    public static function actorName(Actor $actor): string
    {
        $u = trim($actor->username);
        if ($u !== '') {
            return $u;
        }
        if (! empty($actor->customerId)) {
            return 'customer:' . (int) $actor->customerId;
        }

        return 'unknown';
    }

    /* ═══════════════════════════════════════════════════════════════
       5) طبقة السلك — صفوف القاعدة → لسان الواجهات
    ═══════════════════════════════════════════════════════════════ */

    /** صف تقييم → لسان الواجهات (orderNum جاي من الـLEFT JOIN على orders) */
    public static function rating(array|object $row): array
    {
        $r = (array) $row;

        return [
            'id'         => (int) $r['id'],
            'phone'      => $r['subject_phone'],
            'orderId'    => $r['order_id'] !== null ? (int) $r['order_id'] : null,
            'orderNum'   => $r['order_num'] ?? null,
            'raterType'  => $r['rater_type'],
            'raterName'  => $r['rater_name'],
            'stars'      => (int) $r['stars'],
            'note'       => $r['note'],
            'createdAt'  => WireTime::toWire($r['created_at']),
        ];
    }

    /** صف سجل البحث → لسان لوحة المراقبة */
    public static function lookupLogEntry(array|object $row): array
    {
        $r = (array) $row;

        return [
            'id'            => (int) $r['id'],
            'actorType'     => $r['actor_type'],
            'actorName'     => $r['actor_name'],
            'searchedPhone' => $r['searched_phone'],
            'found'         => (bool) $r['found'],
            'fullAccess'    => (bool) $r['full_access'],
            'createdAt'     => WireTime::toWire($r['created_at']),
        ];
    }

    /**
     * صف واحد كمصفوفة — المقابل لـ $st->fetch() تحت PDO::FETCH_ASSOC.
     * بيرجع null لو مفيش صف (زي false في الأصل) عشان `if ($row)` تفضل تشتغل.
     */
    private static function one(string $sql, array $bindings): ?array
    {
        $row = DB::select($sql, $bindings)[0] ?? null;

        return $row !== null ? (array) $row : null;
    }
}
