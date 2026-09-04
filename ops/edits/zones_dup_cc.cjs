/* نفس إصلاح دفعة المناطق — لكن في callcenter.html.
 *
 * ═══ ليه ده مش كود ميت ═══
 * الشاشة مالهاش زرار في السايدبار، بس:
 *   • `settings` موجودة في مصفوفة PAGES (callcenter.html:7407)
 *   • تبويب «🗺️ المناطق» والمودال والزرار كلهم في الـDOM (6870 · 6879 · 7145)
 *   • `routes/api.php:205` بيسمح لـ`admin` على POST /api/zones
 *   • و`AuthController::appsFor` بيدّي للأدمن `callcenter` ضمن تطبيقاته
 * يعني أدمن يفتح callcenter.html ويوصل الشاشة — والـPOSTs بتنجح فعلًا،
 * فالباج حيّ مش كامن.
 *
 * الحلقة هنا نسخة حرفية من اللي في tiar.html (بـpayload مفكوك):
 *     for (const z of zones) await api.post("/api/zones", {...});
 * جوه try واحد بره الحلقة → أول منطقة متكوّدة بتوقف الباقي.
 *
 * الإصلاح نفس الإصلاح المطبّق في tiar.html بالظبط (ops/edits/zones_dup.cjs)
 * عشان الملفين مايتفرّقوش — الاتنين بيتقروا من نفس الحارس.
 *
 * ملحوظة: `handleExcelImport` في نفس الملف (~5226) فيها POST كمان بس هي
 * **سليمة** (try لكل صف) — ممنوع تتلمس.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILE = path.resolve(__dirname, '../../public/callcenter.html');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');

const OLD_TRY = `      const btn = document.getElementById("submitZoneBtn");
      btn.disabled = true; btn.textContent = "جاري الحفظ…";
      try {`;
const NEU_TRY = `      const btn = document.getElementById("submitZoneBtn");
      btn.disabled = true; btn.textContent = "جاري الحفظ…";
      /* بيتحدد جوه فرع الإضافة: نجاح جزئي بيقفل، وفشل حقيقي بيسيب المودال
         مفتوح. فرع التعديل (PUT) بيفضل بيقفل زي ما كان. */
      let zoneBatchDone = true;
      try {`;

const OLD =
`        } else {
          for (const z of zones) await api.post("/api/zones", { areaName: z.areaName, price: z.price, deliveryBranchId: z.deliveryBranchId, sourceBranchId: z.sourceBranchId });
          showToast(zones.length > 1 ? \`تمّ إضافة \${ esc(zones.length) } مناطق بنجاح ✓\` : "تمّ إضافة المنطقة بنجاح ✓", "success");
        }
        document.getElementById("addZoneForm").reset();
        _resetZoneFormUI();
        resetEditState();
        closeModal("zone");
      } catch(e) { showToast("خطأ: " + e.message, "error"); }`;

const NEU =
`        } else {
          /* 🔴 كل منطقة لوحدها — نفس إصلاح tiar.html (2026-09-01).
             كان \`for (…) await api.post(…)\` جوه الـtry اللي بره: أول منطقة
             متكوّدة قبل كده بتوقف الحلقة فالباقي مايتبعتش، واللي اتبعت
             قبلها بتفضل محفوظة — المستخدم يختار ٢٠ ويتكوّد ٥ ويشوف «خطأ».
             المنطقة المتكرّرة لازم تترفض (القيد صح) بس مالهاش حق توقف
             الباقي. ومابنحدّثش سعر الموجودة: دوس صامت على سعر = فلوس غلط. */
          const done = [], dup = [], failed = [];
          for (const z of zones) {
            try {
              await api.post("/api/zones", { areaName: z.areaName, price: z.price, deliveryBranchId: z.deliveryBranchId, sourceBranchId: z.sourceBranchId });
              done.push(z.areaName);
            } catch (e) {
              const m = (e && e.message) || "";
              /* كشف مزدوج: الحالة 409 لو اتضبطت، والنص احتياطي. لو الاتنين
                 فشلوا بتتحسب «فشلت» والدفعة بتكمّل برضه. */
              if ((e && e.status === 409) || /موجودة قبل كده|Duplicate entry|1062/.test(m)) dup.push(z.areaName);
              else failed.push(z.areaName);
            }
          }
          const bits = [];
          if (done.length)   bits.push(\`اتكوّدت \${done.length}\`);
          if (dup.length)    bits.push(\`\${dup.length} متكوّدة قبل كده\`);
          if (failed.length) bits.push(\`\${failed.length} فشلت\`);
          const summary = bits.join(" · ");
          if (failed.length) {
            showToast(\`⚠️ \${summary} — \${failed.slice(0, 3).join("، ")}\${failed.length > 3 ? "…" : ""}\`, "error");
            zoneBatchDone = false;
          } else if (!done.length) {
            showToast(\`كل المناطق المختارة (\${dup.length}) متكوّدة قبل كده لفرع التوصيل ده\`, "error");
            zoneBatchDone = false;
          } else {
            showToast(\`تمّ ✓ \${summary}\`, "success");
          }
        }
        if (zoneBatchDone) {
          document.getElementById("addZoneForm").reset();
          _resetZoneFormUI();
          resetEditState();
          closeModal("zone");
        }
      } catch(e) { showToast("خطأ: " + e.message, "error"); }`;

const problems = [];
for (const [label, txt] of [['كتلة الحفظ', OLD], ['بداية الـtry', OLD_TRY]]) {
  const n = s.split(txt).length - 1;
  if (n !== 1) problems.push(`${label}: متوقّع ١ لقى ${n}`);
}
if (s.includes('zoneBatchDone')) problems.push('الإصلاح متطبّق قبل كده');
if (problems.length) {
  console.log('⛔ مافيش بايت اتكتب:');
  for (const p of problems) console.log('   ✗ ' + p);
  process.exit(1);
}

s = s.split(OLD_TRY).join(NEU_TRY).split(OLD).join(NEU);

const after = [];
if (/for \(const z of zones\) await api\.post/.test(s)) after.push('الحلقة القديمة لسه موجودة');
if ((s.match(/zoneBatchDone/g) || []).length !== 4) after.push('عدد ذكر zoneBatchDone مش ٤');
/* handleExcelImport لازم تفضل زي ما هي */
if (!/handleExcelImport/.test(s)) after.push('handleExcelImport اختفت!');
{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, bad = 0;
  while ((m = re.exec(s)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
    const tmp = path.join(os.tmpdir(), 'zcc-' + process.pid + '-' + i + '.mjs');
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
console.log('✓ اتكتب callcenter.html — نفس إصلاح الدفعة');
