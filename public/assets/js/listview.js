/**
 * listview.js — طبقة عرض موحّدة لكل جداول اللوحات (طلب صاحب النظام 2026-09-10)
 *
 * ═══ إيه اللي بتعمله ═══
 *   ١) **٣٠ صف بس** في أي قايمة — والباقي بزرارين ‹ › وعدّاد «الإجمالي | من-إلى»
 *      (زي الصورة اللي بعتها صاحب النظام). الصفوف اللي برّه الصفحة الحالية
 *      بتتخفي (display:none) فالمتصفح مابيعملش لها layout ولا paint — وده
 *      جزء من علاج «السيستم تقيل»: جدول ٢٠٠٠ أوردر كان بيترسم كامل مع كل
 *      تحديث.
 *   ٢) **زرار كروت/جدول** — الكروت بتتبني من نفس الصفوف (عنوان العمود من
 *      <th> والقيمة من <td>)، والضغط على الكرت بيفتح فورم فيه **كل** أعمدة
 *      الصف بأزرار الإجراءات بتاعته.
 *
 * ═══ ليه طبقة واحدة مش تعديل في كل صفحة ═══
 *   اللوحات فيها ١١٣ جدول بيترسموا بـinnerHTML من عشرات الدوال، وكل واحد
 *   بيتعاد رسمه مع كل استطلاع. الطبقة دي بتراقب الـDOM (MutationObserver)
 *   وبتطبّق نفسها على أي جدول له <thead> بـ٣ أعمدة أو أكتر و٦ صفوف أو أكتر،
 *   وبتفتكر الصفحة والوضع (كروت/جدول) لكل جدول حتى لو الجدول اتعاد بناؤه
 *   من الصفر — المفتاح = أقرب عنصر له id + ترتيب الجدول جوّاه.
 *
 * ═══ اللي بتسيبه في حاله ═══
 *   • الجداول جوّه فورم التفاصيل بتاعها هي نفسها (.lv-modal).
 *   • الجداول الصغيرة (< ٦ صفوف) ولا اللي من غير عناوين أعمدة.
 *   • صف «مفيش بيانات» (خلية واحدة بـcolspan) — بيفضل ظاهر دايمًا.
 *   • الطباعة: كل الصفوف بتظهر والشريط بيتخفي.
 *   • أي جدول عليه data-lv-skip.
 *
 * ⚠️ الأزرار جوّه الكروت والفورم **نسخ** من أزرار الصف (cloneNode) — الـonclick
 *    المكتوب كخاصية بيشتغل زي ما هو. الـid بيتشال من النسخ عشان
 *    getElementById في الصفحة يفضل يلاقي الأصل (المؤقّتات زي otimer-…).
 */
(function (global) {
  "use strict";
  if (global.__LISTVIEW_LOADED__) return;
  global.__LISTVIEW_LOADED__ = true;

  var PAGE = 30;           // الصفوف في الصفحة
  var MIN_ROWS = 6;        // أقل من كده = الجدول يفضل زي ما هو
  var MIN_COLS = 3;
  var doc = global.document;
  var state = {};          // key → { page, mode }
  var LS_PREFIX = "lv-mode:" + (global.location.pathname.split("/").pop() || "page") + ":";

  /* ── الستايل ─────────────────────────────────────────────────────── */
  var CSS = "\
.lv-bar{color:inherit;display:flex;align-items:center;gap:10px;margin:6px 0 8px;font-family:inherit;flex-wrap:wrap}\
.lv-bar .lv-grp{display:inline-flex;border:1px solid var(--border,var(--line,rgba(127,127,127,.35)));border-radius:9px;overflow:hidden;background:var(--card,var(--panel,transparent))}\
.lv-bar button{all:unset;cursor:pointer;padding:6px 11px;color:var(--muted,#8e8e97);font-size:14px;line-height:1;display:inline-flex;align-items:center;gap:6px;user-select:none}\
.lv-bar button:hover{color:var(--text,inherit);background:rgba(127,127,127,.12)}\
.lv-bar button.on{color:var(--text,inherit);background:rgba(14,165,233,.16)}\
.lv-bar button:disabled{opacity:.35;cursor:default;background:none}\
.lv-bar .lv-count{color:var(--muted,#8e8e97);font-size:12.5px;direction:ltr;unicode-bidi:isolate;letter-spacing:.3px}\
.lv-bar .lv-count b{color:var(--text,inherit);font-weight:700}\
.lv-hide{display:none!important}\
.lv-list-hidden{display:none!important}\
table.lv-gen{width:100%;border-collapse:collapse;margin:4px 0 12px}\
table.lv-gen th,table.lv-gen td{padding:9px 10px;text-align:start;border-bottom:1px solid var(--border,#2a2a30);font-size:13px}\
table.lv-gen th{color:var(--muted,#8e8e97);font-weight:700}\
table.lv-gen tr:hover td{background:rgba(127,127,127,.08)}\
table.lv-gen td .btn,table.lv-gen td button{margin:0 2px}\
table.lv-as-cards{display:none!important}\
.lv-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px;margin:4px 0 10px}\
.lv-card{background:var(--card,var(--panel,rgba(127,127,127,.06)));border:1px solid var(--border,var(--line,rgba(127,127,127,.3)));border-radius:12px;padding:12px 13px;cursor:pointer;transition:border-color .15s,transform .15s;min-width:0}\
.lv-card:hover{border-color:var(--sky,#0ea5e9);transform:translateY(-2px)}\
.lv-card .lv-title{font-weight:800;font-size:14.5px;margin-bottom:7px;color:var(--text,inherit);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}\
.lv-card .lv-row{display:flex;justify-content:space-between;gap:8px;font-size:12.5px;padding:3px 0;border-top:1px dashed rgba(127,127,127,.25)}\
.lv-card .lv-row .lv-k{color:var(--muted,#8e8e97);flex:none}\
.lv-card .lv-row .lv-v{color:var(--text,inherit);text-align:start;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}\
.lv-card .lv-more{margin-top:8px;font-size:11.5px;color:var(--sky,#0ea5e9)}\
.lv-modal{position:fixed;inset:0;z-index:10050;background:rgba(0,0,0,.62);display:flex;align-items:center;justify-content:center;padding:16px}\
.lv-modal .lv-panel{color:inherit;background:var(--panel,var(--card,#fff));border:1px solid var(--border,var(--line,rgba(127,127,127,.3)));border-radius:16px;width:min(720px,100%);max-height:90vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,.5)}\
.lv-modal .lv-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;border-bottom:1px solid var(--border,#2a2a30);position:sticky;top:0;background:inherit;z-index:1}\
.lv-modal .lv-head h3{margin:0;font-size:16px;color:var(--text,inherit)}\
.lv-modal .lv-x{all:unset;cursor:pointer;font-size:20px;padding:2px 8px;color:var(--muted,#8e8e97)}\
.lv-modal .lv-x:hover{color:var(--text,inherit)}\
.lv-modal .lv-body{padding:8px 16px 16px}\
.lv-modal .lv-field{display:grid;grid-template-columns:150px 1fr;gap:10px;padding:9px 0;border-bottom:1px solid rgba(127,127,127,.25);font-size:13.5px;align-items:start}\
.lv-modal .lv-field .lv-k{color:var(--muted,#8e8e97)}\
.lv-modal .lv-field .lv-v{color:var(--text,inherit);min-width:0;word-break:break-word}\
.lv-modal .lv-actions{display:flex;flex-wrap:wrap;gap:8px;padding-top:12px}\
.lv-modal .lv-actions>*{margin:0!important}\
@media (max-width:600px){.lv-modal .lv-field{grid-template-columns:1fr;gap:3px}}\
@media print{.lv-bar,.lv-cards{display:none!important}.lv-hide{display:table-row!important}table.lv-as-cards{display:table!important}}";

  function injectCss() {
    if (doc.getElementById("lv-style")) return;
    var s = doc.createElement("style"); s.id = "lv-style"; s.textContent = CSS;
    (doc.head || doc.documentElement).appendChild(s);
  }

  /* ── مفتاح ثابت للجدول حتى لو اتعاد بناؤه ───────────────────────────── */
  function keyOf(table) {
    if (table.dataset.lvKey) return table.dataset.lvKey;
    var anc = table.parentElement, id = "";
    while (anc && anc !== doc.body) { if (anc.id) { id = anc.id; break; } anc = anc.parentElement; }
    var scope = id ? doc.getElementById(id) : doc.body;
    var idx = 0, all = scope ? scope.querySelectorAll("table") : [];
    for (var i = 0; i < all.length; i++) { if (all[i] === table) { idx = i; break; } }
    var k = (id || "body") + "#" + idx;
    table.dataset.lvKey = k;
    return k;
  }
  function st(key) {
    if (!state[key]) {
      var mode = "table";
      try { mode = global.localStorage.getItem(LS_PREFIX + key) || "table"; } catch (_) {}
      state[key] = { page: 0, mode: mode };
    }
    return state[key];
  }
  function saveMode(key, mode) { try { global.localStorage.setItem(LS_PREFIX + key, mode); } catch (_) {} }

  /* ══ الترقيم من المصدر (الجداول التقيلة) ══════════════════════════
     دالة الرسم بتنده paged(key, total, rerender) **قبل** ما تبني الصفوف،
     وترسم pg.slice(list) بس — ٣٠ صف من الأصل بدل ٢٠٠٠ يتبنوا ويتخفوا.
     الجدول بياخد data-lv-key/data-lv-total/data-lv-offset، والشريط لما
     المشرف يضغط ‹ › بيغيّر الصفحة وبينده rerender بتاع الصفحة نفسها.
     (طلب صاحب النظام 2026-09-10 بعد ما شاف إن الإخفاء لوحده مش كفاية.) */
  var sourcePaged = {};   // key → { total, rerender }
  function paged(key, total, rerender) {
    var s = st(key);
    total = Math.max(0, total | 0);
    sourcePaged[key] = { total: total, rerender: rerender };
    var pages = Math.max(1, Math.ceil(total / PAGE));
    if (s.page > pages - 1) s.page = pages - 1;
    if (s.page < 0) s.page = 0;
    var start = total ? s.page * PAGE : 0, end = Math.min(total, start + PAGE);
    return {
      page: s.page, start: start, end: end, total: total,
      slice: function (arr) { return arr.slice(start, end); },
      /* بتتحط على عنصر <table> — الطبقة بتقرا منها إن الصفوف دي «نافذة» مش الكل */
      mark: function (table) {
        if (!table) return;
        table.setAttribute("data-lv-key", key);
        table.setAttribute("data-lv-total", String(total));
        table.setAttribute("data-lv-offset", String(start));
      },
    };
  }

  /* ── الصفوف الحقيقية (مش صف «مفيش بيانات») ─────────────────────────── */
  function dataRows(table) {
    var tb = table.tBodies[0]; if (!tb) return [];
    var out = [], rows = tb.rows;
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      if (r.cells.length === 1 && r.cells[0].colSpan > 1) continue; // صف حالة فاضية
      if (r.classList.contains("lv-skip")) continue;
      out.push(r);
    }
    return out;
  }
  function headers(table) {
    var th = table.tHead ? table.tHead.querySelectorAll("th") : [];
    var out = [];
    for (var i = 0; i < th.length; i++) out.push((th[i].textContent || "").trim());
    return out;
  }
  function isActionsCell(cell) { return !!cell.querySelector("button, a.btn, .btn, .view-btn, .assign-btn, .del-btn"); }
  function cellText(cell) { return (cell.textContent || "").replace(/\s+/g, " ").trim(); }
  function cloneCell(cell) {
    var frag = doc.createElement("div");
    for (var i = 0; i < cell.childNodes.length; i++) frag.appendChild(cell.childNodes[i].cloneNode(true));
    var withId = frag.querySelectorAll("[id]");
    for (var j = 0; j < withId.length; j++) withId[j].removeAttribute("id");
    return frag;
  }

  /* ── الشريط ───────────────────────────────────────────────────────── */
  function buildBar(table, key) {
    var bar = doc.createElement("div");
    bar.className = "lv-bar"; bar.setAttribute("data-lv-for", key); bar.setAttribute("dir", "ltr");
    bar.innerHTML =
      '<span class="lv-grp">' +
        '<button type="button" class="lv-cards-btn" title="عرض كروت">▦</button>' +
        '<button type="button" class="lv-table-btn" title="عرض جدول">☰</button>' +
      '</span>' +
      '<span class="lv-grp">' +
        '<button type="button" class="lv-prev" title="السابق">‹</button>' +
        '<button type="button" class="lv-next" title="التالي">›</button>' +
      '</span>' +
      '<span class="lv-count"></span>';
    var go = function (delta) {
      var s = st(key), sp = sourcePaged[key];
      var total = sp ? sp.total : dataRows(table).length, pages = Math.max(1, Math.ceil(total / PAGE));
      var np = Math.min(pages - 1, Math.max(0, s.page + delta));
      if (np === s.page) return;
      s.page = np;
      if (sp && typeof sp.rerender === "function") { try { sp.rerender(); } catch (e) { console.warn("listview rerender:", e); } }
      else apply(table);
    };
    bar.querySelector(".lv-prev").onclick = function () { go(-1); };
    bar.querySelector(".lv-next").onclick = function () { go(1); };
    bar.querySelector(".lv-cards-btn").onclick = function () { var s = st(key); s.mode = "cards"; saveMode(key, "cards"); apply(table); };
    bar.querySelector(".lv-table-btn").onclick = function () { var s = st(key); s.mode = "table"; saveMode(key, "table"); apply(table); };
    return bar;
  }
  function barFor(table, key) {
    var prev = table.previousElementSibling;
    if (prev && prev.classList && prev.classList.contains("lv-bar") && prev.getAttribute("data-lv-for") === key) return prev;
    var bar = buildBar(table, key);
    table.parentNode.insertBefore(bar, table);
    return bar;
  }
  function cardsFor(table, key) {
    var next = table.nextElementSibling;
    if (next && next.classList && next.classList.contains("lv-cards") && next.getAttribute("data-lv-for") === key) return next;
    var c = doc.createElement("div");
    c.className = "lv-cards"; c.setAttribute("data-lv-for", key); c.setAttribute("dir", doc.documentElement.dir || "rtl");
    table.parentNode.insertBefore(c, table.nextSibling);
    return c;
  }

  /* ── فورم التفاصيل ──────────────────────────────────────────────────── */
  /* الفورم العام: title + fields [[label, node|string]] + actionNodes[] */
  function openDetailsFrom(title, fields, actionNodes) {
    closeDetails();
    var m = doc.createElement("div"); m.className = "lv-modal"; m.setAttribute("dir", doc.documentElement.dir || "rtl");
    var panel = doc.createElement("div"); panel.className = "lv-panel";
    panel.innerHTML = '<div class="lv-head"><h3></h3><button type="button" class="lv-x" title="إغلاق">✕</button></div><div class="lv-body"></div>';
    panel.querySelector("h3").textContent = title || "التفاصيل";
    var body = panel.querySelector(".lv-body");
    for (var i = 0; i < fields.length; i++) {
      var f = doc.createElement("div"); f.className = "lv-field";
      var k = doc.createElement("div"); k.className = "lv-k"; k.textContent = fields[i][0] || "";
      var v = doc.createElement("div"); v.className = "lv-v";
      if (typeof fields[i][1] === "string") v.textContent = fields[i][1]; else if (fields[i][1]) v.appendChild(fields[i][1]);
      f.appendChild(k); f.appendChild(v); body.appendChild(f);
    }
    if (actionNodes && actionNodes.length) {
      var actions = doc.createElement("div"); actions.className = "lv-actions";
      for (var a = 0; a < actionNodes.length; a++) actions.appendChild(actionNodes[a]);
      body.appendChild(actions);
    }
    m.appendChild(panel); doc.body.appendChild(m);
    panel.querySelector(".lv-x").onclick = closeDetails;
    m.addEventListener("click", function (e) { if (e.target === m) closeDetails(); });
    doc.addEventListener("keydown", escClose);
  }
  /* الجدول: الحقول من <th> والخلايا */
  function openDetails(table, row) {
    var hs = headers(table), title = "", fields = [], acts = [];
    for (var t = 0; t < row.cells.length; t++) { if (!isActionsCell(row.cells[t]) && cellText(row.cells[t]) && hs[t] !== "#") { title = cellText(row.cells[t]); break; } }
    for (var i = 0; i < row.cells.length; i++) {
      var cell = row.cells[i];
      if (isActionsCell(cell)) { acts.push(cloneCell(cell)); continue; }
      if (!cellText(cell) && !cell.querySelector("img,svg,canvas")) continue;
      fields.push([hs[i] || "", cloneCell(cell)]);
    }
    openDetailsFrom(title, fields, acts);
  }
  function escClose(e) { if (e.key === "Escape") closeDetails(); }
  function closeDetails() {
    var m = doc.querySelector(".lv-modal"); if (m) m.parentNode.removeChild(m);
    doc.removeEventListener("keydown", escClose);
  }

  /* بصمة محتوى الصفوف الظاهرة — الكروت والجدول المولّد بيتبنوا لما تتغيّر بس */
  function rowsSig(rows) { var a = []; for (var i = 0; i < rows.length; i++) a.push(rows[i].innerHTML); return rows.length + "\u0001" + a.join("\u0001"); }

  /* ── الكروت ──────────────────────────────────────────────────────── */
  function renderCards(table, rows, box) {
    var hs = headers(table);
    box.innerHTML = "";
    for (var r = 0; r < rows.length; r++) {
      (function (row) {
        var card = doc.createElement("div"); card.className = "lv-card";
        var title = "", fields = [];
        for (var i = 0; i < row.cells.length && fields.length < 4; i++) {
          var cell = row.cells[i];
          if (isActionsCell(cell) || hs[i] === "#") continue;
          var txt = cellText(cell); if (!txt) continue;
          if (!title) { title = txt; continue; }
          fields.push([hs[i] || "", txt]);
        }
        var h = '<div class="lv-title"></div>';
        card.innerHTML = h;
        card.querySelector(".lv-title").textContent = title || "—";
        for (var f = 0; f < fields.length; f++) {
          var d = doc.createElement("div"); d.className = "lv-row";
          var k = doc.createElement("span"); k.className = "lv-k"; k.textContent = fields[f][0];
          var v = doc.createElement("span"); v.className = "lv-v"; v.textContent = fields[f][1];
          d.appendChild(k); d.appendChild(v); card.appendChild(d);
        }
        var more = doc.createElement("div"); more.className = "lv-more"; more.textContent = "اضغط لعرض كل التفاصيل ←";
        card.appendChild(more);
        card.onclick = function () { openDetails(table, row); };
        box.appendChild(card);
      })(rows[r]);
    }
  }

  /* ── التطبيق على جدول ─────────────────────────────────────────────── */
  var busy = false;
  function apply(table) {
    var key = keyOf(table), s = st(key), rows = dataRows(table);
    var total = rows.length, sp = table.hasAttribute("data-lv-total") ? sourcePaged[key] : null;
    var offset = 0;
    if (sp) { total = sp.total; offset = parseInt(table.getAttribute("data-lv-offset") || "0", 10) || 0; }
    if (total < MIN_ROWS) { // جدول صغير — نشيل أثرنا لو كان فيه
      var b0 = table.previousElementSibling; if (b0 && b0.classList && b0.classList.contains("lv-bar")) b0.parentNode.removeChild(b0);
      var c0 = table.nextElementSibling; if (c0 && c0.classList && c0.classList.contains("lv-cards")) c0.parentNode.removeChild(c0);
      table.classList.remove("lv-as-cards");
      for (var z = 0; z < rows.length; z++) rows[z].classList.remove("lv-hide");
      return;
    }
    var pages = Math.max(1, Math.ceil(total / PAGE));
    if (s.page > pages - 1) s.page = pages - 1;
    if (s.page < 0) s.page = 0;
    var start = sp ? offset : s.page * PAGE, end = sp ? Math.min(total, offset + rows.length) : Math.min(total, start + PAGE), visible = [];
    if (sp) { for (var v = 0; v < rows.length; v++) { rows[v].classList.remove("lv-hide"); visible.push(rows[v]); } }
    else for (var i = 0; i < total; i++) {
      var on = i >= start && i < end;
      rows[i].classList.toggle("lv-hide", !on);
      if (on) visible.push(rows[i]);
    }
    var bar = barFor(table, key);
    bar.querySelector(".lv-prev").disabled = s.page === 0;
    bar.querySelector(".lv-next").disabled = s.page >= pages - 1;
    /* 🔴 مانكتبش في الـDOM غير لو فيه فرق فعلًا (بلاغ 2026-09-12 «السيستم
       بيرسم نفسه كل شوية»): الكتابة نفسها تغيير بيوصل لمراقبين تانيين،
       وبناء الكروت لـ٤٠٠ منطقة كل ٥ ثواني كان بيرفّ الشاشة كلها. */
    var countHtml = "<b>" + total + "</b> | " + (start + 1) + "-" + end, countEl = bar.querySelector(".lv-count");
    if (countEl.innerHTML !== countHtml) countEl.innerHTML = countHtml;
    bar.querySelector(".lv-cards-btn").classList.toggle("on", s.mode === "cards");
    bar.querySelector(".lv-table-btn").classList.toggle("on", s.mode !== "cards");
    if (s.mode === "cards") {
      table.classList.add("lv-as-cards");
      var box = cardsFor(table, key), csig = rowsSig(visible);
      if (box.__lvSig !== csig) { renderCards(table, visible, box); box.__lvSig = csig; }
    } else {
      table.classList.remove("lv-as-cards");
      var c = table.nextElementSibling;
      if (c && c.classList && c.classList.contains("lv-cards")) c.parentNode.removeChild(c);
    }
  }

  /* ══ قوايم العناصر (كروت/صفوف div) ══════════════════════════════════
     الصفحات اللي قوايمها مش جداول (pilots · customers · stores) بتاخد نفس
     الشريط عن طريق «محوّل» صغير بيقول: فين الحاوية، إيه العنصر، وإزاي
     نطلّع العنوان والحقول. الوضع الافتراضي هنا «كروت» (العناصر زي ما
     الصفحة رسمتها) والزرار التاني بيولّد **جدول** من الحقول. الضغط على
     العنصر: لو الصفحة ليها تفاصيل بتاعتها (clickItem) بنسيب سلوكها،
     وغير كده بنفتح فورم التفاصيل. */
  function txt(el, sel) { var n = sel ? el.querySelector(sel) : el; return n ? (n.textContent || "").replace(/\s+/g, " ").trim() : ""; }
  function kvFields(el) {
    var out = [], kv = el.querySelectorAll(".kv");
    for (var i = 0; i < kv.length; i++) { var k = txt(kv[i], ".k"), v = txt(kv[i], ".v"); if (k) out.push([k, v]); }
    return out;
  }
  function itemActions(el) {
    var out = [], bs = el.querySelectorAll("button, a.btn");
    for (var i = 0; i < bs.length; i++) { var c = bs[i].cloneNode(true); c.removeAttribute("id"); out.push(c); }
    return out;
  }
  var ADAPTERS = {
    "pilots.html": [
      { container: "#pilots-body .grid", item: ".card", key: "pilots",
        title: function (el) { return txt(el, ".card-h b"); }, fields: kvFields },
      { container: "#shifts-body .grid", item: ".card", key: "shifts",
        title: function (el) { return txt(el, ".card-h b"); }, fields: kvFields },
      /* صفوف الدور/الطلبات (.qrow) — جوّه كروت بعنوان */
      { container: "#board-body .card, #shifts-body .card, #requests-body .card, #join-body .card, #transfers-body .card, #support-body .card",
        item: ".qrow", key: "rows",
        title: function (el) { return txt(el, "b") || txt(el); },
        fields: function (el) { var t = txt(el, "b"), all = txt(el); var rest = all.replace(t, "").replace(/▲|▼|خروج|فتح وردية|موافقة|رفض|قبول|تفاصيل/g, "").trim(); return rest ? [["البيانات", rest]] : []; } },
    ],
    "customers.html": [
      { container: "#list", item: ".row", key: "customers", clickItem: true,
        title: function (el) { return txt(el, ".r-1 b"); },
        fields: function (el) { return [["تاريخ التسجيل", txt(el, ".r-1 span")], ["الهاتف", txt(el, ".ph")], ["الطلبات", (txt(el, ".r-2").match(/(\d+)\s*طلب/) || ["", "0"])[1] + " طلب"], ["الحالة", txt(el, ".tag") || "سليم"]]; } },
    ],
    "stores.html": [
      { container: "#list", item: ".row", key: "stores", clickItem: true,
        title: function (el) { return txt(el, ".r-1 b"); },
        fields: function (el) { return [["الهاتف", txt(el, ".r-2 [dir=ltr]")], ["الشحنات", (txt(el, ".r-2").match(/(\d+)\s*شحنة/) || ["", "0"])[1] + " شحنة"], ["الحالة / الرصيد", txt(el, ".tag") || "—"]]; } },
    ],
  };
  var pageName = (global.location.pathname.split("/").pop() || "").toLowerCase();

  function listItems(box, sel) {
    var all = box.querySelectorAll(sel), out = [];
    for (var i = 0; i < all.length; i++) { if (all[i].parentElement === box || sel !== ":scope > *") { if (!all[i].classList.contains("empty")) out.push(all[i]); } }
    return out;
  }
  function genTableFor(box, key) {
    var next = box.nextElementSibling;
    if (next && next.classList && next.classList.contains("lv-gen") && next.getAttribute("data-lv-for") === key) return next;
    var t = doc.createElement("table"); t.className = "tbl lv-gen"; t.setAttribute("data-lv-for", key); t.setAttribute("data-lv-skip", "1");
    box.parentNode.insertBefore(t, box.nextSibling);
    return t;
  }
  function renderGenTable(cfg, box, key, visible) {
    var t = genTableFor(box, key), labels = [], seen = {};
    for (var i = 0; i < visible.length; i++) { var fs = cfg.fields(visible[i]); for (var j = 0; j < fs.length; j++) { if (!seen[fs[j][0]]) { seen[fs[j][0]] = 1; labels.push(fs[j][0]); } } }
    var h = "<thead><tr><th>#</th><th>الاسم</th>"; for (var l = 0; l < labels.length; l++) h += "<th></th>"; h += "<th>إجراء</th></tr></thead><tbody></tbody>";
    t.innerHTML = h;
    var ths = t.tHead.rows[0].cells; for (var x = 0; x < labels.length; x++) ths[x + 2].textContent = labels[x];
    var tb = t.tBodies[0];
    for (var r = 0; r < visible.length; r++) {
      (function (el, idx) {
        var fs = cfg.fields(el), map = {}; for (var q = 0; q < fs.length; q++) map[fs[q][0]] = fs[q][1];
        var tr = doc.createElement("tr");
        var c0 = doc.createElement("td"); c0.textContent = String(idx + 1); tr.appendChild(c0);
        var c1 = doc.createElement("td"); c1.innerHTML = "<b></b>"; c1.firstChild.textContent = cfg.title(el) || "—"; tr.appendChild(c1);
        for (var m = 0; m < labels.length; m++) { var td = doc.createElement("td"); td.textContent = map[labels[m]] || "—"; tr.appendChild(td); }
        var ca = doc.createElement("td"); var acts = itemActions(el); for (var a = 0; a < acts.length; a++) ca.appendChild(acts[a]); tr.appendChild(ca);
        tr.style.cursor = "pointer";
        tr.addEventListener("click", function (e) {
          if (e.target.closest("button, a, input, select, textarea, label")) return;
          if (cfg.clickItem) { el.click(); return; }
          openDetailsFrom(cfg.title(el), cfg.fields(el), itemActions(el));
        });
        tb.appendChild(tr);
      })(visible[r], r);
    }
  }
  function applyList(cfg, box, idx) {
    var key = "list:" + cfg.key + "#" + idx, s = st(key), items = listItems(box, cfg.item), total = items.length;
    if (!s.modeInit) { s.modeInit = true; try { s.mode = global.localStorage.getItem(LS_PREFIX + key) || "cards"; } catch (_) { s.mode = "cards"; } }
    var oldBar = box.previousElementSibling, oldGen = box.nextElementSibling;
    if (total < MIN_ROWS) {
      if (oldBar && oldBar.classList && oldBar.classList.contains("lv-bar") && oldBar.getAttribute("data-lv-for") === key) oldBar.parentNode.removeChild(oldBar);
      if (oldGen && oldGen.classList && oldGen.classList.contains("lv-gen")) oldGen.parentNode.removeChild(oldGen);
      box.classList.remove("lv-list-hidden");
      for (var z = 0; z < total; z++) items[z].classList.remove("lv-hide");
      return;
    }
    var pages = Math.max(1, Math.ceil(total / PAGE));
    if (s.page > pages - 1) s.page = pages - 1; if (s.page < 0) s.page = 0;
    var start = s.page * PAGE, end = Math.min(total, start + PAGE), visible = [];
    for (var i = 0; i < total; i++) { var on = i >= start && i < end; items[i].classList.toggle("lv-hide", !on); if (on) visible.push(items[i]); }
    var bar;
    if (oldBar && oldBar.classList && oldBar.classList.contains("lv-bar") && oldBar.getAttribute("data-lv-for") === key) bar = oldBar;
    else { bar = buildBar(box, key); box.parentNode.insertBefore(bar, box); bar._lvList = true; }
    // أزرار الشريط للقوايم بتشتغل على box مش table
    bar.querySelector(".lv-prev").onclick = function () { if (s.page > 0) { s.page--; applyList(cfg, box, idx); } };
    bar.querySelector(".lv-next").onclick = function () { s.page++; applyList(cfg, box, idx); };
    bar.querySelector(".lv-cards-btn").onclick = function () { s.mode = "cards"; saveMode(key, "cards"); applyList(cfg, box, idx); };
    bar.querySelector(".lv-table-btn").onclick = function () { s.mode = "table"; saveMode(key, "table"); applyList(cfg, box, idx); };
    bar.querySelector(".lv-prev").disabled = s.page === 0;
    bar.querySelector(".lv-next").disabled = s.page >= pages - 1;
    var countHtml2 = "<b>" + total + "</b> | " + (start + 1) + "-" + end, countEl2 = bar.querySelector(".lv-count");
    if (countEl2.innerHTML !== countHtml2) countEl2.innerHTML = countHtml2;
    bar.querySelector(".lv-cards-btn").classList.toggle("on", s.mode !== "table");
    bar.querySelector(".lv-table-btn").classList.toggle("on", s.mode === "table");
    if (s.mode === "table") {
      box.classList.add("lv-list-hidden");
      var gsig = rowsSig(visible), gen = box.nextElementSibling, hasGen = gen && gen.classList && gen.classList.contains("lv-gen");
      if (!hasGen || box.__lvGenSig !== gsig) { renderGenTable(cfg, box, key, visible); box.__lvGenSig = gsig; }
    }
    else { box.classList.remove("lv-list-hidden"); var g = box.nextElementSibling; if (g && g.classList && g.classList.contains("lv-gen")) g.parentNode.removeChild(g); }
    // الضغط على العنصر (مش على زرار) → فورم التفاصيل — مرة واحدة لكل حاوية
    if (!cfg.clickItem && !box._lvClick) {
      box._lvClick = true;
      box.addEventListener("click", function (e) {
        if (e.target.closest("button, a, input, select, textarea, label, .lv-bar")) return;
        var el = e.target.closest(cfg.item); if (!el || !box.contains(el)) return;
        openDetailsFrom(cfg.title(el), cfg.fields(el), itemActions(el));
      });
    }
  }
  function applyLists() {
    var cfgs = ADAPTERS[pageName]; if (!cfgs) return;
    for (var c = 0; c < cfgs.length; c++) {
      var boxes = doc.querySelectorAll(cfgs[c].container);
      for (var b = 0; b < boxes.length; b++) { if (boxes[b].closest(".lv-modal")) continue; applyList(cfgs[c], boxes[b], b); }
    }
  }

  function applyTable(t) {
    if (!t || !t.isConnected || t.hasAttribute("data-lv-skip") || !t.tHead || t.closest(".lv-modal")) return;
    if (headers(t).length < MIN_COLS) return;
    apply(t);
  }
  /* scope: بلا معامل = مسح كامل (أول مرة / LISTVIEW.rescan) — وإلا الجداول
     والقوايم اللي اتغيّرت بس. قبل كده أي تغيير في أي حتة في الصفحة كان بيعيد
     التطبيق على كل الجداول والقوايم. */
  function scan(scope) {
    if (busy) return; busy = true;
    try {
      injectCss();
      if (!scope || scope.full) {
        var tables = doc.querySelectorAll("table");
        for (var i = 0; i < tables.length; i++) applyTable(tables[i]);
        applyLists();
      } else {
        for (var k = 0; k < scope.tables.length; k++) applyTable(scope.tables[k]);
        if (scope.lists) applyLists();
      }
    } catch (e) { try { console.warn("listview:", e); } catch (_) {} }
    busy = false;
  }

  /* ── المراقبة: أي إعادة رسم → إعادة تطبيق (مجمّعة ١٠٠ms) ─────────── */
  var timer = null, pend = { full: false, tables: [], lists: false };
  function schedule(scope) {
    if (scope === true) pend.full = true;
    else if (scope) { if (scope.lists) pend.lists = true; for (var i = 0; i < (scope.tables || []).length; i++) if (pend.tables.indexOf(scope.tables[i]) < 0) pend.tables.push(scope.tables[i]); }
    if (timer) return;
    timer = setTimeout(function () { timer = null; var p = pend; pend = { full: false, tables: [], lists: false }; scan(p); }, 100);
  }
  function listSel() { var cfgs = ADAPTERS[pageName]; if (!cfgs) return null; var a = []; for (var i = 0; i < cfgs.length; i++) a.push(cfgs[i].container); return a.join(","); }
  function inList(node) { var sel = listSel(); return !!(sel && node && node.nodeType === 1 && node.closest && node.closest(sel)); }
  function ours(node) { return node && node.nodeType === 1 && node.closest && node.closest(".lv-bar,.lv-cards,.lv-modal,.lv-gen,#lv-style"); }
  function start() {
    scan();
    var mo = new MutationObserver(function (muts) {
      var scope = { tables: [], lists: false };
      for (var i = 0; i < muts.length; i++) {
        var m = muts[i];
        if (ours(m.target)) continue;
        var added = m.addedNodes, all = true;
        for (var j = 0; j < added.length; j++) { if (!ours(added[j])) { all = false; break; } }
        if (added.length && all) continue;   // تغييرات من عندنا — مانلفّش
        /* 🔴 التغيير جوه جدول → الجدول ده بس. جوه قايمة → القوايم بس.
           عنصر جديد فيه جدول/قايمة → مسح كامل. غير كده (toast · عدّاد وقت ·
           خريطة · مودال) → مايخصناش خالص — ده اللي كان بيرسم كل حاجة كل شوية. */
        var tb = m.target && m.target.nodeType === 1 && m.target.closest ? m.target.closest("table") : null;
        if (tb) { if (scope.tables.indexOf(tb) < 0) scope.tables.push(tb); continue; }
        if (inList(m.target)) { scope.lists = true; continue; }
        for (var a = 0; a < added.length; a++) {
          var nd = added[a];
          if (nd.nodeType !== 1) continue;
          if (nd.tagName === "TABLE" || (nd.querySelector && nd.querySelector("table")) || inList(nd) || (listSel() && nd.querySelector && nd.querySelector(listSel()))) { schedule(true); return; }
        }
      }
      if (scope.tables.length || scope.lists) schedule(scope);
    });
    mo.observe(doc.body, { childList: true, subtree: true });
  }
  if (doc.readyState === "loading") doc.addEventListener("DOMContentLoaded", start); else start();

  global.LISTVIEW = { rescan: scan, PAGE: PAGE, paged: paged, openDetails: openDetailsFrom, adapters: ADAPTERS };
})(window);
