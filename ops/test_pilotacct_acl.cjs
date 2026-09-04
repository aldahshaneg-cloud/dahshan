/**
 * 🔐 حارس: شاشة صلاحيات «تقفيل الطيارين» — الجزء اللي في الواجهة.
 *
 * ═══ الطلب (صاحب النظام 2026-09-01) ═══
 * «عايز أعمل تحكم من تطبيق تقفيل الطيارين بشكل كامل زي ما روح دمشق
 * التطبيق بتاعها فيه تحكم … من داخل التطبيق عايز أعمل صفحة صلاحيات
 * زي اللي في تقفيلة روح دمشق».
 *
 * ═══ اللي بيغلط في قفل أعمدة بـnth-child ═══
 * ① الخريطة بتقول ١٥ عمود والجدول فيه ١٤ → كل الأعمدة بعد الفرق
 *    بتتقفل غلط. الفحص بيعدّ `<th>` الحقيقي ويقارن.
 * ② `colspan` في سطر الإجمالي بيزحلق العد → الرأس بيتقفل والإجمالي لأ.
 *    الفحص بيتأكد إن مافيش colspan في السطور دي.
 * ③ الفهرس من صفر بدل واحد → العمود اللي جنبه هو اللي بيتقفل.
 *
 * ═══ واللي بيغلط في شاشة الصلاحيات نفسها ═══
 * ④ قايمة مفاتيح متكتوبة في الـHTML بتفضل ورا الـWire وتختلف معاه.
 *    الشاشة لازم تتبني من `permGroups` الجاية من السيرفر.
 * ⑤ الحفظ يبعت `false` للمقفول — السيرفر بيخزّن `true` بس، فالمفتاح
 *    المقفول لازم **مايتبعتش خالص**.
 *
 * التشغيل: node ops/test_pilotacct_acl.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const UI = fs.readFileSync('public/accounts.html', 'utf8');

/* قصّ دالة بالمواضع مش بregex — الregex اتمدّت لجوه الدالة اللي بعدها
   قبل كده في المشروع ده والفحوص عدّت وهي غلط. */
const cut = (start, end) => {
  const i = UI.indexOf(start);
  if (i < 0) return '';
  const j = UI.indexOf(end, i + start.length);
  return j < 0 ? UI.slice(i) : UI.slice(i, j);
};

/* ══ 1) خريطة الأعمدة تطابق الجداول الحقيقية ══ */
console.log('\n══ 1) خريطة الأعمدة ══');

const grid = cut('const PA_GRID = {', '\n  };');
ok('الخريطة موجودة', grid.length > 0);

const mapOf = (name) => {
  const seg = grid.split(name + ': { body:')[1];
  if (!seg) return null;
  const arr = seg.slice(seg.indexOf('cols:') + 5, seg.indexOf('] }') + 1);
  /* بنعدّ العناصر: كل `null` وكل نص بين علامتين */
  return (arr.match(/null|'[^']+'/g) || []);
};

const headersOf = (bodyId) => {
  const i = UI.indexOf('id="' + bodyId + '"');
  if (i < 0) return -1;
  const start = UI.lastIndexOf('<table', i);
  return (UI.slice(start, i).match(/<th[\s>]/g) || []).length;
};

[['daily', 'paDailyBody'], ['pilot', 'paPilotBody'], ['month', 'paMonthBody']].forEach(([name, body]) => {
  const m = mapOf(name);
  const h = headersOf(body);
  ok('🔴 ' + name + ': الخريطة (' + (m ? m.length : '؟') + ') = رؤوس الجدول (' + h + ')',
    m !== null && m.length === h,
    'العدد مختلف — كل عمود بعد الفرق هيتقفل غلط');
});

/* الأعمدة الأولى اللي بتفضل دايمًا */
ok('daily: أول عمودين بيفضلوا (# والطيار)',
  (mapOf('daily') || []).slice(0, 2).join(',') === 'null,null');
ok('pilot: أول عمودين بيفضلوا (اليوم واسم اليوم)',
  (mapOf('pilot') || []).slice(0, 2).join(',') === 'null,null');
ok('month: أول تلاتة بيفضلوا (# والطيار والفرع)',
  (mapOf('month') || []).slice(0, 3).join(',') === 'null,null,null');

/* ══ 2) سطور الإجمالي بلا colspan ══
   ⚠️ التعليقات بتتشال قبل الفحص. التعليق اللي فوق كل سطر إجمالي بيقول
   «بلا colspan» — والفحص مسك شرحه هو وفشل والكود صح. حصل قبل كده
   مرتين في المشروع ده، فالتمشيط بقى الخطوة الأولى في أي فحص نصّي. */
console.log('\n══ 2) سطور الإجمالي ══');
const noComments = (t) => t.replace(/\/\*[\s\S]*?\*\//g, '');
['paDailyFoot', 'paPilotFoot', 'paMonthFoot'].forEach(id => {
  const fn = cut('getElementById("' + id + '").innerHTML', '</tr>`;');
  ok('🔴 ' + id + ' بلا colspan — وإلا الأعمدة تتزحلق',
    fn.length > 0 && !/colspan/.test(noComments(fn)),
    'فيه colspan');
});

/* والعدد في سطر الإجمالي لازم يطابق الرؤوس كمان */
const footCells = (id) => {
  const fn = cut('getElementById("' + id + '").innerHTML', '</tr>`;');
  return (fn.match(/<td[\s>]/g) || []).length;
};
ok('paDailyFoot فيه ١٥ خانة', footCells('paDailyFoot') === 15, String(footCells('paDailyFoot')));
ok('paPilotFoot فيه ١٥ خانة', footCells('paPilotFoot') === 15, String(footCells('paPilotFoot')));
ok('paMonthFoot فيه ١٥ خانة', footCells('paMonthFoot') === 15, String(footCells('paMonthFoot')));

/* ══ 3) محرّك الصلاحيات ══ */
console.log('\n══ 3) المحرّك ══');
ok('🔴 paCan بتقارن `=== true` — المفتاح الغايب ممنوع',
  /window\.paCan = k => \(window\._paAcl\?\.keys \|\| \{\}\)\[k\] === true;/.test(UI),
  'المقارنة رخوة — أي قيمة هتعدّي');

const apply = cut('window.applyPaAcl = function', '\n  };');
ok('applyPaAcl اتقصّت', apply.length > 0);
ok('بتخرج بدري لو مافيش acl — مابتقفلش الشاشة بالغلط',
  /if \(!acl\) return;/.test(apply));
ok('🔴 الفهرس \\+1 — nth-child بتبدأ من واحد',
  /nth-child\(' \+ \(i \+ 1\) \+ '\)/.test(apply),
  'الفهرس غلط — العمود اللي جنبه هو اللي هيتقفل');
ok('والقفل بيمسك الرأس والصف والإجمالي مع بعض',
  /tr > \*:nth-child/.test(apply));
ok('الستايل بيتعمل مرة واحدة ويتحدّث بعدها',
  /getElementById\('paAclCss'\)/.test(apply) && /st\.textContent = rules\.join/.test(apply));
ok('التبويبات بتتخفي حسب page.*',
  /b\.style\.display = paCan\('page\.' \+ t\) \? '' : 'none';/.test(apply));
ok('🔴 وتبويب الصلاحيات للأدمن بس',
  /permTab\.style\.display = acl\.isAdmin \? '' : 'none';/.test(apply),
  'أي حد هيشوف شاشة الصلاحيات');
ok('🔴 ولو التبويب المفتوح مقفول بينقله لأول مسموح',
  /if \(open\.length\) switchPaTab\(open\[0\]\);/.test(apply),
  'هيفضل قاعد على شاشة فاضية');
ok('والتنقل بين التواريخ بيتقفل بـact.dateNav',
  /const nav = !paCan\('act\.dateNav'\);/.test(apply)
  && /\['paMonth', 'paBranch', 'paDaySelect'\]/.test(apply));
ok('ولافتة «عرض بس» بتبان لما مافيش act.edit',
  /paCan\('act\.edit'\) \? ''/.test(apply));

/* ══ 4) القفل الفعلي على الخانات ══ */
console.log('\n══ 4) قفل الخانات ══');
const cell = cut('function paCell(pid, day, field, row, opts', '\n  }');
ok('🔴 خانة التعديل بتتقفل بالشهر **أو** بالصلاحية',
  /const locked = !!window\._paData\?\.locked \|\| !paCan\('act\.edit'\);/.test(cell),
  'الصلاحية مش داخلة في القفل');
const pcell = cut('function paPermCell(pid, day, row, side)', '\n  }');
ok('وخانة الاستئذان كمان', /!paCan\('act\.edit'\)/.test(pcell));
const lock = cut('function renderPaLock()', '\n  }');
ok('🔴 وزرار قفل الشهر بيبان لصاحب act.lock بس',
  /const canLock = paCan\('act\.lock'\);/.test(lock)
  && (lock.match(/canLock \?/g) || []).length === 2,
  'الزرار بيبان لأي حد');

/* ══ 5) التقاط الصلاحيات من الرد ══ */
console.log('\n══ 5) الوصل بالتحميل ══');
ok('🔴 الـacl بتتاخد من رد month',
  /window\._paAcl = window\._paData\.acl \|\| null;/.test(UI),
  'الواجهة بتخترع الصلاحيات بدل ما تقراها');
ok('وبتتطبّق قبل الرسم — عشان العمود المقفول ماومضش',
  UI.indexOf('applyPaAcl();') < UI.indexOf('renderPaLock();\n    renderPaStats();'),
  'بتتطبّق بعد الرسم');
/* القايمة اتوسّعت 2026-09-01 بـstaff (تقفيلة الموظفين) وemps (إدارة
   الموظفين) — الفحص بيثبّت القايمة الكاملة عشان تبويب جديد ينضاف هنا
   ولا ينضاف في التبديل يقع فورًا. */
/* واتوسّعت تاني 2026-09-04 بـsettings (إعدادات البرنامج — شكل روح دمشق). */
ok('وكل التبويبات داخلة التبديل',
  /\["daily","pilot","month","deferred","staff","treasury","perms","emps","settings"\]\.forEach/.test(UI)
  && /if \(tab === "treasury"\) loadTreasury\(\);/.test(UI)
  && /if \(tab === "perms"\)    renderPaPerms\(\);/.test(UI)
  && /if \(tab === "staff"\)    loadStaff\(\);/.test(UI)
  && /if \(tab === "emps"\)     renderEmps\(\);/.test(UI)
  && /if \(tab === "settings"\) renderPaSettings\(\);/.test(UI));

/* ══ 6) شاشة الصلاحيات ══ */
console.log('\n══ 6) الشاشة ══');
ok('الماركب موجود', /<div id="pa-perms" style="display:none">/.test(UI));
ok('وقايمة المستخدمين', /id="paPermUser"/.test(UI));
ok('وجسم الشاشة', /id="paPermsBody"/.test(UI));

const rp = cut('window.renderPaPerms = async function', '\n  };');
ok('بتجيب من المسار الصح', /PA_API\.get\('\/api\/pilot-accounting\/acl'\)/.test(rp));
ok('وبتجيب مرة واحدة بس — مش كل مرة يفتح التبويب',
  /if \(!window\._paPerms\) \{/.test(rp));
ok('وبتعرض رسالة الخطأ زي ما هي لو رفض',
  /esc\(e\.message \|\| 'تعذّر تحميل الصلاحيات'\)/.test(rp));

const rb = cut('window.renderPaPermsBody = function', '\n  };');
ok('🔴 الشاشة بتتبني من permGroups الجاية من السيرفر',
  /\(P\.permGroups \|\| \[\]\)\.map\(g =>/.test(rb),
  'فيه قايمة مفاتيح متكتوبة في الـHTML — هتفضل ورا الـWire');
ok('والقوالب كمان من السيرفر',
  /Object\.keys\(P\.presets \|\| \{\}\)/.test(rb));
ok('🔴 و«مافيش صف» بتتقال «الافتراضي» مش «مقفول»',
  /row \? "صلاحيات محدّدة" : "الافتراضي"/.test(rb),
  'الأدمن هيفتكر إنه مقفول عليه وهو لأ');
ok('والافتراضي مشروح بالنص',
  /ماعدا السلف المؤجلة وقفل الشهر والإعدادات/.test(rb));
ok('والفروع بتتعرض ومتعلّمش = الكل',
  /data-permbranch=/.test(rb) && /متعلّمش على حاجة = يشوف كل الفروع/.test(rb));
ok('🔴 وكل نص بيعدّي على esc',
  (rb.match(/esc\(/g) || []).length >= 8,
  String((rb.match(/esc\(/g) || []).length) + ' نداء بس');

/* ══ 7) الحفظ ══ */
console.log('\n══ 7) الحفظ ══');
const sv = cut('window.paPermSave = async function', '\n  };');
ok('بيرفض لو مافيش مستخدم', /if \(!uid\) \{ showToast/.test(sv));
ok('🔴 والمقفول مابيتبعتش خالص — لا `false` ولا حاجة',
  /if \(cb\.checked\) keys\[cb\.getAttribute\('data-permkey'\)\] = true;/.test(sv),
  'بيبعت false — السيرفر بيخزّن true بس فالمفتاح هيتترمي بلا خبر');
ok('والفروع أرقام مش نصوص', /\.map\(cb => Number\(cb\.getAttribute\('data-permbranch'\)\)\)/.test(sv));
ok('وبينده PUT على المسار الصح',
  /PA_API\.put\('\/api\/pilot-accounting\/acl', \{ userId: Number\(uid\), keys, branches \}\)/.test(sv));
ok('وبيرجّع الرسالة لو فشل بدل ما يقول اتحفظت',
  /showToast\('تعذّر الحفظ: '/.test(sv) && /return;/.test(sv));

const rs = cut('window.paPermReset = async function', '\n  };');
ok('🔴 والرجوع للافتراضي بيسأل الأول ويشرح إنه مش قفل',
  /confirm\('هيرجع للافتراضي/.test(rs),
  'بيمسح على طول');
ok('ورقم المستخدم بيتهرّب في المسار', /encodeURIComponent\(uid\)/.test(rs));

/* ══ 8) القوالب والمجموعات ══ */
console.log('\n══ 8) القوالب ══');
const pp = cut('window.paPermPreset = function', '\n  };');
ok('🔴 القالب بيقفل اللي مش فيه كمان — مش بيضيف بس',
  /cb\.checked = want\.has\(cb\.getAttribute\('data-permkey'\)\);/.test(pp),
  'بيضيف من غير ما يقفل — القالب مش بيبقى قالب');
const pg = cut('window.paPermGroup = function', '\n  };');
ok('وعلامة المجموعة بتعلّم كل بنودها', /el\.checked = cb\.checked;/.test(pg));

/* ══ 9) الستايل — كل كلاس مستعمل له تعريف ══
   🔴 الشاشة اتسلّمت أول مرة بماركب كامل (`pa-perm-group` / `pa-chk` /
   `pa-badge` / `warn-box`) **من غير ولا قاعدة CSS** — رندرت خانات خام
   متكومة وصاحب النظام شافها وقال «مش مترتبة». الفحص ده عام: بيلقط كل
   كلاس بيبدأ بـpa- مستعمل في الملف (في HTML أو جوه القوالب) ويتأكد إن
   له تعريف جوه <style>. كلاس جديد من غير CSS = الحارس يقع. */
console.log('\n══ 9) الستايل ══');
const style = (UI.match(/<style>[\s\S]*?<\/style>/) || [''])[0];
ok('بلوك <style> موجود', style.length > 0);

const used = new Set();
/* class="..." الثابتة + اللي جوه القوالب (بيتقطع عند ${) */
for (const m of UI.matchAll(/class="([^"]*)"/g)) {
  m[1].split(/[\s$]/).forEach(c => {
    if (/^pa-[a-z-]+$/.test(c)) used.add(c);
  });
}
/* والكلاسات الشرطية جوه ${ ... ? "pa-x" : "pa-y" } */
for (const m of UI.matchAll(/"(pa-b-[a-z]+)"/g)) used.add(m[1]);

ok('اتلقط كلاسات pa-* مستعملة', used.size >= 10, String(used.size));
let missing = [];
used.forEach(c => {
  /* .pa-in و.pa-pick و.pa-sub و.pa-grid إلخ معرّفين من الاستخراج الأصلي —
     الفحص واحد للكل: تعريف `.الكلاس` بأي صيغة (فراغ أو { بعده) */
  if (!new RegExp('\\.' + c + '(?![a-z-])').test(style)) missing.push(c);
});
ok('🔴 كل كلاس pa-* مستعمل له قاعدة CSS',
  missing.length === 0,
  'بلا تعريف: ' + missing.join(', '));

['warn-box', 'info-box'].forEach(c => {
  const uses = (UI.match(new RegExp('class="' + c + '"', 'g')) || []).length;
  ok('🔴 .' + c + ' معرّف (' + uses + ' استعمال)',
    new RegExp('\\.' + c + ' \\{').test(style),
    'مستعمل ' + uses + ' مرة بلا تعريف');
});

/* عناصر الترتيب الجاية من دمشق بعينها */
ok('الخانات في شبكة أعمدة مش متكومة — repeat(auto-fill, minmax(...))',
  /\.pa-perm-items \{ display: grid; grid-template-columns: repeat\(auto-fill, minmax\(230px, 1fr\)\)/.test(style));
ok('ورأس المجموعة بخلفية مميزة', /\.pa-perm-head \{[^}]*background: var\(--panel\)/.test(style));
ok('وhover على الخانة', /\.pa-chk:hover \{ background:/.test(style));
ok('وشريط الحفظ لاصق', /\.pa-savebar \{ position: sticky; bottom: 0/.test(style));
ok('والأفاتار دايرة بأول حرف',
  /\.pa-avatar \{[^}]*border-radius: 50%/.test(style) && /pa-avatar">\$\{ esc\(\(u\.name \|\| u\.username \|\| "؟"\)\.trim\(\)\.charAt\(0\)\) \}/.test(UI));
ok('وبادجات الحالة بكلاسات مش ستايل محشور',
  /pa-badge \$\{ row \? "pa-b-green" : "pa-b-orange" \}/.test(UI)
  && !/pa-badge" style="background:\$\{ row/.test(UI));
ok('وبادج الفروع أزرق/أخضر زي دمشق',
  /pa-badge \$\{ brs\.length \? "pa-b-blue" : "pa-b-green" \}/.test(UI));
ok('وعدّاد المجموعة ملوّن حسب المحتوى',
  /pa-badge \$\{ on \? "pa-b-green" : "pa-b-muted" \}/.test(UI));

console.log('\n' + '─'.repeat(52));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — شاشة الصلاحيات مربوطة بالسيرفر\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
