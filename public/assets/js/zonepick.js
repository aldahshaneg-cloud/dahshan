/**
 * zonepick.js — بحث ذكي للمناطق بدل القايمة المنسدلة
 * (callcenter.html · branch.html · store.html · customer.html · tiar.html)
 *
 * ═══ الفكرة اللي المكوّن كله مبني عليها ═══
 * الـ<select> الأصلي **مابيتشالش** — بيتخفي وبيفضل هو مصدر الحقيقة.
 * المكوّن بيرسم فوقه خانة بحث، ولما المستخدم يختار بيكتب القيمة في
 * الـ<select> ويبعت حدث `change`.
 *
 * ليه كده؟ عشان كل الكود القديم يفضل شغّال زي ما هو من غير ما نلمسه:
 *   • دوال التعبئة (fillZoneSelect · fillZones · fillPickupZones) بتفضل
 *     بتحط الـ<option>s في نفس المكان — والمكوّن بيقراهم منه.
 *   • فلاتر المناطق (صفوف البيت · مناطق الفرع · منع التكرار) مكانها
 *     مااتغيّرش، فالمكوّن بيرث الفلترة الصح تلقائيًا.
 *   • `sel.value` و `sel.selectedIndex` و `options[i].dataset.price`
 *     و onchange — كلهم بيشتغلوا زي الأول بالظبط.
 * يعني التغيير ده **واجهة بس**، صفر منطق أعمال.
 *
 * ═══ الشكل ═══
 * الخانة بتنسخ ستايل الـ<select> اللي حلّت مكانه (خط · لون · إطار ·
 * حواف · padding)، فبتطلع شبه باقي الخانات في كل لوحة من غير ما
 * نضيف CSS لكل لوحة على حدة.
 *
 * ═══ الربط ═══
 * أي <select> عليه `data-zonepick` بيتربط لوحده — وقت التحميل، ولو
 * اتولّد بعدين كمان (MutationObserver على الصفحة). فالخانات اللي
 * بتتعمل ديناميك (طرد جديد · مستلم جديد) مالهاش أي نداء إضافي.
 */
(function (global) {
  "use strict";

  /* ── تطبيع عربي: يوحّد الهمزات والتاء المربوطة والألف المقصورة ويشيل
        التشكيل. الموظف بيكتب بسرعة وهو على التليفون، و«المنصوره» لازم
        تلاقي «المنصورة». نفس منطق norm في لوحة الكول سنتر. ── */
  function norm(s) {
    return String(s == null ? "" : s).toLowerCase().trim()
      .replace(/[ً-ْـ]/g, "")
      .replace(/[أإآٱ]/g, "ا").replace(/ى/g, "ي").replace(/ة/g, "ه")
      .replace(/\s+/g, " ");
  }

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  /** تظليل حروف البحث جوّه الاسم */
  function hl(text, q) {
    var t = String(text || ""), nq = norm(q);
    if (!nq) return esc(t);
    var i = norm(t).indexOf(nq);
    if (i === -1) return esc(t);
    return esc(t.slice(0, i)) + "<mark class='zp-mk'>" + esc(t.slice(i, i + nq.length)) +
           "</mark>" + esc(t.slice(i + nq.length));
  }

  var STYLE_ID = "zp-style";
  function injectStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var st = document.createElement("style");
    st.id = STYLE_ID;
    st.textContent = [
      ".zp-wrap{position:relative;display:block;width:100%}",
      ".zp-in{width:100%;box-sizing:border-box}",
      ".zp-in::placeholder{opacity:.6}",
      ".zp-clear{position:absolute;inset-inline-end:8px;top:50%;transform:translateY(-50%);",
      "  width:20px;height:20px;border-radius:50%;border:none;cursor:pointer;line-height:1;",
      "  background:rgba(128,128,128,.22);color:inherit;font-size:12px;padding:0;display:none}",
      ".zp-wrap.has-val .zp-clear{display:block}",
      ".zp-pop{position:absolute;z-index:9999;inset-inline:0;top:calc(100% + 4px);max-height:260px;",
      "  overflow-y:auto;border-radius:10px;display:none;box-shadow:0 10px 30px rgba(0,0,0,.35)}",
      ".zp-wrap.open .zp-pop{display:block}",
      ".zp-it{padding:9px 12px;cursor:pointer;font-size:.86rem;line-height:1.5;",
      "  display:flex;align-items:center;justify-content:space-between;gap:10px}",
      ".zp-it+.zp-it{border-top:1px solid rgba(128,128,128,.16)}",
      ".zp-it.sel,.zp-it:hover{background:rgba(128,128,128,.16)}",
      ".zp-it .zp-p{font-size:.78rem;opacity:.75;white-space:nowrap}",
      ".zp-mk{background:transparent;color:#e8192c;font-weight:800;padding:0}",
      ".zp-empty{padding:11px 12px;font-size:.82rem;opacity:.7}",
      ".zp-more{padding:8px 12px;font-size:.75rem;opacity:.6;border-top:1px solid rgba(128,128,128,.16)}"
    ].join("\n");
    document.head.appendChild(st);
  }

  /* أقصى عدد نتايج معروضة. مش سقف على البحث — السطر اللي تحت بيقول
     إن فيه أكتر، فالمستخدم يعرف يكمّل كتابة بدل ما يفتكر إن ده الكل. */
  var MAX_SHOW = 40;

  function optionsOf(sel) {
    var out = [];
    for (var i = 0; i < sel.options.length; i++) {
      var o = sel.options[i];
      if (o.value === "" || o.disabled) continue;   // سطر «— اختر المنطقة —»
      var label = (o.textContent || "").trim();
      var price = "";
      // الاسم والسعر بيتفصلوا لو الـ<option> كاتبهم بالشكل «الاسم — 30 ج.م»
      var m = label.split(" — ");
      if (m.length > 1 && /\d/.test(m[m.length - 1])) {
        price = m.pop().trim();
        label = m.join(" — ").trim();
      }
      out.push({ value: o.value, label: label, price: price, norm: norm(label) });
    }
    return out;
  }

  function copyLook(from, to) {
    var cs = global.getComputedStyle(from);
    [ "fontFamily","fontSize","fontWeight","color","backgroundColor","backgroundImage",
      "borderTopWidth","borderRightWidth","borderBottomWidth","borderLeftWidth",
      "borderTopStyle","borderRightStyle","borderBottomStyle","borderLeftStyle",
      "borderTopColor","borderRightColor","borderBottomColor","borderLeftColor",
      "borderTopLeftRadius","borderTopRightRadius","borderBottomLeftRadius","borderBottomRightRadius",
      "paddingTop","paddingRight","paddingBottom","paddingLeft",
      "height","minHeight","lineHeight","textAlign","direction","outline","boxShadow"
    ].forEach(function (p) { try { to.style[p] = cs[p]; } catch (e) {} });
    // مساحة لزرار المسح
    var pe = parseFloat(cs.paddingInlineEnd || cs.paddingRight) || 10;
    to.style.paddingInlineEnd = (pe + 20) + "px";
  }

  function popLook(from, pop) {
    var cs = global.getComputedStyle(from);
    var bg = cs.backgroundColor;
    // خلفية شفافة مابتنفعش لقايمة عايمة — بندوّر على أول أب له لون
    if (!bg || bg === "transparent" || /rgba\(0,\s*0,\s*0,\s*0\)/.test(bg)) {
      var p = from.parentElement;
      while (p && p !== document.body) {
        var pbg = global.getComputedStyle(p).backgroundColor;
        if (pbg && pbg !== "transparent" && !/rgba\(0,\s*0,\s*0,\s*0\)/.test(pbg)) { bg = pbg; break; }
        p = p.parentElement;
      }
    }
    pop.style.background = bg || "#16161a";
    pop.style.color = cs.color;
    pop.style.border = "1px solid " + (cs.borderTopColor || "rgba(128,128,128,.3)");
  }

  var attached = [];    // كل الخانات المربوطة — بيتفحصوا دوريًا لو الـselect اتغيّر برمجيًا

  function attach(sel) {
    if (!sel || sel._zp || sel.tagName !== "SELECT") return null;
    injectStyle();

    /* 🔴 بعض اللوحات بتستبدل عنصر الـ<select> نفسه بدل ما تعبّيه
       (populateZoneSelect بتعمل cloneNode ثم replaceChild لما الفرع
       يتغيّر). النسخة الجديدة بتورث data-zonepick بس مابتورثش الربط،
       فالمراقب بينده attach عليها. لو عملنا غلاف جديد وقتها هيبقى عندنا
       غلاف جوّه غلاف وخانتين بحث. فبنمسك الغلاف الموجود ونجدّد جوّاه. */
    var wrap = null, reused = false;
    if (sel.parentElement && sel.parentElement.classList.contains("zp-wrap")) {
      wrap = sel.parentElement;
      reused = true;
      // العناصر القديمة بتتشال بمستمعيها — أنضف من فكّ الربط واحد واحد
      var old = wrap.querySelectorAll(".zp-in, .zp-clear, .zp-pop");
      for (var k = 0; k < old.length; k++) old[k].remove();
      attached = attached.filter(function (x) { return x.wrap !== wrap; });
    } else {
      wrap = document.createElement("div");
      wrap.className = "zp-wrap";
    }
    var input = document.createElement("input");
    input.type = "text";
    input.className = "zp-in";
    input.autocomplete = "off";
    input.placeholder = sel.dataset.zpPlaceholder || "اكتب اسم المنطقة…";
    var clear = document.createElement("button");
    clear.type = "button"; clear.className = "zp-clear"; clear.textContent = "✕";
    clear.setAttribute("aria-label", "مسح");
    var pop = document.createElement("div");
    pop.className = "zp-pop";

    if (!reused) {
      sel.parentNode.insertBefore(wrap, sel);
      wrap.appendChild(sel);
    }
    wrap.appendChild(input);
    wrap.appendChild(clear);
    wrap.appendChild(pop);

    copyLook(sel, input);
    popLook(sel, pop);
    // الـselect بيفضل في الصفحة (مصدر الحقيقة) بس مخفي عن العين
    sel.style.position = "absolute";
    sel.style.opacity = "0";
    sel.style.pointerEvents = "none";
    sel.style.width = "1px";
    sel.style.height = "1px";
    sel.style.padding = "0";
    sel.style.border = "0";
    sel.tabIndex = -1;

    var st = { sel: sel, input: input, wrap: wrap, pop: pop, idx: -1, lastVal: null, lastCount: -1 };
    sel._zp = st;

    function labelOf(v) {
      for (var i = 0; i < sel.options.length; i++)
        if (sel.options[i].value === v) return (sel.options[i].textContent || "").trim();
      return "";
    }

    /** يخلّي الخانة تعكس قيمة الـselect (بعد تعبئة أو ضبط برمجي) */
    function syncFromSelect() {
      var v = sel.value;
      input.value = v ? labelOf(v) : "";
      wrap.classList.toggle("has-val", !!v);
      st.lastVal = v;
      st.lastCount = sel.options.length;
      if (sel.disabled) { input.disabled = true; wrap.style.opacity = ".6"; }
      else { input.disabled = false; wrap.style.opacity = ""; }
      // الـplaceholder بياخد نص سطر «— اختر … —» لو موجود
      if (sel.options.length && sel.options[0].value === "") {
        var ph = (sel.options[0].textContent || "").replace(/^—\s*|\s*—$/g, "").trim();
        if (ph) input.placeholder = ph;
      }
    }

    function render(q) {
      var all = optionsOf(sel);
      var nq = norm(q);
      var list;
      if (!nq) {
        list = all;
      } else {
        list = [];
        for (var i = 0; i < all.length; i++) {
          var o = all[i];
          var score = o.norm === nq ? 0 : o.norm.indexOf(nq) === 0 ? 1 : o.norm.indexOf(nq) > 0 ? 2 : -1;
          if (score < 0) continue;
          list.push({ o: o, score: score });
        }
        list.sort(function (a, b) {
          return a.score - b.score || a.o.label.localeCompare(b.o.label, "ar");
        });
        list = list.map(function (x) { return x.o; });
      }

      if (!all.length) {
        pop.innerHTML = "<div class='zp-empty'>مفيش مناطق متاحة — اختار منطقة الاستلام الأول</div>";
      } else if (!list.length) {
        pop.innerHTML = "<div class='zp-empty'>مفيش منطقة بالاسم ده</div>";
      } else {
        var shown = list.slice(0, MAX_SHOW);
        pop.innerHTML = shown.map(function (o, i) {
          return "<div class='zp-it" + (i === 0 ? " sel" : "") + "' data-v='" + esc(o.value) + "'>" +
                 "<span>" + hl(o.label, q) + "</span>" +
                 (o.price ? "<span class='zp-p'>" + esc(o.price) + "</span>" : "") + "</div>";
        }).join("") +
        (list.length > shown.length
          ? "<div class='zp-more'>و" + (list.length - shown.length) + " منطقة تانية — كمّل كتابة الاسم</div>"
          : "");
      }
      st.idx = 0;
      wrap.classList.add("open");
      popLook(sel, pop);
    }

    function items() { return [].slice.call(pop.querySelectorAll(".zp-it")); }

    function mark(i) {
      var its = items();
      if (!its.length) return;
      st.idx = Math.max(0, Math.min(its.length - 1, i));
      its.forEach(function (x, j) { x.classList.toggle("sel", j === st.idx); });
      its[st.idx].scrollIntoView({ block: "nearest" });
    }

    function choose(v) {
      sel.value = v;
      syncFromSelect();
      close();
      // الحدث ده هو اللي بيشغّل كل المنطق القديم (السعر · الفرع · الملخّص)
      sel.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function close() { wrap.classList.remove("open"); }

    input.addEventListener("focus", function () { render(""); input.select(); });
    input.addEventListener("input", function () { render(input.value); });
    input.addEventListener("keydown", function (ev) {
      if (ev.key === "ArrowDown") { ev.preventDefault(); if (!wrap.classList.contains("open")) render(input.value); else mark(st.idx + 1); }
      else if (ev.key === "ArrowUp") { ev.preventDefault(); mark(st.idx - 1); }
      else if (ev.key === "Enter") {
        var its = items();
        if (wrap.classList.contains("open") && its[st.idx]) { ev.preventDefault(); choose(its[st.idx].dataset.v); }
      } else if (ev.key === "Escape") { close(); syncFromSelect(); }
    });
    input.addEventListener("blur", function () {
      // بنأجّل عشان ضغطة الفأرة على عنصر القايمة تعدّي الأول
      setTimeout(function () { if (!wrap.contains(document.activeElement)) { close(); syncFromSelect(); } }, 140);
    });
    pop.addEventListener("mousedown", function (ev) {
      var it = ev.target.closest(".zp-it");
      if (!it) return;
      ev.preventDefault();
      choose(it.dataset.v);
    });
    clear.addEventListener("click", function () {
      sel.value = "";
      syncFromSelect();
      sel.dispatchEvent(new Event("change", { bubbles: true }));
      input.focus();
    });
    sel.addEventListener("change", function () { if (sel.value !== st.lastVal) syncFromSelect(); });

    syncFromSelect();
    attached.push(st);
    return st;
  }

  /* الـ<select> بتتعبّى برمجيًا (fillZones · fillZoneSelect) والقيمة
     بتتضبط بالكود من غير حدث. المتصفح مابيبلّغش عن `sel.value = x`،
     فبنقارن دوريًا — مقارنة نصّين لكل خانة، أرخص من أي بديل. */
  setInterval(function () {
    for (var i = 0; i < attached.length; i++) {
      var st = attached[i];
      if (!st.sel.isConnected) continue;
      if (st.sel.value !== st.lastVal || st.sel.options.length !== st.lastCount) {
        if (document.activeElement !== st.input) {
          st.lastVal = st.sel.value; st.lastCount = st.sel.options.length;
          st.sel._zp && st.sel.dispatchEvent(new Event("zp:resync"));
        }
      }
    }
  }, 400);

  // resync بيتعمل عن طريق نفس دالة المزامنة جوّه attach
  document.addEventListener("zp:resync", function (ev) {
    var sel = ev.target;
    if (!sel || !sel._zp) return;
    var st = sel._zp;
    var v = sel.value, lab = "";
    for (var i = 0; i < sel.options.length; i++)
      if (sel.options[i].value === v) lab = (sel.options[i].textContent || "").trim();
    st.input.value = v ? lab : "";
    st.wrap.classList.toggle("has-val", !!v);
    st.input.disabled = !!sel.disabled;
    st.wrap.style.opacity = sel.disabled ? ".6" : "";
    if (sel.options.length && sel.options[0].value === "") {
      var ph = (sel.options[0].textContent || "").replace(/^—\s*|\s*—$/g, "").trim();
      if (ph) st.input.placeholder = ph;
    }
  }, true);

  function scan(root) {
    (root || document).querySelectorAll && (root || document)
      .querySelectorAll("select[data-zonepick]").forEach(function (s) { attach(s); });
  }

  function auto() {
    scan(document);
    // الخانات اللي بتتولّد بعدين (طرد جديد · مستلم جديد) بتتربط لوحدها
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var added = muts[i].addedNodes;
        for (var j = 0; j < added.length; j++) {
          var n = added[j];
          if (n.nodeType !== 1) continue;
          if (n.tagName === "SELECT" && n.hasAttribute("data-zonepick")) attach(n);
          else scan(n);
        }
      }
    }).observe(document.documentElement, { childList: true, subtree: true });
  }

  global.ZonePick = { attach: attach, auto: auto, scan: scan, norm: norm };

  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", auto);
  else auto();

})(window);
