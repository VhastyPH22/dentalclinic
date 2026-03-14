<?php
/**
 * Environment Variables Loader
 * Loads variables from .env file - MUST BE CALLED FIRST!
 */

function loadEnv($filePath = null) {
    if ($filePath === null) {
        $filePath = dirname(dirname(__FILE__)) . '/.env';
    }
    
    if (file_exists($filePath)) {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                $value = trim($value, '\'"');
                $_SERVER[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

function getEnvVar($var) {
    if (isset($_SERVER[$var])) return $_SERVER[$var];
    if (isset($_ENV[$var])) return $_ENV[$var];
    $value = getenv($var);
    return ($value !== false) ? $value : '';
}

// Load immediately
loadEnv();
?>
