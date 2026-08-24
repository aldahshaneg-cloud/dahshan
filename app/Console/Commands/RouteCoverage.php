<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * تغطية الترحيل — بيقارن مسارات لارافل بجدول `$routes` في النظام القديم.
 *
 * المصدر الوحيد للحقيقة هو الجدول الأصلي نفسه (`aldahshan/public/index.php`)
 * مش أي قايمة مكتوبة بالإيد — القايمة اليدوية بتفترق عن الواقع بصمت.
 *
 * فيه نوعين مسار في لارافل مالهمش مقابل في الأصل، ولازم نفرّق بينهم:
 *   • غلطة ترحيل   — اسم أو شكل مسار اتكتب غلط، ده اللي محتاج يتصلّح
 *   • إضافة مقصودة — ميزة جديدة اتعملت بعد الترحيل، مافيش أصل تتقارن بيه أساسًا
 *
 * القايمة البيضا `INTENTIONAL_ADDITIONS` هي اللي بتفصل بين الاتنين: اللي فيها
 * مايتحسبش «زيادة»، بس بيفضل يتعرض في سطر لوحده — مقصود يفضل مراجَع، مش مخفي.
 * لولا ده كانت البوابة هتقول «زيادة N» للأبد وتفقد قيمتها كإشارة.
 *
 * وعشان القايمة ماتتحولش لمقبرة لمسارات اتشالت: أي مسار مكتوب فيها ومش متسجّل
 * فعلًا في لارافل بيطلع تحذير — يا يترجّع يا يتشال من القايمة.
 *
 * بيطلّع:
 *   • الناقص  — مسار في الأصل ومش متسجّل في لارافل
 *   • الزيادة — مسار في لارافل مش في الأصل ولا في القايمة البيضا (غالبًا غلطة اسم)
 *   • إضافات مقصودة — مسار جديد معروف ومكتوب سببه
 *
 * ملحوظة: نسبة التغطية و«الناقص» بيقيسوا الترحيل هو هو — الإضافات المقصودة
 * مابتأثرش عليهم لا بالسالب ولا بالموجب.
 *
 * التشغيل: php artisan route:coverage [--missing] [--extra]
 */
class RouteCoverage extends Command
{
    /**
     * مسارات اتضافت في لارافل بعد الترحيل عن قصد — مالهاش مقابل في النظام القديم.
     *
     * المفتاح: 'METHOD /path' زي ما هو متسجّل (الـ {id} بيتوحّد وقت المقارنة زي أي مسار تاني).
     * القيمة: ده إيه وليه مضاف — أي سطر جديد هنا لازم يجي معاه سبب مكتوب،
     *         القايمة من غير أسباب بتبقى قايمة تجاهل مش قايمة بيضا.
     */
    private const INTENTIONAL_ADDITIONS = [
        'POST /api/public/contact-message' =>
            'استقبال رسالة من نموذج «اتصل بنا» في الموقع العام — النموذج ده اتضاف بعد الترحيل ومكانش موجود في النظام القديم',

        'GET /api/contact-messages' =>
            'عرض رسايل التواصل الواردة في لوحة الإدارة — الجهة المقابلة للنموذج العام',

        'PUT /api/contact-messages/{id}/read' =>
            'تعليم رسالة تواصل كـ«مقروءة» بعد ما الإدارة تفتحها',

        'GET /api/order-notifications' =>
            'قايمة رسايل إشعار المستلمين بالأوردر (جدول order_notifications) — الإشعار نفسه اتضاف بعد الترحيل، النظام القديم مكانش بيسجّل الرسايل أصلًا',

        'POST /api/order-notifications/{id}/sent' =>
            'تعليم رسالة إشعار إنها اتبعتت بإيد الموظف — الوضع اليدوي المؤقت لحد ما حساب WhatsApp Cloud API يجهز',

        'GET /api/customer/push/key' =>
            'مفتاح VAPID العام لإشعارات ستارة الهاتف في تطبيق العملاء (Web Push) — الميزة اتضافت بعد الترحيل، النظام القديم كان بيستطلع بس',

        'POST /api/customer/push/subscribe' =>
            'تسجيل/تحديث اشتراك جهاز العميل في إشعارات الستارة (جدول customer_push_subscriptions) — upsert على الـendpoint',

        'POST /api/customer/push/unsubscribe' =>
            'حذف اشتراك جهاز العميل من إشعارات الستارة — الجهة المقابلة للاشتراك',
    ];

    protected $signature = 'route:coverage {--missing : اعرض الناقص بس} {--extra : اعرض الزيادة بس}';

    protected $description = 'بيقيس تغطية ترحيل المسارات مقابل النظام القديم';

    public function handle(): int
    {
        $legacyFile = dirname(base_path()) . '/aldahshan/public/index.php';
        if (! is_file($legacyFile)) {
            $this->error("مالقيتش جدول المسارات الأصلي: {$legacyFile}");
            return self::FAILURE;
        }

        // استخراج مفاتيح مصفوفة $routes: 'GET /api/xxx' => 'handler'
        preg_match_all(
            "/^\s*'(GET|POST|PUT|DELETE|PATCH)\s+([^']+)'\s*=>\s*/m",
            (string) file_get_contents($legacyFile),
            $m,
            PREG_SET_ORDER
        );

        $legacy = [];
        foreach ($m as $r) {
            $legacy[strtoupper($r[1]) . ' ' . $this->norm($r[2])] = true;
        }

        $mine = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }
            if (str_contains($uri, 'fallbackPlaceholder')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $mine[$method . ' ' . $this->norm('/' . $uri)] = true;
            }
        }

        // القايمة البيضا بتتوحّد بنفس قواعد أي مسار تاني عشان المقارنة تمشي على الشكل مش التسمية
        $allowed = [];  // مفتاح موحّد => المفتاح زي ما هو مكتوب في الثابت
        foreach (array_keys(self::INTENTIONAL_ADDITIONS) as $key) {
            [$method, $path] = explode(' ', $key, 2);
            $allowed[strtoupper($method) . ' ' . $this->norm($path)] = $key;
        }

        $missing = array_diff_key($legacy, $mine);

        // كل اللي في لارافل ومش في الأصل، بعدين بنقسمه: زيادة محتاجة مراجعة / إضافة مقصودة
        $unmatched  = array_diff_key($mine, $legacy);
        $extra      = array_diff_key($unmatched, $allowed);
        $intentional = array_intersect_key($allowed, $unmatched);

        // مسار في القايمة البيضا ومش متسجّل في لارافل خالص — القايمة بتتحوّل لمقبرة
        $ghosts = array_diff_key($allowed, $mine);

        // التغطية والناقص زي ما هما — بيقيسوا الترحيل بس، والإضافات المقصودة مالهاش دخل بيهم
        $done    = count($legacy) - count($missing);
        $pct     = count($legacy) > 0 ? round($done / count($legacy) * 100) : 0;

        $showMissing = $this->option('missing') || ! $this->option('extra');
        $showExtra   = $this->option('extra') || ! $this->option('missing');

        if ($showMissing && $missing) {
            $this->newLine();
            $this->line('── ناقص (' . count($missing) . ') ──');
            $byPrefix = [];
            foreach (array_keys($missing) as $k) {
                [$mth, $path] = explode(' ', $k, 2);
                $seg = explode('/', trim($path, '/'))[1] ?? '?';
                $byPrefix[$seg][] = $k;
            }
            ksort($byPrefix);
            foreach ($byPrefix as $seg => $items) {
                $this->line(sprintf('  %-18s %d', $seg, count($items)));
                foreach ($items as $k) {
                    $this->line('       ' . $k);
                }
            }
        }

        if ($showExtra && $extra) {
            $this->newLine();
            $this->line('── زيادة عن الأصل (' . count($extra) . ') — راجعها، غالبًا غلطة اسم ──');
            foreach (array_keys($extra) as $k) {
                $this->line('  ' . $k);
            }
        }

        if ($showExtra && $intentional) {
            $this->newLine();
            $this->line('── إضافات مقصودة (' . count($intentional) . ') — مش محسوبة زيادة ──');
            foreach ($intentional as $key) {
                $this->line('  ' . $key);
                $this->line('       ' . self::INTENTIONAL_ADDITIONS[$key]);
            }
        }

        // تحذير بيتعرض دايمًا مهما كانت الفلاتر: القايمة البيضا نفسها بقت فيها مسارات ميتة
        if ($ghosts) {
            $this->newLine();
            $this->warn('── مسارات في القايمة البيضا ومش متسجّلة في لارافل (' . count($ghosts) . ') ──');
            $this->warn('   يا تترجّع يا تتشال من INTENTIONAL_ADDITIONS — القايمة مش مقبرة:');
            foreach ($ghosts as $key) {
                $this->warn('  ' . $key);
                $this->warn('       ' . self::INTENTIONAL_ADDITIONS[$key]);
            }
        }

        $this->newLine();
        $this->line('════════════════════════════════════════════');
        $line = sprintf('التغطية: %d / %d مسار  (%d%%)  ·  ناقص %d  ·  زيادة %d  ·  إضافات مقصودة %d',
            $done, count($legacy), $pct, count($missing), count($extra), count($intentional));
        $missing === [] && $extra === [] && $ghosts === [] ? $this->info($line) : $this->line($line);
        $this->line('════════════════════════════════════════════');

        return self::SUCCESS;
    }

    /** توحيد شكل المسار: {أي اسم} → {} عشان المقارنة تبقى على الشكل مش التسمية */
    private function norm(string $path): string
    {
        return preg_replace('/\{[^}]+\}/', '{}', rtrim($path, '/')) ?? $path;
    }
}
