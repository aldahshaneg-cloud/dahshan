/* «فرع الطيار الثابت» — الجزء السيرفري.
 *
 * ═══ الحكاية ═══
 * `pilots.assigned_branch_id` كان بيلعب دورين متضاربين:
 *   • تعليق العمود بيقول «الفرع اللي الطيار تابع له» (ثابت)،
 *   • والكود بيستعمله كـ«الفرع اللي شغّال فيه دلوقتي»: `enterQueue` بتكتبه
 *     أول ما وردية تتفتح، و`releasePilot` **بتمسحه** أول ما تتقفل.
 *
 * النتيجة اللي شافها صاحب النظام: يحدّد فرع الطيار، وبعد أول قفل وردية
 * يرجع الفرع «—»، وكل وردية جديدة محتاجة يختار الفرع من أول وجديد.
 * وأسوأ: النقل المؤقت لفرع تاني كان بيبقى دايم لأن مفيش حاجة ترجّعه.
 *
 * ═══ الحل ═══
 * عمود جديد `home_branch_id` = الفرع الثابت. `assigned_branch_id` بيفضل
 * زي ما هو بالظبط (شغّال فين دلوقتي) عشان الطابور والدعم بين الفروع
 * مايتكسروش — دول بيعتمدوا عليه بمعناه الجاري.
 *
 *   فتح وردية      → بيقرا home_branch_id (هو الحاكم)
 *   نقل مؤقت/دعم   → بيغيّر assigned_branch_id + صف الوردية بس
 *   قفل الوردية    → بيمسح assigned_branch_id، وhome ما بيتلمسش
 *   نقل دائم معتمد → بيغيّر home_branch_id كمان
 *
 * يعني: بكرة الوردية بتفتح على فرعه هو. وده اللي اتطلب بالحرف.
 */
const fs = require('fs');

let bad = 0, done = 0;
const edit = (file, pairs) => {
  let s = fs.readFileSync(file, 'utf8');
  const eol = s.includes('\r\n') ? '\r\n' : '\n';
  if (eol === '\r\n') s = s.replace(/\r\n/g, '\n');
  for (const [old] of pairs) {
    const n = s.split(old).length - 1;
    if (n !== 1) { console.log(`  🔴 ${file}: اتلقت ${n} مرة — «${old.trim().slice(0, 65)}»`); bad++; return; }
  }
  for (const [old, neu] of pairs) s = s.replace(old, neu);
  fs.writeFileSync(file, eol === '\r\n' ? s.replace(/\n/g, '\r\n') : s);
  console.log('  ✓ ' + file);
  done++;
};

/* ══════════ ① السلك ══════════ */
edit('app/Wire/CoreWire.php', [
  [`            'assignedBranchName' => $r['assigned_branch_name'] ?? null,`,
   `            'assignedBranchName' => $r['assigned_branch_name'] ?? null,
            /* الفرع الثابت — ده اللي بيتعرض في جدول الطيارين وبتفتح عليه
               كل وردية. \`assignedBranch*\` فوق هو الفرع الجاري (بيفضى
               لما الوردية تتقفل)، والاتنين بيختلفوا وقت الدعم المؤقت. */
            'homeBranchId' => isset($r['home_branch_id']) && $r['home_branch_id'] !== null
                ? (int) $r['home_branch_id'] : null,
            'homeBranchName' => $r['home_branch_name'] ?? null,`],
]);

/* ══════════ ② قراءة الطيارين: اسم الفرع الثابت ══════════ */
edit('app/Http/Controllers/Api/EntitiesController.php', [
  [`        $sql = "SELECT p.*, b.name AS assigned_branch_name, u.username,
                       (SELECT COUNT(*) FROM orders o
                         WHERE o.pilot_id = p.id AND o.status = 'delivering') AS active_orders
                  FROM pilots p
                  LEFT JOIN branches b ON b.id = p.assigned_branch_id
                  LEFT JOIN users u ON u.pilot_id = p.id";`,
   `        $sql = "SELECT p.*, b.name AS assigned_branch_name, hb.name AS home_branch_name, u.username,
                       (SELECT COUNT(*) FROM orders o
                         WHERE o.pilot_id = p.id AND o.status = 'delivering') AS active_orders
                  FROM pilots p
                  LEFT JOIN branches b ON b.id = p.assigned_branch_id
                  LEFT JOIN branches hb ON hb.id = p.home_branch_id
                  LEFT JOIN users u ON u.pilot_id = p.id";`],

  /* الفلترة بالفرع: الثابت **أو** الجاري — عشان مدير الفرع يشوف طياريه
     حتى وهم قافلين الوردية، ويشوف كمان اللي جايله دعم النهارده. */
  [`        if ($branchId !== null && $branchId !== '') {
            $sql .= ' WHERE p.assigned_branch_id = ?';
            $vals[] = (int) $branchId;
        }`,
   `        if ($branchId !== null && $branchId !== '') {
            /* الثابت **أو** الجاري: الطيار اللي قافل ورديته لازم يفضل
               باين لفرعه (assigned بيبقى NULL ساعتها)، والطيار اللي جاي
               دعم النهارده لازم يبان للفرع اللي شغّال فيه. */
            $sql .= ' WHERE (p.home_branch_id = ? OR p.assigned_branch_id = ?)';
            $vals[] = (int) $branchId;
            $vals[] = (int) $branchId;
        }`],

  /* الإنشاء: كان بيتجاهل الفرع خالص */
  [`            'INSERT INTO pilots (name, phone1, phone2, card_num, vehicle_no, address,
                                 commission_type, commission_value, monthly_salary, required_daily_hours,
                                 notes, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',`,
   `            'INSERT INTO pilots (name, phone1, phone2, card_num, vehicle_no, address,
                                 home_branch_id,
                                 commission_type, commission_value, monthly_salary, required_daily_hours,
                                 notes, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',`],
  [`                trim((string) ($b['address'] ?? '')) ?: null,
                $commissionType,`,
   `                trim((string) ($b['address'] ?? '')) ?: null,
                $homeBranchId,
                $commissionType,`],
  [`        // 🔴 نوع العمولة فلوس — لازم يفضل percent/fixed بس، شوف Support\\Commission
        $commissionType = (string) ($b['commissionType'] ?? 'percent');
        if (! isset(Vocab::COMMISSION_TYPE_WIRE[$commissionType])) {
            throw new ApiException('نوع العمولة غير صالح (percent أو fixed)');
        }`,
   `        // 🔴 نوع العمولة فلوس — لازم يفضل percent/fixed بس، شوف Support\\Commission
        $commissionType = (string) ($b['commissionType'] ?? 'percent');
        if (! isset(Vocab::COMMISSION_TYPE_WIRE[$commissionType])) {
            throw new ApiException('نوع العمولة غير صالح (percent أو fixed)');
        }

        /* 🔴 الفرع الثابت كان **بيتجاهل خالص** في الإنشاء: الواجهة بتبعته
           والسيرفر بيرميه، فالطيار الجديد بيطلع بلا فرع والموظف مش فاهم ليه.
           \`assignedBranchId\` بيتقبل كمرادف قديم عشان أي واجهة ما اتحدّثتش. */
        $homeIn = $b['homeBranchId'] ?? $b['assignedBranchId'] ?? null;
        $homeBranchId = ($homeIn !== null && $homeIn !== '') ? $this->intId($homeIn) : null;
        if ($homeBranchId !== null && $this->branchName($homeBranchId) === null) {
            throw ApiException::notFound('الفرع غير موجود');
        }`],

  /* التعديل: نفس المفتاح بيكتب الثابت */
  [`        if (array_key_exists('assignedBranchId', $b)) {
            $abid = $b['assignedBranchId'] !== null ? $this->intId($b['assignedBranchId']) : null;
            if ($abid !== null && $this->branchName($abid) === null) {
                throw ApiException::notFound('الفرع غير موجود');
            }
            $fields[] = 'assigned_branch_id = ?';
            $vals[] = $abid;
        }`,
   `        /* 🔴 الفرع اللي بيتبعت من مودال الطيار هو **الفرع الثابت** —
           مش الجاري. الجاري بيتحدّد لوحده وقت فتح الوردية، ولو كتبناه من
           هنا كنا بنقول للنظام إن الطيار شغّال دلوقتي وهو مش فاتح وردية.
           \`assignedBranchId\` بيتقبل كمرادف قديم لنفس المعنى. */
        $homeKey = array_key_exists('homeBranchId', $b) ? 'homeBranchId'
                 : (array_key_exists('assignedBranchId', $b) ? 'assignedBranchId' : null);
        if ($homeKey !== null) {
            $hb = ($b[$homeKey] !== null && $b[$homeKey] !== '') ? $this->intId($b[$homeKey]) : null;
            if ($hb !== null && $this->branchName($hb) === null) {
                throw ApiException::notFound('الفرع غير موجود');
            }
            $fields[] = 'home_branch_id = ?';
            $vals[] = $hb;
        }`],
]);

/* ══════════ ③ فتح الوردية: الفرع الثابت هو الحاكم ══════════ */
edit('app/Http/Controllers/Api/BoardController.php', [
  [`                if ($pilot['status'] !== null && $pilot['status'] !== '') {
                    throw new ApiException('الطيار عنده وردية مفتوحة بالفعل أو في إذن');
                }
                $branchId = $this->branchScope($actor, $branchIn !== null ? $this->intId($branchIn) : null)
                    ?? ($pilot['assigned_branch_id'] !== null ? (int) $pilot['assigned_branch_id'] : null);
                if (! $branchId) {
                    throw new ApiException('حدّد الفرع');
                }`,
   `                /* 🔴 الفرع الثابت هو الحاكم — مش اللي اتبعت من الشاشة.
                   الطلب بالحرف: «ثبّت الطيار على فرع من البداية، وحتى لو
                   اتقفلت الوردية يفتح على الفرع المتكوّد عليه، ولو اتنقل
                   لفرع تاني اليوم يرجع لفرعه تاني يوم».

                   النقل المؤقت بيحصل **بعد** الفتح (نقل الوردية أو الدعم)
                   وبيغيّر \`assigned_branch_id\` بس — فبكرة الوردية بتفتح
                   على \`home_branch_id\` من تاني لوحدها.

                   الرجوع للمبعوت من الشاشة بيحصل بس لو الطيار لسه بلا فرع
                   ثابت (بيانات قديمة) — ساعتها مفيش حاجة نرجعله. */
                $branchId = ($pilot['home_branch_id'] ?? null) !== null
                    ? (int) $pilot['home_branch_id']
                    : ($this->branchScope($actor, $branchIn !== null ? $this->intId($branchIn) : null)
                        ?? ($pilot['assigned_branch_id'] !== null ? (int) $pilot['assigned_branch_id'] : null));
                if (! $branchId) {
                    throw new ApiException('الطيار مالوش فرع ثابت — حدّده من بيانات الطيار الأول');
                }`],

  /* النقل الدائم المعتمد بيحرّك الفرع الثابت كمان */
  [`                $toBranchId = (int) $req['to_branch_id'];
                $this->enterQueue((int) $req['pilot_id'], $toBranchId, $now);`,
   `                $toBranchId = (int) $req['to_branch_id'];
                /* ده النقل **الدائم** (طلب اتعمله موافقة) — فالفرع الثابت
                   بيتحرّك معاه. النقل المؤقت (دعم/نقل وردية) مابيلمسهوش،
                   وعشان كده الطيار بيرجع لفرعه تاني يوم لوحده. */
                DB::update('UPDATE pilots SET home_branch_id = ? WHERE id = ?',
                    [$toBranchId, (int) $req['pilot_id']]);
                $this->enterQueue((int) $req['pilot_id'], $toBranchId, $now);`],
]);

console.log(bad ? `\n🔴 ${bad} مشكلة — مافيش تعديل اتكتب في الملف ده` : `\n✅ ${done} ملف`);
process.exit(bad ? 1 : 0);
