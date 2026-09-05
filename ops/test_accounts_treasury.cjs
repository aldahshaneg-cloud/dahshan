/**
 * 💰 حارس: صفحة الخزنة في تقفيل الطيارين = نفس خزن لوحة الإدارة.
 *
 * ═══ الطلب (صاحب النظام 2026-09-04) ═══
 * «اعمل معاك صفحة عامة للخزنة… وطبعًا الخزنة اللي هتعملها هي نفس الخزنة
 *  اللي جوه». يعني مافيش جدول تاني ولا حساب في المتصفح: نفس المسارات اللي
 *  لوحة الإدارة بتستعملها، والأرصدة من السيرفر.
 *
 * التشغيل: node ops/test_accounts_treasury.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');
const ROOT = path.resolve(__dirname, '..');
const UI = fs.readFileSync(path.join(ROOT, 'public/accounts.html'), 'utf8');
const ROUTES = fs.readFileSync(path.join(ROOT, 'routes/api.php'), 'utf8');
const WIRE = fs.readFileSync(path.join(ROOT, 'app/Wire/PilotAccountingWire.php'), 'utf8');

let pass = 0, fail = 0;
const ok = (what, cond, got = '') => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got ? '   ← ' + got : '')); }
};
const cut = (start, end) => { const i = UI.indexOf(start); if (i < 0) return ''; const j = UI.indexOf(end, i + start.length); return j < 0 ? UI.slice(i) : UI.slice(i, j); };

console.log('\n══ 1) نفس المسارات — مش خزنة تانية ══');
const tr = cut('/* ═══════════ 💰 الخزنة', '/* ═══════════ السلف المؤجلة');
ok('كتلة الخزنة موجودة', tr.length > 500);
ok('الخزن من /api/cash-stores', /PA_API\.get\("\/api\/cash-stores"\)/.test(tr));
ok('الحركات من /api/cash-stores/{id}/transactions', /\/api\/cash-stores\/\$\{ encodeURIComponent\(st\.id\) \}\/transactions/.test(tr));
ok('تسجيل الحركة على POST …/transactions بنفس الجسم (type · amount · reason · notes)',
  /PA_API\.post\(`\/api\/cash-stores\/\$\{ encodeURIComponent\(storeId\) \}\/transactions`, \{ type, amount, reason, notes \}\)/.test(tr));
ok('التحويل على /api/cash-stores/transfer بـfromId/toId', /PA_API\.post\("\/api\/cash-stores\/transfer", \{ fromId, toId, amount/.test(tr));
ok('الاعتماد على /api/cash-transactions/{id}/approve', /\/api\/cash-transactions\/\$\{ id \}\/approve/.test(tr));
ok('🔴 مافيش حساب رصيد في المتصفح — الأرصدة من s.balance', /Number\(s\.balance\)/.test(tr) && !/balance\s*[+-]=/.test(tr));
ok('🔴 مافيش جدول ولا مسار خزنة جديد في الراوتر', !/treasury/.test(ROUTES.replace(/\/\*[\s\S]*?\*\//g, '')));

console.log('\n══ 2) الشاشة والصلاحية ══');
ok('تبويب الخزنة في القايمة', /id="patab-treasury"/.test(UI) && /id="pa-treasury"/.test(UI));
ok('مفتاح page.treasury على السلك', /\['page\.treasury',/.test(WIRE));
ok('التبويب محكوم بـpage.treasury زي باقي الشاشات',
  /* القايمة اتوسّعت 2026-09-05 بـreports (صفحة التقارير) */
  /\['daily', 'pilot', 'month', 'deferred', 'staff', 'treasury', 'reports'\]\.forEach/.test(UI));
ok('السبب إجباري على كل حركة', /اكتب السبب — كل حركة فلوس لازم يبقى لها سبب/.test(tr));
ok('خزنة جديدة للأدمن بس', /\$\("trNewStoreBtn"\)\.style\.display = isAdmin \? "" : "none"/.test(tr));
ok('الزرار بيتقفل وقت الحفظ — دوسة تانية ماتعملش حركتين', /btn\.disabled = true;[\s\S]*?finally \{ btn\.disabled = false; \}/.test(tr));

console.log('\n════════════════════════════════════════');
console.log(fail ? `🔴 وقع ${fail} من ${pass + fail}` : `✅ عدّى ${pass} فحص — الخزنة هي خزنة الإدارة`);
process.exit(fail ? 1 : 0);
