<?php

declare(strict_types = 1);

namespace Pamald\Robo\PamaldYarn\Tests\Helper;

use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\ConsoleOutput;

class DummyOutput extends ConsoleOutput
{
    protected static int $instanceCounter = 0;

    public string $output = '';

    public int $instanceId = 0;

    /**
     * {@inheritdoc}
     */
    public function __construct(
        int $verbosity = self::VERBOSITY_NORMAL,
        ?bool $decorated = null,
        ?OutputFormatterInterface $formatter = null,
        bool $isError = false,
    ) {
        parent::__construct($verbosity, $decorated, $formatter);
        $this->instanceId = static::$instanceCounter++;

        if ($isError === false) {
            // @phpstan-ignore-next-line
            $this->setErrorOutput(new static($verbosity, $decorated, $formatter, true));
        } else {
            $this->setErrorOutput($this);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function doWrite(string $message, bool $newline): void
    {
        $this->output .= $message . ($newline ? "\n" : '');
    }
}
