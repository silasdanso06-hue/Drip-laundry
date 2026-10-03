<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
echo "This SQL setup tool is retired. The site now uses private/data/store.php.\n";
echo "Existing data is kept intact. See OWNER-GUIDE.md and tools/migrate-to-php.php.\n";
