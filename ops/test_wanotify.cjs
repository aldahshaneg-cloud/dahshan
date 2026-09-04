/**
 * 📱 اختبار «إجبار إرسال واتساب المستلم بعد تسجيل الأوردر».
 *
 * ═══ الميزة (قرار صاحب النظام 2026-09-01) ═══
 * بعد نجاح تسجيل الأوردر في الكول سنتر أو الفرع، مودال بيفتح لوحده بكل
 * رسايل الواتساب المعلّقة للأوردر، ومفيش إغلاق غير بعد ما كلها تتعلّم
 * مبعوتة. نفس نمط شاشة «رسايل العملاء» في الإدارة: «افتح واتساب» بيفتح
 * التاب وبس، و«اتبعت» تأكيد صريح هو اللي بيكتب في القاعدة.
 *
 * ═══ ليه سلوكي ═══
 * «الإجبار» منطق حالة: اتبعت مقفول قبل افتح، والإغلاق مقفول قبل اكتمال
 * الكل، والتخطّي للي من غير رقم بس. فحص نصّي مايقدرش يثبت ده — الاختبار
 * بيبني DOM مصغّر وبيدوس الزراير فعليًا ويقرا إيه اللي اتبعت للسيرفر.
 *
 * التشغيل: node ops/test_wanotify.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (w, c, got) => { c ? (pass++, console.log('  ✓ ' + w))
  : (fail++, console.log('  ✗ ' + w + (got !== undefined ? '   ← ' + got : ''))); };

/* الكول سنتر **بس** — قرار صاحب النظام 2026-09-01 (نفس اليوم): «الغي
   موضوع الواتساب في الفرع خليها في الكول سنتر بس». فحص القسم ٧ تحت
   بيتأكد إن الفرع فاضي من الميزة — عشان ماترجعش بالغلط مع نسخ لصق. */
const FILES = ['public/callcenter.html'];

/* ── DOM مصغّر: بيفهم الحاجات اللي المودال بيستعملها وبس ── */
function makeDom() {
  const byId = {};
  class El {
    constructor() { this._html = ''; this._kids = []; this._handlers = []; this._attrs = {};
                    this.style = {}; this.disabled = false; this.textContent = ''; this._removed = false; }
    set innerHTML(v) { this._html = v; this._kids = parseKids(v); }
    get innerHTML() { return this._html; }
    addEventListener(t, h) { this._handlers.push(h); }
    async click() { if (this.disabled) return; for (const h of this._handlers) await h(); }
    getAttribute(k) { return this._attrs[k] ?? null; }
    remove() { this._removed = true; }
    querySelector(sel) { return this._find(sel)[0] || null; }
    querySelectorAll(sel) { return this._find(sel); }
    _find(sel) {
      const all = [];
      const walk = k => { all.push(k); (k._kids || []).forEach(walk); };
      this._kids.forEach(walk);
      let m;
      if ((m = sel.match(/^#(.+)$/))) return all.filter(e => e._attrs.id === m[1]);
      if ((m = sel.match(/^\[([\w-]+)="(.+)"\]$/))) return all.filter(e => e._attrs[m[1]] === m[2]);
      if ((m = sel.match(/^\[([\w-]+)\]$/))) return all.filter(e => m[1] in e._attrs);
      return [];
    }
  }
  /* بنستخرج العناصر اللي ليها id أو data-wa-* من نص الـHTML */
  function parseKids(html) {
    const kids = [];
    const re = /<(button|div)\b([^>]*)>/g;
    let m;
    while ((m = re.exec(html)) !== null) {
      const attrs = {};
      for (const a of m[2].matchAll(/([\w-]+)="([^"]*)"/g)) attrs[a[1]] = a[2];
      if (!attrs.id && !Object.keys(attrs).some(k => k.startsWith('data-'))) continue;
      const el = new El();
      el._attrs = attrs;
      el.disabled = /\bdisabled\b/.test(m[2]);
      kids.push(el);
      if (attrs.id) byId[attrs.id] = el;
    }
    return kids;
  }
  const body = { appended: [], appendChild(el) { this.appended.push(el); if (el._attrs?.id) byId[el._attrs.id] = el; } };
  const document = {
    getElementById: id => byId[id] || null,
    createElement: () => { const el = new El(); el._attrs = {}; return el; },
    body,
  };
  /* box بيتحط ليه id بعد الإنشاء (box.id = "_waNotifyBox") */
  return { document, body, byId,
    hookIdAssign(el, id) { el._attrs.id = id; byId[id] = el; } };
}

/* ── تشغيل الدوال الحقيقية من ملف معيّن ── */
function loadFns(file) {
  const src = fs.readFileSync(file, 'utf8');
  const at = src.indexOf('const _waEsc =');
  const end = src.indexOf('\n    }\n', src.indexOf('data-wa-skip', at));
  if (at < 0 || end < 0) throw new Error('مالقيتش الكتلة في ' + file);
  return src.slice(at, end + 7);
}

async function run(file, { rows, fetchFails = false, popupBlocked = false } = {}) {
  const dom = makeDom();
  const posts = [], toasts = [], opened = [];
  const env = {
    document: dom.document,
    api: {
      get: async () => { if (fetchFails) throw new Error('نت واقع'); return { items: rows }; },
      post: async (p) => { posts.push(p); return { ok: true }; },
    },
    showToast: (m, t) => toasts.push({ m, t }),
    window: { open: (u) => { if (popupBlocked) return null; opened.push(u); return {}; } },
    encodeURIComponent, Set, Map, String, JSON,
  };
  const body = loadFns(file);
  const sandbox = new Proxy(env, {
    has: (t, k) => k in t,
    get: (t, k) => t[k], set: (t, k, v) => { t[k] = v; return true; },
  });
  const fn = new Function('__s', `with (__s) { ${body}\n return { open: window.openWaNotifyForOrder }; }`)(sandbox);
  /* box.id = "..." بيتحصّل بالمراقبة: بنلف على المضاف للجسم */
  await fn.open([9]);
  for (const el of dom.body.appended) if (el.id) dom.hookIdAssign(el, el.id);
  return { dom, posts, toasts, opened,
    box: dom.body.appended[0] || null,
    btn: (sel) => (dom.body.appended[0] ? dom.body.appended[0].querySelector(sel) : null) };
}

const ROWS = [
  { id: 11, orderId: 9, orderNum: 'HAL-260901-007', name: 'عمرو', phone: '01225349736', body: 'رسالة 1' },
  { id: 12, orderId: 9, orderNum: 'HAL-260901-007', name: 'سارة', phone: '01099999999', body: 'رسالة 2' },
  { id: 99, orderId: 5, orderNum: 'HAL-X', name: 'تاني', phone: '01000000000', body: 'مش بتاعنا' },
];

(async () => {
for (const file of FILES) {
  console.log('\n════════ ' + file + ' ════════');

  console.log('══ 1) 🔴 المودال بيفتح لرسايل الأوردر ده بس ══');
  const r = await run(file, { rows: ROWS });
  ok('المودال اتبنى', !!r.box);
  const opens = r.box ? r.box.querySelectorAll('[data-wa-open]') : [];
  ok('صفّين للأوردر ٩ (مش تلاتة)', opens.length === 2, String(opens.length));
  ok('رسالة الأوردر التاني مش ظاهرة', !(r.box?._html + '').includes('مش بتاعنا')
     && !r.box?.querySelector('[data-wa-open="99"]'));

  console.log('══ 2) 🔴 الإجبار: الترتيب افتح → اتبعت → إغلاق ══');
  const close = r.btn('#_waNotifyClose');
  ok('الإغلاق مقفول من الأول', close && close.disabled);
  const sent11 = r.btn('[data-wa-sent="11"]');
  ok('«اتبعت» مقفول قبل «افتح»', sent11 && sent11.disabled);
  await sent11.click();
  ok('🔴 الضغط عليه مقفول مابيبعتش للسيرفر', r.posts.length === 0, JSON.stringify(r.posts));
  await r.btn('[data-wa-open="11"]').click();
  ok('«افتح» فتح wa.me بالرقم الدولي', r.opened.length === 1 && r.opened[0].startsWith('https://wa.me/201225349736?text='),
     r.opened[0]);
  ok('و«اتبعت» اتفتح', !sent11.disabled);
  await sent11.click();
  ok('🔴 «اتبعت» نده المسار الصح', r.posts[0] === '/api/order-notifications/11/sent', JSON.stringify(r.posts));
  ok('والإغلاق لسه مقفول (فاضل رسالة)', close.disabled);
  await r.btn('[data-wa-open="12"]').click();
  await r.btn('[data-wa-sent="12"]').click();
  ok('🔴 بعد الكل: الإغلاق اتفتح', !close.disabled);
  await close.click();
  ok('والمودال اتقفل', r.box._removed);

  console.log('══ 3) حاجب النوافذ ══');
  const rb = await run(file, { rows: ROWS.slice(0, 1), popupBlocked: true });
  await rb.btn('[data-wa-open="11"]').click();
  ok('توست تحذير و«اتبعت» فضل مقفول',
     rb.toasts.some(t => /النوافذ المنبثقة/.test(t.m)) && rb.btn('[data-wa-sent="11"]').disabled);

  console.log('══ 4) مستلم من غير رقم صالح ══');
  const rn = await run(file, { rows: [{ id: 21, orderId: 9, name: 'م', phone: '', body: 'ن' },
                                      ROWS[0]] });
  const skip = rn.btn('[data-wa-skip="21"]');
  ok('ليه زرار تخطّي', !!skip);
  await skip.click();
  ok('التخطّي مابيكتبش في القاعدة', rn.posts.length === 0);
  ok('والإغلاق لسه مقفول (فاضل اللي ليه رقم)', rn.btn('#_waNotifyClose').disabled);
  await rn.btn('[data-wa-open="11"]').click();
  await rn.btn('[data-wa-sent="11"]').click();
  ok('بعد الاتنين: الإغلاق اتفتح', !rn.btn('#_waNotifyClose').disabled);

  console.log('══ 5) مفيش رسايل / الشبكة واقعة ══');
  const re1 = await run(file, { rows: [] });
  ok('مفيش رسايل → مفيش مودال', !re1.box);
  const re2 = await run(file, { rows: ROWS, fetchFails: true });
  ok('🔴 فشل التحميل: «الأوردر اتسجّل» مش «خطأ أثناء الحفظ»',
     re2.toasts.some(t => /اتسجّل/.test(t.m)) && !re2.toasts.some(t => /خطأ أثناء الحفظ/.test(t.m)),
     JSON.stringify(re2.toasts));

  console.log('══ 6) الربط في مسار الحفظ ══');
  {
    const src = fs.readFileSync(file, 'utf8');
    const blank = m => m.replace(/[^\n]/g, ' ');
    const bare = src.replace(/\/\*[\s\S]*?\*\//g, blank);
    ok('النداء بعد closeModal("order")',
       /closeModal\("order"\);\s*\n\s*openWaNotifyForOrder\(_createdIds\);/.test(bare));
    ok('🔴 من غير await (درس الكدب بعد الكتابة)', !/await openWaNotifyForOrder/.test(bare));
    ok('والـids بتتجمع من رد الإنشاء', /_createdIds = \(res\.orders \|\| \(res\.order \? \[res\.order\] : \[\]\)\)\.map\(o => o\?\.id\)/.test(bare));
  }
}

console.log('\n══ 7) 🔴 الفرع فاضي من الميزة (قرار: كول سنتر بس) ══');
{
  /* قرار صاحب النظام 2026-09-01 (بعد ساعات من الإضافة): «الغي موضوع
     الواتساب في الفرع خليها في الكول سنتر بس». الفحص ده بيمنع رجوعها
     بالغلط — نسخ لصق من الكول سنتر هو أسهل طريق ترجع بيه. */
  const br = fs.readFileSync('public/branch.html', 'utf8');
  for (const n of ['openWaNotifyForOrder', '_waNotifyRender', '_waNotifyBox', '_createdIds'])
    ok('branch.html من غير ' + n, !br.includes(n), 'موجودة — القرار كول سنتر بس');
}

console.log('\n════════════════════════════════════════');
console.log('WA NOTIFY: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
})().catch(e => { console.log('\n🔴 الاختبار نفسه وقع: ' + e.message + '\n' + e.stack); process.exit(1); });
