<?php

declare(strict_types=1);

http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
echo "Database reset is disabled from the web.\n";
echo "If you really need a reset, run on the server CLI:\n";
echo "  php tools/install/install_reset.php --confirm-wipe-all-data\n";
