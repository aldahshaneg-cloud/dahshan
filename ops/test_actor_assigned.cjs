/**
 * 🎭 حارس «النتيجة لازم تتسند» — actorOrFail() من غير إسناد + استعمال $actor
 *
 * البلاغ (2026-09-02، يوم الإطلاق): موافقة طلب الإرجاع بترمي
 * «حدث خطأ غير متوقع». السبب: `$request->actorOrFail();` من غير
 * `$actor =` وبعدها `assertRequestBranch($actor, …)` — متغير غير معرّف
 * = ErrorException = 500. تلات دوال في BoardController كانت مضروبة
 * بنفس الشكل (موافقة الإرجاع · رفضه · موافقة الانضمام).
 *
 * php -l مابيمسكش النوع ده (خطأ تشغيل مش تركيب)، وclassload كمان لأ —
 * فالحارس بيمشّط **كل** الكنترولرات: أي نداء عاري متبوع باستعمال
 * `$actor` قبل أي إسناد ليها جوه نفس الدالة = فشل.
 *
 * التشغيل: node ops/test_actor_assigned.cjs
 */
const fs = require('fs');

const DIR = 'app/Http/Controllers/Api/';
const strip = s => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

let bare = 0, bad = [];
for (const f of fs.readdirSync(DIR).filter(x => x.endsWith('.php'))) {
  const lines = fs.readFileSync(DIR + f, 'utf8').split('\n');
  lines.forEach((ln, i) => {
    if (!/^\s*\$request->actorOrFail\(\);\s*$/.test(ln)) return;
    bare++;
    let end = lines.length;
    for (let j = i + 1; j < lines.length; j++) {
      if (/^\s{4}(public|private|protected) (static )?function /.test(lines[j])) { end = j; break; }
    }
    const body = strip(lines.slice(i + 1, end).join('\n'));
    const assignPos = body.search(/\$actor\s*=/);
    const usePos = body.search(/\$actor\b/);
    if (usePos !== -1 && (assignPos === -1 || usePos < assignPos)) {
      bad.push(f + ':' + (i + 1));
    }
  });
}

console.log('نداءات عارية (فحص دخول بس): ' + bare);
if (bad.length) {
  bad.forEach(b => console.log('  ✗ 🔴 ' + b + ' — $actor مستعملة من غير تعريف (500 مضمون)'));
} else {
  console.log('  ✓ ولا واحد فيهم بيستعمل $actor بعدها');
}

console.log('\n════════════════════════════════════════');
console.log('ACTOR ASSIGNED: ' + (bad.length ? bad.length + ' فاشل' : 'نضيف'));
console.log('════════════════════════════════════════');
process.exit(bad.length ? 1 : 0);
