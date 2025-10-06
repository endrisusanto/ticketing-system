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
            echo '<div class="p-4 mb-4 text-sm text-'.$type.'-800 bg-'.$type.'-100 rounded-lg" role="alert">' . htmlspecialchars($_SESSION['flash'][$key]) . '</div>';
            unset($_SESSION['flash'][$key]);
        }
    }
}

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
                $author_display = htmlspecialchars($update['created_by']);
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
                
                $history_html .= '<div class="bubble" style="background-color: #ffffff;">
                                    <p style="font-size: 13px; font-weight: 600; color: #1e293b; margin: 0 0 4px;">'.$author_display.' <span style="font-size: 11px; color: #94a3b8; font-weight: normal;">'.date('d M Y, H:i', strtotime($update['created_at'])).'</span></p>
                                    <p style="font-size: 14px; color: #334155; margin: 0;">'.nl2br(htmlspecialchars($update['notes'])).'</p>
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

            // REPLACEMENTS
            $common_replacements = [
                '{{theme_color}}' => $theme_color, '{{theme_color_light}}' => $theme_color_light,
                '{{drafter_name}}' => htmlspecialchars($issue['drafter_name']),
                '{{pic_emails}}' => htmlspecialchars($issue['pic_emails']),
                '{{issue_title}}' => htmlspecialchars($issue['title']),
                '{{urgency_level}}' => htmlspecialchars($issue['condition']),
                '{{location}}' => htmlspecialchars($issue['location']),
                '{{attachment_block}}' => $attachment_html_block,
                '{{history_block}}' => $history_html,
                '{{breadcrumb_html}}' => $breadcrumb_html,
            ];

            // SENDING LOGIC...
            $all_recipients = array_unique(array_filter(array_map('trim', array_merge(explode(',', $issue['pic_emails']), explode(',', $issue['cc_emails']), explode(',', $issue['bcc_emails']), [$issue['drafter_email']]))));
            $commenter_email = $comment_id && isset($updates[count($updates)-1]) ? $updates[count($updates)-1]['created_by'] : '';
            
            foreach ($all_recipients as $recipient) {
                if ($comment_id && $recipient === $commenter_email) continue;
                
                $mail->clearAllRecipients(); $mail->addAddress($recipient);
                $body = str_replace(array_keys($common_replacements), array_values($common_replacements), $base_body);
                
                if ($comment_id) {
                    $mail->Subject = 'Update on Ticket: ' . $issue['title'];
                    $body = str_replace(['{{preheader}}', '{{header_title}}', '{{main_description}}'], ['A new update on ticket: ' . $issue['title'], 'Ticket Updated', 'A new update has been posted. See history for details.'], $body);
                } else {
                    $mail->Subject = 'New Ticket Created: ' . $issue['title'];
                    $body = str_replace(['{{preheader}}', '{{header_title}}', '{{main_description}}'], ['New ticket created: ' . $issue['title'], 'New Ticket Created', nl2br(htmlspecialchars($issue['description']))], $body);
                }
                
                $body = str_replace(['{{cta_link}}', '{{cta_text}}'], [BASE_URL . '?page=view_ticket&token=' . $issue['access_token'], 'View Full Ticket'], $body);
                $mail->Body = $body; $mail->send();
            }
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