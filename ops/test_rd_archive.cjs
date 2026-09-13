/**
 * 🗄️ حارس: أرشيف طيارين روح دمشق — الترحيل بدل الحذف، وبصلاحيات.
 *
 * طلب صاحب النظام (2026-09-12): «عايز في تقفيل روح دمشق مكان أرحّل فيه
 * الطيارين اللي ما بقوش بيشتغلوا — لأني لو مسحته هيأثر على الحسابات.
 * وخلّي ده كمان له صلاحيات».
 *
 * ═══ ليه الترحيل مش الحذف ═══
 * `DELETE` من `rd_pilots` بيشيل الاسم من قدّام كل خانة قديمة وبيغيّر
 * تقفيلات شهور فاتت. الترحيل بيسيب الصف بكل أسعاره مكانه وبيعلّمه بس،
 * فالشهور القديمة بتفضل بحرفها. اتأكد على الإنتاج: بصمة تقفيلة الشهر
 * (١٢٣ صف) **متطابقة حرفيًا** قبل وبعد الترحيل.
 *
 * 🔴 والمفتاحين لازم يفضلوا في كتالوج الصلاحيات — من غيرهم الصلاحية
 *    موجودة في الكود بس محدش يقدر يدّيها لحد من شاشة الصلاحيات.
 *
 * ⚠️ «موقوف» (`active = 0`) حاجة تانية خالص: بيشيل أيامه من تقفيلة الشهر
 *    المفتوح — للغياب المؤقت. اللي خرج من الشغل يترحّل، ماينوقفش.
 *
 * المناورة الحيّة: php ops/drill_rd_archive.php
 * التشغيل: node ops/test_rd_archive.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const C = fs.readFileSync('app/Http/Controllers/Api/DamascusController.php', 'utf8');
const W = fs.readFileSync('app/Wire/DamascusWire.php', 'utf8');
const H = fs.readFileSync('public/damascus.html', 'utf8');
const R = fs.readFileSync('routes/api.php', 'utf8');
const S = fs.readFileSync('database/schema/mysql-schema.sql', 'utf8');

console.log('\n══ ① المخطط ══');
const tbl = S.slice(S.indexOf('CREATE TABLE `rd_pilots`'));
const tblBody = tbl.slice(0, tbl.indexOf('ENGINE='));
ok('🔴 أعمدة الأرشفة في المخطط (من غيرها النداء بيقع على الإنتاج)',
  /`archived_at` datetime DEFAULT NULL/.test(tblBody)
  && /`archived_by` varchar\(190\) DEFAULT NULL/.test(tblBody)
  && /`archive_note` varchar\(255\) DEFAULT NULL/.test(tblBody));
ok('ومفتاح على archived_at', /KEY `idx_rd_pilots_archived` \(`archived_at`\)/.test(tblBody));
ok('وسكربت التطبيق موجود وidempotent',
  fs.existsSync('ops/apply_rd_archive_schema.php')
  && /information_schema\.COLUMNS/.test(fs.readFileSync('ops/apply_rd_archive_schema.php', 'utf8')));
ok('⚠️ والسكربت مابيحوّلش الموقوفين للأرشيف لوحده (قرار المدير)',
  /الطيارين الموقوفين \(active = 0\) \*\*مش\*\*/.test(fs.readFileSync('ops/apply_rd_archive_schema.php', 'utf8')));

console.log('\n══ ② السيرفر ══');
ok('نداء الترحيل موجود', /public function pilotsArchive\(Request \$request, string \$id\): JsonResponse/.test(C));
ok('ونداء الرجوع منه', /public function pilotsUnarchive\(Request \$request, string \$id\): JsonResponse/.test(C));
ok('وقايمة الأرشيف', /public function archiveList\(Request \$request\): JsonResponse/.test(C));
ok('🔴 الترحيل بيعلّم الصف — مش بيمسحه',
  /UPDATE rd_pilots SET archived_at = \?, archived_by = \?, archive_note = \? WHERE id = \?/.test(C)
  && !/DELETE FROM rd_pilots WHERE id = \?[\s\S]{0,200}archived/.test(C));
ok('وبيسجّل مين رحّله وإمتى وليه',
  /\[WireTime::nowDb\(\), \(string\) \$user\['username'\], \$note !== '' \? \$note : null, \$pid\]/.test(C));
ok('والرجوع بيصفّر التلاتة',
  /UPDATE rd_pilots SET archived_at = NULL, archived_by = NULL, archive_note = NULL WHERE id = \?/.test(C));

console.log('\n══ ③ الصلاحيات ══');
ok('🔴 الترحيل محمي بـ act.archive',
  /requirePerm\(\$user, 'act\.archive', 'ترحيل الطيارين للأرشيف'\)/.test(C));
ok('🔴 وشاشة الأرشيف محمية بـ page.archive',
  /requirePerm\(\$user, 'page\.archive', 'أرشيف الطيارين'\)/.test(C));
ok('🔴 والمفتاحين في كتالوج الصلاحيات (ينفع يتدّوا من الشاشة)',
  /\['page\.archive', /.test(W) && /\['act\.archive', /.test(W),
  'من غير كده الصلاحية في الكود بس ومحدش يقدر يدّيها');
ok('والمسارات مقفولة على الأدوار',
  /rd\/pilots\/\{id\}\/archive.*middleware\('role:admin,accountant,branch'\)/.test(R)
  && /rd\/archive.*middleware\('role:admin,accountant,branch'\)/.test(R));

console.log('\n══ ④ الشاشة ══');
ok('بند القايمة الجانبية', /navigateTo\('archive'\)">🗄️ أرشيف الطيارين/.test(H));
ok('وصفحة الأرشيف', /<div class="page" id="page-archive">/.test(H));
ok('وزرار الترحيل في كارت الطيار', /id="pmArchive" onclick="openArchiveModal\(\)"/.test(H));
ok('🔴 والزرار بيظهر بصلاحية act.archive بس',
  /\$\("pmArchive"\)\.style\.display = \(id && can\("act\.archive"\) && !p\?\.archived\) \? "" : "none";/.test(H));
ok('والقايمة الجانبية بتخفي الشاشة من غير page.archive',
  /const ok = page === "perms" \? isAdmin\(\) : can\("page\." \+ page\);/.test(H));
ok('🔴 وقوايم الاختيار بتشيل المُرحَّل',
  /filter\(p => sameId\(p\.branchId, branchId\) && p\.active !== false && !p\.archived\)/.test(H));
ok('⚠️ والشاشة بتفرّق بين «موقوف مؤقتًا» و«خرج من الشغل»',
  /موقوف مؤقتًا<\/b> للغياب أو الإجازة الطويلة/.test(H) && /مايغيّرش ولا رقم/.test(H));

console.log('\n══ ⑤ زرار التقفيل اليومي ══');
ok('🔴 الزرار في صف التقفيل اليومي (مشرف الفرع مالوش شاشة الفروع والطيارين)',
  /\$\{esc\(r\.name\)\}\$\{archiveBtn\(r\.pilotId\)\}/.test(H));
ok('ودالته بتفحص act.archive — نفس مفتاح السيرفر',
  /function archiveBtn\(pilotId\) \{[\s\S]{0,140}if \(!can\("act\.archive"\)/.test(H));
ok('🔴 والمودال بياخد الطيار كمعامل مش من كارت التعديل بس',
  /window\.openArchiveModal = function\(pilotId\) \{/.test(H)
  && /const id = pilotId != null && pilotId !== "" \? pilotId : window\._editPilot;/.test(H));
ok('🔴 والمعرّف في متغيّر خاص مش بيدوس على _editPilot',
  /window\._archivePilot = id;/.test(H)
  && /const id = window\._archivePilot \|\| window\._editPilot;/.test(H),
  'الدوس عليه بيخلّي كارت التعديل فاكر طيار تاني');
ok('وبعد الترحيل الصفحة الحالية بتترسم تاني', /await reloadCurrentPage\(\);/.test(H));
ok('⚠️ ودمشق داخلة حارس الإقلاع',
  /'public\/damascus\.html'\]/.test(fs.readFileSync('ops/test_page_boot.cjs', 'utf8')));

console.log('\n══ ⑥ الاستطلاع مابيعيدش الرسم على الفاضي ══');
ok('🔴 الرسمة التانية بس لو البيانات اتغيّرت — استطلاع أو فتح',
  /const sig = pageSig\(\);\s*\n\s*if \(!force && sig !== null && sig === _lastSig\) return;/.test(H),
  'من غيرها الشاشة بتتعاد رسمها كل ١٨ث، وبترسم مرتين عند كل فتح');
ok('🔴 وأي رسمة بتسجّل بصمة اللي اترسم (مش الاستطلاع بس)',
  /updateStickyOffsets\(\);\s*\n[\s\S]{0,700}try \{ _lastSig = pageSig\(\); \} catch \(e\) \{\}\s*\n\}/.test(H),
  'رسمة navigateTo مش محسوبة = رفة مزدوجة عند كل فتح (اتقاست على الإنتاج 2026-09-12)');
ok('ودالة البصمة بتغطّي كل الشاشات',
  ['daily', 'pilot', 'month', 'deferred', 'setup', 'archive', 'settings', 'perms']
    .every(p => new RegExp(p + '\\s*: \\(\\) =>').test(H)));
ok('⚠️ وبصمة متعذّرة = ارسم (مانخفيش تحديث بسبب خطأ)',
  /catch \(e\) \{ return null; \}/.test(H) && /sig !== null &&/.test(H));
ok('🔴 و force (بعد كتابة) بيرسم دايمًا', /if \(!force && sig !== null/.test(H), 'بعد الحفظ المستخدم عايز رسمة أكيدة');
ok('والتنقّل مابيبطّلش البصمة (الرسمة بتسجّلها بنفسها)',
  !/invalidatePageSig\(\);   \/\/ شاشة تانية/.test(H) && /if \(pageHasData\(page\)\) renderCurrentPage\(\);\s*\n\s*reloadCurrentPage\(\);\s*\n\};/.test(H),
  'الإبطال كان بيخلّي الرسمة التانية أكيدة = رفة');
ok('🔴 وأول فتح لشاشة (بيانات null) بيستنى السيرفر ويرسم مرة واحدة',
  /function pageHasData\(page\) \{[\s\S]{0,400}case "month":    return !!S\.month;/.test(H),
  'رسم placeholder وبعده البيانات = رفة مزدوجة (اتقاست على الإنتاج 2026-09-12)');
ok('⚠️ والاستطلاع نفسه زي ما هو — البيانات لازم تفضل طازة',
  /const POLL_MS = 18000;/.test(H), 'ممنوع نبطّأه أو نوقفه — أكتر من مشرف على نفس الورقة');

console.log('\n' + '─'.repeat(52));
console.log(fail === 0 ? `✅ عدّى ${pass} فحص — الترحيل بدل الحذف، وبصلاحيات\n` : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
