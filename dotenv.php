<?php
declare(strict_types=1);

if (!function_exists('loadEnv')) {
    /**
     * Charge un fichier .env simple dans $_ENV et $_SERVER.
     * Format attendu :
     * KEY=value
     * Les lignes vides et les commentaires commençant par # sont ignorés.
     */
    function loadEnv(string $path): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);

            $name = trim($name);
            $value = trim($value);

            if ($name === '') {
                continue;
            }

            /*
            | Enlève les guillemets simples ou doubles autour des valeurs
            */
            if (
                strlen($value) >= 2 &&
                (
                    ($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
                    ($value[0] === "'" && $value[strlen($value) - 1] === "'")
                )
            ) {
                $value = substr($value, 1, -1);
            }

            /*
            | On ne remplace pas une variable déjà définie
            */
            if (array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER)) {
                continue;
            }

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;

            /*
            | putenv reste optionnel, mais pratique pour compatibilité
            */
            putenv($name . '=' . $value);
        }

        return true;
    }
}

if (!defined('ENV_LOADED')) {
    define('ENV_LOADED', true);
    loadEnv(__DIR__ . '/.env');
}