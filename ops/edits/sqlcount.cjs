/* بيعدّ الأعمدة والعلامات في كل INSERT INTO orders — عدد مش متساوي
   بيعدّي من `php -l` عادي ويقع وقت التشغيل بس. */
const fs = require('fs');
const src = fs.readFileSync('app/Http/Controllers/Api/OrdersController.php', 'utf8');
let i = -1, bad = 0, n = 0;
while ((i = src.indexOf("'INSERT INTO orders", i + 1)) !== -1) {
  n++;
  const vAt = src.indexOf('VALUES', i);
  const end = src.indexOf("',", vAt);
  const sql = src.slice(i, end);
  const colPart = sql.slice(sql.indexOf('(') + 1, sql.lastIndexOf(')', sql.indexOf('VALUES')));
  const cols = colPart.split(',').map(s => s.trim()).filter(Boolean);
  const ph = (sql.slice(vAt - i).match(/\?/g) || []).length;
  const which = sql.includes('split_from_id') ? 'التفريق' : 'إنشاء أوردر';
  const okCnt = cols.length === ph;
  const needWallet = which === 'التفريق';
  const hasWallet = cols.includes('wallet_used');
  console.log((okCnt && (!needWallet || hasWallet) ? '✓ ' : '✗ ') + which +
              ' — أعمدة: ' + cols.length + ' · علامات: ' + ph +
              (needWallet ? (hasWallet ? ' · wallet_used ✓' : ' · wallet_used ✗') : ''));
  if (!okCnt || (needWallet && !hasWallet)) bad++;
}
console.log('عدد الـINSERT: ' + n);
process.exit(bad ? 1 : 0);
