/**
 * 🔤 حارس الهروب (escaping) على كل صفحات النظام.
 *
 * ═══ الباج اللي بيحرسه ═══
 * في صفحة «نقل الطيارين» كانت شارة الحالة بتتعرض كنص خام على الشاشة:
 *   <span class="status-badge status-cancelled">❌ مرفوض</span>
 * السبب `${ esc(statusMap[r.status]) }` — و`statusMap` قيمها HTML مكتوب
 * في الكود، فـ`esc()` حوّلت الوسوم لحروف.
 *
 * ═══ الفرق اللي الماسح لازم يعرفه ═══
 * مش كل `esc()` على خريطة غلط:
 *   • `CTYPES = { late: "تأخير في التوصيل" }`  ← نصوص عربية، الهروب **صح**
 *   • `statusMap = { rejected: "<span…>" }`     ← HTML، الهروب **غلط**
 * فالماسح بيفتح تعريف الخريطة ويبص على قيمها — مش على اسمها.
 *
 * وبيحرس الاتجاه التاني كمان: أي حقل نصّي جاي من قاعدة البيانات لازم
 * يعدّي على `esc()`، وإلا اللي اتكتب في القاعدة بيتنفّذ كـHTML.
 *
 * التشغيل: node ops/test_escaping.cjs
 */
const fs = require('fs');
const path = require('path');

let pass = 0, fail = 0;
const ok = (w, c, got) => { c ? (pass++, console.log('  ✓ ' + w))
  : (fail++, console.log('  ✗ ' + w + (got !== undefined ? '\n       ' + got : ''))); };

/* بنقص تعريف اسم (خريطة أو متغيّر) ونشوف قيمه فيها وسوم ولا لأ */
function definitionOf(src, name) {
  const re = new RegExp('(?:const|let|var)\\s+' + name.replace(/\$/g, '\\$') + '\\s*=', 'g');
  const m = re.exec(src);
  if (!m) return null;
  const start = m.index + m[0].length;
  /* لحد الفاصلة المنقوطة اللي على مستوى صفر */
  let depth = 0;
  for (let j = start; j < Math.min(src.length, start + 4000); j++) {
    const c = src[j];
    if ('{[('.includes(c)) depth++;
    else if ('}])'.includes(c)) depth--;
    else if (c === ';' && depth <= 0) return src.slice(start, j);
    else if (c === '`' || c === '"' || c === "'") {
      const q = c; j++;
      while (j < src.length && src[j] !== q) { if (src[j] === '\\') j++; j++; }
    }
  }
  return src.slice(start, start + 4000);
}

const HTML_TAG = /<(?:span|div|button|b|i|img|a|td|tr|p|small|strong|em|br)\b/;

function scanFile(file) {
  const src = fs.readFileSync(file, 'utf8');
  const bad = [];

  /* ── esc() على قيمة أصلها HTML ── */
  for (const m of src.matchAll(/esc\(\s*([A-Za-z_$][\w$]*)\s*(\[|\)|\s*\|\|)/g)) {
    const name = m[1];
    const def = definitionOf(src, name);
    if (!def || !HTML_TAG.test(def)) continue;     // نصوص عادية — الهروب صح
    const line = src.slice(0, m.index).split('\n').length;
    bad.push({ line, name, txt: src.split('\n')[line - 1].trim().slice(0, 110) });
  }
  return bad;
}

const FILES = fs.readdirSync('public')
  .filter(f => f.endsWith('.html'))
  .map(f => path.join('public', f));

console.log('\n══ 1) 🔴 مافيش HTML بيتهرب فيتعرض كحروف ══');
let total = 0;
for (const f of FILES) {
  const bad = scanFile(f);
  total += bad.length;
  if (bad.length) {
    ok(path.basename(f), false,
       bad.map(b => 'سطر ' + b.line + ' — esc(' + b.name + ') · ' + b.txt).join('\n       '));
  }
}
ok('كل الـ' + FILES.length + ' صفحة نضيفة', total === 0, total + ' موضع');

console.log('\n══ 2) الماسح نفسه بيفرّق صح ══');
/* حارس على الحارس: لازم يمسك HTML ويسيب النصوص العادية */
{
  const tmp = 'ops/edits/_esc_probe.html';
  fs.writeFileSync(tmp, `<script>
    const LABELS = { a: "تأخير في التوصيل", b: "طرد تالف" };
    const BADGES = { a: \`<span class="x">مرفوض</span>\` };
    const h = \`\${ esc(LABELS[k]) } \${ esc(BADGES[k]) }\`;
  </script>`);
  const hits = scanFile(tmp);
  ok('بيمسك الخريطة اللي فيها HTML', hits.some(h => h.name === 'BADGES'),
     hits.map(h => h.name).join(', ') || '(مامسكش حاجة)');
  ok('وبيسيب خريطة النصوص العادية', !hits.some(h => h.name === 'LABELS'),
     'اتحسبت غلط');
  fs.unlinkSync(tmp);
}

console.log('\n══ 3) الموضعين اللي اتصلّحوا فعلًا ══');
for (const f of ['public/tiar.html', 'public/callcenter.html']) {
  const src = fs.readFileSync(f, 'utf8');
  ok(path.basename(f) + ': شارة الدعم من غير esc',
     /\$\{ statusMap\[r\.status\] \|\| "" \}/.test(src),
     'الشارة لسه مهروبة أو اتغيّر شكلها');
  /* واللي حواليها لازم يفضل مهروب — الأسماء دي جاية من القاعدة */
  ok(path.basename(f) + ': اسم الفرع لسه مهروب',
     /esc\(r\.fromBranchName\)/.test(src));
  ok(path.basename(f) + ': اسم الطيار لسه مهروب',
     /esc\(r\.pilotName\)/.test(src));
}

console.log('\n════════════════════════════════════════');
console.log('ESCAPING: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
