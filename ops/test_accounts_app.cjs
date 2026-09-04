/**
 * 🧾 حارس: «تقفيل الطيارين» برنامج مستقل — وخرج من لوحة الإدارة.
 *
 * ═══ الطلب ═══
 * صاحب النظام 2026-08-31: «تقفيلة الطيارين اللي في الإدارة أريدها برنامج
 * منفصل في المجموعة». وقراراته: تخرج من الإدارة خالص · تقفيل الطيارين بس ·
 * الأدمن والمحاسب · من غير نطاق خاص.
 *
 * ═══ أخطر تلات حاجات في الاستخراج ═══
 *
 * ① **الصفر الصامت.** `loadPilotAcct` بتعبّي فلتر الفروع من
 *    `window._branchesData`، والمتغيّر ده مش من رد
 *    `/pilot-accounting/month` — مصدره مستمع الفروع في لوحة الإدارة.
 *    نقل الكود من غير نداء `/api/branches` كان هيسيب الفلتر فاضي **من
 *    غير أي خطأ**: الشرط `?.length` بيرجع falsy والسطر بيتخطّى بالسكوت.
 *
 * ② **فخ الـCSS.** قواعد `.pa-*` كانت محشورة بين `.sub-tabs` و`.sub-tab`،
 *    والاتنين دول مشتركين مع ٦ حاويات تبويبات تانية في اللوحة. قص واسع
 *    كان هياكلهم ويكسر خمس شاشات **من غير أي خطأ** — التبويبات بتفضل
 *    موجودة بس بلا ستايل.
 *
 * ③ **الاسم المخادع.** عيلة `*MonthlyCloseout` في tiar.html اسمها فيه
 *    «تقفيلة» بس بتاعة **صفحة الورديات** مش صفحة التقفيل. نقلها كان
 *    هيكسر زرار «تقفيلة شهرية للطيار» في صفحة الورديات.
 *
 * التشغيل: node ops/test_accounts_app.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const ACC   = fs.readFileSync('public/accounts.html', 'utf8');
const TIAR  = fs.readFileSync('public/tiar.html', 'utf8');
const HOME  = fs.readFileSync('public/home.html', 'utf8');
const ROUTES= fs.readFileSync('routes/api.php', 'utf8');

/* ══ 1) البرنامج مكتمل ══ */
console.log('\n══ 1) accounts.html ══');
ok('الملف موجود', ACC.length > 0);
/* 🔑 المفتاح بقى `pilotacct` (2026-09-01) عشان الأدمن يقدر يدّي البرنامج
   لمشرف فرع من غير ما يشتق روح دمشق معاه. `accounts` لسه بيشتقه،
   فالمحاسب مايفقدش حاجة. التفاصيل في ops/test_pilotacct_app_key.cjs. */
ok('كود التطبيق pilotacct — مستقل عن accounts', /var APP_KEY = "pilotacct";/.test(ACC));
ok('🔴 والدخول بيبعت الكود — من غيره أي دور بيصادق بينجح',
   /API\.login\(u, p, APP_KEY\)/.test(ACC), 'بوابة الدخول متخطّاة');
/* مشرف الفرع اتضاف مع شاشة الصلاحيات (2026-09-01): الأدمن بيدّيه
   المفتاح ويحدّد له يشوف إيه من جوّه البرنامج. والسيرفر هو الحكم —
   `aclOf` بتقصّ الأعمدة قبل ما تخرج. */
ok('والأدوار: الأدمن والمحاسب ومشرف الفرع',
   /const ALLOWED = \["admin", "accountant", "branch"\];/.test(ACC));
ok('وبيتحقق منها قبل ما يفتح', /if \(!ALLOWED\.includes\(res\.user\.role\)\)/.test(ACC));
ok('وعند استعادة الجلسة كمان', /if \(d\.user && ALLOWED\.includes\(d\.user\.role\)\)/.test(ACC));

['loadPilotAcct', 'switchPaTab', 'renderPaDaily', 'renderPaPilot', 'renderPaMonth',
 'renderPaDeferred', 'paSave', 'openPaPerms', 'openPaAdvance', 'paLock', 'paUnlock']
  .forEach(f => ok('دالة ' + f, new RegExp('window\\.' + f + ' = ').test(ACC)));
['pa-daily', 'pa-pilot', 'pa-month', 'pa-deferred'].forEach(t =>
  ok('تبويب ' + t, ACC.includes('id="' + t + '"')));
['paBranch', 'paMonth', 'paDaySelect', 'paPilotSelect'].forEach(id =>
  ok('فلتر ' + id, ACC.includes('id="' + id + '"')));
ok('الأدوات المشتركة اتنسخت (esc · escJs · showToast)',
   /window\.esc =/.test(ACC) && /window\.escJs =/.test(ACC) && /window\.showToast = function/.test(ACC));
ok('وحاوية التوست موجودة — showToast بتنده getElementById',
   ACC.includes('<div id="toast"></div>'));
ok('وعميل الـAPI المشترك متحمّل', /<script src="assets\/js\/api\.js"><\/script>/.test(ACC));

/* ══ 2) 🔴 الصفر الصامت ══ */
console.log('\n══ 2) فلتر الفروع ══');
ok('🔴 البرنامج بينده /api/branches بنفسه',
   /await API\.get\("\/api\/branches"\)/.test(ACC),
   'الفلتر هيفضل فاضي من غير أي خطأ');
ok('وبيحطها في _branchesData — الاسم اللي loadPilotAcct بتقراه',
   /window\._branchesData = b\.items \|\| \[\];/.test(ACC));
ok('وبينده قبل loadPilotAcct',
   (() => {
     const iB = ACC.indexOf('window._branchesData = b.items');
     const iL = ACC.indexOf('loadPilotAcct();', iB);
     return iB > 0 && iL > iB;
   })(), 'الترتيب مقلوب — الفلتر هيتبنى قبل ما البيانات توصل');
ok('ولو النداء فشل بيقول للمستخدم مش بيسكت',
   /catch \(e\) \{[\s\S]{0,200}showToast\([^)]*الفروع/.test(ACC));

/* ══ 3) الصفحة خرجت من لوحة الإدارة ══ */
console.log('\n══ 3) لوحة الإدارة ══');
/* 🔴 الذكر الوحيد المسموح في لوحة الإدارة هو **مفتاح الصلاحية** —
   عشان الأدمن يعلّم عليه للمستخدم. أي دالة تقفيل أو CSS بتاعها لسه
   ممنوعة (الفحوص اللي تحت). */
ok('🔴 وذكر pilotacct في اللوحة = مفتاح الصلاحية بس',
   /\{ key: "pilotacct",\s+label: "تقفيل الطيارين"/.test(TIAR)
   && (TIAR.match(/pilotacct/g) || []).length === 3,
   String((TIAR.match(/pilotacct/g) || []).length) + ' ذكر — المفروض ٣');
ok('ومافيش دوال التقفيل',
   !/window\.loadPilotAcct|window\.switchPaTab|renderPaDaily|window\.paSave/.test(TIAR));
ok('ومافيش CSS بتاعها', !/\.pa-grid|\.pa-tot|\.pa-edited/.test(TIAR));
/* البرنامج نفسه لسه بره اللوحة — الاسم بيظهر كـ**لافتة صلاحية** بس.
   ⚠️ التعليقات بتتشال الأول: التعليق فوق مفتاح الصلاحية بيشرح إنه
   «عشان مشرف الفرع ياخد تقفيل الطيارين لوحده»، والفحص مسك شرحه هو
   وقال إن فيه ذكرين. */
const TIAR_CODE = TIAR.replace(/\/\*[\s\S]*?\*\//g, '').replace(/<!--[\s\S]*?-->/g, '');
ok('ومافيش زرار للبرنامج في قايمة اللوحة',
   (TIAR_CODE.match(/تقفيل الطيارين/g) || []).length === 1,
   String((TIAR_CODE.match(/تقفيل الطيارين/g) || []).length) + ' ذكر');

/* ══ 4) 🔴 اللي مالوش يتلمس ══ */
console.log('\n══ 4) اللي لازم يفضل في لوحة الإدارة ══');
/* 🔴 الفحص على **محتوى** القاعدة مش على اسمها.
   محاولتين فشلوا قبل كده:
   ① `/\.sub-tab \{/` — بتطابق جوه `.XX-sub-tab {` لو حد غيّر الاسم.
   ② تثبيتها على بداية السطر — لسه بتعدّي، لأن الملف فيه `.sub-tab`
      تانية جوه media query (`flex-shrink: 0`) والفحص بيلاقيها.
   فبنتأكد من الإعلانات نفسها: لو القاعدة الأساسية اتشالت أو اتغيّر
   اسمها، الإعلانات دي بتختفي والتبويبات بتبقى بلا ستايل في ٥ شاشات. */
ok('🔴 .sub-tabs — الحاوية المشتركة',
   /\.sub-tabs \{ display: flex; gap: 6px;[^}]*border-bottom: 2px solid/.test(TIAR));
ok('🔴 .sub-tab — القاعدة الأساسية بإعلاناتها',
   /\.sub-tab \{ background: none;[^}]*padding: 10px 20px;[^}]*cursor: pointer;/.test(TIAR));
ok('🔴 .sub-tab.active', /\.sub-tab\.active \{ color: var\(--sky\);[^}]*border-bottom-color/.test(TIAR));
ok('🔴 و.sub-tab:hover', /\.sub-tab:hover \{ color: var\(--text\);/.test(TIAR));
/* كانوا ٦ قبل الاستخراج: الإشعارات · العملاء · الموارد البشرية ·
   الحسابات · **التقفيلة** · الإعدادات. حاوية التقفيلة راحت مع صفحتها،
   فالباقي ٥. الرقم ده هو الحارس: لو بقى ٤ يبقى القص أكل حاوية تانية. */
ok('   وعدد حاويات التبويبات الباقية ٥',
   (TIAR.match(/class="sub-tabs"/g) || []).length === 5,
   String((TIAR.match(/class="sub-tabs"/g) || []).length));
ok('🔴 عيلة MonthlyCloseout — بتاعة صفحة الورديات مش التقفيل',
   /function buildMonthlyCloseoutData/.test(TIAR)
   && /window\.openMonthlyCloseout/.test(TIAR)
   && /window\.showMonthlyCloseout/.test(TIAR));
ok('   وزرارها في صفحة الورديات', /onclick="openMonthlyCloseout\(\)"/.test(TIAR));
ok('switchAccTab مااتاكلتش — اسمها قريب من switchPaTab',
   /window\.switchAccTab/.test(TIAR));
['accounting', 'treasury', 'shifts'].forEach(p =>
  ok('صفحة ' + p + ' لسه موجودة', TIAR.includes('id="page-' + p + '"')));
ok('والأدوات المشتركة مااتشالتش من الإدارة',
   /window\.esc =/.test(TIAR) && /window\.escJs =/.test(TIAR) && /showToast/.test(TIAR));

/* ══ 5) شاشة المجموعة ══ */
console.log('\n══ 5) المجموعة ══');
ok('🔴 accounts اتشال من غير المبنيّة',
   /const APPS_NOT_BUILT = \["hr", "hrOld", "perf"\];/.test(HOME),
   'الكارت هيفضل مطفّي');
ok('ومساره اتضاف', /accounts:"accounts\.html",/.test(HOME));
ok('واسم الكارت بقى تقفيل الطيارين',
   /<div class="app-name">تقفيل الطيارين<\/div>/.test(HOME));
/* 🔴 كارت **واحد** بالاسم ده. أول محاولة ضفت كارت تاني لـ`pilotacct`
   فبقى في كارتين مكتوب عليهم «تقفيل الطيارين» بيفتحوا نفس الملف —
   لخبطة صافية. الكارت الموجود هو البرنامج، فالمعرّف بس هو اللي اتغيّر. */
ok('الكارت موجود بمعرّف المفتاح الجديد', /id="card-pilotacct"/.test(HOME));
ok('🔴 وكارت واحد بس بالاسم ده — مش اتنين',
   (HOME.match(/<div class="app-name">تقفيل الطيارين<\/div>/g) || []).length === 1,
   String((HOME.match(/<div class="app-name">تقفيل الطيارين<\/div>/g) || []).length) + ' كارت');

/* ══ 6) السيرفر ══ */
console.log('\n══ 6) الصلاحيات على السيرفر ══');
const r = (verb, path) => {
  const m = new RegExp("Route::" + verb + "\\('" + path.replace(/[{}\/]/g, c => '\\' + c) + "'[\\s\\S]{0,220}?;").exec(ROUTES);
  return m ? (/role:([a-z,_]+)/.exec(m[0])?.[1] || '') : null;
};
[['get', 'pilot-accounting/month'], ['get', 'pilot-accounting/deferred'], ['get', 'pilot-accounting/settings']]
  .forEach(([v, p]) => ok('قراءة ' + p + ' بتقبل المحاسب', (r(v, p) || '').includes('accountant'), r(v, p)));
[['post', 'pilot-accounting/entry'], ['post', 'pilot-accounting/perms']]
  .forEach(([v, p]) => ok('كتابة ' + p + ' بتقبل المحاسب', (r(v, p) || '').includes('accountant'), r(v, p)));
/* 🔴 التلاتة دول كانوا `role:admin` لوحده. اتوسّعوا 2026-09-01 مع شاشة
   الصلاحيات جوّه البرنامج، عشان الأدمن يقدر يمنحهم لحد بعينه بدل ما
   يبقوا مقفولين على الدور.

   ⚠️ التوسيع **مش** بيفتحهم لحد لوحده: `defaultPermKeys()` — اللي بياخدها
   اللي مالوش صف صلاحيات — التلاتة مقصوصين منها بالظبط، فالحارس جوّه
   الكنترولر بيرفض بـ403 زي ما المسار كان بيرفض. الفحص التاني تحت هو
   اللي بيثبّت ده، ومن غيره التوسيع بيبقى فتح صامت. */
const WIRE = fs.readFileSync('app/Wire/PilotAccountingWire.php', 'utf8');
[['post', 'pilot-accounting/deferred', 'act.deferred'],
 ['post', 'pilot-accounting/lock',     'act.lock'],
 ['put',  'pilot-accounting/settings', 'act.settings']]
  .forEach(([v, p, key]) => {
    ok(p + ' مفتوح للتلات أدوار — والحارس جوّه الكنترولر',
       r(v, p) === 'admin,branch,accountant', r(v, p));
    ok('  🔴 و«' + key + '» بره الافتراضي — مافيش حد بياخدها لوحده',
       new RegExp("ADMIN_ONLY_KEYS = \\[[^\\]]*'" + key + "'").test(WIRE),
       'الصلاحية الإدارية دخلت الافتراضي — التوسيع بقى فتح صامت');
  });

console.log('\n' + '─'.repeat(52));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — البرنامج مستقل ولوحة الإدارة سليمة\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
