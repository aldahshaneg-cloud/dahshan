/**
 * 🚀 حارس الإقلاع: سكريبتات الصفحات بتتنفّذ **فعليًا** — مش فحص نصّي.
 *
 * ═══ ليه (واقعة 2026-09-02) ═══
 * سطر واحد `_ccNotifPoller.start()` — والبولر مالوش دالة start — رمى
 * TypeError وقت تحميل الكول سنتر وموّت كل اللي بعده في البلوك (من
 * openWaNotifyForOrder لتفاصيل الأوردر). الصفحة اشتغلت «شكلًا» والموظف
 * شاف «خطأ أثناء الحفظ» على أوردرات متسجّلة فكرّرها تلات مرات.
 * php -l مش كفاية للسيرفر، والـgrep مش كفاية للواجهة — النوع ده مايبانش
 * غير بالتنفيذ.
 *
 * ═══ بيعمل إيه ═══
 * بيبني DOM متسامح (أي عنصر بيقبل أي نداء) لكن **كائنات التطبيق حقيقية**:
 * constants.js وapi.js وباقي الأصول المحلية بتتحمّل بجد، فنداء دالة مش
 * موجودة على API أو Poller بيرمي بجد. أي استثناء top-level في أي سكريبت
 * inline = فشل بالسطر الحقيقي في الـHTML.
 *
 * بلوكات type="module" (قلب التطبيق في كل صفحة!) بتتنفّذ برضه: ملفوفة
 * IIFE بوضع صارم — نفس عزل نطاق الموديول الحقيقي — وبتتشغّل **بعد**
 * السكريبتات العادية زي ترتيب المتصفح (الموديولات deferred). أسطر
 * الـimport (فايربيز في تطبيق العميل) بتتبدّل بدُمى بنفس عدد الأسطر
 * عشان أرقام السطور تفضل حقيقية. مكتبات الـCDN دُمى — بنختبر كود
 * الصفحة مش المكتبات.
 *
 * التشغيل: node ops/test_page_boot.cjs [صفحات...]
 */
const fs = require('fs');
const vm = require('vm');

const PAGES = process.argv.length > 2
  ? process.argv.slice(2)
  : ['public/callcenter.html', 'public/branch.html', 'public/tiar.html',
     'public/store.html', 'public/customer.html',
     /* دمشق اتضافت 2026-09-12: شاشة أرشيف الطيارين وزرار الترحيل بيضيفوا
        جافاسكريبت جديد، وهي شاشة فلوس زي الباقي فتستاهل نفس الحارس. */
     'public/damascus.html'];

/* ── دمية متسامحة: أي خاصية بترجع دمية، أي نداء بيرجع دمية ── */
function tol() {
  const fn = function () { return proxy; };
  const proxy = new Proxy(fn, {
    get(t, k) {
      if (k === Symbol.toPrimitive) return () => '';
      if (k === Symbol.iterator) return function* () {};
      if (k === 'toString') return () => '';
      if (k === 'valueOf') return () => 0;
      if (k === 'then' || k === Symbol.toStringTag) return undefined;
      if (k === 'nodeType') return 1;
      return proxy;
    },
    set: () => true,
    apply: () => proxy,
    construct: () => proxy,
    has: () => true,
    deleteProperty: () => true,
  });
  return proxy;
}

function storageStub() {
  const m = new Map();
  return {
    getItem: k => (m.has(k) ? m.get(k) : null),
    setItem: (k, v) => m.set(k, String(v)),
    removeItem: k => m.delete(k),
    clear: () => m.clear(),
    key: () => null, get length() { return m.size; },
  };
}

function docStub() {
  const base = {
    getElementById: () => tol(),
    querySelector: () => tol(),
    querySelectorAll: () => [],
    getElementsByClassName: () => [],
    getElementsByTagName: () => [],
    createElement: () => tol(),
    createTextNode: () => tol(),
    createDocumentFragment: () => tol(),
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => true,
    body: tol(), head: tol(), documentElement: tol(), currentScript: tol(),
    /* readyState = loading زي المتصفح وقت تنفيذ السكريبتات العادية —
       الكود اللي بيقول «لو لسه بنحمّل استنى DOMContentLoaded» بياخد
       فرع التأجيل الحقيقي بدل ما ينده دوال الموديول قبل ما تتعرّف */
    hidden: false, visibilityState: 'visible', readyState: 'loading',
    cookie: '', title: '', fonts: { ready: Promise.resolve() },
  };
  return new Proxy(base, {
    get: (t, k) => (k in t ? t[k] : tol()),
    set: (t, k, v) => { t[k] = v; return true; },
  });
}

function buildContext() {
  const ctx = {};
  ctx.console = { log() {}, warn() {}, error() {}, info() {}, debug() {}, table() {}, group() {}, groupEnd() {} };
  ctx.document = docStub();
  ctx.localStorage = storageStub();
  ctx.sessionStorage = storageStub();
  ctx.location = {
    href: 'https://aldahshan.cloud/x.html', origin: 'https://aldahshan.cloud',
    protocol: 'https:', host: 'aldahshan.cloud', hostname: 'aldahshan.cloud',
    pathname: '/x.html', search: '', hash: '', port: '',
    reload() {}, replace() {}, assign() {}, toString() { return this.href; },
  };
  ctx.history = { pushState() {}, replaceState() {}, back() {}, forward() {}, state: null };
  ctx.navigator = {
    userAgent: 'boot-guard', language: 'ar', languages: ['ar'], onLine: true,
    platform: 'guard', maxTouchPoints: 0, clipboard: { writeText: () => Promise.resolve() },
    serviceWorker: { register: () => Promise.resolve(tol()), getRegistrations: () => Promise.resolve([]), addEventListener() {}, controller: null, ready: new Promise(() => {}) },
    geolocation: { getCurrentPosition() {}, watchPosition: () => 1, clearWatch() {} },
    sendBeacon: () => true, vibrate: () => true,
    permissions: { query: () => Promise.resolve({ state: 'prompt', addEventListener() {} }) },
  };
  ctx.screen = { width: 1920, height: 1080, availWidth: 1920, availHeight: 1080 };
  ctx.innerWidth = 1400; ctx.innerHeight = 900; ctx.devicePixelRatio = 1;
  ctx.scrollX = 0; ctx.scrollY = 0;
  ctx.alert = () => {}; ctx.confirm = () => false; ctx.prompt = () => null;
  ctx.open = () => tol(); ctx.close = () => {}; ctx.focus = () => {}; ctx.blur = () => {};
  ctx.print = () => {}; ctx.scrollTo = () => {}; ctx.scroll = () => {}; ctx.getSelection = () => tol();
  ctx.matchMedia = () => ({ matches: false, media: '', addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
  ctx.getComputedStyle = () => tol();   // getPropertyValue وأخواتها كلها بترجع دمية
  ctx.requestAnimationFrame = () => 1; ctx.cancelAnimationFrame = () => {};
  ctx.requestIdleCallback = () => 1; ctx.cancelIdleCallback = () => {};
  /* مؤقتات ساكنة: مش بننفّذ الدوال — إقلاع بس، مش تشغيل مستمر */
  ctx.setTimeout = () => 1; ctx.clearTimeout = () => {};
  ctx.setInterval = () => 1; ctx.clearInterval = () => {};
  /* fetch معلّقة للأبد — أي مسار async بيقف عند أول await بهدوء */
  ctx.fetch = () => new Promise(() => {});
  ctx.XMLHttpRequest = class { open() {} send() {} setRequestHeader() {} addEventListener() {} };
  ctx.WebSocket = class { close() {} send() {} addEventListener() {} };
  ctx.addEventListener = () => {}; ctx.removeEventListener = () => {}; ctx.dispatchEvent = () => true;
  ctx.Event = class { constructor(t) { this.type = t; } };
  ctx.CustomEvent = class extends ctx.Event { constructor(t, o) { super(t); this.detail = o && o.detail; } };
  ctx.ErrorEvent = ctx.Event; ctx.MessageEvent = ctx.Event;
  ctx.MutationObserver = class { observe() {} disconnect() {} };
  ctx.ResizeObserver = class { observe() {} disconnect() {} unobserve() {} };
  ctx.IntersectionObserver = class { observe() {} disconnect() {} unobserve() {} };
  ctx.PerformanceObserver = class { observe() {} disconnect() {} };
  ctx.Notification = class { static requestPermission() { return Promise.resolve('denied'); } };
  ctx.Notification.permission = 'denied';
  ctx.Audio = class { play() { return Promise.resolve(); } pause() {} load() {} addEventListener() {} };
  ctx.Image = class { addEventListener() {} };
  ctx.FileReader = class { readAsDataURL() {} readAsText() {} addEventListener() {} };
  ctx.DOMParser = class { parseFromString() { return docStub(); } };
  ctx.HTMLElement = class {}; ctx.Element = class {}; ctx.Node = class {};
  ctx.HTMLInputElement = class {}; ctx.HTMLSelectElement = class {};
  ctx.speechSynthesis = { speak() {}, cancel() {}, getVoices: () => [] };
  ctx.SpeechSynthesisUtterance = class {};
  ctx.AudioContext = class { createOscillator() { return tol(); } createGain() { return tol(); } resume() { return Promise.resolve(); } };
  ctx.URL = URL; ctx.URLSearchParams = URLSearchParams;
  ctx.Blob = Blob; ctx.FormData = class { append() {} };
  ctx.TextEncoder = TextEncoder; ctx.TextDecoder = TextDecoder;
  ctx.crypto = globalThis.crypto;
  ctx.performance = globalThis.performance;
  ctx.queueMicrotask = globalThis.queueMicrotask.bind(globalThis);
  ctx.structuredClone = globalThis.structuredClone;
  ctx.atob = globalThis.atob; ctx.btoa = globalThis.btoa;
  ctx.isSecureContext = true;
  ctx.frameElement = null;
  /* مكتبات CDN والـvendor — دُمى: الحارس بيختبر كود الصفحة مش المكتبات */
  ctx.L = tol(); ctx.XLSX = tol(); ctx.QRCode = tol(); ctx.Pusher = tol();
  ctx.firebase = tol(); ctx.Chart = tol(); ctx.__tol = tol;
  vm.createContext(ctx);
  /* window = الكائن العام نفسه — زي المتصفح */
  vm.runInContext('this.window = this; this.self = this; this.top = this; this.parent = this;', ctx);
  return ctx;
}

/* ── تقطيع سكريبتات الصفحة مع أرقام سطورها الحقيقية ──
   الترتيب زي المتصفح: العادية بترتيبها الأول، وبعدين الموديولات
   (الموديولات deferred — بتتنفّذ بعد اكتمال الـDOM). */
function scriptsOf(html) {
  const classic = [], modules = [];
  const re = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
  let m;
  while ((m = re.exec(html)) !== null) {
    const attrs = m[1] || '';
    const srcM = attrs.match(/\bsrc\s*=\s*"([^"]+)"/i);
    const typeM = attrs.match(/\btype\s*=\s*"([^"]+)"/i);
    const type = typeM ? typeM[1].toLowerCase() : '';
    const line = html.slice(0, m.index).split('\n').length;
    const item = { src: srcM ? srcM[1] : null, code: m[2], line };
    if (type === 'module') { item.module = true; modules.push(item); }
    else if (!type || type === 'text/javascript' || type === 'application/javascript') classic.push(item);
    // غير كده (application/json وأشباهه) → مش كود
  }
  return classic.concat(modules);
}

/* import فايربيز وأشباهه → دُمى بنفس عدد الأسطر (أرقام السطور مقدسة) */
function stubImports(code) {
  return code.replace(/(^|\n)\s*import\s+([\s\S]*?)\s+from\s+["'][^"']+["'];?/g, (all, pre, names) => {
    const ids = [];
    const brace = names.match(/\{([\s\S]*?)\}/);
    if (brace) {
      brace[1].split(',').forEach(p => {
        const id = p.trim().split(/\s+as\s+/).pop().trim();
        if (id) ids.push(id);
      });
    }
    const rest = names.replace(/\{[\s\S]*?\}/, '').replace(/,/g, ' ').trim();
    rest.split(/\s+/).forEach(w => {
      if (w && w !== '*' && w !== 'as') ids.push(w);
    });
    const pad = '\n'.repeat((all.match(/\n/g) || []).length ? (all.match(/\n/g) || []).length - (pre === '\n' ? 1 : 0) : 0);
    return pre + 'const ' + (ids.length ? ids.join('=__tol(),') + '=__tol();' : '__unused' + Math.random().toString(36).slice(2) + '=0;') + pad;
  });
}

let totalErr = 0, pagesRun = 0;
for (const page of PAGES) {
  const html = fs.readFileSync(page, 'utf8');
  const ctx = buildContext();
  const errs = [];
  let ran = 0, skipped = 0;
  for (const s of scriptsOf(html)) {
    let code, name, offset = 0;
    if (s.src) {
      if (/^https?:/i.test(s.src) || /vendor\//.test(s.src)) { skipped++; continue; } // CDN/vendor → دُمى فوق
      const p = 'public/' + s.src.replace(/^\.?\//, '').split('?')[0];
      if (!fs.existsSync(p)) { skipped++; continue; }
      code = fs.readFileSync(p, 'utf8');
      name = p;
    } else {
      code = s.code; name = page; offset = s.line - 1;
      if (!code.trim()) continue;
      if (s.module) {
        /* عزل نطاق الموديول + وضعه الصارم — من غير سطر إضافي في الأول
           عشان أرقام السطور تفضل مظبوطة */
        code = "(function(){'use strict';" + stubImports(code) + '\n})();';
      }
    }
    try {
      vm.runInContext(code, ctx, { filename: name, lineOffset: offset, timeout: 20000 });
      ran++;
    } catch (e) {
      const at = ((e.stack || '').split('\n').find(l => l.includes(name)) || '').trim();
      errs.push(`${name}${at ? ' — ' + at : ''}\n      ${e.constructor.name}: ${e.message}`);
    }
  }
  pagesRun++;
  if (errs.length) {
    totalErr += errs.length;
    console.log(`  ✗ ${page} — ${errs.length} انفجار وقت الإقلاع (سكريبتات نجحت: ${ran}):`);
    errs.forEach(x => console.log('    🔴 ' + x));
  } else {
    console.log(`  ✓ ${page} — ${ran} سكريبت اتنفّذ من غير أي استثناء (${skipped} CDN/دمية)`);
  }
}

console.log('\n' + '─'.repeat(52));
console.log(totalErr === 0
  ? `✅ الصفحات الـ${pagesRun} بتقلع من غير انفجارات top-level\n`
  : `🔴 ${totalErr} انفجار — البلوك اللي بيرمي بيموّت كل تعريفات ما بعده\n`);
process.exit(totalErr === 0 ? 0 : 1);
