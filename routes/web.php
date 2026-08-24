<?php

declare(strict_types=1);

use App\Http\Controllers\PanelController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|---------------------------------------------------------------------------
| مسارات الويب — تقديم لوحات النظام
|---------------------------------------------------------------------------
| اللوحات ملفات HTML ثابتة في public/ بتنادي /api/* بمسارات نسبية، فالسيرفر
| بيقدّمها مباشرةً من غير ما تعدّي من هنا. اللي محتاج مسارات حاجتين بس:
|
|  1. الجذر «/» — بيقدّم **الموقع التسويقي** (index.html).
|     ملحوظة: تحت أباتشي المسار ده مابيتنفّذش أصلًا — mod_dir بيقدّم
|     index.html قبل ما لارافل يشوف الطلب. سايبينه عشان `artisan serve`
|     يطابق الإنتاج. (الأسماء اتبدلت 2026-08-20: البوابة بقت home.html.)
|
|  2. الأسماء القديمة — الموظفين والبوكماركس لسه بيستخدموا أسماء نظام
|     «طيّار». نفس تحويلات 301 اللي كانت في .htaccess بالظبط.
|
| ⚠️ **ممنوع closures هنا.** `php artisan route:cache` (خطوة أساسية في
| النشر) بتفشل معاها. كل حاجة كنترولر أو Route::redirect.
|---------------------------------------------------------------------------
*/

Route::get('/', [PanelController::class, 'site']);

/* خريطة الموقع — كنترولر مش ملف ثابت عشان lastmod يبقى حقيقي.
   شوف SitemapController للسبب كامل. */
Route::get('/sitemap.xml', [SitemapController::class, 'xml']);

/* ── أسماء نظام «طيّار» القديمة → الصفحات الجديدة ──────────────────
   tiar.html و site-admin.html أسماؤهم زي ما هي فمش محتاجين. */
Route::redirect('tiar_branch.html',     'branch.html',     301);
Route::redirect('tiar_callcenter.html', 'callcenter.html', 301);
Route::redirect('tiar_store.html',      'store.html',      301);
Route::redirect('tiar_stores.html',     'stores.html',     301);
Route::redirect('tiar_customer.html',   'customer.html',   301);
Route::redirect('tiar_customers.html',  'customers.html',  301);
Route::redirect('tiar_damascus.html',   'damascus.html',   301);
