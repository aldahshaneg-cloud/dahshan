/**
 * 📤 حارس: بلوك المُرسِل بيتطوي لسطر واحد في بوابة المحل.
 *
 * ═══ الطلب ═══
 * صاحب النظام 2026-08-31: «الجزء اللي في الأعلى يختفي ويكفي زرار لإظهاره
 * عند الحاجة، لكي يوفر مساحة».
 *
 * ═══ الأخطر في التعديل ده ═══
 * الطي بيخبّي حاجة، والحاجة دي فيها **منطقة الاستلام** — ومنها بيتحدّد
 * الفرع المسؤول عن الشحنة كلها. فالبنود ٣ و٤ بيحرسوا حالتين لازم يفضل
 * فيهم البلوك مفتوح بالعافية:
 *   ① مافيش منطقة مختارة → `saveOrder` هترفض، والطي بيخبّي سبب الرفض
 *   ② «الاستلام من مكان آخر» → المحل مش هو المُرسِل، والبيانات لازم تتكتب
 * المنظومة دي كانت موجودة قبل التعديل — والحارس ده عشان الطي الجديد
 * مايكسرهاش.
 *
 * ═══ فرق `_pickupOpen` ═══
 * معناها «**المحل** فتحه بإيده» مش «مفتوح دلوقتي». الفتح الإجباري بيتنده
 * بـ`byUser=false` عشان العلم مايترفعش — من غير الفرق ده البلوك بيفضل
 * مفتوح للأبد بعد أول فتح إجباري. البند ٥ بيحرسها.
 *
 * التشغيل: node ops/test_pickup_collapse.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const SRC = fs.readFileSync('public/store.html', 'utf8');

/* ══ 1) الهيكل ══ */
console.log('\n══ 1) الهيكل ══');
ok('هوية المحل ملفوفة في senderIdent', SRC.includes('<div id="senderIdent">'));
ok('واسم المحل والتليفون جواها',
   /<div id="senderIdent">[\s\S]{0,400}id="sender-name"[\s\S]{0,300}id="sender-phone"[\s\S]{0,200}<\/div>/.test(SRC));
ok('الملخّص بقى زرار واحد', SRC.includes('id="pickupSummaryBtn"'));
ok('والصف كله بيفتح مش زرار صغير جنبه',
   /<button type="button" id="pickupSummaryBtn" onclick="togglePickupDetails\(true\)"[\s\S]{0,200}width:100%/.test(SRC));
ok('السطر التاني اتشال (pickupSummarySub)', ! SRC.includes('pickupSummarySub'));

/* ══ 2) التبديل بيخفي كل حاجة ══ */
console.log('\n══ 2) التبديل ══');
const tog = /window\.togglePickupDetails = function \(open, byUser\)[\s\S]*?\n    \};/.exec(SRC)?.[0] || '';
ok('togglePickupDetails اتقصّت', tog.length > 0);
ok('بتخفي التفاصيل', /det\.style\.display = open \? "" : "none";/.test(tog));
ok('وبتظهر الملخّص مكانها', /sum\.style\.display = open \? "none" : "";/.test(tog));
ok('وبتخفي هوية المحل كمان', /ident\.style\.display = open \? "" : "none";/.test(tog));
ok('وبتخفي الأيقونة', /icon\.style\.display = open \? "" : "none";/.test(tog));
ok('🔴 وبتشيل حشو الكارت — من غير كده مافيش توفير حقيقي',
   /classList\.toggle\("collapsed", !open\)/.test(tog));
ok('.sender-bar.collapsed بتصفّر الحشو والإطار',
   /\.sender-bar\.collapsed \{[\s\S]{0,160}padding: 0;/.test(SRC));

/* ══ 3+4) الحالات اللي لازم يفضل مفتوح فيها ══ */
console.log('\n══ 3) البلوك بيفضل مفتوح لما الطي يضر ══');
const ref = /window\.refreshPickupSummary = function \(\)[\s\S]*?\n    \};/.exec(SRC)?.[0] || '';
ok('refreshPickupSummary اتقصّت', ref.length > 0);
ok('مافيش منطقة أو مكان آخر → فتح إجباري',
   /if \(!hasZone \|\| elsewhere\) \{[\s\S]{0,220}togglePickupDetails\(true, false\);/.test(ref));
ok('والفتح الإجباري بـbyUser=false — عشان العلم مايترفعش',
   /togglePickupDetails\(true, false\)/.test(ref));
ok('والمحل اللي فتحه بإيده مابنقفلش عليه',
   /if \(window\._pickupOpen\) return;/.test(ref));
ok('والطي بيحصل بـbyUser=false برضه',
   /togglePickupDetails\(false, false\);/.test(ref));

console.log('\n══ 4) سطر الملخّص ══');
ok('بيقول اسم المحل والمنطقة',
   /pickupSummaryLine"\)\.textContent = `الاستلام من \$\{shop\} — \$\{zoneName\}`;/.test(ref));
ok('🔴 وسعر المنطقة متشال منه — الاستلام مش بفلوس',
   /zoneRaw\.split\(" — "\)\[0\]/.test(ref), 'السطر هيقرا كأن الاستلام بمقابل');
ok('والتفاصيل اتنقلت لـtitle', /btn\.title = \(addr \|\| "من غير عنوان تفصيلي"\)/.test(ref));
ok('وبتقول إن الصف بيتضغط', /اضغط للتعديل/.test(ref));

/* ══ 5) العلم بيفرّق النيّة عن الحالة ══ */
console.log('\n══ 5) _pickupOpen ══');
ok('بيتكتب لما byUser مش false بس', /if \(byUser !== false\) window\._pickupOpen = !!open;/.test(tog));
ok('وبيتصفّر مع الفورم', /window\._pickupOpen = false;/.test(SRC));

/* ══ 6) تشغيل فعلي للمنطق المقصوص ══ */
console.log('\n══ 6) تشغيل togglePickupDetails المقصوصة ══');
{
  const el = () => ({ style: {}, classList: { toggle(c, on) { this._on = on; } } });
  const nodes = { pickupDetails: el(), pickupSummary: el(), senderIdent: el() };
  const bar = el(), icon = el();
  const window_ = { _pickupOpen: false };
  const document_ = {
    getElementById: id => nodes[id] || null,
    querySelector: sel => sel === '.sender-icon' ? icon : sel === '.sender-bar' ? bar : null,
  };
  const fn = new Function('window', 'document', 'return ' + tog.replace(/^window\.togglePickupDetails = /, ''))(window_, document_);

  fn(false, false);
  ok('طي: التفاصيل مخفية والملخّص ظاهر',
     nodes.pickupDetails.style.display === 'none' && nodes.pickupSummary.style.display === '');
  ok('طي: الهوية والأيقونة مخفيين',
     nodes.senderIdent.style.display === 'none' && icon.style.display === 'none');
  ok('طي: كلاس collapsed اتحط', bar.classList._on === true);
  ok('طي بـbyUser=false مابيرفعش العلم', window_._pickupOpen === false);

  fn(true, true);
  ok('فتح: كل حاجة رجعت',
     nodes.pickupDetails.style.display === '' && nodes.senderIdent.style.display === ''
     && icon.style.display === '' && bar.classList._on === false);
  ok('فتح بإيد المحل بيرفع العلم', window_._pickupOpen === true);
}

console.log('\n' + '─'.repeat(48));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — بلوك المُرسِل بيتطوي من غير ما يخبّي حاجة مهمة\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
