<?php

declare(strict_types=1);

namespace ChatFlow\Validation;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Exception\ValidationException;
use ChatFlow\Validation\Rules\CallbackValidator;
use ChatFlow\Validation\Rules\EmailValidator;
use ChatFlow\Validation\Rules\NumericValidator;
use ChatFlow\Validation\Rules\RegexValidator;
use ChatFlow\Validation\Rules\RequiredValidator;

class ValidationRegistry
{
    /** @var array<string, class-string<ValidatorInterface>|ValidatorInterface> */
    private array $validators = [];

    public function __construct(private readonly ?ContainerInterface $container = null)
    {
        $this->registerDefaults();
    }

    private function registerDefaults(): void
    {
        $this->register('numeric', NumericValidator::class);
        $this->register('integer', NumericValidator::class);
        $this->register('required', RequiredValidator::class);
        $this->register('regex', RegexValidator::class);
        $this->register('email', EmailValidator::class);
        $this->register('callback', CallbackValidator::class);
    }

    /**
     * @param class-string<ValidatorInterface>|ValidatorInterface $validator
     */
    public function register(string $alias, string|object $validator): void
    {
        $this->validators[$alias] = $validator;
    }

    /**
     * Get validator by alias.
     *
     * @param string $alias Validator alias
     *
     * @return ValidatorInterface Validator instance
     *
     * @throws ValidationException If validator not found
     * @throws \ChatFlow\Exception\ContainerException
     */
    public function get(string $alias): ValidatorInterface
    {
        if (!isset($this->validators[$alias])) {
            throw new ValidationException("Validator '{$alias}' not found");
        }

        $validator = $this->validators[$alias];

        if (\is_object($validator)) {
            return $validator;
        }

        if ($this->container !== null && $this->container->has($validator)) {
            /** @var ValidatorInterface $instance */
            $instance = $this->container->get($validator);

            return $instance;
        }

        return new $validator();
    }

    public function has(string $alias): bool
    {
        return isset($this->validators[$alias]);
    }
}
