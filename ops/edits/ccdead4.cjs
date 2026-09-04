/* ذيل التتابع بتاع `ccdead2.cjs`.
 *
 * ماسح التتابع هناك بيدوّر على شكلين بس: `function X(` و
 * `window.X = function`. فالدالة المعرّفة كسهم — `const X = z => {…}` —
 * مابتدخلش اللفّ خالص. `isHomeZone` كانت بتتنده من `searchZones` بس،
 * ولما دي اتشالت فضلت هي بلا نداء والماسح ماشافهاش.
 *
 * (الماسح المستقل — اللي بيقرا `const X = …=>` كمان — هو اللي مسكها.)
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILE = path.resolve(__dirname, '../../public/callcenter.html');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');
const before = s.split('\n').length;

const OLD =
`  /* «صف البيت» = المنطقة عند الفرع المالك لها (شوف البلوك فوق الملف).
     الاستلام بيشوفهم بس، والتسليم بيشوف الكل للفرع المختار. */
  const isHomeZone = z => {
    const src = z.sourceBranchId, dst = zoneBranchId(z);
    return src == null || src === "" || String(src) === String(dst);
  };

`;

const n = s.split(OLD).length - 1;
if (n !== 1) { console.log('✗ متوقّع ١ لقى ' + n + ' — مافيش بايت اتكتب.'); process.exit(1); }

/* الاسم مايكونش مذكور في أي حتة تانية (بره التعليقات) */
{
  const bare = s.replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/(^|[^:"'`\\])\/\/[^\n]*/g, '$1 ');
  const uses = (bare.match(/\bisHomeZone\b/g) || []).length;
  if (uses !== 1) { console.log('✗ isHomeZone ليها ' + uses + ' ذكر — مش ميتة. مافيش بايت اتكتب.'); process.exit(1); }
  console.log('  ✓ isHomeZone: ذكر واحد بس (التعريف)');
}

s = s.split(OLD).join('');

/* التركيب بعد الشيل */
{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, bad = 0;
  while ((m = re.exec(s)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
    const tmp = path.join(os.tmpdir(), 'ccdead4-' + process.pid + '-' + i + '.mjs');
    try { fs.writeFileSync(tmp, m[2]); execFileSync(process.execPath, ['--check', tmp], { stdio: 'pipe' }); }
    catch (e) { bad++; console.log('  ✗ كتلة ' + i + ': ' + ((e.stderr || '').toString().match(/SyntaxError:.*/) || ['?'])[0]); }
    finally { try { fs.unlinkSync(tmp); } catch (e) {} }
  }
  console.log((bad ? '  ✗' : '  ✓') + ' كل كتل الجافاسكربت سليمة (' + i + ' كتلة)');
  if (bad) { console.log('\n⛔ مافيش بايت اتكتب.'); process.exit(1); }
}

if (process.env.DRY) { console.log('\n🟦 DRY — مافيش بايت اتكتب.'); process.exit(0); }
fs.writeFileSync(FILE, WAS_CRLF ? s.replace(/\n/g, '\r\n') : s);
console.log('\n✓ اتكتب ' + path.basename(FILE) + '  (' + before + ' → ' + s.split('\n').length + ')');
