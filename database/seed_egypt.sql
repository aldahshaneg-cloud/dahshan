-- =====================================================================
-- seed_egypt.sql — المحافظات والمدن الحقيقية
-- مستخرجة حرفيًا من docs/tiar_branch.html (window.EG_GOVERNORATES / window.EG_CITIES)
-- إعادة التطبيق آمنة: INSERT IGNORE على مفاتيح فريدة
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO egypt_governorates (name) VALUES
  ('القاهرة'),
  ('الجيزة'),
  ('الإسكندرية'),
  ('الدقهلية'),
  ('البحيرة'),
  ('الفيوم'),
  ('الغربية'),
  ('الإسماعيلية'),
  ('المنوفية'),
  ('المنيا'),
  ('القليوبية'),
  ('الوادي الجديد'),
  ('السويس'),
  ('أسوان'),
  ('أسيوط'),
  ('بني سويف'),
  ('بورسعيد'),
  ('دمياط'),
  ('الشرقية'),
  ('جنوب سيناء'),
  ('شمال سيناء'),
  ('الأقصر'),
  ('قنا'),
  ('كفر الشيخ'),
  ('مطروح'),
  ('سوهاج'),
  ('البحر الأحمر');

-- القاهرة (30)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'مدينة نصر' AS name UNION ALL SELECT 'مصر الجديدة' AS name UNION ALL SELECT 'المعادي' AS name UNION ALL SELECT 'المقطم' AS name UNION ALL SELECT 'حلوان' AS name UNION ALL SELECT 'الزمالك' AS name UNION ALL SELECT 'وسط البلد' AS name UNION ALL SELECT 'شبرا' AS name UNION ALL SELECT 'عين شمس' AS name UNION ALL SELECT 'المطرية' AS name UNION ALL SELECT 'حدائق القبة' AS name UNION ALL SELECT 'الزيتون' AS name UNION ALL SELECT 'السيدة زينب' AS name UNION ALL SELECT 'مصر القديمة' AS name UNION ALL SELECT 'الخليفة' AS name UNION ALL SELECT 'الوايلي' AS name UNION ALL SELECT 'الساحل' AS name UNION ALL SELECT 'روض الفرج' AS name UNION ALL SELECT 'بولاق' AS name UNION ALL SELECT 'التجمع الخامس' AS name UNION ALL SELECT 'الرحاب' AS name UNION ALL SELECT 'مدينتي' AS name UNION ALL SELECT 'العبور' AS name UNION ALL SELECT 'الشروق' AS name UNION ALL SELECT 'بدر' AS name UNION ALL SELECT '15 مايو' AS name UNION ALL SELECT 'المرج' AS name UNION ALL SELECT 'السلام' AS name UNION ALL SELECT 'النزهة' AS name UNION ALL SELECT 'الأميرية') c
WHERE g.name = 'القاهرة';

-- الجيزة (22)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'الدقي' AS name UNION ALL SELECT 'المهندسين' AS name UNION ALL SELECT 'العجوزة' AS name UNION ALL SELECT 'إمبابة' AS name UNION ALL SELECT 'بولاق الدكرور' AS name UNION ALL SELECT 'الهرم' AS name UNION ALL SELECT 'فيصل' AS name UNION ALL SELECT '6 أكتوبر' AS name UNION ALL SELECT 'الشيخ زايد' AS name UNION ALL SELECT 'حدائق الأهرام' AS name UNION ALL SELECT 'المنيب' AS name UNION ALL SELECT 'الوراق' AS name UNION ALL SELECT 'أوسيم' AS name UNION ALL SELECT 'كرداسة' AS name UNION ALL SELECT 'البدرشين' AS name UNION ALL SELECT 'الصف' AS name UNION ALL SELECT 'أطفيح' AS name UNION ALL SELECT 'العياط' AS name UNION ALL SELECT 'الحوامدية' AS name UNION ALL SELECT 'منشأة القناطر' AS name UNION ALL SELECT 'أبو النمرس' AS name UNION ALL SELECT 'الباويطي') c
WHERE g.name = 'الجيزة';

-- الإسكندرية (20)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'المنتزه' AS name UNION ALL SELECT 'شرق' AS name UNION ALL SELECT 'وسط' AS name UNION ALL SELECT 'غرب' AS name UNION ALL SELECT 'الجمرك' AS name UNION ALL SELECT 'العامرية' AS name UNION ALL SELECT 'برج العرب' AS name UNION ALL SELECT 'سيدي جابر' AS name UNION ALL SELECT 'سموحة' AS name UNION ALL SELECT 'العصافرة' AS name UNION ALL SELECT 'المندرة' AS name UNION ALL SELECT 'ميامي' AS name UNION ALL SELECT 'لوران' AS name UNION ALL SELECT 'كامب شيزار' AS name UNION ALL SELECT 'محرم بك' AS name UNION ALL SELECT 'كرموز' AS name UNION ALL SELECT 'الدخيلة' AS name UNION ALL SELECT 'العجمي' AS name UNION ALL SELECT 'أبو قير' AS name UNION ALL SELECT 'المعمورة') c
WHERE g.name = 'الإسكندرية';

-- الدقهلية (19)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'المنصورة' AS name UNION ALL SELECT 'طلخا' AS name UNION ALL SELECT 'ميت غمر' AS name UNION ALL SELECT 'دكرنس' AS name UNION ALL SELECT 'أجا' AS name UNION ALL SELECT 'منية النصر' AS name UNION ALL SELECT 'السنبلاوين' AS name UNION ALL SELECT 'الكردي' AS name UNION ALL SELECT 'بني عبيد' AS name UNION ALL SELECT 'المنزلة' AS name UNION ALL SELECT 'تمي الأمديد' AS name UNION ALL SELECT 'الجمالية' AS name UNION ALL SELECT 'شربين' AS name UNION ALL SELECT 'المطرية' AS name UNION ALL SELECT 'بلقاس' AS name UNION ALL SELECT 'ميت سلسيل' AS name UNION ALL SELECT 'جمصة' AS name UNION ALL SELECT 'محلة دمنة' AS name UNION ALL SELECT 'نبروه') c
WHERE g.name = 'الدقهلية';

-- البحيرة (16)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'دمنهور' AS name UNION ALL SELECT 'كفر الدوار' AS name UNION ALL SELECT 'رشيد' AS name UNION ALL SELECT 'إدكو' AS name UNION ALL SELECT 'أبو المطامير' AS name UNION ALL SELECT 'أبو حمص' AS name UNION ALL SELECT 'الدلنجات' AS name UNION ALL SELECT 'المحمودية' AS name UNION ALL SELECT 'الرحمانية' AS name UNION ALL SELECT 'إيتاي البارود' AS name UNION ALL SELECT 'حوش عيسى' AS name UNION ALL SELECT 'شبراخيت' AS name UNION ALL SELECT 'كوم حمادة' AS name UNION ALL SELECT 'بدر' AS name UNION ALL SELECT 'وادي النطرون' AS name UNION ALL SELECT 'النوبارية الجديدة') c
WHERE g.name = 'البحيرة';

-- الفيوم (7)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'الفيوم' AS name UNION ALL SELECT 'طامية' AS name UNION ALL SELECT 'سنورس' AS name UNION ALL SELECT 'إطسا' AS name UNION ALL SELECT 'إبشواي' AS name UNION ALL SELECT 'يوسف الصديق' AS name UNION ALL SELECT 'الفيوم الجديدة') c
WHERE g.name = 'الفيوم';

-- الغربية (8)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'طنطا' AS name UNION ALL SELECT 'المحلة الكبرى' AS name UNION ALL SELECT 'كفر الزيات' AS name UNION ALL SELECT 'زفتى' AS name UNION ALL SELECT 'السنطة' AS name UNION ALL SELECT 'قطور' AS name UNION ALL SELECT 'بسيون' AS name UNION ALL SELECT 'سمنود') c
WHERE g.name = 'الغربية';

-- الإسماعيلية (8)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'الإسماعيلية' AS name UNION ALL SELECT 'فايد' AS name UNION ALL SELECT 'القنطرة شرق' AS name UNION ALL SELECT 'القنطرة غرب' AS name UNION ALL SELECT 'التل الكبير' AS name UNION ALL SELECT 'أبو صوير' AS name UNION ALL SELECT 'القصاصين الجديدة' AS name UNION ALL SELECT 'نفيشة') c
WHERE g.name = 'الإسماعيلية';

-- المنوفية (10)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'شبين الكوم' AS name UNION ALL SELECT 'منوف' AS name UNION ALL SELECT 'سرس الليان' AS name UNION ALL SELECT 'أشمون' AS name UNION ALL SELECT 'الباجور' AS name UNION ALL SELECT 'قويسنا' AS name UNION ALL SELECT 'بركة السبع' AS name UNION ALL SELECT 'تلا' AS name UNION ALL SELECT 'الشهداء' AS name UNION ALL SELECT 'السادات') c
WHERE g.name = 'المنوفية';

-- المنيا (10)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'المنيا' AS name UNION ALL SELECT 'العدوة' AS name UNION ALL SELECT 'مغاغة' AS name UNION ALL SELECT 'بني مزار' AS name UNION ALL SELECT 'مطاي' AS name UNION ALL SELECT 'سمالوط' AS name UNION ALL SELECT 'المنيا الجديدة' AS name UNION ALL SELECT 'أبو قرقاص' AS name UNION ALL SELECT 'ملوي' AS name UNION ALL SELECT 'دير مواس') c
WHERE g.name = 'المنيا';

-- القليوبية (11)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'بنها' AS name UNION ALL SELECT 'قليوب' AS name UNION ALL SELECT 'شبرا الخيمة' AS name UNION ALL SELECT 'القناطر الخيرية' AS name UNION ALL SELECT 'الخانكة' AS name UNION ALL SELECT 'كفر شكر' AS name UNION ALL SELECT 'طوخ' AS name UNION ALL SELECT 'قها' AS name UNION ALL SELECT 'العبور' AS name UNION ALL SELECT 'الخصوص' AS name UNION ALL SELECT 'شبين القناطر') c
WHERE g.name = 'القليوبية';

-- الوادي الجديد (5)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'الخارجة' AS name UNION ALL SELECT 'الداخلة' AS name UNION ALL SELECT 'الفرافرة' AS name UNION ALL SELECT 'باريس' AS name UNION ALL SELECT 'بلاط') c
WHERE g.name = 'الوادي الجديد';

-- السويس (5)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'السويس' AS name UNION ALL SELECT 'الأربعين' AS name UNION ALL SELECT 'عتاقة' AS name UNION ALL SELECT 'الجناين' AS name UNION ALL SELECT 'فيصل') c
WHERE g.name = 'السويس';

-- أسوان (11)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'أسوان' AS name UNION ALL SELECT 'أسوان الجديدة' AS name UNION ALL SELECT 'دراو' AS name UNION ALL SELECT 'كوم أمبو' AS name UNION ALL SELECT 'نصر النوبة' AS name UNION ALL SELECT 'كلابشة' AS name UNION ALL SELECT 'إدفو' AS name UNION ALL SELECT 'الرديسية' AS name UNION ALL SELECT 'البصيلية' AS name UNION ALL SELECT 'السباعية' AS name UNION ALL SELECT 'أبو سمبل') c
WHERE g.name = 'أسوان';

-- أسيوط (11)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'أسيوط' AS name UNION ALL SELECT 'أسيوط الجديدة' AS name UNION ALL SELECT 'ديروط' AS name UNION ALL SELECT 'منفلوط' AS name UNION ALL SELECT 'القوصية' AS name UNION ALL SELECT 'أبنوب' AS name UNION ALL SELECT 'أبو تيج' AS name UNION ALL SELECT 'الغنايم' AS name UNION ALL SELECT 'ساحل سليم' AS name UNION ALL SELECT 'البداري' AS name UNION ALL SELECT 'صدفا') c
WHERE g.name = 'أسيوط';

-- بني سويف (8)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'بني سويف' AS name UNION ALL SELECT 'بني سويف الجديدة' AS name UNION ALL SELECT 'الواسطى' AS name UNION ALL SELECT 'ناصر' AS name UNION ALL SELECT 'إهناسيا' AS name UNION ALL SELECT 'ببا' AS name UNION ALL SELECT 'سمسطا' AS name UNION ALL SELECT 'الفشن') c
WHERE g.name = 'بني سويف';

-- بورسعيد (8)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'بورسعيد' AS name UNION ALL SELECT 'بورفؤاد' AS name UNION ALL SELECT 'العرب' AS name UNION ALL SELECT 'حي الزهور' AS name UNION ALL SELECT 'حي الضواحي' AS name UNION ALL SELECT 'حي المناخ' AS name UNION ALL SELECT 'حي الشرق' AS name UNION ALL SELECT 'حي الجنوب') c
WHERE g.name = 'بورسعيد';

-- دمياط (10)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'دمياط' AS name UNION ALL SELECT 'دمياط الجديدة' AS name UNION ALL SELECT 'رأس البر' AS name UNION ALL SELECT 'فارسكور' AS name UNION ALL SELECT 'الزرقا' AS name UNION ALL SELECT 'السرو' AS name UNION ALL SELECT 'الروضة' AS name UNION ALL SELECT 'كفر البطيخ' AS name UNION ALL SELECT 'عزبة البرج' AS name UNION ALL SELECT 'كفر سعد') c
WHERE g.name = 'دمياط';

-- الشرقية (19)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'الزقازيق' AS name UNION ALL SELECT 'العاشر من رمضان' AS name UNION ALL SELECT 'منيا القمح' AS name UNION ALL SELECT 'بلبيس' AS name UNION ALL SELECT 'مشتول السوق' AS name UNION ALL SELECT 'القنايات' AS name UNION ALL SELECT 'أبو حماد' AS name UNION ALL SELECT 'القرين' AS name UNION ALL SELECT 'ههيا' AS name UNION ALL SELECT 'أبو كبير' AS name UNION ALL SELECT 'فاقوس' AS name UNION ALL SELECT 'الصالحية الجديدة' AS name UNION ALL SELECT 'الإبراهيمية' AS name UNION ALL SELECT 'ديرب نجم' AS name UNION ALL SELECT 'كفر صقر' AS name UNION ALL SELECT 'أولاد صقر' AS name UNION ALL SELECT 'الحسينية' AS name UNION ALL SELECT 'صان الحجر' AS name UNION ALL SELECT 'منشأة أبو عمر') c
WHERE g.name = 'الشرقية';

-- جنوب سيناء (9)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'الطور' AS name UNION ALL SELECT 'شرم الشيخ' AS name UNION ALL SELECT 'دهب' AS name UNION ALL SELECT 'نويبع' AS name UNION ALL SELECT 'طابا' AS name UNION ALL SELECT 'سانت كاترين' AS name UNION ALL SELECT 'أبو رديس' AS name UNION ALL SELECT 'أبو زنيمة' AS name UNION ALL SELECT 'رأس سدر') c
WHERE g.name = 'جنوب سيناء';

-- شمال سيناء (6)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'العريش' AS name UNION ALL SELECT 'الشيخ زويد' AS name UNION ALL SELECT 'رفح' AS name UNION ALL SELECT 'بئر العبد' AS name UNION ALL SELECT 'الحسنة' AS name UNION ALL SELECT 'نخل') c
WHERE g.name = 'شمال سيناء';

-- الأقصر (8)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'الأقصر' AS name UNION ALL SELECT 'الأقصر الجديدة' AS name UNION ALL SELECT 'إسنا' AS name UNION ALL SELECT 'أرمنت' AS name UNION ALL SELECT 'الطود' AS name UNION ALL SELECT 'الزينية' AS name UNION ALL SELECT 'البياضية' AS name UNION ALL SELECT 'القرنة') c
WHERE g.name = 'الأقصر';

-- قنا (10)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'قنا' AS name UNION ALL SELECT 'قنا الجديدة' AS name UNION ALL SELECT 'أبو تشت' AS name UNION ALL SELECT 'نجع حمادي' AS name UNION ALL SELECT 'دشنا' AS name UNION ALL SELECT 'الوقف' AS name UNION ALL SELECT 'قفط' AS name UNION ALL SELECT 'نقادة' AS name UNION ALL SELECT 'فرشوط' AS name UNION ALL SELECT 'قوص') c
WHERE g.name = 'قنا';

-- كفر الشيخ (13)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'كفر الشيخ' AS name UNION ALL SELECT 'دسوق' AS name UNION ALL SELECT 'فوه' AS name UNION ALL SELECT 'مطوبس' AS name UNION ALL SELECT 'برج البرلس' AS name UNION ALL SELECT 'بلطيم' AS name UNION ALL SELECT 'مصيف بلطيم' AS name UNION ALL SELECT 'الحامول' AS name UNION ALL SELECT 'بيلا' AS name UNION ALL SELECT 'الرياض' AS name UNION ALL SELECT 'سيدي سالم' AS name UNION ALL SELECT 'قلين' AS name UNION ALL SELECT 'سيدي غازي') c
WHERE g.name = 'كفر الشيخ';

-- مطروح (8)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'مرسى مطروح' AS name UNION ALL SELECT 'الحمام' AS name UNION ALL SELECT 'العلمين' AS name UNION ALL SELECT 'الضبعة' AS name UNION ALL SELECT 'النجيلة' AS name UNION ALL SELECT 'سيدي براني' AS name UNION ALL SELECT 'السلوم' AS name UNION ALL SELECT 'سيوة') c
WHERE g.name = 'مطروح';

-- سوهاج (14)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'سوهاج' AS name UNION ALL SELECT 'سوهاج الجديدة' AS name UNION ALL SELECT 'أخميم' AS name UNION ALL SELECT 'أخميم الجديدة' AS name UNION ALL SELECT 'البلينا' AS name UNION ALL SELECT 'المراغة' AS name UNION ALL SELECT 'المنشأة' AS name UNION ALL SELECT 'دار السلام' AS name UNION ALL SELECT 'جرجا' AS name UNION ALL SELECT 'جهينة الجديدة' AS name UNION ALL SELECT 'ساقلته' AS name UNION ALL SELECT 'طما' AS name UNION ALL SELECT 'طهطا' AS name UNION ALL SELECT 'الكوثر') c
WHERE g.name = 'سوهاج';

-- البحر الأحمر (9)
INSERT IGNORE INTO egypt_cities (governorate_id, name)
SELECT g.id, c.name FROM egypt_governorates g
JOIN (SELECT 'الغردقة' AS name UNION ALL SELECT 'رأس غارب' AS name UNION ALL SELECT 'سفاجا' AS name UNION ALL SELECT 'القصير' AS name UNION ALL SELECT 'مرسى علم' AS name UNION ALL SELECT 'الشلاتين' AS name UNION ALL SELECT 'حلايب' AS name UNION ALL SELECT 'الجونة' AS name UNION ALL SELECT 'سهل حشيش') c
WHERE g.name = 'البحر الأحمر';

