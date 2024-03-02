<?php

declare(strict_types = 1);

namespace Pamald\Robo\PamaldYarn\Tests\Acceptance\Task;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class TaskTestBase extends TestCase
{

    /**
     * @param string[] $command
     *
     * @phpstan-return cli-execute-result
     */
    protected function runRoboCommand(array $command = []): array
    {
        $binDir = './vendor/bin';
        $roboFile = './tests/AcceptanceRoboFile.php';
        $finalCommand = array_merge(
            [
                "$binDir/robo",
                '--no-ansi',
                "--load-from=$roboFile",
            ],
            $command
        );
        $process = new Process($finalCommand);
        $result = [
            'exitCode' => 0,
            Process::OUT => '',
            Process::ERR => '',

        ];
        $callback = function (string $type, string $data) use (&$result): void {
            $result[$type] .= $data;
        };
        $result['exitCode'] = $process->run($callback);

        // @phpstan-ignore-next-line
        return $result;
    }
}
