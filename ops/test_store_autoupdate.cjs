/**
 * 🔄 حارس: التحديث الذاتي لبوابة المحلات.
 *
 * ═══ ليه الملف ده موجود ═══
 * نفس الثغرة اللي ضربت تطبيق العملاء يوم اللايف (2026-09-01): باج
 * «receipt is not defined» اتصلح واترفع، والتليفونات المفتوحة فضلت
 * بتضرب بالجافاسكربت القديم لأن مافيش أي فحص نسخة تلقائي. بوابة
 * المحلات كانت من غير الآلية دي خالص — الحارس ده بيثبت إنها اتنقلت
 * وبتشتغل بنفس العقدين.
 *
 * ═══ العقدين اللي الفحوص بتثبتهم ═══
 * ① الإقلاع: نسخة أحدث → إعادة تحميل صامتة، محروسة من الحلقات.
 * ② الرجوع من الخلفية: **توست بس — ممنوع إعادة التحميل**. المحل ممكن
 *    يكون في نص أوردر، والمسودة (saveDraft) بتحفظ فورم الطلب بس مش
 *    كل حاجة (بيانات مُرسِل، صور بترفع، حوار مفتوح). طفرة تحط reload
 *    هنا بتصلّح باج بمسح شغل المحل.
 *
 * التشغيل: node ops/test_store_autoupdate.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const UI = fs.readFileSync('public/store.html', 'utf8');
const cut = (start, end) => {
  const i = UI.indexOf(start);
  if (i < 0) return '';
  const j = UI.indexOf(end, i + start.length);
  return j < 0 ? UI.slice(i) : UI.slice(i, j);
};

console.log('\n══ 1) النسخة ══');
const meta = (UI.match(/<meta name="app-version" content="([^"]+)" \/>/) || [])[1];
ok('الميتا موجودة في الـhead', !!meta, 'مافيش meta app-version');
ok('والنسخة صيغتها أرقام منقّطة — الآلية بتقارن نصوص فأي صيغة تانية بتلخبط',
  /^\d+\.\d+\.\d+$/.test(meta || ''), meta);

console.log('\n══ 2) الفحص التلقائي ══');
// السكربت في store.html متزحزح ٤ مسافات — قفلة الدالة `\n    }` مش `\n}`
const fn = cut('async function autoUpdateCheck(mode)', '\n    }');
ok('autoUpdateCheck موجودة', fn.length > 0);
ok('بتسكت لو مافيش نت', /!navigator\.onLine\) return;/.test(fn));
ok('ومش بتتراكب على نفسها', /_updBusy/.test(fn));

console.log('\n══ 3) مسار الإقلاع ══');
ok('الفحص بيتنده عند الإقلاع متأخر — مش بيزاحم التحميل',
  /setTimeout\(\(\) => autoUpdateCheck\("boot"\), 2000\);/.test(UI));
ok('🔴 وإعادة التحميل محروسة من الحلقات بعلامة لكل نسخة',
  /sessionStorage\.getItem\("store-upd-tried"\)/.test(fn)
  && /sessionStorage\.setItem\("store-upd-tried", latest\)/.test(fn),
  'بروكسي بيقدّم نسخة قديمة = حلقة إعادة تحميل لانهائية');
ok('والعلامة باسم مخصوص للبوابة — مش بتتخانق مع علامة تطبيق العملاء',
  !/sessionStorage\.(get|set)Item\("upd-tried"/.test(fn));
ok('والـSW بيتحدّث قبل إعادة التحميل — وإلا يفضل مخدّم الشِل القديم',
  /getRegistrations\(\)/.test(fn) && fn.indexOf('getRegistrations') < fn.indexOf('location.reload'));

console.log('\n══ 4) مسار الرجوع من الخلفية ══');
const bootEnd = fn.indexOf('} else {');
const visiblePart = bootEnd > 0 ? fn.slice(bootEnd) : '';
ok('الفرع التاني موجود', visiblePart.length > 0);
ok('🔴 توست بس — **ممنوع** إعادة التحميل والمحل ممكن يكون في نص أوردر',
  /toast\(/.test(visiblePart) && !/location\.reload/.test(visiblePart),
  'reload في فرع الرجوع = مسح شغل المحل (المسودة مش بتحفظ كل حاجة)');
ok('والمستمع بيتجاهل الإخفاء', /if \(document\.hidden\) return;/.test(UI));
ok('ومتباعد ١٠ دقايق', /10 \* 60 \* 1000/.test(UI));

console.log('\n══ 5) الأساس اللي بيتبني عليه ══');
ok('latestVersion بتجيب store.html نفسها — مش صفحة تانية',
  /fetch\("store\.html\?_v=" \+ Date\.now\(\), \{ cache: "reload" \}\)/.test(UI));
ok('والزرار اليدوي متوصّل', /window\.doAppUpdate = doAppUpdate;/.test(UI)
  && /doAppUpdate\('storeUpdBtn'\)/.test(UI));
ok('والـservice worker لسه بيتسجّل — من غيره مافيش شِل يتحدّث أصلًا',
  /serviceWorker\.register\("app-sw\.js"\)/.test(UI));
ok('ومسودة الطلب (شبكة أمان فرع الرجوع) لسه موجودة',
  /window\.saveDraft = function/.test(UI));

console.log('\n' + '─'.repeat(52));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — التحديث بيوصل المحلات لوحده\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
