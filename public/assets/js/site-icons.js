/* ══════════════════════════════════════════════════════════════════════
   الدهشان — مكتبة الأيقونات (site-icons.js)
   نفس الـ20 أيقونة اللي في includes/icons.php وبنفس الأسماء بالظبط،
   متحوّلة لجافاسكريبت عشان الصفحات الساكنة (HTML) تقدر تستخدمها.

   الاستخدام:
     el.innerHTML = icon('package');              // كلاس افتراضي: icon
     el.innerHTML = icon('phone', 'icon icon--lg');

   أو من غير جافاسكريبت في الماركب: أي عنصر عليه data-icon بيتملى تلقائيًا
     <span class="ico" data-icon="track"></span>

   الأيقونات بتاخد لون النص (fill:currentColor من كلاس .icon في site.css)
   ومقاسها 1em — يعني بتكبر وتصغر مع font-size بتاع العنصر الحاوي.
════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  /* مسارات الأيقونات — منقولة حرفيًا من includes/icons.php (viewBox 0 0 24 24) */
  var ICON_PATHS = {
    restaurant: 'M8.1 2v7.2a2.9 2.9 0 0 1-2 2.8V22H4V12a2.9 2.9 0 0 1-2-2.8V2h2v7h1.1V2h2v7H8V2h.1ZM17 2c2.2 0 4 3.1 4 7 0 3.1-1.2 5.7-2.9 6.6V22h-2V2h.9Z',
    package:    'M12 2 3 6.5v11L12 22l9-4.5v-11L12 2Zm0 2.2 6.4 3.2L12 10.6 5.6 7.4 12 4.2ZM5 9.2l6 3v7.3l-6-3V9.2Zm8 10.3v-7.3l6-3v7.3l-6 3Z',
    store:      'M4 4h16l1.5 5.2A3.2 3.2 0 0 1 18.4 13c-.9 0-1.7-.4-2.2-1a2.9 2.9 0 0 1-4.4 0 2.9 2.9 0 0 1-4.4 0c-.5.6-1.3 1-2.2 1a3.2 3.2 0 0 1-3.1-3.8L4 4Zm1 10.6c.5.2 1 .3 1.6.3.8 0 1.6-.2 2.2-.6.7.4 1.4.6 2.2.6s1.5-.2 2.2-.6c.7.4 1.4.6 2.2.6.5 0 1.1-.1 1.6-.3V20H5v-5.4Z',
    cart:       'M7 18a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm10 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4ZM6.2 6h14.3l-2 8H8L6.2 6ZM2 2h3.3l.6 2.5h-3L2 2Z',
    briefcase:  'M9 3h6a2 2 0 0 1 2 2v2h4v12H3V7h4V5a2 2 0 0 1 2-2Zm0 4h6V5H9v2Z',
    track:      'M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7Zm0 4.5A2.5 2.5 0 1 1 12 11a2.5 2.5 0 0 1 0-4.5Z',
    clock:      'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 10.6 4 2.3-.8 1.4-5.2-3V6h2v6.6Z',
    shield:     'M12 2 4 5.4v6c0 4.7 3.4 9 8 10.6 4.6-1.6 8-5.9 8-10.6v-6L12 2Zm-1 13.4-3.5-3.5 1.4-1.4 2.1 2.1 4.6-4.6 1.4 1.4-6 6Z',
    money:      'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm.9 15.2v1.3h-1.7v-1.3c-1.5-.2-2.7-1.1-2.9-2.7h1.8c.1.8.7 1.3 1.9 1.3 1.1 0 1.7-.5 1.7-1.2 0-.6-.4-1-1.8-1.4-2-.5-3.2-1.2-3.2-2.8 0-1.5 1.1-2.4 2.5-2.6V6.5h1.7v1.3c1.5.3 2.4 1.3 2.5 2.6h-1.8c-.1-.7-.6-1.2-1.6-1.2-1 0-1.6.5-1.6 1.1 0 .6.5.9 1.9 1.3 2 .5 3.1 1.3 3.1 2.9 0 1.5-1.1 2.5-2.5 2.7Z',
    phone:      'M6.6 10.8a15.6 15.6 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.2.4 2.4.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.4c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.4 0 .8-.2 1l-2.2 2.2Z',
    check:      'm9.5 17.2-4.7-4.7 1.4-1.4 3.3 3.3 8-8 1.4 1.4-9.4 9.4Z',
    bike:       'M5.5 14a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Zm13 0a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7ZM14.8 3l1.4 2.6h2.3v1.8h-1.3l2 4.1-1.6.8-2.5-5.1H10L8.7 9.4h3.6v1.8H6.2L9 4.9h4l-.9-1.9h2.7Z',
    users:      'M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-8 1.7-8 5v3h16v-3c0-3.3-4.7-5-8-5Zm8.5-2a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm.5 2c-.7 0-1.5.1-2.2.3 1.6 1.2 2.7 2.8 2.7 4.7v3h5v-3c0-3.3-4-5-5.5-5Z',
    star:       'm12 2 3 6.6 7 .8-5.2 4.8 1.4 7L12 17.8 5.8 21.2l1.4-7L2 9.4l7-.8L12 2Z',
    home:       'M12 3 2 11h3v9h6v-6h2v6h6v-9h3L12 3Z',
    info:       'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15h-2v-6h2v6Zm0-8h-2V7h2v2Z',
    menu:       'M3 6h18v2H3V6Zm0 5h18v2H3v-2Zm0 5h18v2H3v-2Z',
    close:      'M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7l-1.4-1.4L9.2 12 2.9 5.7l1.4-1.4 6.3 6.3 6.3-6.3 1.4 1.4Z',
    app:        'M7 1h10a2 2 0 0 1 2 2v18a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2Zm0 4v14h10V5H7Zm5 15.2a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z',
    whatsapp:   'M12 2a10 10 0 0 0-8.5 15.2L2 22l4.9-1.4A10 10 0 1 0 12 2Zm5.3 14c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .1-1.7-.1a11 11 0 0 1-4.6-3.2c-1-1.2-1.6-2.5-1.5-3.4 0-.9.6-1.6 1-1.9.2-.2.5-.2.7-.2h.5c.2 0 .4 0 .5.4l.7 1.7c0 .2 0 .3-.1.5l-.4.5c-.1.2-.2.3 0 .5.4.7 1 1.3 1.6 1.8.5.4 1 .6 1.3.7.2.1.4 0 .5-.1l.7-.8c.2-.2.3-.1.5 0l1.6.8c.2.1.3.2.3.3 0 .2 0 .8-.3 1.3Z'
  };

  /* تهريب قيمة الكلاس — نفس دور e() في نسخة الـPHP */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /**
   * بترجّع نص SVG للأيقونة.
   * لو الاسم مش موجود بترجّع أيقونة package — نفس سلوك icons.php بالظبط.
   * @param {string} name اسم الأيقونة
   * @param {string} [cls='icon'] كلاس الـsvg
   * @returns {string}
   */
  function icon(name, cls) {
    var path = ICON_PATHS[name] || ICON_PATHS.package;
    return '<svg viewBox="0 0 24 24" class="' + esc(cls || 'icon') +
           '" aria-hidden="true"><path d="' + path + '"/></svg>';
  }

  /* كل الأسماء المتاحة — مفيدة للتأكد قبل الاستدعاء */
  icon.names = Object.keys(ICON_PATHS);
  icon.paths = ICON_PATHS;
  icon.has = function (name) { return Object.prototype.hasOwnProperty.call(ICON_PATHS, name); };

  /**
   * بتملى كل عنصر عليه data-icon بالأيقونة المناسبة.
   * data-icon-class اختياري لتغيير كلاس الـsvg.
   * بتشتغل لوحدها عند تحميل الصفحة، وينفع تستدعيها بعد أي محتوى جديد.
   */
  function renderIcons(rootEl) {
    var scope = rootEl || document;
    var nodes = scope.querySelectorAll('[data-icon]');
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      el.innerHTML = icon(el.getAttribute('data-icon'), el.getAttribute('data-icon-class') || 'icon');
    }
  }

  root.icon = icon;
  root.renderIcons = renderIcons;

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () { renderIcons(); });
    } else {
      renderIcons();
    }
  }
})(typeof window !== 'undefined' ? window : this);
