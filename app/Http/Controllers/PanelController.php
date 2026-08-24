<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * تقديم لوحات النظام (ملفات HTML ثابتة في public/).
 *
 * ليه كنترولر مش closure في routes/web.php:
 * `php artisan route:cache` — وهي خطوة أساسية في النشر — **بتفشل** مع
 * الـclosures («Your route files contain a closure»)، وكمان الدالة العامة
 * اللي كانت في ملف المسارات كانت بتترمي بـ«Cannot redeclare» لأن الملف
 * بيتحمّل مرتين وقت الكاش. اتكشف قبل النشر مش بعده.
 */
class PanelController
{
    /**
     * الجذر «/» — **الموقع التسويقي العام**.
     *
     * قبل 2026-08-20 كان الجذر بيقدّم بوابة الموظفين: أي زائر يكتب الدومين
     * يلاقي فورم دخول. الاسمين اتبدلوا عشان `index.html` (الاسم اللي أباتشي
     * بيدوّر عليه افتراضيًا) يبقى الموقع، والبوابة بقت `home.html`.
     *
     * ⚠️ عمليًا المسار ده **مابيتنفّذش تحت أباتشي** — طلب «/» بيتحل لمجلد،
     * فشرط `!-d` في .htaccess بيفشل ومفيش إعادة كتابة لـindex.php، وmod_dir
     * بيقدّم index.html مباشرةً. المسار ده بيشتغل مع `artisan serve` بس
     * (السيرفر المدمج مالوش DirectoryIndex). سايبينه عشان الاتنين يطابقوا.
     */
    public function site(): BinaryFileResponse
    {
        return $this->serve('index.html');
    }

    private function serve(string $file): BinaryFileResponse
    {
        $path = public_path($file);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
