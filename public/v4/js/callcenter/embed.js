/* ═════════════════════════════════════════════════════════════════════
   v4 · كول سنتر — عرض شاشة من التطبيق القديم **بكودها نفسه** جوه الغلاف الجديد
   (قرار صاحب النظام 2026-09-21: «في الإصدار السابق أمور لا أريد تغييرها مثل عمل أوردر جديد وصفحة
   الأوردرات النشطة»). الغلاف بيعمل تلات حاجات بس:
     1) يظبط كاش الجلسة المحلي `tiar-session` اللي القديم بيستأنف منه (الجلسة الحقيقية كوكي السيرفر،
        والقديم نفسه بيتأكد منها بـ/api/me) — فمفيش شاشة دخول تانية.
     2) يفتح callcenter.html?embed=<page>[&new=1]&theme=… ويوحّد الوضع الليلي معاه.
     3) زرار «طلب جديد» في الشريط العلوي بيفتح مودال القديم نفسه من غير إعادة تحميل.
   ═════════════════════════════════════════════════════════════════════ */
(function (w, d) {
  "use strict";
  var V4 = w.V4, fr = d.getElementById("v4-embed"); if (!fr) return;
  var dark = function () { return d.documentElement.getAttribute("data-theme") === "dark"; };

  try {
    var cur = null; try { cur = JSON.parse(localStorage.getItem("tiar-session") || "null"); } catch (_) {}
    if (!cur || cur.username !== V4.user.username) localStorage.setItem("tiar-session", JSON.stringify({ username: V4.user.username, role: fr.getAttribute("data-role") }));
  } catch (_) {}

  fr.src = fr.getAttribute("data-src") + "?embed=" + encodeURIComponent(fr.getAttribute("data-page")) + (fr.getAttribute("data-new") ? "&new=1" : "") + "&theme=" + (dark() ? "dark" : "light");

  w.addEventListener("message", function (e) {
    if (e.origin !== w.location.origin || !e.data || !e.data.v4embed) return;
    if (e.data.v4embed === "login") w.location.href = V4.url("/v4/login") + "?next=" + encodeURIComponent(w.location.pathname);
    if (e.data.v4embed === "ready") fr.classList.add("ready");
  });

  d.addEventListener("DOMContentLoaded", function () {
    /* «طلب جديد» فوق = مودال القديم نفسه (لو الشاشة المعروضة هي صفحة الطلبات) */
    var nb = d.querySelector('.top-tools a.btn.primary');
    if (nb && fr.getAttribute("data-page") === "orders") nb.addEventListener("click", function (e) {
      try { if (fr.contentWindow && typeof fr.contentWindow.openModal === "function") { e.preventDefault(); fr.contentWindow.openModal("order"); } } catch (_) {}
    });
    /* الوضع الليلي بيتغيّر في الاتنين مع بعض */
    var th = d.querySelector("[data-ux-theme-toggle]");
    if (th) th.addEventListener("click", function () {
      setTimeout(function () { try { var r = fr.contentDocument.documentElement; r.classList.toggle("light", !dark()); localStorage.setItem("dahshan-theme", dark() ? "dark" : "light"); localStorage.setItem("tiar-theme", dark() ? "dark" : "light"); } catch (_) {} }, 0);
    });
  });
})(window, document);
