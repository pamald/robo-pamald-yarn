<?php

declare(strict_types = 1);

namespace Pamald\Robo\PamaldYarn\Tests\Helper;

use JsonSerializable;
use Pamald\Pamald\PackageInterface;
use Sweetchuck\Utils\VersionNumber;

class DummyPackage implements PackageInterface, JsonSerializable
{
    protected ?VersionNumber $version;

    /**
     * @phpstan-param dummy-package-values $values
     */
    public function __construct(protected array $values)
    {
        $this->version = isset($this->values['versionString']) ?
            VersionNumber::createFromString($this->values['versionString'])
            : null;
    }

    /**
     * {@inheritdoc}
     */
    public function jsonSerialize(): mixed
    {
        return array_filter(
            $this->values,
            fn (mixed $value): bool => $value !== null,
        );
    }

    public function name(): string
    {
        return $this->values['name'];
    }

    public function type(): ?string
    {
        return $this->values['type'] ?? null;
    }

    public function versionString(): ?string
    {
        return $this->values['versionString'] ?? null;
    }

    public function version(): ?VersionNumber
    {
        return $this->version ?? null;
    }

    public function typeOfRelationship(): ?string
    {
        return $this->values['typeOfRelationship'] ?? null;
    }

    public function isDirectDependency(): ?bool
    {
        return $this->values['isDirectDependency'] ?? null;
    }

    public function homepage(): ?string
    {
        return $this->values['homepage'] ?? null;
    }

    /**
     * @return null|array<string, mixed>
     */
    public function vcsInfo(): ?array
    {
        return $this->values['vcsInfo'] ?? null;
    }

    /**
     * @return null|array<string, mixed>
     */
    public function issueTracker(): ?array
    {
        return $this->values['issueTracker'] ?? null;
    }
}
