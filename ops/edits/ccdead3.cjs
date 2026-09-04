/* آخر بقايا: كود حيّ تركيبيًا لكنه مالوش أثر — الماسح مابيشوفهوش لأنه
 * مش «تعريف بلا نداء».
 *
 * (١) `shiftBtn` و`leaveBtn` في `renderPilotsTable`: الأزرار اتشالت في
 *     مسح 2026-08-30 وسابت الشروط مفرّغة —
 *         const shiftBtn = canOpenShift ? `` : "";
 *     يعني حساب بيتعمل لكل طيار في كل رسمة عشان يطلع نص فاضي، وقارئ
 *     الكود بيفتكر إن فيه زرار مربوط بشرط.
 *
 * (٢) مستمع كليك على `openShiftPilotDropdown` — الدروب-داون ده كان في
 *     مودال «فتح وردية» المتشال. المستمع بيشتغل مع **كل كليكة** في
 *     الصفحة عشان يدوّر على عنصرين مش موجودين.
 *
 * الاتنين مش بيغيّروا سلوك — بس بيغشّوا القارئ والتدقيق.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const FILE = path.resolve(__dirname, '../../public/callcenter.html');
const raw = fs.readFileSync(FILE, 'utf8');
const WAS_CRLF = raw.includes('\r\n');
let s = raw.replace(/\r\n/g, '\n');
const ORIGINAL = s;

const EDITS = [
  {
    label: 'shiftBtn/leaveBtn المفرّغين',
    old:
`        const canOpenShift = !p.pilotStatus && p.assignedBranchId;
        const shiftBtn = canOpenShift
          ? \`\`
          : "";
        // زر فرض إذن/إيقاف على الطيار (الإدارة توقفه بدل ما هو يطلب) — ولو
        // هو أصلاً في إذن يبقى الزر لإنهاء الإذن وإرجاعه للعمل
        const leaveBtn = p.pilotStatus === "onLeave"
          ? \`\`
          : (p.pilotStatus === "waiting" || p.pilotStatus === "delivering")
          ? \`\`
          : "";
`,
    new:
`        /* عمود الإجراء فيه «تعديل» بس. «فتح وردية» و«فرض إذن» اتشالوا
           2026-08-30 — السيرفر بيرفضهم من دور الكول سنتر. سابوا وراهم
           \`shiftBtn\`/\`leaveBtn\` بشروط بترجّع نص فاضي دايمًا، واتشالوا
           2026-08-31. ممنوع يرجعوا من غير ما الصلاحية تتفتح على السيرفر. */
`,
  },
  {
    label: 'الاستيفاء المفرّغ في صف الطيار',
    old: '<td>${shiftBtn}<button class="edit-btn" onclick="openEditPilot(\'${ escJs(p.id) }\')">تعديل</button>${leaveBtn}</td>',
    new: '<td><button class="edit-btn" onclick="openEditPilot(\'${ escJs(p.id) }\')">تعديل</button></td>',
  },
  {
    label: 'مستمع دروب-داون «فتح وردية»',
    old:
`            document.addEventListener("click", function(e) {
      const drop = document.getElementById("openShiftPilotDropdown");
      const inp  = document.getElementById("openShiftPilotSearch");
      if (drop && inp && !drop.contains(e.target) && e.target !== inp) drop.style.display = "none";
    });

`,
    new: '',
  },
];

/* ── فحص قبل أي كتابة ── */
let bad = 0;
for (const e of EDITS) {
  const n = s.split(e.old).length - 1;
  if (n !== 1) { bad++; console.log('✗ «' + e.label + '»: متوقّع ١ لقى ' + n); }
}
if (bad) { console.log('\n⛔ مافيش بايت اتكتب.'); process.exit(1); }

for (const e of EDITS) { s = s.split(e.old).join(e.new); console.log('  ✓ ' + e.label); }

/* ── فحوص بعدية ── */
const after = [];
for (const n of ['shiftBtn', 'leaveBtn', 'canOpenShift', 'openShiftPilotDropdown', 'openShiftPilotSearch']) {
  if (new RegExp('\\b' + n + '\\b').test(s.replace(/\/\*[\s\S]*?\*\//g, ' '))) after.push('لسه فيه ' + n);
}
console.log((after.length ? '  ✗' : '  ✓') + ' صفر أثر للأسماء المتشالة');
for (const a of after) console.log('     ' + a);

{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, broken = 0;
  while ((m = re.exec(s)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
    const tmp = path.join(os.tmpdir(), 'ccdead3-' + process.pid + '-' + i + '.mjs');
    try { fs.writeFileSync(tmp, m[2]); execFileSync(process.execPath, ['--check', tmp], { stdio: 'pipe' }); }
    catch (err) { broken++; console.log('  ✗ كتلة ' + i + ': ' + ((err.stderr || '').toString().match(/SyntaxError:.*/) || ['?'])[0]); }
    finally { try { fs.unlinkSync(tmp); } catch (e) {} }
  }
  console.log((broken ? '  ✗' : '  ✓') + ' كل كتل الجافاسكربت سليمة (' + i + ' كتلة)');
  if (broken) after.push('كتل مكسورة');
}

/* صف الطيار لازم يفضل فيه زرار «تعديل» */
{
  const ok = s.includes('onclick="openEditPilot(\'${ escJs(p.id) }\')">تعديل</button></td>');
  console.log((ok ? '  ✓' : '  ✗') + ' زرار «تعديل» لسه في صف الطيار');
  if (!ok) after.push('زرار تعديل اختفى');
}

if (after.length) { console.log('\n⛔ فحوص بعدية وقعت — مافيش بايت اتكتب.'); process.exit(1); }
if (process.env.DRY) { console.log('\n🟦 DRY — مافيش بايت اتكتب.'); process.exit(0); }

fs.writeFileSync(FILE, WAS_CRLF ? s.replace(/\n/g, '\r\n') : s);
console.log('\n✓ اتكتب ' + path.basename(FILE));
console.log('  ' + ORIGINAL.split('\n').length + ' سطر → ' + s.split('\n').length + ' سطر');
