<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * خريطة الموقع — `GET /sitemap.xml`.
 *
 * ليه كنترولر مش ملف ثابت:
 * `lastmod` لازم يعكس آخر تعديل **حقيقي** للصفحة. لو اتكتب بالإيد بيبوظ
 * أول ما حد يعدّل صفحة وينسى يحدّثه، وجوجل بيبطّل يثق فيه. الطريقة دي
 * منقولة من `sitemap.php` بتاع الموقع القديم: `filemtime` على الملف نفسه.
 *
 * والصفحات اللي **مش موجودة على القرص بتتخطّى** — يعني الملف ده يفضل صحيح
 * حتى لو صفحة اتشالت أو لسه ما اتعملتش، من غير ما ينتج 404 في الخريطة
 * (و404 في خريطة الموقع بيخصم من ثقة الزحف).
 *
 * ملحوظة: المسار ده بيوصل للارافل لأن مفيش ملف اسمه sitemap.xml على القرص،
 * فشرط `!-f` في .htaccess بينجح. لو حد عمل الملف ده يدويًا، أباتشي هيقدّمه
 * وهيتجاهل الكنترولر ده تمامًا.
 */
class SitemapController
{
    /**
     * الصفحات العامة بأولوياتها.
     *
     * الأولوية **نسبية جوه الموقع** مش مطلقة — يعني معناها «إيه أهم من إيه
     * عندي»، مش «رتّبني كذا». الجذر 1.0، والصفحات اللي بتجيب عملاء بعده.
     */
    private const PAGES = [
        ['file' => 'index.html',    'loc' => '',              'priority' => '1.0', 'freq' => 'weekly'],
        ['file' => 'services.html', 'loc' => 'services.html', 'priority' => '0.9', 'freq' => 'monthly'],
        ['file' => 'pricing.html',  'loc' => 'pricing.html',  'priority' => '0.9', 'freq' => 'weekly'],
        ['file' => 'faq.html',      'loc' => 'faq.html',      'priority' => '0.8', 'freq' => 'monthly'],
        ['file' => 'about.html',    'loc' => 'about.html',    'priority' => '0.6', 'freq' => 'yearly'],
        // بروفايل الشركة — الصفحة اللي الـQR على الكارت بيوّدي لها
        ['file' => 'profile.html',  'loc' => 'profile.html',  'priority' => '0.8', 'freq' => 'monthly'],
        ['file' => 'contact.html',  'loc' => 'contact.html',  'priority' => '0.7', 'freq' => 'yearly'],
        ['file' => 'privacy.html',  'loc' => 'privacy.html',  'priority' => '0.3', 'freq' => 'yearly'],
        ['file' => 'terms.html',    'loc' => 'terms.html',    'priority' => '0.3', 'freq' => 'yearly'],
    ];

    public function xml(): Response
    {
        $base = rtrim((string) config('app.url'), '/') . '/';
        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
              . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach (self::PAGES as $p) {
            $path = public_path($p['file']);

            // الصفحة لسه ما اتعملتش أو اتشالت — نتخطّاها بدل ما نعلن 404
            if (! is_file($path)) {
                continue;
            }

            $out .= '  <url>' . "\n"
                  . '    <loc>' . htmlspecialchars($base . $p['loc'], ENT_XML1) . '</loc>' . "\n"
                  . '    <lastmod>' . date('Y-m-d', (int) filemtime($path)) . '</lastmod>' . "\n"
                  . '    <changefreq>' . $p['freq'] . '</changefreq>' . "\n"
                  . '    <priority>' . $p['priority'] . '</priority>' . "\n"
                  . '  </url>' . "\n";
        }

        $out .= '</urlset>' . "\n";

        return response($out, 200, [
            'Content-Type'  => 'application/xml; charset=UTF-8',
            // الخريطة نفسها مالهاش لزمة في نتايج البحث
            'X-Robots-Tag'  => 'noindex',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
