/* 👥 تقفيلة الموظفين — الواجهة في accounts.html.
 *
 * ═══ الشكل ═══
 * تبويب رئيسي «الموظفين» جواه تلات شاشات فرعية زي الطيارين بالظبط:
 * يومي (كل الموظفين في يوم) · كشف الموظف (شهر واحد لموظف) · المرتبات
 * (إجماليات الشهر). البيانات من `staff-month` والكتابة على `staff-entry`
 * و`staff-perms`.
 *
 * ═══ الصلاحيات ═══
 * التبويب كله ورا مفتاح `page.staff`، والأعمدة بتتقفل بنفس مفاتيح
 * الطيارين (col.in/col.adv/...) — السيرفر أصلًا مش بيبعت الممنوع،
 * والقفل هنا بـnth-child زي الجداول التانية عشان الشاشة ماتبانش فيها
 * خانة فاضية بلا سبب. التلات جداول اتسجلوا في PA_GRID، والحارس بيقارن
 * عدد الخريطة بعدد <th> الفعلي.
 *
 * ═══ الخانة القابلة للتعديل = رقم لاتيني ═══
 * نفس لسعة paCell الموثقة: paMoney بتطلع أرقام عربية والسيرفر بيقراها
 * (float) فبترجع صفر. أي خانة تعديل هنا بتستعمل toFixed العادي.
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

if (s.includes('renderStDaily')) { console.log('🔴 موجود قبل كده'); process.exit(1); }

/* ═══ ① زرار التبويب ═══ */
one(
  L(
    "    <button class=\"sub-tab\" id=\"patab-deferred\" onclick=\"switchPaTab('deferred')\">💳 السلف المؤجلة</button>",
    '    <!-- 🔐 للأدمن بس — `applyPaAcl` بتخفيه لغيره -->'
  ),
  L(
    "    <button class=\"sub-tab\" id=\"patab-deferred\" onclick=\"switchPaTab('deferred')\">💳 السلف المؤجلة</button>",
    "    <button class=\"sub-tab\" id=\"patab-staff\" onclick=\"switchPaTab('staff')\">👥 الموظفين</button>",
    '    <!-- 🔐 للأدمن بس — `applyPaAcl` بتخفيه لغيره -->'
  ),
  '① زرار التبويب'
);

/* ═══ ② الماركب — قبل شاشة الصلاحيات ═══ */
one(
  '  <!-- ══ 🔐 الصلاحيات — للأدمن بس ══',
  L(
    '  <!-- ══ 👥 تقفيلة الموظفين ══',
    '       الحضور تلقائي من جلسات المتصفح (attendance_sessions) —',
    '       الفجوة بين جلستين بتظهر استئذانًا. اليوم هنا ميلادي بتوقيت',
    '       القاهرة زي شاشة الحضور في الإدارة، مش يوم الطيارين التجاري. -->',
    '  <div id="pa-staff" style="display:none">',
    '    <div class="sub-tabs" style="margin-top:0">',
    '      <button class="sub-tab active" id="sttab-daily" onclick="switchStTab(\'daily\')">📋 يومي</button>',
    '      <button class="sub-tab" id="sttab-person" onclick="switchStTab(\'person\')">🧑 كشف الموظف</button>',
    '      <button class="sub-tab" id="sttab-month" onclick="switchStTab(\'month\')">💵 المرتبات</button>',
    '    </div>',
    '',
    '    <div id="st-daily">',
    '      <div class="sec-header">',
    '        <span class="sec-title">📋 حضور الموظفين — يوم واحد</span>',
    '        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">',
    '          <button class="view-btn" onclick="stDay(-1)">◀ اليوم السابق</button>',
    '          <select id="stDaySelect" onchange="renderStDaily()" style="padding:7px 10px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:8px;font-size:.82rem"></select>',
    '          <button class="view-btn" onclick="stDay(1)">اليوم التالي ▶</button>',
    '          <span id="stDayLabel" style="font-size:.82rem;color:var(--muted)"></span>',
    '        </div>',
    '      </div>',
    '      <div class="table-wrap"><table class="pa-grid">',
    '        <thead><tr>',
    '          <th style="width:34px">#</th><th style="min-width:130px">الموظف</th>',
    '          <th style="min-width:110px">الدور والفرع</th>',
    '          <th>ساعة الحضور<div class="pa-sub">من فتح المتصفح</div></th>',
    '          <th>خروج مؤقت<div class="pa-sub">استئذان</div></th>',
    '          <th>رجوع<div class="pa-sub">من الاستئذان</div></th>',
    '          <th>ساعة الانصراف</th>',
    '          <th>عدد ساعات العمل<div class="pa-sub">ناقص الاستئذان</div></th>',
    '          <th>سلف</th><th>خصومات</th><th>حوافز</th><th style="min-width:120px">ملاحظات</th>',
    '        </tr></thead>',
    '        <tbody id="stDailyBody"><tr><td colspan="12" class="empty-row">جاري التحميل…</td></tr></tbody>',
    '        <tfoot id="stDailyFoot"></tfoot>',
    '      </table></div>',
    '    </div>',
    '',
    '    <div id="st-person" style="display:none">',
    '      <div class="sec-header">',
    '        <span class="sec-title">🧑 كشف الموظف — الشهر كله</span>',
    '        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">',
    '          <select id="stPersonSelect" onchange="renderStPerson()" style="padding:7px 10px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:8px;font-size:.82rem;min-width:200px"></select>',
    '          <span id="stPersonSummary" style="font-size:.82rem;color:var(--muted)"></span>',
    '        </div>',
    '      </div>',
    '      <div class="table-wrap"><table class="pa-grid">',
    '        <thead><tr>',
    '          <th style="width:52px">اليوم</th><th style="width:70px"></th>',
    '          <th>ساعة الحضور</th><th>خروج مؤقت</th><th>رجوع</th><th>ساعة الانصراف</th>',
    '          <th>عدد ساعات العمل</th>',
    '          <th>سلف</th><th>خصومات</th><th>حوافز</th><th style="min-width:120px">ملاحظات</th>',
    '        </tr></thead>',
    '        <tbody id="stPersonBody"><tr><td colspan="11" class="empty-row">اختر موظفًا</td></tr></tbody>',
    '        <tfoot id="stPersonFoot"></tfoot>',
    '      </table></div>',
    '    </div>',
    '',
    '    <div id="st-month" style="display:none">',
    '      <div class="sec-header"><span class="sec-title">💵 مرتبات الموظفين — تقفيلة الشهر</span></div>',
    '      <div class="table-wrap"><table>',
    '        <thead><tr>',
    '          <th style="width:34px">#</th><th>الموظف</th><th>الدور</th><th>الفرع</th>',
    '          <th class="num">الساعات</th><th class="num">أيام الشغل</th>',
    '          <th class="num">أجر الساعات</th><th class="num">نصيب الراتب</th>',
    '          <th class="num">إجازة مدفوعة</th><th class="num">حوافز</th>',
    '          <th class="num">سلف</th><th class="num">خصومات</th>',
    '          <th class="num" style="color:var(--green)">صافي المستحق</th>',
    '        </tr></thead>',
    '        <tbody id="stMonthBody"><tr><td colspan="13" class="empty-row">جاري التحميل…</td></tr></tbody>',
    '        <tfoot id="stMonthFoot"></tfoot>',
    '      </table></div>',
    '      <div style="font-size:.78rem;color:var(--muted);margin-top:10px">',
    '        نفس معادلات تقفيلة روح دمشق: أجر الساعات = الساعات × سعر ساعة الموظف ·',
    '        نصيب الراتب = الراتب الشهري ÷ الأيام المحسوبة × أيام الشغل ·',
    '        الغياب بياكل من رصيد الإجازة المدفوعة الأول. الأسعار بتتظبط من «إدارة الموظفين».',
    '      </div>',
    '    </div>',
    '  </div>',
    '',
    '  <!-- ══ 🔐 الصلاحيات — للأدمن بس ══'
  ),
  '② ماركب التلات شاشات'
);

/* ═══ ③ التبديل: staff في القايمة + جلب أول فتح ═══ */
one(
  L(
    '    ["daily","pilot","month","deferred","perms"].forEach(t => {',
    '      document.getElementById("patab-" + t)?.classList.toggle("active", t === tab);',
    '      const el = document.getElementById("pa-" + t);',
    '      if (el) el.style.display = t === tab ? "block" : "none";',
    '    });',
    '    if (tab === "daily")    renderPaDaily();',
    '    if (tab === "pilot")    renderPaPilot();',
    '    if (tab === "month")    renderPaMonth();',
    '    if (tab === "deferred") renderPaDeferred();',
    '    if (tab === "perms")    renderPaPerms();'
  ),
  L(
    '    ["daily","pilot","month","deferred","staff","perms","emps"].forEach(t => {',
    '      document.getElementById("patab-" + t)?.classList.toggle("active", t === tab);',
    '      const el = document.getElementById("pa-" + t);',
    '      if (el) el.style.display = t === tab ? "block" : "none";',
    '    });',
    '    if (tab === "daily")    renderPaDaily();',
    '    if (tab === "pilot")    renderPaPilot();',
    '    if (tab === "month")    renderPaMonth();',
    '    if (tab === "deferred") renderPaDeferred();',
    '    if (tab === "staff")    loadStaff();',
    '    if (tab === "perms")    renderPaPerms();',
    '    if (tab === "emps")     renderEmps();'
  ),
  '③ التبديل'
);

/* ═══ ④ صلاحيات التبويب والأعمدة ═══ */
one(
  "    ['daily', 'pilot', 'month', 'deferred'].forEach(t => {",
  "    ['daily', 'pilot', 'month', 'deferred', 'staff'].forEach(t => {",
  '④أ التبويب في applyPaAcl'
);

one(
  L(
    "    const open = ['daily', 'pilot', 'month', 'deferred']",
    "      .filter(t => paCan('page.' + t));"
  ),
  L(
    "    const open = ['daily', 'pilot', 'month', 'deferred', 'staff']",
    "      .filter(t => paCan('page.' + t));"
  ),
  '④ب قايمة المفتوح'
);

one(
  L(
    "    if (window._paTab !== 'perms' && !paCan('page.' + window._paTab)) {",
    '      if (open.length) switchPaTab(open[0]);',
    '    }'
  ),
  L(
    "    if (window._paTab !== 'perms' && window._paTab !== 'emps' && !paCan('page.' + window._paTab)) {",
    '      if (open.length) switchPaTab(open[0]);',
    '    }'
  ),
  '④ج استثناء تبويبات الأدمن'
);

one(
  L(
    "    month: { body: 'paMonthBody', cols:",
    "      [null, null, null, 'mon.hours', 'mon.hours', 'mon.orders', 'mon.hourPay',",
    "       'mon.commission', 'mon.salary', 'mon.leave', 'mon.bonus', 'mon.adv',",
    "       'mon.ded', 'mon.deferred', 'mon.net'] },",
    '  };'
  ),
  L(
    "    month: { body: 'paMonthBody', cols:",
    "      [null, null, null, 'mon.hours', 'mon.hours', 'mon.orders', 'mon.hourPay',",
    "       'mon.commission', 'mon.salary', 'mon.leave', 'mon.bonus', 'mon.adv',",
    "       'mon.ded', 'mon.deferred', 'mon.net'] },",
    "    /* جداول الموظفين — نفس مفاتيح الأعمدة، فقفل «سلف» مثلًا بيمسك",
    "       الطيارين والموظفين مع بعض. */",
    "    stdaily: { body: 'stDailyBody', cols:",
    "      [null, null, null, 'col.in', 'col.bout', 'col.bin', 'col.out', 'col.hours',",
    "       'col.adv', 'col.ded', 'col.bonus', 'col.note'] },",
    "    stperson: { body: 'stPersonBody', cols:",
    "      [null, null, 'col.in', 'col.bout', 'col.bin', 'col.out', 'col.hours',",
    "       'col.adv', 'col.ded', 'col.bonus', 'col.note'] },",
    "    stmonth: { body: 'stMonthBody', cols:",
    "      [null, null, null, null, 'mon.hours', 'mon.hours', 'mon.hourPay', 'mon.salary',",
    "       'mon.leave', 'mon.bonus', 'mon.adv', 'mon.ded', 'mon.net'] },",
    '  };'
  ),
  '④د خرايط الأعمدة'
);

/* ═══ ⑤ الجافاسكربت — قبل شاشة الصلاحيات ═══ */
one(
  '  /* ═══════════ 🔐 شاشة الصلاحيات ═══════════ */',
  L(
    '  /* ═══════════ 👥 تقفيلة الموظفين ═══════════',
    '',
    '     نفس عقد شاشات الطيارين: `_stData` مرآة `_paData`، والتحديث من',
    '     نفس فلتري الشهر والفرع فوق. `loadPilotAcct` بتصفّر `_stData`',
    '     فأي تغيير شهر/فرع بيجيب حضور الموظفين من جديد أول فتح للتبويب. */',
    '',
    '  window._stData = null;',
    '  window._stTab = "daily";',
    '',
    '  window.switchStTab = function(tab) {',
    '    window._stTab = tab;',
    '    ["daily","person","month"].forEach(t => {',
    '      document.getElementById("sttab-" + t)?.classList.toggle("active", t === tab);',
    '      const el = document.getElementById("st-" + t);',
    '      if (el) el.style.display = t === tab ? "block" : "none";',
    '    });',
    '    if (tab === "daily")  renderStDaily();',
    '    if (tab === "person") renderStPerson();',
    '    if (tab === "month")  renderStMonth();',
    '  };',
    '',
    '  window.stDay = function(step) {',
    '    const sel = document.getElementById("stDaySelect");',
    '    const n = Math.max(1, Math.min((window._stData?.daysInMonth || 31), (Number(sel.value) || 1) + step));',
    '    sel.value = String(n);',
    '    renderStDaily();',
    '  };',
    '',
    '  window.loadStaff = async function() {',
    '    if (window._stData) { switchStTab(window._stTab); return; }',
    '    const monthEl = document.getElementById("paMonth");',
    '    if (!monthEl.value) monthEl.value = new Date().toISOString().slice(0, 7);',
    '    const branchId = document.getElementById("paBranch").value;',
    '    document.getElementById("stDailyBody").innerHTML =',
    '      ' + B + '<tr><td colspan="12" class="empty-row">جاري التحميل…</td></tr>' + B + ';',
    '    try {',
    '      const q = new URLSearchParams({ month: monthEl.value });',
    '      if (branchId) q.set("branchId", branchId);',
    '      window._stData = await PA_API.get("/api/pilot-accounting/staff-month?" + q.toString());',
    '    } catch (e) {',
    '      document.getElementById("stDailyBody").innerHTML =',
    '        ' + B + '<tr><td colspan="12" class="empty-row">' + D + '{ esc(e.message || "تعذّر التحميل") }</td></tr>' + B + ';',
    '      return;',
    '    }',
    '',
    '    const nd = window._stData.daysInMonth || 31;',
    '    const ds = document.getElementById("stDaySelect");',
    '    const keep = Number(ds.value) || 0;',
    '    ds.innerHTML = Array.from({ length: nd }, (_, i) =>',
    '      ' + B + '<option value="' + D + '{i + 1}">يوم ' + D + '{i + 1}</option>' + B + ').join("");',
    '    ds.value = String(Math.min(nd, keep || window._stData.countedDays || 1));',
    '',
    '    const ps = document.getElementById("stPersonSelect");',
    '    const keepP = ps.value;',
    '    ps.innerHTML = (window._stData.staff || [])',
    '      .map(u => ' + B + '<option value="' + D + '{ esc(u.userId) }">' + D + '{ esc(u.name || u.username) } — ' + D + '{ esc(paRole(u.role)) }</option>' + B + ').join("")',
    '      || ' + B + '<option value="">— مفيش موظفين —</option>' + B + ';',
    '    if (keepP) ps.value = keepP;',
    '',
    '    switchStTab(window._stTab);',
    '  };',
    '',
    '  /* خانة موظف — نفس عقد paCell: رقم لاتيني في الخانة القابلة للتعديل',
    '     (paMoney العربية بترجع صفر عند القراءة على السيرفر) والمتعدّلة',
    '     بالإيد بتتلوّن ورقم المتصفح في التلميحة. */',
    '  function stCell(uid, day, field, row, opts = {}) {',
    '    const edited = (row.edited || []).includes(field);',
    "    const locked = !!window._stData?.locked || !paCan('act.edit');",
    '    const val = opts.money ? Number(row[field] ?? 0).toFixed(2) : (row[field] ?? "");',
    '    const auto = row.auto?.[field];',
    '    const tip = edited && auto !== undefined && auto !== null',
    '      ? ' + B + 'رقم المتصفح: ' + D + '{ opts.money ? Number(auto).toFixed(2) : auto }' + B + '',
    '      : "";',
    '    return ' + B + '<td class="' + D + '{edited ? "pa-edited" : ""}"' + D + '{tip ? ' + B + ' title="' + D + '{ esc(tip) }"' + B + ' : ""}>',
    '      <input class="pa-in" value="' + D + '{ esc(val) }" ' + D + '{locked ? "readonly" : ""}',
    '             onchange="stSave(' + D + '{ Number(uid) },' + D + '{day},\'' + D + '{field}\',this.value)" /></td>' + B + ';',
    '  }',
    '',
    '  function stPermCell(uid, day, row) {',
    '    const list = row.perms || [];',
    '    const disp = list.length === 1 ? (arguments[3] === "out" ? (list[0].out || "") : (list[0].in || "")) : (list.length > 1 ? list.length + " استئذان" : "");',
    '    const tip  = list.map((p, i) => ' + B + '' + D + '{i + 1}) ' + D + '{p.out || "—"} → ' + D + '{p.in || "—"}' + B + ').join("\\n");',
    "    const locked = !!window._stData?.locked || !paCan('act.edit');",
    '    return ' + B + '<td><input class="pa-in pa-pick" readonly value="' + D + '{ esc(disp) }"' + D + '{tip ? ' + B + ' title="' + D + '{ esc(tip) }"' + B + ' : ""}',
    '      ' + D + '{locked ? "" : ' + B + 'onclick="openStPerms(' + D + '{ Number(uid) },' + D + '{day})"' + B + '} /></td>' + B + ';',
    '  }',
    '',
    '  function stRowCells(uid, row) {',
    '    return stCell(uid, row.day, "in", row)',
    '      + stPermCell(uid, row.day, row, "out")',
    '      + stPermCell(uid, row.day, row, "in")',
    '      + stCell(uid, row.day, "out", row)',
    '      + stCell(uid, row.day, "hours", row, { money: true })',
    '      + stCell(uid, row.day, "adv", row, { money: true })',
    '      + stCell(uid, row.day, "ded", row, { money: true })',
    '      + stCell(uid, row.day, "bonus", row, { money: true })',
    '      + stCell(uid, row.day, "note", row);',
    '  }',
    '',
    '  window.renderStDaily = function() {',
    '    const d = window._stData;',
    '    const body = document.getElementById("stDailyBody");',
    '    if (!d) return;',
    '    const day = Number(document.getElementById("stDaySelect").value) || 1;',
    '    document.getElementById("stDayLabel").textContent =',
    '      ' + B + '' + D + '{day} ' + D + '{paWeekday(d.month, day)} · ' + D + '{d.month}' + B + ';',
    '',
    '    const us = d.staff || [];',
    '    if (!us.length) { body.innerHTML = ' + B + '<tr><td colspan="12" class="empty-row">مفيش موظفين</td></tr>' + B + '; return; }',
    '',
    '    body.innerHTML = us.map((u, i) => {',
    '      const row = (u.days || [])[day - 1] || { day };',
    '      return ' + B + '<tr><td class="idx">' + D + '{i + 1}</td>',
    '        <td style="font-weight:600">' + D + '{ esc(u.name || u.username) }</td>',
    '        <td style="font-size:.76rem;color:var(--muted)">' + D + '{ esc(paRole(u.role)) }' + D + '{ u.branchName ? "<br>" + esc(u.branchName) : "" }</td>',
    '        ' + D + '{ stRowCells(u.userId, row) }</tr>' + B + ';',
    '    }).join("");',
    '',
    '    const t = k => us.reduce((s, u) => s + (Number((u.days || [])[day - 1]?.[k]) || 0), 0);',
    '    document.getElementById("stDailyFoot").innerHTML =',
    '      ' + B + '<tr><td></td><td style="font-weight:800">الإجمالي</td><td></td><td></td><td></td><td></td><td></td>',
    '        <td class="pa-tot">' + D + '{ paMoney(t("hours")) }</td>',
    '        <td class="pa-tot">' + D + '{ paMoney(t("adv")) }</td><td class="pa-tot">' + D + '{ paMoney(t("ded")) }</td>',
    '        <td class="pa-tot">' + D + '{ paMoney(t("bonus")) }</td><td></td></tr>' + B + ';',
    '  };',
    '',
    '  window.renderStPerson = function() {',
    '    const d = window._stData;',
    '    const body = document.getElementById("stPersonBody");',
    '    if (!d) return;',
    '    const uid = document.getElementById("stPersonSelect").value;',
    '    const u = (d.staff || []).find(x => String(x.userId) === String(uid));',
    '    if (!u) { body.innerHTML = ' + B + '<tr><td colspan="11" class="empty-row">اختر موظفًا</td></tr>' + B + '; return; }',
    '',
    '    const t = u.totals || {};',
    '    document.getElementById("stPersonSummary").innerHTML =',
    '      ' + B + 'سعر الساعة <b>' + D + '{ paMoney(u.hourRate) }</b> · راتب شهري <b>' + D + '{ paMoney(u.monthlySalary) }</b>',
    '       · أيام شغل <b>' + D + '{ paNum(t.worked) }</b> من <b>' + D + '{ paNum(t.countedDays) }</b>',
    '       · إجازة مدفوعة <b>' + D + '{ paNum(t.leaveDays) }</b> (باقي ' + D + '{ paNum(t.leaveLeft) })',
    '       · <b style="color:var(--green)">صافي ' + D + '{ paMoney(t.netDue) } ج.م</b>' + B + ';',
    '',
    '    body.innerHTML = (u.days || []).map(row => ' + B + '<tr>',
    '        <td style="font-weight:700">' + D + '{ row.day }</td>',
    '        <td class="idx">' + D + '{ esc(paWeekday(d.month, row.day)) }</td>',
    '        ' + D + '{ stRowCells(u.userId, row) }</tr>' + B + ').join("");',
    '',
    '    document.getElementById("stPersonFoot").innerHTML =',
    '      ' + B + '<tr><td style="font-weight:800">الإجمالي</td><td></td><td></td><td></td><td></td><td></td>',
    '        <td class="pa-tot">' + D + '{ paMoney(t.hours) }</td>',
    '        <td class="pa-tot">' + D + '{ paMoney(t.adv) }</td><td class="pa-tot">' + D + '{ paMoney(t.ded) }</td>',
    '        <td class="pa-tot">' + D + '{ paMoney(t.bonus) }</td><td></td></tr>' + B + ';',
    '  };',
    '',
    '  window.renderStMonth = function() {',
    '    const d = window._stData;',
    '    const body = document.getElementById("stMonthBody");',
    '    if (!d) return;',
    '    const us = d.staff || [];',
    '    if (!us.length) { body.innerHTML = ' + B + '<tr><td colspan="13" class="empty-row">مفيش موظفين</td></tr>' + B + '; return; }',
    '',
    '    body.innerHTML = us.map((u, i) => {',
    '      const t = u.totals || {};',
    '      return ' + B + '<tr>',
    '        <td class="idx">' + D + '{i + 1}</td>',
    '        <td><b>' + D + '{ esc(u.name || u.username) }</b></td>',
    '        <td style="font-size:.8rem;color:var(--muted)">' + D + '{ esc(paRole(u.role)) }</td>',
    '        <td style="font-size:.8rem;color:var(--muted)">' + D + '{ esc(u.branchName || "—") }</td>',
    '        <td class="num">' + D + '{ paMoney(t.hours) }</td>',
    '        <td class="num">' + D + '{ paNum(t.worked) }</td>',
    '        <td class="num">' + D + '{ paMoney(t.hourPay) }</td>',
    '        <td class="num">' + D + '{ paMoney(t.salaryShare) }</td>',
    '        <td class="num">' + D + '{ paMoney(t.leavePay) }<div class="pa-sub">' + D + '{ paNum(t.leaveDays) } يوم</div></td>',
    '        <td class="num">' + D + '{ paMoney(t.bonusDue) }</td>',
    '        <td class="num" style="color:var(--orange)">' + D + '{ paMoney(t.advanceDue) }</td>',
    '        <td class="num" style="color:var(--orange)">' + D + '{ paMoney(t.deductionDue) }</td>',
    '        <td class="num" style="color:var(--green);font-weight:800">' + D + '{ paMoney(t.netDue) }</td>',
    '      </tr>' + B + ';',
    '    }).join("");',
    '',
    '    const sm = k => us.reduce((a, u) => a + (Number(u.totals?.[k]) || 0), 0);',
    '    document.getElementById("stMonthFoot").innerHTML =',
    '      ' + B + '<tr><td style="font-weight:800">الإجمالي</td><td></td><td></td><td></td>',
    '        <td class="num pa-tot">' + D + '{ paMoney(sm("hours")) }</td><td class="num pa-tot">' + D + '{ paNum(sm("worked")) }</td>',
    '        <td class="num pa-tot">' + D + '{ paMoney(sm("hourPay")) }</td><td class="num pa-tot">' + D + '{ paMoney(sm("salaryShare")) }</td>',
    '        <td class="num pa-tot">' + D + '{ paMoney(sm("leavePay")) }</td><td class="num pa-tot">' + D + '{ paMoney(sm("bonusDue")) }</td>',
    '        <td class="num pa-tot">' + D + '{ paMoney(sm("advanceDue")) }</td><td class="num pa-tot">' + D + '{ paMoney(sm("deductionDue")) }</td>',
    '        <td class="num pa-tot" style="color:var(--green)">' + D + '{ paMoney(sm("netDue")) }</td></tr>' + B + ';',
    '  };',
    '',
    '  window.stSave = async function(userId, day, field, value) {',
    '    try {',
    '      await PA_API.post("/api/pilot-accounting/staff-entry",',
    '        { month: window._stData.month, userId, day, field, value });',
    '    } catch (e) {',
    '      showToast("تعذّر الحفظ: " + (e.message || ""), "error");',
    '    }',
    '    window._stData = null;',
    '    loadStaff();',
    '  };',
    '',
    '  window.openStPerms = function(userId, day) {',
    '    const u = (window._stData?.staff || []).find(x => String(x.userId) === String(userId));',
    '    const row = u?.days?.[day - 1];',
    '    const cur = (row?.perms || []).map(x => ' + B + '' + D + '{x.out || ""}-' + D + '{x.in || ""}' + B + ').join("، ");',
    '    const txt = prompt(',
    '      "فترات الاستئذان لليوم " + day + "\\nاكتبها بالشكل: 12:00-13:30\\nوأكتر من فترة بينهم فاصلة.\\nسيبها فاضية عشان ترجع فجوات المتصفح التلقائية.",',
    '      cur',
    '    );',
    '    if (txt === null) return;',
    '    const periods = txt.split(/[،,]/).map(x => x.trim()).filter(Boolean).map(x => {',
    '      const [o, i] = x.split("-").map(v => (v || "").trim());',
    '      return { out: o || "", in: i || "" };',
    '    });',
    '    PA_API.post("/api/pilot-accounting/staff-perms", { month: window._stData.month, userId, day, periods })',
    '      .then(() => { window._stData = null; loadStaff(); })',
    '      .catch(e => showToast("تعذّر الحفظ: " + (e.message || ""), "error"));',
    '  };',
    '',
    '  /* ═══════════ 🔐 شاشة الصلاحيات ═══════════ */'
  ),
  '⑤ جافاسكربت التقفيلة'
);

/* ═══ ⑥ تغيير الشهر/الفرع بيصفّر بيانات الموظفين ═══ */
one(
  L(
    '    renderPaLock();',
    '    renderPaStats();',
    '    switchPaTab(window._paTab);'
  ),
  L(
    '    /* بيانات الموظفين مربوطة بنفس الشهر والفرع — أي تحديث هنا',
    '       بيصفّرها عشان تتجاب من جديد أول فتح لتبويبها. */',
    '    window._stData = null;',
    '',
    '    renderPaLock();',
    '    renderPaStats();',
    '    switchPaTab(window._paTab);'
  ),
  '⑥ تصفير عند التحديث'
);

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ واجهة تقفيلة الموظفين اتحطت');
