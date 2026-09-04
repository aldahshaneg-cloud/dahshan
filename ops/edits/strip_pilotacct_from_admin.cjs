/* شيل «تقفيل الطيارين» من لوحة الإدارة — البرنامج المستقل بقى بديلها.
 *
 * ═══ آخر خطوة عن قصد ═══
 * الترتيب كان: ابني accounts.html → اربطه → **بعدين** شيل من هنا. كده لو
 * وقفنا في أي نقطة، النظام شغّال — مافيش لحظة الصفحة مش موجودة في المكانين.
 *
 * ═══ الحذف بترتيب تنازلي ═══
 * كل حذف بيزحلق الأرقام اللي تحته. فبنبدأ من آخر الملف لأوّله، وكل قطعة
 * بتتأكد من مرساة نصّية قبل ما تتشال — الأرقام لوحدها مش كفاية.
 *
 * ═══ 🔴 الفخ في الـCSS ═══
 * قواعد `.pa-*` محشورة **بين** قاعدتين مشتركتين:
 *     8231  .sub-tabs      ← مشتركة مع ٦ حاويات تبويبات
 *     8232-8245  .pa-*     ← دي اللي بتتشال
 *     8246-8248  .sub-tab  ← مشتركة كمان
 * أي قص «من التعليق للتعليق» بياكل `.sub-tab` ويكسر خمس شاشات **بصمت** —
 * التبويبات بتفضل موجودة بس من غير أي ستايل.
 *
 * ═══ 🔴 اسم مخادع مايتلمسش ═══
 * عيلة `*MonthlyCloseout` (~6435-6685) اسمها فيه «تقفيلة» بس بتاعة
 * **صفحة الورديات** — بتتنده من زرار جوه `page-shifts`. مالهاش علاقة.
 */
const fs = require('fs');
const F = 'public/tiar.html';
let L = fs.readFileSync(F, 'utf8').split('\n');
const at = n => String(L[n - 1] ?? '');

/* ── القطع بترتيب تنازلي ─────────────────────────────────── */
const CUTS = [
  { from: 10724, to: 11088, first: '/* ══', last: '};',
    after: 'window.switchAccTab', label: 'الجافاسكربت (365 سطر)' },
  { from: 10663, to: 10664, first: 'بيتحمّل عند الفتح', last: 'window.loadPilotAcct',
    after: null, label: 'خطّاف الفتح' },
  { from: 9573,  to: 9678,  first: '<!-- ══', last: '</div>',
    after: 'صفحة خزن الفروع', label: 'الماركب (106 سطر)' },
  { from: 8967,  to: 8967,  first: "navigateTo('pilotacct')", last: "navigateTo('pilotacct')",
    after: null, label: 'زرار الشريط الجانبي' },
  { from: 8232,  to: 8245,  first: 'شبكة تقفيل الطيارين', last: '.pa-tot {',
    after: '.sub-tab {', label: 'CSS الخاص (14 سطر)' },
];

let bad = 0;
for (const c of CUTS) {
  if (!at(c.from).includes(c.first)) {
    console.log(`  🔴 ${c.label}: سطر ${c.from} متوقع «${c.first}»، لقيت «${at(c.from).trim().slice(0,50)}»`); bad++;
  }
  if (!at(c.to).includes(c.last)) {
    console.log(`  🔴 ${c.label}: سطر ${c.to} متوقع «${c.last}»، لقيت «${at(c.to).trim().slice(0,50)}»`); bad++;
  }
  /* اللي بعد القطعة — الحارس الحقيقي ضد أكل سطر زيادة */
  if (c.after && !at(c.to + 2).includes(c.after) && !at(c.to + 1).includes(c.after)) {
    console.log(`  🔴 ${c.label}: اللي بعدها المفروض «${c.after}»، لقيت «${at(c.to + 1).trim().slice(0,50)}»`); bad++;
  }
}
if (bad) { console.log(`\n🔴 ${bad} حد اتزحلق — مالمستش حاجة`); process.exit(1); }
console.log('  ✓ حدود الخمس قطع كلها مضبوطة');

/* القص — تنازلي */
for (const c of CUTS) {
  L.splice(c.from - 1, c.to - c.from + 1);
  console.log(`  ✓ ${c.label}`);
}

let s = L.join('\n');

/* ── `pilotacct` من قايمة الصفحات ─────────────────────────── */
const before = (s.match(/"pilotacct"/g) || []).length;
s = s.replace(/\s*"pilotacct",/, '');
const after = (s.match(/"pilotacct"/g) || []).length;
if (before - after !== 1) { console.log(`  🔴 PAGES: اتشال ${before - after} بدل 1`); process.exit(1); }
console.log('  ✓ pilotacct من قايمة الصفحات');

fs.writeFileSync(F, s);

/* ── التأكد بعد الكتابة ───────────────────────────────────── */
const out = fs.readFileSync(F, 'utf8');
const checks = [
  ['مافيش أي ذكر لـpilotacct',      !/pilotacct/.test(out)],
  ['مافيش دوال pa* فاضلة',           !/window\.loadPilotAcct|window\.switchPaTab|renderPaDaily/.test(out)],
  ['مافيش CSS بتاع pa',              !/\.pa-grid|\.pa-tot|\.pa-edited/.test(out)],
  ['🔴 .sub-tabs لسه موجودة',        /\.sub-tabs \{/.test(out)],
  ['🔴 .sub-tab لسه موجودة',         /\.sub-tab \{/.test(out)],
  ['🔴 .sub-tab.active لسه موجودة',  /\.sub-tab\.active \{/.test(out)],
  ['🔴 صفحة الورديات لسه بتنده تقفيلتها', /onclick="openMonthlyCloseout\(\)"/.test(out)],
  ['🔴 وعيلة MonthlyCloseout كاملة',  /function buildMonthlyCloseoutData/.test(out)
                                      && /window\.openMonthlyCloseout/.test(out)
                                      && /window\.showMonthlyCloseout/.test(out)],
  ['🔴 switchAccTab مااتاكلتش',       /window\.switchAccTab/.test(out)],
  ['🔴 صفحة الحسابات لسه موجودة',     /id="page-accounting"/.test(out)],
  ['🔴 وصفحة الخزنة',                 /id="page-treasury"/.test(out)],
  ['🔴 وصفحة الورديات',               /id="page-shifts"/.test(out)],
];
let f2 = 0;
for (const [what, cond] of checks) { console.log((cond ? '  ✓ ' : '  ✗ ') + what); if (!cond) f2++; }

console.log(f2 ? `\n🔴 ${f2} فحص وقع` : `\n✅ اتشالت — الملف بقى ${out.split('\n').length} سطر`);
process.exit(f2 ? 1 : 0);
