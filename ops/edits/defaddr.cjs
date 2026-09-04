/* المحافظة/المدينة الافتراضية لأي عنوان جديد: الدقهلية / المنصورة.
   الويدجت متكرر بالنص جوه ٤ صفحات + نسخة مستقلة في customer.html،
   فالباتش بيتطبّق على الخمسة مع فحص إن كل قطعة اتلقت مرة واحدة بالظبط. */
const fs = require('fs');
let touched = 0, bad = 0;

const DEF = `    /* ══ الافتراضي لأي عنوان جديد ══
       الدقهلية / المنصورة — ده مقر الشغل، فأغلب الإدخالات عليه
       والموظف بيوفّر اختيارين في كل فورم. */
    window.ADDR_DEFAULT = { gov: 'الدقهلية', city: 'المنصورة' };

`;

const OLD_ANCHOR = `      window.fillCities(containerId);\n`;
const NEW_ANCHOR = `      /* الافتراضي بيتحط بس لما يكون فيه سجل جديد بيتفتح:
         • \`opts.edit\` = بنعدّل سجل موجود — ممنوع نكتب فيه حاجة من عندنا،
           لأن الموظف ممكن يحفظ من غير ما ياخد باله فيتخزّن عنوان ما اختارهوش.
         • \`opts.blank\` = مخرج صريح لو حد عايز الحقل فاضي. */
      if (_govEl && !opts.edit && !opts.blank && !_govEl.value) {
        _govEl.value = window.ADDR_DEFAULT.gov;
        window.fillCities(containerId, window.ADDR_DEFAULT.city);
      } else {
        window.fillCities(containerId);
      }
`;

function once(src, needle, file, what) {
  const n = src.split(needle).length - 1;
  if (n !== 1) { console.log(`  ✗ ${file}: «${what}» اتلقت ${n} مرة`); bad++; return false; }
  return true;
}

/* ① الأربع صفحات ذات الويدجت المشترك */
for (const f of ['branch', 'callcenter', 'store', 'tiar']) {
  const p = `public/${f}.html`;
  let s = fs.readFileSync(p, 'utf8');
  if (!once(s, OLD_ANCHOR, f, 'fillCities(containerId);') ||
      !once(s, '    window.renderAddressWidget = function', f, 'renderAddressWidget')) continue;

  s = s.replace(OLD_ANCHOR, NEW_ANCHOR);
  s = s.replace('    window.renderAddressWidget = function', DEF + '    window.renderAddressWidget = function');

  /* تعليم مواضع التعديل: أي نداء بيقرا العنوان من سجل (value: x.y) */
  let tagged = 0;
  s = s.replace(/(renderAddressWidget\([^)]*?\{[^{}]*?value:\s*[a-zA-Z_$][\w$]*[.?])/g,
    m => { tagged++; return m.replace('{', '{ edit: true,'); });
  fs.writeFileSync(p, s);
  console.log(`  ✓ ${f}.html — ${tagged} نداء تعديل اتعلّم`);
  touched++;
}

/* ② customer.html — نسخة مستقلة، أسماء مختلفة */
{
  const p = 'public/customer.html';
  let s = fs.readFileSync(p, 'utf8');
  const A = `  $(\`\${containerId}-gov\`).addEventListener("change", () => fillCities(containerId));\n  fillCities(containerId);\n`;
  if (once(s, A, 'customer', 'مرساة renderAddr')) {
    s = s.replace(A, `  $(\`\${containerId}-gov\`).addEventListener("change", () => fillCities(containerId));
  /* عنوان جديد بيفتح على الدقهلية / المنصورة — شوف ADDR_DEFAULT.
     لو جاي معانا عنوان محفوظ، \`setAddr\` تحت بتكتب فوقه. */
  if (!opts.value && !opts.blank) {
    $(\`\${containerId}-gov\`).value = ADDR_DEFAULT.gov;
    fillCities(containerId, ADDR_DEFAULT.city);
  } else {
    fillCities(containerId);
  }
`);
    s = s.replace('const EG_GOVERNORATES = [',
      "const ADDR_DEFAULT = { gov: 'الدقهلية', city: 'المنصورة' };   // الافتراضي لأي عنوان جديد\nconst EG_GOVERNORATES = [");
    fs.writeFileSync(p, s);
    console.log('  ✓ customer.html');
    touched++;
  }
}
console.log(bad ? `\n🔴 ${bad} مشكلة — راجع` : `\n✅ ${touched}/5 ملفات`);
process.exit(bad ? 1 : 0);
