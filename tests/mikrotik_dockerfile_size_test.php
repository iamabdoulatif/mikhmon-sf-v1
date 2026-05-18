<?php

$dockerfile = file_get_contents(__DIR__ . '/../Dockerfile.mikrotik');

$requiredRemovals = [
    '/usr/local/bin/php-cgi',
    '/usr/local/bin/docker-php-ext-configure',
    '/usr/local/bin/docker-php-ext-enable',
    '/usr/local/bin/docker-php-ext-install',
    '/usr/local/bin/docker-php-source',
    '/usr/local/bin/php-config',
    '/usr/local/bin/phpize',
    '/src/src/css/font-awesome/less',
    '/src/src/css/font-awesome/scss',
];

foreach ($requiredRemovals as $path) {
    if (strpos($dockerfile, $path) === false) {
        fwrite(STDERR, "Dockerfile.mikrotik does not remove unused runtime path: {$path}\n");
        exit(1);
    }
}

if (strpos($dockerfile, 'ENTRYPOINT ["php"]') === false) {
    fwrite(STDERR, "Dockerfile.mikrotik must keep php as the container entrypoint.\n");
    exit(1);
}

echo "MikroTik Dockerfile size guard OK\n";
