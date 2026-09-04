/* 🔐 تطبيق الصلاحيات في واجهة «تقفيل الطيارين».
 *
 * ═══ إزاي العمود بيتقفل ═══
 * مش بتعديل كل دالة رسم. الجداول أعمدتها ثابتة الترتيب، فالقفل بيحصل
 * بقاعدة CSS واحدة على `:nth-child(n)` — بتمسك الرأس والصف والإجمالي
 * مع بعض. لو كنت عدّلت كل `<td>` كنت هسيب سطر إجمالي أو رأس ورا،
 * والعمود يتزحلق — وده أسوأ من إنه يبان.
 *
 * ⚠️ عشان `nth-child` تفضل صادقة، سطور الإجمالي **لازم** تبقى بلا
 * `colspan`. كانوا اتنين بـcolspan (كشف الطيار والشهر) واتفكّوا هنا
 * لخلايا مفردة. لو حد رجّع `colspan` تاني الأعمدة هتتزحلق — والحارس
 * بيمسك ده.
 *
 * ═══ القفل ده مش الأمان ═══
 * الأمان في السيرفر: العمود الممنوع مش بيخرج من `month` أصلًا. اللي
 * هنا للشكل — إن الشاشة ماتبانش فيها خانة فاضية بلا سبب.
 *
 * 🔒 الحارس: ops/test_pilotacct_acl.cjs
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

if (s.includes('window.paCan')) { console.log('🔴 موجود قبل كده'); process.exit(1); }

/* ═══ ① فكّ الـcolspan من سطري الإجمالي ═══ */
one(
  '      ' + B + '<tr><td colspan="2" style="font-weight:800">الإجمالي</td><td></td><td></td><td></td><td></td>',
  '      /* بلا colspan — قفل الأعمدة بيمشي بـnth-child، وأي دمج بيزحلق العمود */\n' +
  '      ' + B + '<tr><td style="font-weight:800">الإجمالي</td><td></td><td></td><td></td><td></td><td></td>',
  '① فكّ colspan في إجمالي كشف الطيار'
);

one(
  '      ' + B + '<tr><td colspan="3" style="font-weight:800">الإجمالي</td>',
  '      /* بلا colspan — زي فوق بالظبط */\n' +
  '      ' + B + '<tr><td style="font-weight:800">الإجمالي</td><td></td><td></td>',
  '② فكّ colspan في إجمالي الشهر'
);

/* ═══ ③ الخانة بتتقفل لما مافيش act.edit ═══ */
one(
  "    const edited = (row.edited || []).includes(field);\n    const locked = !!window._paData?.locked;",
  "    const edited = (row.edited || []).includes(field);\n" +
  '    /* 🔒 مقفول = الشهر مقفول **أو** مالوش صلاحية تعديل. الاتنين\n' +
  '       بيدّوا نفس النتيجة للمستخدم: بيتفرّج. */\n' +
  "    const locked = !!window._paData?.locked || !paCan('act.edit');",
  '③ قفل خانة التعديل'
);

one(
  "    const list = row.perms || [];\n    const disp = list.length === 1",
  "    const list = row.perms || [];\n    const disp = list.length === 1",
  '④ (مرساة الاستئذان — بلا تغيير)'
);

one(
  '    const tip  = list.map((p, i) => ' + B + D + '{i + 1}) ' + D + '{p.out || "—"} → ' + D + '{p.in || "—"}' + B + ').join("\\n");\n' +
  '    const locked = !!window._paData?.locked;',
  '    const tip  = list.map((p, i) => ' + B + D + '{i + 1}) ' + D + '{p.out || "—"} → ' + D + '{p.in || "—"}' + B + ').join("\\n");\n' +
  "    const locked = !!window._paData?.locked || !paCan('act.edit');",
  '⑤ قفل خانة الاستئذان'
);

/* ═══ ⑥ أزرار القفل حسب act.lock ═══ */
one(
  L(
    '    box.innerHTML = d.locked',
    '      ? ' + B + '<div class="warn-box" style="margin-bottom:12px">🔒 الشهر ده <b>مقفول</b> — العرض بس، مفيش تعديل.',
    '           <button class="view-btn" style="margin-right:10px" onclick="paUnlock()">🔓 فتح الشهر</button></div>' + B + '',
    '      : ' + B + '<div style="margin-bottom:12px;font-size:.8rem;color:var(--muted)">',
    '           بداية اليوم <b>' + D + '{ esc(d.settings?.dayStartHour ?? 9) }:00 ص</b> — الوردية اللي بتقفل بعد نص الليل بتتسجّل على اليوم اللي فات.',
    '           <button class="view-btn" style="margin-right:10px" onclick="paLock()">🔒 اقفل الشهر</button></div>' + B + ';'
  ),
  L(
    '    /* زرار القفل بيبان لمن عنده `act.lock` بس. لو مش عنده، الشريط',
    '       بيفضل بيقول إن الشهر مقفول — الخبر ده مالوش علاقة بالصلاحية. */',
    "    const canLock = paCan('act.lock');",
    '    box.innerHTML = d.locked',
    '      ? ' + B + '<div class="warn-box" style="margin-bottom:12px">🔒 الشهر ده <b>مقفول</b> — العرض بس، مفيش تعديل.' +
      D + '{ canLock ? ' + B + '<button class="view-btn" style="margin-right:10px" onclick="paUnlock()">🔓 فتح الشهر</button>' + B + ' : "" }</div>' + B + '',
    '      : ' + B + '<div style="margin-bottom:12px;font-size:.8rem;color:var(--muted)">',
    '           بداية اليوم <b>' + D + '{ esc(d.settings?.dayStartHour ?? 9) }:00 ص</b> — الوردية اللي بتقفل بعد نص الليل بتتسجّل على اليوم اللي فات.' +
      D + '{ canLock ? ' + B + '<button class="view-btn" style="margin-right:10px" onclick="paLock()">🔒 اقفل الشهر</button>' + B + ' : "" }</div>' + B + ';'
  ),
  '⑥ أزرار قفل الشهر'
);

/* ═══ ⑦ محرّك الصلاحيات — بيتحط قبل switchPaTab ═══ */
one(
  '  window.switchPaTab = function(tab) {\n    window._paTab = tab;',
  L(
    '  /* ═══════════ 🔐 الصلاحيات ═══════════',
    '',
    '     الرد بتاع `month` بيجيب `acl` — مفاتيح المستخدم ده. الشاشة',
    '     بتتظبّط منها: تبويبات، أعمدة، وقفل التعديل.',
    '',
    '     ⚠️ ده **مش** الأمان. السيرفر مابيبعتش العمود الممنوع أصلًا،',
    '     واللي هنا عشان الشاشة ماتبانش فيها خانة فاضية بلا سبب. */',
    '',
    '  window._paAcl = null;',
    "  window.paCan = k => (window._paAcl?.keys || {})[k] === true;",
    '',
    '  /* ترتيب أعمدة كل جدول. الفهرس هنا = ترتيب العمود في الجدول،',
    '     و`null` معناه عمود بيفضل دايمًا (رقم مسلسل، اسم، فرع).',
    '     🔴 أي عمود يتضاف أو يتشال في الماركب لازم يتظبّط هنا — الحارس',
    '     بيقارن العدد بعدد `<th>` الفعلي عشان مايفضلش سر. */',
    '  const PA_GRID = {',
    "    daily: { body: 'paDailyBody', cols:",
    "      [null, null, 'col.in', 'col.bout', 'col.bin', 'col.out', 'col.hours', 'col.orders',",
    "       'col.svc', 'col.psvc', 'col.net', 'col.adv', 'col.ded', 'col.bonus', 'col.note'] },",
    "    pilot: { body: 'paPilotBody', cols:",
    "      [null, null, 'col.in', 'col.bout', 'col.bin', 'col.out', 'col.hours', 'col.orders',",
    "       'col.svc', 'col.psvc', 'col.net', 'col.adv', 'col.ded', 'col.bonus', 'col.note'] },",
    "    month: { body: 'paMonthBody', cols:",
    "      [null, null, null, 'mon.hours', 'mon.hours', 'mon.orders', 'mon.hourPay',",
    "       'mon.commission', 'mon.salary', 'mon.leave', 'mon.bonus', 'mon.adv',",
    "       'mon.ded', 'mon.deferred', 'mon.net'] },",
    '  };',
    '',
    '  /** بيطبّق الصلاحيات على الشاشة كلها — بيتندَه بعد كل تحميل */',
    '  window.applyPaAcl = function() {',
    '    const acl = window._paAcl;',
    '    if (!acl) return;',
    '',
    '    /* ── الأعمدة ── */',
    '    const rules = [];',
    '    Object.keys(PA_GRID).forEach(name => {',
    '      const g = PA_GRID[name];',
    '      const tb = document.getElementById(g.body);',
    "      const tbl = tb && tb.closest('table');",
    '      if (!tbl) return;',
    "      tbl.id = 'paTbl-' + name;",
    '      g.cols.forEach((key, i) => {',
    '        if (key && !paCan(key)) {',
    "          rules.push('#paTbl-' + name + ' tr > *:nth-child(' + (i + 1) + '){display:none}');",
    '        }',
    '      });',
    '    });',
    "    let st = document.getElementById('paAclCss');",
    "    if (!st) { st = document.createElement('style'); st.id = 'paAclCss'; document.head.appendChild(st); }",
    "    st.textContent = rules.join('\\n');",
    '',
    '    /* ── التبويبات ── */',
    "    ['daily', 'pilot', 'month', 'deferred'].forEach(t => {",
    "      const b = document.getElementById('patab-' + t);",
    "      if (b) b.style.display = paCan('page.' + t) ? '' : 'none';",
    '    });',
    "    const permTab = document.getElementById('patab-perms');",
    "    if (permTab) permTab.style.display = acl.isAdmin ? '' : 'none';",
    '',
    '    /* لو التبويب المفتوح دلوقتي مقفول عليه، ننقله لأول تبويب مسموح.',
    '       من غير ده بيفضل قاعد على شاشة فاضية ومش فاهم ليه. */',
    "    const open = ['daily', 'pilot', 'month', 'deferred']",
    "      .filter(t => paCan('page.' + t));",
    "    if (window._paTab !== 'perms' && !paCan('page.' + window._paTab)) {",
    '      if (open.length) switchPaTab(open[0]);',
    '    }',
    '',
    '    /* ── التنقل بين التواريخ ── */',
    "    const nav = !paCan('act.dateNav');",
    "    ['paMonth', 'paBranch', 'paDaySelect'].forEach(id => {",
    '      const el = document.getElementById(id);',
    '      if (el) el.disabled = nav;',
    '    });',
    '',
    '    /* ── لافتة بتقول للمستخدم إنه بيتفرّج بس ── */',
    "    const bar = document.getElementById('paAclNote');",
    '    if (bar) {',
    "      bar.innerHTML = paCan('act.edit') ? ''",
    "        : '<div class=\"warn-box\" style=\"margin-bottom:12px\">👁 عندك عرض بس — التعديل مقفول من الإدارة.</div>';",
    '    }',
    '',
    '    /* ── مافيش ولا شاشة ── */',
    "    const none = document.getElementById('paNoAccess');",
    "    if (none) none.style.display = (open.length || acl.isAdmin) ? 'none' : 'block';",
    '  };',
    '',
    '  window.switchPaTab = function(tab) {',
    '    window._paTab = tab;'
  ),
  '⑦ محرّك الصلاحيات'
);

/* ═══ ⑧ التبويبات: إضافة شاشة الصلاحيات للتبديل ═══ */
one(
  L(
    '    ["daily","pilot","month","deferred"].forEach(t => {',
    '      document.getElementById("patab-" + t)?.classList.toggle("active", t === tab);',
    '      const el = document.getElementById("pa-" + t);',
    '      if (el) el.style.display = t === tab ? "block" : "none";',
    '    });',
    '    if (tab === "daily")    renderPaDaily();',
    '    if (tab === "pilot")    renderPaPilot();',
    '    if (tab === "month")    renderPaMonth();',
    '    if (tab === "deferred") renderPaDeferred();'
  ),
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
  '⑧ تبويب الصلاحيات في التبديل'
);

/* ═══ ⑨ التقاط الـacl من الرد ═══ */
one(
  L(
    '    renderPaLock();',
    '    renderPaStats();',
    '    switchPaTab(window._paTab);'
  ),
  L(
    '    /* 🔐 الصلاحيات جاية مع الرد. بتتطبّق **قبل** الرسم عشان',
    '       الأعمدة المقفولة ماتومضش وهي بتتشال. */',
    '    window._paAcl = window._paData.acl || null;',
    '    applyPaAcl();',
    '',
    '    renderPaLock();',
    '    renderPaStats();',
    '    switchPaTab(window._paTab);'
  ),
  '⑨ التقاط الصلاحيات من الرد'
);

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ محرّك الصلاحيات اتحط في الواجهة');
