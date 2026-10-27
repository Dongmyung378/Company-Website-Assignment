<?php
require_once __DIR__ . '/../config/bootstrap.php';
$current = require_login(true);
$backUrl = $current['department_name'] === 'Executive' ? 'admin_main.php' : 'main.php';
$current_password_err = $new_password_err = $confirm_password_err = $success_msg = '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    abort_request(405, 'Unsupported request method.');
}
if ($method === 'POST') {
    require_csrf();
    $currentPassword = input_string($_POST, 'current_password');
    $newPassword = input_string($_POST, 'new_password');
    $confirmation = input_string($_POST, 'confirm_password');
    $new_password_err = password_validation_error($newPassword);
    if ($newPassword !== $confirmation) {
        $confirm_password_err = 'The new passwords do not match.';
    }
    if ($currentPassword === '') {
        $current_password_err = 'Enter your current password or temporary password.';
    } elseif ($newPassword === $currentPassword) {
        $new_password_err = 'Choose a different password from your current password.';
    }
    if ($current_password_err === '' && $new_password_err === '' && $confirm_password_err === '') {
        $rateLimited = false;
        $verified = company_authenticate((string) $current['employee_id'], $currentPassword, $rateLimited);
        if ($verified === null) {
            $current_password_err = 'Unable to verify your current password. Check your details or try again later.';
            if ($rateLimited) {
                http_response_code(429);
                header('Retry-After: 900');
            }
        } elseif ((int) $verified['auth_version'] !== (int) $current['auth_version']) {
            company_login_redirect();
        } else {
            $stmt = $conn->prepare('UPDATE login SET password_hash=?,must_change_password=0,auth_version=auth_version+1 WHERE employee_id=? AND auth_version=? AND password_hash=?');
            $stmt->execute([company_hash_password($newPassword), $current['employee_id'], $current['auth_version'], $verified['password_hash']]);
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                company_login_redirect();
            }
            $stmt->close();
            $current['auth_version']++;
            $current['must_change_password'] = 0;
            company_establish_session($current);
            $success_msg = 'Your password has been changed. Other signed-in sessions have been revoked.';
        }
    }
    if ($success_msg === '' && http_response_code() !== 429) {
        http_response_code(422);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Change password</title>
    <style>
        .error {
            color: red;
        }

        .success {
            text-align: center;
            font-size: 40px;
            color: green;
        }

        body {
            font-family: Arial, sans-serif;
        }

        form {
            max-width: 400px;
            margin: auto;
        }

        input[type="password"] {
            width: 100%;
            padding: 8px;
            margin: 4px 0;
        }

        label {
            display: block;
            margin-top: 8px;
        }

        button {
            padding: 8px 16px;
            margin-top: 12px;
        }

        h1 {
            text-align: center;
        }
    </style>
</head>

<body>
    <?php if (!$current['must_change_password']): ?>
        <a href="<?php echo e($backUrl); ?>">Back to Main</a>
    <?php endif; ?>
    <a href="logout.php">Sign out</a>
    <h1>Change Password!</h1>
    <?php if ($current['must_change_password']): ?>
        <p style="text-align:center">Choose a new passphrase before continuing. Use your temporary password as the current password.</p>
    <?php endif; ?>
    <?php if (!empty($success_msg)): ?>
        <p class="success"><?php echo e($success_msg); ?> <a href="<?php echo e($backUrl); ?>">Continue</a></p>
        <script>
            setTimeout(function() {
                window.location.href = <?php echo json_encode($backUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            }, 1500);
        </script>
    <?php endif; ?>

    <form method="POST" action="">
        <?php echo csrf_field(); ?>
        <label for="current_password">Current Password:</label>
        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
        <?php if ($current_password_err !== ''): ?>
            <span class="error"><?php echo e($current_password_err); ?></span>
        <?php endif; ?>
        <label for="new_password">New passphrase (at least 15 characters):</label>
        <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="15" required>
        <?php if (!empty($new_password_err)): ?>
            <span class="error"><?php echo e($new_password_err); ?></span>
        <?php endif; ?>

        <label for="confirm_password">Check Password:</label>
        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="15" required>
        <?php if (!empty($confirm_password_err)): ?>
            <span class="error"><?php echo e($confirm_password_err); ?></span>
        <?php endif; ?>

        <button type="submit">Change Password</button>
    </form>
</body>

</html>
