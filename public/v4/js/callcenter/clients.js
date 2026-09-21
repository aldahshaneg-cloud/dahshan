/* v4 · كول سنتر — دفتر العملاء (بحث في السيرفر + فتح أوردرات العميل) */
(function (w, d) {
  "use strict";
  var V4 = w.V4, esc = V4.esc, F = V4.fmt, type = "senders", tok = 0;
  var $ = function (id) { return d.getElementById(id); };
  function run() {
    var q = $("c-q").value.trim(), my = ++tok;
    if (q.length < 2) { $("c-body").innerHTML = '<tr><td colspan="6" class="empty">اكتب حرفين على الأقل.</td></tr>'; return; }
    V4.api.get("/v4/callcenter/contacts", { type: type, q: q }).then(function (r) {
      if (my !== tok) return;
      var it = r.items || [];
      $("c-body").innerHTML = it.length ? it.map(function (c) {
        return '<tr><td class="bold">' + esc(c.name) + "</td><td>" + F.phone(c.phone1) + "</td><td>" + (c.phone2 ? F.phone(c.phone2) : '<span class="muted">—</span>') + "</td><td>" + esc(c.address || c.lastAddress || "—") + "</td><td>" + esc(c.lastZoneName || "—") + "</td>" +
          '<td><a class="btn sm" href="' + esc(w.V4_SEARCH_URL) + "?q=" + encodeURIComponent(c.phone1 || c.name) + '"><i class="fas fa-clipboard-list"></i> أوردراته</a></td></tr>';
      }).join("") : '<tr><td colspan="6" class="empty">مفيش عميل بالبحث ده</td></tr>';
    }).catch(function (e) { if (my === tok) $("c-body").innerHTML = '<tr><td colspan="6"><div class="alert err" style="margin:0">' + esc(e.message) + "</div></td></tr>"; });
  }
  d.addEventListener("DOMContentLoaded", function () {
    $("c-q").addEventListener("input", V4.debounce(run, 280));
    $("c-tabs").addEventListener("click", function (e) { var b = e.target.closest("[data-type]"); if (!b) return; type = b.getAttribute("data-type"); $("c-tabs").querySelectorAll("button").forEach(function (x) { x.classList.toggle("active", x === b); }); run(); });
  });
})(window, document);
