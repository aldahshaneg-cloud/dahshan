/* شيل إجراءات الأوردر الميّتة من callcenter.html.
 *
 * الأربعة الأولانيين بقوا ميّتين لما اتشالوا من كارت البحث السريع.
 * والأربعة اللي بعدهم كانوا **ميّتين من قبل** — بقايا من شاشة «أوردر
 * جديد» المحذوفة ومن جداول اتغيّرت. الاتنين نفس الحكاية: إجراءات على
 * الأوردر مالهاش لازمة عند الكول سنتر.
 *
 * ليه بنشيل التعريف مش الزرار بس: `window.cancelOrder` اللي فاضلة في
 * الصفحة أي حد يفتح الـconsole يقدر ينده عليها — والسيرفر لسه بيقبلها
 * من دور الكول سنتر. شيل التعريف بيرفع الحاجز، لكنه **مش** بديل عن
 * سحب الصلاحية من السيرفر.
 */
const fs = require('fs');
const f = 'public/callcenter.html';
let s = fs.readFileSync(f, 'utf8');

/* كل واحدة: الاسم، وهل بنتوقع إنها ميّتة */
const KILL = ['selectOpenShiftPilot'];

/* ── الحارس ──
   بنعدّ النداءات **بعد كل شيل** مش مرة واحدة في الأول: الدوال ممكن تنده
   على بعض (saveEditOrderData بتنده closeEditOrderData)، فلو عدّينا مرة
   واحدة الحارس بيقف على نداء داخلي مالوش لازمة. الترتيب بيتحدد لوحده:
   في كل لفّة بنشيل اللي بقت بلا نداء بس. */
/* التعليقات بتتشال قبل العدّ: اسم الدالة مذكور في تعليق **مش** نداء،
   ولولا كده الحارس بيقف على `saveMonthlyCloseout` بسبب تعليق جوّاها. */
const strip = src => src
  .replace(/\/\*[\s\S]*?\*\//g, ' ')
  .replace(/(^|[^:])\/\/[^\n]*/g, '$1 ');
const calls = (src, n) => {
  const c = strip(src);
  return (c.match(new RegExp('\\b' + n + '\\b', 'g')) || []).length -
         (c.match(new RegExp('window\\.' + n + '\\s*=', 'g')) || []).length;
};

/* ── الشيل: من `window.NAME =` لحد القوس اللي بيقفلها ── */
function cutFn(src, name) {
  const start = src.indexOf('window.' + name + ' =');
  if (start < 0) return null;
  // نرجع لورا عشان نمسك التعليق اللي فوقها لو موجود
  let head = start;
  const upto = src.lastIndexOf('\n', start - 1);
  const prev = src.slice(Math.max(0, upto - 900), upto);
  const cm = prev.lastIndexOf('/*');
  if (cm > -1 && prev.indexOf('*/', cm) === -1) head = Math.max(0, upto - 900) + cm;

  let p = src.indexOf('(', start), pd = 0, body = -1;
  for (let j = p; j < src.length; j++) {
    if (src[j] === '(') pd++;
    else if (src[j] === ')') { pd--; if (!pd) { body = src.indexOf('{', j); break; } }
  }
  let d = 0;
  for (let j = body; j < src.length; j++) {
    const c = src[j];
    if (c === '{') d++;
    else if (c === '}') {
      d--;
      if (!d) {
        let end = j + 1;
        if (src[end] === ';') end++;
        while (src[end] === '\n' || src[end] === '\r') end++;
        return { head, end };
      }
    } else if (c === '`' || c === '"' || c === "'") {
      const q = c; j++;
      while (j < src.length && src[j] !== q) { if (src[j] === '\\') j++; j++; }
    }
  }
  return null;
}

let removed = 0, lines = 0;
const left = new Set(KILL);
let progress = true;
while (progress && left.size) {
  progress = false;
  for (const n of [...left]) {
    if (calls(s, n) > 0) continue;              // لسه ليها نداء — لفّة جاية
    const r = cutFn(s, n);
    if (!r) { console.log('⚠️  ' + n + ': مالقيتهاش'); left.delete(n); continue; }
    lines += s.slice(r.head, r.end).split('\n').length;
    s = s.slice(0, r.head) + s.slice(r.end);
    left.delete(n); removed++; progress = true;
    console.log('  ✓ ' + n);
  }
}
if (left.size) {
  console.log('\n🔴 لسه ليهم نداء من برّه — مااتشالوش:');
  for (const n of left) console.log('   ' + n + ' (' + calls(s, n) + ' نداء)');
  process.exit(1);
}
fs.writeFileSync(f, s);
console.log('✓ ' + removed + ' دالة · ~' + lines + ' سطر');
