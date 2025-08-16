<?php

declare(strict_types = 1);

namespace Pamald\Robo\PamaldYarn\Task;

use Pamald\Pamald\DependencyCollectorInterface;
use Pamald\PamaldYarn\DependencyCollector;
use Pamald\Robo\Pamald\Task\ModifyCommitMsgPartsTaskBase;
use Siketyan\YarnLock\YarnLock;

class ModifyCommitMsgPartsTask extends ModifyCommitMsgPartsTaskBase
{
    protected string $taskName = 'pamald - Yarn - Modify commit message parts';

    protected string $packageManagerName = 'yarn';

    /**
     * {@inheritdoc}
     */
    protected array $patterns = [
        // Standard name in the project root or in any sub-directory.
        'yarn.lock',
        '**/yarn.lock',
    ];

    protected function getJsonFilePath(string $lockFilePath): string
    {
        return preg_replace('@yarn\.lock$@', 'package.json', $lockFilePath);
    }

    protected function unserialize(string $type, string $fileContent): array
    {
        if ($type === 'lock') {
            return YarnLock::toArray($fileContent);
        }

        return json_decode($fileContent, true);
    }

    protected function getDependencyCollector(): DependencyCollectorInterface
    {
        return new DependencyCollector();
    }

    protected function isDomesticated(string $lockFilePath): bool
    {
        return pathinfo($lockFilePath, \PATHINFO_BASENAME) === 'yarn.lock';
    }
}
