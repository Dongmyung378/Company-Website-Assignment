<?php
declare(strict_types=1);

/** English is the default; selecting Korean never changes stored business values. */
function ui_language(): string
{
    static $language = null;
    if ($language !== null) return $language;
    $selected = $_GET['lang'] ?? null;
    $stored = $_COOKIE['ui_language'] ?? null;
    $language = is_string($selected) && in_array($selected, ['en', 'ko'], true)
        ? $selected : (is_string($stored) && in_array($stored, ['en', 'ko'], true) ? $stored : 'en');
    if (is_string($selected) && in_array($selected, ['en', 'ko'], true) && $stored !== $language && !headers_sent()) {
        setcookie('ui_language', $language, [
            'expires' => time() + 31536000, 'path' => '/', 'httponly' => true,
            'secure' => function_exists('company_is_https') && company_is_https(), 'samesite' => 'Lax',
        ]);
    }
    return $language;
}

function ui_translations(): array
{
    static $messages = null;
    if ($messages === null) {
        $messages = [];
        foreach (glob(__DIR__ . '/translations/*.ko.php') ?: [] as $path) {
            if (basename($path) === 'common.ko.php') continue;
            $messages = array_replace($messages, require $path);
        }
        $messages = array_replace($messages, require __DIR__ . '/translations/common.ko.php');
    }
    return $messages;
}

function ui_t(string $english, array $values = []): string
{
    $text = $english;
    if (ui_language() === 'ko') {
        $messages = ui_translations();
        $text = $messages[$english] ?? $english;
        if ($text === $english && preg_match('/^(.+) is required and must be at most ([0-9]+) characters\.$/D', $english, $match)) {
            $text = ui_t($match[1]) . ' 항목은 필수이며 ' . $match[2] . '자 이하여야 합니다.';
        } elseif ($text === $english && preg_match('/^(.+) contains invalid characters\.$/D', $english, $match)) {
            $text = ui_t($match[1]) . ' 항목에 사용할 수 없는 문자가 있습니다.';
        } elseif ($text === $english && preg_match('/^(.+) must be a positive whole number\.$/D', $english, $match)) {
            $text = ui_t($match[1]) . ' 항목에는 1 이상의 정수를 입력해 주세요.';
        } elseif ($text === $english && preg_match('/^(.+) must be a valid date in YYYY-MM-DD format\.$/D', $english, $match)) {
            $text = ui_t($match[1]) . ' 항목에 YYYY-MM-DD 형식의 올바른 날짜를 입력해 주세요.';
        } elseif ($text === $english && preg_match('/^The password is too long for this server\. Use at most ([0-9]+) UTF-8 bytes\.$/D', $english, $match)) {
            $text = '이 서버에서 허용하는 비밀번호 길이를 초과했습니다. UTF-8 기준 ' . $match[1] . '바이트 이하로 입력해 주세요.';
        }
    }
    foreach ($values as $key => $value) {
        if (is_scalar($value)) $text = str_replace('{' . $key . '}', (string) $value, $text);
    }
    return $text;
}

function ui_language_url(string $language): string
{
    $page = basename($_SERVER['SCRIPT_NAME'] ?? 'login.php');
    if (!preg_match('/^[a-z_]+\.php$/D', $page)) $page = 'login.php';
    $query = [];
    foreach ($_GET as $key => $value) {
        if (is_string($key) && is_scalar($value) && $key !== 'export_csv') $query[$key] = (string) $value;
    }
    $query['lang'] = $language === 'ko' ? 'ko' : 'en';
    return $page . '?' . http_build_query($query);
}

function ui_language_switch(): string
{
    $html = '<nav class="language-switch" aria-label="' . e(ui_t('Display language')) . '">';
    foreach (['en' => 'English', 'ko' => '한국어'] as $language => $label) {
        $html .= '<a href="' . e(ui_language_url($language)) . '" lang="' . $language . '" hreflang="' . $language . '" data-language-switch';
        if (ui_language() === $language) $html .= ' aria-current="true"';
        $html .= '>' . $label . '</a>';
    }
    return $html . '</nav>';
}

function ui_client_messages(): array
{
    $keys = [
        'Open navigation', 'Close navigation', 'Show', 'Hide', 'Show password', 'Hide password',
        'Show {field}', 'Hide {field}', 'Working…', 'Your request is being processed.',
        'Scrollable data table', 'Scroll horizontally to view all columns.', 'Choose your leave type and dates to preview the number of calendar days.',
        'The end date must be on or after the start date.', 'Choose an end date on or after the start date.',
        'The requested dates exceed your available leave balance.',
        '{days} calendar {unit} requested. Your {type} balance is {balance} days; choose a shorter date range.',
        '{days} calendar {unit}, including both dates and weekends.',
        '{remaining} days would remain after approval.', 'day', 'days', 'annual', 'sick', 'personal',
        'Enter the same new password in both fields.', 'Complete this required field.', 'Choose an option.',
        'Check the format shown below this field.', 'Choose a date or number within the allowed range.',
        'You have unsaved entries. Change the display language and reload this page?',
    ];
    $result = ['locale' => ui_language()];
    foreach ($keys as $key) $result[$key] = ui_t($key);
    return $result;
}

ui_language();
if (!headers_sent()) header('Content-Language: ' . ui_language());
