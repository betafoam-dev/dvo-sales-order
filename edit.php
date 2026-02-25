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
        $s = $rid
            ? $conn->prepare("SELECT province_id, province_name, region_id FROM table_province WHERE region_id = ? ORDER BY province_name") && false
            : null;
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

$inventories    = $conn->query("SELECT i.id, i.stock_code, i.stock_name, i.uom FROM inventories i WHERE i.deleted_at IS NULL ORDER BY i.stock_name")->fetchAll();
$uoms           = $conn->query("SELECT id, uom_name, uom_code FROM uoms ORDER BY uom_name")->fetchAll();
$customers      = $conn->query("SELECT id, full_name, address FROM customers ORDER BY full_name")->fetchAll();
$paymentTerms = $conn->query("SELECT id, description FROM payment_terms ORDER BY description")->fetchAll(PDO::FETCH_ASSOC);
$regions        = $conn->query("SELECT region_id, region_description FROM table_region ORDER BY region_description")->fetchAll(PDO::FETCH_ASSOC);
$allProvinces   = $conn->query("SELECT province_id, province_name, region_id FROM table_province ORDER BY province_name")->fetchAll(PDO::FETCH_ASSOC);
$allMunicipalities = $conn->query("SELECT m.municipality_id, m.municipality_name, m.province_id, p.province_name, p.region_id, r.region_description
    FROM table_municipality m
    JOIN table_province p ON p.province_id = m.province_id
    JOIN table_region r ON r.region_id = p.region_id
    ORDER BY m.municipality_name")->fetchAll(PDO::FETCH_ASSOC);

$errors = [];

function generateUuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['customer_name','tin_no','order_date','address','billing_address',
               'contact_details','payment_terms','contact_person','required_delivery_date',
               'deliver_to','remarks','special_instruction','status',
               'lot_no','barangay','municipality','province','region'];
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
                total_amount=?, updated_by=?, updated_at=NOW() WHERE id=?")
            ->execute([
                $data['customer_name'], $data['tin_no'], $data['order_date'],
                $data['address'], $data['billing_address'],
                $data['lot_no'], $data['barangay'], $data['municipality'], $data['province'], $data['region'],
                $data['contact_details'], $data['payment_terms'], $data['contact_person'],
                $data['required_delivery_date'] ?: null, $data['deliver_to'],
                $data['is_new'], $data['remarks'], $data['special_instruction'], $data['status'],
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Sales Order</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
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
        .sd-input:focus { border-color: #fbbf24; box-shadow: 0 0 0 1px #fde68a; }
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

<nav class="bg-yellow-400 shadow mb-6">
    <div class="max-w-full px-4 py-3 flex items-center justify-between">
        <a href="index.php" class="text-gray-800 font-bold text-lg flex items-center gap-2 hover:text-gray-600">
            <i class="bi bi-arrow-left"></i> Sales Orders
        </a>
        <span class="text-gray-700 text-sm flex items-center gap-1">
            <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['user_name']) ?>
        </span>
    </div>
</nav>

<div class="max-w-full px-4">
    <h4 class="text-xl font-bold text-gray-800 mb-4">Edit Order <span class="text-gray-400 font-normal">#<?= htmlspecialchars($data['id']) ?></span></h4>

    <?php if (!empty($errors)): ?>
        <div class="mb-4 bg-red-50 border border-red-300 text-red-700 rounded px-4 py-3 text-sm">
            <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="so-form">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-4">
            <div class="p-5">
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-400 border-b-2 border-gray-100 pb-2 mb-5">Order Information</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Order Date <span class="text-red-500">*</span></label>
                        <input type="date" name="order_date" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300" value="<?= htmlspecialchars(substr($data['order_date'],0,10)) ?>" required>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Customer Name <span class="text-red-500">*</span></label>
                        <select name="customer_name" id="customer-select" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 bg-white" required>
                            <option value="">-- Select Customer --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= htmlspecialchars($c['full_name']) ?>" data-address="<?= htmlspecialchars($c['address']) ?>" <?= $data['customer_name'] === $c['full_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Is New Customer?</label>
                        <label class="flex items-center gap-2 mt-2 cursor-pointer">
                            <input type="checkbox" name="is_new" value="1" <?= $data['is_new'] ? 'checked' : '' ?> class="w-4 h-4 text-yellow-500 border-gray-300 rounded">
                            <span class="text-sm text-gray-700">Yes</span>
                        </label>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Billing Address</label>
                        <input type="text" name="billing_address" id="billing-address-field" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm bg-gray-50 text-gray-600" value="<?= htmlspecialchars($data['billing_address'] ?? '') ?>" readonly>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">TIN No.</label>
                        <input type="text" name="tin_no" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300" value="<?= htmlspecialchars($data['tin_no']) ?>">
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Complete Address</label>
                        <input type="text" name="address" id="address-field" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm bg-gray-50 text-gray-600" value="<?= htmlspecialchars($data['address']) ?>" readonly>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Address Line <span class="text-xs text-gray-500">(Lot, Blk, House #, Street)</span></label>
                        <input type="text" name="lot_no" id="lot-no-field" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300" value="<?= htmlspecialchars($data['lot_no'] ?? '') ?>">
                    </div>

                    <div class="hidden">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Region</label>
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
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Province/City</label>
                        <div class="sd-wrapper" id="province-wrapper">
                            <input type="text" class="sd-input" id="province-display" placeholder="-- Select Province --" readonly value="<?= htmlspecialchars($data['province']) ?>">
                            <input type="hidden" name="province" id="province-value" value="<?= htmlspecialchars($data['province']) ?>">
                            <div class="sd-dropdown" id="province-dropdown">
                                <input type="text" class="sd-search" placeholder="Search province...">
                                <div class="sd-list">
                                    <?php foreach ($allProvinces as $p): ?>
                                        <div class="sd-item" data-value="<?= htmlspecialchars($p['province_name']) ?>" data-id="<?= $p['province_id'] ?>" data-region-id="<?= $p['region_id'] ?>"><?= htmlspecialchars($p['province_name']) ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Municipality</label>
                        <div class="sd-wrapper" id="municipality-wrapper">
                            <input type="text" class="sd-input" id="municipality-display" placeholder="-- Select Municipality --" readonly value="<?= htmlspecialchars($data['municipality']) ?>">
                            <input type="hidden" name="municipality" id="municipality-value" value="<?= htmlspecialchars($data['municipality']) ?>">
                            <div class="sd-dropdown" id="municipality-dropdown">
                                <input type="text" class="sd-search" placeholder="Search municipality...">
                                <div class="sd-list">
                                    <?php foreach ($allMunicipalities as $m): ?>
                                        <div class="sd-item"
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
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Barangay</label>
                        <div class="sd-wrapper" id="barangay-wrapper">
                            <input type="text" class="sd-input" id="barangay-display" placeholder="Type to search barangay..." readonly value="<?= htmlspecialchars($data['barangay']) ?>">
                            <input type="hidden" name="barangay" id="barangay-value" value="<?= htmlspecialchars($data['barangay']) ?>">
                            <div class="sd-dropdown" id="barangay-dropdown">
                                <input type="text" class="sd-search" placeholder="Search barangay...">
                                <div class="sd-list" id="barangay-list">
                                    <div class="sd-empty">Select a municipality first</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Contact Person</label>
                        <input type="text" name="contact_person" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300" value="<?= htmlspecialchars($data['contact_person']) ?>">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Contact Details</label>
                        <input type="text" name="contact_details" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300" value="<?= htmlspecialchars($data['contact_details']) ?>">
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
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Deliver To</label>
                        <input type="text" name="deliver_to" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300" value="<?= htmlspecialchars($data['deliver_to']) ?>">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Required Delivery Date</label>
                        <input type="date" name="required_delivery_date" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300" value="<?= htmlspecialchars(substr($data['required_delivery_date'] ?? '',0,10)) ?>">
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Remarks</label>
                        <textarea name="remarks" rows="2" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300"><?= htmlspecialchars($data['remarks']) ?></textarea>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Special Instruction</label>
                        <textarea name="special_instruction" rows="2" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300"><?= htmlspecialchars($data['special_instruction']) ?></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Status</label>
                        <select name="status" class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 bg-white">
                            <?php foreach (['for adjustment','for so','cancelled'] as $s): ?>
                                <option value="<?= $s ?>" <?= $data['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-4">
            <div class="p-5">
                <div class="flex items-center justify-between mb-4">
                    <div class="text-xs font-semibold uppercase tracking-widest text-gray-400">Order Items</div>
                    <button type="button" id="add-row" class="bg-green-500 hover:bg-green-600 text-white text-sm font-medium px-3 py-1.5 rounded flex items-center gap-1">
                        <i class="bi bi-plus-lg"></i> Add Item
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm border border-gray-200 rounded" id="items-table">
                        <thead class="bg-gray-50">
                            <tr>
                                <?php foreach (['Item','Item Code','Description','UOM','Qty','Unit Price','Amount',''] as $th): ?>
                                    <th class="px-2 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-400 border-b border-gray-200"><?= $th ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody id="items-body" class="divide-y divide-gray-100">
                            <?php foreach ($savedItems as $idx => $item): ?>
                            <tr class="item-row">
                                <input type="hidden" name="items[<?= $idx ?>][item_id]" value="<?= htmlspecialchars($item['id'] ?? '') ?>">
                                <td class="px-2 py-1.5" style="min-width:220px">
                                    <select name="items[<?= $idx ?>][inventory_id]" class="inv-select w-full border border-gray-300 rounded px-2 py-1 text-sm bg-white focus:border-yellow-400" required>
                                        <option value="">-- Select Item --</option>
                                        <?php foreach ($inventories as $inv): ?>
                                            <option value="<?= $inv['id'] ?>" data-code="<?= htmlspecialchars($inv['stock_code']) ?>" data-name="<?= htmlspecialchars($inv['stock_name']) ?>" data-uom="<?= htmlspecialchars($inv['uom']) ?>" <?= ($item['inventory_id'] ?? '') == $inv['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($inv['stock_code'].' - '.$inv['stock_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="px-2 py-1.5"><input type="text" name="items[<?= $idx ?>][item_code]" class="item-code border border-gray-300 rounded px-2 py-1 text-sm w-24 focus:border-yellow-400" value="<?= htmlspecialchars($item['item_code'] ?? '') ?>"></td>
                                <td class="px-2 py-1.5"><input type="text" name="items[<?= $idx ?>][item_description]" class="item-desc border border-gray-300 rounded px-2 py-1 text-sm w-36 focus:border-yellow-400" value="<?= htmlspecialchars($item['item_description'] ?? '') ?>"></td>
                                <td class="px-2 py-1.5">
                                    <select name="items[<?= $idx ?>][uom]" class="item-uom border border-gray-300 rounded px-2 py-1 text-sm bg-white focus:border-yellow-400 w-24">
                                        <option value="">--</option>
                                        <?php foreach ($uoms as $u): ?>
                                            <option value="<?= htmlspecialchars($u['uom_name']) ?>" <?= ($item['uom'] ?? '') === $u['uom_name'] ? 'selected' : '' ?>><?= htmlspecialchars($u['uom_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="px-2 py-1.5"><input type="number" name="items[<?= $idx ?>][quantity]" class="item-qty border border-gray-300 rounded px-2 py-1 text-sm w-20 focus:border-yellow-400" min="0.0001" step="0.0001" value="<?= htmlspecialchars($item['quantity'] ?? 1) ?>" required></td>
                                <td class="px-2 py-1.5"><input type="number" name="items[<?= $idx ?>][unit_price]" class="item-price border border-gray-300 rounded px-2 py-1 text-sm w-24 focus:border-yellow-400" min="0" step="0.01" value="<?= htmlspecialchars($item['unit_price'] ?? 0) ?>" required></td>
                                <td class="px-2 py-1.5"><input type="text" class="item-amount border border-gray-200 rounded px-2 py-1 text-sm w-24 bg-gray-50 text-gray-600" readonly value="<?= number_format(($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0), 2) ?>"></td>
                                <td class="px-2 py-1.5"><button type="button" class="remove-row border border-red-300 text-red-500 hover:bg-red-50 rounded px-2 py-1 text-xs"><i class="bi bi-trash"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="bg-gray-50 border-t border-gray-200">
                                <td colspan="6" class="px-3 py-2.5 text-right text-sm font-bold text-gray-700">Total Amount:</td>
                                <td colspan="2" class="px-3 py-2.5"><span id="grand-total" class="font-bold text-yellow-600 text-sm">₱0.00</span></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 mb-8">
            <button type="submit" class="bg-yellow-400 hover:bg-yellow-500 text-gray-800 text-sm font-semibold px-4 py-2 rounded flex items-center gap-1">
                <i class="bi bi-save"></i> Update Sales Order
            </button>
            <a href="view.php?id=<?= $id ?>" class="border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm font-medium px-4 py-2 rounded">Cancel</a>
        </div>
    </form>
</div>

<script>
const inventories = <?= json_encode(array_combine(array_column($inventories, 'id'), $inventories)) ?>;
const uoms        = <?= json_encode($uoms) ?>;
const saved = {
    region:       <?= json_encode($data['region']) ?>,
    province:     <?= json_encode($data['province']) ?>,
    municipality: <?= json_encode($data['municipality']) ?>,
    barangay:     <?= json_encode($data['barangay']) ?>,
};

let rowIndex = <?= count($savedItems) ?>;

function initSD(wrapperId, onSelect) {
    const wrapper  = document.getElementById(wrapperId);
    const display  = wrapper.querySelector('.sd-input');
    const hidden   = wrapper.querySelector('input[type=hidden]');
    const dropdown = wrapper.querySelector('.sd-dropdown');
    const search   = wrapper.querySelector('.sd-search');
    const list     = wrapper.querySelector('.sd-list');

    function filterItems(q) {
        const lower = q.toLowerCase();
        let vis = 0;
        list.querySelectorAll('.sd-item').forEach(item => {
            const text = (item.firstChild?.nodeType === 3 ? item.firstChild.textContent : item.textContent).toLowerCase();
            const show = !q || text.includes(lower);
            item.style.display = show ? '' : 'none';
            if (show) vis++;
        });
        let emptyEl = list.querySelector('.sd-empty');
        if (vis === 0) {
            if (!emptyEl) { emptyEl = document.createElement('div'); emptyEl.className = 'sd-empty'; emptyEl.textContent = 'No results'; list.appendChild(emptyEl); }
            emptyEl.style.display = '';
        } else if (emptyEl) emptyEl.style.display = 'none';
    }

    function openDropdown() {
        document.querySelectorAll('.sd-dropdown').forEach(d => { if (d !== dropdown) d.style.display = 'none'; });
        dropdown.style.display = 'block';
        search.value = '';
        filterItems('');
        search.focus();
    }

    function closeDropdown() { dropdown.style.display = 'none'; }

    display.addEventListener('click', () => dropdown.style.display === 'block' ? closeDropdown() : openDropdown());
    display.addEventListener('keydown', e => {
        if (e.key === 'Escape') { closeDropdown(); return; }
        if (e.key === 'Backspace' || e.key === 'Delete') {
            display.value = hidden.value = '';
            closeDropdown();
            wrapper.dispatchEvent(new CustomEvent('sd-change', { detail: null }));
            if (onSelect) onSelect(null);
            syncAddress();
            return;
        }
        if (e.key.length === 1) { openDropdown(); search.value += e.key; filterItems(search.value); }
    });
    search.addEventListener('input', () => filterItems(search.value));
    list.addEventListener('mousedown', e => {
        const item = e.target.closest('.sd-item');
        if (!item || item.style.display === 'none') return;
        e.preventDefault();
        display.value = hidden.value = item.dataset.value;
        closeDropdown();
        wrapper.dispatchEvent(new CustomEvent('sd-change', { detail: { id: item.dataset.id, value: item.dataset.value, element: item } }));
        if (onSelect) onSelect(item);
    });
    document.addEventListener('click', e => { if (!wrapper.contains(e.target)) closeDropdown(); });
}

function syncAddress() {
    const parts = [
        document.getElementById('lot-no-field').value.trim(),
        document.getElementById('barangay-value').value.trim(),
        document.getElementById('municipality-value').value.trim(),
        document.getElementById('province-value').value.trim(),
        document.getElementById('region-value').value.trim(),
    ].filter(Boolean);
    document.getElementById('address-field').value = parts.join(', ');
}

document.getElementById('lot-no-field').addEventListener('input', syncAddress);

initSD('region-wrapper', () => syncAddress());

initSD('province-wrapper', item => {
    if (item) {
        const ri = document.querySelector(`#region-wrapper .sd-item[data-id="${item.dataset.regionId}"]`);
        if (ri) document.getElementById('region-display').value = document.getElementById('region-value').value = ri.dataset.value;
    }
    syncAddress();
});

document.getElementById('region-wrapper').addEventListener('sd-change', e => {
    const rid = e.detail?.id;
    const url = rid ? `edit.php?id=<?= $id ?>&ajax=provinces&region_id=${rid}` : `edit.php?id=<?= $id ?>&ajax=provinces`;
    fetch(url).then(r => r.json()).then(provinces => {
        document.querySelector('#province-wrapper .sd-list').innerHTML = provinces.map(p =>
            `<div class="sd-item" data-value="${p.province_name}" data-id="${p.province_id}" data-region-id="${p.region_id}">${p.province_name}</div>`
        ).join('');
    });
});

initSD('municipality-wrapper', item => {
    if (item) {
        document.getElementById('province-display').value = document.getElementById('province-value').value = item.dataset.province;
        document.getElementById('region-display').value   = document.getElementById('region-value').value   = item.dataset.region;
        document.getElementById('barangay-display').value = document.getElementById('barangay-value').value = '';
        loadBarangays(item.dataset.id);
    }
    syncAddress();
});

document.getElementById('province-wrapper').addEventListener('sd-change', e => {
    const pid = e.detail?.id;
    const url = pid ? `edit.php?id=<?= $id ?>&ajax=municipalities&province_id=${pid}` : `edit.php?id=<?= $id ?>&ajax=municipalities`;
    fetch(url).then(r => r.json()).then(municipalities => {
        const pname = document.getElementById('province-display').value;
        const rval  = document.getElementById('region-value').value;
        document.querySelector('#municipality-wrapper .sd-list').innerHTML = municipalities.map(m =>
            `<div class="sd-item" data-value="${m.municipality_name}" data-id="${m.municipality_id}"
                  data-province="${pname || m.province_name}" data-province-id="${m.province_id}"
                  data-region="${rval}" data-region-id="${m.region_id}">
                ${m.municipality_name} <span class="sd-hint">(${pname || m.province_name})</span>
            </div>`
        ).join('');
    });
});

initSD('barangay-wrapper', () => syncAddress());

function loadBarangays(municipalityId, preselect) {
    const list = document.getElementById('barangay-list');
    list.innerHTML = '<div class="sd-empty">Loading...</div>';
    fetch(`edit.php?id=<?= $id ?>&ajax=barangays&municipality_id=${municipalityId}`)
        .then(r => r.json())
        .then(barangays => {
            if (!barangays.length) { list.innerHTML = '<div class="sd-empty">No barangays found</div>'; return; }
            list.innerHTML = barangays.map(b =>
                `<div class="sd-item" data-value="${b.barangay_name}" data-id="${b.barangay_id}">${b.barangay_name}</div>`
            ).join('');
            if (preselect) {
                const match = [...list.querySelectorAll('.sd-item')].find(i => i.dataset.value === preselect);
                if (match) document.getElementById('barangay-display').value = document.getElementById('barangay-value').value = preselect;
            }
        });
}

(function restoreState() {
    if (!saved.municipality) return;
    const munItem = document.querySelector(`#municipality-wrapper .sd-item[data-value="${saved.municipality.replace(/"/g, '\\"')}"]`);
    if (munItem) loadBarangays(munItem.dataset.id, saved.barangay);
})();

function recalcRow(row) {
    const qty   = parseFloat(row.querySelector('.item-qty').value)   || 0;
    const price = parseFloat(row.querySelector('.item-price').value) || 0;
    row.querySelector('.item-amount').value = (qty * price).toFixed(2);
    recalcTotal();
}

function recalcTotal() {
    let total = 0;
    document.querySelectorAll('.item-amount').forEach(el => total += parseFloat(el.value.replace(/,/g,'')) || 0);
    document.getElementById('grand-total').textContent = '₱' + total.toLocaleString('en-PH', { minimumFractionDigits: 2 });
}

function attachRowEvents(row) {
    row.querySelector('.inv-select').addEventListener('change', function () {
        const inv = inventories[this.value];
        if (inv) { row.querySelector('.item-code').value = inv.stock_code; row.querySelector('.item-desc').value = inv.stock_name; row.querySelector('.item-uom').value = inv.uom; }
    });
    row.querySelector('.item-qty').addEventListener('input',   () => recalcRow(row));
    row.querySelector('.item-price').addEventListener('input', () => recalcRow(row));
    row.querySelector('.remove-row').addEventListener('click', () => {
        if (document.querySelectorAll('.item-row').length > 1) { row.remove(); recalcTotal(); }
    });
}

document.querySelectorAll('.item-row').forEach(row => { attachRowEvents(row); recalcRow(row); });

const uomOptions = uoms.map(u => `<option value="${u.uom_name}">${u.uom_name}</option>`).join('');
const invOptions = Object.values(inventories).map(inv =>
    `<option value="${inv.id}" data-code="${inv.stock_code}" data-name="${inv.stock_name}" data-uom="${inv.uom}">${inv.stock_code} - ${inv.stock_name}</option>`
).join('');

document.getElementById('add-row').addEventListener('click', function () {
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML = `
        <input type="hidden" name="items[${rowIndex}][item_id]" value="">
        <td class="px-2 py-1.5" style="min-width:220px">
            <select name="items[${rowIndex}][inventory_id]" class="inv-select w-full border border-gray-300 rounded px-2 py-1 text-sm bg-white focus:border-yellow-400" required>
                <option value="">-- Select Item --</option>${invOptions}
            </select>
        </td>
        <td class="px-2 py-1.5"><input type="text" name="items[${rowIndex}][item_code]" class="item-code border border-gray-300 rounded px-2 py-1 text-sm w-24 focus:border-yellow-400"></td>
        <td class="px-2 py-1.5"><input type="text" name="items[${rowIndex}][item_description]" class="item-desc border border-gray-300 rounded px-2 py-1 text-sm w-36 focus:border-yellow-400"></td>
        <td class="px-2 py-1.5">
            <select name="items[${rowIndex}][uom]" class="item-uom border border-gray-300 rounded px-2 py-1 text-sm bg-white focus:border-yellow-400 w-24">
                <option value="">--</option>${uomOptions}
            </select>
        </td>
        <td class="px-2 py-1.5"><input type="number" name="items[${rowIndex}][quantity]" class="item-qty border border-gray-300 rounded px-2 py-1 text-sm w-20 focus:border-yellow-400" min="0.0001" step="0.0001" value="1" required></td>
        <td class="px-2 py-1.5"><input type="number" name="items[${rowIndex}][unit_price]" class="item-price border border-gray-300 rounded px-2 py-1 text-sm w-24 focus:border-yellow-400" min="0" step="0.01" value="0" required></td>
        <td class="px-2 py-1.5"><input type="text" class="item-amount border border-gray-200 rounded px-2 py-1 text-sm w-24 bg-gray-50 text-gray-600" readonly value="0.00"></td>
        <td class="px-2 py-1.5"><button type="button" class="remove-row border border-red-300 text-red-500 hover:bg-red-50 rounded px-2 py-1 text-xs"><i class="bi bi-trash"></i></button></td>
    `;
    document.getElementById('items-body').appendChild(tr);
    attachRowEvents(tr);
    rowIndex++;
});

recalcTotal();

document.getElementById('customer-select').addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    document.getElementById('billing-address-field').value = opt.dataset.address ?? '';
});

(function () {
    const sel = document.getElementById('customer-select');
    if (sel.value) sel.dispatchEvent(new Event('change'));
})();
</script>
</body>
</html>