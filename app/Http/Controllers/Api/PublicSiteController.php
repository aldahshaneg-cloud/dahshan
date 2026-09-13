<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Support\ApiResponse;
use App\Support\Vocab;
use App\Support\WireTime;
use App\Wire\PublicWire;
use App\Wire\TrustWire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * الموقع العام (index.html) — نقل حرفي لمسارات القراءة من api/routes/public_site.php.
 *
 * 🔒 **المسارات دي عامة بالكامل — مفيش require_auth ومفيش middleware أدوار.**
 * ده مقصود في الأصل: صفحة تتبّع الشحنة بيفتحها المستلم من لينك SMS من غير
 * حساب، وصفحة التغطية بيقراها زوّار الموقع التسويقي. فممنوع نضيف
 * `actorOrFail()` هنا حتى لو باين إنه تحسين أمني — ده كان هيكسّر الموقع.
 *
 * الحماية بدل الدخول هي **التقنيع + قصّ الأعمدة** (شوف App\Wire\PublicWire):
 * الرد بيرجّع الحالة والتايم-لاين والمنطقة بس، من غير أي مبالغ ولا بيانات
 * تواصل كاملة.
 *
 * الاستعلامات مكتوبة خام زي الأصل — الأعمدة المختارة هنا جزء من حد الخصوصية
 * نفسه، مش تفصيلة أداء. (`SELECT o.*` في استعلام الأوردر بيسحب الأعمدة كلها
 * بما فيها المبالغ، بس **مافيش منها حاجة بتوصل السلك** — الرد مبني مفتاح
 * بمفتاح تحت. سيبناه `o.*` زي الأصل عشان صفر تغيير سلوك.)
 */
class PublicSiteController
{
    /**
     * أقصى عدد رسايل تواصل لنفس رقم الموبايل في الساعة — الحد الوحيد على
     * فورم «اتصل بنا» المفتوح. (شوف `contactMessage()` تحت.)
     */
    public const CONTACT_HOURLY_LIMIT = 3;

    /* ═══════════════════════════════════════════════════════════
       تتبّع شحنة بالرقم — عام
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/track/{orderNum}
     *
     * بيقبل رقم الأوردر `{code}-{YYMMDD}-{NNN}` أو رقم طرد بعينه
     * `{code}-{YYMMDD}-{NNN}-{parcelNo}` — ولو اتبعت رقم طرد بيترجّع الطرد ده بس.
     *
     * ⚠️ الشحنة اللي مش موجودة بترجع **200 مع `found:false`** مش 404. ده
     * مقصود: صفحة التتبّع بتعرض «مالقيناش الشحنة دي» بدل صفحة خطأ، والفرق
     * ده بيمنع كمان إن الرد يبقى أداة عدّ أرقام (نفس الكود لكل حالة).
     */
    public function track(string $orderNum): JsonResponse
    {
        $orderNum = trim($orderNum);
        if ($orderNum === '') {
            throw new ApiException('اكتب رقم الشحنة');
        }

        /* ملاحظة نقل: في الأصل هنا بلوك `if` **فاضي بالكامل** فوق ده —
           شرطه بيحسب preg_match مرتين وجسمه تعليق بس، فمالوش أي أثر على
           الرد. اتساب في notes ومااتنقلش لأنه صفر سلوك. */

        // لاحقة الطرد: MNS-260810-001-2 → نفصل رقم الطرد لو موجود
        $baseNum = $orderNum;
        $parcelNo = null;
        if (preg_match('/^([A-Za-z]+-\d{6}-\d+)-(\d+)$/', $orderNum, $m)) {
            $baseNum = $m[1];
            $parcelNo = (int) $m[2];
        }

        $row = DB::select(
            'SELECT o.*, b.name AS _branch_name
               FROM orders o JOIN branches b ON b.id = o.branch_id
              WHERE o.order_num = ? LIMIT 1',
            [$baseNum]
        )[0] ?? null;

        if (! $row) {
            // مفتاحين بس — مفيش orderNum في الرد عشان مايتأكّدش صيغة الرقم
            return ApiResponse::out(['ok' => true, 'found' => false]);
        }
        $o = (array) $row;

        /* التايم-لاين المشتق من التوقيتات (زي تطبيق العميل — بدون مبالغ).
           الترتيب هنا **ثابت في الكود** مش مفروز بالوقت: بيتبني بترتيب مراحل
           الشحنة الطبيعي، والمرحلة اللي توقيتها فاضي بتتشال خالص. */
        $timeline = [];
        $add = function (?string $at, string $label) use (&$timeline): void {
            if ($at) {
                $timeline[] = ['at' => WireTime::toWire($at), 'label' => $label];
            }
        };
        $add($o['created_at'], 'اتسجّلت الشحنة');
        $add($o['received_at'], 'استلمها الفرع');
        $add($o['trip_started_at'], 'الطيار في الطريق');
        $add($o['delivered_at'], 'اتسلّمت للمستلم');
        $add($o['undelivered_at'], 'تعذّر التسليم');
        $add($o['cancelled_at'], 'اتلغت');

        // الطرود — أسماء ومناطق مقنّعة، من غير عهدة ولا أسعار
        $sql = 'SELECT d.parcel_no, d.receiver_name, d.receiver_phone, d.status,
                       COALESCE(NULLIF(d.zone_name, \'\'), z.area_name) AS zone_name
                  FROM order_deliveries d LEFT JOIN zones z ON z.id = d.zone_id
                 WHERE d.order_id = ?' . ($parcelNo !== null ? ' AND d.parcel_no = ?' : '') . '
                 ORDER BY d.parcel_no';

        $params = $parcelNo !== null ? [(int) $o['id'], $parcelNo] : [(int) $o['id']];

        $parcels = array_map(
            fn ($d) => PublicWire::trackParcel($d),
            DB::select($sql, $params)
        );

        return ApiResponse::out([
            'ok'        => true,
            'found'     => true,
            'orderNum'  => $o['order_num'],
            'status'    => Vocab::statusToAr($o['status']),
            // اسم الفرع بيطلع كامل — بيانات منشورة، مش بيانات شخصية
            'branch'    => $o['_branch_name'],
            // 🔒 اسم الطيار مقنّع زي المستلم — ده اسم موظف
            'pilotName' => PublicWire::maskName($o['pilot_name']),
            'createdAt' => WireTime::toWire($o['created_at']),
            'timeline'  => $timeline,
            'parcels'   => $parcels,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       التغطية والأسعار — عام
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/public/coverage
     *
     * مناطق التغطية وأسعارها + عدد الفروع الشغّالة — للموقع التسويقي.
     *
     * الأسعار هنا **مسموحة** وهي الاستثناء الوحيد لقاعدة «مفيش مبالغ في
     * المسارات العامة»: ده سعر التوصيل المعلن على الموقع، مش فلوس أوردر
     * بعينه. الفرق إن ده تسعيرة منشورة والتاني بيانات معاملة.
     *
     * الرد **مش مغلّف بـ PollableList** — لا `serverNow` ولا `changed`. صفحة
     * تسويقية مابتستطلعش، والواجهة بتقرا `zones` مباشرة.
     */
    public function coverage(): JsonResponse
    {
        /* 🔴 جدول `zones` صف لكل **مسار**: (الفرع اللي بيشيل الشحنة →
           المنطقة اللي بيوصّلها). فالمنطقة الواحدة بتتكرر مرة لكل فرع —
           139 منطقة بيطلعوا 417 صف.

           الرد القديم كان `SELECT area_name, price` من غير أي تجميع، يعني
           بيرمي الـ417 صف زي ما هم. النتيجة على الموقع: جدول الأسعار
           بيعرض «6 اكتوبر — 30 جنيه» تلات مرات ورا بعض من غير أي فرق
           باين، والصفحة الرئيسية بتقول «417 منطقة» وهي 139.

           بقى فيه مفتاحين:
             • `zones`  — منطقة واحدة لكل اسم (العدّاد وشرايط الرئيسية).
             • `routes` — الصفوف بالتفصيل بـ`from`/`to`، عشان صفحة الأسعار
               تقدر تقول للعميل «من فين لفين» بدل اسم مكرر بلا معنى.

           `zones` اتساب بنفس شكله (`name`/`price`) عشان الرئيسية
           مااتلمستش — الجديد إضافة مش تغيير. */
        $rows = DB::select(
            'SELECT z.area_name, z.price, b.name AS from_branch
               FROM zones z
               JOIN branches b ON b.id = z.delivery_branch_id
              WHERE b.paused = 0 AND TRIM(z.area_name) <> \'\'
              ORDER BY b.name, z.area_name'
        );

        $routes = [];
        $byArea = [];
        foreach ($rows as $r) {
            $area  = (string) $r->area_name;
            $price = (float) $r->price;

            $routes[] = [
                // «من» = الفرع اللي هيشيل الشحنة، «إلى» = منطقة التسليم
                'from'  => (string) $r->from_branch,
                'to'    => $area,
                'price' => $price,
            ];

            /* أقل سعر للمنطقة + أعلى سعر: دلوقتي كل المسارات بنفس السعر،
               بس لو اتفرّقوا بعدين الواجهة تعرف تقول «من كذا لكذا» بدل
               ما تعرض رقم واحد وتكدب. */
            if (! isset($byArea[$area])) {
                $byArea[$area] = ['min' => $price, 'max' => $price];
            } else {
                $byArea[$area]['min'] = min($byArea[$area]['min'], $price);
                $byArea[$area]['max'] = max($byArea[$area]['max'], $price);
            }
        }

        $out = [];
        foreach ($byArea as $name => $p) {
            $out[] = ['name' => $name, 'price' => $p['min'], 'priceMax' => $p['max']];
        }
        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));

        /* الفروع الموقوفة (`paused = 1`) مش محسوبة — العدد ده بيتعرض على
           الموقع كـ«عدد فروعنا»، والفرع الموقوف مش بيستقبل شحنات أصلًا. */
        $branchCount = (int) (DB::select('SELECT COUNT(*) AS n FROM branches WHERE paused = 0')[0]->n ?? 0);

        return ApiResponse::out([
            'ok'          => true,
            'zones'       => $out,
            'routes'      => $routes,
            'branchCount' => $branchCount,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       طلب انضمام طيار من فورم الموقع — عام (كتابة بلا دخول)
    ═══════════════════════════════════════════════════════════ */

    /**
     * POST /api/public/join-request
     *
     * 🔓 **مسار كتابة مفتوح للدنيا كلها** — أي حد على الإنترنت بيقدر يدخّل
     * صف في `pilot_join_requests`. ده مقصود (فورم «انضم كطيار» على الموقع)،
     * والحماية الوحيدة هي التحقق من الطول تحت + `source='home'` اللي بيخلّي
     * الإدارة تفرّق الطلبات دي عن اللي جاية من اللوحة.
     *
     * ⚠️ **مفيش rate-limit** على المسار ده في الأصل — منقول زي ما هو
     * (متسجّل في notes).
     *
     * تفاصيل منقولة بالحرف:
     *  • الاسم بـ`mb_strlen` (حروف) والتليفون بـ`strlen` (بايت) — التليفون
     *    أرقام لاتينية بعد `preg_replace` فالبايت = الحرف، والاسم عربي
     *    فلازم يتعدّ بالحروف وإلا «محمد» (8 بايت) كانت هتعدّي بالغلط.
     *  • التليفونين بيتخزّنوا في عمود واحد `phones` مفصولين بفاصلة.
     *  • `requested_by` نص ثابت «الموقع العام» — مفيش حساب وراه.
     *  • `mb_substr(...) ?: null` — النص الفاضي بيتخزّن NULL مش ''.
     */
    public function joinRequest(Request $request): JsonResponse
    {
        $b = $request->json()->all();

        $name   = trim((string) ($b['name'] ?? ''));
        $phone1 = (string) preg_replace('/[^\d]/', '', (string) ($b['phone1'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            throw new ApiException('اكتب اسمك بشكل صحيح');
        }
        if (strlen($phone1) < 8 || strlen($phone1) > 15) {
            throw new ApiException('اكتب رقم موبايل صحيح');
        }
        $phone2 = (string) preg_replace('/[^\d]/', '', (string) ($b['phone2'] ?? ''));

        /* 📋 بيانات المتقدّم — **كلها اختيارية** (طلب صاحب النظام 2026-09-12:
           «نضيف حبة معلومات لو حابب يملاها وهو بيقدّم»). فاضية = NULL،
           والطلب بيتقبل عادي من غيرها — دي بتساعد في القرار مش بتمنعه.

           ⚠️ الأرقام بتتقص عند صفر: الراتب بالسالب أو سنين خبرة بالسالب
              بيتحفظوا NULL مش قيمة غلط. */
        $opt = static fn (string $k, int $max): ?string
            => mb_substr(trim((string) ($b[$k] ?? '')), 0, $max) ?: null;
        $num = static function (string $k, float $max) use ($b): ?float {
            $v = $b[$k] ?? null;
            if ($v === null || $v === '' || ! is_numeric($v)) {
                return null;
            }

            return min(max(0.0, (float) $v), $max) ?: null;
        };

        DB::insert(
            "INSERT INTO pilot_join_requests
               (name, phones, card_num, vehicle_no, address,
                prev_employer, leave_reason, last_salary, experience_years, applicant_note,
                branch_id, requested_by, status, source, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,NULL,'الموقع العام','pending','home',?)",
            [
                mb_substr($name, 0, 60),
                $phone2 !== '' ? $phone1 . ',' . $phone2 : $phone1,
                mb_substr(trim((string) ($b['cardNum'] ?? '')), 0, 30) ?: null,
                mb_substr(trim((string) ($b['vehicleNo'] ?? '')), 0, 30) ?: null,
                mb_substr(trim((string) ($b['address'] ?? '')), 0, 190) ?: null,
                $opt('prevEmployer', 255),
                $opt('leaveReason', 255),
                $num('lastSalary', 999999),
                $num('experienceYears', 60),
                $opt('applicantNote', 2000),
                WireTime::nowDb(),
            ]
        );

        // الرد رسالة للزائر مباشرة — مفيش id ولا صف، عشان مايبانش حجم الطابور
        return ApiResponse::ok(['message' => 'وصلنا طلبك — هنتواصل معاك قريب 👍']);
    }

    /* ═══════════════════════════════════════════════════════════
       رسالة تواصل من فورم «اتصل بنا» — عام (كتابة بلا دخول)
    ═══════════════════════════════════════════════════════════ */

    /**
     * POST /api/public/contact-message — {name, phone, email?, subject?, message}
     *
     * 🔓 **مسار كتابة تاني مفتوح للدنيا كلها** — نفس طبيعة `joinRequest()`
     * فوق، ومكتوب على نفس أسلوبه بالظبط: قراءة الجسم بـ`json()->all()`،
     * تحقق يدوي برمي `ApiException` برسالة عربية، `DB::insert` خام،
     * ورد برسالة للزائر من غير id ولا صف (عشان مايبانش حجم الطابور).
     *
     * 🔴 **الفرق الوحيد المقصود عن joinRequest: المسار ده عليه حد معدّل.**
     * `joinRequest` مافيهوش rate-limit لأنه منقول حرفيًا من الأصل — أما ده
     * مسار جديد، وفورم «اتصل بنا» بيتنده عليه بوت أكتر بكتير من فورم
     * الانضمام. الحد **3 رسايل في الساعة لنفس رقم الموبايل**، والعدّ من
     * `contact_messages` نفسه مش من كاش — نفس نمط `TrustController::lookup`
     * و`CustomerAppController::incomingThrottle`: بيفضل شغال عبر أكتر من
     * عامل/سيرفر، ومابيحتاجش تخزين تاني.
     *
     * ليه العدّ على التليفون مش على الـIP: الـIP بيتغيّر بتغيير شبكة الموبايل
     * (وشبكات المحمول في مصر بتشارك IP بين آلاف المستخدمين — الحد على IP
     * كان هيقفل على ناس أبرياء). الرقم هو الهوية اللي الرد هيروح عليها.
     * الـIP بيتخزّن للتدقيق بس، مش للحد.
     *
     * التحقق من التليفون (2026-09-09): نفس قاعدة joinRequest — أي 8→15 رقم
     * (كان موبايل مصري بس، وصاحب النظام طلب قبول أي رقم في كل التطبيقات).
     * التطبيع بيعدّي على `TrustWire::normalizePhone`
     * (نفس اللي منظومة الثقة بتستخدمه) فـ`+20 100 123 4567` و`01001234567`
     * بيتخزّنوا شكل واحد — وده شرط عشان الحد المعدّل ما يتلفّش بإن الواحد
     * يكتب رقمه بشكل مختلف كل مرة.
     *
     * `mb_strlen` للاسم والرسالة (حروف) مش `strlen` (بايت) — نفس سبب
     * joinRequest: النص عربي، و«محمد» 8 بايت وكانت هتعدّي بالغلط.
     */
    public function contactMessage(Request $request): JsonResponse
    {
        $b = $request->json()->all();

        $name    = trim((string) ($b['name'] ?? ''));
        $phone   = TrustWire::normalizePhone((string) ($b['phone'] ?? ''));
        $email   = trim((string) ($b['email'] ?? ''));
        $subject = trim((string) ($b['subject'] ?? ''));
        $message = trim((string) ($b['message'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            throw new ApiException('اكتب اسمك بشكل صحيح');
        }
        // أي رقم من 8 لـ 15 رقم (طلب صاحب النظام 2026-09-09) — كان موبايل مصري بس
        if (preg_match('/^[0-9]{8,15}$/', $phone) !== 1) {
            throw new ApiException('اكتب رقم هاتف صحيح — من 8 لـ 15 رقم');
        }
        // الإيميل اختياري — بس لو اتكتب لازم يكون سليم، عشان مانبعتش رد
        // على عنوان غلط ونفتكر إننا رددنا
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('البريد الإلكتروني مش مكتوب صح');
        }
        if (mb_strlen($message) < 10) {
            throw new ApiException('اكتب رسالتك بتفصيل أكتر — ١٠ حروف على الأقل');
        }
        /* السقف الأعلى **بيترفض** مش بيتقص: العمود TEXT وواسع، بس مسار
           مفتوح بلا دخول لازم يبقى له سقف. والقص الصامت هنا غلط — الرسالة
           الناقصة بتوصل للأدمن مبتورة وهو مش عارف، على عكس الاسم تحت اللي
           القص فيه بيفقد حروف زيادة بس. */
        if (mb_strlen($message) > 5000) {
            throw new ApiException('الرسالة طويلة أوي — اختصرها في 5000 حرف');
        }

        /* الفحص والإدراج جوه معاملة واحدة: من غير كده رسالتين متوازيتين من
           نفس الرقم الاتنين بيلاقوا العدّاد تحت الحد ويعدّوا. */
        return DB::transaction(function () use ($request, $name, $phone, $email, $subject, $message): JsonResponse {
            $used = (int) (DB::select(
                'SELECT COUNT(*) AS c FROM contact_messages
                  WHERE phone = ? AND created_at >= (UTC_TIMESTAMP() - INTERVAL 1 HOUR)',
                [$phone]
            )[0]->c ?? 0);

            if ($used >= self::CONTACT_HOURLY_LIMIT) {
                throw new ApiException(
                    'بعتّ ' . self::CONTACT_HOURLY_LIMIT . ' رسايل في الساعة الأخيرة — استنى شوية وابعت تاني',
                    429
                );
            }

            /* `mb_substr(...) ?: null` — النص الفاضي بيتخزّن NULL مش ''،
               نفس أسلوب joinRequest. الأطوال هي أطوال الأعمدة بالظبط
               (190/190/45) عشان مايحصلش قص من القاعدة نفسها. */
            DB::insert(
                "INSERT INTO contact_messages
                   (name, phone, email, subject, message, ip_address, status, read_by, read_at, created_at)
                 VALUES (?,?,?,?,?,?,'pending',NULL,NULL,?)",
                [
                    mb_substr($name, 0, 190),
                    $phone,
                    mb_substr($email, 0, 190) ?: null,
                    mb_substr($subject, 0, 190) ?: null,
                    $message,
                    mb_substr((string) $request->ip(), 0, 45) ?: null,
                    // الوقت متحط صراحةً UTC زي باقي النظام — العمود عليه
                    // DEFAULT current_timestamp() بس ده بيتبع منطقة السيرفر
                    WireTime::nowDb(),
                ]
            );

            return ApiResponse::ok(['message' => 'وصلتنا رسالتك — هنرد عليك قريب 👍']);
        });
    }
}
