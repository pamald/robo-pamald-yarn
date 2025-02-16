<?php

declare(strict_types = 1);

use Consolidation\AnnotatedCommand\Attributes\Argument;
use Consolidation\AnnotatedCommand\Attributes\Command;
use Consolidation\AnnotatedCommand\Attributes\Help;
use Consolidation\AnnotatedCommand\Attributes\Hook;
use Consolidation\AnnotatedCommand\Attributes\Option;
use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\AnnotatedCommand\Hooks\HookManager;
use League\Container\Container as LeagueContainer;
use NuvoleWeb\Robo\Task\Config\Robo\loadTasks as ConfigLoader;
use Pamald\Robo\PamaldYarn\Tests\Attributes\InitLintReporters;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Collection\CollectionBuilder;
use Robo\Common\ConfigAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Robo\Contract\TaskInterface;
use Robo\Tasks;
use Sweetchuck\LintReport\Reporter\BaseReporter;
use Sweetchuck\Robo\Git\GitTaskLoader;
use Sweetchuck\Robo\Phpcs\PhpcsTaskLoader;
use Sweetchuck\Robo\Phpstan\PhpstanTaskLoader;
use Sweetchuck\Utils\Filter\EnabledFilter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * @phpstan-import-type PhpExecutable from \Pamald\Robo\PamaldYarn\Tests\Phpstan
 */
class RoboFile extends Tasks implements LoggerAwareInterface, ConfigAwareInterface
{
    use LoggerAwareTrait;
    use ConfigAwareTrait;
    use ConfigLoader;
    use GitTaskLoader;
    use PhpcsTaskLoader;
    use PhpstanTaskLoader;

    /**
     * @var array<string, mixed>
     */
    protected array $composerInfo = [];

    protected Filesystem $fs;

    // region testSuiteNames
    /**
     * @var string[]
     */
    protected array $testSuiteNames = [];

    /**
     * @return string[]
     */
    protected function getTestSuiteNames(): array
    {
        if (!$this->testSuiteNames) {
            $this->initTestSuiteNames();
        }

        return $this->testSuiteNames;
    }

    protected function initTestSuiteNames(): static
    {
        $this->testSuiteNames = [];
        $configFilePath = $this->fs->exists('phpunit.xml')
            ? 'phpunit.xml'
            : 'phpunit.dist.xml';
        $doc = new \DOMDocument();
        $doc->loadXML($this->fs->readFile($configFilePath));
        $xpath = new \DOMXPath($doc);

        $nodes = $xpath->query('/phpunit/testsuites/testsuite[@name]');
        if (!$nodes) {
            return $this;
        }

        /** @var \DOMElement $node */
        foreach ($nodes as $node) {
            $this->testSuiteNames[] = $node->getAttribute('name');
        }

        return $this;
    }
    // endregion

    protected string $packageVendor = '';

    protected string $packageName = '';

    protected string $binDir = 'vendor/bin';

    protected string $gitHook = '';

    protected string $envVarNamePrefix = '';

    /**
     * Allowed values: local, dev, ci, prod.
     */
    protected string $environmentType = '';

    /**
     * Allowed values: local, jenkins, travis, circleci.
     */
    protected string $environmentName = '';

    public function __construct()
    {
        $this->fs = new Filesystem();
        $this
            ->initComposerInfo()
            ->initEnvVarNamePrefix()
            ->initEnvironmentTypeAndName();
    }

    #[Hook(
        type: HookManager::PRE_COMMAND_HOOK,
        selector: InitLintReporters::SELECTOR,
    )]
    public function onHookPreCommandInitLintReporters(): void
    {
        $lintServices = BaseReporter::getServices();
        $container = $this->getContainer();
        if (!($container instanceof LeagueContainer)) {
            return;
        }

        foreach ($lintServices as $name => $class) {
            if ($container->has($name)) {
                continue;
            }

            $container
                ->add($name, $class)
                ->setShared(false);
        }
    }

    protected function initComposerInfo(): static
    {
        if ($this->composerInfo) {
            return $this;
        }

        $composerFile = getenv('COMPOSER') ?: 'composer.json';
        $composerContent = file_get_contents($composerFile);
        if ($composerContent === false) {
            return $this;
        }

        $this->composerInfo = json_decode($composerContent, true);
        [$this->packageVendor, $this->packageName] = explode('/', $this->composerInfo['name']);

        if (!empty($this->composerInfo['config']['bin-dir'])) {
            $this->binDir = $this->composerInfo['config']['bin-dir'];
        }

        return $this;
    }

    protected function initEnvVarNamePrefix(): static
    {
        $this->envVarNamePrefix = strtoupper(str_replace('-', '_', $this->packageName));

        return $this;
    }

    protected function initEnvironmentTypeAndName(): static
    {
        $this->environmentType = (string) getenv($this->getEnvVarName('environment_type'));
        $this->environmentName = (string) getenv($this->getEnvVarName('environment_name'));

        if (!$this->environmentType) {
            if (getenv('CI') === 'true') {
                // CircleCI, Travis and GitLab.
                $this->environmentType = 'ci';
            } elseif (getenv('JENKINS_HOME')) {
                $this->environmentType = 'ci';
                if (!$this->environmentName) {
                    $this->environmentName = 'jenkins';
                }
            }
        }

        if (!$this->environmentName && $this->environmentType === 'ci') {
            if (getenv('GITLAB_CI') === 'true') {
                $this->environmentName = 'gitlab';
            } elseif (getenv('TRAVIS') === 'true') {
                $this->environmentName = 'travis';
            } elseif (getenv('CIRCLECI') === 'true') {
                $this->environmentName = 'circle';
            }
        }

        if (!$this->environmentType) {
            $this->environmentType = 'dev';
        }

        if (!$this->environmentName) {
            $this->environmentName = 'local';
        }

        return $this;
    }

    /**
     * @phpstan-param array<string, mixed> $options
     */
    #[Command(name: 'environment:info')]
    #[Help(
        description: 'Exports the curren environment info.',
        hidden: true,
    )]
    #[Option(
        name: 'format',
        description: 'Output format',
    )]
    public function cmdEnvironmentInfoExecute(
        array $options = [
            'format' => 'yaml',
        ],
    ): CommandResult {
        return CommandResult::dataWithExitCode(
            [
                'type' => $this->environmentType,
                'name' => $this->environmentName,
                'envVars' => [
                    $this->getEnvVarName('environment_type'),
                    $this->getEnvVarName('environment_name'),
                ],
            ],
            0,
        );
    }

    // region Command - build
    #[Command(name: 'build')]
    public function cmdBuildExecute(): TaskInterface
    {
        $cb = $this->collectionBuilder();

        $prodNamespace = array_search('src/', $this->composerInfo['autoload']['psr-4'] ?? []);
        $prodNamespace = trim((string) $prodNamespace, '\\');
        // @phpstan-ignore-next-line
        $taskPhpstanGeneratePhpProd = $this
            ->taskPhpstanGeneratePhp()
            ->setSrcFiles(
                (new Finder())
                    ->in('./.phpstan')
                    ->files()
                    ->name('parameters.typeAliases.prod.neon')
            )
            ->setDstFilePath('./src/Phpstan.php')
            ->setNamespace($prodNamespace);

        $devNamespace = array_search('tests/src/', $this->composerInfo['autoload-dev']['psr-4'] ?? []);
        $devNamespace = trim((string) $devNamespace, '\\');
        // @phpstan-ignore-next-line
        $taskPhpstanGeneratePhpDev = $this
            ->taskPhpstanGeneratePhp()
            ->setSrcFiles(
                (new Finder())
                    ->in('./.phpstan')
                    ->files()
                    ->name('parameters.typeAliases.dev.neon')
            )
            ->setDstFilePath('./tests/src/Phpstan.php')
            ->setNamespace($devNamespace);

        $cb->addTaskList([
            'phpstanGeneratePhp.prod' => $taskPhpstanGeneratePhpProd,
            'phpstanGeneratePhp.dev' => $taskPhpstanGeneratePhpDev,
        ]);

        return $cb;
    }
    // endregion

    #[Command(name: 'githook:pre-commit')]
    #[Help(
        description: 'Git "pre-commit" hook callback.',
        hidden: true,
    )]
    #[InitLintReporters]
    public function cmdGitHookPreCommitExecute(): TaskInterface
    {
        $this->gitHook = 'pre-commit';

        return $this
            ->collectionBuilder()
            ->addTaskList(array_filter([
                'composer.validate' => $this->taskComposerValidate(),
                'circleci.config.validate' => $this->getTaskCircleCiConfigValidate(),
                'phpcs.lint' => $this->getTaskPhpcsLint(),
                'phpstan.analyze' => $this->getTaskPhpstanAnalyze(),
                'test.run' => $this->getTaskTestRunSuites(),
            ]));
    }

    #[Hook(
        type: HookManager::ARGUMENT_VALIDATOR,
        target: 'test',
    )]
    public function cmdTestValidate(CommandData $commandData): void
    {
        $input = $commandData->input();
        $actualSuiteNames = $input->getArgument('suiteNames');
        if ($actualSuiteNames) {
            $validSuiteNames = $this->getTestSuiteNames();
            $invalidSuiteNames = array_diff($actualSuiteNames, $validSuiteNames);
            if ($invalidSuiteNames) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Invalid test suite names: %s; allowed values: %s',
                        implode(', ', $invalidSuiteNames),
                        implode(', ', $validSuiteNames),
                    ),
                    1,
                );
            }
        }
    }

    /**
     * @phpstan-param array<string> $suiteNames
     */
    #[Command(name: 'test')]
    #[Help(
        description: 'Runs tests.',
    )]
    #[Argument(
        name: 'suiteNames',
        description: 'Codeception suite names',
    )]
    public function cmdTestExecute(array $suiteNames): TaskInterface
    {
        return $this->getTaskTestRunSuites($suiteNames);
    }

    #[Command(name: 'lint')]
    #[Help(
        description: 'Runs code style checkers.',
    )]
    #[InitLintReporters]
    public function cmdLintExecute(): TaskInterface
    {
        return $this
            ->collectionBuilder()
            ->addTaskList(array_filter([
                'composer.validate' => $this->taskComposerValidate(),
                'circleci.config.validate' => $this->getTaskCircleCiConfigValidate(),
                'phpcs.lint' => $this->getTaskPhpcsLint(),
                'phpstan.analyze' => $this->getTaskPhpstanAnalyze(),
            ]));
    }

    #[Command(name: 'lint:phpcs')]
    #[Help(
        description: 'Runs phpcs.',
    )]
    #[InitLintReporters]
    public function cmdLintPhpcsExecute(): TaskInterface
    {
        return $this->getTaskPhpcsLint();
    }

    #[Command(name: 'lint:phpstan')]
    #[Help(
        description: 'Runs phpstan analyze.',
    )]
    #[InitLintReporters]
    public function cmdLintPhpstanExecute(): TaskInterface
    {
        return $this->getTaskPhpstanAnalyze();
    }

    #[Command(name: 'lint:circleci-config')]
    #[Help(
        description: 'Runs circleci validate.',
    )]
    public function cmdLintCircleciConfigExecute(): ?TaskInterface
    {
        return $this->getTaskCircleCiConfigValidate();
    }

    protected function getTaskCircleCiConfigValidate(): ?TaskInterface
    {
        if ($this->environmentType === 'ci') {
            return null;
        }

        if ($this->gitHook === 'pre-commit') {
            $cb = $this->collectionBuilder();
            $cb->addTask(
                $this
                    ->taskGitListStagedFiles()
                    ->setPaths(['./.circleci/config.yml' => true])
                    ->setDiffFilter(['d' => false])
                    ->setAssetNamePrefix('staged.')
            );

            $cb->addTask(
                $this
                    ->taskGitReadStagedFiles()
                    ->setCommandOnly(true)
                    ->setWorkingDirectory('.')
                    ->deferTaskConfiguration('setPaths', 'staged.fileNames')
            );

            $taskForEach = $this->taskForEach();
            $taskForEach
                ->iterationMessage('CircleCI config validate: {key}')
                ->deferTaskConfiguration('setIterable', 'files')
                ->withBuilder(function (
                    CollectionBuilder $builder,
                    string $key,
                    $file
                ) {
                    $builder->addTask(
                        $this->taskExec("{$file['command']} | circleci --skip-update-check config validate -"),
                    );
                });
            $cb->addTask($taskForEach);

            return $cb;
        }

        return $this->taskExec('circleci --skip-update-check config validate');
    }

    protected function errorOutput(): ?OutputInterface
    {
        $output = $this->output();

        return ($output instanceof ConsoleOutputInterface) ? $output->getErrorOutput() : $output;
    }

    protected function getEnvVarName(string $name): string
    {
        return "{$this->envVarNamePrefix}_" . strtoupper($name);
    }

    /**
     * @param string[] $suiteNames
     */
    protected function getTaskTestRunSuites(array $suiteNames = []): TaskInterface
    {
        if (!$suiteNames) {
            $suiteNames = ['all'];
        }

        /** @phpstan-var array<string, PhpExecutable> $phpExecutables */
        $phpExecutables = array_filter(
            $this->getConfig()->get('php.executables'),
            new EnabledFilter(),
        );

        $cb = $this->collectionBuilder();
        foreach ($suiteNames as $suiteName) {
            foreach ($phpExecutables as $phpExecutable) {
                $cb->addTask($this->getTaskTestRunSuite($suiteName, $phpExecutable));
            }
        }

        return $cb;
    }

    /**
     * @phpstan-param PhpExecutable $php
     */
    protected function getTaskTestRunSuite(string $suite, array $php): TaskInterface
    {
        $command = $php['command'];
        $command[] = "{$this->binDir}/phpunit";

        $cb = $this->collectionBuilder();
        if ($suite !== 'all') {
            $command[] = "--testsuite=$suite";
        }

        return $cb
            ->addCode(function () use ($command, $php) {
                $this->output()->writeln(strtr(
                    '<question>[{name}]</question> runs <info>{command}</info>',
                    [
                        '{name}' => 'Test',
                        '{command}' => implode(' ', $command),
                    ]
                ));

                $process = new Process(
                    $command,
                    null,
                    $php['envVars'] ?? null,
                    null,
                    null,
                );

                return $process->run(function ($type, $data) {
                    switch ($type) {
                        case Process::OUT:
                            $this->output()->write($data);
                            break;

                        case Process::ERR:
                            $this->errorOutput()->write($data);
                            break;
                    }
                });
            });
    }

    protected function getTaskPhpcsLint(): TaskInterface
    {
        $options = [
            'failOn' => 'warning',
            'lintReporters' => [
                'lintVerboseReporter' => null,
            ],
        ];

        if ($this->environmentType === 'ci' && $this->environmentName === 'jenkins') {
            $options['failOn'] = 'never';
            $options['lintReporters']['lintCheckstyleReporter'] = $this
                ->getContainer()
                ->get('lintCheckstyleReporter')
                ->setDestination('reports/machine/checkstyle/phpcs.psr2.xml');
        }

        if ($this->gitHook === 'pre-commit') {
            return $this
                ->collectionBuilder()
                ->addTask($this
                    ->taskPhpcsParseXml()
                    ->setAssetNamePrefix('phpcsXml.'))
                ->addTask($this
                    ->taskGitListStagedFiles()
                    ->setPaths(['*.php' => true])
                    ->setDiffFilter(['d' => false])
                    ->setAssetNamePrefix('staged.'))
                ->addTask($this
                    ->taskGitReadStagedFiles()
                    ->setCommandOnly(true)
                    ->setWorkingDirectory('.')
                    ->deferTaskConfiguration('setPaths', 'staged.fileNames'))
                ->addTask($this
                    ->taskPhpcsLintInput($options)
                    ->deferTaskConfiguration('setFiles', 'files')
                    ->deferTaskConfiguration('setIgnore', 'phpcsXml.exclude-patterns'));
        }

        return $this->taskPhpcsLintFiles($options);
    }

    protected function getTaskPhpstanAnalyze(): TaskInterface
    {
        /** @var \Sweetchuck\LintReport\Reporter\VerboseReporter $verboseReporter */
        $verboseReporter = $this->getContainer()->get('lintVerboseReporter');
        $verboseReporter->setFilePathStyle('relative');

        return $this
            ->taskPhpstanAnalyze()
            ->setNoProgress(true)
            ->setNoInteraction(true)
            ->setErrorFormat('json')
            ->addLintReporter('lintVerboseReporter', $verboseReporter);
    }
}
