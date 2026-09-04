/* زرار «👤 بياناتي» في بيانات المستلم — تطبيق العميل.
 *
 * ═══ الطلب (صاحب النظام، 2026-09-01) ═══
 * «اعمل زرار في بيانات المستلم يضع بيانات صاحب التطبيق، لأنه ممكن يكون هو
 *  المستلم».
 *
 * ═══ ليه ده اتساب ناقص ═══
 * بلوك **المُرسِل** عنده الشيب ده بالفعل (renderSavedPicks، شيب `data-me`)
 * وبيملّي: displayNameAr · phone1 · address · defaultZoneId · lat/lng.
 * بلوك **المستلم** عنده «مستلمون سابقون» بس. والتعليق اللي فوق شيب المُرسِل
 * بيقول إن الشرط القديم `S.addresses.length` كان بيخفيه عن «أكتر واحد
 * محتاجه» — ونفس الغلط بالظبط موجود في المستلم:
 *     if (!picks.length) { box.innerHTML = ""; return; }
 * يعني العميل اللي لسه مابعتش لحد قبل كده — وهو أكتر واحد محتاج يملا بياناته
 * بضغطة — مايشوفش أي حاجة خالص.
 *
 * ═══ اللي بيتعمل ═══
 * (١) شيب «👤 بياناتي» أول القايمة في **كل** بلوك مستلم، بيظهر دايمًا.
 * (٢) الـreturn المبكر بيتشال — الشيب بيظهر حتى بلا مستلمين سابقين.
 * (٣) بيملّي نفس حقول شيب المُرسِل + `phone2` (البروفايل بيرجّعه —
 *     app/Wire/CustomerWire.php:50 — وبلوك المستلم عنده خانة ليه).
 *
 * وضع «الريسيت» مالوش أي معالجة زيادة: الحاوية `rcvSaved-${i}` نفسها
 * `display:none` في الوضع ده (سطر 3566).
 *
 * الحقول كلها متأكدة على السلك من app/Wire/CustomerWire.php:44-55:
 *   displayNameAr · phone1 · phone2 · address · lat · lng · defaultZoneId
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
`  // شيبس المستلمين السابقين — لكل بلوك مستلم قايمته الخاصة
  const picks = uniqueReceivers(S.savedReceivers);
  (S.draft?.receivers || []).forEach((_, i) => {
    const box = $("rcvSaved-" + i); if (!box) return;
    if (!picks.length) { box.innerHTML = ""; return; }
    box.innerHTML = \`<span class="lbl">مستلمون سابقون</span>
      <div class="chips">\${picks.slice(0, 10).map(r =>
        \`<div class="chip" data-id="\${esc(r.id)}">\${esc(r.name || r.phone)}</div>\`).join("")}</div>\`;
    box.querySelectorAll(".chip").forEach(c => c.onclick = () => {
      const r = picks.find(x => String(x.id) === c.dataset.id); if (!r) return;
      const t = S.draft.receivers[i]; if (!t) return;`;

const NEU =
`  /* شيبس الاختيار السريع لكل بلوك مستلم: «بياناتي» الأول وبعده المستلمون
     السابقون.
     🔴 «بياناتي» بيظهر **دايمًا** — صاحب الحساب ممكن يكون هو المستلم (شحنة
     جاية له). والـreturn المبكر القديم (\`if (!picks.length) … return\`) كان
     بيخفي الصف كله عن العميل اللي لسه مابعتش لحد — وهو أكتر واحد محتاج
     يملا بياناته بضغطة. نفس الغلط اتصلّح في شيب المُرسِل قبل كده. */
  const picks = uniqueReceivers(S.savedReceivers);
  (S.draft?.receivers || []).forEach((_, i) => {
    const box = $("rcvSaved-" + i); if (!box) return;
    box.innerHTML = \`<span class="lbl">اختيار سريع</span>
      <div class="chips"><div class="chip" data-me="1">👤 بياناتي</div>\${picks.slice(0, 10).map(r =>
        \`<div class="chip" data-id="\${esc(r.id)}">\${esc(r.name || r.phone)}</div>\`).join("")}</div>\`;
    box.querySelectorAll(".chip").forEach(c => c.onclick = () => {
      const t = S.draft.receivers[i]; if (!t) return;
      /* «بياناتي» — نفس حقول شيب المُرسِل + phone2 (بلوك المستلم عنده خانة
         ليه، والبروفايل بيرجّعه). */
      if (c.dataset.me) {
        const p = S.profile || {};
        $("rcvName-" + i).value   = p.displayNameAr || "";
        $("rcvPhone-" + i).value  = p.phone1 || "";
        $("rcvPhone2-" + i).value = p.phone2 || "";
        setAddr("rcvAddrW-" + i, p.address || "");
        t.lat = p.lat ?? null; t.lng = p.lng ?? null;
        if (p.defaultZoneId != null) $("rcvZone-" + i).value = String(p.defaultZoneId);
        onReceiverZone(i);
        showMiniMap("rcvMap-" + i, p.lat, p.lng);
        toast("اتملت بياناتك", "ok");
        return;
      }
      const r = picks.find(x => String(x.id) === c.dataset.id); if (!r) return;`;

/* ══ فحوص قبلية ══ */
const problems = [];
const n = s.split(OLD).length - 1;
if (n !== 1) problems.push(`كتلة شيبس المستلم: متوقّع ١ لقى ${n}`);
/* علامة الإضافة = عدد شيبات `data-me`. واحد قبل (المُرسِل) واتنين بعد.
   ⚠️ ماتستعملش نص التوست: «اتملت بياناتك» موجودة أصلًا في سطر 1898
   بصيغة تانية («… من الشحنة») فالعدّ عليها بيغلط. */
{
  const meChips = (s.match(/data-me="1"/g) || []).length;
  if (meChips !== 1) problems.push(`شيبات data-me: متوقّع ١ (المُرسِل) لقى ${meChips}`);
}
/* الدوال والحقول اللي بنعتمد عليها */
for (const need of ['function onReceiverZone', 'function setAddr', 'function showMiniMap']) {
  if (!s.includes(need)) problems.push('ناقص: ' + need);
}
if (problems.length) {
  console.log('⛔ مافيش بايت اتكتب:');
  for (const p of problems) console.log('   ✗ ' + p);
  process.exit(1);
}

s = s.split(OLD).join(NEU);

/* ══ فحوص بعدية ══ */
const after = [];
if (!/data-me="1">👤 بياناتي/.test(s)) after.push('الشيب مش موجود');
if ((s.match(/data-me="1"/g) || []).length !== 2) after.push('عدد شيبات data-me مش ٢ (مُرسِل + مستلم)');
if (/if \(!picks\.length\) \{ box\.innerHTML = ""; return; \}/.test(s)) after.push('الـreturn المبكر لسه موجود');
/* مسار المستلم السابق لازم يفضل شغّال */
if (!/const r = picks\.find\(x => String\(x\.id\) === c\.dataset\.id\); if \(!r\) return;/.test(s))
  after.push('مسار المستلم السابق اتكسر');
/* ماينفعش نلمس شيب المُرسِل */
if (!/\$\("sndName"\)\.value  = p\.displayNameAr \|\| "";/.test(s)) after.push('شيب المُرسِل اتغيّر');
{
  const re = /<script([^>]*)>([\s\S]*?)<\/script>/gi;
  let m, i = 0, bad = 0;
  while ((m = re.exec(s)) !== null) {
    i++;
    if (/\ssrc\s*=/i.test(m[1] || '') || !(m[2] || '').trim()) continue;
    const tmp = path.join(os.tmpdir(), 'crm-' + process.pid + '-' + i + '.mjs');
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
console.log('✓ اتكتب customer.html — شيب «بياناتي» في كل بلوك مستلم');
