<?php

/*
|--------------------------------------------------------------------------
| الجيل الرابع (v4) — الواجهات على Laravel/Blade
|--------------------------------------------------------------------------
| طلب صاحب النظام 2026-09-21: «كل شيء على لارافل… نبدأ بتطبيق الكول سنتر فقط، تطبيق موازي
| للموجود وتترك الموجود كما هو حتى يكتمل الجديد». النمط منقول من «الشمس — Laravel»:
| شاشة Blade لكل صفحة + ملف JS/CSS لكل موديول + القائمة من الإعدادات هنا.
|
| كل تطبيق له: الأدوار المسموحة، الرابط القديم، وصفحاته (المفتاح = اسم الـroute بعد `v4.<app>.`).
|
| 🔴 `embed` (قرار صاحب النظام 2026-09-21 بعد أول عرض): «في الإصدار السابق أمور لا أريد تغييرها مثل
|    عمل أوردر جديد وصفحة الأوردرات النشطة». الصفحة اللي عليها `embed => '<صفحة القديم>'` بتتعرض
|    **بكود التطبيق القديم نفسه** جوه غلاف v4 (callcenter.html?embed=…) — صفر تغيير في الشكل والسلوك.
|    تحويل أي صفحة من/إلى «زي القديم» = تعديل السطر ده بس. الصفحة من غير `embed` شاشة Blade أصلية.
|    `ready => false` = «قريبًا» بتفتح القديم في تبويب.
*/

return [
    'apps' => [
        'callcenter' => [
            'label'  => 'الكول سنتر',
            'icon'   => 'fa-headset',
            'roles'  => ['callcenter', 'admin'],
            'legacy' => 'callcenter.html',
            'groups' => ['main' => '', 'orders' => 'الطلبات', 'ops' => 'التشغيل', 'me' => 'متابعة'],
            'pages'  => [
                'home'        => ['label' => 'الرئيسية',          'icon' => 'fa-gauge-high',        'group' => 'main',   'ready' => true],
                /* فورم الأوردر = مودال جوه صفحة الطلبات (قرار قديم لصاحب النظام: الشاشة المستقلة اتشالت) */
                'new'         => ['label' => 'طلب جديد',          'icon' => 'fa-circle-plus',       'group' => 'main',   'ready' => true, 'embed' => 'orders', 'embedNew' => true],
                'search'      => ['label' => 'بحث سريع',          'icon' => 'fa-magnifying-glass',  'group' => 'main',   'ready' => true],
                'active'      => ['label' => 'الطلبات النشطة',    'icon' => 'fa-clipboard-list',    'group' => 'orders', 'ready' => true, 'embed' => 'orders'],
                'delivering'  => ['label' => 'قيد التوصيل',       'icon' => 'fa-motorcycle',        'group' => 'orders', 'ready' => true],
                'delivered'   => ['label' => 'المسلَّمة',          'icon' => 'fa-circle-check',      'group' => 'orders', 'ready' => true],
                'undelivered' => ['label' => 'لم يتم التوصيل',    'icon' => 'fa-rotate-left',       'group' => 'orders', 'ready' => true],
                'cancelled'   => ['label' => 'الملغاة',           'icon' => 'fa-ban',               'group' => 'orders', 'ready' => true],
                'pilots'      => ['label' => 'الطيارين',          'icon' => 'fa-people-group',      'group' => 'ops',    'ready' => true],
                'zones'       => ['label' => 'دليل المناطق',      'icon' => 'fa-map-location-dot',  'group' => 'ops',    'ready' => true],
                'clients'     => ['label' => 'العملاء',           'icon' => 'fa-address-book',      'group' => 'ops',    'ready' => true],
                'map'         => ['label' => 'خريطة الطيارين',    'icon' => 'fa-map',               'group' => 'ops',    'ready' => true, 'embed' => 'pilotmap'],
                'notifs'      => ['label' => 'رسايل العملاء',     'icon' => 'fa-comment-dots',      'group' => 'me',     'ready' => true, 'embed' => 'ccnotifs'],
                'complaints'  => ['label' => 'الشكاوى',           'icon' => 'fa-triangle-exclamation', 'group' => 'me',  'ready' => true, 'embed' => 'ccomplaints'],
                'perf'        => ['label' => 'أدائي',             'icon' => 'fa-chart-line',        'group' => 'me',     'ready' => true, 'embed' => 'ccperf'],
            ],
        ],
    ],

    /* قوايم الأوردرات: مفتاح الصفحة → حالات الـAPI (بالعربي زي السلك) */
    'order_lists' => [
        'active'      => ['title' => 'الطلبات النشطة',  'statuses' => ['قيد التنفيذ', 'مؤجل']],
        'delivering'  => ['title' => 'قيد التوصيل',     'statuses' => ['جاري التوصيل']],
        'delivered'   => ['title' => 'الطلبات المسلَّمة', 'statuses' => ['تم التسليم']],
        'undelivered' => ['title' => 'لم يتم التوصيل',  'statuses' => ['لم يتم التوصيل']],
        'cancelled'   => ['title' => 'الطلبات الملغاة',  'statuses' => ['ملغي']],
    ],
];
