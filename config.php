<?php
// Tampilkan semua error untuk debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Mulai session
session_start();

// --- PENGATURAN DATABASE ---
define('DB_HOST', 'localhost'); // Ganti dengan host database Anda
define('DB_USER', 'root');      // Ganti dengan username database Anda
define('DB_PASS', '');          // Ganti dengan password database Anda
define('DB_NAME', 'ticketing_system'); // Ganti dengan nama database Anda

// --- PENGATURAN APLIKASI ---
// Ganti dengan URL dasar aplikasi Anda. PENTING: sertakan / di akhir.
// Contoh: http://localhost/ticketing-app/
define('BASE_URL', 'http://localhost/ticketing-system/'); 

// --- PENGATURAN EMAIL (SMTP) ---
// Gunakan layanan seperti Mailtrap.io untuk testing atau Gmail, SendGrid, dll. untuk produksi
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'endrisusantomyid@gmail.com'); // Ganti dengan username SMTP Anda
define('SMTP_PASS', 'eoma miqi auvt xwdh'); // Ganti dengan password SMTP Anda
define('SMTP_PORT', 587); // Port SMTP (587 untuk TLS, 465 untuk SSL)
define('SMTP_FROM_EMAIL', 'endrisusantomyid@gmail.com');
define('SMTP_FROM_NAME', 'Issue Ticket System');

// --- KONEKSI DATABASE (PDO) ---
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    // Set mode error PDO ke exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    // Tampilkan pesan error jika koneksi gagal dan hentikan skrip
    die("ERROR: Tidak dapat terhubung ke database. " . $e->getMessage());
}

// Sertakan autoloader Composer untuk PHPMailer
// Pastikan Anda telah menjalankan 'composer require phpmailer/phpmailer' di terminal
require 'vendor/autoload.php';

?>
