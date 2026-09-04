/**
 * 🧾 حارس: «مش معايا بيانات المستلم» لكل طرد لوحده.
 *
 * ═══ الطلب ═══
 * صاحب النظام 2026-08-31: «طرد من الطرود به بيانات مستلم وطرد آخر ليس به
 * بيانات مستلم فأقوم بتصوير الريسيت» — يعني المفتاح جوه كل طرد مش على
 * الطلب كله.
 *
 * ═══ ليه ده كان واجهة بس ═══
 * المنظومة تحت كانت **لكل طرد** من الأصل: الحمولة بتبعت `fromReceipt` جوه
 * كل طرد، والسيرفر بيقراها جوه حلقة الطرود ويخزّنها في العمود
 * `order_deliveries.receiver_from_receipt`، والسلك بيطلّعها
 * `receiverFromReceipt` لكل طرد، ولوحة الفرع بتعرض الشارة على الطرد المعني
 * بس. الواجهة بس هي اللي كانت بتفرض مفتاح واحد على الطلب.
 * فالبند ⑤ تحت بيحرس التوصيلة دي: لو حد رجّع الحمولة تبعت قيمة واحدة
 * للطلب كله، الطرود هتتخزّن كلها بنفس الحالة والفرع هيشوف شارة غلط.
 *
 * ═══ أخطر انحراف ═══
 * إن التحقق يفضل يسأل الطرد اللي بصورة ريسيت عن اسم وتليفون وعنوان —
 * ساعتها الوضع ده بيفشل ١٠٠٪ من المرات لأن الحقول مخفية أصلًا ومفيش مخرج.
 * ده حصل فعلًا قبل كده على السيرفر (مكتوب في تعليق CustomerAppController).
 *
 * التشغيل: node ops/test_receipt_per_parcel.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const SRC = fs.readFileSync('public/customer.html', 'utf8');
const PHP = fs.readFileSync('app/Http/Controllers/Api/CustomerAppController.php', 'utf8');
const WIRE = fs.readFileSync('app/Wire/OrderWire.php', 'utf8');

/* ══ 1) المفتاح العام اتشال بالكامل ══ */
console.log('\n══ 1) مافيش مفتاح عام فاضل ══');
['receiptMode', 'rcvNoData', 'rcvReceiptNote'].forEach(k =>
  ok('مافيش أي ذكر لـ' + k, !SRC.includes(k),
     String((SRC.match(new RegExp(k, 'g')) || []).length) + ' ذكر'));

/* ══ 2) المفتاح بقى جوه كل طرد ══ */
console.log('\n══ 2) المفتاح جوه البلوك ══');
ok('الشيك بوكس مرقّم بالطرد', SRC.includes('id="rcvReceipt-${i}"'));
ok('وجوه قالب البلوك مش في رأس الخطوة',
   /function receiverBlockHtml[\s\S]{0,2000}rcvReceipt-\$\{i\}/.test(SRC));
ok('وبينده onReceiverMode برقم الطرد', SRC.includes('onchange="onReceiverMode(${i})"'));
ok('onReceiverMode بتاخد رقم الطرد', /function onReceiverMode\(i\)/.test(SRC));
ok('وبتكتب الحالة على الطرد ده بس', /r\.receipt = !!\$\("rcvReceipt-" \+ i\)\?\.checked;/.test(SRC));
ok('وبتفضل على نفس الطرد بعد إعادة الرسم', /function onReceiverMode\(i\)[\s\S]{0,400}S\.activeRcv = i;/.test(SRC));
ok('blankReceiver بيبدأ بـreceipt:false', /blankReceiver = \(\) => \([\s\S]{0,300}receipt:false/.test(SRC));
ok('readReceiverBlocks بتقرا المفتاح', /r\.receipt = !!\$\("rcvReceipt-" \+ i\)\?\.checked;\n  \}\);/.test(SRC));
ok('كل بلوك بياخد حالته هو عند الرسم',
   /receiverBlockHtml\(r, i, multi, !!r\.receipt\)/.test(SRC));

/* ══ 3) الحقول بتتخفي للطرد المعني بس ══ */
console.log('\n══ 3) إخفاء الحقول ══');
ok('rcvFields مرتبط بحالة الطرد', SRC.includes('id="rcvFields-${i}" style="display:${receipt ? "none" : "block"}"'));
ok('زر «طرد لنفس المستلم» بيتخفي للطرد اللي بريسيت',
   /\$\{!receipt \? `<button type="button" class="rcv-copy"/.test(SRC));

/* ══ 4) التحقق ══ */
console.log('\n══ 4) التحقق بيسأل الطرد العادي بس ══');
const val = /if \(S\.wizStep === 2\) \{[\s\S]*?\n    \}/.exec(SRC)?.[0] || '';
ok('الشرط بقى على الطرد نفسه', /if \(!r\.receipt\) \{/.test(val));
ok('ومش على الطلب كله', !/S\.draft\.receiptMode/.test(val));
ok('المنطقة إجبارية في الحالتين — بره الشرط',
   /if \(!r\.zoneId\)\s*return stop\(i, "اختر منطقة التسليم"[\s\S]{0,200}if \(!r\.receipt\) \{/.test(val));
ok('علامة الناقص بتعفي الطرد اللي بريسيت',
   /if \(\$\("rcvReceipt-" \+ i\)\?\.checked\) return false;/.test(SRC));
ok('لكن بعد ما تتأكد إن المنطقة متختارة',
   /if \(!v\("rcvZone-" \+ i\)\) return true;[\s\S]{0,200}rcvReceipt-" \+ i\)\?\.checked\) return false;/.test(SRC));

/* ══ 5) الصورة إجبارية للطرد اللي بريسيت بس ══ */
console.log('\n══ 5) إجبارية الصورة ══');
ok('الفحص بيدوّر على طرد عليه ريسيت ومالوش صورة',
   /receivers\.findIndex\(r => r\.receipt && !\(r\.images \|\| \[\]\)\.some\(i => i\.url\)\)/.test(SRC));
ok('وبينقل الشاشة للطرد ده', /wizNext\(2\);\n      showRcv\(bad\);/.test(SRC));
ok('ملخّص المرفقات بيحسب النقص لكل طرد', /const bad = !!r\.receipt && !n;/.test(SRC));
ok('وتلميح المرفقات بيعدّ الطرود اللي محتاجة صورة',
   /const nR = \(S\.draft\?\.receivers \|\| \[\]\)\.filter\(r => r\.receipt\)\.length;/.test(SRC));

/* ══ 6) الحمولة — التوصيلة للسيرفر ══ */
console.log('\n══ 6) fromReceipt بتتبعت لكل طرد ══');
const map = /const deliveries = receivers\.map\(\(r, idx\) => \{[\s\S]*?\n    \}\);/.exec(SRC)?.[0] || '';
ok('حالة الطرد بتتقري جوه الحلقة', /const receipt = !!r\.receipt;/.test(map));
ok('ومافيش متغيّر عام قبل الحلقة', !/const receipt = !!S\.draft/.test(SRC));
ok('fromReceipt بتتبعت مع كل طرد', /fromReceipt   : !!receipt,/.test(map));
ok('والاسم والتليفون والعنوان بيتغيّروا بحالة الطرد',
   /receiverName  : receipt \?/.test(map) && /receiverPhone : receipt \?/.test(map) &&
   /address: receipt \?/.test(map));

/* ══ 7) الطرف التاني: السيرفر والسلك لكل طرد ══ */
console.log('\n══ 7) السيرفر واللوحات (لازم يفضلوا لكل طرد) ══');
ok('السيرفر بيقرا fromReceipt جوه حلقة الطرود',
   /\$fromReceipt = ! empty\(\$d\['fromReceipt'\]\);/.test(PHP));
ok('وبيخزّنها في عمود من ضمن صف الطرد',
   /'from_receipt'\s*=> \$fromReceipt \? 1 : 0,/.test(PHP));
ok('وبيسمح بالرقم الفاضي للطرد ده بس',
   /if \(! \$fromReceipt\) \{[\s\S]{0,300}رقم المستلم غير صحيح في الطرد رقم/.test(PHP));
ok('السلك بيطلّعها لكل طرد',
   /'receiverFromReceipt' => \(bool\) \(\$d\['receiver_from_receipt'\] \?\? false\),/.test(WIRE));
const BR = fs.readFileSync('public/branch.html', 'utf8');
ok('لوحة الفرع بتعرض الشارة على الطرد المعني بس',
   (BR.match(/d\.receiverFromReceipt \?/g) || []).length >= 2);

/* ══ 8) الملخّص بيوري الفرق بين الطرود ══ */
console.log('\n══ 8) ملخّص الطلب ══');
ok('كل طرد بيتعرض بحالته هو',
   /\(r\.receipt \? "🧾 من صورة الريسيت" : \(r\.name \|\| "—"\)\)/.test(SRC));
ok('ومافيش فرع بيعرض الطلب كله كريسيت',
   !/S\.draft\.receiptMode\s*\n?\s*\?/.test(SRC));

/* ══ 9) الستايل ══ */
console.log('\n══ 9) الستايل ══');
ok('.rcv-receipt موجودة', /\.rcv-receipt \{/.test(SRC));
ok('وحالة «مفعّل» متميّزة بصريًا', /\.rcv-receipt\.on \{/.test(SRC));
ok('والكلاس بيتحط على البلوك المفعّل', SRC.includes('class="rcv-receipt${receipt ? " on" : ""}"'));

console.log('\n' + '─'.repeat(50));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — الريسيت لكل طرد لوحده\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
