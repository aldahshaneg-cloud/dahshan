/* v4 · كول سنتر — دليل المناطق (قراءة: العميل بيسأل «بتوصّلوا فين وبكام؟») */
(function (w, d) {
  "use strict";
  var V4 = w.V4, esc = V4.esc, zones = [], branches = {}, shown = 50;
  var $ = function (id) { return d.getElementById(id); };
  function render() {
    var words = V4.norm($("z-q").value).split(" ").filter(Boolean), br = $("z-branch").value;
    var rows = zones.filter(function (z) {
      if (br && String(z.branchId) !== br) return false;
      if (!words.length) return true;
      var hay = V4.norm(z.name + " " + (branches[z.branchId] || "") + " " + z.price);
      return words.every(function (x) { return hay.indexOf(x) >= 0; });
    });
    $("z-count").textContent = rows.length === zones.length ? zones.length + " منطقة" : "ظاهر " + rows.length + " من " + zones.length;
    $("z-body").innerHTML = rows.length ? rows.slice(0, shown).map(function (z, i) {
      return '<tr><td class="muted">' + (i + 1) + '</td><td class="bold">' + esc(z.name) + "</td><td>" + esc(branches[z.branchId] || "—") + '</td><td class="green bold">' + esc(V4.fmt.money(z.price)) + "</td>" +
        "<td>" + (z.home ? '<span class="badge green" title="المنطقة دي بتحدّد الفرع لما تكون منطقة استلام">منطقة الفرع</span>' : '<span class="badge gray">توصيل ليها</span>') + "</td></tr>";
    }).join("") : '<tr><td colspan="5" class="empty">مفيش منطقة مطابقة</td></tr>';
    $("z-pager").innerHTML = rows.length > shown ? '<button class="btn sm" id="z-more">عرض ' + Math.min(50, rows.length - shown) + " كمان</button>" : "";
  }
  d.addEventListener("DOMContentLoaded", function () {
    V4.api.get("/v4/callcenter/form-data").then(function (r) {
      zones = r.zones || []; (r.branches || []).forEach(function (b) { branches[b.id] = b.name; });
      $("z-branch").innerHTML = '<option value="">كل الفروع</option>' + (r.branches || []).map(function (b) { return '<option value="' + esc(b.id) + '">' + esc(b.name) + "</option>"; }).join("");
      render();
    }).catch(function (e) { $("z-body").innerHTML = '<tr><td colspan="5"><div class="alert err" style="margin:0">' + esc(e.message) + "</div></td></tr>"; });
    var re = function () { shown = 50; render(); };
    $("z-q").addEventListener("input", V4.debounce(re, 120)); $("z-branch").onchange = re;
    $("z-pager").addEventListener("click", function (e) { if (e.target.closest("#z-more")) { shown += 50; render(); } });
  });
})(window, document);
