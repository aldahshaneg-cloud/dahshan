/**
 * 🔑 اختبار «تعديل الطيار مش محتاج كلمة مرور».
 *
 * ═══ الباج اللي بيحرسه ═══
 * حقل كلمة المرور كان عليه `required` ثابت في الـHTML، والمودال جوه
 * `<form>` وزرار الحفظ `type="submit"`. يعني **المتصفح** بيوقف الحفظ
 * والفورمة ما بتتبعتش أصلًا — قبل ما أي كود جافاسكربت يشتغل.
 *
 * الخبيث في الباج ده إن كل الكود التاني كان **صح**: `addPilot` بتطلب
 * الباسورد عند الإضافة بس، و`openEditPilot` بتكتب في الـplaceholder
 * «اتركها فارغة للإبقاء على كلمة المرور الحالية». فأي فحص بيقرا
 * الجافاسكربت كان هيعدّي — الحاجز كان في سمة HTML واحدة.
 *
 * عشان كده الاختبار ده بيشغّل `setPilotPassRequired` على عنصر مقلّد
 * ويقرا السمة فعليًا، وبيتأكد إن الوضعين بيتنادوا من المكان الصح.
 *
 * التشغيل: node ops/test_pilot_pass.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (w, c, got) => { c ? (pass++, console.log('  ✓ ' + w))
  : (fail++, console.log('  ✗ ' + w + (got !== undefined ? '   ← ' + got : ''))); };

const FILES = ['public/tiar.html', 'public/callcenter.html'];

for (const file of FILES) {
  const src = fs.readFileSync(file, 'utf8');
  console.log('\n══ ' + file + ' ══');

  /* ── عنصر مقلّد: بيسجّل السمات زي المتصفح ── */
  const attrs = {};
  const star = { style: {} };
  const inp = {
    placeholder: '',
    setAttribute: (k) => { attrs[k] = ''; },
    removeAttribute: (k) => { delete attrs[k]; },
    closest: () => ({ querySelector: () => star }),
  };
  const doc = { getElementById: id => (id === 'pilotPassword' ? inp : null) };

  /* بنقص الدالة من الملف ونشغّلها */
  const start = src.indexOf('window.setPilotPassRequired = function');
  ok('الدالة موجودة', start > -1);
  if (start < 0) continue;
  let d = 0, end = -1;
  for (let j = src.indexOf('{', src.indexOf(')', start)); j < src.length; j++) {
    const c = src[j];
    if (c === '{') d++;
    else if (c === '}') { d--; if (!d) { end = j + 1; break; } }
    else if (c === '`' || c === '"' || c === "'") {
      const q = c; j++;
      while (j < src.length && src[j] !== q) { if (src[j] === '\\') j++; j++; }
    }
  }
  const win = {};
  new Function('window', 'document', src.slice(start, end) + ';')(win, doc);

  /* ① وضع الإضافة */
  win.setPilotPassRequired(true);
  ok('إضافة: الحقل مطلوب', 'required' in attrs, JSON.stringify(attrs));
  ok('إضافة: النجمة بتبان', star.style.display === '', star.style.display);

  /* ② وضع التعديل — دي الحتة اللي كانت مكسورة */
  win.setPilotPassRequired(false);
  ok('🔴 تعديل: الحقل مش مطلوب', !('required' in attrs), JSON.stringify(attrs));
  ok('تعديل: النجمة بتختفي', star.style.display === 'none', star.style.display);
  ok('تعديل: التلميح بيشرح', inp.placeholder.includes('اتركها فارغة'), inp.placeholder);

  /* ③ الرجوع للإضافة بيرجّع السمة (مودال بيتعاد استعماله) */
  win.setPilotPassRequired(true);
  ok('الرجوع للإضافة بيرجّع الشرط', 'required' in attrs, JSON.stringify(attrs));

  /* ── الربط في المكان الصح ── */
  console.log('  — الربط —');
  const editAt = src.indexOf('window.openEditPilot');
  let ed = 0, ee = -1;
  for (let j = src.indexOf('{', src.indexOf(')', editAt)); j < src.length; j++) {
    const c = src[j];
    if (c === '{') ed++;
    else if (c === '}') { ed--; if (!ed) { ee = j + 1; break; } }
    else if (c === '`' || c === '"' || c === "'") {
      const q = c; j++;
      while (j < src.length && src[j] !== q) { if (src[j] === '\\') j++; j++; }
    }
  }
  const editBody = src.slice(editAt, ee);
  ok('التعديل بينده (false)', /setPilotPassRequired\(false\)/.test(editBody));

  const resetAt = src.indexOf('function resetPilotModal');
  const resetBody = src.slice(resetAt, resetAt + 700);
  ok('الإضافة بتنده (true)', /setPilotPassRequired\(true\)/.test(resetBody));

  /* ── الحاجز الأصلي اتشال ── */
  ok('🔴 مفيش required ثابت في الـHTML',
     !/id="pilotPassword"[^>]*\brequired\b/.test(src),
     (src.match(/<input id="pilotPassword"[^>]*>/) || [''])[0].slice(0, 90));

  /* ── المنطق اللي كان صح لازم يفضل صح ── */
  ok('الباسورد مطلوبة عند الإضافة بس (منطق JS)',
     /!isEditingPilot && !pilotPassword/.test(src));
  /* الشكل بيختلف بين الملفين: الإدارة `else if (pilotPassword)` والكول
     سنتر `if (pilotPassword) patch.password = …` — المهم إن الباسورد
     مابتتبعتش وهي فاضية، مش شكل الكتابة. */
  ok('ومابتتبعتش للسيرفر لو فاضية',
     /else if \(pilotPassword\)/.test(src) || /if \(pilotPassword\) patch\.password/.test(src));
}

console.log('\n════════════════════════════════════════');
console.log('PILOT PASS: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
