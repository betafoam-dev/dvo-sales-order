<?php
require_once 'auth.php';
require_once 'config.php';

$conn = getDBConnection();

$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = "WHERE i.deleted_at IS NULL";
$params = [];
if ($search !== '') {
    $where .= " AND (i.stock_code LIKE ? OR i.stock_name LIKE ? OR i.stock_description LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s, $s];
}

$total = $conn->prepare("SELECT COUNT(*) FROM inventories i $where");
$total->execute($params);
$totalRows = (int)$total->fetchColumn();
$totalPages = max(1, ceil($totalRows / $limit));

$sql = "SELECT i.id, i.stock_code, i.stock_name, i.stock_description, i.uom,
            c.category_name AS category_name,
            wi.item_qty, wi.min_qty, wi.max_qty, wi.is_stocking, wi.is_active
        FROM inventories i
        LEFT JOIN categories c ON c.id = i.category_id AND c.deleted_at IS NULL
        LEFT JOIN warehouse_inventories wi ON wi.inventory_id = i.id AND wi.deleted_at IS NULL
        $where
        ORDER BY i.stock_code
        LIMIT $limit OFFSET $offset";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$inventories = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory List</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f8f9fa; }
        .table th { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: #6c757d; }
        .table td { font-size: 0.9rem; vertical-align: middle; }
        .stock-badge { font-size: 0.72rem; padding: 3px 8px; border-radius: 20px; }
        .qty-cell { font-weight: 600; }
        .qty-low { color: #dc3545; }
        .qty-ok { color: #198754; }
        .qty-none { color: #adb5bd; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-primary mb-4">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="index.php"><i class="bi bi-arrow-left me-2"></i>Sales Orders</a>
        <span class="navbar-text text-white fw-semibold"><i class="bi bi-boxes me-1"></i>Inventory</span>
        <span class="ms-auto text-white-50 small"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['user_name']) ?></span>
    </div>
</nav>

<div class="container-fluid px-4">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="fw-bold mb-0">Inventory List</h4>
            <small class="text-muted"><?= number_format($totalRows) ?> total items</small>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <form class="row g-2 mb-3" method="GET">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search by code, name, description..." value="<?= htmlspecialchars($search) ?>">
                        <button class="btn btn-primary">Search</button>
                        <?php if ($search): ?><a href="inventories.php" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Stock Code</th>
                            <th>Stock Name</th>
                            <th>Description</th>
                            <th>UOM</th>
                            <th>Category</th>
                            <th class="text-center">Qty on Hand</th>
                            <th class="text-center">Min Qty</th>
                            <th class="text-center">Max Qty</th>
                            <th class="text-center">Stocking</th>
                            <th class="text-center">Active</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inventories)): ?>
                            <tr><td colspan="11" class="text-center text-muted py-4">No inventory records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($inventories as $idx => $inv): ?>
                            <?php
                                $qty = $inv['item_qty'] ?? null;
                                $minQty = $inv['min_qty'] ?? null;
                                $qtyClass = 'qty-none';
                                if ($qty !== null) {
                                    $qtyClass = ($minQty !== null && $qty <= $minQty) ? 'qty-low' : 'qty-ok';
                                }
                            ?>
                            <tr>
                                <td class="text-muted small"><?= $offset + $idx + 1 ?></td>
                                <td><span class="fw-semibold text-primary"><?= htmlspecialchars($inv['stock_code']) ?></span></td>
                                <td><?= htmlspecialchars($inv['stock_name']) ?></td>
                                <td class="text-muted small"><?= htmlspecialchars($inv['stock_description'] ?? '—') ?></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($inv['uom'] ?? '—') ?></span></td>
                                <td><?= htmlspecialchars($inv['category_name'] ?? '—') ?></td>
                                <td class="text-center qty-cell <?= $qtyClass ?>">
                                    <?php if ($qty !== null): ?>
                                        <?= number_format($qty, 2) ?>
                                        <?php if ($minQty !== null && $qty <= $minQty): ?>
                                            <i class="bi bi-exclamation-triangle-fill text-danger ms-1" title="Below minimum"></i>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center text-muted small"><?= $inv['min_qty'] !== null ? number_format($inv['min_qty'], 2) : '—' ?></td>
                                <td class="text-center text-muted small"><?= $inv['max_qty'] !== null ? number_format($inv['max_qty'], 2) : '—' ?></td>
                                <td class="text-center">
                                    <?php if ($inv['is_stocking'] === null): ?>
                                        <span class="text-muted">—</span>
                                    <?php elseif ($inv['is_stocking']): ?>
                                        <span class="badge bg-success stock-badge">Yes</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary stock-badge">No</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($inv['is_active'] === null): ?>
                                        <span class="text-muted">—</span>
                                    <?php elseif ($inv['is_active']): ?>
                                        <i class="bi bi-check-circle-fill text-success fs-5"></i>
                                    <?php else: ?>
                                        <i class="bi bi-x-circle-fill text-danger fs-5"></i>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <nav class="mt-3">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $page === 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">‹</a>
                    </li>
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    if ($start > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;
                    for ($p = $start; $p <= $end; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $p ?>&search=<?= urlencode($search) ?>"><?= $p ?></a>
                    </li>
                    <?php endfor;
                    if ($end < $totalPages): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                    <li class="page-item <?= $page === $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>">›</a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>

    <!-- Legend -->
    <div class="d-flex gap-3 mt-2 text-muted small">
        <span><i class="bi bi-check-circle-fill text-success"></i> Active &nbsp;
              <i class="bi bi-x-circle-fill text-danger"></i> Inactive</span>
        <span><i class="bi bi-exclamation-triangle-fill text-danger"></i> Below minimum qty</span>
        <span><span class="qty-low fw-semibold">Red qty</span> = at or below min &nbsp;
              <span class="qty-ok fw-semibold">Green qty</span> = above min</span>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
