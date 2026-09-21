/* v4 · كول سنتر — الرئيسية: الأرقام من السيرفر (CallcenterStats) وبتتحدّث مع كل حدث بث */
(function (w, d) {
  "use strict";
  var V4 = w.V4, esc = V4.esc, F = V4.fmt, busy = false, again = false;

  function paint(r) {
    var s = r.stats || {};
    d.querySelectorAll("[data-stat]").forEach(function (el) {
      var k = el.getAttribute("data-stat"); if (!(k in s)) return;
      var txt = el.getAttribute("data-money") ? F.money(s[k]) : F.num(s[k]);
      if (el.textContent !== txt) el.textContent = txt;
    });
    var tb = d.getElementById("home-branches");
    if (tb && r.branches) tb.innerHTML = r.branches.length ? r.branches.map(function (b, i) {
      return "<tr><td class=\"muted\">" + (i + 1) + "</td><td class=\"bold\">" + esc(b.name) + (b.paused ? ' <span class="badge red">موقوف</span>' : "") + "</td>" +
        '<td><span class="badge ' + (b.pending ? "yellow" : "gray") + '">' + b.pending + "</span></td>" +
        '<td><span class="badge ' + (b.delivering ? "blue" : "gray") + '">' + b.delivering + "</span></td>" +
        '<td class="green bold">' + b.deliveredToday + "</td><td>" + b.todayTotal + '</td><td class="num">' + esc(F.num(b.revenueToday)) + "</td><td>" + b.pilotsWaiting + " / " + b.pilots + "</td></tr>";
    }).join("") : '<tr><td colspan="8" class="empty">مفيش فروع</td></tr>';
    Object.keys(r.nav || {}).forEach(function (k) { var c = d.querySelector('[data-nav-count="' + k + '"]'); if (c) { c.textContent = r.nav[k]; c.hidden = !r.nav[k]; } });
  }

  function latest() {
    return V4.api.get("/v4/callcenter/orders-data", { list: "all", page: 1 }).then(function (r) {
      var tb = d.getElementById("home-latest"), items = (r.items || []).slice(0, 12);
      tb.innerHTML = items.length ? items.map(function (o) { return V4.Orders.row(o, { noPrepaid: true }); }).join("") : '<tr><td colspan="8" class="empty">مفيش طلبات لسه</td></tr>';
    });
  }

  function refresh() {
    if (busy) { again = true; return; }
    busy = true;
    Promise.all([V4.api.get("/v4/callcenter/stats").then(paint), latest()]).catch(function () {}).then(function () { busy = false; if (again) { again = false; refresh(); } });
  }

  d.addEventListener("DOMContentLoaded", function () {
    latest().catch(function (e) { d.getElementById("home-latest").innerHTML = '<tr><td colspan="8"><div class="alert err" style="margin:0">' + esc(e.message) + "</div></td></tr>"; });
    V4.live.onOrders(refresh);
    V4.live.onRequests(refresh);
  });
})(window, document);
