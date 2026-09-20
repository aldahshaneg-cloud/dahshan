/**
 * 📡 حارس: ردّ الفعل على حدث البثّ الفوري مابيعتمدش على مؤقتات (التبويب اللي في الخلفية).
 *
 * البلاغ (صاحب النظام 2026-09-21): «الكول سنتر بيعمل الأوردر وبعدها في الفرع بنص دقيقة أو دقيقة
 * على ما يوصل». القياس على الإنتاج: السيرفر بيوصّل الحدث للمشترك في ~0.5 ثانية
 * (ops/drill_realtime_latency.php)، لكن سجل أباتشي بيقول إن لوحات الفروع بتسحب الأوردرات كل
 * 46–75 ثانية أغلب الوقت — التبويب في الخلفية (المشرف على واتساب) والمتصفح بيخنق مؤقتاته لمرة
 * كل دقيقة. ردّ الفعل كان `setTimeout(300)` ثم `setTimeout(900)` → متأخر لحد دقيقة. رسايل
 * الويبسوكت نفسها مابتتخنقش.
 *
 * العقود (تنفيذ حقيقي للملفين جوه vm بمؤقتات **عمرها ما بتشتغل** = أسوأ خنق):
 * • RT.coalesce: أول حدث بينفّذ فورًا؛ حدث بعد نافذة التجميع بينفّذ فورًا حتى لو مؤقت قديم معلّق.
 * • Poller.tickSoon: حدث وصل والطلب شغّال → دورة كمان أول ما الطلب يخلص، من غير مؤقت.
 * • pokePaths والتبويب مخفي: من غير مؤقت.
 * • لوحة الفرع بتستعمل tickSoon، ونسخ السكربتات اتكسر كاشها.
 * التشغيل: node ops/test_realtime_hidden_tab.cjs
 */
const fs = require('fs');
const vm = require('vm');
let pass = 0, fail = 0;
const ok = (what, cond, got) => { if (cond) { pass++; console.log('  ✓ ' + what); } else { fail++; console.log('  ✗ ' + what + (got ? '   ← ' + got : '')); } };

(async () => {
  /* ── 1) coalesce ── */
  console.log('══ 1) RT.coalesce بمؤقتات مخنوقة ══');
  let now = 1000000;
  const timers = [];
  const win = { location: { protocol: 'https:', hostname: 'x', port: '' }, console, document: { hidden: true, addEventListener() {} } };
  win.window = win; win.self = win;
  const ctx = vm.createContext({ window: win, console, Date: { now: () => now }, setTimeout: (fn, ms) => { timers.push(fn); return timers.length; }, clearTimeout: () => {} });
  vm.runInContext('(function(){ var global = window; ' + fs.readFileSync('public/assets/js/realtime.js', 'utf8').replace(/\}\)\((?:typeof window[^)]*|window|this)\);?\s*$/, '})(window);') + ' })();', ctx);
  const RT = win.REALTIME;
  ok('REALTIME اتحمّلت', !!RT && typeof RT.coalesce === 'function');
  if (RT) {
    let calls = 0;
    const poke = RT.coalesce(() => { calls++; }, 300);
    poke();
    ok('🔴 أول حدث بينفّذ فورًا من غير ما أي مؤقت يشتغل', calls === 1, String(calls));
    now += 50; poke(); now += 50; poke();
    ok('  أحداث جوه نافذة التجميع بتتلمّ (مفيش نداء زيادة فوري)', calls === 1 && timers.length === 1, calls + '/' + timers.length);
    now += 5000; poke();
    ok('🔴 حدث بعد النافذة بينفّذ فورًا حتى والمؤقت القديم معلّق (مخنوق)', calls === 2, String(calls));
    timers.forEach(f => f());
    ok('  والمؤقت القديم لما يصحى مابيكرّرش من غير داعي', calls === 2, String(calls));
  }

  /* ── 2) Poller.tickSoon ── */
  console.log('\n══ 2) Poller.tickSoon والطلب شغّال ══');
  const gets = []; let release = null;
  const doc = { hidden: true, addEventListener() {}, createElement: () => ({ style: {}, setAttribute() {}, appendChild() {}, addEventListener() {} }), body: { appendChild() {} }, getElementById: () => null, readyState: 'complete' };
  const w2 = { document: doc, location: { protocol: 'https:', hostname: 'x', href: 'https://x/', origin: 'https://x' }, console, navigator: { onLine: true }, addEventListener() {} };
  w2.window = w2;
  const t2 = [];
  const ctx2 = vm.createContext({ window: w2, document: doc, console, Date, Promise, JSON, Math, Object, Array, String, Number, Error, encodeURIComponent,
    AbortController: class { constructor() { this.signal = {}; } abort() {} },
    fetch: () => new Promise(() => {}), setTimeout: (fn) => { t2.push(fn); return t2.length; }, clearTimeout() {}, setInterval: () => 1, clearInterval() {}, localStorage: { getItem: () => null, setItem() {} } });
  try {
    vm.runInContext(fs.readFileSync('public/assets/js/api.js', 'utf8'), ctx2);
  } catch (e) { ok('api.js اتنفّذ في vm', false, e.message); }
  const API = w2.API;
  ok('API.Poller اتحمّل وفيه tickSoon', !!API && typeof API.Poller === 'function' && typeof API.Poller.prototype.tickSoon === 'function');
  if (API && API.Poller && API.Poller.prototype.tickSoon) {
    API.get = (path) => { gets.push(path); return new Promise(res => { release = () => res({ serverNow: Date.now(), changed: false }); }); };
    const p = new API.Poller('/api/orders', { interval: 60000, immediate: false, hiddenTick: true, onChange() {} });
    p.tick();
    ok('  الطلب الأول اتبعت', gets.length === 1 && p._inFlight === true);
    p.tickSoon(); p.tickSoon();
    ok('  حدثين والطلب شغّال = مفيش طلب متوازي', gets.length === 1);
    const before = t2.length;
    release();
    await new Promise(r => setImmediate(r)); await new Promise(r => setImmediate(r));
    ok('🔴 أول ما الطلب خلص اتبعت طلب تاني تلقائيًا', gets.length === 2, String(gets.length));
    ok('🔴 ومن غير أي مؤقت جديد', t2.length === before, (t2.length - before) + ' مؤقت');
    release(); await new Promise(r => setImmediate(r));
    ok('  ومفيش لوب: بعد الدورة التانية وقف', gets.length === 2, String(gets.length));

    const poked = [];
    const p2 = new API.Poller('/api/shifts', { interval: 60000, immediate: false, onChange() {} });
    p2.tick = () => poked.push('shifts');
    const b2 = t2.length;
    API.pokePaths(['/api/shifts']);
    await new Promise(r => setImmediate(r));
    ok('🔴 pokePaths والتبويب مخفي بينفّذ من غير مؤقت', poked.length === 1 && t2.length === b2, poked.length + ' / +' + (t2.length - b2));
  }

  /* ── 2.5) الاتصال الحقيقي: pusher.min.js نفسها + إعدادات realtime.js على صفحة https ──
     🔴 (2026-09-21) `enabledTransports: ["wss"]` كانت بتخلّي المكتبة `initialized → failed` فورًا من غير
     ما تفتح ويبسوكت خالص — لوحات الويب عمرها ما اتصلت بالبثّ على الإنتاج. الفحص ده بيشغّل المكتبة
     الحقيقية ويتأكد إنها **فتحت** WebSocket على wss:// بنفس دومين الصفحة. */
  console.log('\n══ 2.5) المكتبة الحقيقية بتفتح ويبسوكت على https ══');
  {
    const sockets = [];
    class FakeWS { constructor(url) { this.url = url; this.readyState = 0; sockets.push(this); } send() {} close() {} }
    FakeWS.CONNECTING = 0; FakeWS.OPEN = 1; FakeWS.CLOSING = 2; FakeWS.CLOSED = 3;
    const doc3 = { hidden: false, addEventListener() {}, removeEventListener() {}, createElement: () => ({ style: {}, setAttribute() {}, appendChild() {} }), getElementsByTagName: () => [{ appendChild() {}, insertBefore() {} }], location: { protocol: 'https:' }, body: {} };
    const w3 = { document: doc3, location: { protocol: 'https:', hostname: 'branch.example.test', port: '' }, console: { log() {}, warn() {}, error() {} },
      navigator: { onLine: true, userAgent: 'guard' }, WebSocket: FakeWS, XMLHttpRequest: function () {}, localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
      crypto: { getRandomValues: a => { for (let i = 0; i < a.length; i++) a[i] = (Math.random() * 4294967296) >>> 0; return a; } },
      addEventListener() {}, removeEventListener() {}, setTimeout, clearTimeout, setInterval: () => 1, clearInterval() {} };
    w3.window = w3; w3.self = w3; w3.global = w3;
    const ctx3 = vm.createContext(Object.assign(w3, { Date, Math, JSON, Object, Array, String, Number, Error, Promise, Function, RegExp, parseInt, encodeURIComponent, decodeURIComponent, Uint8Array, ArrayBuffer, TextEncoder, TextDecoder, btoa: s => Buffer.from(s, 'binary').toString('base64'), atob: s => Buffer.from(s, 'base64').toString('binary') }));
    let booted = true;
    try {
      vm.runInContext(fs.readFileSync('public/assets/js/vendor/pusher.min.js', 'utf8'), ctx3);
      vm.runInContext(fs.readFileSync('public/assets/js/realtime.js', 'utf8'), ctx3);
      w3.REALTIME.connect();
    } catch (e) { booted = false; ok('المكتبة اشتغلت في vm', false, e.message); }
    if (booted) {
      await new Promise(r => setTimeout(r, 300));
      const st = w3.REALTIME.state();
      ok('🔴 الحالة «connecting» مش «disconnected/failed»', st === 'connecting', st);
      ok('🔴 واتفتح WebSocket فعلًا على wss:// بنفس دومين الصفحة', sockets.length >= 1 && /^wss:\/\/branch\.example\.test(:443)?\/app\//.test(sockets[0].url), sockets.length ? sockets[0].url : 'ولا سوكت');
    }
    const RS = fs.readFileSync('public/assets/js/realtime.js', 'utf8');
    ok('  والإعداد: ["ws","wss"] مع TLS', RS.includes('base.enabledTransports = base.forceTLS ? ["ws", "wss"] : ["ws"];'));
  }

  /* ── 3) الصفحات ── */
  console.log('\n══ 3) الصفحات ══');
  const B = fs.readFileSync('public/branch.html', 'utf8');
  ok('branch: ركلة الأوردرات بـtickSoon ومفيش setTimeout(900)', B.includes('ordersPoller.tickSoon();') && !B.includes('setTimeout(() => ordersPoller.tick(), 900)'));
  ok('branch: بولر الأوردرات لسه صاحي والتبويب مخفي (hiddenTick)', /new P\("\/api\/orders", \{ interval: 60000, hiddenTick: true/.test(B));
  ok('branch: كسر كاش api.js وrealtime.js', B.includes('assets/js/api.js?v=20260921rt') && B.includes('assets/js/realtime.js?v=20260921rt2'));
  for (const p of ['tiar', 'callcenter', 'pilots']) {
    ok(p + ': كسر كاش realtime.js', fs.readFileSync('public/' + p + '.html', 'utf8').includes('assets/js/realtime.js?v=20260921rt2'));
  }

  console.log('\n════════════════════════════════════════');
  console.log('REALTIME HIDDEN TAB: ' + pass + ' ناجح · ' + fail + ' فاشل');
  console.log('════════════════════════════════════════');
  process.exit(fail ? 1 : 0);
})();
