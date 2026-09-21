/**
 * ✏️ حارس: الاسم اللي المستخدم كتبه مايتمسحش لما الرقم يطلع مسجّل باسم تاني.
 *
 * ═══ البلاغ (صاحب النظام 2026-09-12) ═══
 * «في طلب أوردر جديد، لو كتبت اسم عميل جديد فوق وتحت كتبت رقم العميل وكان
 *  مسجّل باسم قديم قبل كده، بيمسح الجديد ويسجّل القديم تلقائي. ممكن تظهر
 *  رسالة في فورم تسأل: الرقم ده له اسم موجود، هل تود تعديل البيانات؟»
 *
 * وكان بيحصل فعلًا — السطر كان `nameEl.value = res.name` من غير أي سؤال،
 * والشرط `typed !== res.name` يعني إنه كان بيدوس **عشان** المستخدم كتب
 * حاجة مختلفة. أثره باين في بيانات الإنتاج: أوردر «محل سري تون» والمحفوظ
 * «محل سويت هوم»، و«دلتا سويت» مقابل «محل زين»، وتصحيحات إملائية ضاعت.
 *
 * 🔴 القاعدة: الخانة **الفاضية** بتتملى لوحدها (مفيش حاجة بتضيع)،
 *    و**التعارض بس** هو اللي بيفتح المودال. لو الاتنين اتعاملوا بنفس
 *    الطريقة يبقى إما الملى التلقائي ضاع أو الدوس رجع.
 *
 * ⚠️ الشاشات الموظفين (branch/callcenter) خانة الاسم والرقم فيها **واحدة**
 *    (بحث بالاسم أو بالرقم) — فمفيش اسم مكتوب بيتمسح هناك، ومالهاش تعديل.
 *
 * التشغيل: node ops/test_name_conflict.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const J = fs.readFileSync('public/assets/js/trust.js', 'utf8');
const S = fs.readFileSync('public/store.html', 'utf8');
const C = fs.readFileSync('public/customer.html', 'utf8');

console.log('\n══ ① المودال المشترك ══');
ok('الدالة موجودة', /function askNameConflict\(opts\)/.test(J));
ok('ومتصدّرة على Trust', /askNameConflict: askNameConflict,/.test(J));
ok('وبتعرض الاسمين', /esc\(stored\)/.test(J) && /esc\(typed\)/.test(J));
ok('وفيها الاختيارين', /_ncKeep/.test(J) && /_ncUse/.test(J));
ok('🔴 ومفيش قفل من غير اختيار (مفيش Esc ولا ضغط برّه)',
  !/back\.onclick/.test(J.slice(J.indexOf('function askNameConflict'), J.indexOf('global.Trust'))),
  'القفل من غير اختيار بيرجّعنا لنفس السؤال — مين كسب؟');
ok('وبتوضّح مصدر الاسم المحفوظ', /var SRC_AR = \{/.test(J) && /receiver_book   : "دفتر المستلمين"/.test(J));
ok('⚠️ وبتقول إن المحفوظ مابيتغيّرش من هنا', /البيانات المحفوظة مابتتغيّرش من هنا/.test(J));

for (const [name, X, idName, noteFn] of [
  ['بوابة المحلات', S, 'rName-\\$\\{n\\}', 'trustNote\\(n'],
  ['تطبيق العميل', C, 'rcvName-" \\+ i', 'trustNote\\(i'],
]) {
  console.log(`\n══ ② ${name} ══`);
  ok('🔴 مفيش دوس مباشر على الاسم',
    !new RegExp('nameEl\\.value = res\\.name;\\s*(//|$)', 'm').test(X)
    || /if \(!typed\) \{[\s\S]{0,200}nameEl\.value = res\.name;/.test(X),
    'لسه بيمسح اللي المستخدم كتبه');
  ok('والخانة الفاضية بتتملى لوحدها (مفيش حاجة بتضيع)',
    /if \(!typed\) \{/.test(X) && /اتملى من سجلاتنا/.test(X));
  ok('🔴 والتعارض بيفتح المودال', /Trust\.askNameConflict\(\{/.test(X));
  ok('وبيبعت المكتوب والمحفوظ والمصدر',
    /typed: typed, stored: res\.name, source: res\.nameSource,/.test(X));
  ok('وفيه رد فعل للاختيارين', /onUseStored: \(\) =>/.test(X) && /onKeepTyped: \(\) =>/.test(X));
  ok('وبيبلّغ المستخدم بالنتيجة', new RegExp(noteFn).test(X));
}

console.log('\n══ ③ المسوّدة في تطبيق العميل ══');
ok('🔴 الاسم المختار بيتحفظ في المسوّدة كمان',
  /if \(S\.draft\?\.receivers\?\.\[i\]\) S\.draft\.receivers\[i\]\.name = v;/.test(C),
  'من غير كده الاختيار بيضيع مع أول إعادة رسم');
ok('والاتنين (المحفوظ والمكتوب) بيعدّوا على نفس الدالة',
  /onUseStored: \(\) => \{ _setName\(res\.name\)/.test(C) && /onKeepTyped: \(\) => \{ _setName\(typed\)/.test(C));

console.log('\n══ ④ نسخة التطبيقات اتحرّكت ══');
ok('🔴 store.html نسخته اتحرّكت (وإلا التحديث مايوصلش للمحلات)',
  /* النسخة بتتحرك مع كل تعديل في البوابة — المهم إنها **مانزلتش** عن اللي اتثبّتت هنا (1.1.7) */
  (() => { const m = S.match(/<meta name="app-version" content="(\d+)\.(\d+)\.(\d+)"/); return !!m && (+m[1] * 1e6 + +m[2] * 1e3 + +m[3]) >= 1001007; })());
ok('🔴 customer.html كمان', /<meta name="app-version" content="1\.7\.7"/.test(C));

console.log('\n══ ⑤ السيرفر مالوش دعوة (الاسم المكتوب بيكسب) ══');
const O = fs.readFileSync('app/Http/Controllers/Api/OrdersController.php', 'utf8');
ok('⚠️ الأوردر بياخد الاسم المبعوت مش اسم الصف المحفوظ',
  /الصف الموجود بيتساب زي ما هو — الاسم والعنوان بتوعه/.test(O));

console.log('\n══ ⑥ المحل بيصحّح الاسم المحفوظ ══');
const TC = fs.readFileSync('app/Http/Controllers/Api/TrustController.php', 'utf8');
const RT = fs.readFileSync('routes/api.php', 'utf8');
ok('المسار بيسمح للمحل', /role:admin,branch,callcenter,store/.test(RT));
ok('🔴 والمحل مقفول على الأرقام اللي اتعامل معاها',
  /if \(! TrustWire::hasDealtWith\(\$p, \$actor\)\) \{/.test(TC),
  'من غيرها أي محل يقدر يعيد تسمية أي رقم في الشركة');
ok('🔴 ومايقدرش يدوس على تصحيح موظف',
  /\(\$cur->verified_role \?\? ''\) === 'staff'/.test(TC),
  'party_identities أعلى مصدر — تصحيح المحل كان هيغلب الموظف');
ok('والموظف مالوش البوابتين دول',
  /\$staff = \$actor->isStaff\(\);/.test(TC) && /if \(! \$staff\) \{/.test(TC));
ok('والدور بيتسجّل على الصف',
  /verified_role, verified_at/.test(TC) && /\$role = \$staff \? 'staff' : \$actor->role;/.test(TC));
ok('وعمود verified_role في المخطط',
  /`verified_role` varchar\(32\)/.test(fs.readFileSync('database/schema/mysql-schema.sql', 'utf8')));
ok('وسكربت التطبيق بيعلّم الصفوف القديمة staff',
  fs.readFileSync('ops/apply_identity_role.php', 'utf8').includes("SET `verified_role` = 'staff' WHERE `verified_role` IS NULL"),
  'من غيرها تصحيحات الموظفين القديمة كان أي محل يقدر يدوس عليها');
ok('🔴 والزرار في المودال بينده المسار',
  J.includes('api().put("/api/trust/" + encodeURIComponent(normPhone'));
ok('والرقم بيتطبّع قبل الإرسال (زي اللي الفحص بيستخدمه)',
  J.includes('encodeURIComponent(normPhone(opts.phone'));
ok('والكاش بيتمسح بعد التصحيح', J.includes('forget(opts.phone);'), 'وإلا الفحص الجاي يرجّع الاسم القديم');
ok('⚠️ ورسالة الرفض بتبان جوه المودال مش toast', J.includes('errBox.style.display = "";'));
ok('المسار بيسمح للعميل كمان', /role:admin,branch,callcenter,store,customer/.test(RT));
ok('وبوابة المحلات مفعّلاه', S.includes('canFix: true,') && S.includes('onFixed:     () =>'));
ok('وتطبيق العميل مفعّلاه كمان', C.includes('canFix: true,') && C.includes('onFixed:     () =>'));
ok('🔴 والاتنين بيبعتوا الرقم (من غيره التصحيح يروح لرقم فاضي)',
  S.includes('phone: document.getElementById(`rPhone-${n}`)?.value')
  && C.includes('phone: $("rcvPhone-" + i)?.value'));
ok('⚠️ والمحل والعميل متساويين — الموظف هو الحكم',
  TC.includes('المحل والعميل **متساويين** بينهم'));

console.log('\n' + '─'.repeat(52));
console.log(fail === 0 ? `✅ عدّى ${pass} فحص — اللي المستخدم كتبه مايتمسحش من غير ما يوافق\n` : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
