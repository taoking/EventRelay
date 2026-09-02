<?php

declare(strict_types=1);

use Runtime\RunIdentity;
use Runtime\RuntimeException;
use Runtime\RuntimeHarness;

require dirname(__DIR__).'/vendor/autoload.php';

$usage = static function (): void {
    fwrite(STDERR, "Usage: composer runtime -- list|run <scenario>|suite|cleanup-current\n");
};

try {
    $command = $argv[1] ?? 'list';
    $harness = new RuntimeHarness(dirname(__DIR__), RunIdentity::fromEnvironment());

    if ($command === 'list' && count($argv) === 2) {
        foreach ($harness->scenarioNames() as $scenario) {
            fwrite(STDOUT, $scenario.PHP_EOL);
        }
        exit(0);
    }
    if ($command === 'run' && count($argv) === 3) {
        $harness->run($argv[2]);
        exit(0);
    }
    if ($command === 'suite' && count($argv) === 2) {
        $harness->runSuite();
        exit(0);
    }
    if ($command === 'cleanup-current' && count($argv) === 2) {
        $harness->cleanupCurrent();
        exit(0);
    }

    $usage();
    exit(1);
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'Runtime harness failure: '.$exception->getMessage().PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Runtime harness unexpected failure: '.$exception::class.PHP_EOL);
    exit(1);
}
