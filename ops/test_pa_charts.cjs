/**
 * 📈 حارس: الرسوم البيانية في صفحة التقارير (المرحلة ٣ — 2026-09-05).
 *
 * فحص نصّي على accounts.html — الرسوم بتتبني من رد السيرفر، فاللي بنثبّته هنا:
 * • Chart.js من cdnjs بنسخة مثبّتة (المصدر الوحيد المسموح زي الإكسل).
 * • الخمس رسوم اللي صاحب النظام طلبها موجودة لكل بلوك (فرع + إجمالي الشركة):
 *   أوردرات اليوم قصاد خط التعادل · متوقع/فعلي لكل بند · دونات التكلفة ·
 *   الإيراد التراكمي قصاد التكلفة المتوقعة · أوردرات لكل ساعة طيار.
 * • كل رسم بيتدمّر قبل إعادة الرسم (وإلا الكانفاس بيتراكم مع كل تحديث).
 * • الألوان من متغيرات الثيم — الليلي والنهاري.
 * • خط التعادل من ordersNeededPerDay بتاع السيرفر مش رقم مكتوب.
 *
 * التشغيل: node ops/test_pa_charts.cjs
 */
const fs = require('fs');
const path = require('path');
const UI = fs.readFileSync(path.join(__dirname, '..', 'public', 'accounts.html'), 'utf8');
let pass = 0, fail = 0;
const ok = (what, cond, got) => { if (cond) { pass++; console.log('  ✓ ' + what); } else { fail++; console.log('  ✗ ' + what + (got ? '   ← ' + got : '')); } };

console.log('══ 1) المكتبة ══');
ok('🔴 Chart.js من cdnjs بنسخة مثبّتة', /<script src="https:\/\/cdnjs\.cloudflare\.com\/ajax\/libs\/Chart\.js\/4\.4\.1\/chart\.umd\.min\.js"><\/script>/.test(UI));
ok('مافيش مصدر سكربت تاني غير cdnjs', (UI.match(/<script src="https?:\/\/[^"]+"/g) || []).every(t => t.includes('cdnjs.cloudflare.com')));

console.log('\n══ 2) الخمس رسوم ══');
const draw = UI.slice(UI.indexOf('window.rpDrawCharts = function'), UI.indexOf('/* تمرير المعرّف') > 0 ? UI.indexOf('/* تمرير المعرّف') : undefined);
ok('دالة الرسم موجودة', draw.length > 100);
['rpc_daily_', 'rpc_cat_', 'rpc_donut_', 'rpc_cum_', 'rpc_eff_'].forEach(id => {
  ok('كانفاس ' + id + ' في البلوك وبيترسم', UI.includes('<canvas id="' + id + '${ cid }">') && draw.includes('"' + id + '" + cid'));
});
ok('🔴 خط التعادل من ordersNeededPerDay', /const needed = t\.ordersNeededPerDay;/.test(draw) && /خط التعادل/.test(draw));
ok('التراكمي = ثابت/٣٠ × اليوم + متغيّر × الأوردرات التراكمية', /fixedPerDay \* x\.day \+ perOrder \* cumOrd/.test(draw));
ok('أوردرات لكل ساعة بتتفادى القسمة على صفر', /x\.hours > 0 \? Math\.round\(x\.orders \/ x\.hours/.test(draw));
ok('الأيام المرسومة لحد اللي عدّى بس', /filter\(x => x\.day <= \(c\.elapsedDays \|\| 0\)\)/.test(draw));

console.log('\n══ 3) إعادة الرسم والثيم ══');
ok('🔴 الرسم القديم بيتدمّر قبل الجديد', /if \(window\._rpCharts\[id\]\) \{ try \{ window\._rpCharts\[id\]\.destroy\(\); \}/.test(UI));
ok('الألوان من متغيرات الثيم', /rpCssVar\("--sky"\)/.test(draw) && /getPropertyValue\(name\)/.test(UI));
ok('بيرسم لكل فرع وللإجمالي بعد العرض', /forEach\(b => rpDrawCharts\(b, "b" \+ b\.branchId\)\)/.test(UI) && /if \(d\.company\) rpDrawCharts\(d\.company, "co"\)/.test(UI));
ok('لو المكتبة ماتحمّلتش الصفحة ماتقعش', /if \(!window\.Chart\) return;/.test(draw));

console.log('\n════════════════════════════════════════');
console.log('CHARTS: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail ? 1 : 0);
