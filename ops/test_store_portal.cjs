/**
 * 🏪 اختبار بوابة المحلات — طيّ بلوك المرسل · زرار «بياناتي» · بوابة الموقع.
 *
 * ═══ اللسعة الأخطر اللي اتشالت هنا ═══
 * `saveOrder` بتحفظ ملف المحل تلقائيًا مع كل شحنة، وكانت بتبعت
 * `lat: null, lng: null` لو الشحنة من غير دبوس. والسيرفر موثّق إن
 * «أي واحد فيهم فاضي = مسح الاتنين» — يعني **كل شحنة عادية كانت بتمسح
 * موقع المحل المحفوظ**.
 *
 * من غير الإصلاح ده، بوابة «حدّد موقع محلك» كانت هتفضل تظهر للمحل بعد
 * كل شحنة، وهو مش فاهم ليه بيتسأل كل يوم على حاجة سجّلها.
 *
 * التشغيل: node ops/test_store_portal.cjs
 */
const fs = require('fs');

let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const S = fs.readFileSync('public/store.html', 'utf8');

/* 🔴 أي فحص بيدوّر على **كود** لازم يمشّط التعليقات الأول.
   وقعت في الفخ ده تلات مرات في نفس الملف: بكتب تعليق بيشرح الباج القديم
   وبيحط السطر القديم فيه حرفيًا، فالفحص يلاقيه في التعليق ويعدّي —
   والحارس يبان أخضر والكود مكسور. اتكشف كل مرة بتجربة كسر الحرّاس. */
const code = s => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
const SC = code(S);

console.log('\n══ 1) 🔴 الشحنة مابتمسحش موقع المحل ══');
/* النطاق: كتلتا حفظ ملف الاستلام بس — دفتر «عناويني» (2026-09-02) بيبعت
   lat: null عن قصد (openAddrModal بتحمّل الدبوس الحالي قبل الحفظ، فغيابه
   = اختيار المحل) والفحص العام كان بيمسكه بالغلط. */
{
  let profSlices = '';
  let at = -1;
  while ((at = S.indexOf('api.put("/api/store/pickup-profile"', at + 1)) !== -1) {
    profSlices += S.slice(Math.max(0, at - 900), at + 200);
  }
  ok('الحفظ التلقائي (ملف الاستلام) مابيبعتش lat/lng فاضيين',
     profSlices !== '' && ! /lat\s*:\s*pin \? pin\.lat : null/.test(profSlices));
}
ok('الدبوس بيتضاف للجسم بس لو موجود',
   /if \(pin\) \{ body\.lat = pin\.lat; body\.lng = pin\.lng; \}/.test(S));
/* المسار بيعدّل جزئيًا بـ`array_key_exists` — عدم إرسال المفتاح = ماتلمسوش */
ok('الجسم بيتبني بالمفاتيح الموجودة بس',
   /const body = \{ address: pickupAddress \|\| "", zoneId: pickupZone\.id \};/.test(S));

console.log('\n══ 2) زرار «بياناتي» بيملا كل حاجة ══');
const fnAt = S.indexOf('window.fillShopAsReceiver = function');
const fillFn = S.slice(fnAt, fnAt + 2400);
ok('الاسم والتليفونات', /rName-/.test(fillFn) && /rPhone-/.test(fillFn) && /rPhone2-/.test(fillFn));
ok('العنوان', /setAddressValue\(`rAddrWidget-/.test(fillFn));
/* المنطقة إجبارية ومنها بيتحسب السعر — كانت بتتساب فاضية فالزرار
   كان بيوفّر نص الشغل بس */
ok('🔴 المنطقة كمان', /rZone-/.test(fillFn), 'مش بيملا المنطقة');
ok('و`onZone` بتتنده عشان السعر يتحسب', /window\.onZone\(n\)/.test(fillFn));
ok('🔴 والدبوس', /_geoPins\[`r\$\{n\}`\]/.test(fillFn), 'مش بيحط الدبوس');
/* `_pickupProfile` هو الأحدث؛ `_currentUser` لقطة وقت الدخول */
ok('بيقرا من الملف المحفوظ الأول', /const prof = window\._pickupProfile \|\| \{\};/.test(fillFn));
ok('بيقول للمحل لو مفيش بيانات بدل ما يسكت',
   /لسه مافيش بيانات محفوظة للمحل/.test(fillFn));

console.log('\n══ 3) طيّ بلوك الاستلام ══');
ok('البلوك متلفوف في حاوية', S.includes('<div id="pickupDetails">'));
ok('وفيه ملخّص بديل', S.includes('id="pickupSummary"'));
ok('الدالة موجودة', /window\.togglePickupDetails = function/.test(S));
const sumAt = S.indexOf('window.refreshPickupSummary = function');
const sum = S.slice(sumAt, sumAt + 1800);
/* مافيش منطقة = `saveOrder` هترفض. طيّه ساعتها بيخبّي سبب الرفض */
ok('بيفتح إجباري لو مفيش منطقة', /if \(!hasZone \|\| elsewhere\)/.test(sum));
ok('وبيفتح إجباري مع «مكان تاني»', /elsewhere/.test(sum));
ok('ومابيقفلش على المحل وهو فاتحه', /if \(window\._pickupOpen\) return;/.test(sum));

console.log('\n══ 4) «مكان تاني» بيترجع بعد الإرسال ══');
/* كان بيفضل مشيّك، فالطلب اللي بعده بياخد نفس المُرسِل البديل */
const reset = S.slice(S.indexOf('function resetForm()'), S.indexOf('function resetForm()') + 1800);
ok('resetForm بتفكّ التشييك', /elsewhereEl\.checked = false/.test(reset));
ok('وبتمسح حقول المُرسِل البديل',
   /senderNameIn.*senderPhoneIn.*senderPhone2In/s.test(reset));
/* دبوس الاستلام والإعدادات لازم يفضلوا — مسحهم بيكتب NULL في القاعدة */
ok('ولسه بتحافظ على دبوس الاستلام والإعدادات',
   /KEEP_PINS = \["pickup", "profpin"\]/.test(S));

console.log('\n══ 5) بوابة الموقع — مرة واحدة ══');
ok('الشاشة موجودة', S.includes('id="storeGateOverlay"'));
const gateAt = S.indexOf('window.storeNeedsLocation = function');
/* الحد على بداية الدالة اللي بعدها مش على عدد حروف: `openStoreLocationGate`
   فيها `p.lat && p.lng` (بتحط الدبوس المحفوظ في الخريطة) وهي استعمال
   مشروع — شريحة بعدد حروف كانت بتبلعها وتفشّل الفحص غلط. */
const gate = S.slice(gateAt, S.indexOf('window.openStoreLocationGate = function', gateAt));
/* مفتاح localStorage بيتمسح مع الكاش وبيسأل من جديد على كل جهاز */
ok('«مرة واحدة» بتتقاس من السيرفر مش من المتصفح',
   ! /localStorage/.test(gate));
/* 🔴 اتغيّر 2026-08-31: البوابة كانت بتفحص `!(p.lat && p.lng) || !p.zoneId`
   بإيدها — قايمة أقصر من `storeProfileIncomplete`. النتيجة إن المحل كان
   بيعدّي البوابة وحسابه لسه ناقص اسم وهاتف، والباقي بانر بيتقفل ويتعدّى.
   دلوقتي **تعريف واحد** بيحكم الاتنين. */
ok('البوابة بتستعمل نفس تعريف «الملف ناقص»',
   /return storeProfileIncomplete\(\);/.test(gate),
   'بتكرّر الشروط بإيدها — هتفرق عن التعريف مع أول تعديل');
ok('ومفيش شروط مكرّرة جواها',
   ! /p\.lat && p\.lng/.test(gate), 'لسه بيفحص الدبوس بإيده');
ok('نداء فاشل مابيوقفش المحل', /if \(!p\) return false;/.test(gate));
ok('الإدارة اللي بتتفرّج بتتخطّاها', /if \(u\.viaAdmin\) return false;/.test(gate));

console.log('\n══ 6) البوابة في المكان الصح من التسلسل ══');
/* في `applySession` الملف لسه `null` — البوابة كانت هتظهر لكل واحد
   في كل دخول حتى لو موقعه محفوظ */
const ready = S.slice(S.indexOf('window._pickupProfileReady = api.get'),
                      S.indexOf('const reg = p => window._pollers.push(p);'));
ok('بتتنده جوه .then بتاعة الملف', /storeNeedsLocation\(\)\)/.test(ready));
/* 🔴 اتغيّر عن قصد: البوابة بقت **مؤجّلة** ورا شاشة الترحيب.

   السبب: نداء الملف async وممكن يرجع في نص ثانية والترحيب لسه شغّال —
   فالبوابة كانت هتتفتح تحته (z-index 8500 مقابل 9500) والمحل يشوف
   شاشة متجمّدة مايعرفش يعمل فيها حاجة. `afterWelcome` بتنفّذ فورًا لو
   الترحيب مش شغّال، وبتأجّل لو شغّال. */
ok('البوابة مؤجّلة ورا شاشة الترحيب',
   /window\.afterWelcome\(window\.openStoreLocationGate\)/.test(ready),
   'مش مؤجّلة — هتتفتح تحت شاشة الترحيب');
/* لسه نقطة دخول واحدة — لو حد ضاف نداء تاني في applySession أو
   navigateTo، العدد بيزيد والفحص بيقع. */
/* نقاط الفتح **الفعلية** بس — بنستبعد الإشارات اللي جوه التعليقات، وإلا
   الفحص بيقع كل ما حد يشرح البوابة في تعليق (حصل مرتين).
   التلاتة: التعريف · نداء الدخول · نداء `saveOrder`. */
const gateCalls = (SC.match(/openStoreLocationGate/g) || []).length;
ok('نقاط فتح البوابة تلاتة بس', gateCalls === 3,
   String(gateCalls) + ' (المتوقع: التعريف + الدخول + saveOrder)');
/* الحد على نهاية الكتلة مش على عدد حروف — التعليقات بتكبر والشريحة الثابتة
   بتقصّر عن الكود اللي بتفحصه فتقع غلط. (حصل مرتين في نفس الملف.) */
const soAt = S.indexOf('const _elsewhere = document.getElementById("pickupElsewhere")');
const so   = S.slice(soAt, S.indexOf('const pickupZone = window._pickupZone;', soAt));
ok('saveOrder بتوقف لو الملف ناقص والمحل هو المُرسِل',
   /if \(!_elsewhere && !u\.viaAdmin && window\._pickupProfile && storeProfileIncomplete\(\)\)/.test(so),
   'الطلب هيروح للسيرفر باسم فاضي ويرجع رسالة مالهاش علاقة');
ok('وبتفتح البوابة بدل ما تسيبه يدوّر', /window\.openStoreLocationGate\(\);/.test(so));

console.log('\n══ 7) 🔴 الحفظ بينسخ الدبوس للمفاتيح التلاتة ══');
/* `saveOrder` بتحفظ من `_geoPins.pickup` و`saveStoreSettings` من
   `_geoPins.profpin`. لو البوابة حفظت في `gatepin` بس، أول شحنة أو أول
   حفظ إعدادات كان هيكتب فوق اللي لسه اتحفظ. */
const save = S.slice(S.indexOf('window.saveStoreLocationGate'),
                     S.indexOf('window.saveStoreLocationGate') + 2600);
ok('بينسخ لـpickup',  /_geoPins\.pickup\s*=\s*\{ lat: pin\.lat, lng: pin\.lng \}/.test(save));
ok('وبينسخ لـprofpin', /_geoPins\.profpin\s*=\s*\{ lat: pin\.lat, lng: pin\.lng \}/.test(save));
ok('التلاتة إجباريين قبل الحفظ',
   /if \(!zone\)/.test(save) && /if \(!addr\.trim\(\)\)/.test(save) && /if \(!pin\)/.test(save));

console.log('\n══ 8) تعريف واحد لـ«الملف مكتمل» ══');
/* لو الاتنين اختلفوا: المحل يشوف «مفيش ناقص» والبوابة تفتحله — أو العكس */
ok('storeProfileIncomplete بتفحص الموقع',
   /return !p \|\| !p\.shopName \|\| !p\.zoneId \|\| !p\.address \|\| !p\.shopPhone \|\| !\(p\.lat && p\.lng\);/.test(S));
/* 🔴 الاسم بيتبعت كـ`senderName` والسيرفر بيرفض الأوردر من غيره
   (`OrdersController::store`). كان بره التعريف، فالمحل اللي الإدارة
   فتحت له حساب بلا اسم كان بيعدّي كل الحرّاس وبعدين أول شحنة ترجع
   «يرجى اختيار أو إدخال العميل استلام» وهو مش فاهم ليه. */
ok('واسم المحل جوّه التعريف', /!p\.shopName/.test(S), 'الاسم بره — الشحنة هتقع على السيرفر');
ok('وقايمة الناقص بتذكره كمان', /missing\.push\("اسم المحل"\)/.test(S));
ok('وقايمة الناقص بتذكره كمان',
   /missing\.push\("موقع المحل على الخريطة"\)/.test(S));

console.log('\n══ 8.5) 🆕 هوية المُرسِل — المحل بيكمّلها بنفسه ══');
/* الشركة بتفتح الحساب والمحل بيكمّل بياناته. الاسم كان الحقل الوحيد في
   هوية المُرسِل اللي الإدارة بس تقدر تكتبه — والسيرفر مكانش بيقبله أصلًا
   على `PUT /api/store/pickup-profile`. */
const PHP = fs.readFileSync('app/Http/Controllers/Api/CustomersController.php', 'utf8');
const saveAt = PHP.indexOf('public function pickupProfileSave');
const saveFn = PHP.slice(saveAt, PHP.indexOf('public function ', saveAt + 40));
ok('السيرفر بيقبل shopName', /array_key_exists\('shopName', \$b\)/.test(saveFn),
   'المحل مش هيقدر يسجّل اسمه خالص');
ok('وبيكتبه في العمود الصح', /\$sets\[\] = 'shop_name = \?';/.test(saveFn));
/* الأوردر بيرفض من غير senderName، فمسح الاسم كان هيقفل الشحنات على
   المحل برسالة مالهاش علاقة. الباقي بيتمسح — ده لأ. */
ok('🔴 الاسم الفاضي بيترفض مش بيتمسح',
   /mb_strlen\(\$name\) < 2/.test(saveFn) && /throw new ApiException/.test(saveFn),
   'اسم فاضي هيتكتب NULL والشحنات هتقف');
ok('وبيتقص على طول العمود (190)', /mb_substr\(\$name, 0, 190\)/.test(saveFn));

/* البوابة لازم تجمع هوية المُرسِل كلها — مش الموقع بس */
const gOpen = S.slice(S.indexOf('window.saveStoreLocationGate'),
                      S.indexOf('window.saveStoreLocationGate') + 3000);
ok('البوابة بتطلب الاسم',   /gateShopName/.test(gOpen));
ok('وبتطلب الهاتف',        /gatePhone/.test(gOpen));
ok('وبتبعت الاسم للسيرفر', /shopName: name/.test(gOpen));
ok('وبتتحقق من الاسم قبل الحفظ', /name\.length < 2/.test(gOpen));
ok('ومن الهاتف كمان',            /ph1\.length < 8/.test(gOpen));
/* الحقول الجديدة لازم تكون في الـHTML مش في الجافاسكريبت بس */
['gateShopName', 'gatePhone', 'gatePhone2'].forEach(id =>
  ok('حقل ' + id + ' موجود في الشاشة', S.includes('id="' + id + '"')));

/* اللقطة لازم تتحدّث مع الحفظ — الشحنة بتقرا منها */
ok('البوابة بتحدّث لقطة الدخول', /cu\.shopName = name;/.test(gOpen),
   'أول شحنة بعد البوابة هتتبعت بالاسم القديم');
ok('وبتحفظها في الجلسة', /saveSession\(cu\)/.test(gOpen));

/* الشحنة: الملف المحفوظ يغلب لقطة الدخول — نفس قاعدة `fillShopAsReceiver` */
ok('🔴 الشحنة بتقرا الاسم من الملف المحفوظ الأول',
   /name\s*:\s*_prof\.shopName\s*\|\|\s*u\.shopName/.test(so),
   'بتقرا من لقطة الدخول — أي تعديل مايوصلش غير بعد خروج ودخول');
ok('والهاتف كمان', /phone\s*:\s*_prof\.shopPhone\s*\|\|\s*u\.shopPhone/.test(so));

/* الاسم بيتقفل بعد التسجيل — بيتبصم على الشحنات القديمة.
   🔴 الفحص على **جسم** الدالة مش على وجودها: دالة فاضية بنفس الاسم كانت
   بتعدّي، والقفل مايشتغلش. (طلعت في تجربة كسر الحرّاس.) */
const lockAt = S.indexOf('function applyShopNameLock() {');
const lockFn = S.slice(lockAt, S.indexOf('\n    }', lockAt));
ok('فيه قفل لاسم المحل بعد التسجيل', lockAt > 0);
ok('والقفل بيتحسب من الاسم المحفوظ',
   /const locked = !!p\.shopName;/.test(lockFn),
   'الدالة موجودة بس مش بتحسب حاجة');
ok('وبيقفل الحقل فعلًا', /nm\.readOnly = locked;/.test(lockFn),
   'مش بيلمس readOnly — القفل شكلي');
ok('والقفل بيتنده من فتح التعديل',
   /applyShopNameLock\(\);/.test(S.slice(S.indexOf('window.setEditMode = function'),
                                        S.indexOf('window.saveStoreSettings = function'))));
ok('والحفظ مابيبعتش الاسم وهو مقفول',
   /nmEl && !nmEl\.readOnly \? \{ shopName: name \}/.test(S),
   'هيكتب على عمود مبصوم على شحنات قديمة كل مرة');

console.log('\n══ 8.6) 🔴 دخول الإدارة (viaAdmin) — الملف مش مقروء أصلًا ══');
/* الدرس اللي اتعلمناه بالطريقة الصعبة 2026-08-31:
   `GET /api/store/pickup-profile` مقفول على `role:store`، وجلسة الإدارة
   الداخلة على محل هي جلسة **أدمن** — فالمسار بيرجّع **403 على القراءة كمان
   مش الكتابة بس**، و`_pickupProfile` بيفضل `null` مهما كان ملف المحل كامل.

   يعني `storeProfileIncomplete()` بترجّع `true` **دايمًا** في الوضع ده.
   أي حارس جديد بيتبني عليها لازم يستثني `viaAdmin` صراحةً، وإلا بيمنع
   الإدارة من شغلها وهو فاكر إنه بيحمي بيانات ناقصة.
   (حصل فعلًا: حارس `saveOrder` منع الإدارة تعمل أوردر نيابة عن أي محل.) */
ok('🔴 حارس saveOrder بيستثني الإدارة', /!_elsewhere && !u\.viaAdmin &&/.test(S),
   'الإدارة مش هتقدر تعمل أوردر نيابة عن أي محل');
/* الفورم بيرجّع 403 دايمًا في الوضع ده — نفس قاعدة زرار التقييم */
const emAt = S.indexOf('window.setEditMode = function');
const em   = S.slice(emAt, emAt + 1300);
ok('التعديل مقفول على الإدارة', /viaAdmin\) \{\s*\n\s*toast\(/.test(em),
   'الفورم هيتفتح والحفظ هيرجّع 403 من غير سبب مفهوم');
ok('وبيقول السبب مش بيسكت', /لوحة المحلات/.test(em));
/* أزرار «تعديل» في العرض بتودّي لنفس الفورم المرفوض */
const svAt = S.indexOf('function renderSettingsView()');
const sv   = S.slice(svAt, S.indexOf('\n    }', svAt));
ok('وأزرار «تعديل» بتتشال في وضع الإدارة',
   /#setView \.sa \.link-btn/.test(sv) && /viaAdmin \? "none" : ""/.test(sv));
ok('وفيه ملاحظة بتشرح للأدمن', S.includes('id="setAdminNote"'));
/* «ناقصك إيه» كان بيقول معلومة غلط: الملف مش مقروء مش ناقص */
const todoAt = S.indexOf('function renderProfileTodo()');
ok('شريط «ناقصك» مابيظهرش للإدارة',
   /viaAdmin\) \{ setHtml\(box, ""\); return; \}/.test(S.slice(todoAt, todoAt + 700)),
   'بيقول «ناقصك كل حاجة» عن محل ملفه ممكن يكون كامل');
const hintAt = S.indexOf('window.renderStoreSetupHint = function');
ok('وتلميحة الشحنة كمان',
   /viaAdmin\) \{ box\.style\.display = "none"; return; \}/.test(S.slice(hintAt, hintAt + 600)));

console.log('\n══ 8.7) 🔴 باجات مسكتها المراجعة العدائية بعد الرفع ══');
/* ١) الحارس كان بيحبس محل ملفه كامل لو نداء الملف فشل.
   `storeProfileIncomplete()` أول شرط فيها `!p` («ماوصلش» = «ناقص»)،
   بينما `storeNeedsLocation()` جنبها بتقول العكس بالنص. والنداء بيتعمل
   مرة واحدة عند الدخول بلا إعادة محاولة. */
ok('🔴 حارس saveOrder مابيشتغلش والملف مش محمّل',
   /!_elsewhere && !u\.viaAdmin && window\._pickupProfile && storeProfileIncomplete\(\)/.test(S),
   'تهزهزة نت = بوابة فاضية بتتفتح على محل ملفه كامل وشغله يضيع');

/* ٢) القفل كان بيتقاس من لقطة الدخول اللي `shopName` فيها **مستحيل**
   تكون فاضية (بتقع على `u.name` وبعدين `username`) — فالقفل مرفوع من أول
   ثانية والمحل عمره ما يكتب اسمه، والبوابة بتبصم اسم المستخدم كاسم مُرسِل. */
ok('🔴 قفل الاسم من الملف المحفوظ بس', /const locked = !!p\.shopName;/.test(lockFn),
   'بيقرا لقطة الدخول — القفل هيبقى مرفوع دايمًا والميزة ميتة');
ok('ومفيش u.shopName جوّه القفل', ! /u\.shopName/.test(lockFn));
const gOpen2 = S.slice(S.indexOf('window.openStoreLocationGate = function'),
                       S.indexOf('window.saveStoreLocationGate'));
ok('والبوابة كمان بتقرا المحفوظ بس', /const saved = p\.shopName \|\| "";/.test(gOpen2),
   'البوابة هتعرض اسم المستخدم مقفول وتحفظه كاسم مُرسِل دائم');

/* ٣) قايمة مناطق البوابة كانت بتتملى مرة واحدة وقت الفتح بس */
const zp = code(S.slice(S.indexOf('reg(new api.Poller("/api/zones"'),
                        S.indexOf('reg(new api.Poller("/api/zones"') + 2400));
ok('🔴 مستطلع المناطق بيملا قايمة البوابة كمان',
   /fillPickupZones\("gateZone"\)/.test(zp),
   'مناطق اتأخرت = بوابة بمنطقة واحدة فاضية ومفيش زرار إغلاق');

/* ٤) حقول البوابة حقول DOM ثابتة — محل تاني على نفس التاب كان بيلاقيها متعبّية */
const sl = S.slice(S.indexOf('function startListeners(u)'),
                   S.indexOf('const reg = p => window._pollers.push(p);'));
ok('🔴 حقول البوابة بتتصفّر مع تغيير المحل',
   /\["gateShopName", "gatePhone", "gatePhone2"\]/.test(sl),
   'محل تاني هيلاقي هاتف وعنوان اللي قبله متعبّيين ويحفظ عليهم');
ok('وودجت عنوان البوابة بتتبني من جديد', /_gateWidgetsReady = false/.test(sl));

console.log('\n══ 9) القايمة واحدة مش اتنين ══');
/* قايمة مناطق مبنية بإيد كانت هتفرق عن دي مع أول تعديل على الفلتر */
ok('البوابة بتستعمل نفس fillPickupZones', /fillPickupZones\("gateZone"\)/.test(S));
ok('والدالة بقت بتاخد هدف', /function fillPickupZones\(targetId\)/.test(S));


console.log('\n══ 10) 🔴 الطيّ بيتحسب بعد ما المناطق توصل ══');
/* الفحوص فوق كلها كانت خضرا والميزة **مكسورة على الإنتاج**: كانوا
   بيتأكدوا إن الدوال موجودة، مش إنها بتتنده في الوقت الصح.

   الباج: قايمة المناطق بتوصل من مستطلع `/api/zones` بعد ما الفورم
   يترسم، والمنطقة المحفوظة بتتختار في اللحظة دي. وقت التهيئة القايمة
   لسه فاضية فالملخّص بيقرّر «مفيش منطقة ⟵ افتح»، وبعدين محدش بينده
   عليه تاني — فالبلوك بيفضل مفتوح للأبد.

   `applyPickupZone` هي نقطة العبور الوحيدة لكل تغيير في المنطقة. */
const apzAt = S.indexOf('function applyPickupZone(opts)');
const apz = S.slice(apzAt, S.indexOf('window.onPickupZone', apzAt));
ok('applyPickupZone بتنده الملخّص في آخرها',
   /window\.refreshPickupSummary\?\.\(\);/.test(apz), 'مش بتنده — الطيّ مش هيشتغل بعد تحميل المناطق');
/* المستطلع بينده `applyPickupZone` مباشرة — فالنداء من جوّاها بيغطّيه */
const poller = S.slice(S.indexOf('reg(new api.Poller("/api/zones"'), S.indexOf('reg(new api.Poller("/api/zones"') + 700);
ok('مستطلع المناطق بيعدّي على applyPickupZone',
   /applyPickupZone\(\{ keepSelection: true \}\)/.test(poller));

console.log('\n══ 11) شاشة الترحيب — مرة واحدة، ومابتحجبش البوابة ══');
/* «مرة واحدة عند فتح التطبيق» مش «مرة في العمر»: متغيّر في الذاكرة،
   مش localStorage. ولو اتحطت في navigateTo كانت هتظهر مع كل تنقّل. */
ok('بتتنده من applySession',
   /document\.getElementById\("app"\)\.style\.display\s+= "flex";[\s\S]{0,400}showWelcome\(data\)/.test(S));
ok('مش في navigateTo',
   ! /window\.navigateTo = function[\s\S]{0,1400}showWelcome/.test(S));
ok('الحارس بيمنع التكرار لنفس المحل', /if \(_welcomeFor === who\) return;/.test(S));
ok('مش متخزّنة في localStorage',
   ! /localStorage[^\n]{0,40}welcome/i.test(S));
ok('اسم المحل ديناميك مش مكتوب',
   /n\.textContent = \(data && \(data\.shopName \|\| data\.username\)\)/.test(S));
/* البوابة إجبارية — لو اتفتحت تحت الترحيب المحل بيشوف شاشة متجمّدة */
ok('طابور afterWelcome موجود', /window\.afterWelcome = function/.test(S));
ok('وبينفّذ فورًا لو الترحيب مش شغّال', /if \(!window\._welcomeOn\) \{ try \{ fn\(\); \} catch \(e\) \{\} return; \}/.test(S));
ok('الترحيب تحت شاشة الدخول في الطبقات',
   /--z-welcome: 9500/.test(S) && /z-index: ?9999/.test(S));

console.log('\n══ 12) الجرس — سجل حقيقي مش رقم مزيّف ══');
const scanAt = S.indexOf('function scanNotifs()');
const scan = S.slice(scanAt, scanAt + 1900);
/* محل عنده ٥٠ شحنة كان هيفتح التطبيق ويلاقي ٥٠ «جديد» دفعة واحدة */
ok('أول تحميل لقطة صامتة', /if \(first \|\| was === undefined\) return;/.test(scan));
ok('الأوردر الجديد مش خبر', /was === undefined/.test(scan));
/* وقت الاستطلاع ≠ وقت الحدث — «تم التسليم» لازم يقول وقت التسليم */
ok('الوقت من السيرفر مش من لحظة الاستطلاع',
   /o\.deliveredAt \|\| o\.statusSince \|\| o\.updatedAt/.test(scan));
ok('سجل كل محل لوحده', /NOTIF_KEY = "tiar-store-notifs:"/.test(S));
ok('وبيتحمّل باسم المحل', /loadNotifs\(u\.username\)/.test(S));
/* setHtml بتقارن innerHTML — أي وقت نسبي بيهدّ اللوحة كل ٨ ثواني */
const nfAt = S.indexOf('function renderNotifs()');
ok('مفيش وقت نسبي في اللوحة',
   /toLocaleString\("ar-EG"\)/.test(S.slice(nfAt, nfAt + 1200)));
/* الصدق: اللوحة بتقول إنها محلية مش إشعارات سيرفر */
ok('اللوحة بتقول للمحل إنها مش إشعارات سيرفر',
   /مش إشعارات فورية من السيرفر/.test(S));

console.log('\n══ 13) السايد بار مايكسرش الشريط السفلي ══');
/* 🔴 الراوتر بيلوّن `[data-page]`. لو روابط السايد بار حملت نفس السمة،
   `querySelector` المفرد كان هيلوّن أول واحد بس والشريط يفضل ميت. */
ok('روابط السايد بار بـdata-nav مش data-page',
   ! /#drawer[\s\S]{0,60}data-page/.test(S) && /data-nav="home"/.test(S));
ok('الراوتر بيلوّن كل المطابقات',
   /document\.querySelectorAll\(`\[data-page="\$\{p\}"\]`\)/.test(S));
ok('وحارس اسم الصفحة موجود', /if \(!PAGES\.includes\(page\)\) return;/.test(S));
/* السايد بار لازم يفضل **تحت** البوابة الإجبارية */
ok('السايد بار تحت بوابة الموقع في الطبقات',
   /--z-drawer : 7000/.test(S));
ok('السحب بيستثني العناصر اللي بتتمرّر بنفسها',
   /\.chips, \.leaflet-container/.test(S));
ok('والسحب متعطّل والمودالات مفتوحة', /storeGateOverlay.*pinMapOverlay|blocked\(\)/s.test(S));

console.log('\n══ 14) المحفظة صفحة كاملة ══');
/* القايمة كبرت بعد «عناويني/عملائي» (2026-09-02) — الفحص على وجود
   العنصر جوه سطر PAGES مش على إنه آخر واحد */
ok('مسجّلة في PAGES', /const PAGES = \[[^\]]*"wallet"/.test(S));
ok('وليها عنوان', /"wallet": "المحفظة"/.test(S));
ok('والراوتر بيرسمها', /if \(page === "wallet"\)     renderWallet\(\)/.test(S));
/* رقمين مختلفين للعهدة في شاشتين = المحل مايثقش في الاتنين */
const wlAt = S.indexOf('window.renderWallet = function');
ok('«إجمالي العهدة» بنفس حساب الرئيسية',
   /os\.filter\(o => o\.status !== "ملغي"\)\s*\n?\s*\.reduce\(\(a, o\) => a \+ \(Number\(o\.storePrepaid\) \|\| 0\), 0\)/
     .test(S.slice(wlAt, wlAt + 900)));
ok('كارت الرصيد في الرئيسية بيودّي لها', /onclick="navigateTo\('wallet'\)"/.test(S));

console.log('\n══ 15) الرئيسية اتنضّفت ══');
const homeAt = S.indexOf('window.renderHome = function');
const home = S.slice(homeAt, homeAt + 3200);
ok('مفيش كارت ترحيب مكرر', ! /أهلًا بيك/.test(home));
ok('ومفيش كارت خدمة عملاء', ! /supportCardHtml\(\)/.test(home));
ok('خدمة العملاء بقت في حسابي', /<div id="profSupport"><\/div>/.test(S));
ok('وعنوانها بيختفي لو مفيش أرقام', /head\.style\.display = html \? "" : "none"/.test(S));
/* الأرقام من إعدادات الموقع — مكتوبة بالإيد كانت هتفرق عن تطبيق العميل */
ok('الأرقام مش مكتوبة في الملف',
   ! /01040065651/.test(S), 'فيه رقم مكتوب بالإيد');

console.log('\n══ 16) الشريط السفلي والـFAB ══');
const nav = S.slice(S.indexOf('<div class="bottom-nav">'), S.indexOf('</div><!-- /#app -->'));
ok('الترتيب: الرئيسية · طلباتي · طلب جديد · تقاريري · حسابي',
   (nav.match(/data-page="([a-z-]+)"/g) || []).join(',') ===
   'data-page="home",data-page="my-orders",data-page="new-order",data-page="reports",data-page="profile"',
   (nav.match(/data-page="([a-z-]+)"/g) || []).join(','));
ok('«طلب جديد» بقى دايرة بارزة', /class="nav-btn nav-fab active"/.test(nav));
/* الدايرة جوّه الزرار نفسه — لو كانت طبقة عايمة كانت هتبلع دوسات
   «طلباتي» و«تقاريري» اللي جنبها */
ok('الدايرة جوّه الزرار مش طبقة عايمة', /<span class="fab">➕<\/span>/.test(nav));
ok('والزرار مش بيقص الجزء الطالع', /\.nav-fab \{ position: relative; overflow: visible; \}/.test(S));
ok('شارة الطلبات اتنقلت مع «طلباتي»', /data-page="my-orders"[\s\S]{0,200}id="orders-badge"/.test(nav));

console.log('\n══ 17) الموبايل: حواف الشاشة الآمنة ══');
/* env() بترجّع صفر من غير viewport-fit=cover — الشريط بيقع تحت
   شريط الـhome indicator على الأيفون */
/* لازم يتفحص في وسم الـmeta نفسه — الكلمة موجودة في تعليق فوقه كمان،
   وفحص على الملف كله كان بيعدّي حتى لو الوسم اتغيّر. */
ok('viewport-fit=cover في وسم الـmeta',
   /<meta name="viewport"[^>]*viewport-fit=cover/.test(S));
ok('الشريط بيحسب الحافة السفلية', /padding-bottom: var\(--safe-b\)/.test(S));
ok('والصفحة بتحسب الشريط + الحافة + الدايرة',
   /calc\(var\(--nav\) \+ var\(--safe-b\) \+ var\(--fab-up\) \+ 14px\)/.test(S));
ok('وزرار التحديث فوق الدايرة',
   /#api-refresh-fab[\s\S]{0,120}var\(--fab-up\) \+ 12px/.test(S));

console.log('\n══ 18) الوضع النهاري في كل حاجة جديدة ══');
/* كل الملف على `:root.light` — مش data-theme ولا prefers-color-scheme */
const cssAt = S.indexOf('واجهة جديدة: ترحيب');
const css = S.slice(cssAt, S.indexOf('/* ─── Section Title ─── */', cssAt));
const hard = (css.match(/#[0-9a-fA-F]{6}\b/g) || []).filter(c => c !== '#fff');
ok('مفيش لون مكتوب بالإيد في الـCSS الجديد', hard.length === 0, hard.join('، '));
ok('اللوجو له فلتر مختلف في النهاري', /:root\.light \.tb-logo img/.test(S));
ok('وشاشة الترحيب كمان', /:root\.light \.wel-logo/.test(S));
ok('زرار الثيم لسه في الإعدادات', /id="themeSwitch"/.test(S));
ok('واتشال من الهيدر', ! /id="themeBtn"/.test(S));

console.log('\n══ 19) الخروج بينضّف الشاشات الجديدة ══');
/* شاشة الدخول ورا سايد بار مفتوح أو ترحيب معلّق = تطبيق مكسور */
const outAt = S.indexOf('window.doLogout = function');
const out = S.slice(outAt, outAt + 1400);
ok('بيقفل الإشعارات والسايد بار والترحيب',
   /closeNotifs\?\.\(\)/.test(out) && /closeDrawer\?\.\(\)/.test(out) && /hideWelcome\?\.\(\)/.test(out));
ok('وبيصفّر الترحيب عشان الدخول الجاي', /_welcomeFor = null;/.test(out));
ok('وبيصفّر سجل الإشعارات والمحفظة',
   /_notifUser = null/.test(out) && /_wallet\s+= \{ balance: 0, txns: \[\] \}/.test(out));
console.log('\n════════════════════════════════════════');
console.log('STORE PORTAL: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
