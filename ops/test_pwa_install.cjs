/**
 * 📲 حارس تثبيت تطبيق العميل والمحلات — «الضغط على تحميل يوديّ لمربّع
 *    التثبيت الحقيقي مش لصفحة تعليمات».
 *
 * ═══ الطلب (صاحب النظام 2026-09-01) ═══
 * «في تطبيق العميل عايز إذا ضغط على تنزيل التطبيق من الموقع أروح على طول
 *  إلى تثبيت التطبيق مش مجرد تعليمات — عايز تنزيل فعلي بشكل برمجي.
 *  وكذلك تطبيق المحلات».
 *
 * ═══ حدود المنصّة (مهم يتكتب عشان التوقّع يبقى صح) ═══
 * مفيش متصفح بيسمح لموقع إنه «ينزّل» تطبيق بالغصب. أقصى حاجة متاحة هي
 * حدث `beforeinstallprompt` من كروم — وبيه بنفتح **مربّع التثبيت الأصلي
 * بتاع أندرويد**. سفاري على الآيفون مابيبعتوش خالص، فهناك التعليمات هي
 * الطريق الوحيد.
 *
 * ═══ الباج اللي كان بيخلي الكل يشوف تعليمات ═══
 * الروابط في الموقع كانت مظبوطة (`?install=1`)، والـmanifests مستوفية كل
 * شروط التثبيت. المشكلة كانت في `install.js`:
 *   ① بيستنى **١٢٠٠ مللي ثابتة** بعدين يفتح الشاشة — وكروم بياخد وقت أطول
 *     على نت الموبايل (بيجيب الـmanifest والأيقونات الأول)، فالشاشة كانت
 *     بتفتح على «المتصفح مابيسمحش» **قبل** ما الحدث يوصل.
 *   ② `prompt()` من مؤقّت (بلا ضغطة مستخدم) كروم بيرفضه — والاستثناء
 *     مكانش متمسك، فكان بيوقف الدالة كلها.
 *
 * الحل: الانتظار **مربوط بالحدث** مش بمؤقّت، و`try/catch` حوالين
 * `prompt()`، وزرار ضغطة واحدة كبديل. التعليمات آخر حل ولمن يستحقها بس.
 *
 * التشغيل: node ops/test_pwa_install.cjs
 */
const fs = require('fs');

let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};
/* التعليقات بتتمشّط قبل أي فحص على الكود — الشرح بيقتبس السطر القديم
   حرفيًا فالفحص بيلاقيه في التعليق ويعدّي (شوف guards-match-their-own-comments). */
const code = s => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

const JS = fs.readFileSync('public/install.js', 'utf8');
const JC = code(JS);
const INDEX = fs.readFileSync('public/index.html', 'utf8');

console.log('\n══ 1) روابط الموقع بتبعت إشارة التثبيت ══');
const wif = INDEX.slice(INDEX.indexOf('function withInstallFlag'),
                        INDEX.indexOf('function renderApps'));
ok('فيه دالة بتضيف ?install=1', wif.length > 0);
for (const app of ['customer', 'store']) {
  ok(`  و«${app}» جواها`, new RegExp(`["']${app}["']`).test(wif),
     'الرابط هيفتح الصفحة من غير ما يبدأ التثبيت');
}
/* الطيار APK حقيقي — الإشارة عليه مالهاش معنى وممكن تكسر التحميل */
ok('  والطيار مستثنى (APK مش PWA)', ! /["']pilot["']/.test(wif));
ok('والرابط بيتبني من الدالة مش خام',
   /withInstallFlag\(\(v\.url \|\| d\.def \|\| ""\)\.trim\(\), k\)/.test(INDEX));

console.log('\n══ 2) الصفحتين مؤهّلتين للتثبيت ══');
for (const [f, man] of [['customer.html', 'customer-manifest.json'],
                        ['store.html', 'store-manifest.json']]) {
  const H = fs.readFileSync('public/' + f, 'utf8');
  ok(`${f}: فيه manifest`, new RegExp('rel="manifest"[^>]*' + man).test(H)
     || new RegExp(man).test(H), 'من غيره كروم عمره ما هيبعت الحدث');
  /* لازم وسم <script> حقيقي — الاسم مذكور في تعليقات جوه الصفحتين
     فالبحث على الاسم لوحده كان بيعدّي حتى بعد ما الوسم يتشال. */
  ok(`  وبيحمّل install.js`, /<script[^>]+src=["']install\.js["']/.test(H),
     'الاسم في تعليق مش وسم');
  ok(`  وبيسجّل service worker`, /serviceWorker\.register\(/.test(H));
  /* شروط كروم للتثبيت: manifest صالح + أيقونتين + display مستقل */
  const M = JSON.parse(fs.readFileSync('public/' + man, 'utf8'));
  const sizes = (M.icons || []).map(i => i.sizes).join(' ');
  ok(`  ${man}: أيقونة 192 و512`, /192x192/.test(sizes) && /512x512/.test(sizes), sizes);
  ok(`  و display مستقل`, ['standalone', 'fullscreen', 'minimal-ui'].includes(M.display), M.display);
  ok(`  و start_url و name موجودين`, !!M.start_url && !!M.name);
}

console.log('\n══ 3) 🔴 الانتظار مربوط بالحدث مش بمؤقّت أعمى ══');
ok('فيه دالة انتظار للحدث', /function waitForPrompt\(ms\)/.test(JC),
   'الرجوع لمؤقّت ثابت = الشاشة بتفتح على التعليمات قبل ما الحدث يوصل');
ok('  وبتتصحّى من مستمع الحدث', /waiters\.push\(/.test(JC) && /waiters = \[\];/.test(JC));
ok('  ومفيش setTimeout قبل نداء التثبيت من ?install=1',
   ! /setTimeout\([\s\S]{0,60}?dahshanInstall/.test(JC),
   'المؤقّت الأعمى ده كان أصل الباج');
ok('  والآيفون بيروح للتعليمات على طول (سفاري مابيبعتش الحدث)',
   /platform\(\) === "ios"\) return Promise\.resolve\(false\)/.test(JC));

console.log('\n══ 4) 🔴 prompt() محمي والفشل له بديل ══');
ok('نداء prompt جوّه try', /try \{[\s\S]{0,400}deferred\.prompt\(\)/.test(JC),
   'كروم بيرمي NotAllowedError بلا ضغطة — والاستثناء كان بيبلع الدالة كلها');
/* الفحص على **جسم الـcatch** بالذات — فيه `return "blocked"` تانية فوق
   (الحارس على deferred/promptBusy)، فالبحث في الملف كله كان بيعدّي حتى
   لو الـcatch بقى بيرمي الاستثناء تاني. */
const catchBody = JC.slice(JC.indexOf('} catch (e) {', JC.indexOf('async function firePrompt()')),
                           JC.indexOf('} finally {'));
ok('  والـcatch بيرجّع "blocked" مش بيرمي',
   /return "blocked";/.test(catchBody) && ! /throw/.test(catchBody), catchBody.trim().slice(0, 60));
ok('  و"blocked" بيوري زرار ضغطة واحدة مش تعليمات',
   /openSheet\("ready"\)/.test(JC) || /showReady\(\);/.test(JC));
/* مش بس إن المتغيّر موجود — لازم يكون **مستعمل في شرط الحارس** فعلًا */
ok('  ونداءين متزامنين متمنوعين',
   /if \(!deferred \|\| promptBusy\) return "blocked";/.test(JC),
   'نداء تاني وقت ما الأول شغّال بيرمي استثناء');
/* الحدث صالح لنداء واحد بس — لو فضل متخزّن، أي نداء تاني بيرمي استثناء.
   بنقص جسم `firePrompt` بالظبط بدل ما نعتمد على تجاور السطور (التعليق
   اللي في آخر السطر كان بيكسر المطابقة). */
const fp = JC.slice(JC.indexOf('async function firePrompt()'),
                    JC.indexOf('/* ── الشاشة'));
ok('  والحدث بيتستهلك مرة واحدة', /deferred = null;/.test(fp),
   'نداء تاني على نفس الحدث بيرمي استثناء');

console.log('\n══ 5) الشاشة: تلات حالات مرتّبة ══');
ok('حالة انتظار', /_instWait/.test(JS) && /بنجهّز التثبيت/.test(JS));
ok('حالة جاهز (زرار)', /function showReady\(\)/.test(JC));
ok('حالة تعليمات', /function showSteps\(reason\)/.test(JC));

/* 🔴 والأهم: التعليمات لازم تقول **السبب الحقيقي**. الرسالة العامة
   «متصفحك مابيسمحش» كانت بتتعرض لكل الحالات — فاللي فاتح من واتساب
   (وده أكتر حالة) كان بيقرا خطوات كروم وهو مش في كروم أصلًا، يعملها
   ومايحصلش حاجة، ويرجع يقول «بيديني نفس الرسالة تاني». */
ok('  وبتفرّق بين الأسباب', /reason === "inapp"/.test(JC) && /reason === "installed"/.test(JC),
   'رسالة واحدة لكل الحالات = المستخدم بيعمل خطوات مالهاش لازمة');
/* 🔴 الفحص على **الاستدعاء** مش التعريف. دالة معرّفة ومحدش بينده عليها
   بتعدّي أي فحص بيدوّر على الاسم بس — طلع كده في تجربة كسر الحرّاس. */
ok('  وبتكشف المتصفح الداخلي (واتساب/فيسبوك)',
   /function inAppBrowser\(\)/.test(JC) && /FBAN\|FBAV/.test(JC),
   'المتصفح الداخلي عمره ما بيسمح بالتثبيت');
ok('    والكشف متنده فعلًا قبل الانتظار',
   /if \(!deferred && inAppBrowser\(\)\) \{ openSheet\("inapp"\); return; \}/.test(JC),
   'الدالة موجودة بس محدش بيستعملها');
ok('  وبتكشف الأندرويد WebView', /Android\/\.test\(ua\) && \/; wv\\\)\//.test(JC),
   'واتساب وأغلب الـWebViews بيتعرفوا من "; wv)"');
ok('  وبتكشف إن التطبيق متثبّت بالفعل',
   /navigator\.getInstalledRelatedApps\b/.test(JC),
   'كروم مابيعرضش التثبيت لو متثبّت — وده بيبان كأنه عطل');
ok('    والكشف متنده فعلًا',
   /if \(!deferred && await alreadyInstalled\(\)\)/.test(JC));
ok('  والتشخيص قبل الانتظار مش بعده',
   JC.indexOf('inAppBrowser()) { openSheet("inapp")') < JC.indexOf('await waitForPrompt'),
   'انتظار ٨ ثواني على الفاضي قبل رسالة مالهاش معنى');
ok('وفيه زرار «افتح في كروم»',
   /id="_instOpen"/.test(JS) && /getElementById\("_instOpen"\)\.onclick/.test(JC)
   && /intent:\/\//.test(JC),
   'من غيره اللي في واتساب مالوش مخرج خالص');

console.log('\n══ 5.5) الـmanifests بتسمح بكشف التثبيت ══');
for (const man of ['customer-manifest.json', 'store-manifest.json']) {
  const M = JSON.parse(fs.readFileSync('public/' + man, 'utf8'));
  ok(`${man}: فيه related_applications`, Array.isArray(M.related_applications)
     && M.related_applications.some(a => a.platform === 'webapp'),
     'من غيرها getInstalledRelatedApps بترجّع فاضي دايمًا');
  ok(`  و prefer_related_applications = false`, M.prefer_related_applications === false,
     'لو true كروم بيوقف عرض تثبيت الـPWA خالص');
}
ok('🔴 التعليمات مخفية في البداية',
   /id="_instSteps" style="display:none"/.test(JS),
   'لو ظهرت من الأول المستخدم يقرا تعليمات وهو ممكن ياخد تثبيت بضغطة');
ok('وزرار «ثبّت الآن» مخفي لحد ما الحدث يوصل',
   /id="_instGo" style="display:none/.test(JS));
/* ضغطة الزرار = ضغطة مستخدم حقيقية، وهي اللي كروم عايزها */
ok('وضغطة الزرار بتنده prompt', /_instGo"\)\.onclick = async function/.test(JC));

console.log('\n══ 6) الشاشة بتتقفل عند القبول ══');
/* appinstalled مش مضمون في كل المتصفحات — سيبان الشاشة مفتوحة فوق
   التطبيق بعد الموافقة بيبان كأن حاجة علّقت. */
ok('كل مسارات القبول بتقفل الشاشة',
   (JC.match(/=== "accepted"[^\n]*closeSheet\(\)/g) || []).length >= 2,
   'واحد على الأقل بيسيبها مفتوحة');
ok('و appinstalled بيقفلها كمان', /appinstalled[\s\S]{0,200}closeSheet\(\)/.test(JC));

console.log('\n══ 7) العقد مع الصفحتين محفوظ ══');
ok('window.dahshanInstall معرّفة', /window\.dahshanInstall = async function/.test(JC));
ok('window.dahshanCloseInstall معرّفة', /window\.dahshanCloseInstall = closeSheet/.test(JC));
const CUS = fs.readFileSync('public/customer.html', 'utf8');
const STO = fs.readFileSync('public/store.html', 'utf8');
ok('  وتطبيق العميل بينده الاسم ده', /window\.dahshanInstall\(/.test(CUS));
ok('  وبوابة المحلات كمان', /window\.dahshanInstall\(/.test(STO));

console.log('\n══ 8) الـservice worker بيتسجّل بدري ══');
/* كروم **مابيقيّمش التثبيت قبل ما الـservice worker يتسجّل**. الصفحتين
   كانوا بيسجّلوه على `window.load` — يعني بعد ما كل الصور والخطوط تحمّل،
   وده على 4G بياخد ثواني كتير، فالحدث كان بييجي بعد ما مهلة الانتظار
   تكون خلصت والمستخدم شاف التعليمات. install.js بـdefer يعني قبل
   DOMContentLoaded — أبدر بكتير، والتسجيل المكرر آمن. */
ok('install.js بيسجّل الـservice worker بنفسه',
   /navigator\.serviceWorker\.register\("app-sw\.js"\)/.test(JC),
   'التسجيل رجع يعتمد على window.load بتاع الصفحة');
ok('  والتسجيل ده مش متعلّق بـ window load',
   !/addEventListener\("load"[\s\S]{0,120}serviceWorker\.register/.test(JC),
   'اتلف جوه load تاني — بيرجّع نفس التأخير');
/* `.catch(() => {})` القديم كان بيبلع أي فشل في صمت، فلو التسجيل وقع على
   موبايل المستخدم مكناش هنعرف أبدًا — والتثبيت هيفضل مش شغال بلا سبب ظاهر. */
/* لازم الفحص يربط الاتنين: `.catch(function` لوحده و`swError =` لوحده
   بيعدّيهم catch فاضي (`swError` معرّفة فوق أصلًا). الطفرة دي فلتت فعلًا. */
ok('  وفشل التسجيل بيتسجّل مش بيتبلع',
   /serviceWorker\.register\("app-sw\.js"\)\.catch\(function \(e\)[\s\S]{0,140}swError\s*=/.test(JC),
   'الخطأ بيتبلع في catch فاضي');
ok('  وبيظهر في سطر التشخيص', /swError \? " ERR:" \+ swError/.test(JC));
/* المهلة القديمة (٨ ثواني) كانت بتخلص قبل ما كروم يقرّر على شبكة بطيئة. */
ok('ومهلة الانتظار التلقائي ٢٠ ثانية',
   /waitForPrompt\(auto \? 20000 :/.test(JC),
   'رجعت قصيرة — بتوقع على التعليمات قبل ما كروم يقرّر');

console.log('\n══ 9) كشف تطبيقات الدهشان التانية الحاجزة للتثبيت ══');
/* 🔴 الاكتشاف الجذري: الـ٦ تطبيقات كلهم scope: "./" — نطاقات متداخلة
   بالكامل. كروم بيمنع حدث التثبيت عن أي صفحة جوه نطاق تطبيق **متثبّت**،
   فتطبيق قديم واحد (زي «إدارة العملاء») بيقفل التثبيت على النطاق كله
   والمستخدم بيشوف evt0 رغم إن كل الشروط خضراء. عشان كده كل manifest
   لازم يسمّي **كل** التطبيقات في related_applications — من غيرها
   getInstalledRelatedApps بيشوف التطبيق الحالي بس والحاجز بيفضل مخفي. */
const CM = JSON.parse(fs.readFileSync('public/customer-manifest.json', 'utf8'));
const SM = JSON.parse(fs.readFileSync('public/store-manifest.json', 'utf8'));
for (const [label, m] of [['العميل', CM], ['المحلات', SM]]) {
  ok('manifest ' + label + ' بيسمّي كل التطبيقات (٨)',
     Array.isArray(m.related_applications) && m.related_applications.length >= 8,
     'رجع يسمّي نفسه بس — الحاجز القديم بيبقى مخفي');
  ok('  وكلهم platform webapp على نفس النطاق',
     (m.related_applications || []).every(a =>
       a.platform === 'webapp' && /^https:\/\/aldahshan\.cloud\//.test(a.url || '')));
  /* prefer=true بيوقف عرض تثبيت الـPWA خالص — باج كروم موثّق */
  ok('  و prefer_related_applications لسه false',
     m.prefer_related_applications === false);
}
ok('install.js بيجمع أسماء التطبيقات الحاجزة', /var blockers = \[\]/.test(JC) &&
   /blockers\.push\(APP_NAMES\[file\]/.test(JC),
   'blockers اتشالت — الرسالة رجعت «متثبّت بالفعل» الكاذبة');
ok('  ورسالة «متثبّت» بتسمّي الحاجز لو موجود',
   /blockers\.length[\s\S]{0,300}blockers\.join\("، "\)/.test(JC),
   'رسالة واحدة لكل الحالات تاني');
ok('  وضغطة الزرار بتفتح شاشة الحاجز مش توست كاذب',
   /if \(auto \|\| blockers\.length\) \{ openSheet\("installed"\); \}/.test(JC));
ok('  والتشخيص بيعرض أسماء المتثبّت مش العدد بس',
   /replace\("-manifest\.json", ""\)/.test(JC));

console.log('\n════════════════════════════════════════');
console.log('PWA INSTALL: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
