/* ═════════════════════════════════════════════════════════════════════
   الدهشان v4 — النواة المشتركة لكل الشاشات (JS عادي، مفيش npm/Vite)
   ─────────────────────────────────────────────────────────────────────
   V4.api      طلبات JSON لنفس مسارات /api/* بتاعة النظام (منطق الشغل في السيرفر مكان واحد)
   V4.toast    رسالة فوق في النص (مابتغطّيش أزرار الحفظ)
   V4.modal    فتح/قفل النوافذ — النافذة اللي فيها خانات مابتتقفلش بالضغط برّه
   V4.confirm  تأكيد بنافذة البرنامج بدل بتاعة المتصفح
   V4.live     البثّ الفوري (نفس realtime.js/Reverb) + استطلاع أمان
   V4.fmt      تنسيق الفلوس والوقت (توقيت القاهرة) والحالات
   ═════════════════════════════════════════════════════════════════════ */
(function (w, d) {
  "use strict";
  var meta = function (n) { var m = d.querySelector('meta[name="' + n + '"]'); return m ? m.getAttribute("content") : ""; };
  var V4 = { base: (meta("app-base") || "").replace(/\/$/, ""), user: null };
  try { V4.user = JSON.parse(meta("v4-user") || "null"); } catch (_) {}

  /* ── أدوات ── */
  V4.esc = function (v) { return String(v == null ? "" : v).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); };
  V4.url = function (p) { return V4.base + (p.charAt(0) === "/" ? p : "/" + p); };
  V4.qs = function (o) { var a = []; Object.keys(o || {}).forEach(function (k) { if (o[k] !== "" && o[k] != null) a.push(encodeURIComponent(k) + "=" + encodeURIComponent(o[k])); }); return a.length ? "?" + a.join("&") : ""; };
  V4.debounce = function (fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; };
  /* نفس قاعدة السيرفر OrdersController::cleanPhone */
  V4.cleanPhone = function (raw) {
    var s = String(raw == null ? "" : raw).replace(/[٠-٩]/g, function (c) { return String(c.charCodeAt(0) - 0x660); }).replace(/[۰-۹]/g, function (c) { return String(c.charCodeAt(0) - 0x6F0); }).replace(/[^0-9+]/g, "");
    if (!s) return "";
    var plus = s.charAt(0) === "+"; s = s.replace(/\+/g, "");
    if (s.indexOf("00") === 0) { plus = true; s = s.slice(2); }
    if (!s) return "";
    if (s.indexOf("20") === 0 && s.length > 10) { var r = s.slice(2); return r.charAt(0) === "0" ? r : "0" + r; }
    return plus && s.charAt(0) !== "0" ? "+" + s : s;
  };
  /* تطبيع عربي للبحث: «الجامعه» تلاقي «الجامعة» */
  V4.norm = function (v) {
    return String(v == null ? "" : v).toLowerCase().replace(/[ً-ْـ]/g, "").replace(/[أإآ]/g, "ا").replace(/ة/g, "ه").replace(/ى/g, "ي")
      .replace(/[٠-٩]/g, function (c) { return String(c.charCodeAt(0) - 0x660); }).replace(/\s+/g, " ").trim();
  };

  /* ── التنسيق ── */
  var STATUS_BADGE = { "قيد التنفيذ": "yellow", "جاري التوصيل": "blue", "تم التسليم": "green", "لم يتم التوصيل": "red", "ملغي": "gray", "مؤجل": "purple", "بانتظار الاستلام": "yellow", "تم الاستلام": "blue" };
  V4.fmt = {
    money: function (v) { var n = Math.round((Number(v) || 0) * 100) / 100; return n.toLocaleString("en-US", { maximumFractionDigits: 2 }) + " ج.م"; },
    num: function (v) { return (Number(v) || 0).toLocaleString("en-US"); },
    dt: function (iso) { if (!iso) return "—"; var t = new Date(iso); if (isNaN(t)) return "—"; return t.toLocaleString("ar-EG", { timeZone: "Africa/Cairo", day: "numeric", month: "numeric", hour: "2-digit", minute: "2-digit" }); },
    time: function (iso) { if (!iso) return "—"; var t = new Date(iso); if (isNaN(t)) return "—"; return t.toLocaleTimeString("ar-EG", { timeZone: "Africa/Cairo", hour: "2-digit", minute: "2-digit" }); },
    ago: function (iso) { if (!iso) return ""; var m = Math.floor((Date.now() - new Date(iso).getTime()) / 60000); if (m < 1) return "الآن"; if (m < 60) return "من " + m + " د"; var h = Math.floor(m / 60); return h < 24 ? "من " + h + " س" : "من " + Math.floor(h / 24) + " يوم"; },
    status: function (s) { return '<span class="badge ' + (STATUS_BADGE[s] || "gray") + '">' + V4.esc(s || "—") + "</span>"; },
    phone: function (p) { return p ? '<span class="ltr">' + V4.esc(p) + "</span>" : "—"; },
  };

  /* ── الـAPI ── */
  function request(method, path, body) {
    var opt = { method: method, credentials: "same-origin", headers: { Accept: "application/json" } };
    if (body !== undefined) { opt.headers["Content-Type"] = "application/json"; opt.body = JSON.stringify(body); }
    return fetch(V4.url(path), opt).then(function (res) {
      return res.text().then(function (txt) {
        var j = null; try { j = txt ? JSON.parse(txt) : null; } catch (_) {}
        if (res.status === 401) { w.location.href = V4.url("/v4/login") + "?next=" + encodeURIComponent(w.location.pathname + w.location.search); throw new Error("الجلسة انتهت"); }
        if (!res.ok || (j && j.ok === false)) { var e = new Error((j && j.error) || "حدث خطأ — جرّب تاني"); e.status = res.status; e.data = j; throw e; }
        return j || {};
      });
    }, function () { throw new Error("مفيش اتصال بالسيرفر — اتأكد من النت وجرّب تاني"); });
  }
  V4.api = {
    get: function (p, q) { return request("GET", p + V4.qs(q)); },
    post: function (p, b) { return request("POST", p, b || {}); },
    put: function (p, b) { return request("PUT", p, b || {}); },
    del: function (p) { return request("DELETE", p); },
  };

  /* ── التوست ── */
  var toastEl, toastT;
  V4.toast = function (msg, kind) {
    if (!toastEl) { toastEl = d.createElement("div"); toastEl.className = "toast"; toastEl.setAttribute("role", "status"); d.body.appendChild(toastEl); }
    toastEl.textContent = msg; toastEl.className = "toast show " + (kind === "error" || kind === "err" ? "err" : kind === "ok" || kind === "success" ? "ok" : "");
    clearTimeout(toastT); toastT = setTimeout(function () { toastEl.className = "toast"; }, kind === "err" || kind === "error" ? 5200 : 3200);
  };

  /* ── النوافذ ── */
  V4.modal = {
    open: function (id) { var m = d.getElementById(id); if (m) { m.classList.add("open"); var f = m.querySelector("[autofocus]"); if (f) setTimeout(function () { f.focus(); }, 40); } },
    close: function (id) { var m = typeof id === "string" ? d.getElementById(id) : id; if (m) m.classList.remove("open"); },
    hasFields: function (m) { return !!m.querySelector("input:not([type=hidden]), textarea, select"); },
  };
  d.addEventListener("click", function (e) {
    var o = e.target.closest("[data-open]"); if (o) { e.preventDefault(); V4.modal.open(o.getAttribute("data-open")); return; }
    var c = e.target.closest("[data-close]"); if (c) { e.preventDefault(); V4.modal.close(c.getAttribute("data-close") || c.closest(".modal")); return; }
    /* الضغط على الخلفية: نوافذ العرض بس هي اللي بتتقفل — اللي فيها خانات لأ (المكتوب مايضيعش) */
    if (e.target.classList && e.target.classList.contains("modal") && e.target.classList.contains("open")) {
      if (e.target.hasAttribute("data-locked")) return;            // نافذة إجبارية (رسالة الواتساب) — بتتقفل من زرارها بس
      if (V4.modal.hasFields(e.target)) V4.toast("المكتوب لسه موجود — اقفل النافذة من ✕ أو «إلغاء»");
      else V4.modal.close(e.target);
    }
  });
  d.addEventListener("keydown", function (e) {
    if (e.key !== "Escape") return;
    var open = d.querySelectorAll(".modal.open"); if (!open.length) return;
    var top = open[open.length - 1]; if (!top.hasAttribute("data-locked") && !V4.modal.hasFields(top)) V4.modal.close(top);
  });

  V4.confirm = function (msg, opt) {
    opt = opt || {};
    return new Promise(function (resolve) {
      var m = d.createElement("div"); m.className = "modal top open";
      m.innerHTML = '<div class="box" style="max-width:440px"><div class="head"><h2>' + V4.esc(opt.title || "تأكيد") + '</h2></div><p style="margin:0 0 1rem;line-height:1.9">' + V4.esc(msg) + '</p>' +
        (opt.input ? '<div class="field"><label>' + V4.esc(opt.input) + '</label><textarea rows="2" id="_v4c_in"></textarea></div>' : "") +
        '<div class="foot"><button class="btn ' + (opt.danger ? "red" : "primary") + '" id="_v4c_ok">' + V4.esc(opt.ok || "تأكيد") + '</button><button class="btn" id="_v4c_no">إلغاء</button></div></div>';
      d.body.appendChild(m);
      var done = function (v) { m.remove(); resolve(v); };
      m.querySelector("#_v4c_ok").onclick = function () {
        if (opt.input) { var val = m.querySelector("#_v4c_in").value.trim(); if (opt.required && !val) { V4.toast("اكتب " + opt.input, "err"); return; } done(val || true); }
        else done(true);
      };
      m.querySelector("#_v4c_no").onclick = function () { done(false); };
      (m.querySelector("#_v4c_in") || m.querySelector("#_v4c_ok")).focus();
    });
  };

  /* ── البثّ الفوري ─────────────────────────────────────────────────────
     نفس طبقة النظام (assets/js/realtime.js + Reverb). الحدث «جرس مش بيانات»: الشاشة بتعيد تحميل
     اللي قدامها بس. الاستطلاع كل 60 ثانية شبكة أمان، ورجوع التبويب بيحدّث فورًا. */
  V4.live = (function () {
    var subs = { orders: [], requests: [] }, started = false, chip = null;
    function fire(kind, p) { subs[kind].forEach(function (cb) { try { cb(p || {}); } catch (e) { console.error(e); } }); }
    function paint(state) {
      if (!chip) chip = d.getElementById("v4-live"); if (!chip) return;
      var on = state === "connected";
      chip.className = "chip " + (on ? "live" : "off"); chip.hidden = false;
      chip.innerHTML = '<span class="dot"></span><span class="t">' + (on ? "بثّ مباشر" : "بيتحدّث كل دقيقة") + "</span>";
      chip.title = on ? "أي تغيير بيوصل في لحظته" : "البثّ المباشر مش متصل — التحديث بالاستطلاع";
    }
    function start() {
      if (started) return; started = true;
      setInterval(function () { if (!d.hidden) { fire("orders", { poll: true }); fire("requests", { poll: true }); } }, 60000);
      d.addEventListener("visibilitychange", function () { if (!d.hidden) { fire("orders", { poll: true }); fire("requests", { poll: true }); } });
      var RT = w.REALTIME;
      if (!RT || !RT.available()) { paint("off"); return; }
      RT.onStateChange(function (s) { paint(s.state); if (s.reconnected) { fire("orders", { poll: true }); fire("requests", { poll: true }); } });
      var pokeO = RT.coalesce(function () { fire("orders", {}); }, 300), pokeR = RT.coalesce(function () { fire("requests", {}); }, 300);
      V4.api.get("/api/branches").then(function (r) {
        var ids = (r.items || []).map(function (b) { return b.id; }).filter(Boolean);
        if (ids.length) RT.syncBranches(ids, pokeO, pokeR);
        paint(RT.state());
      }).catch(function () { paint("off"); });
    }
    return {
      onOrders: function (cb) { subs.orders.push(cb); start(); },
      onRequests: function (cb) { subs.requests.push(cb); start(); },
    };
  })();

  /* ── الواجهة العامة: القائمة على الموبايل، الوضع الليلي، حجم الخط، الخروج ── */
  d.addEventListener("DOMContentLoaded", function () {
    var side = d.querySelector(".side"), burger = d.querySelector(".burger"), back = d.querySelector(".side-backdrop");
    if (burger && side) burger.onclick = function () { side.classList.toggle("open"); };
    if (back && side) back.onclick = function () { side.classList.remove("open"); };
    var th = d.querySelector("[data-ux-theme-toggle]");
    if (th) th.onclick = function () { var h = d.documentElement, dark = h.getAttribute("data-theme") === "dark"; if (dark) h.removeAttribute("data-theme"); else h.setAttribute("data-theme", "dark"); try { localStorage.setItem("dahshan.v4.theme", dark ? "light" : "dark"); } catch (_) {} };
    var fb = d.querySelector("[data-ux-font-cycle]");
    if (fb) fb.onclick = function () { var h = d.documentElement, cur = h.getAttribute("data-font") || "m", nx = cur === "m" ? "l" : cur === "l" ? "s" : "m"; if (nx === "m") h.removeAttribute("data-font"); else h.setAttribute("data-font", nx); try { localStorage.setItem("dahshan.v4.font", nx); } catch (_) {} };
    var lo = d.querySelector("[data-v4-logout]");
    if (lo) lo.onclick = function () {
      V4.confirm("تخرج من البرنامج؟", { title: "تسجيل الخروج", ok: "خروج" }).then(function (y) {
        if (!y) return;
        V4.api.post("/api/logout").catch(function () {}).then(function () { w.location.href = V4.url("/v4/login"); });
      });
    };
  });

  w.V4 = V4;
})(window, document);
