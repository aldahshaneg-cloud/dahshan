/* ════════════════════════════════════════════════════════════════
   ⏱ LocFresh — «الموقع ده جديد ولا بايت؟»
   ════════════════════════════════════════════════════════════════

   ═══ اللسعة اللي الملف ده اتعمل عشانها ═══
   تطبيق الطيار بيبعت موقعه للسيرفر كل شوية. لو البعتة فشلت — نت وحش،
   السيرفر مشغول، الإذن اترفع، التطبيق اتقفل — الكود بيبلع الخطأ ويكمّل.

   النتيجة إن الخريطة **بتكدب بشكل مطمّن**: المشرف فاتحها وشايف الطيار
   في مكان، والمكان ده بقاله نص ساعة. الوقت («منذ 34 د») كان مكتوب في
   الـpopup بخط رمادي صغير — يعني مش هتشوفه غير لو دوست على الماركر
   أصلًا، ودي حاجة محدش بيعملها وهو بيبص بصة سريعة.

   والحاجة اللي بتقع لما تبني قرار على معلومة بايتة أوحش من إن الحاجة
   تقع وانت شايفها بتقع: هنا مفيش رسالة خطأ، فمفيش حد بيشك.

   الملف ده بيحوّل «عمر الموقع» من رقم مخبّي لحالة بصرية على الماركر
   نفسه، وبيتنده من لوحة الإدارة والكول سنتر والفرع — تعريف واحد بدل
   تلات نسخ بتفرق مع الوقت.

   ═══ ليه العتبات دي ═══
   التطبيق بيبعت مرة كل دقيقة كحد أقصى، **وبيسكت لو الطيار واقف مكانه**
   (تحرّك أقل من 20 متر). فالطيار المستني قدام محل بيبقى عمر موقعه
   قديم وهو تمام — عشان كده «متأخر» بتبدأ من 5 دقايق مش دقيقتين.

   ونسخة التطبيق الجديدة بتبعت نبضة كل 4 دقايق حتى وهو واقف، فالعمر
   بقى إشارة حياة حقيقية مش إشارة حركة. الطيارين اللي لسه على النسخة
   القديمة هيبانوا «متأخر» وهم واقفين — والنص المكتوب («آخر تحديث من
   …») صادق في الحالتين، عشان كده مكتوب كده مش «التطبيق واقع».
   ════════════════════════════════════════════════════════════════ */
(function (w) {
  'use strict';

  var WARN_MS = 5 * 60 * 1000;    // متأخر
  var DEAD_MS = 15 * 60 * 1000;   // بايت

  /** عمر آخر موقع بالملي ثانية — أو null لو مفيش طابع صالح */
  function age(pilot) {
    var u = pilot && pilot.location && pilot.location.updatedAt;
    if (!u) return null;
    var t = new Date(u).getTime();
    if (!isFinite(t)) return null;
    return Math.max(0, Date.now() - t);
  }

  /** 'ok' | 'warn' | 'dead' | 'none' */
  function tier(pilot) {
    var ms = age(pilot);
    if (ms === null) return 'none';
    if (ms >= DEAD_MS) return 'dead';
    if (ms >= WARN_MS) return 'warn';
    return 'ok';
  }

  /* صيغة الجمع العربي — مش «8 دقيقة».
     العربي بيفرّق: 1 مفرد · 2 مثنى · 3–10 جمع · 11+ مفرد تاني. النص
     ده بيتقرا وسط ضغط شغل، والصياغة المكسورة بتخلّي القارئ يتوقف
     ثانية على الشكل بدل ما يقرا المعنى. */
  function plural(n, one, two, few, many) {
    if (n === 1) return one;
    if (n === 2) return two;
    if (n >= 3 && n <= 10) return n + ' ' + few;
    return n + ' ' + many;
  }

  /** «من 4 د» — مختصر عشان يتحط على الماركر نفسه */
  function shortAgo(pilot) {
    var ms = age(pilot);
    if (ms === null) return '؟';
    var s = Math.floor(ms / 1000);
    if (s < 60) return s + ' ث';
    if (s < 3600) return Math.floor(s / 60) + ' د';
    if (s < 86400) return Math.floor(s / 3600) + ' س';
    return Math.floor(s / 86400) + ' ي';
  }

  /** «آخر تحديث من 4 دقايق» — للـpopup */
  function longAgo(pilot) {
    var ms = age(pilot);
    if (ms === null) return 'مفيش موقع متسجّل';
    var s = Math.floor(ms / 1000);
    if (s < 10) return 'آخر تحديث دلوقتي';
    if (s < 60) return 'آخر تحديث من ' + plural(s, 'ثانية', 'ثانيتين', 'ثواني', 'ثانية');
    var m = Math.floor(s / 60);
    if (m < 60) return 'آخر تحديث من ' + plural(m, 'دقيقة', 'دقيقتين', 'دقايق', 'دقيقة');
    var h = Math.floor(s / 3600);
    if (h < 24) return 'آخر تحديث من ' + plural(h, 'ساعة', 'ساعتين', 'ساعات', 'ساعة');
    var d = Math.floor(s / 86400);
    return 'آخر تحديث من ' + plural(d, 'يوم', 'يومين', 'أيام', 'يوم');
  }

  var STYLE = {
    ok:   { opacity: 1,    ring: '#fff',    label: null },
    warn: { opacity: 0.75, ring: '#f59e0b', label: '#b45309' },
    dead: { opacity: 0.45, ring: '#ef4444', label: '#b91c1c' },
    none: { opacity: 0.45, ring: '#94a3b8', label: '#475569' }
  };

  /**
   * الشكل اللي الماركر ياخده. الفكرة إن الفرق يبان **من غير دوس**:
   * الطازة صريح وواضح، والمتأخر باهت وله إطار برتقالي، والبايت باهت
   * جدًا وله إطار أحمر متقطّع — وفوقهم شارة مكتوب فيها العمر.
   */
  function markerStyle(pilot) {
    var t = tier(pilot);
    var s = STYLE[t];
    return {
      tier:    t,
      opacity: s.opacity,
      ring:    s.ring,
      dashed:  t === 'dead' || t === 'none',
      /* الشارة بتظهر للمتأخر وفوق بس — الطازة مالوش لازمة يزحم الخريطة */
      badge:   s.label ? { text: shortAgo(pilot), color: s.label } : null
    };
  }

  /** HTML الشارة اللي بتتحط فوق الماركر */
  function badgeHtml(st) {
    if (!st.badge) return '';
    return '<div style="position:absolute;top:-13px;left:50%;transform:translateX(-50%);' +
           'background:#fff;color:' + st.badge.color + ';border:1.5px solid ' + st.ring + ';' +
           'border-radius:10px;padding:0 5px;font-size:10px;font-weight:800;line-height:15px;' +
           'white-space:nowrap;box-shadow:0 1px 4px rgba(0,0,0,.25);direction:rtl">⚠ ' +
           st.badge.text + '</div>';
  }

  /** سطر الحالة في الـpopup — بلون، مش رمادي مخبّي */
  function popupLine(pilot) {
    var t  = tier(pilot);
    var bg = t === 'ok' ? '#f8fafc' : t === 'warn' ? '#fffbeb' : '#fef2f2';
    var fg = t === 'ok' ? '#64748b' : t === 'warn' ? '#b45309' : '#b91c1c';
    var ic = t === 'ok' ? '⏱' : '⚠️';
    var extra = (t === 'dead' || t === 'none')
      ? '<div style="font-size:.7rem;margin-top:2px;font-weight:400">النقطة دي قديمة — اتأكد من الطيار قبل ما تبني عليها</div>'
      : '';
    return '<div style="margin-top:6px;padding:4px 8px;border-radius:6px;font-size:.76rem;font-weight:700;' +
           'background:' + bg + ';color:' + fg + '">' + ic + ' ' + longAgo(pilot) + extra + '</div>';
  }

  /** عدد الطيارين اللي موقعهم متأخر أو بايت — لشريط الحالة */
  function staleCount(pilots) {
    var n = 0;
    (pilots || []).forEach(function (p) {
      if (!p || !p.pilotStatus) return;                 // مفيش وردية = مش على الخريطة
      var t = tier(p);
      if (t === 'warn' || t === 'dead' || t === 'none') n++;
    });
    return n;
  }


  /* ── إعادة تلوين الماركرات مع مرور الوقت ──────────────────────────
     الماركر بيترسم مرة واحدة وقت ما البيانات توصل. من غير الدالة دي،
     الطيار اللي كان «طازة» وقت الرسم بيفضل شكله طازة للأبد حتى لو بقاله
     ساعة ساكت — وهي دي المشكلة الأصلية بالظبط، بس على مستوى الرسم.

     بتتنده كل ثانية، بس مابتلمسش الـDOM غير لما **الدرجة تتغيّر**
     (طازة ⟵ متأخر ⟵ بايت). إعادة بناء 20 أيقونة كل ثانية بتعمل
     رفرفة في الخريطة ومابتضيفش حاجة.

     @param snap      {id: pilot}
     @param markers   {id: L.Marker}
     @param makeIcon  (pilot) => L.divIcon
     @returns عدد الماركرات اللي اتغيّرت */
  var _lastTier = {};
  function refreshMarkers(snap, markers, makeIcon) {
    var changed = 0;
    Object.keys(markers || {}).forEach(function (id) {
      var v = snap && snap[id];
      if (!v) { delete _lastTier[id]; return; }
      var p = Object.assign({ id: id }, v);
      var t = tier(p);
      if (_lastTier[id] === t) return;
      _lastTier[id] = t;
      try { markers[id].setIcon(makeIcon(p)); changed++; } catch (e) { /* الماركر اتشال */ }
    });

    return changed;
  }

  /** شارة شريط الحالة — أو نص فاضي لو كله تمام */
  function statusChip(pilots) {
    var n = staleCount(pilots);
    if (!n) return '';
    return '<span title="الطيار بيبعت موقعه من التطبيق. الرقم ده معناه إن فيه' +
           ' نقط على الخريطة قديمة — يا إما الطيار واقف من زمان، يا إما التطبيق' +
           ' مش عارف يبعت. دوس على الماركر تشوف آخر تحديث." ' +
           'style="background:#fffbeb;color:#b45309;padding:3px 10px;border-radius:20px;' +
           'font-size:.8rem;font-weight:700;cursor:help">⚠️ ' + n + ' موقعهم متأخر</span>';
  }

  w.LocFresh = {
    WARN_MS: WARN_MS,
    DEAD_MS: DEAD_MS,
    age: age,
    tier: tier,
    shortAgo: shortAgo,
    longAgo: longAgo,
    markerStyle: markerStyle,
    badgeHtml: badgeHtml,
    popupLine: popupLine,
    staleCount: staleCount,
    refreshMarkers: refreshMarkers,
    statusChip: statusChip
  };
})(window);
