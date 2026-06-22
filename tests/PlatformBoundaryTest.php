<?php

declare(strict_types=1);

namespace ChatFlow\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PlatformBoundaryTest extends TestCase
{
    public function test_core_does_not_import_platform_specific_libraries(): void
    {
        $forbidden = [
            'Telegram\\Bot\\',
            'webnarmin\\AmphpWS\\',
        ];

        foreach ($this->sourceFiles() as $file) {
            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $contents,
                    sprintf('Core file %s must not reference %s.', $file->getPathname(), $needle),
                );
            }
        }
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function sourceFiles(): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../src')
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }
}
