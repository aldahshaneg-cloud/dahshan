/* 🔴 إصلاح: المسوّدة كانت بتتمسح قبل ما تترجع.
 *
 * ═══ البلاغ ═══
 * صاحب النظام 2026-08-31: «لما بعمل رفريش كل حاجة بتروح — أنا فاتح من
 * الكمبيوتر».
 *
 * ═══ الترتيب اللي كان بيحصل ═══
 *   applySession
 *     → navigateTo("new-order")
 *       → الفورم فاضي لسه (الصفحة لسه اتحمّلت) فـ`formHasWork()` = false
 *       → resetForm()
 *         → **clearDraft()**  ← المسوّدة اتمسحت هنا
 *     → setTimeout(0) → restoreDraft() → مالقاش حاجة → false
 *
 * أنا اللي حطيت `clearDraft` جوه `resetForm` عشان «الفورم اتفضّى فالمسوّدة
 * مالهاش لازمة» — من غير ما آخد بالي إن `resetForm` بتتنده كمان في
 * **تهيئة الإقلاع**، واللحظة دي بالظبط هي اللي المسوّدة لازم تعيش فيها.
 *
 * ═══ الإصلاح ═══
 * ① `resetForm` مابقتش تلمس التخزين خالص. مسح المسوّدة بقى عند الحاجة
 *    الوحيدة اللي معناها «خلصنا»: نجاح إرسال آخر طرد.
 * ② بوابة `_draftReady`: مافيش كتابة على المسوّدة قبل ما محاولة الاسترجاع
 *    تخلص. ده بيقفل **كل** فئة الباج مش الحالة دي بس — `addRow` وقت
 *    الإقلاع بتنده `queueSaveDraft`، وبعد نص ثانية كانت هتحفظ فورم فاضي
 *    فوق المسوّدة حتى لو `clearDraft` اتشالت.
 *
 * 🔒 الحارس: ops/test_store_draft_persist.cjs (بند «الإقلاع مايمسحش»)
 */
const fs = require('fs');
const F = 'public/store.html';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};
const L = (...x) => x.join('\n');

/* ═══ ① resetForm مابقتش تلمس التخزين ═══ */
one(
  L('      window._activeRow     = null;',
    '      window._parcelChecked = false;',
    '      window.clearDraft?.();        // الفورم اتفضّى — المسوّدة مالهاش لازمة'),
  L('      window._activeRow     = null;',
    '      window._parcelChecked = false;',
    '      /* 🔴 مافيش `clearDraft` هنا. `resetForm` بتتنده في **تهيئة',
    '         الإقلاع** كمان، واللحظة دي بالظبط هي اللي المسوّدة لازم تعيش',
    '         فيها — المسح هنا كان بيوصل قبل الاسترجاع ويمسح كل حاجة.',
    '         المسح بقى عند «خلصنا» بس: نجاح إرسال آخر طرد. */'),
  '① resetForm مابتمسحش');

/* ═══ ② بوابة _draftReady ═══ */
one(
  L('    window.saveDraft = function () {',
    '      const k = draftKey(); if (!k) return;'),
  L('    /* 🔴 مافيش كتابة قبل ما محاولة الاسترجاع تخلص.',
    '',
    '       من غير البوابة دي، أي كتابة وقت الإقلاع بتدهس المسوّدة قبل ما',
    '       تترجع: `resetForm` بتنده `addRow`، و`addRow` بتنده',
    '       `queueSaveDraft`، وبعد نص ثانية بتحفظ فورم فاضي — يعني',
    '       `saveDraft` بتشوف `formHasWork() === false` وتمسح المفتاح.',
    '       البوابة بتقفل الفئة كلها مش حالة واحدة. */',
    '    window._draftReady = false;',
    '',
    '    window.saveDraft = function () {',
    '      const k = draftKey(); if (!k || !window._draftReady) return;'),
  '② البوابة');

/* ═══ ③ الاسترجاع بيفتح البوابة مهما حصل ═══ */
one(
  L('      /* بعد `navigateTo` عشان الفورم يكون اتبنى. `restoreDraft` بترجّع',
    '         false لو مافيش مسوّدة، فالفورم النضيف بيفضل زي ما هو. */',
    '      setTimeout(() => { try { window.restoreDraft?.(); } catch (e) {} }, 0);'),
  L('      /* بعد `navigateTo` عشان الفورم يكون اتبنى. `restoreDraft` بترجّع',
    '         false لو مافيش مسوّدة، فالفورم النضيف بيفضل زي ما هو.',
    '',
    '         🔴 البوابة بتتفتح **بره الـtry** عشان تتفتح حتى لو الاسترجاع',
    '         رمى — وإلا مسوّدة بايظة واحدة بتقفل الحفظ للجلسة كلها. */',
    '      setTimeout(() => {',
    '        try { window.restoreDraft?.(); } catch (e) {}',
    '        window._draftReady = true;',
    '      }, 0);'),
  '③ فتح البوابة');

/* ═══ ④ المسح عند إرسال آخر طرد ═══ */
one(
  L('      if (rows.length <= 1) {',
    '        /* آخر طرد: تفضية كاملة — بترجّع ملف الاستلام وتضيف طرد فاضي.',
    '           ومعاها بتصفّر `_parcelChecked` فالنقط الحمرا مابتفضلش من',
    '           الشحنة اللي فاتت. */',
    '        resetForm();',
    '        return;',
    '      }'),
  L('      if (rows.length <= 1) {',
    '        /* آخر طرد: تفضية كاملة — بترجّع ملف الاستلام وتضيف طرد فاضي.',
    '           ومعاها بتصفّر `_parcelChecked` فالنقط الحمرا مابتفضلش من',
    '           الشحنة اللي فاتت. */',
    '        resetForm();',
    '        /* هنا بالظبط معناها «خلصنا» — آخر طرد اتبعت. ده المكان الوحيد',
    '           اللي المسح فيه صح، مش جوه `resetForm` اللي بتتنده وقت',
    '           الإقلاع كمان. */',
    '        window.clearDraft?.();',
    '        return;',
    '      }'),
  '④ المسح عند الإرسال');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ المسوّدة مابقتش تتمسح قبل ما تترجع');
