# نظام الدهشان — Laravel

منظومة إدارة توصيل: لوحة إدارة · كول سنتر · فروع · بوابة محلات · تطبيق عميل ·
تطبيق طيار (Flutter) · وحدة حسابات «روح دمشق» · منظومة ثقة.

---

## المشروع ده إيه بالظبط

ترحيل للنظام اللي كان مكتوب PHP يدوي (`../aldahshan`) إلى Laravel 12،
**بصفر تغيير في السلوك**. نفس قاعدة البيانات، نفس عقد الـAPI، نفس الواجهات.

النظام القديم **لسه موجود وشغّال** جنبه على نفس القاعدة — للمقارنة وللرجوع.

| | |
|---|---|
| مسارات الـAPI | **219** (مطابقة 100% للنظام القديم) |
| جداول قاعدة البيانات | **82** |
| لوحات الواجهة | **11** |
| كود الكنترولرات | ~14 ألف سطر |

---

## التشغيل محليًا

```bash
composer install
cp .env.example .env          # أو .env.production.example للإنتاج
php artisan key:generate
php artisan serve
```

القاعدة اسمها `aldahshan` وموجودة بالفعل. لو بتبني من الصفر:
```bash
php artisan migrate           # بيحمّل database/schema/mysql-schema.sql
```

افتح `http://127.0.0.1:8000` → بوابة الدخول.

---

## بوابات القبول — شغّلها قبل أي تسليم

**دي مش اختبارات وحدة — دي مقارنة بالنظام القديم نفسه.**

```bash
# القراءة: النظامين جنب بعض على نفس القاعدة، مقارنة رد بـرد حرف بحرف
powershell -File tests\parity.ps1

# التغطية مقابل جدول المسارات الأصلي (مش قايمة يدوية)
php artisan route:coverage

# السكيمة: بيحمّل ملف السكيمة في قاعدة مؤقتة ويقارن SHOW CREATE TABLE لكل جدول
php artisan schema:verify

# القاموس: بيحمّل دوال النظام القديم نفسها ويقارن ناتجها دالة بدالة
php tests\diff_vocab.php

# طبقة السلك: على بيانات حقيقية، وعلى حالات حدّية مصنوعة
php artisan wire:verify
php artisan wire:edge
```

**والكتابة** — اختبارات النظام القديم نفسها بتتنفّذ على لارافل:

```bash
cd ..\aldahshan
$env:ALDAHSHAN_TEST_TARGET = 'laravel'
powershell -File tests\e2e_phase1.ps1        # دورة حياة الشحنة (27 خطوة)
powershell -File tests\e2e_phase2_pilot.ps1  # تطبيق الطيار (31)
powershell -File tests\e2e_damascus.ps1      # حسابات روح دمشق (39)
powershell -File tests\e2e_trust.ps1         # الخصوصية العدائي (117)
```

> شيل المتغير بعد ما تخلص عشان الاختبارات ترجع تشتغل على النظام القديم.

---

## القواعد اللي ممنوع تتكسر

اقرا [`PORT.md`](PORT.md) الأول — فيه القرارات والأدلة كلها. الأهم:

1. **عقد الـAPI مجمّد.** نفس المفاتيح، نفس ترتيبها، نفس نصوص الأخطاء
   العربية حرفيًا. أي فرق = انحدار حتى لو «أنضف».
2. **`ApiResponse::JSON_FLAGS`** لازم تفضل `UNESCAPED_UNICODE|UNESCAPED_SLASHES`.
   فحص الخصوصية العدائي بيمشّط **نص الرد الخام** على أسماء عربية.
3. **`ConvertEmptyStringsToNull` و`TrimStrings` مشالين عمدًا**
   (`bootstrap/app.php`). النظام بيفرّق بين `""` و«مش موجود».
4. **اسم كوكي الجلسة `ALDAHSHAN_SESS`** — تطبيق الطيار المنشور بيعتمد عليه.
5. **التوقيت UTC** في التخزين، و**يوم القاهرة** في العدّادات والحضور.
   شوف `app/Support/OrderNumber.php` للسبب.
6. **الاستعلامات خام مش Eloquent** — أسماء الأعمدة المشتقة من الـjoin جزء
   من عقد طبقة السلك.

---

## الهيكل

```
app/
  Http/Controllers/Api/   الكنترولرات — واحد لكل مجال
  Http/Middleware/        ResolveApiActor (المصادقة التلاتية) · EnsureRole · TolerantJsonBody
  Wire/                   طبقة السلك — تحويل صفوف القاعدة للسان الواجهات
  Support/                Vocab · WireTime · OrderNumber · Commission · Actor · ApiResponse
  Console/Commands/       أدوات التحقق + مهمة الحضور الدورية
  Exceptions/ApiException.php
database/schema/          mysql-schema.sql — السكيمة الكاملة (مأخوذة من القاعدة الحية)
public/                   اللوحات الـ11 + الأصول
ops/                      DEPLOY.md · apache-vhost.conf · backup.sh
tests/                    parity.ps1 · diff_vocab.php
```

---

## النشر

[`ops/DEPLOY.md`](ops/DEPLOY.md) — دليل كامل للـVPS، وفيه خطة رجوع للنظام
القديم لأن الاتنين على نفس القاعدة.

---

## اللي لسه ما اتعملش

مكتوب بالتفصيل في آخر [`PORT.md`](PORT.md). الأهم:

- **مراجعة بشرية للـ52 مسار المالي** — عدّت الاختبارات، بس ده مش بديل
- **تحويل الواجهات من الاستطلاع لـReverb** — البنية جاهزة، التحويل لأ
- **تطبيق الطيار مااتوجّهش على لارافل** (الاختبار عدّى، التطبيق نفسه لأ)
- **HR والمحاسبة** — مؤجلين بقرار صاحب المشروع
