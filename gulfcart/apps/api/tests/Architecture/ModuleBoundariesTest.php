<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Enforces docs/TECHNICAL_SPEC.md §3:
 * - a module may use another module only through its Contracts, Domain value objects or Events;
 * - App\Shared must not depend on any module.
 */
final class ModuleBoundariesTest extends TestCase
{
    private const ALLOWED_FOREIGN_LAYERS = ['Contracts', 'Domain', 'Events'];

    public function test_modules_only_use_public_layers_of_other_modules(): void
    {
        $violations = [];

        foreach ($this->phpFiles(__DIR__.'/../../app/Modules') as $file) {
            $own = $this->moduleOf($file);
            foreach ($this->moduleReferences((string) file_get_contents($file)) as [$module, $layer]) {
                if ($module !== $own && ! in_array($layer, self::ALLOWED_FOREIGN_LAYERS, true)) {
                    $violations[] = basename($file)." ({$own}) uses {$module}\\{$layer}";
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_shared_does_not_depend_on_modules(): void
    {
        $violations = [];
        foreach ($this->phpFiles(__DIR__.'/../../app/Shared') as $file) {
            if ($this->moduleReferences((string) file_get_contents($file)) !== []) {
                $violations[] = basename($file);
            }
        }

        $this->assertSame([], $violations);
    }

    /** @return list<array{0: string, 1: string}> */
    private function moduleReferences(string $source): array
    {
        preg_match_all('/App\\\\Modules\\\\(\w+)\\\\(\w+)/', $source, $m, PREG_SET_ORDER);

        return array_map(static fn (array $x) => [$x[1], $x[2]], $m);
    }

    private function moduleOf(string $file): string
    {
        preg_match('#/Modules/(\w+)/#', str_replace('\\', '/', $file), $m);

        return $m[1] ?? '';
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }

        return $files;
    }
}
