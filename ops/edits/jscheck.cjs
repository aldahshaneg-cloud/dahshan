/* فحص تركيب الجافاسكربت جوه صفحات HTML.
 *
 * ═══ ثغرة اتصلّحت 2026-08-30 ═══
 * النسخة القديمة كانت **بتتخطّى** أي كتلة فيها `import`/`export` بحجة إن
 * `vm.Script` مابيقراش وحدات ES. النتيجة: السكربت الرئيسي في
 * `customer.html` — **٣٥٤٠ سطر** — مكانش بيتفحص خالص، والبوابة كانت
 * بتقول «✅ التركيب سليم» وهي مش شايفاه.
 *
 * وده مش نظري: اتكسر الملف فعلًا بباك-تيك جوه تعليق داخل template literal،
 * والبوابة عدّته، والصفحة اترفعت على الإنتاج **معطّلة**.
 *
 * الإصلاح: كتلة الوحدة بتتكتب في ملف `.mjs` مؤقت وبيتفحص بـ`node --check`
 * — ده المحلّل الحقيقي بتاع نود، بيفهم import/export وبيمسك أي خطأ تاني.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const vm = require('vm');
const { execFileSync } = require('child_process');

let bad = 0;

/** فحص وحدة ES بمحلّل نود الحقيقي */
function checkModule(code, label) {
  const tmp = path.join(os.tmpdir(), 'jscheck-' + process.pid + '-' + Math.abs(hash(label)) + '.mjs');
  try {
    fs.writeFileSync(tmp, code);
    execFileSync(process.execPath, ['--check', tmp], { stdio: 'pipe' });
    return null;
  } catch (e) {
    const out = (e.stderr ? e.stderr.toString() : '') || e.message;
    /* بنقص أول سطر مفيد من رسالة نود */
    const m = out.match(/SyntaxError:.*/) || out.match(/^.*Error.*$/m);
    return (m ? m[0] : out.split('\n')[0]).trim();
  } finally {
    try { fs.unlinkSync(tmp); } catch (e) {}
  }
}
function hash(s) { let h = 0; for (const c of s) h = (h * 31 + c.charCodeAt(0)) | 0; return h; }

for (const f of process.argv.slice(2)) {
  const src = fs.readFileSync(f, 'utf8');
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, modules = 0;
  while ((m = re.exec(src)) !== null) {
    const attrs = m[1] || '';
    const body = m[2] || '';
    i++;
    if (/\ssrc\s*=/i.test(attrs)) continue;
    const t = /type\s*=\s*["']([^"']+)["']/i.exec(attrs);
    if (t && !/^(text\/javascript|application\/javascript|module)$/i.test(t[1].trim())) continue;
    if (!body.trim()) continue;

    const line = src.slice(0, m.index).split('\n').length;
    const isModule = /^\s*(import|export)[\s{*]/m.test(body) || /type\s*=\s*["']module["']/i.test(attrs);

    let err = null;
    if (isModule) {
      modules++;
      err = checkModule(body, f + ':' + i);
    } else {
      try { new vm.Script(body, { filename: f + ':block' + i }); }
      catch (e) { err = e.message; }
    }
    if (err) {
      bad++;
      console.log('✗ ' + f + ' — كتلة ' + i + ' (سطر ~' + line + '): ' + err);
    }
  }
  console.log('· ' + f + ' — ' + i + ' كتلة' + (modules ? ' (' + modules + ' وحدة ES اتفحصت بـnode --check)' : ''));
}
console.log(bad ? '\n⛔ ' + bad + ' خطأ تركيب' : '\n✅ التركيب سليم');
process.exit(bad ? 1 : 0);
