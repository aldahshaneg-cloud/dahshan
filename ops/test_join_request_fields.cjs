/**
 * 📋 حارس: طلبات الانضمام — المصدر بيبان، وبيانات المتقدّم الاختيارية.
 *
 * طلب صاحب النظام (2026-09-12): «في طلبات الانضمام، حد باعت طلب — عايز
 * أكون عارف الطلب ده مبعوت من الموقع ولا من الفرع ولا منين. وعايزين نضيف
 * حبة معلومات لو حابب يملاها وهو بيقدّم: زي الأماكن اللي اشتغل فيها قبل
 * كده، سابها ليه، آخر راتب كان كام».
 *
 * ═══ المصدر ═══
 * عمود `source` كان **موجود في القاعدة من الأصل** وبيتكتب صح (`home` من
 * الموقع · `branch` من المشرف · `admin` من الإدارة) — بس ماكانش بيطلع على
 * السلك خالص، فشاشة الإدارة ماكانش قدّامها أي طريقة تعرف. الإصلاح إنه يطلع
 * ويتعرض كشارة.
 *
 * 🔴 الحقول الجديدة **كلها اختيارية** — الطلب بيتقبل من غيرها. لو أي واحد
 *    فيهم بقى إجباري، المتقدّم المستعجل هيسيب الفورم وهو نص الطريق.
 *
 * ⚠️ والمسار العام مفتوح للدنيا كلها — فأي حقل جديد لازم يتقص طوله
 *    (`mb_substr`) والأرقام تتقص عند حد، وإلا بقى باب لحشو القاعدة.
 *
 * التشغيل: node ops/test_join_request_fields.cjs
 */
const fs = require('fs');
let pass = 0, fail = 0;
const ok = (what, cond, got) => {
  if (cond) { pass++; console.log('  ✓ ' + what); }
  else { fail++; console.log('  ✗ ' + what + (got !== undefined ? '   ← ' + got : '')); }
};

const W = fs.readFileSync('app/Wire/BoardWire.php', 'utf8');
const P = fs.readFileSync('app/Http/Controllers/Api/PublicSiteController.php', 'utf8');
const B = fs.readFileSync('app/Http/Controllers/Api/BoardController.php', 'utf8');
const I = fs.readFileSync('public/index.html', 'utf8');
const T = fs.readFileSync('public/tiar.html', 'utf8');
const R = fs.readFileSync('public/branch.html', 'utf8');
const S = fs.readFileSync('database/schema/mysql-schema.sql', 'utf8');

console.log('\n══ ① المخطط ══');
const tbl = S.slice(S.indexOf('CREATE TABLE `pilot_join_requests`'));
const body = tbl.slice(0, tbl.indexOf('ENGINE='));
['prev_employer', 'leave_reason', 'last_salary', 'experience_years', 'applicant_note']
  .forEach(c => ok(`عمود ${c} في المخطط`, new RegExp('`' + c + '`').test(body)));
/* ⚠️ مش `[^,]*` — `decimal(12,2)` جواها فاصلة وكانت بتكسر الفحص */
ok('🔴 وكلهم NULL (اختياريين)',
  ['prev_employer', 'leave_reason', 'last_salary', 'experience_years', 'applicant_note']
    .every(c => new RegExp('`' + c + '`[^\\n]*DEFAULT NULL').test(body)),
  'لو أي واحد NOT NULL الفورم بيرفض المتقدّم اللي مملاهوش');
ok('وتعليق source بيقول القيم الحقيقية (home مش self)',
  /`source`[^,]*home \(الموقع العام\)/.test(body));
ok('وسكربت التطبيق موجود وidempotent',
  fs.existsSync('ops/apply_join_request_fields.php')
  && /information_schema\.COLUMNS/.test(fs.readFileSync('ops/apply_join_request_fields.php', 'utf8')));

console.log('\n══ ② السلك ══');
ok('🔴 المصدر بيطلع على السلك (ده اللي كان ناقص)', /'source'     => \$r\['source'\] \?\? null,/.test(W));
ok('و requestedBy معاه', /'requestedBy' => \$r\['requested_by'\] \?\? null,/.test(W));
['prevEmployer', 'leaveReason', 'lastSalary', 'experienceYears', 'applicantNote']
  .forEach(k => ok(`و${k} على السلك`, new RegExp("'" + k + "'").test(W)));
ok('والأرقام بترجع أرقام مش نصوص',
  /'lastSalary'      => \$r\['last_salary'\] !== null \? \(float\)/.test(W)
  && /'experienceYears' => \$r\['experience_years'\] !== null \? \(float\)/.test(W));

console.log('\n══ ③ المسار العام (مفتوح للدنيا) ══');
ok('بيخزّن الحقول الخمسة', /prev_employer, leave_reason, last_salary, experience_years, applicant_note/.test(P));
ok('🔴 والنصوص بتتقص بطول أقصى', /mb_substr\(trim\(\(string\) \(\$b\[\$k\] \?\? ''\)\), 0, \$max\) \?: null/.test(P));
ok('🔴 والأرقام بتتقص عند حد وبالسالب بتبقى NULL',
  /return min\(max\(0\.0, \(float\) \$v\), \$max\) \?: null;/.test(P));
ok('والراتب والخبرة ليهم سقف', /\$num\('lastSalary', 999999\)/.test(P) && /\$num\('experienceYears', 60\)/.test(P));
ok('⚠️ و source لسه home', /'pending','home'/.test(P));
ok('والفاضي بيتخزّن NULL مش نص فاضي', /\?: null/.test(P));

console.log('\n══ ④ مسار اللوحة ══');
ok('بيخزّن نفس الحقول', /prev_employer, leave_reason, last_salary, experience_years, applicant_note/.test(B));
ok('و source بيفرّق بين الإدارة والفرع',
  /\$actor->role === 'admin' \? 'admin' : 'branch',/.test(B));
ok('ونفس قص الأرقام', /return min\(max\(0\.0, \(float\) \$v\), \$max\) \?: null;/.test(B));

console.log('\n══ ⑤ فورم الموقع ══');
ok('القسم الاختياري مطوي (الفورم الأساسي يفضل قصير)', /<details class="join-more">/.test(I));
/* ⚠️ علامة الاستفهام في النص عربية (؟) مش لاتينية — المقارنة بـincludes
   أأمن من regex فيها محارف عربية (فخ اتكرّر قبل كده). */
ok('وبيقول إنه اختياري صراحةً', I.includes('احكيلنا (اختياري)'));
['pPrev', 'pWhy', 'pSalary', 'pExp', 'pNote'].forEach(id => ok(`حقل ${id}`, new RegExp('id="' + id + '"').test(I)));
ok('والحقول بتتبعت مع الطلب',
  /prevEmployer: \$\("pPrev"\)\.value\.trim\(\)/.test(I) && /applicantNote: \$\("pNote"\)\.value\.trim\(\)/.test(I));
ok('🔴 ومفيش منهم required (اختيارية فعلًا)',
  !/id="(pPrev|pWhy|pSalary|pExp|pNote)"[^>]*required/.test(I));

console.log('\n══ ⑥ شاشة الإدارة ══');
ok('🔴 شارة المصدر موجودة', /function joinSourceBadge\(r\)/.test(T));
ok('وبتغطّي التلات مصادر', /home  : \['🌐 من الموقع'/.test(T) && /branch: \['🏢 من الفرع'/.test(T) && /admin : \['👑 من الإدارة'/.test(T));
ok('⚠️ والمصدر المجهول ليه شكل كمان (بيانات قديمة)', /مصدر غير معروف/.test(T));
ok('والشارة بتتعرض في الكارت', /\$\{ joinSourceBadge\(r\) \}\$\{statusHtml\}/.test(T));
ok('وبيانات المتقدّم بتتعرض', /function joinExtraHtml\(r\)/.test(T) && /\$\{ joinExtraHtml\(r\) \}/.test(T));
ok('🔴 والقسم بيختفي لو مملاش حاجة', /if \(!rows\.length\) return '';/.test(T));

console.log('\n══ ⑦ فورم الفرع ══');
['jPilotPrev', 'jPilotWhy', 'jPilotSalary', 'jPilotExp', 'jPilotNote']
  .forEach(id => ok(`حقل ${id}`, new RegExp('id="' + id + '"').test(R)));
ok('وبتتفضّى مع فتح المودال', /"jPilotPrev","jPilotWhy","jPilotSalary","jPilotExp","jPilotNote"/.test(R));
ok('وبتتبعت مع الطلب', /prevEmployer: _v\("jPilotPrev"\)/.test(R));

console.log('\n' + '─'.repeat(52));
console.log(fail === 0 ? `✅ عدّى ${pass} فحص — المصدر بيبان والبيانات اختيارية\n` : `🔴 وقع ${fail} من ${pass + fail}\n`);
process.exit(fail === 0 ? 0 : 1);
