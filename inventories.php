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
    $where .= " AND (i.stock_code LIKE ? OR i.stock_description LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s];
}

$total = $conn->prepare("SELECT COUNT(*) FROM inventories i $where");
$total->execute($params);
$totalRows = (int)$total->fetchColumn();
$totalPages = max(1, ceil($totalRows / $limit));

$sql = "SELECT i.id, i.stock_code, i.stock_description, i.uom,
            c.category_name AS category_name,
            wi.item_qty, wi.min_qty, wi.max_qty, wi.is_stocking, wi.is_active
        FROM inventories i
        LEFT JOIN categories c ON c.id = i.category_id AND c.deleted_at IS NULL
        LEFT JOIN warehouse_inventories wi ON wi.inventory_id = i.id AND wi.deleted_at IS NULL
        $where
        ORDER BY i.stock_code
        OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY";

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
    <link rel="stylesheet" href="styles/loader.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body class="bg-gray-100 min-h-screen">

<!-- Navbar -->
<nav class="bg-teal-200 shadow mb-2 xl:mb-6">
    <div class="max-w-full px-4 py-3 hidden xl:flex items-center justify-between">
        <div class="inline-flex items-center gap-6">
        <a class="navbar-brand fw-bold xl:block hidden" href="index.php"><i class="bi bi-arrow-left me-2"></i>Sales Orders</a>
        <span class="navbar-text text-white xl:block hidden fw-semibold"><i class="bi bi-boxes me-1"></i>Inventory</span>
        </div>
        <span class="ms-auto text-white-50 small"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['user_name']) ?></span>
    </div>
    <div class="block pb-2 xl:hidden text-center w-full">
        <span class="ms-auto text-white-50 small"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['user_name']) ?></span>
    </div>
</nav>

<div class="max-w-full px-2 xl:px-4">

    <!-- Card -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">

        <!-- Card Header -->
        <div class="flex items-center justify-between px-2 xl:px-5 py-2 xl:py-4 border-b-none xl:border-b border-gray-100">
            <h5 class="text-base hidden xl:block font-bold text-gray-800">Inventory List</h5>
            <div class="text-base text-gray-500">
                <span><i class="bi bi-boxes"></i> Total: <?= number_format($totalRows) ?> items</span>
            </div>
        </div>

        <div class="p-2 xl:p-5">
            <!-- Search Form -->
            <form method="GET" class="flex items-center gap-2 mb-4">
                <div class="flex items-center border border-gray-300 rounded overflow-hidden w-full max-w-md">
                    <span class="px-1 text-base xl:px-3 text-gray-400 bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="search"
                           class="flex-1 py-0.5 px-1 xl:py-1.5 xl:px-2 text-sm xl:text-base outline-none"
                           placeholder="Stock code, description..."
                           value="<?= htmlspecialchars($search) ?>">
                    <button class="bg-teal-200 hover:bg-teal-300 text-gray-600 text-sm xl:text-base px-2 xl:px-4 py-1.5">Search</button>
                </div>
                <?php if ($search): ?>
                    <a href="inventories.php" class="border border-gray-300 text-gray-600 text-xs xl:text-sm px-2 xl:px-3 py-1 xl:py-1.5 rounded hover:bg-gray-50">Clear</a>
                <?php endif; ?>
            </form>

            <!-- Info Banner -->
            <div class="mb-3 flex flex-col xl:flex-row gap-2 text-xs">
                <span class="flex items-center gap-1">
                    <i class="bi bi-exclamation-triangle-fill text-red-600"></i> 
                    <span>Red qty = at or below minimum</span>
                </span>
                <span class="flex items-center gap-1">
                    <i class="bi bi-check-circle-fill text-green-600"></i> 
                    <span>Green qty = above minimum</span>
                </span>
            </div>

            <!-- Desktop Table -->
            <div class="hidden xl:block overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-gray-50 border-y border-gray-200">
                        <tr>
                            <th class="px-3 py-2.5 text-xs font-semibold uppercase whitespace-nowrap text-gray-500">#</th>
                            <th class="px-3 py-2.5 text-xs font-semibold uppercase whitespace-nowrap text-gray-500">Stock Code</th>
                            <th class="px-3 py-2.5 text-xs font-semibold uppercase whitespace-nowrap text-gray-500">Stock Description</th>
                            <th class="px-3 py-2.5 text-xs font-semibold uppercase whitespace-nowrap text-gray-500 text-center">Qty on Hand</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($inventories)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-gray-400 py-8">No inventory records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inventories as $idx => $inv): ?>
                            <?php
                                $qty = $inv['item_qty'] ?? null;
                                $minQty = $inv['min_qty'] ?? null;
                                $qtyClass = 'text-gray-500';
                                if ($qty !== null) {
                                    $qtyClass = ($minQty !== null && $qty <= $minQty) ? 'text-red-600 font-semibold' : 'text-green-600 font-semibold';
                                }
                            ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-3 py-2.5 text-base whitespace-nowrap text-gray-400"><?= htmlspecialchars($inv['id']) ?></td>
                                <td class="px-3 py-2.5 text-base whitespace-nowrap font-semibold text-blue-600">
                                    <?= htmlspecialchars($inv['stock_code']) ?>
                                </td>
                                <td class="px-3 py-2.5 text-base text-gray-700 whitespace-nowrap">
                                    <?= htmlspecialchars($inv['stock_description']) ?>
                                </td>
                                <td class="px-3 py-2.5 text-base text-center <?= $qtyClass ?> whitespace-nowrap">
                                    <?php if ($qty !== null): ?>
                                        <?= number_format($qty, 2) ?>
                                        <?php if ($minQty !== null && $qty <= $minQty): ?>
                                            <i class="bi bi-exclamation-triangle-fill text-red-600 ms-1"></i>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card Layout -->
            <div class="xl:hidden">
                <?php if (empty($inventories)): ?>
                    <div class="text-center text-gray-400 py-8 text-sm">No inventory records found.</div>
                <?php else: ?>
                    <?php foreach ($inventories as $idx => $inv): ?>
                    <?php
                        $qty = $inv['item_qty'] ?? null;
                        $minQty = $inv['min_qty'] ?? null;
                        $qtyClass = 'text-gray-500';
                        if ($qty !== null) {
                            $qtyClass = ($minQty !== null && $qty <= $minQty) ? 'text-red-600 font-semibold' : 'text-green-600 font-semibold';
                        }
                        $isLow = $minQty !== null && $qty !== null && $qty <= $minQty;
                    ?>
                    <div class="bg-gray-50 border <?= $isLow ? 'border-red-300' : 'border-gray-200' ?> p-3 pb-0 text-base">
                        <!-- Header: Stock Code + Status -->
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <div class="flex items-center gap-1">
                                <span class="text-gray-500 font-semibold">Stock Code:</span>
                                <p class="font-semibold text-blue-600 truncate">
                                    <?= htmlspecialchars($inv['stock_code']) ?>
                                </p>
                            </div>
                            <?php if ($isLow): ?>
                                <span class="bg-red-100 text-red-700 px-2 py-1 rounded-full whitespace-nowrap text-[10px] font-semibold">
                                    Low Stock
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Description -->
                        <div class="mb-1">
                            <span class="text-gray-500 font-semibold">Description</span>
                            <p class="text-gray-700 truncate">
                                <?= htmlspecialchars($inv['stock_description']) ?>
                            </p>
                        </div>

                        <!-- Footer -->
                        <div class="flex items-center justify-between pt-1 border-t border-gray-200">
                            <span class="text-gray-500 text-sm">#<?= htmlspecialchars($inv['id']) ?></span>
                            <div>
                                <span class="text-gray-500 text-base font-semibold">Qty on Hand</span>
                                <p class="text-sm <?= $qtyClass ?>">
                                    <?php if ($qty !== null): ?>
                                        <?= number_format($qty, 2) ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?php if ($isLow): ?>
                                <span class="flex items-center gap-1 text-red-600 text-[10px]">
                                    <i class="bi bi-exclamation-triangle-fill"></i> Stock warning
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <nav class="mt-4 flex gap-1">
                <?php 
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                ?>
                
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>"
                       class="px-2 xl:px-3 py-1 text-xs xl:text-sm rounded border border-gray-300 text-gray-600 hover:bg-gray-50">
                        ‹
                    </a>
                <?php endif; ?>

                <?php if ($start > 1): ?>
                    <span class="px-2 xl:px-3 py-1 text-xs xl:text-sm text-gray-400">…</span>
                <?php endif; ?>

                <?php for ($p = $start; $p <= $end; $p++): ?>
                    <a href="?page=<?= $p ?>&search=<?= urlencode($search) ?>"
                       class="px-2 xl:px-3 py-1 text-xs xl:text-sm rounded border <?= $p === $page
                           ? 'bg-blue-600 text-white border-blue-600'
                           : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php if ($end < $totalPages): ?>
                    <span class="px-2 xl:px-3 py-1 text-xs xl:text-sm text-gray-400">…</span>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>"
                       class="px-2 xl:px-3 py-1 text-xs xl:text-sm rounded border border-gray-300 text-gray-600 hover:bg-gray-50">
                        ›
                    </a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>
    </div>

    <p class="text-gray-400 text-xs xl:text-xs mt-2">Showing <?= count($inventories) ?> of <?= $totalRows ?> records.</p>
</div>

<script src="js/loading.js"></script>
</body>
</html>