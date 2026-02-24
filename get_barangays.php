<?php
require_once '../config.php';
header('Content-Type: application/json');

$conn = getDBConnection();
$municipalityId = (int)($_GET['municipality_id'] ?? 0);
if (!$municipalityId) { echo json_encode([]); exit; }

$stmt = $conn->prepare("SELECT barangay_id, barangay_name FROM table_barangay WHERE municipality_id = ? ORDER BY barangay_name");
$stmt->execute([$municipalityId]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
