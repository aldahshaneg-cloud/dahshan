/* 🔐 شاشة الصلاحيات جوّه «تقفيل الطيارين».
 *
 * ═══ الطلب ═══
 * صاحب النظام 2026-09-01: «عايز أعمل صفحة صلاحيات زي اللي في تقفيلة
 * روح دمشق». الشاشة دي هي هي: مجموعات مفاتيح، علّم واقفل، وقوالب.
 *
 * ═══ الفرق الوحيد المقصود ═══
 * روح دمشق فيها زرار «➕ حساب مشرف» بيعمل حساب دخول بباسورد. الشاشة
 * دي **مابتعملش حسابات ولا بتكتب باسوردات** — بتتعامل مع الحسابات
 * الموجودة في النظام بس. عمل الحسابات مكانه لوحة الإدارة.
 *
 * ═══ الشاشة بتتبني من السيرفر ═══
 * `permGroups` و`presets` جايين في الرد. مفتاح جديد في الـWire بيظهر
 * هنا لوحده — مافيش قايمة مكرّرة تفضل ورا وتختلف.
 *
 * ═══ «مافيش صف» مش «مقفول» ═══
 * اللي مالوش صف بياخد كل حاجة ماعدا السلف والقفل والإعدادات. الشاشة
 * بتقول ده بالنص جنب اسمه، عشان الأدمن مايفتكرش إنه مقفول عليه وهو لأ.
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

/* المرساة `paPermSave` مش `renderPaPerms`: التانية اتذكرت في
   `switchPaTab` من السكربت اللي قبله، فكانت بتقول «موجود» وهو لسه لأ. */
if (s.includes('window.paPermSave')) { console.log('🔴 موجود قبل كده'); process.exit(1); }

/* ═══ ① زرار التبويب ═══ */
one(
  '    <button class="sub-tab" id="patab-deferred" onclick="switchPaTab(\'deferred\')">💳 السلف المؤجلة</button>\n  </div>',
  L(
    "    <button class=\"sub-tab\" id=\"patab-deferred\" onclick=\"switchPaTab('deferred')\">💳 السلف المؤجلة</button>",
    '    <!-- 🔐 للأدمن بس — `applyPaAcl` بتخفيه لغيره -->',
    '    <button class="sub-tab" id="patab-perms" style="display:none" onclick="switchPaTab(\'perms\')">🔐 الصلاحيات</button>',
    '  </div>',
    '',
    '  <div id="paAclNote"></div>',
    '  <div id="paNoAccess" style="display:none">',
    '    <div class="warn-box">لسه مالكش صلاحية على أي شاشة في البرنامج ده — كلّم الإدارة.</div>',
    '  </div>'
  ),
  '① زرار التبويب واللافتات'
);

/* ═══ ② ماركب الشاشة — بعد شاشة السلف ═══ */
one(
  '      <tbody id="paDeferredBody"><tr><td colspan="12" class="empty-row">جاري التحميل…</td></tr></tbody>',
  '      <tbody id="paDeferredBody"><tr><td colspan="12" class="empty-row">جاري التحميل…</td></tr></tbody>',
  '② (مرساة السلف — بلا تغيير)'
);

one(
  '  <div id="pa-deferred" style="display:none">',
  L(
    '  <!-- ══ 🔐 الصلاحيات — للأدمن بس ══',
    '       الشاشة بتتبني من `permGroups` الجاية من السيرفر، مش من قايمة',
    '       هنا — عشان مفتاح جديد في الـWire يظهر لوحده. -->',
    '  <div id="pa-perms" style="display:none">',
    '    <div class="sec-header">',
    '      <span class="sec-title">🔐 صلاحيات البرنامج</span>',
    '      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">',
    '        <select id="paPermUser" onchange="renderPaPermsBody()"',
    '                style="padding:8px 10px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:8px;font-size:.82rem;min-width:220px"></select>',
    '      </div>',
    '    </div>',
    '    <div id="paPermsBody"><div class="empty-row">جاري التحميل…</div></div>',
    '  </div>',
    '',
    '  <div id="pa-deferred" style="display:none">'
  ),
  '③ ماركب شاشة الصلاحيات'
);

/* ═══ ④ الرسم والحفظ — قبل paSave ═══ */
one(
  '  window.paSave = async function(pilotId, day, field, value) {',
  L(
    '  /* ═══════════ 🔐 شاشة الصلاحيات ═══════════ */',
    '',
    '  window._paPerms = null;   // الرد الكامل: users + perms + permGroups + presets',
    '  window._paPermDirty = false;',
    '',
    '  window.renderPaPerms = async function() {',
    "    const box = document.getElementById('paPermsBody');",
    '    if (!window._paPerms) {',
    '      try {',
    "        window._paPerms = await PA_API.get('/api/pilot-accounting/acl');",
    '      } catch (e) {',
    "        box.innerHTML = '<div class=\"warn-box\">' + esc(e.message || 'تعذّر تحميل الصلاحيات') + '</div>';",
    '        return;',
    '      }',
    '    }',
    '',
    "    const sel = document.getElementById('paPermUser');",
    '    const keep = sel.value;',
    '    const us = window._paPerms.users || [];',
    "    sel.innerHTML = us.length",
    '      ? us.map(u => ' + B + '<option value="' + D + '{ esc(u.id) }">' + D + '{ esc(u.name || u.username) } — ' + D + '{ esc(u.role) }' + D + '{ u.branchName ? " · " + u.branchName : "" }</option>' + B + ').join("")',
    '      : ' + B + '<option value="">— مفيش مستخدمين —</option>' + B + ';',
    '    if (keep && us.some(u => String(u.id) === String(keep))) sel.value = keep;',
    '',
    '    renderPaPermsBody();',
    '  };',
    '',
    '  window.renderPaPermsBody = function() {',
    "    const box = document.getElementById('paPermsBody');",
    '    const P = window._paPerms;',
    '    if (!P) return;',
    '',
    "    const uid = document.getElementById('paPermUser').value;",
    '    if (!uid) {',
    '      box.innerHTML = ' + B + '<div class="warn-box">مفيش حسابات موظفين في النظام. الحسابات بتتعمل من لوحة الإدارة، وبعدين بتظبّط صلاحياتها من هنا.</div>' + B + ';',
    '      return;',
    '    }',
    '',
    '    const u    = (P.users || []).find(x => String(x.id) === String(uid)) || {};',
    '    const row  = (P.perms || {})[String(uid)] || null;',
    '    const keys = row ? (row.keys || {}) : {};',
    '    const brs  = row && Array.isArray(row.branches) ? row.branches.map(String) : [];',
    '',
    '    const groups = (P.permGroups || []).map(g => {',
    '      const on = g.items.filter(it => keys[it[0]] === true).length;',
    '      return ' + B + '<div class="pa-perm-group">',
    '        <div class="pa-perm-head">',
    '          <label class="pa-chk"><input type="checkbox" ' + D + '{ on === g.items.length ? "checked" : "" }',
    '                 onchange="paPermGroup(this,' + D + "{ JSON.stringify(g.items.map(it => it[0])).replace(/\"/g, '&quot;') }" + ')" />',
    '            <b>' + D + '{ esc(g.title) }</b></label>',
    '          <span class="pa-badge">' + D + '{ on } / ' + D + '{ g.items.length }</span>',
    '        </div>',
    '        <div class="pa-perm-items">' + D + '{ g.items.map(it => ' + B + '',
    '          <label class="pa-chk"><input type="checkbox" data-permkey="' + D + '{ esc(it[0]) }"',
    '                 onchange="paPermDirty()" ' + D + '{ keys[it[0]] === true ? "checked" : "" } />',
    '            <span>' + D + '{ esc(it[1]) }</span></label>' + B + ').join("") }</div>',
    '      </div>' + B + ';',
    '    }).join("");',
    '',
    '    const presets = Object.keys(P.presets || {}).map(k =>',
    '      ' + B + '<button class="view-btn" onclick="paPermPreset(\\\'' + D + '{ escJs(k) }\\\')">📋 ' + D + '{ esc(P.presets[k].label) }</button>' + B + ').join("");',
    '',
    '    box.innerHTML = ' + B + '',
    '      <div class="pa-perm-group">',
    '        <div class="pa-perm-head">',
    '          <div><b>' + D + '{ esc(u.name || u.username) }</b>',
    '            <div style="font-size:.74rem;color:var(--muted)">' + D + '{ esc(u.username) } · ' + D + '{ esc(u.role) }' + D + '{ u.branchName ? " · " + esc(u.branchName) : "" }</div>',
    '          </div>',
    '          <span class="pa-badge" style="background:' + D + '{ row ? "rgba(34,197,94,.16)" : "rgba(249,115,22,.16)" }">',
    '            ' + D + '{ row ? "صلاحيات محدّدة" : "الافتراضي" }</span>',
    '        </div>',
    '        <div style="padding:0 14px 12px;font-size:.78rem;color:var(--muted);line-height:1.7">' + D + '{ row',
    '          ? "الصلاحيات دي محفوظة" + (row.updatedBy ? " — آخر تعديل من " + esc(row.updatedBy) : "") + "."',
    '          : "لسه مالوش صف صلاحيات، يعني شغّال بـ<b>الافتراضي</b>: كل الشاشات والأعمدة والتعديل، ماعدا السلف المؤجلة وقفل الشهر والإعدادات. أول ما تحفظ هنا، اللي معلّم عليه بس هو اللي هيشوفه." }</div>',
    '      </div>',
    '',
    '      <div class="pa-perm-group">',
    '        <div class="pa-perm-head"><b>الفروع المسموحة</b>',
    '          <span class="pa-badge">' + D + '{ brs.length ? brs.length + " فرع" : "كل الفروع" }</span></div>',
    '        <div class="pa-perm-items">' + D + '{ (P.branches || []).map(b => ' + B + '',
    '          <label class="pa-chk"><input type="checkbox" data-permbranch="' + D + '{ esc(b.id) }"',
    '                 onchange="paPermDirty()" ' + D + '{ brs.includes(String(b.id)) ? "checked" : "" } />',
    '            <span>' + D + '{ esc(b.name) }</span></label>' + B + ').join("") }</div>',
    '        <div style="padding:0 14px 12px;font-size:.76rem;color:var(--muted)">متعلّمش على حاجة = يشوف كل الفروع.</div>',
    '      </div>',
    '',
    '      ' + D + '{ groups }',
    '',
    '      <div style="position:sticky;bottom:10px;background:var(--bg);padding:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center">',
    '        <button class="add-btn" onclick="paPermSave()">💾 حفظ الصلاحيات</button>',
    '        ' + D + '{ presets }',
    '        <button class="view-btn" onclick="paPermAll(true)">✅ افتح الكل</button>',
    '        <button class="view-btn" onclick="paPermAll(false)">🚫 اقفل الكل</button>',
    '        ' + D + '{ row ? ' + B + '<button class="view-btn" onclick="paPermReset()">↩ رجّع للافتراضي</button>' + B + ' : "" }',
    '        <span id="paPermDirtyNote" style="font-size:.8rem;color:var(--orange);font-weight:700"></span>',
    '      </div>' + B + ';',
    '',
    '    window._paPermDirty = false;',
    '  };',
    '',
    '  window.paPermDirty = function() {',
    '    window._paPermDirty = true;',
    "    const n = document.getElementById('paPermDirtyNote');",
    "    if (n) n.textContent = 'فيه تغييرات مش محفوظة';",
    '  };',
    '',
    '  window.paPermGroup = function(cb, keys) {',
    "    (keys || []).forEach(k => {",
    "      const el = document.querySelector('#paPermsBody [data-permkey=\"' + k + '\"]');",
    '      if (el) el.checked = cb.checked;',
    '    });',
    '    paPermDirty();',
    '  };',
    '',
    '  window.paPermAll = function(on) {',
    "    document.querySelectorAll('#paPermsBody [data-permkey]').forEach(cb => { cb.checked = !!on; });",
    "    document.querySelectorAll('#paPermsBody .pa-perm-head input[type=checkbox]').forEach(cb => { cb.checked = !!on; });",
    '    paPermDirty();',
    '  };',
    '',
    '  window.paPermPreset = function(name) {',
    '    const p = (window._paPerms?.presets || {})[name];',
    '    if (!p) return;',
    '    const want = new Set(p.keys || []);',
    "    document.querySelectorAll('#paPermsBody [data-permkey]').forEach(cb => {",
    "      cb.checked = want.has(cb.getAttribute('data-permkey'));",
    '    });',
    '    paPermDirty();',
    '  };',
    '',
    '  window.paPermSave = async function() {',
    "    const uid = document.getElementById('paPermUser').value;",
    "    if (!uid) { showToast('اختار المستخدم', 'error'); return; }",
    '',
    '    const keys = {};',
    "    document.querySelectorAll('#paPermsBody [data-permkey]').forEach(cb => {",
    "      if (cb.checked) keys[cb.getAttribute('data-permkey')] = true;",
    '    });',
    "    const branches = [...document.querySelectorAll('#paPermsBody [data-permbranch]')]",
    "      .filter(cb => cb.checked).map(cb => Number(cb.getAttribute('data-permbranch')));",
    '',
    '    try {',
    "      await PA_API.put('/api/pilot-accounting/acl', { userId: Number(uid), keys, branches });",
    '    } catch (e) {',
    "      showToast('تعذّر الحفظ: ' + (e.message || ''), 'error');",
    '      return;',
    '    }',
    '',
    '    /* بنحدّث النسخة اللي عندنا بدل ما نجيب الرد كله تاني */',
    '    window._paPerms.perms = window._paPerms.perms || {};',
    "    window._paPerms.perms[String(uid)] = { keys, branches, updatedBy: 'الإدارة', updatedAt: null };",
    "    showToast('اتحفظت', 'success');",
    '    renderPaPermsBody();',
    '  };',
    '',
    '  window.paPermReset = async function() {',
    "    const uid = document.getElementById('paPermUser').value;",
    '    if (!uid) return;',
    "    if (!confirm('هيرجع للافتراضي: كل الشاشات والأعمدة والتعديل، ماعدا السلف وقفل الشهر والإعدادات. تمام؟')) return;",
    '    try {',
    "      await PA_API.del('/api/pilot-accounting/acl/' + encodeURIComponent(uid));",
    '    } catch (e) {',
    "      showToast('تعذّر المسح: ' + (e.message || ''), 'error');",
    '      return;',
    '    }',
    '    delete window._paPerms.perms[String(uid)];',
    "    showToast('رجع للافتراضي', 'success');",
    '    renderPaPermsBody();',
    '  };',
    '',
    '  window.paSave = async function(pilotId, day, field, value) {'
  ),
  '④ الرسم والحفظ'
);

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ شاشة الصلاحيات اتحطت');
