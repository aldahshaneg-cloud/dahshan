#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════
#  install.sh — تجهيز سيرفر نظام الدهشان من الصفر (أوبونتو 22.04+ (متجرّب على 24.04))
#
#  بيعمل: الحزم · MariaDB · Composer · القاعدة والمستخدم · Apache · الكرون
#  **مابيرفعش الكود** — ده بيتعمل يدويًا بعده (شوف DEPLOY.md خطوة 3).
#
#  إعادة التشغيل آمنة: كل خطوة بتتفحص قبل ما تتنفّذ.
#
#  ملحوظة على نسخة PHP: أوبونتو 24.04 بتيجي بـ8.3 في المستودع الافتراضي،
#  و22.04 بتيجي بـ8.1. السكربت بيثبّت 8.3 — لارافل 12 بيدعم 8.2 لحد 8.4.
#  لو السيرفر 22.04 هتحتاج ppa:ondrej/php الأول.
#
#  التشغيل:
#      bash install.sh
# ═══════════════════════════════════════════════════════════════════════

set -euo pipefail

APP_DIR="/var/www/dahshan"
DB_NAME="aldahshan"
DB_USER="aldahshan"

say()  { echo -e "\n\033[1;36m══ $* \033[0m"; }
ok()   { echo -e "  \033[0;32m✓\033[0m $*"; }
warn() { echo -e "  \033[0;33m⚠\033[0m $*"; }
die()  { echo -e "  \033[0;31m✗ $*\033[0m"; exit 1; }

[ "$(id -u)" -eq 0 ] || die "شغّله بـ root"

# ── 1) الحزم ──────────────────────────────────────────────────────────
say "1/7  الحزم"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq \
  apache2 mariadb-server unzip git curl certbot python3-certbot-apache \
  php8.3 php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath libapache2-mod-php8.3 >/dev/null
ok "اتثبتت — PHP $(php -r 'echo PHP_VERSION;')"

# الإضافات دي مش رفاهية: mbstring للعربي · intl و bcmath للحسابات
# gd لصور الشحنات · zip لتصدير إكسل
for ext in mbstring intl bcmath gd zip pdo_mysql curl; do
  php -m | grep -qi "^${ext}$" || die "إضافة PHP ناقصة: $ext"
done
ok "كل إضافات PHP المطلوبة موجودة"

# ── 2) Composer ───────────────────────────────────────────────────────
say "2/7  Composer"
if command -v composer >/dev/null 2>&1; then
  ok "موجود — $(composer --version 2>/dev/null | head -1)"
else
  EXPECTED=$(curl -fsSL https://composer.github.io/installer.sig)
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  ACTUAL=$(php -r "echo hash_file('sha384','/tmp/composer-setup.php');")
  [ "$EXPECTED" = "$ACTUAL" ] || die "توقيع مثبّت Composer مش مطابق — متكملش"
  php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
  ok "اتثبت — $(composer --version | head -1)"
fi

# ── 3) قاعدة البيانات ─────────────────────────────────────────────────
say "3/7  قاعدة البيانات"
if mysql -N -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB_NAME}'" | grep -q .; then
  ok "القاعدة «${DB_NAME}» موجودة — ما اتلمستش"
else
  mysql -e "CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  ok "اتعملت القاعدة «${DB_NAME}»"
fi

if mysql -N -e "SELECT User FROM mysql.user WHERE User='${DB_USER}'" | grep -q .; then
  ok "المستخدم «${DB_USER}» موجود — الباسورد ما اتغيّرش"
else
  DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 28)"
  mysql -e "CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
            GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
              ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
            FLUSH PRIVILEGES;"
  umask 077
  echo "${DB_PASS}" > /root/.dahshan_db_pass
  ok "اتعمل المستخدم «${DB_USER}»"
  warn "الباسورد اتحفظ في /root/.dahshan_db_pass — انسخه في .env وامسح الملف"
fi
# ملحوظة: صلاحيات CREATE/ALTER/DROP مطلوبة عشان `php artisan migrate` يشتغل.
# لو عايز تضيّقها بعد أول تركيب: احذفهم وسيب SELECT/INSERT/UPDATE/DELETE بس.

# ── 4) المجلدات ───────────────────────────────────────────────────────
say "4/7  المجلدات"
mkdir -p "$APP_DIR"
ok "$APP_DIR   (لارافل — ارفع الكود هنا)"

# ── 5) Apache ─────────────────────────────────────────────────────────
say "5/7  Apache"
a2enmod rewrite deflate headers expires >/dev/null 2>&1
if [ -f "$APP_DIR/ops/apache-vhost.conf" ]; then
  cp "$APP_DIR/ops/apache-vhost.conf" /etc/apache2/sites-available/dahshan.conf
  ok "الـvhost اتنسخ — **عدّل الدومين فيه** قبل التفعيل:"
  echo "      nano /etc/apache2/sites-available/dahshan.conf"
  echo "      a2ensite dahshan && a2dissite 000-default && systemctl reload apache2"
else
  warn "الكود لسه ما اترفعش — ارفعه الأول ثم شغّل السكربت تاني"
fi

# ── 6) الكرون ─────────────────────────────────────────────────────────
say "6/7  المهام الدورية"
CRON_LINE="* * * * * cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1"
if crontab -u www-data -l 2>/dev/null | grep -qF "artisan schedule:run"; then
  ok "الكرون متسجّل"
else
  ( crontab -u www-data -l 2>/dev/null; echo "$CRON_LINE" ) | crontab -u www-data -
  ok "اتسجّل — سطر واحد بيشغّل كل مهام لارافل"
fi

# ── 7) الجدار الناري ──────────────────────────────────────────────────
say "7/7  الجدار الناري"
if command -v ufw >/dev/null 2>&1; then
  ufw allow OpenSSH >/dev/null 2>&1 || true
  ufw allow 'Apache Full' >/dev/null 2>&1 || true
  ok "SSH و HTTP/HTTPS مفتوحين"
  warn "لتفعيله: ufw --force enable   (اتأكد إن SSH مفتوح الأول)"
fi

# ═══ الخلاصة ══════════════════════════════════════════════════════════
cat <<'DONE'

════════════════════════════════════════════════════════════
  السيرفر اتجهّز. الخطوات الباقية:

  1) ارفع الكود:
       /var/www/dahshan    ← مشروع لارافل (من غير vendor/ و node_modules/)

  2) جهّز لارافل:
       cd /var/www/dahshan
       composer install --no-dev --optimize-autoloader
       cp .env.production.example .env
       nano .env                      # كل اللي عليه «غيّرني»
       php artisan key:generate --force

  3) القاعدة:
       php artisan migrate --force
       php artisan install:seed --admin-password="باسورد-قوي-12-حرف-على-الأقل"

  4) الصلاحيات:
       chown -R www-data:www-data storage bootstrap/cache public/uploads
       chmod -R 775 storage bootstrap/cache

  5) التسريع:
       php artisan config:cache && php artisan route:cache && php artisan view:cache

  6) الدومين والشهادة:
       nano /etc/apache2/sites-available/dahshan.conf    # غيّر yourdomain.com
       a2ensite dahshan && a2dissite 000-default && systemctl reload apache2
       certbot --apache -d الدومين-بتاعك

  7) التحقق:
       curl -s https://الدومين-بتاعك/api/health
       # المتوقع: {"ok":true,"db":true,"version":"1.0.0"}

  ⚠️ وقبل ما تفتحه للناس:
       • ضيف الدومين في Firebase → Authentication → Authorized domains
         (من غيرها الدخول بجوجل مش هيشتغل)
       • امسح /root/.dahshan_db_pass بعد ما تنسخه في .env
════════════════════════════════════════════════════════════
DONE
