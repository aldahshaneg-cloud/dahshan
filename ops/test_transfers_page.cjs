/**
 * 🔄 اختبار «صفحة نقل الطيارين بتتعرض صح».
 *
 * ═══ الباج ═══
 * شارة الحالة في «طلبات الدعم بين الفروع» كانت بتتعرض كنص خام على الشاشة:
 *   <span class="status-badge status-cancelled">❌ مرفوض</span>
 * السبب: `${ esc(statusMap[r.status] || "") }` — و`statusMap` قيمها HTML
 * مكتوب في الكود، فـ`esc()` حوّلت الوسوم لحروف.
 *
 * ═══ ليه اختبار سلوكي ═══
 * فحص نصي «هل esc موجودة؟» بيعدّي على الحالة العكسية (حد يشيل esc من
 * مكان **محتاجها** فيفتح باب حقن). الاختبار ده بيشغّل دالة الرسم الحقيقية
 * على بيانات فيها وسوم HTML في حقول المستخدم، وبيقرا الناتج:
 *   • الشارة لازم تطلع **وسم حقيقي** (مش حروف)،
 *   • واسم الفرع/الطيار لازم يطلع **محروف** (مش وسم) — الحقلين دول
 *     جايين من قاعدة البيانات وممكن يتكتب فيهم أي حاجة.
 *
 * التشغيل: node ops/test_transfers_page.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (w, c, got) => { c ? (pass++, console.log('  ✓ ' + w))
  : (fail++, console.log('  ✗ ' + w + (got !== undefined ? '   ← ' + got : ''))); };

/* نقص دالة بعدّ الأقواس */
function cut(src, name) {
  const start = src.indexOf(name);
  if (start < 0) throw new Error('مالقيتش ' + name);
  let p = src.indexOf('(', start), pd = 0, body = -1;
  for (let j = p; j < src.length; j++) {
    if (src[j] === '(') pd++;
    else if (src[j] === ')') { pd--; if (!pd) { body = src.indexOf('{', j); break; } }
  }
  let d = 0;
  for (let j = body; j < src.length; j++) {
    const c = src[j];
    if (c === '{') d++;
    else if (c === '}') { d--; if (!d) return src.slice(start, j + 1); }
    else if (c === '`' || c === '"' || c === "'") {
      const q = c; j++;
      while (j < src.length && src[j] !== q) { if (src[j] === '\\') j++; j++; }
    }
  }
  throw new Error('ماقدرتش أقفل ' + name);
}

/* نفس `esc` بتاعة الصفحات */
const escReal = s => String(s == null ? '' : s)
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

const STATUSES = [
  ['pending',   'بانتظار الفرع'],
  ['accepted',  'أُرسل الطيار'],
  ['rejected',  'مرفوض'],
  ['completed', 'مكتمل'],
];

for (const file of ['public/tiar.html', 'public/callcenter.html']) {
  const src = fs.readFileSync(file, 'utf8');
  console.log('\n══ ' + file + ' ══');

  const store = {};
  const node = id => (store[id] ||= { id, textContent: '', style: {},
    querySelectorAll: () => [], addEventListener() {},
    get innerHTML() { return this._h || ''; }, set innerHTML(v) { this._h = v; } });
  const BUILTINS = { Object, Array, JSON, Math, String, Number, Boolean, Date, Map, Set, console };
  const env = {
    document: { getElementById: node, querySelectorAll: () => [], querySelector: () => null },
    esc: escReal, escJs: s => String(s ?? ''),
    fmt: n => String(Number(n) || 0), fmt0: n => String(Number(n) || 0),
    branchName: () => 'فرع', toDateStr: () => '2026-08-30',
    window: { _pilotTransfersData: [], _pilotSupportData: [] },
    ...BUILTINS,
  };
  const noop = () => {};
  const sandbox = new Proxy(env, {
    has: () => true,
    get: (t, k) => (k in t ? t[k] : noop),
    set: (t, k, v) => { t[k] = v; return true; },
  });

  let render = null, why = '';
  try {
    render = new Function('__s', `with (__s) { ${cut(src, 'function renderAdminTransfersPage(')}
      return renderAdminTransfersPage; }`)(sandbox);
  } catch (e) { why = String(e.message).slice(0, 80); }
  ok('الدالة اتحمّلت', !!render, why);
  if (!render) continue;

  /* بيانات فيها وسم HTML في حقول جاية من قاعدة البيانات */
  const EVIL = '<img src=x onerror=alert(1)>';
  env.window._pilotSupportData = STATUSES.map(([st], i) => ({
    id: 's' + i, status: st,
    requestingBranchName: 'فرع ' + i, fromBranchName: EVIL,
    pilotName: EVIL, notes: EVIL, requestedAt: '2026-08-30T12:00:00.000Z',
  }));
  env.window._pilotTransfersData = [];
  let err = '';
  try { render(); } catch (e) { err = String(e.message).slice(0, 80); }
  const html = store['adminTransfersContainer']?.innerHTML || '';
  ok('الصفحة اتبنت', html.length > 0, err || '(فاضي)');

  console.log('  — الشارات لازم تبقى وسوم حقيقية —');
  for (const [st, label] of STATUSES) {
    /* الوسم الحقيقي موجود، والنسخة المحروفة مش موجودة */
    const realTag = html.includes('status-badge') && html.includes(label);
    const escaped = html.includes('&lt;span class=&quot;status-badge')
                 || html.includes('&lt;span class="status-badge');
    ok('«' + label + '» بتتعرض كشارة', realTag, 'مش لاقيها');
    ok('«' + label + '» مش حروف خام', !escaped, 'الوسم ظاهر كنص');
  }

  console.log('  — والحقول اللي من قاعدة البيانات لازم تفضل محروفة —');
  ok('🔴 اسم الفرع محروف', !html.includes('<img src=x'), 'وسم من البيانات عدّى!');
  ok('🔴 اسم الطيار والملاحظات محروفة',
     (html.match(/&lt;img src=x/g) || []).length >= 2,
     'عدد المواضع المحروفة: ' + (html.match(/&lt;img src=x/g) || []).length);
  ok('مفيش onerror شغّال', !/onerror=alert\(1\)>/.test(html.replace(/&lt;[^&]*/g, '')));
}

console.log('\n════════════════════════════════════════');
console.log('TRANSFERS PAGE: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
