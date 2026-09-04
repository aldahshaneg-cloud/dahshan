/* «فرع الطيار الثابت» — جزء الواجهات.
 *
 * السيرفر بقى بيميّز بين حقلين:
 *   homeBranchId     = الفرع الثابت (بيتحدد من بيانات الطيار)
 *   assignedBranchId = الفرع اللي عنده دلوقتي (بيرجع للثابت لما الوردية تتقفل)
 *
 * الاتنين بيتساووا في الحالة العادية، وبيختلفوا **بس** وقت الدعم المؤقت
 * لفرع تاني. عشان كده أغلب استعمالات الواجهة (الطابور · الخريطة · مين
 * متاح) بتفضل على `assignedBranchId` وهي صح — دي بتسأل «هو فين دلوقتي».
 *
 * اللي بيتغيّر هنا حاجتين بس:
 *   ① مودال الطيار بيتعبّى من `homeBranchId` — لو اتعبّى من الجاري، تعديل
 *      أي حاجة تانية والطيار في دعم كان **هيدهس فرعه الثابت** بفرع الدعم.
 *   ② عمود «الفرع» بيعرض الثابت، ولو الطيار في دعم بيبان جنبه إنه مؤقتًا
 *      في فرع تاني — بدل ما الفرعين يتخلطوا في خانة واحدة.
 */
const fs = require('fs');

let done = 0, bad = 0;
const edit = (file, pairs) => {
  let s = fs.readFileSync(file, 'utf8');
  const eol = s.includes('\r\n') ? '\r\n' : '\n';
  if (eol === '\r\n') s = s.replace(/\r\n/g, '\n');
  for (const [old] of pairs) {
    const n = s.split(old).length - 1;
    if (n !== 1) { console.log(`  🔴 ${file}: اتلقت ${n} مرة — «${old.trim().slice(0, 60)}»`); bad++; return; }
  }
  for (const [old, neu] of pairs) s = s.replace(old, neu);
  fs.writeFileSync(file, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
  console.log('  ✓ ' + file);
  done++;
};

/* ── لوحة الإدارة ── */
edit('public/tiar.html', [
  /* ① التعبئة من الثابت */
  [`      fillPilotBranchSelect(p.assignedBranchId);`,
   `      /* الثابت مش الجاري: لو الطيار في دعم بفرع تاني والموظف عدّل رقم
         تليفونه، التعبئة من الجاري كانت هتحفظ فرع الدعم كفرعه الدائم. */
      fillPilotBranchSelect(p.homeBranchId ?? p.assignedBranchId);`],

  /* ② العمود يعرض الثابت + إشارة للدعم */
  [`        const branch = (window._branchesList || []).find(b => b.id === p.assignedBranchId);
        const branchHtml = branch ? \`<span style="color:var(--sky);font-size:.8rem">🏢 \${ esc(branch.name) }</span>\` : \`<span style="color:var(--muted);font-size:.78rem">—</span>\`;`,
   `        /* الفرع الثابت هو اللي بيتعرض. ولو الطيار شغّال النهارده في فرع
           تاني (دعم مؤقت) بتبان سطر تحته — الفرعين مايتخلطوش في خانة واحدة. */
        const homeId = p.homeBranchId ?? p.assignedBranchId;
        const branch = (window._branchesList || []).find(b => b.id === homeId);
        const atNow  = p.assignedBranchId && p.assignedBranchId !== homeId
          ? (window._branchesList || []).find(b => b.id === p.assignedBranchId) : null;
        const branchHtml = (branch
          ? \`<span style="color:var(--sky);font-size:.8rem">🏢 \${ esc(branch.name) }</span>\`
          : \`<span style="color:var(--muted);font-size:.78rem">—</span>\`)
          + (atNow ? \`<div style="color:var(--orange);font-size:.7rem;margin-top:2px">↗ دعم مؤقت: \${ esc(atNow.name) }</div>\` : "");`],

  /* ③ «يقدر يفتح وردية» على الثابت — الجاري بيبقى فاضي لو الوردية مقفولة قديمًا */
  [`        const canOpenShift = !p.pilotStatus && p.assignedBranchId;`,
   `        const canOpenShift = !p.pilotStatus && (p.homeBranchId ?? p.assignedBranchId);`],

  /* ④ الإرسال باسمه الصريح */
  [`      const assignedBranchId = document.getElementById("pilotBranch").value || null;`,
   `      /* الحقل ده في المودال اسمه «الفرع المعيّن» ومعناه الفرع **الثابت**. */
      const homeBranchId = document.getElementById("pilotBranch").value || null;`],
  [`        const pilotData = { name, phone1, phone2, cardNum, address, vehicleNo, commissionType, commissionValue, notes, assignedBranchId };`,
   `        const pilotData = { name, phone1, phone2, cardNum, address, vehicleNo, commissionType, commissionValue, notes, homeBranchId };`],
  [`          if (assignedBranchId) {
            try { await api.put("/api/pilots/" + newPilotId, { assignedBranchId }); } catch(e) { console.warn("assign branch:", e); }
          }`,
   `          /* الإنشاء بقى بيحفظ الفرع بنفسه (كان بيتجاهله قبل كده)،
             والـPUT ده فضل كحزام أمان لو السيرفر لسه نسخة قديمة. */
          if (homeBranchId) {
            try { await api.put("/api/pilots/" + newPilotId, { homeBranchId }); } catch(e) { console.warn("home branch:", e); }
          }`],
]);

/* ── الكول سنتر: نفس المودال بالنص ── */
edit('public/callcenter.html', [
  [`      fillPilotBranchSelect(p.assignedBranchId);`,
   `      fillPilotBranchSelect(p.homeBranchId ?? p.assignedBranchId);   // الثابت مش الجاري`],
  [`      const assignedBranchId = document.getElementById("pilotBranch").value || null;`,
   `      const homeBranchId = document.getElementById("pilotBranch").value || null;   // «الفرع المعيّن» = الثابت`],
  [`        const pilotData = { name, phone1, phone2, cardNum, address, vehicleNo, commissionType, commissionValue, notes, assignedBranchId };`,
   `        const pilotData = { name, phone1, phone2, cardNum, address, vehicleNo, commissionType, commissionValue, notes, homeBranchId };`],
  [`            try { await api.put("/api/pilots/" + newPilotId, { assignedBranchId }); } catch (e) { console.warn("assign branch:", e); }`,
   `            try { await api.put("/api/pilots/" + newPilotId, { homeBranchId }); } catch (e) { console.warn("home branch:", e); }`],
]);

console.log(bad ? `\n🔴 ${bad} مشكلة` : `\n✅ ${done} ملف`);
process.exit(bad ? 1 : 0);
