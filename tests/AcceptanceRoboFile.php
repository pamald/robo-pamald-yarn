<?php

declare(strict_types = 1);

use Pamald\Pamald\Reporter\ConsoleTableReporter;
use Pamald\PamaldYarn\PackageCollector;
use Pamald\Robo\Pamald\PamaldTaskLoader;
use Pamald\Robo\PamaldYarn\PamaldYarnTaskLoader;
use Robo\Tasks;
use Robo\Contract\TaskInterface;
use Robo\State\Data as RoboState;
use Siketyan\YarnLock\YarnLock;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Filesystem\Path;

class AcceptanceRoboFile extends Tasks
{
    use PamaldTaskLoader;
    use PamaldYarnTaskLoader;

    protected function output()
    {
        return $this->getContainer()->get('output');
    }

    /**
     * @command pamald:report
     */
    public function cmdPamaldReportExecute(): TaskInterface
    {
        $cb = $this->collectionBuilder();
        $cb
            ->addCode(function (RoboState $state): int {
                $projectDir = $this->fixturesDir('project-01');
                $state['01.json'] = json_decode(
                    file_get_contents("$projectDir/01.json") ?: '{}',
                    true,
                );
                $state['01.lock'] = YarnLock::toArray(
                    file_get_contents("$projectDir/01.yarnlock") ?: '',
                );
                $state['02.json'] = json_decode(
                    file_get_contents("$projectDir/02.json") ?: '{}',
                    true,
                );
                $state['02.lock'] = YarnLock::toArray(
                    file_get_contents("$projectDir/02.yarnlock") ?: '',
                );

                $state['collector'] = new PackageCollector();

                $reporter = new ConsoleTableReporter();
                $reporter->setTable(new Table($this->output()));
                $state['reporter'] = $reporter;

                return 0;
            })
            ->addTask(
                $this
                    ->taskPamaldCollectYarnPackages()
                    ->setAssetNamePrefix('left.')
                    ->deferTaskConfiguration('setCollector', 'collector')
                    ->deferTaskConfiguration('setLock', '01.lock')
                    ->deferTaskConfiguration('setJson', '01.json')
            )
            ->addTask(
                $this
                    ->taskPamaldCollectYarnPackages()
                    ->setAssetNamePrefix('right.')
                    ->deferTaskConfiguration('setCollector', 'collector')
                    ->deferTaskConfiguration('setLock', '02.lock')
                    ->deferTaskConfiguration('setJson', '02.json')
            )
            ->addTask(
                $this
                    ->taskPamaldLockDiffer()
                    ->deferTaskConfiguration('setLeftPackages', 'left.pamald.yarnPackages')
                    ->deferTaskConfiguration('setRightPackages', 'right.pamald.yarnPackages')
            )
            ->addTask(
                $this
                    ->taskPamaldReporter()
                    ->deferTaskConfiguration('setLockDiffEntries', 'pamald.lockDiffEntries')
                    ->deferTaskConfiguration('setReporter', 'reporter')
            );

        return $cb;
    }

    protected function selfRoot(): string
    {
        return dirname(__DIR__);
    }

    protected function fixturesDir(string ...$parts): string
    {
        return Path::join(
            $this->selfRoot(),
            'tests',
            'fixtures',
            ...$parts,
        );
    }
}
