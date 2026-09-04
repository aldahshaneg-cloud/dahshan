const fs = require('fs');
const path = require('path');
const specPath = process.argv[2];
const root = path.resolve(__dirname, '../..');
const spec = JSON.parse(fs.readFileSync(specPath, 'utf8'));

/* نهايات السطور: ملفات فلاتر عندنا CRLF وملفات PHP LF. البدائل مكتوبة
   بـ\n دايمًا، فبنطبّع للمقارنة وبنرجّع الشكل الأصلي وقت الكتابة —
   من غير كده أي بديل بيعدّي أكتر من سطر بيفشل بصمت في ملف CRLF. */
const files = {};
const wasCrlf = {};
let bad = 0;
for (const e of spec) {
  const p = path.join(root, e.file);
  if (!(e.file in files)) {
    const raw = fs.readFileSync(p, 'utf8');
    wasCrlf[e.file] = raw.includes('\r\n');
    files[e.file] = raw.replace(/\r\n/g, '\n');
  }
  const n = files[e.file].split(e.old).length - 1;
  if (n !== e.count) {
    bad++;
    console.log('✗ ' + e.file + ' — متوقّع ' + e.count + ' لقى ' + n + '\n   ' + e.old.slice(0, 90));
  }
}
// أول لفّة بتتأكد من كل حاجة **قبل** ما نكتب أي ملف — سكريبت بيقع في
// النص بيسيب ملفات نص متعدّلة، وده أوحش من إنه مايشتغلش أصلًا.
if (bad) { console.log('\n⛔ مافيش أي ملف اتكتب.'); process.exit(1); }

for (const e of spec) files[e.file] = files[e.file].split(e.old).join(e.new);
for (const f of Object.keys(files)) {
  const out = wasCrlf[f] ? files[f].replace(/\n/g, '\r\n') : files[f];
  fs.writeFileSync(path.join(root, f), out);
  console.log('✓ ' + f + (wasCrlf[f] ? '  (CRLF)' : ''));
}
