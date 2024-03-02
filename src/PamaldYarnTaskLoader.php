<?php

declare(strict_types = 1);

namespace Pamald\Robo\PamaldYarn;

use League\Container\ContainerAwareInterface;
use Robo\Collection\CollectionBuilder;

trait PamaldYarnTaskLoader
{
    /**
     * @phpstan-param robo-pamald-yarn-collect-packages-task-options $options
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
     * @phpstan-param robo-pamald-modify-commit-msg-parts-task-options $options
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
