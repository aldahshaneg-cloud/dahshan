/* كشف التعريفات اللي مالهاش استعمال جوه ملف HTML واحد.
   بيشيل التعليقات الأول عشان اسم مذكور في تعليق مايتحسبش «استعمال». */
const fs = require('fs');
const file = process.argv[2] || 'public/callcenter.html';
const raw = fs.readFileSync(file, 'utf8');

/* بنمسح تعليقات /* *\/ و // — بنستبدلها بمسافات عشان الأرقام ماتتغيرش */
const src = raw
  .replace(/\/\*[\s\S]*?\*\//g, m => ' '.repeat(m.length))
  .replace(/(^|[^:])\/\/[^\n]*/g, (m, p) => p + ' '.repeat(m.length - p.length));

const defs = new Map();
const add = (n, kind) => { if (!defs.has(n)) defs.set(n, kind); };
for (const m of src.matchAll(/(?:^|\n)\s*(?:async\s+)?function\s+([A-Za-z_$][\w$]*)/g)) add(m[1], 'function');
for (const m of src.matchAll(/(?:^|\n)\s*(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:function|\()/g)) add(m[1], 'fn-var');
for (const m of src.matchAll(/(?:^|\n)\s*(?:const|let|var)\s+([A-Z_][A-Z0-9_]{2,})\s*=/g)) add(m[1], 'const');

const dead = [];
for (const [n, kind] of defs) {
  const uses = (src.match(new RegExp('\\b' + n.replace(/\$/g, '\\$') + '\\b', 'g')) || []).length;
  // مرة واحدة = التعريف نفسه بس. و`window.x = ...` بيتحسب استعمال خارجي.
  const onWindow = new RegExp('window\\.' + n + '\\s*=').test(src)
                || new RegExp('\\bwindow\\.' + n + '\\b').test(src)
                || new RegExp('["\']' + n + '["\']').test(raw)          // ممكن يتنده بالاسم من HTML
                || new RegExp('\\b' + n + '\\s*\\(').test(raw);         // نداء في onclick=
  if (uses <= 1 && !onWindow) dead.push(n + ' (' + kind + ')');
}
console.log('الملف: ' + file);
console.log('تعريفات: ' + defs.size);
console.log(dead.length ? '🟡 بلا استعمال (' + dead.length + '):\n   ' + dead.join('\n   ')
                        : '✅ مافيش تعريف بلا استعمال');

/* ── امتداد: الدوال المعرّفة بـ`window.X = ...` ──
   الماسح فوق بيدوّر على `function X` و`const X =` بس، فدالة زي
   `window.cancelOrder = async function(){}` كانت بتفوت منه خالص.
   دي بتتنده غالبًا من onclick في نص، فالبحث لازم يبقى على الخام. */
{
  const win = new Set();
  for (const m of src.matchAll(/window\.([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:function|\()/g)) win.add(m[1]);
  const orphan = [];
  for (const n of win) {
    /* بنعدّ كل ذكر للاسم في الملف الخام (عشان onclick في نص)، وبنطرح
       التعريف نفسه. الباقي = استعمال حقيقي. */
    const all  = (raw.match(new RegExp('\\b' + n + '\\b', 'g')) || []).length;
    const defs = (raw.match(new RegExp('window\\.' + n + '\\s*=', 'g')) || []).length;
    if (all - defs <= 0) orphan.push(n + '  (ذكر ' + all + ' · تعريف ' + defs + ')');
  }
  console.log(orphan.length ? '🟡 دوال window بلا نداء (' + orphan.length + '):\n   ' + orphan.join('\n   ')
                            : '✅ كل دوال window بتتنده');
}
