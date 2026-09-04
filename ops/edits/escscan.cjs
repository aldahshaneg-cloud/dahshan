/* ماسح مشاكل الهروب (escaping) في صفحات النظام.
 *
 * بيدوّر على نوعين — الاتنين بيتسببوا في شاشة غلط:
 *
 *  ① HTML مهروب  → الوسم بيتعرض كحروف على الشاشة (الباج اللي اتشاف في
 *    صفحة نقل الطيارين: `<span class="status-badge">❌ مرفوض</span>` ظاهر
 *    كنص). العلامة: `esc()` متطبّقة على قيمة أصلها وسوم.
 *
 *  ② بيانات مش مهروبة → أي حاجة اتكتبت في قاعدة البيانات بتتنفّذ كـHTML.
 *    العلامة: حقل جاي من صف بيتحط في قالب من غير `esc()`.
 *
 * الماسح ده **مرشّح مش حكم**: بيطلّع مواضع للمراجعة بالعين، لأن الفرق
 * بين «متغيّر فيه HTML مقصود» و«متغيّر فيه نص مستخدم» مش دايمًا واضح
 * من الشكل.
 */
const fs = require('fs');

/* حقول معروف إنها HTML مبني في الكود — الهروب عليها غلط */
const HTMLISH = /(Html|HTML|Badge|badge|Icon|Tag|Markup|Tpl|tpl)$/;

/* أسماء بتيجي من قاعدة البيانات — الهروب عليها **لازم** */
const DATA_HINT = /^(r|o|p|b|d|t|u|s|z|x|it|item|row|rec)\./;

function scan(file) {
  const src = fs.readFileSync(file, 'utf8');
  const lines = src.split('\n');

  /* ── أسماء اتسندت لنص فيه وسوم ── */
  const htmlVars = new Set();
  for (const m of src.matchAll(/(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*[^;]{0,500}?<(?:span|div|button|b|i|img|a|td|tr)\b/g))
    htmlVars.add(m[1]);
  for (const m of src.matchAll(/(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*\{[\s\S]{0,800}?<(?:span|div|button)\b/g))
    htmlVars.add(m[1]);
  /* وأي اسم شكله HTML */
  for (const m of src.matchAll(/(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=/g))
    if (HTMLISH.test(m[1])) htmlVars.add(m[1]);

  const escapedHtml = [];   // ①
  const rawData = [];       // ②

  lines.forEach((l, i) => {
    /* ① esc() على قيمة HTML */
    for (const m of l.matchAll(/esc\(\s*([A-Za-z_$][\w$]*)\s*(?:\[|\)|\s*\|\|)/g)) {
      if (htmlVars.has(m[1])) escapedHtml.push({ line: i + 1, txt: l.trim().slice(0, 110), name: m[1] });
    }
    /* ② `${ x.y }` من غير esc — بس جوه قالب HTML */
    if (!/[`'"]\s*<|<\w+[^>]*>/.test(l)) return;
    for (const m of l.matchAll(/\$\{\s*([A-Za-z_$][\w$]*\.[A-Za-z_$][\w$]*(?:\s*\|\|\s*[^}]*)?)\s*\}/g)) {
      const expr = m[1];
      if (/^(window|document|Math|JSON|console)\./.test(expr)) continue;
      if (!DATA_HINT.test(expr)) continue;
      /* أرقام وحالات معروفة مش خطر */
      if (/\b(Id|Count|No|Num|Qty|Len|Lat|Lng|At|Ms|Sec)\b/.test(expr)) continue;
      rawData.push({ line: i + 1, txt: l.trim().slice(0, 110), expr });
    }
  });
  return { escapedHtml, rawData };
}

let totalA = 0, totalB = 0;
for (const f of process.argv.slice(2)) {
  const { escapedHtml, rawData } = scan(f);
  totalA += escapedHtml.length;
  totalB += rawData.length;
  if (!escapedHtml.length && !rawData.length) { console.log('✅ ' + f); continue; }
  console.log('\n── ' + f + ' ──');
  if (escapedHtml.length) {
    console.log('  🔴 HTML مهروب (بيتعرض كحروف): ' + escapedHtml.length);
    escapedHtml.forEach(h => console.log('     ' + h.line + ': ' + h.txt));
  }
  if (rawData.length) {
    console.log('  🟡 بيانات من غير esc (للمراجعة): ' + rawData.length);
    rawData.slice(0, 12).forEach(h => console.log('     ' + h.line + ': ' + h.expr + '   ← ' + h.txt.slice(0, 70)));
    if (rawData.length > 12) console.log('     … و' + (rawData.length - 12) + ' كمان');
  }
}
console.log('\n════════════════════════════════════════');
console.log('الإجمالي: ' + totalA + ' HTML مهروب · ' + totalB + ' بيانات للمراجعة');
console.log('════════════════════════════════════════');
