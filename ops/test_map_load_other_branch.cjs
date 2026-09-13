/**
 * 🗺️➡️🛵 حارس: تحميل أوردر على طيار فرع تاني من الخريطة.
 *
 * طلب صاحب النظام (2026-09-10): «بمجرد رؤية مدير الفرع الطيارين الآخرين
 * على الخريطة يكون هناك زرار خاص بهم يمكنه من خلاله تحميل أوردر على هذا
 * الطيار … والأوردر الذي يحمله عليه يذهب إلى الفرع التابع له هذا الطيار …
 * لأن في آخر اليوم سوف يتم محاسبة الطيار في الفرع التابع له، وبذلك قد
 * جعلنا كل الطيارين كأنهم أصبحوا طيار جوكر».
 *
 * 🔴 القاعدة اللي ممنوع تنكسر: **نقل الفرع جوه نفس الـUPDATE الذري** بتاع
 *    الحجز. لو اتعمل UPDATE تاني وراه، بتفضل لحظة يكون فيها الأوردر متحمّل
 *    على طيار فرع تاني وهو لسه مسجّل على فرعنا — وأي تقفيلة أو تحصيل بيقرا
 *    في اللحظة دي بيحسبه غلط.
 *
 * وde نفس قاعدة `transfer()` (قاعدة ٤، 2026-09-06) بالظبط بس على **أول
 * تحميل** مش على النقل — فالاتنين لازم يفضلوا متطابقين.
 *
 * ⚠️ `origin_branch_id` مابيتلمسش — ده تاريخ مين عمل الأوردر.
 *
 * المناورة الحقيقية (بتنفّذ claimCore على قاعدة حقيقية وبترجّع كل حاجة):
 *   php ops/drill_map_load.php
 *
 * التشغيل: node ops/test_map_load_other_branch.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

console.log('\n══ ① السيرفر — claimCore ══');
const O = fs.readFileSync('app/Http/Controllers/Api/OrdersController.php', 'utf8');
const claim = O.slice(O.indexOf('private static function claimCore'));
const body = claim.slice(0, claim.indexOf('\n    }\n'));

ok('🔴 الفرع جوه نفس الـUPDATE الذري (مش UPDATE تاني وراه)',
  /SET pilot_id = \?, pilot_name = \?, shift_id = \?, branch_id = \?,/.test(body),
  'لحظة الأوردر فيها متحمّل على فرع تاني وهو لسه مسجّل عندنا');
ok('وبيتبعت في مكانه الصح في الـbindings',
  /\[\$pilotId, \$pilot\['name'\], \$shiftId, \$toBranchId, \$now, \$now, \(int\) \$order\['id'\]\]/.test(body));
ok('🔴 فرع الطيار = فرع ورديته المفتوحة (هو ده فرعه النهارده — الجوكر بينزل أي فرع)',
  /if \(\$shiftId !== null\) \{[\s\S]{0,220}SELECT branch_id FROM shifts WHERE id = \?/.test(body));
ok('وإلا فرعه الحالي', /\} elseif \(\$pilot\['assigned_branch_id'\] !== null\) \{[\s\S]{0,80}\$toBranchId = \(int\) \$pilot\['assigned_branch_id'\];/.test(body));
ok('ولو مالوش لا دي ولا دي — الأوردر بيفضل مكانه',
  /\$toBranchId   = \$fromBranchId;/.test(body));
ok('⚠️ و origin_branch_id مااتلمسش (تاريخ مين عمل الأوردر)', !/origin_branch_id\s*=/.test(body));
ok('🗄️ والطيار المؤرشف مايتحملش عليه (كان ناقص في assign)',
  /\$pilot\['archived_at'\] \?\? null\) !== null/.test(body) && /الطيار مؤرشف/.test(body));
ok('والرد بيقول إن الأوردر خرج من لوحة الفرع',
  /'movedBranch'  => \$toBranchId !== \$fromBranchId,/.test(body));

console.log('\n══ ② نفس قاعدة transfer() — الاتنين لازم يفضلوا متطابقين ══');
const tr = O.slice(O.indexOf('public function transfer(Request'));
const trBody = tr.slice(0, tr.indexOf('public function transferBranch'));
ok('transfer بيقرا فرع الوردية الأول برضه', /SELECT branch_id FROM shifts WHERE id = \?/.test(trBody));
ok('وبيقع على assigned_branch_id برضه', /assigned_branch_id'\] !== null\) \{[\s\S]{0,80}toBranchId = \(int\) \$toPilot\['assigned_branch_id'\]/.test(trBody));

console.log('\n══ ③ السلك ══');
ok('assign بيمرّر movedBranch/from/to للواجهة',
  /'movedBranch'  => \$res\['movedBranch'\],/.test(O) && /'toBranchId'   => \$res\['toBranchId'\],/.test(O));
ok('و assign-bulk بيجمّعهم من كل أوردر',
  /if \(\$res\['movedBranch'\]\) \{[\s\S]{0,140}\$movedBranch = true;/.test(O));

console.log('\n══ ④ الخريطة في شاشة الفرع ══');
const B = fs.readFileSync('public/branch.html', 'utf8');
ok('🔴 الزرار بيظهر لطيار الفرع التاني بس',
  /if \(isOtherBranch\) \{[\s\S]{0,700}mapLoadOnOtherPilot/.test(B),
  'لو طلع لطيار فرعنا كمان بقى مسار تاني لنفس التحميل');
ok('ولما يكون متاح بس (في الانتظار أو بيوصّل — مش في إذن)',
  /const _canLoad = p\.pilotStatus === "waiting" \|\| p\.pilotStatus === "delivering";/.test(B)
  && /_canLoad \? `<button onclick="window\.mapLoadOnOtherPilot/.test(B),
  'السيرفر بيرفض ٤٠٩ والمشرف يبقى ضغط على الفاضي');
ok('والدالة موجودة على window (البالون HTML نصّي)', /window\.mapLoadOnOtherPilot = function \(pilotId\)/.test(B));
ok('وبتدوّر في روستر الشركة كله مش طيارين فرعنا',
  /window\._allPilotsData \|\| window\._pilotsData/.test(B.slice(B.indexOf('mapLoadOnOtherPilot = function'))));
ok('🔴 والمعروض للتحميل = اللي السيرفر بيقبله بالظبط',
  /!o\.pilotId && \(o\.status === "قيد التنفيذ" \|\| o\.status === "لم يتم التوصيل"\)/.test(B),
  'أي حالة تانية بترجع ٤٠٩');
ok('🔴 والتأكيد بيقول إن الأوردرات هتخرج من لوحة الفرع',
  /هتخرج من لوحة فرعك وتروح لـ/.test(B),
  'من غير كده المشرف يفتكر الأوردر ضاع ويعمله تاني');
ok('وبيقول اسم الفرع اللي رايحينه', /\$\{ esc\(bName\) \}<\/b>/.test(B));
ok('والتحميل بيعدّي على assign-bulk (كل أوردر بحجزه الذري)',
  /api\.post\("\/api\/orders\/assign-bulk", \{ orderIds: ids, pilotId: p\.id \}\)/.test(B));

/* ═══ ⑤ نقل الأوردر الجاري لأي طيار (طلب 2026-09-12) ═══
   «مشرف الفرع عايزين نفتحله صلاحية إنه يقدر ينقل أي أوردر على مندوب أثناء
   الأوردر وهو قيد التوصيل». السيرفر (transfer — قاعدة ٤) كان بيقبل طيار أي
   فرع من زمان وبينقل الأوردر لفرعه في نفس الـUPDATE؛ الناقص كان في المودال:
   القايمة كانت من `_pilotsData` (فرعنا بس). */
console.log('\n══ ⑤ مودال «نقل لطيار آخر» — طيارين الفروع التانية ══');
const tm = B.slice(B.indexOf('window.transferOrderPilot = async function'), B.indexOf('PILOTS PANEL (in orders page)'));
ok('🔴 المودال بيجيب طيارين الفروع التانية من روستر الشركة',
  /const otherBranchPilots = \(window\._allPilotsData \|\| \[\]\)/.test(tm),
  'القايمة كانت طيارين فرعنا بس');
ok('🔴 والمعروض = اللي السيرفر بيقبله (في الانتظار أو بيوصّل — مش في إذن ولا مؤرشف)',
  /\(p\.pilotStatus === "waiting" \|\| p\.pilotStatus === "delivering"\)/.test(tm)
  && /in_array\(\$toPilot\['status'\], \['waiting', 'delivering'\], true\)/.test(trBody),
  'طيار في القايمة والسيرفر يرفضه = رسالة خطأ للمشرف');
ok('ومش بيكرّر طيارين فرعنا ولا الطيار الحالي',
  /!_mine\.has\(p\.id\) && p\.id !== currentPilotId/.test(tm));
ok('🔴 وفي مجموعة لوحدها باسم الفرع — المشرف عارف إن الأوردر هيخرج من لوحته',
  /<optgroup label="🏢 طيارين فروع تانية — الأوردر هينتقل لفرعهم">/.test(tm)
  && /esc\(p\.assignedBranchName \|\| p\.homeBranchName \|\| "🃏 جوكر"\)/.test(tm));
ok('والتأكيد بيقول اسم الفرع اللي رايحله',
  /const _foreign = !window\._pilotsData\.some\(p => p\.id === newPilotId\);/.test(tm)
  && /الأوردر هيخرج من لوحة الفرع ده ويروح هناك/.test(tm));
ok('والاختيار بيتلاقى في allAvailable (فيها التلات مجموعات)',
  /const allAvailable     = \[\.\.\.waitingPilots, \.\.\.deliveringPilots, \.\.\.otherBranchPilots\];/.test(tm)
  && /const newPilot = allAvailable\.find\(p => p\.id === newPilotId\);/.test(tm));
ok('⚠️ والسيرفر بيحرس الأوردر على فرع المشرف — مش الطيار الهدف',
  /self::guardBranch\(\$actor, \$order\);/.test(O.slice(O.indexOf('public function transfer('), O.indexOf('public function transferBranch('))),
  'المشرف بينقل أوردرات فرعه بس، لأي طيار');
ok('والواجهة بتعرض توست نقل الفرع لما السيرفر يقول movedBranch',
  /if \(res\.movedBranch\) showToast\("الأوردر اتنقل مع الطيار لفرعه/.test(B));

console.log('\n' + '─'.repeat(52));
console.log(fail === 0 ? `✅ عدّى ${pass} فحص — الأوردر بيروح لفرع الطيار\n` : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
