<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * تغيير باسورد أي مستخدم من سطر الأوامر.
 *
 * ليه الأمر ده موجود:
 * `install:seed` بيعمل حساب الإدارة **مرة واحدة بس** — لو الحساب موجود
 * بيسيبه زي ما هو. يعني لو الباسورد اتكتب غلط وقت التركيب (أو اتنسي بعدين)
 * مافيش أي طريق للدخول: تغيير الباسورد من اللوحة بيتطلب إنك تكون داخل أصلًا.
 *
 * الفجوة دي اتكشفت وقت أول تركيب حقيقي (2026-08-19) لما الباسورد اتكتب
 * على شاشة بخط بيخلي `l` و`1` متطابقين.
 *
 * ⚠️ **مفيش قيمة افتراضية للباسورد** — لازم يتبعت كمعامل، بنفس منطق
 * `install:seed`: أي قيمة معروفة معناها حساب مكشوف.
 *
 * التشغيل:
 *   php artisan user:password admin --password="كلمة-مرور-قوية"
 *   php artisan user:password admin --password="…" --show   ← يطبع تأكيد الطول
 */
class UserPassword extends Command
{
    protected $signature = 'user:password
                            {username : اسم المستخدم}
                            {--password= : الباسورد الجديد (12 حرف على الأقل)}
                            {--show : يطبّع طول الباسورد وأول/آخر حرف للتأكيد}';

    protected $description = 'بيغيّر باسورد مستخدم موجود — طريق الرجوع لو اتقفل عليك الحساب';

    public function handle(): int
    {
        $username = (string) $this->argument('username');
        $password = (string) ($this->option('password') ?? '');

        $user = DB::table('users')->where('username', $username)->first();

        if (! $user) {
            $this->error("  ✗ مفيش مستخدم اسمه «{$username}»");
            $this->line('  الموجودين: ' . implode(', ',
                DB::table('users')->orderBy('id')->pluck('username')->all()));

            return self::FAILURE;
        }

        if ($password === '') {
            $this->error('  ✗ لازم تبعت --password');
            $this->line('  php artisan user:password ' . $username . ' --password="كلمة-مرور-قوية"');

            return self::FAILURE;
        }

        /* نفس الحد اللي في install:seed — ده حساب على النت. */
        if (mb_strlen($password) < 12) {
            $this->error('  ✗ الباسورد أقصر من 12 حرف');

            return self::FAILURE;
        }

        DB::table('users')->where('id', $user->id)->update([
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ]);

        $this->info("  ✓ اتغيّر باسورد «{$username}» (الدور: {$user->role})");

        /* التأكيد ده بيحل مشكلة حقيقية: على شاشة بخط أحادي المسافة `l` و`1`
           و`O` و`0` بيتشابهوا. الطول وأول/آخر حرف بيخلوك تتأكد إن اللي وصل
           هو اللي قصدته — من غير ما تطبع الباسورد كامل على الشاشة. */
        if ($this->option('show')) {
            $this->line(sprintf('    الطول: %d حرف · يبدأ بـ«%s» · ينتهي بـ«%s»',
                mb_strlen($password), mb_substr($password, 0, 1), mb_substr($password, -1)));
        }

        $this->newLine();
        $this->line('  جرّب الدخول دلوقتي. الجلسات القديمة ما اتلغتش —');
        $this->line('  الباسورد الجديد بيشتغل على أي دخول جديد.');

        return self::SUCCESS;
    }
}
