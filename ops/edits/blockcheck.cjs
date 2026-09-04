/* بيتأكد إن `_branchNameOf` معرَّف في **نفس** كتلة الـ<script> اللي بتنده
   عليه — رفع الدوال (hoisting) بيشتغل جوه الكتلة بس، ونداء من كتلة تانية
   بيقع ReferenceError وقت التشغيل مش وقت الفحص. */
const fs = require('fs');
let bad = 0;
for (const f of process.argv.slice(2)) {
  const src = fs.readFileSync(f, 'utf8');
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, defBlocks = [], useBlocks = [];
  while ((m = re.exec(src)) !== null) {
    i++;
    const body = m[2] || '';
    if (body.includes('function _branchNameOf')) defBlocks.push(i);
    if (body.split('_branchNameOf(').length - 1 > (body.includes('function _branchNameOf') ? 1 : 0)) useBlocks.push(i);
  }
  const ok = useBlocks.length === 0 || (defBlocks.length === 1 && useBlocks.every(b => b === defBlocks[0]));
  console.log((ok ? '✓ ' : '✗ ') + f + ' — تعريف في ' + JSON.stringify(defBlocks) + ' · نداء في ' + JSON.stringify(useBlocks));
  if (!ok) bad++;
}
process.exit(bad ? 1 : 0);
