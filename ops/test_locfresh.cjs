/**
 * ⏱ اختبار «الخريطة مابتكدبش» — بياتة موقع الطيار.
 *
 * ═══ اللسعة ═══
 * تطبيق الطيار بيبعت موقعه للسيرفر. لو البعتة فشلت، الكود كان بيبلع
 * الخطأ في صمت — فالخريطة بتفضل عارضة آخر نقطة معروفة **من غير أي
 * علامة** إنها بايتة. المشرف بيبص، بيشوف الطيار في مكان، وبيبني عليه.
 *
 * الوقت كان مكتوب في الـpopup بخط رمادي صغير — يعني محتاج دوسة على
 * الماركر عشان تشوفه، وده مش بيحصل في البصة السريعة اللي الخريطة
 * أصلًا موجودة عشانها.
 *
 * التشغيل: node ops/test_locfresh.cjs
 */
const fs = require('fs');
const vm = require('vm');

let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

/* بنحمّل الملف المنشور نفسه في sandbox — مش نسخة مكتوبة هنا. */
const ctx = { window: {} };
vm.createContext(ctx);
new vm.Script(fs.readFileSync('public/assets/js/locfresh.js', 'utf8')).runInContext(ctx);
const LF = ctx.window.LocFresh;

const at = (minsAgo) => ({
  pilotStatus: 'waiting',
  location: { lat: 30, lng: 31, updatedAt: new Date(Date.now() - minsAgo * 60000).toISOString() }
});

console.log('\n══ 1) الدرجات وحدودها ══');
ok('المكتبة اتحمّلت', !!LF);
ok('من ثانية → طازة',        LF.tier(at(0)) === 'ok',   LF.tier(at(0)));
ok('4 دقايق → لسه طازة',      LF.tier(at(4)) === 'ok',   LF.tier(at(4)));
ok('5 دقايق → متأخر',        LF.tier(at(5)) === 'warn', LF.tier(at(5)));
ok('14 دقيقة → لسه متأخر',    LF.tier(at(14)) === 'warn', LF.tier(at(14)));
ok('15 دقيقة → بايت',        LF.tier(at(15)) === 'dead', LF.tier(at(15)));
ok('ساعتين → بايت',          LF.tier(at(120)) === 'dead', LF.tier(at(120)));
ok('مفيش موقع → none',       LF.tier({ pilotStatus: 'waiting' }) === 'none');
ok('طابع بايظ → none',       LF.tier({ location: { updatedAt: 'كلام' } }) === 'none');

console.log('\n══ 2) الطازة مايزحمش الخريطة — والبايت يبان ══');
/* الشارة على الماركر هي الحاجة الوحيدة اللي بتبان من غير دوس. لو
   ظهرت على كل الطيارين تبقى ضوضاء ومحدش هيبصلها. */
ok('الطازة من غير شارة', LF.markerStyle(at(1)).badge === null);
ok('المتأخر له شارة',    LF.markerStyle(at(7)).badge !== null);
ok('البايت له شارة',     LF.markerStyle(at(40)).badge !== null);
ok('البايت باهت أكتر من المتأخر',
   LF.markerStyle(at(40)).opacity < LF.markerStyle(at(7)).opacity,
   LF.markerStyle(at(40)).opacity + ' مقابل ' + LF.markerStyle(at(7)).opacity);
ok('البايت إطاره متقطّع', LF.markerStyle(at(40)).dashed === true);
ok('الطازة إطاره متصل',   LF.markerStyle(at(1)).dashed === false);
ok('ألوان الإطار مختلفة',
   new Set(['ok', 'warn', 'dead'].map(t => LF.markerStyle(at(t === 'ok' ? 1 : t === 'warn' ? 7 : 40)).ring)).size === 3);

console.log('\n══ 3) النص اللي المشرف بيقراه ══');
ok('ثواني', LF.shortAgo(at(0)).includes('ث'), LF.shortAgo(at(0)));
ok('دقايق', LF.shortAgo(at(7)) === '7 د', LF.shortAgo(at(7)));
ok('ساعات', LF.shortAgo(at(150)) === '2 س', LF.shortAgo(at(150)));
ok('الطويل بيقول «آخر تحديث»', LF.longAgo(at(7)).startsWith('آخر تحديث'), LF.longAgo(at(7)));
ok('مفيش موقع → نص واضح مش رقم', LF.longAgo({}) === 'مفيش موقع متسجّل', LF.longAgo({}));
/* صيغة الجمع العربي: 1 مفرد · 2 مثنى · 3–10 جمع · 11+ مفرد تاني.
   «8 دقيقة» بتخلّي القارئ يتوقف على الشكل بدل ما يقرا الرقم. */
ok('دقيقة واحدة',   LF.longAgo(at(1))   === 'آخر تحديث من دقيقة',      LF.longAgo(at(1)));
ok('دقيقتين',       LF.longAgo(at(2))   === 'آخر تحديث من دقيقتين',    LF.longAgo(at(2)));
ok('8 دقايق',       LF.longAgo(at(8))   === 'آخر تحديث من 8 دقايق',    LF.longAgo(at(8)));
ok('11 دقيقة',      LF.longAgo(at(11))  === 'آخر تحديث من 11 دقيقة',   LF.longAgo(at(11)));
ok('ساعة',          LF.longAgo(at(60))  === 'آخر تحديث من ساعة',       LF.longAgo(at(60)));
ok('ساعتين',        LF.longAgo(at(120)) === 'آخر تحديث من ساعتين',     LF.longAgo(at(120)));
ok('5 ساعات',       LF.longAgo(at(300)) === 'آخر تحديث من 5 ساعات',    LF.longAgo(at(300)));
ok('لحظي بيقول دلوقتي', LF.longAgo(at(0)) === 'آخر تحديث دلوقتي',      LF.longAgo(at(0)));
/* الرسالة لازم تقول اللي إحنا متأكدين منه بس. «التطبيق واقع» ممكن
   تكون كذب — الطيار ممكن يكون واقف على النسخة القديمة من التطبيق. */
ok('البايت بيحذّر من البناء عليه', LF.popupLine(at(40)).includes('اتأكد من الطيار'));
ok('الطازة مافيهوش تحذير', !LF.popupLine(at(1)).includes('اتأكد من الطيار'));

console.log('\n══ 4) العدّاد بيعدّ اللي على الخريطة بس ══');
/* الطيار من غير وردية مش بيترسم أصلًا — لو دخل العدّاد هيطلع رقم
   مرعب دايمًا (كل الطيارين الخارجين من الشغل) ومحدش هيثق فيه. */
ok('اللي من غير وردية مابيتعدّش',
   LF.staleCount([{ location: at(99).location }]) === 0);
ok('اللي في وردية وبايت بيتعدّ',
   LF.staleCount([at(99)]) === 1);
ok('الطازة مابيتعدّش', LF.staleCount([at(1)]) === 0);
ok('خليط 2 من 4',
   LF.staleCount([at(1), at(9), at(2), at(40)]) === 2,
   String(LF.staleCount([at(1), at(9), at(2), at(40)])));
ok('مفيش شارة لما كله تمام', LF.statusChip([at(1)]) === '');
ok('الشارة بتقول العدد', LF.statusChip([at(9), at(40)]).includes('2 موقعهم متأخر'));

console.log('\n══ 5) 🔴 الماركر بيتلوّن من جديد مع مرور الوقت ══');
/* من غير ده الإصلاح كله ملوش لازمة: الماركر بيترسم مرة واحدة وقت وصول
   البيانات، فاللي كان طازة بيفضل شكله طازة للأبد. */
let built = 0;
const mk = () => { built++; return { icon: true }; };
const markers = { p1: { setIcon() {} }, p2: { setIcon() {} } };

built = 0;
LF.refreshMarkers({ p1: at(1), p2: at(1) }, markers, mk);
ok('أول نداء بيرسم الاتنين', built === 2, String(built));

built = 0;
LF.refreshMarkers({ p1: at(1), p2: at(1) }, markers, mk);
ok('نفس الدرجة → مفيش إعادة رسم', built === 0, String(built));

built = 0;
LF.refreshMarkers({ p1: at(9), p2: at(1) }, markers, mk);
ok('واحد بقى متأخر → واحد بس اتعاد رسمه', built === 1, String(built));

built = 0;
LF.refreshMarkers({ p1: at(40), p2: at(1) }, markers, mk);
ok('نفس الواحد بقى بايت → اتعاد رسمه تاني', built === 1, String(built));

built = 0;
LF.refreshMarkers({ p2: at(1) }, markers, mk);
ok('الطيار اللي اختفى مابيرسمش', built === 0, String(built));
/* لو الحالة المحفوظة ماتمسحتش، الطيار اللي رجع بنفس الدرجة القديمة
   مش هيتعاد رسمه ومركره هيفضل بالشكل الغلط. */
built = 0;
LF.refreshMarkers({ p1: at(40), p2: at(1) }, markers, mk);
ok('اللي رجع بيتعاد رسمه حتى بنفس درجته', built === 1, String(built));

built = 0;
LF.refreshMarkers({ p1: at(1) }, { p1: { setIcon() { throw new Error('اتشال'); } } }, mk);
ok('ماركر اتشال من الخريطة مابيوقعش الحلقة', true);

console.log('\n══ 6) التلات لوحات موصّلة ══');
const PAGES = ['public/tiar.html', 'public/callcenter.html', 'public/branch.html'];
for (const f of PAGES) {
  const src = fs.readFileSync(f, 'utf8');
  ok(f + ' — بتحمّل locfresh.js', src.includes('assets/js/locfresh.js'));
  ok(f + ' — الأيقونة بتاخد بيانات الطيار', /LocFresh\.markerStyle\(pilot/.test(src));
  ok(f + ' — الـpopup بيستعمل السطر الملوّن', src.includes('LocFresh.popupLine(p)'));
  ok(f + ' — فيه إعادة تلوين دورية', src.includes('LocFresh.refreshMarkers('));
}
/* شريط الحالة موجود في التلاتة برضه — بس البانل بتاع الفرع بيعدّ
   طيارينه هو، فمهم إنه يتنده بنفس المصفوفة اللي الشريط بيعدّ منها. */
for (const f of PAGES) {
  const src = fs.readFileSync(f, 'utf8');
  ok(f + ' — شارة الشريط موصّلة', src.includes('LocFresh.statusChip(allPilots)'));
}

console.log('\n══ 7) الحارس: مفيش لوحة بتبني الأيقونة من غير الطيار ══');
/* الخطأ السهل هنا إن حد يضيف نداء جديد لـ`_mkPilotIcon` وينسى يبعت
   `p` — الأيقونة هتترسم دايمًا «طازة» وهي مش عارفة، وده أسوأ من
   مفيش إصلاح لأنه بيبان مطمّن.

   الأقواس بتتعدّ بالتوازن مش بـ`[^)]*`: نداء الفرع جواه `has(p.id)`،
   والتعبير البسيط كان بيقطع عنده ويقول إن النداء السليم ناقص. */
function callsOf(src, name) {
  const out = [];
  let i = 0;
  while ((i = src.indexOf(name + '(', i)) !== -1) {
    let d = 0, j = i + name.length;
    for (; j < src.length; j++) {
      if (src[j] === '(') d++;
      else if (src[j] === ')' && --d === 0) { j++; break; }
    }
    out.push(src.slice(i, j));
    i = j;
  }
  return out;
}
for (const f of PAGES) {
  const src = fs.readFileSync(f, 'utf8');
  const calls = callsOf(src, '_mkPilotIcon')
    .filter(c => !c.startsWith('_mkPilotIcon(status'));   // التعريف نفسه
  ok(f + ' — فيه نداءات أصلًا', calls.length > 0, String(calls.length));
  const bad = calls.filter(c => !/,\s*p\s*\)$/.test(c));
  ok(f + ' — كل نداء بيبعت الطيار', bad.length === 0, bad.join(' · '));
}
console.log('\n════════════════════════════════════════');
console.log('LOC FRESH: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
