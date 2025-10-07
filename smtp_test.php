<?php
// Tampilkan semua error untuk debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Muat konfigurasi dan autoloader
require_once 'config.php';

$message = '';
$debug_log = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to_email = $_POST['to_email'] ?? '';
    $subject = $_POST['subject'] ?? 'SMTP Test from Ticketing System';
    $body = $_POST['body'] ?? 'This is a test email to verify SMTP configuration.';

    if (!empty($to_email) && filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        $mail = new PHPMailer(true);

        // Mulai output buffering untuk menangkap log debug
        ob_start();

        try {
            // Pengaturan Server
            $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Aktifkan output debug yang detail
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = 'auto';
            $mail->Port       = SMTP_PORT;

            // Penerima
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME . ' - Test');
            $mail->addAddress($to_email);

            // Konten
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = nl2br($body);
            $mail->AltBody = $body;

            $mail->send();
            $message = '<div class="p-4 text-sm text-green-800 bg-green-100 rounded-lg"><strong>Success!</strong> Email was sent successfully to ' . htmlspecialchars($to_email) . '.</div>';
        } catch (Exception $e) {
            $message = '<div class="p-4 text-sm text-red-800 bg-red-100 rounded-lg"><strong>Error!</strong> Message could not be sent. Mailer Error: ' . htmlspecialchars($mail->ErrorInfo) . '</div>';
        }

        // Ambil log debug dari buffer dan bersihkan
        $debug_log = ob_get_clean();

    } else {
        $message = '<div class="p-4 text-sm text-red-800 bg-red-100 rounded-lg"><strong>Error!</strong> Please provide a valid recipient email address.</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMTP Test Tool</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="antialiased text-slate-800">
    <div class="mx-auto max-w-2xl px-4 py-10">
        <header class="text-center mb-8">
            <h1 class="text-3xl font-bold tracking-tight text-slate-900">SMTP Test Tool</h1>
            <p class="text-slate-500 mt-2">Use this page to verify your email sending configuration from <strong>config.php</strong>.</p>
        </header>

        <div class="bg-white p-8 rounded-2xl shadow-md">
            <form action="smtp_test.php" method="POST" class="space-y-4">
                <div>
                    <label for="to_email" class="block text-sm font-medium text-slate-700">Recipient Email Address</label>
                    <input type="email" name="to_email" id="to_email" class="mt-1 block w-full rounded-lg py-2 px-3 border border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required placeholder="test@example.com">
                </div>
                <div>
                    <label for="subject" class="block text-sm font-medium text-slate-700">Subject (Optional)</label>
                    <input type="text" name="subject" id="subject" class="mt-1 block w-full rounded-lg py-2 px-3 border border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="SMTP Test from Ticketing System">
                </div>
                <div>
                    <label for="body" class="block text-sm font-medium text-slate-700">Body (Optional)</label>
                    <textarea id="body" name="body" rows="4" class="mt-1 block w-full rounded-lg border border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-3" placeholder="This is a test email to verify SMTP configuration."></textarea>
                </div>
                <div class="pt-2">
                    <button type="submit" class="w-full inline-flex justify-center rounded-lg bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-colors">Send Test Email</button>
                </div>
            </form>
        </div>

        <?php if (!empty($message)): ?>
        <div class="mt-8">
            <h2 class="text-xl font-bold text-slate-800 mb-4">Result:</h2>
            <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($debug_log)): ?>
        <div class="mt-6">
            <h2 class="text-xl font-bold text-slate-800 mb-4">SMTP Debug Log:</h2>
            <pre class="bg-slate-900 text-white text-sm p-4 rounded-lg overflow-x-auto"><?php echo htmlspecialchars($debug_log); ?></pre>
        </div>
        <?php endif; ?>

    </div>
</body>
</html>