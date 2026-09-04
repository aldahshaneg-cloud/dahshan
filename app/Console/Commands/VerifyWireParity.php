<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Wire\CoreWire;
use App\Wire\OrderWire;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * فحص تفاضلي لطبقة السلك: بيحمّل **دوال النظام القديم نفسها**
 * (api/ser_core.php + api/ser_orders.php) وبيقارن مخرجها بمخرج الكلاسات
 * الجديدة على **صفوف حقيقية من قاعدة البيانات**.
 *
 * ليه على صفوف حقيقية مش مصنوعة: الفروق الخطيرة بتظهر في القيم الحدّية
 * اللي بتيجي من القاعدة — NULL في عمود decimal، نص فاضي مقابل null،
 * legacy_key من الترحيل، حالة مش في القاموس. البيانات المصنوعة بتفوّتها.
 *
 * التشغيل: php artisan wire:verify
 */
class VerifyWireParity extends Command
{
    protected $signature = 'wire:verify {--limit=200 : أقصى عدد صفوف لكل كيان}';

    protected $description = 'بيقارن طبقة السلك الجديدة بدوال النظام القديم على بيانات حقيقية';

    /**
     * حقول اتضافت **جوه الطرود** (deliveries[]) — نفس فكرة
     * INTENTIONAL_FIELDS بس على مستوى متداخل، لأن الطرد كائن ابن جوه
     * الأوردر والتنظيف بتاع المستوى الأول مابيوصلوش.
     */
    private const INTENTIONAL_DELIVERY_FIELDS = [
        'receiverFromReceipt' => 'شارة «البيانات على صورة الريسيت» — العمود موجود في المخطط من الأصل والواجهات بتقراه، والسلك مكانش بيبعته',
        'lat' => 'دبوس التسليم — خريطة تتبّع العميل بتحتاجه لنقطة الوصول',
        'lng' => 'دبوس التسليم',
    ];

    /**
     * حقول اتضافت في طبقة السلك الجديدة بعد الترحيل ومالهاش مقابل في
     * الأصل — ميزة جديدة، مش اختلاف ترحيل.
     *
     * المفتاح: اسم الكيان زي ما هو متمرّر لـcheckEntity.
     * القيمة: [اسم الحقل => السبب]. أي سطر جديد هنا لازم يجي معاه سبب
     *         مكتوب — القايمة من غير أسباب بتبقى قايمة تجاهل مش قايمة بيضا.
     *
     * ⚠️ الحقل بيتشال من **الجديد** قبل المقارنة بس. لو الأصل بيبعت حقل
     * والجديد مابيبعتوش، ده بيفضل فشل — وده المقصود: الحذف بيكسر واجهات
     * شغّالة، والإضافة لأ.
     */
    private const INTENTIONAL_FIELDS = [
        'orders' => [
            'originBranchId'   => 'الفرع اللي أنشأ الأوردر — اتضاف 2026-08-26 عشان نقل الطيار بين الفروع بينقل أوردراته معاه، وصاحب النظام طلب يفضل باين إن الأوردر أصله من فرع تاني. الأصل كان بيدوس على branch_id ويضيّع المصدر.',
            'originBranchName' => 'اسم الفرع الأصلي — الجهة المعروضة للحقل اللي فوقه',
            'handedOverAt'     => 'تأكيد المحل إنه سلّم الأوردر للطيار — اتضاف 2026-08-29. الأصل كان بيعتبر «جاري التوصيل» بتبدأ من لحظة إسناد الطيار، فالمحل كان شايف الأوردر «مع الطيار» وهو لسه على الرف عنده. العمود ده بيفصل اللحظتين.',
            'handedOverBy'     => 'مين أكّد التسليم من المحل — للمراجعة وقت الخلاف على «أنا سلّمتها»',
            'undeliveredFareBy' => 'مين دفع توصيل المرتجع (receiver/sender/none) — اتضاف 2026-09-02 بطلب صاحب النظام: «لما العميل يرفض أو يرجّع، يا هو يا المحل بيدفع التوصيل — لازم يبان علشان الحسابات». المدفوع بيدخل المتوقع من الطيار في التسوية.',
        ],
        'pilots' => [
            'homeBranchId' => 'فرع الطيار الثابت — اتضاف 2026-08-30 بطلب صاحب النظام: «ثبّت الطيار على فرع من البداية، وحتى لو اتقفلت الوردية يفتح على الفرع المتكوّد عليه، ولو اتنقل لفرع تاني اليوم يرجع لفرعه تاني يوم». الأصل كان بيستعمل assigned_branch_id لمعنيين متضاربين: الفرع الثابت والفرع الجاري — و releasePilot() بتمسحه عند قفل الوردية، فالفرع كان بيضيع.',
            'homeBranchName' => 'اسم الفرع الثابت — الجهة المعروضة للحقل اللي فوقه، وهو اللي بيتعرض في عمود «الفرع» بجدول الطيارين',
            'archivedAt' => 'وقت أرشفة الطيار — اتضاف 2026-09-01 بطلب صاحب النظام: «بدل مسحه، ينتفي لصفحة تانية عشان يبقى غير فعّال، وأي بيانات مرتبطة بيه ماتأثرش على البيانات السابقة». الحذف مكانش شغّال أصلًا (١٨ مفتاح أجنبي RESTRICT + حساب دخول مربوط لكل طيار)، ولو اشتغل كان هيفضّي التقارير المالية بأثر رجعي. NULL = فعّال.',
            'archivedBy' => 'اسم المستخدم اللي أرشف الطيار — لقطة زي باقي أعمدة الـactor في النظام، عشان السجل يفضل مقروء حتى لو الحساب اتغيّر',
            'hourRate'      => 'سعر ساعة الطيار — العمود موجود من زمان بس ماكانش على السلك ولا له نقطة كتابة، فـ١٤ من ١٥ طيار بصفر. اتضاف 2026-09-01 مع شاشة الأسعار في برنامج التقفيل',
            'paidLeaveDays' => 'أيام الإجازة المدفوعة شهريًا — نفس السبب',
        ],
        'users' => [
            'hourRate'      => 'سعر ساعة الموظف — اتضاف 2026-09-01 مع تقفيلة الموظفين (طلب صاحب النظام: «زي ما الطيارين لهم تقفيلة ضيف كل الموظفين»). الأصل ماكانش فيه رواتب موظفين خالص.',
            'monthlySalary' => 'الراتب الشهري للموظف — بيتقسم على أيام الشغل في التقفيلة، نفس معادلة دمشق',
            'paidLeaveDays' => 'أيام الإجازة المدفوعة شهريًا للموظف',
            'canEditPrice'  => 'خاصية تعديل سعر التوصيل للمحل (زيادة أو نقصان) — اتضافت 2026-09-03 بطلب صاحب النظام: «تعديل الخدمة بالناقص أو بالموجب تبقى خاصية يمكن إضافتها لبعض المحلات». الأصل كان مفتوح لكل المحلات (زيادة بس).',
        ],
        'store_contacts' => [
            'zoneId' => 'منطقة التسليم المعتادة للعميل في دفتر المحل — اتضافت 2026-08-24 عشان اختيار العميل يعبّي المنطقة والسعر تلقائيًا. الأصل مكانش بيخزّن منطقة مع جهة الاتصال أصلًا.',
        ],
    ];

    private int $pass = 0;
    private int $fail = 0;
    private array $failures = [];

    public function handle(): int
    {
        $legacy = $this->legacyRoot();
        if ($legacy === null) {
            $this->error('مالقيتش مشروع aldahshan جنب المشروع ده');
            return self::FAILURE;
        }

        // الأصل محتاج توقيت UTC زي api/config.php
        date_default_timezone_set('UTC');

        require_once $legacy . '/api/constants.php';
        require_once $legacy . '/api/ser_core.php';
        require_once $legacy . '/api/ser_orders.php';

        $pdo = $this->legacyPdo();
        $limit = (int) $this->option('limit');

        $this->line("بيانات الفحص: قاعدة " . DB::connection()->getDatabaseName());
        $this->newLine();

        $this->checkEntity('branches', 'ser_branch', fn ($r) => CoreWire::branch($r), $limit,
            'SELECT b.*, f.name AS failover_branch_name FROM branches b
             LEFT JOIN branches f ON f.id = b.failover_branch_id ORDER BY b.id');

        $this->checkEntity('zones', 'ser_zone', fn ($r) => CoreWire::zone($r), $limit,
            'SELECT z.*, db.name AS delivery_branch_name, sb.name AS source_branch_name
               FROM zones z
               LEFT JOIN branches db ON db.id = z.delivery_branch_id
               LEFT JOIN branches sb ON sb.id = z.source_branch_id ORDER BY z.id');

        $this->checkEntity('pilots', 'ser_pilot', fn ($r) => CoreWire::pilot($r), $limit,
            'SELECT p.*, b.name AS assigned_branch_name, u.username
               FROM pilots p
               LEFT JOIN branches b ON b.id = p.assigned_branch_id
               LEFT JOIN users u ON u.pilot_id = p.id ORDER BY p.id');

        $this->checkEntity('users', 'ser_user', fn ($r) => CoreWire::user($r), $limit,
            'SELECT u.*, b.name AS branch_name FROM users u
               LEFT JOIN branches b ON b.id = u.branch_id ORDER BY u.id');

        $this->checkEntity('senders', 'ser_sender', fn ($r) => CoreWire::sender($r), $limit,
            'SELECT * FROM senders ORDER BY id');

        $this->checkEntity('receivers', 'ser_receiver', fn ($r) => CoreWire::receiver($r), $limit,
            'SELECT * FROM receivers ORDER BY id');

        $this->checkEntity('store_contacts', 'ser_store_contact', fn ($r) => CoreWire::storeContact($r), $limit,
            'SELECT * FROM store_contacts ORDER BY id');

        // ── الأوردرات: الكائن الكامل بالطرود والصور والنقلات والتقييمات ──
        $rows = $pdo->query(ser_orders_base_sql() . ' ORDER BY o.id LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC);
        $oldAll = ser_orders_batch($pdo, $rows);
        $newAll = OrderWire::batch($rows);

        if (count($oldAll) !== count($newAll)) {
            $this->recordFail('orders', 'count', count($oldAll), count($newAll));
        } else {
            foreach ($oldAll as $i => $old) {
                $this->compare('orders#' . ($rows[$i]['order_num'] ?? $i), $old, $newAll[$i], 'orders');
            }
        }
        $this->line(sprintf('  orders          : %d صف', count($oldAll)));

        // ── النتيجة ──
        $this->newLine();
        if ($this->failures) {
            foreach ($this->failures as $f) {
                $this->line($f);
            }
            $this->newLine();
        }
        $this->line('════════════════════════════════════════════');
        $line = sprintf('WIRE PARITY: %d مطابق / %d مختلف   (إجمالي %d)',
            $this->pass, $this->fail, $this->pass + $this->fail);
        $this->fail > 0 ? $this->error($line) : $this->info($line);
        $this->line('════════════════════════════════════════════');

        return $this->fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function checkEntity(string $label, string $legacyFn, callable $new, int $limit, string $sql): void
    {
        $rows = DB::select($sql . ' LIMIT ' . $limit);
        foreach ($rows as $row) {
            $arr = (array) $row;
            $this->compare($label . '#' . ($arr['id'] ?? '?'), $legacyFn($arr), $new($arr), $label);
        }
        $this->line(sprintf('  %-15s : %d صف', $label, count($rows)));
    }

    private function compare(string $what, mixed $old, mixed $new, ?string $entity = null): void
    {
        // المقارنة على الـJSON عشان تكشف اختلاف الترتيب والنوع كمان
        /* الحقول المضافة عن قصد بتتشال من الجديد قبل المقارنة — بكده
           الفحص يفضل بيمسك أي اختلاف تاني في نفس الكيان بدل ما يبقى
           كله أحمر بسبب حقل معروف. */
        if ($entity !== null && is_array($new) && isset(self::INTENTIONAL_FIELDS[$entity])) {
            foreach (array_keys(self::INTENTIONAL_FIELDS[$entity]) as $f) {
                unset($new[$f]);
            }
        }
        // والطرود جوه الأوردر — تنظيف على المستوى التاني
        if ($entity === 'orders' && is_array($new) && is_array($new['deliveries'] ?? null)) {
            foreach ($new['deliveries'] as $i => $d) {
                if (! is_array($d)) {
                    continue;
                }
                foreach (array_keys(self::INTENTIONAL_DELIVERY_FIELDS) as $f) {
                    unset($new['deliveries'][$i][$f]);
                }
            }
        }

        $a = json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $b = json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($a === $b) {
            $this->pass++;
            return;
        }

        $this->fail++;
        $keys = array_unique(array_merge(
            array_keys(is_array($old) ? $old : []),
            array_keys(is_array($new) ? $new : [])
        ));
        $diffs = [];
        foreach ($keys as $k) {
            $ov = is_array($old) ? ($old[$k] ?? '«ناقص»') : null;
            $nv = is_array($new) ? ($new[$k] ?? '«ناقص»') : null;
            if (json_encode($ov, JSON_UNESCAPED_UNICODE) !== json_encode($nv, JSON_UNESCAPED_UNICODE)) {
                $diffs[] = sprintf('       %s: أصل=%s  جديد=%s', $k,
                    json_encode($ov, JSON_UNESCAPED_UNICODE),
                    json_encode($nv, JSON_UNESCAPED_UNICODE));
            }
        }
        $this->failures[] = "  ✗ {$what}\n" . implode("\n", $diffs);
    }

    private function recordFail(string $what, string $key, mixed $old, mixed $new): void
    {
        $this->fail++;
        $this->failures[] = sprintf('  ✗ %s (%s): أصل=%s جديد=%s', $what, $key,
            json_encode($old), json_encode($new));
    }

    private function legacyRoot(): ?string
    {
        $p = dirname(base_path()) . DIRECTORY_SEPARATOR . 'aldahshan';

        return is_file($p . '/api/ser_orders.php') ? $p : null;
    }

    private function legacyPdo(): PDO
    {
        $c = config('database.connections.' . config('database.default'));

        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']),
            $c['username'],
            $c['password'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
}
