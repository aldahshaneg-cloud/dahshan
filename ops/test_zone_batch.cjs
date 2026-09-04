/**
 * 🗺️ اختبار «تكويد المناطق بالجملة مايقفش عند أول تكرار».
 *
 * ═══ الباج اللي بيحرسه ═══
 * أول يوم شغل (2026-09-01) صاحب النظام بلّغ: «المدير يحدد 20 منطقة ولكن
 * لا يكوّد إلا 5 ويقول خطأ في قاعدة البيانات».
 *
 * السبب: `for (const z of zones) await api.post(...)` جوه try واحد **بره**
 * الحلقة. أول منطقة متكوّدة قبل كده بترمي → الحلقة تقف → الباقي مايتبعتش.
 * واللي اتبعت قبلها بتفضل محفوظة، فالمدير يشوف «خطأ» ويفتكر إن مافيش حاجة
 * اتحفظت. (١٦ خطأ تكرار في سجل الإنتاج يومها.)
 *
 * ═══ ليه اختبار سلوكي مش grep ═══
 * فحص نصّي يسأل «فيه try جوه الحلقة؟» بيعدّي على حالات كتير غلط: try
 * موجود بس بيعيد الرمي، أو بيمسك نوع واحد بس، أو الملخّص بيعدّ غلط.
 * الاختبار ده **بيشغّل `addZone` فعليًا** بسيرفر مزيّف بيرفض مناطق
 * معيّنة، وبيقرا: كام POST اتبعت؟ وإيه اللي المستخدم شافه؟
 *
 * التشغيل: node ops/test_zone_batch.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (w, c, got) => { c ? (pass++, console.log('  ✓ ' + w))
  : (fail++, console.log('  ✗ ' + w + (got !== undefined ? '   ← ' + got : ''))); };

/* الفحوص السلوكية (١-٥) بتشتغل على tiar.html — الـharness متظبّط عليه.
   الفحوص البنيوية (٦) بتشتغل على **الاتنين**: نفس الحلقة موجودة في
   callcenter.html، وشاشة المناطق هناك بيوصلها الأدمن (settings في PAGES،
   والمودال في الـDOM، وroutes/api.php:205 بيسمح لـadmin). */
const FILES = ['public/tiar.html', 'public/callcenter.html'];
const FILE = FILES[0];
const src = fs.readFileSync(FILE, 'utf8');

/* ── نقص دالة بعدّ الأقواس مع فهم الـtemplate literals المتداخلة ── */
function cut(anchor) {
  const start = src.indexOf(anchor);
  if (start < 0) throw new Error('مالقيتش ' + anchor);
  const open = src.indexOf('{', start + anchor.length - 1);
  const stack = ['b'];
  let i = open + 1;
  while (i < src.length) {
    if (stack[stack.length - 1] === 't') {
      const c = src[i];
      if (c === '\\') { i += 2; continue; }
      if (c === '`') { stack.pop(); i++; continue; }
      if (c === '$' && src[i + 1] === '{') { stack.push('b'); i += 2; continue; }
      i++; continue;
    }
    const c = src[i];
    if (c === '/' && src[i + 1] === '/') { const e = src.indexOf('\n', i); i = e < 0 ? src.length : e; continue; }
    if (c === '/' && src[i + 1] === '*') { const e = src.indexOf('*/', i + 2); i = e < 0 ? src.length : e + 2; continue; }
    if (c === '"' || c === "'") { const q = c; i++; while (i < src.length && src[i] !== q) { if (src[i] === '\\') i++; i++; } i++; continue; }
    if (c === '`') { stack.push('t'); i++; continue; }
    if (c === '{') { stack.push('b'); i++; continue; }
    if (c === '}') { stack.pop(); i++; if (!stack.length) return src.slice(start, i); continue; }
    i++;
  }
  throw new Error('ماقدرتش أقفل ' + anchor);
}

/* ── بيئة مزيّفة: بتسجّل كل POST وكل توست ── */
function makeEnv(zones, rejectSet, rejectKind) {
  const posts = [], toasts = [];
  let formReset = false, modalClosed = false;
  const el = (id) => ({ id, value: '', textContent: '', disabled: false, style: {},
                        reset() { formReset = true; }, options: [], selectedIndex: 0 });
  const env = {
    document: {
      getElementById: (id) => (id === 'addZoneForm' ? { reset() { formReset = true; } } : el(id)),
      querySelector: () => null,
      querySelectorAll: () => [],
    },
    api: {
      post: async (path, z) => {
        posts.push(z.areaName);
        if (rejectSet.has(z.areaName)) {
          const e = new Error(rejectKind === 'dup'
            ? `«${z.areaName}» موجودة قبل كده في نفس فرع التوصيل — غيّر الاسم أو اختار فرع تاني`
            : 'خطأ في قاعدة البيانات');
          e.status = rejectKind === 'dup' ? 400 : 500;
          throw e;
        }
        return { ok: true, id: 1 };
      },
      put: async () => ({ ok: true }),
    },
    showToast: (m, t) => toasts.push({ m, t }),
    closeModal: () => { modalClosed = true; },
    resetEditState: () => {},
    _resetZoneFormUI: () => {},
    esc: (v) => String(v == null ? '' : v),
    _branchNameOf: () => 'فرع',
    _collectZones: () => zones,
    window: { _editState: { active: false, type: null } },
  };
  return { env, posts, toasts, get formReset() { return formReset; }, get modalClosed() { return modalClosed; } };
}

async function runAddZone(zones, rejectNames, rejectKind = 'dup') {
  const h = makeEnv(zones, new Set(rejectNames), rejectKind);
  const body = cut('window.addZone = async function () {');
  const sandbox = new Proxy(h.env, {
    has: () => true,
    get: (t, k) => (k in t ? t[k] : undefined),
    set: (t, k, v) => { t[k] = v; return true; },
  });
  /* deliveryBranchId لازم يبقى غير فاضي عشان الدالة ماترجعش بدري */
  h.env.document.getElementById = (id) => {
    if (id === 'zoneDeliveryBranch') return { value: '54', options: [], selectedIndex: 0 };
    if (id === 'addZoneForm') return { reset() { h.env.__formReset = true; } };
    if (id === 'submitZoneBtn') return { disabled: false, textContent: '' };
    return { value: '', textContent: '', style: {}, options: [], selectedIndex: 0 };
  };
  const fn = new Function('__s', `with (__s) { ${body}\n return window.addZone; }`)(sandbox);
  await fn();
  return h;
}

const mk = (names) => names.map(n => ({ areaName: n, price: 70, deliveryBranchId: '54', sourceBranchId: '53' }));

(async () => {

console.log('\n══ 1) 🔴 الحالة المبلَّغة: ٢٠ منطقة، الخامسة متكوّدة قبل كده ══');
{
  const names = Array.from({ length: 20 }, (_, i) => 'منطقة' + (i + 1));
  const h = await runAddZone(mk(names), ['منطقة5']);
  ok('🔴 كل الـ٢٠ اتبعتوا للسيرفر (الدفعة ماوقفتش)', h.posts.length === 20,
     h.posts.length + ' اتبعت — وقفت عند «' + (h.posts[h.posts.length - 1] || '?') + '»');
  ok('اللي بعد المتكرّرة اتبعتوا برضه', h.posts.includes('منطقة20'));
  const t = h.toasts.map(x => x.m).join(' | ');
  ok('الملخّص بيقول اتكوّدت ١٩', /اتكوّدت 19/.test(t), t);
  ok('والملخّص بيقول واحدة متكوّدة قبل كده', /1 متكوّدة قبل كده/.test(t), t);
  ok('التوست نجاح مش خطأ (فيه شغل اتعمل)', h.toasts.some(x => x.t === 'success'), JSON.stringify(h.toasts));
}

console.log('\n══ 2) عدة مناطق متكرّرة في نفس الدفعة ══');
{
  const names = Array.from({ length: 10 }, (_, i) => 'م' + (i + 1));
  const h = await runAddZone(mk(names), ['م1', 'م5', 'م10']);
  ok('كل الـ١٠ اتبعتوا', h.posts.length === 10, h.posts.length);
  const t = h.toasts.map(x => x.m).join(' | ');
  ok('اتكوّدت ٧', /اتكوّدت 7/.test(t), t);
  ok('و٣ متكوّدة قبل كده', /3 متكوّدة قبل كده/.test(t), t);
}

console.log('\n══ 3) كلهم متكوّدين — رسالة واضحة والمودال يفضل مفتوح ══');
{
  const names = ['أ', 'ب', 'ج'];
  const h = await runAddZone(mk(names), names);
  ok('التلاتة اتبعتوا', h.posts.length === 3);
  const t = h.toasts.map(x => x.m).join(' | ');
  ok('بيقول إن كلهم متكوّدين', /كل المناطق المختارة \(3\)/.test(t), t);
  ok('توست خطأ', h.toasts.some(x => x.t === 'error'));
  ok('🔴 المودال مقفلش (عشان يعدّل اختياره)', !h.modalClosed);
}

console.log('\n══ 4) 🔴 فشل حقيقي (مش تكرار) بيتفرّق عن التكرار ══');
{
  const names = ['س1', 'س2', 'س3', 'س4'];
  const h = await runAddZone(mk(names), ['س2'], 'other');
  ok('كل الـ٤ اتبعتوا برضه', h.posts.length === 4, h.posts.length);
  const t = h.toasts.map(x => x.m).join(' | ');
  ok('اتكوّدت ٣', /اتكوّدت 3/.test(t), t);
  ok('🔴 اتحسبت «فشلت» مش «متكوّدة قبل كده»', /1 فشلت/.test(t) && !/متكوّدة قبل كده/.test(t), t);
  ok('واسم اللي فشل ظاهر للمستخدم', /س2/.test(t), t);
  ok('🔴 المودال مقفلش (فشل حقيقي)', !h.modalClosed);
}

console.log('\n══ 5) الحالة السليمة: مافيش تكرار خالص ══');
{
  const names = ['ن1', 'ن2', 'ن3'];
  const h = await runAddZone(mk(names), []);
  ok('التلاتة اتبعتوا', h.posts.length === 3);
  const t = h.toasts.map(x => x.m).join(' | ');
  ok('ملخّص نجاح نضيف', /اتكوّدت 3/.test(t) && !/فشلت/.test(t) && !/متكوّدة/.test(t), t);
  ok('توست نجاح', h.toasts.some(x => x.t === 'success'));
  ok('🔴 المودال اتقفل (كله تمام)', h.modalClosed);
}

console.log('\n══ 6) 🔴 الحلقة القديمة مارجعتش — في كل واجهة ══');
{
  /* التمشيط بيستبدل التعليق بمسافات بنفس الطول (الأطوال بتفضل مظبوطة).
     السترنجات **مابتتمشّطش** عن قصد: "/api/zones" نفسها سترنج والفحص
     محتاجها. */
  const strip = t => t
    .replace(/\/\*[\s\S]*?\*\//g, m => ' '.repeat(m.length))
    .replace(/(^|[^:"'`\\])\/\/[^\n]*/g, (m, p) => p + ' '.repeat(m.length - p.length));

  for (const f of FILES) {
    const code = strip(fs.readFileSync(f, 'utf8'));
    const nm = f.replace('public/', '');

    ok(nm + ': مافيش حلقة عارية',
       !/for \([^)]*\)\s*await api\.post\(\s*["']\/api\/zones["']/.test(code));

    /* فحص بنيوي بدل regex بفجوة ثابتة: بنقص جسم الحلقة بعدّ الأقواس. */
    const at = code.search(/for \(const z of zones\)\s*\{/);
    ok(nm + ': فيه حلقة مناطق', at > -1);
    if (at > -1) {
      const open = code.indexOf('{', at);
      let d = 0, end = -1;
      for (let j = open; j < code.length; j++) {
        if (code[j] === '{') d++;
        else if (code[j] === '}') { d--; if (!d) { end = j; break; } }
      }
      const body = end > -1 ? code.slice(open, end) : '';
      const iTry = body.indexOf('try'), iPost = body.indexOf('api.post');
      ok(nm + ': 🔴 try جوه الحلقة وقبل الـpost', iTry > -1 && iPost > -1 && iTry < iPost,
         'try@' + iTry + ' post@' + iPost);
      ok(nm + ': وفيه catch جوه الحلقة', /catch\s*\(/.test(body));
      ok(nm + ': والـcatch مابيعيدش الرمي', !/\bthrow\b/.test(body),
         'throw جوه الحلقة = الدفعة هتقف تاني');
    }

    /* الكشف لازم يفضل بنيوي (الحالة) مش على صياغة الرسالة بس */
    ok(nm + ': بيفرّق التكرار بحالة 409', /e\.status === 409/.test(code));
  }
}

console.log('\n══ 7) جانب السيرفر: التكرار برسالة عربية مش «خطأ في قاعدة البيانات» ══');
{
  /* 🔴 التمشيط إجباري: التعليق اللي فوق zonesCreate فيه كلمة «1062»
     ونص «موجودة قبل كده» — فالفحص على الخام كان ممكن يبقى أخضر والكود
     نفسه متشال. (نفس فخ «الحارس بيمسك تعليقه هو».) */
  const phpRaw = fs.readFileSync('app/Http/Controllers/Api/EntitiesController.php', 'utf8');
  const php = phpRaw
    .replace(/\/\*[\s\S]*?\*\//g, m => ' '.repeat(m.length))
    .replace(/(^|[^:"'])\/\/[^\n]*/g, (m, p) => p + ' '.repeat(m.length - p.length));
  const at = php.indexOf('function zonesCreate');
  const fn = php.slice(at, php.indexOf('function zonesUpdate', at));
  ok('بيمسك QueryException', /catch \(QueryException \$e\)/.test(fn));
  ok('🔴 بيفحص 1062 تحديدًا (بعد تمشيط التعليقات)', /1062/.test(fn));
  /* getCode() بترجّع '23000' (حالة SQLSTATE) مش 1062 — لو حد بدّلها
     الفحص بيبقى دايمًا false والتكرار يرجع 500 تاني. */
  ok('🔴 بيقرا errorInfo مش getCode', /errorInfo/.test(fn) && !/getCode\(\)/.test(fn));
  ok('وبيرمي رسالة عربية مفهومة', /ApiException\('/.test(fn) && /موجودة قبل كده/.test(fn));
  ok('🔴 بحالة 409 (كشف بنيوي للواجهة)', /ApiException\([^;]*,\s*409\)/.test(fn),
     'من غيرها الواجهة بتعتمد على مطابقة نص الرسالة بس');
  ok('ومابيعملش UPDATE للسعر بصمت', !/ON DUPLICATE KEY UPDATE/i.test(fn),
     'دوس على السعر = فلوس غلط على العميل');
}

console.log('\n════════════════════════════════════════');
console.log('ZONE BATCH: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);

})().catch(e => { console.log('\n🔴 الاختبار نفسه وقع: ' + e.message + '\n' + e.stack); process.exit(1); });
