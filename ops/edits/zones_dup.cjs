/* تكويد المناطق بالجملة: منطقة متكوّدة قبل كده ماتقتلش الدفعة — الواجهة.
 *
 * ═══ البلاغ (صاحب النظام، 2026-09-01 — أول يوم شغل فعلي) ═══
 * «المدير يحدد مثلا 20 منطقة ولكن لا يكوّد إلا 5 ويقول خطأ في قاعدة البيانات».
 *
 * ═══ السبب الجذري ═══
 * `public/tiar.html` جوه `window.addZone`:
 *     for (const z of zones) await api.post("/api/zones", z);
 * حلقة متتابعة **جوه try واحد بره الحلقة**. أول منطقة بترمي بتوقف الحلقة،
 * فالباقي مابيتبعتش أصلًا. واللي اتبعت قبلها بينجح ويتحفظ فعلًا (كل POST
 * معاملة مستقلة) — فالمدير بيشوف «خطأ» وهو فاكر إن مافيش حاجة اتحفظت،
 * والحقيقة إن جزء اتحفظ. ده بالظبط «٢٠ ← ٥».
 *
 * الرمي كمان بيتخطّى `reset()` و`closeModal()` تحته، فالفورم بيفضل مفتوح
 * على اختيارات نصّها اتحفظ — لو المدير دوس «حفظ» تاني، اللي نجح بيرمي
 * تكرار جديد. (١٦ خطأ تكرار في سجل الإنتاج النهاردة.)
 *
 * ═══ ليه القيد نفسه صح — وليه مابنعملش UPDATE ═══
 * `uq_zones_area_branch (area_name, delivery_branch_id)`: كل منطقة ليها صف
 * واحد لكل فرع توصيل (٣ فروع = ٣ صفوف)، و`source_branch_id` بيسجّل مين ضاف
 * الصف بس — مش جزء من الهوية. (بيانات الإنتاج: «منشية البدوي» ٣ صفوف،
 * delivery 53/54/55 وكلهم source=55.)
 * فالمنطقة المتكرّرة **لازم** تترفض. الغلط كان في إن الرفض بيقتل الباقي.
 * ومابنعملش UPDATE للسعر: الدوس بصمت على سعر موجود = فلوس غلط على العميل.
 * بنتخطّاها ونبلّغ، والمدير يعدّل السعر صراحةً من زرار التعديل لو عايز.
 *
 * ═══ جانب السيرفر ═══
 * اتصلّح بالفعل (EntitiesController::zonesCreate بيمسك 1062 ويرمي
 * ApiException برسالة «... موجودة قبل كده في نفس فرع التوصيل ...»).
 * السكريبت ده بيكمّل الناحية التانية بس.
 *
 * كشف التكرار في العميل مزدوج عن قصد:
 *   • `e.status === 409` — لو حد ضبط الحالة بعدين (دلوقتي بترجع 400).
 *   • مطابقة نص الرسالة — الشغّال دلوقتي.
 * ولو الاتنين فشلوا، المنطقة بتتحسب «فشلت» والدفعة **بتكمّل** برضه —
 * السلوك الحرج مايعتمدش على دقة الكشف.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILE = path.resolve(__dirname, '../../public/tiar.html');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');
const ORIGINAL = s;

const OLD =
`        } else {
          for (const z of zones) await api.post("/api/zones", z);
          showToast(zones.length > 1 ? \`تمّ إضافة \${ esc(zones.length) } مناطق بنجاح ✓\` : "تمّ إضافة المنطقة بنجاح ✓", "success");
        }
        document.getElementById("addZoneForm").reset();
        _resetZoneFormUI();
        resetEditState();
        closeModal("zone");
      } catch(e) { showToast("خطأ: " + e.message, "error"); }`;

const NEU =
`        } else {
          /* 🔴 كل منطقة لوحدها. كان \`for (…) await api.post(…)\` جوه الـtry
             اللي بره — أول منطقة متكوّدة قبل كده كانت بتوقف الحلقة فالباقي
             مايتبعتش، واللي اتبعت قبلها بتفضل محفوظة. المدير يختار ٢٠
             وماتتكوّدش غير ٥ ويشوف «خطأ» فيفتكر إن مافيش حاجة اتحفظت.
             (بلاغ صاحب النظام 2026-09-01، أول يوم شغل.)
             المنطقة المتكرّرة **لازم** تترفض — القيد صح — بس مالهاش حق
             توقف الباقي. ومابنحدّثش سعر الموجودة: الدوس بصمت على سعر
             متحطّ = فلوس غلط على العميل. */
          const done = [], dup = [], failed = [];
          for (const z of zones) {
            try { await api.post("/api/zones", z); done.push(z.areaName); }
            catch (e) {
              const m = (e && e.message) || "";
              /* كشف مزدوج: الحالة لو اتضبطت يومًا، والنص هو الشغّال دلوقتي.
                 لو الاتنين فشلوا بتتحسب «فشلت» والدفعة بتكمّل برضه. */
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
            /* فشل حقيقي (مش تكرار) — نسيب المودال مفتوح عشان يقدر يعيد */
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

/* `zoneBatchDone` لازم يتعرّف قبل الـtry عشان فرع التعديل (PUT) يفضل بيقفل.
   «جاري الحفظ…» لوحدها متكررة ٩ مرات في الملف، فالمرساة مثبّتة بسطر
   `submitZoneBtn` اللي فوقها مباشرة — ده الوحيد الخاص بمودال المناطق. */
const OLD_TRY = `      const btn = document.getElementById("submitZoneBtn");
      btn.disabled = true; btn.textContent = "جاري الحفظ…";
      try {`;
const NEU_TRY = `      const btn = document.getElementById("submitZoneBtn");
      btn.disabled = true; btn.textContent = "جاري الحفظ…";
      /* بيتحدد جوه فرع الإضافة: نجاح جزئي بيقفل، وفشل حقيقي بيسيب المودال
         مفتوح. فرع التعديل (PUT) بيفضل بيقفل زي ما كان. */
      let zoneBatchDone = true;
      try {`;

/* ══ فحوص قبلية — مافيش بايت بيتكتب قبل ما كلهم يعدّوا ══ */
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

/* ══ فحوص بعدية ══ */
const after = [];
if (/for \(const z of zones\) await api\.post/.test(s)) after.push('الحلقة القديمة لسه موجودة');
if ((s.match(/zoneBatchDone/g) || []).length !== 4) after.push('عدد ذكر zoneBatchDone مش ٤');
if (!/try \{ await api\.post\("\/api\/zones", z\); done\.push/.test(s)) after.push('الحلقة الجديدة مش مظبوطة');

/* التركيب: كل كتل الجافاسكربت لازم تعدّي على محلّل نود */
{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, bad = 0;
  while ((m = re.exec(s)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
    const tmp = path.join(os.tmpdir(), 'zdup-' + process.pid + '-' + i + '.mjs');
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
console.log('✓ اتكتب tiar.html — الدفعة بتكمّل، والملخّص بيقول اتكوّد كام');
console.log('  ' + ORIGINAL.split('\n').length + ' سطر → ' + s.split('\n').length + ' سطر');
