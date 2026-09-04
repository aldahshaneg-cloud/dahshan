/**
 * 🏢 اختبار «الفرع يبان باسمه بس» — قوايم اختيار الفروع.
 *
 * ═══ اللسعة ═══
 * قوايم اختيار الفرع كانت بتكتب اسم الفرع + **كل** المناطق المربوطة بيه
 * في نص الـ<option> («حي الجامعة – احمد ماهر، الترعه، مساكن جديلة، …»).
 * في مودال «طلب شحن جديد» ده كان بيطلّع سطر بيعدّي عرض الشاشة ويخفي باقي
 * الاختيارات — المستخدم مابقاش يعرف يقرا أسماء الفروع أصلًا.
 *
 * وكمان: كود كان بيرجّع اسم الفرع بـ`text.split(' –')[0]` — يعني الاسم
 * المتسجّل في الأوردر كان معتمد على شكل النص. دلوقتي من `data-name`.
 *
 * التشغيل: node ops/test_branch_options.cjs
 */
const fs = require('fs');

let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got ? '   ← ' + got : '')); }
};

const PAGES = ['public/tiar.html', 'public/callcenter.html', 'public/branch.html'];

/* بيرجّع سطور الـ<option> بتاعت الفروع بس (اللي جواها `b.id` أو `b.name`) */
const branchOptionLines = (src) =>
  src.split('\n')
     .map((l, i) => [i + 1, l])
     .filter(([, l]) => /<option[^>]*>/.test(l) && /esc\(b\.(id|name)\)/.test(l));

console.log('\n══ 1) مفيش منطقة واحدة في أي قايمة فروع ══');
for (const f of PAGES) {
  const src = fs.readFileSync(f, 'utf8');
  const dirty = branchOptionLines(src).filter(([, l]) => l.includes('b.areas') || /\bb\.area\b/.test(l));
  ok(f + ' — قوايم نضيفة', dirty.length === 0, dirty.map(([n]) => 'سطر ' + n).join('، '));
}

console.log('\n══ 2) القوايم اللي بيتقرا منها الاسم بتحمل data-name ══');
/* مش كل قايمة فروع محتاجة السمة — بس القوايم اللي في كود بيرجّع منها اسم
   الفرع عشان يتسجّل (المنطقة والأوردر). دول لازم يكون عندهم مصدر اسم
   مستقل عن النص المعروض. */
const NEEDS = {
  'public/tiar.html':      ['function _fillBranchSelect', 'function populateBranchSelect', 'if (type === "zone")'],
  'public/callcenter.html':['function _fillBranchSelect', 'function populateBranchSelect', 'if (type === "zone")'],
};
for (const [f, anchors] of Object.entries(NEEDS)) {
  const src = fs.readFileSync(f, 'utf8');
  for (const a of anchors) {
    const at = src.indexOf(a);
    const chunk = at < 0 ? '' : src.slice(at, at + 900);
    const optAt = chunk.indexOf('<option value="${ esc(b.id) }"');
    const optLine = optAt < 0 ? '' : chunk.slice(optAt, chunk.indexOf('</option>', optAt));
    ok(f + ' › ' + a + ' — data-name موجود',
       at >= 0 && optLine.includes('data-name="${ esc(b.name) }"'),
       at < 0 ? 'المرساة مش موجودة' : optLine.slice(0, 120));
  }
}

console.log('\n══ 3) مفيش قراءة لاسم الفرع من نص الـoption ══');
for (const f of PAGES) {
  const src = fs.readFileSync(f, 'utf8');
  const hits = src.split('\n')
    .map((l, i) => [i + 1, l])
    .filter(([, l]) => /\.text[\s\S]{0,20}split\((['"]) –/.test(l) || /\.text \|\| ""\)\.split\(" – "\)/.test(l));
  ok(f + ' — مفيش split على الشرطة', hits.length === 0, hits.map(([n]) => 'سطر ' + n).join('، '));
}

console.log('\n══ 4) _branchNameOf معرَّف في نفس كتلة الـscript اللي بتنده عليه ══');
/* رفع الدوال بيشتغل جوه الكتلة بس — تعريف في كتلة ونداء من كتلة تانية
   بيقع ReferenceError وقت التشغيل، ومفيش فحص تركيب بيمسكه. */
for (const f of PAGES) {
  const src = fs.readFileSync(f, 'utf8');
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0; const def = [], use = [];
  while ((m = re.exec(src)) !== null) {
    i++;
    const body = m[2] || '';
    const isDef = body.includes('function _branchNameOf');
    if (isDef) def.push(i);
    if ((body.split('_branchNameOf(').length - 1) > (isDef ? 1 : 0)) use.push(i);
  }
  ok(f + ' — النطاق سليم',
     use.length === 0 || (def.length === 1 && use.every(b => b === def[0])),
     'تعريف=' + JSON.stringify(def) + ' نداء=' + JSON.stringify(use));
}

console.log('\n══ 5) الجدول لسه بيعرض المناطق — دي مش المشكلة ══');
/* عمود «المنطقة» في جدول الفروع مكانه الصح: عنوان العمود بيفسّره.
   لو اتشال بالغلط مع القوايم يبقى ضاعت معلومة كان لازم تفضل. */
for (const f of ['public/tiar.html', 'public/callcenter.html']) {
  const src = fs.readFileSync(f, 'utf8');
  ok(f + ' — عمود المناطق موجود', /<td[^>]*>\$\{ esc\(b\.areas/.test(src));
}

console.log('\n══ 6) القوايم اللي كانت نضيفة من الأصل ما اتلخبطتش ══');
for (const f of PAGES) {
  const src = fs.readFileSync(f, 'utf8');
  ok(f + ' — الاسم لسه بيتطبع', /esc\(b\.name\) \}<\/option>/.test(src));
}

console.log('\n══ 7) علامة «موقوف» ما اتشالتش مع المناطق ══');
/* الموظف لازم يشوف إن الفرع موقوف **قبل** ما يختاره — العلامة دي
   معلومة تشغيلية، مش زي المناطق. */
for (const f of ['public/tiar.html', 'public/callcenter.html']) {
  const src = fs.readFileSync(f, 'utf8');
  ok(f + ' — «⏸️ (موقوف)» لسه في القايمة', src.includes('esc(b.paused ? "⏸️ (موقوف) " : "")'));
}

console.log('\n══ 8) اسم الفرع مابيتقراش من نص الـoption خالص ══');
/* في لوحة الكول سنتر كان `addOrder` بياخد نص الـoption زي ما هو — يعني
   الأوردر كان بيتسجّل باسم فرع فيه كل مناطقه («حي الجامعة – احمد ماهر،
   الترعه، …») وكمان علامة «⏸️ (موقوف)». الاسم ده بيبان بعد كده في
   الفرع والطيار والتقارير ومفيش حاجة بتنضّفه. */
for (const f of PAGES) {
  const src = fs.readFileSync(f, 'utf8');
  const hits = src.split('\n')
    .map((l, i) => [i + 1, l])
    .filter(([, l]) => /branchName\s*=/.test(l) && /options\[[^\]]*selectedIndex\]/.test(l) && !/dataset/.test(l));
  ok(f + ' — مفيش قراءة خام', hits.length === 0, hits.map(([n]) => 'سطر ' + n).join('، '));
}

console.log('\n════════════════════════════════════════');
console.log('BRANCH OPTIONS: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
