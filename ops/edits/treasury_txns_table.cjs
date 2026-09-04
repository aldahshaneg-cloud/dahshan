/* جدول حركات الخزن تحت جدول الخزن في لوحة الإدارة.
 *
 * ═══ الطلب (صاحب النظام 2026-09-01) ═══
 * «في الخزنة في الإدارة أريد تحت الخزنة الجدول بالمعاملات اللي بتحصل
 * في الفروع».
 *
 * ═══ البيانات محمّلة أصلاً ═══
 * `refreshCashTxns` بتجيب حركات كل خزنة وبتحطّها في `window._cashTxnsData`،
 * وكانت بتتستعمل في **عمود واحد** بس: «آخر عملية». الجدول ده بيعرضها.
 * فمافيش نداء جديد ولا مسار جديد — عرض للي موجود.
 *
 * ═══ التوقيت ═══
 * `WireTime::toWire` بيطلّع ISO بـ`Z` (`...T14:30:00.000Z`)، فـ
 * `new Date(x).toLocaleString("ar-EG")` بتحوّل للتوقيت المحلي صح. ودي
 * الطريقة المتبعة في اللوحة (١٥ موضع) — بنمشي عليها مش بنخترع غيرها.
 *
 * ═══ ليه سقف على المعروض ═══
 * يوم شغل عادي ممكن يطلّع مئات الحركات، وعرضهم كلهم بيبطّئ الصفحة
 * ويخلّي المهم ضايع. بنعرض ٥٠ والزرار بيزوّد — والعدد الكامل مكتوب
 * قدام العين عشان محدش يفتكر إن دول كل الحركات.
 */
const fs = require('fs');
const F = 'public/tiar.html';
let s = fs.readFileSync(F, 'utf8');
let bad = 0;

const one = (old, neu, label) => {
  const n = s.split(old).length - 1;
  if (n !== 1) { console.log('  🔴 ' + label + ': اتلقت ' + n + ' مرة'); bad++; return; }
  s = s.replace(old, neu);
  console.log('  ✓ ' + label);
};
const L = (...x) => x.join('\n');
const D = String.fromCharCode(36);
const B = String.fromCharCode(96);

/* ═══ ① الماركب — تحت جدول الخزن ═══ */
/* المرساة: آخر جدول الخزن جوه `page-treasury`. «📊 التقارير» صفحة
   منفصلة (`page-reports`) — كانت مرساتي الأولى وكانت غلط. */
one(
  L('          <tbody id="branchTreasuryBody"><tr><td colspan="6" class="empty-row">جاري التحميل…</td></tr></tbody>',
    '        </table>',
    '      </div>',
    '    </div>'),
  L('          <tbody id="branchTreasuryBody"><tr><td colspan="6" class="empty-row">جاري التحميل…</td></tr></tbody>',
    '        </table>',
    '      </div>',
    '',
    '      <!-- 📋 حركات الخزن — البيانات محمّلة أصلاً في `_cashTxnsData`',
    '           وكانت بتتستعمل في عمود «آخر عملية» بس. -->',
    '      <div class="sec-header" style="margin-top:26px">',
    '        <span class="sec-title">📋 حركات الخزن <span id="txnCount" style="font-size:.78rem;color:var(--muted);font-weight:600"></span></span>',
    '        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">',
    '          <select id="txnStoreFilter" onchange="renderCashTxns()"',
    '                  style="padding:7px 10px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:8px;font-size:.82rem">',
    '            <option value="">كل الخزن</option></select>',
    '          <select id="txnTypeFilter" onchange="renderCashTxns()"',
    '                  style="padding:7px 10px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:8px;font-size:.82rem">',
    '            <option value="">كل الأنواع</option>',
    '            <option value="in">وارد</option>',
    '            <option value="out">منصرف</option>',
    '            <option value="pending">معلّق</option>',
    '          </select>',
    '        </div>',
    '      </div>',
    '      <div class="table-wrap">',
    '        <table>',
    '          <thead><tr>',
    '            <th style="width:150px">التاريخ</th>',
    '            <th>الخزنة</th><th>الفرع</th>',
    '            <th style="width:90px">النوع</th>',
    '            <th style="width:110px">المبلغ</th>',
    '            <th>السبب</th><th style="width:120px">بواسطة</th>',
    '          </tr></thead>',
    '          <tbody id="cashTxnsBody"><tr><td colspan="7" class="empty-row">جاري التحميل…</td></tr></tbody>',
    '        </table>',
    '      </div>',
    '      <div style="text-align:center;margin-top:12px">',
    '        <button id="txnMoreBtn" class="view-btn" style="display:none" onclick="txnShowMore()">عرض المزيد</button>',
    '      </div>',
    '    </div>'),
  '① الماركب');

/* ═══ ② الرسم ═══ */
one(
  '    window.renderBranchTreasury = function() {',
  L('    /* السقف المعروض. يوم شغل عادي ممكن يطلّع مئات الحركات، وعرضهم',
    '       كلهم بيبطّئ الصفحة ويخلّي المهم ضايع. */',
    '    window._txnLimit = 50;',
    '    window.txnShowMore = function() {',
    '      window._txnLimit += 50;',
    '      renderCashTxns();',
    '    };',
    '',
    '    /**',
    '     * 📋 جدول حركات الخزن — بيقرا من `_cashTxnsData` اللي',
    '     * `refreshCashTxns` بتملاه. مافيش نداء جديد.',
    '     */',
    '    window.renderCashTxns = function() {',
    '      const body = document.getElementById("cashTxnsBody");',
    '      if (!body) return;',
    '      const stores = window._cashStoresData || [];',
    '      const all    = window._cashTxnsData   || [];',
    '',
    '      /* قايمة الخزن في الفلتر بتتبني من الخزن الموجودة فعلًا —',
    '         مش من قايمة الفروع كلها، عشان مايبانش فرع مالوش خزنة. */',
    '      const sel = document.getElementById("txnStoreFilter");',
    '      if (sel && sel.options.length <= 1 && stores.length) {',
    '        const keep = sel.value;',
    '        sel.innerHTML = ' + B + '<option value="">كل الخزن</option>' + B + ' +',
    '          stores.map(x => ' + B + '<option value="' + D + '{ esc(x.id) }">' + D + '{ esc(x.name) }</option>' + B + ').join("");',
    '        sel.value = keep;',
    '      }',
    '',
    '      const fStore = sel ? sel.value : "";',
    '      const fType  = document.getElementById("txnTypeFilter")?.value || "";',
    '',
    '      const rows = all',
    '        .filter(t => !fStore || String(t.storeId) === String(fStore))',
    '        .filter(t => !fType  || t.type === fType)',
    '        /* الأحدث الأول — الترتيب على النص شغّال لأن `toWire` بيطلّع',
    '           ISO بصيغة ثابتة (بادئة صفر وZ)، فالمقارنة النصية = زمنية. */',
    '        .sort((a, b) => String(b.createdAt || "").localeCompare(String(a.createdAt || "")));',
    '',
    '      const cnt = document.getElementById("txnCount");',
    '      if (cnt) cnt.textContent = rows.length ? ' + B + '(' + D + '{rows.length} حركة)' + B + ' : "";',
    '',
    '      if (!rows.length) {',
    '        body.innerHTML = ' + B + '<tr><td colspan="7" class="empty-row">مافيش حركات' + D + '{fStore || fType ? " بالفلتر ده" : " لسه"}</td></tr>' + B + ';',
    '        const mb = document.getElementById("txnMoreBtn"); if (mb) mb.style.display = "none";',
    '        return;',
    '      }',
    '',
    '      const TYPE = {',
    '        in:      { t: "وارد",   c: "var(--green)",  bg: "rgba(34,197,94,.14)" },',
    '        out:     { t: "منصرف",  c: "var(--red)",    bg: "rgba(232,25,44,.14)" },',
    '        pending: { t: "معلّق",   c: "var(--orange)", bg: "rgba(249,115,22,.14)" },',
    '      };',
    '',
    '      const shown = rows.slice(0, window._txnLimit);',
    '      body.innerHTML = shown.map(t => {',
    '        const st = stores.find(x => String(x.id) === String(t.storeId));',
    '        const ty = TYPE[t.type] || { t: t.type, c: "var(--muted)", bg: "transparent" };',
    '        /* الوقت: `toWire` بيبعت ISO بـZ فالتحويل للمحلي بيحصل صح.',
    '           نفس الطريقة المتبعة في باقي اللوحة. */',
    '        const when = t.createdAt ? new Date(t.createdAt).toLocaleString("ar-EG") : "—";',
    '        /* الخزنة اللي بلا فرع = خزنة الإدارة. بنقولها بالنص بدل «—»',
    '           عشان تبان مقصودة مش بيانات ناقصة — زي جدول الخزن فوق. */',
    '        const br = st && !st.branchId',
    '          ? ' + B + '<span style="color:var(--purple,#a855f7);font-weight:700">🏛️ الإدارة</span>' + B + '',
    '          : esc((st && st.branchName) || "—");',
    '        return ' + B + '<tr>',
    '          <td style="font-size:.78rem;color:var(--muted);white-space:nowrap">' + D + '{ esc(when) }</td>',
    '          <td>' + D + '{ esc((st && st.name) || "—") }</td>',
    '          <td>' + D + '{br}</td>',
    '          <td><span style="background:' + D + '{ty.bg};color:' + D + '{ty.c};padding:3px 9px;border-radius:6px;font-size:.74rem;font-weight:700">' + D + '{ esc(ty.t) }</span></td>',
    '          <td style="font-weight:800;color:' + D + '{ty.c};white-space:nowrap">' + D + '{ esc((Number(t.amount) || 0).toFixed(2)) } ج.م</td>',
    '          <td style="font-size:.82rem">' + D + '{ esc(t.reason || "—") }' + D + '{ t.notes ? ' + B + '<div style="font-size:.74rem;color:var(--muted);margin-top:2px">' + D + '{ esc(t.notes) }</div>' + B + ' : "" }</td>',
    '          <td style="font-size:.78rem;color:var(--muted)">' + D + '{ esc(t.createdBy || "—") }</td>',
    '        </tr>' + B + ';',
    '      }).join("");',
    '',
    '      const mb = document.getElementById("txnMoreBtn");',
    '      if (mb) {',
    '        const left = rows.length - shown.length;',
    '        mb.style.display = left > 0 ? "" : "none";',
    '        mb.textContent = ' + B + 'عرض المزيد (' + D + '{left} فاضلين)' + B + ';',
    '      }',
    '    };',
    '',
    '    window.renderBranchTreasury = function() {'),
  '② الرسم');

/* ═══ ③ الوصل بالتحديث ═══ */
one(
  L('      const total = stores.reduce((s,st) => s + (Number(st.balance)||0), 0);',
    '      const el = document.getElementById("branchTreasuryTotal");',
    '      if (el) el.textContent = total.toFixed(2) + " ج.م";',
    '    };'),
  L('      const total = stores.reduce((s,st) => s + (Number(st.balance)||0), 0);',
    '      const el = document.getElementById("branchTreasuryTotal");',
    '      if (el) el.textContent = total.toFixed(2) + " ج.م";',
    '      // جدول الحركات بيترسم مع كل تحديث للخزن — نفس مصدر البيانات',
    '      window.renderCashTxns();',
    '    };'),
  '③ الوصل بالتحديث');

if (bad) { console.log('\n🔴 ' + bad + ' مشكلة — مالمستش حاجة'); process.exit(1); }
fs.writeFileSync(F, s);
console.log('\n✅ جدول الحركات اتضاف');
