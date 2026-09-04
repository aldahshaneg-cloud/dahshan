/**
 * 📤 اختبار «إرسال الطلب مايكدبش».
 *
 * ═══ الباج (2026-09-01، الإنتاج شغّال) ═══
 * صاحب النظام: «وأنا بضغط على الإرسال قال حصل خطأ ولكن فعلاً حصل إرسال».
 *
 * الدليل من سجل الإنتاج: كل عميل بعت مرتين بفارق ثواني والاتنين رجعوا 200،
 * والأوردرات مكرّرة حرفيًا (527087/527088 · 527091/527092). يعني السيرفر
 * نجح، والواجهة قالت «خطأ»، فالزبون ضغط تاني ودفع مرتين.
 *
 * السبب: `const receipt` معرّفة **جوه** الـ`.map()` بتاعة الطرود، وبتتستعمل
 * **بره** في `if (!receipt) deliveries.forEach(...)` — نطاق البلوك بيخلّيها
 * ترمي `ReferenceError` بعد ما الأوردر يتسجّل، والـcatch تقول «تعذّر إرسال
 * الطلب».
 *
 * ═══ ليه سلوكي ═══
 * فحص نصّي «هل receipt مستعملة بره نطاقها؟» صعب يتكتب صح وسهل يتحايل عليه.
 * ده **بيشغّل `submitOrder` الحقيقية** بسيرفر مزيّف بينجح، وبيقرا: العميل
 * شاف نجاح ولا خطأ؟ واتبعت كام مرة؟
 *
 * التشغيل: node ops/test_customer_submit.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (w, c, got) => { c ? (pass++, console.log('  ✓ ' + w))
  : (fail++, console.log('  ✗ ' + w + (got !== undefined ? '   ← ' + got : ''))); };

const FILE = 'public/customer.html';
const src = fs.readFileSync(FILE, 'utf8');

/* تمشيط جوه <script> بس — الماركب فيه `accept="image/*"` واللي بتتقري
   كبداية تعليق بلوك وتبلع نص الملف (لسعتنا قبل كده). */
const blank = m => m.replace(/[^\n]/g, ' ');
const stripJs = t => t
  .replace(/\/\*[\s\S]*?\*\//g, blank)
  .replace(/(^|[^:"'`\\])\/\/[^\n]*/g, (m, p) => p + blank(m.slice(p.length)));
const code = (() => {
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  const parts = []; let last = 0, m;
  while ((m = re.exec(src)) !== null) {
    const b = m.index + m[0].indexOf('>') + 1;
    parts.push(src.slice(last, b), stripJs(m[2]));
    last = b + m[2].length;
  }
  parts.push(src.slice(last));
  return parts.join('');
})();

const cutFn = (anchor, endAnchor) => {
  const at = code.indexOf(anchor);
  if (at < 0) throw new Error('مالقيتش ' + anchor);
  return code.slice(at, code.indexOf(endAnchor, at));
};

/* ── تشغيل submitOrder الحقيقية ── */
async function run({ failCreate = false, breakShowDone = false } = {}) {
  const posts = [], toasts = [];
  let wentTo = null;
  const dom = {
    submitBtn: { disabled: false, textContent: '' },
    sndName: { value: 'محل نظارات' }, sndPhone: { value: '01013194229' },
    doneNum: { textContent: '' }, doneCodes: { innerHTML: '' },
    odNote: { value: '' },
  };
  const rcv = { name: 'عبد الرحمن سعد', phone: '01025459966', phone2: '', address: 'ش 1',
                zoneId: 'z1', lat: null, lng: null, cod: 0, images: [], receipt: false };
  const env = {
    S: { branches: { b1: { name: 'حي الجامعة' } }, page: 'new', profile: { id: 'c1', displayNameAr: 'محل نظارات', phone1: '01013194229' },
         wallet: { balance: 0 }, draft: { receivers: [rcv], senderZone: { id: 'z0', price: 20 },
         kind: 'عادي', pieces: 1, sndLat: null, sndLng: null } },
    $: id => dom[id] || null,
    api: {
      post: async (p, b) => {
        posts.push(p);
        if (p === '/api/customer/orders') {
          if (failCreate) throw new Error('السيرفر وقع');
          const o = { id: 'o1', orderNum: 'HAL-260901-002', deliveries: b.deliveries };
          return { ok: true, order: o, orders: [o] };
        }
        return { ok: true };
      },
    },
    toast: (m, t) => toasts.push({ m, t }),
    go: (p) => { wentTo = p; if (breakShowDone && p === 'done') throw new Error('عرض مكسور'); },
    readReceiverBlocks: () => {},
    allParcelImages: () => [],
    isBlocked: () => false, isOpenNow: () => true,
    showBlocked: () => {}, showClosed: () => {}, wizNext: () => {}, showRcv: () => {},
    zoneLabel: z => (z && z.name) || 'منطقة',
    getAddr: () => 'ش 1',
    mergeOrders: () => {},
    saveReceiver: () => {},
    /* المساعدات اللي submitOrder بتعتمد عليها — مستخرجة من جسم الدالة
       نفسها عشان الـharness مايفوتش حاجة ويطلع خطأ مضلّل. */
    zoneBranchId: () => 'b1',
    zoneBranchName: () => 'حي الجامعة',
    routeBranch: () => 'b1',
    findZone: id => ({ id, price: 30, name: 'حي الاشجار' }),
    /* سعر الطرد الفعلي (خاصية تعديل السعر 2026-09-02) — نفس منطق
       الدالة الحقيقية بأرضية سعر الزون */
    rcvPriceOf: r => {
      const zp = 30;
      return r && r.customPrice != null && r.customPrice !== ''
        ? Math.max(zp, Number(r.customPrice) || 0) : zp;
    },
    confetti: () => {},
    parcelCodeOf: (n) => n,
    trackUrlOf: () => 'https://x/y',
    esc: v => String(v == null ? '' : v),
    QRCode: function () { throw new Error('QR مش محمّل'); },
    console: { error: () => {} },
    document: { getElementById: id => dom[id] || null },
    window: {},
  };
  env.QRCode.CorrectLevel = { M: 0 };
  /* showDone الحقيقية محتاجة عناصر كتير — بنستبدلها بواحدة بتنده go
     (وبتكسر لو طلبنا)، عشان نختبر مسار الخطأ بعد التسجيل. */
  env.showDone = () => { env.go('done'); };

  const body = cutFn('async function submitOrder()', '\nwindow.submitOrder');
  /* `has` لازم ترجّع الموجود في البيئة **بس**. لو رجّعت true لكل حاجة،
     الـ`with` بيخطف حتى `Number` و`Array` و`JSON` ويرجّعهم undefined —
     فبتطلع أخطاء مضلّلة زي «Number is not a function». */
  const sandbox = new Proxy(env, {
    has: (t, k) => k in t,
    get: (t, k) => t[k],
    set: (t, k, v) => { t[k] = v; return true; },
  });
  const fn = new Function('__s', `with (__s) { ${body}\n return submitOrder; }`)(sandbox);
  let threw = null;
  try { await fn(); } catch (e) { threw = e; }
  return { posts, toasts, wentTo, threw, dom };
}

(async () => {

console.log('\n══ 1) 🔴 السيناريو المبلَّغ: السيرفر بينجح ══');
{
  const r = await run();
  ok('🔴 الدالة مارمتش', !r.threw, r.threw && (r.threw.constructor.name + ': ' + r.threw.message));
  const errs = r.toasts.filter(t => t.t === 'err');
  ok('🔴 مافيش رسالة خطأ للعميل', errs.length === 0, JSON.stringify(errs));
  ok('🔴 مافيش «تعذّر إرسال الطلب»', !r.toasts.some(t => /تعذّر إرسال/.test(t.m)),
     JSON.stringify(r.toasts));
  ok('اتبعت مرة واحدة بس', r.posts.filter(p => p === '/api/customer/orders').length === 1);
  ok('ووصل لشاشة النجاح', r.wentTo === 'done', String(r.wentTo));
}

console.log('\n══ 2) 🔴 فشل حقيقي لسه بيبان خطأ ══');
{
  const r = await run({ failCreate: true });
  ok('مافيش رمي', !r.threw);
  ok('🔴 العميل شاف «تعذّر إرسال الطلب»', r.toasts.some(t => /تعذّر إرسال/.test(t.m) && t.t === 'err'),
     JSON.stringify(r.toasts));
  ok('وماوصلش لشاشة النجاح', r.wentTo !== 'done');
}

console.log('\n══ 3) 🔴 رمي بعد التسجيل: ممنوع يقول «تعذّر الإرسال» ══');
{
  const r = await run({ breakShowDone: true });
  ok('مافيش رمي للخارج', !r.threw);
  ok('🔴 مافيش «تعذّر إرسال الطلب» (الأوردر اتسجّل!)',
     !r.toasts.some(t => /تعذّر إرسال/.test(t.m)), JSON.stringify(r.toasts));
  ok('🔴 العميل اتقاله إن الطلب اتسجّل', r.toasts.some(t => /اتسجّل/.test(t.m) && t.t === 'ok'),
     JSON.stringify(r.toasts));
  ok('واتحوّل لقائمة طلباته', r.wentTo === 'orders', String(r.wentTo));
  ok('واتبعت مرة واحدة بس', r.posts.filter(p => p === '/api/customer/orders').length === 1);
}

console.log('\n══ 4) الكود: العلامة في مكانها ══');
{
  const fn = cutFn('async function submitOrder()', '\nwindow.submitOrder');
  ok('مافيش استعمال receipt خارج نطاقه', !/if \(!receipt\)/.test(fn));
  ok('🔴 حفظ المستلمين بيقرا d.fromReceipt',
     /deliveries\.forEach\(d => \{ if \(!d\.fromReceipt\) saveReceiver\(d\); \}\);/.test(fn));
  const iPost = fn.indexOf('api.post("/api/customer/orders"');
  const iMark = fn.indexOf('orderCreated = true');
  const iCatch = fn.indexOf('if (orderCreated)');
  ok('🔴 العلامة بعد الـPOST مش قبله', iPost > -1 && iMark > iPost,
     'post@' + iPost + ' mark@' + iMark);
  ok('والـcatch بيقراها', iCatch > iMark);
  ok('ورسالة الفشل الحقيقي لسه موجودة', /toast\("تعذّر إرسال الطلب: "/.test(fn));
}

console.log('\n════════════════════════════════════════');
console.log('CUSTOMER SUBMIT: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);

})().catch(e => { console.log('\n🔴 الاختبار نفسه وقع: ' + e.message + '\n' + e.stack); process.exit(1); });
