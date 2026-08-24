<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\WireTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * زرع الحد الأدنى اللي أي تركيب جديد محتاجه.
 *
 * ليه الأمر ده موجود:
 * `database/schema/mysql-schema.sql` مأخوذ بـ`mysqldump --no-data` — يعني
 * **بنية بلا بيانات**. على سيرفر نظيف بعد `php artisan migrate` تلاقي:
 *   • جدول المحافظات والمدن **فاضي** (المفروض 27 محافظة و315 مدينة)
 *   • مفيش إعدادات موقع
 *   • **مفيش حساب أدمن — يعني مش هتقدر تدخل النظام أصلاً**
 *
 * الفجوة دي اتكشفت وقت تجهيز أول نشر حقيقي (2026-08-19).
 *
 * إعادة التشغيل آمنة: كل الإدخالات `INSERT IGNORE` أو مشروطة بالوجود،
 * ومابيلمسش أي بيانات موجودة.
 *
 * ⚠️ **الباسورد مش مكتوب في الكود عن قصد.** أي قيمة افتراضية معروفة معناها
 * إن كل سيرفر جديد بيتولد بحساب إدارة مكشوف. لازم يتبعت كمعامل.
 *
 * التشغيل:
 *   php artisan install:seed --admin-password="كلمة-مرور-قوية"
 *   php artisan install:seed                      ← المرجعي والإعدادات بس
 */
class InstallSeed extends Command
{
    protected $signature = 'install:seed
                            {--admin-password= : باسورد حساب admin (لو مش موجود يتعمل)}
                            {--admin-username=admin : اسم مستخدم الإدارة}';

    protected $description = 'بيزرع البيانات المرجعية والإعدادات وحساب الإدارة على تركيب جديد';

    public function handle(): int
    {
        $now = WireTime::nowDb();

        /* ── 1) جغرافيا مصر ─────────────────────────────────────────
           27 محافظة و315 مدينة، مستخرجين حرفيًا من النظام القديم.
           الملف **متسخ جوه المشروع** (`database/seed_egypt.sql`) عشان
           التركيب يبقى مكتفي بنفسه — الأول كان بيقرا من مجلد النظام
           القديم، وده بيفشل لو اتنشر لارافل لوحده (وهو السيناريو الفعلي). */
        $egypt = database_path('seed_egypt.sql');

        // احتياطي: لو المشروع القديم منشور جنبه (تركيب قديم)
        if (! is_file($egypt)) {
            $egypt = dirname(base_path()) . '/aldahshan/db/seed_egypt.sql';
        }

        if (DB::table('egypt_governorates')->count() > 0) {
            $this->line('  المحافظات والمدن موجودة — اتخطّت');
        } elseif (! is_file($egypt)) {
            $this->warn('  ⚠️ مالقيتش database/seed_egypt.sql — المحافظات والمدن هتفضل فاضية');
        } else {
            /* ⚠️ كل جملة في الملف قبلها سطر تعليق (`-- القاهرة (30)`)، فرمي
               الجملة كلها لو بادئتها `--` كان بيرمي الـINSERT اللي وراه:
               جملة واحدة من 30 بس كانت بتتنفّذ، والمحافظات كانت بتتزرع
               والمدن **صفر**. الصح: نشيل سطور التعليق من أول الجملة بس. */
            foreach (preg_split('/;\s*[\r\n]+/', (string) file_get_contents($egypt)) as $stmt) {
                $stmt = preg_replace('/^\s*(--[^\r\n]*[\r\n]+)+/', '', (string) $stmt);
                $stmt = trim((string) $stmt);

                if ($stmt === '' || stripos($stmt, 'USE ') === 0) {
                    continue;
                }
                DB::unprepared($stmt);
            }
            $this->info(sprintf('  ✓ جغرافيا مصر: %d محافظة · %d مدينة',
                DB::table('egypt_governorates')->count(),
                DB::table('egypt_cities')->count()));
        }

        /* ── 2) إعدادات الموقع الافتراضية ───────────────────────────
           المفاتيح دي بتتقرا من `/api/settings/site` وهو **مسار عام**
           بتناديه صفحة التتبع والموقع العام. من غيرها الصفحات بتفضل
           بلا بيانات تواصل. */
        /* 🔴 المفتاح اسمه **`site`** مش `site.info`.
           `GET /api/settings/site` بيدوّر على تلات مفاتيح بالاسم بالظبط:
           ['site','workHours','customerBanner'] — ومفيش فيهم `site.info`.
           ولوحة تحكم الموقع بتحفظ الكتلة كلها تحت `site` بالشكل
           `{info:{…}, apps:{…}, hero:{…}, content:{…}}`.

           أول تركيب كتب `site.info` فالصف فضل **يتيم**: مفيش حاجة بتقراه،
           والمواعيد الافتراضية عمرها ما وصلت للموقع، و/api/settings/site
           كان بيرجّع مصفوفة فاضية. (اتكشفت 2026-08-20 وقت ربط المحتوى.) */
        $defaults = [
            'site' => json_encode([
                'info' => [
                    'phone'    => '',
                    'whatsapp' => '',
                    'hours'    => 'يوميًا من 9 صباحًا حتى 12 منتصف الليل',
                ],
            ], JSON_UNESCAPED_UNICODE),
            'attendanceTimeout' => '15',
        ];

        $added = 0;
        foreach ($defaults as $k => $v) {
            $exists = DB::table('site_settings')->where('setting_key', $k)->exists();
            if (! $exists) {
                DB::table('site_settings')->insert([
                    'setting_key' => $k, 'setting_value' => $v, 'updated_at' => $now,
                ]);
                $added++;
            }
        }
        $this->info("  ✓ الإعدادات: {$added} مفتاح جديد (الموجود ما اتلمسش)");

        /* ── 3) حساب الإدارة ────────────────────────────────────────
           من غيره مفيش طريقة تدخل النظام على تركيب جديد. */
        $username = (string) $this->option('admin-username');
        $password = (string) ($this->option('admin-password') ?? '');
        $exists   = DB::table('users')->where('username', $username)->exists();

        if ($exists) {
            $this->line("  حساب «{$username}» موجود — ما اتلمسش");
            $this->line('  لتغيير الباسورد: من لوحة الإدارة، أو بأمر مخصّص');
        } elseif ($password === '') {
            $this->warn('  ⚠️ مفيش حساب إدارة، وما بعتّش --admin-password');
            $this->warn('     من غيره **مش هتقدر تدخل النظام**. شغّل:');
            $this->warn('     php artisan install:seed --admin-password="كلمة-مرور-قوية"');
        } elseif (mb_strlen($password) < 12) {
            $this->error('  ✗ الباسورد أقصر من 12 حرف — ده حساب إدارة على النت');
            return self::FAILURE;
        } else {
            DB::table('users')->insert([
                'username'      => $username,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'role'          => 'admin',
                'name'          => 'المدير العام',
                'protected'     => 1,   // ميتحذفش وميتبلكش إلا من admin تاني
                'created_at'    => $now,
            ]);
            $this->info("  ✓ اتعمل حساب الإدارة «{$username}»");
        }

        /* ── ملخص ─────────────────────────────────────────────────── */
        $this->newLine();
        $this->line('  الحالة:');
        foreach ([
            'محافظات'  => 'egypt_governorates',
            'مدن'      => 'egypt_cities',
            'إعدادات'  => 'site_settings',
            'مستخدمين' => 'users',
            'فروع'     => 'branches',
        ] as $label => $table) {
            $this->line(sprintf('    %-10s %d', $label, DB::table($table)->count()));
        }

        $this->newLine();
        $this->line('  الخطوة الجاية: من لوحة الإدارة — أنشئ فرع، ثم مناطق، ثم طيارين ومستخدمين.');

        return self::SUCCESS;
    }
}
