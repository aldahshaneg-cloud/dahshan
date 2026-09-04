/* CSS الكمبوننت الذكي — بقى بلا عناصر بعد ما الشاشة اتشالت.
   ⚠️ `.smart-search-*` كمبوننت **تاني خالص** لسه شغّال في المودال — ممنوع يتلمس. */
const fs = require('fs');
const f = 'public/callcenter.html';
const L = fs.readFileSync(f, 'utf8').split('\n');
const start = L.findIndex(l => l.trim() === '.smart { position: relative; }');
if (start < 0) { console.log('✗ مالقيتش البداية'); process.exit(1); }
// النهاية: آخر قاعدة تخص .smart قبل ما يبدأ سيليكتور مش منها
let end = start;
for (let i = start; i < L.length; i++) {
  const t = L[i].trim();
  if (/^\.smart-search/.test(t)) break;                    // الكمبوننت التاني — نقف
  if (/^\.(smart|si-)/.test(t) || /^\/\*/.test(t) || t === '' || /^[a-z-]+:/.test(t)
      || /^\}/.test(t) || /^:root\.light \.smart/.test(t) || /^\.smart/.test(t)) { end = i; continue; }
  if (/^\.[a-zA-Z]/.test(t) && !/^\.smart/.test(t) && !/^\.si-/.test(t)) break;
}
console.log('CSS من ' + (start+1) + ' لـ ' + (end+1) + ' = ' + (end-start+1) + ' سطر');
console.log('آخر سطر: ' + L[end].trim().slice(0,70));
console.log('اللي بعده: ' + (L[end+1]||'').trim().slice(0,70));
