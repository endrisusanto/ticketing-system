<?php
require_once 'config.php';

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
                    // Kita membungkusnya dalam <tr> dan <td> agar menjadi baris baru di tabel bersarang
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
                
                $history_html .= '<div class="bubble" style="background-color: #e1e1e1;">
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
        } catch (Exception $e) { $_SESSION['flash']['error_detail'] = "Mailer Error: {$mail->ErrorInfo}"; return false; }
        return true;
    }
}

// ============== LOGIKA & ROUTING APLIKASI ===============
$page = $_GET['page'] ?? 'home';
$action = $_POST['action'] ?? null;
$user = get_app_user($pdo);

if ($action) {
    switch ($action) {
        case 'register':
            $name = $_POST['name'];
            $email = $_POST['email'];
            $password = $_POST['password'];
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $verification_token = bin2hex(random_bytes(32));

            try {
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, verification_token) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $email, $hashed_password, $verification_token]);
                
                if (send_verification_email($email, $verification_token)) {
                     $_SESSION['flash']['success'] = 'Registration successful! Please check your email to verify your account.';
                } else {
                     $_SESSION['flash']['error'] = 'Registration successful, but failed to send verification email.';
                }
                redirect('?page=login');
            } catch (PDOException $e) {
                if ($e->errorInfo[1] == 1062) {
                    $error = 'Email already exists.';
                } else {
                    $error = 'Registration failed. Please try again.';
                }
            }
            break;

        case 'login':
            $email = $_POST['email'];
            $password = $_POST['password'];
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user_data && password_verify($password, $user_data['password'])) {
                if ($user_data['is_verified'] == 0) {
                    $error = 'Your account is not verified. Please check your email.';
                } else {
                    $_SESSION['user_id'] = $user_data['id'];
                    if (isset($_SESSION['redirect_url'])) {
                        $redirect_url = $_SESSION['redirect_url'];
                        unset($_SESSION['redirect_url']);
                        header('Location: ' . $redirect_url);
                        exit();
                    }
                    redirect('?page=dashboard');
                }
            } else {
                $error = 'Invalid email or password.';
            }
            break;

        case 'forgot_password':
            $email = $_POST['email'];
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND is_verified = 1");
            $stmt->execute([$email]);
            $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user_data) {
                $temp_password = bin2hex(random_bytes(4)); // 8-char password
                $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
                $stmt_update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt_update->execute([$hashed_password, $user_data['id']]);
                send_password_reset_email($email, $temp_password);
            }
            $_SESSION['flash']['success'] = 'If an account with that email exists, a temporary password has been sent.';
            redirect('?page=login');
            break;
        case 'change_username':
            if (!is_logged_in()) redirect('?page=login');
            $new_name = trim($_POST['name']);

            if (empty($new_name)) {
                $_SESSION['flash']['error'] = 'Name cannot be empty.';
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE users SET name = ? WHERE id = ?");
                    $stmt->execute([$new_name, $user['id']]);
                    $_SESSION['flash']['success'] = 'Name changed successfully.';
                } catch (PDOException $e) {
                    $_SESSION['flash']['error'] = 'Failed to change name. Please try again.';
                }
            }
            redirect('?page=profile');
            break;    

        case 'change_password':
            if (!is_logged_in()) redirect('?page=login');
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];

            if ($new_password !== $confirm_password) {
                $_SESSION['flash']['error'] = 'New passwords do not match.';
            } elseif (password_verify($current_password, $user['password'])) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $user['id']]);
                $_SESSION['flash']['success'] = 'Password changed successfully.';
            } else {
                $_SESSION['flash']['error'] = 'Incorrect current password.';
            }
            redirect('?page=profile');
            break;
            
        case 'create_ticket':
            if (!is_logged_in()) redirect('?page=login');
            $title = $_POST['title'];
            $description = $_POST['description'];
            $location = $_POST['location'];
            $condition = $_POST['condition'];
            $pic_emails = $_POST['pic_emails_hidden'] ?? '';
            $cc_emails = $_POST['cc_emails_hidden'] ?? '';
            $bcc_emails = $_POST['bcc_emails_hidden'] ?? '';
            $drafter_id = $_SESSION['user_id'];
            $access_token = bin2hex(random_bytes(32));
            
            $image_paths = [];
            if (isset($_FILES['images']) && count($_FILES['images']['name']) > 0 && $_FILES['images']['name'][0] != "") {
                $target_dir = "uploads/";
                if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
                
                foreach($_FILES['images']['name'] as $key => $name) {
                    if ($_FILES['images']['error'][$key] == 0) {
                        $file_name = time() . '_' . basename($name);
                        $target_file = $target_dir . $file_name;
                        if (move_uploaded_file($_FILES['images']['tmp_name'][$key], $target_file)) {
                            $image_paths[] = $target_file;
                        }
                    }
                }
            }
            $image_paths_json = count($image_paths) > 0 ? json_encode($image_paths) : null;

            try {
                $stmt = $pdo->prepare("INSERT INTO issues (title, description, location, `condition`, pic_emails, cc_emails, bcc_emails, drafter_id, access_token, image_paths) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $description, $location, $condition, $pic_emails, $cc_emails, $bcc_emails, $drafter_id, $access_token, $image_paths_json]);
                $issue_id = $pdo->lastInsertId();
                if (send_notification_email($pdo, $issue_id)) {
                    $_SESSION['flash']['success'] = 'Ticket created and notification sent.';
                } else {
                    $_SESSION['flash']['error'] = 'Ticket created, but failed to send email. Please check error details.';
                }
                redirect('?page=dashboard');
            } catch (PDOException $e) {
                $error = 'Failed to create ticket. ' . $e->getMessage();
            }
            break;
        
        case 'update_status_viewer':
            if (!is_logged_in()) redirect('?page=login');
            $issue_id = $_POST['issue_id'];
            $token = $_POST['token']; // Mengambil token dari POST
            $new_status = $_POST['status'] ?? null;
            $notes = trim($_POST['comment']);

            $stmt = $pdo->prepare("SELECT * FROM issues WHERE id = ? AND access_token = ?");
            $stmt->execute([$issue_id, $token]);
            $issue = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($issue) {
                $drafter_email_stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
                $drafter_email_stmt->execute([$issue['drafter_id']]);
                $drafter_email = $drafter_email_stmt->fetchColumn();

                $allowed_emails = array_merge(explode(',', $issue['pic_emails']), explode(',', $issue['cc_emails']), explode(',', $issue['bcc_emails']), [$drafter_email]);
                $is_allowed_to_comment = in_array($user['email'], array_map('trim', $allowed_emails));
                $is_allowed_to_change_status = in_array($user['email'], array_map('trim', explode(',', $issue['pic_emails']))) || $user['id'] == $issue['drafter_id'];

                $pdo->beginTransaction();
                try {
                    // Update status
                    if ($is_allowed_to_change_status && $new_status && $new_status !== $issue['status']) {
                        $stmt_update = $pdo->prepare("UPDATE issues SET status = ? WHERE id = ?");
                        $stmt_update->execute([$new_status, $issue_id]);
                        $status_note = "Status changed from {$issue['status']} to {$new_status} by {$user['email']}";
                        $stmt_log = $pdo->prepare("INSERT INTO issue_updates (issue_id, notes, created_by, is_status_change) VALUES (?, ?, ?, 1)");
                        $stmt_log->execute([$issue_id, $status_note, $user['email']]);
                        send_notification_email($pdo, $issue_id, $pdo->lastInsertId());
                    }
                    
                    // Add comment
                    if ($is_allowed_to_comment && (!empty($notes) || !empty($_FILES['comment_attachments']['name'][0]))) {
                        $attachments = [];
                        if (isset($_FILES['comment_attachments']) && !empty($_FILES['comment_attachments']['name'][0])) {
                            $target_dir = "uploads/";
                            if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                            foreach($_FILES['comment_attachments']['name'] as $key => $name) {
                                if ($_FILES['comment_attachments']['error'][$key] == 0) {
                                    $file_name = time() . '_' . uniqid() . '_' . basename($name);
                                    if (move_uploaded_file($_FILES['comment_attachments']['tmp_name'][$key], $target_dir . $file_name)) {
                                        $attachments[] = $target_dir . $file_name;
                                    }
                                }
                            }
                        }
                        $attachments_json = count($attachments) > 0 ? json_encode($attachments) : null;
                        $stmt_notes = $pdo->prepare("INSERT INTO issue_updates (issue_id, notes, created_by, attachments) VALUES (?, ?, ?, ?)");
                        $stmt_notes->execute([$issue_id, $notes, $user['email'], $attachments_json]);
                        send_notification_email($pdo, $issue_id, $pdo->lastInsertId());
                    }
                    $pdo->commit();
                } catch (Exception $e) { $pdo->rollBack(); }
            }
            redirect("?page=view_ticket&token=$token");
            break;

        case 'add_comment_ajax':
            header('Content-Type: application/json');
            if (!is_logged_in()) {
                echo json_encode(['success' => false, 'message' => 'Not logged in.']);
                exit();
            }
            $issue_id = $_POST['issue_id'];
            $comment = trim($_POST['comment']);
            $response = ['success' => false];

            if (empty($comment) && (empty($_FILES['attachments']) || $_FILES['attachments']['error'][0] == UPLOAD_ERR_NO_FILE)) {
                $response['message'] = 'Comment or attachment cannot be empty.';
                echo json_encode($response);
                exit();
            }

            $stmt = $pdo->prepare("SELECT i.*, u.email as drafter_email FROM issues i JOIN users u ON i.drafter_id = u.id WHERE i.id = ?");
            $stmt->execute([$issue_id]);
            $issue = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($issue) {
                $allowed_emails = array_merge(explode(',', $issue['pic_emails']), explode(',', $issue['cc_emails']), explode(',', $issue['bcc_emails']), [$issue['drafter_email']]);
                $is_allowed = in_array($user['email'], array_map('trim', $allowed_emails));

                if ($is_allowed) {
                    try {
                        $attachments = [];
                        if (isset($_FILES['attachments']) && count($_FILES['attachments']['name']) > 0 && $_FILES['attachments']['name'][0] != '') {
                             $target_dir = "uploads/";
                             if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
                             foreach($_FILES['attachments']['name'] as $key => $name) {
                                 if ($_FILES['attachments']['error'][$key] == 0) {
                                    $file_name = time() . '_' . uniqid() . '_' . basename($name);
                                    $target_file = $target_dir . $file_name;
                                    if (move_uploaded_file($_FILES['attachments']['tmp_name'][$key], $target_file)) {
                                        $attachments[] = $target_file;
                                    }
                                 }
                             }
                        }
                        $attachments_json = count($attachments) > 0 ? json_encode($attachments) : null;

                        $stmt_insert = $pdo->prepare("INSERT INTO issue_updates (issue_id, notes, created_by, attachments) VALUES (?, ?, ?, ?)");
                        $stmt_insert->execute([$issue_id, $comment, $user['email'], $attachments_json]);
                        $last_id = $pdo->lastInsertId();

                        send_notification_email($pdo, $issue_id, $last_id);

                        $stmt_fetch = $pdo->prepare("SELECT * FROM issue_updates WHERE id = ?");
                        $stmt_fetch->execute([$last_id]);
                        $new_comment = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
                        
                        $new_comment['author_display'] = $user['email'];

                        $response['success'] = true;
                        $response['comment'] = $new_comment;
                     } catch (PDOException $e) {
                        $response['message'] = 'Database error: ' . $e->getMessage();
                     }
                } else {
                    $response['message'] = 'You are not authorized to comment on this ticket.';
                }
            } else {
                 $response['message'] = 'Invalid operation. Issue not found.';
            }
            echo json_encode($response);
            exit();

        case 'resend_email':
            if (!is_logged_in()) redirect('?page=login');
            $issue_id = $_POST['issue_id'];

            $stmt = $pdo->prepare("SELECT id FROM issues WHERE id = ? AND drafter_id = ?");
            $stmt->execute([$issue_id, $_SESSION['user_id']]);
            if ($stmt->fetch()) {
                if (send_notification_email($pdo, $issue_id)) {
                    $_SESSION['flash']['success'] = 'Notification has been resent.';
                } else {
                    $_SESSION['flash']['error'] = 'Failed to resend notification.';
                }
            } else {
                $_SESSION['flash']['error'] = 'Invalid operation.';
            }
            redirect('?page=dashboard');
            break;
    }
}
if ($page === 'verify') {
    $token = $_GET['token'] ?? null;
    if (!$token) {
        $_SESSION['flash']['error'] = 'Invalid verification link.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE verification_token = ?");
        $stmt->execute([$token]);
        $user_to_verify = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user_to_verify) {
            $stmt_update = $pdo->prepare("UPDATE users SET is_verified = 1, verification_token = NULL WHERE id = ?");
            $stmt_update->execute([$user_to_verify['id']]);
            $_SESSION['flash']['success'] = 'Your account has been verified! You can now log in.';
        } else {
             $_SESSION['flash']['error'] = 'Invalid or expired verification link.';
        }
    }
    redirect('?page=login');
}
if ($page === 'home') {
    if (is_logged_in()) {
        redirect('?page=dashboard');
    } else {
        redirect('?page=login');
    }
}
if ($page === 'logout') {
    session_destroy();
    redirect('?page=login');
}

?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Issue Ticket System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .kanban-column { min-height: 200px; }
        #issueModal { backdrop-filter: blur(4px); background-color: rgba(0,0,0,0.5); }
        .kanban-card { transition: all 0.2s ease-in-out; }
        .kanban-card:hover { transform: translateY(-4px); box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1); }
        .condition-border-Urgent { border-top: 4px solid #ef4444; }
        .condition-border-High { border-top: 4px solid #f59e0b; }
        .condition-border-Normal { border-top: 4px solid #3b82f6; }
        .condition-border-Low { border-top: 4px solid #22c55e; }
        .form-input {
            border: 1px solid #cbd5e1;
        }
        .email-tags-container {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            padding: 8px;
            border-radius: 0.5rem;
        }
        .email-tag {
            display: inline-flex;
            align-items: center;
            background-color: #e2e8f0;
            color: #475569;
            border-radius: 9999px;
            padding: 4px 8px;
            font-size: 0.875rem;
        }
        .email-tag span {
            margin-right: 4px;
        }
        .email-tag button {
            color: #94a3b8;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            line-height: 1;
        }
        .email-tags-container input {
            flex-grow: 1;
            border: none;
            outline: none;
            padding: 4px;
            background: transparent;
        }
        .drag-over {
            border-color: #4f46e5;
            background-color: #eef2ff;
        }
    </style>
</head>
<body class="h-full antialiased">
    <div id="loading-overlay" class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/50 backdrop-blur-sm">
        <div class="flex flex-col items-center gap-4 rounded-xl bg-white p-8 text-center shadow-2xl">
            <svg class="h-16 w-16 animate-spin text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <h3 class="text-lg font-bold text-slate-800">Sending Notification...</h3>
            <p class="text-sm text-slate-500">Please wait, this may take a moment.</p>
        </div>
    </div>
    <div class="min-h-full">
        <?php if (is_logged_in() && $page != 'pic_update' && $page != 'view_ticket'): ?>
        <nav class="bg-white/60 backdrop-blur-lg border-b border-slate-200 sticky top-0 z-40">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 text-slate-800 font-bold text-lg flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-indigo-600"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><line x1="10" y1="9" x2="8" y2="9"></line></svg>
                            <span>Issue Ticket System</span>
                        </div>
                        <div class="hidden md:block">
                            <div class="ml-10 flex items-baseline space-x-4">
                                <a href="?page=dashboard" class="text-slate-700 hover:text-indigo-600 px-3 py-2 text-sm font-medium">Dashboard</a>
                                <a href="?page=create_ticket" class="text-slate-700 hover:text-indigo-600 px-3 py-2 text-sm font-medium">Create Ticket</a>
                            </div>
                        </div>
                    </div>
                    <div class="hidden md:block">
                        <div class="ml-4 flex items-center md:ml-6">
                            <span class="text-slate-500 mr-4 text-sm">Hi, <?php echo htmlspecialchars($user['name']); ?></span>
                             <a href="?page=profile" class="text-slate-500 hover:text-indigo-600 px-3 py-2 text-sm font-medium">Profile</a>
                            <a href="?page=logout" class="text-slate-500 hover:text-indigo-600 px-3 py-2 text-sm font-medium">Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </nav>
        <?php endif; ?>

        <main class="<?php echo ($page === 'login' || $page === 'register' || $page === 'pic_update' || $page === 'forgot_password' || $page === 'verify') ? '' : 'py-10' ?>">
            <div class="mx-auto <?php echo ($page === 'pic_update' || $page === 'view_ticket' || $page === 'profile' || $page === 'forgot_password') ? 'max-w-4xl' : 'max-w-7xl'; ?> sm:px-6 lg:px-8">
                <?php if ($page !== 'login' && $page !== 'register'  && $page !== 'forgot_password' && $page !== 'verify'): ?>
                    <?php 
                        flash_message('success'); 
                        flash_message('error'); 
                        if (isset($_SESSION['flash']['error_detail'])) {
                            echo '<div class="p-4 mb-4 text-sm text-yellow-800 bg-yellow-100 rounded-lg" role="alert"><strong>Debugging Info:</strong><br><pre>' . htmlspecialchars($_SESSION['flash']['error_detail']) . '</pre></div>';
                            unset($_SESSION['flash']['error_detail']);
                        }
                    ?>
                <?php endif; ?>
                
                <?php
                switch ($page):
                    case 'login':
                    case 'register':
                    ?>
                        <div class="flex min-h-screen flex-col justify-center items-center px-6 lg:px-8">
                            <div class="sm:mx-auto sm:w-full sm:max-w-md">
                                <div class="bg-white p-8 shadow-xl rounded-2xl">
                                    <h2 class="text-center text-2xl font-bold tracking-tight text-slate-900 mb-6">
                                        <?php echo $page === 'login' ? 'Welcome Back!' : 'Create Your Account'; ?>
                                    </h2>
                                    <?php if ($page === 'login') { flash_message('success'); flash_message('error'); } ?>
                                    <?php if (isset($error)): ?>
                                        <div class="p-3 mb-4 text-sm text-red-700 bg-red-100 rounded-lg" role="alert"><?php echo htmlspecialchars($error); ?></div>
                                    <?php endif; ?>
                                    <form class="space-y-6" action="?page=<?php echo $page; ?>" method="POST">
                                        <input type="hidden" name="action" value="<?php echo $page; ?>">
                                        <?php if ($page === 'register'): ?>
                                        <div>
                                            <label for="name" class="block text-sm font-medium text-slate-700">Full Name</label>
                                            <div class="mt-2"><input id="name" name="name" type="text" required class="block w-full rounded-lg py-3 px-4 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 form-input"></div>
                                        </div>
                                        <?php endif; ?>
                                        <div>
                                            <label for="email" class="block text-sm font-medium text-slate-700">Email address</label>
                                            <div class="mt-2"><input id="email" name="email" type="email" autocomplete="email" required class="block w-full rounded-lg py-3 px-4 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 form-input"></div>
                                        </div>
                                        <div>
                                            <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
                                            <div class="mt-2"><input id="password" name="password" type="password" required class="block w-full rounded-lg py-3 px-4 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 form-input"></div>
                                        </div>
                                        <?php if ($page === 'login'): ?>
                                        <div class="text-sm text-right">
                                            <a href="?page=forgot_password" class="font-semibold text-indigo-600 hover:text-indigo-500">Forgot password?</a>
                                        </div>
                                        <?php endif; ?>
                                        <div><button type="submit" class="flex w-full justify-center rounded-lg bg-indigo-600 px-3 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-colors">
                                            <?php echo $page === 'login' ? 'Sign in' : 'Register'; ?>
                                        </button></div>
                                    </form>
                                    <p class="mt-8 text-center text-sm text-slate-500">
                                        <?php if($page === 'login'): ?>
                                            Not a member? <a href="?page=register" class="font-semibold text-indigo-600 hover:text-indigo-500">Register here</a>
                                        <?php else: ?>
                                            Already a member? <a href="?page=login" class="font-semibold text-indigo-600 hover:text-indigo-500">Sign in</a>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php
                        break;
                    case 'forgot_password':
                    ?>
                        <div class="flex min-h-screen flex-col justify-center items-center px-6 lg:px-8">
                             <div class="sm:mx-auto sm:w-full sm:max-w-md">
                                <div class="bg-white p-8 shadow-xl rounded-2xl">
                                    <h2 class="text-center text-2xl font-bold tracking-tight text-slate-900 mb-6">Reset Password</h2>
                                    <p class="text-center text-sm text-slate-600 mb-6">Enter your email address and we will send you a temporary password.</p>
                                    <form class="space-y-6" action="?page=forgot_password" method="POST">
                                        <input type="hidden" name="action" value="forgot_password">
                                        <div>
                                            <label for="email" class="block text-sm font-medium text-slate-700">Email address</label>
                                            <div class="mt-2"><input id="email" name="email" type="email" autocomplete="email" required class="block w-full rounded-lg py-3 px-4 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 form-input"></div>
                                        </div>
                                        <div><button type="submit" class="flex w-full justify-center rounded-lg bg-indigo-600 px-3 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-colors">Send Reset Link</button></div>
                                    </form>
                                    <p class="mt-8 text-center text-sm text-slate-500">
                                        Remember your password? <a href="?page=login" class="font-semibold text-indigo-600 hover:text-indigo-500">Sign in</a>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php
                        break;
                    case 'profile':
                        if(!is_logged_in()) redirect('?page=login');
                    ?>
                        <header class="mb-8">
                            <h1 class="text-3xl font-bold tracking-tight text-slate-900">User Profile</h1>
                            <p class="text-slate-500 mt-1">Manage your account settings.</p>
                        </header>
                        <div class="bg-white p-8 rounded-2xl shadow-md">
                             <h2 class="text-xl font-bold text-slate-800 mb-6">Update Profile</h2>
                             <form action="?page=profile" method="POST" class="space-y-4 mb-8 border-b pb-8">
                                <input type="hidden" name="action" value="change_username">
                                <div>
                                    <label for="name" class="block text-sm font-medium text-slate-700">Full Name</label>
                                    <input type="text" name="name" id="name" class="mt-1 block w-full rounded-lg py-2 px-3 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 form-input" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                                </div>
                                <div class="pt-2">
                                    <button type="submit" class="inline-flex justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-colors">Update Name</button>
                                </div>
                             </form>

                             <h2 class="text-xl font-bold text-slate-800 mb-6 mt-8">Change Password</h2>
                             <form action="?page=profile" method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="change_password">
                                <div>
                                    <label for="current_password" class="block text-sm font-medium text-slate-700">Current Password</label>
                                    <input type="password" name="current_password" id="current_password" class="mt-1 block w-full rounded-lg py-2 px-3 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 form-input" required>
                                </div>
                                 <div>
                                    <label for="new_password" class="block text-sm font-medium text-slate-700">New Password</label>
                                    <input type="password" name="new_password" id="new_password" class="mt-1 block w-full rounded-lg py-2 px-3 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 form-input" required>
                                </div>
                                 <div>
                                    <label for="confirm_password" class="block text-sm font-medium text-slate-700">Confirm New Password</label>
                                    <input type="password" name="confirm_password" id="confirm_password" class="mt-1 block w-full rounded-lg py-2 px-3 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 form-input" required>
                                </div>
                                <div class="pt-2">
                                    <button type="submit" class="inline-flex justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-colors">Update Password</button>
                                </div>
                             </form>
                        </div>
                    <?php
                        break;
                   case 'dashboard':
    if (!is_logged_in()) redirect('?page=login');

    $current_view = $_GET['view'] ?? 'kanban';

    function get_status_class($status) {
        switch ($status) {
            case 'Open': return 'bg-blue-100 text-blue-800';
            case 'In Progress': return 'bg-yellow-100 text-yellow-800';
            case 'Resolved': return 'bg-green-100 text-green-800';
            case 'Closed': return 'bg-gray-100 text-gray-800';
            default: return 'bg-slate-100 text-slate-800';
        }
    }

    function get_condition_class_table($condition) {
        switch ($condition) {
            case 'Urgent': return 'bg-red-100 text-red-800';
            case 'High': return 'bg-orange-100 text-orange-800';
            case 'Normal': return 'bg-sky-100 text-sky-800';
            case 'Low': return 'bg-emerald-100 text-emerald-800';
            default: return 'bg-slate-100 text-slate-800';
        }
    }

    $user_email_like = '%' . $user['email'] . '%';
    $stmt_issues = $pdo->prepare("SELECT i.*, u.name as drafter_name, u.email as drafter_email FROM issues i JOIN users u ON i.drafter_id = u.id WHERE i.drafter_id = ? OR i.pic_emails LIKE ? OR i.cc_emails LIKE ? OR i.bcc_emails LIKE ? ORDER BY i.created_at DESC");
    $stmt_issues->execute([$user['id'], $user_email_like, $user_email_like, $user_email_like]);
    $issues = $stmt_issues->fetchAll(PDO::FETCH_ASSOC);

    $issue_ids = array_column($issues, 'id');
    $updates_by_issue = [];
    if (!empty($issue_ids)) {
        $in_placeholders = implode(',', array_fill(0, count($issue_ids), '?'));
        $stmt_updates = $pdo->prepare("SELECT * FROM issue_updates WHERE issue_id IN ($in_placeholders) ORDER BY created_at ASC");
        $stmt_updates->execute($issue_ids);
        while ($update = $stmt_updates->fetch(PDO::FETCH_ASSOC)) {
            $updates_by_issue[$update['issue_id']][] = $update;
        }
    }

    $issues_with_updates = [];
    foreach ($issues as $issue) {
        $augmented_updates = [];
        $updates = $updates_by_issue[$issue['id']] ?? [];
        foreach($updates as $update) {
            $update['author_display'] = $update['created_by'];
            $augmented_updates[] = $update;
        }
        $issue['updates'] = $augmented_updates;
        $issues_with_updates[] = $issue;
    }

?>
    <header class="mb-8">
        <div class="flex justify-between items-center">
            <h1 class="text-3xl font-bold tracking-tight text-slate-900">Dashboard</h1>
            <div class="flex items-center gap-2 p-1 bg-slate-200 rounded-lg">
                <a href="?page=dashboard&view=kanban" class="<?php echo $current_view === 'kanban' ? 'bg-white text-indigo-600 shadow' : 'text-slate-600'; ?> rounded-md px-3 py-1.5 text-sm font-medium transition-colors">Kanban</a>
                <a href="?page=dashboard&view=table" class="<?php echo $current_view === 'table' ? 'bg-white text-indigo-600 shadow' : 'text-slate-600'; ?> rounded-md px-3 py-1.5 text-sm font-medium transition-colors">Table</a>
            </div>
        </div>
    </header>
    <div>
        <?php if($current_view === 'kanban'):
            $issues_by_status = ['Open' => [], 'In Progress' => [], 'Resolved' => [], 'Closed' => []];
            foreach ($issues_with_updates as $issue) { $issues_by_status[$issue['status']][] = $issue; }
        ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach ($issues_by_status as $status => $status_issues): ?>
            <div class="bg-slate-100/70 rounded-xl p-4">
                <h3 class="font-semibold text-md mb-4 text-slate-800 px-2 flex justify-between">
                    <span><?php echo $status; ?></span>
                    <span class="text-slate-400"><?php echo count($status_issues); ?></span>
                </h3>
                <div class="space-y-4 kanban-column">
                    <?php foreach ($status_issues as $issue): ?>
                    <div class="bg-white p-4 rounded-lg shadow-sm cursor-pointer kanban-card condition-border-<?php echo str_replace(' ', '', $issue['condition']); ?>" data-issue='<?php echo json_encode($issue, JSON_HEX_APOS | JSON_HEX_QUOT); ?>'>
                        <?php 
                            $images = !empty($issue['image_paths']) ? json_decode($issue['image_paths'], true) : [];
                            if (!empty($images) && file_exists($images[0])): 
                        ?>
                            <img src="<?php echo htmlspecialchars($images[0]); ?>" alt="Preview" class="w-full h-32 object-cover rounded-md mb-3">
                        <?php endif; ?>
                        <h4 class="font-semibold text-slate-800"><?php echo htmlspecialchars($issue['title']); ?></h4>
                        <p class="text-sm text-slate-500 mt-1 flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            <?php echo htmlspecialchars($issue['location']); ?>
                        </p>
                        <p class="text-xs text-slate-400 mt-3 pt-3 border-t">To: <?php echo htmlspecialchars($issue['pic_emails']); ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto bg-white rounded-xl shadow-md">
             <table class="min-w-full">
                <thead class="bg-slate-50">
                    <tr>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Title</th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Drafter</th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">PIC</th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Level Urgensi</th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php foreach ($issues_with_updates as $issue): ?>
                    <tr class="hover:bg-slate-50 cursor-pointer kanban-card" data-issue='<?php echo json_encode($issue, JSON_HEX_APOS | JSON_HEX_QUOT); ?>'>
                        <td class="px-6 py-4 whitespace-nowrap"><div class="text-sm font-medium text-slate-900"><?php echo htmlspecialchars($issue['title']); ?></div><div class="text-sm text-slate-500"><?php echo htmlspecialchars($issue['location']); ?></div></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500"><?php echo htmlspecialchars($issue['drafter_name']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500"><?php echo htmlspecialchars($issue['pic_emails']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2.5 py-0.5 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo get_status_class($issue['status']); ?>">
                                <?php echo htmlspecialchars($issue['status']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                             <span class="px-2.5 py-0.5 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo get_condition_class_table($issue['condition']); ?>">
                                <?php echo htmlspecialchars($issue['condition']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500"><?php echo date('d M Y, H:i', strtotime($issue['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
<?php
    break;
                    case 'create_ticket':
                    ?>
                        <header class="mb-8">
                            <h1 class="text-3xl font-bold tracking-tight text-slate-900">Create New Ticket</h1>
                            <p class="text-slate-500 mt-1">Fill in the details below to report a new issue.</p>
                        </header>
                        <div class="bg-white p-8 rounded-2xl shadow-md">
                            <form action="?page=create_ticket" method="POST" enctype="multipart/form-data" id="create-ticket-form">
                                <input type="hidden" name="action" value="create_ticket">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="md:col-span-2">
                                        <label for="title" class="block text-sm font-medium text-slate-700">Issue Title</label>
                                        <input type="text" name="title" id="title" class="mt-2 block w-full rounded-lg py-3 px-4 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 form-input" required>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label for="description" class="block text-sm font-medium text-slate-700">Description</label>
                                        <textarea id="description" name="description" rows="4" class="mt-2 block w-full rounded-lg shadow-sm focus:border-indigo-500 focus:ring-indigo-500 form-input p-3" required></textarea>
                                    </div>
                                    <div>
                                        <label for="location" class="block text-sm font-medium text-slate-700">Location</label>
                                        <input type="text" name="location" id="location" class="mt-2 block w-full rounded-lg py-3 px-4 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 form-input" required>
                                    </div>
                                    <div>
                                        <label for="condition" class="block text-sm font-medium text-slate-700">Level Urgensi</label>
                                        <select id="condition" name="condition" class="mt-2 block w-full rounded-lg py-3 px-4 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 form-input" required>
                                            <option>Urgent</option>
                                            <option>High</option>
                                            <option selected>Normal</option>
                                            <option>Low</option>
                                        </select>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-slate-700">PIC Email(s)</label>
                                        <div class="mt-2 email-tags-container form-input">
                                            <input type="text" id="pic-input" placeholder="Add PIC emails and press Enter..." class="email-chip-input">
                                        </div>
                                        <input type="hidden" name="pic_emails_hidden" id="pic-emails-hidden">
                                    </div>
                                     <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-slate-700">CC (Optional)</label>
                                        <div class="mt-2 email-tags-container form-input">
                                            <input type="text" id="cc-input" placeholder="Add emails and press Enter..." class="email-chip-input">
                                        </div>
                                        <input type="hidden" name="cc_emails_hidden" id="cc-emails-hidden">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-slate-700">BCC (Optional)</label>
                                        <div class="mt-2 email-tags-container form-input">
                                            <input type="text" id="bcc-input" placeholder="Add emails and press Enter..." class="email-chip-input">
                                        </div>
                                        <input type="hidden" name="bcc_emails_hidden" id="bcc-emails-hidden">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-slate-700">Upload Image (Optional)</label>
                                        <div id="image-upload-container" class="mt-2 flex justify-center rounded-lg border border-dashed border-slate-300 px-6 py-10 transition-colors duration-300">
                                            <div id="image-prompt" class="text-center">
                                                <svg class="mx-auto h-12 w-12 text-slate-400" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true"><path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                                <div class="mt-4 flex text-sm text-slate-600">
                                                    <label for="images" class="relative cursor-pointer rounded-md bg-white font-medium text-indigo-600 focus-within:outline-none focus-within:ring-2 focus-within:ring-indigo-500 focus-within:ring-offset-2 hover:text-indigo-500">
                                                        <span>Upload files</span>
                                                        <input id="images" name="images[]" type="file" class="sr-only" accept="image/png, image/jpeg, image/gif" multiple>
                                                    </label>
                                                    <p class="pl-1">or drag and drop</p>
                                                </div>
                                                <p class="text-xs text-slate-500">PNG, JPG, GIF up to 10MB</p>
                                            </div>
                                            <div id="image-preview-wrapper" class="hidden w-full">
                                                <div id="image-preview-list" class="flex flex-wrap gap-4"></div>
                                                <button type="button" id="remove-all-images-btn" class="mt-4 text-sm text-red-600 hover:text-red-800">Remove all images</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-6 text-right">
                                    <button type="submit" class="inline-flex justify-center rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-colors">Save Ticket</button>
                                </div>
                            </form>
                        </div>
                    <?php
                        break;
                    case 'view_ticket':
                        $token = $_GET['token'] ?? null;
                        if (!$token) {
                             echo '<div class="p-4 text-red-700 bg-red-100 rounded-lg">Error: No token provided.</div>';
                             break;
                        }
                        
                        $stmt = $pdo->prepare("SELECT i.*, u.email as drafter_email, u.name as drafter_name FROM issues i JOIN users u ON i.drafter_id = u.id WHERE i.access_token = ?");
                        $stmt->execute([$token]);
                        $issue = $stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$issue) {
                            echo '<div class="p-4 text-red-700 bg-red-100 rounded-lg">Error: Invalid link.</div>';
                            break;
                        }
                        
                        $stmt_updates = $pdo->prepare("SELECT * FROM issue_updates WHERE issue_id = ? ORDER BY created_at ASC");
                        $stmt_updates->execute([$issue['id']]);
                        $updates = $stmt_updates->fetchAll(PDO::FETCH_ASSOC);
                        
                        $is_allowed = false;
                        $is_pic_or_drafter = false;
                        if(is_logged_in()) {
                            $allowed_emails = array_merge(explode(',', $issue['cc_emails']), explode(',', $issue['bcc_emails']), [$issue['drafter_email']], explode(',', $issue['pic_emails']));
                            if(in_array($user['email'], array_map('trim', $allowed_emails))) {
                                $is_allowed = true;
                            }
                            if (in_array($user['email'], array_map('trim', explode(',', $issue['pic_emails']))) || $user['id'] == $issue['drafter_id']) {
                                $is_pic_or_drafter = true;
                            }
                        }

                        if (!is_logged_in() && !isset($_SESSION['redirect_url'])) {
                            $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
                        }

                        if(!$is_allowed && is_logged_in()){
                             echo '<div class="p-4 text-red-700 bg-red-100 rounded-lg">Error: You are not authorized to view this ticket.</div>';
                             break;
                        }

                        ?>
                        <div class="bg-white shadow-md sm:rounded-2xl mt-10 p-8">
                            <div class="flex justify-between items-start">
                                <div>
                                    <h2 class="text-2xl font-bold leading-6 text-slate-900"><?php echo htmlspecialchars($issue['title']); ?></h2>
                                    <p class="mt-1 text-sm text-slate-500">Ticket Details</p>
                                </div>
                                <?php if(is_logged_in()): ?>
                                <a href="?page=dashboard" class="text-sm text-indigo-600 hover:text-indigo-800">&larr; Back to Dashboard</a>
                                <?php endif; ?>
                            </div>

                             <div class="p-6 overflow-y-auto bg-slate-50/50 mt-6 rounded-xl">
                                <div class="grid grid-cols-3 grid-rows-auto gap-4">
                                    <div class="col-span-3 bg-white p-4 rounded-xl shadow-sm border min-h-[110px] flex items-center justify-center">
                                         <?php 
                                            function generate_breadcrumb_html_php($current_status) {
                                                $statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
                                                $currentIndex = array_search($current_status, $statuses);
                                                $html = '<ol role="list" class="flex items-center justify-center">';
                                                foreach($statuses as $index => $status) {
                                                    $isLast = $index === count($statuses) - 1;
                                                    $liClass = "relative " . (!$isLast ? "pr-8 sm:pr-20" : "");
                                                    $textColor = 'text-slate-400';
                                                    $content = '';
                                                    if ($index < $currentIndex) { 
                                                        $textColor = 'text-green-600';
                                                        $content = '<div class="absolute inset-0 flex items-center"><div class="h-0.5 w-full bg-indigo-600"></div></div>
                                                                   <a href="#" class="relative flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600">
                                                                       <svg class="h-5 w-5 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.052-.143z" clip-rule="evenodd" /></svg>
                                                                   </a>';
                                                    } else if ($index === $currentIndex) { 
                                                        $textColor = 'font-bold text-indigo-600';
                                                        $content = '<div class="absolute inset-0 flex items-center"><div class="h-0.5 w-full bg-gray-200"></div></div>
                                                                   <a href="#" class="relative flex h-8 w-8 items-center justify-center rounded-full border-2 border-indigo-600 bg-white"><span class="h-2.5 w-2.5 rounded-full bg-indigo-600"></span></a>';
                                                    } else { 
                                                         $content = '<div class="absolute inset-0 flex items-center"><div class="h-0.5 w-full bg-gray-200"></div></div>
                                                                    <a href="#" class="group relative flex h-8 w-8 items-center justify-center rounded-full border-2 border-gray-300 bg-white"><span class="h-2.5 w-2.5 rounded-full bg-transparent"></span></a>';
                                                    }
                                                    $html .= "<li class=\"{$liClass}\">{$content}<div class=\"absolute bottom-[-25px] w-max text-center\"><span class=\"text-xs {$textColor}\">{$status}</span></div></li>";
                                                }
                                                $html .= '</ol>';
                                                return $html;
                                            }
                                            echo generate_breadcrumb_html_php($issue['status']);
                                         ?>
                                    </div>
                                    <div class="col-span-3 md:col-span-1 row-span-2 bg-white p-4 rounded-xl shadow-sm border">
                                        <h4 class="font-semibold text-slate-600 text-sm uppercase tracking-wider">Details</h4>
                                        <dl class="mt-3 text-sm space-y-4">
                                            <div><dt class="text-slate-500">Location</dt><dd class="text-slate-800 font-medium"><?php echo htmlspecialchars($issue['location']); ?></dd></div>
                                            <div><dt class="text-slate-500">Level Urgensi</dt><dd class="text-slate-800 font-medium"><?php echo htmlspecialchars($issue['condition']); ?></dd></div>
                                            <div><dt class="text-slate-500">PIC Email(s)</dt><dd class="text-slate-800 font-medium break-all"><?php echo htmlspecialchars(str_replace(',',', ',$issue['pic_emails'])); ?></dd></div>
                                        </dl>
                                    </div>
                                    <div class="col-span-3 md:col-span-2 bg-white p-4 rounded-xl shadow-sm border">
                                        <h4 class="font-semibold text-slate-600 text-sm uppercase tracking-wider">Description</h4>
                                        <p class="mt-3 text-sm text-slate-700"><?php echo nl2br(htmlspecialchars($issue['description'])); ?></p>
                                    </div>
                                    <?php 
                                        $images = !empty($issue['image_paths']) ? json_decode($issue['image_paths'], true) : [];
                                        if (!empty($images)): 
                                    ?>
                                    <div class="col-span-3 md:col-span-2 bg-white p-4 rounded-xl shadow-sm border">
                                         <h4 class="font-semibold text-slate-600 text-sm uppercase tracking-wider">Attachments</h4>
                                         <div class="mt-3 grid grid-cols-2 md:grid-cols-3 gap-4">
                                            <?php foreach($images as $path): if(file_exists($path)): ?>
                                                <img src="<?php echo htmlspecialchars($path); ?>" alt="Issue Image" class="w-full h-auto object-cover rounded-lg shadow-md">
                                            <?php endif; endforeach; ?>
                                         </div>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-span-3 bg-white p-4 rounded-xl shadow-sm border">
                                        <h4 class="font-semibold text-slate-600 text-sm uppercase tracking-wider mb-4">History & Comments</h4>
                                        <div class="space-y-4 max-h-96 overflow-y-auto pr-2">
                                            <?php foreach($updates as $update): ?>
                                                <div class="text-sm flex gap-3 items-start">
                                                    <?php 
                                                        $isSystem = $update['is_status_change'];
                                                        $author_display = $isSystem ? "System" : htmlspecialchars($update['created_by']);
                                                    ?>
                                                    <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center text-white font-bold <?php echo $isSystem ? 'bg-slate-400' : 'bg-indigo-500'; ?>">
                                                        <?php echo strtoupper(substr($author_display, 0, 1)); ?>
                                                    </div>
                                                    <div class="flex-grow p-3 rounded-lg <?php echo $isSystem ? 'bg-slate-50' : 'bg-indigo-50'; ?>">
                                                        <p class="font-semibold text-slate-800"><?php echo $author_display; ?> <span class="text-xs text-slate-500 font-normal ml-2"><?php echo date('d M Y, H:i', strtotime($update['created_at'])); ?></span></p>
                                                        <p class="mt-1 text-slate-700"><?php echo nl2br(htmlspecialchars($update['notes'])); ?></p>
                                                        <?php
                                                            if (!empty($update['attachments'])) {
                                                                $attachments = json_decode($update['attachments'], true);
                                                                if (is_array($attachments) && count($attachments) > 0) {
                                                                    echo '<div class="mt-2 flex flex-wrap gap-2">';
                                                                    foreach($attachments as $att_path) {
                                                                        if (file_exists($att_path)) {
                                                                            echo '<a href="'.htmlspecialchars($att_path).'" target="_blank"><img src="'.htmlspecialchars($att_path).'" class="w-20 h-20 object-cover rounded-md border hover:ring-2 hover:ring-indigo-400"></a>';
                                                                        }
                                                                    }
                                                                    echo '</div>';
                                                                }
                                                            }
                                                        ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if($is_allowed): ?>
                                        <form action="?page=view_ticket&token=<?php echo htmlspecialchars($token); ?>" method="POST" class="mt-6 border-t pt-4" enctype="multipart/form-data">
                                            <input type="hidden" name="action" value="update_status_viewer">
                                            <input type="hidden" name="issue_id" value="<?php echo $issue['id']; ?>">
                                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                                            <?php if($is_pic_or_drafter): ?>
                                            <div class="mb-4">
                                                <label for="status" class="block text-sm font-medium text-slate-700">Update Status</label>
                                                <select id="status" name="status" class="mt-1 block w-full rounded-lg shadow-sm p-2 form-input">
                                                    <?php 
                                                    $statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
                                                    foreach ($statuses as $s): 
                                                    ?>
                                                        <option <?php if($s === $issue['status']) echo 'selected'; ?> value="<?php echo $s; ?>"><?php echo $s; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div>
                                                <label for="comment" class="block text-sm font-medium text-slate-700">Add a Comment</label>
                                                <textarea name="comment" id="comment" class="mt-1 w-full p-3 border border-slate-300 rounded-lg text-sm" rows="3" placeholder="Type your comment here..."></textarea>
                                            </div>
                                            <div class="mt-2">
                                                <label for="comment-attachments-page" class="text-sm font-medium text-indigo-600 cursor-pointer hover:text-indigo-800 flex items-center gap-2">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                                                    Attach Files
                                                </label>
                                                <input type="file" name="comment_attachments[]" id="comment-attachments-page" class="sr-only" multiple accept="image/png, image/jpeg, image/gif">
                                                <div id="comment-attachment-previews-page" class="mt-2 flex flex-wrap gap-2"></div>
                                            </div>
                                            <div class="text-right mt-4">
                                                <button type="submit" class="px-5 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 transition-colors">Post Update</button>
                                            </div>
                                        </form>
                                        <?php else: ?>
                                            <div class="mt-4 text-center p-4 bg-slate-100 rounded-lg">
                                                <p class="text-sm text-slate-600">Please <a href="?page=login" class="font-semibold text-indigo-600">log in</a> to add a comment.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php
                        break;
                    case 'pic_update':
                        redirect("?page=view_ticket&token=".($_GET['token'] ?? ''));
                        break;
                endswitch;
                ?>
            </div>
        </main>
    </div>

    <div id="issueModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4 transition-opacity duration-300">
        <div class="w-full max-w-4xl max-h-[90vh] bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col transform transition-all duration-300 scale-95 opacity-0" id="modalContent">
            <div class="flex justify-between items-center p-5 border-b border-slate-200">
                <h2 id="modalTitle" class="text-xl font-bold text-slate-800"></h2>
                <button id="closeModalBtn" class="text-slate-400 hover:text-slate-600 text-3xl leading-none">&times;</button>
            </div>
            <div class="p-6 overflow-y-auto bg-slate-50/50">
                <div class="grid grid-cols-3 grid-rows-auto gap-4">
                    <div class="col-span-3 bg-white p-4 rounded-xl shadow-sm border min-h-[110px] flex items-center justify-center">
                        <div id="modalBreadcrumb"></div>
                    </div>
                    <div class="col-span-3 md:col-span-1 row-span-2 bg-white p-4 rounded-xl shadow-sm border">
                        <h4 class="font-semibold text-slate-600 text-sm uppercase tracking-wider">Details</h4>
                        <dl class="mt-3 text-sm space-y-4">
                            <div><dt class="text-slate-500">Location</dt><dd id="modalLocation" class="text-slate-800 font-medium"></dd></div>
                            <div><dt class="text-slate-500">Level Urgensi</dt><dd id="modalCondition" class="text-slate-800 font-medium"></dd></div>
                            <div><dt class="text-slate-500">PIC Email(s)</dt><dd id="modalPicEmail" class="text-slate-800 font-medium break-all"></dd></div>
                            <div id="modal-cc-container" class="hidden"><dt class="text-slate-500">CC</dt><dd id="modalCcEmails" class="text-slate-800 font-medium break-all"></dd></div>
                            <div id="modal-bcc-container" class="hidden"><dt class="text-slate-500">BCC</dt><dd id="modalBccEmails" class="text-slate-800 font-medium break-all"></dd></div>
                        </dl>
                    </div>
                    <div class="col-span-3 md:col-span-2 bg-white p-4 rounded-xl shadow-sm border">
                        <h4 class="font-semibold text-slate-600 text-sm uppercase tracking-wider">Description</h4>
                        <p id="modalDescription" class="mt-3 text-sm text-slate-700"></p>
                    </div>
                    <div id="attachment-bento-box" class="col-span-3 md:col-span-2 bg-white p-4 rounded-xl shadow-sm border hidden">
                         <h4 class="font-semibold text-slate-600 text-sm uppercase tracking-wider">Attachments</h4>
                         <div id="image-grid" class="mt-3 grid grid-cols-2 md:grid-cols-3 gap-4"></div>
                    </div>
                    <div class="col-span-3 bg-white p-4 rounded-xl shadow-sm border">
                        <h4 class="font-semibold text-slate-600 text-sm uppercase tracking-wider mb-4">History & Comments</h4>
                        <div id="modalComments" class="space-y-4 max-h-48 overflow-y-auto pr-2"></div>
                        <form id="modalCommentForm" class="mt-4" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add_comment_ajax">
                            <input type="hidden" id="modalCommentIssueId" name="issue_id">
                            <textarea name="comment" class="w-full p-3 border border-slate-300 rounded-lg text-sm" rows="2" placeholder="Add a comment..."></textarea>
                            <div class="mt-2">
                                <label for="comment-attachments" class="text-sm font-medium text-indigo-600 cursor-pointer hover:text-indigo-800">
                                    + Add Attachment
                                </label>
                                <input type="file" name="attachments[]" id="comment-attachments" class="sr-only" multiple accept="image/png, image/jpeg, image/gif">
                            </div>
                            <div id="comment-attachment-previews" class="mt-2 flex flex-wrap gap-2"></div>
                            <div class="text-right mt-2">
                                <button type="submit" class="px-4 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">Post</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="flex justify-end items-center p-4 border-t border-slate-200 bg-slate-100">
                 <form action="?page=dashboard" method="POST">
                    <input type="hidden" name="action" value="resend_email">
                    <input type="hidden" id="modalResendIssueId" name="issue_id">
                    <button type="submit" class="px-4 py-2 bg-amber-500 text-white text-sm font-medium rounded-lg hover:bg-amber-600 transition-colors">Resend Notification</button>
                </form>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('issueModal');
    const modalContent = document.getElementById('modalContent');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const commentForm = document.getElementById('modalCommentForm');
    
    const closeModal = () => {
        modalContent.classList.remove('scale-100', 'opacity-100');
        modalContent.classList.add('scale-95', 'opacity-0');
        setTimeout(() => modal.classList.add('hidden'), 300);
    };
    closeModalBtn.addEventListener('click', closeModal);

    const openModal = (issueData) => {
        const issue = JSON.parse(issueData.replace(/'/g, '"'));
        
        document.getElementById('modalTitle').textContent = issue.title;
        document.getElementById('modalLocation').textContent = issue.location;
        document.getElementById('modalPicEmail').textContent = issue.pic_emails.replace(/,/g, ', ');
        document.getElementById('modalDescription').innerHTML = issue.description.replace(/\n/g, '<br>');
        document.getElementById('modalCommentIssueId').value = issue.id;
        document.getElementById('modalResendIssueId').value = issue.id;
        
        const conditionSpan = document.createElement('span');
        conditionSpan.textContent = issue.condition;
        conditionSpan.className = `text-xs font-semibold inline-block py-1 px-2.5 uppercase rounded-full ${getConditionClass(issue.condition)}`;
        const modalCondition = document.getElementById('modalCondition');
        modalCondition.innerHTML = '';
        modalCondition.appendChild(conditionSpan);
        
        const attachmentBox = document.getElementById('attachment-bento-box');
        const imageGrid = document.getElementById('image-grid');
        imageGrid.innerHTML = '';

        let images = [];
        if (issue.image_paths) {
            try {
                images = JSON.parse(issue.image_paths);
            } catch (e) { images = []; }
        }

        if (images && images.length > 0) {
            attachmentBox.classList.remove('hidden');
            images.forEach(path => {
                const img = document.createElement('img');
                img.src = path;
                img.className = 'w-full h-auto object-cover rounded-lg shadow-md';
                imageGrid.appendChild(img);
            });
        } else {
            attachmentBox.classList.add('hidden');
        }

        const ccContainer = document.getElementById('modal-cc-container');
        const bccContainer = document.getElementById('modal-bcc-container');
        const ccEmailsEl = document.getElementById('modalCcEmails');
        const bccEmailsEl = document.getElementById('modalBccEmails');

        if (issue.cc_emails) {
            ccEmailsEl.textContent = issue.cc_emails.replace(/,/g, ', ');
            ccContainer.classList.remove('hidden');
        } else {
            ccContainer.classList.add('hidden');
        }

        if (issue.bcc_emails) {
            bccEmailsEl.textContent = issue.bcc_emails.replace(/,/g, ', ');
            bccContainer.classList.remove('hidden');
        } else {
            bccContainer.classList.add('hidden');
        }

        document.getElementById('modalBreadcrumb').innerHTML = generateBreadcrumbHTML(issue.status);

        const commentsContainer = document.getElementById('modalComments');
        commentsContainer.innerHTML = '';
        if (issue.updates && issue.updates.length > 0) {
            issue.updates.forEach(update => addCommentToUI(update));
        } else {
            commentsContainer.innerHTML = '<p class="text-sm text-slate-500 text-center py-4">No comments yet.</p>';
        }

        modal.classList.remove('hidden');
        setTimeout(() => modalContent.classList.add('scale-100', 'opacity-100'), 50);
    };

    const addCommentToUI = (commentData) => {
        const commentsContainer = document.getElementById('modalComments');
        const noCommentsEl = commentsContainer.querySelector('p.text-center');
        if (noCommentsEl) noCommentsEl.remove();

        const commentEl = document.createElement('div');
        commentEl.className = 'text-sm flex gap-3 items-start';
        
        const authorInitial = commentData.author_display.charAt(0).toUpperCase();
        const createdDate = new Date(commentData.created_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });

        let attachmentsHTML = '';
        if (commentData.attachments) {
            try {
                const attachments = JSON.parse(commentData.attachments);
                if (Array.isArray(attachments) && attachments.length > 0) {
                    attachmentsHTML += '<div class="mt-2 flex flex-wrap gap-2">';
                    attachments.forEach(path => {
                         attachmentsHTML += `<a href="${path}" target="_blank"><img src="${path}" class="w-20 h-20 object-cover rounded-md border"></a>`;
                    });
                    attachmentsHTML += '</div>';
                }
            } catch (e) { /* Invalid JSON */ }
        }

        commentEl.innerHTML = `
            <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center text-white font-bold bg-slate-500">
                ${authorInitial}
            </div>
            <div class="flex-grow p-3 rounded-lg bg-slate-50 border">
                <p class="font-semibold text-slate-800">${commentData.author_display} <span class="text-xs text-slate-500 font-normal ml-2">${createdDate}</span></p>
                <p class="mt-1 text-slate-700">${commentData.notes.replace(/\n/g, '<br>')}</p>
                ${attachmentsHTML}
            </div>`;
        commentsContainer.appendChild(commentEl);
        commentsContainer.scrollTop = commentsContainer.scrollHeight;
    };

    commentForm.addEventListener('submit', function(e) { 
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitButton = this.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        submitButton.textContent = 'Posting...';

        fetch('?page=dashboard', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                addCommentToUI(data.comment);
                this.reset();
                document.getElementById('comment-attachment-previews').innerHTML = '';
                const issueId = formData.get('issue_id');
                const card = document.querySelector(`.kanban-card[data-issue*='"id":"${issueId}"']`);
                if(card) {
                    try {
                        const currentData = JSON.parse(card.dataset.issue.replace(/'/g, '"'));
                        if (!currentData.updates) currentData.updates = [];
                        currentData.updates.push(data.comment);
                        card.dataset.issue = JSON.stringify(currentData).replace(/"/g, "'");
                    } catch (e) { console.error('Failed to update card data', e); }
                }
            } else {
                alert(data.message || 'Failed to post comment.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        })
        .finally(() => {
            submitButton.disabled = false;
            submitButton.textContent = 'Post';
        });
    });
    
    const commentAttachmentInput = document.getElementById('comment-attachments');
    const commentAttachmentPreviews = document.getElementById('comment-attachment-previews');
    if(commentAttachmentInput) {
        commentAttachmentInput.addEventListener('change', function(e) {
            commentAttachmentPreviews.innerHTML = '';
            Array.from(e.target.files).forEach(file => {
                if(file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = (event) => {
                        const img = document.createElement('img');
                        img.src = event.target.result;
                        img.className = 'w-16 h-16 object-cover rounded-md border';
                        commentAttachmentPreviews.appendChild(img);
                    }
                    reader.readAsDataURL(file);
                }
            });
        });
    }

    document.querySelectorAll('.kanban-card').forEach(card => card.addEventListener('click', function() { openModal(this.dataset.issue); }));
    
    const uploadContainer = document.getElementById('image-upload-container');
    const imageInput = document.getElementById('images');
    const imagePrompt = document.getElementById('image-prompt');
    const previewWrapper = document.getElementById('image-preview-wrapper');
    const previewList = document.getElementById('image-preview-list');
    const removeAllBtn = document.getElementById('remove-all-images-btn');
    
    if(uploadContainer) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadContainer.addEventListener(eventName, e => {
                e.preventDefault();
                e.stopPropagation();
            }, false);
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            uploadContainer.addEventListener(eventName, () => uploadContainer.classList.add('drag-over'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            uploadContainer.addEventListener(eventName, () => uploadContainer.classList.remove('drag-over'), false);
        });

        uploadContainer.addEventListener('drop', e => {
            imageInput.files = e.dataTransfer.files;
            handleFiles(imageInput.files);
        });
        
        imageInput.addEventListener('change', e => {
             handleFiles(e.target.files);
        });
        
        removeAllBtn.addEventListener('click', () => {
            imageInput.value = '';
            previewList.innerHTML = '';
            previewWrapper.classList.add('hidden');
            imagePrompt.classList.remove('hidden');
        });

        function handleFiles(files) {
            if (files.length === 0) return;
            previewList.innerHTML = '';
            imagePrompt.classList.add('hidden');
            previewWrapper.classList.remove('hidden');
            
            Array.from(files).forEach(file => {
                if (!file.type.startsWith('image/')) return;
                const reader = new FileReader();
                reader.onload = e => {
                    const thumb = document.createElement('div');
                    thumb.className = 'w-24 h-24 border rounded-lg overflow-hidden';
                    thumb.innerHTML = `<img src="${e.target.result}" class="w-full h-full object-cover">`;
                    previewList.appendChild(thumb);
                };
                reader.readAsDataURL(file);
            });
        }
    }


    function setupEmailTagInput(inputId, hiddenInputId) {
        const input = document.getElementById(inputId);
        if (!input) return;
        
        const hiddenInput = document.getElementById(hiddenInputId);
        const container = input.parentElement;
        let emails = [];

        function updateHiddenInput() {
            hiddenInput.value = emails.join(',');
        }

        function createTag(email) {
            const tag = document.createElement('div');
            tag.className = 'email-tag';
            
            const emailSpan = document.createElement('span');
            emailSpan.textContent = email;
            
            const removeBtn = document.createElement('button');
            removeBtn.innerHTML = '&times;';
            removeBtn.addEventListener('click', () => {
                emails = emails.filter(e => e !== email);
                container.removeChild(tag);
                updateHiddenInput();
            });

            tag.appendChild(emailSpan);
            tag.appendChild(removeBtn);
            container.insertBefore(tag, input);
        }

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                let email = input.value.trim().replace(/,/g, '');
                if (email && !emails.includes(email) && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    emails.push(email);
                    createTag(email);
                    updateHiddenInput();
                }
                input.value = '';
            }
        });
    }

    const ticketForm = document.getElementById('create-ticket-form');
    if (ticketForm) {
        ticketForm.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.classList.contains('email-chip-input')) {
                e.preventDefault();
            }
        });
    }

    setupEmailTagInput('pic-input', 'pic-emails-hidden');
    setupEmailTagInput('cc-input', 'cc-emails-hidden');
    setupEmailTagInput('bcc-input', 'bcc-emails-hidden');

    const getConditionClass = (condition) => { 
        switch (condition) {
            case 'Urgent': return 'text-red-800 bg-red-100';
            case 'High': return 'text-amber-800 bg-amber-100';
            case 'Normal': return 'text-blue-800 bg-blue-100';
            case 'Low': return 'text-green-800 bg-green-100';
            default: return 'text-slate-800 bg-slate-100';
        }
     };
    const generateBreadcrumbHTML = (currentStatus) => {
        const statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
        const currentIndex = statuses.indexOf(currentStatus);
        let html = '<ol role="list" class="flex items-center justify-center">';
        statuses.forEach((status, index) => {
            const isLast = index === statuses.length - 1;
            const liClass = `relative ${!isLast ? 'pr-8 sm:pr-20' : ''}`;
            let content = '';
            let textColor = 'text-slate-400';

            if (index < currentIndex) { 
                textColor = 'text-green-600';
                content = `<div class="absolute inset-0 flex items-center"><div class="h-0.5 w-full bg-indigo-600"></div></div>
                           <a href="#" class="relative flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600">
                               <svg class="h-5 w-5 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.052-.143z" clip-rule="evenodd" /></svg>
                           </a>`;
            } else if (index === currentIndex) { 
                textColor = 'font-bold text-indigo-600';
                content = `<div class="absolute inset-0 flex items-center"><div class="h-0.5 w-full bg-gray-200"></div></div>
                           <a href="#" class="relative flex h-8 w-8 items-center justify-center rounded-full border-2 border-indigo-600 bg-white"><span class="h-2.5 w-2.5 rounded-full bg-indigo-600"></span></a>`;
            } else { 
                 content = `<div class="absolute inset-0 flex items-center"><div class="h-0.5 w-full bg-gray-200"></div></div>
                            <a href="#" class="group relative flex h-8 w-8 items-center justify-center rounded-full border-2 border-gray-300 bg-white"><span class="h-2.5 w-2.5 rounded-full bg-transparent"></span></a>`;
            }
            html += `<li class="${liClass}">${content}<div class="absolute bottom-[-25px] w-max text-center"><span class="text-xs ${textColor}">${status}</span></div></li>`;
        });
        html += '</ol>';
        return html;
     };

    const pageAttachmentInput = document.getElementById('comment-attachments-page');
    const pageAttachmentPreviews = document.getElementById('comment-attachment-previews-page');
    if(pageAttachmentInput) {
        pageAttachmentInput.addEventListener('change', function(e) {
            pageAttachmentPreviews.innerHTML = '';
            Array.from(e.target.files).forEach(file => {
                if(file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = (event) => {
                        const previewContainer = document.createElement('div');
                        previewContainer.className = 'relative';
                        const img = document.createElement('img');
                        img.src = event.target.result;
                        img.className = 'w-20 h-20 object-cover rounded-md border';
                        previewContainer.appendChild(img);
                        pageAttachmentPreviews.appendChild(previewContainer);
                    }
                    reader.readAsDataURL(file);
                }
            });
        });
    }

    const loadingOverlay = document.getElementById('loading-overlay');
    
    const showLoading = () => {
        if (loadingOverlay) {
            loadingOverlay.classList.remove('hidden');
            loadingOverlay.classList.add('flex');
        }
    };

    const createTicketForm = document.getElementById('create-ticket-form');
    if (createTicketForm) {
        createTicketForm.addEventListener('submit', showLoading);
    }
    
    const viewTicketForm = document.querySelector('form[action*="page=view_ticket"]');
    if (viewTicketForm) {
        viewTicketForm.addEventListener('submit', showLoading);
    }

    const resendEmailForm = document.querySelector('form[action*="action=resend_email"]');
    if(resendEmailForm){
        resendEmailForm.addEventListener('submit', showLoading);
    }
});
</script>

</body>
</html>