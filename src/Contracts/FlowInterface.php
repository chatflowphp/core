<?php

declare(strict_types=1);

namespace ChatFlow\Contracts;

/**
 * A reusable bundle of routes, scenes, transitions, middleware and error policy.
 */
interface FlowInterface
{
    public function register(FlowRuntimeInterface $runtime): void;
}
