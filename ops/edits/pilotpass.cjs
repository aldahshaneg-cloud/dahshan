/* كلمة مرور الطيار: مطلوبة عند الإضافة بس — مش عند التعديل.
 *
 * ═══ الباج ═══
 * حقل `#pilotPassword` عليه `required` في الـHTML، والمودال جوه
 * `<form id="addForm">` وزرار الحفظ `type="submit"`. يعني **المتصفح نفسه**
 * بيوقف الحفظ لو الحقل فاضي — قبل ما أي كود جافاسكربت يشتغل.
 *
 * منطق `addPilot` كان **صح أصلًا**: بيطلب الباسورد لو إضافة جديدة بس
 * (`!isEditingPilot && !pilotPassword`)، وبيبعته للسيرفر لو اتكتب بس.
 * وحتى `openEditPilot` بتكتب في الـplaceholder «اتركها فارغة للإبقاء على
 * كلمة المرور الحالية». الـ`required` كان بيكدّب ده كله.
 *
 * ═══ الحل ═══
 * الـ`required` بقى بيتحط ويتشال حسب الوضع: بيترفع عند التعديل
 * ويترجع عند الإضافة. والنجمة الحمرا بتتخفي معاه عشان الشاشة ما تكدبش.
 */
const fs = require('fs');

let done = 0, bad = 0;
const edit = (file, pairs) => {
  let s = fs.readFileSync(file, 'utf8');
  const eol = s.includes('\r\n') ? '\r\n' : '\n';
  if (eol === '\r\n') s = s.replace(/\r\n/g, '\n');
  for (const [old, neu] of pairs) {
    const n = s.split(old).length - 1;
    if (n !== 1) { console.log(`  🔴 ${file}: اتلقت ${n} مرة — «${old.trim().slice(0, 60)}»`); bad++; return; }
  }
  for (const [old, neu] of pairs) s = s.replace(old, neu);
  fs.writeFileSync(file, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
  console.log('  ✓ ' + file);
  done++;
};

/* الدالة المشتركة — بتتحط مرة واحدة في كل ملف */
const HELPER = `
    /* ══ كلمة المرور: مطلوبة عند الإضافة بس ══
       الحقل جوه <form> وزرار الحفظ submit، فالـ\`required\` بيوقف الحفظ
       في المتصفح قبل أي كود. عند التعديل الباسورد اختيارية (فاضي =
       سيبها زي ما هي)، فلازم السمة تترفع — والنجمة تختفي معاها. */
    window.setPilotPassRequired = function(required) {
      const inp = document.getElementById("pilotPassword");
      if (!inp) return;
      if (required) inp.setAttribute("required", "");
      else inp.removeAttribute("required");
      inp.placeholder = required ? "4 أحرف على الأقل"
                                 : "اتركها فارغة للإبقاء على كلمة المرور الحالية";
      const star = inp.closest(".form-group")?.querySelector(".req");
      if (star) star.style.display = required ? "" : "none";
    };
`;

/* ── لوحة الإدارة ── */
for (const F of ['public/callcenter.html']) edit(F, [
  ['  function resetPilotModal() {\n    document.getElementById("addForm").reset();',
   HELPER + '\n  function resetPilotModal() {\n    document.getElementById("addForm").reset();\n' +
   '    window.setPilotPassRequired(true);          // رجعنا لوضع الإضافة'],
  ['      document.getElementById("pilotPassword").value = "";\n' +
   '      document.getElementById("pilotPassword").placeholder = "اتركها فارغة للإبقاء على كلمة المرور الحالية";',
   '      document.getElementById("pilotPassword").value = "";\n' +
   '      window.setPilotPassRequired(false);        // تعديل: الباسورد اختيارية'],
]);

console.log(bad ? `\n🔴 ${bad} مشكلة` : `\n✅ ${done} ملف`);
process.exit(bad ? 1 : 0);
