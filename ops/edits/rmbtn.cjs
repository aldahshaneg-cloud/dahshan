/* شيل كل زرار في callcenter.html بيوصل لمسار السيرفر بيرفضه من دور
 * الكول سنتر — سواء بينده الـAPI مباشرة أو بيفتح مودال تأكيده ممنوع.
 *
 * قرار صاحب النظام 2026-08-30. السبب: الزرار ده مالوش أي وظيفة غير إنه
 * يطلّع للموظف «خطأ: ...» ويلخبطه. العرض بيفضل زي ما هو — الموظف يشوف
 * إن فيه طلب إذن أو إرجاع، بس القرار مش شغله.
 *
 * ⚠️ بنشيل الزرار بس — مش الكارت ولا الجدول ولا الصفحة.
 */
const fs = require('fs');
const f = 'public/callcenter.html';
let s = fs.readFileSync(f, 'utf8');

const KILL = [
  /* إجراءات على الطيارين والورديات */
  'adminOpenShiftDirect', 'openOpenShiftModal', 'confirmOpenShiftDirect', 'addPilotToPanel',
  'endPilotLeaveAdmin', 'forcePilotLeave', 'completePilotDelivery',
  'approveShiftRequest', 'rejectShiftRequest',
  'approveLeaveRequest', 'rejectLeaveRequest',
  'approveReturnRequest', 'rejectReturnRequest',
  /* الانضمام والنقل */
  'approveJoinRequest', 'confirmApproveJoinRequest', 'rejectJoinRequest',
  'approveAdminTransfer', 'rejectAdminTransfer', 'executeDirectMove',
  /* تقفيلات وتسويات */
  'applyShiftBonusDeduction', 'openMonthlyCloseout', 'saveMonthlyCloseout',
  /* حذف على صفحات إدارية */
  'deletePilot', 'deleteUser', 'deleteExpense', 'deleteManualEmployee',
];

/* ── شيل عنصر <button> اللي جواه النداء ده ──
   ⚠️ مش كل onclick بيكون على <button>: `completePilotDelivery` مثلًا
   بيتحط على `<div class="pilot-card">` كلها. لو مشينا بـlastIndexOf
   على طول بنمسك زرار سابق **مقفول** ونمسح من عنده — وده كسر القالب
   فعليًا أول مرة جرّبت. الحارس تحت بيتأكد إن الفتحة لسه مفتوحة عند
   النداء (يعني مفيش `</button>` بينهم). */
function removeButtons(src, fn) {
  let removed = 0, skipped = 0;
  const needle = 'onclick="' + fn + '(';
  let from = 0;
  for (;;) {
    const at = src.indexOf(needle, from);
    if (at < 0) break;
    const open = src.lastIndexOf('<button', at);
    const closeBefore = src.lastIndexOf('</button>', at);
    if (open < 0 || closeBefore > open) {        // النداء مش جوه زرار
      skipped++; from = at + needle.length; continue;
    }
    const close = src.indexOf('</button>', at);
    if (close < 0) { console.log('  🔴 ' + fn + ': مالقيتش </button>'); process.exit(1); }
    if (src.slice(open + 7, at).includes('<button')) { console.log('  🔴 ' + fn + ': حدود متداخلة'); process.exit(1); }
    let a = open, b = close + 9;
    /* المسافات اللي قبله على نفس السطر */
    while (a > 0 && (src[a - 1] === ' ' || src[a - 1] === '\t')) a--;
    if (src[a - 1] === '\n' && src[b] === '\n') b++;      // السطر كله كان للزرار
    src = src.slice(0, a) + src.slice(b);
    removed++;
  }
  return { src, removed, skipped };
}

/* ── نداء على عنصر مش زرار: بنشيل السمة `onclick` بس ──
   الكارت نفسه لازم يفضل معروض (الموظف بيشوف الطيارين)، بس مايبقاش
   قابل للدوس. وبنشيل معاها التلميح اللي بيقول «اضغط عند العودة». */
function stripHandler(src, fn) {
  let n = 0;
  src = src.replace(new RegExp('\\s*onclick="' + fn + '\\([^"]*"', 'g'), () => { n++; return ''; });
  return { src, n };
}

let total = 0, stripped = 0;
for (const fn of KILL) {
  const r = removeButtons(s, fn);
  s = r.src;
  if (r.removed) { console.log('  ✓ ' + fn.padEnd(28) + r.removed + ' زرار'); total += r.removed; }
  if (r.skipped) {
    const h = stripHandler(s, fn);
    s = h.src; stripped += h.n;
    console.log('  ✓ ' + fn.padEnd(28) + h.n + ' نداء على عنصر مش زرار — اتشال الـonclick بس');
  }
}
/* التلميح ده كان بيقول للموظف يدوس على كارت الطيار — بقى مضلّل */
s = s.replace(/\s*<div class="pilot-card-hint">اضغط عند العودة<\/div>/g, '');

/* ── نضّف الحاويات اللي فضلت فاضية ── */
const EMPTY = [
  /<div style="display:flex;gap:6px;margin-top:10px">\s*<\/div>/g,
  /<div style="margin-top:10px">\s*<\/div>/g,
  /<div class="form-actions">\s*<\/div>/g,
];
let cleaned = 0;
for (const re of EMPTY) s = s.replace(re, m => { cleaned++; return ''; });

/* ── وشرط ternary بقى بيرجّع فاضي على الجهتين ── */
const before = s.length;
s = s.replace(/\$\{isPending \? `\s*` : ``\}/g, '');
s = s.replace(/\$\{isPending \? `\s*` : \(r\.status === "approved" \? `\s*` : ``\)\}/g, '');

fs.writeFileSync(f, s);
console.log('\n✓ ' + total + ' زرار اتشال · ' + cleaned + ' حاوية فاضية اتنضّفت'
  + (before !== s.length ? ' · وشروط فاضية اتشالت' : ''));
