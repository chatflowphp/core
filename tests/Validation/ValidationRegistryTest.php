<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Validation;

use ChatFlow\Container\Container;
use ChatFlow\Exception\ValidationException;
use ChatFlow\Validation\Rules\CallbackValidator;
use ChatFlow\Validation\ValidationRegistry;
use ChatFlow\Validation\ValidatorInterface;
use PHPUnit\Framework\TestCase;

final class ValidationRegistryTest extends TestCase
{
    public function testDefaultRules(): void
    {
        $registry = new ValidationRegistry(new Container());

        self::assertTrue($registry->get('required')->validate('x'));
        self::assertFalse($registry->get('required')->validate('  '));
        self::assertFalse($registry->get('required')->validate(null));
        self::assertTrue($registry->get('numeric')->validate('12'));
        self::assertFalse($registry->get('integer')->validate('twelve'));
        self::assertTrue($registry->get('email')->validate('a@b.co'));
        self::assertFalse($registry->get('email')->validate('nope'));
        self::assertTrue($registry->get('regex')->validate('+79991234567', ['pattern' => '/^\+7\d{10}$/']));
        self::assertFalse($registry->get('regex')->validate('123', ['pattern' => '/^\+7\d{10}$/']));
        self::assertTrue($registry->get('callback')->validate('x', ['fn' => static fn(mixed $v): bool => $v === 'x']));
    }

    public function testRegexRequiresAPattern(): void
    {
        $this->expectException(ValidationException::class);

        (new ValidationRegistry())->get('regex')->validate('x');
    }

    public function testCustomValidatorsCanBeInstancesOrClasses(): void
    {
        $registry = new ValidationRegistry(new Container());
        $registry->register('even', new class implements ValidatorInterface {
            public function validate(mixed $value, array $parameters = []): bool
            {
                return is_numeric($value) && ((int) $value) % 2 === 0;
            }
        });
        $registry->register('cb', CallbackValidator::class);

        self::assertTrue($registry->has('even'));
        self::assertTrue($registry->get('even')->validate('4'));
        self::assertFalse($registry->get('even')->validate('5'));
        self::assertInstanceOf(CallbackValidator::class, $registry->get('cb'));
    }

    public function testUnknownAliasesThrow(): void
    {
        $this->expectException(ValidationException::class);

        (new ValidationRegistry())->get('missing');
    }
}
