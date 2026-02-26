<?php
require_once 'auth.php';
require_once 'config.php';

$conn = getDBConnection();

// Handle soft delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stmt = $conn->prepare("UPDATE sales_order_forms SET deleted_at = NOW(), updated_by = ? WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$_SESSION['user_id'], $_GET['delete']]);
    header('Location: index.php?msg=deleted');
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// --- Role-based access ---
$userRole = $_SESSION['user_role'] ?? ''; // assumes role name is stored in session
$isAdmin = strtolower($userRole) === 'administrator';
$isSales = strtolower($userRole) === 'sales';

$where = "WHERE sof.deleted_at IS NULL";
$params = [];

// If sales role, restrict to own records only
if ($isSales) {
    $where .= " AND sof.created_by = ?";
    $params[] = $_SESSION['user_id'];
}

if ($search !== '') {
    $where .= " AND (sof.sales_order_code LIKE ? OR sof.customer_name LIKE ? OR sof.so_no LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s]);
}

$total = $conn->prepare("SELECT COUNT(*) FROM sales_order_forms sof $where");
$total->execute($params);
$totalRows = (int)$total->fetchColumn();
$totalPages = max(1, ceil($totalRows / $limit));

$sql = "SELECT sof.*, u.name AS created_by_name FROM sales_order_forms sof
        LEFT JOIN users u ON u.id = sof.created_by
        $where ORDER BY sof.id DESC LIMIT $limit OFFSET $offset";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales Orders</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body class="bg-gray-100 min-h-screen">

<!-- Navbar -->
<nav class="bg-blue-600 shadow mb-2 md:mb-6">
    <div class="max-w-full px-4 py-3 flex items-center justify-between">
        <a href="index.php" class="text-white hidden font-bold text-lg tracking-wide md:flex items-center gap-2">
            <i class="bi bi-file-earmark-text"></i> Sales Order
        </a>
        <div class="flex w-full md:w-auto justify-between md:justify-end items-center gap-3">
            <a href="inventories.php" class="bg-white text-blue-600 text-sm font-medium px-3 py-1.5 rounded hover:bg-gray-100 hidden md:flex items-center gap-1">
                <i class="bi bi-boxes"></i> Inventory
            </a>
            <span class="text-blue-200 text-sm flex items-center gap-1">
                <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['user_name']) ?>
            </span>
            <a href="?logout" class="border border-white text-white md:text-xs text-[10px] md:text-sm px-2 py-0.5 md:px-3 md:py-1.5 rounded hover:bg-blue-700">
                <i class="bi bi-box-arrow-in-right"></i> 
                &nbsp;&nbsp;Logout
            </a>
        </div>
    </div>
</nav>

<div class="max-w-full px-2 md:px-4">

    <!-- Alert -->
    <?php if (isset($_GET['msg'])): ?>
        <?php $isDeleted = $_GET['msg'] === 'deleted'; ?>
        <div class="mb-4 flex items-center justify-between px-4 py-2 rounded text-sm font-medium
            <?= $isDeleted ? 'bg-yellow-100 text-yellow-800 border border-yellow-300' : 'bg-green-100 text-green-800 border border-green-300' ?>">
            <span><?= $isDeleted ? 'Sales order deleted.' : 'Sales order saved successfully.' ?></span>
            <button onclick="this.parentElement.remove()" class="text-lg leading-none font-bold opacity-60 hover:opacity-100">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Card -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">

        <!-- Card Header -->
        <div class="flex items-center justify-between px-2 md:px-5 py-2 md:py-4 border-b-none md:border-b border-gray-100">
            <h5 class="text-base hidden md:block font-bold text-gray-800">Sales Order List</h5>
            <a href="add.php" class="bg-blue-600 hover:bg-blue-700 text-white text-[11px] md:text-sm font-medium md:px-3 px-2 py-1 md:py-1.5 rounded flex items-center gap-1">
                <i class="bi bi-plus-lg"></i> Create
            </a>
        </div>

        <div class="p-2 md:p-5">
            <!-- Search Form -->
            <form method="GET" class="flex items-center gap-2 mb-4">
                <div class="flex items-center border border-gray-300 rounded overflow-hidden w-full max-w-md">
                    <span class="px-1 md:text-xs text-[10px] md:text-base md:px-3 text-gray-400 bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="search"
                           class="flex-1 py-0.5 px-1 md:py-1.5 md:px-2 text-[10px] md:text-sm outline-none"
                           placeholder="S.O. Code, Customer, SO#..."
                           value="<?= htmlspecialchars($search) ?>">
                    <button class="bg-blue-600 hover:bg-blue-700 text-white text-[10px] md:text-sm px-2 md:px-4 py-1.5">Search</button>
                </div>
                <?php if ($search): ?>
                    <a href="index.php" class="border border-gray-300 text-gray-600 text-sm px-3 py-1.5 rounded hover:bg-gray-50">Clear</a>
                <?php endif; ?>
            </form>

            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="w-full min-w-max text-sm text-left">
                    <thead class="bg-gray-50 border-y border-gray-200">
                        <tr>
                            <?php foreach (['Actions','#','SO Code','SO No.','SO Date','Customer','Total Amount','Status','Created By'] as $th): ?>
                                <th class="md:px-3 px-1 py-0.5 md:py-2.5 text-[9px] md:md:text-xs text-[10px] font-semibold uppercase whitespace-nowrap text-gray-500"><?= $th ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($orders)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-gray-400 py-8">No records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($orders as $i => $o): ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="md:px-3 px-1 py-0.5 md:py-2.5 text-[11px] md:text-base whitespace-nowrap">
                                    <div class="flex items-center gap-1">
                                        <a href="view.php?id=<?= $o['id'] ?>" title="View"
                                           class="border border-cyan-400 text-cyan-600 hover:bg-cyan-50 rounded px-1.5 py-0.5 md:px-2 md:text-xs text-[10px]">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if (strtolower($o['status'] ?? '') === 'for adjustment'): ?>
                                            <a href="edit.php?id=<?= $o['id'] ?>" title="Edit"
                                            class="border border-yellow-400 text-yellow-600 hover:bg-yellow-50 rounded px-1.5 md:px-2 py-0.5 md:text-xs text-[10px]">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (strtolower($o['status'] ?? '') === 'for adjustment'): ?>
                                            <a href="?delete=<?= $o['id'] ?>&search=<?= urlencode($search) ?>&page=<?= $page ?>"
                                            title="Delete"
                                            onclick="return confirm('Delete this sales order?')"
                                            class="border border-red-400 text-red-600 hover:bg-red-50 rounded px-1.5 md:px-2 py-0.5 md:text-xs text-[10px]">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="md:px-3 px-1 py-0.5 md:py-2.5 text-gray-400 md:text-xs text-[5px] whitespace-nowrap"><?= $offset + $i + 1 ?></td>
                                <td class="md:px-3 px-1 text-[8px] md:text-base py-0.5 md:py-2.5 whitespace-nowrap">
                                    <a href="view.php?id=<?= $o['id'] ?>" class="font-semibold text-blue-600 hover:underline">
                                        <?= htmlspecialchars($o['sales_order_code']) ?>
                                    </a>
                                </td>
                                <td class="md:px-3 px-1 py-0.5 md:py-2.5 text-gray-700 text-[8px] md:text-base whitespace-nowrap"><?= htmlspecialchars($o['so_no'] ?? '—') ?></td>
                                <td class="md:px-3 px-1 py-0.5 md:py-2.5 text-gray-700 text-[8px] md:text-base whitespace-nowrap"><?= htmlspecialchars($o['so_date'] ?? '—') ?></td>
                                <td class="md:px-3 px-1 py-0.5 md:py-2.5 text-gray-700 text-[8px] md:text-base whitespace-nowrap"><?= htmlspecialchars($o['customer_name']) ?></td>
                                <td class="md:px-3 px-1 py-0.5 md:py-2.5 font-semibold text-[8px] md:text-base text-gray-800 whitespace-nowrap">₱<?= number_format($o['total_amount'] ?? 0, 2) ?></td>
                                <td class="md:px-3 px-1 py-0.5 md:py-2.5 text-[8px] md:text-base whitespace-nowrap">
                                    <?php
                                    $status = $o['status'] ?? 'pending';
                                    $badgeClass = match(strtolower($status)) {
                                        'completed'      => 'bg-blue-100 text-blue-700',
                                        'cancelled'      => 'bg-red-100 text-red-700',
                                        'for adjustment' => 'bg-yellow-100 text-yellow-700',
                                        'for so' => 'bg-teal-100 text-teal-700',
                                        'so generated' => 'bg-green-100 text-green-700',
                                        'for delivery' => 'bg-sky-100 text-sky-700',
                                        default          => 'bg-gray-100 text-gray-600',
                                    };
                                    ?>
                                    <span class="inline-block md:text-xs text-[5px] font-medium md:px-2.5 py-0 md:py-0.5 px-1.5 rounded-full <?= $badgeClass ?>">
                                        <?= ucfirst($status) ?>
                                    </span>
                                </td>
                                <td class="md:px-3 px-1 py-0.5 md:py-2.5 md:text-xs text-[8px] text-gray-400 whitespace-nowrap"><?= htmlspecialchars($o['created_by_name'] ?? '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <nav class="mt-4 flex gap-1">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="?page=<?= $p ?>&search=<?= urlencode($search) ?>"
                       class="px-3 py-1 text-sm rounded border <?= $p === $page
                           ? 'bg-blue-600 text-white border-blue-600'
                           : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
            </nav>
            <?php endif; ?>
        </div>
    </div>

    <p class="text-gray-400 md:text-xs text-[10px] mt-2">Showing <?= count($orders) ?> of <?= $totalRows ?> records.</p>
</div>

</body>
</html>