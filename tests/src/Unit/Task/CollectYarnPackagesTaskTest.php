<?php

declare(strict_types = 1);

namespace Pamald\Robo\PamaldYarn\Tests\Unit\Task;

use Pamald\Robo\PamaldYarn\Task\CollectYarnPackagesTask;
use Pamald\Robo\PamaldYarn\Task\TaskBase;
use Pamald\Robo\PamaldYarn\Tests\Helper\DummyTaskBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(CollectYarnPackagesTask::class)]
#[CoversClass(TaskBase::class)]
class CollectYarnPackagesTaskTest extends TaskTestBase
{
    /**
     * @return resource
     */
    protected static function createStream()
    {
        $filePath = 'php://memory';
        $resource = fopen($filePath, 'rw');
        if ($resource === false) {
            throw new \RuntimeException("file $filePath could not be opened");
        }

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public static function casesRunSuccess(): array
    {
        return [
            'basic' => [
                'expected' => [
                    'exitCode' => 0,
                    'exitMessage' => '',
                    'assets' => [
                        'pamald.yarnPackages' => [
                            'a' => [],
                            'b' => [],
                        ],
                    ],
                ],
                'options' => [
                    'lock' => [
                        'a@^1.0' => [
                             'version' => '1.2.3',
                        ],
                        'b@^2.0' => [
                             'version' => '2.3.4',
                        ],
                    ],
                    'json' => [
                        'dependencies' => [
                            'a' => '^1.0',
                        ],
                        'devDependencies' => [
                            'b' => '^2.0',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @phpstan-param array<string, mixed> $expected
     * @phpstan-param robo-pamald-yarn-collect-packages-task-options $options
     */
    #[DataProvider('casesRunSuccess')]
    public function testRunSuccess(array $expected, array $options): void
    {
        $taskBuilder = new DummyTaskBuilder();
        $taskBuilder->setContainer($this->getNewContainer());

        $task = $taskBuilder->taskPamaldCollectYarnPackages($options);
        $result = $task->run();

        static::assertSame($expected['exitCode'], $result->getExitCode());
        static::assertSame($expected['exitMessage'], $result->getMessage());
        static::assertSame(
            array_keys($expected['assets']['pamald.yarnPackages']),
            array_keys($result['pamald.yarnPackages']),
        );
    }
}
