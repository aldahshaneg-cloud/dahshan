/* ترحيب الرئيسية بيعرض الاسم كامل مش أول كلمة — تطبيق العميل.
 *
 * ═══ الطلب (صاحب النظام، 2026-09-01 — مع سكرين شوت) ═══
 * «أريد أن يكمل الاسم، لا يكون مقطوعًا هكذا» — الشاشة بتقول «مرحبًا عبد»
 * لعميل اسمه «عبد الرحمن …».
 *
 * ═══ السبب ═══
 * customer.html سطر ~2173:
 *     $("homeName").textContent = (S.profile?.displayNameAr || "").split(" ")[0] || "بك";
 * `.split(" ")[0]` بياخد أول كلمة عمدًا (ترحيب بالاسم الأول ع الطريقة
 * الأجنبية) — بس مع الأسماء العربية المركّبة (عبد الرحمن · عبد الله ·
 * أبو بكر…) أول كلمة لوحدها اسم مقطوع مش اسم.
 *
 * ═══ الإصلاح ═══
 * الاسم كامل زي ما هو. الحاوية flex والترحيب في عمود `flex:1` — الاسم
 * الطويل بيلف سطر تاني طبيعي من غير ما يكسر الهيدر.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILE = path.resolve(__dirname, '../../public/customer.html');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');

const OLD = `  $("homeName").textContent = (S.profile?.displayNameAr || "").split(" ")[0] || "بك";`;
const NEU = `  /* الاسم **كامل** — كان \`.split(" ")[0]\` (أول كلمة بس)، ومع الأسماء
     المركّبة زي «عبد الرحمن» كان بيطلع «مرحبًا عبد». طلب صاحب النظام
     2026-09-01 بعد ما شافها على الموبايل. الحاوية flex فالاسم الطويل
     بيلف سطر تاني عادي. */
  $("homeName").textContent = (S.profile?.displayNameAr || "").trim() || "بك";`;

const problems = [];
const n = s.split(OLD).length - 1;
if (n !== 1) problems.push(`السطر المستهدف: متوقّع ١ لقى ${n}`);
if (problems.length) {
  console.log('⛔ مافيش بايت اتكتب:');
  for (const p of problems) console.log('   ✗ ' + p);
  process.exit(1);
}
s = s.split(OLD).join(NEU);

/* فحوص بعدية */
const after = [];
const blank = m => m.replace(/[^\n]/g, ' ');
const bare = s.replace(/\/\*[\s\S]*?\*\//g, blank);
if (/homeName"\)\.textContent = [^\n]*split\(" "\)\[0\]/.test(bare)) after.push('القص لسه موجود');
if (!/\$\("homeName"\)\.textContent = \(S\.profile\?\.displayNameAr \|\| ""\)\.trim\(\) \|\| "بك";/.test(s))
  after.push('السطر الجديد مش موجود');
{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, bad = 0;
  while ((m = re.exec(s)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
    const tmp = path.join(os.tmpdir(), 'cfn-' + process.pid + '-' + i + '.mjs');
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

if (process.env.DRY) { console.log('🟦 DRY — مافيش كتابة.'); process.exit(0); }
fs.writeFileSync(FILE, WAS_CRLF ? s.replace(/\n/g, '\r\n') : s);
console.log('✓ اتكتب customer.html — الترحيب بالاسم الكامل');
