<?php
require_once 'auth.php';
require_once 'config.php';

$conn = getDBConnection();

$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = "WHERE c.deleted_at IS NULL";
$params = [];
if ($search !== '') {
    $where .= " AND (c.full_name LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s];
}

$total = $conn->prepare("SELECT COUNT(*) FROM customers c $where");
$total->execute($params);
$totalRows = (int)$total->fetchColumn();
$totalPages = max(1, ceil($totalRows / $limit));

$sql = "SELECT c.id, c.full_name, c.address, c.lot_no, c.barangay,
            c.municipality, c.province, c.contact_no, c.email, c.tin_no, c.sales_person,
            c.contact_no, c.email, c.business_nature, c.billing_cycle, c.statement_cycle,
            c.term, c.balance, c.company_code
        FROM customers c
        $where
        ORDER BY c.full_name
        LIMIT $limit OFFSET $offset";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer List</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f8f9fa; }
        .table th { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: #6c757d; }
        .table td { font-size: 0.9rem; vertical-align: middle; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-blue-600 mb-2">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold md:block hidden" href="index.php"><i class="bi bi-arrow-left me-2"></i>Sales Orders</a>
        <span class="navbar-text text-white md:block hidden fw-semibold"><i class="bi bi-person-badge me-1"></i>Customers</span>
        <span class="ms-auto text-white-50 small"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['user_name']) ?></span>
    </div>
</nav>

<div class="container-fluid px-2">

    <div class="d-flex align-items-center justify-content-between md:mb-3 mb-1">
        <div>
            <small class="text-muted"><b>Customers:</b> <?= number_format($totalRows) ?> total customers</small>
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
                        <?php if ($search): ?><a href="customers.php" class="text-[10px] text-black md:text-sm px-2 md:px-4 py-1.5 border-2">Clear</a><?php endif; ?>
                    </div>
                </div>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full min-w-max text-sm text-left">
                    <thead class="bg-gray-50 border-y border-gray-200">
                        <tr>
                            <th class=" px-1 py-0.5 md:py-2.5 text-[9px] md:md:text-xs text-[10px] font-semibold uppercase whitespace-nowrap text-gray-500">#</th>
                            <th class=" px-1 py-0.5 md:py-2.5 text-[9px] md:md:text-xs text-[10px] font-semibold uppercase whitespace-nowrap text-gray-500">Customer Name</th>
                            <th class=" px-1 py-0.5 md:py-2.5 text-[9px] md:md:text-xs text-[10px] font-semibold uppercase whitespace-nowrap text-gray-500">Address</th>
                            <th class=" px-1 py-0.5 md:py-2.5 text-[9px] text-center md:md:text-xs text-[10px] font-semibold uppercase whitespace-nowrap text-gray-500">Sales Person</th>
                            <th class=" px-1 py-0.5 md:py-2.5 text-[9px] text-center md:md:text-xs text-[10px] font-semibold uppercase whitespace-nowrap text-gray-500">TIN</th>
                            <th class=" px-1 py-0.5 md:py-2.5 text-[9px] text-center md:md:text-xs text-[10px] font-semibold uppercase whitespace-nowrap text-gray-500">Contact No</th>
                            <th class=" px-1 py-0.5 md:py-2.5 text-[9px] text-center md:md:text-xs text-[10px] font-semibold uppercase whitespace-nowrap text-gray-500">Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($customers)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">No customer records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($customers as $idx => $cus): ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class=" px-1 py-0.5 md:py-2.5 text-gray-400 md:text-xs text-[5px] whitespace-nowrap"><?= $offset + $idx + 1 ?></td>
                                <td class=" px-1 text-[8px] md:text-base py-0.5 md:py-2.5 whitespace-nowrap"><span class="fw-semibold text-primary"><?= htmlspecialchars($cus['full_name']) ?></span></td>
                                <td class=" px-1 py-0.5 md:py-2.5 text-gray-700 text-[8px] md:text-base whitespace-nowrap"><?= htmlspecialchars($cus['address'] ?? '') ?></td>
                                <td class=" px-1 py-0.5 md:py-2.5 text-gray-700 text-[8px] md:text-base whitespace-nowrap"><?= htmlspecialchars($cus['sales_person'] ?? '') ?></td>
                                <td class=" px-1 py-0.5 md:py-2.5 text-gray-700 text-[8px] md:text-base whitespace-nowrap"><?= htmlspecialchars($cus['tin_no'] ?? '') ?></td>
                                <td class=" px-1 py-0.5 md:py-2.5 text-gray-700 text-[8px] md:text-base whitespace-nowrap"><?= htmlspecialchars($cus['contact_no'] ?? '') ?></td>
                                <td class=" px-1 py-0.5 md:py-2.5 text-gray-700 text-[8px] md:text-base whitespace-nowrap"><?= htmlspecialchars($cus['email'] ?? '') ?></td>
                                
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="mt-2">
            <ul class="flex gap-1 mb-0">
                <li class="<?= $page === 1 ? 'opacity-50 cursor-not-allowed' : '' ?>">
                    <a class="px-2 py-1 border border-gray-300 rounded hover:bg-blue-400 <?= $page === 1 ? 'pointer-events-none' : '' ?>" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">‹</a>
                </li>
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                if ($start > 1): ?><li class="opacity-50 bg-gray-200 cursor-not-allowed"><span class="px-2 py-1 border border-gray-300 rounded">…</span></li><?php endif;
                for ($p = $start; $p <= $end; $p++): ?>
                <li class="">
                    <a class="px-2 py-1 border border-gray-300 rounded hover:bg-blue-400 <?= $p === $page ? 'bg-blue-600 text-white' : '' ?>" href="?page=<?= $p ?>&search=<?= urlencode($search) ?>"><?= $p ?></a>
                </li>
                <?php endfor;
                if ($end < $totalPages): ?><li class="opacity-50 cursor-not-allowed"><span class="px-2 py-1 border border-gray-300 rounded">…</span></li><?php endif; ?>
                <li class="<?= $page === $totalPages ? 'opacity-50 cursor-not-allowed' : '' ?>">
                    <a class="px-2 py-1 border border-gray-300 rounded hover:bg-gray-100 <?= $page === $totalPages ? 'pointer-events-none' : '' ?>" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>">›</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
