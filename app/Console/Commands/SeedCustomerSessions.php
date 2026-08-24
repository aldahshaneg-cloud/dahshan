<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\WireTime;
use Illuminate\Console\Command;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * المكافئ اللارافلي لـ `aldahshan/tests/seed_incoming_fixtures.php`.
 *
 * ليه احتاجنا مكافئ أصلًا: الـseeder القديم بيفتح **جلسة PHP أصلية**
 * (`session_start()` + `session_id()`) وبيسلّم الـid لـcurl. جلسة لارافل
 * ليها تخزين وتوقيع مختلفين تمامًا — الـid ده مالوش أي معنى عندها،
 * فـ`e2e_incoming_wallet.ps1` كان الاختبار الوحيد اللي مش قادر يشتغل على
 * لارافل. الأمر ده بيزرع **نفس الفيكستشرز** وبيفتح **جلسات لارافل حقيقية**
 * وبيطبع **نفس الـJSON بنفس أسماء المفاتيح** — فالاختبار مايفرقش بين
 * الهدفين إلا في سطر اختيار الـseeder.
 *
 * بيزرع:
 *   • عميل «الضحية» (A) وله شحنة واردة على رقمه + محفظة برصيد
 *   • عميل «المهاجم» (B) — بياناته مكتملة، ومالوش أي علاقة بشحنة A
 *   • عميل «لسه بيسجّل» (C) — profile_completed = 0
 *
 * ⚠️ الجلسات بتتفتح بـ`SessionManager` مش بكتابة ملفات في
 * `storage/framework/sessions` باليد. السبب مش أناقة: كتابة الملف باليد
 * بتربط الاختبار بـ`SESSION_DRIVER=file`، و`PORT.md` مسجّل إن السواقة
 * هتتحوّل لـ`database` قبل النشر. مع المدير الاختبار بيفضل شغّال من غير
 * ما حد يفتكر يعدّله.
 *
 * ⚠️ أداة اختبار محلية — بترفض الاشتغال على APP_ENV=production. الأمر ده
 * بيفتح جلسات عملاء صالحة من غير أي كلمة سر؛ ده مقبول على جهاز التطوير
 * وبس.
 *
 * الاستخدام: php artisan test:seed-customer-sessions  →  JSON فيه معرفات الجلسات
 */
class SeedCustomerSessions extends Command
{
    protected $signature = 'test:seed-customer-sessions
                            {--tag=ZZINC : بادئة الفيكستشرز — لازم تطابق اللي الاختبار بينضّف بيه}';

    protected $description = 'أداة اختبار محلية: بتزرع فيكستشرز /api/customer/incoming وبتفتح 3 جلسات عملاء حقيقية';

    public function handle(): int
    {
        /* الحارس الأول: الأمر ده بيدّي جلسات عملاء جاهزة بلا مصادقة.
           على سيرفر إنتاج ده مش «أداة اختبار» — ده باب خلفي. */
        if ($this->laravel->environment('production')) {
            $this->error('ممنوع — أداة اختبار محلية وإحنا على APP_ENV=production');

            return self::FAILURE;
        }

        $tag = strtoupper(trim((string) $this->option('tag')));
        // البادئة بتتحط جوه LIKE مباشرةً في جمل الحذف، فبنقفلها على ASCII
        if ($tag === '' || preg_match('/^[A-Z0-9]{2,16}$/', $tag) !== 1) {
            $this->error('البادئة لازم تكون حروف وأرقام إنجليزية من 2 لـ16');

            return self::FAILURE;
        }

        $now = WireTime::nowDb();

        try {
            $seed = $this->seedFixtures($tag, $now);
        } catch (Throwable $e) {
            $this->error('فشل زرع الفيكستشرز: ' . $e->getMessage());

            return self::FAILURE;
        }

        /* الجلسات بره المعاملة عمدًا: السيرفر اللي الاختبار بيناديه عملية
           تانية خالص، فلازم الصفوف تكون متثبتة قبل ما جلسة تشاور عليها. */
        $sessions = [
            'sidVictim'   => $this->openCustomerSession($seed['victimId'], "{$tag}-victim@test.local"),
            'sidAttacker' => $this->openCustomerSession($seed['attackerId'], "{$tag}-attacker@test.local"),
            'sidOnboard'  => $this->openCustomerSession($seed['onboardId'], "{$tag}-onboard@test.local"),
        ];

        /* ترتيب المفاتيح وأسماؤها **حرفيًا** زي الـseeder القديم — السكربت
           بيقرا `$seed.sessionName` و`$seed.sidVictim` ... إلخ. */
        $out = [
            'sessionName' => (string) config('session.cookie'),
            'sidVictim'   => $sessions['sidVictim'],
            'sidAttacker' => $sessions['sidAttacker'],
            'sidOnboard'  => $sessions['sidOnboard'],
            'victimId'    => $seed['victimId'],
            'attackerId'  => $seed['attackerId'],
            'onboardId'   => $seed['onboardId'],
            'victimPhone' => $seed['victimPhone'],
            'orderNum'    => $seed['orderNum'],
            'orderId'     => $seed['orderId'],
            'branchId'    => $seed['branchId'],
            'zoneId'      => $seed['zoneId'],
        ];

        /* OUTPUT_RAW عشان Symfony ماتحاولش تفسّر أي `<...>` كوسم تنسيق،
           والمخرج لازم يفضل JSON نضيف يتقرا بـConvertFrom-Json. */
        $this->output->writeln(
            (string) json_encode($out, JSON_UNESCAPED_UNICODE),
            OutputInterface::OUTPUT_RAW
        );

        return self::SUCCESS;
    }

    /**
     * نقل حرفي لجزء الزرع في `seed_incoming_fixtures.php` — نفس الجداول،
     * نفس القيم، نفس ترتيب الحذف (الترتيب مهم: المفاتيح الأجنبية).
     *
     * @return array{victimId:int,attackerId:int,onboardId:int,victimPhone:string,orderNum:string,orderId:int,branchId:int,zoneId:int}
     */
    private function seedFixtures(string $tag, string $now): array
    {
        /* ── تنظيف بقايا أي تشغيلة سابقة ─────────────────────────── */
        DB::statement("DELETE FROM wallet_transactions WHERE order_num LIKE '{$tag}%'");
        DB::statement("DELETE FROM order_deliveries WHERE order_id IN (SELECT id FROM orders WHERE order_num LIKE '{$tag}%')");
        DB::statement("DELETE FROM orders WHERE order_num LIKE '{$tag}%'");
        DB::statement("DELETE FROM wallets WHERE owner_type='customer' AND owner_id IN (SELECT id FROM customers WHERE email LIKE '{$tag}%')");
        DB::statement("DELETE FROM lookup_log WHERE actor_name LIKE 'customer:%' AND actor_type='customer_onboarding'");
        DB::statement("DELETE FROM customers WHERE email LIKE '{$tag}%'");
        DB::statement("DELETE FROM zones WHERE area_name = '{$tag}-زون'");
        DB::statement("DELETE FROM branches WHERE code = 'ZZI'");

        /* ── فرع + زون ───────────────────────────────────────────── */
        DB::insert(
            "INSERT INTO branches (name, code, phone, created_at) VALUES ('{$tag}-فرع','ZZI','0100',?)",
            [$now]
        );
        $branchId = $this->lastId();

        DB::insert(
            "INSERT INTO zones (area_name, price, delivery_branch_id, created_at) VALUES ('{$tag}-زون', 35, ?, ?)",
            [$branchId, $now]
        );
        $zoneId = $this->lastId();

        /* ── العملاء التلاتة ─────────────────────────────────────── */
        $victimPhone = '01011112222';
        $attackPhone = '01033334444';

        $victimId   = $this->makeCustomer($tag, 'victim', $victimPhone, 1, $now);   // الضحية — بياناته مكتملة
        $attackerId = $this->makeCustomer($tag, 'attacker', $attackPhone, 1, $now); // المهاجم — بياناته مكتملة
        $onboardId  = $this->makeCustomer($tag, 'onboard', '', 0, $now);            // لسه بيسجّل

        // محفظة الضحية برصيد 100
        DB::insert(
            "INSERT INTO wallets (owner_type, owner_id, balance, created_at) VALUES ('customer', ?, 100, ?)",
            [$victimId, $now]
        );

        /* ── شحنة واردة للضحية (هو المستلم) ──────────────────────── */
        $orderNum = $tag . '-260819-001';
        DB::insert(
            "INSERT INTO orders (order_num, branch_id, customer_id, status, source, added_by, added_by_role,
                                 sender_name, sender_phone, sender_address,
                                 total_delivery_price, goods_value, store_prepaid, wallet_used, created_at, updated_at)
             VALUES (?,?,?, 'processing', 'customer', ?, 'عميل',
                     'مُرسِل سرّي', '01099998888', 'عنوان المُرسِل السرّي',
                     35, 500, 0, 0, ?, ?)",
            [$orderNum, $branchId, $victimId, "{$tag}-victim-uid", $now, $now]
        );
        $orderId = $this->lastId();

        /* الأسماء العربية دي **هي** موضوع الفحص: الاختبار بيمشّط نص الرد
           الخام عليها عشان يتأكد إن المهاجم مشافش حاجة منها. */
        DB::insert(
            "INSERT INTO order_deliveries (order_id, parcel_no, receiver_name, receiver_phone, address,
                                           zone_id, zone_price, order_price, status)
             VALUES (?,1,'اسم المستلم السرّي',?, 'عنوان المستلم السرّي', ?, 35, 250, 'processing')",
            [$orderId, $victimPhone, $zoneId]
        );

        return [
            'victimId'    => $victimId,
            'attackerId'  => $attackerId,
            'onboardId'   => $onboardId,
            'victimPhone' => $victimPhone,
            'orderNum'    => $orderNum,
            'orderId'     => $orderId,
            'branchId'    => $branchId,
            'zoneId'      => $zoneId,
        ];
    }

    /** المقابل لـ mkCustomer() في الـseeder القديم */
    private function makeCustomer(string $tag, string $slug, string $phone, int $completed, string $now): int
    {
        DB::insert(
            'INSERT INTO customers (legacy_key, email, display_name, phone1, profile_completed, created_at, last_login_at)
             VALUES (?,?,?,?,?,?,?)',
            ["{$tag}-{$slug}-uid", "{$tag}-{$slug}@test.local", "{$tag} {$slug}", $phone, $completed, $now, $now]
        );

        return $this->lastId();
    }

    private function lastId(): int
    {
        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * المقابل لـ openSession() القديمة — بس بآلية جلسة لارافل.
     *
     * نفس المفاتيح التلاتة اللي `customer_login` بيحطها، وهي اللي
     * `ResolveApiActor` و`CustomerAppController::customerRequire()`
     * بيقروها: role + customer_id + username.
     *
     * `setId(null)` بتخلي `Store` يولّد معرف جديد **صالح عند لارافل**
     * (40 حرف alnum) — الشرط اللي `Store::isValidId()` بيفحصه على الكوكي
     * الجاية؛ معرف الجلسة القديم (32 حرف hex) كان بيترفض هنا.
     */
    private function openCustomerSession(int $customerId, string $email): string
    {
        /** @var SessionManager $manager */
        $manager = $this->laravel->make(SessionManager::class);

        /** @var Store $store */
        $store = $manager->driver();

        // سواقة الملفات مابتعملش المجلد لوحدها — أول تشغيلة على نسخة نضيفة بتقع
        if ($manager->getDefaultDriver() === 'file') {
            File::ensureDirectoryExists((string) config('session.files'));
        }

        $store->setId(null);
        $store->start();
        $store->put([
            'customer_id' => $customerId,
            'role'        => 'customer',
            'username'    => $email,
        ]);
        $store->save();

        return $store->getId();
    }
}
