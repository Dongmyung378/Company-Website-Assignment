<?php
declare(strict_types=1);

// Use the restricted runtime configuration; no setup credentials or fixture writes.
require_once dirname(__DIR__) . '/config/bootstrap.php';
$checks = [];
$record = static function (string $name, bool $passed) use (&$checks): void {
    $checks[] = ['name' => $name, 'passed' => $passed];
};

$originalTimezone = date_default_timezone_get();
foreach (['UTC', 'America/New_York', 'Asia/Seoul'] as $timezone) {
    date_default_timezone_set($timezone);
    $record('F14 calendar spring DST dates remain inclusive in ' . $timezone, calendar_days('2026-03-07', '2026-03-09') === 3);
    $record('F14 calendar fall DST dates remain inclusive in ' . $timezone, calendar_days('2026-10-31', '2026-11-02') === 3);
    $record('F14 leap-day range remains inclusive in ' . $timezone, calendar_days('2024-02-28', '2024-03-01') === 3);
}
date_default_timezone_set($originalTimezone);
$record('F14 strict date validation rejects impossible and noncanonical dates',
    valid_date('2026-02-29') === null && valid_date('2026-02-30') === null
    && valid_date('2026-1-01') === null && valid_date('2026-01-01 trailing') === null
    && valid_date('0999-12-31') === null && valid_date('2024-02-29') !== null);
$record('F14 inclusive calendar handles year boundaries and single days',
    calendar_days('2026-12-31', '2027-01-01') === 2
    && calendar_days('2026-01-01', '2026-01-01') === 1);
$reversedRejected = false;
try {
    calendar_days('2026-05-02', '2026-05-01');
} catch (InvalidArgumentException) {
    $reversedRejected = true;
}
$record('F14 reversed calendar date range is rejected', $reversedRejected);

$formulaSafe = true;
foreach (['=1+1', '+1+1', '@SUM(1,1)', '-1+1', '  =1+1', "\t+1+1", "\r\n@SUM(1,1)", "\x01-1+1"] as $value) {
    $formulaSafe = $formulaSafe && csv_safe_text($value) === "'" . $value;
}
$record('F08 formula prefixes and leading whitespace or controls are neutralized', $formulaSafe);
$normalPreserved = true;
foreach (['Employee Name', '12345', '서울', 'Backslash \\ example', 'name@example.invalid', ''] as $value) {
    $normalPreserved = $normalPreserved && csv_safe_text($value) === $value;
}
$record('F08 ordinary CSV text stays unchanged', $normalPreserved);
$values = ['backslash \\', 'quoted "text"', "line one\nline two", 'comma,value', '서울 · café', ''];
$stream = fopen('php://temp', 'w+');
fputcsv($stream, $values, ',', '"', '');
rewind($stream);
$roundtrip = fgetcsv($stream, 0, ',', '"', '');
fclose($stream);
$record('F16 CSV explicit empty escape round-trips quotes, backslashes, newlines and UTF-8', $roundtrip === $values);

$record('F05 password policy rejects short and overlong or NUL-containing inputs',
    password_validation_error('abc') !== ''
    && password_validation_error(str_repeat('A', 1025)) !== ''
    && password_validation_error("a sufficiently long\0passphrase") !== '');
$record('F05 valid long passphrase is accepted without trimming significant spaces',
    password_validation_error(' A sufficiently long passphrase ') === '');

echo json_encode(['checks' => $checks], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
