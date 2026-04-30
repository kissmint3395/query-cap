<?php

declare(strict_types=1);

namespace QueryCap;

final readonly class Duration
{
    private function __construct(
        private int $milliseconds,
    ) {}

    public static function milliseconds(int $ms): self
    {
        return new self($ms);
    }

    public static function seconds(int $seconds): self
    {
        return new self($seconds * 1000);
    }

    public static function minutes(int $minutes): self
    {
        return new self($minutes * 60 * 1000);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function toMilliseconds(): int
    {
        return $this->milliseconds;
    }

    public function toSeconds(): float
    {
        return $this->milliseconds / 1000;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->milliseconds > $other->milliseconds;
    }

    public function add(self $other): self
    {
        return new self($this->milliseconds + $other->milliseconds);
    }
}
