/**
 * pilotmotion.js — حركة ماركر الطيار على الخريطة: تزحلق مش نطّ
 *
 * ═══ المشكلة (بلاغ صاحب النظام 2026-09-07) ═══
 * «الطيار بيثبت في مكان على الخريطة ومش بيظهر بشكل جيد». الماركر كان
 * بيتحط في النقطة الجديدة بـsetLatLng لحظة وصولها — يعني وقفة طول
 * الفاصل بين النقطتين ونطّة. حتى بنقطة كل ٥ ثواني ده بيبان تجميد.
 *
 * ═══ إزاي الشركات الكبيرة بتعملها ═══
 * الحركة اللي بتبان «حية» في أوبر وكريم جاية من **الخريطة** مش من كمية
 * البيانات: الماركر بيتزحلق بين النقاط على مدار الفاصل الزمني، ومعاه
 * سهم اتجاه. عشان الزحلقة تبقى مستمرة بنعرض الحركة **متأخرة ٢٠ ثانية**
 * (LAG_MS): الدفعة بتوصل كل ١٥ ثانية، فوقت ما نكون بنعرض ثانية «الآن-٢٠»
 * تكون النقاط اللي بعدها وصلت خلاص — مفيش وقفة استنى.
 *
 * ═══ العقد ═══
 *   PilotMotion.apply(marker, pilot, {trailLayer, color})
 *     pilot.location {lat,lng,updatedAt} · pilot.trail [{lat,lng,t}] أو null
 *     · pilot.heading درجات أو null
 *   PilotMotion.remove(id) — لما الماركر يتشال
 *   PilotMotion.arrowHtml() — عنصر السهم اللي بيتحط جوه أيقونة الماركر
 *
 * لو `trail` مش موجودة (طيار على تطبيق قديم بيبعت نقطة كل دقيقة) بنعمل
 * زحلقة بسيطة من مكان الماركر الحالي للنقطة الجديدة على ٤ ثواني.
 *
 * مافيش template literals هنا عن قصد (قاعدة الشغل ٦).
 */
(function (w) {
  'use strict';

  var LAG_MS       = 20000;   // تأخير العرض عشان الحركة تبقى مستمرة
  var SIMPLE_MS    = 4000;    // زحلقة بسيطة لما مفيش أثر
  var STALE_MS     = 90000;   // أثر أقدم من كده = نطّ للنقطة الأخيرة مباشرة
  var KEEP_MS      = 150000;  // بنحتفظ بنقاط آخر دقيقتين ونص
  var MOVE_MIN_M   = 2;       // تحت كده مابنحرّكش الماركر (رعشة GPS)

  var _st = {};      // id → {pts, curLat, curLng, fromLat, fromLng, toLat, toLng, t0, t1, mode, heading, marker, line}
  var _raf = null;

  function toMs(v) {
    if (v == null) return null;
    if (typeof v === 'number') return v;
    var t = new Date(v).getTime();
    return isFinite(t) ? t : null;
  }

  /* الاتجاه من نقطة لنقطة بالدرجات (٠ = شمال، بعقارب الساعة) */
  function bearing(aLat, aLng, bLat, bLng) {
    var toR = Math.PI / 180;
    var dLng = (bLng - aLng) * toR;
    var y = Math.sin(dLng) * Math.cos(bLat * toR);
    var x = Math.cos(aLat * toR) * Math.sin(bLat * toR) - Math.sin(aLat * toR) * Math.cos(bLat * toR) * Math.cos(dLng);
    return ((Math.atan2(y, x) * 180 / Math.PI) + 360) % 360;
  }

  function distM(aLat, aLng, bLat, bLng) {
    var toR = Math.PI / 180, R = 6371000;
    var dLat = (bLat - aLat) * toR, dLng = (bLng - aLng) * toR;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(aLat * toR) * Math.cos(bLat * toR) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * R * Math.asin(Math.sqrt(h));
  }

  /* السهم — بيتحط جوه أيقونة الماركر (٤٠×٤٠) وبيتلف حوالين مركزه */
  function arrowHtml() {
    return '<div class="pm-arrow" style="position:absolute;inset:0;display:flex;justify-content:center;' +
           'align-items:flex-start;pointer-events:none;transform:rotate(0deg);transition:transform .6s ease;' +
           'visibility:hidden"><span style="margin-top:-11px;font-size:13px;line-height:1;color:#111827;' +
           'text-shadow:0 0 3px #fff,0 0 3px #fff">▲</span></div>';
  }

  function setArrow(s) {
    var el = s.marker && s.marker.getElement && s.marker.getElement();
    if (!el) return;
    var a = el.querySelector('.pm-arrow');
    if (!a) return;
    if (s.heading == null) { a.style.visibility = 'hidden'; return; }
    a.style.visibility = 'visible';
    a.style.transform = 'rotate(' + Math.round(s.heading) + 'deg)';
  }

  function ensureLoop() {
    if (_raf) return;
    _raf = w.requestAnimationFrame(frame);
  }

  function frame() {
    _raf = null;
    var now = Date.now(), active = false;
    Object.keys(_st).forEach(function (id) {
      var s = _st[id];
      if (!s.marker) return;
      var lat, lng, hd = null;
      if (s.mode === 'trail') {
        var target = now - LAG_MS, pts = s.pts;
        if (!pts.length) return;
        if (target <= pts[0].t) { lat = pts[0].lat; lng = pts[0].lng; }
        else if (target >= pts[pts.length - 1].t) {
          var L = pts[pts.length - 1]; lat = L.lat; lng = L.lng;
        } else {
          for (var i = 0; i < pts.length - 1; i++) {
            var a = pts[i], b = pts[i + 1];
            if (target >= a.t && target <= b.t) {
              var f = b.t === a.t ? 1 : (target - a.t) / (b.t - a.t);
              lat = a.lat + (b.lat - a.lat) * f;
              lng = a.lng + (b.lng - a.lng) * f;
              if (distM(a.lat, a.lng, b.lat, b.lng) >= MOVE_MIN_M) hd = bearing(a.lat, a.lng, b.lat, b.lng);
              active = true;
              break;
            }
          }
        }
      } else {
        // زحلقة بسيطة من → إلى على SIMPLE_MS
        var p = s.t1 > s.t0 ? Math.min(1, (now - s.t0) / (s.t1 - s.t0)) : 1;
        p = 1 - Math.pow(1 - p, 2);   // ease-out
        lat = s.fromLat + (s.toLat - s.fromLat) * p;
        lng = s.fromLng + (s.toLng - s.fromLng) * p;
        if (p < 1) active = true;
        if (distM(s.fromLat, s.fromLng, s.toLat, s.toLng) >= MOVE_MIN_M) hd = bearing(s.fromLat, s.fromLng, s.toLat, s.toLng);
      }
      if (lat == null) return;
      if (s.curLat == null || distM(s.curLat, s.curLng, lat, lng) >= 0.3) {
        s.curLat = lat; s.curLng = lng;
        try { s.marker.setLatLng([lat, lng]); } catch (e) { /* الماركر اتشال */ }
      }
      if (hd != null) s.heading = hd;
      setArrow(s);
    });
    if (active) ensureLoop();
  }

  function apply(marker, pilot, opts) {
    opts = opts || {};
    var id = String(pilot.id);
    var loc = pilot.location || {};
    var lat = parseFloat(loc.lat), lng = parseFloat(loc.lng);
    if (!isFinite(lat) || !isFinite(lng)) return;
    var s = _st[id] || (_st[id] = { pts: [], curLat: null, curLng: null, heading: null });
    s.marker = marker;
    if (pilot.heading != null && isFinite(parseFloat(pilot.heading))) s.heading = parseFloat(pilot.heading);

    var now = Date.now();
    var trail = Array.isArray(pilot.trail) ? pilot.trail : null;
    var updMs = toMs(loc.updatedAt);

    if (trail && trail.length) {
      // دمج النقاط الجديدة (بالوقت) والاحتفاظ بآخر دقيقتين ونص بس
      var have = {};
      s.pts.forEach(function (p) { have[p.t] = true; });
      trail.forEach(function (p) {
        var t = toMs(p.t), la = parseFloat(p.lat), ln = parseFloat(p.lng);
        if (t == null || !isFinite(la) || !isFinite(ln) || have[t]) return;
        s.pts.push({ t: t, lat: la, lng: ln }); have[t] = true;
      });
      s.pts.sort(function (a, b) { return a.t - b.t; });
      s.pts = s.pts.filter(function (p) { return now - p.t < KEEP_MS; });
      var last = s.pts[s.pts.length - 1];
      /* الأثر قديم (الطيار واقف أو التطبيق سكت) → مفيش زحلقة على نقاط
         بايتة؛ نحط الماركر على آخر موقع معروف ونسيب السهم على حاله. */
      if (!last || now - last.t > STALE_MS) {
        s.mode = 'snap';
        s.fromLat = s.toLat = lat; s.fromLng = s.toLng = lng; s.t0 = s.t1 = now;
      } else {
        s.mode = 'trail';
        // آخر موقع معروف كنقطة ختامية لو أحدث من آخر نقطة أثر
        if (updMs != null && updMs > last.t + 1000 && distM(last.lat, last.lng, lat, lng) >= MOVE_MIN_M) {
          s.pts.push({ t: updMs, lat: lat, lng: lng });
        }
      }
    } else {
      // مفيش أثر — زحلقة بسيطة من مكان الماركر الحالي
      var from = s.curLat != null ? [s.curLat, s.curLng] : (function () {
        try { var ll = marker.getLatLng(); return [ll.lat, ll.lng]; } catch (e) { return [lat, lng]; }
      })();
      s.mode = 'simple';
      s.fromLat = from[0]; s.fromLng = from[1];
      s.toLat = lat; s.toLng = lng;
      s.t0 = now; s.t1 = now + (distM(from[0], from[1], lat, lng) < MOVE_MIN_M ? 0 : SIMPLE_MS);
    }

    // خط الأثر — آخر دقيقتين
    if (opts.trailLayer && w.L) {
      var lls = (s.mode === 'trail' ? s.pts : []).map(function (p) { return [p.lat, p.lng]; });
      if (lls.length >= 2) {
        if (!s.line) {
          s.line = w.L.polyline(lls, { color: opts.color || '#3b82f6', weight: 3, opacity: 0.55, interactive: false });
          s.line.addTo(opts.trailLayer);
        } else {
          s.line.setLatLngs(lls);
          if (opts.color) s.line.setStyle({ color: opts.color });
        }
      } else if (s.line) {
        try { opts.trailLayer.removeLayer(s.line); } catch (e) {}
        s.line = null;
      }
    }

    ensureLoop();
    frame();
  }

  function remove(id) {
    var s = _st[String(id)];
    if (!s) return;
    if (s.line) { try { s.line.remove(); } catch (e) {} }
    delete _st[String(id)];
  }

  w.PilotMotion = { apply: apply, remove: remove, arrowHtml: arrowHtml, LAG_MS: LAG_MS };
})(window);
