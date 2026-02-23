<?php
require_once 'auth.php';
require_once 'config.php';

$conn = getDBConnection();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    header('Location: index.php');
    exit;
}

$so = $conn->prepare("SELECT * FROM sales_order_forms WHERE id = ? AND deleted_at IS NULL");
$so->execute([$id]);
$data = $so->fetch();
if (!$data) {
    header('Location: index.php');
    exit;
}

$existingItems = $conn->prepare("SELECT * FROM sales_order_items WHERE sales_order_id = ? AND deleted_at IS NULL");
$existingItems->execute([$id]);
$savedItems = $existingItems->fetchAll();

$inventories = $conn->query("SELECT i.id, i.stock_code, i.stock_name, i.uom FROM inventories i WHERE i.deleted_at IS NULL ORDER BY i.stock_name")->fetchAll();

$errors = [];

function generateUuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'customer_name',
        'tin_no',
        'order_date',
        'address',
        'contact_details',
        'payment_terms',
        'contact_person',
        'required_delivery_date',
        'deliver_to',
        'remarks',
        'special_instruction',
        'status'
    ];
    foreach ($fields as $f) $data[$f] = $_POST[$f] ?? $data[$f];
    $data['is_new'] = isset($_POST['is_new']) ? 1 : 0;

    if (!$data['customer_name']) $errors[] = 'Customer name is required.';
    if (!$data['order_date']) $errors[] = 'SO Date is required.';

    $items = [];
    foreach ($_POST['items'] ?? [] as $item) {
        if (!empty($item['inventory_id']) && !empty($item['quantity']) && (float)$item['quantity'] > 0) {
            $items[] = $item;
        }
    }
    if (empty($items)) $errors[] = 'At least one item is required.';

    if (empty($errors)) {
        $total = array_sum(array_map(fn($i) => (float)$i['unit_price'] * (float)$i['quantity'], $items));

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("UPDATE sales_order_forms SET
                customer_name=?, tin_no=?, order_date=?, address=?,
                contact_details=?, payment_terms=?, contact_person=?, required_delivery_date=?,
                deliver_to=?, is_new=?, remarks=?,
                special_instruction=?, status=?, total_amount=?, updated_by=?, updated_at=NOW()
                WHERE id=?");
            $stmt->execute([
                $data['customer_name'],
                $data['tin_no'],
                $data['order_date'],
                $data['address'],
                $data['contact_details'],
                $data['payment_terms'],
                $data['contact_person'],
                $data['required_delivery_date'] ?: null,
                $data['deliver_to'],
                $data['is_new'],
                $data['remarks'],
                $data['special_instruction'],
                $data['status'],
                $total,
                $_SESSION['user_id'],
                $id
            ]);

            $submittedIds = array_filter(array_column($items, 'item_id'));
            if (!empty($submittedIds)) {
                $placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
                $conn->prepare("UPDATE sales_order_items SET deleted_at=NOW(), updated_by=?
                    WHERE sales_order_id=? AND deleted_at IS NULL AND id NOT IN ($placeholders)")
                    ->execute(array_merge([$_SESSION['user_id'], $id], $submittedIds));
            } else {
                $conn->prepare("UPDATE sales_order_items SET deleted_at=NOW(), updated_by=?
                    WHERE sales_order_id=? AND deleted_at IS NULL")
                    ->execute([$_SESSION['user_id'], $id]);
            }

            $istmt_insert = $conn->prepare("INSERT INTO sales_order_items
                (uuid, sales_order_uuid, sales_order_id, inventory_id, item_code, item_description, uom, quantity, unit_price, amount, created_by, updated_by, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");

            $istmt_update = $conn->prepare("UPDATE sales_order_items SET
                inventory_id=?, item_code=?, item_description=?, uom=?, quantity=?, unit_price=?, amount=?, updated_by=?, updated_at=NOW()
                WHERE id=? AND sales_order_id=?");

            foreach ($items as $item) {
                $amt = (float)$item['unit_price'] * (float)$item['quantity'];
                $existingItemId = !empty($item['item_id']) ? (int)$item['item_id'] : 0;

                if ($existingItemId) {
                    $istmt_update->execute([
                        $item['inventory_id'],
                        $item['item_code'] ?? '',
                        $item['item_description'] ?? '',
                        $item['uom'] ?? '',
                        $item['quantity'],
                        $item['unit_price'] ?? 0,
                        $amt,
                        $_SESSION['user_id'],
                        $existingItemId,
                        $id
                    ]);
                } else {
                    $istmt_insert->execute([
                        generateUuid(),
                        $data['uuid'],   // <-- SO uuid from the loaded record
                        $id,
                        $item['inventory_id'],
                        $item['item_code'] ?? '',
                        $item['item_description'] ?? '',
                        $item['uom'] ?? '',
                        $item['quantity'],
                        $item['unit_price'] ?? 0,
                        $amt,
                        $_SESSION['user_id'],
                        $_SESSION['user_id']
                    ]);
                }
            }
            $conn->commit();
            header('Location: view.php?id=' . $id . '&msg=updated');
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }

    $savedItems = [];
    foreach ($_POST['items'] ?? [] as $item) {
        $savedItems[] = $item;
    }
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
</head>

<body class="bg-gray-100 min-h-screen">

    <!-- Navbar (yellow/warning theme to match original) -->
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
        <h4 class="text-xl font-bold text-gray-800 mb-4">
            Edit Order <span class="text-gray-400 font-normal">#<?= htmlspecialchars($data['id']) ?></span>
        </h4>

        <!-- Errors -->
        <?php if (!empty($errors)): ?>
            <div class="mb-4 bg-red-50 border border-red-300 text-red-700 rounded px-4 py-3 text-sm">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="so-form">

            <!-- Order Information Card -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-4">
                <div class="p-5">
                    <div class="text-xs font-semibold uppercase tracking-widest text-gray-400 border-b-2 border-gray-100 pb-2 mb-5">
                        Order Information
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Order Date <span class="text-red-500">*</span></label>
                            <input type="date" name="order_date"
                                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 outline-none"
                                value="<?= htmlspecialchars(substr($data['order_date'], 0, 10)) ?>" required>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Customer Name <span class="text-red-500">*</span></label>
                            <input type="text" name="customer_name"
                                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 outline-none"
                                value="<?= htmlspecialchars($data['customer_name']) ?>" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Is New Customer?</label>
                            <label class="flex items-center gap-2 mt-2 cursor-pointer">
                                <input type="checkbox" name="is_new" value="1" <?= $data['is_new'] ? 'checked' : '' ?>
                                    class="w-4 h-4 text-yellow-500 border-gray-300 rounded">
                                <span class="text-sm text-gray-700">Yes</span>
                            </label>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">TIN No.</label>
                            <input type="text" name="tin_no"
                                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 outline-none"
                                value="<?= htmlspecialchars($data['tin_no']) ?>">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Address</label>
                            <input type="text" name="address"
                                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 outline-none"
                                value="<?= htmlspecialchars($data['address']) ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Contact Person</label>
                            <input type="text" name="contact_person"
                                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 outline-none"
                                value="<?= htmlspecialchars($data['contact_person']) ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Contact Details</label>
                            <input type="text" name="contact_details"
                                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 outline-none"
                                value="<?= htmlspecialchars($data['contact_details']) ?>">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Payment Terms</label>
                            <input type="text" name="payment_terms"
                                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 outline-none"
                                value="<?= htmlspecialchars($data['payment_terms']) ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Deliver To</label>
                            <input type="text" name="deliver_to"
                                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 outline-none"
                                value="<?= htmlspecialchars($data['deliver_to']) ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Required Delivery Date</label>
                            <input type="date" name="required_delivery_date"
                                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 outline-none"
                                value="<?= htmlspecialchars(substr($data['required_delivery_date'] ?? '', 0, 10)) ?>">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Remarks</label>
                            <textarea name="remarks" rows="2"
                                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 outline-none"><?= htmlspecialchars($data['remarks']) ?></textarea>
                        </div>

                        <div class="sm:col-span-2">
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Special Instruction</label>
                            <textarea name="special_instruction" rows="2"
                                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 outline-none"><?= htmlspecialchars($data['special_instruction']) ?></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Status</label>
                            <select name="status"
                                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:border-yellow-400 focus:ring-1 focus:ring-yellow-300 outline-none bg-white">
                                <?php foreach (['for adjustment', 'for so','cancelled'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $data['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Order Items Card -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-4">
                <div class="p-5">
                    <div class="flex items-center justify-between mb-4">
                        <div class="text-xs font-semibold uppercase tracking-widest text-gray-400">Order Items</div>
                        <button type="button" id="add-row"
                            class="bg-green-500 hover:bg-green-600 text-white text-sm font-medium px-3 py-1.5 rounded flex items-center gap-1">
                            <i class="bi bi-plus-lg"></i> Add Item
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm border border-gray-200 rounded" id="items-table">
                            <thead class="bg-gray-50">
                                <tr>
                                    <?php foreach (['Item', 'Item Code', 'Description', 'UOM', 'Qty', 'Unit Price', 'Amount', ''] as $th): ?>
                                        <th class="px-2 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-400 border-b border-gray-200"><?= $th ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody id="items-body" class="divide-y divide-gray-100">
                                <?php foreach ($savedItems as $idx => $item): ?>
                                    <tr class="item-row">
                                        <input type="hidden" name="items[<?= $idx ?>][item_id]" value="<?= htmlspecialchars($item['id'] ?? '') ?>">
                                        <input type="hidden" name="items[<?= $idx ?>][item_uuid]" value="<?= htmlspecialchars($item['uuid'] ?? '') ?>">
                                        <td class="px-2 py-1.5" style="min-width:220px">
                                            <select name="items[<?= $idx ?>][inventory_id]"
                                                class="inv-select w-full border border-gray-300 rounded px-2 py-1 text-sm bg-white focus:border-yellow-400 outline-none" required>
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
                                        </td>
                                        <td class="px-2 py-1.5">
                                            <input type="text" name="items[<?= $idx ?>][item_code]"
                                                class="item-code border border-gray-300 rounded px-2 py-1 text-sm w-24 focus:border-yellow-400 outline-none"
                                                value="<?= htmlspecialchars($item['item_code'] ?? '') ?>">
                                        </td>
                                        <td class="px-2 py-1.5">
                                            <input type="text" name="items[<?= $idx ?>][item_description]"
                                                class="item-desc border border-gray-300 rounded px-2 py-1 text-sm w-36 focus:border-yellow-400 outline-none"
                                                value="<?= htmlspecialchars($item['item_description'] ?? '') ?>">
                                        </td>
                                        <td class="px-2 py-1.5">
                                            <input type="text" name="items[<?= $idx ?>][uom]"
                                                class="item-uom border border-gray-300 rounded px-2 py-1 text-sm w-16 focus:border-yellow-400 outline-none"
                                                value="<?= htmlspecialchars($item['uom'] ?? '') ?>">
                                        </td>
                                        <td class="px-2 py-1.5">
                                            <input type="number" name="items[<?= $idx ?>][quantity]"
                                                class="item-qty border border-gray-300 rounded px-2 py-1 text-sm w-20 focus:border-yellow-400 outline-none"
                                                min="0.0001" step="0.0001" value="<?= htmlspecialchars($item['quantity'] ?? 1) ?>" required>
                                        </td>
                                        <td class="px-2 py-1.5">
                                            <input type="number" name="items[<?= $idx ?>][unit_price]"
                                                class="item-price border border-gray-300 rounded px-2 py-1 text-sm w-24 focus:border-yellow-400 outline-none"
                                                min="0" step="0.01" value="<?= htmlspecialchars($item['unit_price'] ?? 0) ?>" required>
                                        </td>
                                        <td class="px-2 py-1.5">
                                            <input type="text"
                                                class="item-amount border border-gray-200 rounded px-2 py-1 text-sm w-24 bg-gray-50 text-gray-600"
                                                readonly value="<?= number_format(($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0), 2) ?>">
                                        </td>
                                        <td class="px-2 py-1.5">
                                            <button type="button"
                                                class="remove-row border border-red-300 text-red-500 hover:bg-red-50 rounded px-2 py-1 text-xs">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="bg-gray-50 border-t border-gray-200">
                                    <td colspan="6" class="px-3 py-2.5 text-right text-sm font-bold text-gray-700">Total Amount:</td>
                                    <td colspan="2" class="px-3 py-2.5">
                                        <span id="grand-total" class="font-bold text-yellow-600 text-sm">₱0.00</span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-2 mb-8">
                <button type="submit"
                    class="bg-yellow-400 hover:bg-yellow-500 text-gray-800 text-sm font-semibold px-4 py-2 rounded flex items-center gap-1">
                    <i class="bi bi-save"></i> Update Sales Order
                </button>
                <a href="view.php?id=<?= $id ?>"
                    class="border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm font-medium px-4 py-2 rounded">
                    Cancel
                </a>
            </div>

        </form>
    </div>

    <script>
        const inventories = <?= json_encode(array_combine(array_column($inventories, 'id'), $inventories)) ?>;
        let rowIndex = <?= count($savedItems) ?>;

        function recalcRow(row) {
            const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
            const price = parseFloat(row.querySelector('.item-price').value) || 0;
            row.querySelector('.item-amount').value = (qty * price).toFixed(2);
            recalcTotal();
        }

        function recalcTotal() {
            let total = 0;
            document.querySelectorAll('.item-amount').forEach(el => {
                total += parseFloat(el.value.replace(/,/g, '')) || 0;
            });
            document.getElementById('grand-total').textContent = '₱' + total.toLocaleString('en-PH', {
                minimumFractionDigits: 2
            });
        }

        function attachRowEvents(row) {
            row.querySelector('.inv-select').addEventListener('change', function() {
                const inv = inventories[this.value];
                if (inv) {
                    row.querySelector('.item-code').value = inv.stock_code;
                    row.querySelector('.item-desc').value = inv.stock_name;
                    row.querySelector('.item-uom').value = inv.uom;
                }
            });
            row.querySelector('.item-qty').addEventListener('input', () => recalcRow(row));
            row.querySelector('.item-price').addEventListener('input', () => recalcRow(row));
            row.querySelector('.remove-row').addEventListener('click', function() {
                if (document.querySelectorAll('.item-row').length > 1) {
                    row.remove();
                    recalcTotal();
                }
            });
        }

        document.querySelectorAll('.item-row').forEach(row => {
            attachRowEvents(row);
            recalcRow(row);
        });

        document.getElementById('add-row').addEventListener('click', function() {
            const tbody = document.getElementById('items-body');
            const tr = document.createElement('tr');
            tr.className = 'item-row';
            const invOptions = Object.values(inventories).map(inv =>
                `<option value="${inv.id}" data-code="${inv.stock_code}" data-name="${inv.stock_name}" data-uom="${inv.uom}">${inv.stock_code} - ${inv.stock_name}</option>`
            ).join('');
            tr.innerHTML = `
        <input type="hidden" name="items[${rowIndex}][item_id]" value="">
        <td class="px-2 py-1.5" style="min-width:220px">
            <select name="items[${rowIndex}][inventory_id]" class="inv-select w-full border border-gray-300 rounded px-2 py-1 text-sm bg-white focus:border-yellow-400 outline-none" required>
                <option value="">-- Select Item --</option>${invOptions}
            </select>
        </td>
        <td class="px-2 py-1.5"><input type="text" name="items[${rowIndex}][item_code]" class="item-code border border-gray-300 rounded px-2 py-1 text-sm w-24 focus:border-yellow-400 outline-none"></td>
        <td class="px-2 py-1.5"><input type="text" name="items[${rowIndex}][item_description]" class="item-desc border border-gray-300 rounded px-2 py-1 text-sm w-36 focus:border-yellow-400 outline-none"></td>
        <td class="px-2 py-1.5"><input type="text" name="items[${rowIndex}][uom]" class="item-uom border border-gray-300 rounded px-2 py-1 text-sm w-16 focus:border-yellow-400 outline-none"></td>
        <td class="px-2 py-1.5"><input type="number" name="items[${rowIndex}][quantity]" class="item-qty border border-gray-300 rounded px-2 py-1 text-sm w-20 focus:border-yellow-400 outline-none" min="0.0001" step="0.0001" value="1" required></td>
        <td class="px-2 py-1.5"><input type="number" name="items[${rowIndex}][unit_price]" class="item-price border border-gray-300 rounded px-2 py-1 text-sm w-24 focus:border-yellow-400 outline-none" min="0" step="0.01" value="0" required></td>
        <td class="px-2 py-1.5"><input type="text" class="item-amount border border-gray-200 rounded px-2 py-1 text-sm w-24 bg-gray-50 text-gray-600" readonly value="0.00"></td>
        <td class="px-2 py-1.5"><button type="button" class="remove-row border border-red-300 text-red-500 hover:bg-red-50 rounded px-2 py-1 text-xs"><i class="bi bi-trash"></i></button></td>
    `;
            tbody.appendChild(tr);
            attachRowEvents(tr);
            rowIndex++;
        });

        recalcTotal();
    </script>
</body>

</html>