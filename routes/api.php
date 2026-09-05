<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AlertsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\CustomerAppController;
use App\Http\Controllers\Api\DamascusController;
use App\Http\Controllers\Api\CustomersController;
use App\Http\Controllers\Api\EntitiesController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\OrderUrgeController;
use App\Http\Controllers\Api\OrdersController;
use App\Http\Controllers\Api\PilotAccountingController;
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
// ⚠️ وكذلك urges — لازم تفضل قبل {id} لنفس السبب
Route::get('orders/urges', [OrderUrgeController::class, 'list'])
    ->middleware('role:admin,branch,pilot,callcenter');
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
/* 🔒 كانوا **بلا أي قيد دور** — فالمحل والعميل والكول سنتر كانوا بيقروا
   أذونات وورديات وطلبات إرجاع كل الطيارين. نفس قايمة الأدوار اللي على
   pilot-transfers و support-requests تحتيهم بالظبط. */
Route::get('leave-requests', [BoardController::class, 'leaveRequestsList'])
    ->middleware('role:admin,branch,pilot,pilot_supervisor');
Route::get('shift-requests', [BoardController::class, 'shiftRequestsList'])
    ->middleware('role:admin,branch,pilot,pilot_supervisor');
Route::get('return-requests', [BoardController::class, 'returnRequestsList'])
    ->middleware('role:admin,branch,pilot,pilot_supervisor');
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

/* 🚨 أعطال الإنتاج — الوجه المرئي لجدول error_alerts.
   الإدارة بس: التفاصيل فيها مسارات ملفات ومكدس. */
Route::get('alerts', [AlertsController::class, 'index'])->middleware('role:admin');
Route::post('alerts/{id}/resolve', [AlertsController::class, 'resolve'])->middleware('role:admin');
Route::post('alerts/{id}/reopen', [AlertsController::class, 'reopen'])->middleware('role:admin');
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
// دفتر عناوين بوابة المحلات — «عناويني» زي تطبيق العملاء (طلب 2026-09-02)
Route::get('store/addresses', [CustomersController::class, 'storeAddressesList'])
    ->middleware('role:store');
Route::post('store/addresses', [CustomersController::class, 'storeAddressesCreate'])
    ->middleware('role:store');
Route::put('store/addresses/{id}', [CustomersController::class, 'storeAddressesUpdate'])
    ->middleware('role:store');
Route::delete('store/addresses/{id}', [CustomersController::class, 'storeAddressesDelete'])
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

/* 🔒 الكول سنتر اتشال — المناطق بتحدّد الأسعار والفروع، ودي مش من شغله
   («تفاصيل وشكوى بس»). وحارس الفرع على الحذف جوّه الكنترولر. */
Route::post('zones', [EntitiesController::class, 'zonesCreate'])->middleware('role:admin,branch');
Route::put('zones/{id}', [EntitiesController::class, 'zonesUpdate'])->middleware('role:admin,branch');
Route::delete('zones/{id}', [EntitiesController::class, 'zonesDelete'])->middleware('role:admin,branch');

Route::post('pilots', [EntitiesController::class, 'pilotsCreate'])->middleware('role:admin,branch,callcenter,pilot_supervisor');
/* 🔒 الكول سنتر اتشال (قرار «تفاصيل وشكوى بس») — كان بيقدر يغيّر مرتب
   وعمولة وفرع أي طيار. وحارس الفرع للدور branch جوّه الكنترولر. */
Route::put('pilots/{id}', [EntitiesController::class, 'pilotsUpdate'])->middleware('role:admin,branch,pilot_supervisor');
/* 🗄️ الحذف اتلغى والبديل الأرشفة (قرار صاحب النظام 2026-09-01).
   المسار سايب مسجّل عشان `route:coverage` يفضل 219/219 وعشان أي واجهة
   قديمة مكاشة تاخد رسالة عربية واضحة — الكنترولر بيرمي 403 دايمًا. */
Route::delete('pilots/{id}', [EntitiesController::class, 'pilotsDelete'])->middleware('role:admin');
Route::post('pilots/{id}/archive', [EntitiesController::class, 'pilotsArchive'])->middleware('role:admin');
Route::post('pilots/{id}/unarchive', [EntitiesController::class, 'pilotsUnarchive'])->middleware('role:admin');

Route::post('users', [EntitiesController::class, 'usersCreate'])->middleware('role:admin');
Route::post('users/{id}/block', [EntitiesController::class, 'usersBlock'])->middleware('role:admin');
/* 🏪 فتح/قفل تعديل سعر التوصيل لمحل بعينه (طلب 2026-09-03) — إدارة المحلات */
Route::post('users/{id}/price-edit', [EntitiesController::class, 'usersPriceEdit'])->middleware('role:admin');
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
/* 🔴 صلاحيات الكول سنتر على الأوردر — التاريخ باختصار:
     2026-08-23  اتفتحت
     2026-08-25  اترجعت واتقفلت جزئيًا («ليس له سلطة على الطيار أو الفرع
                 غير أنه يرسل الأوردر») — فـassign و assign-bulk و
                 transfer رجعوا للفرع والإدارة بس.
     2026-08-30  اتقفلت بالكامل. القرار بالحرف: «الكول سنتر يتعامل مع
                 العميل فقط في تلقي الأوردر والشكاوى، أما باقي العمل من
                 اختصاص باقي المنظومة».

   ✅ اللي فضل للكول سنتر — تلقّي الأوردر والشكوى وبس:
      • orders (store)   — تسجيل الأوردر وإرساله للفرع (جوهر الدور).
      • complaints       — تسجيل شكوى العميل.
      • senders/receivers — بيانات العملاء نفسهم وقت المكالمة.

   ⛔ اللي اتسحب منه 2026-08-30:
      • cancel · postpone · unpostpone — بقوا شغل الفرع والإدارة، وزراير
        التأجيل اتضافت لـbranch.html و tiar.html في نفس اليوم.
      • transfer-branch  — تصحيح الفرع الغلط بقى شغل الفرع/الإدارة.
      • update (PUT)     — تصحيح بيانات العميل على أوردر موجود؛ الواجهة
        بتاعته موجودة أصلًا في branch.html و tiar.html.

   ⛔ deliver و undeliver مقفولين من الأول (بيقفلوا الأوردر ماليًا).

   الأزرار في callcenter.html اتشالت كمان، بس الشيل تجميل —
   القفل الحقيقي هو السطور دي. الحارس: ops/test_cc_routes.php */
Route::post('orders/assign-bulk', [OrdersController::class, 'assignBulk'])->middleware('role:branch,admin');
Route::post('orders/settle-money-bulk', [OrdersController::class, 'settleMoneyBulk'])->middleware('role:branch,admin');
Route::post('orders', [OrdersController::class, 'store'])
    ->middleware('role:branch,admin,callcenter,store,customer');
Route::put('orders/{id}', [OrdersController::class, 'update'])->middleware('role:branch,admin');
Route::post('orders/{id}/split', [OrdersController::class, 'split'])->middleware('role:branch,admin');
/* 🔴 كان بلا `role:` خالص — يعني أي حساب مسجّل (عميل تطبيق · محل · طيار ·
   محاسب · hr) يقدر يناديه على **أي** أوردر. والدالة بترجّع
   `OrderWire::full` كامل (اسم المستلم وتليفوناته وعنوانه ودبوسه والأسعار
   والتحصيل وبيانات المُرسِل)، وبتكتب روابط صور خام على الطرد كمان.
   الأدوار دي هي اللي بترفع صور فعلًا: الطيار (إثبات التسليم) والفرع
   والإدارة والمحل (صور الطرد وقت التسجيل). */
Route::post('orders/{id}/images', [OrdersController::class, 'addImages'])
    ->middleware('role:pilot,branch,admin,store');
Route::post('orders/{id}/assign', [OrdersController::class, 'assign'])->middleware('role:branch,admin');
Route::post('orders/{id}/transfer', [OrdersController::class, 'transfer'])->middleware('role:branch,admin');
Route::post('orders/{id}/transfer-branch', [OrdersController::class, 'transferBranch'])
    ->middleware('role:branch,admin');
Route::post('orders/{id}/receive', [OrdersController::class, 'receive'])->middleware('role:pilot,branch,admin');
/* ✅ «سلّمت الأوردر للطيار» من بوابة المحل. تأكيد مش بوابة — الطيار
   بيقدر يبدأ رحلته حتى لو المحل نسي يدوس، والطابع بيتملا لوحده ساعتها. */
Route::post('orders/{id}/handover', [OrdersController::class, 'handover'])
    ->middleware('role:store,branch,admin');
Route::post('orders/{id}/start-trip', [OrdersController::class, 'startTrip'])->middleware('role:pilot,branch,admin');
Route::post('orders/{id}/deliver', [OrdersController::class, 'deliver'])->middleware('role:pilot,branch,admin');
Route::post('orders/{id}/undeliver', [OrdersController::class, 'undeliver'])->middleware('role:pilot,branch,admin');
Route::post('orders/{id}/cancel', [OrdersController::class, 'cancel'])->middleware('role:branch,admin');
Route::post('orders/{id}/postpone', [OrdersController::class, 'postpone'])->middleware('role:branch,admin');
Route::post('orders/{id}/unpostpone', [OrdersController::class, 'unpostpone'])->middleware('role:branch,admin');
Route::post('orders/{id}/settle-money', [OrdersController::class, 'settleMoney'])->middleware('role:branch,admin');

/* ⏰ «الأوردر جاهز — برجاء الاستعجال» من بوابة المحل.
   المحل بيستعجل أوردراته هو بس (الفحص جوّه الكنترولر على added_by)،
   والتنبيه بيروح للفرع لو الأوردر لسه في المكتب أو للطيار لو متحمّل.
   الحد: استعجال كل 4 دقايق لكل أوردر. */
Route::post('orders/{id}/urge', [OrderUrgeController::class, 'urge'])
    ->middleware('role:store,admin,branch');
Route::post('orders/urges/seen', [OrderUrgeController::class, 'seen'])
    ->middleware('role:admin,branch,pilot,callcenter');
// 💰 تعديل عمولة طيار — بيدخل مستحقاته فعلًا، فالكتابة للمدير ومدير الفرع بس
Route::post('pilot-commission-adjustments', [BoardController::class, 'commissionAdjustmentSave'])
    ->middleware('role:admin,branch');
Route::delete('pilot-commission-adjustments/{id}', [BoardController::class, 'commissionAdjustmentDelete'])
    ->middleware('role:admin,branch');

/* ═══ 💰 قسم حسابات الطيارين — الشيت اليومي والتقفيلة والسلف المؤجلة ═══
   نموذج «روح دمشق» مطبّق على طياري الشركة، بفرق إن الخانات بتتملى من
   المنظومة. القراءة لمشرف الفرع والمحاسب والإدارة (والكنترولر بيقصّ
   مشرف الفرع على فرعه)، والكتابة على الشيت لمشرف الفرع والإدارة.
   السلف المؤجلة وقفل الشهر والإعدادات **للإدارة بس** — قرارات مالية. */
Route::get('pilot-accounting/month', [PilotAccountingController::class, 'month'])
    ->middleware('role:admin,branch,accountant');
Route::get('pilot-accounting/deferred', [PilotAccountingController::class, 'deferredList'])
    ->middleware('role:admin,branch,accountant');
Route::get('pilot-accounting/settings', [PilotAccountingController::class, 'settingsGet'])
    ->middleware('role:admin,branch,accountant');

/* المحاسب اتضاف 2026-08-31 مع برنامج «تقفيل الطيارين» المستقل
   (public/accounts.html). بياخد اللي مشرف الفرع بياخده بالظبط: يملا
   الشيت ويظبّط أعمدة الصلاحيات. أما السلف والقفل والإعدادات تحت
   فبيفضلوا للإدارة — قرارات مالية زي ما التعليق فوق بيقول. */
Route::post('pilot-accounting/entry', [PilotAccountingController::class, 'entrySave'])
    ->middleware('role:admin,branch,accountant');
/* 💸 صرف الرواتب من الخزنة على الأرقام المعتمدة بعد القفل (2026-09-04) */
Route::post('pilot-accounting/payout', [PilotAccountingController::class, 'payoutSave'])
    ->middleware('role:admin,branch,accountant');
Route::delete('pilot-accounting/payout/{id}', [PilotAccountingController::class, 'payoutDelete'])
    ->middleware('role:admin,branch,accountant');
/* 📈 صفحة التقارير: الميزانية المتوقعة لكل فرع ونقطة التعادل (2026-09-05) */
Route::get('pilot-accounting/budget', [PilotAccountingController::class, 'budgetList'])
    ->middleware('role:admin,branch,accountant');
Route::post('pilot-accounting/budget', [PilotAccountingController::class, 'budgetSave'])
    ->middleware('role:admin,branch,accountant');
Route::delete('pilot-accounting/budget/{id}', [PilotAccountingController::class, 'budgetDelete'])
    ->middleware('role:admin,branch,accountant');
Route::post('pilot-accounting/budget/prefill', [PilotAccountingController::class, 'budgetPrefill'])
    ->middleware('role:admin,branch,accountant');
/* أوردرات الطيار في يوم بعمولة كل أوردر — الضغط على اسم الطيار في الشيت (2026-09-04) */
Route::get('pilot-accounting/pilot-orders', [PilotAccountingController::class, 'pilotOrders'])
    ->middleware('role:admin,branch,accountant');
/* بلوك تقفيلة الفرع اليومي (الخارجي · مصاريف · المستلم من المشرف) — زي روح دمشق (2026-09-04) */
Route::post('pilot-accounting/day-summary', [PilotAccountingController::class, 'daySummarySave'])
    ->middleware('role:admin,branch,accountant');
Route::post('pilot-accounting/perms', [PilotAccountingController::class, 'permsSave'])
    ->middleware('role:admin,branch,accountant');

/* 🔐 التلاتة دول كانوا `role:admin` لوحده. اتوسّعوا عشان شاشة الصلاحيات
   جوّه البرنامج تقدر تمنحهم — والحارس بقى جوّه الكنترولر:
   `act.deferred` و`act.lock` و`act.settings`.

   ⚠️ التوسيع ده **مش** بيفتح حاجة لحد لوحده: اللي مالوش صف صلاحيات
   بياخد `defaultPermKeys()` اللي التلاتة دول مقصوصين منها بالظبط،
   فبيترفض بـ403 زي ما المسار كان بيرفضه. اللي بيتغيّر هو إن الأدمن
   بقى يقدر يمنحهم لحد بعينه من الشاشة. */
Route::post('pilot-accounting/deferred', [PilotAccountingController::class, 'deferredSave'])
    ->middleware('role:admin,branch,accountant');
Route::delete('pilot-accounting/deferred/{id}', [PilotAccountingController::class, 'deferredDelete'])
    ->middleware('role:admin,branch,accountant');
Route::post('pilot-accounting/deferred/{id}/payment', [PilotAccountingController::class, 'deferredPayment'])
    ->middleware('role:admin,branch,accountant');

Route::post('pilot-accounting/lock', [PilotAccountingController::class, 'lockMonth'])
    ->middleware('role:admin,branch,accountant');
Route::delete('pilot-accounting/lock', [PilotAccountingController::class, 'unlockMonth'])
    ->middleware('role:admin,branch,accountant');
Route::put('pilot-accounting/settings', [PilotAccountingController::class, 'settingsSave'])
    ->middleware('role:admin,branch,accountant');

/* 🔐 شاشة الصلاحيات جوّه البرنامج — للإدارة بس، والكنترولر بيتأكد تاني.
   الرد بيحمل تعريف المجموعات نفسه، فالشاشة بتتبني من السيرفر. */
/* 👥 تقفيل الموظفين — نفس أدوار تقفيلة الطيارين، والكنترولر بيقصّ
   بالصلاحيات (page.staff) وبنطاق الفرع. الحضور من attendance_sessions
   اللي بيتكتب تلقائيًا من فتح صفحات الفرع والكول سنتر والإدارة. */
Route::get('pilot-accounting/staff-month', [PilotAccountingController::class, 'staffMonth'])
    ->middleware('role:admin,branch,accountant');
Route::post('pilot-accounting/staff-entry', [PilotAccountingController::class, 'staffEntrySave'])
    ->middleware('role:admin,branch,accountant');
Route::post('pilot-accounting/staff-perms', [PilotAccountingController::class, 'staffPermsSave'])
    ->middleware('role:admin,branch,accountant');

Route::get('pilot-accounting/acl', [PilotAccountingController::class, 'aclList'])
    ->middleware('role:admin');
Route::put('pilot-accounting/acl', [PilotAccountingController::class, 'aclSave'])
    ->middleware('role:admin');
Route::delete('pilot-accounting/acl/{userId}', [PilotAccountingController::class, 'aclReset'])
    ->middleware('role:admin');

/* ── لوحة العمليات: الدور والورديات ── */
Route::post('queue/enter', [BoardController::class, 'queueEnter'])->middleware('role:admin,branch,pilot_supervisor');
Route::post('queue/reorder', [BoardController::class, 'queueReorder'])->middleware('role:admin,branch,pilot_supervisor');
Route::post('queue/leave', [BoardController::class, 'queueLeave'])->middleware('role:admin,branch,pilot_supervisor');
Route::post('shifts/open', [BoardController::class, 'shiftOpen'])->middleware('role:admin,branch,pilot_supervisor');
Route::post('shifts/{id}/end', [BoardController::class, 'shiftEnd'])->middleware('role:admin,branch');
/* 📊 تفاصيل التقفيلة (عهدة + أذونات + عمولات) — قراءة خالصة للتقرير */
Route::get('shifts/{id}/closeout-details', [BoardController::class, 'shiftCloseoutDetails'])
    ->middleware('role:admin,branch');
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
/* 🔄 تحويل بين خزنتين — حركتين في معاملة واحدة. لازم تفضل **قبل**
   `cash-stores/{id}/transactions`؟ لأ: دي مسار مختلف تمامًا (`transfer`
   مش `{id}/transactions`) فمفيش تعارض. بس بتفضل قبل أي `cash-stores/{id}`
   محتمل يتضاف بعدين — نفس درس `orders/urges`. */
Route::post('cash-stores/transfer', [FinanceController::class, 'cashStoresTransfer'])
    ->middleware('role:admin,accountant');   // 💰
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
/* ═══ روح دمشق (rd/*) — مقفولة على الحسابات والإدارة ══════════
   🔒 التسعة وعشرين مسار دول كانوا **بلا أي قيد دور**، وأربعة منهم بلا أي
   فحص صلاحية جوّه الكنترولر كمان (bootstrap · branchesList · pilotsList ·
   monthLocksList) — يعني أجور طياري دمشق ومصروفاتهم كانت مكشوفة لأي حساب
   موظف، وللطيارين بتوكن الموبايل، وللمحلات.
   الأدوار دي هي اللي بيتفتح لها تطبيق الحسابات (appsFor: accounts ⟵ damascus).
   منظومة rd_perms جوّه الكنترولر بتفضل شغّالة فوق القيد ده.
   ⚠️ المسارات متداخلة مع غيرها في الملف فمينفعش تتلفّ في group — القيد
   على كل واحد لوحده، والحارس بيعدّهم. */
Route::get('rd/bootstrap', [DamascusController::class, 'bootstrap'])->middleware('role:admin,accountant,branch');   // 💰
Route::get('rd/branches', [DamascusController::class, 'branchesList'])->middleware('role:admin,accountant,branch');
Route::post('rd/branches', [DamascusController::class, 'branchesCreate'])->middleware('role:admin,accountant,branch');
Route::get('rd/deferred', [DamascusController::class, 'deferredList'])->middleware('role:admin,accountant,branch');   // 💰
Route::post('rd/deferred', [DamascusController::class, 'deferredCreate'])->middleware('role:admin,accountant,branch');   // 💰
Route::get('rd/entries', [DamascusController::class, 'entriesList'])->middleware('role:admin,accountant,branch');   // 💰
Route::put('rd/entries', [DamascusController::class, 'entriesSave'])->middleware('role:admin,accountant,branch');   // 💰
Route::put('rd/entry-perms', [DamascusController::class, 'entryPermsSave'])->middleware('role:admin,accountant,branch');   // 💰
Route::post('rd/month-lock', [DamascusController::class, 'monthLockCreate'])->middleware('role:admin,accountant,branch');
Route::delete('rd/month-lock', [DamascusController::class, 'monthLockDelete'])->middleware('role:admin,accountant,branch');
Route::get('rd/month-locks', [DamascusController::class, 'monthLocksList'])->middleware('role:admin,accountant,branch');
Route::get('rd/perms', [DamascusController::class, 'permsList'])->middleware('role:admin,accountant,branch');
Route::put('rd/perms', [DamascusController::class, 'permsSave'])->middleware('role:admin,accountant,branch');
Route::get('rd/pilot-sheet', [DamascusController::class, 'pilotSheet'])->middleware('role:admin,accountant,branch');   // 💰
Route::get('rd/pilots', [DamascusController::class, 'pilotsList'])->middleware('role:admin,accountant,branch');   // 💰
Route::post('rd/pilots', [DamascusController::class, 'pilotsCreate'])->middleware('role:admin,accountant,branch');   // 💰
Route::get('rd/settings', [DamascusController::class, 'settingsGet'])->middleware('role:admin,accountant,branch');   // 💰
Route::put('rd/settings', [DamascusController::class, 'settingsPut'])->middleware('role:admin,accountant,branch');   // 💰
Route::get('rd/summaries', [DamascusController::class, 'summariesList'])->middleware('role:admin,accountant,branch');   // 💰
Route::put('rd/summaries', [DamascusController::class, 'summariesSave'])->middleware('role:admin,accountant,branch');   // 💰
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
Route::get('rd/closeout/day', [DamascusController::class, 'closeoutDay'])->middleware('role:admin,accountant,branch');   // 💰
Route::get('rd/closeout/month', [DamascusController::class, 'closeoutMonth'])->middleware('role:admin,accountant,branch');   // 💰
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
/* المستلمون المحفوظون: كان فيه قايمة وحفظ بس — الحذف اتضاف مع شاشة
   «عملائي» عشان التاجر يقدر يشيل مستلم مابيتعاملش معاه تاني. */
Route::delete('customer/receivers/{id}', [CustomerAppController::class, 'receiversDelete']);
Route::post('customers/{id}/block', [CustomersController::class, 'block'])
    ->middleware('role:admin');
// فتح/قفل تعديل سعر التوصيل للعميل — زي المحلات (طلب 2026-09-02)
Route::post('customers/{id}/price-edit', [CustomersController::class, 'priceEdit'])
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
/* 🔒 كان بلا قيد دور وبيرجّع OrderWire::full — أي حساب مسجّل كان بيقرا
   أي أوردر كامل (تليفونات وعناوين ودبابيس وأسعار) بحجة إنه بيقيّم.
   وحارس الفرع للدور branch جوّه الكنترولر. */
Route::post('orders/{id}/rating', [SupportController::class, 'orderRating'])
    ->middleware('role:admin,branch,callcenter,store');
Route::post('pilot-transfers/{id}/approve', [BoardController::class, 'transferApprove'])
    ->middleware('role:admin,pilot_supervisor');
Route::post('pilot-transfers/{id}/end', [BoardController::class, 'transferEnd'])
    ->middleware('role:admin,branch,pilot_supervisor');
Route::post('pilot-transfers/{id}/reject', [BoardController::class, 'transferReject'])
    ->middleware('role:admin,pilot_supervisor');
Route::put('rd/branches/{id}', [DamascusController::class, 'branchesUpdate'])->middleware('role:admin,accountant,branch');
Route::delete('rd/branches/{id}', [DamascusController::class, 'branchesDelete'])->middleware('role:admin,accountant,branch');
Route::put('rd/deferred/{id}', [DamascusController::class, 'deferredUpdate'])->middleware('role:admin,accountant,branch');   // 💰
Route::delete('rd/deferred/{id}', [DamascusController::class, 'deferredDelete'])->middleware('role:admin,accountant,branch');   // 💰
Route::put('rd/pilots/{id}', [DamascusController::class, 'pilotsUpdate'])->middleware('role:admin,accountant,branch');   // 💰
Route::delete('rd/pilots/{id}', [DamascusController::class, 'pilotsDelete'])->middleware('role:admin,accountant,branch');
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
Route::put('rd/deferred/{id}/payment', [DamascusController::class, 'deferredPayment'])->middleware('role:admin,accountant,branch');   // 💰
Route::post('wallets/{ownerType}/{ownerId}/credit', [FinanceController::class, 'walletCredit'])
    ->middleware('role:admin,branch,accountant');   // 💰
Route::post('wallets/{ownerType}/{ownerId}/debit', [FinanceController::class, 'walletDebit'])
    ->middleware('role:admin,branch,accountant');   // 💰
Route::post('wallets/{ownerType}/{ownerId}/use', [FinanceController::class, 'walletUse']);   // 💰

/* ═══ أي مسار مش موجود ════════════════════════════════════════ */
Route::fallback(fn () => ApiResponse::fail('المسار غير موجود', 404));
