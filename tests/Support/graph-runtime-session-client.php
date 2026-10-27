<?php

declare(strict_types=1);

// Only this synthetic client joins the disposable session. Runtime children
// use their normal entrypoint, without inheriting this test-only bootstrap.
if (posix_setsid() !== getmypid()) {
    throw new RuntimeException('Cannot isolate the test client process group.');
}
$sessionPath = $argv[1];
$entrypoint = $argv[2];
file_put_contents($sessionPath, json_encode(['pid' => getmypid(), 'pgid' => posix_getpgrp(), 'sid' => posix_getsid(0)], JSON_THROW_ON_ERROR));
$argv = [$entrypoint, ...array_slice($argv, 3)];
require $entrypoint;
