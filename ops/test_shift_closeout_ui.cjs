/**
 * 🔒💰 حارس: واجهتا تقفيلة الوردية (الفرع والإدارة) — العهدة والعمولة.
 *
 * ═══ اللي بيغلط هنا ═══
 * ① المسار السريع (مافيش أوردرات → confirm → قفل) يرجع تاني: السيرفر
 *    هيرفض والعهدة مش صفر والمشرف قدامه خطأ من غير أي خانة يسدد منها.
 * ② خانة الردّ ماتتبعتش في الحمولة: المشرف يكتب المبلغ والسيرفر يرفض
 *    «العهدة مش صفر» — تجربة تخلّي الناس تلف حوالين الميزة.
 * ③ «قفل مع ترحيل» يظهر في الفرع: السيرفر هيرفضه أصلًا (إداري بس)،
 *    بس المشرف هيشوف خانة شغالة شكلًا ومتعطلة فعلًا.
 * ④ عمولة «تحديد يدوي» تتبعت لأوردر معلّم «لم يتم» — فلوس لأوردر
 *    مرجوع.
 *
 * التشغيل: node ops/test_shift_closeout_ui.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const BR = fs.readFileSync('public/branch.html', 'utf8');
const TR = fs.readFileSync('public/tiar.html', 'utf8');
const strip = t => t.replace(/\/\*[\s\S]*?\*\//g, '').replace(/<!--[\s\S]*?-->/g, '').replace(/^\s*\/\/[^\n]*$/gm, '');
const B = strip(BR), T = strip(TR);

for (const [name, S] of [['branch.html', B], ['tiar.html', T]]) {
  console.log('\n══ ' + name + ' ══');

  ok('🔴 المسار السريع اتشال — المودال بيفتح دايمًا',
    !/if \(!confirm\(`هل تريد إنهاء وردية \$\{pilot\.name\}؟`\)\) return;/.test(S),
    'confirm ثم قفل من غير خانة عهدة');
  ok('خانة ردّ العهدة موجودة', S.includes('id="_seCustodyReturn"'));
  ok('وبتتعبى بالمتوقع بعد التسوية',
    /const projected = Math\.max\(0, \(Number\(pilot\.custody\) \|\| 0\) \+ expected/.test(S)
    || /\(Number\(pilot\.custody\) \|\| 0\) \+ expected - collected\)/.test(S));
  ok('والكتابة اليدوية بتوقف التعبئة التلقائية',
    /_seCustodyEdited = true;/.test(S) && /if \(!_seCustodyEdited\) _seCustodyInput\.value/.test(S));
  ok('🔴 والردّ بيتبعت في الحمولة',
    /custodyReturn: custodyAmount > 0 \? \{ amount: custodyAmount, cashStoreId: /.test(S),
    'الخانة شكلية — مش بتوصل السيرفر');

  ok('اختيارات العمولة الأربعة',
    S.includes('id="_seCommMode"')
    && /value="keep"/.test(S) && /value="percent"/.test(S)
    && /value="fixed"/.test(S) && /value="custom"/.test(S));
  ok('والقايمة اليدوية متعبية بحساب الطيار الافتراضي',
    /pilotCommissionFor\(pilot, o\)\.toFixed\(2\)/.test(S));
  ok('🔴 والتحديد اليدوي للمتسلّم بس — «لم يتم» ماياخدش عمولة',
    /deliveredIds\.has\(inp\.getAttribute\("data-commorder"\)\)/.test(S),
    'عمولة بتتبعت لأوردر مرجوع');
  ok('والعمولة في الحمولة', /commission,/.test(S));
  ok('والنجاح بيقول إثبات الإخلاء', /custodyReturned/.test(S));
}

console.log('\n══ الفرق المقصود بين الاتنين ══');
ok('🔴 «قفل مع ترحيل» في الإدارة بس — قرار إداري',
  T.includes('id="_seCustodyCarry"') && !B.includes('id="_seCustodyCarry"'),
  'الفرع شايف خانة السيرفر هيرفضها');
ok('والإدارة بتبعت العلم الصريح', /allowCustodyCarry,/.test(T) && !/allowCustodyCarry/.test(B));
ok('والإدارة ليها خزنة ردّ مستقلة — التحصيل ممكن يكون «لم يتم»',
  T.includes('id="_seCustodyStore"'));
ok('والفرع بيستعمل خزنة التحصيل نفسها — درج واحد قدام المشرف',
  !B.includes('id="_seCustodyStore"')
  && /custodyAmount > 0 && !collectStoreId/.test(B));

console.log('\n══ السيرفر لسه متوافق ══');
const CTL = fs.readFileSync('app/Http/Controllers/Api/BoardController.php', 'utf8');
ok('custodyReturn بيتقري', CTL.includes("\$request->input('custodyReturn')"));
ok('والعلم إداري بس',
  /'admin' && \$request->boolean\('allowCustodyCarry'\)/.test(CTL));
ok('والعمولة بتتكتب override في السجل الموجود',
  /applyShiftCommission/.test(CTL) && CTL.includes("'override', \$amt, \$reason, \$effective"));

console.log('\n' + '─'.repeat(52));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — واجهتا التقفيلة بيثبتوا الإخلاء ويبعتوا العمولة\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
