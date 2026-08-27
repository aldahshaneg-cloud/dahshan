<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\CustomerAppController;
use App\Http\Controllers\Api\DamascusController;
use App\Http\Controllers\Api\CustomersController;
use App\Http\Controllers\Api\EntitiesController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\OrdersController;
use App\Http\Controllers\Api\PilotAppController;
use App\Http\Controllers\Api\PublicSiteController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\TrustController;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

/*
|---------------------------------------------------------------------------
| مسارات API — الترحيل من جدول $routes في public/index.php
|---------------------------------------------------------------------------
| ⚠️ قاعدة الترتيب: **المسارات الحرفية تتسجّل قبل اللي فيها باراميتر**.
| الراوتر القديم كان بيعمل مطابقة حرفية الأول ثم يجرّب الأنماط، فمسار زي
| GET /api/orders/stats مكانش بيقع على GET /api/orders/{id}. راوتر لارافل
| بيمشي بالترتيب، فالترتيب هنا هو اللي بيحافظ على نفس السلوك.
|
| ملاحظة على الأدوار: بعض المسارات مالهاش middleware 'role' عن قصد — الأصل
| بيعمل require_auth() بس (أي حساب مسجّل) والتقييد بيتم جوه الكنترولر حسب
| النطاق. تحويلها لـmiddleware دور كان هيغيّر السلوك.
|
| 🧑‍✈️ pilot_supervisor («مشرف الطيارين») — دور تشغيلي بحت اتضاف 2026-08-20
| عشان يشيل حمل إدارة الأسطول عن الأدمن. القاعدة اللي اتبنى بيها: **قراءة
| وكتابة على الطيارين والدور والورديات والطلبات، وصفر فلوس**. فالمسارات
| المرفوضة له مقصودة مش منسية:
|   • pilots/{id}/custody · closeouts/monthly · shifts/{id}/settlement
|     · shifts/{id}/end · pilots/{id}/return — كلها بتحرّك خزنة/عهدة/مستحقات
|     (shiftEnd و pilotReturn بينادوا settlePilotMoney جوّه).
|   • return-requests/{id}/approve — بيحوّل الأوردر لـ«لم يتم التوصيل»
|     (تحصيل مش متم)؛ الرفض بس هو المسموح لأنه علامة عرض للطيار.
|   • كل /api/orders الكتابية (assign · transfer · settle-money · …)
|     و orders/stats — الدور بيشوف شغل الطيار قراءة بس.
|   • DELETE pilots/{id} — فاضل admin بس زي ما هو (أضيق من الإنشاء عن قصد).
|   • users · attendance · manual-employees · cash-stores · wallets · rd/*
|     · customers — بره نطاق الدور بالكامل.
| وحتى في المسموح، بيانات الفلوس اللي راكبة على كارت الطيار (العهدة والمرتب
| والعمولة) بتتشال قراءةً وكتابةً — شوف EntitiesController::pilotsList
| و pilotsCreate/pilotsUpdate و BoardController::dashboard.
|
| التحقق من العدد: php artisan route:list --path=api
|---------------------------------------------------------------------------
*/

/* ═══ الصحة والدخول ═══════════════════════════════════════════ */
Route::get('health', [AuthController::class, 'health']);
Route::post('login', [AuthController::class, 'login']);
Route::post('logout', [AuthController::class, 'logout']);
Route::get('me', [AuthController::class, 'me']);

/* ═══ مسارات عامة بلا مصادقة ══════════════════════════════════ */
Route::get('egypt', [EntitiesController::class, 'egyptList']);
Route::get('settings/site', [SupportController::class, 'settingsSite']);
Route::get('partners', [SupportController::class, 'partnersList']);
Route::get('public/coverage', [PublicSiteController::class, 'coverage']);
Route::get('track/{orderNum}', [PublicSiteController::class, 'track']);

/* ═══ الكيانات ════════════════════════════════════════════════ */
Route::get('branches', [EntitiesController::class, 'branchesList']);
Route::get('zones', [EntitiesController::class, 'zonesList']);
// pilots/{id}/custody قبل pilots عشان الوضوح — مفيش تعارض فعلي (شكل مختلف)
Route::get('pilots/{id}/custody', [FinanceController::class, 'pilotCustody'])
    ->middleware('role:admin,branch,accountant');
Route::get('pilots', [EntitiesController::class, 'pilotsList'])
    ->middleware('role:admin,branch,callcenter,pilot_supervisor');
Route::get('users', [EntitiesController::class, 'usersList'])->middleware('role:admin');
Route::get('admin-emails', [EntitiesController::class, 'adminEmailsList'])->middleware('role:admin');
Route::get('senders', [EntitiesController::class, 'sendersList'])
    ->middleware('role:admin,branch,callcenter');
Route::get('receivers', [EntitiesController::class, 'receiversList'])
    ->middleware('role:admin,branch,callcenter');
Route::get('store-contacts', [EntitiesController::class, 'storeContactsList']);

/* ═══ الأوردرات ═══════════════════════════════════════════════ */
// ⚠️ stats لازم تفضل قبل {id} — من غير كده الراوتر بيعتبر "stats" هو الـid
Route::get('orders/stats', [OrdersController::class, 'stats'])
    ->middleware('role:admin,callcenter');
Route::get('orders', [OrdersController::class, 'index']);
Route::get('orders/{id}', [OrdersController::class, 'show']);

/* ═══ لوحة العمليات ═══════════════════════════════════════════ */
Route::get('board', [BoardController::class, 'dashboard'])->middleware('role:admin,branch,pilot_supervisor');
Route::get('shifts', [BoardController::class, 'shiftsList']);
Route::get('closeouts/monthly', [BoardController::class, 'closeoutGet'])
    ->middleware('role:admin,branch');
Route::get('join-requests', [BoardController::class, 'joinRequestsList'])
    ->middleware('role:admin,branch,pilot_supervisor');
/* تعديلات عمولة الطيار — بديل لعمولة أوردر أو مبلغ مستقل بلا أوردر.
   مدير الفرع مقصوص على طيارين فرعه جوّه الكنترولر. */
Route::get('pilot-commission-adjustments', [BoardController::class, 'commissionAdjustmentsList'])
    ->middleware('role:admin,branch,accountant');
Route::get('leave-requests', [BoardController::class, 'leaveRequestsList']);
Route::get('shift-requests', [BoardController::class, 'shiftRequestsList']);
Route::get('return-requests', [BoardController::class, 'returnRequestsList']);
Route::get('pilot-transfers', [BoardController::class, 'transfersList'])
    ->middleware('role:admin,branch,pilot_supervisor');
Route::get('support-requests', [BoardController::class, 'supportRequestsList'])
    ->middleware('role:admin,branch,pilot_supervisor');

/* ═══ المالية ═════════════════════════════════════════════════ */
Route::get('cash-stores/{id}/transactions', [FinanceController::class, 'cashTxnsList'])
    ->middleware('role:admin,branch,accountant');
Route::get('cash-stores', [FinanceController::class, 'cashStoresList'])
    ->middleware('role:admin,branch,accountant');
Route::get('custody', [FinanceController::class, 'custodyList'])
    ->middleware('role:admin,branch,accountant');
Route::get('expenses', [FinanceController::class, 'expensesList'])
    ->middleware('role:admin,branch,accountant');
// المحفظة الواحدة: البوابة جوه الكنترولر (finance_require_wallet_access) —
// اتعملت عشان ثغرة IDOR على المحافظ، فمش middleware دور بسيط
Route::get('wallets/{ownerType}/{ownerId}', [FinanceController::class, 'walletGet']);
Route::get('wallets', [FinanceController::class, 'walletsList'])
    ->middleware('role:admin,accountant,callcenter');
/* 🔴 الكول سنتر اتضاف هنا عشان شاشة «أدائي» بقت بتقيس الأداء بـ«أوردر لكل
   ساعة عمل» — والساعات مصدرها الوحيد جلسات الحضور. الدور **مقصوص جوه
   الكنترولر على حضوره هو بس**: مايشوفش حضور زمايله ولا حضور الطيارين. */
Route::get('attendance', [FinanceController::class, 'attendanceList'])
    ->middleware('role:admin,branch,hr,accountant,callcenter');
Route::get('manual-employees', [FinanceController::class, 'manualEmployeesList'])
    ->middleware('role:admin,hr,branch');

/* ═══ الدعم والإعدادات ════════════════════════════════════════ */
Route::get('complaints', [SupportController::class, 'complaintsList'])
    ->middleware('role:admin,callcenter,branch,hr');
Route::get('zone-requests', [SupportController::class, 'zoneRequestsList'])
    ->middleware('role:admin,callcenter,branch');
// رسايل «اتصل بنا» — admin بس، أضيق من الشكاوى وطلبات المناطق:
// دي بيانات ناس من بره الشركة بلا تقنيع (شوف ContactWire)
Route::get('contact-messages', [SupportController::class, 'contactMessagesList'])
    ->middleware('role:admin');
// رسايل إشعار المستلمين بالأوردر — أوسع من contact-messages عن قصد:
// ده شغل تشغيلي يومي بيتعمل من الفرع والكول سنتر، ونفس البيانات اللي
// الأدوار دي شايفاها في شاشة الأوردرات. الفرع مقصوص على فرعه جوه الكنترولر
Route::get('order-notifications', [SupportController::class, 'orderNotificationsList'])
    ->middleware('role:admin,branch,callcenter');
// الأصل require_auth() بس — أي حساب مسجّل، فالدخول مفروض جوه الكنترولر
Route::get('settings', [SupportController::class, 'settingsGet']);

/* ═══ العملاء ═════════════════════════════════════════════════ */
Route::get('customers', [CustomersController::class, 'index'])
    ->middleware('role:admin,callcenter');
Route::get('store/pickup-profile', [CustomersController::class, 'pickupProfile'])
    ->middleware('role:store');

/* ═══ تطبيق الطيار (X-Auth-Token) ═════════════════════════════ */
Route::middleware('role:pilot')->group(function (): void {
    Route::get('pilot/state', [PilotAppController::class, 'state']);
    Route::get('pilot/active-orders', [PilotAppController::class, 'activeOrders']);
    Route::get('pilot/return-requests', [PilotAppController::class, 'returnRequests']);
    Route::get('pilot/leave-requests', [PilotAppController::class, 'leaveRequests']);
    Route::get('pilot/shift-requests', [PilotAppController::class, 'shiftRequests']);
    Route::get('pilot/finished-orders', [PilotAppController::class, 'finishedOrders']);
    Route::get('pilot/closeouts', [PilotAppController::class, 'closeouts']);
});

/* ═══ منظومة الثقة ════════════════════════════════════════════ */
// lookup-log قبل {phone} — أشكال مختلفة بس بنسجّل الحرفي الأول على أي حال
Route::get('trust/lookup-log', [TrustController::class, 'lookupLog'])->middleware('role:admin');
Route::get('trust/{phone}/ratings', [TrustController::class, 'ratings'])
    ->middleware('role:store,customer,branch,callcenter,admin');
Route::get('lookup', [TrustController::class, 'lookup'])
    ->middleware('role:store,customer,branch,callcenter,admin');


/* ═══════════════════════════════════════════════════════════════
   مسارات الكتابة
   ⚠️ المسارات الحرفية قبل اللي فيها باراميتر — نفس قاعدة القراءة.
   💰 = بيلمس فلوس. كلها جوه DB::transaction فالتراجع تلقائي.
═══════════════════════════════════════════════════════════════ */

/* ── الكيانات ── */
Route::post('branches', [EntitiesController::class, 'branchesCreate'])->middleware('role:admin');
Route::put('branches/{id}', [EntitiesController::class, 'branchesUpdate'])->middleware('role:admin');

Route::post('zones', [EntitiesController::class, 'zonesCreate'])->middleware('role:admin,branch,callcenter');
Route::put('zones/{id}', [EntitiesController::class, 'zonesUpdate'])->middleware('role:admin,branch,callcenter');
Route::delete('zones/{id}', [EntitiesController::class, 'zonesDelete'])->middleware('role:admin,branch,callcenter');

Route::post('pilots', [EntitiesController::class, 'pilotsCreate'])->middleware('role:admin,branch,callcenter,pilot_supervisor');
Route::put('pilots/{id}', [EntitiesController::class, 'pilotsUpdate'])->middleware('role:admin,branch,callcenter,pilot_supervisor');
Route::delete('pilots/{id}', [EntitiesController::class, 'pilotsDelete'])->middleware('role:admin');

Route::post('users', [EntitiesController::class, 'usersCreate'])->middleware('role:admin');
Route::post('users/{id}/block', [EntitiesController::class, 'usersBlock'])->middleware('role:admin');
Route::put('users/{id}', [EntitiesController::class, 'usersUpdate'])->middleware('role:admin');
Route::delete('users/{id}', [EntitiesController::class, 'usersDelete'])->middleware('role:admin');

Route::post('admin-emails', [EntitiesController::class, 'adminEmailsCreate'])->middleware('role:admin');
Route::delete('admin-emails/{id}', [EntitiesController::class, 'adminEmailsDelete'])->middleware('role:admin');

// lookup قبل أي {id} على نفس الجذر — بحث بالتليفون للموظفين بس
Route::get('senders/lookup', [EntitiesController::class, 'sendersLookup'])
    ->middleware('role:admin,branch,callcenter');
/* بوابة العهدة: العميل ده يستاهل عهدة ولا لأ؟ الواجهة بتقفل الخانة بيها،
   والحارس الحقيقي في POST /api/orders (الواجهة مابتحرسش فلوس). */
Route::get('senders/{id}/custody', [EntitiesController::class, 'senderCustodyGate'])
    ->middleware('role:admin,branch,callcenter');
Route::get('receivers/lookup', [EntitiesController::class, 'receiversLookup'])
    ->middleware('role:admin,branch,callcenter');
// 🔒 الـupsert ده كان بيرجّع الصف الموجود كامل لو الرقم متكرر — التفاف تام
// على قاعدة الخصوصية. entities_party_wire_private بتطبّق نفس قاعدة /api/lookup.
Route::post('senders', [EntitiesController::class, 'sendersCreate']);
Route::post('receivers', [EntitiesController::class, 'receiversCreate']);
Route::put('senders/{id}', [EntitiesController::class, 'sendersUpdate'])
    ->middleware('role:admin,branch,callcenter');
Route::put('receivers/{id}', [EntitiesController::class, 'receiversUpdate'])
    ->middleware('role:admin,branch,callcenter');

Route::post('store-contacts', [EntitiesController::class, 'storeContactsCreate']);
Route::delete('store-contacts/{id}', [EntitiesController::class, 'storeContactsDelete']);

/* ── الأوردرات ── */
Route::post('upload', [OrdersController::class, 'upload']);
// bulk قبل {id} — أشكال مختلفة بس بنلتزم بالقاعدة
/* 🔴 الكول سنتر **اترجع واتقفل** 2026-08-25 بقرار صاحب النظام، بعد ما كان
   اتفتح 2026-08-23. القرار الجديد بالحرف: «الكول سنتر ليس له سلطة على الطيار
   أو الفرع غير أنه يرسل الأوردر». فالتحميل على طيار (assign · assign-bulk)
   والنقل من طيار لطيار (transfer) رجعوا للفرع والإدارة بس.

   ⛔ deliver و undeliver فضلوا مقفولين من الأول (بيقفلوا الأوردر ماليًا).

   ✅ اللي فضل مفتوح للكول سنتر عن قصد — ده شغله هو مش سلطة على حد:
      • store            — تسجيل الأوردر وإرساله للفرع (جوهر الدور).
      • cancel/postpone  — العميل بيتصل يلغي أو يأجّل، والموظف على التليفون.
      • transfer-branch  — تصحيح **غلطته هو** لما يبعت الأوردر لفرع غلط.
      • update           — تصحيح بيانات العميل والعنوان بعد المكالمة.

   الأزرار في callcenter.html اتخفت كمان (isCC)، بس الإخفاء تجميل —
   القفل الحقيقي هو السطور دي. */
Route::post('orders/assign-bulk', [OrdersController::class, 'assignBulk'])->middleware('role:branch,admin');
Route::post('orders/settle-money-bulk', [OrdersController::class, 'settleMoneyBulk'])->middleware('role:branch,admin');
Route::post('orders', [OrdersController::class, 'store'])
    ->middleware('role:branch,admin,callcenter,store,customer');
Route::put('orders/{id}', [OrdersController::class, 'update'])->middleware('role:branch,admin,callcenter');
Route::post('orders/{id}/split', [OrdersController::class, 'split'])->middleware('role:branch,admin');
Route::post('orders/{id}/images', [OrdersController::class, 'addImages']);
Route::post('orders/{id}/assign', [OrdersController::class, 'assign'])->middleware('role:branch,admin');
Route::post('orders/{id}/transfer', [OrdersController::class, 'transfer'])->middleware('role:branch,admin');
Route::post('orders/{id}/transfer-branch', [OrdersController::class, 'transferBranch'])
    ->middleware('role:branch,admin,callcenter');
Route::post('orders/{id}/receive', [OrdersController::class, 'receive'])->middleware('role:pilot,branch,admin');
Route::post('orders/{id}/start-trip', [OrdersController::class, 'startTrip'])->middleware('role:pilot,branch,admin');
Route::post('orders/{id}/deliver', [OrdersController::class, 'deliver'])->middleware('role:pilot,branch,admin');
Route::post('orders/{id}/undeliver', [OrdersController::class, 'undeliver'])->middleware('role:pilot,branch,admin');
Route::post('orders/{id}/cancel', [OrdersController::class, 'cancel'])->middleware('role:branch,admin,callcenter');
Route::post('orders/{id}/postpone', [OrdersController::class, 'postpone'])->middleware('role:branch,admin,callcenter');
Route::post('orders/{id}/unpostpone', [OrdersController::class, 'unpostpone'])->middleware('role:branch,admin,callcenter');
Route::post('orders/{id}/settle-money', [OrdersController::class, 'settleMoney'])->middleware('role:branch,admin');
// 💰 تعديل عمولة طيار — بيدخل مستحقاته فعلًا، فالكتابة للمدير ومدير الفرع بس
Route::post('pilot-commission-adjustments', [BoardController::class, 'commissionAdjustmentSave'])
    ->middleware('role:admin,branch');
Route::delete('pilot-commission-adjustments/{id}', [BoardController::class, 'commissionAdjustmentDelete'])
    ->middleware('role:admin,branch');

/* ── لوحة العمليات: الدور والورديات ── */
Route::post('queue/enter', [BoardController::class, 'queueEnter'])->middleware('role:admin,branch,pilot_supervisor');
Route::post('queue/reorder', [BoardController::class, 'queueReorder'])->middleware('role:admin,branch,pilot_supervisor');
Route::post('queue/leave', [BoardController::class, 'queueLeave'])->middleware('role:admin,branch,pilot_supervisor');
Route::post('shifts/open', [BoardController::class, 'shiftOpen'])->middleware('role:admin,branch,pilot_supervisor');
Route::post('shifts/{id}/end', [BoardController::class, 'shiftEnd'])->middleware('role:admin,branch');
Route::post('shifts/{id}/transfer', [BoardController::class, 'shiftTransfer'])->middleware('role:admin,branch,pilot_supervisor');
Route::post('shifts/{id}/settlement', [BoardController::class, 'shiftSettlement'])->middleware('role:admin,branch');
Route::post('pilots/{id}/return', [BoardController::class, 'pilotReturn'])->middleware('role:admin,branch');
Route::post('pilots/{id}/force-leave', [BoardController::class, 'pilotForceLeave'])->middleware('role:admin,branch,pilot_supervisor');
Route::post('closeouts/monthly', [BoardController::class, 'closeoutSave'])->middleware('role:admin,branch');


/* ═══════════════════════════════════════════════════════════════
   باقي المسارات — الجولة التانية (اتولّدت آليًا من مخرج الوكلاء)
   الترتيب: الحرفي قبل اللي فيه باراميتر، وبعدد المقاطع.
   💰 = بيلمس فلوس.
═══════════════════════════════════════════════════════════════ */

/* 🔴 إنشاء الخزنة للمدير العام بس — قرار صاحب النظام 2026-08-26.
   كان مفتوح لمشرف الفرع والمحاسب كمان، فكان أي فرع يقدر يفتح خزنة
   لنفسه من غير علم الإدارة، والفلوس تتحرّك جوّه خزنة محدش واخد باله
   منها. الفتح والإغلاق قرار إداري مش تشغيلي. */
Route::post('cash-stores', [FinanceController::class, 'cashStoresCreate'])
    ->middleware('role:admin');   // 💰
Route::post('complaints', [SupportController::class, 'complaintsCreate'])
    ->middleware('role:admin,callcenter,branch');
Route::post('custody', [FinanceController::class, 'custodyCreate'])
    ->middleware('role:admin,branch,accountant');   // 💰
Route::post('expenses', [FinanceController::class, 'expensesCreate'])
    ->middleware('role:admin,branch,accountant');   // 💰
Route::post('join-requests', [BoardController::class, 'joinRequestCreate'])
    ->middleware('role:admin,branch,pilot_supervisor');
Route::post('leave-requests', [BoardController::class, 'leaveRequestCreate'])
    ->middleware('role:pilot,admin,branch,pilot_supervisor');
Route::post('manual-employees', [FinanceController::class, 'manualEmployeesCreate'])
    ->middleware('role:admin,hr');
Route::post('partners', [SupportController::class, 'partnersCreate'])
    ->middleware('role:admin');
Route::post('pilot-transfers', [BoardController::class, 'transferCreate'])
    ->middleware('role:admin,branch,pilot_supervisor');
Route::post('return-requests', [BoardController::class, 'returnRequestCreate'])
    ->middleware('role:pilot');
Route::put('settings', [SupportController::class, 'settingsPut'])
    ->middleware('role:admin');
Route::post('shift-requests', [BoardController::class, 'shiftRequestCreate'])
    ->middleware('role:pilot');
Route::post('support-requests', [BoardController::class, 'supportRequestCreate'])
    ->middleware('role:admin,branch,pilot_supervisor');
Route::post('zone-requests', [SupportController::class, 'zoneRequestsCreate'])
    ->middleware('role:admin,callcenter,branch');
Route::post('admin/google-login', [AuthController::class, 'adminGoogleLogin']);
Route::post('attendance/check-in', [FinanceController::class, 'attendanceCheckIn']);
Route::post('attendance/check-out', [FinanceController::class, 'attendanceCheckOut']);
Route::post('attendance/heartbeat', [FinanceController::class, 'attendanceHeartbeat']);
Route::get('customer/addresses', [CustomerAppController::class, 'addressesList']);
Route::post('customer/addresses', [CustomerAppController::class, 'addressesCreate']);
Route::get('customer/incoming', [CustomerAppController::class, 'incoming']);
Route::post('customer/login', [CustomerAppController::class, 'login']);   // 💰
Route::get('customer/me', [CustomerAppController::class, 'me']);   // 💰
Route::get('customer/notifications', [CustomerAppController::class, 'notifications']);
Route::get('customer/orders', [CustomerAppController::class, 'ordersList']);
Route::post('customer/orders', [CustomerAppController::class, 'orderCreate']);   // 💰
Route::put('customer/profile', [CustomerAppController::class, 'profileUpdate']);
Route::get('customer/receivers', [CustomerAppController::class, 'receiversList']);
Route::post('customer/receivers', [CustomerAppController::class, 'receiversUpsert']);
Route::post('pilot/leave-requests', [BoardController::class, 'leaveRequestCreate'])
    ->middleware('role:pilot');
Route::post('pilot/location', [PilotAppController::class, 'location'])
    ->middleware('role:pilot');
Route::post('pilot/return-requests', [BoardController::class, 'returnRequestCreate'])
    ->middleware('role:pilot');
Route::post('pilot/shift-requests', [BoardController::class, 'shiftRequestCreate'])
    ->middleware('role:pilot');
Route::post('pilot/version', [PilotAppController::class, 'version'])
    ->middleware('role:pilot');
// 🔓 فورم «اتصل بنا» — عام بلا مصادقة زي join-request بالظبط، بس عليه
// حد معدّل جوه الكنترولر (3 رسايل/ساعة لنفس رقم الموبايل)
Route::post('public/contact-message', [PublicSiteController::class, 'contactMessage']);
Route::post('public/join-request', [PublicSiteController::class, 'joinRequest']);
Route::get('rd/bootstrap', [DamascusController::class, 'bootstrap']);   // 💰
Route::get('rd/branches', [DamascusController::class, 'branchesList']);
Route::post('rd/branches', [DamascusController::class, 'branchesCreate']);
Route::get('rd/deferred', [DamascusController::class, 'deferredList']);   // 💰
Route::post('rd/deferred', [DamascusController::class, 'deferredCreate']);   // 💰
Route::get('rd/entries', [DamascusController::class, 'entriesList']);   // 💰
Route::put('rd/entries', [DamascusController::class, 'entriesSave']);   // 💰
Route::put('rd/entry-perms', [DamascusController::class, 'entryPermsSave']);   // 💰
Route::post('rd/month-lock', [DamascusController::class, 'monthLockCreate']);
Route::delete('rd/month-lock', [DamascusController::class, 'monthLockDelete']);
Route::get('rd/month-locks', [DamascusController::class, 'monthLocksList']);
Route::get('rd/perms', [DamascusController::class, 'permsList']);
Route::put('rd/perms', [DamascusController::class, 'permsSave']);
Route::get('rd/pilot-sheet', [DamascusController::class, 'pilotSheet']);   // 💰
Route::get('rd/pilots', [DamascusController::class, 'pilotsList']);   // 💰
Route::post('rd/pilots', [DamascusController::class, 'pilotsCreate']);   // 💰
Route::get('rd/settings', [DamascusController::class, 'settingsGet']);   // 💰
Route::put('rd/settings', [DamascusController::class, 'settingsPut']);   // 💰
Route::get('rd/summaries', [DamascusController::class, 'summariesList']);   // 💰
Route::put('rd/summaries', [DamascusController::class, 'summariesSave']);   // 💰
Route::put('store/pickup-profile', [CustomersController::class, 'pickupProfileSave'])
    ->middleware('role:store');
Route::post('trust/rate', [TrustController::class, 'rateDirect'])
    ->middleware('role:admin');
Route::post('customer/notifications/seen', [CustomerAppController::class, 'notificationsSeen']);
// إشعارات ستارة الهاتف (Web Push) لتطبيق العملاء — إضافة بعد الترحيل،
// مصادقة العميل جوه الكنترولر (customerRequire) زي باقي مسارات customer/*
Route::get('customer/push/key', [CustomerAppController::class, 'pushKey']);
Route::post('customer/push/subscribe', [CustomerAppController::class, 'pushSubscribe']);
Route::post('customer/push/unsubscribe', [CustomerAppController::class, 'pushUnsubscribe']);
Route::post('pilot/leave-requests/end', [PilotAppController::class, 'leaveEnd'])
    ->middleware('role:pilot');
Route::post('pilot/shift/end', [PilotAppController::class, 'shiftEnd'])
    ->middleware('role:pilot');
Route::get('rd/closeout/day', [DamascusController::class, 'closeoutDay']);   // 💰
Route::get('rd/closeout/month', [DamascusController::class, 'closeoutMonth']);   // 💰
Route::put('cash-stores/{id}', [FinanceController::class, 'cashStoresUpdate'])
    ->middleware('role:admin,accountant');   // 💰
Route::delete('cash-stores/{id}', [FinanceController::class, 'cashStoresDelete'])
    ->middleware('role:admin');   // 💰
Route::put('customers/{id}', [CustomersController::class, 'update'])
    ->middleware('role:admin');
Route::delete('customers/{id}', [CustomersController::class, 'destroy'])
    ->middleware('role:admin');   // 💰
Route::put('expenses/{id}', [FinanceController::class, 'expensesUpdate'])
    ->middleware('role:admin,branch,accountant');   // 💰
Route::delete('expenses/{id}', [FinanceController::class, 'expensesDelete'])
    ->middleware('role:admin,accountant');   // 💰
Route::put('manual-employees/{id}', [FinanceController::class, 'manualEmployeesUpdate'])
    ->middleware('role:admin,hr');
Route::delete('manual-employees/{id}', [FinanceController::class, 'manualEmployeesDelete'])
    ->middleware('role:admin,hr');
Route::put('partners/{id}', [SupportController::class, 'partnersUpdate'])
    ->middleware('role:admin');
Route::delete('partners/{id}', [SupportController::class, 'partnersDelete'])
    ->middleware('role:admin');
Route::post('cash-stores/{id}/transactions', [FinanceController::class, 'cashTxnsCreate'])
    ->middleware('role:admin,branch,accountant');   // 💰
Route::post('cash-transactions/{id}/approve', [FinanceController::class, 'cashTxnsApprove'])
    ->middleware('role:admin,branch,accountant');   // 💰
Route::post('complaints/{id}/resolve', [SupportController::class, 'complaintsResolve'])
    ->middleware('role:admin,callcenter');
// تعليم رسالة تواصل كمقروءة — admin بس، ونداؤها المكرر سليم (idempotent)
Route::put('contact-messages/{id}/read', [SupportController::class, 'contactMessageRead'])
    ->middleware('role:admin');
// «الموظف فتح الواتساب وبعتها» — بيسجّل sent_by، ونداؤه المكرر سليم
// (idempotent) بشرط sent_at IS NULL زي contact-messages/{id}/read
Route::post('order-notifications/{id}/sent', [SupportController::class, 'orderNotificationSent'])
    ->middleware('role:admin,branch,callcenter');
Route::put('customer/addresses/{id}', [CustomerAppController::class, 'addressesUpdate']);
Route::delete('customer/addresses/{id}', [CustomerAppController::class, 'addressesDelete']);
Route::post('customers/{id}/block', [CustomersController::class, 'block'])
    ->middleware('role:admin');
Route::post('customers/{id}/zone', [CustomersController::class, 'setZone'])
    ->middleware('role:admin');
Route::post('join-requests/{id}/approve', [BoardController::class, 'joinRequestApprove'])
    ->middleware('role:admin,pilot_supervisor');
Route::post('join-requests/{id}/reject', [BoardController::class, 'joinRequestReject'])
    ->middleware('role:admin,pilot_supervisor');
Route::post('leave-requests/{id}/approve', [BoardController::class, 'leaveRequestApprove'])
    ->middleware('role:admin,branch,pilot_supervisor');
Route::post('leave-requests/{id}/end', [BoardController::class, 'leaveRequestEnd'])
    ->middleware('role:pilot,admin,branch,pilot_supervisor');
Route::post('leave-requests/{id}/reject', [BoardController::class, 'leaveRequestReject'])
    ->middleware('role:admin,branch,pilot_supervisor');
Route::post('orders/{id}/apply-wallet', [SupportController::class, 'applyWallet'])
    ->middleware('role:admin,store,customer');   // 💰
Route::post('orders/{id}/clear-return-flag', [BoardController::class, 'orderClearReturnFlag'])
    ->middleware('role:pilot,admin,branch');
Route::post('orders/{id}/rate-receiver', [TrustController::class, 'rateReceiver']);
Route::post('orders/{id}/rating', [SupportController::class, 'orderRating']);
Route::post('pilot-transfers/{id}/approve', [BoardController::class, 'transferApprove'])
    ->middleware('role:admin,pilot_supervisor');
Route::post('pilot-transfers/{id}/end', [BoardController::class, 'transferEnd'])
    ->middleware('role:admin,branch,pilot_supervisor');
Route::post('pilot-transfers/{id}/reject', [BoardController::class, 'transferReject'])
    ->middleware('role:admin,pilot_supervisor');
Route::put('rd/branches/{id}', [DamascusController::class, 'branchesUpdate']);
Route::delete('rd/branches/{id}', [DamascusController::class, 'branchesDelete']);
Route::put('rd/deferred/{id}', [DamascusController::class, 'deferredUpdate']);   // 💰
Route::delete('rd/deferred/{id}', [DamascusController::class, 'deferredDelete']);   // 💰
Route::put('rd/pilots/{id}', [DamascusController::class, 'pilotsUpdate']);   // 💰
Route::delete('rd/pilots/{id}', [DamascusController::class, 'pilotsDelete']);
Route::post('return-requests/{id}/approve', [BoardController::class, 'returnRequestApprove'])
    ->middleware('role:admin,branch');   // 💰
Route::post('return-requests/{id}/reject', [BoardController::class, 'returnRequestReject'])
    ->middleware('role:admin,branch,pilot_supervisor');
Route::post('shift-requests/{id}/approve', [BoardController::class, 'shiftRequestApprove'])
    ->middleware('role:admin,branch,pilot_supervisor');
Route::post('shift-requests/{id}/reject', [BoardController::class, 'shiftRequestReject'])
    ->middleware('role:admin,branch,pilot_supervisor');
Route::post('support-requests/{id}/accept-pilot', [BoardController::class, 'supportAcceptPilot'])
    ->middleware('role:admin,branch,pilot_supervisor');
Route::post('support-requests/{id}/cancel', [BoardController::class, 'supportRequestCancel'])
    ->middleware('role:admin,branch,pilot_supervisor');
Route::post('support-requests/{id}/respond', [BoardController::class, 'supportRequestRespond'])
    ->middleware('role:admin,branch,pilot_supervisor');
Route::post('support-requests/{id}/send-pilot', [BoardController::class, 'supportSendPilot'])
    ->middleware('role:admin,branch,pilot_supervisor');
Route::put('trust/{phone}/identity', [TrustController::class, 'identitySave'])
    ->middleware('role:admin,branch,callcenter');
Route::post('zone-requests/{id}/mark-added', [SupportController::class, 'zoneRequestsMarkAdded'])
    ->middleware('role:admin,callcenter');
Route::post('customer/orders/{id}/cancel', [CustomerAppController::class, 'orderCancel']);   // 💰
Route::post('customer/orders/{id}/rating', [CustomerAppController::class, 'orderRating']);
Route::get('customer/orders/{id}/track', [CustomerAppController::class, 'orderTrack']);
Route::post('pilot/orders/{id}/deliver', [OrdersController::class, 'deliver'])
    ->middleware('role:pilot');   // 💰
Route::post('pilot/orders/{id}/undeliver', [OrdersController::class, 'undeliver'])
    ->middleware('role:pilot');
Route::put('rd/deferred/{id}/payment', [DamascusController::class, 'deferredPayment']);   // 💰
Route::post('wallets/{ownerType}/{ownerId}/credit', [FinanceController::class, 'walletCredit'])
    ->middleware('role:admin,branch,accountant');   // 💰
Route::post('wallets/{ownerType}/{ownerId}/debit', [FinanceController::class, 'walletDebit'])
    ->middleware('role:admin,branch,accountant');   // 💰
Route::post('wallets/{ownerType}/{ownerId}/use', [FinanceController::class, 'walletUse']);   // 💰

/* ═══ أي مسار مش موجود ════════════════════════════════════════ */
Route::fallback(fn () => ApiResponse::fail('المسار غير موجود', 404));
