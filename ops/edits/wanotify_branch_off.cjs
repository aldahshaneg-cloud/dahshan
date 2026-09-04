/* إلغاء إجبار واتساب المستلم من تطبيق الفرع — يفضل في الكول سنتر بس.
 *
 * ═══ القرار (صاحب النظام، 2026-09-01) ═══
 * «الغي موضوع الواتساب في الفرع، خليها في الكول سنتر بس».
 * نفس اليوم اللي الميزة اتضافت فيه للاتنين — فده عكس دقيق لتعديل
 * `ops/edits/wanotify_force.cjs` على branch.html **وبس**:
 *   ١) سطر `_createdIds` بيتشال (بيرجع سطر `_created` لوحده)
 *   ٢) النداء وتعليقه بعد `closeModal("order")` بيتشالوا
 *   ٣) كتلة الجافاسكربت كلها (openWaNotifyForOrder + _waNotifyRender
 *      + _waEsc + _waIntl) بتتشال
 * callcenter.html مايتلمسش — الحارس بيتأكد من ده صراحةً.
 *
 * صفوف `order_notifications` نفسها بتفضل بتتولد لكل أوردر زي ما هي —
 * رسايل أوردرات الفرع بتبان في شاشة «رسايل العملاء» بالإدارة وبتتبعت
 * من هناك. اللي اتشال هو الإجبار في واجهة الفرع بس.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILE = path.resolve(__dirname, '../../public/branch.html');
const CC   = path.resolve(__dirname, '../../public/callcenter.html');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');

/* ══ العكس بالحرف — بنقص من نص الملف الحالي مش من نسخة محفوظة ══ */

/* (١) سطر الـids */
const IDS_OLD = `        const _created = (res.orders || (res.order ? [res.order] : [])).map(o => o?.orderNum).filter(Boolean);
        const _createdIds = (res.orders || (res.order ? [res.order] : [])).map(o => o?.id).filter(Boolean);`;
const IDS_NEU = `        const _created = (res.orders || (res.order ? [res.order] : [])).map(o => o?.orderNum).filter(Boolean);`;

/* (٢) + (٣): من النداء لحد آخر كتلة الواتساب.
   بنحدد الحدود بالبحث الفعلي مش بنص ثابت طويل — أضمن ضد فروق المسافات. */
const CALL_START = `        /* 🔴 إجبار إرسال الواتساب (قرار صاحب النظام 2026-09-01)`;
const CALL_END   = `        openWaNotifyForOrder(_createdIds);\n`;
const BLOCK_START = `    /* ══ إجبار إرسال رسالة الواتساب للمستلم بعد تسجيل الأوردر ══`;

/* ══ فحوص قبلية ══ */
const problems = [];
if (s.split(IDS_OLD).length - 1 !== 1) problems.push('سطرا _created/_createdIds مش موجودين مرة واحدة');
if (s.split(CALL_START).length - 1 !== 1) problems.push('تعليق النداء مش موجود مرة واحدة');
if (s.split(CALL_END).length - 1 !== 1) problems.push('سطر النداء مش موجود مرة واحدة');
if (s.split(BLOCK_START).length - 1 !== 1) problems.push('بداية الكتلة مش موجودة مرة واحدة');
if (problems.length) {
  console.log('⛔ مافيش بايت اتكتب:');
  for (const p of problems) console.log('   ✗ ' + p);
  process.exit(1);
}

/* شيل النداء وتعليقه: من بداية التعليق لنهاية سطر النداء */
{
  const a = s.indexOf(CALL_START);
  const b = s.indexOf(CALL_END, a) + CALL_END.length;
  s = s.slice(0, a) + s.slice(b);
}

/* شيل كتلة الجافاسكربت: من بداية التعليق لحد قفلة `_waNotifyRender`.
   القفلة = آخر `\n    }\n` بعد آخر data-wa-skip listener. بنحدد النهاية
   بعدّ الأقواس من أول `function _waNotifyRender` — أدق من مطابقة نص. */
{
  const a = s.indexOf(BLOCK_START);
  const fnAt = s.indexOf('function _waNotifyRender', a);
  const open = s.indexOf('{', fnAt);
  let d = 0, end = -1;
  for (let j = open; j < s.length; j++) {
    const c = s[j];
    if (c === '`') { j++; let td = 0; while (j < s.length) { if (s[j] === '\\') { j += 2; continue; }
        if (s[j] === '`' && td === 0) break; if (s[j] === '$' && s[j+1] === '{') td++; if (s[j] === '}' && td > 0) td--; j++; } continue; }
    if (c === '"' || c === "'") { const q = c; j++; while (j < s.length && s[j] !== q) { if (s[j] === '\\') j++; j++; } continue; }
    if (c === '{') d++;
    else if (c === '}') { d--; if (!d) { end = j + 1; break; } }
  }
  if (end < 0) { console.log('⛔ ماقدرتش أقفل _waNotifyRender — مافيش كتابة'); process.exit(1); }
  while (s[end] === '\n') end++;
  s = s.slice(0, a - '\n'.length >= 0 && s[a-1] === '\n' ? a : a) + s.slice(end);
  /* شيل السطر الفاضي المتبقي قبل البلوك لو فيه */
  s = s.replace(/\n\n\n+    \/\* ═/g, '\n\n    /* ═');
}

/* (١) أخيرًا — عشان مايتلخبطش مع الحذف فوق */
s = s.split(IDS_OLD).join(IDS_NEU);

/* ══ فحوص بعدية ══ */
const after = [];
for (const n of ['openWaNotifyForOrder', '_waNotifyRender', '_waEsc', '_waIntl', '_createdIds', '_waNotifyBox']) {
  if (s.includes(n)) after.push('لسه فيه ' + n);
}
/* addOrder لازم يرجع لشكله الأصلي بالظبط */
if (!s.includes(`        closeModal("order");
      } catch(e) { showToast("خطأ أثناء الحفظ: " + e.message, "error"); }
      finally { btn.disabled = false; btn.textContent = "💾 حفظ الطلب"; }
    };`)) after.push('ذيل addOrder مش راجع لأصله');
/* callcenter لازم يفضل فيه الميزة */
const cc = fs.readFileSync(CC, 'utf8');
if (!cc.includes('openWaNotifyForOrder')) after.push('🔴 callcenter.html فقد الميزة — ممنوع!');
{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, bad = 0;
  while ((m = re.exec(s)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
    const tmp = path.join(os.tmpdir(), 'wboff-' + process.pid + '-' + i + '.mjs');
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

if (process.env.DRY) { console.log('🟦 DRY — كل الفحوص عدّت، مافيش كتابة.'); process.exit(0); }
fs.writeFileSync(FILE, WAS_CRLF ? s.replace(/\n/g, '\r\n') : s);
console.log('✓ اتكتب branch.html — الواتساب اتشال من الفرع، فاضل في الكول سنتر');
