/* رسالة «المنطقة متكوّدة قبل كده» ترجع بحالة 409 بدل 400.
 *
 * ═══ ليه ═══
 * `api.js:75` بيحط `err.status = res.status` على الخطأ، والواجهة في
 * tiar.html و callcenter.html عندها فرع جاهز:
 *     if ((e && e.status === 409) || /موجودة قبل كده|Duplicate entry|1062/.test(m))
 * الفرع الأول **معطّل دلوقتي** لأن `ApiException` افتراضها 400، فالكشف
 * شغّال بمطابقة النص العربي بس.
 *
 * الخطر من الاعتماد على النص: أي حد يعيد صياغة الرسالة (وهي رسالة
 * مستخدم نهائي، فالصياغة بتتغيّر عادي) → التكرارات تتصنّف «فشلت» →
 * `zoneBatchDone = false` → المودال يفضل مفتوح والتوست يقول «فشلت» —
 * يعني **نص الشكوى الأصلية يرجع بصمت** من غير ما أي اختبار يقع.
 * الحالة بنيوية والنص احتياطي = الكشف مايعتمدش على صياغة.
 *
 * ═══ ليه 409 آمنة ═══
 *   • الرد أصلاً `{ok:false, error}` — العميل بيقرا `data.error` زي ما هو،
 *     والحالة كانت 400 وبقت 409؛ مافيش مستهلك تاني للمسار.
 *   • `api.js` مالوش معالجة خاصة غير 401 (جلسة) و403 (بولّر) — 409 عادية.
 *   • 409 < 500 فبتفضل مستبعَدة من `dontReportWhen` (bootstrap/app.php:134)
 *     يعني مافيش ضجيج في اللوج زي ما كان بيحصل قبل مصيدة الـ1062.
 */
const fs = require('fs');
const path = require('path');

const FILE = path.resolve(__dirname, '../../app/Http/Controllers/Api/EntitiesController.php');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');

const OLD = `                throw new ApiException('«' . $areaName . '» موجودة قبل كده في نفس فرع التوصيل — غيّر الاسم أو اختار فرع تاني');`;
const NEU = `                /* 409 مش 400: الواجهة بتفرّق التكرار عن أي فشل تاني
                   بالحالة (e.status === 409) مش بمطابقة نص الرسالة —
                   عشان إعادة صياغة الرسالة ماترجّعش الباج بصمت. */
                throw new ApiException('«' . $areaName . '» موجودة قبل كده في نفس فرع التوصيل — غيّر الاسم أو اختار فرع تاني', 409);`;

const problems = [];
const n = s.split(OLD).length - 1;
if (n !== 1) problems.push(`الرمية المستهدفة: متوقّع ١ لقى ${n}`);
if (s.includes(', 409)')) problems.push('409 موجودة قبل كده — السكريبت اتشغّل مرتين؟');
if (problems.length) {
  console.log('⛔ مافيش بايت اتكتب:');
  for (const p of problems) console.log('   ✗ ' + p);
  process.exit(1);
}

s = s.split(OLD).join(NEU);

/* الرمية لازم تفضل جوه zonesCreate وجنب فحص 1062 */
const at = s.indexOf('function zonesCreate');
const fn = s.slice(at, s.indexOf('function zonesUpdate', at));
const after = [];
if (!/=== 1062/.test(fn)) after.push('فحص 1062 اختفى');
if (!/موجودة قبل كده[^']*', 409\)/.test(fn)) after.push('الحالة 409 مش في مكانها');
if (after.length) {
  console.log('⛔ فحوص بعدية وقعت — مافيش بايت اتكتب:');
  for (const a of after) console.log('   ✗ ' + a);
  process.exit(1);
}

if (process.env.DRY) { console.log('🟦 DRY — كل الفحوص عدّت، مافيش بايت اتكتب.'); process.exit(0); }
fs.writeFileSync(FILE, WAS_CRLF ? s.replace(/\n/g, '\r\n') : s);
console.log('✓ اتكتب EntitiesController.php — التكرار بيرجع 409');
