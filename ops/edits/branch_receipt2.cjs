/* تكملة branch_receipt.cjs — خلفية بيضا صريحة لنافذة الريسيت.
 *
 * المشكلة اتشافت في المعاينة الحيّة: ستايل الريسيت بيحدد color:#000 من
 * غير background. المتصفح اللي على الوضع الغامق بيرندر النافذة سودا
 * قبل الطباعة → نص أسود على أسود، والموظف بيفتكرها فاضية. الورق مش
 * متأثر، بس المعاينة هي اللي الموظف بيتأكد منها قبل ما يطبع.
 */
const fs = require('fs');
const path = require('path');
const FILE = path.resolve(__dirname, '../../public/branch.html');
const raw = fs.readFileSync(FILE, 'utf8');

const OLD = 'body{font-family:Tahoma,Arial,sans-serif;color:#000;margin:0 auto;padding:8px;max-width:300px;font-size:13px}';
const NEU = 'html{background:#fff}body{background:#fff;font-family:Tahoma,Arial,sans-serif;color:#000;margin:0 auto;padding:8px;max-width:300px;font-size:13px}';

const n = raw.split(OLD).length - 1;
if (n !== 1) { console.log('✗ متوقّع ١ لقى ' + n + ' — مافيش بايت اتكتب.'); process.exit(1); }
fs.writeFileSync(FILE, raw.split(OLD).join(NEU));
console.log('✓ الخلفية البيضا اتضافت لستايل الريسيت');
