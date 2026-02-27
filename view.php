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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f8f9fa; }
        .section-title { font-size: 0.82rem; text-transform: uppercase; font-weight: 600; color: #6c757d; letter-spacing: 0.05em; border-bottom: 2px solid #e9ecef; padding-bottom: 6px; margin-bottom: 14px; }
        .info-label { font-size: 0.78rem; color: #6c757d; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
        .info-value { font-size: 0.95rem; }
        .table-items th { font-size: 0.8rem; text-transform: uppercase; color: #6c757d; }
        @media print {
            .no-print { display: none !important; }
            .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; }
            body { background: white; }
        }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-sky-300 mb-4 no-print">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold text-dark" href="index.php"><i class="bi bi-arrow-left me-2"></i>Sales Orders</a>
        <span class="text-dark small"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['user_name']) ?></span>
    </div>
</nav>

<div class="container-fluid px-4">
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show py-2 no-print">
            Sales order updated successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex align-items-center justify-content-between mb-3 no-print">
        <div>
            <h4 class="fw-bold mb-0">Sales Order Details</h4>
            <small class="text-muted">ID #<?= $data['id'] ?></small>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print</button>
            <?php if (strtolower($o['status'] ?? '') === 'for adjustment'): ?>
                <a href="edit.php?id=<?= $id ?>" class="btn btn-warning btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
            <?php endif; ?>
            <a href="index.php?delete=<?= $id ?>" class="btn btn-outline-danger btn-sm"
               onclick="return confirm('Delete this sales order?')"><i class="bi bi-trash me-1"></i>Delete</a>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div>
                    <h5 class="fw-bold mb-0"><?= htmlspecialchars($data['sales_order_code']) ?></h5>
                    <small class="text-muted">SO No: <?= htmlspecialchars($data['so_no'] ?? '—') ?></small>
                </div>
                <span class="badge bg-<?= $statusBadge ?> fs-6 px-3 py-2"><?= ucfirst($data['status'] ?? 'pending') ?></span>
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
                <div class="col-md-2">
                    <div class="info-label">New Customer?</div>
                    <div class="info-value"><?= $data['is_new'] ? '<span class="badge bg-info">Yes</span>' : 'No' ?></div>
                </div>
                <div class="col-md-4">
                    <div class="info-label">Address</div>
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
                    <div class="info-value"><?= htmlspecialchars($data['required_delivery_date'] ?? '—') ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Deliver To</div>
                    <div class="info-value"><?= htmlspecialchars($data['deliver_to'] ?? '—') ?></div>
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

    <!-- Items -->
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <div class="section-title">Order Items</div>
            <div class="table-responsive">
                <table class="table table-bordered table-items align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Item Code</th>
                            <th>Description</th>
                            <th>UOM</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orderItems as $i => $item): ?>
                        <tr>
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($item['item_code'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($item['item_description'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($item['uom'] ?? '—') ?></td>
                            <td class="text-end"><?= number_format($item['quantity'], 4) ?></td>
                            <td class="text-end">₱<?= number_format($item['unit_price'], 2) ?></td>
                            <td class="text-end fw-semibold">₱<?= number_format($item['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($orderItems)): ?>
                        <tr><td colspan="7" class="text-center text-muted">No items found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="6" class="text-end fw-bold fs-6">Total Amount:</td>
                            <td class="text-end fw-bold text-primary fs-6">₱<?= number_format($data['total_amount'] ?? 0, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Audit -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body py-2">
            <div class="row text-muted small">
                <div class="col-md-3">
                    <strong>Created By:</strong> <?= htmlspecialchars($data['created_by_name'] ?? '—') ?>
                </div>
                <div class="col-md-3">
                    <strong>Created At:</strong> <?= htmlspecialchars($data['created_at'] ?? '—') ?>
                </div>
                <div class="col-md-3">
                    <strong>Updated By:</strong> <?= htmlspecialchars($data['updated_by_name'] ?? '—') ?>
                </div>
                <div class="col-md-3">
                    <strong>Updated At:</strong> <?= htmlspecialchars($data['updated_at'] ?? '—') ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
