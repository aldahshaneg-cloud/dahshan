/**
 * 🏢 حارس «فرع الطيار الثابت مايضيعش من لوحة الإدارة».
 *
 * ═══ البلاغ (صاحب النظام 2026-09-01) ═══
 * «في لوحة الإدارة لا أستطيع تثبيت فرع للطيار من صفحة الطيارين. يعني أريد
 *  أن أعدّل على الطيار أو أحفظه أنه تابع لفرع معيّن، وعندما أقوم بعملها
 *  ترجع لتختفي مرة أخرى».
 *
 * ═══ السبب ═══
 * `homeBranchId` **مكانش في `ID_FIELDS`**، فبيفضل **رقم** جاي من الـJSON،
 * بينما `branches[].id` بيعدّي على `nrm()` وبيتحوّل **نص**. وكل المقارنات
 * في الملف صارمة (`===`):
 *
 *     const branch = _branchesList.find(b => b.id === homeId);   // "53" === 53
 *
 * فبترجع `false` **دايمًا**. النتيجة:
 *   • جدول الطيارين بيعرض «—» في خانة الفرع رغم إنه محفوظ في القاعدة
 *   • قايمة المودال بتفتح على «— بلا فرع —»
 *   • ولما الموظف يحفظ تاني، `pilotBranch.value` فاضية فبيتبعت
 *     `homeBranchId: null` والسيرفر بيكتب `home_branch_id = NULL`
 *     — يعني الفرع **بيتمسح فعلًا**.
 *
 * ده اللي بيفسّر «أحفظه فيرجع يختفي» بالحرف: العرض بيكدب، والحفظ التاني
 * بيصدّق الكدبة.
 *
 * التشغيل: node ops/test_pilot_branch_ui.cjs
 */
const fs = require('fs');

let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};
/* التعليقات بتتمشّط قبل أي فحص على الكود — الشرح بيقتبس السطر القديم
   حرفيًا فالفحص كان بيلاقيه في التعليق ويعدّي. */
const code = s => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

console.log('\n══ 1) 🔴 توحيد المعرّف — أصل الباج ══');
/* أي حقل معرّف بيتقارن بـ`===` لازم يعدّي على `nrm()`، وإلا نوعه بيفرق
   عن الطرف التاني والمقارنة بترجع false في صمت. */
for (const f of ['tiar.html', 'callcenter.html']) {
  const S = fs.readFileSync('public/' + f, 'utf8');
  const m = S.match(/const ID_FIELDS = \[[\s\S]*?\];/);
  ok(`${f}: فيه ID_FIELDS`, !!m);
  if (!m) continue;
  ok(`  و«homeBranchId» جواها`, /homeBranchId/.test(m[0]),
     'هيفضل رقم والفروع نصوص — كل مقارنة === هترجع false');
  /* `assignedBranchId` كان فيها من الأول — وده اللي خلّى الباج يبان
     متقطّع: الطيار اللي مالوش فرع ثابت كان بيشتغل بالـfallback. */
  ok(`  و«assignedBranchId» كمان`, /assignedBranchId/.test(m[0]));
}

console.log('\n══ 2) الحفظ مايمسحش الفرع في صمت ══');
const T  = fs.readFileSync('public/tiar.html', 'utf8');
const TC = code(T);
const at = TC.indexOf('window.addPilot = async function');
const fn = TC.slice(at, TC.indexOf('const pilotData =', at));
ok('فيه حارس على الفرع الفاضي وقت التعديل',
   /if \(!homeBranchId && window\._editState\.active/.test(fn),
   'حفظ بقايمة فاضية بيكتب home_branch_id = NULL');
ok('  وبيقارن باللي محفوظ فعلًا للطيار',
   /_old\.homeBranchId \?\? _old\.assignedBranchId/.test(fn));
ok('  وبيوقف الحفظ (return) مش بس بيحذّر', /return;/.test(fn));
// من 2026-09-12 (الجهاز التاني): شيل الفرع الثابت = الطيار بيبقى 🃏 جوكر — الرسالة بتشرح كده
ok('  والرسالة بتشرح النتيجة (جوكر)',
   /هتشيل فرع «.*الثابت وتخلّيه 🃏 جوكر/.test(T));
/* الحارس لازم يبقى **قبل** تعطيل الزرار — وإلا الزرار بيفضل «جاري الحفظ…»
   والموظف يفتكر الصفحة علّقت. */
ok('🔴 والحارس قبل ما زرار الحفظ يتقفل',
   TC.indexOf('if (!homeBranchId && window._editState.active') <
   TC.indexOf('btn.disabled = true; btn.textContent = "جاري الحفظ…"'),
   'الزرار هيفضل متعطّل بعد الرفض');

console.log('\n══ 3) المودال والجدول بيقروا الفرع الثابت ══');
ok('المودال بيتعبّى من الثابت الأول',
   /fillPilotBranchSelect\(p\.homeBranchId \?\? p\.assignedBranchId\)/.test(TC),
   'التعبئة من الجاري بتحفظ فرع الدعم المؤقت كفرع دائم');
ok('والجدول كمان', /const homeId = p\.homeBranchId \?\? p\.assignedBranchId;/.test(TC));
ok('والدعم المؤقت بيتعرض في سطر منفصل',
   /p\.assignedBranchId && p\.assignedBranchId !== homeId/.test(TC),
   'الفرعين مايتخلطوش في خانة واحدة');

console.log('\n══ 4) السيرفر بيقبل الحقل ويكتبه ══');
const E = fs.readFileSync('app/Http/Controllers/Api/EntitiesController.php', 'utf8');
const up = E.slice(E.indexOf('public function pilotsUpdate('), E.indexOf('private function stripPilotMoney('));
ok('pilotsUpdate بيقبل homeBranchId', /array_key_exists\('homeBranchId', \$b\)/.test(up));
ok('  وبيقبل assignedBranchId كمرادف قديم', /array_key_exists\('assignedBranchId', \$b\)/.test(up));
ok('  وبيكتب في العمود الصح', /\$fields\[\] = 'home_branch_id = \?';/.test(up));
ok('  وبيتحقق إن الفرع موجود', /\$this->branchName\(\$hb\) === null/.test(up));
/* مشرف الفرع مايقدرش ينقل طيار لفرع تاني — النقل الدائم له مساره وموافقته */
ok('ومشرف الفرع مايقدرش يغيّر الفرع',
   /unset\(\$b\['homeBranchId'\], \$b\['branchId'\], \$b\['assignedBranchId'\]\);/.test(up));

console.log('\n══ 5) فتح الوردية بيحكم بالفرع الثابت مش المُعيّن بس ══');
/* 🔴 البلاغ (2026-09-01): «طيارين ليهم فرع في الصفحة وأجي أفتح لهم
   وردية يقولي ليس لهم فرع». السبب: الصفحة بتعرض الثابت ?? المُعيّن،
   وبوابات فتح الوردية كانت بتفحص المُعيّن **بس** — والطيار الجديد
   اللي عمره ما دخل وردية المُعيّن بتاعه NULL (محمود وسيف، فرع المدير).
   السيرفر (shifts/open) بيحكم بالثابت الأول — الواجهة لازم تطابقه. */
const gate = 'p.homeBranchId ?? p.assignedBranchId';
ok('بوابة زرار «فتح وردية» بتفحص الفرع الحاكم',
   TC.includes('if (!(' + gate + ')) { showToast("لازم الطيار يكون مضافاً لفرع الأول"'),
   'رجعت تفحص المُعيّن بس — الطيار الجديد بيترفض');
ok('لوحة «لم يفتحوا وردية» بتشمل أصحاب الفرع الثابت',
   TC.includes('.filter(p => (' + gate + ') && !p.pilotStatus)'),
   'الطيار الجديد بيختفي من اللوحة');
ok('مودال فتح الوردية بيعرض الفرع الحاكم ويطلب اختيار بس لو مفيش',
   TC.includes('if (!(' + gate + ')) {\n        branchSel.innerHTML'),
   'بيسأل عن الفرع وهو معروف — أو أسوأ: بيعتبره بدون فرع');
ok('  وبيبعت الفرع الحاكم للسيرفر',
   TC.includes('let branchId = ' + gate + ';'));
// 🃏 الجوكر (بلا فرع) داخل القايمة كمان من 2026-09-12 — بيتضاف بفرع بيتختار وقت الإضافة
ok('قايمة إضافة اللوحة بتشمل أصحاب الفرع الثابت والجوكر',
   TC.includes('const inBranch   = pilots.filter(p => (' + gate + ') || !p.pilotStatus);'));
ok('  والإضافة بتبعت الفرع الحاكم (أو المختار للجوكر)',
   TC.includes('let _bId = (' + gate + ') || null;') && TC.includes('await api.post("/api/shifts/open", { pilotId, branchId: _bId });'));

console.log('\n════════════════════════════════════════');
console.log('PILOT BRANCH UI: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
