<?php
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
exit('Web installation disabled. Use scripts/install_cli.php from the command line.');
