<?php

declare(strict_types=1);

namespace Doctrine\Migrations\Finder;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Finder\Exception\InvalidDirectory;
use Doctrine\Migrations\Finder\Exception\NameIsReserved;
use ReflectionClass;

use function assert;
use function get_declared_classes;
use function in_array;
use function is_a;
use function is_dir;
use function ksort;
use function realpath;
use function strlen;
use function strncmp;
use function substr;

use const SORT_STRING;

/**
 * The Finder class is responsible for for finding migrations on disk at a given path.
 */
abstract class Finder implements MigrationFinder
{
    protected static function requireOnce(string $path): void
    {
        require_once $path;
    }

    /**
     * @throws InvalidDirectory
     */
    protected function getRealPath(string $directory): string
    {
        $dir = realpath($directory);

        if ($dir === false || ! is_dir($dir)) {
            throw InvalidDirectory::new($directory);
        }

        return $dir;
    }

    /**
     * @param string[] $files
     *
     * @throws NameIsReserved
     *
     * @psalm-return array<string, class-string<AbstractMigration>>
     */
    protected function loadMigrations(array $files, ?string $namespace): array
    {
        $includedFiles = [];
        foreach ($files as $file) {
            static::requireOnce($file);

            $realFile = realpath($file);
            assert($realFile !== false);

            $includedFiles[] = $realFile;
        }

        $classes  = $this->loadMigrationClasses($includedFiles, $namespace);
        $versions = [];
        foreach ($classes as $class) {
            $version = substr($class->getShortName(), 7);

            if ($version === '0') {
                throw NameIsReserved::new($version);
            }

            $versions[$version] = $class->getName();
        }

        ksort($versions, SORT_STRING);

        return $versions;
    }

    /**
     * Look up all declared classes and find those classes contained
     * in the given `$files` array.
     *
     * @param string[]    $files     The set of files that were `required`
     * @param string|null $namespace If not null only classes in this namespace will be returned
     *
     * @psalm-return list<ReflectionClass<AbstractMigration>> the classes in `$files`
     */
    protected function loadMigrationClasses(array $files, ?string $namespace): array
    {
        $classes = [];
        foreach (get_declared_classes() as $class) {
            $reflectionClass = new ReflectionClass($class);

            if (! in_array($reflectionClass->getFileName(), $files, true)) {
                continue;
            }

            /** @psalm-var ReflectionClass<AbstractMigration> $reflectionClass */

            if ($namespace !== null && ! $this->isReflectionClassInNamespace($reflectionClass, $namespace)) {
                continue;
            }

            assert(is_a($class, AbstractMigration::class, true));
            $classes[] = $reflectionClass;
        }

        return $classes;
    }

    /**
     * @psalm-param ReflectionClass<AbstractMigration> $reflectionClass
     */
    private function isReflectionClassInNamespace(ReflectionClass $reflectionClass, string $namespace): bool
    {
        return strncmp($reflectionClass->getName(), $namespace . '\\', strlen($namespace) + 1) === 0;
    }
}
