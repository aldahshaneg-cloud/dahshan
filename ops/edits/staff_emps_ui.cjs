/* ⚙️ إدارة الموظفين جوه برنامج التقفيل — للأدمن بس.
 *
 * ═══ الطلب ═══
 * صاحب النظام 2026-09-01: «عايز إدارة الموظفين تكون في صفحة في البرنامج
 * بحيث أضيف منها موظفين أو أعدل عليهم زي اللي في الإدارة بالضبط — ده مش
 * بيلغي اللي في الإدارة، ده نقل لشغل الإعدادات... أيًا كان طيارين أو
 * غيره». يعني الشاشة دي **بتنده نفس نقط النهاية** بتاعة لوحة الإدارة
 * (/api/users و/api/pilots) — مافيش مسار جديد ومافيش منطق مكرر على
 * السيرفر، فأي تعديل من هنا بيبان هناك والعكس.
 *
 * ═══ قسمين ═══
 * ① حسابات الموظفين: إضافة/تعديل بحقول لوحة الإدارة (اسم مستخدم، كلمة
 *    مرور، دور، فرع) + صلاحيات التطبيقات بنفس قايمة APP_PERMS + **حقول
 *    الرواتب الجديدة** (سعر ساعة/راتب شهري/إجازة) اللي التقفيلة بتحسب
 *    منها.
 * ② أسعار الطيارين: الجدول اللي بيحل مشكلة الليلة — ١٤ من ١٥ طيار
 *    بعمولة صفر وسعر ساعة صفر والتقفيلة هتطلع أصفار. تعديل مباشر في
 *    الجدول بينده PUT /api/pilots/{id}.
 *
 * ═══ اللي عمدًا مش هنا ═══
 * حقول صاحب المحل (ربط المرسل والمنطقة) وصلاحيات صفحات التطبيقات
 * الدقيقة — دول ليهم شجرة تبعيات كاملة في لوحة الإدارة، والشاشة بتقول
 * ده بالنص وبتسيب المحفوظ زي ما هو (المفتاح الغايب في PUT مش بيتلمس).
 *
 * ⚠️ الشاشة **مابتكتبش باسوردات بنفسها** — بتوفر الخانة والأدمن هو
 * اللي بيكتب، زي لوحة الإدارة بالظبط.
 *
 * 🔒 الحارس: ops/test_staff_closeout.cjs
 */
const fs = require('fs');
const F = 'public/accounts.html';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const L = (...x) => x.join('\n');
const D = String.fromCharCode(36);
const B = String.fromCharCode(96);
const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};

if (s.includes('renderEmpsUsers')) { console.log('🔴 موجود قبل كده'); process.exit(1); }

/* ═══ ① CSS المودال ═══ */
one(
  '/* التوست */',
  L(
    '/* ══ مودال إدارة الموظفين — مقتبس من لوحة الإدارة ══ */',
    '.emp-modal { position: fixed; inset: 0; background: rgba(0,0,0,.72); z-index: 900;',
    '  display: none; align-items: center; justify-content: center; padding: 18px; }',
    '.emp-modal.open { display: flex; }',
    '.emp-box { background: var(--panel); border: 1px solid var(--border); border-radius: 14px;',
    '  width: 100%; max-width: 560px; max-height: 92vh; overflow-y: auto; }',
    '.emp-head { display: flex; justify-content: space-between; align-items: center;',
    '  padding: 15px 18px; border-bottom: 1px solid var(--border); position: sticky; top: 0;',
    '  background: var(--panel); z-index: 3; font-weight: 800; }',
    '.emp-body { padding: 16px 18px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }',
    '.emp-field { display: flex; flex-direction: column; gap: 5px; }',
    '.emp-field.full { grid-column: 1 / -1; }',
    '.emp-field label { font-size: .78rem; color: var(--muted); font-weight: 700; }',
    '.emp-field input, .emp-field select { background: var(--card); border: 1px solid var(--border);',
    '  border-radius: 8px; color: var(--text); padding: 9px 12px; font-size: .86rem;',
    '  font-family: inherit; outline: none; width: 100%; }',
    '.emp-field input:focus, .emp-field select:focus { border-color: var(--sky); }',
    '.emp-foot { padding: 13px 18px; border-top: 1px solid var(--border); display: flex; gap: 8px;',
    '  position: sticky; bottom: 0; background: var(--panel); }',
    '',
    '/* التوست */'
  ),
  '① CSS المودال'
);

/* ═══ ② زرار التبويب — بعد الصلاحيات ═══ */
one(
  '    <button class="sub-tab" id="patab-perms" style="display:none" onclick="switchPaTab(\'perms\')">🔐 الصلاحيات</button>',
  L(
    '    <button class="sub-tab" id="patab-perms" style="display:none" onclick="switchPaTab(\'perms\')">🔐 الصلاحيات</button>',
    '    <button class="sub-tab" id="patab-emps" style="display:none" onclick="switchPaTab(\'emps\')">⚙️ إدارة الموظفين</button>'
  ),
  '② زرار التبويب'
);

/* ═══ ③ إظهاره للأدمن في applyPaAcl ═══ */
one(
  L(
    "    const permTab = document.getElementById('patab-perms');",
    "    if (permTab) permTab.style.display = acl.isAdmin ? '' : 'none';"
  ),
  L(
    "    const permTab = document.getElementById('patab-perms');",
    "    if (permTab) permTab.style.display = acl.isAdmin ? '' : 'none';",
    "    const empsTab = document.getElementById('patab-emps');",
    "    if (empsTab) empsTab.style.display = acl.isAdmin ? '' : 'none';"
  ),
  '③ إظهار للأدمن'
);

/* ═══ ④ الماركب — بعد شاشة الصلاحيات ═══ */
one(
  L(
    '    <div id="paPermsBody"><div class="empty-row">جاري التحميل…</div></div>',
    '  </div>'
  ),
  L(
    '    <div id="paPermsBody"><div class="empty-row">جاري التحميل…</div></div>',
    '  </div>',
    '',
    '  <!-- ══ ⚙️ إدارة الموظفين — للأدمن بس ══',
    '       نفس نقط نهاية لوحة الإدارة (/api/users و/api/pilots) — الشاشة',
    '       نقل لشغل الإعدادات مش نسخة تانية من المنطق. -->',
    '  <div id="pa-emps" style="display:none">',
    '    <div class="sec-header">',
    '      <div>',
    '        <span class="sec-title">⚙️ حسابات الموظفين</span>',
    '        <div class="pa-sec-sub">نفس حسابات لوحة الإدارة — التعديل من هنا بيبان هناك والعكس</div>',
    '      </div>',
    '      <button class="add-btn" onclick="openEmpModal(null)">➕ إضافة موظف</button>',
    '    </div>',
    '    <div class="table-wrap"><table>',
    '      <thead><tr>',
    '        <th style="width:34px">#</th><th>اسم المستخدم</th><th>الاسم</th><th>الدور</th><th>الفرع</th>',
    '        <th class="num">سعر الساعة</th><th class="num">راتب شهري</th><th class="num">إجازة</th>',
    '        <th>الحالة</th><th style="width:150px">إجراء</th>',
    '      </tr></thead>',
    '      <tbody id="empUsersBody"><tr><td colspan="10" class="empty-row">جاري التحميل…</td></tr></tbody>',
    '    </table></div>',
    '',
    '    <div class="sec-header" style="margin-top:26px">',
    '      <div>',
    '        <span class="sec-title">🛵 أسعار الطيارين</span>',
    '        <div class="pa-sec-sub">العمولة وسعر الساعة والراتب — التقفيلة بتحسب من الأرقام دي، والصفر معناه مستحقات صفر</div>',
    '      </div>',
    '    </div>',
    '    <div class="table-wrap"><table>',
    '      <thead><tr>',
    '        <th style="width:34px">#</th><th>الطيار</th><th>الفرع</th>',
    '        <th>نوع العمولة</th><th class="num">قيمة العمولة</th>',
    '        <th class="num">سعر الساعة</th><th class="num">راتب شهري</th><th class="num">إجازة مدفوعة</th>',
    '      </tr></thead>',
    '      <tbody id="empPilotsBody"><tr><td colspan="8" class="empty-row">جاري التحميل…</td></tr></tbody>',
    '    </table></div>',
    '  </div>',
    '',
    '  <!-- مودال الموظف -->',
    '  <div class="emp-modal" id="empModal" onclick="if(event.target===this) closeEmpModal()">',
    '    <div class="emp-box">',
    '      <div class="emp-head"><span id="empModalTitle">👤 إضافة موظف</span>',
    '        <button class="view-btn" onclick="closeEmpModal()">✕</button></div>',
    '      <div class="emp-body">',
    '        <div class="emp-field"><label>اسم المستخدم *</label><input id="empUsername" dir="ltr" /></div>',
    '        <div class="emp-field"><label id="empPassLabel">كلمة المرور *</label><input id="empPassword" type="password" dir="ltr" autocomplete="new-password" /></div>',
    '        <div class="emp-field"><label>الاسم الظاهر</label><input id="empName" /></div>',
    '        <div class="emp-field"><label>الدور</label>',
    '          <select id="empRole" onchange="empRoleChanged()">',
    '            <option value="مشرف فرع">مشرف فرع</option>',
    '            <option value="كول سنتر">كول سنتر</option>',
    '            <option value="محاسب">📒 محاسب</option>',
    '            <option value="موارد بشرية">🧑‍💼 موارد بشرية</option>',
    '            <option value="مشرف الطيارين">🧑‍✈️ مشرف الطيارين</option>',
    '            <option value="موظف">موظف</option>',
    '            <option value="مدير">مدير</option>',
    '            <option value="صاحب محل">🏪 صاحب محل</option>',
    '          </select></div>',
    '        <div class="emp-field" id="empBranchField"><label>الفرع المسؤول عنه *</label>',
    '          <select id="empBranch"><option value="">— اختر الفرع —</option></select></div>',
    '        <div class="emp-field"><label>سعر الساعة</label><input id="empHourRate" type="number" step="0.01" min="0" dir="ltr" /></div>',
    '        <div class="emp-field"><label>الراتب الشهري</label><input id="empSalary" type="number" step="0.01" min="0" dir="ltr" /></div>',
    '        <div class="emp-field"><label>أيام الإجازة المدفوعة شهريًا</label><input id="empLeave" type="number" step="1" min="0" dir="ltr" /></div>',
    '        <div class="emp-field full">',
    '          <label>🔐 التطبيقات المسموح له يفتحها',
    '            <button type="button" class="view-btn" style="margin-inline-start:8px" onclick="empApplyRoleDefaults()">↺ الافتراضي حسب الدور</button></label>',
    '          <div id="empAppsBox" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:6px"></div>',
    '        </div>',
    '        <div class="emp-field full" id="empStoreNote" style="display:none">',
    '          <div class="warn-box" style="margin:0">بيانات المحل (الربط بمرسل والمنطقة) بتتظبط من لوحة الإدارة — الشاشة دي بتحفظ الحساب والصلاحيات والرواتب بس، واللي محفوظ هناك مابيتلمسش.</div>',
    '        </div>',
    '        <div class="emp-field full" style="font-size:.74rem;color:var(--muted)">',
    '          صلاحيات صفحات كل تطبيق بالتفصيل بتتظبط من لوحة الإدارة — المحفوظ منها مابيتلمسش من هنا.',
    '        </div>',
    '      </div>',
    '      <div class="emp-foot">',
    '        <button class="add-btn" id="empSaveBtn" onclick="saveEmp()">💾 حفظ</button>',
    '        <button class="view-btn" onclick="closeEmpModal()">إلغاء</button>',
    '        <span id="empErr" style="font-size:.8rem;color:var(--red);font-weight:700;align-self:center"></span>',
    '      </div>',
    '    </div>',
    '  </div>'
  ),
  '④ الماركب'
);

/* ═══ ⑤ الجافاسكربت — قبل paSave ═══ */
one(
  '  window.paSave = async function(pilotId, day, field, value) {',
  L(
    '  /* ═══════════ ⚙️ إدارة الموظفين ═══════════',
    '',
    '     نفس نقط نهاية لوحة الإدارة. قايمة APP_PERMS والافتراضيات حسب',
    '     الدور منسوخين منها (tiar.html ~7411) — لو اتغيّروا هناك لازم',
    '     يتغيّروا هنا، والحارس بيقارن الاتنين. */',
    '',
    '  const EMP_APPS = [',
    '    { key: "admin",      label: "🗂️ لوحة الإدارة" },',
    '    { key: "branch",     label: "🏢 تطبيق الفروع" },',
    '    { key: "callcenter", label: "📞 الكول سنتر" },',
    '    { key: "store",      label: "🏪 بوابة المحلات" },',
    '    { key: "accounts",   label: "💰 النظام المحاسبي" },',
    '    { key: "pilotacct",  label: "🧾 تقفيل الطيارين" },',
    '    { key: "damascus",   label: "🧾 تقفيل روح دمشق" },',
    '    { key: "hr",         label: "🧑‍💼 الموارد البشرية" },',
    '    { key: "hrOld",      label: "🗄️ الموارد البشرية (القديمة)" },',
    '    { key: "customer",   label: "📱 تطبيق العملاء" },',
    '    { key: "customers",  label: "👥 إدارة العملاء" },',
    '    { key: "siteadmin",  label: "🛠️ لوحة تحكم الموقع" },',
    '    { key: "perf",       label: "⭐ إدارة التقييمات" },',
    '    { key: "storesadmin",label: "🏪 إدارة المحلات" },',
    '    { key: "pilotsadmin",label: "🧑‍✈️ إدارة الطيارين" },',
    '    { key: "site",       label: "🌐 الموقع العام" },',
    '  ];',
    '',
    '  /* نفس roleDefaultApps بتاعة لوحة الإدارة حرفيًا */',
    '  function empRoleDefaults(role) {',
    '    const r = (role || "").trim();',
    '    if (["مدير", "مدير عام", "admin"].includes(r))',
    '      return ["admin","branch","store","hr","hrOld","accounts","pilotacct","callcenter","damascus","customer","customers","site","siteadmin","perf","storesadmin","pilotsadmin"];',
    '    if (["مشرف الطيارين", "pilot_supervisor"].includes(r))         return ["pilotsadmin","site"];',
    '    if (["محاسب", "مدير حسابات", "accountant"].includes(r))       return ["accounts","damascus","pilotacct","site"];',
    '    if (["موارد بشرية", "شؤون عاملين", "hr", "HR"].includes(r))   return ["hr","hrOld","perf","site"];',
    '    if (["كول سنتر", "خدمة عملاء", "callcenter"].includes(r))     return ["callcenter","customer","site"];',
    '    if (["مشرف فرع", "مشرف", "branch"].includes(r))               return ["branch","site"];',
    '    if (["صاحب محل", "تاجر", "محل", "store"].includes(r))         return ["store","site"];',
    '    return ["site"];',
    '  }',
    '',
    '  window._empUsers = null;',
    '  window._empPilots = null;',
    '  window._empEditId = null;   // null = إضافة',
    '',
    '  window.renderEmps = async function() {',
    '    renderEmpsUsers();',
    '    renderEmpsPilots();',
    '  };',
    '',
    '  async function empFetchUsers() {',
    '    const d = await PA_API.get("/api/users");',
    '    /* المحلات والعملاء ليهم شاشاتهم — هنا موظفي المنظومة بس */',
    '    window._empUsers = (d.items || []).filter(u => !["store", "customer", "pilot"].includes(u.role));',
    '  }',
    '',
    '  window.renderEmpsUsers = async function() {',
    '    const body = document.getElementById("empUsersBody");',
    '    try { await empFetchUsers(); }',
    '    catch (e) { body.innerHTML = ' + B + '<tr><td colspan="10" class="empty-row">' + D + '{ esc(e.message || "تعذّر التحميل") }</td></tr>' + B + '; return; }',
    '    const us = window._empUsers;',
    '    if (!us.length) { body.innerHTML = ' + B + '<tr><td colspan="10" class="empty-row">مفيش موظفين</td></tr>' + B + '; return; }',
    '    body.innerHTML = us.map((u, i) => ' + B + '<tr>',
    '      <td class="idx">' + D + '{i + 1}</td>',
    '      <td dir="ltr" style="font-weight:600">' + D + '{ esc(u.username) }</td>',
    '      <td>' + D + '{ esc(u.name || "—") }</td>',
    '      <td>' + D + '{ esc(u.role) }</td>',
    '      <td style="font-size:.8rem;color:var(--muted)">' + D + '{ esc(u.branchName || "—") }</td>',
    '      <td class="num">' + D + '{ paMoney(u.hourRate) }</td>',
    '      <td class="num">' + D + '{ paMoney(u.monthlySalary) }</td>',
    '      <td class="num">' + D + '{ paNum(u.paidLeaveDays) }</td>',
    '      <td>' + D + '{ u.blocked ? \'<span style="color:var(--red);font-weight:700">موقوف</span>\' : \'<span style="color:var(--green);font-weight:700">نشط</span>\' }</td>',
    '      <td>' + D + '{ u.protected ? \'<span style="color:var(--muted);font-size:.78rem">محمي</span>\'',
    '        : ' + B + '<button class="view-btn" onclick="openEmpModal(' + D + '{ Number(u.id) })">✏️ تعديل</button>',
    '           <button class="view-btn" onclick="empToggleBlock(' + D + '{ Number(u.id) }, ' + D + '{ u.blocked ? 0 : 1 })">' + D + '{ u.blocked ? "▶ تفعيل" : "⏸ إيقاف" }</button>' + B + ' }</td>',
    '    </tr>' + B + ').join("");',
    '  };',
    '',
    '  window.renderEmpsPilots = async function() {',
    '    const body = document.getElementById("empPilotsBody");',
    '    let d;',
    '    try { d = await PA_API.get("/api/pilots"); }',
    '    catch (e) { body.innerHTML = ' + B + '<tr><td colspan="8" class="empty-row">' + D + '{ esc(e.message || "تعذّر التحميل") }</td></tr>' + B + '; return; }',
    '    /* المؤرشف مش بيتحاسب — مابيظهرش هنا */',
    '    const ps = (d.items || []).filter(p => !p.archivedAt);',
    '    window._empPilots = ps;',
    '    if (!ps.length) { body.innerHTML = ' + B + '<tr><td colspan="8" class="empty-row">مفيش طيارين</td></tr>' + B + '; return; }',
    '',
    '    /* 🔴 خانات فلوس قابلة للتعديل = أرقام لاتينية (نفس لسعة paCell) */',
    '    const cell = (p, field, val, step) => ' + B + '<td class="num">',
    '      <input class="pa-in" dir="ltr" style="max-width:90px" value="' + D + '{ esc(Number(val || 0).toFixed(step === 1 ? 0 : 2)) }"',
    '             onchange="empPilotSave(' + D + '{ Number(p.id) },\'' + D + '{field}\',this.value)" /></td>' + B + ';',
    '',
    '    body.innerHTML = ps.map((p, i) => ' + B + '<tr>',
    '      <td class="idx">' + D + '{i + 1}</td>',
    '      <td style="font-weight:600">' + D + '{ esc(p.name) }</td>',
    '      <td style="font-size:.8rem;color:var(--muted)">' + D + '{ esc(p.homeBranchName || p.assignedBranchName || "—") }</td>',
    '      <td><select class="pa-in" style="max-width:110px" onchange="empPilotSave(' + D + '{ Number(p.id) },\'commissionType\',this.value)">',
    '        <option value="percent"' + D + '{ p.commissionType === "percent" ? " selected" : "" }>نسبة %</option>',
    '        <option value="fixed"' + D + '{ p.commissionType === "fixed" ? " selected" : "" }>مبلغ ثابت</option>',
    '      </select></td>',
    '      ' + D + '{ cell(p, "commissionValue", p.commissionValue) }',
    '      ' + D + '{ cell(p, "hourRate", p.hourRate) }',
    '      ' + D + '{ cell(p, "monthlySalary", p.monthlySalary) }',
    '      ' + D + '{ cell(p, "paidLeaveDays", p.paidLeaveDays, 1) }',
    '    </tr>' + B + ').join("");',
    '  };',
    '',
    '  window.empPilotSave = async function(pilotId, field, value) {',
    '    const bodyMap = {',
    '      commissionType: v => ({ commissionType: v }),',
    '      commissionValue: v => ({ commissionValue: Number(v) || 0 }),',
    '      hourRate: v => ({ hourRate: Number(v) || 0 }),',
    '      monthlySalary: v => ({ monthlySalary: Number(v) || 0 }),',
    '      paidLeaveDays: v => ({ paidLeaveDays: Math.max(0, parseInt(v, 10) || 0) }),',
    '    };',
    '    try {',
    '      await PA_API.put("/api/pilots/" + encodeURIComponent(pilotId), bodyMap[field](value));',
    '      showToast("اتحفظ", "success");',
    '      /* أرقام التقفيلة بتتغير بالسعر الجديد — نصفّرها تتجاب تاني */',
    '      window._paData = null; window._stData = null;',
    '    } catch (e) {',
    '      showToast("تعذّر الحفظ: " + (e.message || ""), "error");',
    '      renderEmpsPilots();',
    '    }',
    '  };',
    '',
    '  window.empToggleBlock = async function(id, block) {',
    '    if (block && !confirm("هيتوقف عن الدخول لكل التطبيقات. متأكد؟")) return;',
    '    try {',
    '      await PA_API.post("/api/users/" + encodeURIComponent(id) + "/block", { blocked: !!block });',
    '      renderEmpsUsers();',
    '    } catch (e) { showToast("تعذّر: " + (e.message || ""), "error"); }',
    '  };',
    '',
    '  window.empRoleChanged = function() {',
    '    const role = document.getElementById("empRole").value;',
    '    document.getElementById("empBranchField").style.display = role === "مشرف فرع" ? "" : "none";',
    '    document.getElementById("empStoreNote").style.display = role === "صاحب محل" ? "" : "none";',
    '  };',
    '',
    '  window.empApplyRoleDefaults = function() {',
    '    const want = new Set(empRoleDefaults(document.getElementById("empRole").value));',
    '    document.querySelectorAll("#empAppsBox [data-app]").forEach(cb => {',
    '      cb.checked = want.has(cb.getAttribute("data-app"));',
    '    });',
    '  };',
    '',
    '  window.openEmpModal = function(id) {',
    '    window._empEditId = id;',
    '    const u = id !== null ? (window._empUsers || []).find(x => Number(x.id) === Number(id)) : null;',
    '    document.getElementById("empModalTitle").textContent = u ? "👤 تعديل: " + (u.name || u.username) : "👤 إضافة موظف";',
    '    document.getElementById("empPassLabel").textContent = u ? "كلمة المرور (سيبها فاضية = من غير تغيير)" : "كلمة المرور *";',
    '',
    '    document.getElementById("empUsername").value = u ? u.username : "";',
    '    document.getElementById("empUsername").readOnly = !!u;',
    '    document.getElementById("empPassword").value = "";',
    '    document.getElementById("empName").value = u ? (u.name || "") : "";',
    '    document.getElementById("empRole").value = u ? u.role : "مشرف فرع";',
    '    document.getElementById("empHourRate").value = u ? Number(u.hourRate || 0).toFixed(2) : "0";',
    '    document.getElementById("empSalary").value = u ? Number(u.monthlySalary || 0).toFixed(2) : "0";',
    '    document.getElementById("empLeave").value = u ? String(u.paidLeaveDays || 0) : "0";',
    '    document.getElementById("empErr").textContent = "";',
    '',
    '    /* الفروع من بيانات البرنامج المحمّلة */',
    '    const bs = document.getElementById("empBranch");',
    '    bs.innerHTML = ' + B + '<option value="">— اختر الفرع —</option>' + B + ' +',
    '      (window._branchesData || []).map(b => ' + B + '<option value="' + D + '{ esc(b.id) }">' + D + '{ esc(b.name) }</option>' + B + ').join("");',
    '    bs.value = u && u.branchId ? String(u.branchId) : "";',
    '',
    '    const apps = new Set(u ? (u.allowedApps || []) : empRoleDefaults(document.getElementById("empRole").value));',
    '    document.getElementById("empAppsBox").innerHTML = EMP_APPS.map(a => ' + B + '',
    '      <label class="pa-chk"><input type="checkbox" data-app="' + D + '{ esc(a.key) }"' + D + '{ apps.has(a.key) ? " checked" : "" } />',
    '        <span>' + D + '{ esc(a.label) }</span></label>' + B + ').join("");',
    '',
    '    empRoleChanged();',
    '    document.getElementById("empModal").classList.add("open");',
    '  };',
    '  window.closeEmpModal = function() { document.getElementById("empModal").classList.remove("open"); };',
    '',
    '  window.saveEmp = async function() {',
    '    const err = document.getElementById("empErr");',
    '    err.textContent = "";',
    '    const username = document.getElementById("empUsername").value.trim();',
    '    const password = document.getElementById("empPassword").value;',
    '    const role     = document.getElementById("empRole").value;',
    '    const branchId = document.getElementById("empBranch").value;',
    '    if (!username) { err.textContent = "اكتب اسم المستخدم"; return; }',
    '    if (window._empEditId === null && !password) { err.textContent = "اكتب كلمة المرور"; return; }',
    '    if (role === "مشرف فرع" && !branchId) { err.textContent = "اختار الفرع"; return; }',
    '',
    '    const allowedApps = [...document.querySelectorAll("#empAppsBox [data-app]")]',
    '      .filter(cb => cb.checked).map(cb => cb.getAttribute("data-app"));',
    '',
    '    const body = {',
    '      username, role,',
    '      name: document.getElementById("empName").value.trim(),',
    '      branchId: branchId ? Number(branchId) : null,',
    '      hourRate: Number(document.getElementById("empHourRate").value) || 0,',
    '      monthlySalary: Number(document.getElementById("empSalary").value) || 0,',
    '      paidLeaveDays: Math.max(0, parseInt(document.getElementById("empLeave").value, 10) || 0),',
    '      allowedApps,',
    '    };',
    '    if (password) body.password = password;',
    '',
    '    const btn = document.getElementById("empSaveBtn");',
    '    btn.disabled = true;   // دوسة تانية بتعمل حسابين',
    '    try {',
    '      if (window._empEditId === null) {',
    '        await PA_API.post("/api/users", body);',
    '      } else {',
    '        await PA_API.put("/api/users/" + encodeURIComponent(window._empEditId), body);',
    '      }',
    '      closeEmpModal();',
    '      showToast("اتحفظ", "success");',
    '      window._stData = null;   // الرواتب اتغيرت — التقفيلة تتجاب تاني',
    '      renderEmpsUsers();',
    '    } catch (e) {',
    '      err.textContent = e.message || "تعذّر الحفظ";',
    '    } finally { btn.disabled = false; }',
    '  };',
    '',
    '  window.paSave = async function(pilotId, day, field, value) {'
  ),
  '⑤ الجافاسكربت'
);

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ إدارة الموظفين اتحطت');
