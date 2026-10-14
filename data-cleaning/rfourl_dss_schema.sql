-- RfourL PDSS - database schema (structure only, no data)
-- phpMyAdmin: Import this file. It creates the database rfourl_dss and all 17 tables with their foreign keys.
-- Then open the database > Designer to see the ER diagram.
CREATE DATABASE IF NOT EXISTS `rfourl_dss` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `rfourl_dss`;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE `analytics_snapshot` (
  `snapshot_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `generated_at` datetime NOT NULL,
  `metric_type` varchar(50) NOT NULL,
  `period` varchar(20) NOT NULL,
  `metric_value` decimal(15,4) NOT NULL,
  `raw_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_data`)),
  PRIMARY KEY (`snapshot_id`),
  KEY `user_id` (`user_id`),
  KEY `metric_type_period` (`metric_type`,`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `cluster_segments` (
  `item_id` varchar(15) NOT NULL,
  `cluster_label` int(11) NOT NULL,
  `cluster_name` varchar(50) DEFAULT NULL,
  `abc_class` char(1) DEFAULT NULL,
  `service_level` decimal(5,2) DEFAULT NULL,
  `z_score` decimal(5,3) DEFAULT NULL,
  `computed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`item_id`),
  CONSTRAINT `cluster_segments_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `products` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `dss_parameters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ordering_cost` decimal(10,2) NOT NULL,
  `holding_cost_per_unit` decimal(10,2) NOT NULL,
  `service_level_target` decimal(5,2) NOT NULL,
  `z_score` decimal(5,3) NOT NULL,
  `demand_lookback_days` int(11) NOT NULL DEFAULT 90,
  `minimum_order_qty` int(11) NOT NULL DEFAULT 5,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `dss_parameters_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `inventory_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` varchar(15) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `log_type` enum('MANUAL_ADJUSTMENT','RESTOCK','SALE','RETURN','DAMAGED_LOST') NOT NULL,
  `quantity_changed` int(11) NOT NULL,
  `stock_after_change` int(11) NOT NULL,
  `reference_so_id` varchar(15) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `user_id` (`user_id`),
  KEY `reference_so_id` (`reference_so_id`),
  KEY `inventory_log_ibfk_1` (`item_id`),
  CONSTRAINT `inventory_log_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `products` (`item_id`),
  CONSTRAINT `inventory_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `inventory_log_ibfk_3` FOREIGN KEY (`reference_so_id`) REFERENCES `stock_order` (`so_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `migrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(255) NOT NULL,
  `class` varchar(255) NOT NULL,
  `group` varchar(255) NOT NULL,
  `namespace` varchar(255) NOT NULL,
  `time` int(11) NOT NULL,
  `batch` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `notification_preferences` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `rop_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `low_stock_warnings` tinyint(1) NOT NULL DEFAULT 1,
  `daily_sales_summary` tinyint(1) NOT NULL DEFAULT 1,
  `procurement_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `weekly_trend_report` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient_role` enum('Admin','Staff','Supplier') NOT NULL,
  `recipient_id` int(11) DEFAULT NULL,
  `type` varchar(50) NOT NULL,
  `category` varchar(20) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `message` text DEFAULT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `status` varchar(20) DEFAULT NULL,
  `action_token` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `recipient_id` (`recipient_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `pdss_computation` (
  `computation_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` varchar(15) NOT NULL,
  `computed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `annual_demand` int(11) DEFAULT NULL,
  `avg_daily_demand` decimal(10,2) DEFAULT NULL,
  `demand_std_dev` decimal(10,2) DEFAULT NULL,
  `lead_time_days` int(11) DEFAULT NULL,
  `service_level_z` decimal(5,3) DEFAULT NULL,
  `safety_stock` int(11) DEFAULT NULL,
  `reorder_point` int(11) DEFAULT NULL,
  `eoq_value` int(11) DEFAULT NULL,
  `triggered_by` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`computation_id`),
  KEY `pdss_computation_ibfk_1` (`item_id`),
  CONSTRAINT `pdss_computation_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `products` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `products` (
  `item_id` varchar(15) NOT NULL,
  `product_code` varchar(10) DEFAULT NULL,
  `supplier_id` varchar(10) DEFAULT NULL,
  `item_name` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL,
  `size` varchar(10) DEFAULT NULL,
  `size_type` varchar(10) DEFAULT NULL,
  `unit_cost` decimal(10,2) NOT NULL,
  `selling_price` decimal(10,2) NOT NULL,
  `current_stock` int(11) NOT NULL DEFAULT 0,
  `manual_rop_warning` int(11) DEFAULT NULL,
  `abc_category` enum('A','B','C') DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`item_id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `idx_products_code_size` (`product_code`,`size`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `reorder_alert` (
  `alert_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` varchar(15) NOT NULL,
  `computation_id` int(11) DEFAULT NULL,
  `so_id` varchar(20) DEFAULT NULL,
  `alert_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `trigger_reason` varchar(100) DEFAULT NULL,
  `recommended_eoq` int(11) DEFAULT NULL,
  `stock_at_trigger` int(11) DEFAULT NULL,
  `status` enum('Active','Acknowledged','Ordered','Fulfilled','Dismissed') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`alert_id`),
  KEY `computation_id` (`computation_id`),
  KEY `reorder_alert_ibfk_1` (`item_id`),
  CONSTRAINT `reorder_alert_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `products` (`item_id`),
  CONSTRAINT `reorder_alert_ibfk_2` FOREIGN KEY (`computation_id`) REFERENCES `pdss_computation` (`computation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `sales_item` (
  `sales_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `sales_id` int(11) NOT NULL,
  `item_id` varchar(15) NOT NULL,
  `quantity_sold` int(11) NOT NULL,
  `selling_price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`sales_item_id`),
  KEY `sales_id` (`sales_id`),
  KEY `sales_item_ibfk_2` (`item_id`),
  CONSTRAINT `sales_item_ibfk_1` FOREIGN KEY (`sales_id`) REFERENCES `sales_transaction` (`sales_id`) ON DELETE CASCADE,
  CONSTRAINT `sales_item_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `products` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `sales_transaction` (
  `sales_id` int(11) NOT NULL AUTO_INCREMENT,
  `receipt_no` varchar(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `sale_date` datetime NOT NULL,
  `payment_method` enum('Cash','GCash','Bank Transfer','Credit Card','Split') NOT NULL DEFAULT 'Cash',
  `cash_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gcash_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gcash_reference_no` varchar(50) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  PRIMARY KEY (`sales_id`),
  UNIQUE KEY `receipt_no` (`receipt_no`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `sales_transaction_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `security_log` (
  `log_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `role` varchar(20) DEFAULT NULL,
  `event` varchar(50) NOT NULL,
  `detail` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`log_id`),
  KEY `event` (`event`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `so_item` (
  `so_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `so_id` varchar(15) NOT NULL,
  `item_id` varchar(15) NOT NULL,
  `order_quantity` int(11) NOT NULL,
  `shipped_quantity` int(11) DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`so_item_id`),
  KEY `so_id` (`so_id`),
  KEY `so_item_ibfk_2` (`item_id`),
  CONSTRAINT `so_item_ibfk_1` FOREIGN KEY (`so_id`) REFERENCES `stock_order` (`so_id`) ON DELETE CASCADE,
  CONSTRAINT `so_item_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `products` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `stock_order` (
  `so_id` varchar(15) NOT NULL,
  `supplier_id` varchar(10) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `priority` enum('Urgent','Order','Planned') DEFAULT 'Order',
  `status` enum('Order Confirmed','Preparing','Shipped','Delivered','Cancelled') DEFAULT 'Order Confirmed',
  `order_date` date NOT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `actual_delivery_date` date DEFAULT NULL,
  `tracking_no` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`so_id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `stock_order_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`),
  CONSTRAINT `stock_order_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `suppliers` (
  `supplier_id` varchar(10) NOT NULL,
  `company_name` varchar(100) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `lead_time_days` int(11) NOT NULL,
  `preferred_courier` varchar(100) DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `contact_email` varchar(100) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` varchar(10) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('Admin','Staff','Supplier') NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `reset_otp_hash` varchar(255) DEFAULT NULL,
  `reset_otp_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  KEY `supplier_id` (`supplier_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS=1;
