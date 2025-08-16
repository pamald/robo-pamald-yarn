<?php

declare(strict_types = 1);

namespace Pamald\Robo\PamaldYarn;

use League\Container\ContainerAwareInterface;
use Robo\Collection\CollectionBuilder;

/**
 * @phpstan-import-type RoboPamaldYarnCollectDependenciesTaskOptions from \Pamald\Robo\PamaldYarn\Phpstan
 * @phpstan-import-type RoboPamaldYarnModifyCommitMsgPartsTaskOptions from \Pamald\Robo\PamaldYarn\Phpstan
 */
trait PamaldYarnTaskLoader
{
    /**
     * @phpstan-param RoboPamaldYarnCollectDependenciesTaskOptions $options
     *
     * @return \Pamald\Robo\PamaldYarn\Task\CollectYarnPackagesTask|\Robo\Collection\CollectionBuilder
     */
    protected function taskPamaldCollectYarnPackages(array $options = []): CollectionBuilder
    {
        /** @var \Pamald\Robo\PamaldYarn\Task\CollectYarnPackagesTask|\Robo\Collection\CollectionBuilder $task */
        $task = $this->task(Task\CollectYarnPackagesTask::class);
        $task->setOptions($options);

        return $task;
    }

    /**
     * @phpstan-param RoboPamaldYarnModifyCommitMsgPartsTaskOptions $options
     *
     * @return \Pamald\Robo\PamaldYarn\Task\ModifyCommitMsgPartsTask|\Robo\Collection\CollectionBuilder
     */
    protected function taskPamaldYarnModifyCommitMsgParts(array $options = []): CollectionBuilder
    {
        /** @var \Pamald\Robo\PamaldYarn\Task\ModifyCommitMsgPartsTask|\Robo\Collection\CollectionBuilder $task */
        $task = $this->task(Task\ModifyCommitMsgPartsTask::class);
        if ($this instanceof ContainerAwareInterface) {
            $task->setContainer($this->getContainer());
        }

        $task->setOptions($options);

        return $task;
    }
}
