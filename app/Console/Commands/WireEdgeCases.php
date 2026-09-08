<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Wire\CoreWire;
use App\Wire\OrderWire;
use Illuminate\Console\Command;
use PDO;

/**
 * فحص طبقة السلك على **حالات حدّية مصنوعة عمدًا** — في قاعدة منفصلة.
 *
 * الفحص على البيانات الحقيقية (`wire:verify`) بيغطي المألوف بس. الفروق
 * الصامتة بتتخبّى في: NULL مقابل نص فاضي، decimal فاضي، حالة مش في
 * القاموس، legacy_key من الترحيل، طرد بلا منطقة، أوردر بلا طرود، قيم
 * unicode، وأرقام على الحافة.
 *
 * ⚠️ **القاعدة الحقيقية مابتتلمسش** — كل حاجة في قاعدة مؤقتة بتتمسح بعدها.
 *
 * التشغيل: php artisan wire:edge
 */
class WireEdgeCases extends Command
{
    protected $signature = 'wire:edge {--scratch=aldahshan_wire_edge} {--keep}';

    protected $description = 'بيقارن طبقة السلك بالأصل على حالات حدّية مصنوعة';

    public function handle(): int
    {
        $legacy = dirname(base_path()) . DIRECTORY_SEPARATOR . 'aldahshan';
        if (! is_file($legacy . '/api/ser_orders.php')) {
            $this->error('مالقيتش مشروع aldahshan');
            return self::FAILURE;
        }

        date_default_timezone_set('UTC');
        require_once $legacy . '/api/constants.php';
        require_once $legacy . '/api/ser_core.php';
        require_once $legacy . '/api/ser_orders.php';

        $c    = config('database.connections.' . config('database.default'));
        $live = $c['database'];
        $db   = (string) $this->option('scratch');

        if (strcasecmp($db, $live) === 0) {
            $this->error('اسم القاعدة المؤقتة لازم يختلف عن الحقيقية');
            return self::FAILURE;
        }

        $dsn = fn (?string $d = null) => sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4%s', $c['host'], $c['port'], $d ? ";dbname={$d}" : ''
        );
        $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false];

        $root = new PDO($dsn(), $c['username'], $c['password'], $opt);
        $root->exec("DROP DATABASE IF EXISTS `{$db}`");
        $root->exec("CREATE DATABASE `{$db}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $pass = 0; $fail = 0; $failures = [];

        try {
            // السكيمة من نفس الملف اللي النشر بيستخدمه
            $sql = (string) file_get_contents(base_path('database/schema/mysql-schema.sql'));
            $pdo = new PDO($dsn($db), $c['username'], $c['password'], $opt);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (preg_split('/;\s*[\r\n]+/', $sql) as $stmt) {
                $stmt = trim((string) $stmt);
                if ($stmt !== '' && ! str_starts_with($stmt, '--')) {
                    $pdo->exec($stmt);
                }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            $this->seed($pdo);

            /* حقول اتضافت في السلك الجديد ومالهاش مقابل في الأصل — بتتشال
               من الجديد قبل المقارنة عشان الفحص يفضل بيمسك أي اختلاف تاني.
               نفس فكرة INTENTIONAL_FIELDS في wire:verify. */
            $intentional = [
                'originBranchId'   => true,   // الفرع اللي أنشأ الأوردر (2026-08-26)
                'originBranchName' => true,
                // تأكيد المحل إنه سلّم الأوردر للطيار (2026-08-29)
                'handedOverAt'     => true,
                'handedOverBy'     => true,
                // مين دفع توصيل المرتجع (2026-09-02) — شوف wire:verify
                'undeliveredFareBy' => true,
                /* فرع الطيار الثابت (2026-08-30) — الأصل كان بيستعمل
                   assigned_branch_id لمعنيين، وبيمسحه عند قفل الوردية. */
                'homeBranchId'     => true,
                'homeBranchName'   => true,
                // الأرشفة — بديل الحذف (2026-09-01). شوف VerifyWireParity للسبب الكامل.
                'archivedAt'       => true,
                'archivedBy'       => true,
                /* رواتب التقفيلة (2026-09-01) — سعر الساعة والإجازة على سلك
                   الطيار والمستخدم. شوف VerifyWireParity للسبب الكامل. */
                'hourRate'        => true,
                'paidLeaveDays'   => true,
                /* التتبّع الحي (2026-09-07) — اتجاه/سرعة آخر نقطة وأثر
                   آخر دقيقتين. أعلى مستوى عن قصد: `location` بيتقارن ككائن. */
                'heading'         => true,
                'speed'           => true,
                'trail'           => true,
                'monthlySalary'   => true,
            ];
            // وحقول الطرد الجديدة (2026-08-27)
            $intentionalDelivery = ['receiverFromReceipt' => true, 'lat' => true, 'lng' => true];

            $compare = function (string $what, mixed $old, mixed $new) use (&$pass, &$fail, &$failures, $intentional, $intentionalDelivery): void {
                if (is_array($new)) {
                    foreach (array_keys($intentional) as $f) {
                        if (! array_key_exists($f, (array) $old)) {
                            unset($new[$f]);
                        }
                    }
                    if (is_array($new['deliveries'] ?? null)) {
                        foreach ($new['deliveries'] as $i => $d) {
                            if (! is_array($d)) {
                                continue;
                            }
                            $oldD = ((array) $old)['deliveries'][$i] ?? [];
                            foreach (array_keys($intentionalDelivery) as $f) {
                                if (! is_array($oldD) || ! array_key_exists($f, $oldD)) {
                                    unset($new['deliveries'][$i][$f]);
                                }
                            }
                        }
                    }
                }
                $a = json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $b = json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($a === $b) { $pass++; return; }
                $fail++;
                $keys = array_unique(array_merge(array_keys((array) $old), array_keys((array) $new)));
                $d = [];
                foreach ($keys as $k) {
                    $ov = ((array) $old)[$k] ?? '«ناقص»';
                    $nv = ((array) $new)[$k] ?? '«ناقص»';
                    if (json_encode($ov, JSON_UNESCAPED_UNICODE) !== json_encode($nv, JSON_UNESCAPED_UNICODE)) {
                        $d[] = sprintf('       %s: أصل=%s جديد=%s', $k,
                            json_encode($ov, JSON_UNESCAPED_UNICODE), json_encode($nv, JSON_UNESCAPED_UNICODE));
                    }
                }
                $failures[] = "  ✗ {$what}\n" . implode("\n", $d);
            };

            // الكيانات
            foreach ($pdo->query('SELECT b.*, f.name AS failover_branch_name FROM branches b
                                  LEFT JOIN branches f ON f.id=b.failover_branch_id ORDER BY b.id') as $r) {
                $compare('branch#' . $r['id'], ser_branch($r), CoreWire::branch($r));
            }
            foreach ($pdo->query('SELECT * FROM pilots ORDER BY id') as $r) {
                $compare('pilot#' . $r['id'], ser_pilot($r), CoreWire::pilot($r));
            }
            foreach ($pdo->query('SELECT * FROM senders ORDER BY id') as $r) {
                $compare('sender#' . $r['id'], ser_sender($r), CoreWire::sender($r));
            }

            // الأوردرات — الكائن الكامل
            $rows = $pdo->query(ser_orders_base_sql() . ' ORDER BY o.id')->fetchAll();
            $old  = ser_orders_batch($pdo, $rows);

            // الجديد بيستخدم DB:: بتاع لارافل — بنوجّهه للقاعدة المؤقتة
            config(['database.connections.wire_edge' => array_merge($c, ['database' => $db])]);
            \Illuminate\Support\Facades\DB::purge('wire_edge');
            $prev = config('database.default');
            config(['database.default' => 'wire_edge']);
            $new = OrderWire::batch($rows);
            config(['database.default' => $prev]);

            $this->line(sprintf('  حالات حدّية: %d أوردر · %d فرع · %d طيار',
                count($rows), $pdo->query('SELECT COUNT(*) c FROM branches')->fetch()['c'],
                $pdo->query('SELECT COUNT(*) c FROM pilots')->fetch()['c']));

            if (count($old) !== count($new)) {
                $fail++;
                $failures[] = sprintf('  ✗ عدد الأوردرات: أصل=%d جديد=%d', count($old), count($new));
            } else {
                foreach ($old as $i => $o) {
                    $compare('order#' . ($rows[$i]['order_num'] ?? $i), $o, $new[$i]);
                }
            }
        } finally {
            if (! $this->option('keep')) {
                $root->exec("DROP DATABASE IF EXISTS `{$db}`");
            }
        }

        $this->newLine();
        foreach ($failures as $f) { $this->line($f); }
        $this->newLine();
        $this->line('════════════════════════════════════════════');
        $msg = sprintf('WIRE EDGE: %d مطابق / %d مختلف   (إجمالي %d)', $pass, $fail, $pass + $fail);
        $fail > 0 ? $this->error($msg) : $this->info($msg);
        $this->line('════════════════════════════════════════════');

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * بيزرع صفوف بقيم قاسية عمدًا.
     * فحص المفاتيح الأجنبية مطفّي: المقصود قيم حدّية على الأعمدة (NULL،
     * نص فاضي، حالة مجهولة، مرجع لصف مش موجود) مش رسم علاقات سليمة —
     * وطبقة السلك مابتقراش العلاقات دي أصلًا غير من الـjoin.
     */
    private function seed(PDO $p): void
    {
        $now = '2026-08-19 10:00:00';
        $p->exec('SET FOREIGN_KEY_CHECKS = 0');

        // فروع: واحد كامل، واحد كله NULL، واحد بـfailover وlegacy_key
        $p->exec("INSERT INTO branches (id,name,code,phone,manager,address,paused,created_at)
                  VALUES (1,'فرع كامل','AAA','01000000000','أ. محمد','٣ ش النيل',0,'{$now}')");
        $p->exec("INSERT INTO branches (id,name,code,phone,manager,address,paused,created_at)
                  VALUES (2,'فرع فاضي','BBB',NULL,NULL,NULL,1,'{$now}')");
        $p->exec("INSERT INTO branches (id,legacy_key,name,code,failover_branch_id,paused,created_at)
                  VALUES (3,'-NxYzKey_01','فرع مرحّل','CCC',1,0,'{$now}')");

        $p->exec("INSERT INTO zones (id,area_name,price,delivery_branch_id,created_at)
                  VALUES (1,'الزمالك',35.50,1,'{$now}')");
        $p->exec("INSERT INTO zones (id,area_name,price,delivery_branch_id,source_branch_id,created_at)
                  VALUES (2,'المعادي',0.00,1,2,'{$now}')");

        // طيارين: في إذن، فاضي، بموقع، بعمولة ثابتة
        $p->exec("INSERT INTO pilots (id,name,phone1,status,leave_type,leave_reason,leave_forced,
                                      commission_type,commission_value,custody_balance,created_at)
                  VALUES (1,'طيار في إذن','01111111111','on_leave','rest','تعبان',1,'percent',10,150.75,'{$now}')");
        // ملاحظة: leave_* المفروض تتخفي لأن الحالة مش on_leave
        $p->exec("INSERT INTO pilots (id,name,status,leave_type,leave_reason,commission_type,commission_value,created_at)
                  VALUES (2,'طيار شغال','waiting','dayoff','مش المفروض تظهر','fixed',25,'{$now}')");
        $p->exec("INSERT INTO pilots (id,name,lat,lng,location_updated_at,commission_type,commission_value,created_at)
                  VALUES (3,'طيار بموقع',30.0444,31.2357,'{$now}','',0,'{$now}')");
        $p->exec("INSERT INTO pilots (id,name,commission_type,commission_value,created_at)
                  VALUES (4,'طيار بلا حاجة','',0,'{$now}')");

        $p->exec("INSERT INTO senders (id,name,phone1,phone2,address,created_by,source,created_at)
                  VALUES (1,'مُرسِل كامل','01000000001','01000000002','عنوان','admin','branch','{$now}')");
        $p->exec("INSERT INTO senders (id,legacy_key,name,phone1,created_at)
                  VALUES (2,'-LegacySender','مُرسِل ناقص','01000000003','{$now}')");

        // أوردر 1: كل الحقول مليانة + طردين + صور + نقلة + تقييمين
        $p->exec("INSERT INTO orders (id,order_num,qr_code,branch_id,sender_id,sender_name,sender_phone,sender_phone2,
                    sender_address,sender_lat,sender_lng,sender_zone_id,notes,total_delivery_price,store_prepaid,
                    store_prepaid_note,goods_value,wallet_used,status,status_since,prev_status,added_by,added_by_role,
                    source,customer_name,customer_phone,order_kind,payment_method,pieces_count,pilot_id,pilot_name,
                    shift_id,current_pilot_since,received_at,trip_started_at,delivered_at,money_settled,transfer_count,
                    created_at,updated_at)
                  VALUES (1,'AAA-260819-001','AAA-260819-001',1,1,'مُرسِل','01000000001','01000000002',
                    'عنوان المُرسِل',30.05,31.23,1,'ملاحظات',71.00,50.00,'مقدم',500.00,10.00,'delivered','{$now}',
                    'delivering','admin','admin','branch','عميل','01000000009','وثائق','كاش',2,1,'طيار في إذن',
                    5,'{$now}','{$now}','{$now}','{$now}',1,1,'{$now}','{$now}')");
        $p->exec("INSERT INTO order_deliveries (id,order_id,parcel_no,receiver_name,receiver_phone,receiver_phone2,
                    zone_id,zone_name,zone_price,order_price,address,note,status)
                  VALUES (1,1,1,'مستلم ١','01000000011','01000000012',1,'الزمالك',35.50,250.00,'عنوان','ملحوظة','delivered')");
        // طرد بلا منطقة مخزّنة — لازم يقع على اسم المنطقة من الـjoin
        $p->exec("INSERT INTO order_deliveries (id,order_id,parcel_no,receiver_name,receiver_phone,
                    zone_id,zone_name,zone_price,order_price,status)
                  VALUES (2,1,2,'مستلم ٢','01000000013',2,'',35.50,0.00,'undelivered')");
        $p->exec("INSERT INTO order_images (id,delivery_id,url,created_at) VALUES (1,1,'/uploads/a.jpg','{$now}')");
        $p->exec("INSERT INTO order_images (id,delivery_id,url,created_at) VALUES (2,1,'/uploads/b.jpg','{$now}')");
        $p->exec("INSERT INTO order_transfers (id,order_id,from_pilot_id,to_pilot_id,from_shift_id,to_shift_id,
                    transferred_at,transferred_by) VALUES (1,1,2,1,3,5,'{$now}','admin')");
        $p->exec("INSERT INTO order_ratings (id,order_id,rater,stars,note,action,rated_by,rated_by_id,rated_at)
                  VALUES (1,1,'store',5,'ممتاز','none','alnour',16,'{$now}')");
        $p->exec("INSERT INTO order_ratings (id,order_id,rater,stars,note,action,rated_by,rated_by_id,rated_at)
                  VALUES (2,1,'customer',3,'','call',NULL,NULL,'{$now}')");

        // أوردر 2: كل الاختياري NULL + بلا طرود خالص + بلا فرع مشتق
        $p->exec("INSERT INTO orders (id,order_num,branch_id,total_delivery_price,status,created_at,updated_at)
                  VALUES (2,'BBB-260819-001',2,0.00,'processing','{$now}','{$now}')");

        // أوردر 3: ملغي بـlegacy_key وأسباب وقيم على الحافة
        $p->exec("INSERT INTO orders (id,legacy_key,order_num,branch_id,total_delivery_price,store_prepaid,goods_value,
                    wallet_used,status,prev_status,cancelled_at,cancelled_by,cancelled_reason,return_status,
                    return_reason,split_from_id,undelivered_at,undelivered_reason,transfer_count,created_at,updated_at)
                  VALUES (3,'-LegacyOrder1','CCC-260819-001',3,99999.99,0.00,0.00,0.00,'cancelled','processing',
                    '{$now}','عميل','اتلغى بطلب العميل','rejected','مرفوض',1,'{$now}','مفيش حد',3,'{$now}','{$now}')");

        // أوردر 4: حالة مش في القاموس القديم — لازم ترجع زي ما هي
        $p->exec("INSERT INTO orders (id,order_num,branch_id,total_delivery_price,status,created_at,updated_at)
                  VALUES (4,'AAA-260819-002',1,10.00,'pending_pickup','{$now}','{$now}')");

        $p->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
