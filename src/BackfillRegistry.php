<?php

namespace Kstmostofa\Backfill;

use Illuminate\Support\Collection;
use Kstmostofa\Backfill\Exceptions\BackfillNotFound;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

class BackfillRegistry
{
    /** @var array<int, class-string<Backfill>> */
    protected array $registered = [];

    /** @var Collection<int, Backfill>|null */
    protected ?Collection $cache = null;

    /**
     * Register a backfill class explicitly — useful for tests and for classes
     * living outside the configured path.
     *
     * @param  class-string<Backfill>  $class
     */
    public function register(string $class): static
    {
        if (! in_array($class, $this->registered, true)) {
            $this->registered[] = $class;
            $this->cache = null;
        }

        return $this;
    }

    public function flush(): static
    {
        $this->registered = [];
        $this->cache = null;

        return $this;
    }

    /**
     * Every discoverable backfill, keyed by nothing in particular.
     *
     * @return Collection<int, Backfill>
     */
    public function all(): Collection
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $classes = array_unique(array_merge($this->registered, $this->discoverClasses()));

        return $this->cache = collect($classes)
            ->map(fn (string $class) => new $class)
            ->sortBy(fn (Backfill $backfill) => $backfill->name())
            ->values();
    }

    /**
     * Resolve by CLI name (user-slugs), class basename, or FQCN.
     */
    public function find(string $name): Backfill
    {
        $needle = ltrim($name, '\\');

        $match = $this->all()->first(function (Backfill $backfill) use ($needle) {
            return $backfill->name() === $needle
                || $backfill::class === $needle
                || class_basename($backfill) === $needle;
        });

        if ($match) {
            return $match;
        }

        if (class_exists($needle) && is_subclass_of($needle, Backfill::class)) {
            return new $needle;
        }

        throw BackfillNotFound::named($name, $this->all()->map->name()->all());
    }

    public function has(string $name): bool
    {
        try {
            $this->find($name);

            return true;
        } catch (BackfillNotFound) {
            return false;
        }
    }

    /**
     * @return array<int, class-string<Backfill>>
     */
    protected function discoverClasses(): array
    {
        $path = config('backfill.path');

        if (! $path || ! is_dir($path)) {
            return [];
        }

        $classes = [];

        foreach (Finder::create()->files()->name('*.php')->in($path) as $file) {
            $class = $this->classFromFile($file);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Backfill::class)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * Read the namespace and class name straight out of the file, so discovery
     * does not depend on the file living under the app namespace.
     *
     * @return class-string|null
     */
    protected function classFromFile(SplFileInfo $file): ?string
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            return null;
        }

        $tokens = token_get_all($contents);
        $namespace = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->readNamespace($tokens, $i, $count);

                continue;
            }

            if ($token[0] === T_CLASS && $this->isClassDeclaration($tokens, $i)) {
                $name = $this->readClassName($tokens, $i, $count);

                if ($name !== null) {
                    return $namespace === '' ? $name : $namespace.'\\'.$name;
                }
            }
        }

        return null;
    }

    protected function readNamespace(array $tokens, int $i, int $count): string
    {
        $namespace = '';

        for ($j = $i + 1; $j < $count; $j++) {
            if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                break;
            }

            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $namespace = $tokens[$j][1];

                break;
            }
        }

        return $namespace;
    }

    protected function readClassName(array $tokens, int $i, int $count): ?string
    {
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                return $tokens[$j][1];
            }

            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            // `new class(...)` and similar — no name to read.
            return null;
        }

        return null;
    }

    /**
     * Distinguish a real declaration from `Foo::class` and `new class {}`.
     */
    protected function isClassDeclaration(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $tokens[$j];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_DOUBLE_COLON, T_NEW], true)) {
                return false;
            }

            return true;
        }

        return true;
    }
}
