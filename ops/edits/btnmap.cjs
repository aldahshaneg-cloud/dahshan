/* خريطة الأزرار المكسورة: كل موقع onclick، السطر، والسياق اللي بيرسمه. */
const fs = require('fs');
const L = fs.readFileSync('public/callcenter.html', 'utf8').split('\n');

const FNS = ['adminOpenShiftDirect', 'endPilotLeaveAdmin', 'forcePilotLeave', 'completePilotDelivery',
  'approveShiftRequest', 'rejectShiftRequest', 'approveLeaveRequest', 'rejectLeaveRequest',
  'approveReturnRequest', 'rejectReturnRequest', 'applyShiftBonusDeduction', 'saveMonthlyCloseout',
  'approveAdminTransfer', 'rejectAdminTransfer', 'executeDirectMove', 'rejectJoinRequest',
  'addPilotToPanel', 'confirmOpenShiftDirect', 'confirmApproveJoinRequest'];

/* الدالة اللي السطر ده جواها */
const DEFS = [];
L.forEach((l, i) => {
  const m = l.match(/^\s*(?:window\.([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?function|(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\()/);
  if (m) DEFS.push({ line: i + 1, name: m[1] || m[2] });
});
const owner = ln => { let o = null; for (const d of DEFS) { if (d.line <= ln) o = d; else break; } return o?.name || '(HTML ثابت)'; };

let total = 0;
console.log('السطر   الدالة اللي بتنده          السياق اللي بيرسمه');
console.log('─'.repeat(78));
for (const fn of FNS) {
  L.forEach((l, i) => {
    if (!l.includes('onclick="' + fn + '(')) return;
    total++;
    const label = (l.match(/>([^<>]{2,40})<\/button>/) || [, l.trim().slice(0, 34)])[1].trim();
    console.log(String(i + 1).padEnd(7) + fn.padEnd(27) + owner(i + 1));
    console.log('       └─ « ' + label + ' »');
  });
}
console.log('─'.repeat(78));
console.log('الإجمالي: ' + total + ' زرار');
