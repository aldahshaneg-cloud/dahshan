/**
 * 💰 حارس: الأوردرات غير المسوّاة بتمشي مع الطيار لما ورديته تتنقل لفرع تاني.
 *
 * ═══ البلاغ (صاحب النظام 2026-09-10) ═══
 * «محمود عبد الحميد من فرع المدير اتنقل لحي شرق، وبعدين رجع تاني بعد ما
 *  دعم الفرع. الأوردر خرج من عند الفرع التاني وطار به الطيار وحساب الأوردر
 *  بقى معاه، ولما يتحاسب مع الفرع التابع له هيدّي الأوردر له — فلو مش موجود
 *  عنده هيكون أوفر مع الفرع وعجز على الفرع التاني».
 *
 * وهو صح. السبب مفتاحين مختلفين لنفس الأوردر:
 *   • `settlePilotMoney` بتلمّ اللي هتطلبه بـ**`orders.pilot_id`**.
 *   • والكاش بيتختم بـ**`shifts.branch_id`**.
 * و`shiftTransfer` كانت بتحرّك الوردية **بس**، فالأوردرات تفضل ورا.
 *
 * 🔴 القاعدة: المجموعة اللي بتتحرك لازم تفضل **نفس** مجموعة التقفيلة
 *    بالظبط. أي فرق بينهم بيرجع نفس الأوفر/العجز من باب تاني.
 *
 * ⚠️ المسوّى خلاص (`money_settled = 1`) مابيتحركش — فلوسه دخلت خزنة فرعه.
 * ⚠️ `origin_branch_id` مابيتلمسش.
 *
 * المناورة الحقيقية: php ops/drill_shift_transfer_money.php
 * التشغيل: node ops/test_shift_transfer_money.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const B = fs.readFileSync('app/Http/Controllers/Api/BoardController.php', 'utf8');

console.log('\n══ ① الدالة المشتركة ══');
ok('موجودة', /private function moveUnsettledOrdersToBranch\(int \$pilotId, int \$toBranch\): int/.test(B));
const fn = B.slice(B.indexOf('private function moveUnsettledOrdersToBranch'));
const fnBody = fn.slice(0, fn.indexOf('\n    }\n'));
ok('🔴 وفيها WHERE (UPDATE بلا شرط بيدوس على أوردرات الشركة كلها)',
  /UPDATE orders SET branch_id = \?\s*\n\s*WHERE pilot_id = \?/.test(fnBody));
ok('ومقيّدة بالطيار ده وحده', /WHERE pilot_id = \?/.test(fnBody));
ok('وبتتخطّى لو هو الفرع نفسه', /AND branch_id <> \?/.test(fnBody));
ok('🔴 اللي في إيده بيتحرك', /status IN \('processing','delivering','postponed'\)/.test(fnBody));
ok('🔴 والمسلَّم اللي لسه مش مسوّى كمان (دي اللي كانت ناقصة)',
  /\(status = 'delivered'   AND money_settled = 0\)/.test(fnBody),
  'الأوردر اتسلّم وفلوسه لسه مع الطيار — دي بالظبط حالة البلاغ');
ok('والمرتجع المدفوع توصيله وغير المسوّى',
  /status = 'undelivered' AND money_settled = 0[\s\S]{0,80}undelivered_fare_by IN \('receiver','sender'\)/.test(fnBody));
ok('⚠️ والمسوّى خلاص مابيتحركش', /money_settled = 0/.test(fnBody) && !/money_settled = 1/.test(fnBody));
ok('⚠️ و origin_branch_id مااتلمسش', !/origin_branch_id/.test(fnBody));
ok('والـbindings بترتيبها', /\[\$toBranch, \$pilotId, \$toBranch\]/.test(fnBody));

console.log('\n══ ② نفس مجموعة settlePilotMoney بالظبط ══');
const st = B.slice(B.indexOf('private function settlePilotMoney'));
const stBody = st.slice(0, st.indexOf('\n    private function ', 10));
ok('التقفيلة بتطلب الجارية', /status = 'delivering'/.test(stBody));
ok('والمسلَّمة غير المسوّاة', /status = 'delivered' AND money_settled = 0/.test(stBody));
ok('والمرتجعة المدفوع توصيلها', /undelivered_fare_by IN \('receiver','sender'\) AND money_settled = 0/.test(stBody));
ok('🔴 وبتلمّها بـ pilot_id (ده سبب المشكلة الأصلي — عشان كده الحركة لازمة)',
  /WHERE pilot_id = \? AND status/.test(stBody));

console.log('\n══ ③ المسارين بينادوا الدالة ══');
ok('🔴 shiftTransfer بيحرّك الأوردرات مع الوردية',
  /INSERT INTO shift_branch_history[\s\S]{0,400}\$this->moveUnsettledOrdersToBranch\(\(int\) \$shift\['pilot_id'\], \$toBranchId\);/.test(B),
  'ده الباج اللي البلاغ عنه');
ok('و completePilotTransfer بيستخدم نفس الدالة',
  /\$this->moveUnsettledOrdersToBranch\(\$pilotId, \$toBranch\);/.test(B));
ok('🔴 ومفيش استعلام ناقص فاضل (كان بيسيب المسلَّم غير المسوّى ورا)',
  !/UPDATE orders SET branch_id = \?\s*\n\s*WHERE pilot_id = \? AND status IN \('processing','delivering','postponed'\)"/.test(B));
ok('والحركة جوه معاملة نقل الوردية نفسها',
  /DB::transaction\(function \(\) use \(\$actor, \$shiftId, \$toBranchId\)[\s\S]{0,1400}moveUnsettledOrdersToBranch/.test(B));

console.log('\n' + '─'.repeat(52));
console.log(fail === 0 ? `✅ عدّى ${pass} فحص — الدفتر والكاش بيقعوا في نفس الفرع\n` : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
