<?php

namespace Kstmostofa\Backfill\Commands;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:backfill')]
class MakeBackfillCommand extends GeneratorCommand
{
    protected $name = 'make:backfill';

    protected $description = 'Create a new backfill class';

    protected $type = 'Backfill';

    protected function getStub(): string
    {
        return __DIR__.'/stubs/backfill.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        $path = $this->backfillPath();
        $appPath = app_path();

        if (str_starts_with($path, $appPath)) {
            $relative = trim(substr($path, strlen($appPath)), '/\\');

            return $relative === ''
                ? $rootNamespace
                : $rootNamespace.'\\'.str_replace('/', '\\', $relative);
        }

        return $rootNamespace.'\\Backfills';
    }

    protected function getPath($name): string
    {
        $namespace = $this->getDefaultNamespace(trim($this->rootNamespace(), '\\')).'\\';
        $relative = str_replace('\\', '/', str_replace($namespace, '', $name));

        return rtrim($this->backfillPath(), '/\\').'/'.$relative.'.php';
    }

    protected function backfillPath(): string
    {
        return config('backfill.path') ?: app_path('Backfills');
    }
}
