<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/inventory.php';

/** Each run owns fresh users, locations and materials on the identity-checked fixture. */
function inventory_fixture(): array
{
    $tag = 'qa-' . bin2hex(random_bytes(6));
    $category = (int)scalar('SELECT id FROM categories WHERE is_active=1 ORDER BY id LIMIT 1');
    $meter = (int)scalar("SELECT id FROM units WHERE code='M'");
    $piece = (int)scalar("SELECT id FROM units WHERE code='PC'");
    $role = (int)scalar("SELECT id FROM roles WHERE code='ADMIN'");
    if (!$category || !$meter || !$piece || !$role) {
        throw new RuntimeException('The inspected CELCO reference data is required.');
    }
    $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
    $actor = insert_record('users', ['username' => "$tag-user", 'role_id' => $role, 'password_hash' => $hash]);
    $reviewer = insert_record('users', ['username' => "$tag-review", 'role_id' => $role, 'password_hash' => $hash]);
    $_SESSION = ['user_id' => $actor, 'credential_version' => hash('sha256', $hash)];
    $department = insert_record('departments', ['code' => $tag, 'name' => "$tag Operations"]);
    $employee = insert_record('employees', [
        'employee_no' => $tag, 'full_name' => "$tag Employee", 'department_id' => $department,
    ]);
    $a = insert_record('warehouses', ['code' => "$tag-A", 'name' => "$tag A"]);
    $b = insert_record('warehouses', ['code' => "$tag-B", 'name' => "$tag B"]);
    $wire = insert_record('materials', [
        'material_code' => "$tag-wire", 'name' => "$tag Wire", 'category_id' => $category, 'unit_id' => $meter,
    ]);
    $equipment = insert_record('materials', [
        'material_code' => "$tag-meter", 'name' => "$tag Meter", 'category_id' => $category,
        'unit_id' => $piece, 'is_serialized' => 1,
    ]);
    return compact('tag', 'actor', 'reviewer', 'department', 'employee', 'a', 'b', 'wire', 'equipment');
}

function inventory_draft(array $fixture, string $type, array $lines, ?string $number = null): int
{
    $header = [
        'document_date' => date('Y-m-d'), 'remarks' => 'Isolated QA scenario',
        'department_id' => $fixture['department'], 'recipient_employee_id' => $fixture['employee'],
    ];
    return save_document($type, $header, $lines, $fixture['actor'], $number ?? doc_number($type));
}

function inventory_issue_line(array $fixture, int $document): int
{
    return (int)scalar('SELECT l.id FROM stock_document_lines l JOIN stock_documents d ON d.id=l.document_id
        WHERE d.id=? AND d.created_by=? ORDER BY l.id LIMIT 1', [$document, $fixture['actor']]);
}

function inventory_line(int $material, string $quantity, ?int $from, ?int $to, array $extra = []): array
{
    return $extra + [
        'material_id' => $material, 'quantity' => $quantity, 'from_warehouse_id' => $from, 'to_warehouse_id' => $to,
    ];
}

function assert_balance(int $material, int $warehouse, string $amount, string $condition = 'GOOD'): void
{
    checked(bccomp(inventory_balance($material, $warehouse, $condition), $amount, 3) === 0,
        "material $material at warehouse $warehouse has $amount $condition");
}

/** A database or PHP error must never pass as the business rejection we expected. */
function expect_inventory_rejection(callable $action, string $fragment, string $label): void
{
    try {
        $action();
    } catch (InputError $error) {
        checked(str_contains(strtolower($error->getMessage()), strtolower($fragment)), $label . ' (' . $error->getMessage() . ')');
        return;
    }
    throw new RuntimeException("Expected rejection for $label.");
}

function assert_draft_untouched(int $document): void
{
    $status = scalar('SELECT status FROM stock_documents WHERE id=?', [$document]);
    $movements = scalar('SELECT COUNT(*) FROM stock_movements m JOIN stock_document_lines l ON l.id=m.document_line_id WHERE l.document_id=?', [$document]);
    checked($status === 'DRAFT' && (int)$movements === 0, "rejected document $document remains draft with zero movements");
}

function assert_serial(int $material, string $name, string $state, ?int $warehouse, ?string $condition): void
{
    $record = row('SELECT state,warehouse_id,stock_condition FROM material_serials WHERE material_id=? AND serial_number=?', [$material, $name]);
    checked($record && $record['state'] === $state && $record['warehouse_id'] == $warehouse
        && $record['stock_condition'] === $condition, "serial $name has state $state and the expected location");
}

function assert_reconciled(array $fixture): void
{
    $mismatch = scalar('SELECT COUNT(*) FROM v_stock_reconciliation WHERE material_id IN (?,?) AND variance<>0', [$fixture['wire'], $fixture['equipment']]);
    checked((int)$mismatch === 0, 'every stock balance for the fixture reconciles to its ledger');
}

function wire_acceptance(array $f): void
{
    ['wire' => $wire, 'a' => $a, 'b' => $b, 'actor' => $actor] = $f;
    $receiveLines = [inventory_line($wire, '100', null, $a, ['unit_cost' => '8.8123'])];
    $number = doc_number('RECEIPT');
    $receive = inventory_draft($f, 'RECEIPT', $receiveLines, $number);
    checked($receive === inventory_draft($f, 'RECEIPT', $receiveLines, $number), 'duplicate submission returns the original draft');
    assert_balance($wire, $a, '0');
    post_document($receive, $actor, 'RECEIPT');
    post_document($receive, $actor, 'RECEIPT');
    assert_balance($wire, $a, '100');
    $issue = inventory_draft($f, 'ISSUE', [inventory_line($wire, '30', $a, null)]);
    post_document($issue, $actor);
    assert_balance($wire, $a, '70');
    $source = inventory_issue_line($f, $issue);
    $return = inventory_draft($f, 'RETURN', [inventory_line($wire, '5', null, $a, ['return_of_line_id' => $source])]);
    post_document($return, $actor);
    assert_balance($wire, $a, '75');
    $transfer = inventory_draft($f, 'TRANSFER', [inventory_line($wire, '20', $a, $b)]);
    post_document($transfer, $actor);
    assert_balance($wire, $a, '55');
    assert_balance($wire, $b, '20');
    $oversell = inventory_draft($f, 'ISSUE', [inventory_line($wire, '60', $a, null)]);
    expect_inventory_rejection(fn() => post_document($oversell, $actor), 'insufficient stock', 'over-issuance rejected');
    assert_draft_untouched($oversell);
    assert_balance($wire, $a, '55');
    assert_reconciled($f);

    $overreturn = inventory_draft($f, 'RETURN', [inventory_line($wire, '25.001', null, $b, ['return_of_line_id' => $source])]);
    expect_inventory_rejection(fn() => post_document($overreturn, $actor), 'cumulative returns', 'cumulative excess return rejected');
    assert_draft_untouched($overreturn);
    $rollback = inventory_draft($f, 'ISSUE', [
        inventory_line($wire, '1.025', $b, null), inventory_line($wire, '55.001', $a, null),
    ]);
    expect_inventory_rejection(fn() => post_document($rollback, $actor), 'insufficient stock', 'failure on second line rolls back the entire issue');
    assert_draft_untouched($rollback);
    assert_balance($wire, $a, '55');
    assert_balance($wire, $b, '20');
    expect_inventory_rejection(fn() => reverse_document($receive, $actor, 'Cannot erase a consumed receipt'), 'later movements', 'dependent transactions block reversal');
    $reversal = reverse_document($transfer, $actor, 'Correct transfer');
    checked((int)scalar("SELECT COUNT(*) FROM stock_documents WHERE id=? AND status='POSTED' AND reverses_document_id=?", [$reversal, $transfer]) === 1,
        'posted reversal links to its original transfer');
    assert_balance($wire, $a, '75');
    assert_balance($wire, $b, '0');
    expect_inventory_rejection(fn() => reverse_document($transfer, $actor, 'Duplicate'), 'already reversed', 'duplicate reversal rejected');
    $opening = inventory_draft($f, 'OPENING', [inventory_line($wire, '0.125', null, $b)]);
    post_document($opening, $actor);
    assert_balance($wire, $b, '0.125');
    assert_reconciled($f);
}

function serialized_acceptance(array $f): void
{
    ['a' => $a, 'b' => $b, 'equipment' => $material, 'actor' => $actor] = $f;
    $first = $f['tag'] . '-01';
    $second = $f['tag'] . '-02';
    $receiptLine = fn(string $quantity, array $serials) => inventory_line($material, $quantity, null, $a, ['serials' => $serials]);
    expect_inventory_rejection(fn() => inventory_draft($f, 'RECEIPT', [$receiptLine('1.500', [$first])]),
        'whole-number', 'fractional serialized quantity rejected');
    expect_inventory_rejection(fn() => inventory_draft($f, 'RECEIPT', [$receiptLine('2', [$first, $first])]),
        'appears twice', 'duplicate names on a line rejected');
    $receipt = inventory_draft($f, 'RECEIPT', [$receiptLine('2', [$first, $second])]);
    post_document($receipt, $actor);
    assert_serial($material, $first, 'IN_STOCK', $a, 'GOOD');
    $duplicate = inventory_draft($f, 'RECEIPT', [$receiptLine('1', [$first])]);
    expect_inventory_rejection(fn() => post_document($duplicate, $actor), 'serial', 'previously received serial rejected');
    assert_draft_untouched($duplicate);
    $transfer = inventory_draft($f, 'TRANSFER', [inventory_line($material, '1', $a, $b, ['serials' => [$first]])]);
    post_document($transfer, $actor);
    assert_serial($material, $first, 'IN_STOCK', $b, 'GOOD');
    $wrong = inventory_draft($f, 'ISSUE', [inventory_line($material, '1', $a, null, ['serials' => [$first]])]);
    expect_inventory_rejection(fn() => post_document($wrong, $actor), 'serial', 'serial in the wrong warehouse rejected');
    assert_draft_untouched($wrong);
    $issue = inventory_draft($f, 'ISSUE', [inventory_line($material, '1', $b, null, ['serials' => [$first]])]);
    post_document($issue, $actor);
    assert_serial($material, $first, 'ISSUED', null, null);
    $otherIssue = inventory_draft($f, 'ISSUE', [inventory_line($material, '1', $a, null, ['serials' => [$second]])]);
    post_document($otherIssue, $actor);
    $source = inventory_issue_line($f, $issue);
    $returnLine = fn(string $serial) => inventory_line($material, '1', null, $b,
        ['serials' => [$serial], 'return_of_line_id' => $source, 'to_condition' => 'DAMAGED']);
    $wrongOriginal = inventory_draft($f, 'RETURN', [$returnLine($second)]);
    expect_inventory_rejection(fn() => post_document($wrongOriginal, $actor), 'original issue', 'serial belonging to a different issue rejected');
    assert_draft_untouched($wrongOriginal);
    $returned = inventory_draft($f, 'RETURN', [$returnLine($first)]);
    post_document($returned, $actor);
    assert_balance($material, $b, '0');
    assert_balance($material, $b, '1', 'DAMAGED');
    assert_serial($material, $first, 'IN_STOCK', $b, 'DAMAGED');
    expect_inventory_rejection(fn() => reverse_document($issue, $actor, 'Issue has a damaged return'),
        'outstanding return', 'return into another condition prevents reversing the original issue');
    $disposal = inventory_draft($f, 'DISPOSAL', [inventory_line($material, '1', $b, null,
        ['serials' => [$first], 'from_condition' => 'DAMAGED'])]);
    expect_inventory_rejection(fn() => post_document($disposal, $actor), 'second', 'disposal requires independent approval');
    assert_draft_untouched($disposal);
    $_SESSION['user_id'] = $f['reviewer'];
    post_document($disposal, $f['reviewer']);
    assert_serial($material, $first, 'DISPOSED', null, null);
    assert_balance($material, $b, '0', 'DAMAGED');
    $_SESSION['user_id'] = $actor;
    reverse_document($disposal, $actor, 'Restore inspected equipment');
    assert_serial($material, $first, 'IN_STOCK', $b, 'DAMAGED');
    assert_balance($material, $b, '1', 'DAMAGED');
    assert_reconciled($f);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $fixture = inventory_fixture();
    wire_acceptance($fixture);
    serialized_acceptance($fixture);
}
