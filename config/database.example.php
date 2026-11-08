<?php
declare(strict_types=1);

/*
 * Copy this file to database.php and set your local values.
 * Never commit database.php or real credentials.
 */
return [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '3306',
    'name' => getenv('DB_NAME') ?: 'gaston_power',
    'user' => getenv('DB_USER') ?: 'root',
    'pass' => getenv('DB_PASS') ?: '',
    // Replace with a long random value (at least 32 characters) before deployment.
    'app_key' => getenv('APP_KEY') ?: 'CHANGE-THIS-TO-A-LONG-RANDOM-SECRET-KEY',
];
