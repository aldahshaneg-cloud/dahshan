<?php

declare(strict_types=1);

use App\Http\Controllers\V4\AuthController;
use App\Http\Controllers\V4\CallcenterController;
use Illuminate\Support\Facades\Route;

/*
|---------------------------------------------------------------------------
| مسارات الجيل الرابع (v4) — شاشات Blade، موازية للوحات الـHTML القديمة
|---------------------------------------------------------------------------
| بتتحمّل من bootstrap/app.php تحت البادئة `/v4` ومجموعة `v4` (جلسة النظام نفسها من غير
| تشفير كوكي — نفس مجموعة `api` — عشان الدخول يبقى واحد للقديم والجديد).
|
| ⚠️ ممنوع closures (route:cache). برّه بادئة /api فبوابة route:coverage مابتعدّهاش.
| اسم كل route = `v4.<app>.<page>` — والقائمة الجانبية بتتبني من config/v4.php.
*/

Route::get('login', [AuthController::class, 'show'])->name('v4.login');

Route::prefix('callcenter')->middleware('v4.auth:callcenter')->group(function (): void {
    Route::get('/', [CallcenterController::class, 'home'])->name('v4.callcenter.home');
    Route::get('stats', [CallcenterController::class, 'stats'])->name('v4.callcenter.stats');
    Route::get('new', [CallcenterController::class, 'newOrder'])->name('v4.callcenter.new');
    Route::get('search', [CallcenterController::class, 'search'])->name('v4.callcenter.search');
    Route::get('orders-data', [CallcenterController::class, 'ordersData'])->name('v4.callcenter.ordersData');
    Route::get('contacts', [CallcenterController::class, 'contacts'])->name('v4.callcenter.contacts');
    Route::get('form-data', [CallcenterController::class, 'formData'])->name('v4.callcenter.formData');
    Route::get('orders/{list}', [CallcenterController::class, 'orders'])
        ->where('list', 'active|delivering|delivered|undelivered|cancelled')->name('v4.callcenter.orders');
    Route::get('pilots', [CallcenterController::class, 'pilots'])->name('v4.callcenter.pilots');
    Route::get('zones', [CallcenterController::class, 'zones'])->name('v4.callcenter.zones');
    Route::get('clients', [CallcenterController::class, 'clients'])->name('v4.callcenter.clients');
    Route::get('soon/{page}', [CallcenterController::class, 'soon'])
        ->where('page', '[a-z]+')->name('v4.callcenter.soon');
});
