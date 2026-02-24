<?php
require_once '../config.php';
header('Content-Type: application/json');

$conn = getDBConnection();
$regionId = (int)($_GET['region_id'] ?? 0);
if (!$regionId) { echo json_encode([]); exit; }

$stmt = $conn->prepare("SELECT province_id, province_name FROM table_province WHERE region_id = ? ORDER BY province_name");
$stmt->execute([$regionId]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
