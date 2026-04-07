<?php
function loadEnv($path)
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and blank lines
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        // Skip lines without '='
        if (strpos($line, '=') === false) {
            continue;
        }

        $parts = explode('=', $line, 2);
        $name  = trim($parts[0]);
        $value = isset($parts[1]) ? trim($parts[1]) : '';

        if ($name === '') continue;

        putenv("$name=$value");
        $_ENV[$name] = $value;
    }
}