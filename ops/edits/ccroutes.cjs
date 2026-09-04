/* سحب صلاحيات الكول سنتر على الأوردر من السيرفر.
 *
 * القرار (2026-08-30، صاحب النظام): «الكول سنتر يتعامل مع العميل فقط في
 * تلقي الأوردر والشكاوى، أما باقي العمل من اختصاص باقي المنظومة».
 * الأزرار اتشالت من الواجهة، وده الجزء التاني — القفل الحقيقي.
 */
const fs = require('fs');
const f = 'routes/api.php';
let s = fs.readFileSync(f, 'utf8');
const eol = s.includes('\r\n') ? '\r\n' : '\n';
if (eol === '\r\n') s = s.replace(/\r\n/g, '\n');

const OLD_NOTE = `/* 🔴 الكول سنتر **اترجع واتقفل** 2026-08-25 بقرار صاحب النظام، بعد ما كان
   اتفتح 2026-08-23. القرار الجديد بالحرف: «الكول سنتر ليس له سلطة على الطيار
   أو الفرع غير أنه يرسل الأوردر». فالتحميل على طيار (assign · assign-bulk)
   والنقل من طيار لطيار (transfer) رجعوا للفرع والإدارة بس.

   ⛔ deliver و undeliver فضلوا مقفولين من الأول (بيقفلوا الأوردر ماليًا).

   ✅ اللي فضل مفتوح للكول سنتر عن قصد — ده شغله هو مش سلطة على حد:
      • store            — تسجيل الأوردر وإرساله للفرع (جوهر الدور).
      • cancel/postpone  — العميل بيتصل يلغي أو يأجّل، والموظف على التليفون.
      • transfer-branch  — تصحيح **غلطته هو** لما يبعت الأوردر لفرع غلط.
      • update           — تصحيح بيانات العميل والعنوان بعد المكالمة.

   الأزرار في callcenter.html اتخفت كمان (isCC)، بس الإخفاء تجميل —
   القفل الحقيقي هو السطور دي. */`;

const NEW_NOTE = `/* 🔴 صلاحيات الكول سنتر على الأوردر — التاريخ باختصار:
     2026-08-23  اتفتحت
     2026-08-25  اترجعت واتقفلت جزئيًا («ليس له سلطة على الطيار أو الفرع
                 غير أنه يرسل الأوردر») — فـassign و assign-bulk و
                 transfer رجعوا للفرع والإدارة بس.
     2026-08-30  اتقفلت بالكامل. القرار بالحرف: «الكول سنتر يتعامل مع
                 العميل فقط في تلقي الأوردر والشكاوى، أما باقي العمل من
                 اختصاص باقي المنظومة».

   ✅ اللي فضل للكول سنتر — تلقّي الأوردر والشكوى وبس:
      • orders (store)   — تسجيل الأوردر وإرساله للفرع (جوهر الدور).
      • complaints       — تسجيل شكوى العميل.
      • senders/receivers — بيانات العملاء نفسهم وقت المكالمة.

   ⛔ اللي اتسحب منه 2026-08-30:
      • cancel · postpone · unpostpone — بقوا شغل الفرع والإدارة، وزراير
        التأجيل اتضافت لـbranch.html و tiar.html في نفس اليوم.
      • transfer-branch  — تصحيح الفرع الغلط بقى شغل الفرع/الإدارة.
      • update (PUT)     — تصحيح بيانات العميل على أوردر موجود؛ الواجهة
        بتاعته موجودة أصلًا في branch.html و tiar.html.

   ⛔ deliver و undeliver مقفولين من الأول (بيقفلوا الأوردر ماليًا).

   الأزرار في callcenter.html اتشالت كمان، بس الشيل تجميل —
   القفل الحقيقي هو السطور دي. الحارس: ops/test_cc_routes.php */`;

const PAIRS = [
  [OLD_NOTE, NEW_NOTE],
  [`Route::put('orders/{id}', [OrdersController::class, 'update'])->middleware('role:branch,admin,callcenter');`,
   `Route::put('orders/{id}', [OrdersController::class, 'update'])->middleware('role:branch,admin');`],
  [`Route::post('orders/{id}/transfer-branch', [OrdersController::class, 'transferBranch'])
    ->middleware('role:branch,admin,callcenter');`,
   `Route::post('orders/{id}/transfer-branch', [OrdersController::class, 'transferBranch'])
    ->middleware('role:branch,admin');`],
  [`Route::post('orders/{id}/cancel', [OrdersController::class, 'cancel'])->middleware('role:branch,admin,callcenter');`,
   `Route::post('orders/{id}/cancel', [OrdersController::class, 'cancel'])->middleware('role:branch,admin');`],
  [`Route::post('orders/{id}/postpone', [OrdersController::class, 'postpone'])->middleware('role:branch,admin,callcenter');`,
   `Route::post('orders/{id}/postpone', [OrdersController::class, 'postpone'])->middleware('role:branch,admin');`],
  [`Route::post('orders/{id}/unpostpone', [OrdersController::class, 'unpostpone'])->middleware('role:branch,admin,callcenter');`,
   `Route::post('orders/{id}/unpostpone', [OrdersController::class, 'unpostpone'])->middleware('role:branch,admin');`],
];

let bad = 0;
for (const [old, neu] of PAIRS) {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log(`🔴 اتلقت ${n} مرة — «${old.trim().slice(0, 60)}»`); bad++; }
}
if (bad) { console.log('\nوقفت — مافيش تعديل اتكتب.'); process.exit(1); }
for (const [old, neu] of PAIRS) s = s.replace(old, neu);

fs.writeFileSync(f, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
console.log('✓ routes/api.php — 5 مسارات اتقفلت على الكول سنتر');
