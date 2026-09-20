/**
 * 🧭 حارس: مفيش سكريبت بينده `api.` من غير ما تكون معرّفة في نطاقه.
 *
 * البلاغ (2026-09-17/20، لوحة الفرع): «في مشكلة في تحميل الأوردرات على المناديب من مكاتب تانية،
 * لما باجي أحمّل بيقولي حدث خطأ» — التوست كان «خطأ: api is not defined».
 * `const api = window.API` معرّفة جوه `<script type="module">` (نطاق خاص بالموديول)، وكود الخريطة
 * (`mapLoadOnOtherPilot`) وقسم «سلف ومرتبات الطيارين من الخزنة» في سكريبتات عادية تانية فبيرموا
 * ReferenceError وقت الضغط بس — لا jscheck ولا page_boot بيشوفوها. الحل: `window.API` صراحةً.
 *
 * التشغيل: node ops/test_script_scope.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => { if (cond) { pass++; console.log('  ✓ ' + what); } else { fail++; console.log('  ✗ ' + what + (got ? '   ← ' + got : '')); } };

for (const f of fs.readdirSync('public').filter(x => x.endsWith('.html'))) {
  const s = fs.readFileSync('public/' + f, 'utf8');
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/g;
  let m; const bad = [];
  while ((m = re.exec(s))) {
    if (/src=/.test(m[1])) continue;
    const body = m[2];
    const uses = [...body.matchAll(/(^|[^.\w$"'`])api\.(get|post|put|delete|del|patch)\(/g)];
    if (!uses.length) continue;
    if (/(const|let|var)\s+api\s*=/.test(body)) continue;
    bad.push('سكريبت سطر ' + s.slice(0, m.index).split('\n').length + ' (' + uses.length + ' نداء)');
  }
  if (bad.length || /\bapi\.(get|post|put|del)\(/.test(s)) ok(f + ': 🔴 كل نداء api. جوه سكريبت معرّف فيه api', bad.length === 0, bad.join(' · '));
}
const B = fs.readFileSync('public/branch.html', 'utf8');
ok('branch: تحميل الخريطة على طيار فرع تاني بينده window.API', B.includes('await window.API.post("/api/orders/assign-bulk", { orderIds: ids, pilotId: p.id });'));
ok('branch: سلف ومرتبات الطيارين من الخزنة بتنده window.API', B.includes('await window.API.get("/api/pilot-accounting/pilot-cash"') && B.includes('await window.API.post("/api/pilot-accounting/pilot-cash"'));

console.log('\n════════════════════════════════════════');
console.log('SCRIPT SCOPE: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail ? 1 : 0);
