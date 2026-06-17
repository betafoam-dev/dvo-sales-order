<?php
require_once 'auth.php';
require_once 'config.php';

function getAvailableLaravelUrl() {
    $cacheFile = __DIR__ . '/laravel_url_cache.json';
    $cacheTtl = 120; // seconds

    // Use cache if still fresh
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && (time() - $cached['time']) < $cacheTtl) {
            return $cached['url'];
        }
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';

    // Internal access — always fixed
    if (strpos($host, 'system.betafoam.ph') === false
        && strpos($host, 'system2.betafoam.ph') === false
        && strpos($host, 'system3.betafoam.ph') === false) {
        return 'http://172.16.0.21';
    }

    // External access — check which domain is online
    $logins = ["http://system.betafoam.ph", "http://system2.betafoam.ph", "http://system3.betafoam.ph"];
    $availableLogin = null;

    foreach ($logins as $login) {
        $context = stream_context_create(['http' => ['timeout' => 2]]);
        $headers = @get_headers($login, 1, $context);
        if ($headers && preg_match('/(200|301|302)/', $headers[0])) {
            $availableLogin = $login;
            break;
        }
    }

    $url = $availableLogin ? $availableLogin . ':1214' : null;

    // Save to cache
    file_put_contents($cacheFile, json_encode(['url' => $url, 'time' => time()]));

    return $url;
}

$sso_error = null;
if (isset($_POST['goto_laravel'])) {
    $laravelUrl = getAvailableLaravelUrl();

    if (!$laravelUrl) {
        $sso_error = 'Unable to connect to the main system. Please try again later.';
    } else {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", [
                    'Content-Type: application/json',
                    'X-SSO-Secret: ' . SSO_SECRET,
                ]),
                'content' => json_encode(['user_id' => $_SESSION['user_id']]),
                'timeout' => 5,
            ],
        ]);

        $response = @file_get_contents($laravelUrl . '/api/sso/generate-token', false, $context);
        $data = $response ? json_decode($response, true) : null;

        if (!empty($data['token'])) {
            header('Location: ' . $laravelUrl . '/sso/login?token=' . $data['token']);
            exit;
        } else {
            $sso_error = 'Failed to connect to the main system.';
        }
    }
}

$conn = getDBConnection();

$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = "WHERE c.deleted_at IS NULL";
$params = [];

if ($search !== '') {
    $where .= " AND (c.full_name LIKE ?)";
    $params[] = "%$search%";
}

// COUNT query
$total = $conn->prepare("SELECT COUNT(*) FROM customers c $where");
$total->execute($params);
$totalRows = (int)$total->fetchColumn();
$totalPages = max(1, ceil($totalRows / $limit));

$sql = "SELECT c.id, c.full_name, c.address, c.lot_no, c.barangay,
            c.municipality, c.province, c.contact_no, c.email, c.tin_no, c.sales_person,
            c.business_nature, c.billing_cycle, c.statement_cycle,
            c.term, c.balance, c.company_code
        FROM customers c
        $where
        ORDER BY c.full_name
        OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY";

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
    <link rel="stylesheet" href="styles/loader.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body class="bg-gray-100 min-h-screen">

<!-- Navbar -->
<nav class="bg-teal-200 shadow mb-2 xl:mb-6">
    <div class="max-w-full px-4 py-3 flex items-center justify-between">
        <div class="inline-flex items-center gap-6">
            <a href="index.php" class="text-gray-600 hidden font-bold text-lg tracking-wide xl:flex items-center gap-2">
                <i class="bi bi-arrow-left"></i> Sales Order
            </a>
            <a href="inventories.php" class="bg-white text-teal-600 text-sm font-medium px-3 py-1.5 rounded hover:bg-gray-100 hidden xl:flex items-center gap-1">
                <i class="bi bi-boxes"></i> Inventory
            </a>
            <a href="customers.php" class="bg-white text-teal-600 text-sm font-medium px-3 py-1.5 rounded hover:bg-gray-100 hidden xl:flex items-center gap-1">
                <i class="bi bi-person-badge"></i> Customer
            </a>
            <form method="POST">
                <button type="submit" name="goto_laravel"
                    class="bg-white text-blue-600 text-sm font-medium px-3 py-1.5 rounded hover:bg-gray-100 hidden xl:flex items-center gap-1">
                    <i class="bi bi-box-arrow-up-right"></i> Edit Customer
                </button>
            </form>
        </div>
        <div class="flex w-full xl:w-auto justify-between xl:justify-end items-center gap-3">
            <span class="text-gray-800 text-sm flex items-center gap-1">
                <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['user_name']) ?>
            </span>
            <a href="index.php?logout" class="border border-gray-600 text-gray-100 bg-teal-400 xl:text-base text-base px-2 py-1 xl:px-3 xl:py-1.5 rounded hover:bg-teal-500">
                <i class="bi bi-box-arrow-in-right"></i> 
                &nbsp;&nbsp;Logout
            </a>
        </div>
    </div>
</nav>
<?php if ($sso_error): ?>
    <div class="max-w-full px-4 mt-2">
        <div class="bg-red-100 text-red-700 text-sm px-4 py-2 rounded">
            <?= htmlspecialchars($sso_error) ?>
        </div>
    </div>
<?php endif; ?>

<div class="max-w-full px-2 xl:px-4">

    <!-- Card -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">

        <!-- Card Header -->
        <div class="flex items-center justify-between px-2 xl:px-5 py-2 xl:py-4 border-b-none xl:border-b border-gray-100">
            <h5 class="text-base hidden xl:block font-bold text-gray-800">Customer List</h5>
            <div class="text-base text-gray-500">
                <span><i class="bi bi-person-badge"></i> Total: <?= number_format($totalRows) ?> customers</span>
            </div>
        </div>

        <div class="p-2 xl:p-5">
            <form method="POST">
                <button type="submit" name="goto_laravel"
                    class="bg-white block xl:hidden text-blue-600 text-sm font-medium px-3 py-1.5 rounded hover:bg-gray-100 hidden xl:flex items-center gap-1">
                    <i class="bi bi-box-arrow-up-right"></i> Edit Customer
                </button>
            </form>
            <!-- Search Form -->
            <form method="GET" class="flex items-center gap-2 mb-4">
                <div class="flex items-center border border-gray-300 rounded overflow-hidden w-full max-w-md">
                    <span class="px-1 text-sm xl:text-base xl:px-3 text-gray-400 bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="search"
                           class="flex-1 py-0.5 px-1 xl:py-1.5 xl:px-2 text-sm xl:text-base outline-none"
                           placeholder="Search customer name..."
                           value="<?= htmlspecialchars($search) ?>">
                    <button class="bg-teal-200 hover:bg-teal-300 text-gray-600 text-sm xl:text-base px-2 xl:px-4 py-1.5">Search</button>
                </div>
                <?php if ($search): ?>
                    <a href="customers.php" class="border border-gray-300 text-gray-600 text-xs xl:text-sm px-2 xl:px-3 py-1 xl:py-1.5 rounded hover:bg-gray-50">Clear</a>
                <?php endif; ?>
            </form>

            <!-- Desktop Table -->
            <div class="hidden xl:block overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-gray-50 border-y border-gray-200">
                        <tr>
                            <th class="px-3 py-2.5 text-xs font-semibold uppercase whitespace-nowrap text-gray-500">#</th>
                            <th class="px-3 py-2.5 text-xs font-semibold uppercase whitespace-nowrap text-gray-500">Customer Name</th>
                            <th class="px-3 py-2.5 text-xs font-semibold uppercase whitespace-nowrap text-gray-500">Address</th>
                            <th class="px-3 py-2.5 text-xs font-semibold uppercase whitespace-nowrap text-gray-500">Sales Person</th>
                            <th class="px-3 py-2.5 text-xs font-semibold uppercase whitespace-nowrap text-gray-500">TIN</th>
                            <th class="px-3 py-2.5 text-xs font-semibold uppercase whitespace-nowrap text-gray-500">Contact No</th>
                            <th class="px-3 py-2.5 text-xs font-semibold uppercase whitespace-nowrap text-gray-500">Email</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($customers)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-gray-400 py-8">No customer records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($customers as $idx => $cus): ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-3 py-2.5 text-base whitespace-nowrap text-gray-400"><?= htmlspecialchars($cus['id']) ?></td>
                                <td class="px-3 py-2.5 text-base whitespace-nowrap font-semibold text-blue-600">
                                    <?= htmlspecialchars($cus['full_name']) ?>
                                </td>
                                <td class="px-3 py-2.5 text-base text-gray-700 whitespace-nowrap">
                                    <?= htmlspecialchars($cus['address'] ?? '—') ?>
                                </td>
                                <td class="px-3 py-2.5 text-sm text-gray-600 whitespace-nowrap">
                                    <?= htmlspecialchars($cus['sales_person'] ?? '—') ?>
                                </td>
                                <td class="px-3 py-2.5 text-base text-gray-700 whitespace-nowrap">
                                    <?= htmlspecialchars($cus['tin_no'] ?? '—') ?>
                                </td>
                                <td class="px-3 py-2.5 text-base text-gray-700 whitespace-nowrap">
                                    <?= htmlspecialchars($cus['contact_no'] ?? '—') ?>
                                </td>
                                <td class="px-3 py-2.5 text-base text-gray-700 whitespace-nowrap">
                                    <?= htmlspecialchars($cus['email'] ?? '—') ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card Layout -->
            <div class="xl:hidden">
                <?php if (empty($customers)): ?>
                    <div class="text-center text-gray-400 py-8 text-sm">No customer records found.</div>
                <?php else: ?>
                    <?php foreach ($customers as $idx => $cus): ?>
                    <div class="bg-gray-50 border border-gray-200 p-3 pb-1 text-base">
                        <!-- Header: Customer Name -->
                        
                        <div class="mb-2">
                            <div class="inline-flex items-center justify-between w-full">
                                <span class="text-gray-500 font-semibold">Customer Name:</span>
                                <span class="text-gray-500 text-base">#<?= htmlspecialchars($cus['id']) ?></span>
                            </div>
                            <p class="font-semibold text-blue-600 truncate">
                                <?= htmlspecialchars($cus['full_name']) ?>
                            </p>
                        </div>

                        <!-- Address -->
                        <div class="mb-2">
                            <span class="text-gray-500 font-semibold">Address</span>
                            <p class="text-gray-700 truncate text-xs">
                                <?= htmlspecialchars($cus['address'] ?? '—') ?>
                            </p>
                        </div>

                        <!-- Details Grid -->
                        <div class="grid grid-cols-2 gap-2 text-gray-700">
                            <div>
                                <span class="text-gray-500 text-sm font-semibold">Sales Person</span>
                                <p class="text-base truncate"><?= htmlspecialchars($cus['sales_person'] ?? '—') ?></p>
                            </div>
                            <div>
                                <span class="text-gray-500 text-sm font-semibold">TIN</span>
                                <p class="text-base truncate"><?= htmlspecialchars($cus['tin_no'] ?? '—') ?></p>
                            </div>
                            <div>
                                <span class="text-gray-500 text-sm font-semibold">Contact No</span>
                                <p class="text-base"><?= htmlspecialchars($cus['contact_no'] ?? '—') ?></p>
                            </div>
                            <div>
                                <span class="text-gray-500 text-sm font-semibold">Email</span>
                                <p class="text-base truncate"><?= htmlspecialchars($cus['email'] ?? '—') ?></p>
                            </div>
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

    <p class="text-gray-400 text-xs xl:text-xs mt-2">Showing <?= count($customers) ?> of <?= $totalRows ?> records.</p>
</div>

<script src="js/loading.js"></script>
</body>
</html>