/* قفل الوردية يرجّع الطيار لفرعه الثابت — مش يمسح فرعه.
 *
 * ═══ ليه ده أبسط وأصح من تعديل الواجهات ═══
 * `assigned_branch_id` معناه «الفرع اللي الطيار عنده دلوقتي». لما الوردية
 * كانت بتتقفل الحقل كان بيتصفّر — يعني الطيار الفاضي بقى **بلا فرع**،
 * وده مش صحيح أصلًا: هو تابع لفرعه حتى وهو مش شغّال.
 *
 * الأثر اللي كان بيبان لصاحب النظام:
 *   • عمود «الفرع» في جدول الطيارين بيرجع «—» بعد أول قفل وردية.
 *   • مدير الفرع بيفقد طياريه الفاضيين من شاشته (الاستعلامات بتفلتر على
 *     الحقل ده من غير شرط حالة — سطر 90 و2525).
 *   • كل وردية جديدة لازم يختار الفرع من أول وجديد.
 *
 * دلوقتي بيرجع لـ`home_branch_id`. والطابور مش بيتأثر: كل استعلامات
 * الدور بتفلتر على `status = 'waiting'` كمان، والطيار الفاضي حالته NULL.
 *
 * وده كمان بيحقق «لو اتنقل لفرع تاني اليوم يرجع لفرعه تاني يوم» — بمجرد
 * ما وردية الدعم تتقفل، الطيار راجع لفرعه من غير أي تدخّل.
 */
const fs = require('fs');

const OLD = `            "UPDATE pilots SET status = NULL, assigned_branch_id = NULL, queue_no = NULL,
                status_since = NULL, break_started_at = NULL, leave_type = NULL,
                leave_reason = NULL, leave_forced = 0
          WHERE id = ?",
            [(int) $pilotRow['id']]`;

const NEW = `            /* 🔴 الفرع بيرجع للثابت مش بيتمسح — الطيار تابع لفرعه حتى
               وهو مش شغّال. لو مالوش فرع ثابت (بيانات قديمة) بيتصفّر زي
               الأول عشان مانخترعش له فرع. */
            "UPDATE pilots SET status = NULL, assigned_branch_id = home_branch_id, queue_no = NULL,
                status_since = NULL, break_started_at = NULL, leave_type = NULL,
                leave_reason = NULL, leave_forced = 0
          WHERE id = ?",
            [(int) $pilotRow['id']]`;

let done = 0, bad = 0;
for (const f of ['app/Http/Controllers/Api/BoardController.php',
                 'app/Http/Controllers/Api/PilotAppController.php']) {
  let s = fs.readFileSync(f, 'utf8');
  const eol = s.includes('\r\n') ? '\r\n' : '\n';
  if (eol === '\r\n') s = s.replace(/\r\n/g, '\n');
  const n = s.split(OLD).length - 1;
  if (n !== 1) { console.log(`  🔴 ${f}: اتلقت ${n} مرة`); bad++; continue; }
  s = s.replace(OLD, NEW);
  fs.writeFileSync(f, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
  console.log('  ✓ ' + f);
  done++;
}
console.log(bad ? `\n🔴 ${bad} مشكلة` : `\n✅ ${done} ملف`);
process.exit(bad ? 1 : 0);
