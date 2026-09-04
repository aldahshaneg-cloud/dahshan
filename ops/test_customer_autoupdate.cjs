/**
 * 🔄 حارس: التحديث الذاتي لتطبيق العملاء.
 *
 * ═══ الواقعة اللي الملف ده موجود عشانها (2026-09-01) ═══
 * باج «receipt is not defined» اتصلح واترفع والسيرفر بيقدّم النسخة
 * السليمة — وتليفون العميل فضل بيضرب بيه، لأن التطبيق مفتوح في الخلفية
 * والجافاسكربت القديم في الذاكرة ومافيش أي فحص تلقائي. الأوردر كان
 * بيتسجّل والعميل بيشوف «تعذّر الإرسال» ويضغط تاني — أوردرات مكررة
 * بفلوس حقيقية.
 *
 * ═══ العقدين اللي الفحوص بتثبتهم ═══
 * ① الإقلاع: نسخة أحدث → إعادة تحميل صامتة، محروسة من الحلقات.
 * ② الرجوع من الخلفية: **توست بس — ممنوع إعادة التحميل**. العميل ممكن
 *    يكون في نص أوردر، وفورم العميل بيتمسح بإعادة الرسم (ذاكرة
 *    customer-form-rerender-wipes). طفرة تحط reload هنا بتصلّح باج
 *    بمسح شغل العميل.
 *
 * التشغيل: node ops/test_customer_autoupdate.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const UI = fs.readFileSync('public/customer.html', 'utf8');
const cut = (start, end) => {
  const i = UI.indexOf(start);
  if (i < 0) return '';
  const j = UI.indexOf(end, i + start.length);
  return j < 0 ? UI.slice(i) : UI.slice(i, j);
};

console.log('\n══ 1) النسخة ══');
const meta = (UI.match(/<meta name="app-version" content="([^"]+)" \/>/) || [])[1];
ok('الميتا موجودة', !!meta, 'مافيش meta app-version');
ok('🔴 والنسخة اتحركت من 1.6.1 — الآلية من غير فرق نسخة مابتشتغلش',
  meta !== '1.6.1', meta);

console.log('\n══ 2) الفحص التلقائي ══');
const fn = cut('async function autoUpdateCheck(mode)', '\n}');
ok('autoUpdateCheck موجودة', fn.length > 0);
ok('بتسكت لو مافيش نت', /!navigator\.onLine\) return;/.test(fn));
ok('ومش بتتراكب على نفسها', /_updBusy/.test(fn));

console.log('\n══ 3) مسار الإقلاع ══');
ok('الفحص بيتنده عند الإقلاع متأخر — مش بيزاحم التحميل',
  /setTimeout\(\(\) => autoUpdateCheck\("boot"\), 2000\);/.test(UI));
ok('🔴 وإعادة التحميل محروسة من الحلقات بعلامة لكل نسخة',
  /sessionStorage\.getItem\("upd-tried"\)/.test(fn)
  && /sessionStorage\.setItem\("upd-tried", latest\)/.test(fn),
  'بروكسي بيقدّم نسخة قديمة = حلقة إعادة تحميل لانهائية');
ok('والـSW بيتحدّث قبل إعادة التحميل — وإلا يفضل مخدّم الشِل القديم',
  /getRegistrations\(\)/.test(fn) && fn.indexOf('getRegistrations') < fn.indexOf('location.reload'));

console.log('\n══ 4) مسار الرجوع من الخلفية ══');
const bootEnd = fn.indexOf('} else {');
const visiblePart = bootEnd > 0 ? fn.slice(bootEnd) : '';
ok('الفرع التاني موجود', visiblePart.length > 0);
ok('🔴 توست بس — **ممنوع** إعادة التحميل والعميل ممكن يكون في نص أوردر',
  /toast\(/.test(visiblePart) && !/location\.reload/.test(visiblePart),
  'reload في فرع الرجوع = مسح أوردر بيتكتب (الفورم بيتمسح بإعادة الرسم)');
ok('والمستمع بيتجاهل الإخفاء', /if \(document\.hidden\) return;/.test(UI));
ok('ومتباعد ١٠ دقايق', /10 \* 60 \* 1000/.test(UI));

console.log('\n══ 5) الأساس اللي بيتبني عليه ══');
ok('latestVersion بتتخطى كاش المتصفح والـSW',
  /fetch\("customer\.html\?_v=" \+ Date\.now\(\), \{ cache: "reload" \}\)/.test(UI));
ok('والزرار اليدوي فضل موجود', /window\.doAppUpdate = doAppUpdate;/.test(UI));
ok('والإصلاح الأصلي («receipt is not defined») لسه في مكانه',
  /deliveries\.forEach\(d => \{ if \(!d\.fromReceipt\) saveReceiver\(d\); \}\);/.test(UI));
ok('وفرق «اتسجّل بس العرض وقع» عن «فشل فعلًا» لسه موجود',
  /if \(orderCreated\) \{/.test(UI) && /الطلب اتسجّل ✓/.test(UI));

console.log('\n' + '─'.repeat(52));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — التحديث بيوصل التليفونات لوحده\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
