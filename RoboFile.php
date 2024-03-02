<?php

/**
 * @noinspection PhpComposerExtensionStubsInspection
 */

declare(strict_types = 1);

use Consolidation\AnnotatedCommand\Attributes as Cli;
use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\CommandResult;
use League\Container\Container as LeagueContainer;
use NuvoleWeb\Robo\Task\Config\Robo\loadTasks as ConfigLoader;
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
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

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
            : 'phpunit.xml.dist';
        $doc = new \DOMDocument();
        $doc->loadXML($this->fs->readFile($configFilePath));
        $xpath = new \DOMXPath($doc);

        $nodes = $xpath->query('/phpunit/testsuites/testsuite');
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
     * @hook pre-command @initLintReporters
     */
    public function initLintReporters(): void
    {
        $container = $this->getContainer();
        if (!($container instanceof LeagueContainer)) {
            return;
        }

        foreach (BaseReporter::getServices() as $name => $class) {
            if ($container->has($name)) {
                continue;
            }

            $container
                ->add($name, $class)
                ->setShared(false);
        }
    }

    /**
     * Exports the curren environment info.
     *
     * @command environment:info
     *
     * @param mixed[] $options
     *
     * @option string $format
     *   Default: yaml
     *
     * @hidden
     */
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
    #[Cli\Command(name: 'build')]
    public function cmdBuildExecute(): TaskInterface
    {
        $cb = $this->collectionBuilder();

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
            ->setNamespace('Pamald\Robo\Pamald');

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
            ->setNamespace('Pamald\Robo\Pamald\Tests');

        $cb->addTaskList([
            'phpstanGeneratePhp.prod' => $taskPhpstanGeneratePhpProd,
            'phpstanGeneratePhp.dev' => $taskPhpstanGeneratePhpDev,
        ]);

        return $cb;
    }
    // endregion

    /**
     * Git "pre-commit" hook callback.
     *
     * @command githook:pre-commit
     *
     * @hidden
     *
     * @initLintReporters
     */
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

    /**
     * @hook validate test
     */
    public function cmdTestValidate(CommandData $commandData): void
    {
        $input = $commandData->input();
        $suiteNames = $input->getArgument('suiteNames');
        if ($suiteNames) {
            $invalidSuiteNames = array_diff($suiteNames, $this->getTestSuiteNames());
            if ($invalidSuiteNames) {
                throw new InvalidArgumentException(
                    'The following PHPUnit suite names are invalid: ' . implode(', ', $invalidSuiteNames),
                    1,
                );
            }
        }
    }

    /**
     * Run tests.
     *
     * @param string[] $suiteNames
     *
     * @command test
     */
    public function cmdTestExecute(array $suiteNames): TaskInterface
    {
        return $this->getTaskTestRunSuites($suiteNames);
    }

    /**
     * Run static code analyzers.
     *
     * @command lint
     *
     * @initLintReporters
     */
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

    /**
     * Run phpcs.
     *
     * @command lint:phpcs
     *
     * @initLintReporters
     */
    public function cmdLintPhpcsExecute(): TaskInterface
    {
        return $this->getTaskPhpcsLint();
    }

    /**
     * Runs phpstan analyze.
     *
     * @command lint:phpstan
     *
     * @initLintReporters
     */
    public function cmdLintPhpstanExecute(): TaskInterface
    {
        return $this->getTaskPhpstanAnalyze();
    }

    /**
     * Runs circleci validate.
     *
     * @command lint:circleci-config
     */
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

        /** @phpstan-var array<string, php-executable> $phpExecutables */
        $phpExecutables = array_filter(
            (array) $this->getConfig()->get('php.executables'),
            fn(array $php): bool => !empty($php['enabled']),
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
     * @phpstan-param php-executable $php
     */
    protected function getTaskTestRunSuite(string $suite, array $php): TaskInterface
    {
        $cmdPattern = '';
        $cmdArgs = [];
        foreach ($php['envVars'] ?? [] as $envName => $envValue) {
            $cmdPattern .= "{$envName}";
            if ($envValue === null) {
                $cmdPattern .= ' ';
            } else {
                $cmdPattern .= '=%s ';
                $cmdArgs[] = escapeshellarg($envValue);
            }
        }

        $cmdPattern .= '%s';
        $cmdArgs[] = $php['command'];

        $cmdPattern .= ' %s';
        $cmdArgs[] = escapeshellcmd("{$this->binDir}/phpunit");

        $cmdPattern .= ' --colors=%s';
        $cmdArgs[] = escapeshellarg('always');

        if ($this->gitHook === 'pre-commit') {
            $cmdPattern .= ' --no-logging';
            $cmdPattern .= ' --no-coverage';
        }

        if ($suite !== 'all') {
            $cmdPattern .= ' --testsuite %s';
            $cmdArgs[] = escapeshellarg($suite);
        }

        $command = vsprintf($cmdPattern, $cmdArgs);

        return $this
            ->collectionBuilder()
            ->addCode(function () use ($command, $php) {
                $this->output()->writeln(strtr(
                    '<question>[{name}]</question> runs <info>{command}</info>',
                    [
                        '{name}' => 'PHPUnit',
                        '{command}' => $command,
                    ]
                ));

                $process = Process::fromShellCommandline(
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
