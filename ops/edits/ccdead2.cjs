/* شيل الكود الميت القديم من callcenter.html — اللي مالوش علاقة بجزيرة
 * الورديات، وكان ميّت **قبل** مسح 2026-08-31.
 *
 * ═══ منين جم ═══
 * الملف اتبنى بنسخ لوحة الإدارة. كل مسح للأزرار (2026-08-23 و2026-08-30
 * و2026-08-31) كان بيشيل الزرار وبيسيب الدالة. الناتج: ٢٥ دالة ذكرها
 * الوحيد في الملف هو تعريفها.
 *
 * ═══ ليه `deadscan.cjs` مالقاهاش ═══
 * الحارس بتاعه فيه شرط `\bالاسم\s*\(` على الخام عشان يمسك النداء من
 * `onclick`. بس **التعريف نفسه** بيطابق الشرط ده — `function _mcNum(id)`
 * فيه `_mcNum(`. فكل دالة ذكرها الوحيد تعريفها بتتحسب «مستعملة».
 * الماسح هنا بيطرح التعريفات صراحةً بدل ما يخمّن.
 *
 * ═══ اللي اتساب عن قصد ═══
 *   • `$`                 — ٣٧ نداء. الماسح الأول قال ميّتة لأن `\b\$\b`
 *                           مابيشتغلش (`$` مش حرف كلمة). فخ حقيقي.
 *   • `_onSessionExpired` — `assets/js/api.js:78` بينده عليها عند 401.
 *
 * ═══ تصحيح تعليق ═══
 * تعليق 2026-08-23 بيقول إن `finishSingleOrder` و`markOrderNotDelivered`
 * اتسابوا «لأن لوحة الفرع بتستعملهم». ده **غلط**: كل صفحة سكوب لوحده،
 * و`branch.html` مافيهاش `finishSingleOrder` أصلاً (صفر ذكر). التعليق
 * بيتصحّح مع الشيل.
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
const cuts = [];

/* ══ ماسح الجافاسكربت (نفس اللي في ccshifts.cjs) ══ */
const REGEX_KEYWORDS = new Set(['return', 'typeof', 'case', 'in', 'of', 'delete',
  'void', 'instanceof', 'new', 'do', 'else', 'yield', 'await', 'throw']);
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
    if (src[i] === '\n') return i;
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
function findBlockEnd(src, openIdx) {
  if (src[openIdx] !== '{') throw new Error('مش قوس فتح عند ' + openIdx);
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
      prev = '}'; prevWord = ''; continue;
    }
    if (/[A-Za-z0-9_$]/.test(c)) {
      let j = i; while (j < src.length && /[A-Za-z0-9_$]/.test(src[j])) j++;
      prevWord = src.slice(i, j); prev = src[j - 1]; i = j; continue;
    }
    prev = c; prevWord = ''; i++;
  }
  return -1;
}

let seq = 0;
function parsesAlone(chunk) {
  const tmp = path.join(os.tmpdir(), 'ccdead2-' + process.pid + '-' + (seq++) + '.mjs');
  try {
    fs.writeFileSync(tmp, chunk + '\n');
    execFileSync(process.execPath, ['--check', tmp], { stdio: 'pipe' });
    return null;
  } catch (e) {
    const out = (e.stderr ? e.stderr.toString() : '') || e.message;
    return ((out.match(/SyntaxError:.*/) || [out.split('\n')[0]])[0]).trim();
  } finally { try { fs.unlinkSync(tmp); } catch (e) {} }
}

function commentHeadStart(src, defStart) {
  const lines = src.slice(0, defStart).split('\n');
  lines.pop();
  let k = lines.length;
  while (k > 0) {
    const t = lines[k - 1].trim();
    if (t.startsWith('//')) { k--; continue; }
    if (t.endsWith('*/')) {
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

function cutDef(anchor, label, headAnchor) {
  const n = s.split(anchor).length - 1;
  if (n !== 1) { problems.push(`«${label}»: متوقّع ١ لقى ${n}`); return; }
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
    head = s.lastIndexOf('\n', s.indexOf(headAnchor)) + 1;
  } else head = commentHeadStart(s, start);

  const err = parsesAlone(s.slice(start, rawEnd + (s[rawEnd] === ';' ? 1 : 0)));
  if (err) { problems.push(`«${label}»: القطعة مش سليمة — ${err}`); return; }
  cuts.push({ label, head, end, lines: s.slice(head, end).split('\n').length - 1 });
}

/* لسطر واحد كامل (زي `const round2 = n => …;`) */
function cutLine(text, label) {
  const n = s.split(text).length - 1;
  if (n !== 1) { problems.push(`«${label}»: متوقّع ١ لقى ${n}`); return; }
  const start = s.lastIndexOf('\n', s.indexOf(text)) + 1;
  let end = s.indexOf('\n', s.indexOf(text));
  end = end < 0 ? s.length : end + 1;
  const head = commentHeadStart(s, start);
  cuts.push({ label, head, end, lines: s.slice(head, end).split('\n').length - 1 });
}

function replText(old, neu, label) {
  const n = s.split(old).length - 1;
  if (n !== 1) { problems.push(`«${label}»: متوقّع ١ لقى ${n}`); return; }
  const start = s.indexOf(old);
  cuts.push({ label, head: start, end: start + old.length, ins: neu, lines: 0 });
}

/* ══════════════════════════════════════════════════════════════════
   الدوال المتشالة — كل واحدة اتأكد إن ذكرها الوحيد في الملف هو تعريفها
   ══════════════════════════════════════════════════════════════════ */
const DEFS = [
  /* مساعد تاريخ مكرّر: `constants.js` عنده نسخته وبيصدّرها في
     TIAR_CONSTANTS. النسخة دي على window ومحدّش بينده عليها. */
  ['    window.cairoDayKeyCompact = function (d) {',                    'cairoDayKeyCompact'],

  /* الترميز الجغرافي — الكول سنتر بيقرا الإحداثيات من السيرفر دلوقتي */
  ['    async function geocodeAddress(address) {',                      'geocodeAddress'],
  ['    async function geocodeOrderInBackground(orderId, order) {',     'geocodeOrderInBackground'],
  ['  async function _geocodeAddr(address) {',                          '_geocodeAddr'],
  ['  async function _nominatim(q) {',                                  '_nominatim'],

  /* الطرود — المنطق اتنقل للسيرفر */
  ['    function normalizeParcels(order, parcels) {',                   'normalizeParcels'],
  ['    function parcelSuffixNum(orderNum, parcelsPart) {',             'parcelSuffixNum'],

  /* بقايا «فتح وردية من اللوحة» (اتشال 2026-08-30) */
  ['    async function createShiftRecord(pilot, branchId) {',           'createShiftRecord'],
  ['    function findActivePilotShift(pilotId) {',                      'findActivePilotShift'],
  ['    async function openOrTransferShift(pilot, branchId) {',         'openOrTransferShift'],
  ['        function highlightOpenShiftPilotMatch(text, q) {',          'highlightOpenShiftPilotMatch'],

  /* الدور في الفرع — بقى على السيرفر (board.php) */
  ['    async function shiftQueueAfterRemoval(branchId, removedQueueNo) {', 'shiftQueueAfterRemoval'],
  ['    async function getNextQueueNo(branchId) {',                     'getNextQueueNo'],

  /* بقايا «إرجاع الطيار» */
  ['    function openPilotReturnModal(pilotId, p, activeOrders, pendingSettlementOrders) {', 'openPilotReturnModal'],

  /* إجراءات الأوردر اللي اتشالت 2026-08-23 */
  ['    window.finishSingleOrder = async function(orderId, pilotId) {', 'finishSingleOrder'],
  ['    window.markOrderNotDelivered = async function(orderId, pilotId) {', 'markOrderNotDelivered'],
  ['    async function claimOrderForPilot(orderId, pilotId, pilotName, now) {', 'claimOrderForPilot'],
  ['    function activeOrdersForPilot(pilotId, movedOrderId, movedToPilotId) {', 'activeOrdersForPilot'],

  /* التقفيلة الشهرية — openMonthlyCloseout/saveMonthlyCloseout اتشالوا
     2026-08-30 وسابوا الحسابات كلها بلا نداء. */
  ['    function buildMonthlyCloseoutData(pilotId, year, month) {',     'buildMonthlyCloseoutData'],
  ['    function _mcNum(id) {',                                         '_mcNum'],
  ['    function _monthKeyOf(year, month) {',                           '_monthKeyOf'],

  /* بقايا شاشة «أوردر جديد» المتشالة من الكول سنتر */
  ['  function searchZones(q, lockBranchId) {',                         'searchZones'],
  ['  function searchCustomers(q, list) {',                             'searchCustomers'],
  ['  function hl(text, q) {',                                          'hl'],
];
for (const [anchor, label, head] of DEFS) cutDef(anchor, label, head);

/* سهم من غير أقواس */
cutLine('  const round2 = n => Math.round((Number(n)||0)*100)/100;', 'round2');

/* ── تعليق بيشاور على `searchZones` اللي بتتشال ── */
replText(
  'فالأوردر بيروح لفرع غلط. نفس منطق searchZones في «أوردر جديد».',
  'فالأوردر بيروح لفرع غلط.',
  'تعليق بيشاور على searchZones');

/* ── تصحيح التعليق اللي بيدّعي إن لوحة الفرع بتستعمل الدالتين ── */
replText(
  `وزرار مكسور أسوأ من زرار مش موجود. الدالتين (finishSingleOrder
                   و markOrderNotDelivered) سايبينهم في الملف — لوحة الفرع بتستعملهم. */`,
  `وزرار مكسور أسوأ من زرار مش موجود.

                   تحديث 2026-08-31: الدالتين (finishSingleOrder و
                   markOrderNotDelivered) اتشالوا كمان. التبرير القديم «لوحة
                   الفرع بتستعملهم» كان غلط — كل صفحة سكوب لوحدها، و
                   branch.html/tiar.html كل واحدة عندها نسختها. والنسخة اللي
                   هنا مكانش بينده عليها حاجة من ٢٠٢٦-٠٨-٢٣. */`,
  'تصحيح تعليق 2026-08-23');

/* ══════════════════════════════════════════════════════════════════
   تحقّق ثم قصّ
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
let total = 0;
for (const c of cuts) {
  total += c.lines;
  console.log('  ✓ ' + c.label.padEnd(30) + String(lineOf(c.head)).padStart(5) + '  ' + c.lines + ' سطر');
}

let out = '', pos = 0;
for (const c of cuts) { out += s.slice(pos, c.head) + (c.ins || ''); pos = c.end; }
out += s.slice(pos);

/* ══════════════════════════════════════════════════════════════════
   جولات التتابع — دالة بتموت لأن اللي كان بينده عليها اتشال
   ══════════════════════════════════════════════════════════════════
   مثال: `sumPrepaid` كانت بتتنده من `normalizeParcels` بس. لما دي راحت،
   دي بقت ميّتة. اللفّ بيكمّل لحد ما اللفّة ماتلاقيش جديد. نفس معايير
   الجولة الأولى بالظبط، وكل قطعة بتتفحص تركيبيًا قبل ما تتشال. */
const stripCom = t => t
  .replace(/\/\*[\s\S]*?\*\//g, m => ' '.repeat(m.length))
  .replace(/(^|[^:"'`\\])\/\/[^\n]*/g, (m, p) => p + ' '.repeat(m.length - p.length));

const EXTERNAL_SRC = [];
for (const m of ORIGINAL.matchAll(/<script src="([^"]+)"/g)) {
  if (/^https?:/.test(m[1])) continue;
  const p = path.resolve(__dirname, '../../public', m[1].split('?')[0]);
  if (fs.existsSync(p)) EXTERNAL_SRC.push(fs.readFileSync(p, 'utf8'));
}

/* الأسماء المحميّة: ثبت إنها حيّة بالإيد، أو الماسح بيغلط فيها */
const PROTECTED = new Set(['$', '_onSessionExpired', 'onload']);

function findDead(text) {
  const noCom = stripCom(text);
  const found = new Map();          // اسم → [أنواع]
  const add = (n, kind) => { if (!found.has(n)) found.set(n, []); found.get(n).push(kind); };
  for (const m of noCom.matchAll(/window\.([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?function\b/g)) add(m[1], 'w');
  for (const m of noCom.matchAll(/(?:^|\n)[ \t]*(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(/g)) add(m[1], 'f');

  const dead = [];
  for (const [n, kinds] of found) {
    if (PROTECTED.has(n) || n.length < 3) continue;
    const uses = (noCom.match(new RegExp('\\b' + n + '\\b', 'g')) || []).length - kinds.length;
    if (uses > 0) continue;
    if (EXTERNAL_SRC.some(src => new RegExp('\\b' + n + '\\b').test(src))) continue;
    if (new RegExp('on[a-z]+\\s*=\\s*["\']' + n + '\\s*\\(').test(text)) continue;
    if (kinds.length > 1) continue;                     // تعريف مكرّر — مالوش لازمة نلمسه
    dead.push({ name: n, kind: kinds[0] });
  }
  return dead;
}

/* بيشيل تعريف من نص معطى ويرجّع النص الجديد (أو null لو فشل) */
function removeDefFrom(text, name, kind) {
  const re = kind === 'w'
    ? new RegExp('[ \\t]*window\\.' + name + '\\s*=\\s*(?:async\\s*)?function\\b')
    : new RegExp('(?:^|\\n)([ \\t]*(?:async\\s+)?function\\s+' + name + '\\s*\\()');
  const m = re.exec(text);
  if (!m) return null;
  const start = kind === 'w' ? m.index + (m[0].length - m[0].trimStart().length)
                             : m.index + m[0].indexOf(m[1] || m[0].trim());
  const open = text.indexOf('{', start);
  if (open < 0) return null;
  const rawEnd = findBlockEnd(text, open);
  if (rawEnd < 0) return null;
  if (parsesAlone(text.slice(start, rawEnd))) return null;
  let end = rawEnd;
  if (text[end] === ';') end++;
  while (text[end] === '\n') end++;
  const lineStart = text.lastIndexOf('\n', start) + 1;
  const head = commentHeadStart(text, lineStart);
  return { text: text.slice(0, head) + text.slice(end),
           lines: text.slice(head, end).split('\n').length - 1 };
}

const cascade = [];
for (let round = 2; round <= 12; round++) {
  const dead = findDead(out);
  if (!dead.length) break;
  const names = [];
  for (const d of dead) {
    const r = removeDefFrom(out, d.name, d.kind);
    if (!r) { problems.push(`جولة ${round}: مقدرتش أشيل «${d.name}»`); continue; }
    out = r.text; total += r.lines;
    names.push(d.name + ' (' + r.lines + ')');
  }
  if (!names.length) break;
  cascade.push({ round, names });
}
if (cascade.length) {
  console.log('\n══ جولات التتابع ══');
  for (const c of cascade) console.log('  جولة ' + c.round + ': ' + c.names.join(' · '));
}
if (problems.length) {
  console.log('\n⛔ فحوص وقعت — مافيش بايت اتكتب:');
  for (const p of problems) console.log('   ✗ ' + p);
  process.exit(1);
}

/* ══ فحوص بعدية ══ */
console.log('\n══ فحوص بعدية ══');
const after = [];

/* (١) مافيش **كود** فاضل لأي اسم متشال.
   بنفحص على النص من غير تعليقات: التعليق المصحَّح بيذكر
   `finishSingleOrder`/`markOrderNotDelivered` عن قصد كتوثيق. */
{
  const names = DEFS.map(d => d[1]).concat(['round2'], cascade.flatMap(c => c.names.map(n => n.split(' ')[0])));
  const bare = stripCom(out);
  const left = names.filter(n => new RegExp('\\b' + n + '\\b').test(bare));
  console.log((left.length ? '  ✗' : '  ✓') + ' صفر كود فاضل لكل اسم متشال (' + names.length + ')');
  if (left.length) { after.push('لسه فيه: ' + left.join(', ')); console.log('     ' + left.join(', ')); }

  /* واللي فاضل في التعليقات: لازم يكون المذكور في التصحيح بس */
  const inCom = names.filter(n => new RegExp('\\b' + n + '\\b').test(out));
  console.log('  · مذكورين في تعليقات (توثيق مقصود): ' + (inCom.join(', ') || 'مافيش'));
}

/* (٢) الأسماء اللي لازم تفضل */
{
  const keep = ['$', '_onSessionExpired', 'cairoDayKey', 'cairoParts', 'isCairoToday',
                'pilotCommissionFor', 'esc', 'escJs', 'navigateTo', 'trackCard', 'addOrder'];
  const gone = keep.filter(n => !out.includes(n === '$' ? 'const $ = id =>' : n));
  console.log((gone.length ? '  ✗' : '  ✓') + ' الأسماء الحيّة لسه موجودة (' + keep.length + ')');
  if (gone.length) { after.push('اختفى: ' + gone.join(', ')); console.log('     ' + gone.join(', ')); }
}

/* (٣) كل كتل الجافاسكربت سليمة */
{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, bad = 0;
  while ((m = re.exec(out)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
    const err = parsesAlone(m[2]);
    if (err) { bad++; console.log('  ✗ كتلة ' + i + ': ' + err); }
  }
  console.log((bad ? '  ✗' : '  ✓') + ' كل كتل الجافاسكربت سليمة (' + i + ' كتلة)');
  if (bad) after.push('كتل مكسورة');
}

/* (٤) توازن الـdiv ما اتغيّرش */
{
  const bal = t => { const b = t.slice(t.indexOf('<body'), t.indexOf('</body>'));
    return (b.match(/<div\b/g) || []).length - (b.match(/<\/div>/g) || []).length; };
  const d0 = bal(ORIGINAL), d1 = bal(out);
  console.log((d0 === d1 ? '  ✓' : '  ✗') + ` توازن <div> زي ما كان (${d0} → ${d1})`);
  if (d0 !== d1) after.push('توازن div اتغيّر');
}

/* (٥) اللفّ وصل لنقطة ثابتة؟ */
{
  const fresh = findDead(out).map(d => d.name);
  console.log((fresh.length ? '  ✗' : '  ✓') + ' نقطة ثابتة: مافيش كود ميت فاضل'
    + (fresh.length ? ' ← ' + fresh.join(', ') : ''));
  if (fresh.length) after.push('لسه فيه كود ميت: ' + fresh.join(', '));
}

if (after.length) { console.log('\n⛔ فحوص بعدية وقعت — مافيش بايت اتكتب.'); process.exit(1); }
if (process.env.DRY) { console.log('\n🟦 DRY — كل الفحوص عدّت، بس مافيش بايت اتكتب.'); process.exit(0); }

fs.writeFileSync(FILE, WAS_CRLF ? out.replace(/\n/g, '\r\n') : out);
console.log('\n✓ اتكتب ' + path.basename(FILE) + (WAS_CRLF ? '  (CRLF)' : '  (LF)'));
console.log('  ' + ORIGINAL.split('\n').length + ' سطر → ' + out.split('\n').length + ' سطر  (−' + total + ')');
