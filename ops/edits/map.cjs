/* بيحدد بلوك الجافاسكربت بتاع الشاشة المحذوفة، وبيقول أنهي دوال
   جواه بيتنده عليها من برّه — دي اللي ممنوع تتشال. */
const fs = require('fs');
const L = fs.readFileSync('public/callcenter.html', 'utf8').split('\n');
const start = L.findIndex(l => l.includes('شاشة الأوردر الجديد'));
// النهاية: أول كتلة تعليق كبيرة بعدها لقسم تاني، أو نهاية السكربت
let end = L.findIndex((l, i) => i > start && /^  \/\* ═+$/.test(l) &&
  L[i+1] && !/الأوردر الجديد|العهدة|الطرود|المنطقة|الملخّص/.test(L[i+1]));
console.log('البلوك من ' + (start+1) + ' لـ ' + (end+1) + ' = ' + (end-start) + ' سطر');
console.log('العنوان اللي بعده: ' + (L[end+1]||'').trim());

const blk = L.slice(start, end).join('\n');
const rest = L.slice(0, start).concat(L.slice(end)).join('\n');

// الدوال المعرّفة جوه البلوك
const defs = new Set();
[...blk.matchAll(/(?:window\.)?(?:function\s+|const\s+|let\s+)([A-Za-z_$][\w$]*)\s*(?:=|\()/g)]
  .forEach(m => defs.add(m[1]));
[...blk.matchAll(/window\.([A-Za-z_$][\w$]*)\s*=/g)].forEach(m => defs.add(m[1]));

const used = [], dead = [];
[...defs].sort().forEach(n => {
  const re = new RegExp('\b' + n.replace(/\$/g,'\$') + '\b');
  (re.test(rest) ? used : dead).push(n);
});
console.log('\n🔴 بيتنده من برّه (ممنوع يتشال): ' + used.length);
console.log('   ' + used.join(', '));
console.log('\n✅ ميّت بالكامل: ' + dead.length);
console.log('   ' + dead.join(', '));
