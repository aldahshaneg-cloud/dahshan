/**
 * 🏙 اختبار «الافتراضي الدقهلية / المنصورة».
 *
 * ═══ ليه اختبار سلوكي مش فحص نصوص ═══
 * الودجت متكرر بالنص جوه ٥ صفحات، والفحص النصي بيعدّي على باج زي اللي
 * حصل فعلًا وأنا بطبّق: الـregex حط التعليم جوه `${i}` بتاع تمبليت،
 * فالتركيب اتكسر والنص كان لسه «موجود». فالاختبار ده بيستخرج الدوال من
 * كل ملف، بيشغّلها على DOM مقلّد، وبيقرا قيمة الحقول بعدها.
 *
 * الحتة اللي لازم تتحرس: العنوان الافتراضي **ممنوع** يظهر وإحنا بنعدّل
 * سجل موجود — لأن الموظف ممكن يحفظ من غير ما ياخد باله فيتخزّن عنوان
 * ما اختارهوش. ده الفرق بين «اقتراح» و«كتابة بيانات من عندنا».
 *
 * التشغيل: node ops/test_defaddr.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (w, c, got) => { c ? (pass++, console.log('  ✓ ' + w))
  : (fail++, console.log('  ✗ ' + w + (got !== undefined ? '   ← ' + got : ''))); };

/* ── DOM مقلّد: بس اللي الودجت بيلمسه ── */
function makeDoc() {
  const nodes = {};
  const mk = (id, tag) => (nodes[id] = {
    id, tagName: tag, value: '', innerHTML: '', _kids: [],
    style: {}, addEventListener() {}, appendChild(o) { this._kids.push(o); },
  });
  return {
    nodes,
    getElementById: id => nodes[id] || null,
    createElement: t => ({ tagName: t.toUpperCase(), value: '', textContent: '' }),
    seed(cid) { mk(cid, 'DIV'); mk(cid + '-gov', 'SELECT'); mk(cid + '-area', 'SELECT'); mk(cid + '-detail', 'INPUT'); },
  };
}

/* بنقص الدوال من الملف ونشغّلها في سياق معزول */
function loadWidget(file, names) {
  const src = fs.readFileSync(file, 'utf8');
  const out = [];
  for (const n of names) {
    const start = src.indexOf(n);
    if (start < 0) throw new Error('مالقيتش ' + n + ' في ' + file);
    /* بداية الجسم = أول `{` بعد ما قوس المعامِلات يقفل. مانقدرش نمسك أول
       `{` على طول لأن `opts = {}` في التوقيع نفسه. */
    let p = src.indexOf('(', start), pd = 0, body = -1;
    for (let j = p; j < src.length; j++) {
      if (src[j] === '(') pd++;
      else if (src[j] === ')') { pd--; if (!pd) { body = src.indexOf('{', j); break; } }
    }
    let depth = 0, end = -1;
    for (let j = body; j < src.length; j++) {
      const c = src[j];
      if (c === '{') depth++;
      else if (c === '}') { depth--; if (!depth) { end = j + 1; break; } }
      else if (c === '`' || c === '"' || c === "'") {           // تخطّي النصوص
        const q = c; j++;
        while (j < src.length && src[j] !== q) { if (src[j] === '\\') j++; j++; }
      }
    }
    out.push(src.slice(start, body) + src.slice(body, end) + ';');
  }
  return out.join('\n');
}

const GOVS = ['القاهرة', 'الدقهلية', 'الجيزة'];
const CITIES = { 'الدقهلية': ['المنصورة', 'طلخا', 'ميت غمر'], 'القاهرة': ['مدينة نصر'] };

function runShared(file) {
  const doc = makeDoc();
  const win = { EG_GOVERNORATES: GOVS, EG_CITIES: CITIES };
  /* الثابت بيتقرا من الملف نفسه مش من الاختبار — عشان لو حد غيّره
     في صفحة واحدة بس، الاختبار يقع بدل ما يعدّي. */
  const decl = fs.readFileSync(file, 'utf8').match(/window\.ADDR_DEFAULT = \{[^}]*\};/);
  if (!decl) throw new Error('مافيش ADDR_DEFAULT في ' + file);
  const code = decl[0] + '\n' +
    loadWidget(file, ['window.renderAddressWidget = function', 'window.fillCities = function']);
  let setCalls = 0;
  win.setAddressValue = () => { setCalls++; };           // مراقب بدل الدالة الحقيقية
  win._updateAddrPreview = () => {};
  new Function('window', 'document', code)(win, doc);
  return {
    render(cid, opts) { doc.seed(cid); win.renderAddressWidget(cid, opts); return doc.nodes; },
    setCalls: () => setCalls,
  };
}

const SHARED = ['branch', 'callcenter', 'store', 'tiar'];

console.log('\n══ 1) سجل جديد — الافتراضي بيظهر ══');
for (const f of SHARED) {
  const n = runShared('public/' + f + '.html').render('t', { required: true });
  ok(f + ': المحافظة = الدقهلية', n['t-gov'].value === 'الدقهلية', n['t-gov'].value || '(فاضي)');
  ok(f + ': المدينة = المنصورة', n['t-area'].value === 'المنصورة', n['t-area'].value || '(فاضي)');
  ok(f + ': قايمة المدن اتملت', /المنصورة/.test(n['t-area'].innerHTML));
}

console.log('\n══ 2) `value: ""` (فورم إضافة) — برضه بيظهر ══');
for (const f of SHARED) {
  const n = runShared('public/' + f + '.html').render('t', { required: true, value: '' });
  ok(f + ': الافتراضي شغّال', n['t-gov'].value === 'الدقهلية', n['t-gov'].value || '(فاضي)');
}

console.log('\n══ 3) 🔴 تعديل سجل موجود — ممنوع نكتب حاجة من عندنا ══');
for (const f of SHARED) {
  const n = runShared('public/' + f + '.html').render('t', { edit: true, value: '' });
  ok(f + ': المحافظة فضلت فاضية', n['t-gov'].value === '', n['t-gov'].value);
  ok(f + ': المدينة فضلت فاضية', n['t-area'].value === '', n['t-area'].value);
}

console.log('\n══ 4) مخرج صريح `blank` ══');
{
  const n = runShared('public/callcenter.html').render('t', { blank: true });
  ok('blank بتلغي الافتراضي', n['t-gov'].value === '', n['t-gov'].value);
}

console.log('\n══ 5) الافتراضي مابيدهسش قيمة محفوظة ══');
{
  const w = runShared('public/callcenter.html');
  w.render('t', { value: 'شارع الجيش، طلخا، الدقهلية' });
  ok('setAddressValue اتندهت (هي اللي بتحكم)', w.setCalls() === 1, w.setCalls());
}

console.log('\n══ 6) customer.html (نسخة مستقلة) ══');
{
  const doc = makeDoc();
  const src = fs.readFileSync('public/customer.html', 'utf8');
  const code = loadWidget('public/customer.html', ['function renderAddr(', 'function fillCities(']);
  let setCalled = false;
  const $ = id => doc.getElementById(id);
  const run = new Function('$', 'esc', 'EG_GOVERNORATES', 'EG_CITIES', 'ADDR_DEFAULT', 'setAddr', 'document',
    code + '\nreturn renderAddr;');
  const decl = src.match(/const ADDR_DEFAULT = ({[^}]*});/);
  if (!decl) throw new Error('مافيش ADDR_DEFAULT في customer.html');
  const renderAddr = run($, s => s, GOVS, CITIES,
    new Function('return ' + decl[1])(), () => { setCalled = true; }, doc);

  doc.seed('c'); renderAddr('c', {});
  ok('عنوان جديد: الدقهلية', doc.nodes['c-gov'].value === 'الدقهلية', doc.nodes['c-gov'].value || '(فاضي)');
  ok('عنوان جديد: المنصورة', doc.nodes['c-area'].value === 'المنصورة', doc.nodes['c-area'].value || '(فاضي)');
  doc.seed('c2'); renderAddr('c2', { value: 'شارع، طلخا، الدقهلية' });
  ok('عنوان محفوظ: setAddr هي اللي بتحكم', setCalled === true);
  doc.seed('c3'); renderAddr('c3', { simple: true });
  ok('simple: مافيش حقل محافظة', doc.nodes['c3-gov'].value === '');
  ok('ADDR_DEFAULT متعرّفة في الملف', /const ADDR_DEFAULT = \{ gov: 'الدقهلية'/.test(src));
}

console.log('\n══ 7) كل نداء بيقرا من سجل معلّم بـ edit ══');
for (const f of SHARED) {
  const s = fs.readFileSync('public/' + f + '.html', 'utf8');
  const re = /renderAddressWidget\([^)]*?value:\s*[a-zA-Z_$][\w$]*[.?][^)]*\)/g;
  let m, miss = 0;
  while ((m = re.exec(s))) if (!/edit:\s*true/.test(m[0])) miss++;
  ok(f + ': مافيش نداء تعديل بلا علامة', miss === 0, miss + ' ناقص');
}

console.log('\n════════════════════════════════════════');
console.log('DEFADDR: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
