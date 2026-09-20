/**
 * 🛡️ حارس: النافذة اللي فيها خانات إدخال مابتتقفلش بالضغط برّه.
 *
 * البلاغ (صاحب النظام 2026-09-20): «الفورم بتاع عمل طلب جديد على مستوى السيستم بيقفل بمجرد
 * الضغط خارج الفورم وده بيضيّع الشغل اللي اتعمل، وكذلك عند تعديل البيانات».
 * كل غطاء كان عليه `if(event.target===this) closeModal(...)` — ضغطة غلط برّه = الفورم كله راح.
 *
 * العقد: أي قفل بالضغط على الخلفية لازم يعدّي على `window._hasFormFields` — لو النافذة فيها
 * input/textarea/select بتفضل مفتوحة (وبيظهر تلميح)، ونوافذ العرض بس بتتقفل زي الأول.
 *
 * التشغيل: node ops/test_modal_backdrop.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => { if (cond) { pass++; console.log('  ✓ ' + what); } else { fail++; console.log('  ✗ ' + what + (got ? '   ← ' + got : '')); } };

const pages = fs.readdirSync('public').filter(f => f.endsWith('.html'));
let guarded = 0;
for (const f of pages) {
  const S = fs.readFileSync('public/' + f, 'utf8');
  const raw = [
    ...(S.match(/onclick="if\s*\(event\.target\s*===\s*this\)/g) || []),
    /* نافذة باركود بوابة المحل (closeCodesBox) عرض بس ومتثبّتة في test_store_draft_persist — مستثناة عن قصد */
    ...(S.match(/if \(e\.target === box\) (box\.remove|\{ box\.remove)/g) || []),
    ...(S.match(/if \(e\.target === \$\("modal"\)\) closeM\(\)/g) || []),
    ...(S.match(/if \(e\.target\.id === "modal"\) closeModal\(\)/g) || []),
  ];
  const safe = (S.match(/!window\._hasFormFields\(/g) || []).length;
  if (!raw.length && !safe) continue;
  guarded += safe;
  ok(f + ': 🔴 مفيش قفل بالخلفية من غير فحص الخانات', raw.length === 0, raw.length + ' موضع مكشوف');
  if (safe) ok('  ' + f + ': والدالة في الـhead الحقيقي مش جوه قالب طباعة', /\};\n<\/script>\n<\/head>\s*<body/.test(S));
  if (safe) ok('  ' + f + ': _hasFormFields معرّفة في الصفحة (' + safe + ' موضع محمي)', S.includes('window._hasFormFields = function (el) {'));
}
ok('فيه مواضع محمية فعلًا (≥ 50)', guarded >= 50, String(guarded));

/* السلوك: تنفيذ الدالة الحقيقية على DOM مبسّط */
const B = fs.readFileSync('public/branch.html', 'utf8');
const a = B.indexOf('window._hasFormFields = function (el) {');
const z = B.indexOf('</script>', a);
ok('الدالة موجودة في الفرع', a > 0 && z > a);
if (a > 0 && z > a) {
  const win = {};
  const doc = { getElementById: () => ({ style: {} }), createElement: () => ({ style: {} }), body: { appendChild() {} } };
  new Function('window', 'document', 'setTimeout', 'clearTimeout', B.slice(a, z))(win, doc, () => 0, () => {});
  ok('  نافذة فيها خانة = ممنوع القفل', win._hasFormFields({ querySelector: () => ({}) }) === true);
  ok('  نافذة عرض بس = بتتقفل', win._hasFormFields({ querySelector: () => null }) === false);
  ok('  عنصر مش موجود = مابترميش', win._hasFormFields(null) === false);
}
for (const p of ['branch', 'tiar', 'callcenter']) {
  const S = fs.readFileSync('public/' + p + '.html', 'utf8');
  ok(p + ': فورم الأوردر الجديد محمي', S.includes('<div id="modal-order" class="modal-overlay" onclick="if(event.target===this&&!window._hasFormFields(this)) closeModal(\'order\')">'));
}

/* 🪟 تسجيل عميل جديد من جوه فورم الأوردر = فورم عائم مستقل (طلب صاحب النظام 2026-09-20):
   كان بيترسم جوه قايمة البحث (dropdown) فبيختفي بالمكتوب مع أي ضغطة برّه. */
console.log('\n══ فورم تسجيل العميل العائم ══');
for (const p of ['branch', 'tiar', 'callcenter']) {
  const S = fs.readFileSync('public/' + p + '.html', 'utf8');
  const a = S.indexOf("      addNewBtn.addEventListener('click', function() {");
  const blk = a > 0 ? S.slice(a, S.indexOf('      function showDropdown()', a)) : '';
  ok(p + ': 🔴 الفورم مابيترسمش جوه قايمة البحث', blk !== '' && !blk.includes('dropdown.innerHTML'));
  ok('  ' + p + ': عائم على body فوق فورم الأوردر', blk.includes('qaBox.id = "_quickAddBox";') && blk.includes('document.body.appendChild(qaBox);') && /z-index:10050/.test(blk));
  ok('  ' + p + ': 🔴 مفيش أي قفل بالضغط على الخلفية', !/qaBox\.(onclick|addEventListener)/.test(blk));
  ok('  ' + p + ': القفل من ✕ وإلغاء وبعد الحفظ الناجح بس',
     blk.includes("-qclose`).addEventListener('click', closeQuickAdd);") && blk.includes("-qcancel`).addEventListener('click', closeQuickAdd);")
     && /selectItem\([^\n]*\);\n\s+closeQuickAdd\(\);/.test(blk));
  ok('  ' + p + ': الاسم المكتوب بيتنقل للفورم متأمّن (esc)', blk.includes('value="${ esc(name) }"'));
}

console.log('\n════════════════════════════════════════');
console.log('MODAL BACKDROP: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail ? 1 : 0);
