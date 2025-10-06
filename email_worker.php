<?php
// Pastikan skrip ini hanya bisa dijalankan dari command line (CLI)
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

// Memuat file konfigurasi dan fungsi
require_once 'config.php';
require_once 'functions.php';

// Cek apakah argumen yang dibutuhkan ada
// $argv[0] adalah nama file skrip itu sendiri
if ($argc < 2) {
    die("Usage: php email_worker.php <issue_id> [comment_id]\n");
}

// Ambil issue_id dan comment_id dari argumen command line
$issue_id = (int)$argv[1];
$comment_id = isset($argv[2]) ? (int)$argv[2] : null;

// Koneksi $pdo sudah dibuat di dalam config.php
// Panggil fungsi pengiriman email
if (isset($pdo)) {
    echo "Sending email for issue_id: $issue_id...\n";
    if (send_notification_email($pdo, $issue_id, $comment_id)) {
        echo "Email sent successfully.\n";
    } else {
        echo "Failed to send email.\n";
    }
} else {
    die("Database connection failed.\n");
}