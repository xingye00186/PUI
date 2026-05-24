<?php
// Use environment variable SWOOLE_COMPILER_ROOT set by main_build.bat
$swooleRoot = getenv('SWOOLE_COMPILER_ROOT');
if ($swooleRoot) {
    return require $swooleRoot . '\vendor\autoload.php';
}
echo "[ERROR] SWOOLE_COMPILER_ROOT not set";
exit(1);
