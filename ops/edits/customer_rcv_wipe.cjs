/* 🔴 بيانات المستلم بتتمسح وهو بيكتب — تطبيق العميل.
 *
 * ═══ البلاغ (صاحب النظام، 2026-09-01 — الإنتاج شغّال) ═══
 * «في تطبيق العميل لا أستطيع أن أكتب بيانات المستلم — أكتبها وتُمسح فجأة».
 *
 * ═══ السبب ═══
 * حقول المستلم (`rcvName-i` · `rcvPhone-i` · `rcvPhone2-i`، سطور 3501-3510)
 * **مالهاش أي `oninput`** — اللي المستخدم بيكتبه عايش في الـDOM بس، ومابيتخزّنش
 * في `S.draft.receivers` غير لما `readReceiverBlocks()` تتنده صراحةً.
 *
 * و`renderReceiverBlocks()` (3619) بتعمل:
 *     box.innerHTML = list.map(...)          ← بتبني الـHTML من `S.draft`
 * فأي إعادة رسم من غير مزامنة قبلها = مسح كل اللي اتكتب.
 *
 * الكود نفسه عارف القاعدة دي وموثّقها في سطر 3791:
 *     «⚠️ readReceiverBlocks الأول: إعادة الرسم بتبني الـHTML من الحالة»
 * وكل مواضع إعادة الرسم بتحترمها (3751 · 3766 · 3776 · 3794 · 4020 · 4149)
 * **إلا واحد**: بولّر `/api/customer/me` في سطر 1945.
 *
 * البولّر بيشتغل **كل ٦٠ ثانية**، وبيعيد الرسم طول ما العميل واقف على فورم
 * الطلب — من غير مزامنة، **وبغض النظر عن إن حاجة اتغيّرت أصلًا**. فالعميل
 * بيكتب اسم المستلم ورقمه، وفجأة كل حاجة بتترجع فاضية. ده «المفاجئ» في البلاغ.
 *
 * ═══ الإصلاح — حتّتين ═══
 * (١) **ماتعيدش الرسم إلا لو البوابة اتغيّرت فعلًا.** السبب المعلن لإعادة
 *     الرسم (تعليق 1944) هو إن بوابة التحصيل `codLocked()` تتفتح فورًا لما
 *     العميل يعدّي عتبة الطلبات المتسلّمة. ده حدث بيحصل **مرة واحدة**، مش
 *     كل ٦٠ ثانية. فبنقارن الحالة قبل وبعد وماننددهاش غير عند التغيير.
 *     ده لوحده بيوقّف المسح في ٩٩٪ من الحالات، وبيوقّف كمان **ضياع الفوكس
 *     وموضع المؤشر** اللي المزامنة لوحدها مابتحلّهوش.
 * (٢) **ولو اتغيّرت فعلًا، نزامن قبل ما نرسم.** عشان اللحظة النادرة اللي
 *     البوابة بتتفتح فيها والعميل بيكتب — ماتضيعش كتابته كمان.
 *
 * الاتنين مع بعض عشان مانعتمدش على واحد بس: (١) بتمنع السبب، و(٢) بتأمّن
 * الحالة الباقية.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILE = path.resolve(__dirname, '../../public/customer.html');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');

const OLD =
`    onChange: d => {
      const wasBlocked = !!(S.profile && S.profile.blocked);
      S.profile = d.customer || S.profile;
      S.wallet  = d.wallet   || S.wallet;
      if (d.cod) S.cod = d.cod;
      // لو العميل واقف على فورم الطلب والبوابة اتفتحت، الخانة تتحدّث فورًا
      if (S.page === "new" && typeof renderReceiverBlocks === "function") renderReceiverBlocks();`;

const NEU =
`    onChange: d => {
      const wasBlocked = !!(S.profile && S.profile.blocked);
      /* 🔴 حالة بوابة التحصيل **قبل** ما نحدّث S.cod — المقارنة دي هي اللي
         بتمنع إعادة رسم بلا لزوم. */
      const wasCodLocked = (typeof codLocked === "function") ? codLocked() : null;
      S.profile = d.customer || S.profile;
      S.wallet  = d.wallet   || S.wallet;
      if (d.cod) S.cod = d.cod;
      /* لو العميل واقف على فورم الطلب والبوابة اتفتحت، الخانة تتحدّث فورًا.
         🔴 بس **بشرطين** — الاتنين اتضافوا 2026-09-01 بعد بلاغ «بكتب بيانات
         المستلم وتتمسح فجأة»:
         (١) إعادة الرسم بتبني الـHTML من S.draft، والحقول مالهاش oninput،
             فاللي المستخدم كتبه لسه في الـDOM بس. البولّر ده كان بيرسم كل
             ٦٠ ثانية **بغض النظر عن إن حاجة اتغيّرت** — فكان بيمسح كتابته
             وهو بيكتب. دلوقتي مابنرسمش إلا لو البوابة **اتقلبت** فعلًا،
             وده بيحصل مرة واحدة في عمر الحساب. وده كمان بيحمي الفوكس
             وموضع المؤشر — حاجة المزامنة لوحدها مابتحلهاش.
         (٢) وحتى في اللحظة النادرة دي، بنزامن الـDOM للحالة الأول — نفس
             القاعدة الموثّقة تحت في renderReceiverBlocks. */
      if (S.page === "new" && typeof renderReceiverBlocks === "function"
          && wasCodLocked !== null && wasCodLocked !== codLocked()) {
        if (typeof readReceiverBlocks === "function") readReceiverBlocks();
        renderReceiverBlocks();
      }`;

/* ══ فحوص قبلية ══ */
const problems = [];
const n = s.split(OLD).length - 1;
if (n !== 1) problems.push(`كتلة البولّر: متوقّع ١ لقى ${n}`);
if (s.includes('wasCodLocked')) problems.push('الإصلاح متطبّق قبل كده');
/* الدوال اللي بنعتمد عليها لازم تكون موجودة */
for (const fn of ['function readReceiverBlocks()', 'function renderReceiverBlocks()', 'const codLocked =']) {
  if (!s.includes(fn)) problems.push('ناقص: ' + fn);
}
if (problems.length) {
  console.log('⛔ مافيش بايت اتكتب:');
  for (const p of problems) console.log('   ✗ ' + p);
  process.exit(1);
}

s = s.split(OLD).join(NEU);

/* ══ فحوص بعدية ══ */
const after = [];
/* ٣ = التعريف + الشرطين (!== null و !== codLocked()) */
if ((s.match(/wasCodLocked/g) || []).length !== 3) after.push('عدد ذكر wasCodLocked مش ٣');
/* البولّر لازم يفضل بينده readReceiverBlocks قبل renderReceiverBlocks */
{
  const at = s.indexOf('new api.Poller("/api/customer/me"');
  const blk = s.slice(at, at + 2200);
  const iRead = blk.indexOf('readReceiverBlocks()'), iRender = blk.indexOf('renderReceiverBlocks();');
  if (!(iRead > -1 && iRender > -1 && iRead < iRender))
    after.push('المزامنة مش قبل إعادة الرسم في البولّر');
  if (!/wasCodLocked !== codLocked\(\)/.test(blk)) after.push('شرط تغيّر البوابة مش موجود');
}
/* مواضع إعادة الرسم التانية ما اتلمستش */
if ((s.match(/renderReceiverBlocks\(\)/g) || []).length !== (raw.match(/renderReceiverBlocks\(\)/g) || []).length)
  after.push('عدد مناداة renderReceiverBlocks اتغيّر');
{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, bad = 0;
  while ((m = re.exec(s)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
    const tmp = path.join(os.tmpdir(), 'crw-' + process.pid + '-' + i + '.mjs');
    try { fs.writeFileSync(tmp, m[2]); execFileSync(process.execPath, ['--check', tmp], { stdio: 'pipe' }); }
    catch (e) { bad++; console.log('  ✗ كتلة ' + i + ': ' + ((e.stderr || '').toString().match(/SyntaxError:.*/) || ['?'])[0]); }
    finally { try { fs.unlinkSync(tmp); } catch (e2) {} }
  }
  console.log((bad ? '  ✗' : '  ✓') + ' كل كتل الجافاسكربت سليمة (' + i + ' كتلة)');
  if (bad) after.push('كتل مكسورة');
}
if (after.length) {
  console.log('⛔ فحوص بعدية وقعت — مافيش بايت اتكتب:');
  for (const a of after) console.log('   ✗ ' + a);
  process.exit(1);
}

if (process.env.DRY) { console.log('🟦 DRY — كل الفحوص عدّت، مافيش بايت اتكتب.'); process.exit(0); }
fs.writeFileSync(FILE, WAS_CRLF ? s.replace(/\n/g, '\r\n') : s);
console.log('✓ اتكتب customer.html — البولّر مابقاش يمسح كتابة العميل');
