<?php

declare(strict_types = 1);

namespace Pamald\Robo\PamaldYarn\Tests\Acceptance\Task;

class ReportTaskTest extends TaskTestBase
{
    public function testRoboTaskPamaldReport(): void
    {
        $actual = $this->runRoboCommand(['pamald:report']);
        $expected = [
            'exitCode' => 0,
            'out' => <<< 'Text'
                +---------------+-----------+-----------+----------------+----------------+---------+---------+
                | Name          | L Version | R Version | L Relationship | R Relationship | L Depth | R Depth |
                +---------------+-----------+-----------+----------------+----------------+---------+---------+
                | find-versions | 5.0.0     | 5.1.0     | dependencies   | dependencies   | direct  | direct  |
                | semver-regex  | 4.0.1     | 4.2.0     | ?              | ?              | child   | child   |
                +---------------+-----------+-----------+----------------+----------------+---------+---------+

                Text,
            'err' => implode(
                "\n",
                [
                    ' [pamald - Collect Yarn packages] Collect Yarn packages',
                    ' [pamald - Collect Yarn packages] Collect Yarn packages',
                    ' [Pamald\Robo\Pamald\Task\LockDifferTask] ',
                    ' [Pamald\Robo\Pamald\Task\ReporterTask] ',
                    '',
                ],
            ),
        ];

        static::assertSame($expected['exitCode'], $actual['exitCode']);
        static::assertSame($expected['out'], $actual['out']);
        static::assertSame($expected['err'], $actual['err']);
    }
}
