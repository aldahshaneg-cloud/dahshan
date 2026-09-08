/**
 * 🪶 حارس: تخفيف لوحات الويب — المرحلة 1 من علاج شكوى «النظام تقيل» (مراجعة 2026-09-08).
 *
 * اللي بنثبّته نصّيًا في tiar/callcenter/branch:
 * • نبضة الحضور مرة واحدة كل دقيقة بـ noPoke (كانت بتفجّر كل البولرات = 25 طلب/دقيقة/تاب)
 *   ومن غير فحص document.hidden (وقفها كان بيسجّل انصراف تلقائي بعد ربع ساعة على تاب تاني).
 * • صفحة الحسابات ما بتتحسبش ولا بتنده /api/pilot-commission-adjustments وهي مقفولة.
 * • xlsx وleaflet بـ defer (900 كيلو كانت بتوقف الرسم)، وكل ملف JS بإصدار ?v=.
 * • بصمة رد الطيارين قبل الرسم، وبصمة مجموعة المتأخرين قبل إعادة الرسم، وحارس الخروج.
 * • الإدارة: since على قوايم الطلبات والورديات، users كل 60 ثانية، وإلغاء التسجيل المكرر
 *   لـ senders/receivers. الكول سنتر: since + حارس changed:false على القوايم.
 * • 50 صف + «عرض المزيد» (طلب صاحب النظام): pagedRows/showMoreRows + المواضع المطلوبة.
 *
 * التشغيل: node ops/test_dashboard_light.cjs
 */
const fs = require('fs');
const path = require('path');
let pass = 0, fail = 0;
const ok = (what, cond, got) => { if (cond) { pass++; console.log('  ✓ ' + what); } else { fail++; console.log('  ✗ ' + what + (got ? '   ← ' + got : '')); } };
const count = (s, needle) => s.split(needle).length - 1;

const PAGES = ['tiar', 'callcenter', 'branch'];
const S = {};
for (const p of PAGES) S[p] = fs.readFileSync(path.join(__dirname, '..', 'public', p + '.html'), 'utf8');

for (const p of PAGES) {
  const s = S[p];
  console.log('\n══ ' + p + ' ══');
  ok('🔴 نبضة الحضور بـ noPoke ومرة واحدة', count(s, 'api.post("/api/attendance/heartbeat", {}, { noPoke: true })') === 1 && count(s, 'api.post("/api/attendance/heartbeat")') === 0);
  ok('النبضة ما بتقفش والتاب مخفي', !/username \|\| document\.hidden\) return;\s*\n\s*api\.post\("\/api\/attendance\/heartbeat"/.test(s));
  ok('xlsx وleaflet بـ defer', s.includes('<script defer src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js">') && s.includes('<script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js">'));
  ok('كل ملفات JS المحلية بإصدار ?v=', !/<script src="assets\/js\/[^"?]+\.js"><\/script>/.test(s));
  ok('🔴 بصمة الطيارين قبل الرسم', s.includes('function pilotsSig(list)') && s.includes('if (_psig === window._pilotsSig) return;'));
  ok('البصمة بتتصفّر عند الخروج', count(s, 'window._currentUser = null; window._pilotsSig = null; window._overdueKey = null;') === 2);
  ok('المتأخرين: رسم بس لو المجموعة اتغيّرت', s.includes('const _okey = [...nowOverdue].sort().join(",");') && s.includes('if (_okey === window._overdueKey) return;'));
  ok('التوست المزدوج اتشال من تنبيه التأخر', !/showToast\(msg, "error"\);\s*\n\s*setTimeout\(\(\) => showToast\(msg, "error"\), 400\);/.test(s));
  ok('🔴 50 صف + «عرض المزيد»: الدالة والزرار والـCSS', s.includes('window.PAGE_ROWS = 50;') && s.includes('function pagedRows(key, list, rowFn, cols, rerender)') && s.includes('window.showMoreRows = function (key)') && s.includes('.more-rows-btn {'));
}

console.log('\n══ الإدارة والكول سنتر ══');
for (const p of ['tiar', 'callcenter']) {
  const s = S[p];
  ok(p + ': 🔴 صفحة الحسابات ما بتتحسبش وهي مقفولة', s.includes('if (!document.getElementById("page-accounting")?.classList.contains("active")) return;\n\n      const { from, to } = getAccDateRange();'));
  ok(p + ': تنبيه التأخر ما بيشتغلش بعد الخروج', s.includes('function checkOverdueOrders() {\n      if (!window._currentUser) return;'));
  ok(p + ': المستلمين/المسلّمين/المناطق/الرسايل بـ 50 صف', s.includes('pagedRows("senders", list, (c, i) =>') && s.includes('pagedRows("receivers", list, (c, i) =>') && s.includes('pagedRows("zones", zones, (z, i) => {') && /pagedRows\("notifs", list, (notifRowHTML|ccNotifRowHTML), 6/.test(s));
}
const T = S.tiar, C = S.callcenter, B = S.branch;
ok('tiar: since على قوايم الطلبات والورديات', ['shifts', 'pilotJoinRequests', 'pilotLeaveRequests', 'pilotShiftRequests', 'pilotReturnRequests', 'pilotTransfers'].every(k => new RegExp(k + ':\\s*\\{ path: "[^"]+",\\s*interval: \\d+, since: true \\}').test(T)) && T.includes('useSince: !!(cfg.merge || cfg.since),'));
ok('tiar: users كل 60 ثانية', /users:\s*\{ path: "\/api\/users",\s*interval: 60000 \}/.test(T));
ok('tiar: 🔴 senders/receivers مسجّلين مرة واحدة', count(T, '_regListener("senders"') === 1 && count(T, '_regListener("receivers"') === 1 && T.includes('window._sendersList = list;') && T.includes('window._receiversList = list;'));
ok('tiar: الورديات بـ 50 صف', T.includes('pagedRows("shifts", filtered, (s, i) => {') && T.includes('}, 8, () => window.renderShiftsPage());'));
ok('tiar: النبضة المكررة اتشالت', !T.includes('// ── 2. إرسال heartbeat كل دقيقة — السيرفر بيحدّث lastSeen'));
ok('callcenter: since + حارس changed:false على القوايم', ['/api/shifts', '/api/join-requests', '/api/leave-requests', '/api/shift-requests', '/api/return-requests', '/api/pilot-transfers'].every(pth => new RegExp('ccPoller\\("' + pth.replace(/\//g, '\\/') + '", \\{ interval: \\d+, useSince: true, onChange: \\(?d\\)? => \\{\\n\\s*if \\(!d\\.items\\) return;').test(C)));
ok('callcenter: users والحضور كل 60 ثانية', C.includes('ccPoller("/api/users", { interval: 60000, useSince: false,') && C.includes('ccPoller("/api/attendance", { interval: 60000, useSince: false,'));
ok('callcenter: النبضة المكررة اتشالت', !C.includes('// ── 2. إرسال heartbeat كل دقيقة ────'));
ok('branch: الورديات بـ 50 صف', B.includes('pagedRows("shifts", filtered, (s, i) => {') && B.includes('}, 8, () => window.renderShiftsPage());'));

console.log('\n════════════════════════════════════════');
console.log('DASHBOARD LIGHT: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail ? 1 : 0);
