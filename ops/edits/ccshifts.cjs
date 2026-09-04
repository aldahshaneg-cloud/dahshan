/* شيل جزيرة «ورديات الطيارين» الميّتة من callcenter.html.
 *
 * ═══ الحكاية ═══
 * صفحتين — `page-shifts` و`page-shift-orders` — ورثهم الكول سنتر لما
 * اتبنى بنسخ من لوحة الإدارة. مافيش أي زرار في السايدبار بيوصلهم:
 * القايمة فيها ١٢ صفحة بس (`ccsearch` … `ccperf`) ومفيهاش `shifts`.
 * الـ`navigateTo('shifts')` الوحيد في الملف زرار «رجوع للورديات» جوه
 * `page-shift-orders` نفسها — يعني الجزيرة بتوصل لنفسها بس.
 *
 * ═══ ليه بنشيلها دلوقتي ═══
 * (١) قرار صاحب النظام 2026-08-30: الكول سنتر «تفاصيل وشكوى بس». الأزرار
 *     المكسورة اتشالت يومها، لكن الجزيرة دي فاتت من المسح لأنها مش في
 *     السايدبار — فالفحص اللي بيمشي على القايمة ماشافهاش.
 * (٢) جوّاها زرار «🔴 إنهاء الوردية» شكله شغّال، مربوط بـ`adminEndPilotShift`
 *     → مودال تسوية → `POST /api/shifts/{id}/end`. المسار ده
 *     `role:admin,branch` — يعني الكول سنتر هياخد 403 لو وصله. مش خطر
 *     تنفيذي، لكنه **بيغش التدقيق**: مسح للكود قرا الجزيرة كأنها طريق
 *     حقيقي لإنهاء الوردية عند الكول سنتر.
 *
 * ═══ اللي **مش** بنلمسه ═══
 * بولّر `/api/shifts` فاضل. `window._shiftsData` لسه بيتقرا من
 * `findActivePilotShift` و`buildMonthlyCloseoutData` — والاتنين بقايا
 * ميّتة **من قبل** المسح ده (كانوا ميّتين قبل ما نلمس الملف)، فشيلهم
 * شغل تاني بقرار منفصل. سيبنا البولّر عشان التغيير يفضل مقروء.
 *
 * ═══ ليه سكريبت مش تعديل بالإيد ═══
 * ٢٦ دالة + بلوكين HTML + ٤ نتف متفرّقة. التعديل اليدوي على ملف ٧٠٠ كيلو
 * بيسيب نص دالة. السكريبت ده:
 *   • بيمسك حدود كل دالة بماسح بيفهم التعليقات والسترنجات والـtemplate
 *     literals المتداخلة والـregex — مش بعدّ أقواس ساذج.
 *   • بيتأكد إن كل قطعة **لوحدها** بتعدّي على `node --check`، وإن الملف
 *     **بعد** الشيل بيعدّي كمان. القطعة سليمة + الباقي سليم = القص مظبوط.
 *   • بيفحص كل حاجة **قبل** ما يكتب أي بايت.
 *   • بيحافظ على نهايات السطور الأصلية (الملف ده LF، بس الحارس عام).
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILE = path.resolve(__dirname, '../../public/callcenter.html');

const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');
const ORIGINAL = s;

const problems = [];
const note = m => console.log('   ' + m);

/* ══════════════════════════════════════════════════════════════════
   ١) ماسح الجافاسكربت — يلاقي القوس اللي بيقفل بلوك
   ══════════════════════════════════════════════════════════════════
   الماسح الساذج (اللي في ccdead.cjs) بيقع على `${active ? `<span>…`}`
   — باك-تيك جوه باك-تيك. وده موجود فعلاً في renderShiftsPage. فالماسح
   هنا بيمسك سياقات متداخلة بمكدس. */

const REGEX_KEYWORDS = new Set(['return', 'typeof', 'case', 'in', 'of', 'delete',
  'void', 'instanceof', 'new', 'do', 'else', 'yield', 'await', 'throw']);

/* هل الشرطة المايلة دي بداية regex ولا قسمة؟ القاعدة القياسية: بعد
   قيمة (اسم/رقم/`)`/`]`/سترنج) = قسمة، غير كده = regex. */
function regexAllowed(prev, prevWord) {
  if (!prev) return true;
  if (/[A-Za-z0-9_$)\]"'`}]/.test(prev)) return REGEX_KEYWORDS.has(prevWord);
  return true;
}

function skipQuoted(src, i) {
  const q = src[i]; i++;
  while (i < src.length) {
    if (src[i] === '\\') { i += 2; continue; }
    if (src[i] === q) return i + 1;
    if (src[i] === '\n') return i;          // سترنج مش مقفول — نوقف عند السطر
    i++;
  }
  return i;
}

function skipRegex(src, i) {
  i++; let inClass = false;
  while (i < src.length) {
    const c = src[i];
    if (c === '\\') { i += 2; continue; }
    if (c === '[') inClass = true;
    else if (c === ']') inClass = false;
    else if (c === '/' && !inClass) { i++; while (/[a-z]/i.test(src[i] || '')) i++; return i; }
    else if (c === '\n') return i;
    i++;
  }
  return i;
}

/* بيرجّع الفهرس **بعد** القوس اللي بيقفل الـ`{` اللي في openIdx */
function findBlockEnd(src, openIdx) {
  if (src[openIdx] !== '{') throw new Error('findBlockEnd: مش قوس فتح عند ' + openIdx);
  const stack = ['brace'];
  let i = openIdx + 1, prev = '{', prevWord = '';

  while (i < src.length) {
    if (stack[stack.length - 1] === 'tpl') {
      const c = src[i];
      if (c === '\\') { i += 2; continue; }
      if (c === '`') { stack.pop(); i++; prev = '`'; prevWord = ''; continue; }
      if (c === '$' && src[i + 1] === '{') { stack.push('brace'); i += 2; prev = '{'; prevWord = ''; continue; }
      i++; continue;
    }

    const c = src[i];
    if (c === ' ' || c === '\t' || c === '\n' || c === '\r') { i++; continue; }
    if (c === '/' && src[i + 1] === '/') { const e = src.indexOf('\n', i); i = e < 0 ? src.length : e; continue; }
    if (c === '/' && src[i + 1] === '*') { const e = src.indexOf('*/', i + 2); i = e < 0 ? src.length : e + 2; continue; }
    if (c === '"' || c === "'") { i = skipQuoted(src, i); prev = '"'; prevWord = ''; continue; }
    if (c === '`') { stack.push('tpl'); i++; continue; }
    if (c === '/' && regexAllowed(prev, prevWord)) { i = skipRegex(src, i); prev = '/'; prevWord = ''; continue; }
    if (c === '{') { stack.push('brace'); i++; prev = '{'; prevWord = ''; continue; }
    if (c === '}') {
      stack.pop(); i++;
      if (!stack.length) return i;
      prev = '}'; prevWord = '';
      continue;
    }
    if (/[A-Za-z0-9_$]/.test(c)) {
      let j = i; while (j < src.length && /[A-Za-z0-9_$]/.test(src[j])) j++;
      prevWord = src.slice(i, j); prev = src[j - 1]; i = j; continue;
    }
    prev = c; prevWord = ''; i++;
  }
  return -1;
}

/* كل قطعة بتتشال لازم تعدّي **لوحدها** على محلّل نود. */
let checkSeq = 0;
function parsesAlone(chunk, label) {
  const tmp = path.join(os.tmpdir(), 'ccshifts-' + process.pid + '-' + (checkSeq++) + '.mjs');
  try {
    /* من غير أي مقدّمة: `node --check` تحليل تركيبي بحت، مابيحلّش أسماء.
       المقدّمة كانت بتصطدم بـ`const api` الحقيقي في كتلة تانية. */
    fs.writeFileSync(tmp, chunk + '\n');
    execFileSync(process.execPath, ['--check', tmp], { stdio: 'pipe' });
    return null;
  } catch (e) {
    const out = (e.stderr ? e.stderr.toString() : '') || e.message;
    const m = out.match(/SyntaxError:.*/);
    return (m ? m[0] : out.split('\n')[0]).trim();
  } finally { try { fs.unlinkSync(tmp); } catch (e) {} }
}

/* التعليقات اللي فوق التعريف على طول بتتشال معاه. بنرجع لورا سطر سطر
   طول ما السطر تعليق (`//` أو جزء من `/* … *\/`) أو فاضي بين تعليقين. */
function commentHeadStart(src, defStart) {
  const lines = src.slice(0, defStart).split('\n');
  lines.pop();                                  // السطر اللي فيه التعريف
  let k = lines.length;
  while (k > 0) {
    const t = lines[k - 1].trim();
    if (t.startsWith('//')) { k--; continue; }
    if (t.endsWith('*/')) {                     // نبلع بلوك التعليق كله
      let j = k - 1;
      while (j > 0 && !lines[j].includes('/*')) j--;
      if (!lines[j].includes('/*')) break;
      k = j; continue;
    }
    break;
  }
  if (k === lines.length) return defStart;
  return lines.slice(0, k).join('\n').length + (k ? 1 : 0);
}

/* بيشيل تعريف واحد بالاسم. anchor = بداية التعريف بالنص.
 * headAnchor (اختياري): أول سطر تعليق **يخصّ الدالة دي**. من غيره بنرجع
 * لورا لحد ما التعليقات تخلص — وده بيبلع في حتة زي `adminEndPilotShift`
 * تعليقات يتيمة من مسح 2026-08-30 بتوصف دوال تانية خالص. */
function cutDef(anchor, label, headAnchor) {
  const n = s.split(anchor).length - 1;
  if (n !== 1) { problems.push(`«${label}»: متوقّع ١ لقى ${n} — «${anchor}»`); return; }
  const start = s.indexOf(anchor);
  const open = s.indexOf('{', start + anchor.length - 1);
  if (open < 0) { problems.push(`«${label}»: مالقيتش قوس الفتح`); return; }
  const rawEnd = findBlockEnd(s, open);
  if (rawEnd < 0) { problems.push(`«${label}»: القوس مش مقفول`); return; }

  let end = rawEnd;
  if (s[end] === ';') end++;
  while (s[end] === '\n') end++;

  let head;
  if (headAnchor) {
    const hn = s.split(headAnchor).length - 1;
    if (hn !== 1) { problems.push(`«${label}»: headAnchor متوقّع ١ لقى ${hn}`); return; }
    head = s.indexOf(headAnchor);
    if (head > start) { problems.push(`«${label}»: headAnchor بعد التعريف`); return; }
    head = s.lastIndexOf('\n', head) + 1;
  } else {
    head = commentHeadStart(s, start);
  }

  const chunk = s.slice(start, rawEnd + (s[rawEnd] === ';' ? 1 : 0));
  const err = parsesAlone(chunk, label);
  if (err) { problems.push(`«${label}»: القطعة مش سليمة تركيبيًا — ${err}`); return; }

  cuts.push({ label, head, end, lines: s.slice(head, end).split('\n').length - 1 });
}

/* بيشيل جملة/سطر بالنص الكامل بتاعه. keep = نص بيترجّع مكانه (عشان
 * السطور اللي جوّاها حاجة عايزين نسيبها). */
function cutText(text, label, keep = '') {
  const n = s.split(text).length - 1;
  if (n !== 1) { problems.push(`«${label}»: متوقّع ١ لقى ${n}`); return; }
  const start = s.indexOf(text);
  let end = start + text.length;
  if (!keep) while (s[end] === '\n') end++;
  cuts.push({ label, head: start, end, ins: keep, lines: s.slice(start, end).split('\n').length - (keep.split('\n').length - 1) - 1 });
}

/* بيشيل بلوك HTML: من سطر التعليق لحد الـ`</div>` اللي بيقفل الصفحة. */
function cutPage(commentText, pageId) {
  const cIdx = s.indexOf(commentText);
  if (cIdx < 0) { problems.push(`«${pageId}»: مالقيتش التعليق`); return; }
  const divOpen = `<div class="page" id="${pageId}">`;
  const dIdx = s.indexOf(divOpen, cIdx);
  if (dIdx < 0 || dIdx - cIdx > 200) { problems.push(`«${pageId}»: التعليق مش ملزوق بالـdiv`); return; }
  if (s.split(divOpen).length - 1 !== 1) { problems.push(`«${pageId}»: الـdiv مش مرة واحدة`); return; }

  /* عدّ التداخل — الجزء ده HTML صافي، مافيش سكربت جوّاه */
  let depth = 0, i = dIdx, end = -1;
  const re = /<div\b|<\/div>/g;
  re.lastIndex = dIdx;
  let m;
  while ((m = re.exec(s)) !== null) {
    depth += m[0] === '</div>' ? -1 : 1;
    if (depth === 0) { end = m.index + m[0].length; break; }
  }
  if (end < 0) { problems.push(`«${pageId}»: الـdiv مش مقفول`); return; }

  const head = s.lastIndexOf('\n', cIdx) + 1;      // من أول السطر
  /* بنبلع باقي السطر + أي سطور فاضية بعده. السطر الفاضي اللي **قبل**
     التعليق بيفضل، فالفاصل بين الصفحتين اللي حواليها مابيضيعش. */
  let tail = end;
  while (s[tail] === ' ' || s[tail] === '\t') tail++;
  if (s[tail] === '\n') tail++;
  for (;;) {
    const nl = s.indexOf('\n', tail);
    if (nl < 0 || !/^\s*$/.test(s.slice(tail, nl))) break;
    tail = nl + 1;
  }
  cuts.push({ label: pageId + ' (HTML)', head, end: tail, lines: s.slice(head, tail).split('\n').length - 1 });
}

/* ══════════════════════════════════════════════════════════════════
   ٢) فحوص قبلية — لو أي واحد وقع، مافيش بايت واحد بيتكتب
   ══════════════════════════════════════════════════════════════════ */
console.log('\n══ فحوص قبلية ══');
{
  const navBlock = s.slice(s.indexOf('<nav class="sidebar-nav">'), s.indexOf('</nav>', s.indexOf('<nav class="sidebar-nav">')));
  const navPages = [...navBlock.matchAll(/navigateTo\('([\w-]+)'\)/g)].map(m => m[1]);
  const inNav = navPages.includes('shifts') || navPages.includes('shift-orders');
  console.log((inNav ? '  ✗' : '  ✓') + ' السايدبار مافيهوش الورديات   (' + navPages.length + ' صفحة: ' + navPages.join(', ') + ')');
  if (inNav) problems.push('السايدبار فيه رابط للورديات — الجزيرة مش ميتة!');

  /* النداءات دي كلها المفروض تختفي مع القص. الإثبات الحقيقي مش هنا —
     هو الفحص البعدي «صفر نداء فاضل». هنا بنسجّل الحالة قبل بس. */
  const navs = [...s.matchAll(/navigateTo\(\s*['"](shifts|shift-orders)['"]\s*\)/g)];
  console.log('  · نداءات navigateTo للورديات قبل القص: ' + navs.length);
  for (const nv of navs) note('سطر ' + s.slice(0, nv.index).split('\n').length + ': ' + nv[0]);
}

/* ══════════════════════════════════════════════════════════════════
   ٣) القصّات
   ══════════════════════════════════════════════════════════════════ */
const cuts = [];

/* ── HTML: الصفحتين ── */
cutPage('<!-- صفحة ورديات الطيارين -->', 'page-shifts');
cutPage('<!-- صفحة تفاصيل طلبات وردية واحدة -->', 'page-shift-orders');

/* ── نتف متفرّقة ──
   السطر بتاع «ارسم صفحة الورديات لو هي المفتوحة» موجود في البولّرين،
   فالنص لوحده مش فريد — بنثبّته بالسطر اللي قبله في كل حالة. */
cutText('      if (window.renderAccountingPage) window.renderAccountingPage();\n'
      + '      if (document.getElementById("page-shifts")?.classList.contains("active") && window.renderShiftsPage) renderShiftsPage();\n',
        'نداء الرسم من بولّر الطيارين',
        '      if (window.renderAccountingPage) window.renderAccountingPage();\n');
cutText('      window._shiftsData = nrmList(d.items);\n'
      + '      if (document.getElementById("page-shifts")?.classList.contains("active") && window.renderShiftsPage) renderShiftsPage();\n'
      + '      if (document.getElementById("page-shift-orders")?.classList.contains("active") && window.renderShiftOrdersPage) renderShiftOrdersPage();\n',
        'نداءات الرسم من بولّر الورديات',
        '      window._shiftsData = nrmList(d.items);\n');
cutText('    if (page === "shifts" && window.onShiftsPeriodChange) window.onShiftsPeriodChange();\n',
        'hook الورديات في navigateTo');
cutText('    /* ── بحث ذكي عن الطيار داخل فلتر الورديات ──────────────────────── */\n    window._shiftsPilotFilterId = "";\n',
        'المتغيّر _shiftsPilotFilterId');
/* تعليقات يتيمة اتساب فوقها دوال اتشالت في مسح 2026-08-30، وبتوصف
   شاشات بتتشال دلوقتي — فبتفضل بتوصف حاجة مش موجودة. */
cutText('    /* ── تطبيق وحفظ بونص/خصم إضافي على وردية الطيار ────────────────────\n       بيتحفظ في سجل الوردية نفسه (shifts/{id}) وبيتحدّث فورًا في نافذة\n       التقرير وأي تصدير/طباعة/رسالة واتساب لاحقة لنفس التقرير ────────── */\n',
        'تعليق يتيم: بونص/خصم الوردية');
/* «// يفتح التقفيلة الشهرية للطيار المختار من فلتر صفحة الورديات» بيتشال
   مع viewOldShiftReport تحت (هو الـheadAnchor بتاعها). */

/* ── مصفوفة PAGES ── */
cutText('"shifts","shift-orders",', 'PAGES: الورديات');

/* ── الدوال ── */
const DEFS = [
  ['    function fmtShiftDT(iso) {',                          'fmtShiftDT'],
  ['    function fmtShiftDuration(ms) {',                     'fmtShiftDuration'],
  ['    window.onShiftsPeriodChange = function() {',          'onShiftsPeriodChange'],
  ['    function getShiftsDateRange() {',                     'getShiftsDateRange'],
  ['    function renderNotOpenedShiftPanel() {',              'renderNotOpenedShiftPanel'],
  ['    window.renderShiftsPage = function() {',              'renderShiftsPage'],
  ['    function highlightShiftsPilotMatch(text, q) {',       'highlightShiftsPilotMatch'],
  ['    window.filterShiftsPilot = function(inp) {',          'filterShiftsPilot'],
  ['    window.selectShiftsPilot = function(id, name) {',     'selectShiftsPilot'],
  ['    window.clearShiftsPilotFilter = function() {',        'clearShiftsPilotFilter'],
  ['    window.openShiftOrders = function(shiftId) {',        'openShiftOrders'],
  ['    window.renderShiftOrdersPage = function() {',         'renderShiftOrdersPage'],
  /* فوق adminEndPilotShift فيه ٧ سطور تعليق يتيمة بتوصف دوال تانية
     (الموافقة على الإرجاع · فرض إذن) اتشالت في مسح 2026-08-30. مش شغلنا،
     فبنثبّت أول سطر تعليق يخصّ الدالة دي بالذات. */
  ['    window.adminEndPilotShift = async function(shiftId, pilotId) {', 'adminEndPilotShift',
   '// إنهاء وردية الطيار بالكامل من لوحة الإدارة (المشرف)'],
  ['    function openShiftEndSettlementModal(shiftId, pilot, activeOrders, pendingSettlementOrders) {', 'openShiftEndSettlementModal'],
  ['    async function finalizeShiftEnd(shiftId, pilot, settlementBody) {', 'finalizeShiftEnd'],
  ['    function buildShiftReportData(shift, pilot) {',       'buildShiftReportData'],
  ['    function _settleRadiosHtml(name, cur) {',             '_settleRadiosHtml'],
  ['    function _srSettleVal(name) {',                       '_srSettleVal'],
  ['    window.recomputeShiftDue = function() {',             'recomputeShiftDue'],
  ['    window.showShiftReport = function(shift, pilot) {',   'showShiftReport'],
  /* التعليق اليتيم بتاع «التقفيلة الشهرية من فلتر صفحة الورديات» ملزوق
     فوقها، وبيوصف مدخلًا من الصفحة اللي بتتشال — فبيروح معاها. */
  ['    window.viewOldShiftReport = function(shiftId) {',     'viewOldShiftReport',
   '// يفتح التقفيلة الشهرية للطيار المختار من فلتر صفحة الورديات'],
  ['    window.printShiftReport = function() {',              'printShiftReport'],
  ['    window.exportShiftReportExcel = function() {',        'exportShiftReportExcel'],
  ['    function buildShiftReportWhatsAppText(data) {',       'buildShiftReportWhatsAppText'],
  ['    function _toWhatsAppNumber(phone) {',                 '_toWhatsAppNumber'],
  ['    window.sendShiftReportWhatsApp = function() {',       'sendShiftReportWhatsApp'],
];
for (const [anchor, label, headAnchor] of DEFS) cutDef(anchor, label, headAnchor);

/* ── مستمع الكليك بتاع دروب-داون فلتر الطيار (مالوش اسم) ── */
{
  const anchor = '    document.addEventListener("click", function(e) {\n      const drop = document.getElementById("shiftsPilotDropdown");';
  const n = s.split(anchor).length - 1;
  if (n !== 1) problems.push(`«مستمع دروب-داون الورديات»: متوقّع ١ لقى ${n}`);
  else {
    const start = s.indexOf(anchor);
    const open = s.indexOf('{', s.indexOf('function(e)', start));
    const rawEnd = findBlockEnd(s, open);
    let end = rawEnd;
    while (s[end] === ')' || s[end] === ';') end++;
    const chunk = s.slice(start, end);
    const err = parsesAlone(chunk, 'مستمع دروب-داون');
    if (err) problems.push('«مستمع دروب-داون الورديات»: القطعة مش سليمة — ' + err);
    else {
      while (s[end] === '\n') end++;
      cuts.push({ label: 'مستمع دروب-داون الورديات', head: start, end, lines: s.slice(start, end).split('\n').length - 1 });
    }
  }
}

/* ══════════════════════════════════════════════════════════════════
   ٤) تحقّق من عدم التداخل، وبعدين القص
   ══════════════════════════════════════════════════════════════════ */
cuts.sort((a, b) => a.head - b.head);
for (let i = 1; i < cuts.length; i++)
  if (cuts[i].head < cuts[i - 1].end)
    problems.push(`تداخل: «${cuts[i - 1].label}» مع «${cuts[i].label}»`);

if (problems.length) {
  console.log('\n⛔ فحوص وقعت — مافيش بايت اتكتب:');
  for (const p of problems) console.log('   ✗ ' + p);
  process.exit(1);
}

const lineOf = i => s.slice(0, i).split('\n').length;
console.log('\n══ القصّات (' + cuts.length + ') ══');
let totalLines = 0;
for (const c of cuts) {
  totalLines += c.lines;
  const a = lineOf(c.head), b = lineOf(c.end) - 1;
  console.log('  ✓ ' + c.label.padEnd(32) + String(a).padStart(5) + '–' + String(b).padEnd(6) + c.lines + ' سطر');
  if (process.env.DRY) {
    const body = s.slice(c.head, c.end).replace(/\n+$/, '').split('\n');
    console.log('        ┌ ' + body[0].trim().slice(0, 96));
    if (body.length > 1) console.log('        └ ' + body[body.length - 1].trim().slice(0, 96));
  }
}

let out = '';
let pos = 0;
for (const c of cuts) { out += s.slice(pos, c.head) + (c.ins || ''); pos = c.end; }
out += s.slice(pos);

/* ══════════════════════════════════════════════════════════════════
   ٥) فحوص بعدية على النتيجة — قبل الكتابة
   ══════════════════════════════════════════════════════════════════ */
console.log('\n══ فحوص بعدية ══');
const after = [];
const NAMES = [...DEFS.map(d => d[1]), 'page-shifts', 'page-shift-orders',
  '_shiftsPilotFilterId', '_shiftsPilotList', '_currentShiftId', '_lastShiftReportData',
  'shiftsPilotDropdown', 'notOpenedShiftBox', 'shiftOrdersBody', 'shiftsBody'];
for (const n of NAMES) {
  const hits = (out.match(new RegExp('\\b' + n.replace(/-/g, '\\-') + '\\b', 'g')) || []).length;
  if (hits) after.push(`لسه فيه ${hits} ذكر لـ«${n}»`);
}
console.log((after.length ? '  ✗' : '  ✓') + ' مافيش أي أثر فاضل للجزيرة');
for (const a of after) console.log('     ' + a);

/* الإثبات إن الجزيرة كانت مقفولة على نفسها: مافيش أي نداء فاضل */
{
  const left = [...out.matchAll(/navigateTo\(\s*['"](shifts|shift-orders)['"]\s*\)/g)];
  console.log((left.length ? '  ✗' : '  ✓') + ' صفر navigateTo للورديات (كانوا ٢)');
  if (left.length) after.push(left.length + ' نداء navigateTo فاضل — الجزيرة كانت ليها مدخل من برّه!');
}

/* الملف بعد الشيل لازم يعدّي على محلّل نود */
{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, bad = 0;
  while ((m = re.exec(out)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '')) continue;
    if (!(m[2] || '').trim()) continue;
    const err = parsesAlone(m[2], 'block' + i);
    if (err) { bad++; console.log('  ✗ كتلة ' + i + ': ' + err); }
  }
  console.log((bad ? '  ✗' : '  ✓') + ' كل كتل الجافاسكربت سليمة تركيبيًا (' + i + ' كتلة)');
  if (bad) after.push('كتل جافاسكربت مكسورة');
}

/* توازن الـdiv في الجسم — قص HTML غلط بيبان هنا */
{
  const body = out.slice(out.indexOf('<body'), out.indexOf('</body>'));
  const opens = (body.match(/<div\b/g) || []).length;
  const closes = (body.match(/<\/div>/g) || []).length;
  const beforeBody = ORIGINAL.slice(ORIGINAL.indexOf('<body'), ORIGINAL.indexOf('</body>'));
  const d0 = (beforeBody.match(/<div\b/g) || []).length - (beforeBody.match(/<\/div>/g) || []).length;
  const d1 = opens - closes;
  console.log((d0 === d1 ? '  ✓' : '  ✗') + ` توازن <div> زي ما كان (${d0} → ${d1})`);
  if (d0 !== d1) after.push('توازن الـdiv اتغيّر');
}

if (after.length) {
  console.log('\n⛔ فحوص بعدية وقعت — مافيش بايت اتكتب.');
  process.exit(1);
}

if (process.env.DRY) { console.log('\n🟦 DRY — كل الفحوص عدّت، بس مافيش بايت اتكتب.'); process.exit(0); }

fs.writeFileSync(FILE, WAS_CRLF ? out.replace(/\n/g, '\r\n') : out);
console.log('\n✓ اتكتب ' + path.basename(FILE) + (WAS_CRLF ? '  (CRLF)' : '  (LF)'));
console.log('  ' + ORIGINAL.split('\n').length + ' سطر → ' + out.split('\n').length + ' سطر  (−' + totalLines + ')');
