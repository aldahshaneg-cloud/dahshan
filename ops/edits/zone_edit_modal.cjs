/* 🔴 تعديل سعر منطقة موجودة كان مكسور — tiar.html.
 *
 * ═══ الباج ═══
 * `openEditZone` (سطر ~3110) بتملّي السيلكتين بالقيم الحالية عن طريق
 * `_fillBranchSelect` (بتحط `selected` على الفرع الصح)، وبعدين بتنده
 * `setEditMode(...)` (بتضبط `_editState.active = true`) وبعدها `openModal('zone')`.
 *
 * و`openModal` في فرع `type === "zone"` بيدوس على الاتنين:
 *     if (dSel) dSel.innerHTML = branchOpts;
 *     if (sSel) sSel.innerHTML = branchOpts;
 * **من غير أي حارس** — فالاختيار بيرجع «— اختر الفرع —».
 *
 * النتيجة: المدير يدوس «تعديل» على منطقة، الفروع بتترجّع فاضية. ولو دوس
 * «تحديث المنطقة» على طول، `addZone` بتقع عند
 *     if (!deliveryBranchId) { showToast("يرجى اختيار الفرع المسؤول عن التوصيل"); return; }
 * فالتعديل مابيحصلش. يعني تصحيح سعر منطقة غلط محتاج إعادة اختيار الفرعين
 * بالإيد كل مرة، والمدير اللي مايعرفش ده بيفتكر إن التعديل مكسور.
 *
 * ده مساس مباشر بصحة الأسعار: السعر ده بيتحول لفلوس على العميل، ولو
 * اتحط غلط مافيش طريق واضح لتصحيحه.
 *
 * ═══ الإصلاح ═══
 * نفس الحارس المستعمل في فرعي `sender` (سطر ~3514) و`receiver` (~3525)
 * في نفس الدالة بالظبط: `if (!window._editState?.active)`.
 * وضع الإضافة مالوش أي تأثير — `resetZoneModal()` بتتنده قبل `openModal`
 * من زرار «➕ إضافة منطقة جديدة»، و`_editState.active` بتبقى false فالسيلكتس
 * بتتملى زي ما كانت.
 *
 * (اتكشف في تحقيق موازي يوم 2026-09-01 وأنا بصلّح باج الدفعة — فات على
 *  الفحص الأول لأن الشكوى كانت عن الإضافة مش التعديل.)
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILE = path.resolve(__dirname, '../../public/tiar.html');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');

const OLD =
`      if (type === "zone") {
        const branchOpts = \`<option value="">— اختر الفرع —</option>\` +
          window._branchesList.map(b => \`<option value="\${ esc(b.id) }" data-name="\${ esc(b.name) }">\${ esc(b.name) }</option>\`).join("");
        const dSel = document.getElementById("zoneDeliveryBranch");
        const sSel = document.getElementById("zoneSourceBranch");
        if (dSel) dSel.innerHTML = branchOpts;
        if (sSel) sSel.innerHTML = branchOpts;
      }`;

const NEU =
`      if (type === "zone") {
        /* 🔴 الحارس ده هو اللي بيخلّي «تعديل منطقة» يشتغل أصلًا.
           \`openEditZone\` بتملّي السيلكتين بالفرع الحالي (_fillBranchSelect
           بتحط selected) وبعدين بتنده openModal — ومن غير الشرط ده كان
           السطرين تحت بيدوسوا على الاختيار ويرجّعوه «— اختر الفرع —»،
           فـ\`addZone\` تقع عند فحص deliveryBranchId وتطلع «يرجى اختيار
           الفرع» ومايحصلش تعديل. يعني مافيش طريق شغّال لتصحيح سعر منطقة
           — وده فلوس على العميل. نفس حارس فرعي sender و receiver فوق. */
        if (!window._editState?.active) {
          const branchOpts = \`<option value="">— اختر الفرع —</option>\` +
            window._branchesList.map(b => \`<option value="\${ esc(b.id) }" data-name="\${ esc(b.name) }">\${ esc(b.name) }</option>\`).join("");
          const dSel = document.getElementById("zoneDeliveryBranch");
          const sSel = document.getElementById("zoneSourceBranch");
          if (dSel) dSel.innerHTML = branchOpts;
          if (sSel) sSel.innerHTML = branchOpts;
        }
      }`;

const problems = [];
const n = s.split(OLD).length - 1;
if (n !== 1) problems.push(`الكتلة المستهدفة: متوقّع ١ لقى ${n}`);
/* لازم يكون فيه فرعين تانيين بنفس الحارس — عشان نتأكد إننا بنمشي على نمط قايم */
if ((s.match(/if \(!window\._editState\?\.active\)/g) || []).length < 2)
  problems.push('نمط الحارس (sender/receiver) مش موجود — راجع بالإيد');
if (problems.length) {
  console.log('⛔ مافيش بايت اتكتب:');
  for (const p of problems) console.log('   ✗ ' + p);
  process.exit(1);
}

s = s.split(OLD).join(NEU);

/* ══ فحوص بعدية ══ */
const after = [];
if ((s.match(/if \(!window\._editState\?\.active\)/g) || []).length !== 3)
  after.push('عدد الحرّاس مش ٣ (sender + receiver + zone)');
/* ترتيب openEditZone لازم يفضل: setEditMode قبل openModal */
{
  const at = s.indexOf('window.openEditZone');
  const fn = s.slice(at, s.indexOf('function _fillBranchSelect', at));
  const iSet = fn.indexOf("setEditMode("), iOpen = fn.indexOf("openModal('zone')");
  if (!(iSet > -1 && iOpen > -1 && iSet < iOpen))
    after.push('setEditMode مش قبل openModal — الحارس مش هيشوف active=true');
}
{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, bad = 0;
  while ((m = re.exec(s)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
    const tmp = path.join(os.tmpdir(), 'zem-' + process.pid + '-' + i + '.mjs');
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
console.log('✓ اتكتب tiar.html — «تعديل منطقة» بقى بيحتفظ بالفرع المختار');
