/**
 * 💰 حارس: العهدة المسبقة — أول مدخل فلوس للخزن.
 *
 * ═══ الفجوة اللي اتسدّت (2026-09-01) ═══
 * صاحب النظام: «بالنسبة للخزن لازم نعمل إمكانية للإدارة إنها تضع بها
 * عهدة مسبقة».
 *
 * كل خزنة بتتخلق برصيد **صفر** (`cashStoresCreate` بيعمل INSERT بـ
 * `balance = 0` صراحةً)، وشاشة الخزن في لوحة الإدارة كان فيها تلات أفعال
 * بس: تعديل · حذف · تحويل بين الخزن.
 *
 * والتحويل **دايري**: كل الخزن أصفار، فمافيش خزنة فيها حاجة تتحوّل منها.
 * يعني منظومة الخزن كانت مقفولة على الصفر — أول جنيه مالوش طريق يدخل.
 *
 * ═══ الآلية كانت موجودة والشاشة هي الناقصة ═══
 * `POST /api/cash-stores/{id}/transactions` بـ`type:"in"` موجود من الأصل
 * وأدواره `admin,branch,accountant`، ومشرف الفرع بينده عليه من
 * `branch.html`. فمافيش مسار جديد ولا عمود — الشاشة بس.
 *
 * ═══ الحاجات اللي بتغلط في شاشة فلوس ═══
 * ① دوسة تانية وقت التحميل بتسجّل الحركة مرتين والفلوس بتتضاعف.
 * ② مبلغ صفر أو سالب بيمرّ ويعمل حركة بلا معنى في السجل.
 * ③ حركة بلا سبب في سجل فلوس بتبقى سؤال محدش بيعرف يجاوبه بعد شهر.
 *
 * التشغيل: node ops/test_treasury_deposit.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const UI  = fs.readFileSync('public/tiar.html', 'utf8');
const PHP = fs.readFileSync('app/Http/Controllers/Api/FinanceController.php', 'utf8');
const RT  = fs.readFileSync('routes/api.php', 'utf8');

/* ══ 1) الشاشة ══ */
console.log('\n══ 1) الشاشة ══');
ok('زرار العهدة في صف كل خزنة', /onclick="openDepositModal\('\$\{ escJs\(s\.id\) \}'\)"/.test(UI));
ok('وجنب زراري التعديل والحذف',
   /openDepositModal[\s\S]{0,260}openStoreModal[\s\S]{0,260}deleteStore/.test(UI));
ok('المودال موجود', /<div id="modal-deposit" class="modal-overlay"/.test(UI));
['depStoreId', 'depStoreName', 'depBal', 'depAmount', 'depReason', 'depNotes', 'submitDepositBtn']
  .forEach(id => ok('حقل ' + id, UI.includes('id="' + id + '"')));
ok('والمبلغ رقمي بحد أدنى', /id="depAmount" type="number" step="0\.01" min="0\.01" required/.test(UI));

/* ══ 2) الفتح ══ */
console.log('\n══ 2) فتح المودال ══');
/* القصّ بمواضع النص مش بregex: صياغة `function(id)` بمسافة أو من غيرها
   كسرت الregex مرتين، والفحوص كلها وقعت وهي صح. */
const cut = (start) => {
  const i = UI.indexOf(start);
  if (i < 0) return '';
  const j = UI.indexOf('\n    };', i);
  return j < 0 ? '' : UI.slice(i, j);
};
const open = cut('window.openDepositModal = function');
ok('openDepositModal اتقصّت', open.length > 0);
ok('بيرفض لو الخزنة مش موجودة', /if \(!st\) \{[\s\S]{0,120}return; \}/.test(open));
ok('وبيعرض اسم الخزنة ورصيدها الحالي',
   /depStoreName"\)\.textContent = st\.name/.test(open) && /depBal"\)\.textContent =/.test(open));
ok('وبيفضّي المبلغ — مافيش رقم متساب من مرة فاتت',
   /depAmount"\)\.value = "";/.test(open));
ok('🔴 والسبب متعبّى «عهدة مسبقة» — حركة بلا سبب بتبقى لغز بعد شهر',
   /depReason"\)\.value = "عهدة مسبقة";/.test(open));

/* ══ 3) الإرسال ══ */
console.log('\n══ 3) الإرسال ══');
const sub = cut('window.submitDeposit = async function');
ok('submitDeposit اتقصّت', sub.length > 0);
ok('🔴 بيرفض المبلغ الفاضي أو الصفر أو السالب',
   /if \(!\(amount > 0\)\) \{[\s\S]{0,90}return; \}/.test(sub));
ok('🔴 وبيرفض السبب الفاضي', /if \(!reason\) \{[\s\S]{0,80}return; \}/.test(sub));
ok('وبيرفض لو الخزنة مش محدّدة', /if \(!id\) \{[\s\S]{0,90}return; \}/.test(sub));
ok('🔴 والزرار بيتقفل قبل النداء — دوسة تانية بتضاعف الفلوس',
   (() => {
     const iLock = sub.indexOf('btn.disabled = true;');
     const iPost = sub.indexOf('api.post(');
     return iLock > 0 && iPost > iLock;
   })(), 'الزرار مفتوح وقت النداء');
ok('🔴 وبيترجع في finally — مش في الـtry لوحده',
   /finally \{ btn\.disabled = false; \}/.test(sub), 'الزرار هيفضل عالق لو النداء رمى');
ok('بينده المسار الموجود مش مسار جديد',
   /api\.post\(`\/api\/cash-stores\/\$\{encodeURIComponent\(id\)\}\/transactions`/.test(sub));
ok('ورقم الخزنة بيتهرّب في المسار', /encodeURIComponent\(id\)/.test(sub));
ok('والنوع «وارد»', /type: "in", amount, reason, notes/.test(sub));
ok('وبيقفل المودال ويحدّث الشاشة بعد النجاح',
   /closeModal\("deposit"\);[\s\S]{0,140}await refreshStores\(\);/.test(sub));

/* ══ 4) السيرفر — مافيش حاجة اتغيّرت ══ */
console.log('\n══ 4) السيرفر ══');
ok('المسار موجود من الأصل',
   /Route::post\('cash-stores\/\{id\}\/transactions'/.test(RT));
ok('وأدواره زي ما هي — مافيش توسيع صلاحية',
   /Route::post\('cash-stores\/\{id\}\/transactions'[\s\S]{0,160}role:admin,branch,accountant/.test(RT));
ok('والنوع «in» مقبول من الأصل',
   /in_array\(\$type, \['in', 'out', 'pending'\], true\)/.test(PHP));
ok('🔴 والرصيد بيتحدّث على السيرفر بقفل صف — مش من الواجهة',
   /\$store = \$this->lockStore\(\$storeId\);/.test(PHP));
ok('والحركة بتتسجّل بسببها ومنشئها',
   /INSERT INTO cash_transactions[\s\S]{0,220}created_by/.test(PHP));

console.log('\n' + str(50));
function str(n) { return '─'.repeat(n); }
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — الإدارة تقدر تحطّ عهدة في الخزنة\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
