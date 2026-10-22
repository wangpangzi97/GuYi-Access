<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/app/Infrastructure/Database/Database.php';
new Database(true);
echo "Schema migration completed. Rotate legacy application secrets in the administrator UI.\n";
