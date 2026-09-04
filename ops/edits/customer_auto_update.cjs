/* 🔄 تحديث ذاتي لتطبيق العملاء — عشان الإصلاحات توصل التليفونات لوحدها.
 *
 * ═══ الواقعة (2026-09-01 — أول يوم لايف) ═══
 * باج «receipt is not defined» اتصلح واترفع، والسيرفر بيقدّم النسخة
 * السليمة، والـSW أصلًا network-first — ومع كده تليفون العميل فضل
 * بيضرب بنفس الخطأ. السبب: التطبيق **مفتوح في الخلفية** من قبل الرفعة،
 * والجافاسكربت القديم عايش في الذاكرة، ومافيش أي فحص تلقائي — الموجود
 * زرار يدوي في القايمة بس، رغم إن نصه بيدّعي «بيحدّث نفسه لوحده».
 * دي بالظبط ذاكرة «مافيش فرض تحديث»: تعديل التطبيق لوحده مابيسريش.
 *
 * ═══ العلاج — فحصين ═══
 * ① عند الإقلاع: مقارنة نسخة السيرفر بالشغالة، ولو أحدث → إعادة تحميل
 *    **صامتة** فورًا. آمنة لأن العميل لسه ماكتبش حرف، ومحروسة من
 *    الحلقات بعلامة sessionStorage لكل نسخة.
 * ② عند الرجوع للتطبيق (visibilitychange): فحص متباعد (١٠ دقايق)،
 *    ولو فيه أحدث → **توست بس، مش إعادة تحميل**. لأن إعادة التحميل
 *    هنا بتضرب في نص شغل العميل، وفورم العميل بيتمسح بإعادة الرسم
 *    (ذاكرة customer-form-rerender-wipes) — كنا هنصلّح باج بمسح
 *    أوردر بيتكتب.
 *
 * ═══ ورفع النسخة ═══
 * 1.6.1 ← 1.6.2 — الآلية من غير فرق نسخة مابتعملش حاجة.
 *
 * 🔒 الحارس: ops/test_customer_autoupdate.cjs
 */
const fs = require('fs');
const F = 'public/customer.html';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const L = (...x) => x.join('\n');
const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};

if (s.includes('autoUpdateCheck')) { console.log('🔴 موجود قبل كده'); process.exit(1); }

/* ═══ ① رفع النسخة ═══ */
one(
  '<meta name="app-version" content="1.6.1" />',
  '<meta name="app-version" content="1.6.2" />',
  '① النسخة 1.6.2'
);

/* ═══ ② الفحص التلقائي — بعد doAppUpdate مباشرة ═══ */
one(
  'window.doAppUpdate = doAppUpdate;',
  L(
    'window.doAppUpdate = doAppUpdate;',
    '',
    '/* ══ تحديث ذاتي ══',
    '   من غيره التطبيق المفتوح في الخلفية بيفضل شغال بالنسخة القديمة',
    '   للأبد — حصلت فعلًا أول يوم لايف: باج اتصلح واترفع والتليفونات',
    '   فضلت بتضرب بيه لأن مافيش أي فحص غير الزرار اليدوي. */',
    '',
    'let _updBusy = false, _updLastAt = 0;',
    '',
    'async function autoUpdateCheck(mode) {',
    '  if (_updBusy || !navigator.onLine) return;',
    '  _updBusy = true;',
    '  try {',
    '    const latest = await latestVersion();',
    '    if (latest && latest !== APP_VERSION) {',
    '      if (mode === "boot") {',
    '        /* لسه ماكتبش حرف — إعادة تحميل صامتة آمنة. العلامة بتمنع',
    '           حلقة لو في النص بروكسي بيقدّم نسخة قديمة متكاشية. */',
    '        let tried = null;',
    '        try { tried = sessionStorage.getItem("upd-tried"); } catch (_) {}',
    '        if (tried !== latest) {',
    '          try { sessionStorage.setItem("upd-tried", latest); } catch (_) {}',
    '          if ("serviceWorker" in navigator) {',
    '            try {',
    '              const rs = await navigator.serviceWorker.getRegistrations();',
    '              await Promise.all(rs.map(r => r.update().catch(() => {})));',
    '            } catch (_) {}',
    '          }',
    '          location.reload();',
    '          return;',
    '        }',
    '      } else {',
    '        /* راجع للتطبيق وممكن يكون في نص أوردر — إعادة التحميل هنا',
    '           بتمسح اللي كتبه (فورم العميل بيتمسح بإعادة الرسم). توست',
    '           بس، والزرار في القايمة أو قفلة وفتحة بيكمّلوا. */',
    '        toast("فيه نسخة جديدة من التطبيق — اقفله وافتحه تاني أول ما تخلّص 🔄", "ok");',
    '      }',
    '    }',
    '  } catch (_) {}',
    '  _updBusy = false;',
    '}',
    '',
    '/* عند الإقلاع — متأخر ثانيتين عشان مايزاحمش تحميل البيانات الأولى */',
    'setTimeout(() => autoUpdateCheck("boot"), 2000);',
    '',
    '/* وعند الرجوع للتطبيق من الخلفية — متباعد ١٠ دقايق */',
    'document.addEventListener("visibilitychange", () => {',
    '  if (document.hidden) return;',
    '  const now = Date.now();',
    '  if (now - _updLastAt < 10 * 60 * 1000) return;',
    '  _updLastAt = now;',
    '  autoUpdateCheck("visible");',
    '});'
  ),
  '② الفحص التلقائي'
);

/* ═══ ③ تصحيح النص الكدّاب — بقى صادق دلوقتي ═══ */
one(
  '        التطبيق بيحدّث نفسه لوحده أول ما تفتحه ومعاك نت — الزرار ده لو حابب تتأكد بنفسك',
  '        التطبيق بيحدّث نفسه لوحده أول ما تفتحه ومعاك نت، وبينبّهك لو ظهرت نسخة وانت شغال — الزرار ده لو حابب تتأكد بنفسك',
  '③ نص «عن التطبيق»'
);

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ التحديث الذاتي اتركّب');
