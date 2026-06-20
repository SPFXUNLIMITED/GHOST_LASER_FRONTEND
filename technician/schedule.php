<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (empty($_SESSION )) {
    header('Location: ../admin-login.php');
    exit;
}

require_once '../project/db.php';

$jobs = [];
try {
    $sql = "SELECT 
                sr.id,
                c.first_name,
                c.last_name,
                c.city,
                sr.latitude,
                sr.longitude,
                sr.priority_level,
                sr.problem_summary,
                sr.preferred_date_start,
                sr.preferred_date_end
            FROM service_requests sr
            JOIN customers c ON sr.customer_id = c.id
            WHERE sr.request_status IN ('new', 'queued')
            ORDER BY FIELD(LOWER(sr.priority_level), 'emergency', 'vip', 'standard'), sr.id DESC";

    $jobs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheduling Dashboard | Ghost Laser</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; }
    </style>
</head>
<body class="bg-zinc-950 text-white">
    <header class="fixed top-0 left-0 right-0 z-50 bg-zinc-950/80 backdrop-blur-lg border-b border-zinc-800">
        <div class="max-w-7xl mx-auto px