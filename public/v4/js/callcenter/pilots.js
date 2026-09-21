/* v4 · كول سنتر — الطيارين (عرض حي: مين متاح ومين بيوصّل، بالفرع) */
(function (w, d) {
  "use strict";
  var V4 = w.V4, esc = V4.esc, F = V4.fmt, all = [], busy = false, again = false;
  var $ = function (id) { return d.getElementById(id); };
  var LABEL = { waiting: ["في الانتظار", "green"], delivering: ["في التوصيل", "blue"], onLeave: ["في إذن", "yellow"] };

  function statusOf(p) { return p.pilotStatus || "off"; }
  function render() {
    var q = V4.norm($("pl-q").value), br = $("pl-branch").value, st = $("pl-status").value;
    var c = { waiting: 0, delivering: 0, onLeave: 0, off: 0 };
    all.forEach(function (p) { var s = statusOf(p); c[s] = (c[s] || 0) + 1; });
    $("pl-waiting").textContent = c.waiting; $("pl-delivering").textContent = c.delivering; $("pl-leave").textContent = c.onLeave; $("pl-off").textContent = c.off;
    var rows = all.filter(function (p) {
      var s = statusOf(p);
      if (br && String(p.assignedBranchId || "") !== br) return false;
      if (st && (st === "on_leave" ? s !== "onLeave" : s !== st)) return false;
      return !q || V4.norm(p.name).indexOf(q) >= 0;
    }).sort(function (a, b) { var o = { delivering: 0, waiting: 1, onLeave: 2, off: 3 }; return (o[statusOf(a)] - o[statusOf(b)]) || (a.queueNo || 999) - (b.queueNo || 999) || String(a.name).localeCompare(String(b.name), "ar"); });
    $("pl-body").innerHTML = rows.length ? rows.map(function (p, i) {
      var s = statusOf(p), l = LABEL[s] || ["خارج الوردية", "gray"], loc = p.location && p.location.updatedAt;
      return "<tr><td class=\"muted\">" + (i + 1) + "</td><td class=\"bold\">" + esc(p.name) + (p.phone1 ? '<div class="small muted">' + F.phone(p.phone1) + "</div>" : "") + "</td>" +
        "<td>" + esc(p.assignedBranchName || "—") + (p.homeBranchId && p.homeBranchId !== p.assignedBranchId ? ' <span class="badge purple" title="فرعه الثابت ' + esc(p.homeBranchName || "") + '">دعم</span>' : "") + "</td>" +
        '<td><span class="badge ' + l[1] + '">' + l[0] + "</span>" + (s === "onLeave" && p.leaveReason ? '<div class="small muted">' + esc(p.leaveReason) + "</div>" : "") + "</td>" +
        "<td>" + (p.activeOrders ? '<span class="badge blue">' + p.activeOrders + "</span>" : '<span class="muted">—</span>') + "</td>" +
        "<td>" + (p.queueNo ? "#" + p.queueNo : '<span class="muted">—</span>') + "</td>" +
        '<td class="small">' + (loc ? esc(F.ago(loc)) : '<span class="muted">مفيش</span>') + "</td></tr>";
    }).join("") : '<tr><td colspan="7" class="empty">مفيش طيارين بالفلتر ده</td></tr>';
  }
  function load() {
    if (busy) { again = true; return; } busy = true;
    V4.api.get("/api/pilots", { all: 1 }).then(function (r) { all = (r.items || []).filter(function (p) { return !p.archivedAt; }); render(); })
      .catch(function (e) { if (!all.length) $("pl-body").innerHTML = '<tr><td colspan="7"><div class="alert err" style="margin:0">' + esc(e.message) + "</div></td></tr>"; })
      .then(function () { busy = false; if (again) { again = false; load(); } });
  }
  d.addEventListener("DOMContentLoaded", function () {
    V4.api.get("/api/branches").then(function (r) { $("pl-branch").innerHTML = '<option value="">كل الفروع</option>' + (r.items || []).map(function (b) { return '<option value="' + esc(b.id) + '">' + esc(b.name) + "</option>"; }).join(""); }).catch(function () {});
    $("pl-q").addEventListener("input", V4.debounce(render, 150)); $("pl-branch").onchange = render; $("pl-status").onchange = render;
    load(); V4.live.onOrders(load); V4.live.onRequests(load);
  });
})(window, document);
