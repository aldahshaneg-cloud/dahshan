/**
 * ☎️ حارس: قبول التلفون الأرضي جنب الموبايل.
 *
 * ═══ ليه الملف ده موجود ═══
 * فيه عملاء مالهمش غير تلفون أرضي، وكل فحوص الأرقام كانت موبايل-بس
 * (`^0?1[0-9]{9}$`) — فالمحل/العميل كان بيتزنق في «رقم هاتف غير صحيح»
 * ومفيش طريقة يكمّل. القاعدة بقت: موبايل مصري **أو** أرضي مصري بكود
 * المحافظة (يبدأ 02–09، ٩ أو ١٠ أرقام بصفر إجباري).
 *
 * الفحص وظيفي مش نصّي: بيسحب validPhone من customer.html وينفّذها
 * بأرقام حقيقية، وبيطبّق نفس الحالات على ريجيكسات السيرفر المسحوبة
 * من الكنترولر — فطفرة تكسر القبول أو توسّعه غلط بتقع هنا.
 *
 * ⚠️ contactMessage في PublicSiteController موبايل-بس **عن قصد**
 * (موثّق هناك) — مش من نطاق الحارس ده.
 *
 * التشغيل: node ops/test_landline_phone.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

/* 2026-09-09 (طلب صاحب النظام): «تأكد إن رقم الهاتف مقبول سواء 010 أو 011 أو 015 أو 012 أو 050 أو أي
   رقم» — القاعدة بقت: أي رقم من 8 لـ 15 رقم بعد شيل المسافات والشرط والأقواس و+. الحروف والقصير بس بيترفضوا. */
const CASES = [
  // [الرقم, المفروض يتقبل؟, الوصف]
  ['01012345678', true,  'موبايل 010'],
  ['01112345678', true,  'موبايل 011'],
  ['01212345678', true,  'موبايل 012'],
  ['01512345678', true,  'موبايل 015'],
  ['05012345678', true,  'يبدأ بـ 050 (11 رقم)'],
  ['1012345678',  true,  'موبايل من غير الصفر'],
  ['010 1234-5678', true, 'موبايل بمسافات وشُرط'],
  ['0212345678',  true,  'أرضي قاهرة (02 + 8)'],
  ['034567890',   true,  'أرضي إسكندرية (03 + 7)'],
  ['0502345678',  true,  'أرضي دقهلية (050 + 7)'],
  ['+201012345678', true, 'دولي بعلامة +'],
  ['00966501234567', true, 'دولي سعودي'],
  ['212345678',   true,  'أرضي من غير صفر — 9 أرقام مقبولة'],
  ['02123456',    true,  '8 أرقام — الحد الأدنى'],
  ['012345',      false, 'رقم قصير عشوائي'],
  ['1234567',     false, '7 أرقام — أقل من الحد'],
  ['0123456789012345', false, '16 رقم — أكتر من الحد'],
  ['05023456x8',  false, 'فيه حرف'],
  ['',            false, 'فاضي'],
];

function runCases(name, fn) {
  const bad = CASES.filter(([num, want]) => fn(num) !== want)
    .map(([num, want, why]) => `«${num}» (${why}) المفروض ${want ? 'يتقبل' : 'يترفض'}`);
  ok(`🔴 ${name}: كل حالات الموبايل والأرضي ماشية صح`, bad.length === 0, bad.join(' · '));
}

console.log('\n══ 1) تطبيق العملاء — customer.html (تنفيذ فعلي) ══');
const CUST = fs.readFileSync('public/customer.html', 'utf8');
const i = CUST.indexOf('function validPhone');
const j = CUST.indexOf('\n}', i);
ok('validPhone موجودة', i > 0 && j > i);
const validPhone = new Function('p', CUST.slice(i, j + 2) + '\nreturn validPhone(p);');
runCases('validPhone بتاعة الواجهة', p => validPhone(p));
ok('والـplaceholder بيقول للعميل إن الأرضي مقبول',
  CUST.includes('placeholder="01xxxxxxxxx أو أرضي"'));

console.log('\n══ 2) السيرفر — CustomerAppController (نفس القاعدة) ══');
const CAC = fs.readFileSync('app/Http/Controllers/Api/CustomerAppController.php', 'utf8');
const k = CAC.indexOf('private static function validPhone');
// قفلة الدالة هي `\n    }` (مزاحة ٤ مسافات) — أول `}` ساذجة بتقع جوه
// الريجيكس نفسه (`{9}`) وبتقص الفرع التاني
const fnPhp = k > 0 ? CAC.slice(k, CAC.indexOf('\n    }', k)) : '';
ok('validPhone السيرفرية موجودة', fnPhp.length > 0);
ok('🔴 القاعدة الواحدة: أي 8→15 رقم (مفيش فحص بادئة)', fnPhp.includes("/^[0-9]{8,15}$/") && !fnPhp.includes('01[0125]') && !fnPhp.includes('0?1[0-9]{9}'),
  'فحص البادئة كان بيزنق 050 والدولي في «رقم غير صحيح»');
// نفس ريجيكس السيرفر بيتجرب هنا بالحالات — صيغة PCRE دي متوافقة مع JS
runCases('ريجيكس السيرفر',
  p => { const d = String(p).replace(/[\s\-()+]/g, ''); return /^[0-9]{8,15}$/.test(d); });

console.log('\n══ 3) دفتر العملاء — لوحة الإدارة والسيرفر ══');
const CC = fs.readFileSync('app/Http/Controllers/Api/CustomersController.php', 'utf8');
ok('CustomersController بنفس القاعدة (8→15 رقم)', CC.includes("'/^[0-9]{8,15}$/'") && !CC.includes('0?1[0-9]{9}'));
const CUI = fs.readFileSync('public/customers.html', 'utf8');
ok('وواجهة دفتر العملاء بنفس القاعدة (وإلا الموظف مايعرفش يسجّل رقم الأوردر الواصل)',
  CUI.includes('/^\\d{8,15}$/') && !CUI.includes('0?1[0-9]{9}'));
console.log('\n══ 3ب) صفحة الدخول وصفحة التواصل والسيرفر العام ══');
const IDX = fs.readFileSync('public/index.html', 'utf8');
const CON = fs.readFileSync('public/contact.html', 'utf8');
const PUB = fs.readFileSync('app/Http/Controllers/Api/PublicSiteController.php', 'utf8');
ok('index.html وcontact.html من غير فحص بادئة', IDX.includes('/^\\d{8,15}$/') && !IDX.includes('0?1[0-9]{9}') && CON.includes('/^\\d{8,15}$/') && !CON.includes('01[0125]'));
ok('PublicSiteController::contactMessage من غير فحص بادئة', PUB.includes("/^[0-9]{8,15}$/") && !PUB.includes("preg_match('/^01[0125]"));

console.log('\n══ 4) بوابة المحلات ══');
const ST = fs.readFileSync('public/store.html', 'utf8');
ok('رقم المستلم مافيهوش فحص موبايل-بس (البوابة بتفحص الوجود بس — سلوك أصل)',
  !/0\?1\[0-9\]\{9\}/.test(ST));
ok('والـplaceholder بيقول إن الأرضي مقبول',
  ST.includes('placeholder="01xxxxxxxxx أو أرضي"'));

console.log('\n══ 5) نسخ التحديث الذاتي اتحركت مع التغيير ══');
const cVer = (CUST.match(/<meta name="app-version" content="([^"]+)" \/>/) || [])[1];
const sVer = (ST.match(/<meta name="app-version" content="([^"]+)" \/>/) || [])[1];
ok('نسخة تطبيق العملاء اتحركت من 1.6.2', !!cVer && cVer !== '1.6.2', cVer);
ok('ونسخة بوابة المحلات اتحركت من 1.0.1', !!sVer && sVer !== '1.0.1', sVer);

console.log('\n' + '─'.repeat(52));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — الأرضي بقى مقبول والموبايل زي ما هو\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
