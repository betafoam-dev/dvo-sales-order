<?php
require_once '../config.php';
header('Content-Type: application/json');

$conn = getDBConnection();
$provinceId = (int)($_GET['province_id'] ?? 0);
if (!$provinceId) { echo json_encode([]); exit; }

$stmt = $conn->prepare("SELECT municipality_id, municipality_name FROM table_municipality WHERE province_id = ? ORDER BY municipality_name");
$stmt->execute([$provinceId]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
