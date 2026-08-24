- 🔴 أهم نقطة: قوايم support.php التلاتة (complaints / zone-requests / partners) بترجع في الأصل {ok, changed, items} **من غير serverNow**. ده مخالف لبند 5 في CONVENTIONS.md وللغلاف الموحد. فمقدرتش أستخدم PollableList::items() لأنها بتضيف serverNow والفاحص بيقارن حرف بحرف. كتبت غلاف خاص private legacyList() جوه الكنترولر بنفس التلات مفاتيح بالترتيب ده. المسارات دي كمان مابتدعمش ?since أصلًا فمفيش حالة changed:false.

- ملف سلك جديد اتعمل: D:\dahshaneg\dahshan\app\Wire\SupportWire.php فيه complaint() / zoneRequest() / partner() — نقل حرفي لـ support_complaint_wire / support_zone_request_wire / support_partner_wire. مابيعدّيش على Vocab خالص: حالات الشكوى (open/resolved) وطلب المنطقة (pending/added/rejected) **إنجليزية على السلك** مش عربية، واللوحات بتقارنها نصًا.

- php -l نضيف على الملفين، والكلاسات بتتحمّل من الـautoloader والدوال الخمسة موجودة (اتأكدت بـ class_exists/method_exists على vendor/autoload.php).

- ⚠️ باج محتمل منقول زي ما هو: GET /api/partners مسار **عام بلا دخول** والاستعلام 'SELECT * FROM site_partners ORDER BY sort_order, id' مافيهوش WHERE is_active = 1 — يعني الشريك المخفي (is_active=0) بيطلع في الرد العام برضه. سيبته زي ما هو، الإصلاح قرار منفصل.

- ⚠️ تعليق غلط في الأصل: support_settings_get مكتوب فوقه «كل الإعدادات (للموظفين)» لكن الكود require_auth() بس — يعني **أي حساب مسجّل** بيقراها بما فيهم دور customer وصاحب المحل والطيار. الكود هو الحاكم فمانحطش middleware دور، والدخول مفروض بـ $request->actorOrFail() جوه الكنترولر (بيدي 401 زي require_auth).

- عدم تماثل في الأدوار منقول زي ما هو: GET /api/complaints بيسمح لـ hr كمان (admin,callcenter,branch,hr) بينما POST /api/complaints مافيهوش hr (admin,callcenter,branch). ومسار GET /api/zone-requests مافيهوش hr خالص. مش تنضيف — ده الأصل.

- فلتر ?status مابيتحققش من قايمة مسموحة: أي قيمة غير فاضي/'all' بتتحط في WHERE كما هي، فقيمة مجهولة = قايمة فاضية مش خطأ. سيبت القيمة تتمرّر خام من $request->query('status') من غير cast — نفس $_GET['status'] بالظبط.

- فرق مقصود بين المسارين اتحافظ عليه: GET /api/settings لو فك الـJSON رجّع null والنص مش "null" حرفيًا بيرجّع **النص الخام** (عشان الإعدادات القديمة اللي اتخزّنت كنص عادي)، لكن GET /api/settings/site **مفيهوش** الرجوع ده — أي قيمة مش JSON صالح بترجع null.

- لو جدول site_settings فاضي (أو مفيش أي من التلات مفاتيح في settings/site) الرد بيبقى "settings":[] و"site":[] — **مصفوفة مش كائن** لأن PHP بيرمّز المصفوفة الفاضية كمصفوفة. سلوك الأصل بالحرف، فمحوّلتهاش لـ stdClass زي ما بيحصل في أماكن تانية في المشروع.

- ترتيب التسجيل: مفيش تعارض بين 'settings' و'settings/site' (الاتنين حرفيين مفيش باراميتر) فالترتيب مش حاسس هنا — بس فضّلت أذكر settings/site الأول للاتساق مع قاعدة «الحرفي قبل اللي فيه باراميتر».

- مسارات الكتابة المتسابة لجولة جاية (كلها في نفس الملف المصدر): POST /api/complaints (support_complaints_create) · POST /api/complaints/{id}/resolve (support_complaints_resolve) · POST /api/zone-requests (support_zone_requests_create) · POST /api/zone-requests/{id}/mark-added (support_zone_requests_mark_added) · PUT /api/settings (support_settings_put) · POST /api/partners (support_partners_create) · PUT /api/partners/{id} (support_partners_update) · DELETE /api/partners/{id} (support_partners_delete) · POST /api/orders/{id}/rating (support_order_rating) · POST /api/orders/{id}/apply-wallet (support_apply_wallet).

- تحذير للجولة الجاية: support_order_rating و support_apply_wallet بيعيشوا في support.php بس بيعتمدوا على دوال من ملفات تانية لسه مااتنقلتش — customer_require() من customer_app.php، finance_lock_wallet() من finance.php، و ser_order_full() (= OrderWire::full). كمان الاتنين على مسار /api/orders/{id}/... فلما يتسجّلوا لازم ياخدوا بالهم من ترتيبهم جنب مسارات OrdersController (مش تعارض فعلي لأنهم POST مش GET).

- support_zone_requests_create فيه SELECT ... FOR UPDATE جوه معاملة (زيادة العداد بدل صف جديد) — لما يتنقل لازم يبقى جوه DB::transaction() عشان الـrollback التلقائي زي ما ApiException مصمّمة.

- حالة حافة مسجّلة مش متغطّاة: ?status[]=x (باراميتر مصفوفة) — الأصل بيرمي داخل PDO والراوتر بيمسكه ويرد 'حدث خطأ غير متوقع' 500. في لارافل الربط بمصفوفة بيرمي TypeError (مش Exception) فبيقع على نفس الفرع في bootstrap/app.php وبيرد نفس الرسالة والكود. مفحصتهاش عمليًا لأنها محتاجة تشغيل السيرفرين.

- ملفات اتكتبت: D:\dahshaneg\dahshan\app\Http\Controllers\Api\FinanceController.php و D:\dahshaneg\dahshan\app\Wire\FinanceWire.php. وكمان اتضافت دالة واحدة جديدة (إضافة بس، مافيش تعديل على السلوك القديم) في D:\dahshaneg\dahshan\app\Support\WireTime.php اسمها cairoDayKey() — نقل حرفي لـ cairo_day_key() المحتاجة في /api/attendance. التلات ملفات php -l نضيفين.

- 🔴 شذوذ في العقد لازم يتنقل زي ما هو: GET /api/wallets الرد بتاعه **من غير serverNow** — {ok, changed, items} بس. كل قوايم الملف التانية بترجع {ok, serverNow, changed, items}. عشان كده walletsList مابيستخدمش PollableList::items وبيكتب المصفوفة يدوي بـApiResponse::out.

- 🔴 تضارب أدوار موثّق في الأصل: التعليق فوق finance_require_wallet_access بيقول «الإدارة/الفرع/الكول سنتر/الحسابات: أي محفظة (نفس أدوار finance_wallets_list)» — لكن finance_wallets_list فعليًا require_role('admin','accountant','callcenter') **من غير branch**، بينما البوابة بتسمح لـbranch. يعني مشرف الفرع يقدر يقرا محفظة مفردة بس مايقدرش يجيب قايمة المحافظ. اتنقل زي ما هو، مش تصحيح.

- خمس مجموعات أدوار مختلفة في نفس الملف واتنقلت كلها بالحرف: (1) أدوار الفلوس admin/branch/accountant لـ cash-stores + transactions + custody + pilots/{id}/custody + expenses · (2) admin/accountant/callcenter لـ wallets · (3) أي حساب مسجّل + بوابة الوصول لـ wallets/{ownerType}/{ownerId} · (4) admin/branch/hr/accountant لـ attendance · (5) admin/hr/branch لـ manual-employees.

- GET /api/cash-stores فلتر branchId بيستخدم `isset && !== ''` مش `!empty` — يعني ?branchId=0 بيولّد فعلًا شرط `branch_id = 0` (نتيجة فاضية)، عكس expenses/custody اللي بيستخدموا !empty فبيتجاهلوا الصفر. اتنقل زي ما هو.

- باج محتمل منقول: في cash-stores/{id}/transactions و /api/custody الفلاتر from/to بتتلزق نصًا (' 00:00:00' / ' 23:59:59') وبتتقارن مع created_at اللي متخزّن **UTC**، بينما المستخدم بيكتب يوم بتوقيت القاهرة — يعني حدود اليوم بتزحف ساعتين. /api/expenses مش عندها المشكلة دي لأنها بتقارن على عمود DATE من غير لزق وقت. الفرق ده مقصود/موجود في الأصل واتنقل كما هو.

- باج محتمل منقول: from/to في الفلاتر دي مابيتحققوش خالص — أي نص بيروح للقاعدة كباراميتر مربوط (مفيش حقن) وبيرجّع نتيجة فاضية بدل خطأ عربي.

- 🔴 استثناء نوع على السلك في /api/attendance: لو auto_check_out=1 المفتاح checkOut بيطلع **رقم epoch بالميلي ثانية** بدل نص ISO — نوع الحقل نفسه بيتغيّر. وكمان مفتاح autoCheckOut **مابيظهرش خالص** لما الانصراف يدوي (مش بيطلع false). ده الخرق الوحيد لقاعدة «ممنوع حذف اسم حقل» في النظام، وموجود في الأصل حرفيًا.

- GET /api/attendance **مفيهاش LIMIT** — ?from=1990-01-01 من غير to بيفرّغ الجدول كله. اتنقل زي ما هو. وكمان ?day= بتغلب ?from=/?to= لو الاتنين اتبعتوا.

- GET /api/pilots/{id}/custody: custodyBalance بيتقرا من pilots.custody_balance (الرصيد الحي) بينما items مسقوفة بـ200 حركة — يعني مجموع items مش بيساوي custodyBalance بالضرورة، وده مقصود. والرد ده مش قايمة استطلاع: مفيش serverNow ولا changed.

- GET /api/wallets/{ownerType}/{ownerId} لما المحفظة لسه ما اتخلقتش بترجع **200** بـ walletId:null و balance:0.0 و items:[] — مش 404. الإنشاء بيحصل في مسارات الكتابة بس (خارج الجولة دي).

- ترتيب الفحص في walletGet مهم ومنقول بالحرف: actorOrFail() (401) → تحقق النوع (400 'نوع المحفظة لازم يكون customer أو store') → تحقق المعرّف (400 'معرّف غير صالح') → بوابة الوصول (403 'غير مسموح لك بالوصول لهذه المحفظة'). المسار ده لازم يتسجّل **من غير middleware role** عشان المحل والعميل يقدروا يوصلوا لمحفظتهم.

- finance_int_id بترجع 400 'معرّف غير صالح' مش 404 لأي معرّف مش رقم موجب — يعني /api/pilots/abc/custody و /api/cash-stores/0/transactions بيدّوا 400. اتنقلت في FinanceController::intId().

- المصروف: مفتاح date بيطلع **نص عمود DATE خام** ('2026-08-06') مش ISO بميلي ثانية زي باقي التواريخ — استثناء الأصل، لأن الواجهة بتفلتر بيه كما هو.

- تسجيل المسارات (في routes/api.php من عندك): لازم كلها **قبل** Route::fallback. والمسار الحرفي `wallets` قبل `wallets/{ownerType}/{ownerId}` حسب قاعدة الترتيب في الملف. `pilots/{id}/custody` مالوش تعارض مع `pilots` الموجود. مفيش تعارض بين `cash-stores` و`cash-stores/{id}/transactions`.

- مفيش تغيير على D:\dahshaneg\aldahshan — قراءة بس. ومفيش أي أمر بيكتب في قاعدة البيانات اتشغّل.

- 🔴 **GET /api/pilot/version مش موجود في النظام القديم خالص.** جدول المسارات في public/index.php فيه `POST /api/pilot/version` بس (سطر 162)، وجسم pilot_version() بيقرا body_json()['version'] وبيعمل UPDATE — يعني مسار كتابة مش قراءة. فمانقلتوش ومارجّعتوش أي مسار ليه: النداء بـGET بيقع على Route::fallback في لارافل وبيرجّع {"ok":false,"error":"المسار غير موجود"} 404 — **نفس رد الراوتر القديم بالحرف**، فالمطابقة قايمة من غير أي كود. لو كان المقصود POST فهو مسار كتابة وبيتنقل في جولة الكتابة.

- 🔴 GET /api/pilot/closeouts بيرجّع `{ok, items}` **من غير serverNow ولا changed** — الأصل بيبني المصفوفة بإيده (json_out(['ok'=>true,'items'=>...])). ده مخالف لبند 5 في CONVENTIONS.md وللغلاف الموحد PollableList، فاستعملت ApiResponse::ok() بدلها عمدًا. استخدام PollableList::items() هنا كان هيضيف مفتاحين ويكسّر المقارنة الحرفية.

- 🔴 GET /api/pilot/finished-orders بيقرا `?since` **بطريقة مختلفة** عن باقي مسارات الملف: `isset && !== '' ? (int) : null` من غير `max(0,…)` اللي في pilot_since(). يعني `?since=-5` بيعدّي بقيمة سالبة والفحص بيلاقي كل حاجة أحدث منها. منقول بالحرف — فيه سطر مطابقة ليه.

- ⚠️ **فرق سلوك حقيقي بين مسارات نفس الملف** ومنقول زي ما هو: مسارات state / active-orders / finished-orders / closeouts بتعدي على pilot_ctx() فبترمي 403 «الحساب مش مربوط بطيار» لو users.pilot_id فاضي. لكن قوايم الطلبات التلاتة (return/leave/shift-requests) بتقرا users.pilot_id مباشرة وبتعمله (int) — فبيبقى **0** والقايمة بترجع فاضية بدل 403. مش تسق، بس ده الأصل بالحرف.

- ⚠️ فرع الموظفين في board_leave_requests_list / board_shift_requests_list / board_return_requests_list (board_branch_scope + `?branch=`) **مش منقول** في pilotRequestsList — الفرع ده ميت تحت `role:pilot`. هيتنقل مع board.php على مساراته هو (GET /api/leave-requests وإخوانه).

- ⚠️ `?status` في قوايم الطلبات بيتحط في WHERE **كما هو** من غير أي تحقق من القايمة المسموحة (قيمة مش موجودة = قايمة فاضية مش خطأ)، والفحص `empty()` مش `!== ''` — يعني `?status=0` بيتجاهل بالكامل. منقول حرفيًا وفيه سطرين مطابقة ليه.

- ⚠️ غلاف board_list_out: قايمة فاضية (أو أعمدة توقيت كلها NULL) بترجّع **الرد الكامل** `changed:true, items:[]` مش `changed:false` — لأن الشرط `maxTs > 0`. سلوك مقصود (التطبيق لازم يعرف إن القايمة اتفضّت) ومنقول زي ما هو.

- ⚠️ بصمة `sig` في /api/pilot/state بتتحسب بـ `json_encode(..., JSON_UNESCAPED_UNICODE)` **لوحده** — من غير JSON_UNESCAPED_SLASHES اللي في ApiResponse::JSON_FLAGS. العلم الناقص ده بيغيّر النص فبيغيّر الـmd5، فمانقلتش الأعلام من ApiResponse. وكمان ترتيب عناصر المصفوفة وأنواعها ((int) على ids) جزء من العقد: أي تزحزح = كل الأجهزة المنصّبة تشوف البصمة اتغيرت مرة واحدة.

- ✅ الأنواع في الـsig متطابقة بين النظامين: النظام القديم PDO بـ EMULATE_PREPARES=false (api/db.php:11) ولارافل كذلك افتراضيًا مع STRINGIFY_FETCHES=false، فأعمدة INT/TINYINT بترجع int أصلي في الاتنين. لو حد غيّر options في config/database.php الـsig هتتغير من غير أي أثر تاني.

- ⚠️ حسبة العمولة في finished-orders **تجميعية** ومختلفة عن App\Support\Commission::forPilot(): fixed = القيمة × عدد المُسلَّم (مش القيمة وخلاص)، ومفيش الخروج المبكر `== 0.0`. فمااستعملتش الهيلبر عمدًا وكتبتها inline زي الأصل. النتيجة الرقمية واحدة في حالة القيمة صفر، بس المعادلة مختلفة فالربط بالهيلبر غلط.

- ⚠️ في finished-orders لو اتبعت `?day=` و`?shift=` مع بعض، **shift بتكسب وday بيتجاهل بالصمت** (شجرة if/elseif). شكله سهو في الأصل بس منقول زي ما هو — فيه سطر مطابقة ليه (finished?day+shift).

- ⚠️ نافذة اليوم في finished-orders بتتبني على كائن بتوقيت القاهرة ثم `+1 day` **قبل** التحويل لـUTC — يعني يوم التغيير الصيفي بيفضل 24 ساعة قاهرة مش 24 ساعة UTC. نقلت العمليات بنفس الترتيب بالظبط؛ عكسها بيغيّر الحدود ساعة في يومين في السنة.

- ⚠️ BoardWire.php: كتبت نسخة فيها الخمس serializers اللي محتاجها (shift/leaveRequest/shiftRequest/returnRequest/closeout)، وفي نفس الوقت وكيل تاني شغّال بالتوازي وسّع الملف لنسخة كاملة فيها joinRequest/transfer/supportRequest كمان. سيبت نسختهم وراجعت الخمس دوال اللي بستعملها **سطر بسطر** مقابل board_ser_* الأصلية — نفس المفاتيح ونفس الترتيب ونفس الـcasts. اتأكدت بـReflection إن الخمسة موجودين والكنترولر بيتحمّل. مفيش تصادم.

- ⚠️ BoardWire::shift بيحط الافتراضي `daily` لبنود التسوية الأربعة، بينما Vocab::SHIFT_SETTLE_WIRE في تعليقه بيقول الافتراضي monthly (VOCAB.md:276). التناقض ده في الأصل؛ عمليًا مابيظهرش لأن أعمدة shifts.*_settle كلها NOT NULL DEFAULT 'daily' (build.sql:672-675) فالـ`?:` مابتشتغلش. متسجّل هنا كبند مفتوح مش إصلاح.

- 📋 **بوابة المطابقة ناقصة قطعة**: سطور parity المرفقة بتفحص بوابة الدور (401 بلا دخول · 403 بحساب أدمن) والراوتنج، لكن **مش بتفحص البيانات نفسها** لأن Compare-Route في tests/parity.ps1 بيبعت كوكي الأدمن بس ومفيش دعم لهيدر X-Auth-Token. عشان مطابقة بيانات حقيقية لازم يتضاف لـCompare-Route باراميتر -Token يبعت `-H "X-Auth-Token: <token>"` للسيرفرين، وتوكن طيار اختبار في aldahshan\tests\creds.local.ps1. من غير كده مسار زي /api/pilot/state بيتفحص شكله مش محتواه.

- ℹ️ استعملت ApiResponse::out() المكتوب بالإيد في state وfinished-orders مش PollableList: state فيه مفتاح `sig` بين `changed` و`pilot`، وfinished-orders فيه `stats` بعد `items` — الترتيبين دول جزء من المقارنة الحرفية وPollableList مابيسمحش بيهم.

- ℹ️ أضفت `AS n` / `AS c` لأعمدة COUNT/EXISTS في الاستعلامات الخام (الأصل كان بيستخدم fetchColumn() اللي مابيحتاجش اسم). الأسماء دي داخلية بالكامل ومابتوصلش الرد.

- ملف سلك جديد اتكتب كمان: D:\dahshaneg\dahshan\app\Wire\BoardWire.php — فيه shift / joinRequest / leaveRequest / shiftRequest / returnRequest / transfer / supportRequest / closeout. مفيش أي تعديل على CoreWire أو OrderWire أو routes/api.php.

- التحقق: قارنت المخرج مع النظام القديم فعليًا على قاعدة aldahshan (قراءة بس، بلا أي كتابة) — 47 حالة مسار + 24 حالة سيريالايزر على صفوف صناعية، كلها **مطابقة حرف بحرف** بعد تصفير serverNow. سكربتات الفحص في الـscratchpad: legacy_run.php / new_run.php / cmp.sh / ser_diff.php.

- 🔴 غلاف ?since هنا **مش زي باقي النظام**: جداول اللوحة ملهاش عمود updated_at، فالأصل بيفلتر بعد الاستعلام على `_ts` محسوب لكل صف (board_row_ts). نتيجتان محفوظتان بالحرف: (1) القايمة بترجع **كاملة** لو فيها أي صف أحدث من since — مش دلتا. (2) القايمة الفاضية بترجع changed:true مع items:[] مش changed:false، لأن شرط الأصل `$maxTs > 0`.

- باج موروث (منقول زي ما هو): board_branch_scope بترجّع (int)$user['branch_id'] لمشرف الفرع — لو branch_id = NULL بترجّع 0 وهي falsy، يعني **بلا أي فلترة** فالمشرف بيشوف كل الفروع بدل ما يشوف ولا حاجة. متحقق منه بالمقارنة (مطابق للأصل).

- 🔒 توسّع صلاحيات موروث: GET /api/shifts هو require_auth() بس — أي حساب مسجّل (طيار/محل/عميل) يقدر يسحب آخر 500 وردية في الشركة بأسماء الطيارين والحوافز والخصومات والسلف. اتحققت من ده بدور store وطلع مطابق للأصل. التضييق قرار منفصل.

- 🔒 نفس الحكاية في leave-requests / shift-requests / return-requests: الطيار بس هو اللي بيتقفل على نفسه؛ أي دور تاني (محل/عميل/كول سنتر… أي حد مسجّل) بيمشي على مسار الفرع، ومن غير ?branch= بيشوف طلبات الشركة كلها (300 صف).

- 🔒 GET /api/support-requests مافيهاش أي فلترة بالفرع خالص — كل فرع بيشوف كل طلبات الدعم (آخر 200). ده السبب اللي خلّى عدّاد pending.support في /api/board يتحسب بشروط الفرع في الـSQL بدل ما يتحسب من القايمة.

- 🔒 GET /api/closeouts/monthly مافيهاش نداء لـ board_assert_pilot_in_scope — مشرف أي فرع يقدر يقرا تقفيلة أي طيار في الشركة بمرتبه وسلفه وخصوماته. (الدالة معرّفة في board.php بس بتتندى في مسارات الكتابة بس: force-leave / pilot return / queue.) منقول زي ما هو.

- تناقض موروث في افتراضي التسوية: board_ser_shift بتستخدم `?: 'daily'` لأعمدة commission_settle/bonus_settle/deduction_settle/advance_settle، بينما board_build_monthly_data بتستخدم `?: 'monthly'` لنفس الأعمدة بالظبط. الاتنين اتنقلوا بالحرف. عمليًا خامد لأن السكيمة NOT NULL DEFAULT 'daily'، بس لو صف قديم فيه سلسلة فاضية السلوك بيختلف بين المسارين.

- حقول بترجع null على طول لأنها مش متخزنة في السكيمة: joinRequest.approvedAt / joinRequest.rejectedAt / returnRequest.respondedAt / returnRequest.respondedBy. سايبها بالحرف — الشكل القديم هو العقد.

- pilot-transfers: العمود type بيتخزّن temp/permanent، والسلك بيقول **direct** بدل temp (ترجمة خروج بس). أي قيمة تانية بتعدّي زي ما هي.

- ⚠️ فخ اختبار محتمل: totalHours في /api/closeouts/monthly للورديات **المفتوحة** بتتحسب لحد اللحظة الحالية، فالرقم بيتحرّك. round(ms/3600000, 2) يعني كل 36 ثانية بيتغيّر. لو الفاحص ندى النظامين على طرفي حدّ الـ36 ثانية الرد هيختلف من غير ما يكون انحدار. في قاعدة الاختبار الحالية الطيار 6 عنده وردية active من 2026-08-18، فالسطر '/api/closeouts/monthly?pilot=6&month=2026-08' ممكن يرفرف. لو حصل، زوّد Normalize في parity.ps1 بـ '"totalHours":\s*[0-9.]+' أو استعمل شهر بلا ورديات مفتوحة (2026-07).

- استخدمت مساعد داخلي firstColumn() بدل fetchColumn() عشان أسيب نص الـSQL بتاع COUNT(*) و GREATEST(...) **حرفيًا زي الأصل** من غير ما أضطر أضيف alias للعمود.

- ترتيب التسجيل: كل المسارات دي حرفية ومفيش فيها باراميتر، فمفيش تصادم مع اللي متسجّل حاليًا. بس لما مسارات الكتابة تتنقل بعدين لازم 'shifts' تفضل قبل 'shifts/{id}/...' و 'closeouts/monthly' تفضل مسار حرفي مستقل.

- بلا دخول: كل المسارات بترجّع 401 'يجب تسجيل الدخول أولًا' — اللي عليها role:admin,branch عن طريق EnsureRole (بيرمي unauthenticated قبل forbidden)، واللي من غير middleware عن طريق $request->actorOrFail(). مطابق للأصل (require_auth بتضرب قبل فحص الدور).

- مقدرتش أفحص قوايم الطلبات على بيانات حقيقية — الجداول الستة (join/leave/shift/return/transfers/support) فاضية في قاعدة aldahshan الحالية، وممنوع أكتب فيها. غطّيت النقص ده بمقارنة السيريالايزرز نفسها كدوال نقية على 24 صف صناعي (فيها null و stdClass وحالات حافة زي phones بفاصلة/من غير فاصلة و type=temp/permanent) وكلها مطابقة.

- اتعمل ملف سلك جديد: D:\dahshaneg\dahshan\app\Wire\CustomerWire.php فيه customer() و address() و savedReceiver() — نقل حرفي لـ customers_wire() و customers_address_wire() و customers_receiver_wire(). مفيش سيريالايزر للعملاء كان موجود قبل كده في CoreWire/OrderWire.

- الفرق الوحيد عن نص الاستعلام الأصلي: فحص ?since بقى فيه alias ‏`AS n` على ناتج جمع الأربع EXISTS، لأن لارافل بيرجّع كائن بأسماء أعمدة والأصل كان بيقرا العمود بالموضع (fetchColumn). الناتج والسلوك متطابقين.

- سقف LIMIT 2000 على /api/customers اتنقل زي ما هو، ومعاه ORDER BY c.created_at DESC, c.id DESC.

- ترتيب استعلامات الأبناء اتساب زي الأصل: customer_addresses بـ ORDER BY is_default DESC, id ثم customer_saved_receivers بـ ORDER BY id DESC، والاتنين مقصورين على الـids الراجعة (IN) زي التعليق الأصلي عن memory_limit.

- باج محتمل متنقل زي ما هو #1: جدول customer_saved_receivers فيه أعمدة lat/lng بس السيريالايزر القديم مابيطلعهمش على السلك. سبتهم مخفيين — إظهارهم تغيير في العقد مش تصليح.

- باج محتمل متنقل زي ما هو #2: فحص ?since بيبص على customer_addresses.created_at و customer_saved_receivers.created_at بس. يعني **تعديل** أو **حذف** عنوان/مستلم محفوظ مابيحرّكش الفحص (والجدولين مالهمش updated_at أصلًا)، فالتاب المفتوح ممكن يفضل شايف بيانات ابن قديمة. حذف العميل نفسه متغطّى بعلامة site_settings.customersDeletedAt. سبته زي ما هو.

- باج محتمل متنقل زي ما هو #3: ‏since مافيهوش أي clamp (مفيش max(0,…) زي اللي في senders/receivers). قيمة سالبة بتخلي FROM_UNIXTIME ترجع NULL فالتلات EXISTS الأولانيين false، لكن شرط site_settings ‏`> سالب` بيبقى true لو العلامة موجودة → changed:true. سلوك الأصل بالحرف.

- باج محتمل متنقل زي ما هو #4: التكرار المقصود ‏name و displayNameAr الاتنين من نفس العمود display_name. سبته — كل واحد فيهم بتقراه واجهة مختلفة.

- ‏GET /api/store/pickup-profile محبوس على دور store بس (require_role('store') في الأصل). يعني بجلسة أدمن الرد 403 والنص 'غير مسموح لك بهذه العملية'، وبلا دخول 401 — والـmiddleware بيعمل الفرق ده صح. الفاعل هو مصدر u.id (مفيش باراميتر يحدد محل تاني).

- ‏zonePrice و branchId في ملف الاستلام مشتقين من الـjoin على zones (‏_zone_price و delivery_branch_id) — مش أعمدة في users. الأسماء دي جزء من عقد الاستعلام.

- مسارات الكتابة في نفس الملف المصدر (PUT /api/customers/{id}، POST /api/customers/{id}/block، POST /api/customers/{id}/zone، DELETE /api/customers/{id}، PUT /api/store/pickup-profile) **متنقلتش** في الجولة دي حسب التعليمات. الدالة الخاصة pickupProfileWire() اتفصلت جوه الكنترولر عشان الـPUT ينده عليها بعدين زي ما الأصل بيعمل (store_pickup_profile_save بتنده على store_pickup_profile_get في آخر سطر).

- مفيش أي تعديل على routes/api.php ولا على أي حاجة في D:\dahshaneg\aldahshan. الملفين الجداد عدّوا php -l نضاف.

- ملف تاني اتكتب: D:\dahshaneg\dahshan\app\Wire\PublicWire.php — فيه maskName/maskPhone (نقل حرفي لـ pub_mask_name/pub_mask_phone) و trackParcel (سيريالايزر الطرد المقنّع، 5 مفاتيح بس مقابل 14 في OrderWire::delivery). php -l نضيف على الملفين.

- المسارين **عامين بالكامل** — مفيش actorOrFail() ومفيش middleware أدوار. سجّلهم من غير ->middleware('role:...'). إضافة مصادقة هنا بتكسّر صفحة تتبّع الـSMS والموقع التسويقي.

- ترتيب التسجيل: 'public/coverage' (حرفي) قبل 'track/{orderNum}' (فيه باراميتر)، والاتنين قبل Route::fallback. مفيش تصادم بينهم فعليًا لكن ده اللي ماشي عليه الملف.

- ✅ تحقق فعلي: شغّلت مقارنة بايت-ببايت بين الأصل والكود الجديد على قاعدة aldahshan نفسها (قراءة بس) — 10 حالات track + coverage كلها مطابقة حرفيًا. الحالات: أوردر موجود، أوردر بطردين، لاحقة طرد صحيحة (-1/-2)، لاحقة طرد مش موجود (-9) → parcels:[]، لاحقة مشوّهة (-2-5) → found:false، رقم مش موجود، رقم بايظ، مسافة، فاضي.

- ✅ فحص خصوصية عدائي على الرد الخام: مافيش أي مبلغ (total_delivery_price / store_prepaid / goods_value / wallet_used / zone_price / order_price) ولا اسم كامل (sender/customer/pilot/receiver) ولا تليفون كامل ولا عنوان بيوصل السلك. الرد الحقيقي: pilotName="أ***" و receiverPhone="•••••••7890" و receiverName="م***".

- 🐛 كود ميت في الأصل: في public_track فيه بلوك `if (preg_match('/^(.*)-(\d+)$/'...) && preg_match(...) === 0) {}` **جسمه فاضي تمامًا** (تعليق بس). بيحسب preg_match مرتين ومالوش أي أثر على الرد ولا على $m (اللي بيتعاد كتابته في الـpreg_match اللي بعده). مانقلتوش ككود — سيبت تعليق مكانه في الكنترولر. صفر تغيير سلوك.

- 🐛 تناقض تعليق/كود في الأصل: تعليق pub_mask_phone بيقول «آخر 3 أرقام» بس الكود بيسيب **4** (substr(-4)). نقلت الكود زي ما هو (4) — التعليق الغلط مااتصلّحش، وموثّق في PublicWire.

- 🐛 ثغرة تقنيع صغيرة منقولة زي ما هي: تليفون من **4 أرقام بالظبط** بيرجع **مكشوف بالكامل** — str_repeat('•', 0) + آخر 4 = الرقم كله. مثال: '1234' → '1234'. برضه اسم من حرف واحد بيطلع 'أ*' يعني الحرف مكشوف. الإصلاح قرار منفصل.

- 🐛 لاحقة الطرد بتتطابق بـ /^([A-Za-z]+-\d{6}-\d+)-(\d+)$/ بالظبط. يعني 'CAI-260809-001-2-5' **مش** بيتقسّم — بيتبعت كله كـorder_num فبيرجّع found:false بدل ما يقرا الأوردر. منقول زي ما هو.

- 🐛 حالة الطرد بترجع «قيد التنفيذ» لو d.status = NULL حتى لو الأوردر نفسه «تم التسليم» (parcel_status_to_ar بترجع الافتراضي). اتأكدت منها حيًا على CAI-260809-001. سلوك أصلي منقول.

- الشحنة المش موجودة بترجع **200 مع {ok:true, found:false}** مش 404 — مفتاحين بس، من غير orderNum. مقصود عشان صفحة التتبّع تعرض رسالة بدل صفحة خطأ.

- 'اكتب رقم الشحنة' = **400** (fail() الافتراضي). مايوصلهاش غير باراميتر مسافة زي /api/track/%20 — لأن نمط الراوتر في النظامين ([^/]+ في القديم، نفسه في لارافل) مابيقبلش مقطع فاضي. /api/track من غير باراميتر بيدّي 404 'المسار غير موجود' في النظامين (الفallback).

- فرق نظري في فك ترميز الباراميتر: الراوتر القديم بيعمل urldecode (بيحوّل '+' لمسافة) ولارافل بيعمل rawurldecode ('+' بيفضل '+'). مالوش أثر عمليًا لأن صيغة أرقام الشحنات ([A-Za-z]+-\d{6}-\d+) مافيهاش '+' أبدًا. مذكور للعلم بس.

- coverage **مش مغلّف بـ PollableList** — لا serverNow ولا changed، الرد {ok, zones, branchCount} على طول زي الأصل. والأسعار هنا مسموحة (تسعيرة منشورة على الموقع) وهي الاستثناء الوحيد لقاعدة «مفيش مبالغ في المسارات العامة».

- سيبت `SELECT o.*` في استعلام الأوردر زي الأصل رغم إنه بيسحب أعمدة الفلوس — مافيش منها حاجة بتوصل السلك (الرد مبني مفتاح بمفتاح). قصّه لأعمدة محددة كان هيبقى تحسين أمني بس برضه **تغيير**، والترحيل ده صفر تغيير.

- POST /api/public/join-request متساب لجولة جاية زي ما طُلب — الدالة public_join_request لسه في الأصل ومااتنقلتش.

- متعدّلتش routes/api.php ولا أي حاجة في D:\dahshaneg\aldahshan. كل الاستعلامات على قاعدة aldahshan كانت SELECT بس.

- ملف تاني اتكتب: D:\dahshaneg\dahshan\app\Wire\TrustWire.php — النقل الحرفي لـ api/trust.php كله (تطبيع الأرقام بالـ5 صيغ، phoneKey، phoneSql، maskName/maskPhone، reputation، level، identityInfo، canonicalName، hasDealtWith، actorName) + السيريالايزرين اللي كانوا جوه routes/trust.php (rating، lookupLogEntry). حطيته في app\Wire\ مش app\Support\ عشان النظير واحد-لواحد مع ser_core.php/ser_orders.php ويفضل الفحص التفاضلي ملف-لملف.

- ترتيب التسجيل مُلزم: 'trust/lookup-log' لازم قبل 'trust/{phone}/ratings' (زي ملاحظة الراوتر القديم)، والتلاتة قبل Route::fallback في آخر routes/api.php. عمليًا مفيش تصادم (سيجمنتين مقابل تلاتة) بس الترتيب متساب زي الأصل.

- ⚠️ GET /api/lookup **بيكتب** في lookup_log — كتابة جوه مسار GET. مقصودة ومنقولة زي ما هي: السجل ده هو نفسه أساس الـrate-limit، من غيره الحد مابيتحسبش.

- ⚠️ خطر على بوابة التطابق: كل Compare-Route لـ /api/lookup بينفّذ البحث **مرتين** (قديم ثم جديد) على نفس القاعدة فبيضيف صفّين ويستهلك من حصة الـ60/ساعة. وعلى الحد بالظبط الردّين بيختلفوا: القديم بيشوف 59 فيعدّي ويكتب (60)، والجديد بيشوف 60 فيرجّع 429. قبل أي جولة parity فيها lookup: نضّف lookup_log لحساب الاختبار (DELETE FROM lookup_log WHERE actor_name='admin') أو خلّي عدد سطور lookup أقل من 25 في الساعة. ده سلوك الأصل مش انحدار.

- الانحراف الوحيد عن حرفية الـSQL: 'SELECT COUNT(*) FROM lookup_log ...' بقت 'SELECT COUNT(*) AS c FROM lookup_log ...' لأن DB::select مالهاش fetchColumn. محايد تمامًا على الرد. كل استعلامات القراءة التانية اتقارنت بالتوكنايزر وطلعت مطابقة حرف بحرف.

- 🐛 محتمل (منقول زي ما هو): trust_level بيتنده على الدرجة **غير المقرّبة** بينما 'score' على السلك مقرّب لخانتين. يعني درجة 4.4999 بتطلع score=4.5 و level='جيد' مش 'ممتاز'. متغيّرش — قرار منفصل.

- 🐛 محتمل (منقول زي ما هو): 'nameVerified' في رد /api/lookup بيطلع **من غير شرط fullAccess** — يعني أي محل مسموح له بالبحث يعرف إن رقم معيّن اسمه موثّق يدويًا من موظف، حتى لو مش متعامل معاه. باقي حقول الهوية (name/address/nameSource/isRegisteredCustomer/lastOrderAt) كلها مقفولة صح. سيبته زي الأصل.

- maskPhone بتعدّ بالبايت (strlen) مش mb_strlen — الرقم المطبَّع أرقام لاتينية فمفيش فرق عمليًا، بس سيبتها زي الأصل عشان أي حالة شاذة تفضل بنفس عدد النقط.

- hasDealtWith لأي دور غير staff/customer بيقع على فرع اسم المستخدم (o.added_by = username). مش قابل للوصول من المسارات التلاتة دي (الـmiddleware بيحصر الأدوار)، لكنه بيتنده من entities_receivers_lookup في النظام القديم — TrustWire جاهز ليه لما GET /api/receivers/lookup تتنقل (لسه متنقلتش).

- فكّ ترميز {phone}: الراوتر القديم بيعمل urldecode (بيحوّل '+' لمسافة) ولارافل/سيمفوني بيعمل rawurldecode ('+' يفضل '+'). النتيجة النهائية واحدة لأن normalizePhone بتشيل المسافة وبتشيل بادئة '+' برضه — اتأكدت من '+201012345678' في الحالتين بتدّي '01012345678'. مفيش فرق ملحوظ، بس مسجّل.

- مسارات الكتابة في المجال ده **متنقلتش** في الجولة دي (POST /api/orders/{id}/rate-receiver · POST /api/trust/rate · PUT /api/trust/{phone}/identity). trust_req_stars و trust_insert_rating (بما فيهم التقاط خرق المفتاح الفريد 23000 → 'أنت قيّمت الأوردر ده قبل كده' 409) لسه في النظام القديم بس. النواة المشتركة اللي هيحتاجوها موجودة في TrustWire.

- trust_canonical_name اتنقلت كـ TrustWire::canonicalName() رغم إن مفيش مسار GET بينده عليها — لأنها قاعدة الحسم الموثّقة في build.sql وبتتذكر في تعليق السكيمة.

- تحقق فعلي اتعمل: (1) php -l نضيف على الملفين. (2) سكربت فحص تفاضلي شغّل النسخة الأصلية جنب TrustWire على 24 مدخل حدّي (فاضي/null/'+'/'00'/أرقام عربية/بشرط/بأقواس/بادئة 20 و002 و+2/10 خانات/أقل من 4) — normalizePhone و phoneKey و phoneSql و maskName و maskPhone و level كلهم طلعوا متطابقين بالبايت. (3) مقارنة بالتوكنايزر لكل نصوص الـSQL: كل استعلامات القراءة موجودة بالحرف، الناقص بس استعلامات POST/PUT + الـCOUNT alias. (4) الكلاسين بيتحمّلوا من الـautoloader.

- متلمستش routes/api.php ولا أي حاجة في D:\dahshaneg\aldahshan، ومشغّلتش أي أمر بيكتب في القاعدة.