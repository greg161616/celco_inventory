<?php
declare(strict_types=1);

/**
 * Each SQL template has one FILTERS marker after its fixed predicates.
 * Filters and sort expressions are server-owned. Bind user values to placeholders.
 * An array of filter expressions matches either endpoint of a transfer.
 */
function report_definitions(): array
{
    $make = static fn(
        string $title, array $columns, string $sql, array $filters, string $order,
        array $totals = [], string $note = '', string $permission = 'reports.view'
    ): array => compact('title', 'columns', 'sql', 'filters', 'order', 'totals', 'note', 'permission');

    $catalog = [];
    $mat = ['material_code' => 'Material code', 'material' => 'Material', 'unit' => 'Unit'];
    $matSelect = 'm.material_code, m.name AS material, u.code AS unit';
    $matFilters = ['material_id' => 'm.id', 'category_id' => 'm.category_id'];
    $orderMaterial = 'm.material_code, m.id';
    $units = ' JOIN units u ON u.id=m.unit_id ';
    $availability = "SELECT $matSelect, a.available_qty, a.total_qty, a.reorder_level
        FROM %s a JOIN materials m ON m.id=a.material_id $units WHERE %s /*FILTERS*/";
    $availableColumns = $mat + [
        'available_qty' => 'Good stock', 'total_qty' => 'All conditions', 'reorder_level' => 'Reorder level',
    ];
    $catalog['inventory-summary'] = $make('Inventory Summary Report', $availableColumns,
        sprintf($availability, 'v_material_availability', '1=1'), $matFilters, $orderMaterial, ['available_qty','total_qty']);
    $catalog['low-stock'] = $make('Low Stock Report', $availableColumns,
        sprintf($availability, 'v_low_stock', '1=1'), $matFilters, $orderMaterial, [],
        'Active materials with good stock at or below their reorder level across all warehouses.');
    $catalog['out-of-stock'] = $make('Out-of-Stock Report', $availableColumns,
        sprintf($availability, 'v_material_availability', 'm.is_active=1 AND a.available_qty=0'), $matFilters, $orderMaterial);

    $stockBase = "FROM inventory_stocks s JOIN materials m ON m.id=s.material_id
        $units JOIN warehouses w ON w.id=s.warehouse_id";
    $stockSelect = "$matSelect, w.name AS warehouse, s.stock_condition, s.quantity";
    $stockCols = $mat + ['warehouse' => 'Warehouse', 'stock_condition' => 'Condition', 'quantity' => 'On hand'];
    $stockFilters = $matFilters + ['warehouse_id'=>'s.warehouse_id','stock_condition'=>'s.stock_condition'];
    $orderStock = 'w.name, m.material_code, s.stock_condition, s.id';
    $catalog['warehouse-inventory'] = $make('Warehouse Inventory Report', $stockCols,
        "SELECT $stockSelect $stockBase WHERE 1=1 /*FILTERS*/", $stockFilters, $orderStock, ['quantity']);
    $catalog['damaged-material'] = $make('Damaged Material Report', $stockCols,
        "SELECT $stockSelect $stockBase WHERE s.stock_condition IN ('DAMAGED','DEFECTIVE','SCRAP') AND s.quantity>0 /*FILTERS*/",
        $stockFilters, $orderStock, ['quantity']);
    $catalog['inventory-valuation'] = $make('Inventory Valuation Report', $stockCols,
        "SELECT $stockSelect $stockBase WHERE s.quantity<>0 /*FILTERS*/", $stockFilters, $orderStock, ['quantity'],
        'Monetary valuation is unavailable. The database records optional document-line costs but has no approved accounting calculation or cost layers. The figures below are physical quantities.');
    $catalog['stock-card'] = $make('Stock Card Report', [
        'posted_at'=>'Posted at','document_no'=>'Document','document_type'=>'Type',
    ] + $mat + ['warehouse'=>'Warehouse','stock_condition'=>'Condition','quantity_delta'=>'Change','balance_after'=>'Balance after'],
        "SELECT v.posted_at, d.document_no, d.document_type, $matSelect, w.name AS warehouse,
            s.stock_condition, v.quantity_delta, v.balance_after
         FROM stock_movements v JOIN stock_document_lines l ON l.id=v.document_line_id
         JOIN stock_documents d ON d.id=l.document_id JOIN inventory_stocks s ON s.id=v.stock_id
         JOIN warehouses w ON w.id=s.warehouse_id JOIN materials m ON m.id=s.material_id $units
         WHERE d.status='POSTED' /*FILTERS*/",
        $stockFilters + ['date'=>'v.posted_at','document_type'=>'d.document_type'],
        'v.posted_at, v.id', ['quantity_delta'], 'Balance after is specific to the material, warehouse and condition on that movement.');

    $documents = [
        'stock-in'=>['Stock-In Report', 'RECEIPT', 'to'],
        'stock-out'=>['Stock-Out Report', 'ISSUE', 'from'],
        'material-return'=>['Material Return Report', 'RETURN', 'to'],
        'warehouse-transfer'=>['Warehouse Transfer Report', 'TRANSFER', 'both'],
        'disposal'=>['Disposal Report', 'DISPOSAL', 'from'],
        'stock-adjustment'=>['Stock Adjustment Report', 'ADJUSTMENT', 'both'],
    ];
    foreach ($documents as $slug => [$title,$type,$side]) {
        $cols = ['document_no'=>'Document','document_date'=>'Date','status'=>'Status'] + $mat + ['quantity'=>'Quantity'];
        $select = "d.document_no, d.document_date, d.status, $matSelect, l.quantity";
        $joins = " FROM stock_documents d JOIN stock_document_lines l ON l.document_id=d.id
            JOIN materials m ON m.id=l.material_id $units";
        $filters = $matFilters + ['date'=>'d.document_date','status'=>'d.status'];
        if ($side !== 'to') {
            $select .= ', fw.name AS from_warehouse, l.from_condition';
            $joins .= ' LEFT JOIN warehouses fw ON fw.id=l.from_warehouse_id';
            $cols += ['from_warehouse'=>'From warehouse','from_condition'=>'From condition'];
        }
        if ($side !== 'from') {
            $select .= ', tw.name AS to_warehouse, l.to_condition';
            $joins .= ' LEFT JOIN warehouses tw ON tw.id=l.to_warehouse_id';
            $cols += ['to_warehouse'=>'To warehouse','to_condition'=>'To condition'];
        }
        foreach (['warehouse_id','condition'] as $field) {
            $filters[$field === 'condition' ? 'stock_condition' : $field] = $side === 'both'
                ? ["l.from_$field", "l.to_$field"] : "l.{$side}_$field";
        }
        if ($type === 'RECEIPT') {
            $cols += ['supplier'=>'Supplier','reference'=>'Delivery reference','unit_cost'=>'Receipt unit cost'];
            $select .= ', sp.name AS supplier, d.external_reference AS reference, l.unit_cost';
            $joins .= ' LEFT JOIN suppliers sp ON sp.id=d.supplier_id';
            $filters['supplier_id'] = 'd.supplier_id';
        }
        if ($type === 'ISSUE' || $type === 'RETURN') {
            $cols['employee'] = $type === 'ISSUE' ? 'Recipient' : 'Returned by';
            $select .= ', emp.full_name AS employee';
            $joins .= ' LEFT JOIN employees emp ON emp.id=d.recipient_employee_id';
            $filters['employee_id'] = 'd.recipient_employee_id';
        }
        if ($type === 'ISSUE') {
            $cols += ['department'=>'Department','project'=>'Project','request_no'=>'Requisition'];
            $select .= ', dep.name AS department, proj.name AS project, r.request_no';
            $joins .= ' LEFT JOIN departments dep ON dep.id=d.department_id LEFT JOIN projects proj ON proj.id=d.project_id
                LEFT JOIN requisition_items ri ON ri.id=l.requisition_item_id LEFT JOIN requisitions r ON r.id=ri.requisition_id';
            $filters += ['department_id'=>'d.department_id','project_id'=>'d.project_id'];
        }
        if ($type === 'RETURN') {
            $cols['issue_no'] = 'Original issuance';
            $select .= ', orig.document_no AS issue_no';
            $joins .= ' LEFT JOIN stock_document_lines orig_l ON orig_l.id=l.return_of_line_id
                LEFT JOIN stock_documents orig ON orig.id=orig_l.document_id';
        }
        $cols['reversal_no'] = 'Posted reversal';
        $select .= ', rev.document_no AS reversal_no';
        $joins .= " LEFT JOIN stock_documents rev ON rev.reverses_document_id=d.id AND rev.status='POSTED'";
        $catalog[$slug] = $make($title, $cols, "SELECT $select $joins WHERE d.document_type='$type' /*FILTERS*/",
            $filters, 'd.document_date DESC, d.id DESC, l.id', [],
            'Quantities are document line amounts. Status and posted reversal identify the effect on inventory.');
    }
    $reqCols = ['request_no'=>'Requisition','created_at'=>'Created','requester'=>'Requester','department'=>'Department',
        'project'=>'Project','warehouse'=>'Warehouse','status'=>'Status','purpose'=>'Purpose'];
    $reqSelect = 'r.request_no, r.created_at, usr.username AS requester, dep.name AS department,
        proj.name AS project, w.name AS warehouse, r.status, r.purpose';
    $reqFrom = ' FROM requisitions r JOIN users usr ON usr.id=r.requested_by JOIN departments dep ON dep.id=r.department_id
        LEFT JOIN projects proj ON proj.id=r.project_id LEFT JOIN warehouses w ON w.id=r.warehouse_id ';
    $reqFilters = ['date'=>'r.created_at','status'=>'r.status','warehouse_id'=>'r.warehouse_id',
        'employee_id'=>'usr.employee_id','project_id'=>'r.project_id','department_id'=>'r.department_id'];
    $catalog['material-requisition'] = $make('Material Requisition Report', $reqCols,
        "SELECT $reqSelect $reqFrom WHERE 1=1 /*FILTERS*/", $reqFilters, 'r.created_at DESC, r.id DESC');
    $catalog['requisition-fulfillment'] = $make('Requisition Fulfillment Report',
        ['request_no'=>'Requisition','department'=>'Department','status'=>'Status'] + $mat
        + ['requested_qty'=>'Requested','approved_qty'=>'Approved','issued_qty'=>'Issued','remaining_qty'=>'Remaining'],
        "SELECT r.request_no, dep.name AS department, r.status, $matSelect,
            f.requested_qty, f.approved_qty, f.issued_qty, f.remaining_qty $reqFrom
         JOIN v_requisition_fulfillment f ON f.requisition_id=r.id JOIN materials m ON m.id=f.material_id $units WHERE 1=1 /*FILTERS*/",
        $reqFilters + $matFilters, 'r.created_at DESC, r.id DESC, f.requisition_item_id',
        ['requested_qty','approved_qty','issued_qty','remaining_qty']);

    // Attribute returns and reversals to the employee, project and department on the original issue.
    $consumptionFrom = " FROM stock_documents d JOIN stock_document_lines l ON l.document_id=d.id
        LEFT JOIN stock_document_lines prior_l ON prior_l.id=l.reverses_line_id
        LEFT JOIN stock_documents prior_d ON prior_d.id=prior_l.document_id
        JOIN stock_document_lines issue_l ON issue_l.id=CASE COALESCE(prior_d.document_type,d.document_type)
           WHEN 'ISSUE' THEN COALESCE(prior_l.id,l.id)
           WHEN 'RETURN' THEN COALESCE(prior_l.return_of_line_id,l.return_of_line_id) END
        JOIN stock_documents issue ON issue.id=issue_l.document_id
        JOIN materials m ON m.id=issue_l.material_id $units ";
    $net = "SUM(l.quantity * CASE WHEN d.document_type='REVERSAL' THEN -1 ELSE 1 END
        * CASE WHEN COALESCE(prior_d.document_type,d.document_type)='RETURN' THEN -1 ELSE 1 END)";
    foreach (['department-consumption'=>'department','project-consumption'=>'project'] as $slug => $group) {
        $title = ucfirst($group);
        $table = $group === 'department' ? 'departments' : 'projects';
        $catalog[$slug] = $make("$title Consumption Report", ['assignment'=>$title] + $mat + ['net_qty'=>'Net issued'],
            "SELECT COALESCE(g.name,'Unassigned') AS assignment, $matSelect, $net AS net_qty $consumptionFrom
             LEFT JOIN $table g ON g.id=issue.{$group}_id
             WHERE d.status='POSTED' AND d.document_type IN ('ISSUE','RETURN','REVERSAL') AND issue.document_type='ISSUE' /*FILTERS*/
             GROUP BY g.id, g.name, m.id, m.material_code, m.name, u.code",
            $matFilters + ['date'=>'d.document_date','department_id'=>'issue.department_id','employee_id'=>'issue.recipient_employee_id',
                'project_id'=>'issue.project_id','warehouse_id'=>'issue_l.from_warehouse_id'],
            'assignment, m.material_code, m.id', ['net_qty'], 'Net issued equals posted issues less returns, including reversal effects on each document date.');
    }

    $catalog['physical-inventory'] = $make('Physical Inventory Report',
        ['count_no'=>'Count','count_date'=>'Date','warehouse'=>'Warehouse','status'=>'Status'] + $mat +
        ['stock_condition'=>'Condition','system_qty'=>'Snapshot','counted_qty'=>'Counted','variance'=>'Difference','remarks'=>'Explanation'],
        "SELECT c.count_no, c.count_date, w.name AS warehouse, c.status, $matSelect, i.stock_condition,
            i.system_qty, i.counted_qty, i.counted_qty-i.system_qty AS variance, i.remarks
         FROM stock_counts c JOIN warehouses w ON w.id=c.warehouse_id
         JOIN stock_count_items i ON i.stock_count_id=c.id JOIN materials m ON m.id=i.material_id $units
         WHERE 1=1 /*FILTERS*/", $matFilters + ['warehouse_id'=>'c.warehouse_id','date'=>'c.count_date',
             'status'=>'c.status','stock_condition'=>'i.stock_condition'],
        'c.count_date DESC, c.id DESC, i.id');
    $catalog['inventory-reconciliation'] = $make('Inventory Reconciliation Report',
        $mat + ['warehouse'=>'Warehouse','stock_condition'=>'Condition','recorded_qty'=>'Recorded','ledger_qty'=>'Ledger','variance'=>'Variance'],
        "SELECT $matSelect, w.name AS warehouse, s.stock_condition, s.recorded_qty, s.ledger_qty, s.variance
         FROM v_stock_reconciliation s JOIN materials m ON m.id=s.material_id $units
         JOIN warehouses w ON w.id=s.warehouse_id WHERE 1=1 /*FILTERS*/",
        $stockFilters, 'w.name, m.material_code, s.stock_condition, s.stock_id', ['recorded_qty','ledger_qty','variance']);
    $catalog['serial-tracking'] = $make('Serial Number Tracking Report', $mat + ['serial_number'=>'Serial number',
        'state'=>'Current state','warehouse'=>'Current warehouse','stock_condition'=>'Current condition',
        'document_no'=>'Document','document_type'=>'Type','document_date'=>'Date','from_warehouse'=>'From','to_warehouse'=>'To'],
        "SELECT $matSelect, s.serial_number, s.state, w.name AS warehouse, s.stock_condition,
           history.document_no, history.document_type, history.document_date, fw.name AS from_warehouse, tw.name AS to_warehouse
         FROM material_serials s JOIN materials m ON m.id=s.material_id $units LEFT JOIN warehouses w ON w.id=s.warehouse_id
         LEFT JOIN (
            SELECT ls.serial_id, d.id AS doc_id, l.id AS line_id, d.document_no, d.document_type, d.document_date, l.from_warehouse_id, l.to_warehouse_id
            FROM stock_document_line_serials ls JOIN stock_document_lines l ON l.id=ls.document_line_id
            JOIN stock_documents d ON d.id=l.document_id AND d.status='POSTED'
         ) history ON history.serial_id=s.id LEFT JOIN warehouses fw ON fw.id=history.from_warehouse_id
         LEFT JOIN warehouses tw ON tw.id=history.to_warehouse_id WHERE 1=1 /*FILTERS*/",
        $matFilters + ['warehouse_id'=>'s.warehouse_id','stock_condition'=>'s.stock_condition','status'=>'s.state',
            'date'=>'history.document_date','document_type'=>'history.document_type'],
        'm.material_code, s.serial_number, history.document_date, history.doc_id, history.line_id',
        [], 'Each row shows one posted document in the serial history. Warehouse, condition and state filters apply to the current serial record.');
    $catalog['audit-trail'] = $make('Audit Trail Report',
        ['created_at'=>'Time','username'=>'User','action'=>'Action','entity_type'=>'Record type','entity_id'=>'Record ID','ip_address'=>'IP address'],
        "SELECT a.created_at, usr.username, a.action, a.entity_type, a.entity_id, a.ip_address FROM audit_logs a
         LEFT JOIN users usr ON usr.id=a.user_id WHERE 1=1 /*FILTERS*/",
        ['date'=>'a.created_at','employee_id'=>'usr.employee_id'], 'a.created_at DESC, a.id DESC', [], '', 'audit.view');
    return $catalog;
}
