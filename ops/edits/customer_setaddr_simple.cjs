/* 🔴 `setAddr` مابتشتغلش خالص في الوضع المبسّط — تطبيق العميل.
 *
 * ═══ الاكتشاف ═══
 * ودجت العنوان ليه وضعين:
 *   • كامل  → بيرسم `-gov` (محافظة) و`-area` (مدينة) و`-detail`
 *   • مبسّط → بيرسم `-detail` **بس** (سطر 1512-1516)
 *
 * `getAddr` (1554) بتتعامل مع الاتنين صح — بتشوف `-gov` مش موجودة فبترجّع
 * التفصيل لوحده. لكن `setAddr` (1562) بتعمل:
 *       const g = $(`${containerId}-gov`);
 *       if (!g) return;                     ← 🔴 بترجع من غير ما تكتب حاجة
 *
 * وعنوان **المستلم** مبسّط (سطر 3649: `simple: true`)، وعنوان المُرسِل كامل
 * (3419). عشان كده زرار «بياناتي» شغّال في المُرسِل ومكسور في المستلم.
 *
 * ═══ أربع مواضع ميتة بسبب ده ═══
 *   3724  التعبئة التلقائية من منظومة الثقة
 *   3748  زرار «استخدمه» للعنوان المقترح
 *   4003  شيب «👤 بياناتي» (الميزة اللي صاحب النظام طلبها 2026-09-01)
 *   4015  شيب «مستلمون سابقون»
 *
 * وأخطرهم 3724: بتكتب العنوان في **الحالة** ومابتكتبوش في الـDOM. فأول
 * `readReceiverBlocks()` بتقرا "" من الخانة الفاضية وتمسح العنوان من الحالة —
 * العميل بيشوف «✓ العنوان اتملى من سجلاتنا» وبعدين «اكتب عنوان التسليم».
 * ده شكل تاني من نفس شكوى «بكتبها وتتمسح».
 *
 * ═══ الإصلاح ═══
 * نفس منطق `getAddr` بالظبط: لو مافيش `-gov` يبقى التفصيل هو العنوان كله.
 * سطر واحد. المُرسِل (الوضع الكامل) مابيتأثرش — `g` موجودة عنده فالمسار
 * القديم بيتنفّذ زي ما هو.
 *
 * ═══ وكمان: حارس على `phone2` في شيب «بياناتي» ═══
 * `startNewOrder` بترفض من غير ملف مكتمل (سطر 3399)، و`saveProfile` بتفرض
 * الاسم والتليفون والمنطقة — فدول مضمونين. لكن `phone2` **اختياري**.
 * فالكتابة غير المشروطة `= p.phone2 || ""` بتمسح رقم إضافي العميل كتبه
 * بإيده لو ملفه مالوش رقم تاني. بنكتبه بس لو موجود.
 * (`address` محمي أصلًا: `setAddr` بترجع بدري على النص الفاضي — سطر 1563.)
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILE = path.resolve(__dirname, '../../public/customer.html');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');

const EDITS = [
  {
    label: 'setAddr: دعم الوضع المبسّط',
    old:
`  const g = $(\`\${containerId}-gov\`), a = $(\`\${containerId}-area\`), d = $(\`\${containerId}-detail\`);
  if (!g) return;`,
    neu:
`  const g = $(\`\${containerId}-gov\`), a = $(\`\${containerId}-area\`), d = $(\`\${containerId}-detail\`);
  /* 🔴 الوضع المبسّط (\`simple:true\`) بيرسم \`-detail\` بس — نفس منطق getAddr
     فوق: التفصيل هو العنوان كله. كان \`if (!g) return;\` يعني الدالة **مابتعملش
     حاجة** خالص لعنوان المستلم، فأربع مواضع كانت ميتة بصمت: التعبئة من
     منظومة الثقة · زرار «استخدمه» · شيب «بياناتي» · شيب «مستلمون سابقون».
     وأخطرهم اللي بيكتب العنوان في الحالة بس — أول مزامنة كانت بتقرا الخانة
     الفاضية وتمسحه. (اتكشف 2026-09-01.) */
  if (!g) { if (d) d.value = text; return; }`,
  },
  {
    label: 'شيب «بياناتي»: ماتمسحش رقم إضافي مكتوب',
    old: `        $("rcvPhone2-" + i).value = p.phone2 || "";`,
    neu:
`        /* الاسم والتليفون مضمونين (startNewOrder بترفض من غير ملف مكتمل)،
           لكن الرقم الإضافي اختياري — فالكتابة غير المشروطة كانت هتمسح رقم
           العميل كتبه بإيده لو ملفه مالوش رقم تاني. */
        if (p.phone2) $("rcvPhone2-" + i).value = p.phone2;`,
  },
];

/* ══ فحوص قبلية ══ */
const problems = [];
for (const e of EDITS) {
  const n = s.split(e.old).length - 1;
  if (n !== 1) problems.push(`«${e.label}»: متوقّع ١ لقى ${n}`);
}
/* الوضع المبسّط لازم يكون فعلًا موجود ومستعمل للمستلم */
if (!/renderAddr\("rcvAddrW-" \+ i, \{ value: r\.address \|\| "", simple: true \}\)/.test(s))
  problems.push('عنوان المستلم مش مبسّط — راجع الافتراض');
if (problems.length) {
  console.log('⛔ مافيش بايت اتكتب:');
  for (const p of problems) console.log('   ✗ ' + p);
  process.exit(1);
}
for (const e of EDITS) s = s.split(e.old).join(e.neu);

/* ══ فحوص بعدية ══ */
const after = [];
{
  /* ⚠️ لازم نمشّط التعليقات: الشرح اللي فوق الإصلاح بيقتبس `if (!g) return;`
     كمثال على الكود القديم — فالفحص على الخام بيقع على تعليقه هو. */
  const blank = m => m.replace(/[^\n]/g, ' ');
  const bare = s.replace(/\/\*[\s\S]*?\*\//g, blank)
                .replace(/(^|[^:"'`\\])\/\/[^\n]*/g, (m, p) => p + blank(m.slice(p.length)));
  const at = bare.indexOf('function setAddr');
  const fn = bare.slice(at, bare.indexOf('\n}', at));
  if (/if \(!g\) return;/.test(fn)) after.push('الرجوع المبكر لسه موجود');
  if (!/if \(!g\) \{ if \(d\) d\.value = text; return; \}/.test(fn)) after.push('فرع الوضع المبسّط مش موجود');
  /* المسار الكامل (المُرسِل) لازم يفضل زي ما هو */
  if (!/g\.value = gov; fillCities\(containerId, ""\);/.test(fn)) after.push('مسار الوضع الكامل اتغيّر');
}
if (/\$\("rcvPhone2-" \+ i\)\.value = p\.phone2 \|\| "";/.test(s)) after.push('كتابة phone2 غير المشروطة لسه موجودة');
if (!/if \(p\.phone2\) \$\("rcvPhone2-" \+ i\)\.value = p\.phone2;/.test(s)) after.push('حارس phone2 مش موجود');
{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, bad = 0;
  while ((m = re.exec(s)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
    const tmp = path.join(os.tmpdir(), 'sad-' + process.pid + '-' + i + '.mjs');
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
console.log('✓ اتكتب customer.html — setAddr بتشتغل في الوضع المبسّط (٤ مواضع اتصلّحت)');
