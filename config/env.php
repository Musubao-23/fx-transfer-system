<?php

// Reads a setting loaded from the .env file, whichever way PHP exposes it.
if (!function_exists('env')) {
    function env($key, $default = null)
    {
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }
}