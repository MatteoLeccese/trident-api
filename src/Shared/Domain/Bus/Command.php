<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Bus;

/**
 * Marks a write DTO. Immutable: all of its properties are `public readonly`.
 */
interface Command {}
