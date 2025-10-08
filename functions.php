<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// ============== FUNGSI-FUNGSI HELPER ===============

if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: " . BASE_URL . $url);
        exit();
    }
}

if (!function_exists('is_logged_in')) {
    function is_logged_in() {
        return isset($_SESSION['user_id']);
    }
}

if (!function_exists('get_app_user')) {
    function get_app_user($pdo) {
        if (!is_logged_in()) return null;
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('flash_message')) {
    function flash_message($key) {
        if (isset($_SESSION['flash'][$key])) {
            $type = $key === 'error' ? 'red' : 'green';
            // Perubahan untuk dukungan Emoji/UTF-8
            echo '<div class="p-4 mb-4 text-sm text-'.$type.'-800 bg-'.$type.'-100 rounded-lg" role="alert">' . htmlspecialchars($_SESSION['flash'][$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
            unset($_SESSION['flash'][$key]);
        }
    }
}

// ============== FUNGSI EMAIL ===============
// functions.php

// ============== FUNGSI EMAIL ===============
if (!function_exists('send_notification_email')) {
    function send_notification_email($pdo, $issue_id, $comment_id = null) {
        $stmt_issue = $pdo->prepare("SELECT i.*, u.name as drafter_name, u.email as drafter_email FROM issues i JOIN users u ON i.drafter_id = u.id WHERE i.id = ?");
        $stmt_issue->execute([$issue_id]);
        $issue = $stmt_issue->fetch(PDO::FETCH_ASSOC);
        if (!$issue) return false;

        $stmt_updates = $pdo->prepare("SELECT * FROM issue_updates WHERE issue_id = ? ORDER BY created_at ASC");
        $stmt_updates->execute([$issue_id]);
        $updates = $stmt_updates->fetchAll(PDO::FETCH_ASSOC);

        $mail = new PHPMailer(true);
        try {
            // SMTP Config
            $mail->isSMTP(); $mail->Host = SMTP_HOST; $mail->SMTPAuth = true; $mail->Username = SMTP_USER; $mail->Password = SMTP_PASS; $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = SMTP_PORT;
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->isHTML(true);

            $template_path = 'email_template.html';
            if (!file_exists($template_path)) return false;
            $base_body = file_get_contents($template_path);

            // THEME COLOR
            $theme_color = '#3b82f6'; $theme_color_light = '#dbeafe';
            switch ($issue['condition']) {
                case 'Urgent': $theme_color = '#ef4444'; $theme_color_light = '#fee2e2'; break;
                case 'High': $theme_color = '#f59e0b'; $theme_color_light = '#fef3c7'; break;
                case 'Low': $theme_color = '#22c55e'; $theme_color_light = '#dcfce7'; break;
            }

            // ATTACHMENT BLOCK
            $attachment_html_block = '';
            if (!empty($issue['image_paths'])) {
                $image_paths = json_decode($issue['image_paths'], true);
                if (is_array($image_paths) && count($image_paths) > 0) {
                    $image_grid = '<table width="100%" style="border-spacing: 0; margin-top: 12px;"><tr>';
                    foreach($image_paths as $index => $path) {
                        if(file_exists($path)) {
                            $cid = 'issue_image_' . $index;
                            $mail->addEmbeddedImage($path, $cid);
                            $image_grid .= '<td style="padding: 0 4px;"><img src="cid:'.$cid.'" alt="Attachment" style="width: 100%; height: auto; border-radius: 8px; display: block;"></td>';
                        }
                    }
                    $image_grid .= '</tr></table>';
                    $attachment_html_block = '<tr><td style="padding-top: 16px;"><table width="100%" class="card" style="background-color:#f8fafc;"><tr><td><p class="h2">Attachments</p>'.$image_grid.'</td></tr></table></td></tr>';
                }
            }
            
            // HISTORY BLOCK (Bubble Chat)
            $history_html = '';
            foreach($updates as $update) {
                // Perubahan untuk dukungan Emoji/UTF-8
                $author_display = htmlspecialchars($update['created_by'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                if ($update['is_status_change']) $author_display = "System";
                
                $attachments_comment_html = '';
                if (!empty($update['attachments'])) {
                    $attachments = json_decode($update['attachments'], true);
                    if(is_array($attachments) && count($attachments) > 0) {
                        $attachments_comment_html .= '<div style="margin-top: 8px;">';
                        foreach($attachments as $att_path) {
                            if(file_exists($att_path)) {
                                $cid = 'comment_'. uniqid() .'_att';
                                $mail->addEmbeddedImage($att_path, $cid);
                                $attachments_comment_html .= '<img src="cid:'.$cid.'" style="max-width: 80px; height: auto; border-radius: 8px; display:inline-block; margin-right: 8px;">';
                            }
                        }
                        $attachments_comment_html .= '</div>';
                    }
                }
                
                $history_html .= '<div class="bubble" style="background-color: #eef2ff;">
                                    <p style="font-size: 13px; font-weight: 600; color: #1e293b; margin: 0 0 4px;">'.$author_display.' <span style="font-size: 11px; color: #94a3b8; font-weight: normal;">'.date('d M Y, H:i', strtotime($update['created_at'])).'</span></p>
                                    <p style="font-size: 14px; color: #334155; margin: 0;">'.nl2br(htmlspecialchars($update['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')).'</p>
                                    '.$attachments_comment_html.'
                                </div>';
            }
            if (empty($history_html)) $history_html = '<p style="font-size: 14px; color: #64748b; text-align: center;">No updates yet.</p>';

            // BREADCRUMB BLOCK (New Style)
            $statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
            $currentIndex = array_search($issue['status'], $statuses);
            $breadcrumb_html = '';
            foreach ($statuses as $index => $status) {
                $is_active = ($index === $currentIndex);
                $is_done = ($index < $currentIndex);
                
                $bg_color = $is_active ? $theme_color : ($is_done ? '#e2e8f0' : '#f8fafc');
                $text_color = $is_active ? '#ffffff' : ($is_done ? '#475569' : '#94a3b8');
                $border = $is_active ? 'none' : '1px solid #e2e8f0';

                $breadcrumb_html .= '<span style="display: inline-block; padding: 6px 14px; border-radius: 99px; font-size: 12px; font-weight: 600; background-color:'.$bg_color.'; color:'.$text_color.'; border:'.$border.';">'.$status.'</span>';
                if ($index < count($statuses) - 1) {
                    $breadcrumb_html .= '<span style="font-size: 14px; color: #cbd5e1; padding: 0 8px; vertical-align: middle;">&gt;</span>';
                }
            }

            // LOGIKA PEMBUATAN DAFTAR EMAIL PIC (Point List)
            $pic_emails_array = array_filter(array_map('trim', explode(',', $issue['pic_emails'])));
            $pic_emails_list_html = '<ul style="padding-left: 18px; margin-top: 4px; margin-bottom: 0;">';
            
            if (empty($pic_emails_array)) {
                $pic_emails_list_html = '<p style="font-size: 14px; color:#64748b; margin:4px 0 0;">No PIC assigned.</p>';
            } else {
                foreach ($pic_emails_array as $email) {
                    $pic_emails_list_html .= '<li style="font-size: 14px; color:#1e293b; margin-bottom: 4px; word-break: break-all; word-wrap: break-word;">' . htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
                }
                $pic_emails_list_html .= '</ul>';
            }
            // END LOGIKA PEMBUATAN DAFTAR EMAIL PIC


// REPLACEMENTS
            $common_replacements = [
                // ... (daftar penggantian)
                '{{theme_color}}' => $theme_color, '{{theme_color_light}}' => $theme_color_light,
                '{{drafter_name}}' => htmlspecialchars($issue['drafter_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '{{drafter_email}}' => htmlspecialchars($issue['drafter_email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '{{pic_emails}}' => htmlspecialchars(str_replace(',', ', ', $issue['pic_emails']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '{{pic_emails_list}}' => $pic_emails_list_html, 
                '{{issue_title}}' => htmlspecialchars($issue['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '{{urgency_level}}' => htmlspecialchars($issue['condition'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '{{location}}' => htmlspecialchars($issue['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '{{attachment_block}}' => $attachment_html_block,
                '{{history_block}}' => $history_html,
                '{{breadcrumb_html}}' => $breadcrumb_html,
            ];

            // --- LOGIKA PENGIRIMAN EMAIL MASSAL BARU: SEMUA PIC JADI TO ---
            
            $mail->clearAllRecipients(); // Bersihkan semua penerima sebelum memulai
            $mail->clearBCCs();

            // 1. Kumpulkan dan validasi semua alamat email PIC (TO: utama)
            $to_recipients = array_unique(array_filter(array_map('trim', explode(',', $issue['pic_emails']))));
            $to_recipients = array_filter($to_recipients, 'filter_var', FILTER_VALIDATE_EMAIL);

            // 2. Kumpulkan dan validasi Drafter dan CC (CC: potensial)
            $cc_recipients_raw = array_unique(array_filter(array_map('trim', array_merge(
                explode(',', $issue['cc_emails']), 
                [$issue['drafter_email']]
            ))));
            $cc_recipients_raw = array_filter($cc_recipients_raw, 'filter_var', FILTER_VALIDATE_EMAIL);
            
            // 3. Kumpulkan dan validasi BCC (BCC:)
            $bcc_recipients = array_unique(array_filter(array_map('trim', explode(',', $issue['bcc_emails']))));
            $bcc_recipients = array_filter($bcc_recipients, 'filter_var', FILTER_VALIDATE_EMAIL);

            // 4. Filter: Hapus Drafter/CC yang sudah menjadi TO (PIC)
            $final_cc_recipients = array_values(array_filter($cc_recipients_raw, function($email) use ($to_recipients) {
                return !in_array($email, $to_recipients);
            }));

            // 5. Penanganan kasus jika daftar TO kosong (Tidak ada PIC)
            $final_to_recipients = $to_recipients;
            
            if (empty($final_to_recipients) && !empty($final_cc_recipients)) {
                // Pindahkan CC pertama ke TO: (Wajib ada satu TO:)
                $to_recipient = array_shift($final_cc_recipients);
                $final_to_recipients[] = $to_recipient;
            } elseif (empty($final_to_recipients) && !empty($issue['drafter_email']) && filter_var($issue['drafter_email'], FILTER_VALIDATE_EMAIL)) {
                 // Jika hanya ada Drafter dan dia belum terdaftar
                 $final_to_recipients[] = $issue['drafter_email'];
            }

            if (empty($final_to_recipients) && empty($final_cc_recipients) && empty($bcc_recipients)) {
                 return false; // Tidak ada penerima yang valid
            }

            // Atur Penerima TO: (Semua PIC yang sudah difilter)
            foreach ($final_to_recipients as $recipient) {
                $mail->addAddress($recipient);
            }

            // Atur Penerima CC: (Drafter dan CC yang tidak ada di PIC)
            foreach ($final_cc_recipients as $recipient) {
                $mail->addCC($recipient);
            }

            // Atur Penerima BCC:
            foreach ($bcc_recipients as $recipient) {
                $mail->addBCC($recipient);
            }
            
            // Konten Email (Subjek & Body)
            $body = str_replace(array_keys($common_replacements), array_values($common_replacements), $base_body);
            
            if ($comment_id) {
                $mail->Subject = 'Update on Ticket: ' . $issue['title'];
                $body = str_replace(['{{preheader}}', '{{header_title}}', '{{main_description}}'], ['A new update on ticket: ' . $issue['title'], 'Ticket Updated', 'A new update has been posted. See history for details.'], $body);
            } else {
                $mail->Subject = 'New Ticket Created: ' . $issue['title'];
                // Perubahan untuk dukungan Emoji/UTF-8 pada deskripsi
                $body = str_replace(['{{preheader}}', '{{header_title}}', '{{main_description}}'], ['New ticket created: ' . $issue['title'], 'New Ticket Created', nl2br(htmlspecialchars($issue['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))], $body);
            }
            
            $body = str_replace(['{{cta_link}}', '{{cta_text}}'], [BASE_URL . '?page=view_ticket&token=' . $issue['access_token'], 'View Full Ticket'], $body);
            $mail->Body = $body; 
            
            $mail->send();
            return true;
        } catch (Exception $e) { 
            // ... (logika catch)
            // ... (logika catch)
            // Tidak bisa menggunakan session flash di background script, jadi kita log ke file
            error_log("Mailer Error: {$mail->ErrorInfo}\n", 3, "email_error.log");
            return false; 
        }
        return true;
    }
}
// ... (Sisa kode functions.php)
                
// --- PENGGALAN SESUDAH PERUBAHAN (sekitar baris 155) ---
                $history_html .= '<div class="bubble" style="background-color: #eef2ff;">
                                    <p style="font-size: 13px; font-weight: 600; color: #1e293b; margin: 0 0 4px;">'.$author_display.' <span style="font-size: 11px; color: #94a3b8; font-weight: normal;">'.date('d M Y, H:i', strtotime($update['created_at'])).'</span></p>
                                    <p style="font-size: 14px; color: #334155; margin: 0;">'.nl2br(htmlspecialchars($update['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')).'</p>
                                    '.$attachments_comment_html.'
                                </div>';
            }
            if (empty($history_html)) $history_html = '<p style="font-size: 14px; color: #64748b; text-align: center;">No updates yet.</p>';

            // --- PERBAIKAN: LOGIKA PEMBUATAN DAFTAR EMAIL PIC (Point List) ---
            $pic_emails_array = array_filter(array_map('trim', explode(',', $issue['pic_emails'])));
            $pic_emails_list_html = '<ul style="padding-left: 18px; margin-top: 4px; margin-bottom: 0;">';
            
            // Tambahkan penanganan jika PIC kosong
            if (empty($pic_emails_array)) {
                $pic_emails_list_html = '<p style="font-size: 14px; color:#64748b; margin:4px 0 0;">No PIC assigned.</p>';
            } else {
                foreach ($pic_emails_array as $email) {
                    // Membuat setiap email sebagai list item HTML
                    $pic_emails_list_html .= '<li style="font-size: 14px; color:#1e293b; margin-bottom: 4px; word-break: break-all; word-wrap: break-word;">' . htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
                }
                $pic_emails_list_html .= '</ul>';
            }
            // -----------------------------------------------------------

            // BREADCRUMB BLOCK (New Style)
            $statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
// ...
            $currentIndex = array_search($issue['status'], $statuses);
            $breadcrumb_html = '';
            foreach ($statuses as $index => $status) {
                $is_active = ($index === $currentIndex);
                $is_done = ($index < $currentIndex);
                
                $bg_color = $is_active ? $theme_color : ($is_done ? '#e2e8f0' : '#f8fafc');
                $text_color = $is_active ? '#ffffff' : ($is_done ? '#475569' : '#94a3b8');
                $border = $is_active ? 'none' : '1px solid #e2e8f0';

                $breadcrumb_html .= '<span style="display: inline-block; padding: 6px 14px; border-radius: 99px; font-size: 12px; font-weight: 600; background-color:'.$bg_color.'; color:'.$text_color.'; border:'.$border.';">'.$status.'</span>';
                if ($index < count($statuses) - 1) {
                    $breadcrumb_html .= '<span style="font-size: 14px; color: #cbd5e1; padding: 0 8px; vertical-align: middle;">&gt;</span>';
                }
            }

            // --- PERUBAHAN UNTUK TEMPLATE EMAIL (Bullet Point List) ---
            $pic_emails_array = array_filter(array_map('trim', explode(',', $issue['pic_emails'])));
            $pic_emails_list_html = '<ul style="padding-left: 18px; margin-top: 4px; margin-bottom: 0;">';
            foreach ($pic_emails_array as $email) {
                $pic_emails_list_html .= '<li style="font-size: 14px; color:#1e293b; margin-bottom: 4px; word-break: break-all; word-wrap: break-word;">' . htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
            }
            $pic_emails_list_html .= '</ul>';
            // -----------------------------------------------------------

             // REPLACEMENTS
             $common_replacements = [
                 '{{theme_color}}' => $theme_color, '{{theme_color_light}}' => $theme_color_light,
                 // Perubahan untuk dukungan Emoji/UTF-8
                 '{{drafter_name}}' => htmlspecialchars($issue['drafter_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                 '{{drafter_email}}' => htmlspecialchars($issue['drafter_email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '{{pic_emails}}' => htmlspecialchars(str_replace(',', ', ', $issue['pic_emails']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '{{pic_emails_list}}' => $pic_emails_list_html, // Memasukkan variabel HTML bullet list yang sudah dibuat
                 '{{issue_title}}' => htmlspecialchars($issue['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                 '{{urgency_level}}' => htmlspecialchars($issue['condition'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                 '{{location}}' => htmlspecialchars($issue['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                 '{{attachment_block}}' => $attachment_html_block,
                 '{{history_block}}' => $history_html,
                 '{{breadcrumb_html}}' => $breadcrumb_html,
             ];

            // --- PERUBAHAN LOGIKA PENGIRIMAN EMAIL DIMULAI DI SINI (Menggunakan BCC Massal) ---
            
            // 1. Kumpulkan semua alamat unik
            $all_emails_string = trim($issue['pic_emails'] . ',' . $issue['cc_emails'] . ',' . $issue['bcc_emails'] . ',' . $issue['drafter_email'], ',');
            $all_recipients_raw = array_unique(array_filter(array_map('trim', explode(',', $all_emails_string))));
            
            // Email yang melakukan update (untuk di-skip sebagai To/CC, dan dijadikan satu To: atau BCC)
            $commenter_email = $comment_id && isset($updates[count($updates)-1]) ? $updates[count($updates)-1]['created_by'] : '';
            
            // Tentukan penerima utama (TO:)
            // Ambil email pertama sebagai penerima TO: yang 'nampak' oleh semua orang
            $to_recipient = array_shift($all_recipients_raw);
            
            // Sisa penerima (termasuk yang meng-update jika dia bukan TO: dan bukan saat ada komentar)
            $bcc_recipients = $all_recipients_raw;

            // Jika ada komentar baru, pastikan pengirim komentar tidak menerima notifikasi ganda
            if (!empty($commenter_email)) {
                if ($to_recipient === $commenter_email) {
                    // Jika TO: adalah pengirim, pindahkan TO: ke BCC dan ambil penerima TO: baru
                    $to_recipient = array_shift($bcc_recipients);
                }
                // Hapus pengirim dari daftar BCC
                $bcc_recipients = array_filter($bcc_recipients, function($email) use ($commenter_email) {
                    return $email !== $commenter_email;
                });
            }
            
            // Jika setelah filter hanya tersisa satu atau tanpa penerima
            if (empty($to_recipient) && !empty($bcc_recipients)) {
                $to_recipient = array_shift($bcc_recipients);
            } elseif (empty($to_recipient) && empty($bcc_recipients)) {
                return false; // Tidak ada penerima yang valid
            }

            $mail->clearAllRecipients();
            $mail->clearBCCs();

            // Atur Penerima TO: (Wajib ada satu)
            $mail->addAddress($to_recipient);
            
            // Atur Penerima BCC: (Sisa semua penerima)
            foreach ($bcc_recipients as $recipient) {
                 if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                    $mail->addBCC($recipient);
                 }
            }
            
            // Konten Email (Subjek & Body)
            $body = str_replace(array_keys($common_replacements), array_values($common_replacements), $base_body);
            
            if ($comment_id) {
                $mail->Subject = 'Update on Ticket: ' . $issue['title'];
                $body = str_replace(['{{preheader}}', '{{header_title}}', '{{main_description}}'], ['A new update on ticket: ' . $issue['title'], 'Ticket Updated', 'A new update has been posted. See history for details.'], $body);
            } else {
                $mail->Subject = 'New Ticket Created: ' . $issue['title'];
                // Perubahan untuk dukungan Emoji/UTF-8 pada deskripsi
                $body = str_replace(['{{preheader}}', '{{header_title}}', '{{main_description}}'], ['New ticket created: ' . $issue['title'], 'New Ticket Created', nl2br(htmlspecialchars($issue['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))], $body);
            }
            
            $body = str_replace(['{{cta_link}}', '{{cta_text}}'], [BASE_URL . '?page=view_ticket&token=' . $issue['access_token'], 'View Full Ticket'], $body);
            $mail->Body = $body; $mail->send();
            
            // --- PERUBAHAN LOGIKA PENGIRIMAN EMAIL SELESAI DI SINI ---
            
        } catch (Exception $e) { 
            // Tidak bisa menggunakan session flash di background script, jadi kita log ke file
            error_log("Mailer Error: {$mail->ErrorInfo}\n", 3, "email_error.log");
            return false; 
        }
        return true;
    }
}

if (!function_exists('send_email_in_background')) {
    function send_email_in_background($issue_id, $comment_id = null) {
        $comment_id_arg = $comment_id ? (int)$comment_id : '';
        $php_executable = PHP_BINARY; // Menggunakan path PHP yang sedang berjalan
        $worker_script = __DIR__ . '/email_worker.php';
        $command = escapeshellcmd($php_executable) . ' ' . escapeshellarg($worker_script) . ' ' . (int)$issue_id . ' ' . $comment_id_arg;
        
        // Cek sistem operasi untuk menjalankan command di background
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Untuk Windows
            pclose(popen('start /B ' . $command, 'r'));
        } else {
            // Untuk Unix/Linux/Mac
            exec($command . ' > /dev/null 2>&1 &');
        }
    }
}

?>
