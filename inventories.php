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
    $where .= " AND (i.stock_code LIKE ? OR i.stock_name LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s];
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
    <script src="https://cdn.tailwindcss.com"></script>
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
<nav class="navbar navbar-dark bg-blue-600 mb-2">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold md:block hidden" href="index.php"><i class="bi bi-arrow-left me-2"></i>Sales Orders</a>
        <span class="navbar-text text-white md:block hidden fw-semibold"><i class="bi bi-boxes me-1"></i>Inventory</span>
        <span class="ms-auto text-white-50 small"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['user_name']) ?></span>
    </div>
</nav>

<div class="container-fluid px-2">

    <div class="d-flex align-items-center justify-content-between md:mb-3 mb-1">
        <div>
            <small class="text-muted"><b>Current Inventory:</b> <?= number_format($totalRows) ?> total items</small>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="p-2">
            <form class="row g-2 mb-3" method="GET">
                <div class="col-md-4">
                    <div class="flex items-center border border-gray-300 rounded overflow-hidden w-full max-w-md">
                        <span class="px-1 md:text-xs text-[10px] md:text-base md:px-3 text-gray-400 bg-white"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" name="search" class="flex-1 border py-0.5 px-1 md:py-1.5 md:px-2 text-[10px] md:text-sm outline-none" placeholder="Search by code, name..." value="<?= htmlspecialchars($search) ?>">
                        <button class="bg-blue-600 hover:bg-blue-700 text-white text-[10px] md:text-sm px-2 md:px-4 py-1.5">Search</button>
                        <?php if ($search): ?><a href="inventories.php" class="text-[10px] text-black md:text-sm px-2 md:px-4 py-1.5 border-2">Clear</a><?php endif; ?>
                    </div>
                </div>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full min-w-max text-sm text-left">
                    <thead class="bg-gray-50 border-y border-gray-200">
                        <tr>
                            <th class="md:px-3 px-1 py-0.5 md:py-2.5 text-[9px] md:md:text-xs text-[10px] font-semibold uppercase whitespace-nowrap text-gray-500">#</th>
                            <th class="md:px-3 px-1 py-0.5 md:py-2.5 text-[9px] md:md:text-xs text-[10px] font-semibold uppercase whitespace-nowrap text-gray-500">Stock Code</th>
                            <th class="md:px-3 px-1 py-0.5 md:py-2.5 text-[9px] md:md:text-xs text-[10px] font-semibold uppercase whitespace-nowrap text-gray-500">Stock Name</th>
                            <th class="md:px-3 px-1 py-0.5 md:py-2.5 text-[9px] text-center md:md:text-xs text-[10px] font-semibold uppercase whitespace-nowrap text-gray-500">Qty on Hand</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inventories)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No inventory records found.</td></tr>
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
                            <tr class="hover:bg-gray-50 transition">
                                <td class="md:px-3 px-1 py-0.5 md:py-2.5 text-gray-400 md:text-xs text-[5px] whitespace-nowrap"><?= $offset + $idx + 1 ?></td>
                                <td class="md:px-3 px-1 text-[8px] md:text-base py-0.5 md:py-2.5 whitespace-nowrap"><span class="fw-semibold text-primary"><?= htmlspecialchars($inv['stock_code']) ?></span></td>
                                <td class="md:px-3 px-1 py-0.5 md:py-2.5 text-gray-700 text-[8px] md:text-base whitespace-nowrap"><?= htmlspecialchars($inv['stock_name']) ?></td>
                                <td class="text-center text-[8px] md:text-base whitespace-nowrap qty-cell <?= $qtyClass ?>">
                                    <?php if ($qty !== null): ?>
                                        <?= number_format($qty, 2) ?>
                                        <?php if ($minQty !== null && $qty <= $minQty): ?>
                                            <i class="bi bi-exclamation-triangle-fill text-danger ms-1" title="Below minimum"></i>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
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
