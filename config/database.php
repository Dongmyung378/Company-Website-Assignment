<?php

return [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'username' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'database' => getenv('DB_NAME') ?: 'company_db',
    'port' => (int) (getenv('DB_PORT') ?: 3307),
];
