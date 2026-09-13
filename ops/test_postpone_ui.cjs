/**
 * ⏸️ اختبار «التأجيل ليه باب في الفرع والإدارة».
 *
 * ═══ ليه الاختبار ده موجود ═══
 * «تأجيل / فك تأجيل» كان زراره الوحيد في كارت البحث السريع بتاع الكول
 * سنتر، واتشال 2026-08-30. لو اتشال من غير بديل، الميزة بتختفي من
 * المنظومة كلها — وأسوأ حاجة: أوردر حالته «مؤجل» **مايبقاش ليه أي طريق
 * يرجع منه**، لأن جداول الفرع والإدارة بتفلتر «قيد التنفيذ» بالظبط
 * والمؤجل بيقع بين الجداول ويختفي.
 *
 * فالفحص النصي «هل زرار فك التأجيل موجود؟» **مابيكفيش** — ممكن يكون
 * موجود في كود مابيتنفّذش على أوردر مؤجل. الاختبار ده بيشغّل دالة الرسم
 * الحقيقية من الملف على أوردر مؤجل وبيقرا الأزرار اللي اتبنت فعلاً.
 *
 * التشغيل: node ops/test_postpone_ui.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (w, c, got) => { c ? (pass++, console.log('  ✓ ' + w))
  : (fail++, console.log('  ✗ ' + w + (got !== undefined ? '   ← ' + got : ''))); };

/* ── نقص دالة بعدّ الأقواس (نفس أسلوب باقي الاختبارات) ── */
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

const mk = (id, num, status) => ({
  id, orderNum: num, status, senderName: 'محمد', senderPhone: '01000000000',
  deliveries: [{ receiverName: 'أحمد', receiverPhone: '01100000000',
                 zoneName: 'الجلاء', address: 'ش الجمال', zonePrice: 30 }],
  totalDeliveryPrice: 30, statusSince: '2026-08-30T12:00:00.000Z',
  createdAt: '2026-08-30T12:00:00.000Z', notes: '', pilotId: '', pilotName: '',
});

/* الأزرار اللي اتبنت في كل صف، بالنداء بتاعها */
const rowsOf = html =>
  html.split('</tr>').filter(r => r.includes('<button')).map(r => ({
    num: (r.match(/<b[^>]*>([^<]*)<\/b>/) || [, '?'])[1],
    btns: [...r.matchAll(/onclick="([a-zA-Z_$][\w$]*)\(/g)].map(m => m[1]),
  }));

function runBranch() {
  const src = fs.readFileSync('public/branch.html', 'utf8');
  let out = '';
  const doc = { getElementById: id => (id === 'ordersBody' ? { id } : null) };
  const helpers = {
    _lvPage: () => ({ start: 0, slice: a => a, mark: () => {} }),   // ترقيم LISTVIEW (2026-09-12) — الحارس بيعرض كل الصفوف
    document: doc,
    setHtml: (el, html) => { out = html; },
    esc: s => String(s == null ? '' : s),
    escJs: s => String(s == null ? '' : s),
    isOrderOverdue: () => false,
    formatElapsed: () => '0:00',
    startTimers: () => {},
    toggleNotesColumn: () => {},
    window: { _ordersData: [] },
  };
  const fn = new Function(...Object.keys(helpers),
    cut(src, 'function renderOrdersPage()') + '\nreturn renderOrdersPage;')(...Object.values(helpers));
  return orders => { helpers.window._ordersData = orders; out = ''; fn(); return out; };
}

console.log('\n══ 1) 🔴 الفرع: الأوردر المؤجل بيوصل للجدول ══');
{
  const render = runBranch();
  const html = render([mk('a', 'ORD-1', 'قيد التنفيذ'), mk('b', 'ORD-2', 'مؤجل')]);
  const rows = rowsOf(html);
  ok('الصفّين الاتنين بيتعرضوا', rows.length === 2, rows.length + ' صف');

  const pend = rows.find(r => r.num.includes('ORD-1'));
  const post = rows.find(r => r.num.includes('ORD-2'));
  ok('🔴 الأوردر المؤجل موجود في الجدول', !!post, 'مش موجود — فك التأجيل مالوش باب');

  if (pend) {
    ok('«قيد التنفيذ»: فيه زرار تأجيل', pend.btns.includes('postponeOrder'), pend.btns.join(', '));
    ok('«قيد التنفيذ»: فيه تحميل ونقل', pend.btns.includes('assignPilotToOrder')
       && pend.btns.includes('openTransferOrderModal'), pend.btns.join(', '));
    ok('«قيد التنفيذ»: مفيش فك تأجيل', !pend.btns.includes('unpostponeOrder'), pend.btns.join(', '));
  }
  if (post) {
    ok('🔴 «مؤجل»: فيه زرار فك التأجيل', post.btns.includes('unpostponeOrder'), post.btns.join(', '));
    /* التحميل والنقل بيرجّعوا 409 من السيرفر على أوردر مؤجل — عرضهم
       بيدّي الموظف زرار بيفشل، فلازم يختفوا. */
    ok('«مؤجل»: مفيش تحميل', !post.btns.includes('assignPilotToOrder'), post.btns.join(', '));
    ok('«مؤجل»: مفيش نقل لفرع', !post.btns.includes('openTransferOrderModal'), post.btns.join(', '));
    ok('«مؤجل»: مفيش تأجيل تاني', !post.btns.includes('postponeOrder'), post.btns.join(', '));
    ok('«مؤجل»: الإلغاء لسه متاح', post.btns.includes('cancelOrderBranch'), post.btns.join(', '));
  }
}

console.log('\n══ 2) الحالات اللي السيرفر بيرفضها مافيهاش زرار تأجيل ══');
{
  const render = runBranch();
  /* postpone على السيرفر: processing | undelivered بس */
  for (const st of ['جاري التوصيل', 'تم التسليم', 'ملغي']) {
    const rows = rowsOf(render([mk('c', 'ORD-X', st)]));
    ok('«' + st + '» مش في الجدول ده أصلًا', rows.length === 0, rows.length + ' صف');
  }
}

console.log('\n══ 3) الدوال بتضرب على المسار الصح ══');
for (const f of ['branch', 'tiar']) {
  const t = fs.readFileSync('public/' + f + '.html', 'utf8');
  ok(f + ': postpone على /postpone', /\/api\/orders\/\$\{orderId\}\/postpone/.test(t));
  ok(f + ': unpostpone على /unpostpone', /\/api\/orders\/\$\{orderId\}\/unpostpone/.test(t));
  /* السيرفر بيحفظ prev_status ويرجّعه — لو الواجهة بعتت حالة بإيدها
     هتدهس اللي السيرفر عارفه. */
  ok(f + ': مابيبعتش حالة من عنده', !/postpone`,\s*\{\s*status/.test(t));
}

console.log('\n══ 4) 🔴 الإدارة: نفس الاختبار السلوكي ══');
/* `_renderOrdersUI` بترسم ٦ جداول وبتلمس عناصر كتير، فالـDOM المقلّد
   بيدّي عنصر وهمي لأي id ويحتفظ باللي اتكتب فيه. بنقرا جدول
   `ordersBody` بس — هو اللي فيه «قيد التنفيذ» و«مؤجل». */
{
  const src = fs.readFileSync('public/tiar.html', 'utf8');
  const store = {};
  const node = id => (store[id] ||= {
    id, textContent: '', style: {}, classList: { add() {}, remove() {}, toggle() {} },
    querySelectorAll: () => [], querySelector: () => null, addEventListener() {},
    get innerHTML() { return this._h || ''; }, set innerHTML(v) { this._h = v; },
  });
  const doc = { getElementById: node, querySelectorAll: () => [], querySelector: () => null };
  const helpers = {
    _lvPage: () => ({ start: 0, slice: a => a, mark: () => {} }),   // ترقيم LISTVIEW (2026-09-12) — الحارس بيعرض كل الصفوف
    document: doc, window: { _ordersData: [], _ordersUiSig: null },
    esc: s => String(s == null ? '' : s), escJs: s => String(s == null ? '' : s),
    fmt: n => String(Number(n) || 0), fmt0: n => String(Number(n) || 0),
    totalParcelsOf: a => a.length, parcelCount: () => 1,
    isOrderOverdue: () => false, formatElapsed: () => '0:00',
    startOrderTimers: () => {}, toggleNotesColumn: () => {}, capNewest: a => a,
    rowsCapNote: () => '', _orderTime: () => 0, branchName: () => 'فرع',
    setHtml: (el, h) => { if (el) el.innerHTML = h; },
    _orderSourceBadge: () => '', orderSourceBadge: () => '', _orderDeliveryBase: () => 0,
    _overdueBadgeHtml: () => '', badge: s => s, toDateStr: () => '2026-08-30',
    timeOf: () => '12:00', money: n => String(n), statCard: () => '',
  };
  /* `_renderOrdersUI` بتنده على دوال كتير من باقي الصفحة (renderDashboard
     وغيرها). بدل ما نلاحقهم واحدة واحدة، بنلفّ الكود في `with` على Proxy
     بيرجّع دالة فاضية لأي اسم مش معرّف عندنا — فأي نداء جانبي بيعدّي
     من غير ما يوقّف الرسم، واللي يهمنا (بناء الجدول) بيتنفّذ زي ما هو. */
  /* الـProxy بيلفّ النطاق كله، فحتى الأسماء المدمجة (Object · Array · JSON)
     بتعدّي عليه — لازم نمرّرها زي ما هي وإلا بتبقى دوال فاضية. */
  const BUILTINS = { Object, Array, JSON, Math, String, Number, Boolean, Date, Map, Set,
                     RegExp, Error, Promise, parseInt, parseFloat, isNaN, console };
  Object.assign(helpers, BUILTINS);
  const noop = () => {};
  const sandbox = new Proxy(helpers, {
    has: () => true,
    get: (t, k) => (k in t ? t[k] : (typeof k === 'string' && k !== 'Symbol' ? noop : undefined)),
    set: (t, k, v) => { t[k] = v; return true; },
  });
  let render = null, why = '';
  try {
    render = new Function('__s', `with (__s) { ${cut(src, 'function _renderOrdersUI(')}
      return _renderOrdersUI; }`)(sandbox);
  } catch (e) { why = String(e.message).slice(0, 90); }
  ok('الدالة اتحمّلت', !!render, why);

  if (render) {
    const data = { a: mk('a', 'ORD-1', 'قيد التنفيذ'), b: mk('b', 'ORD-2', 'مؤجل') };
    let err = '';
    try { render(data); } catch (e) { err = String(e.message).slice(0, 90); }
    const html = store['ordersBody']?.innerHTML || '';
    ok('الجدول اتبنى', html.length > 0, err || '(فاضي)');

    const rows = rowsOf(html);
    const post = rows.find(r => r.num.includes('ORD-2'));
    const pend = rows.find(r => r.num.includes('ORD-1'));
    ok('🔴 الأوردر المؤجل بيوصل للجدول', !!post, 'مش موجود — فك التأجيل مالوش باب');
    if (post) {
      ok('«مؤجل»: فيه فك التأجيل', post.btns.includes('unpostponeOrder'), post.btns.join(', '));
      ok('«مؤجل»: مفيش تحميل ولا نقل', !post.btns.includes('assignPilotToOrder')
         && !post.btns.includes('openTransferOrderModal'), post.btns.join(', '));
    }
    if (pend) ok('«قيد التنفيذ»: فيه تأجيل', pend.btns.includes('postponeOrder'), pend.btns.join(', '));
  }
}

console.log('\n══ 5) الكول سنتر مالوش أي منهم ══');
{
  const t = fs.readFileSync('public/callcenter.html', 'utf8');
  ok('مفيش postponeOrder', !/postponeOrder/.test(t));
  ok('مفيش unpostponeOrder', !/unpostponeOrder/.test(t));
}

console.log('\n════════════════════════════════════════');
console.log('POSTPONE UI: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
