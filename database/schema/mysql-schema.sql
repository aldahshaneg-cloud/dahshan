
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `acc_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL COMMENT 'كود الحساب الهرمي (زي 1101)',
  `name` varchar(190) NOT NULL COMMENT 'اسم الحساب بالعربي',
  `type` varchar(20) NOT NULL COMMENT 'asset=أصول, liability=خصوم, equity=حقوق ملكية, revenue=إيرادات, expense=مصروفات',
  `is_group` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = حساب تجميعي (أب) لا يقبل قيود مباشرة',
  `nature` varchar(10) NOT NULL COMMENT 'debit=مدين, credit=دائن — طبيعة رصيد الحساب',
  `parent_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الحساب الأب في الشجرة',
  `party_type` varchar(20) DEFAULT NULL COMMENT 'نوع الطرف المرتبط: pilot=طيار, branch=فرع, store=خزنة, customer=عميل, employee=موظف — NULL للحسابات العامة',
  `link_id` bigint(20) unsigned DEFAULT NULL COMMENT 'id الصف المرتبط في جدول الطرف (pilots/branches/...)',
  `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = حساب نظامي مزروع بالكود لا يجوز حذفه',
  `active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = حساب نشط',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acc_accounts_code` (`code`),
  UNIQUE KEY `uq_acc_accounts_party` (`party_type`,`link_id`),
  KEY `idx_acc_accounts_parent` (`parent_id`),
  KEY `idx_acc_accounts_type` (`type`),
  KEY `idx_acc_accounts_active` (`active`),
  CONSTRAINT `fk_acc_accounts_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `acc_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='شجرة الحسابات — حسابات النظام والحسابات الفرعية للأطراف';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `acc_journal_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `entry_no` bigint(20) unsigned NOT NULL COMMENT 'رقم القيد المتسلسل — يتولّد بقفل داخل المعاملة، مش AUTO_INCREMENT',
  `entry_date` date NOT NULL COMMENT 'تاريخ القيد المحاسبي',
  `period` char(7) NOT NULL COMMENT 'الفترة المحاسبية YYYY-MM',
  `description` varchar(190) NOT NULL COMMENT 'بيان القيد',
  `ref_type` varchar(30) DEFAULT NULL COMMENT 'نوع المستند المصدر (order/expense/payroll/advance/manual/...)',
  `ref_id` varchar(60) DEFAULT NULL COMMENT 'معرّف المستند المصدر (رقم أو مفتاح قديم)',
  `status` varchar(10) NOT NULL DEFAULT 'posted' COMMENT 'posted=مرحّل, draft=مسودة, void=ملغي',
  `total_debit` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي الجانب المدين — لازم يساوي الدائن',
  `total_credit` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي الجانب الدائن',
  `created_by` varchar(190) DEFAULT NULL COMMENT 'اسم مستخدم منشئ القيد — أو auto للترحيل التلقائي',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `voided_at` datetime DEFAULT NULL COMMENT 'وقت الإلغاء',
  `voided_by` varchar(190) DEFAULT NULL COMMENT 'من قام بالإلغاء',
  `void_reason` varchar(190) DEFAULT NULL COMMENT 'سبب الإلغاء',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acc_journal_entries_no` (`entry_no`),
  KEY `idx_acc_journal_entries_period` (`period`),
  KEY `idx_acc_journal_entries_ref` (`ref_type`,`ref_id`),
  KEY `idx_acc_journal_entries_date` (`entry_date`),
  KEY `idx_acc_journal_entries_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رؤوس القيود اليومية — محاسبة مزدوجة';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `acc_journal_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `entry_id` bigint(20) unsigned NOT NULL COMMENT 'القيد الأب',
  `account_id` bigint(20) unsigned NOT NULL COMMENT 'الحساب من الشجرة',
  `account_code` varchar(20) NOT NULL COMMENT 'snapshot كود الحساب وقت القيد',
  `account_name` varchar(190) NOT NULL COMMENT 'snapshot اسم الحساب وقت القيد',
  `debit` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'المبلغ المدين — صفر لو السطر دائن',
  `credit` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'المبلغ الدائن — صفر لو السطر مدين',
  `branch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الفرع (مركز التكلفة) إن وجد',
  `branch_name` varchar(190) DEFAULT NULL COMMENT 'snapshot اسم الفرع وقت القيد',
  `party_id` bigint(20) unsigned DEFAULT NULL COMMENT 'id الطرف المرتبط بالسطر (طيار/عميل/موظف...) حسب party_type بتاع الحساب',
  `note` varchar(190) DEFAULT NULL COMMENT 'ملاحظة على السطر',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_acc_journal_lines_entry` (`entry_id`),
  KEY `idx_acc_journal_lines_account` (`account_id`),
  KEY `idx_acc_journal_lines_branch` (`branch_id`),
  KEY `idx_acc_journal_lines_party` (`party_id`),
  CONSTRAINT `fk_acc_journal_lines_account_id` FOREIGN KEY (`account_id`) REFERENCES `acc_accounts` (`id`),
  CONSTRAINT `fk_acc_journal_lines_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_acc_journal_lines_entry_id` FOREIGN KEY (`entry_id`) REFERENCES `acc_journal_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بنود القيود اليومية — سطر لكل حساب مدين أو دائن';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `acc_periods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `period` char(7) NOT NULL COMMENT 'الفترة YYYY-MM',
  `status` varchar(10) NOT NULL DEFAULT 'open' COMMENT 'open=مفتوحة, closed=مقفولة',
  `closed_at` datetime DEFAULT NULL COMMENT 'وقت قفل الفترة',
  `closed_by` varchar(190) DEFAULT NULL COMMENT 'من قفل الفترة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acc_periods_period` (`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الفترات المحاسبية الشهرية وحالة قفلها';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `acc_post_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'وقت تشغيل دورة الترحيل',
  `ref_type` varchar(30) DEFAULT NULL COMMENT 'نوع المستندات اللي اتعالجت في الدورة — NULL لو دورة شاملة',
  `processed` int(11) NOT NULL DEFAULT 0 COMMENT 'عدد المستندات اللي اتفحصت',
  `posted` int(11) NOT NULL DEFAULT 0 COMMENT 'عدد القيود اللي اترحّلت فعلًا',
  `skipped` int(11) NOT NULL DEFAULT 0 COMMENT 'عدد اللي اتعدّى (مرحّل قبل كده أو ناقص بيانات)',
  `errors` text DEFAULT NULL COMMENT 'تفاصيل الأخطاء إن وجدت',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_acc_post_log_run` (`run_at`),
  KEY `idx_acc_post_log_ref_type` (`ref_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل دورات الترحيل التلقائي للقيود';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `acc_posted_refs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ref_key` varchar(120) NOT NULL COMMENT 'بصمة المستند المرحّل (نوع:معرّف) — unique تمنع الترحيل مرتين',
  `entry_id` bigint(20) unsigned NOT NULL COMMENT 'القيد الناتج عن الترحيل',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acc_posted_refs_key` (`ref_key`),
  KEY `idx_acc_posted_refs_entry` (`entry_id`),
  CONSTRAINT `fk_acc_posted_refs_entry_id` FOREIGN KEY (`entry_id`) REFERENCES `acc_journal_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بصمات منع ازدواج الترحيل التلقائي للمستندات';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `acc_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL COMMENT 'مفتاح الإعداد (زي mapping أو devFeePerOrder)',
  `setting_value` longtext DEFAULT NULL COMMENT 'قيمة الإعداد JSON',
  `updated_at` datetime DEFAULT NULL COMMENT 'آخر تعديل — بيتحدث من التطبيق',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acc_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إعدادات المحاسبة — mapping أكواد الربط ورسوم المطوّر وغيرها';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_emails` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL COMMENT 'إيميل جوجل مسموح له بالإدارة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_emails_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إيميلات جوجل المسموحة لدخول تطبيق الإدارة';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `session_date` date NOT NULL COMMENT 'يوم الجلسة',
  `username` varchar(190) NOT NULL COMMENT 'اسم المستخدم أو معرّف الموظف اليدوي',
  `role` varchar(32) DEFAULT NULL COMMENT 'دور صاحب الجلسة (pilot/branch/callcenter/manual...)',
  `check_in` datetime NOT NULL COMMENT 'وقت الحضور',
  `check_out` datetime DEFAULT NULL COMMENT 'وقت الانصراف — NULL لسه شغال',
  `last_seen` datetime DEFAULT NULL COMMENT 'آخر نبضة heartbeat من الجهاز',
  `entry_type` varchar(20) DEFAULT NULL COMMENT 'auto=تلقائي, manual=يدوي',
  `auto_check_out` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 لو الانصراف اتسجل تلقائيًا لانقطاع النبض',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_attendance_sessions_legacy` (`legacy_key`),
  KEY `idx_attendance_sessions_date_user` (`session_date`,`username`),
  KEY `idx_attendance_sessions_username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جلسات الحضور والانصراف (حضور هجين بالنبض)';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branch_areas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned NOT NULL,
  `area_name` varchar(190) NOT NULL COMMENT 'اسم المنطقة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_areas_branch_area` (`branch_id`,`area_name`),
  KEY `idx_branch_areas_area` (`area_name`),
  CONSTRAINT `fk_branch_areas_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مناطق كل فرع';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL COMMENT 'اسم الفرع',
  `code` varchar(6) NOT NULL COMMENT 'كود الفرع المختصر (2-6 حروف، فريد)',
  `phone` varchar(20) DEFAULT NULL,
  `manager` varchar(190) DEFAULT NULL COMMENT 'اسم مدير الفرع',
  `address` varchar(190) DEFAULT NULL,
  `paused` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'الفرع موقوف مؤقتًا',
  `failover_branch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الفرع البديل اللي يستلم الشغل وقت الإيقاف',
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branches_code` (`code`),
  KEY `idx_branches_legacy` (`legacy_key`),
  KEY `idx_branches_failover` (`failover_branch_id`),
  CONSTRAINT `fk_branches_failover_branch_id` FOREIGN KEY (`failover_branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الفروع';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cash_stores` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `name` varchar(190) NOT NULL COMMENT 'اسم الخزنة',
  `branch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الفرع التابعة له الخزنة — NULL للخزنة الرئيسية',
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'الرصيد الحالي',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cash_stores_legacy` (`legacy_key`),
  KEY `idx_cash_stores_branch` (`branch_id`),
  CONSTRAINT `fk_cash_stores_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الخزن — خزنة رئيسية وخزن الفروع';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cash_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `store_id` bigint(20) unsigned NOT NULL COMMENT 'الخزنة',
  `type` varchar(10) NOT NULL COMMENT 'in=وارد, out=منصرف, pending=معلّق',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(190) DEFAULT NULL COMMENT 'سبب الحركة',
  `notes` text DEFAULT NULL,
  `related_pilot_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الطيار المرتبط بالحركة إن وجد',
  `related_expense_id` bigint(20) unsigned DEFAULT NULL COMMENT 'معرّف المصروف المرتبط إن وجدت الحركة من مصروف',
  `branch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الفرع صاحب الحركة',
  `created_by` varchar(190) DEFAULT NULL COMMENT 'اسم مستخدم منشئ الحركة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cash_transactions_legacy` (`legacy_key`),
  KEY `idx_cash_transactions_store_created` (`store_id`,`created_at`),
  KEY `idx_cash_transactions_type` (`type`),
  KEY `idx_cash_transactions_branch` (`branch_id`),
  KEY `idx_cash_transactions_pilot` (`related_pilot_id`),
  KEY `idx_cash_transactions_expense` (`related_expense_id`),
  CONSTRAINT `fk_cash_transactions_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_cash_transactions_related_pilot_id` FOREIGN KEY (`related_pilot_id`) REFERENCES `pilots` (`id`),
  CONSTRAINT `fk_cash_transactions_store_id` FOREIGN KEY (`store_id`) REFERENCES `cash_stores` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حركات النقدية على الخزن (وارد/منصرف/معلّق)';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cc_complaints` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `order_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الأوردر موضوع الشكوى إن وجد',
  `order_num` varchar(40) DEFAULT NULL COMMENT 'رقم الأوردر كما أدخله الموظف (قد لا يطابق أوردر فعلي)',
  `customer_name` varchar(190) NOT NULL COMMENT 'اسم العميل الشاكي',
  `phone` varchar(20) DEFAULT NULL COMMENT 'تليفون العميل',
  `type` varchar(40) NOT NULL DEFAULT 'other' COMMENT 'نوع الشكوى — 7 أنواع',
  `details` text NOT NULL COMMENT 'تفاصيل الشكوى',
  `status` varchar(20) NOT NULL DEFAULT 'open' COMMENT 'حالة الشكوى',
  `resolution` text DEFAULT NULL COMMENT 'الإجراء اللي اتعمل لحل الشكوى',
  `created_by` varchar(100) DEFAULT NULL COMMENT 'اسم مستخدم موظف الكول سنتر اللي سجّل الشكوى',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_by` varchar(100) DEFAULT NULL COMMENT 'اسم مستخدم من قفل الشكوى',
  `resolved_at` datetime DEFAULT NULL COMMENT 'وقت حل الشكوى',
  PRIMARY KEY (`id`),
  KEY `idx_cc_complaints_legacy` (`legacy_key`),
  KEY `idx_cc_complaints_order` (`order_id`),
  KEY `idx_cc_complaints_order_num` (`order_num`),
  KEY `idx_cc_complaints_phone` (`phone`),
  KEY `idx_cc_complaints_status` (`status`,`created_at`),
  KEY `idx_cc_complaints_type` (`type`),
  CONSTRAINT `fk_cc_complaints_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='شكاوى العملاء المسجلة من الكول سنتر';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cc_zone_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `area_name` varchar(190) NOT NULL COMMENT 'اسم المنطقة غير المعرفة كما كتبها الموظف',
  `branch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الفرع اللي طُلبت له المنطقة',
  `last_price` decimal(12,2) DEFAULT NULL COMMENT 'آخر سعر توصيل اتكتب يدوي للمنطقة',
  `request_count` int(11) NOT NULL DEFAULT 1 COMMENT 'عداد تكرار طلب نفس المنطقة لنفس الفرع',
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'حالة طلب المنطقة',
  `first_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'أول مرة اتطلبت فيها المنطقة',
  `last_at` datetime DEFAULT NULL COMMENT 'آخر مرة اتطلبت فيها المنطقة',
  `last_order_num` varchar(40) DEFAULT NULL COMMENT 'رقم آخر أوردر استخدم المنطقة',
  `first_order_id` bigint(20) unsigned DEFAULT NULL COMMENT 'أول أوردر ظهرت فيه المنطقة',
  `requested_by` varchar(100) DEFAULT NULL COMMENT 'اسم مستخدم أول من طلب المنطقة',
  `last_by` varchar(100) DEFAULT NULL COMMENT 'اسم مستخدم آخر من طلب المنطقة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cc_zone_requests_legacy` (`legacy_key`),
  KEY `idx_cc_zone_requests_branch` (`branch_id`,`status`),
  KEY `idx_cc_zone_requests_area` (`area_name`),
  KEY `idx_cc_zone_requests_status` (`status`),
  KEY `fk_cc_zone_requests_first_order_id` (`first_order_id`),
  CONSTRAINT `fk_cc_zone_requests_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_cc_zone_requests_first_order_id` FOREIGN KEY (`first_order_id`) REFERENCES `orders` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='طلبات إضافة مناطق غير معرفة — بتتجمع بعداد تكرار لكل (منطقة، فرع)';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL COMMENT 'اسم صاحب الرسالة',
  `phone` varchar(20) NOT NULL COMMENT 'رقم تليفون صاحب الرسالة',
  `email` varchar(190) DEFAULT NULL COMMENT 'إيميل صاحب الرسالة — اختياري',
  `subject` varchar(190) DEFAULT NULL COMMENT 'موضوع الرسالة — اختياري',
  `message` text NOT NULL COMMENT 'نص الرسالة',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'عنوان IP اللي اتبعتت منه الرسالة',
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'حالة الرسالة: pending/read/archived',
  `read_by` varchar(190) DEFAULT NULL COMMENT 'مين قرأ الرسالة (اسم/معرّف المستخدم)',
  `read_at` datetime DEFAULT NULL COMMENT 'وقت قراءة الرسالة — NULL يعني لسه مش مقروءة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_contact_messages_status` (`status`,`created_at`),
  KEY `idx_contact_messages_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رسائل نموذج «اتصل بنا» الجاية من الموقع التسويقي';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `custody_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `pilot_id` bigint(20) unsigned NOT NULL COMMENT 'الطيار صاحب العهدة',
  `type` varchar(20) NOT NULL COMMENT 'give=تسليم عهدة, return=ردّ عهدة, order_pending=معلّق أوردر, order_extra=زيادة أوردر',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `store_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الخزنة اللي اتحركت منها/ليها النقدية',
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` varchar(190) DEFAULT NULL COMMENT 'اسم مستخدم منشئ الحركة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_custody_transactions_legacy` (`legacy_key`),
  KEY `idx_custody_transactions_pilot_created` (`pilot_id`,`created_at`),
  KEY `idx_custody_transactions_type` (`type`),
  KEY `idx_custody_transactions_store` (`store_id`),
  KEY `idx_custody_transactions_branch` (`branch_id`),
  CONSTRAINT `fk_custody_transactions_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_custody_transactions_pilot_id` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`),
  CONSTRAINT `fk_custody_transactions_store_id` FOREIGN KEY (`store_id`) REFERENCES `cash_stores` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حركات العهدة مع الطيارين (تسليم/ردّ/معلّق أوردرات)';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `label` varchar(190) DEFAULT NULL COMMENT 'تسمية العنوان (البيت، الشغل...)',
  `full_address` text DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `zone_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الفرع المشتق من الزون وقت الحفظ',
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'العنوان الافتراضي للعميل',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_customer_addresses_customer_id` (`customer_id`),
  KEY `fk_customer_addresses_zone_id` (`zone_id`),
  KEY `fk_customer_addresses_branch_id` (`branch_id`),
  KEY `idx_caddr_created` (`created_at`),
  CONSTRAINT `fk_customer_addresses_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_customer_addresses_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_customer_addresses_zone_id` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=98908 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='دفتر عناوين العميل داخل التطبيق';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_push_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL COMMENT 'الأوردر اللي الإشعار بيخصّه',
  `state_key` varchar(20) NOT NULL COMMENT 'مفتاح الحالة اللي اتبعت عنها إشعار: assigned/received/out/delivered/failed/cancel — نفس مفاتيح إشعارات تطبيق العميل',
  `state_at` datetime DEFAULT NULL COMMENT 'طابع الحالة وقت الإرسال (created_at/current_pilot_since/trip_started_at…) — لو اتقدّم (إعادة توصيل) الإشعار بيتبعت تاني',
  `sent_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'وقت حجز الحالة للإرسال — الصف بيتكتب قبل الإرسال الفعلي (منع التكرار من القاعدة)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_push_events_state` (`order_id`,`state_key`),
  CONSTRAINT `fk_customer_push_events_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حالات الأوردر اللي اتبعت عنها إشعار ستارة للعميل — صف واحد لكل (أوردر، حالة) يمنع التكرار';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_push_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL COMMENT 'العميل صاحب الجهاز — الاشتراك بيتنقل لآخر عميل اشترك من نفس المتصفح',
  `endpoint_hash` char(64) NOT NULL COMMENT 'SHA-256 لعنوان الـendpoint — مفتاح منع التكرار (العنوان نفسه ممكن يعدّي 500 حرف فمايصلحش كمفتاح فريد)',
  `endpoint` text NOT NULL COMMENT 'عنوان خدمة الدفع الخاص بالجهاز (FCM/Mozilla/Apple/WNS) — الإشعار بيتبعت له',
  `p256dh` varchar(255) NOT NULL COMMENT 'مفتاح المتصفح العام (P-256، base64url) — لتشفير الحمولة',
  `auth` varchar(255) NOT NULL COMMENT 'سر المصادقة بتاع الاشتراك (base64url) — لتشفير الحمولة',
  `ua` varchar(255) DEFAULT NULL COMMENT 'User-Agent وقت الاشتراك — للتشخيص بس',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_ok_at` datetime DEFAULT NULL COMMENT 'آخر إرسال ناجح — NULL يعني لسه ما اتبعتش له حاجة',
  `fail_count` int(11) NOT NULL DEFAULT 0 COMMENT 'عدد مرات الفشل المتتالية — بيتصفّر مع أي نجاح أو إعادة اشتراك؛ 404/410 بيمسح الصف فورًا',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_push_subscriptions_endpoint` (`endpoint_hash`),
  KEY `idx_customer_push_subscriptions_customer` (`customer_id`),
  CONSTRAINT `fk_customer_push_subscriptions_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='اشتراكات إشعارات الستارة (Web Push) لأجهزة عملاء التطبيق — صف واحد لكل جهاز/متصفح';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_saved_receivers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `name` varchar(190) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `phone2` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `zone_id` bigint(20) unsigned DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_saved_receivers_phone` (`customer_id`,`phone`),
  KEY `idx_customer_saved_receivers_phone` (`phone`),
  KEY `fk_customer_saved_receivers_zone_id` (`zone_id`),
  KEY `idx_csr_created` (`created_at`),
  CONSTRAINT `fk_customer_saved_receivers_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_customer_saved_receivers_zone_id` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مستلمين محفوظين لكل عميل — رقم المستلم فريد داخل حساب العميل';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'uid القديم من Firebase Auth',
  `email` varchar(190) DEFAULT NULL COMMENT 'إيميل دخول جوجل',
  `photo_url` varchar(500) DEFAULT NULL COMMENT 'صورة البروفايل من جوجل',
  `display_name` varchar(190) DEFAULT NULL,
  `phone1` varchar(20) DEFAULT NULL COMMENT 'رقم الموبايل الأساسي',
  `phone2` varchar(20) DEFAULT NULL COMMENT 'رقم احتياطي',
  `address` varchar(190) DEFAULT NULL COMMENT 'العنوان الافتراضي المختصر',
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `default_zone_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الزون الافتراضي (بيحدد فرع الإرسال)',
  `default_branch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الفرع الافتراضي المشتق من الزون',
  `profile_completed` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'أكمل بياناته (موبايل + عنوان) بعد أول دخول',
  `blocked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'محظور من استخدام التطبيق',
  `blocked_at` datetime DEFAULT NULL,
  `blocked_by` varchar(190) DEFAULT NULL COMMENT 'مين حظره (اسم/إيميل الموظف)',
  `notif_seen_at` datetime DEFAULT NULL COMMENT 'آخر مرة فتح فيها جرس الإشعارات — لحساب العدّاد غير المقروء',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_login_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'آخر تعديل — بيغذّي فحص ?since',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customers_email` (`email`),
  KEY `idx_customers_legacy` (`legacy_key`),
  KEY `idx_customers_phone1` (`phone1`),
  KEY `idx_customers_phone2` (`phone2`),
  KEY `idx_customers_blocked` (`blocked`),
  KEY `fk_customers_default_zone_id` (`default_zone_id`),
  KEY `fk_customers_default_branch_id` (`default_branch_id`),
  KEY `idx_customers_updated` (`updated_at`),
  CONSTRAINT `fk_customers_default_branch_id` FOREIGN KEY (`default_branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_customers_default_zone_id` FOREIGN KEY (`default_zone_id`) REFERENCES `zones` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=49848 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عملاء تطبيق العميل — دخول جوجل، الملف الشخصي، الحظر';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `device_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_type` varchar(20) NOT NULL COMMENT 'نوع صاحب التوكن: customer/pilot/user',
  `owner_id` bigint(20) unsigned NOT NULL COMMENT 'id صاحب التوكن في جدوله حسب owner_type',
  `token` varchar(255) NOT NULL COMMENT 'توكن FCM للجهاز',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL COMMENT 'آخر تحديث للتوكن — بيتحدث يدويًا من التطبيق',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_tokens_token` (`token`),
  KEY `idx_device_tokens_owner` (`owner_type`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توكنات FCM — بتتنضف عند فشل الإرسال (توكن بايظ)';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `egypt_cities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `governorate_id` bigint(20) unsigned NOT NULL,
  `name` varchar(190) NOT NULL COMMENT 'اسم المدينة/المركز',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_egypt_cities_gov_name` (`governorate_id`,`name`),
  CONSTRAINT `fk_egypt_cities_governorate_id` FOREIGN KEY (`governorate_id`) REFERENCES `egypt_governorates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=464 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مدن ومراكز مصر مرتبطة بالمحافظات';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `egypt_governorates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL COMMENT 'اسم المحافظة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_egypt_governorates_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='محافظات مصر — بديل القوائم المكررة في الكود';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expenses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `expense_date` date NOT NULL COMMENT 'تاريخ المصروف',
  `item` varchar(190) NOT NULL COMMENT 'بند المصروف',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `cash_store_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الخزنة اللي اتصرف منها المبلغ',
  `cash_txn_id` bigint(20) unsigned DEFAULT NULL COMMENT 'معرّف حركة النقدية المرتبطة في cash_transactions',
  `created_by` varchar(190) DEFAULT NULL COMMENT 'اسم مستخدم مسجّل المصروف',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_expenses_legacy` (`legacy_key`),
  KEY `idx_expenses_date` (`expense_date`),
  KEY `idx_expenses_branch` (`branch_id`),
  KEY `idx_expenses_cash_store` (`cash_store_id`),
  KEY `idx_expenses_cash_txn` (`cash_txn_id`),
  CONSTRAINT `fk_expenses_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_expenses_cash_store_id` FOREIGN KEY (`cash_store_id`) REFERENCES `cash_stores` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المصروفات اليومية';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL COMMENT 'سبب الفشل — ده اللي بتشوفه لما البث يقع',
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المهام اللي فشلت بعد كل المحاولات';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_attendance` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `att_date` date NOT NULL COMMENT 'يوم الوردية المنطقي (يوم بدايتها)',
  `employee_id` bigint(20) unsigned NOT NULL,
  `check_in` datetime DEFAULT NULL COMMENT 'وقت الحضور UTC — قد يكون NULL لو غياب/إجازة',
  `check_out` datetime DEFAULT NULL COMMENT 'وقت الانصراف UTC — قد يتجاوز منتصف الليل عن att_date',
  `status` varchar(20) NOT NULL DEFAULT 'present',
  `source` varchar(20) NOT NULL DEFAULT 'manual' COMMENT 'مصدر تسجيل الحضور — الحضور الهجين',
  `recorded_by` varchar(190) DEFAULT NULL COMMENT 'من سجّل/عدّل السطر',
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_attendance_day_emp` (`att_date`,`employee_id`),
  KEY `idx_hr_attendance_legacy` (`legacy_key`),
  KEY `idx_hr_attendance_employee_date` (`employee_id`,`att_date`),
  KEY `idx_hr_attendance_status` (`status`),
  CONSTRAINT `fk_hr_attendance_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل الحضور والانصراف اليومي — سطر واحد لكل موظف لكل يوم وردية';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_departments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `name` varchar(190) NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL COMMENT 'القسم الأب لو القسم فرعي',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hr_departments_legacy` (`legacy_key`),
  KEY `idx_hr_departments_parent` (`parent_id`),
  CONSTRAINT `fk_hr_departments_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `hr_departments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أقسام الشركة (هيكل شجري)';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_employee_leave_overrides` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `leave_type_id` bigint(20) unsigned NOT NULL,
  `days_override` int(11) NOT NULL COMMENT 'الرصيد السنوي المخصص لهذا الموظف بدل الافتراضي',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_emp_leave_override` (`employee_id`,`leave_type_id`),
  KEY `idx_hr_emp_leave_overrides_type` (`leave_type_id`),
  CONSTRAINT `fk_hr_employee_leave_overrides_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hr_employee_leave_overrides_leave_type_id` FOREIGN KEY (`leave_type_id`) REFERENCES `hr_leave_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رصيد إجازات مخصص لموظف معين يتغلب على رصيد النوع';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_employees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `code` varchar(20) NOT NULL COMMENT 'كود الموظف الظاهر في الشاشات',
  `name` varchar(190) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `phone2` varchar(20) DEFAULT NULL COMMENT 'تليفون إضافي / للطوارئ',
  `national_id` varchar(20) DEFAULT NULL COMMENT 'الرقم القومي',
  `birth_date` date DEFAULT NULL,
  `gender` varchar(32) NOT NULL DEFAULT 'male',
  `marital` varchar(32) NOT NULL DEFAULT 'single',
  `address` varchar(190) DEFAULT NULL,
  `education` varchar(190) DEFAULT NULL COMMENT 'المؤهل الدراسي',
  `license_no` varchar(50) DEFAULT NULL COMMENT 'رقم رخصة القيادة',
  `license_end` date DEFAULT NULL COMMENT 'تاريخ انتهاء رخصة القيادة',
  `health_end` date DEFAULT NULL COMMENT 'تاريخ انتهاء الشهادة الصحية',
  `insurance_no` varchar(50) DEFAULT NULL COMMENT 'الرقم التأميني',
  `branch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الفرع التابع له الموظف',
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `job_title_id` bigint(20) unsigned DEFAULT NULL,
  `manager_id` bigint(20) unsigned DEFAULT NULL COMMENT 'المدير المباشر (موظف آخر)',
  `work_shift_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `hire_date` date DEFAULT NULL COMMENT 'تاريخ التعيين',
  `probation_end` date DEFAULT NULL COMMENT 'نهاية فترة الاختبار',
  `contract_type` varchar(20) NOT NULL DEFAULT 'permanent',
  `contract_end` date DEFAULT NULL COMMENT 'نهاية العقد لو مؤقت',
  `term_date` date DEFAULT NULL COMMENT 'تاريخ إنهاء الخدمة',
  `term_reason` varchar(190) DEFAULT NULL COMMENT 'سبب إنهاء الخدمة',
  `salary` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'الراتب الأساسي',
  `allowances` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي البدلات الشهرية',
  `pay_method` varchar(20) NOT NULL DEFAULT 'cash',
  `documents` longtext DEFAULT NULL COMMENT 'JSON: مستندات الموظف (روابط/أنواع/تواريخ انتهاء)',
  `notes` text DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'manual' COMMENT 'مصدر السجل الموحد',
  `source_id` bigint(20) unsigned DEFAULT NULL COMMENT 'id الصف في جدول المصدر (users أو pilots)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_employees_code` (`code`),
  UNIQUE KEY `uq_hr_employees_source` (`source`,`source_id`),
  KEY `idx_hr_employees_legacy` (`legacy_key`),
  KEY `idx_hr_employees_name` (`name`),
  KEY `idx_hr_employees_phone` (`phone`),
  KEY `idx_hr_employees_national_id` (`national_id`),
  KEY `idx_hr_employees_status_branch` (`status`,`branch_id`),
  KEY `idx_hr_employees_department` (`department_id`),
  KEY `idx_hr_employees_job_title` (`job_title_id`),
  KEY `idx_hr_employees_manager` (`manager_id`),
  KEY `idx_hr_employees_work_shift` (`work_shift_id`),
  KEY `fk_hr_employees_branch_id` (`branch_id`),
  CONSTRAINT `fk_hr_employees_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_hr_employees_department_id` FOREIGN KEY (`department_id`) REFERENCES `hr_departments` (`id`),
  CONSTRAINT `fk_hr_employees_job_title_id` FOREIGN KEY (`job_title_id`) REFERENCES `hr_job_titles` (`id`),
  CONSTRAINT `fk_hr_employees_manager_id` FOREIGN KEY (`manager_id`) REFERENCES `hr_employees` (`id`),
  CONSTRAINT `fk_hr_employees_work_shift_id` FOREIGN KEY (`work_shift_id`) REFERENCES `hr_work_shifts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الموظفون — السجل الموحد لكل العاملين (مستخدمين/طيارين/يدوي)';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_holidays` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `holiday_date` date NOT NULL,
  `name` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_holidays_date` (`holiday_date`),
  KEY `idx_hr_holidays_legacy` (`legacy_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='العطلات الرسمية المعتمدة';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_job_titles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `name` varchar(190) NOT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL COMMENT 'القسم التابع له المسمى (اختياري)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hr_job_titles_legacy` (`legacy_key`),
  KEY `idx_hr_job_titles_department` (`department_id`),
  CONSTRAINT `fk_hr_job_titles_department_id` FOREIGN KEY (`department_id`) REFERENCES `hr_departments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المسميات الوظيفية';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_leave_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `name` varchar(190) NOT NULL,
  `days_per_year` int(11) NOT NULL DEFAULT 0 COMMENT 'الرصيد السنوي الافتراضي بالأيام',
  `paid` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'مدفوعة الأجر؟',
  `carry_over` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'يُرحَّل المتبقي للسنة التالية؟',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hr_leave_types_legacy` (`legacy_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أنواع الإجازات وأرصدتها السنوية';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_leaves` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `employee_id` bigint(20) unsigned NOT NULL,
  `leave_type_id` bigint(20) unsigned NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `decided_by` varchar(190) DEFAULT NULL COMMENT 'اسم/معرّف من اتخذ القرار',
  `decided_at` datetime DEFAULT NULL,
  `reason` text DEFAULT NULL COMMENT 'سبب طلب الإجازة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hr_leaves_legacy` (`legacy_key`),
  KEY `idx_hr_leaves_employee_status` (`employee_id`,`status`),
  KEY `idx_hr_leaves_type` (`leave_type_id`),
  KEY `idx_hr_leaves_dates` (`date_from`,`date_to`),
  CONSTRAINT `fk_hr_leaves_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`),
  CONSTRAINT `fk_hr_leaves_leave_type_id` FOREIGN KEY (`leave_type_id`) REFERENCES `hr_leave_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='طلبات إجازات الموظفين ودورة الموافقة عليها';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_payroll_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `snapshot` longtext DEFAULT NULL COMMENT 'JSON: لقطة قسيمة الراتب كاملة وقت الاعتماد (تفاصيل الحساب)',
  `base_salary` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'الراتب الأساسي',
  `allowances` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'البدلات',
  `overtime` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'قيمة الوقت الإضافي',
  `bonus` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'مكافآت',
  `deductions` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'خصومات (غياب/تأخير...)',
  `penalties` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'قيمة الجزاءات المخصومة',
  `advances` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'السلف المستقطعة هذا الشهر',
  `net` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'صافي المستحق',
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_payroll_items_run_emp` (`run_id`,`employee_id`),
  KEY `idx_hr_payroll_items_employee` (`employee_id`),
  CONSTRAINT `fk_hr_payroll_items_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`),
  CONSTRAINT `fk_hr_payroll_items_run_id` FOREIGN KEY (`run_id`) REFERENCES `hr_payroll_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بنود مسير الرواتب — قسيمة راتب لكل موظف في كل مسير';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_payroll_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `month` char(7) NOT NULL COMMENT 'الشهر بصيغة YYYY-MM',
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `totals` longtext DEFAULT NULL COMMENT 'JSON: إجماليات المسير (أساسي/بدلات/خصومات/صافي...)',
  `accounting_journal_id` bigint(20) unsigned DEFAULT NULL COMMENT 'قيد اليومية المرتبط في نظام الحسابات (بدون FK — مجال آخر)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  `approved_by` varchar(190) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_payroll_runs_month` (`month`),
  KEY `idx_hr_payroll_runs_legacy` (`legacy_key`),
  KEY `idx_hr_payroll_runs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مسيرات الرواتب الشهرية — مسودة ثم اعتماد وترحيل للحسابات';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_penalties` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `employee_id` bigint(20) unsigned NOT NULL,
  `penalty_date` date NOT NULL,
  `amount_days` decimal(4,1) DEFAULT NULL COMMENT 'الجزاء بالأيام (خصم أيام من الراتب) — أحد الحقلين يُملأ',
  `amount_money` decimal(12,2) DEFAULT NULL COMMENT 'الجزاء بمبلغ مالي مباشر — أحد الحقلين يُملأ',
  `reason` text DEFAULT NULL,
  `applied_to_payroll` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'هل خُصم فعليًا في مسير رواتب؟',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hr_penalties_legacy` (`legacy_key`),
  KEY `idx_hr_penalties_employee_date` (`employee_id`,`penalty_date`),
  KEY `idx_hr_penalties_applied` (`applied_to_payroll`),
  CONSTRAINT `fk_hr_penalties_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جزاءات الموظفين (بالأيام أو بمبلغ) وحالة خصمها من الرواتب';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `employee_id` bigint(20) unsigned NOT NULL,
  `review_date` date NOT NULL,
  `scores` longtext DEFAULT NULL COMMENT 'JSON: درجات بنود التقييم التفصيلية',
  `total` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'الدرجة الإجمالية',
  `reviewer` varchar(190) DEFAULT NULL COMMENT 'اسم/معرّف المُقيِّم',
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hr_reviews_legacy` (`legacy_key`),
  KEY `idx_hr_reviews_employee_date` (`employee_id`,`review_date`),
  CONSTRAINT `fk_hr_reviews_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تقييمات أداء الموظفين الدورية';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إعدادات نظام الموارد البشرية (مفتاح/قيمة)';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hr_work_shifts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `name` varchar(190) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL COMMENT 'لو أصغر من start_time فالشيفت عابر منتصف الليل',
  `grace_minutes` int(11) NOT NULL DEFAULT 0 COMMENT 'دقائق السماح قبل احتساب التأخير',
  `break_minutes` int(11) NOT NULL DEFAULT 0 COMMENT 'دقائق الراحة المخصومة من ساعات العمل',
  `work_days` varchar(30) NOT NULL DEFAULT '' COMMENT 'أيام العمل بالأسبوع، أرقام مفصولة بفواصل (0=الأحد .. 6=السبت)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hr_work_shifts_legacy` (`legacy_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ورديات العمل للموظفين';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL COMMENT 'اسم الطابور',
  `payload` longtext NOT NULL COMMENT 'المهمة مسلسلة',
  `attempts` tinyint(3) unsigned NOT NULL COMMENT 'عدد المحاولات',
  `reserved_at` int(10) unsigned DEFAULT NULL COMMENT 'وقت حجزها لعامل',
  `available_at` int(10) unsigned NOT NULL COMMENT 'وقت إتاحتها للتنفيذ',
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='طابور المهام — البث بيتأجّل هنا بدل ما يتنفّذ جوه طلب المستخدم';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `username` varchar(190) NOT NULL,
  `fail_count` int(10) unsigned NOT NULL DEFAULT 0,
  `first_fail` datetime NOT NULL,
  `last_fail` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_login_ip_user` (`ip`,`username`),
  KEY `idx_login_locked` (`locked_until`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lookup_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_type` varchar(20) NOT NULL COMMENT 'Ï»┘êÏ▒ Ïº┘äÏ¿ÏºÏ¡Ï½: store/customer/branch/callcenter/admin',
  `actor_name` varchar(190) NOT NULL COMMENT 'ÏºÏ│┘à Ïº┘ä┘àÏ│Ï¬Ï«Ï»┘à Ïº┘äÏ¿ÏºÏ¡Ï½ (Ïú┘ê customer:{id})',
  `searched_phone` varchar(20) NOT NULL COMMENT 'Ïº┘äÏ▒┘é┘à Ïº┘ä┘àÏ¿Ï¡┘êÏ½ Ï╣┘å┘ç Ï¿Ï╣Ï» Ïº┘äÏ¬ÏÀÏ¿┘èÏ╣',
  `found` tinyint(1) NOT NULL DEFAULT 0 COMMENT '┘ç┘ä Ïº┘ä┘åÏ©Ïº┘à ┘ä┘é┘ë Ïº┘äÏ▒┘é┘à',
  `full_access` tinyint(1) NOT NULL DEFAULT 0 COMMENT '┘ç┘ä ÏºÏ¬Ï╣Ï▒ÏÂÏ¬ Ïº┘äÏ¿┘èÏº┘åÏºÏ¬ Ïº┘ä┘âÏº┘à┘äÏ® (ÏºÏ│┘à+Ï╣┘å┘êÏº┘å)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lookup_log_actor` (`actor_name`,`created_at`),
  KEY `idx_lookup_log_phone` (`searched_phone`),
  KEY `idx_lookup_log_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1056 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ï│Ï¼┘ä Ï╣┘à┘ä┘èÏºÏ¬ Ïº┘äÏ¿Ï¡Ï½ Ï¿Ïº┘äÏ¬┘ä┘è┘ü┘ê┘å ÔÇö ┘à┘åÏ╣ Ï│Ï¡Ï¿ ┘éÏºÏ╣Ï»Ï® Ïº┘äÏ╣┘à┘äÏºÏí';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `manual_employees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `name` varchar(190) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `job_title` varchar(190) DEFAULT NULL COMMENT 'المسمى الوظيفي',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_manual_employees_legacy` (`legacy_key`),
  KEY `idx_manual_employees_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='موظفين يدويين بيتسجل لهم حضور بدون حساب نظام';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `customer_id` bigint(20) unsigned DEFAULT NULL COMMENT 'العميل المستهدف بالإشعار',
  `order_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الأوردر المرتبط بالإشعار إن وجد',
  `type` varchar(30) NOT NULL DEFAULT 'order_status' COMMENT 'نوع الإشعار',
  `title` varchar(190) NOT NULL COMMENT 'عنوان الإشعار',
  `body` text DEFAULT NULL COMMENT 'نص الإشعار',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL COMMENT 'وقت قراءة العميل للإشعار — NULL يعني غير مقروء',
  PRIMARY KEY (`id`),
  KEY `idx_notifications_legacy` (`legacy_key`),
  KEY `idx_notifications_customer` (`customer_id`,`read_at`),
  KEY `idx_notifications_order` (`order_id`),
  KEY `idx_notifications_created` (`created_at`),
  CONSTRAINT `fk_notifications_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_notifications_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إشعارات العملاء — كانت بتتشتق محليًا في تطبيق العميل والآن تتخزن مركزيًا';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_counters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned NOT NULL,
  `day_key` char(8) NOT NULL COMMENT 'اليوم بصيغة YYYYMMDD بتوقيت القاهرة (مش UTC — عشان أوردر الفجر ياخد تاريخ اليوم الصح)',
  `counter` int(11) NOT NULL DEFAULT 0 COMMENT 'آخر رقم متسلسل اتصرف في اليوم ده',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_counters_branch_day` (`branch_id`,`day_key`),
  CONSTRAINT `fk_order_counters_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2260 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عداد ذري للترقيم اليومي للأوردرات لكل فرع — بديل transaction الترقيم في Firebase';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_deliveries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `order_id` bigint(20) unsigned NOT NULL,
  `parcel_no` int(11) NOT NULL DEFAULT 1 COMMENT 'ترتيب الطرد داخل الأوردر (1، 2، ...)',
  `receiver_id` bigint(20) unsigned DEFAULT NULL COMMENT 'مرجع المستلم المحفوظ في جدول receivers لو معروف',
  `receiver_name` varchar(190) DEFAULT NULL,
  `receiver_phone` varchar(20) DEFAULT NULL COMMENT 'تليفون المستلم — مفهرس (شاشة: الشحنات الجاية لي)',
  `receiver_phone2` varchar(20) DEFAULT NULL,
  `receiver_from_receipt` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'بيانات المستلم اتقرت من صورة الفاتورة (OCR) مش مدخلة يدوي',
  `zone_id` bigint(20) unsigned DEFAULT NULL COMMENT 'زون التسليم',
  `zone_name` varchar(190) DEFAULT NULL COMMENT 'اسم الزون (snapshot)',
  `zone_price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'سعر توصيل الزون وقت الإنشاء (snapshot)',
  `order_price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'عهدة الطرد — المبلغ المطلوب تحصيله من المستلم',
  `address` varchar(190) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending_pickup' COMMENT 'حالة الطرد — أكواد إنجليزية (انظر التعليق فوق العمود)',
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `geo_src` varchar(20) DEFAULT NULL COMMENT 'مصدر إحداثيات المستلم: gps/map/geocode/manual',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order_deliveries_legacy` (`legacy_key`),
  KEY `idx_order_deliveries_order` (`order_id`),
  KEY `idx_order_deliveries_receiver_phone` (`receiver_phone`),
  KEY `idx_order_deliveries_zone` (`zone_id`),
  KEY `idx_order_deliveries_status` (`status`),
  KEY `idx_order_deliveries_receiver` (`receiver_id`),
  CONSTRAINT `fk_order_deliveries_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_deliveries_receiver_id` FOREIGN KEY (`receiver_id`) REFERENCES `receivers` (`id`),
  CONSTRAINT `fk_order_deliveries_zone_id` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2284 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='طرود الأوردر — صف لكل مستلم/طرد داخل الأوردر';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `delivery_id` bigint(20) unsigned NOT NULL COMMENT 'الطرد اللي الصورة تابعة له',
  `url` varchar(500) NOT NULL COMMENT 'رابط الصورة (مضغوطة قبل الرفع)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order_images_delivery` (`delivery_id`),
  CONSTRAINT `fk_order_images_delivery_id` FOREIGN KEY (`delivery_id`) REFERENCES `order_deliveries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='صور الطرود — فواتير المحل أو إثبات التسليم';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL COMMENT 'الأوردر اللي الرسالة بتخصّه',
  `channel` varchar(20) NOT NULL DEFAULT 'whatsapp' COMMENT 'قناة الإرسال: whatsapp (الوحيدة دلوقتي)',
  `recipient_phone` varchar(20) NOT NULL COMMENT 'رقم المستلم بعد التطبيع — فاضي يعني مفيش رقم (جزء من مفتاح منع التكرار)',
  `body` text NOT NULL COMMENT 'نص الرسالة كما اتبنى وقت الإنشاء (snapshot — بيتبعت زي ما هو)',
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'حالة الرسالة: pending/sent/failed/skipped',
  `provider` varchar(20) DEFAULT NULL COMMENT 'المزوّد اللي اتعامل مع الصف: manual/cloud_api',
  `provider_message_id` varchar(190) DEFAULT NULL COMMENT 'معرّف الرسالة عند المزوّد (wamid في Meta) — للمطابقة مع تقارير التسليم',
  `error` varchar(190) DEFAULT NULL COMMENT 'سبب الفشل أو سبب التخطّي — بيتكتب مع failed/skipped',
  `attempts` int(11) NOT NULL DEFAULT 0 COMMENT 'عدد محاولات الإرسال الفعلية',
  `sent_by` varchar(190) DEFAULT NULL COMMENT 'مين بعت: اسم الموظف في الوضع اليدوي، NULL في التلقائي',
  `sent_at` datetime DEFAULT NULL COMMENT 'وقت الإرسال — NULL يعني لسه ما اتبعتتش',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_notifications_target` (`order_id`,`channel`,`recipient_phone`),
  KEY `idx_order_notifications_status` (`status`,`created_at`),
  KEY `idx_order_notifications_created` (`created_at`),
  CONSTRAINT `fk_order_notifications_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رسايل إشعار المستلمين بالأوردر — صف واحد لكل مستلم لكل قناة';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_ratings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `rater` varchar(20) NOT NULL COMMENT 'مين قيّم (انظر التعليق فوق العمود)',
  `stars` tinyint(4) NOT NULL COMMENT 'النجوم 1-5',
  `note` text DEFAULT NULL,
  `action` varchar(20) DEFAULT NULL COMMENT 'إجراء المتابعة — NULL يعني مفيش إجراء',
  `rated_by` varchar(190) DEFAULT NULL COMMENT 'اسم صاحب التقييم (snapshot)',
  `rated_by_id` varchar(100) DEFAULT NULL COMMENT 'معرّف صاحب التقييم (legacy أو id نصي)',
  `rated_at` datetime DEFAULT NULL COMMENT 'وقت التقييم',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order_ratings_order` (`order_id`),
  KEY `idx_order_ratings_rater` (`rater`),
  KEY `idx_order_ratings_stars` (`stars`),
  CONSTRAINT `fk_order_ratings_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تقييمات الأوردرات من العميل/المحل/خدمة العملاء';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `from_pilot_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الطيار المنقول منه — NULL لو أول إسناد',
  `to_pilot_id` bigint(20) unsigned NOT NULL COMMENT 'الطيار المنقول إليه',
  `from_shift_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ÙˆØ±Ø¯ÙŠØ© Ø§Ù„Ø·ÙŠØ§Ø± Ø§Ù„Ù…Ù†Ù‚ÙˆÙ„ Ù…Ù†Ù‡ ÙˆÙ‚Øª Ø§Ù„Ù†Ù‚Ù„',
  `to_shift_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ÙˆØ±Ø¯ÙŠØ© Ø§Ù„Ø·ÙŠØ§Ø± Ø§Ù„Ù…Ù†Ù‚ÙˆÙ„ Ø¥Ù„ÙŠÙ‡ ÙˆÙ‚Øª Ø§Ù„Ù†Ù‚Ù„ â€” NULL Ù„Ùˆ Ù…Ù„ÙˆØ´ ÙˆØ±Ø¯ÙŠØ© Ù…ÙØªÙˆØ­Ø©',
  `transferred_at` datetime NOT NULL DEFAULT current_timestamp(),
  `transferred_by` varchar(190) DEFAULT NULL COMMENT 'مين نفّذ النقل (اسم/معرّف مستخدم الفرع أو الإدارة)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order_transfers_order` (`order_id`),
  KEY `idx_order_transfers_from_pilot` (`from_pilot_id`),
  KEY `idx_order_transfers_to_pilot` (`to_pilot_id`),
  KEY `idx_order_transfers_at` (`transferred_at`),
  CONSTRAINT `fk_order_transfers_from_pilot_id` FOREIGN KEY (`from_pilot_id`) REFERENCES `pilots` (`id`),
  CONSTRAINT `fk_order_transfers_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_transfers_to_pilot_id` FOREIGN KEY (`to_pilot_id`) REFERENCES `pilots` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل نقل الأوردرات بين الطيارين — لحساب الوقت التراكمي وبصمة الوردية';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `order_num` varchar(30) NOT NULL COMMENT 'رقم الأوردر — ترقيم يومي بالفرع (من order_counters)',
  `branch_id` bigint(20) unsigned NOT NULL COMMENT 'الفرع المسؤول عن الأوردر',
  `sender_id` bigint(20) unsigned DEFAULT NULL COMMENT 'مرجع المُرسِل المحفوظ في جدول senders لو معروف',
  `sender_name` varchar(190) DEFAULT NULL COMMENT 'اسم المُرسِل (snapshot)',
  `sender_phone` varchar(20) DEFAULT NULL,
  `sender_phone2` varchar(20) DEFAULT NULL,
  `sender_address` varchar(190) DEFAULT NULL,
  `sender_zone_id` bigint(20) unsigned DEFAULT NULL COMMENT 'زون المُرسِل (بيحدد الفرع في تطبيق العميل)',
  `sender_lat` decimal(10,7) DEFAULT NULL,
  `sender_lng` decimal(10,7) DEFAULT NULL,
  `geo_src` varchar(20) DEFAULT NULL COMMENT 'مصدر إحداثيات المُرسِل: gps/map/geocode/manual',
  `status` varchar(32) NOT NULL DEFAULT 'pending_pickup' COMMENT 'حالة الأوردر — أكواد إنجليزية (انظر التعليق فوق العمود)',
  `status_since` datetime DEFAULT NULL COMMENT 'وقت آخر تغيير للحالة',
  `prev_status` varchar(32) DEFAULT NULL COMMENT 'الحالة السابقة (بترجع لها عند فك التأجيل/الإلغاء)',
  `pilot_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الطيار الحالي المسند له الأوردر',
  `pilot_name` varchar(190) DEFAULT NULL COMMENT 'اسم الطيار (snapshot)',
  `shift_id` bigint(20) unsigned DEFAULT NULL COMMENT 'وردية الطيار وقت الإسناد',
  `current_pilot_since` datetime DEFAULT NULL COMMENT 'من إمتى الأوردر مع الطيار الحالي — لحساب الوقت التراكمي عند النقل',
  `transfer_count` int(11) NOT NULL DEFAULT 0 COMMENT 'عدد مرات نقل الأوردر بين الطيارين',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3) COMMENT 'Ø¢Ø®Ø± ØªØ¹Ø¯ÙŠÙ„ â€” Ø£Ø³Ø§Ø³ delta polling (?since)',
  `received_at` datetime DEFAULT NULL COMMENT 'وقت استلام الطيار للطرود من المُرسِل',
  `trip_started_at` datetime DEFAULT NULL COMMENT 'وقت بدء رحلة التوصيل',
  `delivered_at` datetime DEFAULT NULL,
  `undelivered_at` datetime DEFAULT NULL,
  `undelivered_reason` varchar(190) DEFAULT NULL COMMENT 'سبب عدم التسليم',
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` varchar(190) DEFAULT NULL COMMENT 'مين ألغى الأوردر (اسم/معرّف المستخدم)',
  `cancelled_reason` varchar(190) DEFAULT NULL,
  `total_delivery_price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي سعر التوصيل (مجموع أسعار الزونات)',
  `store_prepaid` decimal(12,2) DEFAULT NULL COMMENT 'المحل دافع مقدم — NULL يعني مفيش دفع مقدم',
  `store_prepaid_note` varchar(190) DEFAULT NULL,
  `wallet_used` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'المخصوم من محفظة العميل',
  `net_delivery_price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'صافي سعر التوصيل بعد المحفظة/المدفوع مقدمًا',
  `goods_value` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'قيمة البضاعة (عهدة إجمالية)',
  `money_settled` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'هل اتقفلت فلوس الأوردر مع الطيار/الفرع',
  `payment_method` varchar(20) DEFAULT NULL COMMENT 'طريقة الدفع (انظر التعليق فوق العمود)',
  `split_from_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الأوردر الأصلي لو ده اتفرّق منه',
  `return_status` varchar(20) DEFAULT NULL COMMENT 'حالة طلب إرجاع الأوردر — NULL يعني مفيش طلب إرجاع',
  `return_reason` varchar(190) DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'branch' COMMENT 'مصدر إنشاء الأوردر (انظر التعليق فوق العمود)',
  `added_by` varchar(190) DEFAULT NULL COMMENT 'اسم/معرّف اللي أضاف الأوردر',
  `added_by_role` varchar(32) DEFAULT NULL COMMENT 'دور اللي أضاف: admin/branch/store/customer/callcenter',
  `customer_id` bigint(20) unsigned DEFAULT NULL COMMENT 'عميل تطبيق العميل لو الأوردر جاي منه',
  `customer_name` varchar(190) DEFAULT NULL COMMENT 'اسم العميل (snapshot)',
  `customer_phone` varchar(20) DEFAULT NULL COMMENT 'تليفون العميل (snapshot)',
  `order_kind` varchar(20) DEFAULT NULL COMMENT 'نوع الأوردر (انظر التعليق فوق العمود)',
  `pieces_count` int(11) NOT NULL DEFAULT 1 COMMENT 'عدد القطع الإجمالي',
  `qr_code` varchar(64) DEFAULT NULL COMMENT 'كود QR للتحقق عند التسليم',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_order_num` (`order_num`),
  KEY `idx_orders_legacy` (`legacy_key`),
  KEY `idx_orders_branch_status` (`branch_id`,`status`),
  KEY `idx_orders_pilot_status` (`pilot_id`,`status`),
  KEY `idx_orders_customer` (`customer_id`),
  KEY `idx_orders_created` (`created_at`),
  KEY `idx_orders_sender_phone` (`sender_phone`),
  KEY `idx_orders_customer_phone` (`customer_phone`),
  KEY `idx_orders_shift` (`shift_id`),
  KEY `idx_orders_sender` (`sender_id`),
  KEY `idx_orders_return_status` (`return_status`),
  KEY `idx_orders_qr` (`qr_code`),
  KEY `fk_orders_sender_zone_id` (`sender_zone_id`),
  KEY `fk_orders_split_from_id` (`split_from_id`),
  KEY `idx_orders_updated` (`updated_at`),
  KEY `idx_orders_added_by_created` (`added_by`,`created_at`),
  KEY `idx_orders_added_by_updated` (`added_by`,`updated_at`),
  CONSTRAINT `fk_orders_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_orders_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_orders_pilot_id` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`),
  CONSTRAINT `fk_orders_sender_id` FOREIGN KEY (`sender_id`) REFERENCES `senders` (`id`),
  CONSTRAINT `fk_orders_sender_zone_id` FOREIGN KEY (`sender_zone_id`) REFERENCES `zones` (`id`),
  CONSTRAINT `fk_orders_shift_id` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`),
  CONSTRAINT `fk_orders_split_from_id` FOREIGN KEY (`split_from_id`) REFERENCES `orders` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=526545 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الأوردرات — قلب النظام: أوردر واحد ممكن يضم أكتر من طرد (order_deliveries)';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `party_identities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subject_phone` varchar(20) NOT NULL COMMENT 'Ïº┘äÏ▒┘é┘à Ï¿Ï╣Ï» Ïº┘äÏ¬ÏÀÏ¿┘èÏ╣',
  `canonical_name` varchar(190) DEFAULT NULL COMMENT 'Ïº┘äÏºÏ│┘à Ïº┘äÏ▒Ï│┘à┘è Ïº┘ä┘àÏ╣Ï¬┘àÏ»',
  `canonical_address` varchar(190) DEFAULT NULL COMMENT 'Ïº┘äÏ╣┘å┘êÏº┘å Ïº┘ä┘àÏ╣Ï¬┘àÏ»',
  `verified_by` varchar(190) DEFAULT NULL COMMENT 'Ïº┘ä┘à┘êÏ©┘ü Ïº┘ä┘ä┘è ┘êÏ½┘æ┘é/ÏÁÏ¡┘æÏ¡ Ïº┘äÏºÏ│┘à',
  `verified_at` datetime DEFAULT NULL COMMENT '┘ê┘éÏ¬ Ïº┘äÏ¬┘êÏ½┘è┘é ÔÇö ┘êÏ¼┘êÏ»┘ç ┘àÏ╣┘åÏº┘ç Ïº┘äÏºÏ│┘à ┘àÏÁÏ¡┘æÏ¡ ┘èÏ»┘ê┘è┘ïÏº',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_party_identities_phone` (`subject_phone`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ïº┘äÏºÏ│┘à/Ïº┘äÏ╣┘å┘êÏº┘å Ïº┘ä┘àÏ╣Ï¬┘àÏ» ┘ä┘â┘ä Ï▒┘é┘à Ï¬┘ä┘è┘ü┘ê┘å';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `party_ratings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subject_phone` varchar(20) NOT NULL COMMENT 'Ï▒┘é┘à Ïº┘ä┘à┘Å┘é┘è┘Ä┘æ┘à Ï¿Ï╣Ï» Ïº┘äÏ¬ÏÀÏ¿┘èÏ╣ ÔÇö Ïº┘ä┘à┘üÏ¬ÏºÏ¡ Ïº┘ä┘à┘êÏ¡┘æÏ»',
  `subject_customer_id` bigint(20) unsigned DEFAULT NULL COMMENT '┘ä┘ê Ïº┘ä┘à┘Å┘é┘è┘Ä┘æ┘à Ï╣┘à┘è┘ä ┘àÏ│Ï¼┘æ┘ä ┘ü┘è Ïº┘äÏ¬ÏÀÏ¿┘è┘é',
  `subject_user_id` bigint(20) unsigned DEFAULT NULL COMMENT '┘ä┘ê Ïº┘ä┘à┘Å┘é┘è┘Ä┘æ┘à ÏÁÏºÏ¡Ï¿ ┘àÏ¡┘ä (Ï¡Ï│ÏºÏ¿ users)',
  `order_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Ïº┘äÏú┘êÏ▒Ï»Ï▒ Ïº┘ä┘ä┘è Ïº┘äÏ¬┘é┘è┘è┘à ÏºÏ¬Ï╣┘à┘ä Ï╣┘ä┘è┘ç ÔÇö NULL ┘äÏ¬┘é┘è┘è┘à Ïº┘äÏÑÏ»ÏºÏ▒Ï® Ïº┘ä┘àÏ¿ÏºÏ┤Ï▒',
  `rater_type` varchar(20) NOT NULL COMMENT '┘å┘êÏ╣ Ïº┘ä┘à┘Å┘é┘è┘É┘æ┘à',
  `rater_name` varchar(190) DEFAULT NULL COMMENT 'ÏºÏ│┘à Ïº┘ä┘à┘Å┘é┘è┘É┘æ┘à ┘ê┘éÏ¬ Ïº┘äÏ¬┘é┘è┘è┘à (┘ä┘éÏÀÏ® Ï¬ÏºÏ▒┘èÏ«┘èÏ®)',
  `rater_user_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Ï¡Ï│ÏºÏ¿ Ïº┘ä┘à┘êÏ©┘ü/Ïº┘ä┘àÏ¡┘ä Ïº┘ä┘à┘Å┘é┘è┘É┘æ┘à',
  `rater_customer_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Ï╣┘à┘è┘ä Ïº┘äÏ¬ÏÀÏ¿┘è┘é Ïº┘ä┘à┘Å┘é┘è┘É┘æ┘à',
  `stars` tinyint(4) NOT NULL COMMENT '┘à┘å 1 ┘ä┘Ç 5',
  `note` text DEFAULT NULL COMMENT '┘à┘äÏºÏ¡Ï©Ï® Ï¡Ï▒Ï® ┘à┘å Ïº┘ä┘à┘Å┘é┘è┘É┘æ┘à',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `rater_uid` varchar(48) GENERATED ALWAYS AS (concat(coalesce(`rater_user_id`,0),':',coalesce(`rater_customer_id`,0))) VIRTUAL COMMENT '┘ç┘ê┘èÏ® Ïº┘ä┘à┘Å┘é┘è┘É┘æ┘à Ïº┘ä┘àÏ»┘àÏ¼Ï® ÔÇö ÏúÏ│ÏºÏ│ ┘à┘åÏ╣ Ïº┘äÏ¬┘é┘è┘è┘à Ïº┘ä┘à┘âÏ▒Ï▒',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_party_ratings_once` (`order_id`,`rater_type`,`rater_uid`),
  KEY `idx_party_ratings_phone` (`subject_phone`),
  KEY `idx_party_ratings_order_rater` (`order_id`,`rater_type`),
  KEY `idx_party_ratings_created` (`created_at`),
  KEY `fk_party_ratings_subject_customer` (`subject_customer_id`),
  KEY `fk_party_ratings_subject_user` (`subject_user_id`),
  KEY `fk_party_ratings_rater_user` (`rater_user_id`),
  KEY `fk_party_ratings_rater_customer` (`rater_customer_id`),
  CONSTRAINT `fk_party_ratings_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_party_ratings_rater_customer_id` FOREIGN KEY (`rater_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_party_ratings_rater_user_id` FOREIGN KEY (`rater_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_party_ratings_subject_customer_id` FOREIGN KEY (`subject_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_party_ratings_subject_user_id` FOREIGN KEY (`subject_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ï¬┘é┘è┘è┘àÏºÏ¬ Ïº┘äÏúÏÀÏ▒Ïº┘ü ÔÇö Ïº┘äÏ¼Ï▓Ïí Ïº┘ä┘èÏ»┘ê┘è ┘à┘å Ï»Ï▒Ï¼Ï® Ïº┘ä┘àÏÁÏ»Ïº┘é┘èÏ®';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pilot_join_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `name` varchar(190) NOT NULL COMMENT 'اسم المتقدم',
  `phones` varchar(190) DEFAULT NULL COMMENT 'أرقام تليفونات المتقدم (ممكن أكتر من رقم مفصولين)',
  `card_num` varchar(20) DEFAULT NULL COMMENT 'الرقم القومي',
  `vehicle_no` varchar(32) DEFAULT NULL COMMENT 'رقم المركبة/اللوحة',
  `address` varchar(190) DEFAULT NULL COMMENT 'عنوان السكن',
  `branch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الفرع المطلوب الانضمام له',
  `requested_by` varchar(190) DEFAULT NULL COMMENT 'من سجّل الطلب (اسم/معرّف المستخدم)',
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'حالة الطلب: pending/approved/rejected',
  `pilot_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الطيار اللي اتعمل بعد الموافقة',
  `source` varchar(20) DEFAULT NULL COMMENT 'مصدر الطلب: branch/admin/self',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pilot_join_requests_legacy` (`legacy_key`),
  KEY `idx_pilot_join_requests_status` (`status`),
  KEY `idx_pilot_join_requests_branch` (`branch_id`,`status`),
  KEY `idx_pilot_join_requests_phones` (`phones`),
  KEY `fk_pilot_join_requests_pilot_id` (`pilot_id`),
  CONSTRAINT `fk_pilot_join_requests_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_pilot_join_requests_pilot_id` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='طلبات انضمام طيارين جدد قبل إنشاء حساب الطيار';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pilot_leave_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `pilot_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL COMMENT 'فرع الطيار وقت الطلب',
  `type` varchar(20) NOT NULL COMMENT 'نوع الإجازة: rest/dayoff/incident',
  `reason` text DEFAULT NULL COMMENT 'سبب الطلب',
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'حالة الطلب: pending/approved/rejected/ended',
  `requested_at` datetime NOT NULL COMMENT 'وقت تقديم الطلب (UTC)',
  `responded_at` datetime DEFAULT NULL COMMENT 'وقت الرد بالموافقة/الرفض (UTC)',
  `responded_by` varchar(190) DEFAULT NULL COMMENT 'من رد على الطلب',
  `ended_at` datetime DEFAULT NULL COMMENT 'وقت انتهاء الإجازة ورجوع الطيار (UTC)',
  `ended_by` varchar(190) DEFAULT NULL COMMENT 'من أنهى الإجازة',
  `forced_by` varchar(190) DEFAULT NULL COMMENT 'لو الإيقاف إجباري من الإدارة/الفرع: من نفّذه (NULL = طلب عادي من الطيار)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pilot_leave_requests_legacy` (`legacy_key`),
  KEY `idx_pilot_leave_requests_pilot_status` (`pilot_id`,`status`),
  KEY `idx_pilot_leave_requests_branch_status` (`branch_id`,`status`),
  KEY `idx_pilot_leave_requests_type` (`type`),
  CONSTRAINT `fk_pilot_leave_requests_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_pilot_leave_requests_pilot_id` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='طلبات راحة/إجازة/حادث للطيارين + الإيقاف الإجباري (forced_by)';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pilot_monthly_closeouts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `pilot_id` bigint(20) unsigned NOT NULL,
  `month` char(7) NOT NULL COMMENT 'الشهر بصيغة YYYY-MM',
  `work_days` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'عدد أيام الشغل الفعلية',
  `hours` decimal(7,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي ساعات الشغل في الشهر',
  `delivered_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'عدد الأوردرات المُسلّمة',
  `commission` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي العمولة (المرحّلة monthly)',
  `bonus` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي الحوافز',
  `deductions` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي الخصومات',
  `advances` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي السلف',
  `salary` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'المرتب الأساسي للشهر',
  `required_daily_hours` decimal(7,2) NOT NULL DEFAULT 0.00 COMMENT 'ساعات الشغل المطلوبة يوميًا وقت التقفيلة',
  `paid_leave_days` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'أيام إجازة مدفوعة',
  `unpaid_leave_days` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'أيام إجازة غير مدفوعة (بتتخصم)',
  `daily_rate` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'قيمة اليوم الواحد من المرتب',
  `net_due` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'صافي المستحق للطيار بعد كل الحسابات',
  `closed_at` datetime DEFAULT NULL COMMENT 'وقت إقفال التقفيلة (UTC)',
  `closed_by` varchar(190) DEFAULT NULL COMMENT 'من أقفل التقفيلة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pilot_monthly_closeouts_pilot_month` (`pilot_id`,`month`),
  KEY `idx_pilot_monthly_closeouts_legacy` (`legacy_key`),
  KEY `idx_pilot_monthly_closeouts_month` (`month`),
  CONSTRAINT `fk_pilot_monthly_closeouts_pilot_id` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='التقفيلة الشهرية للطيار — لقطة نهائية للساعات والعمولة والمرتب وصافي المستحق';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pilot_return_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `order_id` bigint(20) unsigned NOT NULL COMMENT 'الأوردر المطلوب إرجاعه',
  `order_num` varchar(32) NOT NULL COMMENT 'رقم الأوردر (نسخة للعرض والبحث السريع)',
  `pilot_id` bigint(20) unsigned NOT NULL COMMENT 'الطيار طالب الإرجاع',
  `branch_id` bigint(20) unsigned NOT NULL COMMENT 'فرع الأوردر',
  `reason` text DEFAULT NULL COMMENT 'سبب طلب الإرجاع',
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'حالة الطلب: pending/approved/rejected',
  `requested_at` datetime NOT NULL COMMENT 'وقت الطلب (UTC)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pilot_return_requests_legacy` (`legacy_key`),
  KEY `idx_pilot_return_requests_order` (`order_id`),
  KEY `idx_pilot_return_requests_order_num` (`order_num`),
  KEY `idx_pilot_return_requests_pilot_status` (`pilot_id`,`status`),
  KEY `idx_pilot_return_requests_branch_status` (`branch_id`,`status`),
  CONSTRAINT `fk_pilot_return_requests_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_pilot_return_requests_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `fk_pilot_return_requests_pilot_id` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='طلبات إرجاع الأوردر بإذن من الفرع/الإدارة';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pilot_shift_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `pilot_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL COMMENT 'الفرع المطلوب فتح الوردية عليه',
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'حالة الطلب: pending/approved/rejected',
  `requested_at` datetime NOT NULL COMMENT 'وقت الطلب (UTC)',
  `responded_at` datetime DEFAULT NULL COMMENT 'وقت الرد (UTC)',
  `responded_by` varchar(190) DEFAULT NULL COMMENT 'من رد على الطلب',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pilot_shift_requests_legacy` (`legacy_key`),
  KEY `idx_pilot_shift_requests_pilot_status` (`pilot_id`,`status`),
  KEY `idx_pilot_shift_requests_branch_status` (`branch_id`,`status`),
  CONSTRAINT `fk_pilot_shift_requests_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_pilot_shift_requests_pilot_id` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='طلبات الطيارين لفتح وردية — الفرع/الإدارة بيوافق أو يرفض';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pilot_support_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `requesting_branch_id` bigint(20) unsigned NOT NULL COMMENT 'الفرع اللي طالب الدعم',
  `from_branch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'فرع محدد مطلوب منه الدعم (NULL = broadcast لكل الفروع)',
  `pilot_id` bigint(20) unsigned DEFAULT NULL COMMENT 'طيار محدد مطلوب دعمه (NULL = أي طيار متاح)',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات الطلب',
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'حالة الطلب: pending/accepted/rejected/cancelled/ended',
  `accepted_by_branch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الفرع اللي قبل يبعت الدعم',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pilot_support_requests_legacy` (`legacy_key`),
  KEY `idx_pilot_support_requests_requesting` (`requesting_branch_id`,`status`),
  KEY `idx_pilot_support_requests_status` (`status`),
  KEY `idx_pilot_support_requests_pilot` (`pilot_id`),
  KEY `fk_pilot_support_requests_from_branch_id` (`from_branch_id`),
  KEY `fk_pilot_support_requests_accepted_by_branch_id` (`accepted_by_branch_id`),
  CONSTRAINT `fk_pilot_support_requests_accepted_by_branch_id` FOREIGN KEY (`accepted_by_branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_pilot_support_requests_from_branch_id` FOREIGN KEY (`from_branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_pilot_support_requests_pilot_id` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`),
  CONSTRAINT `fk_pilot_support_requests_requesting_branch_id` FOREIGN KEY (`requesting_branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='طلبات دعم طيارين بين الفروع — broadcast أو موجّه لفرع/طيار محدد';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pilot_support_responses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `request_id` bigint(20) unsigned NOT NULL COMMENT 'طلب الدعم الأم',
  `branch_id` bigint(20) unsigned NOT NULL COMMENT 'الفرع اللي رد',
  `response` varchar(20) NOT NULL COMMENT 'رد الفرع: accepted/rejected',
  `responded_at` datetime NOT NULL COMMENT 'وقت الرد (UTC)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pilot_support_responses_legacy` (`legacy_key`),
  KEY `idx_pilot_support_responses_request` (`request_id`),
  KEY `idx_pilot_support_responses_branch` (`branch_id`),
  CONSTRAINT `fk_pilot_support_responses_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_pilot_support_responses_request_id` FOREIGN KEY (`request_id`) REFERENCES `pilot_support_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ردود الفروع على طلبات الدعم (صفوف أبناء خالصة لطلب الدعم)';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pilot_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `pilot_id` bigint(20) unsigned NOT NULL,
  `from_branch_id` bigint(20) unsigned NOT NULL COMMENT 'الفرع المنقول منه',
  `to_branch_id` bigint(20) unsigned NOT NULL COMMENT 'الفرع المنقول له',
  `type` varchar(20) NOT NULL DEFAULT 'temp' COMMENT 'نوع النقل: temp/permanent',
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'حالة النقل: pending/approved/rejected/ended',
  `requested_at` datetime NOT NULL COMMENT 'وقت طلب النقل (UTC)',
  `resolved_at` datetime DEFAULT NULL COMMENT 'وقت البت في الطلب أو إنهاء النقل (UTC)',
  `resolved_by` varchar(190) DEFAULT NULL COMMENT 'من بتّ في الطلب',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pilot_transfers_legacy` (`legacy_key`),
  KEY `idx_pilot_transfers_pilot_status` (`pilot_id`,`status`),
  KEY `idx_pilot_transfers_from_branch` (`from_branch_id`,`status`),
  KEY `idx_pilot_transfers_to_branch` (`to_branch_id`,`status`),
  CONSTRAINT `fk_pilot_transfers_from_branch_id` FOREIGN KEY (`from_branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_pilot_transfers_pilot_id` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`),
  CONSTRAINT `fk_pilot_transfers_to_branch_id` FOREIGN KEY (`to_branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='نقل الطيارين بين الفروع — مؤقت أو دائم مع تتبع البت في الطلب';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pilots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL COMMENT 'اسم الطيار',
  `phone1` varchar(20) DEFAULT NULL COMMENT 'التليفون الأساسي',
  `phone2` varchar(20) DEFAULT NULL COMMENT 'تليفون إضافي',
  `card_num` varchar(20) DEFAULT NULL COMMENT 'الرقم القومي',
  `vehicle_no` varchar(20) DEFAULT NULL COMMENT 'رقم لوحة المركبة',
  `address` varchar(190) DEFAULT NULL,
  `assigned_branch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الفرع اللي الطيار تابع له',
  `status` varchar(20) DEFAULT NULL COMMENT 'حالة الطيار الحالية',
  `queue_no` int(11) DEFAULT NULL COMMENT 'ترتيب الطيار في دور الانتظار',
  `status_since` datetime DEFAULT NULL COMMENT 'من إمتى الحالة الحالية',
  `commission_type` varchar(20) DEFAULT NULL COMMENT 'نوع العمولة',
  `commission_value` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'قيمة العمولة حسب النوع',
  `custody_balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'رصيد العهدة (فلوس مع الطيار للشركة)',
  `monthly_salary` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'المرتب الشهري',
  `required_daily_hours` decimal(4,1) DEFAULT NULL COMMENT 'ساعات العمل اليومية المطلوبة',
  `leave_type` varchar(20) DEFAULT NULL COMMENT 'نوع الإجازة الحالية لو on_leave',
  `leave_reason` varchar(190) DEFAULT NULL COMMENT 'سبب الإجازة',
  `leave_forced` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'الإجازة إجبارية من الإدارة/الفرع',
  `break_started_at` datetime DEFAULT NULL COMMENT 'بداية الراحة الحالية (لخصم الراحة من الساعات)',
  `lat` decimal(10,7) DEFAULT NULL COMMENT 'آخر موقع معروف',
  `lng` decimal(10,7) DEFAULT NULL,
  `location_updated_at` datetime DEFAULT NULL COMMENT 'آخر تحديث للموقع',
  `app_version` varchar(20) DEFAULT NULL COMMENT 'إصدار تطبيق الطيار المثبت',
  `app_version_at` datetime DEFAULT NULL COMMENT 'آخر إبلاغ عن الإصدار',
  `notes` text DEFAULT NULL,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pilots_legacy` (`legacy_key`),
  KEY `idx_pilots_phone1` (`phone1`),
  KEY `idx_pilots_branch_status` (`assigned_branch_id`,`status`),
  KEY `idx_pilots_status` (`status`),
  CONSTRAINT `fk_pilots_assigned_branch_id` FOREIGN KEY (`assigned_branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=139 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الطيارين: البيانات والحالة والعمولة والعهدة والموقع';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rd_branches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `name` varchar(190) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rd_branches_legacy` (`legacy_key`),
  KEY `idx_rd_branches_active` (`active`)
) ENGINE=InnoDB AUTO_INCREMENT=87 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فروع مطعم روح دمشق';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rd_deferred_advances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `pilot_id` bigint(20) unsigned NOT NULL,
  `advance_date` date DEFAULT NULL COMMENT 'تاريخ أخذ السلفة',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي السلفة',
  `monthly` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'القسط الشهري الافتراضي',
  `start_month` char(7) NOT NULL COMMENT 'أول شهر خصم YYYY-MM',
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rd_deferred_advances_legacy` (`legacy_key`),
  KEY `idx_rd_deferred_advances_pilot` (`pilot_id`),
  KEY `idx_rd_deferred_advances_start` (`start_month`),
  CONSTRAINT `fk_rd_deferred_advances_pilot_id` FOREIGN KEY (`pilot_id`) REFERENCES `rd_pilots` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سلف مؤجلة بتقسيط شهري — الرصيد المتبقي محسوب من المدفوعات';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rd_deferred_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `advance_id` bigint(20) unsigned NOT NULL,
  `month` char(7) NOT NULL COMMENT 'YYYY-MM',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'القسط المخصوم فعليًا في الشهر ده (override للقسط الافتراضي)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rd_deferred_payments_adv_month` (`advance_id`,`month`),
  KEY `idx_rd_deferred_payments_month` (`month`),
  CONSTRAINT `fk_rd_deferred_payments_advance_id` FOREIGN KEY (`advance_id`) REFERENCES `rd_deferred_advances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قسط السلفة لكل شهر — أبناء خالصين للسلفة (بديل مفاتيح paid{ym})';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rd_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `month` char(7) NOT NULL COMMENT 'YYYY-MM',
  `pilot_id` bigint(20) unsigned NOT NULL,
  `day` tinyint(4) NOT NULL COMMENT 'يوم الشهر 1..31 (اليوم التجاري يبدأ 8ص)',
  `time_in` varchar(8) DEFAULT NULL COMMENT 'وقت الحضور HH:MM (نص زي Firebase)',
  `time_out` varchar(8) DEFAULT NULL COMMENT 'وقت الانصراف HH:MM',
  `hours` decimal(5,2) DEFAULT NULL COMMENT 'ساعات override يدوي — NULL = تتحسب من الأوقات وفترات الاستئذان',
  `orders_count` int(11) DEFAULT NULL COMMENT 'عدد الأوردرات',
  `svc` decimal(12,2) DEFAULT NULL COMMENT 'خدمة اليوم (إيراد الخدمة المكتوب في الشيت)',
  `psvc_override` decimal(12,2) DEFAULT NULL COMMENT 'خدمة الطيار override — NULL = أوردرات × سعر الأوردر بتاعه',
  `net_override` decimal(12,2) DEFAULT NULL COMMENT 'الصافي override — NULL = محسوب لايف',
  `advance` decimal(12,2) DEFAULT NULL COMMENT 'سلفة اليوم',
  `deduction` decimal(12,2) DEFAULT NULL COMMENT 'خصم اليوم',
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rd_entries_month_pilot_day` (`month`,`pilot_id`,`day`),
  KEY `idx_rd_entries_legacy` (`legacy_key`),
  KEY `idx_rd_entries_pilot_month` (`pilot_id`,`month`),
  KEY `idx_rd_entries_month` (`month`),
  CONSTRAINT `fk_rd_entries_pilot_id` FOREIGN KEY (`pilot_id`) REFERENCES `rd_pilots` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=207 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الشيت اليومي لروح دمشق: صف لكل طيار/يوم — الحسابات derived والـoverrides اختيارية';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rd_entry_perms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `entry_id` bigint(20) unsigned NOT NULL,
  `perm_out` varchar(8) DEFAULT NULL COMMENT 'وقت الخروج للاستئذان HH:MM',
  `perm_in` varchar(8) DEFAULT NULL COMMENT 'وقت العودة من الاستئذان HH:MM',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rd_entry_perms_entry` (`entry_id`),
  CONSTRAINT `fk_rd_entry_perms_entry_id` FOREIGN KEY (`entry_id`) REFERENCES `rd_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=69 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فترات الاستئذان التابعة لصف الشيت اليومي — أبناء خالصين للـentry';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rd_month_locks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `month` char(7) NOT NULL COMMENT 'YYYY-MM',
  `branch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'NULL = قفل الشهر لكل الفروع',
  `locked_at` datetime NOT NULL DEFAULT current_timestamp(),
  `locked_by` varchar(100) DEFAULT NULL COMMENT 'اسم المستخدم اللي قفل الشهر',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rd_month_locks_month_branch` (`month`,`branch_id`),
  KEY `idx_rd_month_locks_month` (`month`),
  KEY `fk_rd_month_locks_branch_id` (`branch_id`),
  CONSTRAINT `fk_rd_month_locks_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `rd_branches` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تقفيل الشهر المالي: بعد القفل الشيت والملخصات بيبقوا قراءة فقط';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rd_perms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL COMMENT 'اسم المستخدم (بيتطابق مع users.username في النظام الأم)',
  `perm_keys` longtext DEFAULT NULL COMMENT 'JSON: مفاتيح الصلاحيات granular زي col.psvc / blk.pct / صفوف',
  `branches` varchar(190) DEFAULT NULL COMMENT 'فروع روح دمشق المسموحة (قائمة ids مفصولة بفواصل، فاضي = الكل)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rd_perms_username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='صلاحيات روح دمشق لكل مستخدم: مفاتيح JSON على مستوى الأعمدة والسطور';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rd_pilots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `branch_id` bigint(20) unsigned NOT NULL COMMENT 'فرع روح دمشق التابع له',
  `name` varchar(190) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `job` varchar(30) DEFAULT NULL COMMENT 'الوظيفة (طيار/مشرف...) نص حر قصير',
  `hour_rate` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'سعر الساعة',
  `order_rate` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'سعر الأوردر للطيار (خدمة الطيار = أوردرات × سعره)',
  `leave_days` int(11) NOT NULL DEFAULT 0 COMMENT 'أيام الإجازة المدفوعة شهريًا',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rd_pilots_legacy` (`legacy_key`),
  KEY `idx_rd_pilots_branch` (`branch_id`),
  KEY `idx_rd_pilots_active` (`active`),
  CONSTRAINT `fk_rd_pilots_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `rd_branches` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=150 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='طيارين روح دمشق: أسعار الساعة والأوردر وأيام الإجازة';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rd_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rd_settings_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=188 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إعدادات نظام روح دمشق key/value';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rd_summaries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `month` char(7) NOT NULL COMMENT 'YYYY-MM',
  `branch_id` bigint(20) unsigned NOT NULL,
  `day` tinyint(4) NOT NULL COMMENT 'يوم الشهر 1..31',
  `pct` decimal(12,2) DEFAULT NULL COMMENT 'نسبة روح دمشق (نسبة المطعم) المدفوعة من الصافي',
  `ext` decimal(12,2) DEFAULT NULL COMMENT 'الخارجي — مدفوعات لطيارين خارجيين',
  `exp` decimal(12,2) DEFAULT NULL COMMENT 'مصاريف اليوم',
  `recv` decimal(12,2) DEFAULT NULL COMMENT 'المستلم من المشرف فعليًا',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rd_summaries_month_branch_day` (`month`,`branch_id`,`day`),
  KEY `idx_rd_summaries_legacy` (`legacy_key`),
  KEY `idx_rd_summaries_branch_month` (`branch_id`,`month`),
  CONSTRAINT `fk_rd_summaries_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `rd_branches` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ملخص الفرع اليومي: النسبة والخارجي والمصاريف والمستلم — والمفروض يورّده محسوب';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receivers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `phone1` varchar(20) NOT NULL COMMENT 'التليفون الأساسي — مفتاح البحث',
  `phone2` varchar(20) DEFAULT NULL,
  `address` varchar(190) DEFAULT NULL,
  `created_by` varchar(190) DEFAULT NULL COMMENT 'اسم المستخدم اللي أضاف المستلم',
  `source` varchar(32) DEFAULT NULL COMMENT 'مصدر إضافة المستلم',
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'آخر تعديل — بيغذّي فلتر ?since',
  PRIMARY KEY (`id`),
  KEY `idx_receivers_legacy` (`legacy_key`),
  KEY `idx_receivers_phone1` (`phone1`),
  KEY `idx_receivers_phone2` (`phone2`),
  KEY `idx_receivers_created` (`created_at`),
  KEY `idx_receivers_name` (`name`(32)),
  KEY `idx_receivers_updated` (`updated_at`)
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المستلمين المحفوظين';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `senders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `phone1` varchar(20) NOT NULL COMMENT 'التليفون الأساسي — مفتاح البحث',
  `phone2` varchar(20) DEFAULT NULL,
  `address` varchar(190) DEFAULT NULL,
  `created_by` varchar(190) DEFAULT NULL COMMENT 'اسم المستخدم اللي أضاف المرسل',
  `source` varchar(32) DEFAULT NULL COMMENT 'مصدر إضافة المرسل',
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'آخر تعديل — بيغذّي فلتر ?since',
  PRIMARY KEY (`id`),
  KEY `idx_senders_legacy` (`legacy_key`),
  KEY `idx_senders_phone1` (`phone1`),
  KEY `idx_senders_phone2` (`phone2`),
  KEY `idx_senders_created` (`created_at`),
  KEY `idx_senders_name` (`name`(32)),
  KEY `idx_senders_updated` (`updated_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1933 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المرسلين المحفوظين';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shift_branch_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `shift_id` bigint(20) unsigned NOT NULL COMMENT 'الوردية الأم',
  `branch_id` bigint(20) unsigned NOT NULL COMMENT 'الفرع اللي اشتغلت عليه الوردية في الفترة دي',
  `moved_at` datetime NOT NULL COMMENT 'وقت الانتقال للفرع ده (UTC)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shift_branch_history_legacy` (`legacy_key`),
  KEY `idx_shift_branch_history_shift` (`shift_id`),
  KEY `idx_shift_branch_history_branch` (`branch_id`,`moved_at`),
  CONSTRAINT `fk_shift_branch_history_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_shift_branch_history_shift_id` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بصمة تنقل الوردية بين الفروع — لحساب ساعات وأوردرات كل فرع من الوردية';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shifts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `pilot_id` bigint(20) unsigned NOT NULL COMMENT 'الطيار صاحب الوردية',
  `branch_id` bigint(20) unsigned NOT NULL COMMENT 'الفرع الحالي للوردية (بيتغير مع النقل)',
  `status` varchar(20) NOT NULL DEFAULT 'active' COMMENT 'حالة الوردية: active/ended',
  `started_at` datetime NOT NULL COMMENT 'وقت فتح الوردية (UTC)',
  `ended_at` datetime DEFAULT NULL COMMENT 'وقت قفل الوردية (UTC)',
  `ended_by` varchar(190) DEFAULT NULL COMMENT 'من قفل الوردية (اسم/معرّف المستخدم)',
  `opened_by` varchar(190) DEFAULT NULL COMMENT 'من فتح الوردية (اسم/معرّف المستخدم)',
  `opened_manually` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = اتفتحت يدويًا من الإدارة/الفرع مش من طلب الطيار',
  `transferred_at` datetime DEFAULT NULL COMMENT 'آخر وقت اتنقلت فيه الوردية لفرع تاني (UTC)',
  `transferred_by` varchar(190) DEFAULT NULL COMMENT 'من نقل الوردية',
  `bonus_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'حافز الوردية',
  `bonus_reason` varchar(190) DEFAULT NULL COMMENT 'سبب الحافز',
  `deduction_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'خصم الوردية',
  `deduction_reason` varchar(190) DEFAULT NULL COMMENT 'سبب الخصم',
  `advance_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'سلفة الوردية',
  `advance_reason` varchar(190) DEFAULT NULL COMMENT 'سبب السلفة',
  `commission_settle` varchar(10) NOT NULL DEFAULT 'daily' COMMENT 'تسوية العمولة: daily/monthly',
  `bonus_settle` varchar(10) NOT NULL DEFAULT 'daily' COMMENT 'تسوية الحافز: daily/monthly',
  `deduction_settle` varchar(10) NOT NULL DEFAULT 'daily' COMMENT 'تسوية الخصم: daily/monthly',
  `advance_settle` varchar(10) NOT NULL DEFAULT 'daily' COMMENT 'تسوية السلفة: daily/monthly',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shifts_legacy` (`legacy_key`),
  KEY `idx_shifts_pilot_status` (`pilot_id`,`status`),
  KEY `idx_shifts_branch_status` (`branch_id`,`status`),
  KEY `idx_shifts_started_at` (`started_at`),
  CONSTRAINT `fk_shifts_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_shifts_pilot_id` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ورديات الطيارين: الفتح والقفل والحوافز والخصومات والسلف وطريقة تسويتها';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `site_partners` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `name` varchar(190) NOT NULL COMMENT 'اسم الشريك',
  `logo_url` varchar(500) DEFAULT NULL COMMENT 'رابط لوجو الشريك',
  `description` text DEFAULT NULL COMMENT 'وصف الشريك',
  `url` varchar(500) DEFAULT NULL COMMENT 'رابط موقع الشريك',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'ترتيب الظهور في الموقع',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'ظاهر في الموقع أم مخفي',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_site_partners_legacy` (`legacy_key`),
  KEY `idx_site_partners_sort` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='شركاء الموقع التسويقي — لوجوهات وروابط بترتيب عرض';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `site_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(60) NOT NULL COMMENT 'مفتاح الإعداد — مثل: workHours, pilotAppVersion, customerBanner, attendanceTimeout, siteContent',
  `setting_value` longtext DEFAULT NULL COMMENT 'قيمة الإعداد بصيغة JSON',
  `updated_by` varchar(100) DEFAULT NULL COMMENT 'آخر من عدّل الإعداد',
  `updated_at` datetime DEFAULT NULL COMMENT 'وقت آخر تعديل — يتحدث من التطبيق يدويًا',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_site_settings_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إعدادات الموقع والنظام — ساعات العمل، إصدار تطبيق الطيار، بانر العميل، مهلة الحضور...';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `store_contacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_username` varchar(100) NOT NULL COMMENT 'اسم مستخدم حساب المحل صاحب الدفتر',
  `name` varchar(190) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `phone2` varchar(20) DEFAULT NULL,
  `address` varchar(190) DEFAULT NULL,
  `zone_id` bigint(20) unsigned DEFAULT NULL COMMENT 'منطقة التسليم المعتادة للعميل ده — بتتعبّى تلقائيًا لما المحل يختاره من الدفتر',
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_store_contacts_legacy` (`legacy_key`),
  KEY `idx_store_contacts_store` (`store_username`),
  KEY `idx_store_contacts_phone` (`phone`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جهات اتصال المحلات (دفتر مستلمين لكل محل)';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_app_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `app` varchar(40) NOT NULL COMMENT 'كود التطبيق المسموح (branch/callcenter/hr/accounts...)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_uap_user_app` (`user_id`,`app`),
  CONSTRAINT `fk_user_app_permissions_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=163 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='التطبيقات المسموحة لكل مستخدم — بديل allowedApps';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_page_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `app` varchar(40) NOT NULL COMMENT 'كود التطبيق',
  `page` varchar(40) NOT NULL COMMENT 'كود الصفحة داخل التطبيق',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_upp_user_app_page` (`user_id`,`app`,`page`),
  CONSTRAINT `fk_user_page_permissions_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='صلاحيات الصفحات داخل التطبيقات — بديل pagePerms';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL COMMENT 'اسم المستخدم للدخول (فريد)',
  `password_hash` varchar(255) NOT NULL COMMENT 'الباسورد مشفر bcrypt',
  `role` varchar(32) NOT NULL COMMENT 'دور الحساب',
  `name` varchar(190) DEFAULT NULL COMMENT 'الاسم الظاهر',
  `branch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الفرع المرتبط بالحساب (لحسابات الفرع)',
  `pilot_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الطيار المرتبط (لحسابات الطيارين)',
  `sender_id` bigint(20) unsigned DEFAULT NULL COMMENT 'المرسل المرتبط (لحسابات المحلات المربوطة بمرسل محفوظ)',
  `shop_name` varchar(190) DEFAULT NULL COMMENT 'اسم المحل (لحسابات المحلات)',
  `shop_phone` varchar(20) DEFAULT NULL COMMENT 'تليفون المحل',
  `shop_phone2` varchar(20) DEFAULT NULL COMMENT 'تليفون المحل الإضافي',
  `shop_address` varchar(190) DEFAULT NULL COMMENT 'عنوان المحل',
  `shop_zone_id` bigint(20) unsigned DEFAULT NULL COMMENT 'منطقة استلام المحل الافتراضية — منها بيتحدد الفرع المسؤول',
  `shop_lat` decimal(10,7) DEFAULT NULL COMMENT 'إحداثيات المحل',
  `shop_lng` decimal(10,7) DEFAULT NULL,
  `blocked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'الحساب موقوف',
  `blocked_at` datetime DEFAULT NULL COMMENT 'وقت الإيقاف',
  `blocked_by` varchar(190) DEFAULT NULL COMMENT 'مين أوقف الحساب',
  `protected` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'حساب محمي من الحذف/التعديل',
  `api_token` varchar(64) DEFAULT NULL COMMENT 'Ï¬┘ê┘â┘å Ï¬ÏÀÏ¿┘è┘é Ïº┘ä┘à┘êÏ¿Ïº┘è┘ä ÔÇö NULL ┘èÏ╣┘å┘è ┘à┘ü┘èÏ┤ Ï»Ï«┘ê┘ä ┘à┘êÏ¿Ïº┘è┘ä ┘åÏ┤ÏÀ',
  `api_token_at` datetime DEFAULT NULL COMMENT '┘ê┘éÏ¬ ÏÑÏÁÏ»ÏºÏ▒ Ïº┘äÏ¬┘ê┘â┘å Ïº┘äÏ¡Ïº┘ä┘è',
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_api_token` (`api_token`),
  KEY `idx_users_legacy` (`legacy_key`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_branch` (`branch_id`),
  KEY `idx_users_pilot` (`pilot_id`),
  KEY `idx_users_sender` (`sender_id`),
  KEY `idx_users_shop_phone` (`shop_phone`),
  KEY `idx_users_shop_zone` (`shop_zone_id`),
  CONSTRAINT `fk_users_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_users_pilot_id` FOREIGN KEY (`pilot_id`) REFERENCES `pilots` (`id`),
  CONSTRAINT `fk_users_sender_id` FOREIGN KEY (`sender_id`) REFERENCES `senders` (`id`),
  CONSTRAINT `fk_users_shop_zone_id` FOREIGN KEY (`shop_zone_id`) REFERENCES `zones` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=329 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حسابات الدخول لكل التطبيقات';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wallet_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `wallet_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'المبلغ بالإشارة: موجب إضافة، سالب خصم',
  `type` varchar(20) NOT NULL COMMENT 'credit=إضافة رصيد, discount=خصم/أوردر مجاني, debit=خصم من الرصيد, use=استخدام في أوردر, settle=تسوية',
  `note` varchar(190) DEFAULT NULL,
  `order_num` varchar(30) DEFAULT NULL COMMENT 'رقم الأوردر المرتبط بالحركة إن وجد',
  `balance_after` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'رصيد المحفظة بعد الحركة',
  `created_by` varchar(190) DEFAULT NULL COMMENT 'اسم مستخدم منشئ الحركة',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wallet_transactions_legacy` (`legacy_key`),
  KEY `idx_wallet_transactions_wallet_created` (`wallet_id`,`created_at`),
  KEY `idx_wallet_transactions_type` (`type`),
  KEY `idx_wallet_transactions_order_num` (`order_num`),
  CONSTRAINT `fk_wallet_transactions_wallet_id` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حركات المحافظ — سجل ابن خالص للمحفظة';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wallets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `owner_type` varchar(10) NOT NULL COMMENT 'customer=عميل, store=محل',
  `owner_id` bigint(20) unsigned NOT NULL COMMENT 'معرّف صاحب المحفظة في جدول customers أو users حسب النوع',
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'الرصيد الحالي',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallets_owner` (`owner_type`,`owner_id`),
  KEY `idx_wallets_legacy` (`legacy_key`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='محافظ العملاء والمحلات';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `zones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `area_name` varchar(190) NOT NULL COMMENT 'اسم المنطقة',
  `price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'سعر التوصيل للمنطقة',
  `delivery_branch_id` bigint(20) unsigned NOT NULL COMMENT 'الفرع اللي بيوصّل للمنطقة دي',
  `source_branch_id` bigint(20) unsigned DEFAULT NULL COMMENT 'الفرع مصدر الإضافة — NULL يعني اتضافت يدوي من الإدارة',
  `legacy_key` varchar(100) DEFAULT NULL COMMENT 'مفتاح Firebase القديم وقت الترحيل',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  /* 🔴 نفس المنطقة ممكن تتسجّل مرة لكل فرع بيوصّل لها (من/إلى) — ده مقصود.
     اللي مش مقصود إنها تتسجّل مرتين لنفس الفرع: بيخلّي الاسم يتكرر في
     قوايم الاختيار من غير أي فرق، وحصل فعلًا في 4 مناطق. */
  UNIQUE KEY `uq_zones_area_branch` (`area_name`,`delivery_branch_id`),
  KEY `idx_zones_legacy` (`legacy_key`),
  KEY `idx_zones_area` (`area_name`),
  KEY `idx_zones_delivery_branch` (`delivery_branch_id`),
  KEY `idx_zones_source_branch` (`source_branch_id`),
  CONSTRAINT `fk_zones_delivery_branch_id` FOREIGN KEY (`delivery_branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_zones_source_branch_id` FOREIGN KEY (`source_branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='زونات التسعير: المنطقة وسعرها والفرع المسؤول عن توصيلها';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

