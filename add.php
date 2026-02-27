<?php
require_once 'auth.php';
require_once 'config.php';

$conn = getDBConnection();

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $ajax = $_GET['ajax'];

    if ($ajax === 'provinces') {
        $rid = (int)($_GET['region_id'] ?? 0);
        if ($rid) { $s = $conn->prepare("SELECT province_id, province_name, region_id FROM table_province WHERE region_id = ? ORDER BY province_name"); $s->execute([$rid]); }
        else $s = $conn->query("SELECT province_id, province_name, region_id FROM table_province ORDER BY province_name");
        echo json_encode($s->fetchAll(PDO::FETCH_ASSOC)); exit;
    }

    if ($ajax === 'municipalities') {
        $pid = (int)($_GET['province_id'] ?? 0);
        if ($pid) {
            $s = $conn->prepare("SELECT municipality_id, municipality_name, province_id FROM table_municipality WHERE province_id = ? ORDER BY municipality_name");
            $s->execute([$pid]);
        } else {
            $s = $conn->query("SELECT m.municipality_id, m.municipality_name, m.province_id, p.province_name, p.region_id FROM table_municipality m JOIN table_province p ON p.province_id = m.province_id ORDER BY m.municipality_name");
        }
        echo json_encode($s->fetchAll(PDO::FETCH_ASSOC)); exit;
    }

    if ($ajax === 'barangays') {
        $mid = (int)($_GET['municipality_id'] ?? 0);
        if (!$mid) { echo json_encode([]); exit; }
        $s = $conn->prepare("SELECT barangay_id, barangay_name, municipality_id FROM table_barangay WHERE municipality_id = ? ORDER BY barangay_name");
        $s->execute([$mid]);
        echo json_encode($s->fetchAll(PDO::FETCH_ASSOC)); exit;
    }

    echo json_encode(['error' => 'Invalid ajax action']); exit;
}

$errors = [];
$data = [
    'customer_name' => '', 'tin_no' => '', 'so_no' => '',
    'order_date' => date('Y-m-d') , 'address' => '', 'billing_address' => '', 'contact_details' => '',
    'payment_terms' => '', 'contact_person' => '', 'required_delivery_date' => '',
    'deliver_to' => '', 'is_new' => 0,
    'remarks' => '', 'special_instruction' => '', 'status' => 'for adjustment', 'total_amount' => 0,
    'lot_no' => '', 'barangay' => '', 'municipality' => '', 'province' => '', 'region' => ''
];

function generateUuid() {
    return strtoupper(sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)));
}

$inventories = $conn->query("SELECT i.id, i.stock_code, i.stock_name, i.uom, COALESCE(wi.item_qty, 0) AS qty_on_hand
    FROM inventories i LEFT JOIN warehouse_inventories wi ON wi.inventory_id = i.id AND wi.deleted_at IS NULL
    WHERE i.deleted_at IS NULL ORDER BY i.stock_name")->fetchAll();

$uoms      = $conn->query("SELECT id, uom_name, uom_code FROM uoms ORDER BY uom_name")->fetchAll();
$customers = $conn->query("SELECT id, full_name, address FROM customers ORDER BY full_name")->fetchAll();
$regions   = $conn->query("SELECT region_id, region_description FROM table_region ORDER BY region_description")->fetchAll(PDO::FETCH_ASSOC);
$paymentTerms = $conn->query("SELECT id, description FROM payment_terms ORDER BY description")->fetchAll(PDO::FETCH_ASSOC);

$allProvinces = $conn->query("SELECT province_id, province_name, region_id FROM table_province ORDER BY province_name")->fetchAll(PDO::FETCH_ASSOC);

$allMunicipalities = $conn->query("SELECT m.municipality_id, m.municipality_name, m.province_id,
    p.province_name, p.region_id, r.region_description
    FROM table_municipality m
    JOIN table_province p ON p.province_id = m.province_id
    JOIN table_region r ON r.region_id = p.region_id
    ORDER BY m.municipality_name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = array_merge($data, array_intersect_key($_POST, $data));
    if (!$data['customer_name']) $errors[] = 'Customer name is required.';
    if (!$data['order_date'])    $errors[] = 'Order Date is required.';

    $locationFields = ['barangay', 'municipality', 'province', 'lot_no'];
    if (array_filter(array_map(fn($f) => trim($data[$f]), $locationFields)) && empty(trim($data['region'])))
        $errors[] = 'Region is required when any location field is provided.';

    $items = array_filter($_POST['items'] ?? [], fn($i) => !empty($i['inventory_id']) && (float)($i['quantity'] ?? 0) > 0);
    if (empty($items)) $errors[] = 'At least one item is required.';

    if (empty($errors)) {
        $total = array_sum(array_map(fn($i) => (float)$i['unit_price'] * (float)$i['quantity'], $items));
        $conn->beginTransaction();
        try {
            $soUuid = generateUuid();
            $stmt = $conn->prepare("INSERT INTO sales_order_forms
                (sales_order_code, uuid, customer_name, tin_no, so_no, order_date, address, billing_address,
                 lot_no, barangay, municipality, province, region,
                 contact_details, payment_terms, contact_person, required_delivery_date,
                 deliver_to, is_new, remarks, special_instruction, status, total_amount,
                 created_by, updated_by, created_at, updated_at)
                VALUES ('',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
            $stmt->execute([
                $soUuid,
                $data['customer_name'], $data['tin_no'], $data['so_no'],
                $data['order_date'], $data['address'], $data['billing_address'],
                $data['lot_no'], $data['barangay'], $data['municipality'], $data['province'], $data['region'],
                $data['contact_details'], $data['payment_terms'],
                $data['contact_person'], $data['required_delivery_date'] ?: null, $data['deliver_to'],
                $data['is_new'] ? 1 : 0,
                $data['remarks'], $data['special_instruction'], $data['status'], $total,
                $_SESSION['user_id'], $_SESSION['user_id']
            ]);
            $soId = $conn->lastInsertId();

            $istmt = $conn->prepare("INSERT INTO sales_order_items
                (uuid, sales_order_uuid, sales_order_id, inventory_id, item_code, item_description, uom, quantity, unit_price, amount, created_by, updated_by, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
            foreach ($items as $item) {
                $istmt->execute([
                    generateUuid(), $soUuid, $soId,
                    $item['inventory_id'], $item['item_code'] ?? '', $item['item_description'] ?? '', $item['uom'] ?? '',
                    $item['quantity'], $item['unit_price'] ?? 0,
                    (float)$item['unit_price'] * (float)$item['quantity'],
                    $_SESSION['user_id'], $_SESSION['user_id']
                ]);
            }
            $conn->commit();
            header('Location: index.php?msg=saved'); exit;
        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$existingItems = $_POST['items'] ?? [[]];
if (empty($existingItems)) $existingItems = [[]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Sales Order</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="styles/add-style.css">
    <style>
        input[type=number]::-webkit-inner-spin-button { opacity: 1; }
        select, input, textarea { outline: none; }

        .sd-wrapper { position: relative; }
        .sd-input {
            width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 4px;
            padding: 6px 28px 6px 10px; font-size: 0.875rem;
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24'%3E%3Cpath fill='%236b7280' d='M7 10l5 5 5-5z'/%3E%3C/svg%3E") no-repeat right 8px center;
            cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sd-input:focus { border-color: #60a5fa; box-shadow: 0 0 0 1px #bfdbfe; outline: none; }
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

        /* ── Item rows: div card layout (your design) ── */
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
            border-color: #60a5fa;
            outline: none;
            box-shadow: 0 0 0 1px #bfdbfe;
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
            color: #2563eb;
            font-size: 0.875rem;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

<nav class="bg-blue-600 shadow mb-2">
    <div class="max-w-full px-4 py-3 flex items-center justify-between">
        <a href="index.php" class="text-white font-bold text-xs md:text-lg flex items-center gap-2 hover:text-blue-100">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <span class="text-blue-200 text-sm flex items-center gap-1">
            <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['user_name']) ?>
        </span>
    </div>
</nav>

<div class="max-w-full px-2">
    <h4 class="text-xl font-bold text-gray-800 hidden md:block mb-4">New Sales Order</h4>

    <?php if (!empty($errors)): ?>
        <div class="mb-4 bg-red-50 border border-red-300 text-red-700 rounded px-4 py-3 text-sm">
            <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="so-form">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-4">
            <div class="p-2">
                <div class="text-xs text-center font-semibold uppercase tracking-widest text-gray-400 border-b-2 border-gray-100 pb-2 mb-2">Order Information</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-2">

                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Order Date <span class="text-red-500">*</span></label>
                        <input type="date" name="order_date" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-300" value="<?= htmlspecialchars($data['order_date']) ?>" required>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700">Customer Name <span class="text-red-500">*</span></label>
                        <select name="customer_name" id="customer-select" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-300 bg-white" required>
                            <option value="">-- Select Customer --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= htmlspecialchars($c['full_name']) ?>"
                                        data-address="<?= htmlspecialchars($c['address']) ?>"
                                        <?= $data['customer_name'] === $c['full_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="hidden">
                        <label class="block text-sm font-semibold text-gray-700">Is New Customer?</label>
                        <label class="flex items-center gap-2 mt-2 cursor-pointer">
                            <input type="checkbox" name="is_new" value="1" <?= $data['is_new'] ? 'checked' : '' ?> class="w-4 h-4 text-blue-600 border-gray-300 rounded">
                            <span class="text-sm text-gray-700">Yes</span>
                        </label>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700">Billing Address</label>
                        <input type="text" name="billing_address" id="billing-address-field"
                            class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm bg-gray-50 text-gray-600"
                            value="<?= htmlspecialchars($data['billing_address'] ?? '') ?>" readonly>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700">TIN No.</label>
                        <input type="text" name="tin_no" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-300" value="<?= htmlspecialchars($data['tin_no']) ?>">
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700">Complete Address</label>
                        <input type="text" name="address" id="address-field" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-300" value="<?= htmlspecialchars($data['address']) ?>" readonly>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Address Line <span class="text-sm text-gray-500">(Lot, Blk, House #, Street)</span></label>
                        <input type="text" name="lot_no" id="lot-no-field" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-300" value="<?= htmlspecialchars($data['lot_no']) ?>">
                    </div>

                    <div class="hidden">
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

                    <div>
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

                    <div>
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

                    <div>
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

                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Contact Person</label>
                        <input type="text" name="contact_person" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-300" value="<?= htmlspecialchars($data['contact_person']) ?>">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Contact Details</label>
                        <input type="text" name="contact_details" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-300" value="<?= htmlspecialchars($data['contact_details']) ?>">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Payment Terms</label>
                        <select name="payment_terms" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-300 bg-white">
                            <option value="">-- Select --</option>
                            <?php foreach ($paymentTerms as $pt): ?>
                                <option value="<?= htmlspecialchars($pt['description']) ?>" <?= $data['payment_terms'] === $pt['description'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pt['description']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Deliver To</label>
                        <input type="text" name="deliver_to" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-300" value="<?= htmlspecialchars($data['deliver_to']) ?>">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Required Delivery Date</label>
                        <input type="date" name="required_delivery_date" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-300" value="<?= htmlspecialchars($data['required_delivery_date']) ?>">
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700">Remarks</label>
                        <textarea name="remarks" rows="5" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-300"><?= htmlspecialchars($data['remarks']) ?></textarea>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700">Special Instruction</label>
                        <textarea name="special_instruction" rows="5" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-300"><?= htmlspecialchars($data['special_instruction']) ?></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700">Status</label>
                        <select name="status" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-300 bg-white">
                            <?php foreach (['for adjustment','for so','cancelled'] as $s): ?>
                                <option value="<?= $s ?>" <?= $data['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── Order Items: div card layout ── -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-4">
            <div class="p-3">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-xs font-semibold uppercase tracking-widest text-gray-400">Order Items</div>
                </div>

                <div id="items-body">
                    <?php foreach ($existingItems as $idx => $item): ?>
                    <div class="item-row">
                        <div class="item-field">
                            <label class="item-label">Item</label>
                            <select name="items[<?= $idx ?>][inventory_id]" class="inv-select item-input" required>
                                <option value="">-- Select Item --</option>
                                <?php foreach ($inventories as $inv): ?>
                                    <option value="<?= $inv['id'] ?>"
                                        data-code="<?= htmlspecialchars($inv['stock_code']) ?>"
                                        data-name="<?= htmlspecialchars($inv['stock_name']) ?>"
                                        data-uom="<?= htmlspecialchars($inv['uom']) ?>"
                                        <?= ($item['inventory_id'] ?? '') == $inv['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($inv['stock_code'] . ' - ' . $inv['stock_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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

        <div class="flex flex-row justify-between w-full items-center gap-2 my-4">
            <a href="index.php" class="border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm font-medium px-4 py-2 rounded">Cancel</a>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded flex items-center gap-1">
                <i class="bi bi-save"></i> Save Sales Order
            </button>
        </div>
    </form>
</div>

<script>
window.appData = {
    inventories: <?= json_encode(array_combine(array_column($inventories, 'id'), $inventories)) ?>,
    uoms: <?= json_encode($uoms) ?>,
    saved: {
        region:       <?= json_encode($data['region']) ?>,
        province:     <?= json_encode($data['province']) ?>,
        municipality: <?= json_encode($data['municipality']) ?>,
        barangay:     <?= json_encode($data['barangay']) ?>,
    },
    rowIndex: <?= count($existingItems) ?>
};
</script>
<script src="js/add.js"></script>
<script src="js/searchable-select.js"></script>
<script>
// Customer SearchableSelect (partner's addition)
const customerSS = new SearchableSelect(document.getElementById('customer-select'), { placeholder: 'Search customer...' });
customerSS.wrapper.addEventListener('ss:change', e => {
    document.getElementById('billing-address-field').value = e.detail?.data?.address || '';
});

// Inventory items on existing rows (partner's addition)
document.querySelectorAll('.inv-select').forEach(sel => {
    const ss = new SearchableSelect(sel, { placeholder: 'Search item...' });
    ss.wrapper.addEventListener('ss:change', e => {
        if (!e.detail) return;
        const inv = (window.appData || window.appConfig).inventories[e.detail.value];
        if (!inv) return;
        const row = ss.wrapper.closest('.item-row');
        row.querySelector('.item-code').value = inv.stock_code;
        row.querySelector('.item-desc').value = inv.stock_name;
        row.querySelector('.item-uom').value  = inv.uom;
    });
});

// Patch add-row to init SearchableSelect on new div rows (partner's addition, updated for div)
const addRowBtn = document.getElementById('add-row');
addRowBtn.addEventListener('ss:init-row', e => {
    const row = e.detail;
    const sel = row.querySelector('.inv-select');
    if (!sel) return;
    const ss = new SearchableSelect(sel, { placeholder: 'Search item...' });
    ss.wrapper.addEventListener('ss:change', ev => {
        if (!ev.detail) return;
        const inv = (window.appData || window.appConfig).inventories[ev.detail.value];
        if (!inv) return;
        row.querySelector('.item-code').value = inv.stock_code;
        row.querySelector('.item-desc').value = inv.stock_name;
        row.querySelector('.item-uom').value  = inv.uom;
    });
});
</script>
</body>
</html>