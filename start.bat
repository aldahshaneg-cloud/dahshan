@echo off
REM ═══════════════════════════════════════════════════════════════════
REM  تشغيل نظام الدهشان (Laravel) محليًا
REM  دبل كليك على الملف ده — أو من الطرفية: start.bat
REM ═══════════════════════════════════════════════════════════════════
chcp 65001 >nul
cd /d "%~dp0"

echo.
echo   نظام الدهشان — بيقوم...
echo.

REM MariaDB لازمة تكون شغالة (بتيجي مع XAMPP)
tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | find /I "mysqld.exe" >nul
if errorlevel 1 (
  echo   [!] MariaDB مش شغالة — شغّلها من لوحة XAMPP الأول
  echo.
  pause
  exit /b 1
)

echo   العناوين:
echo     بوابة الدخول      http://127.0.0.1:8000/
echo     لوحة الإدارة      http://127.0.0.1:8000/tiar.html
echo     الكول سنتر        http://127.0.0.1:8000/callcenter.html
echo     تطبيق الفروع      http://127.0.0.1:8000/branch.html
echo     بوابة المحلات     http://127.0.0.1:8000/store.html
echo     تطبيق العميل      http://127.0.0.1:8000/customer.html
echo     إدارة العملاء     http://127.0.0.1:8000/customers.html
echo     إدارة المحلات     http://127.0.0.1:8000/stores.html
echo     روح دمشق          http://127.0.0.1:8000/damascus.html
echo     الموقع العام      http://127.0.0.1:8000/home.html
echo     لوحة الموقع       http://127.0.0.1:8000/site-admin.html
echo.
echo   للإيقاف: Ctrl+C
echo.

start "" http://127.0.0.1:8000/
C:\xampp\php\php.exe artisan serve --host=127.0.0.1 --port=8000
