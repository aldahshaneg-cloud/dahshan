/* تشديد ops/test_zone_batch.cjs — تلات نقاط ضعف اتكشفت في تدقيق موازي.
 *
 * (١) `const FILE = 'public/tiar.html'` وبس — الحارس كان **أعمى** عند
 *     `public/callcenter.html` اللي فيه نفس الحلقة بالظبط.
 * (٢) فحص القسم ٦ كان regex بفجوة ثابتة `[\s\S]{0,120}` ومتوقّع payload
 *     الـ`z` المختصر — بيكسر لو حد ضاف سطر جوه الحلقة، وبيبقى أحمر كذبًا
 *     على callcenter (payload مفكوك). البديل: قص جسم الحلقة بعدّ الأقواس
 *     وفحص بنيوي (try قبل post · فيه catch · مافيش throw).
 * (٣) 🔴 القسم ٧ كان بيقرا الـPHP **خام من غير تمشيط تعليقات**. وبعد ما
 *     اتحط تعليق فوق `zonesCreate` فيه كلمة `1062` ونص «موجودة قبل كده»،
 *     الحارس بقى ينفع يبقى أخضر على **تعليقه هو** والكود متشال. ده نفس
 *     الفخ اللي المشروع اتلسع منه قبل كده.
 *
 * وبيضيف تأكيدين جداد: الحالة 409 على السيرفر، و`e.status === 409` في كل
 * واجهة — عشان الكشف يفضل بنيوي لو صياغة الرسالة اتغيّرت.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILE = path.resolve(__dirname, '../../ops/test_zone_batch.cjs');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');

const EDITS = [];

/* ── (١) ملف واحد → قايمة ملفات ── */
EDITS.push({
  label: 'FILES بدل FILE',
  old: `const FILE = 'public/tiar.html';
const src = fs.readFileSync(FILE, 'utf8');`,
  neu: `/* الفحوص السلوكية (١-٥) بتشتغل على tiar.html — الـharness متظبّط عليه.
   الفحوص البنيوية (٦) بتشتغل على **الاتنين**: نفس الحلقة موجودة في
   callcenter.html، وشاشة المناطق هناك بيوصلها الأدمن (settings في PAGES،
   والمودال في الـDOM، وroutes/api.php:205 بيسمح لـadmin). */
const FILES = ['public/tiar.html', 'public/callcenter.html'];
const FILE = FILES[0];
const src = fs.readFileSync(FILE, 'utf8');`,
});

/* ── (٢) قسم ٦: فحص بنيوي على كل الملفات ── */
EDITS.push({
  label: 'قسم ٦ بنيوي وعلى الملفين',
  old: `console.log('\\n══ 6) الحلقة القديمة مارجعتش ══');
{
  const strip = src.replace(/\\/\\*[\\s\\S]*?\\*\\//g, ' ').replace(/(^|[^:"'\`\\\\])\\/\\/[^\\n]*/g, '$1 ');
  ok('مافيش \`for (…) await api.post("/api/zones"…)\` عارية',
     !/for \\([^)]*\\) await api\\.post\\("\\/api\\/zones"/.test(strip));
  ok('الحلقة الجديدة فيها try لكل عنصر',
     /for \\(const z of zones\\) \\{[\\s\\S]{0,120}try \\{ await api\\.post\\("\\/api\\/zones", z\\)/.test(strip));
}`,
  neu: `console.log('\\n══ 6) 🔴 الحلقة القديمة مارجعتش — في كل واجهة ══');
{
  /* التمشيط بيستبدل التعليق بمسافات بنفس الطول (الأطوال بتفضل مظبوطة).
     السترنجات **مابتتمشّطش** عن قصد: "/api/zones" نفسها سترنج والفحص
     محتاجها. */
  const strip = t => t
    .replace(/\\/\\*[\\s\\S]*?\\*\\//g, m => ' '.repeat(m.length))
    .replace(/(^|[^:"'\`\\\\])\\/\\/[^\\n]*/g, (m, p) => p + ' '.repeat(m.length - p.length));

  for (const f of FILES) {
    const code = strip(fs.readFileSync(f, 'utf8'));
    const nm = f.replace('public/', '');

    ok(nm + ': مافيش حلقة عارية',
       !/for \\([^)]*\\)\\s*await api\\.post\\(\\s*["']\\/api\\/zones["']/.test(code));

    /* فحص بنيوي بدل regex بفجوة ثابتة: بنقص جسم الحلقة بعدّ الأقواس. */
    const at = code.search(/for \\(const z of zones\\)\\s*\\{/);
    ok(nm + ': فيه حلقة مناطق', at > -1);
    if (at > -1) {
      const open = code.indexOf('{', at);
      let d = 0, end = -1;
      for (let j = open; j < code.length; j++) {
        if (code[j] === '{') d++;
        else if (code[j] === '}') { d--; if (!d) { end = j; break; } }
      }
      const body = end > -1 ? code.slice(open, end) : '';
      const iTry = body.indexOf('try'), iPost = body.indexOf('api.post');
      ok(nm + ': 🔴 try جوه الحلقة وقبل الـpost', iTry > -1 && iPost > -1 && iTry < iPost,
         'try@' + iTry + ' post@' + iPost);
      ok(nm + ': وفيه catch جوه الحلقة', /catch\\s*\\(/.test(body));
      ok(nm + ': والـcatch مابيعيدش الرمي', !/\\bthrow\\b/.test(body),
         'throw جوه الحلقة = الدفعة هتقف تاني');
    }

    /* الكشف لازم يفضل بنيوي (الحالة) مش على صياغة الرسالة بس */
    ok(nm + ': بيفرّق التكرار بحالة 409', /e\\.status === 409/.test(code));
  }
}`,
});

/* ── (٣) قسم ٧: تمشيط تعليقات PHP + تأكيدات جديدة ── */
EDITS.push({
  label: 'قسم ٧ يمشّط تعليقات PHP',
  old: `  const php = fs.readFileSync('app/Http/Controllers/Api/EntitiesController.php', 'utf8');
  const at = php.indexOf('function zonesCreate');
  const fn = php.slice(at, php.indexOf('function zonesUpdate', at));
  ok('بيمسك QueryException', /catch \\(QueryException \\$e\\)/.test(fn));
  ok('🔴 بيفحص 1062 تحديدًا', /1062/.test(fn));
  ok('وبيرمي رسالة عربية مفهومة', /ApiException\\('/.test(fn) && /موجودة قبل كده/.test(fn));
  ok('ومابيعملش UPDATE للسعر بصمت', !/ON DUPLICATE KEY UPDATE/i.test(fn),
     'دوس على السعر = فلوس غلط على العميل');`,
  neu: `  /* 🔴 التمشيط إجباري: التعليق اللي فوق zonesCreate فيه كلمة «1062»
     ونص «موجودة قبل كده» — فالفحص على الخام كان ممكن يبقى أخضر والكود
     نفسه متشال. (نفس فخ «الحارس بيمسك تعليقه هو».) */
  const phpRaw = fs.readFileSync('app/Http/Controllers/Api/EntitiesController.php', 'utf8');
  const php = phpRaw
    .replace(/\\/\\*[\\s\\S]*?\\*\\//g, m => ' '.repeat(m.length))
    .replace(/(^|[^:"'])\\/\\/[^\\n]*/g, (m, p) => p + ' '.repeat(m.length - p.length));
  const at = php.indexOf('function zonesCreate');
  const fn = php.slice(at, php.indexOf('function zonesUpdate', at));
  ok('بيمسك QueryException', /catch \\(QueryException \\$e\\)/.test(fn));
  ok('🔴 بيفحص 1062 تحديدًا (بعد تمشيط التعليقات)', /1062/.test(fn));
  /* getCode() بترجّع '23000' (حالة SQLSTATE) مش 1062 — لو حد بدّلها
     الفحص بيبقى دايمًا false والتكرار يرجع 500 تاني. */
  ok('🔴 بيقرا errorInfo مش getCode', /errorInfo/.test(fn) && !/getCode\\(\\)/.test(fn));
  ok('وبيرمي رسالة عربية مفهومة', /ApiException\\('/.test(fn) && /موجودة قبل كده/.test(fn));
  ok('🔴 بحالة 409 (كشف بنيوي للواجهة)', /ApiException\\([^;]*,\\s*409\\)/.test(fn),
     'من غيرها الواجهة بتعتمد على مطابقة نص الرسالة بس');
  ok('ومابيعملش UPDATE للسعر بصمت', !/ON DUPLICATE KEY UPDATE/i.test(fn),
     'دوس على السعر = فلوس غلط على العميل');`,
});

/* ══ فحص قبل أي كتابة ══ */
let bad = 0;
for (const e of EDITS) {
  const n = s.split(e.old).length - 1;
  if (n !== 1) { bad++; console.log('✗ «' + e.label + '»: متوقّع ١ لقى ' + n); }
}
if (bad) { console.log('\n⛔ مافيش بايت اتكتب.'); process.exit(1); }
for (const e of EDITS) { s = s.split(e.old).join(e.neu); console.log('  ✓ ' + e.label); }

/* ══ الملف لازم يفضل جافاسكربت سليم ══ */
{
  const tmp = path.join(os.tmpdir(), 'zgh-' + process.pid + '.cjs');
  fs.writeFileSync(tmp, s);
  try { execFileSync(process.execPath, ['--check', tmp], { stdio: 'pipe' }); console.log('  ✓ التركيب سليم'); }
  catch (e) {
    console.log('  ✗ ' + ((e.stderr || '').toString().match(/SyntaxError:.*/) || ['?'])[0]);
    console.log('\n⛔ مافيش بايت اتكتب.'); process.exit(1);
  } finally { try { fs.unlinkSync(tmp); } catch (e2) {} }
}

if (process.env.DRY) { console.log('🟦 DRY — مافيش بايت اتكتب.'); process.exit(0); }
fs.writeFileSync(FILE, WAS_CRLF ? s.replace(/\n/g, '\r\n') : s);
console.log('✓ اتكتب test_zone_batch.cjs');
