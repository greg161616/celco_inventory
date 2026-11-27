# Database reference

Inspected MariaDB 10.4.32-MariaDB on 27 November 2026. The definitions below document the existing schema. They are reference text only.

## audit_logs

```sql
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `entity_type` varchar(60) NOT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_time` (`created_at`),
  KEY `fk_audit_logs_user_id` (`user_id`),
  CONSTRAINT `fk_audit_logs_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## categories

```sql
CREATE TABLE `categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## departments

```sql
CREATE TABLE `departments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(150) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## employees

```sql
CREATE TABLE `employees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_no` varchar(40) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `department_id` bigint(20) unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_no` (`employee_no`),
  KEY `fk_employees_department_id` (`department_id`),
  CONSTRAINT `fk_employees_department_id` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## inventory_stocks

```sql
CREATE TABLE `inventory_stocks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `material_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `stock_condition` enum('GOOD','DAMAGED','DEFECTIVE','SCRAP') NOT NULL,
  `quantity` decimal(18,3) NOT NULL DEFAULT 0.000,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `material_id` (`material_id`,`warehouse_id`,`stock_condition`),
  KEY `fk_inventory_stocks_warehouse_id` (`warehouse_id`),
  CONSTRAINT `fk_inventory_stocks_material_id` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `fk_inventory_stocks_warehouse_id` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`quantity` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## material_serials

```sql
CREATE TABLE `material_serials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `material_id` bigint(20) unsigned NOT NULL,
  `serial_number` varchar(100) NOT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `stock_condition` enum('GOOD','DAMAGED','DEFECTIVE','SCRAP') DEFAULT NULL,
  `state` enum('PENDING','IN_STOCK','ISSUED','DISPOSED') NOT NULL DEFAULT 'PENDING',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `material_id` (`material_id`,`serial_number`),
  KEY `fk_material_serials_warehouse_id` (`warehouse_id`),
  CONSTRAINT `fk_material_serials_material_id` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `fk_material_serials_warehouse_id` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`state` = 'IN_STOCK' and `warehouse_id` is not null and `stock_condition` is not null or `state` <> 'IN_STOCK' and `warehouse_id` is null and `stock_condition` is null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## materials

```sql
CREATE TABLE `materials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `material_code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `specification` text DEFAULT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  `unit_id` bigint(20) unsigned NOT NULL,
  `reorder_level` decimal(18,3) NOT NULL DEFAULT 0.000,
  `is_serialized` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `material_code` (`material_code`),
  KEY `fk_materials_category_id` (`category_id`),
  KEY `fk_materials_unit_id` (`unit_id`),
  CONSTRAINT `fk_materials_category_id` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  CONSTRAINT `fk_materials_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`reorder_level` >= 0),
  CONSTRAINT `CONSTRAINT_2` CHECK (`is_serialized` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## notifications

```sql
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `material_id` bigint(20) unsigned DEFAULT NULL,
  `requisition_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(40) NOT NULL,
  `message` varchar(255) NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_read` (`user_id`,`read_at`),
  KEY `fk_notifications_material_id` (`material_id`),
  KEY `fk_notifications_requisition_id` (`requisition_id`),
  CONSTRAINT `fk_notifications_material_id` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `fk_notifications_requisition_id` FOREIGN KEY (`requisition_id`) REFERENCES `requisitions` (`id`),
  CONSTRAINT `fk_notifications_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## permissions

```sql
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(70) NOT NULL,
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## projects

```sql
CREATE TABLE `projects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('PLANNED','ACTIVE','COMPLETED','CANCELLED') NOT NULL DEFAULT 'PLANNED',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_code` (`project_code`),
  KEY `fk_projects_department_id` (`department_id`),
  CONSTRAINT `fk_projects_department_id` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`end_date` is null or `start_date` is null or `end_date` >= `start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## requisition_approvals

```sql
CREATE TABLE `requisition_approvals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `requisition_id` bigint(20) unsigned NOT NULL,
  `decided_by` bigint(20) unsigned NOT NULL,
  `decision` enum('APPROVED','REJECTED','CANCELLED','CLOSED') NOT NULL,
  `remarks` text DEFAULT NULL,
  `decided_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_requisition_approvals_requisition_id` (`requisition_id`),
  KEY `fk_requisition_approvals_decided_by` (`decided_by`),
  CONSTRAINT `fk_requisition_approvals_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_requisition_approvals_requisition_id` FOREIGN KEY (`requisition_id`) REFERENCES `requisitions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## requisition_items

```sql
CREATE TABLE `requisition_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `requisition_id` bigint(20) unsigned NOT NULL,
  `material_id` bigint(20) unsigned NOT NULL,
  `requested_qty` decimal(18,3) NOT NULL,
  `approved_qty` decimal(18,3) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `requisition_id` (`requisition_id`,`material_id`),
  KEY `fk_requisition_items_material_id` (`material_id`),
  CONSTRAINT `fk_requisition_items_material_id` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `fk_requisition_items_requisition_id` FOREIGN KEY (`requisition_id`) REFERENCES `requisitions` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`requested_qty` > 0),
  CONSTRAINT `CONSTRAINT_2` CHECK (`approved_qty` is null or `approved_qty` >= 0 and `approved_qty` <= `requested_qty`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## requisitions

```sql
CREATE TABLE `requisitions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_no` varchar(50) NOT NULL,
  `requested_by` bigint(20) unsigned NOT NULL,
  `department_id` bigint(20) unsigned NOT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `purpose` text NOT NULL,
  `status` enum('DRAFT','SUBMITTED','APPROVED','REJECTED','PARTIALLY_ISSUED','ISSUED','CANCELLED','CLOSED') NOT NULL DEFAULT 'DRAFT',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_no` (`request_no`),
  KEY `fk_requisitions_requested_by` (`requested_by`),
  KEY `fk_requisitions_department_id` (`department_id`),
  KEY `fk_requisitions_project_id` (`project_id`),
  KEY `fk_requisitions_warehouse_id` (`warehouse_id`),
  CONSTRAINT `fk_requisitions_department_id` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_requisitions_project_id` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `fk_requisitions_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_requisitions_warehouse_id` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## role_permissions

```sql
CREATE TABLE `role_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_id` (`role_id`,`permission_id`),
  KEY `fk_role_permissions_permission_id` (`permission_id`),
  CONSTRAINT `fk_role_permissions_permission_id` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`),
  CONSTRAINT `fk_role_permissions_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## roles

```sql
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## settings

```sql
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(80) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `fk_settings_updated_by` (`updated_by`),
  CONSTRAINT `fk_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## stock_count_item_serials

```sql
CREATE TABLE `stock_count_item_serials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stock_count_item_id` bigint(20) unsigned NOT NULL,
  `serial_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_count_item_id` (`stock_count_item_id`,`serial_id`),
  KEY `fk_stock_count_item_serials_serial_id` (`serial_id`),
  CONSTRAINT `fk_stock_count_item_serials_serial_id` FOREIGN KEY (`serial_id`) REFERENCES `material_serials` (`id`),
  CONSTRAINT `fk_stock_count_item_serials_stock_count_item_id` FOREIGN KEY (`stock_count_item_id`) REFERENCES `stock_count_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## stock_count_items

```sql
CREATE TABLE `stock_count_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stock_count_id` bigint(20) unsigned NOT NULL,
  `material_id` bigint(20) unsigned NOT NULL,
  `stock_condition` enum('GOOD','DAMAGED','DEFECTIVE','SCRAP') NOT NULL,
  `system_qty` decimal(18,3) NOT NULL,
  `counted_qty` decimal(18,3) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_count_id` (`stock_count_id`,`material_id`,`stock_condition`),
  KEY `fk_stock_count_items_material_id` (`material_id`),
  CONSTRAINT `fk_stock_count_items_material_id` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `fk_stock_count_items_stock_count_id` FOREIGN KEY (`stock_count_id`) REFERENCES `stock_counts` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`system_qty` >= 0),
  CONSTRAINT `CONSTRAINT_2` CHECK (`counted_qty` is null or `counted_qty` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## stock_counts

```sql
CREATE TABLE `stock_counts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `count_no` varchar(50) NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `counted_by` bigint(20) unsigned NOT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `adjustment_document_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('DRAFT','COUNTING','REVIEWED','POSTED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  `count_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `remarks` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `count_no` (`count_no`),
  UNIQUE KEY `adjustment_document_id` (`adjustment_document_id`),
  KEY `fk_stock_counts_warehouse_id` (`warehouse_id`),
  KEY `fk_stock_counts_counted_by` (`counted_by`),
  KEY `fk_stock_counts_approved_by` (`approved_by`),
  CONSTRAINT `fk_stock_counts_adjustment_document_id` FOREIGN KEY (`adjustment_document_id`) REFERENCES `stock_documents` (`id`),
  CONSTRAINT `fk_stock_counts_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_stock_counts_counted_by` FOREIGN KEY (`counted_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_stock_counts_warehouse_id` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## stock_document_line_serials

```sql
CREATE TABLE `stock_document_line_serials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_line_id` bigint(20) unsigned NOT NULL,
  `serial_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_line_id` (`document_line_id`,`serial_id`),
  KEY `fk_stock_document_line_serials_serial_id` (`serial_id`),
  CONSTRAINT `fk_stock_document_line_serials_document_line_id` FOREIGN KEY (`document_line_id`) REFERENCES `stock_document_lines` (`id`),
  CONSTRAINT `fk_stock_document_line_serials_serial_id` FOREIGN KEY (`serial_id`) REFERENCES `material_serials` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## stock_document_lines

```sql
CREATE TABLE `stock_document_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL,
  `material_id` bigint(20) unsigned NOT NULL,
  `quantity` decimal(18,3) NOT NULL,
  `unit_cost` decimal(18,4) DEFAULT NULL,
  `from_warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `from_condition` enum('GOOD','DAMAGED','DEFECTIVE','SCRAP') DEFAULT NULL,
  `to_warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `to_condition` enum('GOOD','DAMAGED','DEFECTIVE','SCRAP') DEFAULT NULL,
  `requisition_item_id` bigint(20) unsigned DEFAULT NULL,
  `return_of_line_id` bigint(20) unsigned DEFAULT NULL,
  `reverses_line_id` bigint(20) unsigned DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_stock_document_lines_document_id` (`document_id`),
  KEY `fk_stock_document_lines_material_id` (`material_id`),
  KEY `fk_stock_document_lines_from_warehouse_id` (`from_warehouse_id`),
  KEY `fk_stock_document_lines_to_warehouse_id` (`to_warehouse_id`),
  KEY `fk_stock_document_lines_requisition_item_id` (`requisition_item_id`),
  KEY `fk_stock_document_lines_return_of_line_id` (`return_of_line_id`),
  KEY `fk_stock_document_lines_reverses_line_id` (`reverses_line_id`),
  CONSTRAINT `fk_stock_document_lines_document_id` FOREIGN KEY (`document_id`) REFERENCES `stock_documents` (`id`),
  CONSTRAINT `fk_stock_document_lines_from_warehouse_id` FOREIGN KEY (`from_warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_stock_document_lines_material_id` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `fk_stock_document_lines_requisition_item_id` FOREIGN KEY (`requisition_item_id`) REFERENCES `requisition_items` (`id`),
  CONSTRAINT `fk_stock_document_lines_return_of_line_id` FOREIGN KEY (`return_of_line_id`) REFERENCES `stock_document_lines` (`id`),
  CONSTRAINT `fk_stock_document_lines_reverses_line_id` FOREIGN KEY (`reverses_line_id`) REFERENCES `stock_document_lines` (`id`),
  CONSTRAINT `fk_stock_document_lines_to_warehouse_id` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`quantity` > 0),
  CONSTRAINT `CONSTRAINT_2` CHECK (`unit_cost` is null or `unit_cost` >= 0),
  CONSTRAINT `CONSTRAINT_3` CHECK (`from_warehouse_id` is null = (`from_condition` is null)),
  CONSTRAINT `CONSTRAINT_4` CHECK (`to_warehouse_id` is null = (`to_condition` is null)),
  CONSTRAINT `CONSTRAINT_5` CHECK (`from_warehouse_id` is not null or `to_warehouse_id` is not null),
  CONSTRAINT `CONSTRAINT_6` CHECK (`from_warehouse_id` is null or `to_warehouse_id` is null or `from_warehouse_id` <> `to_warehouse_id` or `from_condition` <> `to_condition`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## stock_documents

```sql
CREATE TABLE `stock_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_no` varchar(50) NOT NULL,
  `document_type` enum('OPENING','RECEIPT','ISSUE','RETURN','TRANSFER','ADJUSTMENT','DISPOSAL','REVERSAL') NOT NULL,
  `status` enum('DRAFT','POSTED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  `document_date` date NOT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `recipient_employee_id` bigint(20) unsigned DEFAULT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `posted_by` bigint(20) unsigned DEFAULT NULL,
  `reverses_document_id` bigint(20) unsigned DEFAULT NULL,
  `external_reference` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `posted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_no` (`document_no`),
  KEY `fk_stock_documents_supplier_id` (`supplier_id`),
  KEY `fk_stock_documents_department_id` (`department_id`),
  KEY `fk_stock_documents_recipient_employee_id` (`recipient_employee_id`),
  KEY `fk_stock_documents_project_id` (`project_id`),
  KEY `fk_stock_documents_created_by` (`created_by`),
  KEY `fk_stock_documents_approved_by` (`approved_by`),
  KEY `fk_stock_documents_posted_by` (`posted_by`),
  KEY `fk_stock_documents_reverses_document_id` (`reverses_document_id`),
  CONSTRAINT `fk_stock_documents_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_stock_documents_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_stock_documents_department_id` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_stock_documents_posted_by` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_stock_documents_project_id` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `fk_stock_documents_recipient_employee_id` FOREIGN KEY (`recipient_employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `fk_stock_documents_reverses_document_id` FOREIGN KEY (`reverses_document_id`) REFERENCES `stock_documents` (`id`),
  CONSTRAINT `fk_stock_documents_supplier_id` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`status` = 'POSTED' and `posted_at` is not null and `posted_by` is not null or `status` <> 'POSTED' and `posted_at` is null and `posted_by` is null),
  CONSTRAINT `CONSTRAINT_2` CHECK (`document_type` = 'REVERSAL' and `reverses_document_id` is not null or `document_type` <> 'REVERSAL' and `reverses_document_id` is null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## stock_movements

```sql
CREATE TABLE `stock_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_line_id` bigint(20) unsigned NOT NULL,
  `stock_id` bigint(20) unsigned NOT NULL,
  `direction` enum('IN','OUT') NOT NULL,
  `quantity_delta` decimal(18,3) NOT NULL,
  `balance_after` decimal(18,3) NOT NULL,
  `posted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_line_id` (`document_line_id`,`direction`),
  KEY `idx_movements_stock_time` (`stock_id`,`posted_at`,`id`),
  CONSTRAINT `fk_stock_movements_document_line_id` FOREIGN KEY (`document_line_id`) REFERENCES `stock_document_lines` (`id`),
  CONSTRAINT `fk_stock_movements_stock_id` FOREIGN KEY (`stock_id`) REFERENCES `inventory_stocks` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`direction` = 'IN' and `quantity_delta` > 0 or `direction` = 'OUT' and `quantity_delta` < 0),
  CONSTRAINT `CONSTRAINT_2` CHECK (`balance_after` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## suppliers

```sql
CREATE TABLE `suppliers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `contact_person` varchar(120) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_code` (`supplier_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## units

```sql
CREATE TABLE `units` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## users

```sql
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned DEFAULT NULL,
  `username` varchar(80) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `employee_id` (`employee_id`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_users_role_id` (`role_id`),
  CONSTRAINT `fk_users_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `fk_users_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## v_low_stock

```sql
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_low_stock` AS select `a`.`material_id` AS `material_id`,`a`.`material_code` AS `material_code`,`a`.`name` AS `name`,`a`.`reorder_level` AS `reorder_level`,`a`.`available_qty` AS `available_qty`,`a`.`total_qty` AS `total_qty` from (`v_material_availability` `a` join `materials` `m` on(`m`.`id` = `a`.`material_id`)) where `m`.`is_active` = 1 and `a`.`available_qty` <= `a`.`reorder_level`
```

## v_material_availability

```sql
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_material_availability` AS select `m`.`id` AS `material_id`,`m`.`material_code` AS `material_code`,`m`.`name` AS `name`,`m`.`reorder_level` AS `reorder_level`,coalesce(sum(case when `s`.`stock_condition` = 'GOOD' then `s`.`quantity` else 0 end),0) AS `available_qty`,coalesce(sum(`s`.`quantity`),0) AS `total_qty` from (`materials` `m` left join `inventory_stocks` `s` on(`s`.`material_id` = `m`.`id`)) group by `m`.`id`,`m`.`material_code`,`m`.`name`,`m`.`reorder_level`
```

## v_requisition_fulfillment

```sql
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_requisition_fulfillment` AS select `ri`.`id` AS `requisition_item_id`,`ri`.`requisition_id` AS `requisition_id`,`ri`.`material_id` AS `material_id`,`ri`.`requested_qty` AS `requested_qty`,`ri`.`approved_qty` AS `approved_qty`,sum(case when `d`.`id` is not null and `r`.`id` is null then `l`.`quantity` else 0 end) AS `issued_qty`,case when `ri`.`approved_qty` is null then NULL else `ri`.`approved_qty` - sum(case when `d`.`id` is not null and `r`.`id` is null then `l`.`quantity` else 0 end) end AS `remaining_qty` from (((`requisition_items` `ri` left join `stock_document_lines` `l` on(`l`.`requisition_item_id` = `ri`.`id`)) left join `stock_documents` `d` on(`d`.`id` = `l`.`document_id` and `d`.`document_type` = 'ISSUE' and `d`.`status` = 'POSTED')) left join `stock_documents` `r` on(`r`.`reverses_document_id` = `d`.`id` and `r`.`status` = 'POSTED')) group by `ri`.`id`,`ri`.`requisition_id`,`ri`.`material_id`,`ri`.`requested_qty`,`ri`.`approved_qty`
```

## v_stock_reconciliation

```sql
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_stock_reconciliation` AS select `s`.`id` AS `stock_id`,`s`.`material_id` AS `material_id`,`s`.`warehouse_id` AS `warehouse_id`,`s`.`stock_condition` AS `stock_condition`,`s`.`quantity` AS `recorded_qty`,coalesce(sum(`l`.`quantity_delta`),0) AS `ledger_qty`,`s`.`quantity` - coalesce(sum(`l`.`quantity_delta`),0) AS `variance` from (`inventory_stocks` `s` left join `stock_movements` `l` on(`l`.`stock_id` = `s`.`id`)) group by `s`.`id`,`s`.`material_id`,`s`.`warehouse_id`,`s`.`stock_condition`,`s`.`quantity`
```

## warehouses

```sql
CREATE TABLE `warehouses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

