/* v4 · كول سنتر — قوايم الأوردرات (فلترة + ترقيم في السيرفر + تحديث حي) */
(function (w, d) {
  "use strict";
  var V4 = w.V4, cfg = w.V4_LIST, esc = V4.esc, F = V4.fmt;
  var $ = function (id) { return d.getElementById(id); };
  var state = { page: 1, loading: false, again: false, known: null };

  /* الفلاتر بتتحفظ في العنوان — تحديث الصفحة أو مشاركة الرابط بيرجّعوا نفس العرض */
  function readUrl() {
    var p = new URLSearchParams(w.location.search);
    $("flt-q").value = p.get("q") || ""; $("flt-from").value = p.get("from") || ""; $("flt-to").value = p.get("to") || "";
    state.branch = p.get("branchId") || ""; state.page = Math.max(1, parseInt(p.get("page") || "1", 10) || 1);
  }
  function filters() { return { q: $("flt-q").value.trim(), branchId: $("flt-branch").value || state.branch || "", from: $("flt-from").value, to: $("flt-to").value, page: state.page }; }
  function writeUrl(f) { var q = V4.qs(f.page > 1 ? f : Object.assign({}, f, { page: "" })); w.history.replaceState(null, "", w.location.pathname + q); }

  function load(opt) {
    opt = opt || {};
    if (state.loading) { state.again = true; return; }     // حدث وصل والطلب شغّال → دورة كمان بعده (من غير مؤقت)
    state.loading = true;
    var f = filters(); writeUrl(f);
    V4.api.get(cfg.url.replace(V4.base, ""), Object.assign({ list: cfg.list }, f)).then(function (r) {
      render(r, opt.live);
    }).catch(function (e) {
      if (!opt.live) $("ol-body").innerHTML = '<tr><td colspan="9"><div class="alert err" style="margin:0">' + esc(e.message) + "</div></td></tr>";
    }).then(function () {
      state.loading = false;
      if (state.again) { state.again = false; load({ live: true }); }
    });
  }

  function render(r, live) {
    var items = r.items || [];
    $("ol-total").textContent = F.num(r.total) + " طلب";
    $("ol-sum").textContent = r.total ? "· إجمالي التوصيل " + F.money(r.sum) : "";
    $("ol-updated").textContent = "آخر تحديث " + new Date().toLocaleTimeString("ar-EG", { hour: "2-digit", minute: "2-digit", second: "2-digit" });
    if (!items.length) { $("ol-body").innerHTML = '<tr><td colspan="9" class="empty">مفيش طلبات بالفلتر ده</td></tr>'; }
    else {
      $("ol-body").innerHTML = items.map(function (o) { return V4.Orders.row(o); }).join("");
      /* الجديد اللي دخل القايمة بتحديث حي بيومض — الموظف يلمحه من غير ما يدوّر */
      if (live && state.known) items.forEach(function (o) { if (!state.known[o.id]) { var tr = $("ol-body").querySelector('tr[data-order="' + o.id + '"]'); if (tr) tr.classList.add("flash"); } });
    }
    state.known = {}; items.forEach(function (o) { state.known[o.id] = 1; });
    state.page = r.page;
    var pg = $("ol-pager");
    pg.innerHTML = r.pages > 1
      ? '<button class="btn sm" data-pg="' + (r.page - 1) + '"' + (r.page <= 1 ? " disabled" : "") + '><i class="fas fa-chevron-right"></i> السابق</button>' +
        '<span class="muted small">صفحة ' + r.page + " من " + r.pages + "</span>" +
        '<button class="btn sm" data-pg="' + (r.page + 1) + '"' + (r.page >= r.pages ? " disabled" : "") + '>التالي <i class="fas fa-chevron-left"></i></button>'
      : "";
  }

  function todayBiz() {
    /* يوم العمل الحالي بتوقيت القاهرة (قبل ٩ص = لسه يوم امبارح) */
    var now = new Date(), h = +new Intl.DateTimeFormat("en-GB", { timeZone: "Africa/Cairo", hour: "2-digit", hour12: false }).format(now);
    var t = h < 9 ? new Date(now.getTime() - 864e5) : now;
    return new Intl.DateTimeFormat("en-CA", { timeZone: "Africa/Cairo", year: "numeric", month: "2-digit", day: "2-digit" }).format(t);
  }

  d.addEventListener("DOMContentLoaded", function () {
    readUrl();
    V4.api.get("/api/branches").then(function (r) {
      $("flt-branch").innerHTML = '<option value="">كل الفروع</option>' + (r.items || []).map(function (b) { return '<option value="' + esc(b.id) + '">' + esc(b.name) + "</option>"; }).join("");
      if (state.branch) $("flt-branch").value = state.branch;
    }).catch(function () {});
    var refilter = function () { state.page = 1; state.known = null; load(); };
    $("flt-q").addEventListener("input", V4.debounce(refilter, 350));
    ["flt-branch", "flt-from", "flt-to"].forEach(function (id) { $(id).addEventListener("change", function () { state.branch = $("flt-branch").value; refilter(); }); });
    $("flt-today").onclick = function () { var t = todayBiz(); $("flt-from").value = t; $("flt-to").value = t; refilter(); };
    $("flt-clear").onclick = function () { $("flt-q").value = ""; $("flt-from").value = ""; $("flt-to").value = ""; $("flt-branch").value = ""; state.branch = ""; refilter(); };
    $("ol-pager").addEventListener("click", function (e) { var b = e.target.closest("[data-pg]"); if (!b || b.disabled) return; state.page = parseInt(b.getAttribute("data-pg"), 10); state.known = null; load(); w.scrollTo({ top: 0, behavior: "smooth" }); });
    load();
    V4.live.onOrders(function () { load({ live: true }); });
  });
})(window, document);
