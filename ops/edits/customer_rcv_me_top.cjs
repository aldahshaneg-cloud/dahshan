/* نقل شيب «👤 بياناتي» لأعلى بلوك المستلم — تطبيق العميل.
 *
 * ═══ الطلب (صاحب النظام، 2026-09-01) ═══
 * «زرار بياناتي لازم يكون في الأعلى زي الفورم السابق».
 *
 * ═══ نفس السبب موثّق في فورم المُرسِل ═══
 * التعليق اللي فوق `sndSaved` (سطر ~697) بيقول حرفيًا:
 *   «شيبات الملء السريع بقت هنا فوق: كانت تحت الحقول، يعني العميل بيكتب
 *    بياناته بالإيد الأول وبعدين يكتشف إن فيه زرار بيملاها».
 * وبلوك المستلم كان لسه على الوضع القديم — الحاوية `rcvSaved-${i}` آخر
 * حاجة في البلوك، بعد الاسم والتليفون والعنوان والمنطقة والخريطة كلهم.
 *
 * ═══ اللي بيتعمل ═══
 * الحاوية بتتنقل من آخر البلوك لأول حاجة بعد الرأس (`.rcv-hd`) — قبل
 * مفتاح «مش معايا بيانات المستلم» وقبل كل الحقول.
 *
 * ليه تحت الرأس مش **جوّه** الرأس (زي المُرسِل بالظبط): رأس المستلم فيه
 * زرارين إجراء أصلًا (`＋ طرد لنفس المستلم` و`🗑️`) و`.rcv-hd` عبارة عن
 * صف flex واحد — وشيبات المستلم ممكن توصل ١١ (بياناتي + ١٠ سابقين).
 * حشرهم في نفس الصف بيزحم الشاشة على الموبايل. صف مستقل تحت الرأس بيدّي
 * نفس الفايدة (قبل أي حقل بيتكتب فيه) وعرض كامل.
 *
 * وبنسيب `class="chips"` (بتلف) مش `chips-inline` — دي متقيّدة بـ
 * `max-width:58%` لأنها مصمّمة تقعد جنب عنوان المُرسِل في نفس السطر،
 * فهتضيّع نص العرض في صف مستقل.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILE = path.resolve(__dirname, '../../public/customer.html');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');

/* الحاوية زي ما هي دلوقتي، آخر البلوك */
const OLD_BOTTOM =
`
    <div id="rcvSaved-\${i}" style="margin-top:10px;display:\${receipt ? "none" : "block"}"></div>
  </div>\`;`;
const NEW_BOTTOM =
`  </div>\`;`;

/* الرأس — بنحط الحاوية بعده على طول */
const OLD_HEAD =
`                   data-tip="حذف الطرد ده من الطلب">🗑️</button>\` : ""}
    </div>
`;
const NEW_HEAD =
`                   data-tip="حذف الطرد ده من الطلب">🗑️</button>\` : ""}
    </div>

    <!-- الملء السريع فوق، قبل أي خانة بتتكتب. كان آخر البلوك — يعني العميل
         بيكتب بيانات المستلم بالإيد كلها وبعدين يكتشف إن فيه زرار بيملاها.
         نفس النقلة اللي اتعملت في فورم المُرسِل قبل كده. -->
    <div id="rcvSaved-\${i}" style="margin-bottom:12px;display:\${receipt ? "none" : "block"}"></div>
`;

/* ══ فحوص قبلية ══ */
const problems = [];
for (const [label, txt, want] of [
  ['الحاوية في آخر البلوك', OLD_BOTTOM, 1],
  ['رأس البلوك', OLD_HEAD, 1],
]) {
  const n = s.split(txt).length - 1;
  if (n !== want) problems.push(`${label}: متوقّع ${want} لقى ${n}`);
}
/* لازم تكون موجودة مرة واحدة بس قبل النقل */
if ((s.match(/id="rcvSaved-\$\{i\}"/g) || []).length !== 1)
  problems.push('عدد حاويات rcvSaved مش ١ — اتنقلت قبل كده؟');
if (problems.length) {
  console.log('⛔ مافيش بايت اتكتب:');
  for (const p of problems) console.log('   ✗ ' + p);
  process.exit(1);
}

s = s.split(OLD_BOTTOM).join(NEW_BOTTOM).split(OLD_HEAD).join(NEW_HEAD);

/* ══ فحوص بعدية ══ */
const after = [];
if ((s.match(/id="rcvSaved-\$\{i\}"/g) || []).length !== 1) after.push('عدد الحاويات مش ١ بعد النقل');

/* 🔴 الفحص اللي بيهم فعلًا: الحاوية قبل خانة الاسم جوه نفس الدالة */
{
  const at = s.indexOf('function receiverBlockHtml');
  const fn = s.slice(at, s.indexOf('\n}', s.indexOf('return `', at)));
  const iSaved = fn.indexOf('id="rcvSaved-');
  const iName  = fn.indexOf('id="rcvName-');
  const iHead  = fn.indexOf('class="rcv-hd"');
  if (!(iSaved > -1 && iName > -1 && iSaved < iName)) after.push('الحاوية مش قبل خانة الاسم');
  if (!(iHead > -1 && iHead < iSaved)) after.push('الحاوية مش بعد الرأس');
  console.log('  · الترتيب: رأس@' + iHead + ' → بياناتي@' + iSaved + ' → الاسم@' + iName);
}
/* بلوك المُرسِل ما اتلمسش */
if (!/<div id="sndSaved" style="margin-inline-start:auto"><\/div>/.test(s)) after.push('حاوية المُرسِل اتغيّرت');
{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, bad = 0;
  while ((m = re.exec(s)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
    const tmp = path.join(os.tmpdir(), 'rmt-' + process.pid + '-' + i + '.mjs');
    try { fs.writeFileSync(tmp, m[2]); execFileSync(process.execPath, ['--check', tmp], { stdio: 'pipe' }); }
    catch (e) { bad++; console.log('  ✗ كتلة ' + i + ': ' + ((e.stderr || '').toString().match(/SyntaxError:.*/) || ['?'])[0]); }
    finally { try { fs.unlinkSync(tmp); } catch (e2) {} }
  }
  console.log((bad ? '  ✗' : '  ✓') + ' كل كتل الجافاسكربت سليمة (' + i + ' كتلة)');
  if (bad) after.push('كتل مكسورة');
}
if (after.length) {
  console.log('⛔ فحوص بعدية وقعت — مافيش بايت اتكتب:');
  for (const a of after) console.log('   ✗ ' + a);
  process.exit(1);
}

if (process.env.DRY) { console.log('🟦 DRY — كل الفحوص عدّت، مافيش بايت اتكتب.'); process.exit(0); }
fs.writeFileSync(FILE, WAS_CRLF ? s.replace(/\n/g, '\r\n') : s);
console.log('✓ اتكتب customer.html — «بياناتي» بقى فوق قبل كل الخانات');
