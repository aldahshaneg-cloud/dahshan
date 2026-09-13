/**
 * ⏸️ حارس: قفل الوردية بيقفل أي إذن لسه مفتوح.
 *
 * طلب صاحب النظام (2026-09-10): «اعمل إن قفل الوردية يقفل أي إذن مفتوح تلقائيًا».
 *
 * ═══ ليه ═══
 * الإذن بيتقفل يدوي (الطيار «عدت للعمل» أو الفرع ينهيه)، ولو محدش عمل كده
 * بيفضل `approved` بـ`ended_at` فاضي للأبد. وبعد إصلاح خصم وقت الإذن،
 * الإذن المفتوح بيتحسب **لحد آخر يومه التجاري** — فالطيار بيخسر باقي يومه
 * كله من ساعاته لأن الفرع نسي. اتشاف على الإنتاج: الطيار ١٦٨ يوم ١٠ طلع
 * **صفر ساعات** ومعاه وردية ١٠ ساعات.
 *
 * 🔴 `ended_at` = **وقت قفل الوردية** مش آخر اليوم — ده اللي بيخلّي
 *    الساعات تتحسب على المدى الحقيقي اللي كان بره الشغل.
 * 🔴 و`approved` بس: الـ`pending` لسه مااتوافقش عليه والـ`rejected` مرفوض.
 *
 * المناورة الحيّة: php ops/drill_close_leave.php
 * التشغيل: node ops/test_shift_end_closes_leave.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const B = fs.readFileSync('app/Http/Controllers/Api/BoardController.php', 'utf8');

console.log('\n══ ① الدالة ══');
ok('موجودة', /private function closeOpenLeaves\(int \$pilotId, string \$now, string \$by\): int/.test(B));
const fn = B.slice(B.indexOf('private function closeOpenLeaves'));
const body = fn.slice(0, fn.indexOf('\n    }\n'));
ok('🔴 وفيها WHERE (UPDATE بلا شرط بينهي أذونات الشركة كلها)',
  /WHERE pilot_id = \? AND status = 'approved' AND ended_at IS NULL/.test(body));
ok('ومقيّدة بالطيار ده وحده', /WHERE pilot_id = \?/.test(body));
ok('🔴 و«approved» بس — مش pending ولا rejected',
  /status = 'approved'/.test(body) && !/'pending'/.test(body) && !/'rejected'/.test(body));
ok('وبتمسك المفتوح بس (ended_at فاضي)', /ended_at IS NULL/.test(body));
ok('وبتكتب نفس عقد الإنهاء اليدوي', /SET status = 'ended', ended_at = \?, ended_by = \?/.test(body));
ok('وبترجّع العدد', /return DB::update\(/.test(body));

console.log('\n══ ② قفل الوردية بينديها ══');
const se = B.slice(B.indexOf('public function shiftEnd'));
const seBody = se.slice(0, se.indexOf('\n    /**', 10));
ok('🔴 shiftEnd بينده closeOpenLeaves',
  /\$leavesClosed = \$this->closeOpenLeaves\(\(int\) \$pilot\['id'\], \$now, \$actor->username\);/.test(seBody));
ok('🔴 و`$now` هو وقت قفل الوردية (مش آخر اليوم)',
  /\$now      = WireTime::nowDb\(\);[\s\S]*closeOpenLeaves\(\(int\) \$pilot\['id'\], \$now/.test(seBody));
ok('وقبل تحرير الطيار',
  /closeOpenLeaves[\s\S]{0,400}\$this->releasePilot\(\$pilot\);/.test(seBody));
ok('وجوه معاملة القفل نفسها (مايتقفلش إذن ووردية تفشل)',
  /DB::transaction\([\s\S]{0,200}\$row = DB::select\('SELECT \* FROM shifts WHERE id = \? FOR UPDATE'[\s\S]*closeOpenLeaves/.test(seBody));
ok('وبيبثّ عشان اللوحات وتطبيق الطيار يشوفوا',
  /if \(\$leavesClosed > 0\) \{[\s\S]{0,160}broadcastPilotRequest\('leave', \$branchId, \(int\) \$pilot\['id'\]\);/.test(seBody));

console.log('\n══ ③ المسار اليدوي زي ما هو ══');
ok('⚠️ إنهاء الإذن اليدوي لسه شغّال',
  /"UPDATE pilot_leave_requests SET status = 'ended', ended_at = \?, ended_by = \? WHERE id = \?"/.test(B));
ok('وحارس الضغطة بالغلط (أقل من دقيقة) لسه مكانه',
  /الإذن لسه بادئ من/.test(B));

console.log('\n' + '─'.repeat(52));
console.log(fail === 0 ? `✅ عدّى ${pass} فحص — الإذن المفتوح بيتقفل مع الوردية\n` : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
