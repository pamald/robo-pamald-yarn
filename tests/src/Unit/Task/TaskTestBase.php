<?php

declare(strict_types = 1);

namespace Pamald\Robo\PamaldYarn\Tests\Unit\Task;

use League\Container\Container as LeagueContainer;
use League\Container\DefinitionContainerInterface;
use Pamald\Robo\PamaldYarn\Tests\Helper\DummyOutput;
use Pamald\Robo\PamaldYarn\Tests\Helper\DummyTaskBuilder;
use Pamald\Robo\PamaldYarn\Tests\Unit\TestBase;
use Psr\Container\ContainerInterface;
use Robo\Collection\CollectionBuilder;
use Robo\Config\Config as RoboConfig;
use Robo\Robo;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\ErrorHandler\BufferingLogger;

class TaskTestBase extends TestBase
{

    protected ContainerInterface $container;

    protected RoboConfig $config;

    protected CollectionBuilder $builder;

    protected DummyTaskBuilder $taskBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new LeagueContainer();
        $application = new SymfonyApplication('pamald-composer', '1.0.0');
        $this->config = (new RoboConfig());
        $input = null;
        $output = new DummyOutput(OutputInterface::VERBOSITY_DEBUG);

        $this->container->add('container', $this->container);

        Robo::configureContainer($this->container, $application, $this->config, $input, $output);
        $this->container->addShared('logger', BufferingLogger::class);

        /** @var \Robo\Tasks $null */
        $null = null;
        $this->builder = CollectionBuilder::create($this->container, $null);
        $this->taskBuilder = new DummyTaskBuilder();
        $this->taskBuilder->setContainer($this->container);
        $this->taskBuilder->setBuilder($this->builder);
    }

    protected function getNewContainer(): DefinitionContainerInterface
    {
        $output = new DummyOutput(
            OutputInterface::VERBOSITY_DEBUG,
            false,
        );

        /** @var \League\Container\DefinitionContainerInterface $container */
        $container = Robo::createContainer();
        $container->add('output', $output);

        return $container;
    }
}
