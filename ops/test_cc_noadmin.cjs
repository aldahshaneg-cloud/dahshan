/**
 * 🚫 اختبار «مافيش زرار مكسور في الكول سنتر».
 *
 * ═══ الحكاية ═══
 * تطبيق الكول سنتر اتبنى بنسخ صفحات من لوحة الإدارة، فورث معاها أزرار
 * إجراءات على الطيارين والورديات والحسابات. السيرفر بيرفضها من دور
 * `callcenter`، فالموظف كان بيدوس «✅ موافقة» ويطلعله «خطأ: ...» وخلاص.
 * اتشالت كلها 2026-08-30 بقرار صاحب النظام.
 *
 * ═══ ليه الاختبار ده مش مجرد grep ═══
 * (١) الزرار ممكن يرجع بشكل تاني (اسم دالة مختلف، أو onclick على `<div>`
 *     زي ما كان في كارت الطيار).
 * (٢) الخطر الحقيقي مش الزرار — هو **الوصول**. فالفحص الأساسي هنا بيربط
 *     كل `onclick` في الملف بالمسار اللي بيضربه (حتى لو عن طريق مودال)
 *     وبأدواره في `routes/api.php`، وبيقع لو ظهر أي زرار بيرجع 403.
 *
 * التشغيل: node ops/test_cc_noadmin.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (w, c, got) => { c ? (pass++, console.log('  ✓ ' + w))
  : (fail++, console.log('  ✗ ' + w + (got !== undefined ? '   ← ' + got : ''))); };

const html = fs.readFileSync('public/callcenter.html', 'utf8');
const routes = fs.readFileSync('routes/api.php', 'utf8');
const ROLE = 'callcenter';

/* ── أدوار كل مسار ── */
const ROUTES = [];
for (const m of routes.matchAll(/Route::(get|post|put|patch|delete)\(\s*'([^']+)'[\s\S]{0,300}?;/g)) {
  const roles = (m[0].match(/role:([a-z_,]+)/) || [, null])[1];
  ROUTES.push({ uri: m[2], roles: roles ? roles.split(',') : null });
}
const routeRoles = path => {
  const clean = path.replace(/^\/api\//, '').replace(/\$\{[^}]*\}/g, 'X').replace(/\/+$/, '');
  for (const r of ROUTES)
    if (new RegExp('^' + r.uri.replace(/\{[^}]+\}/g, '[^/]+') + '$').test(clean)) return r;
  return null;
};

/* ── جسم كل دالة ── */
const bodies = new Map();
const grab = (idx, name) => {
  let p = html.indexOf('(', idx), pd = 0, body = -1;
  for (let j = p; j < html.length; j++) {
    if (html[j] === '(') pd++;
    else if (html[j] === ')') { pd--; if (!pd) { body = html.indexOf('{', j); break; } }
  }
  let d = 0;
  for (let j = body; j < html.length; j++) {
    const c = html[j];
    if (c === '{') d++;
    else if (c === '}') { d--; if (!d) { bodies.set(name, html.slice(idx, j + 1)); return; } }
    else if (c === '`' || c === '"' || c === "'") {
      const q = c; j++;
      while (j < html.length && html[j] !== q) { if (html[j] === '\\') j++; j++; }
    }
  }
};
for (const m of html.matchAll(/window\.([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?function/g)) grab(m.index, m[1]);
for (const m of html.matchAll(/(?:^|\n)\s*(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(/g))
  if (!bodies.has(m[1])) grab(m.index + m[0].indexOf('function'), m[1]);

/* ── أزرار كل مودال (عشان نتابع الزرار اللي بيفتح مودال) ── */
const modalBtns = new Map();
for (const m of html.matchAll(/<div id="modal-([a-z-]+)"[\s\S]*?<\/div>\s*(?=<div id="modal-|<!--|<script)/g))
  modalBtns.set(m[1], [...m[0].matchAll(/onclick="([A-Za-z_$][\w$]*)\(/g)].map(x => x[1]));

const reaches = (fn, seen = new Set()) => {
  if (seen.has(fn)) return [];
  seen.add(fn);
  const body = bodies.get(fn) || '';
  const out = [];
  for (const c of body.matchAll(/\.(post|put|patch|del|delete)\(\s*[`'"]([^`'"]+)[`'"]/g)) {
    const r = routeRoles(c[2]);
    out.push({ path: c[2], roles: r?.roles || null, ok: r ? (!r.roles || r.roles.includes(ROLE)) : null });
  }
  for (const om of body.matchAll(/openModal\(\s*['"]([a-z-]+)['"]/g))
    for (const inner of (modalBtns.get(om[1]) || [])) out.push(...reaches(inner, seen));
  for (const om of body.matchAll(/getElementById\(\s*['"]modal-([a-z-]+)['"]\s*\)\.style\.display\s*=\s*['"](?:flex|block)/g))
    for (const inner of (modalBtns.get(om[1]) || [])) out.push(...reaches(inner, seen));
  return out;
};

console.log('\n══ 1) 🔴 مافيش أي زرار بيرجع 403 ══');
{
  const sites = new Map();
  for (const m of html.matchAll(/onclick="([A-Za-z_$][\w$]*)\(/g))
    sites.set(m[1], (sites.get(m[1]) || 0) + 1);

  const broken = [];
  for (const [fn, count] of sites) {
    if (!bodies.has(fn)) continue;
    const hits = reaches(fn);
    const known = hits.filter(h => h.ok !== null);
    if (!known.length) continue;
    if (known.every(h => h.ok === false))
      broken.push(fn + ' ×' + count + ' (' + known[0].path + ')');
  }
  ok('مفيش زرار مكسور', broken.length === 0, broken.join(' · '));
  ok('الفحص شغّال فعلًا (لقى أزرار يفحصها)', sites.size > 20, sites.size + ' دالة');
}

console.log('\n══ 2) الإجراءات المتشالة مالهاش أثر ══');
const GONE = ['adminOpenShiftDirect', 'openOpenShiftModal', 'confirmOpenShiftDirect', 'addPilotToPanel',
  'endPilotLeaveAdmin', 'forcePilotLeave', 'completePilotDelivery',
  'approveShiftRequest', 'rejectShiftRequest', 'approveLeaveRequest', 'rejectLeaveRequest',
  'approveReturnRequest', 'rejectReturnRequest', 'approveJoinRequest', 'confirmApproveJoinRequest',
  'rejectJoinRequest', 'approveAdminTransfer', 'rejectAdminTransfer', 'executeDirectMove',
  'applyShiftBonusDeduction', 'openMonthlyCloseout', 'saveMonthlyCloseout',
  'deletePilot', 'deleteUser', 'deleteExpense', 'deleteManualEmployee',
  /* جزيرة الورديات — اتشالت 2026-08-31 */
  'adminEndPilotShift', 'openShiftOrders', 'viewOldShiftReport', 'renderShiftsPage',
  'finalizeShiftEnd', 'showShiftReport', 'recomputeShiftDue', 'sendShiftReportWhatsApp'];
{
  const stillDefined = GONE.filter(n => new RegExp('window\\.' + n + '\\s*=').test(html));
  const stillCalled  = GONE.filter(n => new RegExp('onclick="' + n + '\\(').test(html));
  ok('مفيش تعريف فاضل', stillDefined.length === 0, stillDefined.join(', '));
  ok('مفيش نداء فاضل', stillCalled.length === 0, stillCalled.join(', '));
  ok('مودال «فتح وردية» اتشال', !/id="modal-open-shift"/.test(html));
  ok('مودال «قبول انضمام» اتشال', !/id="modal-approve-creds"/.test(html));
}

console.log('\n══ 3) العرض فضل زي ما هو ══');
/* الطلب كان «شيل الأزرار» مش «شيل الصفحات» — الموظف لازم يفضل شايف
   إن فيه طلب إذن أو إرجاع، بس مايقدرش ياخد قرار. */
for (const [what, needle] of [
  ['صفحة طلبات الطيارين', 'id="page-pilotleave"'],
  ['صفحة النقل والدعم', 'id="page-transfers"'],
  ['صفحة طلبات الانضمام', 'id="page-joinrequests"'],
  ['رسم طلبات الطيارين', 'renderPilotLeavePage'],
  ['رسم النقل والدعم', 'renderAdminTransfersPage'],
  ['رسم طلبات الانضمام', 'renderJoinRequests'],
  ['كارت الطيار', 'pilot-card'],
]) ok(what + ' لسه موجود', html.includes(needle));

console.log('\n══ 4) شغل الكول سنتر مالوش خدش ══');
for (const [what, needle] of [
  ['تسجيل أوردر', 'window.addOrder = async function'],
  ['تسجيل شكوى', 'openComplaintModal'],
  ['تفاصيل الأوردر', 'viewOrderDetails'],
  ['البحث السريع', 'trackCard'],
  ['دليل المناطق', 'renderCCZones'],
]) ok(what + ' شغّال', html.includes(needle));

console.log('\n══ 5) 🔴 الرسم الحقيقي: كروت من غير أزرار ══');
/* الفحص النصي بيقول «الاسم مش موجود». ده بيقول «شغّلت الدالة على طلب
   معلّق فعلًا، والكارت اتبنى، ومافيش زرار فيه». ده اللي بيفرّق بين
   الزرار اتشال، والزرار رجع باسم تاني. */
{
  const cut = name => {
    const start = html.indexOf(name);
    if (start < 0) throw new Error('مالقيتش ' + name);
    let p = html.indexOf('(', start), pd = 0, body = -1;
    for (let j = p; j < html.length; j++) {
      if (html[j] === '(') pd++;
      else if (html[j] === ')') { pd--; if (!pd) { body = html.indexOf('{', j); break; } }
    }
    let d = 0;
    for (let j = body; j < html.length; j++) {
      const c = html[j];
      if (c === '{') d++;
      else if (c === '}') { d--; if (!d) return html.slice(start, j + 1); }
      else if (c === '`' || c === '"' || c === "'") {
        const q = c; j++;
        while (j < html.length && html[j] !== q) { if (html[j] === '\\') j++; j++; }
      }
    }
    throw new Error('ماقدرتش أقفل ' + name);
  };

  const store = {};
  const node = id => (store[id] ||= { id, textContent: '', style: {},
    querySelectorAll: () => [], addEventListener() {},
    get innerHTML() { return this._h || ''; }, set innerHTML(v) { this._h = v; } });
  const BUILTINS = { Object, Array, JSON, Math, String, Number, Boolean, Date, Map, Set, console };
  const base = {
    document: { getElementById: node, querySelectorAll: () => [], querySelector: () => null },
    esc: s => String(s == null ? '' : s), escJs: s => String(s == null ? '' : s),
    fmt: n => String(Number(n) || 0), fmt0: n => String(Number(n) || 0),
    branchName: () => 'فرع', toDateStr: () => '2026-08-30', ...BUILTINS,
  };
  const noop = () => {};
  const run = (name, setup, containerId) => {
    const env = { ...base, window: { _ordersData: [], ...setup } };
    const sandbox = new Proxy(env, {
      has: () => true,
      get: (t, k) => (k in t ? t[k] : noop),
      set: (t, k, v) => { t[k] = v; return true; },
    });
    let err = '';
    try {
      const fn = new Function('__s', `with (__s) { ${cut('function ' + name + '(')}\n return ${name}; }`)(sandbox);
      fn(setup.__arg);
    } catch (e) { err = String(e.message).slice(0, 70); }
    return { html: store[containerId]?.innerHTML || '', err };
  };

  const iso = '2026-08-30T12:00:00.000Z';
  const pending = { id: 't1', pilotId: 'p1', pilotName: 'محمد', fromBranchName: 'أ', toBranchName: 'ب',
                    status: 'pending', requestedAt: iso, reason: 'اختبار' };

  const t = run('renderAdminTransfersPage', { _pilotTransfersData: [pending], _pilotSupportData: [] },
                'adminTransfersContainer');
  ok('النقل والدعم: الكارت اتبنى', t.html.length > 0, t.err || '(فاضي)');
  ok('🔴 النقل والدعم: صفر زرار', !/<button/.test(t.html),
     (t.html.match(/<button[^>]*>([^<]*)/g) || []).join(' | ').slice(0, 90));

  const j = run('renderJoinRequests', { __arg: [{ ...pending, name: 'محمد', phone1: '0100' }] },
                'joinRequestsList');
  ok('طلبات الانضمام: الكارت اتبنى', j.html.length > 0, j.err || '(فاضي)');
  ok('🔴 طلبات الانضمام: صفر زرار إجراء',
     !/onclick="(approve|reject|confirm)/.test(j.html),
     (j.html.match(/onclick="(\w+)/g) || []).join(' | ').slice(0, 90));
}

console.log('\n══ 6) كارت الطيار بقى للعرض بس ══');
{
  ok('مافيش onclick على الكارت', !/pilot-card[^"]*"\s+onclick=/.test(html));
  ok('وتلميح «اضغط عند العودة» اتشال', !html.includes('اضغط عند العودة'));
}

console.log('\n══ 7) 🔴 جزيرة الورديات اتشالت بالكامل ══');
/* ═══ الحكاية ═══
   `page-shifts` و`page-shift-orders` اتنسخوا مع باقي الشاشات من لوحة
   الإدارة وقت ما الكول سنتر اتبنى. مافيش زرار في السايدبار بيوصلهم —
   الـ`navigateTo('shifts')` الوحيد كان زرار «رجوع للورديات» **جوه**
   page-shift-orders نفسها. يعني جزيرة بتوصل لنفسها بس.

   ═══ ليه ده مهم ═══
   جوّاها كان فيه زرار «🔴 إنهاء الوردية» → مودال تسوية →
   `POST /api/shifts/{id}/end`. المسار `role:admin,branch` فالكول سنتر
   كان هياخد 403 — مش خطر تنفيذي، لكنه بيغش أي تدقيق: مسح للكود قرا
   الجزيرة كأنها طريق حقيقي لإنهاء الوردية عند الكول سنتر.

   ═══ ليه فحص نصّي هنا وبس ═══
   القسم (١) بيتابع الزرار → المسار، بس تتبّعه بيعدّي من `openModal` بس.
   مودال التسوية كان مبني بـ`document.createElement` — فالتتبّع وقف عند
   `adminEndPilotShift` ولقى صفر مسارات وعدّاها. الفحص ده بيسدّ الفتحة
   دي بالاسم الصريح. */
{
  for (const [what, needle] of [
    ['صفحة الورديات',        'id="page-shifts"'],
    ['صفحة طلبات الوردية',   'id="page-shift-orders"'],
    ['جدول الورديات',        'id="shiftsBody"'],
    ['لوحة «لم يفتحوا وردية»', 'id="notOpenedShiftBox"'],
    ['مودال التسوية',        '_shiftEndBox'],
    ['نافذة التقفيلة',       '_shiftReportBox'],
  ]) ok(what + ' اتشال', !html.includes(needle), 'لسه موجود');

  /* 🔴 دي الأهم: مسارات الكتابة على الوردية (role:admin,branch) مالهاش
     أي ذكر في ملف الكول سنتر خالص — لا نداء ولا نص. */
  ok('🔴 مافيش نداء لـ /shifts/{id}/end', !/shifts\/\$\{[^}]*\}\/end|shifts\/[^"'`]*\/end/.test(html),
     (html.match(/[^"'`]*shifts[^"'`]*\/end/) || [])[0]);
  ok('🔴 مافيش نداء لـ /shifts/{id}/settlement', !/shifts\/[^"'`]*\/settlement/.test(html));

  /* الجزيرة مالهاش مدخل: لا من السايدبار ولا من أي navigateTo */
  const navBlock = html.slice(html.indexOf('<nav class="sidebar-nav">'),
                              html.indexOf('</nav>', html.indexOf('<nav class="sidebar-nav">')));
  const navPages = [...navBlock.matchAll(/navigateTo\('([\w-]+)'\)/g)].map(m => m[1]);
  ok('السايدبار مافيهوش الورديات', !navPages.includes('shifts') && !navPages.includes('shift-orders'));
  ok('مافيش أي navigateTo للورديات',
     ![...html.matchAll(/navigateTo\(\s*['"](shifts|shift-orders)['"]\s*\)/g)].length);
  ok('الورديات اتشالت من مصفوفة PAGES',
     !/const PAGES = \[[^\]]*"shift/.test(html));

  /* حارس الحارس: لو الفحص فوق بيقرا ملف فاضي أو غلط، الشيكات كلها
     هتعدّي كذب. فبنتأكد إن السايدبار الحقيقي لسه هنا. */
  ok('الفحص شغّال فعلًا (السايدبار مقروء)', navPages.length >= 10, navPages.length + ' صفحة');
}

console.log('\n════════════════════════════════════════');
console.log('CC NOADMIN: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
