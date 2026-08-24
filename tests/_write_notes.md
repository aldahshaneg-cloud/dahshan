- الملف الوحيد اللي اتعدّل: D:\dahshaneg\dahshan\app\Http\Controllers\Api\EntitiesController.php — الدوال الجديدة اتضافت في الآخر من غير مسح أي حاجة قديمة. مفيش أي كلاس Wire اتعدّل: CoreWire::sender/receiver و TrustWire::normalizePhone/maskName/maskPhone/hasDealtWith كانوا موجودين بالفعل وكفوا. php -l نضيف على EntitiesController.php و CoreWire.php و TrustWire.php.

- 🔒 entities_party_wire_private اتنقلت بالحرف كـ partyWirePrivate(Actor, array row, string table). الشرط: staff (admin/branch/callcenter عبر Actor::isStaff — نفس in_array الصارمة) **أو** (phone !== '' بعد TrustWire::normalizePhone **و** TrustWire::hasDealtWith) = الصف كامل. غير كده: name=maskName, phone1=maskPhone, phone2=null, address=null, createdBy=null. مهم: الإسناد بيستبدل قيمة مفتاح موجود أصلًا في مصفوفة الـwire، فترتيب مفاتيح الـJSON متطابق بين المقنّع والكامل — لازم يفضل كده.

- 🔒 قاعدة الخصوصية في upsert: POST /api/senders و POST /api/receivers مالهمش middleware دور عن قصد (الأصل require_auth() بس). لو اتحطلهم role حتى لو واسع، سلوك 401/403 هيتغير للأدوار اللي الأصل بيسمح لها تدخل وتاخد الصف المقنّع (طيار/محل/عميل). التقنيع هو البوابة مش الدور.

- الصف الجديد في upsert (existing=false) بيرجع **بلا تقنيع** — سلوك الأصل بالحرف، ومنطقيًا الصف من إنشاء الطالب نفسه.

- GET /api/senders/lookup و /api/receivers/lookup بيرجّعوا {ok, serverNow, items} **من غير مفتاح changed** — شذوذ في الأصل مخالف لغلاف قوايم الاستطلاع، فمستخدمتش PollableList::items معاهم. شبيه بشذوذ /api/wallets المسجّل في PORT.md.

- باج منقول زي ما هو — PUT /api/branches/{id}: `($v === '' && $col !== 'name') ? null : $v` معناها إن `name: ""` بيتخزّن نص فاضي من غير أي تحقق، بينما POST بيرفض الاسم الفاضي. اسم فرع فاضي ممكن يتخزّن من التعديل بس.

- باج/تفاوت منقول — DELETE /api/zones/{id} **مافيهوش** entities_assert_zone_in_scope بينما PUT فيه. يعني مشرف أي فرع يقدر يمسح منطقة أي فرع تاني، لكن مايقدرش يعدّلها. اتنقل زي ما هو.

- تفاوت منقول — رسالة الدور غير المعروف: الإنشاء 'دور غير معروف: ' . $roleAr (باسم الدور)، التعديل 'دور غير معروف' (من غير اسم). النصين اتنقلوا بالحرف.

- تفاوت منقول — نص «اسم مستخدم المحل مطلوب» في POST /api/store-contacts من غير اللاحقة `(?store=)` اللي في مسار القراية GET. النصين مختلفين في الأصل واتنقلوا زي ما هما.

- فرع ميت اتنقل زي ما هو — entities_users_fetch_guarded بتفحص `role !== 'admin'` قبل ما ترمي 'هذا الحساب محمي — التعديل من الإدارة فقط' 403، لكن التلات مسارات اللي بتناديها (update/block/delete) كلها admin-only أصلًا فالشرط عمره ما هيتحقق. سيبته زي ما هو كحماية دفاعية.

- protected=1: الحذف ممنوع **على الجميع بما فيهم الأدمن** — الفحص الصريح `if ((int)$target['protected'] === 1) fail('هذا الحساب محمي — ممنوع حذفه', 403)` بيجي بعد fetchUserGuarded. التعديل والحظر بيعدّوا للأدمن (لأن fetchUserGuarded بتسمح له). ده سلوك الأصل بالحرف.

- entities_users_write_perms اتنقلت كـ writeUserPerms: **استبدال كامل** جوه المعاملة. التفرقة بين null (الحقل مش متبعت → مايتلمسش) و[] (امسح كله) محفوظة عبر `is_array($b['allowedApps'] ?? null) ? ... : null` في نقطة الاستدعاء. pagePerms بيقبل الشكلين {app:{page:true}} و{app:[page,page]}.

- entities_assert_zone_in_scope اتنقلت كـ assertZoneInScope: `$ub === 0` (مشرف فرع بلا branch_id) = مرفوض دايمًا، مش مسموح له بكل المناطق.

- المعاملات: DB::transaction اتستخدمت في branchesCreate/branchesUpdate (الفرع + branch_areas) و usersCreate/usersUpdate (الحساب + جدولين صلاحيات) — دي الأربعة اللي الأصل كان بيعمل فيها beginTransaction/commit/rollBack يدوي. مفيش beginTransaction يدوي في أي مكان. باقي المسارات جملة كتابة واحدة زي الأصل بالظبط ومحطتش عليها معاملة عشان مااضيفش سلوك مش موجود (partyCreate/partyUpdate فيهم قراءة رجوع بعد الكتابة — قراءة مش كتابة).

- مفيش SELECT ... FOR UPDATE في الملف المصدر خالص — مافيش أي قفل صف في مجال الكيانات، فمفيش ترتيب أقفال يتحافظ عليه هنا.

- أخطاء القاعدة: catch (Illuminate\Database\QueryException) و`$e->errorInfo[1]` (QueryException بترث PDOException وبتنسخ errorInfo من الاستثناء الأصلي — اتأكدت من vendor). 1062 → رسالة التكرار الخاصة، 1451 → رسالة الـFK الخاصة، وأي كود تاني → error_log + الرسالة الخاصة 500. الاستثناء: POST /api/admin-emails بتعمل `throw $e` لأي كود غير 1062 — فبتوصل للمعالج العام وترجع «خطأ في قاعدة البيانات» 500. ده سلوك الأصل بالحرف (مختلف عن باقي المسارات).

- الجسم بيتقرا بـ`$request->json()->all()` مش `$request->input()` — عن قصد: الكود الأصلي معتمد بالكامل على `array_key_exists($wire, $b)` عشان يفرّق بين «الحقل مش متبعت» و«متبعت null/فاضي»، وده أساس التعديل الجزئي. `input()` بيدمج مصادر ومابيديش المصفوفة الخام. TolerantJsonBody بيضمن إن الجسم الفاضي/المكسور = [].

- lastInsertId بيتقرا بـ`DB::getPdo()->lastInsertId()` — اتأكدت إن config/database.php مافيهوش read/write split فالـPDO واحد، و`Connection::getPdo()` بيرجّع اتصال الكتابة.

- المسارات اللي مالهاش role_middleware (POST senders/receivers/store-contacts و DELETE store-contacts) لازم تفضل كده — الأصل require_auth() بس والتقييد جوه الكنترولر (403 من جوه). إضافة middleware دور ليها = تغيير سلوك.

- ترتيب التسجيل: مفيش تعارض حرفي/نمطي جديد — `senders/lookup` و`receivers/lookup` مالهمش `{id}` مقابل بنفس الميثود (GET)، و`users/{id}/block` (POST) شكله مختلف عن `users` (POST). برضه الأأمن تسجّل الحرفي قبل النمطي زي باقي الملف.

- المسارات دي مالهاش رد فيه فلوس، لكن zones (price) و pilots (commission_type/commission_value/monthly_salary) بيكتبوا القيم اللي بتتحسب منها أسعار التوصيل ومستحقات الطيارين — علّمتهم touches_money=true عشان المراجعة. القيم بتتخزّن كما هي بلا حد أدنى/أقصى ولا تقريب زي الأصل (`(float)` بس)، ونوع العمولة بيتحقق ضد Vocab::COMMISSION_TYPE_WIRE.

- الملف الوحيد اللي اتعدّل: OrdersController.php — الدوال القديمة (index/stats/show/sessionPilotId) اتسابت زي ما هي واتضاف عليها store/split/update/addImages/upload + أدوات داخلية. مفيش كلاس Wire جديد: OrderWire::full بيغطي كل الإخراج (orders_out).

- ترتيب التسجيل: مسارات الـ5 دول مفيهاش تعارض مع بعض ولا مع المسجّل حاليًا (POST orders مقطعين، POST orders/{id}/split و/images تلاتة). بس لو اتسجّل بعدين POST orders/assign-bulk أو POST orders/settle-money-bulk لازم يبقوا **قبل** أي نمط POST orders/{id}، عشان الراوتر القديم كان بيعمل مطابقة حرفية الأول.

- addImages و upload من غير middleware دور عن قصد — الأصل require_auth() بس، والتقييد جوه الكنترولر بـ actorOrFail() (401) + guardBranch (403).

- الأخطاء اللي بترجع 409 في split و update: الأصل بيرمي RuntimeException وبيمسكها بـ catch يحوّلها fail(msg, 409). نقلتها كـ ApiException(msg, 409) بالنص الحرفي. أما guardBranch فبترجع 403 (fail مباشر مش RuntimeException) — وده الفرق اللي لازم يفضل.

- رسايل الـ500 الخاصة بالمسار محفوظة: catch(QueryException) حوالين DB::transaction بيرمي «خطأ أثناء حفظ الأوردر — جرّب تاني» (create) · «التفريق ما تمّش — جرّب تاني» (split) · «التعديل ما تمّش — جرّب تاني» (update). من غير الـcatch ده كان المعالج المركزي هيرد «خطأ في قاعدة البيانات» وده تغيير عقد.

- 🔴 باج LAST_INSERT_ID: نقلت **النسخة المصلّحة** زي ما اتطلب — الـupsert على order_counters بعده SELECT counter صريح، مش LAST_INSERT_ID(). التعليق الأصلي اللي بيشرح الباج (أول شحنة كل يوم كانت بتاخد id الصف بدل 001، اتكشف بـe2e_phase1 يوم 2026-08-19) اتنقل كامل جوه الكود.

- 🔴 باج فلوس منقول زي ما هو في orders_create: totalDeliveryPrice = array_sum(zone_price) و storePrepaid = array_sum(order_price) **من غير round(x,2)**. تراكم الفاصلة العائمة بيقدر يطلع 0.30000000000000004 على أسعار كسرية. مانقلتش أي round عشان الأصل مافيهوش.

- 🔴 باج فلوس منقول زي ما هو في orders_split: (أ) الأربع مجاميع (selTotal/selPrepaid/remTotal/remPrepaid) من غير round. (ب) الأصل بيدوس على store_prepaid وtotal_delivery_price بتوع الأوردر الأب بمجموع الطرود المتبقية — فأي قيمة يدوية اتحطت في store_prepaid وقت الإنشاء (مش مساوية لمجموع order_price) بتضيع بعد أول تفريق. (ج) goods_value و wallet_used و money_settled مابيتقسموش ولا بيتنقلوا للأوردر الفرعي خالص — الفرعي بياخد الافتراضي بتاع العمود. (د) pieces_count اليدوي بيتدهس بـcount(الطرود) في الاتنين.

- باج تاني في split: array_filter على array_map('intval', parcelNos) بيشيل الصفر — يعني طرد رقمه 0 مستحيل يتختار، وطلب فيه parcelNos:[0] بيرد «اختر طردًا واحدًا على الأقل». اتنقل زي ما هو.

- باج في PUT /api/orders/{id}: حقول المُرسِل ليها fallback للقيمة القديمة (`$b['x'] ?? $order['x']`)، لكن حقول الطرد **مالهاش** — إرسال {id:5} لوحده بيمسح receiver_name/phone/phone2/address للطرد ده. اتنقل بالحرف وموثّق بتعليق في الكود.

- ثغرة صلاحيات منقولة في POST /api/orders/{id}/images: التحقق require_auth() + نطاق الفرع بس. يعني أي محل أو طيار أو عميل مسجّل يقدر يضيف صور لأي أوردر في النظام (ما دام مش دور branch من فرع تاني). الأصل كده، فاتنقل كده.

- استثناء وحيد عن «صفر تغيير»: orders_add_images في الأصل بيكتب من غير معاملة (N إدخالات + لمسة updated_at). لفّيت الكتابات في DB::transaction طبقًا للقاعدة رقم 2. الرد ومفاتيحه وأكواد الحالة ما اتغيروش — الفرق الوحيد إن الاستطلاع مابيشوفش نص الصور. لو المراجعة عايزاه حرفي 100% يتشال السطرين.

- orders_create: التحقق كله (الفرع/الطرود/المُرسِل) قبل DB::transaction زي الأصل بالظبط، وتخصيص رقم الأوردر آخر حاجة بعد بحث المناطق — عشان القفل على صف العدّاد يتمسك لأقل وقت ولو التحقق فشل مايحصلش فجوة في الترقيم.

- ترتيب الأقفال في split اتساب كما هو: orders FOR UPDATE ← order_deliveries FOR UPDATE ← pilots FOR UPDATE ← shifts (بلا قفل) ← الإدخالات والتحديثات. كلها DB::select('... FOR UPDATE') خام جوه المعاملة.

- «اشتقاق الفرع من الزون» **مش موجود** في orders.php — orders_create بياخد الفرع من جلسة مشرف الفرع أو من body.branchId. الاشتقاق من zones.delivery_branch_id موجود في api/routes/customer_app.php (سطور 323، 401، 623-629) وده مجال وكيل تاني (customer_order_create). ما نقلتش حاجة منه.

- POST /api/upload: النوع بيتقرا من محتوى الملف بـ\finfo(FILEINFO_MIME_TYPE) مش من الامتداد، وترتيب الفحوصات محفوظ (وجود الملف ← error ← الحجم 5MB ← الـmime). استعملت is_uploaded_file() و move_uploaded_file() حرفيًا — دول بيشتغلوا تحت SAPI حقيقي (بوابة parity بتنده HTTP فعلي) لكنهم بيفشلوا لو اتكتب اختبار Laravel بـUploadedFile::fake(). لو ده لزم، يتحوّلوا لـ$f->isValid()/$f->move() مع ملاحظة إن isValid بتدمج الفحصين الأولين.

- مسار التخزين بقى public_path('uploads/YYYYMM') يعني D:\dahshaneg\dahshan\public\uploads — الأصل بيكتب في aldahshan\public\uploads. الرابط المرجّع نفسه ('/uploads/YYYYMM/name') فالعقد ما اتغيرش، بس الملفات المرفوعة من لارافل مش هتبان من النظام القديم والعكس. محتاج قرار وقت النشر (symlink أو مجلد مشترك).

- الأدوات الداخلية المضافة (lockOrderRow / guardBranch / activeShiftId / queueCompact / orderOut / intOrNull / floatOrNull / trimOrNull) هي المقابل لـ orders_lock_row / orders_guard_branch / orders_active_shift_id / orders_queue_compact / orders_out. ⚠️ وكيل تاني بيشتغل على assign/transfer/deliver في نفس الكنترولر غالبًا هيضيف نفس الأسماء — لازم الدمج المركزي يدمجهم مش يكرّرهم. لسه ناقص من الأدوات: orders_sync_pilot_status و orders_next_queue_no و orders_guard_pilot (مالهمش لزوم في المسارات الخمسة دي).

- php -l خضرا على الملف، والكلاس بيتحمّل من الـautoloader (reflection طلّع الـ17 دالة)، و`php artisan route:list --path=api` لسه 54 مسار — يعني مفيش كسر في الإقلاع. ما لمستش routes/api.php ولا أي حاجة في aldahshan، ولا شغّلت migrate.

- تسجيل المسارات: `orders/assign-bulk` و`orders/settle-money-bulk` مقطعين، و`orders/{id}/...` تلات مقاطع — مفيش تعارض، لكن الأأمن تسجيلهم قبل مسارات `{id}`. كلهم POST فمابيتصادموش مع `GET orders/{id}`.

- صفر `beginTransaction` يدوي — 17 نداء `DB::transaction` و12 `FOR UPDATE` عبر `DB::select`. مفيش Eloquent ولا JsonResource.

- التحقق من النصوص: سكريبت قارن 34 نص عربي حرفي (كل رسايل الأخطاء + شظايا الرسايل المركّبة زي «الأوردر ده اتحمّل على » و« من ثانية — اعمل تحديث للصفحة» و« مالوش وردية مفتوحة — ...») بين الكنترولر الجديد و`api/routes/orders.php` — كلها موجودة بالحرف في الاتنين.

- 🔴 `claimCore` منقول بترتيب الجُمل بالحرف: قفل صف الأوردر → حارس الفرع → فحص «متحمّل على طيار تاني» → فحص الحالة المسموحة → قفل صف الطيار → الوردية المفتوحة → UPDATE مشروط بالحالة (`WHERE id = ? AND status IN ('processing','undelivered')`) → لو 0 صف بنعيد قراية الصف عشان اسم الطيار الكاسب في الرسالة → إزاحة الطيار من الدور.

- 🔴 تحويل `fail()`/`RuntimeException` لأكواد الحالة: كل `throw new RuntimeException` في الأصل بيتحوّل لـ409 عند الـcatch، وكل `orders_guard_branch`/`orders_guard_pilot` بتنده `fail(..., 403)` اللي بتعمل exit ومابتتمسكش بالـcatch. عشان كده رميت `ApiException(..., 409)` مباشرة في كل نقطة رمي، والحراس بيرموا 403. النتيجة نفس أكواد الحالة بالظبط من غير أي تحويل.

- 🔴 **`assign-bulk` وسلوك الحارس**: في الأصل `orders_guard_branch` جوه الحلقة بتعمل exit بـ403 فبتقطع الطلب كله — والأوردرات اللي اتحمّلت قبلها بتفضل متحمّلة (معاملات مستقلة). نقلته بالحرف: `catch (ApiException $e) { if ($e->status() !== 409) throw $e; ... }`. يعني 409 بيروح `failed[]` والحلقة بتكمّل، و403 بيقطع.

- `assign-bulk`: `$now` بيتحسب **مرة واحدة قبل الحلقة** في الأصل فكل الأوردرات بتاخد نفس الطابع — منقول زي ما هو. وكمان `$warning = $res['warning'] ?? $warning` (مش `=`) عشان تحذير فاضي مايمسحش تحذير سابق.

- 🔴 فلوس `deliver`: `net_delivery_price = max(0.0, (float)total_delivery_price - (float)wallet_used)` — **من غير `round()`** زي الأصل بالحرف.

- 🔴 فلوس `settle-money-bulk`: مفيش أي حساب — علم `money_settled = 1` بس. الرد `settled` = عدد الصفوف المتغيّرة فعلًا (`DB::update` = affected rows زي `rowCount()` تحت `EMULATE_PREPARES=0` من غير `MYSQL_ATTR_FOUND_ROWS`)، فأوردر متسوّي قبل كده مابيتعدّش.

- `settle-money-bulk`: الـ`branch_id` بيتحط بالتضمين النصي (`' AND branch_id = ' . (int)$actor->branchId`) في استعلام الـ`FOR UPDATE` الأول، وبباراميتر `?` في الـUPDATE — **التفرقة دي في الأصل نفسه** ونقلتها زي ما هي. الـcast لـint هو اللي بيمنع الحقن.

- ⚠️ باج/شذوذ منقول زي ما هو — `receive` و`start-trip` بينادوا `orders_guard_pilot` **بس** من غير `orders_guard_branch`: مشرف فرع (أو أدمن) يقدر يسجّل استلام/بدء رحلة لأوردر تابع لفرع تاني. `deliver` و`undeliver` بينادوا الاتنين.

- ⚠️ شذوذ منقول — `transfer-branch` مافيهوش أي فحص للحالة: تقدر تنقل أوردر «متسلّم» أو «ملغي» لفرع تاني، و`created_at` بيتصفّر لوقت النقل فبيختفي من تقارير يوم إنشائه الأصلي.

- ⚠️ شذوذ منقول — `postpone` بيكتب `prev_status = $order['status']` **من غير** `?: 'processing'`، بينما `cancel` و`unpostpone` بيستعملوا الـfallback. في الأصل بالظبط كده.

- ⚠️ شذوذ منقول — `settle-money-bulk` بمسار `orderIds` لدور `branch`: أوردرات الفروع التانية بتتخطى بصمت (فلتر `AND branch_id = ?`) لكن الرد `orderIds` بيرجّع القايمة المبعوتة كاملة، فـ`settled` ممكن تقل عن `count(orderIds)` من غير أي خطأ.

- ⚠️ شذوذ منقول — لو اتبعت `pilotId` و`orderIds` مع بعض في `settle-money-bulk`، الـ`pilotId` **بيدوس** على `orderIds` بالكامل.

- رسالتين متشابهتين ومختلفتين عن قصد: `guardPilot` (دورة الحياة) = «الأوردر ده مش **محمّل** عليك»، و`show()` (القراءة) = «الأوردر ده مش **متحمّل** عليك». الاتنين حرفيين من الأصل — ممنوع توحيدهم.

- `receive` · `start-trip` · `postpone` · `unpostpone` · `settle-money` مافيهمش `catch (PDOException)` في الأصل — فسيبتهم من غير `catch (QueryException)` عشان خطأ القاعدة يطلع للمعالج المركزي برسالة «خطأ في قاعدة البيانات» 500 زي الأصل. الباقي ليه رسالة 500 خاصة بالمسار.

- `orders_sync_pilot_status` و`orders_next_queue_no` اتنقلوا كـ`syncPilotStatus`/`nextQueueNo` (ساكنين، جوه معاملة). `orders_queue_compact` و`orders_active_shift_id` و`orders_lock_row` و`orders_guard_branch` كانوا موجودين بالفعل في الكنترولر من الجولة السابقة واستعملتهم زي ما هم من غير تعديل.

- `nextQueueNo` بيستعمل `SELECT COALESCE(MAX(queue_no),0)+1 AS n ... FOR UPDATE` — الـ`FOR UPDATE` على الـaggregate بيقفل نطاق صفوف المنتظرين، وده اللي بيمنع طيارين ياخدوا نفس رقم الدور. منقول بالحرف.

- في `transfer`: `syncPilotStatus` بتتنده للطيار **الجديد الأول ثم القديم** — الترتيب ده جزء من الصح (لو اتعكس، الطيار الجديد ممكن يتحسب فاضي لحظة). و`status_since` مالوش لمس (قاعدة 1 في VOCAB بند 13.2).

- مفيش Wire جديد اتضاف — `OrderWire::full()` الموجود بيغطي كل ردود المسارات دي عبر `orderOut()`.

- `php -l` عدّى على الكنترولر بعد كل تعديل. مالمستش أي حاجة في `D:\dahshaneg\aldahshan` ولا في `routes/api.php`.

- GET /api/closeouts/monthly (BoardController@closeoutGet) **متسجّل بالفعل** في routes/api.php من جولة القراءة — المطلوب إضافته هو POST بس على نفس المسار.

- الملف اتزوّد مش اتكتب من جديد: BoardController كان فيه 9 مسارات قراءة + الأدوات المساعدة (branchScope · intId · firstColumn · monthRange · buildMonthlyData) وكلها اتسابت زي ما هي واستُعملت. اتضاف 10 دوال عامة + 11 دالة خاصة، وفوقهم use لـ Vocab و WireTime و Throwable. مالمستش app/Wire/BoardWire.php خالص — BoardWire::closeout الموجود كفى closeoutSave.

- **نمط الـcatch**: الأصل بيلف كل مسار كتابة في try/catch(Throwable) بيرجّع رسالة عامة 500 («تعذّر إدخال الطيار في الدور» / «تعذّر فتح الوردية» / «تعذّر إنهاء الوردية» / «تعذّرت تسوية عودة الطيار» / «تعذّر إيقاف الطيار» / «تعذّر حفظ التقفيلة الشهرية» / «تعذّر إعادة ترتيب الدور» / «تعذّر إخراج الطيار من الدور» / «تعذّر نقل الوردية»). fail() القديمة بتعمل exit فمابتقعش في الـcatch — عشان كده كل بلوك عندنا فيه `catch (ApiException $e) { throw $e; }` قبل `catch (Throwable)`. من غيره كل 400/403/404 كان هيتحوّل 500 وده تغيير سلوك.

- 🔴 board_apply_custody_delta اتنقل بالحرف بعيوبه: (1) العتبة abs(delta) < 0.005 (نص قرش) — أقل من كده مفيش حركة أصلًا. (2) العهدة بتتقص عند صفر (newCustody < 0 → 0.0) يعني تسديد زيادة أكبر من العهدة **بيضيع الفرق** ومابيتحوّلش لرصيد للطيار. (3) صف custody_transactions بياخد abs($delta) **الكاملة** مش المقصوصة — فمجموع الحركات ممكن مايطابقش pilots.custody_balance. **باج موروث، منقول زي ما هو.**

- board_apply_cash_txn: `type === 'in'` موجب وأي حاجة تانية سالب، والرصيد بيتحدّث بـ`balance = balance + ?` بعد قفل الصف. مفيش أي فحص إن الخزنة تابعة للفرع اللي بيسوّي — أي مشرف يقدر يودّع في أي خزنة بالـid. منقول زي ما هو.

- board_settle_pilot_money: تعليق الأصل نفسه بيوثّق قرار — «الأصل مكانش بيعلّم moneySettled هنا رغم تحصيل الفلوس في نفس اللحظة — بنعلّمها 1 منعًا لعدّ الأوردر تاني في تسوية لاحقة». التعليق اتنقل مع الكود. كمان: القرار الافتراضي لأي أوردر «جاري التوصيل» مش مذكور في orders[] هو **delivered** (يعني الفلوس بتتحسب عليه)، وسبب عدم التسليم الفاضي بيتخزن «—».

- `$collectedAmount > 0` صارمة: تحصيل بصفر **مابيعملش حركة خزنة خالص** (ولا بيطلب cashStoreId) لكن فرق العهدة بيتحسب عادي. تحصيل > 0 من غير cashStoreId = 400 «اختر الخزنة لإيداع المبلغ المحصَّل من الطيار».

- ⚠️ تناقض فرع التسوية بين المسارين ومنقول زي ما هو: shiftEnd بياخد الفرع من `board_branch_scope($user, shift.branch_id)` (يعني فرع المشرف لو دوره branch)، لكن pilotReturn بياخد **فرع الطيار المسجّل أولًا** وبيرجع لـbranchScope بس لو الطيار بلا فرع. يعني حركة الخزنة والعهدة في العودة بتتقيّد على فرع الطيار مش فرع المشرف.

- ⚠️ board_assert_pilot_in_scope متندى عليها في **force-leave بس** من كل مسارات المجموعة دي. pilotReturn و queueEnter و queueLeave و shiftOpen و shiftEnd و shiftTransfer و closeoutSave **مفيهاش أي فحص نطاق على الطيار** — مشرف فرع يقدر يسوّي فلوس/يقفل وردية/يحفظ تقفيلة (بمرتب وسلف) لطيار فرع تاني بالـid. باج أمان موروث؛ منقول بالحرف زي ما هو مسجّل في تعليق closeoutGet الموجود أصلًا.

- ⚠️ shiftSettlement هو **المسار الوحيد بلا معاملة ولا قفل** — جملة UPDATE واحدة زي الأصل. والقيم بتتكتب كاملة كل مرة (مش فرقية): البند اللي مش مبعوت بياخد صفر/فاضي مش قيمته القديمة. عكس shiftEnd اللي بياخد قيمة صف الوردية كافتراضي. الفرق ده متعمّد في الأصل.

- ⚠️ الافتراضي في shiftSettlement و shiftEnd لأي قيمة تسوية غير daily/monthly هو **monthly** (في shiftEnd: القيمة الحالية في الصف)، بينما BoardWire::shift بيطلّع `daily` كافتراضي على السلك. تناقض موروث متسجّل أصلًا في تعليق BoardWire — منقول زي ما هو.

- ⚠️ `rowCount()` في shiftSettlement: MySQL بيرجّع الصفوف اللي **اتغيّرت فعلًا** مش المطابقة، فحفظ نفس القيم تاني بيدي 0. الأصل بيعمل فحص وجود بعدها ويرجّع 404 «الوردية غير موجودة» بس لو الصف مش موجود، وإلا ok:true. Laravel DB::update بنفس الدلالة (FOUND_ROWS مقفول افتراضيًا) — نفس السلوك.

- 💰 closeoutSave: المعادلة بالحرف — net = salary + totalCommission + totalBonus − totalDeduction − totalAdvance − (unpaidLeaveDays × dailyRate)، و dailyRate = salary ÷ daysInMonth (صفر لو الشهر بصفر أيام). التقريب round(...,2) على dailyRate و net **بس** وقت التخزين؛ باقي المجاميع بتيجي مقرّبة أصلًا من buildMonthlyData. `paidLeaveDays` بيتخزن لكنه **مش داخل في الحساب خالص**. و `required_daily_hours = 0` بيتخزن NULL على صف الطيار (`?: null`).

- closeoutSave بيقرا اللقطة **بعد** الـcommit ويرجّعها. لو الصف مالقاش (مستحيل عمليًا) BoardWire::closeout(null) بترمي TypeError وبتتحوّل لـ500 «تعذّر حفظ التقفيلة الشهرية» — نفس سلوك الأصل بالظبط (board_ser_closeout(false)).

- board_queue_reorder: `array_filter` من غير callback بتشيل الأصفار، يعني pilotIds:[0,5] بيبقى [5] من غير خطأ. وأي منتظر في الفرع مش موجود في القايمة المبعوتة بياخد آخر الدور بترتيبه القديم — الأرقام تفضل متراصة 1..n.

- queueShiftAfterRemoval بتستخدم `!$branchId || !$removedQueueNo` مش `=== null` — يعني queue_no = 0 بيتعامل كفاضي والإزاحة مابتحصلش. سلوك الأصل.

- shiftOpen بيشترط `status !== null && status !== ''` (الطيار حر تمامًا)، والترتيب جواه مهم: enterQueue الأول بعدين openOrTransferShift — لأن التانية ممكن **تنقل** وردية مفتوحة من فرع تاني بدل ما تفتح جديدة، والقفل على صف الطيار لازم يكون واخد قبلها.

- shiftTransfer: النقل لنفس الفرع = لا شيء (ولا صف تاريخ) لكن الرد ok:true. ومفيش أي فحص نطاق على الفرع الجديد ولا على الطيار.

- pilotForceLeave: نوع إذن غير معروف بيتحوّل `rest` بصمت مش 400 (Vocab::LEAVE_TYPE_WIRE = rest/dayoff/incident). و `leave_reason` على صف الطيار بياخد النص الفاضي زي ما هو، بينما صف pilot_leave_requests بياخد null (`?: null`) — فرق موروث ومنقول.

- **استخدمت `$request->input()` مش قراءة الجسم الخام** حسب البند 7 — خد بالك إن Laravel بيدمج الـquery string كـfallback تحت الجسم، بينما body_json() القديمة كانت بتقرا الجسم بس. عمليًا الفرق بيظهر بس لو حد بعت باراميتر في الـURL على مسار POST (زي POST /api/queue/enter?pilotId=5 بجسم فاضي) — الأصل كان هيرد «معرّف غير صالح» وإحنا هناخد الـ5. نفس النمط المتبع في OrdersController/EntitiesController اللي عدّوا 241 فحص تطابق، فسايبه كده للاتساق — لو البوابة لقطته يتحوّل لقراءة الجسم صراحةً.

- الترتيب في routes/api.php: سجّل `POST shifts/open` قبل أي `POST shifts/{id}` لو اتضاف واحد لاحقًا (دلوقتي مفيش تعارض فعلي — كل مسارات {id} فيها مقطع تالت). و `POST closeouts/monthly` لازم يكون على نفس مسار الـGET الموجود.

- فحص: `C:\xampp\php\php.exe -l app/Http/Controllers/Api/BoardController.php` → No syntax errors. وتحميل الكلاس بالـautoload + ReflectionClass أكّد وجود الـ19 دالة عامة و21 دالة خاصة بلا تصادم أسماء.

- ملفات اتلمست: BoardController.php بس (إضافة 23 دالة عامة + دالة مساعدة خاصة pilotBackToWaitingIfFree). مفيش حاجة اتمسحت. مااتضافش سيريالايزر جديد — BoardWire موجود بالفعل وكامل لكل الأنواع دي، والمسارات دي كلها بترد {ok} أو {ok,id} أو {ok,pilotId} أو {ok,shiftId} من غير كائنات سلك.

- php -l على BoardController.php = نظيف. وفحص تطابق نصوص: كل الـ41 نص عربي حرفي في الكتلة الجديدة موجود حرفيًا في aldahshan/api/routes/board.php (سكريبت الفحص في السكراتشباد: chk.php) — صفر اختلاف.

- routes/api.php مااتلمستش زي التعليمات. ⚠️ ترتيب التسجيل: POST orders/{id}/clear-return-flag لازم تتسجّل مع مسارات الأوردرات — مفيش تعارض شكلي مع GET orders/{id} لأن الميثود مختلف والمقطع زايد.

- بواجات موروثة اتنقلت زي ما هي (متصلحتش):
• clear-return-flag: **مفيش أي فحص ملكية** — أي طيار يمسح علامة إرجاع أي أوردر بالـid، وأوردر مش موجود بيرجّع ok:true (مفيش فحص rowCount).
• support-requests/{id}/cancel: مفيش فحص إن الملغي هو الفرع الطالب — أي فرع يلغي أي إنذار معلّق.
• support-requests/{id}/respond: مفيش فحص إن الطلب لسه pending قبل تسجيل الرد، ولا منع تكرار رد نفس الفرع — صفوف ردود مكررة ممكنة.
• POST leave-requests: مفيش assertPilotInScope — مشرف فرع يسجّل إذن لطيار فرع تاني. ولو الطيار مش موجود، fetchColumn() بيرجّع false فالفرع بيقع على فرع المشرف والـINSERT بيتكسر على الـFK ويطلع «خطأ في قاعدة البيانات» 500 بدل 404.
• POST shift-requests: فحص «طلب معلّق موجود» من غير معاملة ولا قفل — طلبين في نفس اللحظة بيعدّوا الاتنين.
• pilot-transfers/{id}/end: مفيش فحص إن النقل type=temp — النقل الدائم يقدر يتعلّم ended برضه، والطيار مابيرجعش لفرعه القديم تلقائيًا.
• join-requests/{id}/approve: فحص تكرار username جوه المعاملة بس من غير قفل — سباق نظري بيتمسك بقيد UNIQUE.
• branchScope لمشرف فرع بـbranch_id فاضي بيرجّع 0 (مش null) — في transferCreate ده بيقع على «حدّد الفرع المنقول منه» بدل ما يقع على فرع الطيار، وفي joinRequestCreate بيتخزّن branch_id = 0.

- فروق صغيرة موروثة اتنقلت بالحرف:
• join approve بيهش بـPASSWORD_BCRYPT صراحةً بينما EntitiesController بيستخدم PASSWORD_DEFAULT — مفيش أثر عملي (الاتنين password_verify).
• leave approve بيكتب leave_reason = $req['reason'] ?? '' على صف الطيار (نص فاضي) بينما صف الطلب بياخد null.
• leave end: ended_by بياخد النص الحرفي 'pilot' لو الطيار هو المنهي مش username.
• return approve: undelivered_reason = ($req['reason'] ?: null) ?? 'إرجاع من الطيار' — الشكل الغريب اتنقل زي ما هو.
• transferCreate بيقرا type من القاعدة (temp/permanent) مش من السلك (direct) — الترجمة اتجاه واحد بس في BoardWire::transfer.

- حالات الاستثناء: كل مسار بيلف الـtransaction في try/catch(ApiException → rethrow)/catch(Throwable → 500 برسالة المسار). ده لازم لأن fail() القديمة بتعمل exit فمابتقعش في catch(Throwable) بتاع الأصل — من غير الـrethrow كنا هنحوّل كل 400/403/404 لـ500. نفس نمط الدوال الموجودة في نفس الكنترولر.

- المسارات اللي uses_transaction=false كلها جملة INSERT/UPDATE واحدة (أو قراءات + INSERT واحد) — زي الأصل بالحرف، مفيش قفل صف فيها. الرفض والإلغاء بيعتمدوا على `WHERE ... AND status = 'pending'` كحجز ذري بدل القفل، و`rowCount()` صفر = 404 بنفس الرسالة لـ«مش موجود» و«اتبتّ فيه» مع بعض.

- returnRequestApprove اتعلّمت touches_money=true لأنها بتطلّع الأوردر من حالة delivering من غير تسوية — وده بيغيّر اللي settlePilotMoney بيعدّه بعدين في تقفيلة الوردية (الأوردر مابيدخلش expected). مفيش أي حساب فلوس صريح ولا round() في أي مسار من الـ23، ومفيش لمس لـcash_stores ولا custody_transactions.

- دالة مساعدة جديدة: pilotBackToWaitingIfFree() — نقل حرفي لـboard_pilot_back_to_waiting_if_free(). بترجّع false من غير تغيير لو الطيار مالوش فرع، ومفيش فيها استثناء on_leave (على عكس OrdersController::syncPilotStatus) — فرق موروث متسجّل في التعليق فوق الدالة.

- 🔴 باج منقول زي ما هو — finance_amount(): الفحص `$a <= 0` بيحصل **قبل** `round($a, 2)`. يعني `amount: 0.001` بيعدّي الفحص وبيتخزّن `0.00` في عمود DECIMAL(12,2). الأثر العملي: (1) صف حركة نقدية/عهدة بمبلغ صفر، (2) في مسار المحفظة رصيد مابيتغيّرش بس سجل حركة بيتكتب بـamount=0.00 وbalance_after زي ما هو. الدالة الجديدة `FinanceController::amount()` نقل حرفي بالباج، والتعليق فوقها بيوثّقه. الإصلاح = تغيير سلوك فاتساب لقرار منفصل.

- ⚠️ تفاوت في الأصل منقول: `finance_expenses_update` **مابتندهش finance_amount()** — بتعمل `round((float)$body['amount'], 2)` الأول وبعدين تفحص `<= 0`. يعني نفس المدخل `0.001` **بيترفض** في PUT /api/expenses/{id} بينما **بيعدّي** في POST /api/expenses. التفاوت ده اتنقل بالحرف (شوف تعليق expensesUpdate).

- 🔴 finance_lock_wallet منقولة كاملة بـ`INSERT IGNORE` — بيمتص سباق الإنشاء المتزامن على الفهرس الفريد uq_wallets_owner. الـSELECT التاني بعد الإدراج **بـFOR UPDATE برضه** (مش SELECT عادي) عشان الصف يرجع مقفول. لو الصف لسه مش موجود بعد الإدراج → ApiException('تعذر إنشاء المحفظة', 500).

- 🔴 finance_require_wallet_access منقولة كما هي وبتعليقها الأصلي اللي بيوثّق ثغرة IDOR مالي مؤكّدة بالاختبار. مطبّقة على `POST .../use` بس (زي الأصل بالظبط) — credit/debit مالهمش بوابة محفظة لأن الدور نفسه هو البوابة. ترتيب النداءات في use: actorOrFail (401) ← walletOwner (400) ← requireWalletAccess (403). الدالة كانت موجودة أصلًا في الكنترولر من جولة القراءة، استعملتها زي ما هي من غير تعديل.

- 🔴 `finance_tx` اتنقلت كـ`FinanceController::tx()` وفيها **إعادة رمي ApiException صراحةً قبل catch(Throwable)**. ده مش تجميل: في الأصل `fail()` بتعمل exit فرسالة المسار الخاصة (زي «رصيد الخزنة لا يكفي لهذا المنصرف») مكانتش بتوصل للـcatch أبدًا. من غير الـrethrow كل أخطاء التحقق كانت هتتحوّل لرسالة الـ500 العامة «حصل خطأ أثناء تنفيذ العملية المالية — حاول تاني». اللي بيوصل للـcatch أخطاء تقنية بس، ورسالته الـ500 منقولة حرفيًا.

- 🔴 ترتيب الأقفال متحافظ عليه بالحرف: (أ) custodyCreate = **pilots ثم cash_stores** — عكسه بيعمل deadlock مع مسار تسوية الوردية في BoardController. (ب) cashTxnsApprove = **صف الحركة ثم صف الخزنة**. (ج) expensesCreate = **INSERT المصروف ثم قفل الخزنة** (الإدراج قبل القفل، مش بعده). (د) expensesUpdate/Delete = **قفل صف المصروف ثم قفل الخزنة**.

- ⚠️ cashTxnsApprove فيه حارس مزدوج منقول: `UPDATE ... WHERE id = ? AND type = 'pending'` + فحص rowCount. القفل لوحده كان كفاية نظريًا، بس الشرط في الـWHERE هو اللي بيضمن إن اعتمادين متوازيين مايزوّدوش رصيد الخزنة مرتين. رسالة التصادم «الحركة اتعتمدت من ثانية — اعمل تحديث» (400).

- ⚠️ المقارنات المرنة (`!=` مش `!==`) اتسابت زي ما هي في 3 مواضع لأن القيم جاية من الدرايفر **نصًا** ("0.00"): (1) `(float)$store['balance'] != 0.0` في cashStoresDelete، (2) `$delta != 0.0` في applyCashTxn (بيخلي pending مايلمسش جملة UPDATE أصلًا)، (3) `$amount != $oldAmount` في expensesUpdate. نفس سبب Commission.php.

- ⚠️ walletMove: `round($balance + $signed, 2)` — التقريب على **الناتج** مش على الطرفين، ونفس الرقم بيتخزّن في `wallets.balance` و`wallet_transactions.balance_after` سوا. وفحص السالب **بعد** التقريب، فرصيد -0.001 بيتقرّب لـ-0.0 وبيعدّي. ترتيب الجُمل: UPDATE wallets قبل INSERT wallet_transactions. و`amount` بيتخزّن **بالإشارة** مش مطلق.

- ⚠️ credit/debit بيعملوا **تطبيع مش تحقق** على `type`: أي قيمة مش في [credit, discount] بتبقى `credit`، وأي قيمة مش في [debit, settle] بتبقى `debit`. مفيش رسالة خطأ. سلوك الأصل بالحرف.

- ⚠️ attendanceHeartbeat **من غير معاملة ومن غير قفل** عن قصد — SELECT ثم UPDATE مستقلتين. التعليق الأصلي منقول لأنه بيوثّق باج اتصلح: الاعتماد على rowCount بتاع الـUPDATE كان بيرجّع 0 لو last_seen متغيّرش (نبضة في نفس الثانية) فكان بيبان غلط إن مفيش جلسة مفتوحة. الردين (`open:false` / `open:true`) الاتنين **200** — مفيش خطأ في المسار ده خالص.

- ⚠️ attendance check-in/heartbeat/check-out **مالهمش middleware دور** — الأصل `require_auth()` بس (أي حساب مسجّل بما فيهم الطيار والمحل والعميل). والأدمن بس هو اللي يقدر يسجّل حضور/انصراف لاسم تاني عبر `username` في الجسم؛ أي دور تاني بيبعتها بتتجاهل. check-in **idempotent**: جلسة مفتوحة موجودة = تحديث نبضة وإرجاعها بدل فتح تانية.

- ⚠️ manualEmployeesCreate بيستخدم `isset($body[k]) ? trim(...) : null` بينما manualEmployeesUpdate بيستخدم `array_key_exists($k, $body) ? trim(...) : $emp[col]`. النتيجة: `phone: null` بيتخزّن **NULL** في الإنشاء و**نص فاضي** في التعديل (trim على null = ""). تفاوت في الأصل، منقول.

- ⚠️ cashStoresCreate: مشرف الفرع بيتدوس على `branchId` اللي بعته ويتفرض عليه فرعه هو (`$user->branchId`) حتى لو بعت فرع تاني. والرصيد بيتخلق **صفر حرفيًا في نص الـSQL** مش من الجسم. وcashStoresUpdate **مش بيسمح بتعديل balance** خالص (ملحوظة حاكمة من الأصل — التعديل عبر cash_transactions حصريًا).

- ⚠️ cashStoresUpdate بيفحص الـ404 **بعد** الـUPDATE مش قبله: لو rowCount صفر بيتأكد الصف موجود ولا لأ. يعني تعديل بنفس القيم (صفر صفوف متغيّرة) بيرجّع الخزنة عادي مش 404. ملاحظة على السيمانتيك: لا الأصل ولا لارافل بيفعّلوا `PDO::MYSQL_ATTR_FOUND_ROWS`، فـrowCount = «الصفوف المتغيّرة» في الاتنين. نفس السلوك.

- ⚠️ اختلاف أدوار مقصود بين القراءة والكتابة اتنقل زي ما هو: manual-employees القراءة (admin/hr/branch) أوسع من الكتابة (admin/hr). وcash-stores: الإنشاء (admin/accountant/branch) ← التعديل (admin/accountant) ← الحذف (admin). وexpenses: الإنشاء والتعديل بأدوار الفلوس التلاتة بينما الحذف (admin/accountant) من غير branch.

- 🗑️ حذف الأرشيف المالي ممنوع ومتحافظ عليه: cashStoresDelete بيرفض خزنة عليها أي حركة («الأرشيف لازم يفضل»)، وexpensesDelete بيولّد حركة `in` عكسية بترجّع المبلغ **من غير ما يمسح** حركة الـ`out` الأصلية، وexpensesUpdate بيولّد حركة تسوية بالفرق مش بيعدّل الحركة القديمة.

- ⚙️ مافيش أي إضافة على `D:\dahshaneg\dahshan\app\Wire\FinanceWire.php` — كل الـserializers المطلوبة (store · cashTxn · custody · expense · walletTxn · attendanceSession · manualEmployee) كانت موجودة بالفعل من جولة القراءة ومطابقة للأصل. الملف ما اتلمسش خالص.

- ⚙️ اتضاف على FinanceController: ثابت `CUSTODY_TYPES` + 8 دوال داخلية جديدة (`body` · `amount` · `tx` · `lockStore` · `applyCashTxn` · `lockWallet` · `walletMove`) + استيراد `Log` و`Throwable`. الدوال الموجودة قبل كده (`intId` · `walletOwner` · `requireWalletAccess`) اتستعملت زي ما هي من غير تعديل، ولا دالة قراءة واحدة اتغيّرت.

- ⚙️ `body()` = `$request->json()->all()` (نفس نمط EntitiesController المتحقق منه) مش `$request->input()`، لأن كل مسارات التعديل هنا بتعتمد على `array_key_exists` عشان تفرّق بين «الحقل مش متبعت» و«متبعت فاضي/null» — وده أساس التعديل الجزئي. TolerantJsonBody بيضمن إن الجسم الفاضي أو المكسور = [].

- ✅ `C:\xampp\php\php.exe -l` على FinanceController.php = No syntax errors. وفحص Reflection طلّع 27 دالة عامة (9 قراءة قديمة + 18 كتابة جديدة) — مفيش تصادم أسماء ولا فقدان دالة.

- **خمسة من التمن مسارات المطلوبة مالهاش دوال جديدة خالص.** الأصل مابيكتبش منطق في pilot.php للتسليم/عدم التسليم/طلبات الإرجاع/الأذونات/الورديات — تعليق رأس الملف صريح: «منطق التسليم/الإرجاع/الأذونات/الورديات بيعاد استخدامه من orders.php و board.php — مفيش تكرار منطق (نفس المعاملات والأقفال)». الدوال دي كلها موجودة ومنقولة بالفعل في OrdersController و BoardController من الجولات السابقة، فسجّلت المسارات عليها مباشرةً. تكرار المنطق كان هيخلّق معاملتين وأقفالًا مختلفة لنفس العملية.

- **الأدوار — الصافي طيار بس في كل المسارات.** الأصل بيعمل تحقق مزدوج: pilot_order_deliver بتنده require_role('pilot') وبعدها orders_deliver اللي بتنده require_role('pilot','branch','admin') تاني؛ و pilot_leave_request_create بتنده require_role('pilot') وبعدها board_leave_request_create بـ require_role('pilot','admin','branch'). تقاطع الاتنين = pilot، وده اللي بيتحقق هنا بـ ->middleware('role:pilot'). أما board_return_request_create و board_shift_request_create فبيندوا require_role('pilot') جوّاهم أصلًا فالنتيجة واحدة.

- **POST /api/pilot/version مكانش في القايمة المطلوبة** لكنه مسار كتابة في نفس الملف المصدر (pilot_version سطر 499) والتطبيق المنشور بيندهه لبصمة النسخة — ومنها بتتقرر رسالة التحديث الإجباري في GET /api/pilot/state. ضفته وسجّلته. لو وكيل تاني ماسكه، اشطبه من جدول المسارات بس (الدالة مالهاش أثر جانبي على غيرها).

- 🔴 **POST /api/pilot/shift/end مابيعملش أي تسوية فلوس** — لا عهدة ولا خزنة ولا money_settled. الطيار بيقفل ورديته من التطبيق وهو في الشارع، والتسوية بتفضل شغلة الفرع من اللوحة (POST /api/shifts/{id}/end اللي بينده settlePilotMoney). ده مذكور صراحةً في تعليق الأصل: «من غير تسوية فلوس (التسوية بتفضل شغلة الفرع من اللوحة زي القديم بالظبط)». touches_money=false صح هنا — بس لاحظ إنه **بيسيب عهدة مفتوحة** على الطيار عمدًا.

- ⚠️ **pilot_shift_end بيشتغل حتى لو مفيش وردية مفتوحة** — بيتخطى الـUPDATE على shifts وبيحرّر الطيار من الفرع بس. سلوك الأصل بالحرف (if ($shiftId !== false))، منقول. و ended_by بياخد **النص الحرفي 'pilot'** مش اسم المستخدم.

- ⚠️ **فخ الاستثناءات في shiftEnd:** الأصل بيلف كل حاجة في catch(Throwable) بيحوّلها لـ«تعذّر إنهاء الوردية — جرّب تاني» 500، **لكن** board_lock_pilot بتنده fail() اللي بتعمل exit — يعني «الطيار غير موجود» 404 بتوصل للعميل زي ما هي من غير ما تتحوّل. عندنا ApiException بترمي فعلًا، فلو كتبت catch(Throwable) لوحدها كانت هتبلع الـ404 وتحوّلها 500 = تغيير سلوك. الحل المطبّق: catch (ApiException) { throw $e; } قبل catch (Throwable) — نفس نمط BoardController.

- ⚠️ **تكرار مقصود ومسجّل:** lockPilot و releasePilot و queueShiftAfterRemoval اتكتبوا تاني كدوال private في PilotAppController لأن نظائرهم في BoardController private ومفيش طريقة نناديهم بيها، و pilot_shift_end في الأصل بينده board_release_pilot مباشرةً. مالمستش BoardController عشان مايحصلش تصادم مع وكيل تاني بيكتب فيه. **لو الجُمل دي اتغيّرت في BoardController لازم تتغيّر هنا كمان** — المصدر الواحد هو api/routes/board.php سطور 113 و 146 و 171. التعليق فوق الدوال بيقول ده صراحةً. لو حبيت توحّدهم لاحقًا، الحل الأنضف تحويل التلاتة في BoardController لـ public من غير أي تغيير في المنطق.

- PilotAppController@leaveEnd بيدوّر على الإذن الساري بـ ORDER BY id DESC (مش requested_at) — زي الأصل. الترتيب مقصود: pilotCtx() الأول (403 «الحساب مش مربوط بطيار») ← الدوران (400 «مفيش إذن ساري عليك دلوقتي») ← وبعدين بس المعاملة. رفض الإيقاف الإجباري (leave_forced=1 أو forced_by مش فاضي → 403 «تم إيقافك من الإدارة/الفرع — مينفعش تنهي الإذن بنفسك») بيحصل **جوه** BoardController::leaveRequestEnd مش في الدالة دي — بالحرف زي الأصل. الدالة بتنده (new BoardController())->leaveRequestEnd($request, (string) $reqId) عشان نفس المعاملة ونفس الأقفال.

- POST /api/pilot/location **بجملة UPDATE واحدة من غير معاملة** — زي الأصل. التطبيق بيندهه من كل طيار شغّال كل ≤60 ثانية، فأي قفل أو معاملة هنا بتتضرب في العدد ده. التحقق isset($b['lat'],$b['lng']) معناه إن null بيترفض زي الغياب، و is_numeric بيقبل النص «30.1» زي الرقم — منقول بالحرف. نصوص الأخطاء: «ابعت lat و lng أرقام» و «إحداثيات غير صالحة» (الاتنين 400).

- **ترتيب التسجيل:** pilot/leave-requests/end مسار حرفي منفصل عن pilot/leave-requests (مقاطع مختلفة) فمفيش تعارض في راوتر لارافل، بس التزامًا بقاعدة الملف سجّله قبله. و pilot/shift/end مالوش أي تعارض. أنسب مكان: جوه المجموعة الموجودة Route::middleware('role:pilot')->group(...) في routes/api.php:120 — كل التسعة نفس الـmiddleware.

- **مفيش serializers جديدة اتضافت في app/Wire/** — كل الردود هنا إما {ok:true} أو {ok:true,id:N} أو الأوردر الكامل عبر OrderWire::full (موجود). ولا لمست routes/api.php ولا أي حاجة في D:\dahshaneg\aldahshan.

- C:\xampp\php\php.exe -l على PilotAppController.php = No syntax errors detected. الملف الوحيد اللي اتغيّر في الجولة دي.

- 🔴 لازم تعديل واحد خارج ملفاتي — عملته وموثّق: D:\dahshaneg\dahshan\app\Http\Middleware\ResolveApiActor.php. الفحص `isBlocked('customers', ...)` كان بيقتل جلسة العميل المحظور ويرمي 403 «هذا الحساب موقوف — تواصل مع الإدارة» على **كل** طلب api. بس في الأصل مسارات customer_app.php **مابتندهش require_auth() خالص** — بتنده customer_require() اللي: (أ) بتسيب المحظور يعدّي على مسارات القراءة عشان الواجهة تعرض شاشة «الحساب موقوف»، (ب) بترفضه على الكتابة برسالة تانية «حسابك موقوف مؤقتًا — كلّم خدمة العملاء» 403. من غير الاستثناء ده كان هيبقى فرق سلوك على الـ17 مسار كلهم. التعديل سطر واحد: `if (! $request->is('api/customer/*') && $this->isBlocked(...))` + تعليق يشرح ليه. مامسحتش حاجة.

- ⚠️ **ممنوع تضيف `role:customer`** على أي مسار من الـ17. الأصل مابيندهش require_role() — customer_require() بترجّع 401 «يجب تسجيل الدخول أولًا» لأي جلسة مش عميل (بما فيهم الموظف والطيار). middleware الدور كان هيرجّع 403 «غير مسموح لك بهذه العملية» بدلها.

- ملف سلك جديد: D:\dahshaneg\dahshan\app\Wire\CustomerAppWire.php. **مش نفس CustomerWire** ومامستهوش: السيريالايزرز مختلفة الشكل فعليًا — customer فيها uid/notifSeenAt و`?? ''` بدل null، address فيها zoneName/branchName من الـjoin، savedReceiver بتطلّع lat/lng (وCustomerWire صراحةً مابتطلعهمش). توحيدهم تغيير عقد على واجهتين.

- customer_verify_id_token اتنقلت بالحرف: 3 أجزاء، alg==='RS256' + kid موجود، جلب شهادات جوجل من securetoken@system.gserviceaccount.com بكاش ملف ساعة في sys_get_temp_dir() (نفس مسار الأصل عشان النظامين يشاركوا الكاش)، openssl_verify(...) === 1 بالظبط (مش `!` — الدالة بترجّع -1 عند الخطأ وهي قيمة صادقة)، exp بسماحية 60ث، iat مش أبعد من +300ث، aud === 'aldahshaneg-92f66'، iss === 'https://securetoken.google.com/aldahshaneg-92f66'، sub مش فاضي. وسقوط النداء بيرجّع آخر كاش حتى لو قديم قبل ما يرمي 503. المشروع اتساب ثابت في الكود (const) مش env — زي الأصل بالحرف.

- customer_incoming اتنقلت بتعليقاتها الكاملة (قفل الخصوصية 2026-08-19) + customer_incoming_mask + customer_incoming_throttle. السقف 15/ساعة من lookup_log بـactor_type='customer_onboarding' وactor_name='customer:{id}' وfull_access=0 وsearched_phone مقصوصة بـmb_substr(...,0,20). التسجيل بيتم **بعد** الاستعلام عشان found تبقى حقيقية — يعني البحث الفاشل بيتحسب من السقف كمان (مقصود).

- باج منقول زي ما هو في customer_incoming_mask: المفتاح `netDeliveryPrice` في قايمة التصفير لكنه **مش موجود أصلًا** في كائن الأوردر بتاع OrderWire (الأصل ser_orders.php مابيطلعوش كمان — العمود net_delivery_price بيتكتب في orders_deliver وبس). array_key_exists بيتخطاه بصمت. سيبته زي ما هو.

- customer_order_cancel: رد رصيد المحفظة اتنقل بالحرف بما فيه التعليق بتاع الإصلاح (2026-08-19). round((float)wallet_used, 2) قبل المقارنة > 0، والمحفظة بتتجاب من **صف الحركة** (`SELECT wallet_id FROM wallet_transactions WHERE order_num=? AND type='use' ORDER BY id DESC LIMIT 1`) مش من محفظة العميل المفترضة — لأن apply-wallet بيخصم من محفظة العميل أو المحل حسب مين نداه. القفل `SELECT * FROM wallets WHERE id=? FOR UPDATE`. fallback على finance_lock_wallet('customer', cid) للبيانات المرحّلة. round(balance + used, 2). ونوع الحركة 'credit' بنص 'رجوع رصيد بعد إلغاء الطلب' وcreated_by='عميل'، وبعدها `UPDATE orders SET wallet_used = 0`.

- شذوذ في العقد منقول زي ما هو: **كل** أخطاء POST /customer/orders/{id}/cancel بترجع **409** — حتى «الطلب غير موجود» و«الطلب ده مش تابع لحسابك». السبب في الأصل إن الكتلة كلها بترمي RuntimeException والـcatch بينده fail($e->getMessage(), 409). قارن بـcustomer_owned_order (المستعملة في track وrating) اللي بترجّع 404 و403 لنفس الحالتين. متوحّدش.

- شذوذ تاني منقول: GET /customer/addresses و GET /customer/receivers بيرجعوا `{ok, changed, items}` **من غير serverNow** — مخالف لغلاف الاستطلاع الموحد، فمااستخدمتش PollableList معاهم (نفس شذوذ قوايم support.php المتسجّل في PORT.md). GET /customer/notifications بيرجّع serverNow لكن بمفاتيح زيادة بعد items (seenAt, unread) فبرضه ApiResponse::out مباشرة.

- customer_receivers_upsert: الـupsert معتمد على خطأ 1062 من الفهرس الفريد (customer_id, phone) — **مش** SELECT ثم INSERT، والفهرس هو اللي بيحسم السباق. مالفّيتهاش في معاملة لأن جملة كتابة واحدة بس بتنفّذ فعليًا في كل الحالات (إدخال أو تحديث) — زي الأصل. اتحققت إن Illuminate\Database\QueryException بينسخ errorInfo من الـPDOException الأصلية (QueryException.php:70) فـ`$e->errorInfo[1] !== 1062` شغّال.

- شذوذ منقول في receivers_upsert: `zoneId` لزون مش موجود **بيتجاهل بصمت** (بيبقى null) مابيرجعش خطأ — على عكس customer_addr_zone اللي بترمي «المنطقة المختارة غير موجودة».

- customer_order_create: قراية العدّاد فيها `FOR UPDATE` (`SELECT counter FROM order_counters WHERE branch_id=? AND day_key=? FOR UPDATE`) — **مختلفة عن OrdersController::store** اللي بيقرا من غير FOR UPDATE. نقلتها بالحرف. ومجموع الفلوس (totalDeliveryPrice/storePrepaid) من غير round() زي الأصل. وسعر الزون **إجباري من جدول zones** — مفيش قبول لـzonePrice من الجسم زي مسار الموظفين (العميل مايسعّرش أوردره).

- الجسم بيتقرا بـ`$request->json()->all()` (المقابل الحرفي لـbody_json()) مش `$request->input()` — لأن customer_addresses_update بتستخدم array_key_exists('zoneId'|'isDefault'|'lat'|'lng', $b) عشان تفرّق بين «مش متبعت» و«متبعت فاضي». نفس نمط FinanceController::body().

- أرقام التليفون: customer_phone_variants بتولّد 4 صيغ لكل رقم (الخام/الأرقام بس/بلا صفر بادئ/بصفر بادئ) وبترفض أي رقم أقل من 8 أرقام. المطابقة على كل الصيغ مش على صيغة موحّدة — لأن الأعمدة فيها بيانات تاريخية بصيغ مختلفة. اتنقلت بالحرف بما فيها إن مفاتيح المصفوفة الرقمية بتتحوّل لـint في array_keys/array_flip (نفس سلوك PHP في الأصل).

- customer_notifications مافيهوش جدول إشعارات — القايمة مشتقّة من طوابع الأوردر، والفرز تنازلي بـstrcmp على الطابع ISO (صالح لأن الصيغة ثابتة الطول وUTC). حد 120 أوردر ثم 60 إشعار. مفتاح 'assigned' بياخد current_pilot_since ?: status_since.

- `php -l` نضيف على التلات ملفات، و`php artisan route:list --path=api` لسه بيشتغل (104 مسار حاليًا قبل تسجيل مساراتي). ما اتعملش أي حاجة في D:\dahshaneg\aldahshan ولا في routes/api.php ولا في السكيمة.

- لسه محتاج (خارج نطاقي): tests/seed_customer_session.php بتاع النظام القديم بيفتح جلسة PHP أصلية ويسلّم الـsession id لـcurl — مش هيشتغل على لارافل (تخزين/توقيع جلسة مختلفين). محتاج مكافئ عشان بوابة التطابق تقدر تفحص الـ17 مسار دول فعليًا (متسجّل أصلًا في PORT.md تحت «معروف ومفتوح»).

- الملفات: D:\dahshaneg\dahshan\app\Http\Controllers\Api\DamascusController.php (كنترولر، 29 ميثود عام = 29 مسار) + D:\dahshaneg\dahshan\app\Wire\DamascusWire.php (كلاس ساكن جديد فيه كل الحسابات المجمّدة). الاتنين عدّوا php -l. مفيش ملف قديم اتمسّ.

- التقسيم: DamascusWire = حساب خالص على مصفوفات (num/round2/has، الوقت والساعات، الأسعار والخدمة، branchDayCloseout، dayAllBranches، deferredForMonth، pilotMonthTotals، السلك). الكنترولر = تحميل السياق من القاعدة + الصلاحيات + الاستجابة. سبب التقسيم إن الدوال المجمّدة تفضل في مكان واحد يتقارن بالأصل مباشرة.

- 🔴 السنتينل ‎−1‎ في devFeeBranch() اتنقل حرفيًا مع تعليق الأصل كامل + سطر زيادة إنه اتحط بعد باج فلوس حقيقي وممنوع يتشال. اتفحص على القيم: '' و'0' ورقم فرع صحيح ومفتاح فايربيز موجود (-KBBB) ومفتاح مش لاقي فرع (-KZZZ) ونص عربي — كلها متطابقة مع الأصل في التقفيلة اليومية وفي dayAllBranches.

- الفحص التفاضلي اللي اتعمل (كله على PHP 8.2.12 وقاعدة aldahshan الحقيقية):
  • 538 مقارنة على الحساب الخالص (الدوال الأصلية محمّلة جنب DamascusWire في نفس العملية) — صفر اختلاف.
  • 485 مقارنة على بيانات مزروعة في القاعدة باتصال PDO **مشترك** بين النظامين (rd_ctx كامل + التقفيلة لكل فرع/يوم + pilotMonthTotals + permsRow + entryRowId/entryCleanup) — صفر اختلاف.
  • 168 مقارنة على **نص الرد الخام** لكل مسارات القراءة (12 مسار × حالات شهر/يوم/فرع/طيار صالحة وغلط × 3 ملفات مستخدم: أدمن، مشرف بصلاحيات جزئية وفرع واحد، مستخدم بلا صلاحيات) بنفس أعلام الترميز ومع كود الحالة — صفر اختلاف.
  • 1098 مقارنة على مسارات الكتابة (الرد + **صورة كل جداول rd بعد الكتابة**) × 3 مستخدمين — اختلاف واحد بس، موصوف تحت.
  كل الاختبارات اتعملت جوه معاملة اترجّعت في الآخر — القاعدة رجعت صفر صفوف في كل جداول rd_ (اتأكدت بعد كل تشغيلة).

- ⚠️ **الاختلاف الوحيد** (مقصود، بند 2 في القواعد): في PUT /api/rd/entries لما الـUPDATE نفسه يفشل على مستوى القاعدة (مثال متحقق: field=h و value='1630' — العمود hours هو DECIMAL(5,2) فبيطلع 1264 out of range). الأصل مافيهوش معاملة، فصف rd_entries الفاضي اللي rd_entry_row_id() عمله قبل الـUPDATE **بيفضل يتيم في الجدول**؛ عندنا DB::transaction بترجّعه. **الرد متطابق حرف بحرف** في الحالتين ({ok:false, error:'خطأ في قاعدة البيانات'} 500 — لأن معالج لارافل بيحوّل QueryException زي index.php بالظبط). يعني الفرق في أثر جانبي على مسار الخطأ بس، ولصالحنا (مفيش صفوف يتيمة).

- ⚠️ فرق تاني من نفس النوع (ما اتحققش عمليًا لأنه محتاج فشل في النص): PUT /api/rd/settings. الأصل بيكتب مفتاح مفتاح بلا معاملة، فلو devFeeBranchId طلع فرع مش موجود المفاتيح اللي قبله في الجسم بتفضل متكتبة. عندنا الحلقة كلها جوه DB::transaction فالكل بيترجّع. مسار النجاح متطابق (متحقق في اختبار الكتابة). التعليق ده مكتوب فوق الميثود في الكود.

- كل مسارات rd **من غير middleware دور** عن قصد — الأصل بينده rd_user() اللي بيقبل أي حساب users (بما فيهم branch/callcenter/hr/accountant/pilot) ويرفض عميل التطبيق بـ403 «غير مسموح لك بهذه العملية»، والتقييد الحقيقي بيتم بمفاتيح rd_perms جوه الكنترولر. تحويلها لـ role:admin أو غيره كان هيغيّر السلوك على كل الـ29 مسار.

- ترتيب التسجيل المطلوب: PUT rd/deferred/{id}/payment **قبل** PUT rd/deferred/{id} (أشكال مختلفة بس التزامًا بقاعدة الحرفي-قبل-الباراميتر)، وrd/branches و rd/pilots و rd/settings و rd/perms الحرفية قبل أي {id} على نفس الجذر. rd/closeout/day و rd/closeout/month حرفيين مفيش تعارض.

- شذوذات في العقد اتنقلت زي ما هي (مش اتصلحت):
  • كل مسارات rd بترجع serverNow جوه الرد مباشرةً مش عبر غلاف PollableList، ومفيش changed خالص — فمستخدمناش PollableList معاها.
  • GET /api/rd/month-locks و POST/DELETE month-lock بيرجعوا {ok, month, items/branchId, locked} من غير serverNow في الاتنين الأخيرين.
  • PUT /api/rd/deferred/{id}/payment بينده rd_require_unlocked($ym, null) — يعني **قفل الفرع مابيمنعش** تعديل قسط السلفة، القفل العام بس هو اللي بيمنع. متحقق باختبار (فرع مقفول + شهر مفتوح = بيعدّي).
  • rd_summaries_save و rd_entries_save بيحطوا اسم العمود في نص الـSQL من whitelist (مفاتيح summaryFields/entryFields هي نفسها أسماء الأعمدة) — منقول زي ما هو وآمن لأن الفلترة قبله.
  • قيم الإعدادات اللي من القاعدة بترجع **نصوص** واللي افتراضية بترجع **أرقام** في نفس الرد ({"hourRate":"30"} مقابل {"hourRate":30}) — فرق نوع حقيقي على السلك منقول بالحرف.
  • allowedBranchIds: لستة فروع فاضية في rd_perms = **كل الفروع** مش ولا فرع.
  • rd_perms_list بيرجّع صفوف users خام (id, username, name, role) لأي أدمن.

- تفاصيل تقنية اتفحصت عن قصد: db()->query() القديمة (بروتوكول نصي) و DB::select (بروتوكول ثنائي، EMULATE_PREPARES=0) بيرجّعوا **نفس الأنواع** على PHP 8.2 — اتقاس عمليًا على users و rd_branches قبل النقل، فمفيش فرق في نوع id أو active على السلك.

- كاش الصلاحيات: rd_perms_row القديمة كانت static $cache جوه الدالة. عندنا بقى خاصية على الكنترولر ($permsCache) — نفس عمر الطلب الواحد بالظبط، بس ماتعيشش بين الطلبات لو السيرفر بقى طويل العمر (Octane/Swoole).

- rd_ctx متنادية من غير كاش عن قصد: مسارات الحفظ بتنده ctx() تاني بعد الكتابة عشان ترجّع التقفيلة المحدّثة في نفس الرد. كاش هنا كان هيرجّع أرقام قديمة.

- مفيش SELECT ... FOR UPDATE في damascus.php الأصلي خالص، فماضفتش أي أقفال صفوف جديدة — إضافتها كانت هتغيّر سلوك التزامن.

- cal_days_in_month و mb_strpos مستعملين حرفيًا زي الأصل — الاتنين متأكد إنهم موجودين في C:\xampp\php\php.exe (اتفحصوا).

- الملفات المعدّلة (كلها إضافة فوق الموجود، مفيش مسح): D:\dahshaneg\dahshan\app\Http\Controllers\Api\TrustController.php · SupportController.php · PublicSiteController.php · AuthController.php · CustomersController.php · CustomerAppController.php (تعديل واحد: توسيع رؤية verifyIdToken). مفيش أي ملف جديد في app\Wire — الـserializers المطلوبة (SupportWire::complaint/zoneRequest/partner · CustomerWire::customer · TrustWire::rating/identityInfo/reputation · OrderWire::full) كانت موجودة كلها.

- php -l نضيف على الست ملفات، وكل الكلاسات بتتحمّل عبر الـautoloader (اتفحصت بـReflectionClass) والدوال العامة كلها ظاهرة.

- ترتيب التسجيل: مسارات orders/{id}/rating و rate-receiver و apply-wallet لازم تتسجّل **بعد** orders/assign-bulk و orders/settle-money-bulk الحرفيين الموجودين. مفيش تعارض فعلي (اللاحقة مختلفة) بس القاعدة مطبّقة. و PUT /api/settings مالوش تعارض مع GET settings/site (فعل مختلف).

- 🐞 باج اتصلح في الأصل واتنقل معاه: trust_req_stars بترفض أي حاجة مش رقم صحيح 1-5 (كانت 3.5 بتتقبل وتتحوّل صامتة لـ3، و«[5]» بتبقى 1). التعليق الأصلي منقول بالكامل فوق TrustController::reqStars.

- 🔴 تفاوت في الأصل منقول زي ما هو: support_order_rating (POST /api/orders/{id}/rating) **لسه على `(int)($b['stars'] ?? 0)` القديمة** — يعني 3.5 بتتقبل وتتحوّل لـ3 على المسار ده بالذات، بينما trust_rate_receiver و trust_rate_direct بيرفضوها. مسارين مختلفين لنفس المفهوم بسلوكين مختلفين — الإصلاح قرار منفصل.

- ⚠️ **فرق سلوك واحد مقصود** (PUT /api/settings): حلقة الـupsert اتحطّت جوه DB::transaction تنفيذًا لقاعدة المعاملات. الأصل كان autocommit، فمفتاح غلط في نص القايمة (فاضي أو أطول من 60 حرف) كان بيسيب المفاتيح اللي قبله **متكتوبة** ويرد 400. عندنا بيترجعوا. نص الخطأ («مفتاح إعداد غير صالح: ' . $key») وكود الحالة مطابقين حرفيًا — الفرق في الأثر الجانبي بس. لو المطلوب تطابق حرفي كامل بما فيه الكتابة الجزئية، اشيل الـtransaction.

- ⚠️ **فرق على مستوى البنية التحتية** (POST /api/orders/{id}/rating للعميل المحظور): الأصل مابيندهش require_auth() على المسار ده خالص — بيقرا $_SESSION بإيده وبينده customer_require() اللي بترفض المحظور برسالة «حسابك موقوف مؤقتًا — كلّم خدمة العملاء» 403. على لارافل ResolveApiActor بيقتل جلسة العميل المحظور **قبل** الكنترولر برسالة «هذا الحساب موقوف — تواصل مع الإدارة» 403 (المسار مش api/customer/*). فالرسالة مختلفة للمحظور. سيبت customerRequire() في الكنترولر حرفية رغم إنها غير قابلة للوصول عمليًا. الإصلاح يتطلب توسيع استثناء ResolveApiActor ليشمل POST orders/{id}/rating — قرار خارج نطاق الوكيل ده.

- ⚠️ فرق edge-case في rated_by: الأصل `$user['name'] ?? $user['username']` (بيسقط للـusername لو name = **null**). Actor بيعمل `(string) $session->get('name','')` فالـnull بيتحوّل ''. استخدمت `$actor->name` مباشرة، فحساب users.name = NULL هيخزّن '' بدل الـusername. في مسارات الثقة الأصل بيستخدم `?: ` (بيمسك null و'' الاتنين) فاستخدمت `$actor->name !== '' ? $actor->name : $actor->username` = مطابق تمامًا.

- 🔴 apply-wallet — تفاصيل الفلوس منقولة بالحرف: ترتيب الأقفال **صف الأوردر FOR UPDATE ثم lockWallet() FOR UPDATE** (عكسه = deadlock). `$use = min($balance, $price)` **من غير round()**، و`$newBalance = round($balance - $use, 2)` (تقريب على الناتج بس)، و`wallet_used` بيتخزّن `$use` غير مقرّب والعمود DECIMAL هو اللي بيقرّب. السجل بياخد `-$use` بالإشارة و`type='use'`. `walletUsed` في الرد = القيمة غير المقرّبة. ترتيب الكتابات: wallets ← wallet_transactions ← orders.

- lockWallet() اتكرّرت في SupportController بدل ما أعدّل FinanceController (وكلاء تانيين كاتبين فيه، والنسخة هناك private). الأصل نفسه دالة عامة مشتركة finance_lock_wallet() بينده عليها finance.php و support.php و customer_app.php. النسختين متطابقتين حرفيًا. لو حبيت توحّدهم بعدين، انقلها لـApp\Support أو App\Wire\FinanceWire.

- توسيع رؤية CustomerAppController::verifyIdToken من private لـpublic (static) — إضافة بحتة بلا تغيير سلوك، عشان AuthController::adminGoogleLogin ينده عليها زي ما الأصل بينده customer_verify_id_token() من auth.php. **متعملش نسخة تانية منها** — ده باب الدخول الوحيد وفحص أمان في مكانين معناه تحديث ناقص.

- google-login — bootstrap أول مدير منقول زي ما هو: لو admin_emails **فاضي تمامًا**، أول إيميل يوصل للمسار بيتسجّل ويعدّي. يعني على نشر جديد أول واحد يوصل للدومين بياخد الإدارة. مش باج، بس خطر تشغيلي لازم يتعمل قبل ما الدومين يبقى عام.

- google-login — الرد **مالوش مفتاح branch_id** في كائن الـuser (على عكس /api/login)، وفيه email و allowedApps بدلها. و`via='google'` بيتخزّن في الجلسة بس ومابيطلعش على السلك. الأصل كان بيعمل `$adminUser['username'] ?? $email` على `false` (تحذير PHP بس) — اتكتبت صريحة بنفس الناتج.

- 🐞 باج منقول (PUT /api/partners/{id}): `name` بيتقرا `?? $row['name']` مش `array_key_exists` — يعني `{"name":""}` بيمسح اسم الشريك لسلسلة فاضية من غير رفض (على عكس POST اللي بيرفض الاسم الفاضي)، و`{"name":null}` بيرجّع الاسم القديم.

- 🐞 باج منقول (PUT /api/customers/{id}): **مش تعديل جزئي** — الأربع أعمدة (display_name/phone1/phone2/address) بتتكتب كلها كل نداء، فالحقل المش مبعوت بيتمسح null. وفحص الوجود بيتم **بعد** الـUPDATE وبس لو 0 صف اتغيّر.

- 🐞 باج منقول (POST customers/{id}/block و /zone): الـUPDATE مابيتأكدش من وجود العميل — بيعدّي بصمت على id مش موجود، و404 بتيجي من fetch_wire بعدها. يعني كتابة فاضية ثم 404.

- 🐞 باج منقول (customers_fetch_wire): رد التعديل/الحظر/الزون بيرجّع `addresses: []` و`savedReceivers: []` دايمًا حتى لو العميل عنده عناوين — الأصل بينده customers_wire($row) بلا الوسيطين. اللوحة بتعيد تحميل اللستة بعدين.

- 🔴 DELETE /api/customers/{id} — الحذف **نهائي مش soft delete** زي ما اتطلب. علامة الوقت في site_settings (`customersDeletedAt` بالمللي ثانية) **مش soft delete** — هي علامة استطلاع: الصف بيختفي خالص فمفيش updated_at يشهد عليه، والتاب المفتوح على /api/customers?since كان يفضل عارض العميل المحذوف. ترتيب الجُمل جوه المعاملة محفوظ بالحرف، وأهمها `DELETE FROM notifications` **قبل** حذف العميل لأن الـFK عليها RESTRICT مش CASCADE. المسار بيلمس فلوس (بيحذف صف wallets وحركاته بالـCASCADE) فعلّمته touches_money=true.

- 🐞 منقول: POST /api/public/join-request **مسار كتابة مفتوح للدنيا بلا أي rate-limit** — أي حد على الإنترنت بيدخّل صفوف في pilot_join_requests. الحماية الوحيدة فحص طول الاسم (2-60 حرف بـmb_strlen) والتليفون (8-15 رقم بـstrlen). التفاوت في دالة العدّ مقصود ومنقول (الاسم عربي فبيتعدّ بالحروف، التليفون أرقام لاتينية فالبايت = الحرف).

- 🐞 منقول: POST /api/zone-requests/{id}/mark-added بيرجّع `{ok:true}` **بس** — من غير الصف المحدّث، على عكس complaints/{id}/resolve اللي بيرجّع الشكوى. تفاوت في الأصل.

- تفاوت الأدوار بين القراءة والكتابة منقول زي ما هو: complaints قراءة فيها **hr** والإنشاء لأ · complaints إنشاء فيه **branch** والحل لأ · zone-requests إنشاء فيه branch والـmark-added لأ. أي توحيد = تغيير سلوك.

- zone-requests create: الشرط `branch_id IS NULL` مقابل `branch_id = ?` متبني في **نص الـSQL** مش بباراميتر — لأن `NULL = ?` بترجّع NULL في SQL، فالمطابقة على المنطقة بلا فرع كانت هتفشل وتدخّل صف جديد كل مرة. و`COALESCE(?, last_price)` بيمنع المش مبعوت من مسح القيمة القديمة. القراءة النهائية بتتم **بعد** الـcommit زي الأصل.

- insertRating بيلتقط SQLSTATE 23000 ويحوّله لـ«أنت قيّمت الأوردر ده قبل كده» 409 (الفريد مبني على عمود محسوب rater_uid عشان NULL في MariaDB مايبطّلش القيد). أي خطأ قاعدة تاني بيتعاد رميه فيوصل للمعالج المركزي بـ«خطأ في قاعدة البيانات» 500 — زي `throw $e` في الأصل.

- PUT /api/trust/{phone}/identity: الأصل بيعمل urldecode على باراميتر المسار (فالـ`+` بيبقى مسافة) ولارافل لأ — الناتج **مطابق** لأن TrustWire::normalizePhone بيشيل غير الأرقام في الحالتين. نفس السلوك اللي مسار القراءة trust/{phone}/ratings عدّى بيه بوابة التطابق.