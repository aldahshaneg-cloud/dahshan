/**
 * 🗂 حارس: التنقّل بين الطرود بالأزرار — بوابة المحلات وتطبيق العميل.
 *
 * ═══ الطلب ═══
 * صاحب النظام 2026-08-31: الطرود ماتبقاش تحت بعضها، وزرار لكل طرد (1·2·3)
 * في نفس الصف اللي فيه زر «طرد آخر»، عشان التنقّل من غير سكرول طويل.
 *
 * ═══ أخطر حاجة في التعديل ده ═══
 * إخفاء الطرود معناه إن **البيانات الناقصة بقت مش شايفها**. فالحماية
 * التلاتة دي لازم تفضل شغّالة مع بعض:
 *   ① الإرسال بينقل الشاشة للطرد الناقص قبل ما يقول الرسالة
 *   ② الرسالة بتقول الرقم **المعروض** مش الداخلي
 *   ③ الطرد الناقص عليه علامة على زراره
 * لو واحدة وقعت، المستخدم بيبقى قدام رسالة عن حاجة مش شايفها.
 *
 * ═══ الانحراف اللي الحارس ده موجود عشانه ═══
 * شرط «الطرد ناقص» متكرّر في مكانين: دالة العلامة، ودالة التحقق عند
 * الإرسال. لو حد زوّد حقل مطلوب في التحقق ونسي العلامة، الطرد يبقى ناقص
 * والزرار نضيف — وده أسوأ من مافيش علامة أصلًا. البند ٣ بيقارن الاتنين.
 *
 * ═══ فخ الخريطة (تطبيق العميل بس) ═══
 * كل بلوك مستلم جواه خريطة Leaflet مضمّنة. خريطة بتتبني جوه `display:none`
 * بتطلع بمقاس صفر وبتفضل مكسورة حتى بعد الإظهار. فالبناء لازم يبقى
 * للبلوك الظاهر بس. بوابة المحلات مالهاش المشكلة دي — الخريطة عندها
 * بتفتح في طبقة منفصلة.
 *
 * التشغيل: node ops/test_parcel_tabs.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const STORE = fs.readFileSync('public/store.html', 'utf8');
const CUST  = fs.readFileSync('public/customer.html', 'utf8');

/* ══════════ 1) بوابة المحلات — الهيكل ══════════ */
console.log('\n══ 1) بوابة المحلات ══');
ok('صف الأزرار موجود في الهيكل', STORE.includes('<div id="parcelTabs" class="parcel-tabs"></div>'));
ok('وقبل حاوية الطرود مش بعدها',
   STORE.indexOf('id="parcelTabs"') < STORE.indexOf('id="delivContainer"'));
ok('زر الإضافة المنفصل اللي تحت اتشال',
   !STORE.includes('<button class="add-btn" onclick="addRow()">'));
ok('زر الإضافة بقى جوه صف الأزرار',
   /class="ptab-add" onclick="addRow\(\)"/.test(STORE));
/* الدوال الداخلية معرّفة بـ`function`، والمصدَّرة بـ`window.X = function` */
['parcelRows', 'parcelIsIncomplete'].forEach(f =>
  ok('دالة ' + f, new RegExp('function ' + f + '\\s*\\(').test(STORE)));
['parcelDisplayNo', 'renderParcelTabs', 'showRow'].forEach(f =>
  ok('دالة ' + f + ' (مصدَّرة على window)',
     new RegExp('window\\.' + f + ' = function').test(STORE)));
/* كان بيثبّت `showRow` على إنها آخر سطر في الدالة، فأول سطر اتضاف بعدها
   كسره (حفظ المسوّدة 2026-08-31) وهو مظبوط. المقصود إنها بتتنده **بعد**
   ما الصف يتبني بالكامل — يعني بعد `bindTrustToRow`. */
ok('addRow بيفتح الطرد الجديد بعد ما يتبني بالكامل',
   /bindTrustToRow\(n\);[\s\S]*?window\.showRow\(n\);/.test(STORE));
ok('rmRow بيروح لجاره', /rows\[i \+ 1\] \|\| rows\[i - 1\]/.test(STORE));
ok('التصفير بيفضّي صف الأزرار وحالة التنقّل',
   /_pt\.innerHTML = "";[\s\S]{0,200}window\._activeRow\s*=\s*null/.test(STORE));
ok('زر الحذف مابقاش مربوط بـ n > 1 وقت الإنشاء',
   !/\$\{n > 1 \? .*rm-btn/.test(STORE));
ok('والحذف بيتخفي لو ده الطرد الوحيد',
   /rm\.style\.display = rows\.length > 1/.test(STORE));

/* ══════════ 2) بوابة المحلات — الحماية التلاتة ══════════ */
console.log('\n══ 2) الحماية الثلاثة (المحلات) ══');
ok('① الإرسال بينقل للطرد الناقص', /function _jumpToParcel\(n, fieldId, msg\)/.test(STORE));
ok('   والنقلة قبل الإبراز والتركيز',
   /_jumpToParcel[\s\S]{0,300}window\.showRow\?\.\(n\);[\s\S]{0,400}el\.focus\(\)/.test(STORE));
ok('   وفحص المنطقة بينقل كمان', /if \(!zoneId\) \{[\s\S]{0,300}window\.showRow\?\.\(n\)/.test(STORE));
/* المقصود رسايل التوست. التسمية اللي جوه قالب الكارت لسه بترقم داخليًا —
   وده مقصود: `renderParcelTabs` بتكتب فوقها بالرقم المعروض فورًا، والرقم
   الداخلي بيفضل احتياطي لو حصل حاجة ومانديتش. */
ok('② مافيش رسالة توست بالرقم الداخلي',
   !/toast\(`[^`]*طرد #\$\{n\}/.test(STORE) && !/_jumpToParcel\([^)]*طرد #\$\{n\}/.test(STORE),
   'لسه في رسالة بالرقم الداخلي');
ok('   والتسمية جوه الكارت بتتكتب من renderParcelTabs',
   /lbl\.textContent = "📦 الطرد #" \+ \(i \+ 1\)/.test(STORE));
/* الفحص كان بيثبّت العدد على ٣ بالظبط، فأول رسالة جديدة كسرته وهي
   مظبوطة (رسالة صورة الريسيت 2026-08-31). ولما وسّعته للملف كله لقط
   استعمالات صح في سياق تاني — قايمة الأوردرات المرسَلة، و`i` هناك أصلًا
   هو الرقم المعروض. فالمدى الصح هو **حلقة التحقق في فورم الإرسال** بس:
   دي اللي شغّالة بالأرقام الداخلية وبتكلّم المستخدم. */
{
  const loop = /const deliveries = \[\];[\s\S]*?\n      \}/.exec(STORE)?.[0] || '';
  ok('   حلقة التحقق اتقصّت', loop.length > 0);
  const withNo   = (loop.match(/طرد #\$\{[^}]*\}/g) || []);
  const withDisp = withNo.filter(x => x.includes('window.parcelDisplayNo(n)'));
  ok('   وكل رسالة فيها بترقم طرد بتستعمل parcelDisplayNo',
     withNo.length >= 3 && withDisp.length === withNo.length,
     `${withDisp.length} من ${withNo.length}` +
     (withNo.length > withDisp.length
       ? ' — الشاذة: ' + withNo.filter(x => !x.includes('parcelDisplayNo')).join(' · ')
       : ''));
}
ok('③ العلامات بتتفعّل عند أول إرسال', /window\._parcelChecked = true;/.test(STORE));
/* المعالج بقى بيعمل حاجتين (النقط + حفظ المسوّدة)، فالشكل المختصر
   `() => window.renderParcelTabs?.()` مابقاش موجود. المقصود إن الكتابة
   بتحدّث النقط — مش شكل المعالج. */
ok('   وبتتحدّث وانت بتكتب',
   /addEventListener\("input",\s*\(\) => \{[^}]*window\.renderParcelTabs\?\.\(\)/.test(STORE));

/* ══════════ 3) تطابق شرط «ناقص» مع التحقق (المحلات) ══════════ */
console.log('\n══ 3) العلامة والتحقق بيتكلموا على نفس الحقول (المحلات) ══');
const sInc = /function parcelIsIncomplete\(n\)[\s\S]*?\n    \}/.exec(STORE)?.[0] || '';
[['rName', 'اسم المستلِم'], ['rPhone', 'هاتف المستلِم'], ['rZone', 'المنطقة']].forEach(([f, lbl]) => {
  ok('العلامة بتفحص ' + lbl + ' (' + f + ')', sInc.includes('"' + f + '-"'));
});
// وأي حقل مطلوب زيادة في saveOrder لازم يبقى في العلامة كمان
const sSave = /window\.saveOrder = async function[\s\S]*?const deliveries = \[\];[\s\S]*?\n      \}/.exec(STORE)?.[0] || '';
const sReq  = [...sSave.matchAll(/_jumpToParcel\(n, `(r\w+)-/g)].map(m => m[1]);
const sZone = /if \(!zoneId\)/.test(sSave) ? ['rZone'] : [];
const sAll  = [...new Set([...sReq, ...sZone])];
ok('مافيش حقل مطلوب في الإرسال ومش في العلامة',
   sAll.every(f => sInc.includes('"' + f + '-"')),
   'المطلوب: ' + sAll.join(', '));

/* ══════════ 4) تطبيق العميل — الهيكل ══════════ */
console.log('\n══ 4) تطبيق العميل ══');
ok('صف الأزرار موجود', CUST.includes('<div id="rcvTabs" class="parcel-tabs"></div>'));
ok('وقبل حاوية البلوكات',
   CUST.indexOf('id="rcvTabs"') < CUST.indexOf('id="rcvBlocks"'));
ok('الزرارين القدام اتشالوا (addRcvBtn / addRcvBtnTop)',
   !CUST.includes('addRcvBtn'), 'لسه فيه مرجع');
['rcvCount', 'rcvActive', 'rcvIsIncomplete', 'renderRcvTabs', 'showRcv']
  .forEach(f => ok('دالة ' + f, new RegExp('function ' + f + '\\s*\\(').test(CUST)));
ok('addReceiver بيفتح الطرد الجديد',
   /S\.activeRcv = S\.draft\.receivers\.length - 1;/.test(CUST));
ok('addParcelSameReceiver بيفتح النسخة الجديدة', /S\.activeRcv = i \+ 1;/.test(CUST));
ok('removeReceiver بيروح لجاره',
   /S\.activeRcv = Math\.min\(i, S\.draft\.receivers\.length - 1\);/.test(CUST));
ok('renderReceiverBlocks بينده showRcv في الآخر', /showRcv\(rcvActive\(\)\);\n\}/.test(CUST));

/* ══════════ 5) فخ الخريطة (العميل) ══════════ */
console.log('\n══ 5) خريطة Leaflet مابتتبنيش جوه بلوك مخفي ══');
ok('البناء داخل renderReceiverBlocks متقيّد بالبلوك الظاهر',
   /if \(i === rcvActive\(\)\) showMiniMap\("rcvMap-" \+ i/.test(CUST));
ok('showRcv بتبني خريطة البلوك اللي بيتفتح',
   /function showRcv[\s\S]{0,600}showMiniMap\("rcvMap-" \+ act/.test(CUST));
ok('دخول خطوة ٢ بيبني الظاهر بس',
   /if \(step === 2\) setTimeout\(\(\) => \{[\s\S]{0,300}rcvActive\(\)/.test(CUST));
ok('نداء اختيار مستلم محفوظ مالوش شرط — البلوك ده ظاهر أصلًا',
   /onReceiverZone\(i\);\n      showMiniMap\("rcvMap-" \+ i/.test(CUST));

/* ══════════ 6) الحماية التلاتة (العميل) ══════════ */
console.log('\n══ 6) الحماية الثلاثة (العميل) ══');
ok('① التحقق بينقل للطرد الناقص',
   /const stop = \(i, msg\) => \{ showRcv\(i\); toast\(msg, "err"\); return true; \};/.test(CUST));
const cVal = /if \(S\.wizStep === 2\) \{[\s\S]*?\n    \}/.exec(CUST)?.[0] || '';
ok('   وكل فحص بيمرّ على stop مش toast مباشر',
   (cVal.match(/return stop\(i,/g) || []).length === 4 && !/return toast\("اختر منطقة التسليم/.test(cVal),
   String((cVal.match(/return stop\(i,/g) || []).length) + ' فحص');
ok('② الرسالة بتقول رقم الطرد المعروض', /const at = list\.length > 1 \? ` — الطرد \$\{i \+ 1\}`/.test(CUST));
ok('③ العلامات بتتفعّل عند أول محاولة', /S\.rcvChecked = true;/.test(CUST));

/* ══════════ 7) تطابق شرط «ناقص» مع التحقق (العميل) ══════════ */
console.log('\n══ 7) العلامة والتحقق بيتكلموا على نفس الحقول (العميل) ══');
const cInc = /function rcvIsIncomplete\(i\)[\s\S]*?\n\}/.exec(CUST)?.[0] || '';
[['rcvZone', 'المنطقة'], ['rcvName', 'الاسم'], ['rcvPhone', 'الهاتف'], ['rcvAddrW', 'العنوان']]
  .forEach(([f, lbl]) => ok('العلامة بتفحص ' + lbl, cInc.includes('"' + f + '-"')));
/* بقى لكل طرد لوحده من 2026-08-31 (شوف ops/test_receipt_per_parcel.cjs).
   الاتنين لازم يسألوا **الطرد** مش الطلب: لو واحد فيهم فضل بيسأل الطلب،
   طرد بصورة ريسيت جنب طرد عادي يبقى نصّه غلط. */
ok('وبتحترم علامة الريسيت للطرد ده زي التحقق بالظبط',
   /if \(\$\("rcvReceipt-" \+ i\)\?\.checked\) return false;/.test(cInc) &&
   /if \(!r\.receipt\) \{/.test(cVal));
ok('ومفيش مفتاح ريسيت عام في أي منهم',
   !/receiptMode/.test(cInc) && !/receiptMode/.test(cVal));
ok('وبتستعمل نفس فاحص الهاتف (validPhone)',
   cInc.includes('validPhone(') && cVal.includes('validPhone('));

/* ══════════ 8) تشغيل فعلي: قصّ rcvActive وتجريب التقصير ══════════ */
console.log('\n══ 8) تشغيل rcvActive المقصوصة ══');
const mAct = /function rcvActive\(\)[\s\S]*?\n\}/.exec(CUST)?.[0];
ok('rcvActive اتقصّت من الملف', !!mAct);
if (mAct) {
  let S = {};
  const rcvActive = new Function('S', 'const rcvCount = () => (S.draft?.receivers || []).length; return ' + mAct)(S);
  const at = (len, a) => { S.draft = { receivers: Array(len).fill(0) }; S.activeRcv = a; return rcvActive(); };
  ok('عادي: 3 طرود والمؤشر 1 → 1', at(3, 1) === 1);
  ok('مؤشر بره المصفوفة بيترجع للأخير', at(2, 5) === 1, String(at(2, 5)));
  ok('مؤشر سالب بيترجع للأخير', at(3, -1) === 2, String(at(3, -1)));
  ok('مفيش طرود → 0 من غير ما يقع', at(0, 4) === 0);
  ok('undefined بيبقى 0', at(3, undefined) === 0);
}

/* ══════════ 9) الستايل ══════════ */
console.log('\n══ 9) الستايل في الملفين ══');
[['store.html', STORE], ['customer.html', CUST]].forEach(([nm, src]) => {
  ok(nm + ': .parcel-tabs بتسكرول أفقيًا', /\.parcel-tabs \{[\s\S]{0,220}overflow-x: auto/.test(src));
  ok(nm + ': .ptab.active متميّز', /\.ptab\.active \{/.test(src));
  ok(nm + ': علامة الناقص', /\.ptab\.bad::after \{/.test(src));
  ok(nm + ': التمرير مابيحرّكش الصفحة رأسيًا', src.includes('inline: "center", block: "nearest"'));
});

console.log('\n' + '─'.repeat(50));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — التنقّل بالأزرار سليم في التطبيقين\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
