<?php
require_once 'auth.php';
require_once 'config.php';

$conn = getDBConnection();
$id   = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

// AJAX
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    if ($_GET['ajax'] === 'barangays') {
        $mid = (int)($_GET['municipality_id'] ?? 0);
        if (!$mid) { echo json_encode([]); exit; }
        $s = $conn->prepare("SELECT barangay_id, barangay_name FROM table_barangay WHERE municipality_id = ? ORDER BY barangay_name");
        $s->execute([$mid]);
        echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
    } elseif ($_GET['ajax'] === 'provinces') {
        $rid = (int)($_GET['region_id'] ?? 0);
        if ($rid) { $s = $conn->prepare("SELECT province_id, province_name, region_id FROM table_province WHERE region_id = ? ORDER BY province_name"); $s->execute([$rid]); }
        else $s = $conn->query("SELECT province_id, province_name, region_id FROM table_province ORDER BY province_name");
        echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
    } elseif ($_GET['ajax'] === 'municipalities') {
        $pid = (int)($_GET['province_id'] ?? 0);
        if ($pid) {
            $s = $conn->prepare("SELECT municipality_id, municipality_name, province_id FROM table_municipality WHERE province_id = ? ORDER BY municipality_name");
            $s->execute([$pid]);
        } else {
            $s = $conn->query("SELECT m.municipality_id, m.municipality_name, m.province_id, p.province_name, p.region_id FROM table_municipality m JOIN table_province p ON p.province_id = m.province_id ORDER BY m.municipality_name");
        }
        echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
    } else {
        echo json_encode([]);
    }
    exit;
}

$so = $conn->prepare("SELECT * FROM sales_order_forms WHERE id = ? AND deleted_at IS NULL");
$so->execute([$id]);
$data = $so->fetch();
if (!$data) { header('Location: index.php'); exit; }

$siStmt = $conn->prepare("SELECT * FROM sales_order_items WHERE sales_order_id = ? AND deleted_at IS NULL");
$siStmt->execute([$id]);
$savedItems = $siStmt->fetchAll();

$inventories       = $conn->query("SELECT i.id, i.stock_code, i.stock_name, i.uom FROM inventories i WHERE i.deleted_at IS NULL ORDER BY i.stock_name")->fetchAll();
$uoms              = $conn->query("SELECT id, uom_name, uom_code FROM uoms ORDER BY uom_name")->fetchAll();
$userName = $_SESSION['user_name'];

$customers = $conn->prepare("
    SELECT id, full_name, address 
    FROM customers 
    WHERE LOWER(sales_person) LIKE LOWER(?)
    ORDER BY full_name
");
$customers->execute(['%' . $userName . '%']);
$customers = $customers->fetchAll();
$paymentTerms      = $conn->query("SELECT id, description FROM payment_terms ORDER BY description")->fetchAll(PDO::FETCH_ASSOC);
$regions           = $conn->query("SELECT region_id, region_description FROM table_region ORDER BY region_description")->fetchAll(PDO::FETCH_ASSOC);
$allProvinces      = $conn->query("SELECT province_id, province_name, region_id FROM table_province ORDER BY province_name")->fetchAll(PDO::FETCH_ASSOC);
$allMunicipalities = $conn->query("SELECT m.municipality_id, m.municipality_name, m.province_id, p.province_name, p.region_id, r.region_description
    FROM table_municipality m
    JOIN table_province p ON p.province_id = m.province_id
    JOIN table_region r ON r.region_id = p.region_id
    ORDER BY m.municipality_name")->fetchAll(PDO::FETCH_ASSOC);

$errors = [];

function generateUuid() {
    return strtoupper(sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['customer_name','tin_no','order_date','address','billing_address',
               'contact_details','payment_terms','contact_person','required_delivery_date',
               'deliver_to','remarks','special_instruction','status',
               'lot_no','barangay','municipality','province','region', 'attachment'];
    foreach ($fields as $f) $data[$f] = $_POST[$f] ?? $data[$f];
    $data['is_new'] = isset($_POST['is_new']) ? 1 : 0;

    if (!$data['customer_name']) $errors[] = 'Customer name is required.';
    if (!$data['order_date'])    $errors[] = 'Order Date is required.';

    $locationFields = ['barangay','municipality','province','lot_no'];
    if (array_filter(array_map(fn($f) => trim($data[$f]), $locationFields)) && empty(trim($data['region'])))
        $errors[] = 'Region is required when any location field is provided.';

    $items = array_filter($_POST['items'] ?? [], fn($i) => !empty($i['inventory_id']) && (float)($i['quantity'] ?? 0) > 0);
    if (empty($items)) $errors[] = 'At least one item is required.';

    if (empty($errors)) {
        $total = array_sum(array_map(fn($i) => (float)$i['unit_price'] * (float)$i['quantity'], $items));
        $conn->beginTransaction();
        try {
            $conn->prepare("UPDATE sales_order_forms SET
                customer_name=?, tin_no=?, order_date=?, address=?, billing_address=?,
                lot_no=?, barangay=?, municipality=?, province=?, region=?,
                contact_details=?, payment_terms=?, contact_person=?, required_delivery_date=?,
                deliver_to=?, is_new=?, remarks=?, special_instruction=?, status=?,
                attachment=?, total_amount=?, updated_by=?, updated_at=NOW() WHERE id=?")
            ->execute([
                $data['customer_name'], $data['tin_no'], $data['order_date'],
                $data['address'], $data['billing_address'],
                $data['lot_no'], $data['barangay'], $data['municipality'], $data['province'], $data['region'],
                $data['contact_details'], $data['payment_terms'], $data['contact_person'],
                $data['required_delivery_date'] ?: null, $data['deliver_to'],
                $data['is_new'], $data['remarks'], $data['special_instruction'], $data['status'],
                $data['attachment'],
                $total, $_SESSION['user_id'], $id
            ]);

            $submittedIds = array_filter(array_column(array_values($items), 'item_id'));
            if (!empty($submittedIds)) {
                $ph = implode(',', array_fill(0, count($submittedIds), '?'));
                $conn->prepare("UPDATE sales_order_items SET deleted_at=NOW(), updated_by=?
                    WHERE sales_order_id=? AND deleted_at IS NULL AND id NOT IN ($ph)")
                    ->execute(array_merge([$_SESSION['user_id'], $id], array_values($submittedIds)));
            } else {
                $conn->prepare("UPDATE sales_order_items SET deleted_at=NOW(), updated_by=?
                    WHERE sales_order_id=? AND deleted_at IS NULL")
                    ->execute([$_SESSION['user_id'], $id]);
            }

            $ins = $conn->prepare("INSERT INTO sales_order_items
                (uuid, sales_order_uuid, sales_order_id, inventory_id, item_code, item_description, uom, quantity, unit_price, amount, created_by, updated_by, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
            $upd = $conn->prepare("UPDATE sales_order_items SET
                inventory_id=?, item_code=?, item_description=?, uom=?, quantity=?, unit_price=?, amount=?, updated_by=?, updated_at=NOW()
                WHERE id=? AND sales_order_id=?");

            foreach ($items as $item) {
                $amt = (float)$item['unit_price'] * (float)$item['quantity'];
                $eid = (int)($item['item_id'] ?? 0);
                if ($eid) {
                    $upd->execute([$item['inventory_id'], $item['item_code']??'', $item['item_description']??'',
                        $item['uom']??'', $item['quantity'], $item['unit_price']??0, $amt, $_SESSION['user_id'], $eid, $id]);
                } else {
                    $ins->execute([generateUuid(), $data['uuid'], $id, $item['inventory_id'],
                        $item['item_code']??'', $item['item_description']??'', $item['uom']??'',
                        $item['quantity'], $item['unit_price']??0, $amt, $_SESSION['user_id'], $_SESSION['user_id']]);
                }
            }
            $conn->commit();
            header('Location: view.php?id='.$id.'&msg=updated'); exit;
        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = 'Database error: '.$e->getMessage();
        }
    }

    $savedItems = array_values($items);
}

// Current status for button state logic
$currentStatus = $data['status'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Sales Order</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="styles/loader.css">
    <link rel="stylesheet" href="styles/edit-style.css">
    <style>
        input[type=number]::-webkit-inner-spin-button { opacity: 1; }
        select, input, textarea { outline: none; }

        #items-body {
            display: flex !important;
            flex-direction: column !important;
            gap: 1rem !important;
        }

        .item-row {
            display: flex !important;
            flex-direction: column !important;
            gap: 0.75rem !important;
            padding: 1rem !important;
            background: #f9fafb !important;
            border: 1px solid #e5e7eb !important;
            border-radius: 0.375rem !important;
        }

        .item-field {
            display: flex !important;
            flex-direction: column !important;
            gap: 0.25rem !important;
            width: 100% !important;
        }

        .item-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: 0.05em;
        }

        .item-input {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            padding: 0.5rem 0.625rem;
            font-size: 0.875rem;
            background-color: #fff;
        }

        .item-input:focus {
            border-color: #fbbf24;
            outline: none;
            box-shadow: 0 0 0 1px #fde68a;
        }

        .item-btn-remove {
            padding: 0.5rem 1rem;
            border: 1px solid #fca5a5;
            background-color: #fff;
            color: #ef4444;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            cursor: pointer;
        }

        .item-btn-remove:hover {
            background-color: #fee2e2;
        }

        .items-total {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            align-items: center;
            padding: 0.75rem 1rem;
            background-color: #f3f4f6;
            border-top: 1px solid #e5e7eb;
        }

        .items-total-label {
            font-size: 0.875rem;
            font-weight: 700;
            color: #374151;
        }

        .items-total-amount {
            font-weight: 700;
            color: #d97706;
            font-size: 0.875rem;
        }

        /* Status buttons */
        .status-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.4rem 0.85rem;
            border-radius: 0.375rem;
            border: 2px solid transparent;
            cursor: pointer;
            transition: opacity 0.15s, box-shadow 0.15s;
        }
        .status-btn:not(:disabled):hover {
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        /* Active (current) status — disabled appearance */
        .status-btn:disabled {
            cursor: not-allowed;
            opacity: 0.38;
            box-shadow: none;
        }
        /* Draft — gray */
        .status-btn-draft {
            background-color: #f3f4f6;
            color: #374151;
            border-color: #d1d5db;
        }
        .status-btn-draft:not(:disabled):hover {
            background-color: #e5e7eb;
        }
        /* For Approval — blue */
        .status-btn-approval {
            background-color: #2563eb;
            color: #fff;
            border-color: #1d4ed8;
        }
        .status-btn-approval:not(:disabled):hover {
            background-color: #1d4ed8;
        }
        /* Cancelled — red */
        .status-btn-cancelled {
            background-color: #ef4444;
            color: #fff;
            border-color: #dc2626;
        }
        .status-btn-cancelled:not(:disabled):hover {
            background-color: #dc2626;
        }
        /* Current status badge shown next to label */
        .current-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .sd-wrapper { position: relative; }
        .sd-input {
            width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 4px;
            padding: 6px 28px 6px 10px; font-size: 0.875rem;
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24'%3E%3Cpath fill='%236b7280' d='M7 10l5 5 5-5z'/%3E%3C/svg%3E") no-repeat right 8px center;
            cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sd-input:focus { border-color: #fbbf24; box-shadow: 0 0 0 1px #fde68a; outline: none; }
        .sd-dropdown {
            display: none; position: absolute; z-index: 9999; left: 0; right: 0; top: calc(100% + 2px);
            background: #fff; border: 1px solid #d1d5db; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,.12);
        }
        .sd-search { width: 100%; box-sizing: border-box; border: none; border-bottom: 1px solid #e5e7eb; padding: 7px 10px; font-size: 0.8rem; outline: none; }
        .sd-list { max-height: 200px; overflow-y: auto; }
        .sd-item { padding: 6px 10px; font-size: 0.85rem; cursor: pointer; }
        .sd-item:hover { background: #eff6ff; }
        .sd-item .sd-hint { font-size: 0.75rem; color: #9ca3af; }
        .sd-empty { padding: 8px 10px; font-size: 0.8rem; color: #9ca3af; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

<nav class="bg-yellow-400 shadow mb-2">
    <div class="max-w-full px-4 py-3 flex items-center justify-between">
        <a href="index.php" class="text-gray-800 font-bold text-xs xl:text-lg flex items-center gap-2 hover:text-gray-600">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <span class="text-gray-700 text-sm flex items-center gap-1">
            <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['user_name']) ?>
        </span>
    </div>
</nav>

<div class="max-w-full px-2">
    <h4 class="text-xl font-bold text-gray-800 hidden xl:block mb-4">
        Edit Order <span class="text-gray-400 font-normal">#<?= htmlspecialchars($data['id']) ?></span>
        <?php
            $badgeClass = match($currentStatus) {
                'for approval' => 'bg-blue-100 text-blue-700',
                'cancelled'    => 'bg-red-100 text-red-600',
                default        => 'bg-gray-200 text-gray-600',
            };
            $badgeIcon = match($currentStatus) {
                'for approval' => 'bi-hourglass-split',
                'cancelled'    => 'bi-x-circle',
                default        => 'bi-pencil-square',
            };
        ?>
        <span class="current-status-badge <?= $badgeClass ?>">
            <i class="bi <?= $badgeIcon ?>"></i>
            <?= htmlspecialchars(ucfirst($currentStatus)) ?>
        </span>
    </h4>

    <?php if (!empty($errors)): ?>
        <div class="mb-4 bg-red-50 border border-red-300 text-red-700 rounded px-4 py-3 text-sm">
            <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="so-form">
        <!-- Holds current status; overridden by status buttons when clicked -->
        <input type="hidden" name="status" id="status-hidden" value="<?= htmlspecialchars($currentStatus) ?>">

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-4">
            <div class="p-2">
                <div class="text-xs text-center font-semibold uppercase tracking-widest text-gray-400 border-b-2 border-gray-100 pb-2 mb-2">Order Information</div>
                <div class="grid grid-cols-1 xl:grid-cols-4 gap-2">

                    <div class="col-span-1">
                        <label class="block text-sm font-semibold text-gray-700">Order Date <span class="text-red-500">*</span></label>
                        <input type="date" name="order_date" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300" value="<?= htmlspecialchars(substr($data['order_date'],0,10)) ?>" required>
                    </div>

                    <div class="col-span-1 xl:col-span-3">
                        <label class="block text-sm font-semibold text-gray-700">Customer Name <span class="text-red-500">*</span></label>
                        <div class="sd-wrapper" id="customer-wrapper">
                            <input type="hidden" name="customer_name" id="customer-name-value" value="<?= htmlspecialchars($data['customer_name']) ?>">
                            <input type="text" class="sd-input" id="customer-display" placeholder="-- Select Customer --" readonly
                                value="<?= htmlspecialchars($data['customer_name']) ?>">
                            <div class="sd-dropdown" id="customer-dropdown">
                                <input type="text" class="sd-search" placeholder="Search customer...">
                                <div class="sd-list">
                                    <?php foreach ($customers as $c): ?>
                                        <div class="sd-item"
                                            data-value="<?= htmlspecialchars($c['full_name']) ?>"
                                            data-address="<?= htmlspecialchars($c['address']) ?>">
                                            <?= htmlspecialchars($c['full_name']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-span-1 hidden">
                        <label class="block text-sm font-semibold text-gray-700">Is New Customer?</label>
                        <label class="flex items-center gap-2 mt-2 cursor-pointer">
                            <input type="checkbox" name="is_new" value="1" <?= $data['is_new'] ? 'checked' : '' ?> class="w-4 h-4 text-yellow-500 border-gray-300 rounded">
                            <span class="text-sm text-gray-700">Yes</span>
                        </label>
                    </div>

                    <div class="col-span-1 xl:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700">Billing Address</label>
                        <input type="text" name="billing_address" id="billing-address-field" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm bg-gray-50 text-gray-600" value="<?= htmlspecialchars($data['billing_address'] ?? '') ?>" readonly>
                    </div>

                    <div class="col-span-1 xl:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700">TIN No.</label>
                        <input type="text" name="tin_no" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300" value="<?= htmlspecialchars($data['tin_no']) ?>">
                    </div>

                    <div class="col-span-1 xl:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700">Delivery Address</label>
                        <input type="text" name="address" id="address-field" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm bg-gray-50 text-gray-600" value="<?= htmlspecialchars($data['address']) ?>" readonly>
                    </div>

                    <div class="col-span-1 xl:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700">Address Line <span class="text-sm text-gray-500">(Lot, Blk, House #, Street)</span></label>
                        <input type="text" name="lot_no" id="lot-no-field" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300" value="<?= htmlspecialchars($data['lot_no'] ?? '') ?>">
                    </div>

                    <div class="col-span-1 hidden">
                        <label class="block text-sm font-semibold text-gray-700">Region</label>
                        <div class="sd-wrapper" id="region-wrapper">
                            <input type="text" class="sd-input" id="region-display" placeholder="-- Select Region --" readonly value="<?= htmlspecialchars($data['region']) ?>">
                            <input type="hidden" name="region" id="region-value" value="<?= htmlspecialchars($data['region']) ?>">
                            <div class="sd-dropdown" id="region-dropdown">
                                <input type="text" class="sd-search" placeholder="Search region...">
                                <div class="sd-list">
                                    <?php foreach ($regions as $r): ?>
                                        <div class="sd-item" data-value="<?= htmlspecialchars($r['region_description']) ?>" data-id="<?= $r['region_id'] ?>"><?= htmlspecialchars($r['region_description']) ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-span-1">
                        <label class="block text-sm font-semibold text-gray-700">Province/City</label>
                        <div class="sd-wrapper" id="province-wrapper">
                            <input type="text" class="sd-input text-sm" id="province-display" placeholder="-- Select Province --" readonly value="<?= htmlspecialchars($data['province']) ?>">
                            <input type="hidden" name="province" id="province-value" value="<?= htmlspecialchars($data['province']) ?>">
                            <div class="sd-dropdown" id="province-dropdown">
                                <input type="text" class="sd-search text-sm" placeholder="Search province...">
                                <div class="sd-list">
                                    <?php foreach ($allProvinces as $p): ?>
                                        <div class="sd-item text-sm" data-value="<?= htmlspecialchars($p['province_name']) ?>" data-id="<?= $p['province_id'] ?>" data-region-id="<?= $p['region_id'] ?>"><?= htmlspecialchars($p['province_name']) ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-span-1">
                        <label class="block text-sm font-semibold text-gray-700">Municipality</label>
                        <div class="sd-wrapper text-sm" id="municipality-wrapper">
                            <input type="text" class="sd-input text-sm" id="municipality-display" placeholder="-- Select Municipality --" readonly value="<?= htmlspecialchars($data['municipality']) ?>">
                            <input type="hidden" name="municipality" id="municipality-value" value="<?= htmlspecialchars($data['municipality']) ?>">
                            <div class="sd-dropdown text-sm" id="municipality-dropdown">
                                <input type="text" class="sd-search text-sm" placeholder="Search municipality...">
                                <div class="sd-list">
                                    <?php foreach ($allMunicipalities as $m): ?>
                                        <div class="sd-item text-sm"
                                             data-value="<?= htmlspecialchars($m['municipality_name']) ?>"
                                             data-id="<?= $m['municipality_id'] ?>"
                                             data-province="<?= htmlspecialchars($m['province_name']) ?>"
                                             data-province-id="<?= $m['province_id'] ?>"
                                             data-region="<?= htmlspecialchars($m['region_description']) ?>"
                                             data-region-id="<?= $m['region_id'] ?>">
                                            <?= htmlspecialchars($m['municipality_name']) ?>
                                            <span class="sd-hint">(<?= htmlspecialchars($m['province_name']) ?>)</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-span-1">
                        <label class="block text-sm font-semibold text-gray-700">Barangay</label>
                        <div class="sd-wrapper text-sm" id="barangay-wrapper">
                            <input type="text" class="sd-input text-sm" id="barangay-display" placeholder="Type to search barangay..." readonly value="<?= htmlspecialchars($data['barangay']) ?>">
                            <input type="hidden" name="barangay" id="barangay-value" value="<?= htmlspecialchars($data['barangay']) ?>">
                            <div class="sd-dropdown text-sm" id="barangay-dropdown">
                                <input type="text" class="sd-search text-sm" placeholder="Search barangay...">
                                <div class="sd-list text-sm" id="barangay-list">
                                    <div class="sd-empty text-sm">Select a municipality first</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-span-1">
                        <label class="block text-sm font-semibold text-gray-700">Contact Details</label>
                        <input type="text" name="contact_details" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300" value="<?= htmlspecialchars($data['contact_details']) ?>">
                    </div>

                    <div class="col-span-1 xl:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700">Contact Person</label>
                        <input type="text" name="contact_person" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300" value="<?= htmlspecialchars($data['contact_person']) ?>">
                    </div>

                    <div class="col-span-1">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Payment Terms</label>
                        <select name="payment_terms" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 bg-white">
                            <option value="">-- Select --</option>
                            <?php foreach ($paymentTerms as $pt): ?>
                                <option value="<?= htmlspecialchars($pt['description']) ?>" <?= $data['payment_terms'] === $pt['description'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pt['description']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!--<div>
                        <label class="block text-sm font-semibold text-gray-700">Deliver To</label>
                        <input type="text" name="deliver_to" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300" value="<?= htmlspecialchars($data['deliver_to']) ?>">
                    </div>-->

                    <div class="col-span-1">
                        <label class="block text-sm font-semibold text-gray-700">Required Delivery Date</label>
                        <input type="date" name="required_delivery_date" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300" value="<?= htmlspecialchars(substr($data['required_delivery_date'] ?? '',0,10)) ?>">
                    </div>

                    <div class="col-span-1 xl:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700">Remarks</label>
                        <textarea name="remarks" rows="5" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300"><?= htmlspecialchars($data['remarks']) ?></textarea>
                    </div>

                    <div class="col-span-1 xl:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700">Special Instruction</label>
                        <textarea name="special_instruction" rows="5" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300"><?= htmlspecialchars($data['special_instruction']) ?></textarea>
                    </div>
                    <div class="col-span-1 xl:col-span-4">
                        <label class="block text-sm font-semibold text-gray-700">Attachment</label>

                        <?php if (!empty($data['attachment'])): ?>
                        <div id="attachment-current" class="mb-1 text-xs text-gray-500 flex items-center gap-2">
                            <i class="bi bi-paperclip"></i>
                            <span>Current attachment saved</span>
                            <button type="button" id="attachment-remove-current"
                                class="text-red-400 hover:text-red-600 text-xs">✕ Remove</button>
                        </div>
                        <?php endif; ?>

                        <input type="file" id="attachment-file" accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx"
                            class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 bg-white">
                        <input type="hidden" name="attachment" id="attachment-b64"
                            value="<?= htmlspecialchars($data['attachment'] ?? '') ?>">
                        <div id="attachment-preview" class="mt-1 text-xs text-gray-500 hidden">
                            <span id="attachment-filename"></span>
                            <button type="button" id="attachment-clear" class="ml-2 text-red-400 hover:text-red-600">✕ Remove</button>
                        </div>
                    </div>
                    <div class="col-span-1 xl:col-span-4">
                        <?php if (!empty($data['attachment'])): ?>
                            <?php
                                $attachment = $data['attachment'];
                                $mime = '';
                                if (preg_match('/^data:([a-zA-Z0-9\/+\-]+);base64,/', $attachment, $matches)) {
                                    $mime = $matches[1];
                                }
                                $isImage = in_array($mime, ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp']);
                                $isPdf   = $mime === 'application/pdf';
                            ?>

                            <div class="mt-2 border border-gray-200 rounded overflow-hidden">

                                <?php if ($isImage): ?>
                                    <img src="<?= htmlspecialchars($attachment) ?>"
                                        alt="Current Attachment"
                                        class="max-w-full"
                                        style="max-height: 400px; object-fit: contain;">

                                <?php elseif ($isPdf): ?>
                                    <iframe src="<?= htmlspecialchars($attachment) ?>"
                                            class="w-full"
                                            style="height: 500px;"
                                            title="PDF Attachment">
                                    </iframe>

                                <?php else: ?>
                                    <div class="p-3 text-sm text-gray-500 flex items-center gap-2">
                                        <i class="bi bi-file-earmark text-gray-400 text-lg"></i>
                                        File attached — not previewable in browser.
                                    </div>
                                <?php endif; ?>

                                <div class="px-3 py-2 bg-gray-50 border-t border-gray-100 flex items-center justify-between">
                                    <span class="text-xs text-gray-400">Current attachment</span>
                                    <a href="<?= htmlspecialchars($attachment) ?>"
                                    download="attachment"
                                    class="text-xs text-blue-500 hover:text-blue-700 flex items-center gap-1">
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Items -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-4">
            <div class="p-3">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-xs font-semibold uppercase tracking-widest text-gray-400">Order Items</div>
                </div>

                <div id="items-body">
                    <?php foreach ($savedItems as $idx => $item): ?>
                    <div class="item-row">
                        <input type="hidden" name="items[<?= $idx ?>][item_id]" value="<?= htmlspecialchars($item['id'] ?? '') ?>">

                        <div class="item-field">
                            <label class="item-label">Item</label>
                            <input type="hidden" name="items[<?= $idx ?>][inventory_id]" class="inv-id-value" value="<?= htmlspecialchars($item['inventory_id'] ?? '') ?>">
                            <div class="sd-wrapper inv-sd-wrapper">
                                <input type="text" class="sd-input inv-display" placeholder="-- Select Item --" readonly
                                    value="<?= htmlspecialchars(isset($item['inventory_id']) ? ($item['item_code'] ?? '') . ' - ' . ($item['item_description'] ?? '') : '') ?>">
                                <div class="sd-dropdown">
                                    <input type="text" class="sd-search" placeholder="Search item...">
                                    <div class="sd-list">
                                        <?php foreach ($inventories as $inv): ?>
                                            <div class="sd-item"
                                                data-value="<?= $inv['id'] ?>"
                                                data-code="<?= htmlspecialchars($inv['stock_code']) ?>"
                                                data-name="<?= htmlspecialchars($inv['stock_name']) ?>"
                                                data-uom="<?= htmlspecialchars($inv['uom']) ?>"
                                                data-label="<?= htmlspecialchars($inv['stock_code'] . ' - ' . $inv['stock_description']) ?>">
                                                <?= htmlspecialchars($inv['stock_code'] . ' - ' . $inv['stock_description']) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="item-field">
                            <label class="item-label">Item Code</label>
                            <input type="text" name="items[<?= $idx ?>][item_code]" class="item-code item-input" value="<?= htmlspecialchars($item['item_code'] ?? '') ?>">
                        </div>

                        <div class="item-field">
                            <label class="item-label">Description</label>
                            <input type="text" name="items[<?= $idx ?>][item_description]" class="item-desc item-input" value="<?= htmlspecialchars($item['item_description'] ?? '') ?>">
                        </div>

                        <div class="item-field">
                            <label class="item-label">UOM</label>
                            <select name="items[<?= $idx ?>][uom]" class="item-uom item-input">
                                <option value="">--</option>
                                <?php foreach ($uoms as $u): ?>
                                    <option value="<?= htmlspecialchars($u['uom_name']) ?>" <?= ($item['uom'] ?? '') === $u['uom_name'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['uom_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="item-field">
                            <label class="item-label">Qty</label>
                            <input type="number" name="items[<?= $idx ?>][quantity]" class="item-qty item-input" min="0.0001" step="0.0001" value="<?= htmlspecialchars($item['quantity'] ?? 1) ?>" required>
                        </div>

                        <div class="item-field">
                            <label class="item-label">Unit Price</label>
                            <input type="number" name="items[<?= $idx ?>][unit_price]" class="item-price item-input" min="0" step="0.01" value="<?= htmlspecialchars($item['unit_price'] ?? 0) ?>" required>
                        </div>

                        <div class="item-field">
                            <label class="item-label">Amount</label>
                            <input type="text" class="item-amount item-input" readonly value="<?= number_format(($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0), 2) ?>">
                        </div>

                        <div class="item-field">
                            <button type="button" class="remove-row item-btn-remove">
                                <i class="bi bi-trash"></i> Remove
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="flex items-center justify-between my-2">
                    <button type="button" id="add-row" class="bg-green-500 hover:bg-green-600 text-white text-sm font-medium px-3 py-1.5 rounded flex items-center gap-1">
                        <i class="bi bi-plus-lg"></i> Add Item
                    </button>
                </div>

                <div class="items-total">
                    <span class="items-total-label">Total Amount:</span>
                    <span id="grand-total" class="items-total-amount">₱0.00</span>
                </div>
            </div>
        </div>
        <!-- Bottom action bar — single block, responsive -->
        <div class="flex flex-col xl:flex-row xl:justify-between w-full gap-3 my-4">

            <!-- Left: Cancel -->
            <a href="view.php?id=<?= $id ?>"
            id="btn-cancel"
            class="border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm font-medium px-4 py-2 rounded text-center">
                Cancel
            </a>

            <!-- Right: status buttons + update -->
            <div class="flex flex-col xl:flex-row flex-wrap items-stretch xl:items-center gap-2">

                <!-- Status: Order Draft -->
                <button type="button"
                        class="status-btn status-btn-draft"
                        data-status="order draft"
                        <?= $currentStatus === 'order draft' ? 'disabled title="Current status"' : '' ?>>
                    <span class="btn-idle flex items-center justify-center gap-1">
                        <i class="bi bi-pencil-square"></i> Order Draft
                        <?php if ($currentStatus === 'order draft'): ?>
                            <i class="bi bi-check2 ml-0.5"></i>
                        <?php endif; ?>
                    </span>
                    <span class="btn-loading hidden items-center justify-center gap-1">
                        <svg class="animate-spin w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                        Saving…
                    </span>
                </button>

                <!-- Status: For Approval -->
                <button type="button"
                        class="status-btn status-btn-approval"
                        data-status="for approval"
                        <?= $currentStatus === 'for approval' ? 'disabled title="Current status"' : '' ?>>
                    <span class="btn-idle flex items-center justify-center gap-1">
                        <i class="bi bi-hourglass-split"></i> For Approval
                        <?php if ($currentStatus === 'for approval'): ?>
                            <i class="bi bi-check2 ml-0.5"></i>
                        <?php endif; ?>
                    </span>
                    <span class="btn-loading hidden items-center justify-center gap-1">
                        <svg class="animate-spin w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                        Saving…
                    </span>
                </button>

                <!-- Status: Cancelled -->
                <button type="button"
                        class="status-btn status-btn-cancelled"
                        data-status="cancelled"
                        <?= $currentStatus === 'cancelled' ? 'disabled title="Current status"' : '' ?>>
                    <span class="btn-idle flex items-center justify-center gap-1">
                        <i class="bi bi-x-circle"></i> Cancelled
                        <?php if ($currentStatus === 'cancelled'): ?>
                            <i class="bi bi-check2 ml-0.5"></i>
                        <?php endif; ?>
                    </span>
                    <span class="btn-loading hidden items-center justify-center gap-1">
                        <svg class="animate-spin w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                        Saving…
                    </span>
                </button>

                <!-- Divider (desktop only) -->
                <span class="hidden xl:inline text-gray-300 text-lg font-thin">|</span>

                <!-- Update Sales Order -->
                <button type="submit"
                        id="btn-update"
                        class="bg-yellow-400 hover:bg-yellow-500 text-gray-800 text-sm font-semibold px-4 py-2 rounded flex items-center justify-center gap-1 transition-opacity">
                    <span id="btn-update-idle" class="flex items-center gap-1">
                        <i class="bi bi-save"></i> Update Sales Order
                    </span>
                    <span id="btn-update-loading" class="hidden items-center gap-1">
                        <svg class="animate-spin w-4 h-4 text-gray-800" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                        Saving…
                    </span>
                </button>

            </div>
        </div>
    </form>
</div>

<script>
window.appConfig = {
    inventories: <?= json_encode(array_combine(array_column($inventories, 'id'), $inventories)) ?>,
    uoms: <?= json_encode($uoms) ?>,
    saved: {
        region:       <?= json_encode($data['region']) ?>,
        province:     <?= json_encode($data['province']) ?>,
        municipality: <?= json_encode($data['municipality']) ?>,
        barangay:     <?= json_encode($data['barangay']) ?>,
    },
    rowIndex: <?= count($savedItems) ?>,
    editId: <?= $id ?>
};
</script>
<script src="js/editing.js"></script>
<script>
(function () {
    const form         = document.getElementById('so-form');
    const statusHidden = document.getElementById('status-hidden');
    const btnCancel    = document.getElementById('btn-cancel');
    const btnUpdate    = document.getElementById('btn-update');
    let   submitting   = false;  // flag — prevents double-fire

    function showSpinner(btn) {
        const idle    = btn.querySelector('.btn-idle, #btn-update-idle');
        const loading = btn.querySelector('.btn-loading, #btn-update-loading');
        if (idle)    idle.classList.add('hidden');
        if (loading) { loading.classList.remove('hidden'); loading.classList.add('flex'); }
    }

    function lockAll() {
        btnCancel.classList.add('opacity-50', 'pointer-events-none');
        document.querySelectorAll('.status-btn, #btn-update').forEach(b => {
            b.disabled = true;
            b.classList.add('opacity-70', 'cursor-not-allowed');
        });
    }

    // Status buttons
    document.querySelectorAll('.status-btn:not([disabled])').forEach(btn => {
        btn.addEventListener('click', function () {
            if (submitting) return;
            submitting = true;
            statusHidden.value = this.dataset.status;
            showSpinner(this);
            lockAll();
            form.submit();
        });
    });

    // Update button (native form submit)
    form.addEventListener('submit', function (e) {
        if (submitting) { e.preventDefault(); return; }
        submitting = true;
        showSpinner(btnUpdate);
        lockAll();
    });
})();
</script>
<script src="js/loading.js"></script>
<script>
(function () {
    const fileInput     = document.getElementById('attachment-file');
    const b64Input      = document.getElementById('attachment-b64');
    const preview       = document.getElementById('attachment-preview');
    const filenameEl    = document.getElementById('attachment-filename');
    const clearBtn      = document.getElementById('attachment-clear');
    const currentDiv    = document.getElementById('attachment-current');
    const removeCurrentBtn = document.getElementById('attachment-remove-current');

    fileInput.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function (e) {
            b64Input.value = e.target.result;
            filenameEl.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
            preview.classList.remove('hidden');
            if (currentDiv) currentDiv.classList.add('hidden');
        };
        reader.readAsDataURL(file);
    });

    clearBtn && clearBtn.addEventListener('click', function () {
        fileInput.value = '';
        b64Input.value  = '';
        preview.classList.add('hidden');
        filenameEl.textContent = '';
    });

    removeCurrentBtn && removeCurrentBtn.addEventListener('click', function () {
        b64Input.value = '';
        currentDiv.classList.add('hidden');
    });
})();
</script>
</body>
</html>