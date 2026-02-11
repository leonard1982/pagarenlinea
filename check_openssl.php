<?php
header('Content-Type: text/plain; charset=utf-8');
echo "phpversion: " . phpversion() . PHP_EOL;
echo "extension_loaded('openssl'): " . (extension_loaded('openssl') ? 'YES' : 'NO') . PHP_EOL;
echo "functions: " . (function_exists('openssl_sign') ? 'openssl_sign OK' : 'MISSING') . PHP_EOL;
