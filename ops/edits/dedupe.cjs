/* البلوك اتكرر لأن `homebranch.cjs` اتشغّل مرتين والمرساة فضلت موجودة
   في نص الاستبدال. المفاتيح المتكررة في مصفوفة PHP بتعدّي من غير خطأ —
   الأخيرة بتغلب — فالتكرار ده مابيبانش غير بالعين. */
const fs = require('fs');
const f = 'app/Wire/CoreWire.php';
let s = fs.readFileSync(f, 'utf8');

const KEY = "'homeBranchId' =>";
const n = s.split(KEY).length - 1;
console.log('عدد المرات: ' + n);
if (n === 1) { console.log('✓ مفيش تكرار'); process.exit(0); }
if (n !== 2) { console.log('🔴 عدد غير متوقع — وقف'); process.exit(1); }

/* بنشيل التكرار التاني: من التعليق اللي قبله لحد سطر homeBranchName */
const first = s.indexOf(KEY);
const second = s.indexOf(KEY, first + 1);
const commentStart = s.lastIndexOf('/* الفرع الثابت', second);
const endMark = "'homeBranchName' => $r['home_branch_name'] ?? null,\n";
const end = s.indexOf(endMark, second) + endMark.length;
if (commentStart < first || end <= second) { console.log('🔴 حدود غلط'); process.exit(1); }

/* المسافات اللي قبل التعليق */
let a = commentStart;
while (a > 0 && (s[a - 1] === ' ' || s[a - 1] === '\t')) a--;
s = s.slice(0, a) + s.slice(end);
fs.writeFileSync(f, s);
console.log('✓ التكرار اتشال — فاضل ' + (s.split(KEY).length - 1));
