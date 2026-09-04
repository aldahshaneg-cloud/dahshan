/**
 * 📱 اختبار تعديلات تطبيق العميل (طلب صاحب النظام 2026-08-30).
 *
 * أربع تعديلات:
 *   ① وضع ليلي/نهاري بتلات حالات (ليلي · نهاري · النظام)
 *   ② شيب «بياناتي» فوق جنب عنوان «بيانات المُرسِل»
 *   ③ زرار «طرد آخر» فوق جنب عنوان «بيانات المستلم»
 *   ④ وضع «مش معايا بيانات المستلم» بقى يقبل أكتر من طرد — بصور بس
 *
 * ═══ الحتة اللي لازم تتحرس أكتر من غيرها ═══
 * التعديل ④ مش زرار — هو **سلوك**: الكود القديم كان بيقصّ الطرود لواحد
 * أول ما الوضع يتفعّل. يعني عميل ضاف تلات طرود ودوس على الاختيار كان
 * بيلاقي اتنين اتمسحوا من غير أي تحذير. الفحص هنا بيشغّل الدالة الحقيقية
 * ويعدّ الطرود قبل وبعد.
 *
 * التشغيل: node ops/test_customer_ui.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (w, c, got) => { c ? (pass++, console.log('  ✓ ' + w))
  : (fail++, console.log('  ✗ ' + w + (got !== undefined ? '   ← ' + got : ''))); };

const src = fs.readFileSync('public/customer.html', 'utf8');

console.log('\n══ ① الوضع الليلي/النهاري ══');
{
  ok('اللوحة الليلية على :root', /:root\{[\s\S]{0,400}--bg:#0a0a0b/.test(src));
  ok('واللوحة النهارية تحت الميديا',
     /@media \(prefers-color-scheme:light\)\{\s*:root:not\(\[data-theme="dark"\]\)/.test(src));
  ok('وتحت [data-theme="light"] كمان', /:root\[data-theme="light"\]\{/.test(src));
  /* الحارس ده هو اللي بيخلي الاختيار الصريح يغلب على الجهاز */
  ok('🔴 الميديا محروسة بـ:not([data-theme="dark"])',
     /@media \(prefers-color-scheme:light\)\{\s*:root:not\(\[data-theme="dark"\]\)/.test(src),
     'من غيره: اخترت ليلي وجهازك نهاري → التطبيق يطلع نهاري');
  ok('تلات حالات مش اتنين', /key: "system"/.test(src) && /key: "dark"/.test(src) && /key: "light"/.test(src));
  ok('الاختيار بيتحفظ', /localStorage\.setItem\("cust-theme"/.test(src));
  /* من غير السكربت المبكر بيحصل وميض أبيض قبل ما الجافاسكربت يشتغل */
  ok('🔴 سكربت منع الوميض في الـhead',
     /localStorage\.getItem\("cust-theme"\)[\s\S]{0,160}setAttribute\("data-theme"/.test(src.slice(0, 4000)),
     'الصفحة هتفتح ليلي لحظة قبل ما تتحول');
  ok('لون شريط المتصفح بيتغيّر', /theme-color[\s\S]{0,120}prefers-color-scheme: light/.test(src));
}

console.log('\n══ ② الألوان الثابتة اتحوّلت لتوكنز ══');
/* من غير كده الوضع النهاري بيطلع نص فاتح على خلفية فاتحة */
for (const [tok, was] of [['--line2', '#3a3a42'], ['--dim', '#5a5a63'],
                          ['--txt2', '#d0d0d6'], ['--sunken', '#1a1a1d'], ['--pop', '#26262c']]) {
  ok('`' + tok + '` بديل ' + was, src.includes(tok + ':'), 'التوكن مش متعرّف');
  ok('و' + was + ' مابقاش مكتوب بالإيد',
     !new RegExp('(color|background|border-color):' + was).test(src), 'لسه موجود');
}

console.log('\n══ ③ خانة المنطقة بتتبع الوضع ══');
/* zonepick بتنسخ الألوان كستايل مباشر، والمباشر بيغلب على التوكنز */
{
  ok('فيه إعادة تلوين', /function repaintZonePicks/.test(src));
  /* الفحص على **الاحتواء** مش على المسافة: أول نسخة كانت بتقيس ١٤٠ حرف
     بين النداء و`theme-color` — والتعليق اللي بينهم كبّر المسافة فوقعت
     وهي شغّالة. المهم إن النداء جوّه `applyTheme` مش قد إيه بعيد. */
  {
    const at = src.indexOf('function applyTheme');
    const end = src.indexOf('\nfunction ', at + 10);
    const body = src.slice(at, end > 0 ? end : at + 1800);
    ok('وبتتنده من جوّه applyTheme', body.includes('repaintZonePicks()'),
       'النداء بره الدالة — التبديل مش هيعيد التلوين');
  }
  ok('بتلمس الخانة والقايمة', /\.zp-in/.test(src) && /\.zp-pop/.test(src));
  /* الأبعاد لازم تفضل زي ما copyLook نسختها — لو لمسناها بنرجّع باج الـ1px */
  ok('🔴 مابتلمسش الأبعاد',
     !/repaintZonePicks[\s\S]{0,700}style\.(height|padding|width)/.test(src),
     'لمس الأبعاد بيرجّع انهيار الخانة');
}

console.log('\n══ ④ «بياناتي» فوق جنب العنوان ══');
{
  ok('الشيبات في رأس البطاقة',
     /بيانات المُرسِل<\/b>[\s\S]{0,300}id="sndSaved"/.test(src));
  ok('ومكانها القديم اتشال', !/<div id="sndSaved" style="margin-top:10px">/.test(src));
  ok('سطر واحد مايلفّش', /\.chips-inline\{[^}]*flex-wrap:nowrap/.test(src),
     'من غيره بيلفّوا ويكبّروا الرأس');
}

console.log('\n══ ⑤ زرار الطرد فوق ══');
{
  /* اتغيّر 2026-08-31: كان زرارين للإضافة — واحد في رأس المستلم وواحد
     تحت البلوكات — عشان الزرار مايبقاش بعيد لما الطرود تكتر. دلوقتي
     الطرود بقت في تبويبات وزرار الإضافة الوحيد جوه صف الأزرار فوقها،
     فهو **دايمًا** في المنظور من غير تكرار.
     النية اللي بنحرسها ماتغيرتش: طريقة إضافة طرد تفضل فوق البلوكات. */
  ok('زر الإضافة فوق البلوكات', /id="rcvTabs"[\s\S]{0,300}id="rcvBlocks"/.test(src));
  ok('وبينده addReceiver', /class="ptab-add" onclick="addReceiver\(\)"/.test(src));
  ok('والزرارين القدام اتشالوا', !/addRcvBtn/.test(src), 'لسه فيه مرجع يتيم');
  ok('وصف الأزرار جوه خطوة المستلم (wz-2)',
     /id="wz-2"[\s\S]{0,4000}id="rcvTabs"/.test(src));
}

console.log('\n══ ⑥ 🔴 وضع الريسيت: أكتر من طرد ══');
{
  /* الحاجز الأول: الزرار كان بيتخفي */
  /* الزرار بقى واحد جوه صف الأزرار وبيترسم دايمًا — مافيش سطر بيخفيه
     أصلًا، فالحاجز بقى: مافيش أي كود بيمنع رسم زر الإضافة. */
  ok('الزرار مابيتخفيش في وضع الريسيت',
     !/ptab-add[\s\S]{0,120}receipt \? "none"/.test(src) &&
     /let html = `<button type="button" class="ptab-add"/.test(src),
     'لسه بيتخفي');
  /* اتغيّر 2026-08-31: المفتاح بقى جوه كل طرد، فنص زر الإضافة مابقاش
     بيتغيّر — الطرد الجديد بيبدأ عادي والعميل بيقرر جواه. النية اللي
     بنحرسها ماتغيرتش: العميل لازم يتقاله إن الصورة مطلوبة، والمكان بقى
     تحت المفتاح نفسه جوه الطرد. */
  ok('العميل بيتقاله إن الصورة مطلوبة للطرد ده',
     /مطلوب صورة ريسيت للطرد ده/.test(src));
  ok('ونص المفتاح بيشرح البديل قبل ما يفعّله',
     /هرفع صورة الريسيت بدل ما أكتب الاسم والتليفون والعنوان/.test(src));

  /* الحاجز التاني — وهو الأخطر: الطرود كانت بتتقصّ من غير تحذير */
  ok('🔴 الطرود مابتتقصّش لواحد',
     !/if \(on && S\.draft\.receivers\.length > 1\) S\.draft\.receivers = \[S\.draft\.receivers\[0\]\];/.test(src),
     'لسه بيمسح الطرود الزيادة');

  /* والملخّص لازم يعرض كل الطرود مش الأول بس */
  /* بقى مسار واحد بيلفّ على كل الطرود وكل طرد بيتعرض بحالته هو — مش
     فرعين حسب مفتاح عام. النية: مافيش طرد بيختفي من المراجعة. */
  ok('الملخّص بيعرض كل الطرود',
     /const rcvRows = list\.map\(\(r, i\) => row\(/.test(src),
     'لسه بيعرض list[0] بس');
  ok('وكل طرد بحالته هو في الملخّص',
     /\(r\.receipt \? "🧾 من صورة الريسيت" : \(r\.name \|\| "—"\)\)/.test(src));

  /* اللي كان شغّال أصلًا ولازم يفضل */
  /* `stop` بدل `toast` من 2026-08-31: الطرود بقت مخفية في تبويبات،
     فالتحقق لازم ينقل الشاشة للطرد الناقص قبل الرسالة — رسالة عن طرد
     المستخدم مش شايفه بلا معنى. الإجبارية نفسها ماتغيرتش. */
  ok('المنطقة إجبارية لكل طرد', /if \(!r\.zoneId\)\s*return stop\(i, "اختر منطقة التسليم"/.test(src));
  /* بقت إجبارية للطرود اللي عليها علامة الريسيت **بس** — الطرد اللي
     كاتب بياناته عادي مايتطلبش منه صورة. النية الأصلية (صورة لكل طرد
     محتاجها، مش صورة واحدة للطلب كله) لسه محفوظة. */
  ok('والصورة إجبارية لكل طرد عليه علامة الريسيت',
     /findIndex\(r => r\.receipt && !\(r\.images \|\| \[\]\)\.some\(i => i\.url\)\)/.test(src));
  ok('والطرد العادي مايتطلبش منه صورة',
     !/findIndex\(r => !\(r\.images \|\| \[\]\)\.some/.test(src));
  ok('وحقول المستلم مخفية في الوضع ده', /id="rcvFields-\$\{i\}" style="display:\$\{receipt \? "none"/.test(src));
}

console.log('\n══ ⑦ 🔴 سبب عدم التوصيل مهروب ══');
/* السبب ده بيكتبه الطيار وبيتعرض للعميل. كل تطبيقات الموظفين بتهرّبه؛
   تطبيق العميل كان الوحيد اللي فاته — يعني سبب فيه وسم HTML كان بيتنفّذ
   في متصفح العميل. */
{
  ok('صف المسار الزمني بيعدّي على esc', /<b>\$\{esc\(x\.t\)\}<\/b>/.test(src),
     'undeliveredReason بيتحط في x.t من غير هروب');
  ok('والسبب فعلًا بيتحط في x.t',
     /t: "لم يتم التوصيل"[\s\S]{0,90}undeliveredReason/.test(src));
  /* والأسباب في باقي التطبيقات لازم تفضل مهروبة */
  for (const other of ['branch', 'tiar', 'callcenter']) {
    const t = fs.readFileSync('public/' + other + '.html', 'utf8');
    ok(other + ': أسبابه مهروبة',
       !/\$\{\s*o\.(undeliveredReason|cancelReason)\s*\}/.test(t));
  }
}

console.log('\n══ ⑧ تباين الوضع النهاري ══');
/* الألوان الدلالية كانت متسابة زي الليلي — #22c55e على أبيض بيدي تباين
   ~2.0، يعني نص أخضر شبه مختفي. الفحص بيحسب النسبة زي المتصفح. */
{
  const hex = h => { h = h.trim().replace('#', '');
    if (h.length === 3) h = h.split('').map(c => c + c).join('');
    return [0, 2, 4].map(i => parseInt(h.slice(i, i + 2), 16)); };
  const rel = ([r, g, b]) => { const f = v => { v /= 255;
    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
  const ratio = (a, b) => { const [l1, l2] = [rel(hex(a)), rel(hex(b))].sort((x, y) => y - x);
    return (l1 + 0.05) / (l2 + 0.05); };

  /* بنقص اللوحة النهارية من الملف — مش بنكتب القيم هنا، عشان لو حد
     غيّرها الفحص يقيس الجديد مش القديم. */
  const block = src.match(/:root\[data-theme="light"\]\{([\s\S]*?)\}/);
  ok('اللوحة النهارية موجودة', !!block);
  if (block) {
    const tok = {};
    for (const m of block[1].matchAll(/(--[a-z0-9-]+)\s*:\s*(#[0-9a-fA-F]{3,8})/g)) tok[m[1]] = m[2];
    const BGS = ['--bg', '--bg2', '--card', '--card2', '--sunken', '--pop'];
    for (const fg of ['--txt', '--muted', '--dim', '--red', '--green', '--orange', '--blue']) {
      if (!tok[fg]) { ok('`' + fg + '` معرّف في النهاري', false); continue; }
      let worst = 99, at = '';
      for (const bg of BGS) if (tok[bg]) {
        const r = ratio(tok[fg], tok[bg]);
        if (r < worst) { worst = r; at = bg; }
      }
      ok('`' + fg + '` تباينه ≥ 3', worst >= 3,
         'أسوأ حالة ' + worst.toFixed(2) + ' على ' + at + ' (' + tok[fg] + ')');
    }
  }
}

console.log('\n══ ⑨ الافتراضي ليلي ══');
/* التطبيق أسود من أصله وكل صفحات الموقع سودا. خلّي الافتراضي «النظام»
   معناه إن أي حد جهازه نهاري يلاقي التطبيق قلب أبيض من غير ما يطلب. */
{
  ok('🔴 currentTheme بترجّع dark لو مفيش اختيار',
     /localStorage\.getItem\("cust-theme"\) \|\| "dark"/.test(src),
     'الافتراضي لسه «النظام»');
  ok('والسكربت المبكر بيحط dark كمان',
     /localStorage\.getItem\("cust-theme"\) \|\| "dark"/.test(src.slice(0, 5000)),
     'الصفحة هتفتح نهاري قبل ما الجافاسكربت يشتغل');
  ok('وليلي أول واحد في ترتيب التبديل',
     /const THEMES = \[[\s\S]{0,140}\{ key: "dark"/.test(src));
}

console.log('\n══ ⑩ التوست مقروء في الوضعين ══');
/* خلفياته غامقة مكتوبة بالإيد، ولونه كان بيورث --txt — اللي بقى غامق
   في النهاري. النتيجة كانت نص أسود على أخضر غامق. */
{
  ok('🔴 لون التوست مكتوب صراحةً', /#toast\{[^}]*color:#fff/.test(src),
     'بيورث --txt فبيبقى غامق على غامق في النهاري');
  ok('ولون النجاح مكتوب كمان', /#toast\.ok\{[^}]*color:#[0-9a-f]{3,6}/.test(src));
  ok('ولون الخطأ كمان', /#toast\.err\{[^}]*color:#[0-9a-f]{3,6}/.test(src));
}

console.log('\n══ ⑪ 🔴 الإحصائيات بتعدّ الطرود ══');
/* طلب واحد فيه طردين = شحنتين فعليًا للعميل. الكود كان بيعدّ الأوردرات. */
{
  const start = src.indexOf('function parcelsOf');
  ok('دالة عدّ الطرود موجودة', start > -1);
  if (start > -1) {
    /* بنقصّها ونشغّلها على حالات حقيقية */
    let d = 0, end = -1;
    for (let j = src.indexOf('{', start); j < src.length; j++) {
      const c = src[j];
      if (c === '{') d++;
      else if (c === '}') { d--; if (!d) { end = j + 1; break; } }
    }
    const parcelsOf = new Function(src.slice(start, end) + '\nreturn parcelsOf;')();

    ok('أوردر بطردين = 2', parcelsOf({ deliveries: [{}, {}] }) === 2, parcelsOf({ deliveries: [{}, {}] }));
    ok('أوردر بطرد = 1', parcelsOf({ deliveries: [{}] }) === 1);
    ok('أوردر قديم بلا طرود = 1', parcelsOf({}) === 1, 'لازم مايختفيش من العدّ');
    /* الشحنة الجايّة: نعدّ طروده هو بس مش طرود الأوردر كله */
    ok('🔴 شحنة جايّة: طروده هو بس',
       parcelsOf({ _incoming: true, _myParcels: [1], deliveries: [{}, {}, {}] }) === 1,
       'بيعدّ طرود ناس تانية');
    ok('وشحنة جايّة بطردين ليه = 2',
       parcelsOf({ _incoming: true, _myParcels: [0, 2], deliveries: [{}, {}, {}] }) === 2);

    /* `parcelsOf` لسه مستعملة في عدّاد الإشعارات (`unreadOrders`).
       أما الإحصائيات فبقت بتمشي على صفوف الطرود مباشرةً — أدقّ، لأنها
       بتصنّف كل طرد لوحده بدل ما تصنّف الأوردر وتضرب في عدد طروده.
       الفحص على الشكل الجديد في قسم ⑭. */
    ok('عدّاد الإشعارات لسه بيستعملها',
       /n \+= Math\.max\(1, myParcelsOf\(id\)\.length\)/.test(src));
    ok('والإحصائيات بقت على صفوف الطرود',
       /const rows = all\.flatMap\(orderRows\)/.test(src) &&
       /stTotal"\)\.textContent\s*=\s*rows\.length/.test(src),
       'لسه بتستعمل all.length أو بتضرب في عدد الطرود');
  }
}

console.log('\n══ ⑫ 🔴 إشعارات: كارت لكل طرد ══');
/* أوردر بتلات طرود لازم يطلع تلات كروت بأرقامهم. والطرد اللي اتسلّم
   يبان «تم التسليم» حتى لو إخوته لسه في الطريق. */
{
  ok('دالة طرود العميل موجودة', /function myParcelsOf/.test(src));
  ok('ودالة حالة الطرد', /function parcelNotifKey/.test(src));
  ok('الكروت بتتبني لكل طرد', /const items = multi \? parcels : \[null\]/.test(src));
  ok('والرقم بيبقى رقم-طرد', /p\.parcelNo \?\? \(i \+ 1\)/.test(src));
  ok('وبيبان «طرد ن من م»', /طرد \$\{i \+ 1\} من \$\{parcels\.length\}/.test(src));
  ok('🔴 العدّاد بيعدّ الطرود',
     /n \+= Math\.max\(1, myParcelsOf\(id\)\.length\)/.test(src), 'لسه بيعدّ الأوردرات');
  ok('والشحنة الجايّة طرودها هي بس', /o\._incoming \? \(o\._myParcels/.test(src));

  /* بنشغّل الدالة فعلًا مش بنشوف اسمها */
  const at = src.indexOf('function parcelNotifKey');
  let d = 0, end = -1;
  for (let j = src.indexOf('{', at); j < src.length; j++) {
    const c = src[j];
    if (c === '{') d++;
    else if (c === '}') { d--; if (!d) { end = j + 1; break; } }
  }
  const fn = new Function(src.slice(at, end) + '\nreturn parcelNotifKey;')();
  ok('طرد اتسلّم = delivered', fn({ status: 'تم التسليم' }, 'out') === 'delivered');
  ok('طرد فشل = failed', fn({ status: 'لم يتم التوصيل' }, 'out') === 'failed');
  ok('🔴 طرد لسه شغّال = خطوة الرحلة', fn({ status: 'قيد التنفيذ' }, 'out') === 'out',
     'الطرد اللي لسه في الطريق لازم ياخد الخطوة المشتركة');
}

console.log('\n══ ⑬ شاشة عملائي ══');
{
  ok('الشاشة موجودة', /id="s-clients"/.test(src));
  ok('ومربوطة بالتنقّل', /page === "clients"\)\s*renderClients/.test(src));
  ok('والشريط السفلي بيفضل', /"addresses", "clients", "detail"/.test(src));
  ok('صف في الرئيسية', /go\('clients'\)[\s\S]{0,120}عملائي/.test(src));
  ok('زرار «ابعت له»', /function sendToClient/.test(src) && /ابعت له/.test(src));
  ok('وبيملا بيانات المستلم', /rc\.phone\s*=\s*r\.phone/.test(src));
  ok('وحذف', /function delClient/.test(src) && /api\.del\("\/api\/customer\/receivers/.test(src));

  const routes = fs.readFileSync('routes/api.php', 'utf8');
  ok('🔴 مسار الحذف على السيرفر', /Route::delete\('customer\/receivers\/\{id\}'/.test(routes));
  const ctrl = fs.readFileSync('app/Http/Controllers/Api/CustomerAppController.php', 'utf8');
  ok('🔴 الحارس على customer_id',
     /DELETE FROM customer_saved_receivers WHERE id = \? AND customer_id = \?/.test(ctrl),
     'من غيره أي عميل يمسح مستلم حد تاني');
}

console.log('\n══ ⑭ 🔴 صفحة الطلبات: صف لكل طرد ══');
/* ═══ الباج اللي وقعت فيه ═══
   أول نسخة كانت بتستعمل `orderState(o)` كخلفية لحالة الطرد — و
   `orderState` بتقرا **الطرد الأول**. فأوردر فيه طرد اتسلّم وطرد لسه في
   الطريق كان بيعرض الاتنين «تم التسليم»، والإحصائيات تقول ٣ مسلّمين
   وهو واحد. الإصلاح: `tripState(o)` بتقرا الأوردر لوحده من غير أي طرد. */
{
  ok('دالة صفوف الطرود موجودة', /function orderRows/.test(src));
  ok('ودالة حالة الرحلة', /function tripState/.test(src));
  ok('🔴 حالة الرحلة مابتقراش أي طرد',
     !/function tripState[\s\S]{0,400}myParcel|function tripState[\s\S]{0,400}deliveries/.test(src),
     'لو قرت طرد، الطرود هترث حالة بعض');
  ok('وحالة الطرد بترجع لحالة الرحلة',
     /function parcelState[\s\S]{0,320}return tripState\(o\)/.test(src),
     'لو رجعت لـorderState بيحصل التلوّث');
  ok('الصفحة بتفرد الطرود', /S\.orders\.flatMap\(orderRows\)/.test(src));
  ok('والفلتر على حالة الطرد', /filter\(r => S\.orderFilter === "all" \|\| parcelState\(r\.o, r\.d\)/.test(src));
  ok('والإحصائيات بتصنّف كل طرد لوحده',
     /const st = s => rows\.filter\(r => parcelState\(r\.o, r\.d\) === s\)\.length/.test(src),
     'لسه بتصنّف الأوردر وتضرب في عدد طروده');

  /* بنشغّل الدالتين على نفس الحالة اللي كشفت الباج */
  const cut = name => {
    const at = src.indexOf('function ' + name);
    let d = 0, end = -1;
    for (let j = src.indexOf('{', at); j < src.length; j++) {
      const c = src[j];
      if (c === '{') d++;
      else if (c === '}') { d--; if (!d) { end = j + 1; break; } }
    }
    return src.slice(at, end);
  };
  const fns = new Function(cut('tripState') + '\n' + cut('parcelState') +
                           '\nreturn { tripState, parcelState };')();
  const o = { status: 'جاري التوصيل', pilotId: 'p1' };
  ok('🔴 طرد اتسلّم = done', fns.parcelState(o, { status: 'تم التسليم' }) === 'done');
  ok('🔴 طرد لسه شغّال = way', fns.parcelState(o, { status: 'قيد التنفيذ' }) === 'way',
     'ورث حالة أخوه المسلّم');
  ok('🔴 طرد فشل = failed', fns.parcelState(o, { status: 'لم يتم التوصيل' }) === 'failed');
  ok('أوردر ملغي: كل طرده ملغي',
     fns.parcelState({ status: 'ملغي' }, { status: 'تم التسليم' }) === 'cancel');
  ok('أوردر بلا طيار = جديد', fns.tripState({ status: 'قيد التنفيذ' }) === 'new');
}

console.log('\n════════════════════════════════════════');
console.log('CUSTOMER UI: ' + pass + ' ناجح · ' + fail + ' فاشل');
console.log('════════════════════════════════════════');
process.exit(fail > 0 ? 1 : 0);
