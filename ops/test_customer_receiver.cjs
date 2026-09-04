/**
 * 👤 اختبار «بيانات المستلم في تطبيق العميل».
 *
 * ═══ باج ١: الكتابة بتتمسح فجأة ═══
 * بلاغ صاحب النظام 2026-09-01 (الإنتاج شغّال): «أكتب بيانات المستلم وتُمسح
 * بشكل مفاجئ».
 *
 * السبب: حقول المستلم (`rcvName-i` · `rcvPhone-i` · `rcvPhone2-i`) **مالهاش
 * أي oninput** — الكتابة عايشة في الـDOM بس لحد ما `readReceiverBlocks()`
 * تتنده. و`renderReceiverBlocks()` بتعمل `box.innerHTML = …` من `S.draft`.
 * فبولّر `/api/customer/me` (كل ٦٠ث) كان بيعيد الرسم **من غير مزامنة**
 * و**بغض النظر عن إن حاجة اتغيّرت** → كل اللي اتكتب بيروح.
 *
 * ═══ باج ٢ (طلب صاحب النظام) ═══
 * «اعمل زرار في بيانات المستلم يضع بيانات صاحب التطبيق» — صاحب الحساب ممكن
 * يكون هو المستلم. الشيب موجود في بلوك المُرسِل وناقص في المستلم.
 *
 * ═══ ليه سلوكي ═══
 * فحص نصّي «فيه readReceiverBlocks قبل render؟» بيعدّي على شرط مقلوب أو
 * على تعليق. ده بيشغّل `onChange` بتاع البولّر فعليًا على DOM مزيّف فيه
 * كتابة، وبيقرا: الكتابة عاشت ولا اتمسحت؟
 *
 * التشغيل: node ops/test_customer_receiver.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (w, c, got) => { c ? (pass++, console.log('  ✓ ' + w))
  : (fail++, console.log('  ✗ ' + w + (got !== undefined ? '   ← ' + got : ''))); };

const FILE = 'public/customer.html';
const src = fs.readFileSync(FILE, 'utf8');

/* بنمشّط التعليقات قبل أي فحص نصّي — التعليقات هنا بتشرح الباج نفسه
   وبتذكر أسماء الدوال، فالفحص على الخام ممكن يبقى أخضر عليها.
   ⚠️ لازم نحافظ على السطور الجديدة: `' '.repeat(len)` بتحوّل الـ\n جوه
   التعليقات لمسافات فأرقام السطور بتتزحلق (لقيتها بالتجربة). */
const blank = m => m.replace(/[^\n]/g, ' ');
const stripJs = t => t
  .replace(/\/\*[\s\S]*?\*\//g, blank)
  .replace(/(^|[^:"'`\\])\/\/[^\n]*/g, (m, p) => p + blank(m.slice(p.length)));

/* 🔴 التمشيط جوه <script> **بس**. الماركب فيه `accept="image/*"` — والـ`/*`
   دي كانت بتتقري كبداية تعليق بلوك وتبلع ٤ آلاف حرف بعدها (منهم
   `id="sndSaved"`)، فالفحوص كانت بتشتغل على نص ممسوح وتقع كذبًا.
   الأطوال بتتحافظ (المسافات بدل الحروف والسطور الجديدة زي ما هي) عشان
   الفحوص اللي بتقارن مواضع تفضل مظبوطة. */
const code = (() => {
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  const parts = []; let last = 0, m;
  while ((m = re.exec(src)) !== null) {
    const bodyStart = m.index + m[0].indexOf('>') + 1;
    parts.push(src.slice(last, bodyStart), stripJs(m[2]));
    last = bodyStart + m[2].length;
  }
  parts.push(src.slice(last));
  return parts.join('');
})();
if (code.length !== src.length) throw new Error('التمشيط غيّر الطول — المواضع مش هتظبط');

/* ── نقص الـonChange بتاع بولّر me ── */
function pollerOnChange() {
  const at = code.indexOf('new api.Poller("/api/customer/me"');
  if (at < 0) throw new Error('مالقيتش بولّر me');
  const oc = code.indexOf('onChange:', at);
  const open = code.indexOf('{', oc);
  let d = 0;
  for (let j = open; j < code.length; j++) {
    if (code[j] === '{') d++;
    else if (code[j] === '}') { d--; if (!d) return code.slice(open, j + 1); }
  }
  throw new Error('ماقدرتش أقفل onChange');
}

/* ═══ 1) 🔴 السيناريو المبلَّغ: العميل بيكتب والبولّر بيضرب ═══ */
console.log('\n══ 1) 🔴 الكتابة بتعيش لما البولّر يشتغل ══');
{
  const body = pollerOnChange();
  const dom = { 'rcvName-0': 'أحمد المستلم', 'rcvPhone-0': '01012345678', 'rcvPhone2-0': '' };
  let rendered = 0, synced = 0;

  const S = { page: 'new', profile: {}, wallet: {}, cod: { allowed: false, minDelivered: 3, delivered: 1 },
              draft: { receivers: [{ name: '', phone: '', phone2: '' }] } };
  const env = {
    S,
    codLocked: () => (S.cod ? !S.cod.allowed : false),
    readReceiverBlocks: () => { synced++; const r = S.draft.receivers[0];
      r.name = dom['rcvName-0']; r.phone = dom['rcvPhone-0']; r.phone2 = dom['rcvPhone2-0']; },
    renderReceiverBlocks: () => { rendered++; const r = S.draft.receivers[0];
      /* إعادة الرسم بتبني الـDOM من الحالة — ده جوهر الباج */
      dom['rcvName-0'] = r.name || ''; dom['rcvPhone-0'] = r.phone || ''; dom['rcvPhone2-0'] = r.phone2 || ''; },
    showBlocked: () => {}, renderHome: () => {}, renderAccount: () => {},
  };
  /* `has: () => true` بيخلّي الـwith يخطف **كل** اسم — بما فيهم `d` بتاع
     الحمولة، فبيرجع undefined بدل الوسيط. بنستثنيه صراحةً. */
  const sandbox = new Proxy(env, { has: (t, k) => k !== 'd',
    get: (t, k) => (k in t ? t[k] : undefined), set: (t, k, v) => { t[k] = v; return true; } });
  const run = (d) => new Function('__s', 'd', `with (__s) { ${body.slice(1, -1)} }`)(sandbox, d);

  /* البوابة **ماتغيّرتش** — الحالة الشايعة: البولّر بيضرب كل ٦٠ث والعميل بيكتب */
  run({ customer: {}, wallet: {}, cod: { allowed: false, minDelivered: 3, delivered: 1 } });
  ok('🔴 اسم المستلم عاش', dom['rcvName-0'] === 'أحمد المستلم', JSON.stringify(dom['rcvName-0']));
  ok('🔴 رقم المستلم عاش', dom['rcvPhone-0'] === '01012345678', JSON.stringify(dom['rcvPhone-0']));
  ok('ومافيش إعادة رسم أصلًا (الفوكس مايضيعش)', rendered === 0, 'اترسم ' + rendered + ' مرة');

  /* عشرين دقيقة كتابة = ٢٠ ضربة بولّر */
  for (let k = 0; k < 20; k++) run({ customer: {}, cod: { allowed: false, minDelivered: 3, delivered: 1 } });
  ok('🔴 بعد ٢٠ ضربة بولّر الكتابة لسه موجودة', dom['rcvName-0'] === 'أحمد المستلم', dom['rcvName-0']);
  ok('وصفر إعادة رسم', rendered === 0, String(rendered));

  /* البوابة **اتفتحت** — لازم يعيد الرسم، بس بعد مزامنة */
  run({ customer: {}, cod: { allowed: true, minDelivered: 3, delivered: 3 } });
  ok('البوابة اتفتحت → اترسم مرة', rendered === 1, String(rendered));
  ok('🔴 وزامن قبل ما يرسم', synced === 1, String(synced));
  ok('🔴 والكتابة عاشت رغم إعادة الرسم', dom['rcvName-0'] === 'أحمد المستلم' && dom['rcvPhone-0'] === '01012345678',
     JSON.stringify(dom));
}

/* ═══ 2) الشرط مكتوب صح في المصدر ═══ */
console.log('\n══ 2) شرط تغيّر البوابة والمزامنة ══');
{
  const body = pollerOnChange();
  ok('بيلقط حالة البوابة قبل التحديث', /wasCodLocked\s*=/.test(body));
  ok('🔴 وبيقارنها بعد التحديث', /wasCodLocked !== codLocked\(\)/.test(body));
  const iRead = body.indexOf('readReceiverBlocks()'), iRender = body.indexOf('renderReceiverBlocks()');
  ok('🔴 المزامنة قبل إعادة الرسم', iRead > -1 && iRender > -1 && iRead < iRender,
     'read@' + iRead + ' render@' + iRender);
}

/* ═══ 3) 🔴 كل مواضع إعادة الرسم بتزامن الأول ═══ */
console.log('\n══ 3) 🔴 مافيش إعادة رسم بلا مزامنة ══');
{
  /* القاعدة موثّقة في الملف نفسه: «readReceiverBlocks الأول — إعادة الرسم
     بتبني الـHTML من الحالة». الفحص ده بيمنع أي موضع جديد ينساها. */
  const sites = [];
  let p = -1;
  while ((p = code.indexOf('renderReceiverBlocks()', p + 1)) > -1) {
    if (code.slice(Math.max(0, p - 30), p).includes('function ')) continue;  // التعريف نفسه
    sites.push(p);
  }
  ok('فيه مواضع إعادة رسم يتفحصوا', sites.length >= 5, String(sites.length));
  /* موضع إعادة الرسم بيبقى آمن لو حصل قبله واحد من اتنين جوه نفس السياق:
       • `readReceiverBlocks()` — اتزامن اللي المستخدم كتبه
       • `startNewOrder()`      — الـdraft اتصفّر أصلًا فمافيش كتابة تضيع
     (اتنين من المواضع رسم أولي: بناء الفورم، و`sendToClient` اللي بينده
      startNewOrder قبل ما يملا. الاتنين مالهمش كتابة في الخطر.) */
  /* الدالة الحاوية — `startNewOrder` نفسها بتبني الفورم من draft متصفّر */
  const enclosing = (at) => {
    const head = code.slice(0, at).split('\n');
    for (let i = head.length - 1; i >= 0; i--)
      if (/^(async )?function |^window\.[A-Za-z_$]+ *= *(async )?function/.test(head[i]))
        return (head[i].match(/function\s+([A-Za-z_$][\w$]*)/) || [, '?'])[1];
    return '?';
  };
  const bad = [];
  for (const at of sites) {
    const before = code.slice(Math.max(0, at - 900), at);
    const safe = /readReceiverBlocks\(\)/.test(before)      // اتزامن
              || /startNewOrder\(\)/.test(before)            // الـdraft اتصفّر قبله
              || enclosing(at) === 'startNewOrder';          // هو نفسه بناء الفورم
    if (!safe) bad.push('سطر ' + code.slice(0, at).split('\n').length + ' في ' + enclosing(at));
  }
  ok('🔴 كل موضع بيرسم بعد مزامنة أو بعد تصفير', bad.length === 0, bad.join(' · '));
}

/* ═══ 4) زرار «بياناتي» في بلوك المستلم ═══ */
console.log('\n══ 4) 👤 زرار «بياناتي» في بيانات المستلم ══');
{
  ok('الشيب موجود في الماركب', /data-me="1">👤 بياناتي/.test(code));
  ok('🔴 موجود في المُرسِل والمستلم (٢)', (code.match(/data-me="1"/g) || []).length === 2,
     String((code.match(/data-me="1"/g) || []).length));
  ok('🔴 مابيختفيش لو مافيش مستلمين سابقين',
     !/if \(!picks\.length\) \{ box\.innerHTML = ""; return; \}/.test(code),
     'الـreturn المبكر لسه موجود');

  /* بيملّي إيه فعلًا — نقص فرع data-me من renderSavedPicks */
  const at = code.indexOf('$("rcvName-" + i).value   = p.displayNameAr');
  ok('بيملّي من S.profile', at > -1);
  const blk = at > -1 ? code.slice(at, at + 700) : '';
  for (const [what, re] of [
    ['الاسم',        /rcvName-" \+ i\)\.value\s+= p\.displayNameAr/],
    ['رقم الهاتف',   /rcvPhone-" \+ i\)\.value\s+= p\.phone1/],
    ['الرقم الإضافي', /rcvPhone2-" \+ i\)\.value = p\.phone2/],
    ['العنوان',      /setAddr\("rcvAddrW-" \+ i, p\.address/],
    ['المنطقة',      /p\.defaultZoneId != null/],
    ['الإحداثيات',    /t\.lat = p\.lat/],
  ]) ok('  بيملّي ' + what, re.test(blk), 'مش موجود');
  ok('وبينده onReceiverZone عشان السعر يتحسب', /onReceiverZone\(i\)/.test(blk));

  /* 🔴 مكان الزرار جزء من الميزة مش تفصيلة شكل: لو رجع تحت الخانات،
     العميل بيكتب بياناته بالإيد كلها وبعدين يكتشف إن فيه زرار بيملاها —
     ودي بالظبط الشكوى اللي اتصلّحت في فورم المُرسِل قبل كده وفي المستلم
     يوم 2026-09-01. */
  const at2 = code.indexOf('function receiverBlockHtml');
  const fn2 = code.slice(at2, code.indexOf('\n}', code.indexOf('return `', at2)));
  const iHead = fn2.indexOf('class="rcv-hd"');
  const iSaved = fn2.indexOf('id="rcvSaved-');
  const iName = fn2.indexOf('id="rcvName-');
  ok('🔴 الزرار فوق — قبل خانة الاسم', iSaved > -1 && iName > -1 && iSaved < iName,
     'saved@' + iSaved + ' name@' + iName);
  ok('وبعد رأس البلوك', iHead > -1 && iHead < iSaved);
  /* وفورم المُرسِل لسه شيبه فوق في الرأس */
  ok('وشيب المُرسِل لسه في رأس بطاقته',
     /<div id="sndSaved" style="margin-inline-start:auto"><\/div>/.test(code));
}

/* ═══ 4ب) 🔴 setAddr بتشتغل في الوضع المبسّط ═══
   عنوان المستلم مبسّط (`simple:true`) — بيرسم `-detail` بس من غير `-gov`.
   و`setAddr` كانت بتعمل `if (!g) return;` يعني **مابتكتبش حاجة** خالص،
   فزرار «بياناتي» كان بيملا الاسم والتليفون ويسيب العنوان فاضي. وأربع
   مواضع تانية كانت ميتة بنفس السبب. */
console.log('\n══ 4ب) 🔴 setAddr في الوضع المبسّط (عنوان المستلم) ══');
{
  const at = code.indexOf('function setAddr');
  /* +2 عشان القوس اللي بيقفل الدالة يدخل — من غيره الجسم ناقص */
  const body = code.slice(at, code.indexOf('\n}', at) + 2);
  const fn = new Function('$', 'EG_GOVERNORATES', 'fillCities',
    body + '\nreturn setAddr;')(
      (id) => dom[id] || null,
      ['الدقهلية', 'القاهرة'],
      () => {});

  /* الوضع المبسّط: `-detail` بس */
  var dom = { 'w-detail': { value: '' } };
  fn('w', '٧ ش الجلاء، المنصورة');
  ok('🔴 بتكتب العنوان في الخانة المبسّطة', dom['w-detail'].value === '٧ ش الجلاء، المنصورة',
     JSON.stringify(dom['w-detail'].value));
  fn('w', '');
  ok('والنص الفاضي مابيمسحش المكتوب', dom['w-detail'].value === '٧ ش الجلاء، المنصورة');

  /* الوضع الكامل (المُرسِل): لازم يفضل زي ما هو */
  dom = { 'v-gov': { value: '' }, 'v-area': { value: '' }, 'v-detail': { value: '' } };
  fn('v', 'ش الجمال، المنصورة، الدقهلية');
  ok('الوضع الكامل: المحافظة اتقرت', dom['v-gov'].value === 'الدقهلية', dom['v-gov'].value);
  ok('والتفصيل اتحط', dom['v-detail'].value === 'ش الجمال', dom['v-detail'].value);

  ok('🔴 مافيش رجوع مبكر بيقتل الوضع المبسّط', !/if \(!g\) return;/.test(body));
  ok('عنوان المستلم فعلًا مبسّط', /renderAddr\("rcvAddrW-" \+ i, \{ value: r\.address \|\| "", simple: true \}\)/.test(code));
  ok('وعنوان المُرسِل كامل', /renderAddr\("sndAddrW", \{ value: "" \}\)/.test(code));

  /* الرقم الإضافي مابيتمسحش لو الملف مالوش واحد */
  ok('🔴 «بياناتي» مابيمسحش رقم إضافي مكتوب',
     /if \(p\.phone2\) \$\("rcvPhone2-" \+ i\)\.value = p\.phone2;/.test(code));
}

/* ═══ 5) اللي كان شغّال ما اتكسرش ═══ */
console.log('\n══ 5) مسار المستلمين السابقين ما اتخدشش ══');
{
  ok('لسه بيدوّر على المستلم المختار',
     /const r = picks\.find\(x => String\(x\.id\) === c\.dataset\.id\); if \(!r\) return;/.test(code));
  ok('ولسه بيملّي منه', /\$\("rcvName-" \+ i\)\.value   = r\.name/.test(code));
  ok('وشيب المُرسِل زي ما هو', /\$\("sndName"\)\.value  = p\.displayNameAr/.test(code));
  ok('وحقول المستلم لسه في الماركب', /id="rcvName-\$\{i\}"/.test(code) && /id="rcvPhone-\$\{i\}"/.test(code));
}

console.log('\n════════════════════════════════════════');
console.log('CUSTOMER RECEIVER: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
