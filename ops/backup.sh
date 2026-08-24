#!/usr/bin/env bash
# backup.sh — نسخة احتياطية يومية بتسيب السيرفر في نفس الليلة.
#
# ليه الملف ده موجود:
#   النسخة اللي بتفضل على نفس قرص قاعدة البيانات مش نسخة احتياطية — أي حاجة
#   بتودّي السيرفر بتودّيها معاها. وكمان لازم نتأكد إن الملف اللي اتكتب فعلًا
#   قابل للاسترجاع وإن العربي جواه سليم، لأن أسوأ فشل هو اللي بيعدّي بصمت.
#
# قبل التشغيل: اظبط DEST_* تحت، وحط الباسورد في ~/.my.cnf مش هنا.

set -euo pipefail

DB="aldahshan"
DIR="/var/backups/aldahshan"
KEEP_DAYS=14
STAMP="$(date +%F_%H%M)"
FILE="$DIR/${DB}_${STAMP}.sql"

mkdir -p "$DIR"

# --result-file بيخلي mysqldump يكتب الملف بنفسه — من غير أي أنبوب يعيد
# الترميز. (نسخة الويندوز كانت بتعدّي على PowerShell Out-File فيتلف العربي)
mysqldump --defaults-file="$HOME/.my.cnf" \
          --default-character-set=utf8mb4 \
          --single-transaction --routines --triggers \
          --result-file="$FILE" "$DB"

# ── فحص 1: الحجم ──────────────────────────────────────────────────
if [ "$(stat -c%s "$FILE")" -lt 10240 ]; then
  echo "BACKUP FAILED - file too small: $FILE" >&2; exit 1
fi

# ── فحص 2: الترميز — لازم نلاقي بايتات عربية بصيغة UTF-8 ──────────
if ! grep -qP '[\xd8\xd9][\x80-\xbf]' "$FILE"; then
  echo "BACKUP FAILED - encoding check: no UTF-8 Arabic bytes in dump" >&2; exit 1
fi

# ── فحص 3: استرجاع حقيقي على قاعدة مؤقتة ومقارنة بصمة ─────────────
DRILL="${DB}_drill"
mysql --defaults-file="$HOME/.my.cnf" -e "DROP DATABASE IF EXISTS \`$DRILL\`; CREATE DATABASE \`$DRILL\` CHARACTER SET utf8mb4;"
mysql --defaults-file="$HOME/.my.cnf" --default-character-set=utf8mb4 "$DRILL" < "$FILE"
for T in users orders zones; do
  A=$(mysql --defaults-file="$HOME/.my.cnf" -N -B "$DB"    -e "CHECKSUM TABLE $T" | awk '{print $2}')
  B=$(mysql --defaults-file="$HOME/.my.cnf" -N -B "$DRILL" -e "CHECKSUM TABLE $T" | awk '{print $2}')
  if [ "$A" != "$B" ]; then
    echo "BACKUP FAILED - checksum mismatch on $T ($A vs $B)" >&2
    mysql --defaults-file="$HOME/.my.cnf" -e "DROP DATABASE IF EXISTS \`$DRILL\`;"
    exit 1
  fi
done
mysql --defaults-file="$HOME/.my.cnf" -e "DROP DATABASE IF EXISTS \`$DRILL\`;"

gzip -f "$FILE"
FILE="$FILE.gz"

# ── الرفع برّه السيرفر — ده أهم سطر في الملف ──────────────────────
# اظبط واحد على الأقل. من غيره النسخة لسه على نفس الجهاز.
#
# مثال rclone (Backblaze B2 / Google Drive / أي وجهة):
#   rclone copy "$FILE" remote:aldahshan-backups/ --quiet
#
# ومفضّل كمان وجهة تانية بنظام السحب (الجهاز اللي في المكتب هو اللي بيجيب
# الملف بـ rsync) — كده لو السيرفر اتخرق، المخترق مش هيقدر يمسح الأرشيف
# لأن السيرفر أصلًا مش شايل مفاتيح الوجهة دي.
if command -v rclone >/dev/null 2>&1 && [ -n "${RCLONE_REMOTE:-}" ]; then
  rclone copy "$FILE" "$RCLONE_REMOTE" --quiet
  echo "UPLOADED: $RCLONE_REMOTE"
else
  echo "WARNING: النسخة لسه على السيرفر بس — اظبط RCLONE_REMOTE" >&2
fi

find "$DIR" -name "${DB}_*.sql.gz" -mtime +$KEEP_DAYS -delete
echo "BACKUP OK: $FILE"
