# دليل النشر — نظام الدهشان على Laravel

> السيرفر المتفق عليه: **VPS أوبونتو 22.04+، 2 vCPU / 2-4GB RAM**، أقرب منطقة
> لمصر (فرانكفورت / أمستردام) + دومين.
>
> الدليل ده للنسخة الجديدة (Laravel). دليل النظام القديم في
> `../aldahshan/ops/DEPLOY.md` — سيبه، ممكن تحتاجه لو رجعت للقديم.

---

## الحالة الفعلية — نُشر بتاريخ 2026-08-19

| | |
|---|---|
| الدومين | `https://aldahshan.cloud` (+ `www`) — شهادة Let's Encrypt لحد 2026-11-17، تجديد تلقائي متجرّب |
| السيرفر | Ubuntu 24.04.4 · PHP 8.3.6 · MariaDB 10.11.14 · Apache · لارافل 12.67 |
| القاعدة | `aldahshan` — 82 جدول · 27 محافظة · 315 مدينة · مستخدم مخصّص (مش root) |
| النظام القديم | **مش مرفوع** — لارافل بس، بقرار صريح |

### 🔴 عطلتان اتكشفوا وقت النشر واتصلحوا

**1) عمود محسوب `STORED` بيمنع بناء القاعدة على MariaDB 10.11**

`party_ratings.rater_uid` كان `GENERATED ALWAYS AS (...) STORED` وفي نفس الوقت
`rater_user_id` و`rater_customer_id` عليهم `FOREIGN KEY ... ON DELETE SET NULL`.
MariaDB **10.11 بترفض التركيبة دي** (خطأ 1901) — الترحيل بيقف عند الجدول رقم 50
من 82. 10.4 (المحلي) بتسمح بيها بصمت.

ومش مشكلة نقل بس: مع `STORED`، لو اتمسح مستخدم كان `rater_user_id` يبقى NULL
والعمود المخزّن **يفضل بقيمته القديمة** — يعني المفتاح الفريد
`uq_party_ratings_once` (اللي بيمنع التقييم المكرر) بيشتغل على قيمة كاذبة.

**الإصلاح:** `VIRTUAL` بدل `STORED`. بيتحسب وقت القراءة فبيتحدّث لوحده،
والـFK فضل زي ما هو فالحذف لسه شغّال. متجرّب على 10.4 و10.11 بنفس النتيجة.
العمود ده **مابيتقراش من الكود خالص** (موجود للفهرس بس) — صفر أثر على العقد.

**2) كل طلب غير مصرّح كان بيكتب ~20 كيلوبايت في السجل**

`ApiException` مسار تحكّم عادي (401/403/404/409) لكن لارافل كان بيسجّلها كـERROR
بمكدس كامل. أي ماسح بورتات بيملّي القرص ويدفن الأعطال الحقيقية.

**الإصلاح:** `dontReportWhen` في `bootstrap/app.php` بيكتم الأقل من 500 بس.
الحد ده مقصود: **46 موضع بيرمي ApiException بحالة 500 وواحد بـ503**، وكلهم
أعطال فعلية في عمليات بتمسّ فلوس — دول لازم يفضلوا في السجل.

### الأدلة

| البوابة | النتيجة |
|---|---|
| `schema:verify` | 82 جدول · صفر اختلاف |
| `tests/parity.ps1` | 241 مطابق / 0 مختلف |
| `e2e_trust.ps1` (على لارافل) | 117 PASS / 0 FAIL |
| نسخة احتياطية + استرجاع تجريبي | العربي مطابق **بايت ببايت** (27 محافظة + 315 مدينة) |
| السجل بعد 13 طلب غير مصرّح | صفر بايت |

### اللي لسه محتاج إيد بني آدم

1. **حساب الإدارة لسه ما اتعملش** — `users` فاضي:
   `php artisan install:seed --admin-password="…"` (12 حرف على الأقل)
2. **ضيف `aldahshan.cloud` في Firebase → Authentication → Authorized domains**
   من غيرها الدخول بجوجل مش هيشتغل.
3. **الجدار الناري مجهّز ومتوقف** — `ufw --force enable` (اتأكد إن SSH مفتوح).
4. **النسخة الاحتياطية لسه على نفس السيرفر** — اظبط `RCLONE_REMOTE` في
   `ops/backup.sh` عشان تسيب الجهاز. نسخة على نفس القرص مش نسخة احتياطية.
5. **`/root/.dbpass` لسه موجود** — نفس الباسورد في `.env`، امسحه لو مش محتاجه.
---

## قبل ما تبدأ — قايمة تحقق

- [ ] **باسورد الأدمن اتغيّر** (لسه على الافتراضي — شوف `../aldahshan/STATE.md`)
- [ ] الدومين متظبّط وبيشاور على IP السيرفر
- [ ] 🔴 **الدومين اتضاف في Firebase** — `Firebase Console → Authentication →
      Settings → Authorized domains → Add domain`

      من غير الخطوة دي **الدخول بحساب جوجل مش هيشتغل** — لا في لارافل ولا في
      النظام القديم. Firebase بيسمح بنافذة الدخول من دومينات مسجّلة عنده بس،
      والقايمة الافتراضية فيها `localhost` وبس.

      العرض لما تكون ناقصة: رسالة «الدومين ده مش مصرّح في Firebase» في شاشة
      الدخول (`index.html` بيمسك الخطأ `auth/unauthorized-domain` صراحةً).

      ⚠️ ولاحظ إن **`127.0.0.1` و`localhost` دومينين مختلفين** عند Firebase —
      لو بتجرب محليًا لازم تفتح على `localhost` مش على الـIP.
- [ ] عندك نسخة احتياطية من قاعدة البيانات الحالية
- [ ] قريت قسم «اللي لسه ما اتعملش» في `../PORT.md`

---

## 1) تجهيز السيرفر (مرة واحدة)

```bash
apt update && apt upgrade -y
apt install -y apache2 mariadb-server unzip git certbot python3-certbot-apache \
               php8.3 php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml \
               php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath \
               libapache2-mod-php8.3
mysql_secure_installation
```

**ليه الإضافات دي بالذات:** `mbstring` (النصوص العربية) · `intl` و`bcmath`
(الحسابات) · `gd` (صور الشحنات) · `zip` (تصدير إكسل) · `pdo_mysql`.

### Composer

```bash
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
```

---

## 2) قاعدة البيانات

```bash
mysql -u root -p -e "
CREATE DATABASE aldahshan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aldahshan'@'localhost' IDENTIFIED BY 'باسورد-قوي-هنا';
GRANT SELECT, INSERT, UPDATE, DELETE ON aldahshan.* TO 'aldahshan'@'localhost';
FLUSH PRIVILEGES;"
```

> 🔴 **مستخدم مخصّص مش root**، وبصلاحيات البيانات بس — من غير `DROP` ولا
> `ALTER`. الميجريشن بتتشغّل بحساب تاني وقت التحديث فقط.

### نقل البيانات

**لو عندك بيانات في النظام الحالي:**
```bash
# على جهازك
mysqldump -u root aldahshan | gzip > aldahshan.sql.gz
scp aldahshan.sql.gz root@SERVER:/tmp/

# على السيرفر
gunzip -c /tmp/aldahshan.sql.gz | mysql -u root aldahshan
```

**لو بتبدأ من الصفر:**
```bash
cd /var/www/dahshan
php artisan migrate --force     # بيحمّل database/schema/mysql-schema.sql (بنية بس)
php artisan install:seed --admin-password="كلمة-مرور-قوية-هنا"
```

> 🔴 **الخطوة التانية مش اختيارية.** ملف السكيمة مأخوذ بـ`--no-data` يعني
> **بنية بلا بيانات**. من غير `install:seed` هتلاقي جدول المحافظات والمدن
> **فاضي** (المفروض 27 و315)، ومفيش إعدادات، **ومفيش حساب إدارة — يعني مش
> هتقدر تدخل النظام أصلاً**.
>
> الباسورد **مش** له قيمة افتراضية عن قصد: أي قيمة معروفة معناها إن كل سيرفر
> جديد بيتولد بحساب إدارة مكشوف. والأمر بيرفض أي باسورد أقصر من 12 حرف.
>
> إعادة تشغيله آمنة — مابيلمسش أي بيانات موجودة.
>
> ✅ ملف جغرافيا مصر **متسخ جوه المشروع** (`database/seed_egypt.sql`) —
> التركيب مكتفي بنفسه ومش محتاج النظام القديم يكون مرفوع جنبه.

> السكيمة دي **متحقق منها**: `php artisan schema:verify` بيقارنها بالقاعدة
> الحية جدول بجدول = صفر اختلاف، و`migrate` على قاعدة فاضية طلّع الـ82 جدول
> مطابقين حرفيًا.

---

## 3) الكود

```bash
mkdir -p /var/www/dahshan
# ارفع محتوى D:\dahshaneg\dahshan (من غير vendor/ و node_modules/ و .env)
# بـ scp أو git clone

cd /var/www/dahshan
composer install --no-dev --optimize-autoloader --no-interaction

cp .env.production.example .env
nano .env                      # عدّل كل اللي عليه «غيّرني»
php artisan key:generate --force
```

### الصلاحيات

```bash
chown -R www-data:www-data /var/www/dahshan/storage
chown -R www-data:www-data /var/www/dahshan/bootstrap/cache
chown -R www-data:www-data /var/www/dahshan/public/uploads
chmod -R 775 /var/www/dahshan/storage /var/www/dahshan/bootstrap/cache
```

> `public/uploads` هو مكان صور الشحنات (`POST /api/upload` بيكتب فيه
> `uploads/YYYYMM/`). لو مش قابل للكتابة، رفع الصور بيفشل بصمت في اللوحات.

### جداول الجلسات والكاش والمهام

`.env` بيحط `SESSION_DRIVER=database` و`CACHE_STORE=database`
و`QUEUE_CONNECTION=database`، فمحتاجين جداولهم:

```bash
php artisan session:table
php artisan cache:table
php artisan queue:table
php artisan migrate --force
```

> ⚠️ الأوامر دي بتضيف **جداول جديدة** جنب الـ82 — مابتلمسش أي جدول قايم.
> لو مش عايز تضيفهم، سيب الثلاثة على `file` / `sync` في `.env`.

### التسريع (بعد أي تعديل على .env أو المسارات)

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> 🔴 لازم تعيدهم بعد **أي** تعديل في `.env`. من غير كده التعديل مش هيتطبّق
> وهتفضل تدوّر على السبب.

---

## 4) Apache

```bash
cp ops/apache-vhost.conf /etc/apache2/sites-available/dahshan.conf
nano /etc/apache2/sites-available/dahshan.conf    # عدّل الدومين
a2enmod rewrite deflate headers
a2ensite dahshan
a2dissite 000-default
systemctl reload apache2

certbot --apache -d yourdomain.com    # شهادة مجانية بتتجدد لوحدها
```

بعد الشهادة: ارجع لـ`.env` وتأكد إن `SESSION_SECURE_COOKIE=true`
و`APP_URL=https://...` ثم `php artisan config:cache`.

---

## 4.5) الخدمات الدائمة — Reverb والطابور

النظام بقى فيه **تلات خدمات** لازم تفضل شغّالة، مش واحدة:

| الخدمة | بتعمل إيه | لو وقعت |
|---|---|---|
| `apache2` | الموقع والـAPI | كل حاجة تقف |
| `dahshan-reverb` | سيرفر الويبسوكت (بث لحظي) | البث يقف · **الاستطلاع يفضل شغّال فالنظام مايقعش** |
| `dahshan-queue` | عامل الطابور | الأحداث تتكدّس في جدول `jobs` وتتنفّذ لما يرجع |

```bash
systemctl status dahshan-reverb dahshan-queue
journalctl -u dahshan-reverb -n 50
tail -f /var/log/dahshan-queue.log
```

### 🔴 ليه الطابور مش رفاهية

البث بيتنفّذ **جوه طلب المستخدم** لو `QUEUE_CONNECTION=sync`. اتثبت بالتجربة
(2026-08-20): وقّفنا Reverb فالبث رمى `BroadcastException` — يعني **كل عملية
أوردر كانت هتفشل** لو الويبسوكت وقع.

بـ`QUEUE_CONNECTION=database` الحدث بيتكتب في `jobs` والطلب بيرجع فورًا.
اتجرّب: Reverb متوقّف → البث رجع `OK` والمهمة استنت → رجع → اتنفّذت في 69ms.

**متحوّلش `QUEUE_CONNECTION` لـ`sync` تاني** إلا لو شيلت كل البث من الكنترولرز.

### بعد أي نشر

```bash
php artisan reverb:restart     # بيقفل الاتصالات بالراحة
systemctl restart dahshan-queue # العامل بيحمّل الكود القديم في الذاكرة
```

⚠️ **`systemctl restart dahshan-queue` مطلوب بعد أي تعديل على الكود** — العامل
بروسيس دايم بيحمّل الكلاسات مرة واحدة، فالكود الجديد مش بيوصله من غير إعادة تشغيل.

### الويبسوكت في أباتشي

`/etc/apache2/dahshan-reverb-proxy.conf` بيتضمّن في كل vhost بتاع :443.
Reverb بيسمع على `127.0.0.1:8080` بس — **مش مكشوف للنت**، أباتشي بيمرّر.
كده الويبسوكت بياخد نفس الشهادة ونفس الأصل بتاع الصفحة.

---
## 5) المهام الدورية

```bash
crontab -e -u www-data
```
```cron
* * * * * cd /var/www/dahshan && php artisan schedule:run >> /dev/null 2>&1
```

> سطر واحد بس. جدولة لارافل بتتكفّل بالباقي — إقفال جلسات الحضور المقطوعة
> (`attendance:autoclose`) وأي مهمة جاية. مش زي القديم اللي كان محتاج سطر
> لكل مهمة.

### النسخ الاحتياطي

```cron
0 4 * * * /var/www/dahshan/ops/backup.sh
```

> ⚠️ **النسخة الاحتياطية لازم تسيب السيرفر في نفس الليلة.** نسخة على نفس
> القرص مش نسخة احتياطية — أي حاجة بتودّي السيرفر بتودّيها معاه.
>
> ⚠️ **واتأكد إن الاسترجاع شغال قبل ما تحتاجه.** في النظام القديم نسختين
> (9 و11 أغسطس 2026) رجّعوا العدد الصح من الجداول والصفوف **وكل العربي
> فيهم تالف** — والفشل كان صامت تمامًا. الفحص الصح:
> ```bash
> gunzip -c backup.sql.gz | mysql -u root aldahshan_drill
> mysql -N -B -e "SELECT HEX(shop_name) FROM aldahshan.users LIMIT 1"
> mysql -N -B -e "SELECT HEX(shop_name) FROM aldahshan_drill.users LIMIT 1"
> ```
> الاتنين لازم يطلعوا **نفس الـHEX**. عدد الجداول مش دليل.

---

## 6) التحقق بعد النشر

```bash
curl -s https://yourdomain.com/api/health
# المتوقع: {"ok":true,"db":true,"version":"1.0.0"}

curl -s -o /dev/null -w "%{http_code}\n" https://yourdomain.com/          # 200
curl -s -o /dev/null -w "%{http_code}\n" https://yourdomain.com/tiar.html # 200
curl -s https://yourdomain.com/api/no_such_route
# المتوقع: {"ok":false,"error":"المسار غير موجود"}
```

ثم من المتصفح: افتح `/` → سجّل دخول → افتح كل لوحة وتأكد إنها بتحمّل بيانات.

---

## الرجوع للنظام القديم لو حصلت مشكلة

النظام القديم **مايتشالش** من السيرفر لأول أسبوعين. لو حصلت مشكلة:

```bash
a2dissite dahshan && a2ensite aldahshan && systemctl reload apache2
```

الاتنين بيستخدموا **نفس قاعدة البيانات**، فالرجوع مافيهوش فقدان بيانات —
كل اللي اتكتب على لارافل موجود للقديم والعكس.

> ده أهم سطر في الدليل ده. متشيلش القديم غير لما تعدّي أسبوعين شغل حقيقي.

---

## مشاكل شائعة

| العرض | السبب الغالب |
|---|---|
| 500 على كل حاجة | صلاحيات `storage/` — `chown -R www-data:www-data storage` |
| التعديل في `.env` مش بيتطبّق | نسيت `php artisan config:cache` |
| العربي بيطلع `\u0627\u0644...` | حد غيّر `ApiResponse::JSON_FLAGS` — لازم ترجع زي ما هي |
| تطبيق الطيار بيطلب دخول كل مرة | `SESSION_COOKIE` اتغيّر عن `ALDAHSHAN_SESS` |
| رفع الصور بيفشل | `public/uploads` مش قابل للكتابة |
| «المسار غير موجود» على مسار موجود | `route:cache` قديم — `php artisan route:clear && php artisan route:cache` |
