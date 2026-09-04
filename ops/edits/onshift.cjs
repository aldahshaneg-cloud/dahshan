/* إصلاح انحدار: «مالوش فرع» مابقاش معناه «مش شغّال».
 *
 * ═══ إيه اللي حصل ═══
 * قبل تعديل 2026-08-30 كان `releasePilot` بيصفّر `assigned_branch_id` عند
 * قفل الوردية. فمسارين اتكلوا على ده كإشارة **ضمنية** إن الطيار خلص شغل:
 *
 *   • BoardController::pilotBackToWaitingIfFree — `if (! $branchId) return false;`
 *   • OrdersController::syncPilotStatus        — `if (... || ! $branchId) return;`
 *
 * الاتنين بيرجّعوا الطيار لـ`waiting` أول ما آخر أوردر جاري يتقفل. الحارس
 * الوحيد اللي كان بيمنع ده لطيار قافل ورديته هو «مالوش فرع».
 *
 * بعد ما بقى القفل بيرجّع الطيار لفرعه الثابت (مش بيمسح)، الحارس ده مات:
 * **أوردر متأخر بيتقفل بعد نهاية الوردية كان هيرمي الطيار في الدور من
 * غير ما حد يفتحله وردية** — يظهر متاح، وياخد أوردرات، وساعاته تتحسب غلط.
 *
 * ═══ الإصلاح ═══
 * الشرط الصح مش «عنده فرع» — هو «هو شغّال؟». و`pilots.status` هو الإشارة
 * الصريحة: `NULL` = مافيش وردية مفتوحة (releasePilot بتصفّرها). فبنفحصها
 * مباشرة بدل ما نستنتجها من الفرع.
 */
const fs = require('fs');

const JOBS = [
  ['app/Http/Controllers/Api/BoardController.php',
   `        $branchId = $pilot['assigned_branch_id'] !== null ? (int) $pilot['assigned_branch_id'] : null;
        if (! $branchId) {
            return false;
        }`,
   `        /* 🔴 الشرط ده كان \`if (! $branchId)\` وكان بيلعب دور «الطيار مش
           شغّال» — لأن الفرع كان بيتصفّر عند قفل الوردية. من 2026-08-30
           الفرع بيفضل (بيرجع للثابت)، فالسؤال الصح بقى على الحالة نفسها:
           \`status = NULL\` يعني مافيش وردية مفتوحة، والطيار **ممنوع**
           يترجّع للدور مهما اتقفل من أوردرات متأخرة. */
        if (($pilot['status'] ?? null) === null || $pilot['status'] === '') {
            return false;
        }
        $branchId = $pilot['assigned_branch_id'] !== null ? (int) $pilot['assigned_branch_id'] : null;
        if (! $branchId) {
            return false;
        }`],

  ['app/Http/Controllers/Api/OrdersController.php',
   `            if ($pilot['status'] === 'waiting' || ! $branchId) {
                return;
            }`,
   `            /* 🔴 \`! $branchId\` كان بيمنع الطيار القافل ورديته من إنه
               يترجّع للدور — لأن الفرع كان بيتصفّر ساعتها. الفرع بقى
               بيفضل من 2026-08-30، فالفحص بقى على الحالة: \`NULL\` = مافيش
               وردية، وأوردر متأخر بيتقفل مايرجّعهوش للشغل. */
            if ($pilot['status'] === 'waiting' || ($pilot['status'] ?? null) === null
                || $pilot['status'] === '' || ! $branchId) {
                return;
            }`],
];

let done = 0, bad = 0;
for (const [file, old, neu] of JOBS) {
  let s = fs.readFileSync(file, 'utf8');
  const eol = s.includes('\r\n') ? '\r\n' : '\n';
  if (eol === '\r\n') s = s.replace(/\r\n/g, '\n');
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log(`  🔴 ${file}: اتلقت ${n} مرة`); bad++; continue; }
  s = s.replace(old, neu);
  fs.writeFileSync(file, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
  console.log('  ✓ ' + file);
  done++;
}
console.log(bad ? `\n🔴 ${bad} مشكلة` : `\n✅ ${done} ملف`);
process.exit(bad ? 1 : 0);
