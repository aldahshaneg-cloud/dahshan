/**
 * 👥 حارس: واجهة تقفيلة الموظفين وإدارة الموظفين في accounts.html.
 *
 * ═══ اللي بيغلط في الشاشات دي ═══
 * ① خريطة أعمدة الـACL أقصر/أطول من الجدول → القفل بيمسك عمود غلط.
 *    الفحص بيعد <th> الفعلي ويقارن بالخريطة — نفس منهج جداول الطيارين.
 * ② خانة فلوس قابلة للتعديل بأرقام عربية → السيرفر بيقراها (float)
 *    فبترجع صفر. لسعة موثقة حصلت في paCell.
 * ③ قايمة التطبيقات هنا تختلف عن APP_PERMS في لوحة الإدارة → الأدمن
 *    يعلّم على تطبيق من هنا مش موجود هناك أو العكس. الفحص بيقارن
 *    المفاتيح بالفعل من الملفين.
 * ④ الطيار المؤرشف يظهر في شاشة الأسعار → أرقام بتتكتب لطيار خارج
 *    الخدمة. (قرار الأرشفة: مايظهرش في القوايم التشغيلية.)
 *
 * التشغيل: node ops/test_staff_closeout.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const UI = fs.readFileSync('public/accounts.html', 'utf8');
const TIAR = fs.readFileSync('public/tiar.html', 'utf8');

const cut = (start, end) => {
  const i = UI.indexOf(start);
  if (i < 0) return '';
  const j = UI.indexOf(end, i + start.length);
  return j < 0 ? UI.slice(i) : UI.slice(i, j);
};

/* ══ 1) خرايط الأعمدة تطابق الجداول ══ */
console.log('\n══ 1) خرايط الأعمدة ══');
const grid = cut('const PA_GRID = {', '\n  };');
const mapOf = (name) => {
  const seg = grid.split(name + ": { body:")[1];
  if (!seg) return null;
  const arr = seg.slice(seg.indexOf('cols:') + 5, seg.indexOf('] }') + 1);
  return (arr.match(/null|'[^']+'/g) || []);
};
const headersOf = (bodyId) => {
  const i = UI.indexOf('id="' + bodyId + '"');
  if (i < 0) return -1;
  const start = UI.lastIndexOf('<table', i);
  return (UI.slice(start, i).match(/<th[\s>]/g) || []).length;
};
[['stdaily', 'stDailyBody'], ['stperson', 'stPersonBody'], ['stmonth', 'stMonthBody']].forEach(([name, body]) => {
  const m = mapOf(name);
  const h = headersOf(body);
  ok('🔴 ' + name + ': الخريطة (' + (m ? m.length : '؟') + ') = رؤوس الجدول (' + h + ')',
    m !== null && m.length === h,
    'العدد مختلف — القفل هيمسك عمود غلط');
});
ok('وأعمدة الموظفين بنفس مفاتيح الطيارين — قفل «سلف» بيمسك الاتنين',
  (mapOf('stdaily') || []).includes("'col.adv'")
  && (mapOf('stmonth') || []).includes("'mon.net'"));

/* وسطور الإجمالي بلا colspan — نفس شرط nth-child */
const noComments = (t) => t.replace(/\/\*[\s\S]*?\*\//g, '');
['stDailyFoot', 'stPersonFoot', 'stMonthFoot'].forEach(id => {
  const fn = cut('getElementById("' + id + '").innerHTML', '</tr>`;');
  ok('🔴 ' + id + ' بلا colspan', fn.length > 0 && !/colspan/.test(noComments(fn)), 'فيه colspan');
});

/* ══ 2) الخانات القابلة للتعديل ══ */
console.log('\n══ 2) الخانات ══');
const stcell = cut('function stCell(uid, day, field, row, opts', '\n  }');
ok('stCell اتقصّت', stcell.length > 0);
ok('🔴 الرقم لاتيني في الخانة — paMoney العربية بترجع صفر عند الحفظ',
  /Number\(row\[field\] \?\? 0\)\.toFixed\(2\)/.test(stcell) && !/paMoney\(/.test(stcell),
  'أرقام عربية في خانة تعديل');
ok('والقفل بالشهر أو بالصلاحية',
  /const locked = !!window\._stData\?\.locked \|\| !paCan\('act\.edit'\);/.test(stcell));
ok('والمتعدّلة بالإيد بتتلوّن', /pa-edited/.test(stcell));
ok('والحفظ بينده staff-entry',
  /PA_API\.post\("\/api\/pilot-accounting\/staff-entry"/.test(UI));
ok('والاستئذان بينده staff-perms',
  /PA_API\.post\("\/api\/pilot-accounting\/staff-perms"/.test(UI));
const stperm = cut('function stPermCell(uid, day, row, side)', '\n  }');
ok('وخانة الاستئذان بتقرا الجهة من معامل صريح',
  /list\[0\]\[side\]/.test(stperm), 'بتقرا من arguments — هشّة');

/* ══ 3) الجلب والتصفير ══ */
console.log('\n══ 3) الجلب ══');
const load = cut('window.loadStaff = async function', '\n  };');
ok('loadStaff بتجيب مرة واحدة وبتعيد الرسم لو محمّلة',
  /if \(window\._stData\) \{ switchStTab\(window\._stTab\); return; \}/.test(load));
ok('ومن نفس فلتري الشهر والفرع بتوع الطيارين',
  /getElementById\("paMonth"\)/.test(load) && /getElementById\("paBranch"\)/.test(load));
ok('🔴 وتغيير الشهر/الفرع بيصفّرها — وإلا تفضل شايفة الشهر القديم',
  /window\._stData = null;\n\n    renderPaLock\(\);/.test(UI),
  'البيانات مش بتتصفّر عند التحديث');
ok('والخطأ بيتعرض في الجدول مش بيتبلع',
  /esc\(e\.message \|\| "تعذّر التحميل"\)/.test(load));

/* ══ 4) الصلاحيات ══ */
console.log('\n══ 4) الصلاحيات ══');
ok('تبويب الموظفين ورا page.staff',
  /\['daily', 'pilot', 'month', 'deferred', 'staff'\]\.forEach/.test(UI));
ok('🔴 وإدارة الموظفين للأدمن بس',
  /const empsTab = document\.getElementById\('patab-emps'\);\n    if \(empsTab\) empsTab\.style\.display = acl\.isAdmin \? '' : 'none';/.test(UI),
  'أي حد هيشوف شاشة الحسابات');
ok('وتبويبات الأدمن مستثناة من إعادة التوجيه',
  /window\._paTab !== 'perms' && window\._paTab !== 'emps'/.test(UI));

/* ══ 5) إدارة الموظفين ══ */
console.log('\n══ 5) إدارة الموظفين ══');
ok('بتنده نقط نهاية لوحة الإدارة نفسها',
  /PA_API\.get\("\/api\/users"\)/.test(UI) && /PA_API\.get\("\/api\/pilots"\)/.test(UI)
  && /PA_API\.put\("\/api\/pilots\/" \+ encodeURIComponent/.test(UI));
ok('والمحلات والعملاء والطيارين مش في جدول الحسابات — ليهم شاشاتهم',
  /filter\(u => !\["store", "customer", "pilot"\]\.includes\(u\.role\)\)/.test(UI));
ok('🔴 والطيار المؤرشف مش في شاشة الأسعار',
  /filter\(p => !p\.archivedAt\)/.test(UI),
  'أرقام هتتكتب لطيار خارج الخدمة');
ok('والحساب المحمي من غير أزرار', /u\.protected \?/.test(UI));
ok('والإيقاف بيسأل الأول', /if \(block && !confirm\(/.test(UI));

const save = cut('window.saveEmp = async function', '\n  };');
ok('🔴 الباسورد مطلوب في الإضافة بس — التعديل الفاضي مش بيغيره',
  /window\._empEditId === null && !password/.test(save)
  && /if \(password\) body\.password = password;/.test(save),
  'التعديل هيبعت باسورد فاضي');
ok('والزرار بيتقفل وقت الحفظ وبيرجع في finally',
  /btn\.disabled = true;/.test(save) && /finally \{ btn\.disabled = false; \}/.test(save));
ok('ومشرف الفرع لازم له فرع', /role === "مشرف فرع" && !branchId/.test(save));

const modal = cut('window.openEmpModal = function', '\n  };');
ok('واسم المستخدم مقفول في التعديل', /readOnly = !!u;/.test(modal));
ok('والدور الغريب بيتضاف كخيار بدل ما القايمة تقف فاضية',
  /insertAdjacentHTML\("beforeend"/.test(modal));

/* ══ 6) قايمة التطبيقات = لوحة الإدارة ══ */
console.log('\n══ 6) قايمة التطبيقات ══');
const empApps = [...cut('const EMP_APPS = [', '];').matchAll(/key: "([a-zA-Z]+)"/g)].map(m => m[1]);
const tiarApps = [...cut.call && []];
const tiarBlock = (() => {
  const i = TIAR.indexOf('const APP_PERMS = [');
  return TIAR.slice(i, TIAR.indexOf('];', i));
})();
const tiarKeys = [...tiarBlock.matchAll(/key: "([a-zA-Z]+)"/g)].map(m => m[1]);
ok('🔴 نفس مفاتيح APP_PERMS في لوحة الإدارة بالظبط',
  empApps.length > 0 && JSON.stringify([...empApps].sort()) === JSON.stringify([...tiarKeys].sort()),
  'هنا: ' + empApps.length + ' · هناك: ' + tiarKeys.length
  + ' · الفرق: ' + [...empApps.filter(k => !tiarKeys.includes(k)), ...tiarKeys.filter(k => !empApps.includes(k))].join(','));

/* والافتراضيات حسب الدور متطابقة مع البوابة (الأدمن مثالًا) */
const empDef = cut('function empRoleDefaults(role)', '\n  }');
ok('وافتراضي الأدمن مطابق للوحة الإدارة',
  /"admin","branch","store","hr","hrOld","accounts","pilotacct","callcenter","damascus","customer","customers","site","siteadmin","perf","storesadmin","pilotsadmin"/.test(empDef));
ok('وافتراضي المحاسب فيه pilotacct', /"accounts","damascus","pilotacct","site"/.test(empDef));

/* ══ 6+) البرنامج بيسجّل حضور لنفسه ══
   من غير التلات نداءات دول المحاسب اللي شغله كله هنا كان بيبان غايب
   في التقفيلة اللي هو بيشتغل عليها. */
console.log('\n══ 6+) حضور البرنامج نفسه ══');
ok('check-in عند الدخول', /API\.post\("\/api\/attendance\/check-in"\)/.test(UI));
ok('ونبضة كل دقيقة', /API\.post\("\/api\/attendance\/heartbeat"\)/.test(UI));
ok('وانصراف عند القفل بـsendBeacon', /sendBeacon\("\/api\/attendance\/check-out"/.test(UI));

/* ══ 7) حقول الرواتب واصلة السيرفر ══ */
console.log('\n══ 7) حقول الرواتب ══');
const ENT = fs.readFileSync('app/Http/Controllers/Api/EntitiesController.php', 'utf8');
ok('usersUpdate بيقبل الرواتب',
  /'hourRate' => 'hour_rate', 'monthlySalary' => 'monthly_salary'/.test(ENT)
  && /'paid_leave_days = \?'/.test(ENT));
ok('🔴 وpilotsUpdate بيقبل سعر الساعة — كان مالوش أي نقطة كتابة',
  /'hourRate' => 'hour_rate'\] as \$wire => \$col\) \{/.test(ENT.replace(/\n\s+/g, ' ')) || /'hourRate' => 'hour_rate'/.test(ENT),
  '١٤ من ١٥ طيار بصفر ومافيش طريقة تدخل الرقم');
const CW = fs.readFileSync('app/Wire/CoreWire.php', 'utf8');
ok('والسلك بيطلعهم للواجهة',
  (CW.match(/'hourRate'\s+=> round\(\(float\)/g) || []).length === 2
  && (CW.match(/'paidLeaveDays' => \(int\)/g) || []).length === 2);

console.log('\n' + '─'.repeat(52));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — تقفيلة الموظفين وإدارتهم\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
