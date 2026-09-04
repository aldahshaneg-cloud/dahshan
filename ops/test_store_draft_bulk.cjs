/**
 * 💾 حارس: الشغل مايضيعش بالتنقّل + إرسال كل الطلبات دفعة واحدة.
 *
 * ═══ الطلبات (صاحب النظام 2026-08-31) ═══
 * ① «لو بعتّ طلب ورحت لأي مكان تاني وكان في طلب تاني مفتوح، أرجع ألاقيه
 *    زي ما هو حتى أعمله إرسال».
 * ② «زرار في الأسفل يعمل على إرسال كل الطلبات دفعة واحدة».
 *
 * ═══ ليه الشغل كان بيضيع ═══
 * الصفحات بتتخفي مش بتتمسح، فالطرود بتفضل في الـDOM. اللي كان بيمسحها هو
 * `resetForm()` اللي بتتنده مع **كل** دخول على صفحة الطلب الجديد.
 *
 * ═══ الخطر في ② ═══
 * الإرسال الجماعي لازم يفضل **نفس مسار الإرسال الفردي** — أي مسار تاني
 * بيرجّع الترقيم المجمّع بالشرطة اللي صاحب النظام رفضه. البند ٣ بيحرس ده.
 * ولازم يقف عند أول فشل، وإلا المحل يلاقي طرود اتبعتت وطرود لأ ومايعرفش
 * مين فيهم. وعلامة النجاح هي **اختفاء الصف** — `saveOrder` مابتشيلوش غير
 * بعد ما السيرفر يرد بنجاح.
 *
 * التشغيل: node ops/test_store_draft_bulk.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const UI = fs.readFileSync('public/store.html', 'utf8');

/* ══ 1) الشغل بيفضل بالتنقّل ══ */
console.log('\n══ 1) التنقّل مايمسحش الشغل ══');
ok('التصفير بقى مشروط',
   /if \(page === "new-order"\)  \{ if \(!formHasWork\(\)\) resetForm\(\); renderStoreSetupHint\(\); \}/.test(UI));
ok('🔴 ومافيش نداء تصفير غير مشروط في navigate',
   !/if \(page === "new-order"\)  \{ resetForm\(\);/.test(UI), 'لسه بيمسح مع كل دخول');
ok('formHasWork موجودة', /window\.formHasWork = function \(\)/.test(UI));

const hw = /window\.formHasWork = function \(\)[\s\S]*?\n    \};/.exec(UI)?.[0] || '';
[['rName', 'اسم المستلم'], ['rPhone', 'الهاتف'], ['rPhone2', 'هاتف 2'],
 ['rZone', 'المنطقة'], ['rOrderPrice', 'سعر الطلب'], ['rNote', 'الملاحظات']]
  .forEach(([f, lbl]) => ok('بتشوف ' + lbl, hw.includes('"' + f + '-"')));
ok('وبتشوف الصور', /_rowImages\?\.\[n\] \|\| \[\]\)\.length/.test(hw));
ok('وبتشوف علامة الريسيت', /window\.rowIsReceipt\(n\)/.test(hw));
ok('rPrice مش فيها — بيتملى لوحده من المنطقة فمش دليل شغل',
   !hw.includes('"rPrice-"'));

/* ══ 2) تشغيل formHasWork المقصوصة ══ */
console.log('\n══ 2) تشغيل formHasWork ══');
{
  ok('اتقصّت', hw.length > 0);
  if (hw) {
    let vals = {}, receipt = false, imgs = {};
    const doc = { getElementById: id => (id in vals ? { value: vals[id] } : null) };
    const win = { _rowImages: imgs, rowIsReceipt: () => receipt };
    const rows = [{ id: 'row-1' }];
    const fn = new Function('document', 'window', 'parcelRows',
      'return ' + hw.replace(/^window\.formHasWork = /, ''))(doc, win, () => rows);

    const reset = () => { vals = {}; ['rName','rPhone','rPhone2','rZone','rOrderPrice','rNote']
      .forEach(f => vals[f + '-1'] = ''); receipt = false; Object.keys(imgs).forEach(k => delete imgs[k]); };

    reset(); ok('فورم نضيف → مافيش شغل', fn() === false);
    reset(); vals['rName-1'] = 'أحمد';       ok('اسم مكتوب → فيه شغل', fn() === true);
    reset(); vals['rZone-1'] = 'z1';         ok('منطقة متختارة → فيه شغل', fn() === true);
    reset(); vals['rOrderPrice-1'] = '0';    ok('عهدة صفر مكتوبة → فيه شغل', fn() === true);
    reset(); vals['rNote-1'] = 'هش';         ok('ملاحظة → فيه شغل', fn() === true);
    reset(); imgs[1] = ['x'];                ok('صورة مرفوعة → فيه شغل', fn() === true);
    reset(); receipt = true;                 ok('علامة الريسيت → فيه شغل', fn() === true);
    reset(); vals['rName-1'] = '   ';        ok('مسافات بس → مافيش شغل', fn() === false);
  }
}

/* ══ 3) إرسال الكل ══ */
console.log('\n══ 3) إرسال كل الطلبات ══');
ok('الزرار موجود تحت', /id="sendAllBtn"[\s\S]{0,90}onclick="sendAllParcels\(\)"/.test(UI));
ok('ومخفي افتراضيًا', /id="sendAllBtn" style="display:none"/.test(UI));
ok('وبيظهر مع أكتر من طرد بس',
   /all\.style\.display = rows\.length > 1 \? "" : "none";/.test(UI));
ok('sendAllParcels موجودة', /window\.sendAllParcels = async function \(\)/.test(UI));

const bulk = /window\.sendAllParcels = async function \(\)[\s\S]*?\n    \};/.exec(UI)?.[0] || '';
ok('🔴 وبتنده نفس saveOrder — مافيش مسار إرسال تاني',
   /await window\.saveOrder\(n\);/.test(bulk), 'مسار تاني = ترقيم تاني');
ok('وبتلفّ على لقطة من المعرّفات مش القايمة الحيّة',
   /const ids = parcelRows\(\)\.map\(r => r\.id\.replace\("row-", ""\)\);/.test(bulk));
ok('🔴 وبتقف عند أول فشل', /if \(document\.getElementById\(`row-\$\{n\}`\)\) \{ stopped = n; break; \}/.test(bulk));
ok('وبتتخطّى الصف اللي اتشال', /if \(!document\.getElementById\(`row-\$\{n\}`\)\) continue;/.test(bulk));
ok('والزرار بيترجع لحالته في الحالتين',
   (bulk.match(/btn\.textContent = "📤 إرسال كل الطلبات"/g) || []).length >= 1
   && /btn\.disabled = false;/.test(bulk));

/* ══ 4) مودال واحد مش N ══ */
console.log('\n══ 4) مودال واحد ══');
ok('التجميع مفعّل قبل الحلقة', /window\._bulkCodes = \[\];/.test(bulk));
ok('showOrderCodes بتجمّع بدل ما تفتح مودال',
   /if \(Array\.isArray\(window\._bulkCodes\)\) \{ window\._bulkCodes\.push\(\.\.\.codes\); return; \}/.test(UI));
ok('والتجميع بيتقفل بعد الحلقة', /window\._bulkCodes = null;/.test(bulk));
ok('renderCodesModal اتفصلت عن showOrderCodes',
   /window\.renderCodesModal = function \(headline, codes, note\)/.test(UI));
ok('والفردي لسه بينده عليها', /renderCodesModal\(orderNum, codes\);/.test(UI));
ok('والجماعي بيعرض عدد الطلبات', /codes\.length === 1 \? "طلب" : "طلبات"/.test(bulk));
ok('🔴 والتحذير جوه المودال مش توست — التوست بيتغطّى وراه',
   /const left = stopped/.test(bulk) && /renderCodesModal\([^)]*, codes, left\)/.test(bulk));
ok('والمودال بيرسم التحذير', /note \? `<div style="background:rgba\(245,158,11,\.12\)/.test(UI));
ok('ونص التحذير بيعدّي على esc', /\$\{esc\(note\)\}/.test(UI));

console.log('\n' + '─'.repeat(50));
console.log(fail === 0
  ? `✅ عدّى ${pass} فحص — الشغل بيفضل والإرسال الجماعي سليم\n`
  : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
