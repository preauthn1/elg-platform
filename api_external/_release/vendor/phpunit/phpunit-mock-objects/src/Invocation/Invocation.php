<?php
/*
 * This file is part of the phpunit-mock-objects package.
 *
 * (c) Sebastian Bergmann <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Framework\MockObject;

/**
 * Interface for invocations.
 */
interface Invocation
{
    /**
     * @return mixed Mocked return value.
     */
    public function generateReturnValue();

    public function getClassName(): string;

    public function getMethodName(): string;

    public function getParameters(): array;

    public function getReturnType(): string;

    public function isReturnTypeNullable(): bool;
}
