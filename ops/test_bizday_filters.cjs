/**
 * 📅 حارس: فلاتر التاريخ في صفحات الأوردرات المنتهية بيوم العمل (٩ صباحًا → ٩ صباحًا) مش بيوم التقويم.
 *
 * البلاغ (2026-09-16، صفحة الطلبات المسلّمة في فرع المدير): «بقفل وأنا الإجمالي 60 أوردر، تاني يوم
 * ألاقيهم 48». الفرع بيشتغل بالليل: يوم 13/9 مثلًا 64 أوردر مسلّم منهم 43 بعد نص الليل. الفلتر كان
 * `new Date(fromVal + "T00:00:00")` … `T23:59:59` بتوقيت المتصفح = يوم تقويم، فالأوردرات اللي بعد
 * نص الليل بتروح لليوم التالي. كل اللوحات التلاتة بقت تستعمل `window._bizRangeOf` (نفس أساس
 * الحسابات والتقارير).
 *
 * التشغيل: node ops/test_bizday_filters.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => { if (cond) { pass++; console.log('  ✓ ' + what); } else { fail++; console.log('  ✗ ' + what + (got ? '   ← ' + got : '')); } };

for (const f of ['branch', 'tiar', 'callcenter']) {
  const S = fs.readFileSync('public/' + f + '.html', 'utf8');
  console.log('══ ' + f + ' ══');
  ok('🔴 مفيش فلتر بيوم التقويم (T00:00:00 / T23:59:59)', !/\+ "T00:00:00"\)/.test(S) && !/\+ "T23:59:59"\)/.test(S));
  ok('  الفلاتر بتنده window._bizRangeOf(from || to, to || from)',
     /window\._bizRangeOf\((fromVal|fromISO) \|\| (toVal|toISO), (toVal|toISO) \|\| (fromVal|fromISO)\)/.test(S));
  ok('  و_bizRangeOf معرّفة على window قبل الاستعمال',
     S.indexOf('window._bizRangeOf =') > 0 && S.indexOf('window._bizRangeOf =') < S.indexOf('window._bizRangeOf(fromVal || toVal'));
  ok('  والتسمية بتقول «بيوم العمل»', /من تاريخ <span title="يوم العمل من ٩ صباحًا لـ٩ صباحًا اليوم التالي"/.test(S));
}
/* التحقق الحسابي: نطاق يوم واحد بيوم العمل = من ٩ صباحًا بالقاهرة لحد ٩ صباحًا اليوم التالي − ١ مللي */
const B = fs.readFileSync('public/branch.html', 'utf8');
const start = B.indexOf('function bizDayStartMs(');
const end = B.indexOf('window._bizRangeOf = _bizRange;');
ok('bizDayStartMs/_bizRange موجودين في الفرع', start > 0 && end > start);
if (start > 0 && end > start) {
  const code = B.slice(start, end);
  let helper;
  try {
    helper = new Function('dayStartHour', 'window', code + '\nreturn { bizDayStartMs, _bizRange };')(() => 9, {});
  } catch (e) {
    helper = null; ok('  الكود بيتقرا كوحدة', false, e.message);
  }
  if (helper) {
    const r = helper._bizRange('2026-09-13', '2026-09-13');
    const hours = (r.to.getTime() + 1 - r.from.getTime()) / 3600e3;
    ok('  يوم واحد = ٢٤ ساعة بالظبط', Math.abs(hours - 24) < 0.001, String(hours));
    ok('  وبيبدأ ٩ صباحًا بالقاهرة (06:00Z في الصيف) مش نص الليل', r.from.toISOString() === '2026-09-13T06:00:00.000Z', r.from.toISOString());
  }
}

console.log('\n════════════════════════════════════════');
console.log('BIZDAY FILTERS: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail ? 1 : 0);
