<?php

declare(strict_types=1);

namespace ChatFlow\Contracts;

interface FlowInterface
{
    public function register(FlowRuntimeInterface $runtime): void;
}
