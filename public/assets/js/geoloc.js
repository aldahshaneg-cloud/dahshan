/**
 * geoloc.js — لوكيشن العميل بالإحداثيات (branch.html · tiar.html · callcenter.html)
 *
 * ═══ ليه الملف ده موجود ═══
 * النقطة على الخريطة مصدرها إحداثيات حقيقية بس (من تطبيق العميل/المحل)،
 * والأوردر اللي الفرع بيضربه كان بيطلع «بدون موقع» — والموظف واقف قدام
 * جوجل مابس شايف مكان العميل بالظبط ومش عارف يدخّله (طلب صاحب النظام
 * 2026-09-03: «لوكيشن عميل عايز أضيفه بدقة على الماب وأنا بضرب الأوردر…
 * ولما أفتح النقطة بتاعة العميل مش بقدر أعمل نسخ للوكيشن»).
 *
 * الملف بيوفّر حاجتين:
 *   • parse(text)  — بيفهم أي صيغة الموظف هيلزقها: «31.018732, 31.228285»
 *     أو لينك جوجل مابس (@lat,lng · ?q=lat,lng · !3d…!4d…) أو صيغة
 *     الدرجات 31°01'07.4"N 31°13'41.8"E.
 *   • أزرار «📋 نسخ» و«🗺️ جوجل» جاهزة لأي مكان بيعرض نقطة (البوب-أب على
 *     الخريطة، تفاصيل الأوردر).
 *
 * الحقل اختياري: من غيره الأوردر بيتسجّل عادي زي الأول.
 */
(function (global) {
  "use strict";

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function num(v) { var n = parseFloat(v); return isFinite(n) ? n : NaN; }

  function finish(lat, lng) {
    if (!isFinite(lat) || !isFinite(lng)) return null;
    if (Math.abs(lat) > 90 || Math.abs(lng) > 180) return null;
    return { lat: Math.round(lat * 1e6) / 1e6, lng: Math.round(lng * 1e6) / 1e6 };
  }

  /** بيرجّع {lat,lng} أو null لو النص مش مفهوم */
  function parse(text) {
    if (text == null) return null;
    var t = String(text).trim();
    if (!t) return null;
    try { t = decodeURIComponent(t); } catch (e) { /* نص عادي */ }
    // أرقام عربية → لاتينية (لو اتكتبت من كيبورد عربي)
    t = t.replace(/[٠-٩]/g, function (d) { return String("٠١٢٣٤٥٦٧٨٩".indexOf(d)); });

    // صيغة الدرجات: 31°01'07.4"N 31°13'41.8"E
    var dms = /(\d{1,3})\s*[°º]\s*(\d{1,2})\s*['′’]\s*(\d{1,2}(?:\.\d+)?)\s*["″”]?\s*([NSns])[\s,]*(\d{1,3})\s*[°º]\s*(\d{1,2})\s*['′’]\s*(\d{1,2}(?:\.\d+)?)\s*["″”]?\s*([EWew])/.exec(t);
    if (dms) {
      var la = (num(dms[1]) + num(dms[2]) / 60 + num(dms[3]) / 3600) * (/s/i.test(dms[4]) ? -1 : 1);
      var lo = (num(dms[5]) + num(dms[6]) / 60 + num(dms[7]) / 3600) * (/w/i.test(dms[8]) ? -1 : 1);
      return finish(la, lo);
    }
    var m;
    // لينك مكان جوجل: …!3d31.0187321!4d31.2282848
    m = /!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/.exec(t);
    if (m) return finish(num(m[1]), num(m[2]));
    // ?q=lat,lng · ll= · query= · destination=
    m = /[?&](?:q|query|ll|center|destination|daddr|saddr)=(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/.exec(t);
    if (m) return finish(num(m[1]), num(m[2]));
    // …/@31.0187,31.2257,17z
    m = /@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/.exec(t);
    if (m) return finish(num(m[1]), num(m[2]));
    // «31.018732, 31.228285» أو بمسافة أو بفاصلة عربية
    m = /^\s*(-?\d{1,3}(?:\.\d+)?)\s*[,،;]?\s+?(-?\d{1,3}(?:\.\d+)?)\s*$/.exec(t) ||
        /^\s*(-?\d{1,3}(?:\.\d+)?)\s*[,،;]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/.exec(t);
    if (m) return finish(num(m[1]), num(m[2]));
    return null;
  }

  function fmt(lat, lng) {
    var g = finish(num(lat), num(lng));
    return g ? g.lat.toFixed(6) + ", " + g.lng.toFixed(6) : "";
  }
  function gmaps(lat, lng) {
    var g = finish(num(lat), num(lng));
    return g ? "https://www.google.com/maps?q=" + g.lat.toFixed(6) + "," + g.lng.toFixed(6) : "#";
  }

  function toast(msg, kind) {
    if (typeof global.showToast === "function") global.showToast(msg, kind || "success");
  }
  async function copy(lat, lng) {
    var text = fmt(lat, lng);
    if (!text) return;
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        var ta = document.createElement("textarea");
        ta.value = text; ta.style.cssText = "position:fixed;opacity:0";
        document.body.appendChild(ta); ta.select(); document.execCommand("copy"); ta.remove();
      }
      toast("📋 اتنسخ اللوكيشن: " + text);
    } catch (e) {
      toast("مقدرتش أنسخ — الإحداثيات: " + text, "error");
    }
  }

  var BTN = "border:1px solid rgba(59,130,246,.45);background:rgba(59,130,246,.10);color:#2563eb;" +
    "padding:2px 8px;border-radius:7px;cursor:pointer;font-size:.72rem;font-weight:700;text-decoration:none;font-family:inherit";

  /** سطر إحداثيات + نسخ + جوجل — للبوب-أب على الخريطة (خلفية فاتحة) */
  function popupRow(g) {
    if (!g || !isFinite(num(g.lat))) return "";
    var t = fmt(g.lat, g.lng);
    return '<div style="margin-top:5px;display:flex;align-items:center;gap:5px;flex-wrap:wrap">' +
      '<span dir="ltr" style="font-family:monospace;font-size:.72rem;color:#334155">' + esc(t) + '</span>' +
      '<button type="button" onclick="GeoLoc.copy(' + num(g.lat) + ',' + num(g.lng) + ')" style="' + BTN + '">📋 نسخ</button>' +
      '<a href="' + esc(gmaps(g.lat, g.lng)) + '" target="_blank" rel="noopener" style="' + BTN + '">🗺️ جوجل</a>' +
      '</div>';
  }

  /** نفس السطر لتفاصيل الأوردر (بيورث لون النص من الصفحة) */
  function actionsHtml(lat, lng) {
    var g = finish(num(lat), num(lng));
    if (!g) return "—";
    return '<span style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap">' +
      '<span dir="ltr" style="font-family:monospace;font-size:.8rem">' + esc(fmt(g.lat, g.lng)) + '</span>' +
      '<button type="button" onclick="GeoLoc.copy(' + g.lat + ',' + g.lng + ')" style="' + BTN + '">📋 نسخ</button>' +
      '<a href="' + esc(gmaps(g.lat, g.lng)) + '" target="_blank" rel="noopener" style="' + BTN + '">🗺️ فتح في جوجل</a>' +
      '</span>';
  }

  /** حقل الإدخال في فورم الأوردر/التعديل — اختياري، بيتحقق وهو بيتكتب */
  function fieldHtml(id, value) {
    return '<div style="grid-column:1/-1">' +
      '<label for="' + esc(id) + '" style="font-size:.78rem;color:var(--muted,#94a3b8);display:block;margin-bottom:4px">' +
        '📍 لوكيشن العميل <span style="opacity:.75">(اختياري — الصق إحداثيات أو لينك خرائط جوجل)</span></label>' +
      '<input id="' + esc(id) + '" type="text" dir="ltr" value="' + esc(value || "") + '" ' +
        'placeholder="31.018732, 31.228285  أو  https://maps.google.com/…" oninput="GeoLoc.liveCheck(this)" ' +
        'style="width:100%;box-sizing:border-box;padding:8px 10px;background:var(--panel,#1e1e24);border:1px solid var(--border,rgba(128,128,128,.3));border-radius:8px;color:var(--text,#eee);font-family:monospace;font-size:.85rem" />' +
      '<div id="' + esc(id) + '-st" style="font-size:.72rem;margin-top:4px;min-height:14px;color:var(--muted,#94a3b8)"></div>' +
      '</div>';
  }

  function liveCheck(input) {
    var st = document.getElementById(input.id + "-st");
    if (!st) return;
    var t = input.value.trim();
    if (!t) { st.textContent = ""; input.style.borderColor = ""; return; }
    var g = parse(t);
    if (g) {
      st.innerHTML = '<span style="color:#22c55e">✓ ' + esc(fmt(g.lat, g.lng)) + '</span> — <a href="' + esc(gmaps(g.lat, g.lng)) + '" target="_blank" rel="noopener" style="color:#3b82f6">افتح على جوجل للتأكد</a>';
      input.style.borderColor = "#22c55e";
    } else {
      st.innerHTML = '<span style="color:#ef4444">✗ مش مفهوم — الصق «خط العرض, خط الطول» أو لينك من خرائط جوجل</span>';
      input.style.borderColor = "#ef4444";
    }
  }

  /** قراءة الحقل وقت الإرسال: {ok, geo} — فاضي = ok بلا لوكيشن */
  function read(id) {
    var el = document.getElementById(id);
    var t = el ? el.value.trim() : "";
    if (!t) return { ok: true, geo: null };
    var g = parse(t);
    return g ? { ok: true, geo: g } : { ok: false, geo: null };
  }

  global.GeoLoc = { parse: parse, fmt: fmt, gmaps: gmaps, copy: copy,
                    popupRow: popupRow, actionsHtml: actionsHtml, fieldHtml: fieldHtml,
                    liveCheck: liveCheck, read: read };
})(window);
