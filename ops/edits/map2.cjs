const fs = require('fs');
const L = fs.readFileSync('public/callcenter.html', 'utf8').split('\n');
const start = L.findIndex(l => l.includes('شاشة الأوردر الجديد'));
const end   = L.findIndex(l => l.includes('async function logZoneRequest'));
console.log('النطاق المرشّح للحذف: ' + (start+1) + '..' + end + ' = ' + (end-start) + ' سطر\n');

const blk  = L.slice(start, end).join('\n');
const rest = L.slice(0, start).concat(L.slice(end)).join('\n');

const defs = new Set();
[...blk.matchAll(/window\.([A-Za-z_$][\w$]*)\s*=/g)].forEach(m => defs.add(m[1]));
[...blk.matchAll(/^\s*(?:async\s+)?function\s+([A-Za-z_$][\w$]*)/gm)].forEach(m => defs.add(m[1]));
[...blk.matchAll(/^\s*(?:const|let|var)\s+([A-Za-z_$][\w$]*)/gm)].forEach(m => defs.add(m[1]));

const used = [], dead = [];
[...defs].sort().forEach(n => {
  const re = new RegExp('\b' + n.replace(/\$/g, '\$') + '\b');
  (re.test(rest) ? used : dead).push(n);
});
console.log('🔴 بيتنده من برّه — لازم يفضل (' + used.length + '):');
console.log('   ' + (used.join(', ') || '(مفيش)'));
console.log('\n✅ ميّت (' + dead.length + '):');
console.log('   ' + dead.join(', '));
