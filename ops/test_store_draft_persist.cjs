/**
 * 💾 حارس: المسوّدة بتتحفظ على التليفون + الخروج من مودال الباركود.
 *
 * ═══ الطلبات (صاحب النظام 2026-08-31) ═══
 * ① «احفظ الشغل على التليفون علشان مايضيعش مع الرفريش».
 * ② «لما صورة الباركود تظهر، اعمل فيها زرار للخروج».
 *
 * ═══ الترتيب اللي بيكسر الاسترجاع ═══
 * `onZone` بتدهس سعر التوصيل بسعر المنطقة. فلو رجّعنا السعر قبل المنطقة،
 * المحل اللي زوّد السعر بيلاقيه رجع لسعر المنطقة بعد كل رفريش — وده
 * بيضيّع فلوس من غير ما حد ياخد باله. البند ٣ بيحرس الترتيب.
 *
 * ═══ ليه الحفظ مش على `beforeunload` بس ═══
 * أندرويد بيقتل الصفحة من غير ما ينده عليها لما التليفون يضغط على
 * الذاكرة. `visibilitychange` هو اللي بيتنده فعلًا لما المستخدم يطلع من
 * التطبيق. البند ٤ بيتأكد إن الاتنين موجودين.
 *
 * ═══ ليه المفتاح فيه اسم المستخدم ═══
 * محل بيفتح على نفس التليفون بعد محل تاني كان هيلاقي مسوّدة مش بتاعته —
 * بأرقام مستلمين وعناوين غريبة عنه. البند ٢ بيحرسها.
 *
 * التشغيل: node ops/test_store_draft_persist.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const UI = fs.readFileSync('public/store.html', 'utf8');

/* ══ 1) الدوال موجودة ══ */
console.log('\n══ 1) منظومة المسوّدة ══');
['saveDraft', 'clearDraft', 'queueSaveDraft', 'restoreDraft'].forEach(f =>
  ok('window.' + f, new RegExp('window\\.' + f + ' = function').test(UI)));
ok('draftKey داخلية', /function draftKey\(\)/.test(UI));
ok('readDraft داخلية', /function readDraft\(\)/.test(UI));

/* ══ 2) المفتاح ══ */
console.log('\n══ 2) المفتاح ══');
const key = /function draftKey\(\)[\s\S]*?\n    \}/.exec(UI)?.[0] || '';
ok('🔴 فيه اسم المستخدم', /"tiar-store-draft:" \+ u/.test(key),
   'من غيره محل بيشوف مسوّدة محل تاني');
ok('وبيرجّع فاضي لو مفيش مستخدم', /return u \? /.test(key));
ok('والحفظ بيقف لو المفتاح فاضي', /const k = draftKey\(\); if \(!k\) return;/.test(UI));

/* ══ 3) إيه اللي بيتحفظ وبيترجع ══ */
console.log('\n══ 3) الحقول ══');
const rd = /function readDraft\(\)[\s\S]*?\n    \}/.exec(UI)?.[0] || '';
[['rName', 'الاسم'], ['rPhone', 'الهاتف'], ['rPhone2', 'هاتف 2'], ['rZone', 'المنطقة'],
 ['rPrice', 'سعر التوصيل'], ['rOrderPrice', 'العهدة'], ['rNote', 'الملاحظة']]
  .forEach(([f, lbl]) => ok('بيحفظ ' + lbl, rd.includes('"' + f + '-"')));
ok('وعلامة الريسيت', /receipt: !!window\.rowIsReceipt\(n\)/.test(rd));
ok('والعنوان', /addr   : window\.getAddressValue/.test(rd));
ok('والدبوس', /pin    : window\._geoPins\?\.\["r" \+ n\]/.test(rd));
ok('🔴 والصور روابط بس — المؤقّت مابيتحفظش',
   /images : \(window\._rowImages\?\.\[n\] \|\| \[\]\)\.filter\(x => typeof x === "string"\)/.test(rd),
   'كائن {uploading} هيترجع كصورة ميتة');

const rs = /window\.restoreDraft = function \(\)[\s\S]*?\n    \};/.exec(UI)?.[0] || '';
ok('الاسترجاع بينده addRow لكل صف — الودجتات تتبني عادي',
   /d\.rows\.forEach\(row => \{\s*\n\s*addRow\(\);/.test(rs));
ok('🔴 والمنطقة **قبل** السعر — onZone بتدهس السعر',
   (() => {
     const iZone = rs.indexOf('window.onZone(n);');
     const iPrice = rs.indexOf('pe.value = row.price');
     return iZone > 0 && iPrice > 0 && iZone < iPrice;
   })(), 'السعر المزوّد هيترجع لسعر المنطقة مع كل رفريش');
ok('وبيرجّع علامة الريسيت وينده onRowReceipt',
   /cb\.checked = true; window\.onRowReceipt\(n\);/.test(rs));
ok('وبيرجّع الصور والدبوس',
   /window\._rowImages\[n\] = row\.images\.slice\(\)/.test(rs) && /window\._geoPins\["r" \+ n\] = row\.pin/.test(rs));
ok('وبيرجّع للطرد الأول', /window\.showRow\(1\);/.test(rs));
ok('وبيعيد حساب الإجماليات', /recalc\(\); recalcTotal\(\);/.test(rs));

/* ══ 4) إمتى بيتحفظ ══ */
console.log('\n══ 4) مواعيد الحفظ ══');
ok('🔴 عند visibilitychange — أندرويد بيقتل الصفحة من غير beforeunload',
   /document\.addEventListener\("visibilitychange"[\s\S]{0,140}saveDraft/.test(UI));
ok('وعند pagehide', /window\.addEventListener\("pagehide"[\s\S]{0,60}saveDraft/.test(UI));
ok('وعند الكتابة في أي حقل (مؤجّل)',
   /el\.addEventListener\("input",  \(\) => \{ window\.renderParcelTabs\?\.\(\); window\.queueSaveDraft\?\.\(\); \}\)/.test(UI));
ok('والتأجيل نص ثانية — مش كتابة مع كل حرف',
   /_draftTimer = setTimeout\(\(\) => window\.saveDraft\(\), 500\);/.test(UI));
[['addRow', /window\.showRow\(n\);\n      window\.queueSaveDraft\?\.\(\);/, 'إضافة طرد'],
 ['removeSentRow', /window\.saveDraft\?\.\(\);          \/\/ فورًا مش مؤجّل/, 'بعد الإرسال'],
 ['onRowReceipt', /window\.queueSaveDraft\?\.\(\);\n      const hint = /, 'تبديل الريسيت']]
  .forEach(([, re, lbl]) => ok('وعند ' + lbl, re.test(UI)));
/* اتغيّر: المسح كان جوه `resetForm` وده كان بيمسح المسوّدة وقت الإقلاع.
   بقى عند نجاح إرسال آخر طرد بس — البند 4.5 بيحرسه بالتفصيل. */
ok('والمسح بقى بعد إرسال آخر طرد', /window\.clearDraft\?\.\(\);\s*\n\s*return;/.test(UI));
ok('والحفظ بيمسح لوحده لو الفورم بقى فاضي',
   /if \(!formHasWork\(\)\) \{ localStorage\.removeItem\(k\); return; \}/.test(UI));

/* ══ 4.5) 🔴 الإقلاع مايمسحش المسوّدة ══
   الباج اللي حصل فعلًا: `resetForm` كانت بتنده `clearDraft`، و`navigateTo`
   بتنده `resetForm` عند **كل** دخول على صفحة الطلب الجديد — بما فيها
   تهيئة الإقلاع. فالترتيب كان:
       navigateTo → resetForm → clearDraft → (بعدها) restoreDraft → مالقاش
   يعني كل رفريش كان بيمسح الشغل قبل ما يرجّعه.
   البند ده بيحرس الاتنين: المسح اتشال من `resetForm`، والبوابة بتمنع أي
   كتابة قبل ما الاسترجاع يخلص. */
console.log('\n══ 4.5) الإقلاع مايمسحش ══');
const rf = (() => {
  const i = UI.indexOf('function resetForm() {');
  if (i < 0) return '';
  const j = UI.indexOf('\n      addRow();\n    }', i);
  return j < 0 ? '' : UI.slice(i, j);
})();
ok('resetForm اتقصّت', rf.length > 0);
/* 🔴 بنشيل التعليقات قبل الفحص. التعليق اللي في الكود مكتوب فيه «مافيش
   `clearDraft` هنا» — والفحص النصّي كان بيلاقي الكلمة **جوه التعليق**
   ويقول إن المسح لسه موجود. حارس بيمسك تعليقه هو. */
const stripped = rf.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');
ok('🔴 resetForm مابتندهش clearDraft', !/clearDraft/.test(stripped),
   'المسح وقت الإقلاع بيمسح الشغل قبل ما يترجع');
ok('🔴 وبوابة _draftReady موجودة', /window\._draftReady = false;/.test(UI));
ok('والحفظ بيقف عندها', /const k = draftKey\(\); if \(!k \|\| !window\._draftReady\) return;/.test(UI));
ok('والبوابة بتتفتح بعد محاولة الاسترجاع',
   /try \{ window\.restoreDraft\?\.\(\); \} catch \(e\) \{\}\s*\n\s*window\._draftReady = true;/.test(UI));
ok('🔴 وبتتفتح بره الـtry — مسوّدة بايظة مالازمش تقفل الحفظ للجلسة كلها',
   /\} catch \(e\) \{\}\n        window\._draftReady = true;/.test(UI));
ok('والمسح بقى عند نجاح إرسال آخر طرد بس',
   /resetForm\(\);[\s\S]{0,260}window\.clearDraft\?\.\(\);\s*\n\s*return;/.test(UI));

/* تشغيل فعلي للبوابة */
{
  const b = (() => { const i = UI.indexOf('window.saveDraft = function () {');
    const j = UI.indexOf('\n    };', i); return i < 0 || j < 0 ? '' : UI.slice(i, j + 7); })();
  ok('saveDraft اتقصّت', b.length > 0);
  if (b) {
    let removed = false, written = false;
    const win = { _draftReady: false, _currentUser: { username: 'u' } };
    const ls  = { removeItem: () => { removed = true; }, setItem: () => { written = true; } };
    const fn = new Function('window', 'localStorage', 'draftKey', 'formHasWork', 'readDraft', 'Date',
      b.replace(/^window\.saveDraft = /, 'const f = ') + '\nreturn f;')(
      win, ls, () => 'k', () => false, () => [], { now: () => 0 });

    fn();
    ok('🔴 قبل الاسترجاع: مافيش مسح ولا كتابة', !removed && !written,
       removed ? 'مسح!' : 'كتب!');
    win._draftReady = true;
    fn();
    ok('وبعد الاسترجاع: الفورم الفاضي بيمسح المفتاح', removed === true);
  }
}

/* ══ 5) الاسترجاع عند الدخول ══ */
console.log('\n══ 5) الاسترجاع عند الدخول ══');
ok('بيتنده بعد navigateTo في applySession',
   /navigateTo\("new-order"\);[\s\S]{0,700}window\.restoreDraft\?\.\(\)/.test(UI));
ok('وجوه try — مسوّدة بايظة مالازمش توقف الدخول',
   /try \{ window\.restoreDraft\?\.\(\); \} catch \(e\) \{\}/.test(UI));

/* ══ 6) التخزين مايوقعش الصفحة ══ */
console.log('\n══ 6) الأمان ══');
/* 🔴 الفحص ده كان بمدى مفتوح، فلما اتشالت الـtry من `saveDraft` الregex
   كمّلت لجسم `clearDraft` اللي فيه try ولقيت مطابقة — والطفرة عدّت.
   دلوقتي بنقصّ **جسم كل دالة لوحده** ونفحص جواه بس. */
const bodyOf = name => {
  const i = UI.indexOf('window.' + name + ' = function () {');
  if (i < 0) return '';
  const j = UI.indexOf('\n    };', i);
  return j < 0 ? '' : UI.slice(i, j);
};
[['saveDraft', 'الحفظ'], ['clearDraft', 'المسح']].forEach(([fn, lbl]) => {
  const b = bodyOf(fn);
  ok(lbl + ' جوه try/catch', b.length > 0 && /try \{/.test(b) && /catch \(e\) \{/.test(b),
     b.length === 0 ? 'مالقيتش الدالة' : 'مافيش try');
});
ok('والقراءة جوه try برضه',
   /try \{ d = JSON\.parse\(localStorage\.getItem\(k\) \|\| "null"\); \} catch \(e\) \{ return false; \}/.test(UI));

/* ══ 7) الخروج من المودال ══ */
console.log('\n══ 7) مودال الباركود ══');
ok('closeCodesBox في مكان واحد', /window\.closeCodesBox = function \(\)/.test(UI));
ok('🔴 وزرار «تمام» مابقاش بينقل لصفحة تانية',
   !/onclick="document\.getElementById\('_codesBox'\)\.remove\(\); navigateTo\('my-orders'\)"/.test(UI),
   'لسه بيجبرك تخرج من الصفحة');
ok('زرار ✕ فوق', /onclick="closeCodesBox\(\)" aria-label="إغلاق"/.test(UI));
ok('وزرار «تمام» بيقفل بس', /<button onclick="closeCodesBox\(\)"\n[\s\S]{0,220}تمام/.test(UI));
ok('وزرار «طلباتي» منفصل لمين عايز', /closeCodesBox\(\); navigateTo\('my-orders'\)[\s\S]{0,260}طلباتي/.test(UI));
ok('والدوسة على الخلفية بتقفل',
   /box\.addEventListener\("click", e => \{ if \(e\.target === box\) window\.closeCodesBox\(\); \}\);/.test(UI));
ok('🔴 والدوسة على الصندوق نفسه مابتقفلش',
   /if \(e\.target === box\)/.test(UI), 'دوسة غلط جوه المودال هتقفله');
ok('والصندوق position:relative عشان الـ✕ تقعد صح',
   /<div style="position:relative;background:var\(--panel\)/.test(UI));

console.log('\n' + '─'.repeat(50));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — المسوّدة بتعيش والخروج من المودال سهل\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
