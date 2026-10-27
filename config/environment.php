<?php
declare(strict_types=1);

/** Read the optional project-root .env as data, never as executable PHP. */
function company_load_environment(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    $path = dirname(__DIR__) . '/.env';
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read the application environment file.');
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/\A(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*)\z/', $line, $match)) {
            throw new RuntimeException('Invalid application environment file syntax.');
        }
        $value = trim($match[2]);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            if (strlen($value) < 2 || substr($value, -1) !== $value[0]) {
                throw new RuntimeException('Invalid quoted environment value.');
            }
            $value = substr($value, 1, -1);
        } else {
            $value = rtrim(preg_replace('/\s+#.*\z/', '', $value) ?? $value);
        }
        // Explicit process configuration always takes precedence over .env.
        if (getenv($match[1]) === false) {
            putenv($match[1] . '=' . $value);
            $_ENV[$match[1]] = $value;
        }
    }
}

function company_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

/** Resolve one private absolute destination; stream wrappers and public paths are rejected. */
function company_private_log_path(): ?string
{
    $path = company_env('APP_ERROR_LOG', dirname(__DIR__) . '/var/php-error.log');
    if ($path === null || $path === '' || !preg_match('~\A(?:[A-Za-z]:[/\\\\]|/|\\\\\\\\)~', $path)) {
        return null;
    }
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true)) {
        return null;
    }
    $directory = realpath($directory);
    $publicRoot = realpath(dirname(__DIR__) . '/public');
    if ($directory === false || $publicRoot === false) {
        return null;
    }
    $normalizedDirectory = strtolower(str_replace('\\', '/', $directory));
    $normalizedPublic = strtolower(str_replace('\\', '/', $publicRoot));
    if ($normalizedDirectory === $normalizedPublic || str_starts_with($normalizedDirectory . '/', $normalizedPublic . '/')) {
        return null;
    }
    $resolved = $directory . DIRECTORY_SEPARATOR . basename($path);
    if (is_link($resolved)) {
        return null;
    }
    return $resolved;
}

function company_configure_private_errors(): void
{
    $path = company_private_log_path();
    if ($path !== null) {
        $handle = @fopen($path, 'ab');
        if ($handle !== false) {
            fclose($handle);
            @chmod($path, 0600);
            ini_set('error_log', $path);
        }
    }
}

/** Never send connection details, SQL, submitted values or traces to a browser. */
function company_private_log(Throwable|string $error): void
{
    $message = $error instanceof Throwable
        ? get_class($error) . ': ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine()
        : $error;
    $message = str_replace(["\r", "\n"], ' ', $message);
    $path = company_private_log_path();
    if ($path !== null && @error_log('[' . gmdate('c') . '] ' . $message . PHP_EOL, 3, $path)) {
        @chmod($path, 0600);
        return;
    }
    error_log($message);
}
