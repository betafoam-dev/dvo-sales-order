<?php
require_once 'auth.php';
require_once 'config.php';

$conn = getDBConnection();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$so = $conn->prepare("SELECT sof.*, u.name AS created_by_name, u2.name AS updated_by_name
    FROM sales_order_forms sof
    LEFT JOIN users u ON u.id = sof.created_by
    LEFT JOIN users u2 ON u2.id = sof.updated_by
    WHERE sof.id = ? AND sof.deleted_at IS NULL");
$so->execute([$id]);
$data = $so->fetch();
if (!$data) { header('Location: index.php'); exit; }

$items = $conn->prepare("SELECT soi.*, i.stock_code FROM sales_order_items soi
    LEFT JOIN inventories i ON i.id = soi.inventory_id
    WHERE soi.sales_order_id = ? AND soi.deleted_at IS NULL ORDER BY soi.id");
$items->execute([$id]);
$orderItems = $items->fetchAll();

$statusBadge = match(strtolower($data['status'] ?? 'pending')) {
    'approved' => 'success', 'cancelled' => 'danger', 'pending' => 'warning', default => 'secondary'
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Sales Order - <?= htmlspecialchars($data['sales_order_code']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="styles/loader.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f8f9fa; }
        .section-title { font-size: 0.82rem; text-transform: uppercase; font-weight: 700; color: #323435; letter-spacing: 0.05em; border-bottom: 2px solid #c4c7cb; border-top: 2px solid #c4c7cb; padding-bottom: 6px; padding-top: 6px; margin-bottom: 14px; }
        .info-label { font-size: 0.78rem; color: #6c757d; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
        .info-value { font-size: 0.95rem; }
        .table-items th { font-size: 0.8rem; text-transform: uppercase; color: #6c757d; }
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            nav { display: none !important; }
            @page {
                size: A4 landscape;
                margin: 0;
            }
        }
    </style>
</head>
<body>
<nav class="navbar print:hidden navbar-dark bg-sky-400 mb-4 no-print">
    <div class="container-fluid">
        <a class="text-sm text-white" href="index.php"><i class="bi bi-arrow-left me-2"></i>Back</a>
        <span class="text-sm text-white"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['user_name']) ?></span>
    </div>
</nav>

<!--Start of viewing details-->
<div class="print:hidden container-fluid px-4">
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert print:hidden alert-success alert-dismissible fade show py-2 no-print">
            Sales order updated successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex print:hidden align-items-center justify-content-between mb-3 no-print">
        <div>
            <h4 class="fw-bold mb-0">Sales Order Details</h4>
            <small class="text-muted">ID #<?= $data['id'] ?></small>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print</button>
            <?php if (strtolower($data['status'] ?? '') === 'order draft'): ?>
                <a href="edit.php?id=<?= $id ?>" class="btn btn-warning btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
            <?php endif; ?>
            <a href="index.php?delete=<?= $id ?>" class="btn btn-outline-danger btn-sm"
               onclick="return confirm('Delete this sales order?')"><i class="bi bi-trash me-1"></i>Delete</a>
        </div>
    </div>

    <div class="print:hidden">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div>
                    <h5 class="fw-bold mb-0"><?= htmlspecialchars($data['sales_order_code']) ?></h5>
                    <small class="text-muted">SO No: <?= htmlspecialchars($data['so_no'] ?? '—') ?></small>
                </div>
                <span class="badge print:hidden bg-<?= $statusBadge ?> fs-6 px-3 py-2"><?= ucfirst($data['status'] ?? 'pending') ?></span>
            </div>

            <div class="section-title">Customer Information</div>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="info-label">Customer Name</div>
                    <div class="info-value fw-semibold"><?= htmlspecialchars($data['customer_name']) ?></div>
                </div>
                <div class="col-md-2">
                    <div class="info-label">TIN No.</div>
                    <div class="info-value"><?= htmlspecialchars($data['tin_no'] ?? '—') ?></div>
                </div>
                <div class="col-md-4">
                    <div class="info-label">Billing Address</div>
                    <div class="info-value"><?= htmlspecialchars($data['billing_address'] ?? '—') ?></div>
                </div>
                <div class="col-md-4">
                    <div class="info-label">Delivery Address</div>
                    <div class="info-value"><?= htmlspecialchars($data['address'] ?? '—') ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Contact Person</div>
                    <div class="info-value"><?= htmlspecialchars($data['contact_person'] ?? '—') ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Contact Details</div>
                    <div class="info-value"><?= htmlspecialchars($data['contact_details'] ?? '—') ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Payment Terms</div>
                    <div class="info-value"><?= htmlspecialchars($data['payment_terms'] ?? '—') ?></div>
                </div>
            </div>

            <div class="section-title">Order Details</div>
            <div class="row g-3 mb-3">
                <div class="col-md-2">
                    <div class="info-label">SO Date</div>
                    <div class="info-value"><?= htmlspecialchars($data['so_date'] ?? '—') ?></div>
                </div>
                <div class="col-md-2">
                    <div class="info-label">Required Delivery</div>
                    <div class="info-value"><?= $data['required_delivery_date'] ? (new DateTime($data['required_delivery_date']))->format('F d, Y') : '—' ?></div>
                </div>
                <div class="col-md-2">
                    <div class="info-label">Prepared By</div>
                    <div class="info-value"><?= htmlspecialchars($data['prepared_by'] ?? '—') ?></div>
                </div>
                <div class="col-md-2">
                    <div class="info-label">Confirmed By</div>
                    <div class="info-value"><?= htmlspecialchars($data['confirmed_by'] ?? '—') ?></div>
                </div>
                <?php if ($data['remarks']): ?>
                <div class="col-md-6">
                    <div class="info-label">Remarks</div>
                    <div class="info-value"><?= nl2br(htmlspecialchars($data['remarks'])) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($data['special_instruction']): ?>
                <div class="col-md-6">
                    <div class="info-label">Special Instruction</div>
                    <div class="info-value"><?= nl2br(htmlspecialchars($data['special_instruction'])) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="">
        <div class="py-2">
            <div class="section-title">Order Items</div>
            <?php foreach ($orderItems as $i => $item): ?>
            <div class="grid grid-cols-1 gap-1 p-2 border-1 border-gray-400">
                <div class="flex flex-row items-center justify-between">
                    <div class="grid grid-cols-1">
                        <p class="font-bold mb-1"># <?= $i + 1 ?></p>
                    </div>
                    <div class="grid grid-cols-1">
                        <p class="font-bold">Item Code:</p>
                        <p class="text-sm"><?= htmlspecialchars($item['item_code'] ?? '—') ?></p>
                    </div>
                </div>
                <div class="grid grid-cols-1">
                    <p class="font-bold">Description:</p>
                    <p class="text-sm"><?= htmlspecialchars($item['item_description'] ?? '—') ?></p>
                </div>
                <div class="grid grid-cols-4 gap-1">
                    <div class="grid grid-cols-1">
                        <p class="font-bold">UOM:</p>
                        <p class="text-xs"><?= htmlspecialchars($item['uom'] ?? '—') ?></p>
                    </div>
                    <div class="grid grid-cols-1">
                        <p class="font-bold">Qty:</p>
                        <p class="text-sm"><?= number_format($item['quantity'], 2) ?></p>
                    </div>
                    <div class="grid grid-cols-1">
                        <p class="font-bold">Unit Price:</p>
                        <p class="text-sm">₱<?= number_format($item['unit_price'], 2) ?></p>
                    </div>
                    <div class="grid grid-cols-1">
                        <p class="font-bold">Amount:</p>
                        <p class="text-sm">₱<?= number_format($item['amount'], 2) ?></p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($orderItems)): ?>
            <div class="grid grid-cols-1 gap-2 p-2 border border-gray-400">
                <p class="text-center text-gray-300">No items found.</p>
            </div>
            <?php endif; ?>
            <div class="flex flex-row items-center justify-end">
                <p class="font-bold p-1 text-right border-1 bg-gray-200 border-gray-400">Total Amount: <b class="text-blue-700">₱<?= number_format($data['total_amount'] ?? 0, 2) ?></b></p>
            </div>
        </div>
    </div>    

    <!-- Audit -->
    <div class="">
        <div class="py-2">
            <div class="section-title">Audit Trail</div>
            <div class="row gap-1 text-muted small">
                <div class="col-md-3">
                    <strong>Created By:</strong> <?= htmlspecialchars($data['created_by_name'] ?? '—') ?>
                </div>
                <div class="col-md-3">
                    <strong>Created At:</strong> <?= $data['created_at'] ? (new DateTime($data['created_at']))->format('F d, Y @ h:i a') : '—' ?>
                </div>
                <div class="col-md-3">
                    <strong>Updated By:</strong> <?= htmlspecialchars($data['updated_by_name'] ?? '—') ?>
                </div>
                <div class="col-md-3">
                    <strong>Updated At:</strong> <?= $data['updated_at'] ? (new DateTime($data['updated_at']))->format('F d, Y @ h:i a') : '—' ?>
                </div>
            </div>
        </div>
    </div>
</div>
<!--End of viewing details-->

<!--Start of printing layout-->
<?php
$chunks = array_chunk($orderItems, 10);
if (empty($chunks)) $chunks = [[]]; // at least 1 page if no items
$totalPages = count($chunks);
?>

<?php foreach ($chunks as $pageIndex => $pageItems): ?>
<div class="hidden print:block px-4 pt-2 text-sm" style="page-break-after: <?= $pageIndex < $totalPages - 1 ? 'always' : 'auto' ?>;">

    <!-- Header -->
    <div class="w-full flex flex-row items-start justify-between">
        <div>
            <img src="images/logotext.png" class="h-10" />
            <p>Marketing Department</p>
        </div>
        <div>
            <p>SAL-F002A-2.0</p>
        </div>
    </div>
    <div>
        <h1 class="text-center pt-1 font-bold text-lg">SALES ORDER FORM</h1>
    </div>

    <!-- Customer Info Row 1 -->
    <div class="flex flex-row w-full border border-black">
        <div class="w-[40%] flex flex-row border-r border-black items-start px-1 pb-1">
            <p class="break-words"><b>Customer Name:</b> <?= htmlspecialchars($data['customer_name'] ?? '') ?></p>
        </div>
        <div class="w-[40%] flex flex-row border-r border-black items-start px-1 pb-1">
            <p class="break-words"><b>Order Date:</b> <?= $data['order_date'] ? (new DateTime($data['order_date']))->format('F d, Y') : '—' ?></p>
        </div>
        <div class="w-[20%] flex flex-row items-start px-1 pb-1">
            <p class="break-words"><b>SO Date:</b> <?= $data['so_date'] ? (new DateTime($data['so_date']))->format('F d, Y') : '—' ?></p>
        </div>
    </div>

    <!-- TIN + New/Old -->
    <div class="flex flex-row w-full border-x border-b border-black">
        <div class="w-[80%] flex flex-row border-r border-black items-start px-1 pb-1">
            <p class="break-words"><b>TIN #:</b> <?= htmlspecialchars($data['tin_no'] ?? '') ?></p>
        </div>
        <div class="w-[10%] flex flex-row border-r border-black justify-center items-center gap-1 px-1 pb-1">
            <p>New</p>
            <b><?= $data['is_new'] ? '✓' : '' ?></b>
        </div>
        <div class="w-[10%] flex flex-row justify-center items-center gap-1 px-1 pb-1">
            <p>Old</p>
            <b><?= !$data['is_new'] ? '✓' : '' ?></b>
        </div>
    </div>

    <!-- Addresses + Contact -->
    <div class="flex flex-row w-full border-x border-b border-black">
        <div class="w-[80%] flex flex-col border-r border-black">
            <div class="w-full flex flex-row border-b border-black items-start px-1 pb-1">
                <p class="break-words"><b>Billing Address:</b> <?= htmlspecialchars($data['billing_address'] ?? '') ?></p>
            </div>
            <div class="w-full flex flex-row border-b border-black">
                <div class="w-[50%] flex flex-row border-r border-black items-start px-1 pb-1">
                    <p class="break-words"><b>Contact Details:</b> <?= htmlspecialchars($data['contact_details'] ?? '') ?></p>
                </div>
                <div class="w-[50%] flex flex-row items-start px-1 pb-1">
                    <p class="break-words"><b>Contact Person:</b> <?= htmlspecialchars($data['contact_person'] ?? '') ?></p>
                </div>
            </div>
            <div class="w-full flex flex-row">
                <div class="w-[50%] flex flex-row border-r border-black items-start px-1 pb-1">
                    <p class="break-words"><b>Payment Terms:</b> <?= htmlspecialchars($data['payment_terms'] ?? '') ?></p>
                </div>
                <div class="w-[50%] flex flex-row items-start px-1 pb-1">
                    <p class="break-words"><b>Required Delivery Date:</b> <?= $data['required_delivery_date'] ? (new DateTime($data['required_delivery_date']))->format('F d, Y') : '—' ?></p>
                </div>
            </div>
        </div>
        <div class="w-[20%] flex flex-row items-start px-1 pb-1">
            <p class="break-words"><b>Delivery Address:</b> <?= htmlspecialchars($data['address'] ?? '') ?></p>
        </div>
    </div>

    <!-- Page indicator if more than 1 page -->
    <?php if ($totalPages > 1): ?>
    <div class="flex flex-row w-full border-x border-b border-black px-1 pb-1">
        <p class="text-xs text-gray-500">Page <?= $pageIndex + 1 ?> of <?= $totalPages ?></p>
    </div>
    <?php endif; ?>

    <!-- Items Table -->
    <table class="w-full table-auto border-x text-xs border-black">
        <thead>
            <tr>
                <th class="p-1 text-center border-r border-b border-black w-[4%]">#</th>
                <th class="p-1 text-center border-r border-b border-black">Product Description</th>
                <th class="p-1 text-center border-r border-b border-black">Specification</th>
                <th class="p-1 text-center border-r border-b border-black w-[8%]">Quantity</th>
                <th class="p-1 text-center border-r border-b border-black w-[8%]">UOM</th>
                <th class="p-1 text-center border-r border-b border-black w-[10%]">Unit Price</th>
                <th class="p-1 text-center border-b border-black w-[10%]">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $startIndex = $pageIndex * 10;
            for ($row = 0; $row < 10; $row++):
                $item = $pageItems[$row] ?? null;
                $rowNumber = $startIndex + $row + 1;
            ?>
            <tr>
                <td class="p-1 border-r border-b border-black"><?= $item ? $rowNumber : $rowNumber ?></td>
                <td class="p-1 border-r border-b border-black break-words"><?= $item ? htmlspecialchars($item['item_code'] ?? '—') : '&nbsp;' ?></td>
                <td class="p-1 border-r border-b border-black break-words"><?= $item ? htmlspecialchars($item['item_description'] ?? '—') : '&nbsp;' ?></td>
                <td class="p-1 border-r border-b border-black text-center"><?= $item ? number_format($item['quantity'], 2) : '&nbsp;' ?></td>
                <td class="p-1 border-r border-b border-black text-center"><?= $item ? htmlspecialchars($item['uom'] ?? '—') : '&nbsp;' ?></td>
                <td class="p-1 border-r border-b border-black text-right"><?= $item ? '₱' . number_format($item['unit_price'], 2) : '&nbsp;' ?></td>
                <td class="p-1 border-b border-black text-right"><?= $item ? '₱' . number_format($item['amount'], 2) : '&nbsp;' ?></td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <!-- Footer: Special Instructions + Total -->
    <div class="flex flex-row w-full border-x border-b h-16 border-black">
        <div class="w-[40%] flex flex-row border-r border-black items-start px-1 pb-1">
            <p class="break-words"><b>Remarks:</b> <?= htmlspecialchars($data['remarks'] ?? '') ?></p>
        </div>
        <div class="w-[40%] flex flex-row border-r border-black items-start px-1 pb-1">
            <p class="break-words"><b>Special Instruction/s:</b> <?= htmlspecialchars($data['special_instruction'] ?? '') ?></p>
        </div>
        <div class="w-[20%] flex flex-col justify-start items-end px-1 pb-1">
            <p class="text-xs">Total Amount:</p>
            <p class="font-bold">₱<?= number_format($data['total_amount'] ?? 0, 2) ?></p>
        </div>
    </div>

    <!-- Signatures -->
    <div class="flex flex-row items-center gap-40 mt-2">
        <div class="w-[30%]">
            <p>Prepared by Sales/CS</p>
            <p class="w-full text-center border-b border-black mb-1 pt-4">
                <?= htmlspecialchars($data['prepared_by'] ?? '') ?>
            </p>
            <p class="w-full text-center">Signature Over Printed Name/Date</p>
        </div>
        <div class="w-[30%]">
            <p>Conformed by customer</p>
            <p class="w-full text-center border-b border-black mb-1 pt-4">
                <?= htmlspecialchars($data['confirmed_by'] ?? '') ?>
            </p>
            <p class="w-full text-center">Signature Over Printed Name/Date</p>
        </div>
    </div>

</div>
<?php endforeach; ?>
<!--End of printing layout-->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/loading.js"></script>
</body>
</html>
