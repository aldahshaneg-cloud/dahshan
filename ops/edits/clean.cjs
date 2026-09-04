/* تنضيف كود شاشة «أوردر جديد» المحذوفة.
   بيتشال جزئين وبيتساب `logZoneRequest` بينهم — دي بتغذّي صفحة
   «دليل المناطق» وممنوع تروح. */
const fs = require('fs');
const f = 'public/callcenter.html';
const L = fs.readFileSync(f, 'utf8').split('\n');

const find = (needle, from = 0) => {
  const i = L.findIndex((l, idx) => idx >= from && l.includes(needle));
  if (i < 0) { console.log('✗ مالقيتش: ' + needle); process.exit(1); }
  return i;
};

// ① من عنوان «شاشة الأوردر الجديد» (وتعليقه اللي فوقه) لحد قبل logZoneRequest
const h1    = find('شاشة الأوردر الجديد');
const open1 = h1 - 1;                       // سطر /* ═══
const stop1 = find('async function logZoneRequest');

// ② معالجَي الإغلاق بتوع القائمة الذكية — بقوا بلا عناصر
const h2    = find('document.addEventListener("click", ev => {', stop1);
const stop2 = find('بحث سريع', h2) - 1;      // سطر /* ═══ بتاع القسم اللي بعده

console.log('① سطور ' + (open1 + 1) + '..' + stop1 + '  = ' + (stop1 - open1) + ' سطر');
console.log('② سطور ' + (h2 + 1) + '..' + (stop2) + '  = ' + (stop2 - h2) + ' سطر');
console.log('الإجمالي: ' + ((stop1 - open1) + (stop2 - h2)) + ' سطر');

const note = [
  '  /* ══════════════════════════════════════════════════════════════',
  '     [اتشال] كود شاشة «أوردر جديد» المستقلة',
  '     ──────────────────────────────────────────────────────────────',
  '     الشاشة اتشالت بقرار صاحب النظام (نفس الوظيفة في مودال «➕ طلب',
  '     جديد» جوه صفحة الطلبات)، وكودها اتشال معاها — 708 سطر مكانوش',
  '     بيتنفّذوا خالص بعد ما اتشال الـHTML بتاعها.',
  '',
  '     اللي اتساب عن قصد: `logZoneRequest` تحت — دي بتسجّل «المناطق',
  '     المطلوبة» وبتغذّي صفحة «دليل المناطق»، فمالهاش علاقة بالشاشة.',
  '  ══════════════════════════════════════════════════════════════ */',
];

// من تحت لفوق عشان الأرقام ماتزحلقش
L.splice(h2, stop2 - h2);
L.splice(open1, stop1 - open1, ...note);
fs.writeFileSync(f, L.join('\n'));
console.log('✓ ' + f);
