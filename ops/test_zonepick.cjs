/**
 * 🗺 اختبار «خانة المنطقة مابتنهارش لبكسل واحد».
 *
 * ═══ اللسعة ═══
 * `zonepick` بيخفي الـ<select> الأصلي ويحط مكانه خانة بحث، وبينسخ شكل
 * الـselect للخانة (`copyLook`) — بما فيه `height` و`padding` و`border`.
 *
 * الإخفاء بيحصل **بعد** النسخ، فأول ربط بيطلع سليم. المشكلة إن الربط
 * بيتكرر: لما الفرع يتغيّر، `populateZoneSelect` بتعمل `cloneNode` +
 * `replaceChild` للـselect — والنسخة بتاخد سمة `style` معاها، يعني
 * **بتيجي مخفية أصلًا** بـ`height:1px`. فالمراقب بينده `attach` عليها
 * و`copyLook` بتنسخ الـ1px لخانة البحث.
 *
 * النتيجة على الإنتاج (اتشافت بالقياس على متصفح صاحب النظام):
 *   خانة «المنطقة» في مودال الطلب ارتفاعها **1px** — النص المختار مش
 *   بيبان والموظف بالكاد يقدر يقف عليها بالماوس. وخانة «منطقة الاستلام»
 *   سليمة (47px) لأن الـselect بتاعها عمره ما بيتبدل.
 *
 * الاختبار بيعيد نفس السيناريو: ربط ← استنساخ ← إعادة ربط.
 *
 * التشغيل: node ops/test_zonepick.cjs
 */
const fs = require('fs');

let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const SRC = fs.readFileSync('public/assets/js/zonepick.js', 'utf8');

console.log('\n══ 1) الشكل بيتنسخ من select مش مخفي ══');
/* الترتيب هو كل الحكاية: تصفير الإخفاء ← نسخ الشكل ← إخفاء من جديد. */
const clearAt = SRC.indexOf('sel.style.position = "";');
const copyAt  = SRC.indexOf('copyLook(sel, input);');
const hideAt  = SRC.indexOf('sel.style.position = "absolute";');
ok('فيه تصفير لستايل الإخفاء', clearAt > -1, 'مفيش — النسخ هيقرا 1px');
ok('🔴 التصفير قبل النسخ', clearAt > -1 && clearAt < copyAt,
   'تصفير=' + clearAt + ' نسخ=' + copyAt);
ok('والإخفاء بعد النسخ', copyAt < hideAt, 'نسخ=' + copyAt + ' إخفاء=' + hideAt);

console.log('\n══ 2) الخصائص الخطيرة اللي بتتنسخ ══');
/* دي اللي بتنقل الانهيار. لو حد شال التصفير، الأربعة دول بيوصلوا 1px/0. */
['height', 'minHeight', 'paddingTop', 'borderTopWidth'].forEach(p =>
  ok('`' + p + '` لسه بتتنسخ (فالتصفير ضروري)', SRC.includes('"' + p + '"')));

console.log('\n══ 3) 🔴 محاكاة السيناريو الحقيقي ══');
/* بنقلّد الجزء اللي بيهمنا: select بيتخفي، بيتنسخ، وإعادة الربط بتقرا
   شكله. من غير التصفير، القراءة بترجّع 1px. */
const HIDE = { position: 'absolute', opacity: '0', width: '1px', height: '1px', padding: '0', border: '0' };

function makeSelect() { return { style: { height: '46px', padding: '11px', border: '1px solid' } }; }
function hide(s) { Object.assign(s.style, HIDE); }
function cloneOf(s) { return { style: Object.assign({}, s.style) }; }   // cloneNode بينسخ style

// (أ) السلوك القديم — نسخ من غير تصفير
function attachOld(sel) { const copied = { height: sel.style.height, padding: sel.style.padding }; hide(sel); return copied; }
let s = makeSelect();
attachOld(s);                       // أول ربط
const oldCopied = attachOld(cloneOf(s));   // إعادة ربط على نسخة مخفية
ok('القديم: النسخة بتاخد 1px', oldCopied.height === '1px', oldCopied.height);

// (ب) السلوك الجديد — تصفير قبل النسخ
function attachNew(sel) {
  sel.style.position = ''; sel.style.opacity = ''; sel.style.width = '';
  sel.style.height = ''; sel.style.padding = ''; sel.style.border = '';
  const copied = { height: sel.style.height, padding: sel.style.padding };
  hide(sel);
  return copied;
}
s = makeSelect();
attachNew(s);
const newCopied = attachNew(cloneOf(s));
ok('🔴 الجديد: مابياخدش 1px', newCopied.height !== '1px', newCopied.height || '(فاضي — الـCSS بيحكم)');

console.log('\n══ 4) الصفحات اللي بتستعمل الكمبوننت ══');
/* الملف مشترك — الإصلاح بيوصل الأربعة مع بعض. */
['public/callcenter.html', 'public/tiar.html', 'public/store.html', 'public/customer.html']
  .filter(f => fs.existsSync(f))
  .forEach(f => {
    const s = fs.readFileSync(f, 'utf8');
    if (s.includes('zonepick.js')) ok(f + ' — بتحمّل zonepick', true);
  });
/* الـselect اللي بيتبدل هو ده — لو اتشال الاستنساخ يبقى الباج راح من
   أصله، بس التصفير بيفضل صح. */
const CC = fs.readFileSync('public/callcenter.html', 'utf8');
ok('لسه فيه استنساخ للـselect (سبب إعادة الربط)',
   /cloneNode/.test(CC) || /replaceChild/.test(CC));

console.log('\n════════════════════════════════════════');
console.log('ZONEPICK: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
