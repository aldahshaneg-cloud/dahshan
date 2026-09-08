<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Support\ApiResponse;
use App\Support\PollableList;
use App\Support\Vocab;
use App\Support\WireTime;
use App\Http\Controllers\Concerns\BroadcastsOrders;
use App\Services\Whatsapp\OrderRecipients;
use App\Wire\ContactWire;
use App\Wire\NotifyWire;
use App\Wire\OrderWire;
use App\Wire\SupportWire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * الدعم والإعدادات — نقل حرفي لمسارات القراءة من api/routes/support.php.
 *
 * 🔴 **أهم فرق في الملف ده عن باقي الكنترولرات**: قوايم الدعم التلاتة
 * (complaints / zone-requests / partners) بترجع غلاف
 *
 *     {"ok":true,"changed":true,"items":[...]}
 *
 * **من غير `serverNow`** — الأصل بيبني المصفوفة بإيده وبيسيب المفتاح ده.
 * ده مخالف لبند 5 في CONVENTIONS.md وللغلاف الموحد PollableList، بس
 * العقد مجمّد: `PollableList::items()` كان هيضيف `serverNow` ويكسّر
 * المقارنة الحرفية. فالغلاف هنا مكتوب بالإيد عمدًا — مش سهو.
 * (المسارات دي كمان مابتدعمش `?since` أصلًا، فمفيش `changed:false`.)
 *
 * الاستعلامات خام بـ DB::select زي الأصل — نفس `SELECT *`، نفس الفرز،
 * نفس السقوف (500 للشكاوى، 300 لطلبات المناطق).
 */
class SupportController
{
    use BroadcastsOrders;

    /* ═══════════════════════════════════════════════════════════
       شكاوى الكول سنتر
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/complaints?status=open|resolved|all
     *
     * الأدوار: admin · callcenter · branch · **hr**.
     * وجود `hr` هنا مقصود في الأصل (شؤون العاملين بتراجع شكاوى تعامل
     * الطيارين)، وهو **أوسع** من مسار الإنشاء اللي مافيهوش hr. سيبناه
     * زي ما هو — أي تضييق هنا تغيير سلوك.
     *
     * `?status`: أي قيمة تانية غير فاضي/`all` بتتحط في WHERE **كما هي**
     * من غير أي تحقق من القايمة المسموحة. قيمة مش موجودة = قايمة فاضية،
     * مش خطأ. ده سلوك الأصل بالحرف.
     */
    public function complaintsList(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $sql = 'SELECT * FROM cc_complaints';
        $args = [];
        if ($status !== null && $status !== '' && $status !== 'all') {
            $sql .= ' WHERE status = ?';
            $args[] = $status;
        }
        // الفرز على created_at ثم id: الشكاوى اللي اتسجّلت في نفس الثانية
        // (العمود DATETIME من غير كسور) لازم يفضل ترتيبها ثابت
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 500';

        return $this->legacyList(array_map(
            fn ($r) => SupportWire::complaint($r),
            DB::select($sql, $args)
        ));
    }

    /* ═══════════════════════════════════════════════════════════
       طلبات المناطق غير المعرفة
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/zone-requests?status=pending|added|rejected|all
     *
     * الأدوار: admin · callcenter · branch — من غير hr هنا (على عكس
     * الشكاوى). الفرق ده في الأصل ومنقول زي ما هو.
     *
     * الفرز بالعدّاد الأول: المنطقة اللي اتطلبت 40 مرة أهم من اللي
     * اتطلبت امبارح — اللوحة بتشتغل من فوق لتحت من غير فرز إضافي.
     */
    public function zoneRequestsList(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $sql = 'SELECT * FROM cc_zone_requests';
        $args = [];
        if ($status !== null && $status !== '' && $status !== 'all') {
            $sql .= ' WHERE status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY request_count DESC, last_at DESC LIMIT 300';

        return $this->legacyList(array_map(
            fn ($r) => SupportWire::zoneRequest($r),
            DB::select($sql, $args)
        ));
    }

    /* ═══════════════════════════════════════════════════════════
       رسايل «اتصل بنا» الجاية من الموقع التسويقي
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/contact-messages?status=pending|read|archived|all
     *
     * 🔒 `role:admin` بس — **أضيق من قوايم الدعم اللي فوق**. الرسايل دي
     * فيها اسم وتليفون وإيميل ناس من بره الشركة كتبوها في فورم عام، ومفيش
     * أي تقنيع عليها (شوف ContactWire). فتحها لـbranch أو callcenter =
     * دفتر عناوين مفتوح لكل موظف. لو الاحتياج اتغيّر لازم نسخة مقنّعة أول.
     *
     * ⚠️ **المسار ده جديد مش منقول** — فبيستخدم غلاف PollableList الموحد
     * (بند 5 في CONVENTIONS.md) مش الغلاف اليدوي بتاع `legacyList()` اللي
     * فوق. الأخير مجمّد على عقد قديم؛ المسار الجديد مش مربوط بيه. الغلاف
     * مبني بالإيد هنا بس عشان `PollableList::items()` مابتاخدش مفاتيح
     * زيادة، وإحنا محتاجين `unread` جنب `items` — نفس اللي بيحصل بالظبط في
     * `CustomerAppController::notifications`.
     *
     * `?status` بيتحط في WHERE **كما هو** من غير تحقق من قايمة مسموحة —
     * نفس سلوك قوايم الدعم فوق بالحرف (قيمة مش موجودة = قايمة فاضية مش خطأ).
     *
     * 🔴 `unread` **مستقل تمامًا عن الفلتر**: العدّاد بيتحسب على الجدول كله
     * مش على القايمة المرجّعة. ده مقصود — اللوحة بتعرضه كشارة (badge) جنب
     * التبويب، فلازم يفضل ثابت لما الأدمن يفلتر على `read`. لو اتحسب من
     * `$items` كان هيبقى صفر بالظبط لما يبقى محتاج يبان.
     *
     * تعريف «مش مقروءة» = `read_at IS NULL` مش `status <> 'read'` — العمود
     * `read_at` هو التعريف المكتوب في الجدول نفسه (COMMENT: «NULL يعني لسه
     * مش مقروءة»)، والرسالة اللي اتقرت وبعدين اتأرشفت مالهاش لازمة في
     * العدّاد.
     *
     * الفرز `created_at DESC, id DESC` — الأحدث الأول، والـid بيكسر التعادل
     * لأن العمود DATETIME من غير كسور فرسايل نفس الثانية لازم ترتيبها ثابت.
     */
    public function contactMessagesList(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $sql  = 'SELECT * FROM contact_messages';
        $args = [];
        if ($status !== null && $status !== '' && $status !== 'all') {
            $sql .= ' WHERE status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 500';

        $items = array_map(
            fn ($r) => ContactWire::message($r),
            DB::select($sql, $args)
        );

        $unread = (int) (DB::select(
            'SELECT COUNT(*) AS c FROM contact_messages WHERE read_at IS NULL'
        )[0]->c ?? 0);

        return ApiResponse::out([
            'ok'        => true,
            'serverNow' => PollableList::serverNowMs(),
            'changed'   => true,
            'items'     => $items,
            'unread'    => $unread,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       رسايل العملاء — إشعار المستلمين بالأوردر
       ───────────────────────────────────────────────────────────
       الجدول `order_notifications` بيتملّى تلقائيًا من `SendOrderWhatsapp`
       بعد إنشاء أي أوردر من أي مصدر. المسارين دول هما **الجهة الإدارية**
       للجدول ده: قايمة للعرض، وزرار «اتبعت» للوضع اليدوي.

       🔴 ليه المسارين موجودين أصلًا: مفيش حساب WhatsApp Cloud API لسه،
       فالمزوّد الافتراضي `manual` بيسيب الصف `pending` ومحدّش بيبعت.
       الشاشة في tiar.html هي اللي بتقفل الدايرة — الموظف بيفتح واتساب
       بالنص المتخزّن وبيعلّم الصف. يوم ما `WHATSAPP_PROVIDER=cloud_api`
       الصفوف الجديدة هتبقى `sent` من غير تدخّل، والشاشة تفضل كشاشة
       متابعة وتصليح للفاشل.
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/order-notifications?status=pending|sent|failed|skipped|all
     *
     * 🔒 `role:admin,branch,callcenter` — **أوسع من contact-messages**
     * (اللي admin بس) لأن ده شغل تشغيلي يومي بيتعمل من الفرع والكول سنتر،
     * والبيانات اللي فيه (اسم ورقم مستلم أوردر) نفس اللي الأدوار دي شايفاه
     * في شاشة الأوردرات أصلًا. مفيش توسيع خصوصية هنا — نفس الجمهور.
     *
     * 🔒 ودور `branch` مقصوص على أوردرات فرعه بنفس شرط
     * `OrdersController::index` بالحرف (`o.branch_id = ?`). من غير القصّ ده
     * كان الفرع هيشوف أرقام مستلمين كل الفروع — وده **توسيع** حقيقي، لأن
     * شاشة الأوردرات عنده مقصوصة.
     *
     * `?status`: أي قيمة غير فاضي/`all` بتتحط في WHERE كما هي من غير تحقق
     * من قايمة مسموحة — **نفس سلوك `complaintsList` و`contactMessagesList`
     * بالحرف**. قيمة مش موجودة = قايمة فاضية، مش خطأ.
     *
     * الغلاف زي `contactMessagesList`: `serverNow` + `changed:true` + `items`
     * (المسار ده مابيدعمش `?since`، فـ`changed` ثابتة).
     */
    public function orderNotificationsList(Request $request): JsonResponse
    {
        $actor  = $request->actorOrFail();
        $status = $request->query('status');

        $where = [];
        $args  = [];

        if ($actor->role === 'branch') {
            $where[] = 'o.branch_id = ?';
            $args[]  = (int) ($actor->branchId ?? 0);
        }

        if (is_string($status) && $status !== '' && $status !== 'all') {
            $where[] = 'n.status = ?';
            $args[]  = $status;
        }

        /* 🔴 أوردر اتلغى **بعد** ما رسالته اتسجّلت.
           `SendOrderWhatsapp` بيفحص الإلغاء مرة واحدة — وقت تشغيل المهمة،
           يعني ثواني بعد الإنشاء. لكن في الوضع اليدوي الإرسال الحقيقي
           بيحصل بعدها بوقت لما موظف يفتح تبويب «معلّقة» ويضغط. من غير
           الشرط ده: أوردر اتعمل ٩:٠٠ واتلغى ٩:٠٤ بيفضل في طابور الإرسال،
           والموظف بيبعت لينك تتبّع لعميل أوردره ملغي — والشاشة أصلًا
           مش بتجيب حالة الأوردر فمفيش طريقة يعرف.
           المعلّق بس هو اللي بيتشال (هو اللي عليه فعل) — المبعوت والفاشل
           والمتخطّي سجلّ تاريخي ولازم يفضل ظاهر مهما حصل للأوردر. */
        $where[] = "(n.status <> 'pending'
                     OR (o.status <> 'cancelled' AND o.cancelled_at IS NULL))";

        /* الـJOIN على `orders` بيخدم غرضين: `order_num` للعرض (مش متخزّن في
           صف الرسالة)، و`branch_id` للقصّ. مفتاح أجنبي مفهرس فالربط رخيص. */
        /* ?since (2026-09-08): الإدارة والكول سنتر بيستطلعوا القايمة كل دقيقة والرد الكامل
           ~257 كيلو (352 ميجا في 13 ساعة). آخر تعديل = أكبر updated_at (بيتحدّث مع أي تغيير
           حالة/إرسال) — لو أقدم من since نرد changed:false من غير items. */
        $since = $request->query->has('since') ? max(0, (int) $request->query('since') - 1000) : 0;
        if ($since > 0) {
            $mx = DB::select(
                'SELECT UNIX_TIMESTAMP(MAX(n.updated_at)) * 1000 AS m FROM order_notifications n JOIN orders o ON o.id = n.order_id'
                . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : ''),
                $args
            )[0]->m ?? null;
            if ($mx !== null && (int) $mx <= $since) {
                return PollableList::unchanged();
            }
        }

        $sql = 'SELECT n.*, o.order_num AS order_num
                  FROM order_notifications n
                  JOIN orders o ON o.id = n.order_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        // الأحدث الأول — نفس فرز contact-messages، و`idx_order_notifications_created` بيخدمه
        $sql .= ' ORDER BY n.created_at DESC, n.id DESC LIMIT 500';

        $rows = DB::select($sql, $args);
        $meta = $this->notifyRecipientMeta($rows);

        $items = array_map(
            fn ($r) => NotifyWire::notification(
                $r,
                $meta[(int) $r->order_id][(string) $r->recipient_phone] ?? []
            ),
            $rows
        );

        /* العدّاد من القاعدة مش من طول القايمة — بالظبط نفس سبب `unread` في
           `contactMessagesList`: القايمة مسقوفة بـ500 صف، فلو المعلّق أكتر
           من كده الشارة تفضل صح والقايمة هي اللي ناقصة. وبيتحسب مع الفلتر
           **مرفوع** عشان يفضل ثابت وإنت بتتنقّل بين التبويبات. */
        $pendSql  = 'SELECT COUNT(*) AS c
                       FROM order_notifications n
                       JOIN orders o ON o.id = n.order_id
                      WHERE n.status = ?';
        $pendArgs = ['pending'];
        if ($actor->role === 'branch') {
            $pendSql .= ' AND o.branch_id = ?';
            $pendArgs[] = (int) ($actor->branchId ?? 0);
        }
        // نفس شرط القايمة فوق: أوردر ملغي = رسالته المعلّقة مش شغل حد،
        // فالشارة الحمرا ماينفعش تعدّها — وإلا العدّاد يقول «فيه شغل»
        // والقايمة فاضية.
        $pendSql .= " AND o.status <> 'cancelled' AND o.cancelled_at IS NULL";
        return ApiResponse::out([
            'ok'        => true,
            'serverNow' => PollableList::serverNowMs(),
            'changed'   => true,
            'items'     => $items,
            'pending'   => (int) (DB::select($pendSql, $pendArgs)[0]->c ?? 0),
        ]);
    }

    /**
     * POST /api/order-notifications/{id}/sent — «الموظف بعتها بإيده».
     *
     * 🔒 `role:admin,branch,callcenter`، والفرع على أوردراته بس — القصّ
     * هنا **فحص صريح مش شرط في WHERE**: التحديث الأعمى كان هيرجّع
     * «اتعلّمت» لصف مش من فرعه، فبيبان إن العملية نجحت وهي ما نجحتش.
     *
     * 🔴 المسار ده بيسجّل «فتحت الواتساب» مش «الرسالة وصلت». مفيش أي طريقة
     * نعرف بيها إن الموظف ضغط send فعلًا جوه واتساب — فالشاشة بتفتح
     * الواتساب الأول وبتسيب التعليم لضغطة تانية منفصلة، وde جوه العمود
     * `sent_by` اللي بيفرّق بين «بني آدم علّمها» و«المزوّد بعتها».
     *
     * ⚠️ `attempts` **مابتزيدش هنا** عن قصد. العمود معرّف في الجدول بإنه
     * «عدد محاولات الإرسال الفعلية» — يعني نداءات الشبكة اللي المزوّد
     * عملها. لو الضغطة اليدوية زوّدته، العمود كان هيبقى خليط بين حاجتين
     * ومايبقاش صالح لأمر الكنس اللي هيتبني عليه بعدين.
     *
     * الشرط `sent_at IS NULL` + إعادة القراءة: نفس نمط `contactMessageRead`
     * بالحرف. `affected === 0` **مش خطأ لوحده** — يا الرسالة اتعلّمت قبل
     * كده (سليم، ونداء مكرر لازم يعدّي) يا مش موجودة، والفرق اتحدّد فوق.
     */
    public function orderNotificationSent(Request $request, string $id): JsonResponse
    {
        $user = $request->actorOrFail();
        $nid  = (int) $id;

        return DB::transaction(function () use ($user, $nid): JsonResponse {
            $row = DB::selectOne(
                'SELECT n.id, n.status, n.error, o.branch_id AS branch_id
                   FROM order_notifications n
                   JOIN orders o ON o.id = n.order_id
                  WHERE n.id = ?',
                [$nid]
            );

            if ($row === null) {
                throw ApiException::notFound('الرسالة غير موجودة');
            }

            if ($user->role === 'branch'
                && (int) $row->branch_id !== (int) ($user->branchId ?? 0)) {
                throw ApiException::forbidden('الرسالة دي مش من أوردرات فرعك');
            }

            /* المتخطّية مالهاش «اتبعت»: `skipped` معناها مفيش رقم صالح
               أصلًا (أو الأوردر اتلغى)، فمفيش حاجة اتبعتت. رفض صريح
               برسالة السبب أحسن من تحديث بيكدب على الشاشة. */
            if ($row->status === 'skipped') {
                throw new ApiException(
                    'الرسالة دي متخطّية: ' . ($row->error !== null && $row->error !== ''
                        ? $row->error
                        : 'مفيش رقم صالح للمستلم')
                );
            }

            DB::update(
                "UPDATE order_notifications
                    SET status = 'sent', sent_by = ?, sent_at = ?
                  WHERE id = ? AND sent_at IS NULL",
                [$user->username, WireTime::nowDb(), $nid]
            );

            /* بنقرا بعد التحديث على طول عشان نرجّع القيم المحسوبة (وقت
               الإرسال اللي اتكتب دلوقتي، أو بتاع أول واحد علّمها) — نفس
               سبب إعادة القراءة في `contactMessageRead`. */
            $after = DB::selectOne(
                'SELECT n.*, o.order_num AS order_num
                   FROM order_notifications n
                   JOIN orders o ON o.id = n.order_id
                  WHERE n.id = ?',
                [$nid]
            );

            if ($after === null) {
                throw ApiException::notFound('الرسالة غير موجودة');
            }

            $meta = $this->notifyRecipientMeta([$after]);

            return ApiResponse::ok([
                'message' => NotifyWire::notification(
                    $after,
                    $meta[(int) $after->order_id][(string) $after->recipient_phone] ?? []
                ),
            ]);
        });
    }

    /**
     * اسم المستلم وأرقام طروده لكل صف رسالة — استعلام واحد لكل الصفوف.
     *
     * 🔴 **ليه محتاجينه أصلًا:** `order_notifications` مابيخزّنش الاسم.
     * الصف snapshot **للرسالة** (نصها ورقمها) مش للمستلم؛ والاسم بيتصلّح
     * بعد الإنشاء كتير، فنسخة متجمّدة منه كانت هتوَرّي الموظف اسمًا غلط
     * وهو بيدوّر على الطرد. بنقراه من `order_deliveries` وقت العرض.
     *
     * 🔴 **الوصلة هي `OrderRecipients::phoneKey()` نفسها** — نفس الدالة
     * اللي بنى بيها الصف مفتاحه. أي اشتقاق تاني للمفتاح هنا كان هيخلّي
     * المطابقة تفشل بالصمت لما التطبيع يتغيّر، والشاشة تعرض أرقام بلا
     * أسامي لصفوف سليمة.
     *
     * استعلام واحد بـ`IN` مش استعلام لكل صف: القايمة مسقوفة بـ500، يعني
     * الشكل التاني كان 500 رحلة للقاعدة في كل تحميل للشاشة.
     *
     * صف مالوش مقابل (الطرد اتنقل بـ`split()` لأوردر تاني) بيرجع بلا
     * مفتاح خالص، و`NotifyWire` بيدّيه `name = null` — مقصود وموثّق هناك.
     *
     * @param  array<int,object>  $rows  صفوف الرسايل (فيها order_id)
     * @return array<int,array<string,array{name:string|null,parcels:int[]}>>
     */
    private function notifyRecipientMeta(array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $ids[(int) $r->order_id] = true;
        }
        if ($ids === []) {
            return [];
        }

        $ids = array_keys($ids);
        $in  = implode(',', array_fill(0, count($ids), '?'));

        $deliveries = DB::select(
            "SELECT order_id, parcel_no, receiver_name, receiver_phone
               FROM order_deliveries
              WHERE order_id IN ({$in})
              ORDER BY order_id, parcel_no",
            $ids
        );

        $grouped = [];
        foreach ($deliveries as $d) {
            $oid = (int) $d->order_id;
            $key = OrderRecipients::phoneKey($d->receiver_phone);

            if (! isset($grouped[$oid][$key])) {
                $grouped[$oid][$key] = ['names' => [], 'parcels' => []];
            }

            $grouped[$oid][$key]['parcels'][] = (int) $d->parcel_no;

            $name = trim((string) ($d->receiver_name ?? ''));
            if ($name !== '') {
                // مفتاح مش قيمة — نفس الاسم على طردين مايتكررش في العرض
                $grouped[$oid][$key]['names'][$name] = true;
            }
        }

        $out = [];
        foreach ($grouped as $oid => $byPhone) {
            foreach ($byPhone as $key => $g) {
                $names = array_keys($g['names']);
                $out[$oid][(string) $key] = [
                    /* أكتر من اسم على نفس الرقم بيتعرضوا كلهم: ده بيحصل
                       فعلًا (بيت واحد، اسمين) والاختيار العشوائي بين
                       الاتنين كان هيخفي طرد على الموظف. */
                    'name'    => $names === [] ? null : implode(' · ', $names),
                    'parcels' => $g['parcels'],
                ];
            }
        }

        return $out;
    }

    /* ═══════════════════════════════════════════════════════════
       الإعدادات
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/settings — كل الإعدادات.
     *
     * 🔒 `require_auth()` بس — **أي حساب مسجّل** بيقراها، بما فيهم دور
     * `customer` وصاحب المحل والطيار. مش موظفين بس رغم تعليق الأصل
     * («للموظفين»). التعليق غلط والكود هو الحاكم — منقول زي ما هو.
     *
     * فك التشفير: القيمة متخزّنة JSON، بس لو الفك رجّع null والنص مش
     * `"null"` حرفيًا → بنرجّع **النص الخام**. ده اللي بيخلي الإعدادات
     * القديمة اللي اتخزّنت كنص عادي (قبل ما التخزين يبقى JSON) تفضل
     * شغّالة بدل ما تختفي كـnull.
     *
     * ⚠️ لو الجدول فاضي بيرجع `"settings":[]` (مصفوفة) مش `{}` — لأن
     * PHP بيرمّز المصفوفة الفاضية كمصفوفة. سلوك الأصل بالحرف.
     */
    public function settingsGet(Request $request): JsonResponse
    {
        $request->actorOrFail();

        $out = [];
        foreach (DB::select('SELECT setting_key, setting_value FROM site_settings') as $r) {
            $decoded = json_decode((string) $r->setting_value, true);
            $out[$r->setting_key] = $decoded !== null || $r->setting_value === 'null'
                ? $decoded
                : $r->setting_value;
        }

        $out['pilotAppContent'] = self::inheritPilotSupport($out);

        return ApiResponse::ok(['settings' => $out]);
    }

    /**
     * أرقام دعم الطيار الفاضية بتورّث أرقام الموقع.
     *
     * ═══ المشكلة ═══
     * أرقام الدعم متخزّنة في مكانين: `site.info` (الموقع وتطبيق العميل
     * وبوابة المحلات) و`pilotAppContent.support` (تطبيق الطيار). التاني
     * **عمره ما اتملى**، فالطيار كان بيفتح «تواصل مع الدعم» ويلاقي
     * «أرقام الدعم لسه ما اتظبطتش» — والأرقام موجودة في اللوحة طول الوقت.
     *
     * ═══ ليه توريث مش نسخ ═══
     * لو نسخنا الأرقام مرة واحدة، أول ما الإدارة تغيّر رقم الموقع تفضل
     * النسخة القديمة عند الطيار للأبد ومحدش ياخد باله. التوريث بيحصل مع
     * كل نداء، فرقم واحد بيتغيّر في مكان واحد.
     *
     * ═══ ليه في السيرفر مش في التطبيق ═══
     * نفس سبب إصلاح المحفظة: بيوصل **كل** الطيارين في نفس اللحظة، حتى
     * اللي مانزّلش نسخة جديدة. تطبيق الطيار بيقرا `support.whatsapp` و
     * `support.phone` (والنسخ الجديدة بتقرا القوايم كمان) — وكلهم
     * بيتملوا هنا.
     *
     * ═══ `inherited` ═══
     * علامة للوحة التحكم بس: بتقولها «الأرقام دي مش متكتوبة، دي موروثة».
     * من غيرها كانت الخانات هتتملى في شاشة التعديل، وأول حفظ كان
     * هينسخهم فعلًا في `pilotAppContent` ويكسر التوريث من غير ما حد يقصد.
     *
     * @param  array<string, mixed>  $all  كل الإعدادات المقروءة
     * @return array<string, mixed>|null   `pilotAppContent` بعد التوريث
     */
    /**
     * أرقام الهاتف اللي جوه القيمة — بعد تطبيع الأرقام العربية والفارسية.
     *
     * 🔴 `preg_replace('/\D+/', '', 'ولا اتنين')` بيرجّع فاضي — لأن `\d`
     * في PCRE أرقام لاتينية بس. يعني رقم متكتوب بالكيبورد العربي
     * («٠١٠٠٠٠٠٠٠٠٠») كان بيتعامل على إنه **مفيش رقم**: السيرفر يورّث
     * فوقه، واللوحة تفضّي الخانة قدام عين المدير، وأول حفظ بعدها يمسحه
     * من القاعدة نهائي. (اتكشفت في مراجعة 2026-08-29 بتشغيل PHP فعلي.)
     */
    private static function digitsOf(mixed $v): string
    {
        $s = (string) $v;
        $map = [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ];

        return preg_replace('/\D+/', '', strtr($s, $map)) ?? '';
    }

    /** فيه رقم فعلي في القيمة دي؟ (نص أو قايمة من نصوص/كائنات) */
    private static function hasNumbers(mixed $v): bool
    {
        if (is_array($v)) {
            foreach ($v as $x) {
                $n = is_array($x) ? ($x['n'] ?? $x['number'] ?? '') : $x;
                if (self::digitsOf($n) !== '') {
                    return true;
                }
            }

            return false;
        }

        return self::digitsOf($v) !== '';
    }

    /**
     * أرقام دعم الطيار الفاضية بتورّث أرقام الموقع — **قناة قناة**.
     *
     * ═══ المشكلة ═══
     * الأرقام متخزّنة في مكانين: `site.info` (الموقع · تطبيق العميل ·
     * بوابة المحلات) و`pilotAppContent.support` (تطبيق الطيار). التاني
     * عمره ما اتملى، فالطيار كان بيفتح «تواصل مع الدعم» ويلاقي «لسه ما
     * اتظبطتش» والأرقام موجودة في اللوحة طول الوقت.
     *
     * ═══ ليه قناة قناة مش «كل أو لا شيء» ═══
     * 🔴 أول نسخة كانت بتفحص القنّاتين مع بعض: أي رقم في أي منهم بيقفل
     * التوريث على الاتنين. يعني المدير يحط رقم **واتساب** لدعم الطيارين
     * ← التوريث بيتقفل ← أرقام **التليفون** الموروثة بتختفي من التطبيق
     * من غير ولا رسالة، واللوحة بتخفي التلميحة الخضرا فمافيش أي إشارة إن
     * هو اللي كسرها. دلوقتي كل قناة بتتحاسب لوحدها.
     *
     * ═══ ليه توريث مش نسخ ═══
     * لو نسخنا الأرقام مرة واحدة، أول ما الإدارة تغيّر رقم الموقع تفضل
     * النسخة القديمة عند الطيار للأبد ومحدش ياخد باله.
     *
     * ═══ ليه في السيرفر مش في التطبيق ═══
     * نفس سبب إصلاح المحفظة: بيوصل **كل** الطيارين في نفس اللحظة، حتى
     * اللي مانزّلش نسخة جديدة.
     *
     * ═══ `inherited` ═══
     * **قايمة** بأسماء القنوات الموروثة (`['phone','whatsapp']`) — للوحة
     * التحكم بس. بتقولها «القنوات دي مش متكتوبة، دي موروثة» فتسيب
     * خاناتها فاضية. من غيرها كانت الخانات هتتملى في شاشة التعديل، وأول
     * حفظ كان هينسخهم فعلًا ويكسر التوريث من غير ما حد يقصد.
     * تطبيق الطيار مابيقراش المفتاح ده أصلًا.
     *
     * @param  array<string, mixed>  $all  كل الإعدادات المقروءة
     * @return array<string, mixed>|null   `pilotAppContent` بعد التوريث
     */
    private static function inheritPilotSupport(array $all): ?array
    {
        $content = is_array($all['pilotAppContent'] ?? null) ? $all['pilotAppContent'] : [];
        $support = is_array($content['support'] ?? null) ? $content['support'] : [];
        $info    = is_array($all['site']['info'] ?? null) ? $all['site']['info'] : [];

        $inherited = [];
        // [اسم القناة, المفتاح المفرد, مفتاح القايمة]
        foreach ([['phone', 'phone', 'phones'], ['whatsapp', 'whatsapp', 'whatsapps']] as [$ch, $one, $many]) {
            if (self::hasNumbers($support[$one] ?? '') || self::hasNumbers($support[$many] ?? [])) {
                continue;   // متكتوبة بالإيد — مابنلمسهاش
            }
            if (! self::hasNumbers($info[$one] ?? '') && ! self::hasNumbers($info[$many] ?? [])) {
                continue;   // الموقع نفسه مالوش أرقام في القناة دي
            }
            $support[$one]  = $info[$one] ?? '';
            $support[$many] = $info[$many] ?? [];
            $inherited[]    = $ch;
        }

        if (! $inherited) {
            return $content ?: null;
        }
        $support['inherited'] = $inherited;
        $content['support']   = $support;

        return $content;
    }

    /**
     * GET /api/settings/site — بيانات الموقع العامة **بلا مصادقة**.
     *
     * تلات مفاتيح بس: تواصل/محتوى الموقع، ساعات الشغل، وبانر تطبيق
     * العميل. الموقع التسويقي وشاشة العميل قبل الدخول بينادوها، فمفيش
     * `require_auth` — وعشان كده القايمة البيضا دي مقفولة على التلاتة
     * دول بالاسم بدل ما ترجّع كل الإعدادات.
     *
     * ⚠️ فرق مقصود عن GET /api/settings: هنا **مفيش رجوع للنص الخام** —
     * أي قيمة مش JSON صالح بترجع null. سلوك الأصل بالحرف.
     */
    public function settingsSite(): JsonResponse
    {
        $keys = ['site', 'workHours', 'customerBanner'];
        $ph = implode(',', array_fill(0, count($keys), '?'));

        $out = [];
        foreach (DB::select(
            "SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($ph)",
            $keys
        ) as $r) {
            $out[$r->setting_key] = json_decode((string) $r->setting_value, true);
        }

        return ApiResponse::ok(['site' => $out]);
    }

    /* ═══════════════════════════════════════════════════════════
       شركاء الموقع التسويقي
    ═══════════════════════════════════════════════════════════ */

    /**
     * GET /api/partners — **عام بلا مصادقة** (الموقع التسويقي بيعرضهم
     * قبل أي دخول).
     *
     * ⚠️ الاستعلام مافيهوش `WHERE is_active = 1` — يعني الشريك المخفي
     * بيطلع في الرد العام برضه. شكله باج في الأصل، بس الترحيل ده صفر
     * تغيير سلوك فمنقول زي ما هو. (اتسجّل في notes)
     */
    public function partnersList(): JsonResponse
    {
        return $this->legacyList(array_map(
            fn ($r) => SupportWire::partner($r),
            DB::select('SELECT * FROM site_partners ORDER BY sort_order, id')
        ));
    }

    /* ═══════════════════════════════════════════════════════════
       مسارات الكتابة — الشكاوى
    ═══════════════════════════════════════════════════════════ */

    /**
     * POST /api/complaints — {orderId?, orderNum?, customer, phone?, type, details}
     *
     * 🔒 `role:admin,callcenter,branch` — **من غير `hr`** (على عكس مسار
     * القراءة اللي فيه hr). شؤون العاملين بتراجع الشكاوى ومابتفتحش. الفرق
     * ده في الأصل ومنقول زي ما هو.
     *
     * ⚠️ `orderId` بيتحوّل لـ`null` لو مش مطابق أوردر فعلي **بدل ما يرفض**:
     * الموظف بيكتب رقم الأوردر من كلام العميل في التليفون، فالرقم الغلط
     * بيتحفظ نصًا في `orderNum` والربط بيفضل فاضي. قصده يفضل السجل موجود.
     *
     * `type` غير المعروف بيتحوّل `other` — تطبيع مش تحقق (سلوك الأصل).
     */
    public function complaintsCreate(Request $request): JsonResponse
    {
        $user = $request->actorOrFail();
        $b    = $this->body($request);

        $customer = trim((string) ($b['customer'] ?? ''));
        $details  = trim((string) ($b['details'] ?? ''));
        if ($customer === '') {
            throw new ApiException('اسم العميل مطلوب');
        }
        if ($details === '') {
            throw new ApiException('تفاصيل الشكوى مطلوبة');
        }

        $allowedTypes = ['late', 'damaged', 'wrong_addr', 'behavior', 'price', 'not_received', 'other'];
        $type = in_array($b['type'] ?? '', $allowedTypes, true) ? $b['type'] : 'other';

        $orderId = isset($b['orderId']) && $b['orderId'] !== null && $b['orderId'] !== '' ? (int) $b['orderId'] : null;
        if ($orderId !== null) {
            // رقم مدخل يدوي مش لازم يطابق أوردر فعلي
            if (! DB::select('SELECT id FROM orders WHERE id = ?', [$orderId])) {
                $orderId = null;
            }
        }

        DB::insert(
            "INSERT INTO cc_complaints (order_id, order_num, customer_name, phone, type, details, status, created_by, created_at)
             VALUES (?,?,?,?,?,?,'open',?,?)",
            [
                $orderId,
                isset($b['orderNum']) ? trim((string) $b['orderNum']) : null,
                $customer,
                isset($b['phone']) ? trim((string) $b['phone']) : null,
                $type,
                $details,
                $user->username,
                WireTime::nowDb(),
            ]
        );
        $id = (int) DB::getPdo()->lastInsertId();

        return ApiResponse::ok(['complaint' => SupportWire::complaint($this->complaintRow($id))]);
    }

    /**
     * POST /api/complaints/{id}/resolve — {resolution}
     *
     * 🔒 `role:admin,callcenter` بس — **أضيق من الإنشاء** (مفيش branch).
     * الفرع بيفتح شكوى ومابيقفلهاش، عشان مايقفلش شكوى على نفسه.
     *
     * الشرط `AND status = 'open'` جوه الـUPDATE هو القفل الحقيقي: نداءين
     * متوازيين على نفس الشكوى، واحد بس بيغيّر صف والتاني بياخد 404 —
     * فمفيش دهس على قرار حل موجود. عشان كده مفيش `SELECT` قبله ولا معاملة.
     */
    public function complaintsResolve(Request $request, string $id): JsonResponse
    {
        $user = $request->actorOrFail();
        $b    = $this->body($request);

        $resolution = trim((string) ($b['resolution'] ?? ''));
        if ($resolution === '') {
            throw new ApiException('يجب كتابة الإجراء اللي اتعمل لحل الشكوى');
        }

        $affected = DB::update(
            "UPDATE cc_complaints SET status = 'resolved', resolution = ?, resolved_by = ?, resolved_at = ?
             WHERE id = ? AND status = 'open'",
            [$resolution, $user->username, WireTime::nowDb(), (int) $id]
        );
        if ($affected === 0) {
            throw ApiException::notFound('الشكوى غير موجودة أو اتحلت قبل كده');
        }

        return ApiResponse::ok(['complaint' => SupportWire::complaint($this->complaintRow((int) $id))]);
    }

    /* ═══════════════════════════════════════════════════════════
       مسارات الكتابة — رسايل «اتصل بنا»
    ═══════════════════════════════════════════════════════════ */

    /**
     * PUT /api/contact-messages/{id}/read — بلا جسم.
     *
     * 🔒 `role:admin` بس — نفس ضيق القايمة، لنفس السبب.
     *
     * 🔴 **متعمّد إنها idempotent** — على عكس `complaintsResolve()` فوق.
     * هناك «حل الشكوى» قرار بيدهس قرار تاني، فالنداء التاني بياخد 404.
     * هنا «فتحت الرسالة» مش قرار — الأدمن بيفتح نفس الرسالة عشر مرات وهو
     * بيرد عليها، و404 على الفتحة التانية كانت هتبقى رسالة خطأ في وش
     * المستخدم على حاجة سليمة تمامًا. فالنداء المكرر بيرجّع نفس الصف 200.
     *
     * القفل الحقيقي هو الشرط `AND read_at IS NULL` جوه الـUPDATE: نداءين
     * متوازيين على نفس الرسالة، واحد بس بيغيّر صف — فـ`read_by` و`read_at`
     * بيفضلوا على **أول** واحد فتحها، مش آخر واحد. ده المفيد إداريًا (مين
     * شاف الرسالة الأول)، وهو السبب إن التحديث مشروط مش مطلق.
     *
     * الاستعلامين جوه `DB::transaction` عشان الصف اللي بيترجّع يبقى هو
     * نفسه اللي اتكتب — من غيرها ممكن نداء تاني يعدّل بينهم فيرجّع الرد
     * حالة مش اللي إحنا كتبناها.
     *
     * ⚠️ الترتيب: UPDATE قبل SELECT. عكسه (قراءة → قرار → كتابة) بيفتح
     * نافذة سباق بين القراءة والكتابة، والشرط في الـUPDATE بيغني عنها.
     */
    public function contactMessageRead(Request $request, string $id): JsonResponse
    {
        $user = $request->actorOrFail();

        return DB::transaction(function () use ($user, $id): JsonResponse {
            DB::update(
                "UPDATE contact_messages SET status = 'read', read_by = ?, read_at = ?
                 WHERE id = ? AND read_at IS NULL",
                [$user->username, WireTime::nowDb(), (int) $id]
            );

            /* بنقرا بعد التحديث على طول: الصف الراجع فيه القيم المحسوبة
               (وقت القراءة اللي اتكتب دلوقتي، أو بتاع أول واحد فتحها).
               `affected === 0` **مش** خطأ لوحده — يا إما الرسالة مقروءة
               قبل كده (سليم) يا إما مش موجودة، والفرق بيتحدد من هنا. */
            $row = DB::select('SELECT * FROM contact_messages WHERE id = ?', [(int) $id])[0] ?? null;
            if (! $row) {
                throw ApiException::notFound('الرسالة غير موجودة');
            }

            return ApiResponse::ok(['message' => ContactWire::message($row)]);
        });
    }

    /* ═══════════════════════════════════════════════════════════
       مسارات الكتابة — طلبات المناطق غير المعرفة
    ═══════════════════════════════════════════════════════════ */

    /**
     * POST /api/zone-requests — {areaName, branchId?, lastPrice?, orderNum?}
     *
     * 🔒 `role:admin,callcenter,branch`.
     *
     * 🔴 **نفس المنطقة لنفس الفرع وهي pending → زيادة العدّاد بدل صف جديد.**
     * الأصل بيعمل `beginTransaction` + `SELECT ... FOR UPDATE` صراحةً، وده
     * السبب: من غير القفل، طلبين متوازيين على نفس المنطقة الاتنين بيلاقوا
     * «مفيش صف» ويدخّلوا صفّين، فالعدّاد اللي اللوحة بتفرز بيه بيتفتّت.
     *
     * ⚠️ الشرط `branch_id IS NULL` مقابل `branch_id = ?` **متبني في نص الـSQL**
     * مش بباراميتر — لأن `NULL = ?` بترجّع NULL في SQL مهما كانت القيمة،
     * فالمطابقة على المنطقة بلا فرع كانت هتفشل دايمًا وتدخّل صف جديد كل مرة.
     *
     * `COALESCE(?, last_price)` في التحديث: السعر/الرقم المش مبعوت **مايمسحش**
     * القيمة القديمة — بس المبعوت بيدوس عليها.
     */
    public function zoneRequestsCreate(Request $request): JsonResponse
    {
        $user = $request->actorOrFail();
        $b    = $this->body($request);

        $areaName = trim((string) ($b['areaName'] ?? ''));
        if ($areaName === '') {
            throw new ApiException('اسم المنطقة مطلوب');
        }
        $branchId  = isset($b['branchId']) && $b['branchId'] !== null && $b['branchId'] !== '' ? (int) $b['branchId'] : null;
        $lastPrice = isset($b['lastPrice']) && $b['lastPrice'] !== null && $b['lastPrice'] !== '' ? (float) $b['lastPrice'] : null;
        $orderNum  = isset($b['orderNum']) ? trim((string) $b['orderNum']) : null;

        $id = DB::transaction(function () use ($areaName, $branchId, $lastPrice, $orderNum, $user): int {
            $row = DB::select(
                "SELECT id FROM cc_zone_requests
                 WHERE area_name = ? AND status = 'pending' AND "
                . ($branchId === null ? 'branch_id IS NULL' : 'branch_id = ?')
                . ' FOR UPDATE',
                $branchId === null ? [$areaName] : [$areaName, $branchId]
            )[0] ?? null;

            if ($row) {
                DB::update(
                    'UPDATE cc_zone_requests
                     SET request_count = request_count + 1, last_at = ?, last_by = ?,
                         last_price = COALESCE(?, last_price), last_order_num = COALESCE(?, last_order_num)
                     WHERE id = ?',
                    [WireTime::nowDb(), $user->username, $lastPrice, $orderNum, (int) $row->id]
                );

                return (int) $row->id;
            }

            // `$now` واحد للـfirst_at والـlast_at سوا — زي الأصل بالحرف
            $now = WireTime::nowDb();
            DB::insert(
                "INSERT INTO cc_zone_requests
                   (area_name, branch_id, last_price, request_count, status, first_at, last_at, last_order_num, requested_by, last_by)
                 VALUES (?,?,?,1,'pending',?,?,?,?,?)",
                [$areaName, $branchId, $lastPrice, $now, $now, $orderNum, $user->username, $user->username]
            );

            return (int) DB::getPdo()->lastInsertId();
        });

        // القراءة **بعد** الـcommit زي الأصل — الصف مقروء بحالته النهائية
        $row = DB::select('SELECT * FROM cc_zone_requests WHERE id = ?', [$id])[0] ?? null;

        return ApiResponse::ok(['request' => SupportWire::zoneRequest($row)]);
    }

    /**
     * POST /api/zone-requests/{id}/mark-added — بعد إضافة الزون رسميًا
     *
     * 🔒 `role:admin,callcenter` — أضيق من الإنشاء (مفيش branch).
     * الشرط `AND status = 'pending'` هو نفس نمط قفل الشكوى: تعليم مرتين =
     * 404 على التانية بدل دهس صامت.
     *
     * ⚠️ الرد `{ok:true}` **بس** — مفيش الصف المحدّث (على عكس complaints/resolve).
     * التفاوت ده في الأصل ومنقول زي ما هو.
     */
    public function zoneRequestsMarkAdded(Request $request, string $id): JsonResponse
    {
        $request->actorOrFail();

        $affected = DB::update(
            "UPDATE cc_zone_requests SET status = 'added' WHERE id = ? AND status = 'pending'",
            [(int) $id]
        );
        if ($affected === 0) {
            throw ApiException::notFound('طلب المنطقة غير موجود أو اتقفل قبل كده');
        }

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       مسارات الكتابة — الإعدادات
    ═══════════════════════════════════════════════════════════ */

    /**
     * PUT /api/settings — {key: value, ...} · 🔒 `role:admin` بس.
     *
     * الجسم نفسه **هو** خريطة الإعدادات (مش مغلّف في مفتاح) — كل مفتاح
     * بيتعمله upsert لوحده. القيمة بتتخزّن `json_encode(..., UNESCAPED_UNICODE)`
     * عشان النصوص العربية تفضل خام في العمود (نفس سبب علم الرد).
     *
     * حد الـ60 حرف للمفتاح هو طول عمود `setting_key` — الأطول كان هيتقص
     * صامت في MySQL غير الصارم ويدهس مفتاح تاني.
     *
     * ⚠️ **فرق واحد مقصود عن الأصل**: الحلقة هنا جوه `DB::transaction`.
     * الأصل كان autocommit، فمفتاح غلط في نص القايمة كان بيسيب المفاتيح
     * اللي قبله **متكتوبة** والرد 400. هنا بيترجعوا. الرد (النص والكود)
     * مطابق حرفيًا؛ الفرق في الأثر الجانبي بس. (متسجّل في notes)
     */
    public function settingsPut(Request $request): JsonResponse
    {
        $user = $request->actorOrFail();
        $b    = $this->body($request);

        if (! is_array($b) || ! $b) {
            throw new ApiException('لا توجد إعدادات للحفظ');
        }

        $now = WireTime::nowDb();

        DB::transaction(function () use ($b, $user, $now): void {
            foreach ($b as $key => $value) {
                $key = trim((string) $key);
                if ($key === '' || strlen($key) > 60) {
                    throw new ApiException('مفتاح إعداد غير صالح: ' . $key);
                }
                DB::insert(
                    'INSERT INTO site_settings (setting_key, setting_value, updated_by, updated_at)
                     VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                             updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
                    [$key, json_encode($value, JSON_UNESCAPED_UNICODE), $user->username, $now]
                );
            }
        });

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       مسارات الكتابة — شركاء الموقع التسويقي
    ═══════════════════════════════════════════════════════════ */

    /** POST /api/partners — {name, desc?, url?, logo?, order?} · 🔒 `role:admin` */
    public function partnersCreate(Request $request): JsonResponse
    {
        $request->actorOrFail();
        $b = $this->body($request);

        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            throw new ApiException('اسم الشريك مطلوب');
        }

        DB::insert(
            'INSERT INTO site_partners (name, description, url, logo_url, sort_order, created_at) VALUES (?,?,?,?,?,?)',
            [
                $name,
                isset($b['desc']) ? trim((string) $b['desc']) : null,
                isset($b['url']) ? trim((string) $b['url']) : null,
                isset($b['logo']) ? trim((string) $b['logo']) : null,
                (int) ($b['order'] ?? 0),
                WireTime::nowDb(),
            ]
        );
        $id = (int) DB::getPdo()->lastInsertId();

        return ApiResponse::ok(['partner' => SupportWire::partner($this->partnerRow($id))]);
    }

    /**
     * PUT /api/partners/{id} · 🔒 `role:admin`
     *
     * تعديل جزئي بـ`array_key_exists` — المفتاح المش مبعوت بياخد قيمته
     * القديمة من الصف.
     *
     * ⚠️ **باج منقول زي ما هو**: `name` بيتقرا بـ`?? $row['name']` مش
     * بـ`array_key_exists` — يعني `{"name": ""}` بيمسح اسم الشريك لسلسلة
     * فاضية من غير ما يترفض (على عكس الإنشاء اللي بيرفض الاسم الفاضي).
     * وكمان `{"name": null}` بيرجّع الاسم القديم بدل ما يمسحه.
     */
    public function partnersUpdate(Request $request, string $id): JsonResponse
    {
        $request->actorOrFail();
        $b = $this->body($request);

        $row = DB::select('SELECT * FROM site_partners WHERE id = ?', [(int) $id])[0] ?? null;
        if (! $row) {
            throw ApiException::notFound('الشريك غير موجود');
        }
        $r = (array) $row;

        DB::update(
            'UPDATE site_partners SET name = ?, description = ?, url = ?, logo_url = ?, sort_order = ? WHERE id = ?',
            [
                trim((string) ($b['name'] ?? $r['name'])),
                array_key_exists('desc', $b) ? trim((string) $b['desc']) : $r['description'],
                array_key_exists('url', $b) ? trim((string) $b['url']) : $r['url'],
                array_key_exists('logo', $b) ? trim((string) $b['logo']) : $r['logo_url'],
                array_key_exists('order', $b) ? (int) $b['order'] : (int) $r['sort_order'],
                (int) $id,
            ]
        );

        return ApiResponse::ok(['partner' => SupportWire::partner($this->partnerRow((int) $id))]);
    }

    /**
     * DELETE /api/partners/{id} · 🔒 `role:admin`
     * حذف حقيقي (مش تعطيل) — والصف المش موجود 404.
     */
    public function partnersDelete(Request $request, string $id): JsonResponse
    {
        $request->actorOrFail();

        $affected = DB::delete('DELETE FROM site_partners WHERE id = ?', [(int) $id]);
        if ($affected === 0) {
            throw ApiException::notFound('الشريك غير موجود');
        }

        return ApiResponse::ok();
    }

    /* ═══════════════════════════════════════════════════════════
       مسارات الكتابة — تقييم الأوردر وخصم المحفظة
    ═══════════════════════════════════════════════════════════ */

    /**
     * POST /api/orders/{id}/rating — {stars: 1..5, note?}
     *
     * 🔒 المسار **مالوش middleware أدوار** عن قصد: الأصل بيفحص الجلسة بإيده
     * الأول (`$_SESSION['role'] === 'customer'`) وبعدين بينده `require_role`
     * في الفرع التاني بس. فالتقييد بيتم جوه الكنترولر:
     *   • جلسة عميل التطبيق → `customer` (أوردره هو + بعد التسليم)
     *   • غير كده → `admin/callcenter/branch/store` (403 لأي دور تاني)
     * التعليق الأصلي: «كانت مقفولة على الموظفين والمحلات — اتفتحت لجلسة
     * العميل لرحلة تطبيق العميل».
     *
     * ⚠️ النجوم هنا `(int)($b['stars'] ?? 0)` — **مش** `trust_req_stars`
     * المصلّحة. يعني 3.5 بتتقبل وتتحوّل صامتة لـ3. التفاوت ده في الأصل
     * ومنقول زي ما هو (متسجّل في notes).
     *
     * تقييم واحد لكل مصدر: الـDELETE قبل الـINSERT جوه معاملة — الجديد
     * بيحل محل القديم بدل ما يتراكم، والمعاملة بتمنع لحظة «مفيش تقييم».
     */
    public function orderRating(Request $request, string $id): JsonResponse
    {
        $b = $this->body($request);

        $stars = (int) ($b['stars'] ?? 0);
        if ($stars < 1 || $stars > 5) {
            throw new ApiException('التقييم من 1 لـ 5 نجوم');
        }
        $note = isset($b['note']) ? trim((string) $b['note']) : '';

        $row = DB::select('SELECT * FROM orders WHERE id = ?', [(int) $id])[0] ?? null;
        if (! $row) {
            throw ApiException::notFound('الأوردر غير موجود');
        }
        $order = (array) $row;

        // «تم التسليم» بتتحوّل لكود القاعدة عبر القاموس — نفس status_to_code()
        $deliveredCode = Vocab::statusToCode('تم التسليم');

        if ($request->session()->get('role') === 'customer' && $request->session()->get('customer_id')) {
            // جلسة عميل التطبيق — بيقيّم أوردره هو بس وبعد التسليم
            $c = $this->customerRequire($request);
            if ((int) ($order['customer_id'] ?? 0) !== (int) $c['id']) {
                throw ApiException::forbidden('لا يمكنك تقييم أوردر لا يخصك');
            }
            if ($order['status'] !== $deliveredCode) {
                throw new ApiException('التقييم متاح بعد تسليم الأوردر');
            }
            $rater     = 'customer';
            $ratedBy   = $c['display_name'] ?: ($c['email'] ?? 'عميل');
            // الهوية القديمة (مفتاح Firebase) لو موجودة — وإلا الـid الرقمي
            $ratedById = $c['legacy_key'] ?: (string) $c['id'];
        } else {
            $user = $request->actorOrFail();
            if (! $user->hasRole('admin', 'callcenter', 'branch', 'store')) {
                throw ApiException::forbidden();
            }
            /* 🔒 مشرف الفرع كان بيقيّم — وبيقرا — **أي** أوردر في الشركة.
               المسار بيرجّع `OrderWire::full` (تليفونات المستلم وعنوانه
               ودبوسه والأسعار والتحصيل)، فكان قناة قراءة كاملة بحجة
               التقييم. الحارس على فرع الأوردر زي باقي مسارات الأوردر. */
            if ($user->role === 'branch') {
                $mine = (int) ($user->branchId ?? 0);
                if ($mine === 0 || $mine !== (int) ($order['branch_id'] ?? 0)) {
                    throw ApiException::forbidden('الأوردر ده مش تابع لفرعك');
                }
            }
            // أي موظف غير المحل بيتسجّل `support` — الدعم بيقيّم نيابة عن العميل
            $rater = $user->role === 'store' ? 'store' : 'support';
            if ($rater === 'store') {
                if (($order['added_by'] ?? '') !== $user->username) {
                    throw ApiException::forbidden('لا يمكنك تقييم أوردر لا يخصك');
                }
                if ($order['status'] !== $deliveredCode) {
                    throw new ApiException('التقييم متاح بعد تسليم الأوردر');
                }
            }
            $ratedBy   = $user->name;
            $ratedById = (string) $user->userId;
        }

        DB::transaction(function () use ($id, $rater, $stars, $note, $ratedBy, $ratedById): void {
            // تقييم واحد لكل مصدر — الجديد بيحل محل القديم
            DB::delete('DELETE FROM order_ratings WHERE order_id = ? AND rater = ?', [(int) $id, $rater]);
            DB::insert(
                'INSERT INTO order_ratings (order_id, rater, stars, note, rated_by, rated_by_id, rated_at)
                 VALUES (?,?,?,?,?,?,?)',
                [(int) $id, $rater, $stars, $note, $ratedBy, $ratedById, WireTime::nowDb()]
            );
        });

        return ApiResponse::ok(['order' => OrderWire::full((int) $id)]);
    }

    /**
     * POST /api/orders/{id}/apply-wallet — خصم المحفظة من سعر التوصيل (ذري)
     * 🔒 `role:admin,store,customer`.
     *
     * 🔴 **فلوس + قفلين.** ترتيب الأقفال جزء من الصح وممنوع يتغيّر:
     *   1. `SELECT ... FROM orders ... FOR UPDATE` — صف الأوردر
     *   2. `lockWallet()` — صف المحفظة (`FOR UPDATE` كمان)
     * الترتيب ده (أوردر ← محفظة) هو نفسه في كل المسارات اللي بتلمس
     * الاتنين، وعكسه في مسار واحد بس كان بيدّي deadlock تحت التزامن.
     *
     * الحسابات منقولة بالحرف:
     *   • `$use = min($balance, $price)` — **من غير round()**.
     *   • `$newBalance = round($balance - $use, 2)` — التقريب على الناتج بس،
     *     نفس قاعدة `walletMove` في FinanceController.
     *   • `wallet_used` بيتخزّن `$use` غير مقرّب — والـcolumn DECIMAL هو اللي
     *     بيقرّب. `deliver()` بعدين بتحسب الصافي من العمود ده.
     *   • السجل بياخد `-$use` (بالإشارة) و`type='use'`.
     *
     * البوابات قبل الخصم: `wallet_used > 0` (مرة واحدة بس) و`money_settled`
     * (بعد التسوية الفلوس اتقفلت، فالخصم كان هيبوّظ كشف حساب مقفول).
     */
    public function applyWallet(Request $request, string $id): JsonResponse
    {
        $user = $request->actorOrFail();

        [$use, $newBalance] = DB::transaction(function () use ($request, $user, $id): array {
            $row = DB::select('SELECT * FROM orders WHERE id = ? FOR UPDATE', [(int) $id])[0] ?? null;
            if (! $row) {
                throw ApiException::notFound('الأوردر غير موجود');
            }
            $order = (array) $row;

            if ($user->role === 'customer') {
                // عميل التطبيق — محفظته هو على أوردراته هو
                $cid = (int) ($request->session()->get('customer_id') ?? 0);
                if (! $cid || (int) ($order['customer_id'] ?? 0) !== $cid) {
                    throw ApiException::forbidden('لا يمكنك استخدام المحفظة على أوردر لا يخصك');
                }
                $walletOwnerType = 'customer';
                $walletOwnerId   = $cid;
            } else {
                // المحل بيخصم من محفظته على أوردراته هو — الأدمن بيقدر يطبّق
                // لأي محل صاحب الأوردر
                $ownerUsername = $order['added_by'] ?? '';
                if ($user->role === 'store' && $ownerUsername !== $user->username) {
                    throw ApiException::forbidden('لا يمكنك استخدام المحفظة على أوردر لا يخصك');
                }
                $ownerRow = DB::select(
                    "SELECT id FROM users WHERE username = ? AND role = 'store'",
                    [$ownerUsername]
                )[0] ?? null;
                if (! $ownerRow) {
                    // 400 مش 404 — الأوردر موجود، بس مالوش محفظة محل أصلًا
                    throw new ApiException('الأوردر ده مش تابع لحساب محل');
                }
                $walletOwnerType = 'store';
                $walletOwnerId   = (int) $ownerRow->id;
            }

            // مقارنة مرنة على نص decimal جاي من القاعدة — `(float)` قبلها زي الأصل
            if ((float) $order['wallet_used'] > 0) {
                throw new ApiException('المحفظة مستخدمة على الأوردر ده بالفعل');
            }
            if ((int) $order['money_settled'] === 1) {
                throw new ApiException('الأوردر اتسوى ماليًا — مفيش خصم بعد التسوية');
            }

            $wallet  = $this->lockWallet($walletOwnerType, $walletOwnerId);
            $balance = (float) $wallet['balance'];
            $price   = (float) $order['total_delivery_price'];
            $use     = min($balance, $price);
            if ($use <= 0) {
                throw new ApiException('رصيد المحفظة لا يسمح بالخصم');
            }

            $newBalance = round($balance - $use, 2);

            // ترتيب الكتابات حرفي: المحفظة ← السجل ← الأوردر
            DB::update('UPDATE wallets SET balance = ? WHERE id = ?', [$newBalance, (int) $wallet['id']]);
            DB::insert(
                "INSERT INTO wallet_transactions (wallet_id, amount, type, note, order_num, balance_after, created_by, created_at)
                 VALUES (?,?,'use',?,?,?,?,?)",
                [
                    (int) $wallet['id'],
                    -$use,
                    'استخدام المحفظة في أوردر',
                    $order['order_num'],
                    $newBalance,
                    $user->username,
                    WireTime::nowDb(),
                ]
            );
            // لمسة updated_at عشان delta polling يشوف تغيير المبلغ على الأوردر
            DB::update('UPDATE orders SET wallet_used = ?, updated_at = NOW(3) WHERE id = ?', [$use, (int) $id]);

            /* نفس حالة `update()` و`addImages()` بالحرف: اللمسة فوق متعمّدة
               عشان الاستطلاع يشوف التغيير — فالبثّ لازم يشوفه كمان، وإلا
               يبقى الدفع أضيق من الاستطلاع. (فجوة اتكشفت في مراجعة 2026-08-20) */
            $this->broadcastOrder((int) $id);

            return [$use, $newBalance];
        });

        return ApiResponse::out([
            'ok'            => true,
            'walletUsed'    => $use,
            'walletBalance' => $newBalance,
            'order'         => OrderWire::full((int) $id),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       الغلاف القديم
    ═══════════════════════════════════════════════════════════ */

    /**
     * غلاف قوايم support.php — `{ok, changed, items}` **من غير serverNow**.
     * مكتوب هنا بدل `PollableList::items()` عن قصد: الأخيرة بتضيف
     * `serverNow` والفاحص التفاضلي بيقارن حرف بحرف.
     */
    private function legacyList(array $items): JsonResponse
    {
        return ApiResponse::out([
            'ok'      => true,
            'changed' => true,
            'items'   => $items,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       أدوات الكتابة
    ═══════════════════════════════════════════════════════════ */

    /**
     * المقابل لـ body_json(): المصفوفة الخام مش مصدر مدخلات مدموج.
     *
     * `$request->json()->all()` مش `input()` عن قصد: `partnersUpdate`
     * بيستخدم `array_key_exists()` عشان يفرّق بين «الحقل مش متبعت» و«متبعت
     * فاضي»، و`settingsPut` بياخد **الجسم نفسه** كخريطة مفاتيح.
     * (`TolerantJsonBody` ضامن إن الجسم الفاضي أو المكسور = `[]`.)
     */
    private function body(Request $request): array
    {
        return $request->json()->all();
    }

    /** صف الشكوى بعد الكتابة — الأصل بيعيد قراءته عشان يرجّع القيم المحسوبة */
    private function complaintRow(int $id): object
    {
        return DB::select('SELECT * FROM cc_complaints WHERE id = ?', [$id])[0];
    }

    /** صف الشريك بعد الكتابة */
    private function partnerRow(int $id): object
    {
        return DB::select('SELECT * FROM site_partners WHERE id = ?', [$id])[0];
    }

    /**
     * صف العميل من الجلسة — المقابل لـ customer_require() في customer_app.php.
     *
     * ⚠️ نسخة مختصرة عن اللي في `CustomerAppController` لأن `support_order_rating`
     * بينده الأصلية بلا `$allowBlocked` — يعني المحظور بيترفض بـ403 برسالة
     * **«حسابك موقوف مؤقتًا — كلّم خدمة العملاء»** مش رسالة `require_auth`.
     *
     * 🔴 ملاحظة نقل مهمة: على لارافل الفرع ده **مش قابل للوصول عمليًا** —
     * `ResolveApiActor` بيقتل جلسة العميل المحظور قبل الكنترولر برسالة
     * «هذا الحساب موقوف — تواصل مع الإدارة» 403، لأن المسار ده مش
     * `api/customer/*`. الأصل مكانش بينده `require_auth()` هنا خالص فكان
     * بيوصل للرسالة التانية. اتساب هنا حرفيًا وموثّق في notes.
     */
    private function customerRequire(Request $request): array
    {
        $cid = (int) $request->session()->get('customer_id');
        if (! $cid) {
            throw new ApiException('يجب تسجيل الدخول أولًا', 401);
        }

        $row = DB::select('SELECT * FROM customers WHERE id = ? LIMIT 1', [$cid])[0] ?? null;
        if (! $row) {
            throw new ApiException('يجب تسجيل الدخول أولًا', 401);
        }
        $c = (array) $row;

        if ((int) $c['blocked'] === 1) {
            throw new ApiException('حسابك موقوف مؤقتًا — كلّم خدمة العملاء', 403);
        }

        return $c;
    }

    /**
     * 💰 المقابل لـ finance_lock_wallet(): صف المحفظة مقفول — **وبينشئها
     * برصيد صفر لو مش موجودة**. لازم يتندى جوه معاملة مفتوحة.
     *
     * نسخة مطابقة للي في `FinanceController::lockWallet()` (الأصل دالة عامة
     * مشتركة في finance.php وsupport.php بينده عليها). متكرّرة هنا بدل ما
     * تتشال هناك عشان مانلمسش كنترولر وكيل تاني.
     *
     * 🔴 `INSERT IGNORE` مش «تكاسل»: هو اللي بيمتص سباق الإنشاء المتزامن على
     * الفهرس الفريد `uq_wallets_owner`. والـSELECT التاني **بـFOR UPDATE
     * برضه** — من غيره كنا هنمسك صف غير مقفول ونحسب عليه رصيد.
     */
    private function lockWallet(string $ownerType, int $ownerId): array
    {
        $sql = 'SELECT * FROM wallets WHERE owner_type = ? AND owner_id = ? FOR UPDATE';

        $wallet = DB::select($sql, [$ownerType, $ownerId])[0] ?? null;
        if ($wallet) {
            return (array) $wallet;
        }

        DB::insert(
            'INSERT IGNORE INTO wallets (owner_type, owner_id, balance, created_at) VALUES (?,?,0,?)',
            [$ownerType, $ownerId, WireTime::nowDb()]
        );

        $wallet = DB::select($sql, [$ownerType, $ownerId])[0] ?? null;
        if (! $wallet) {
            throw new ApiException('تعذر إنشاء المحفظة', 500);
        }

        return (array) $wallet;
    }
}
