/* ═════════════════════════════════════════════════════════════════════
   v4 — إجبار إرسال رسالة الواتساب للمستلم بعد تسجيل الأوردر
   قرار صاحب النظام 2026-09-01 (نفس قواعد التطبيق القديم بالحرف):
     • «افتح واتساب» بيفتح التبويب وبس — «اتبعتت» تأكيد بني آدم صريح هو اللي بيكتب في القاعدة.
     • النافذة مابتتقفلش غير لما كل الرسايل تتبعت (أو تتخطّى اللي من غير رقم صالح).
     • الأوردر اتسجّل خلاص — ممنوع أي رسالة هنا توحي بفشل الحفظ.
   ═════════════════════════════════════════════════════════════════════ */
(function (w, d) {
  "use strict";
  var V4 = w.V4, esc = V4.esc;
  function intl(phone) { var p = String(phone || "").replace(/\D/g, ""); if (p.charAt(0) === "0") p = "2" + p; return p.length >= 10 ? p : ""; }

  V4.waNotify = function (orderIds) {
    var ids = {}; (orderIds || []).forEach(function (i) { ids[String(i)] = 1; });
    if (!Object.keys(ids).length) return Promise.resolve();
    return V4.api.get("/api/order-notifications", { status: "pending" }).then(function (r) {
      var rows = (r.items || []).filter(function (n) { return ids[String(n.orderId)]; });
      if (!rows.length) return;                       // مفيش رسايل معلّقة للأوردر ده — مفيش إجبار على العدم
      return new Promise(function (resolve) { render(rows, resolve); });
    }).catch(function () { V4.toast("الأوردر اتسجّل ✓ — بس تعذّر تحميل رسالة الواتساب، ابعتها من شاشة رسايل العملاء", "err"); });
  };

  function render(rows, resolve) {
    var st = {}; rows.forEach(function (n) { st[n.id] = intl(n.phone) ? "wait" : "nophone"; });
    var body = d.getElementById("m-wa-body");
    var nums = rows.map(function (n) { return n.orderNum; }).filter(function (v, i, a) { return v && a.indexOf(v) === i; }).join("، ");
    body.innerHTML = '<p class="muted small" style="margin-top:0">الأوردر <b class="ltr">' + esc(nums) + "</b> اتسجّل ✓ — لازم المستلم ياخد رسالة برقم شحنته قبل ما تقفل.</p>" +
      rows.map(function (n) {
        var ok = st[n.id] !== "nophone";
        return '<div class="wa-row" data-row="' + esc(n.id) + '"><div style="display:flex;justify-content:space-between;gap:.5rem;flex-wrap:wrap"><b>' + esc(n.name || "المستلم") + '</b><span class="ltr blue">' + esc(n.phone || "—") + "</span></div>" +
          "<pre>" + esc(n.body || "") + "</pre><div style=\"display:flex;gap:.5rem\">" +
          (ok ? '<button class="btn green" style="flex:1;justify-content:center" data-wa-open="' + esc(n.id) + '"><i class="fab fa-whatsapp"></i> افتح واتساب</button><button class="btn" style="flex:1;justify-content:center" data-wa-sent="' + esc(n.id) + '" disabled><i class="fas fa-check"></i> اتبعتت</button>'
              : '<div class="small yellow" style="flex:1">⚠️ مفيش رقم صالح — الرسالة هتفضل معلّقة في شاشة رسايل العملاء</div><button class="btn" data-wa-skip="' + esc(n.id) + '">تخطّي</button>') +
          "</div></div>";
      }).join("") +
      '<button class="btn block" id="wa-close" disabled>إغلاق — لسه فيه رسايل ماتبعتتش</button>';
    V4.modal.open("m-wa");

    var done = function () { return Object.keys(st).every(function (k) { return st[k] === "sent" || st[k] === "skipped"; }); };
    var refresh = function () { var b = d.getElementById("wa-close"); if (done()) { b.disabled = false; b.className = "btn green block"; b.innerHTML = '<i class="fas fa-check"></i> تم — إغلاق'; } };
    refresh();
    body.onclick = function (e) {
      var o = e.target.closest("[data-wa-open]"), s = e.target.closest("[data-wa-sent]"), k = e.target.closest("[data-wa-skip]");
      if (o) {
        var id = o.getAttribute("data-wa-open"), n = rows.filter(function (x) { return String(x.id) === id; })[0]; if (!n) return;
        var win = w.open("https://wa.me/" + intl(n.phone) + "?text=" + encodeURIComponent(n.body || ""), "_blank");
        if (!win) { V4.toast("المتصفح حجب فتح واتساب — اسمح بالنوافذ المنبثقة", "err"); return; }
        st[id] = "opened"; var sb = body.querySelector('[data-wa-sent="' + id + '"]'); if (sb) { sb.disabled = false; sb.className = "btn blue"; sb.style.cssText = "flex:1;justify-content:center"; }
      } else if (s) {
        var sid = s.getAttribute("data-wa-sent"); if (st[sid] !== "opened") return;     // «اتبعتت» بعد «افتح» بس
        s.disabled = true;
        V4.api.post("/api/order-notifications/" + encodeURIComponent(sid) + "/sent").then(function () { st[sid] = "sent"; s.className = "btn"; s.innerHTML = '<i class="fas fa-check green"></i> اتبعتت'; refresh(); },
          function (err) { s.disabled = false; V4.toast("تعذّر التعليم: " + err.message, "err"); });
      } else if (k) { st[k.getAttribute("data-wa-skip")] = "skipped"; k.disabled = true; k.textContent = "متخطّاة"; refresh(); }
      else if (e.target.closest("#wa-close") && done()) { V4.modal.close("m-wa"); resolve(); }
    };
  }
})(window, document);
