<?php
declare(strict_types=1);

/*
 * Local development placeholder. Replace credentials for your environment.
 * This file is git-ignored. Do not commit production credentials.
 */
return [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '3306',
    'name' => getenv('DB_NAME') ?: 'gaston_power',
    'user' => getenv('DB_USER') ?: 'root',
    'pass' => getenv('DB_PASS') ?: '',
    'app_key' => getenv('APP_KEY') ?: 'CHANGE-THIS-TO-A-LONG-RANDOM-SECRET-KEY',
];
