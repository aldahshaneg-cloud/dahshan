/* 🔴 «حصل خطأ» والأوردر اتسجّل فعلًا — تطبيق العميل.
 *
 * ═══ البلاغ (صاحب النظام، 2026-09-01 — الإنتاج شغّال) ═══
 * «وأنا بضغط على الإرسال قال حصل خطأ ولكن فعلاً حصل إرسال».
 *
 * ═══ الدليل من الإنتاج ═══
 * سجل أباتشي: كل عميل بعت **مرتين** بفارق ثواني، والاتنين رجعوا **200**:
 *   156.197.73.252  10:48:53 → 200   |  10:48:57 → 200
 *   196.134.114.50  13:01:29 → 200   |  13:02:01 → 200
 * والأوردرات مكرّرة حرفيًا (نفس المُرسِل والمستلم والمنطقة):
 *   527087/527088 «محل نظارات → عبد الرحمن سعد»
 *   527091/527092 «احمد موسي → احمد موسي»
 * يعني السيرفر نجح، والعميل قال «خطأ»، فالزبون ضغط تاني.
 * **كل** أوردر جه من التطبيق النهاردة طلع مكرر — الباج بيحصل ١٠٠٪.
 *
 * ═══ السبب ═══
 * جوه `submitOrder`:
 *   • سطر ~4233: `const receipt = !!r.receipt;`  ← **جوه** الـ`.map()`
 *   • سطر ~4325: `if (!receipt) deliveries.forEach(...)`  ← **بره** الـmap
 * `const` نطاقها البلوك، فالسطر التاني بيرمي
 *   ReferenceError: receipt is not defined
 * بعد ما الأوردر يكون اتسجّل خلاص. والـ`catch` بتقول «تعذّر إرسال الطلب».
 *
 * ═══ الإصلاح — حتّتين ═══
 * (١) **السبب المباشر**: الريسيت خاصية **كل طرد لوحده** مش الطلب كله
 *     (التعليق اللي فوق الـmap بيقول كده صراحةً). فالشرط بيتنقل جوه اللفّة
 *     على `d.fromReceipt` — اللي الـmap نفسها بتحطها.
 * (٢) **الفئة كلها**: أي رمي **بعد** نجاح الإنشاء ممنوع يقول «تعذّر
 *     الإرسال». علامة `orderCreated` بتتحط بعد الـPOST مباشرة، والـcatch
 *     بيفرّق: فشل حقيقي → رسالة خطأ؛ فشل بعد التسجيل → الطلب اتسجّل +
 *     تحويل لقائمة الطلبات عشان يشوفه بنفسه.
 *     من غير (٢) أي غلطة جاية في العرض هترجّع نفس الكارثة — أوردرات
 *     مكرّرة وفلوس مكرّرة على العميل.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILE = path.resolve(__dirname, '../../public/customer.html');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');

const EDITS = [
  {
    label: '(١) شرط الريسيت لكل طرد',
    old:
`    // نحفظ المستلمين للاقتراح في المرات الجاية
    // (مش في وضع الريسيت — مفيش بيانات نحفظها)
    if (!receipt) deliveries.forEach(d => saveReceiver(d));`,
    neu:
`    /* نحفظ المستلمين للاقتراح في المرات الجاية — وبنتخطّى طرود الريسيت
       (مفيش بيانات نحفظها).
       🔴 كان \`if (!receipt)\` و\`receipt\` معرّفة بـ\`const\` **جوه** الـmap
       فوق — نطاقها البلوك، فالسطر ده كان بيرمي ReferenceError **بعد** ما
       الأوردر يتسجّل، والـcatch تقول «تعذّر إرسال الطلب» فالعميل يضغط تاني
       ويعمل أوردر مكرر. حصل لكل أوردر جه من التطبيق يوم 2026-09-01.
       والريسيت أصلًا خاصية كل طرد لوحده مش الطلب كله. */
    deliveries.forEach(d => { if (!d.fromReceipt) saveReceiver(d); });`,
  },
  {
    label: '(٢) علامة «الأوردر اتسجّل»',
    old: `    const res = await api.post("/api/customer/orders", {`,
    neu: `    const res = await api.post("/api/customer/orders", {`,   // بيتعدّل تحت
    skip: true,
  },
];

/* الحتة (٢) محتاجة تعديلين مرتبطين */
const MARK_OLD = `    const saved = res.order;`;
const MARK_NEU =
`    /* 🔴 من هنا وتحت: الأوردر **اتسجّل على السيرفر خلاص**. أي رمي بعد
       السطر ده مشكلة عرض، مش فشل إرسال — والـcatch تحت بتفرّق بيهم. */
    orderCreated = true;
    const saved = res.order;`;

const CATCH_OLD =
`  } catch (e) {
    toast("تعذّر إرسال الطلب: " + (e.message || e), "err");
  } finally {
    btn.disabled = false; btn.textContent = "إرسال الطلب";
  }`;
const CATCH_NEU =
`  } catch (e) {
    /* 🔴 الفرق ده هو اللي بيمنع الأوردرات المكرّرة. لو الإنشاء نجح وبعدين
       حاجة في العرض رمت، «تعذّر إرسال الطلب» **كدب** — والعميل بيضغط تاني
       ويدفع مرتين. حصل فعلًا 2026-09-01: أربع أوردرات، اتنين منهم مكرّرين،
       وكل الطلبات رجعت 200 من السيرفر. */
    if (orderCreated) {
      console.error("submitOrder — بعد التسجيل:", e);
      toast("الطلب اتسجّل ✓ — بس حصلت مشكلة في عرض التأكيد، هتلاقيه في طلباتك", "ok");
      try { go("orders"); } catch (_) {}
    } else {
      toast("تعذّر إرسال الطلب: " + (e.message || e), "err");
    }
  } finally {
    btn.disabled = false; btn.textContent = "إرسال الطلب";
  }`;

/* تعريف العلامة قبل الـtry */
const DECL_OLD = `  const btn = $("submitBtn");`;
const DECL_NEU =
`  const btn = $("submitBtn");
  /* بتتقلب لـtrue بمجرد ما السيرفر يرد بنجاح — الـcatch بيقراها عشان
     مايقولش «تعذّر الإرسال» على أوردر اتسجّل فعلًا. */
  let orderCreated = false;`;

/* ══ فحوص قبلية ══ */
const problems = [];
const checks = [
  ['(١) شرط الريسيت', EDITS[0].old],
  ['(٢أ) تعريف العلامة', DECL_OLD],
  ['(٢ب) موضع العلامة', MARK_OLD],
  ['(٢ج) كتلة الـcatch', CATCH_OLD],
];
for (const [label, txt] of checks) {
  const n = s.split(txt).length - 1;
  if (n !== 1) problems.push(`${label}: متوقّع ١ لقى ${n}`);
}
if (s.includes('orderCreated')) problems.push('الإصلاح متطبّق قبل كده');
/* الـmap لازم تكون فعلًا بتحط fromReceipt */
if (!/fromReceipt   : !!receipt,/.test(s)) problems.push('الـmap مش بتحط fromReceipt — راجع');
if (problems.length) {
  console.log('⛔ مافيش بايت اتكتب:');
  for (const p of problems) console.log('   ✗ ' + p);
  process.exit(1);
}

s = s.split(EDITS[0].old).join(EDITS[0].neu)
     .split(DECL_OLD).join(DECL_NEU)
     .split(MARK_OLD).join(MARK_NEU)
     .split(CATCH_OLD).join(CATCH_NEU);

/* ══ فحوص بعدية ══ */
const after = [];
const blank = m => m.replace(/[^\n]/g, ' ');
const bare = s.replace(/\/\*[\s\S]*?\*\//g, blank)
              .replace(/(^|[^:"'`\\])\/\/[^\n]*/g, (m, p) => p + blank(m.slice(p.length)));
{
  const at = bare.indexOf('async function submitOrder');
  const fn = bare.slice(at, bare.indexOf('\nwindow.submitOrder', at));
  if (/if \(!receipt\)/.test(fn)) after.push('استعمال receipt خارج نطاقه لسه موجود');
  if (!/deliveries\.forEach\(d => \{ if \(!d\.fromReceipt\) saveReceiver\(d\); \}\);/.test(fn))
    after.push('اللفّة الجديدة مش موجودة');
  if ((fn.match(/orderCreated/g) || []).length !== 3) after.push('عدد ذكر orderCreated مش ٣');
  const iDecl = fn.indexOf('let orderCreated'), iMark = fn.indexOf('orderCreated = true'), iCatch = fn.indexOf('if (orderCreated)');
  if (!(iDecl > -1 && iDecl < iMark && iMark < iCatch)) after.push('ترتيب العلامة غلط');
  /* العلامة لازم تكون **بعد** الـPOST مش قبله */
  const iPost = fn.indexOf('api.post("/api/customer/orders"');
  if (!(iPost > -1 && iPost < iMark)) after.push('العلامة قبل الـPOST — هتكدب على فشل حقيقي');
  if (!/toast\("تعذّر إرسال الطلب: "/.test(fn)) after.push('رسالة الفشل الحقيقي اختفت');
}
{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, bad = 0;
  while ((m = re.exec(s)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
    const tmp = path.join(os.tmpdir(), 'csl-' + process.pid + '-' + i + '.mjs');
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
console.log('✓ اتكتب customer.html — الإرسال مابقاش يكدب، والأوردر المكرر اتقفل');
