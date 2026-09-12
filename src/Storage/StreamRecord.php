<?php

declare(strict_types=1);

namespace ChatFlow\Storage;

/**
 * One entry of a stream: its position, the data as it was appended and the deduplication id
 * it was appended with, if any.
 */
final class StreamRecord
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly int $seq,
        public readonly array $data,
        public readonly ?string $id = null,
    ) {}
}
