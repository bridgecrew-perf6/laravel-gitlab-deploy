<?php

declare(strict_types=1);

namespace HexideDigital\GitlabDeploy;

use HexideDigital\GitlabDeploy\Console\PrepareDeployCommand;
use Illuminate\Support\ServiceProvider;

class GitlabDeployServiceProvider extends ServiceProvider
{
    protected array $commands = [
        'deploy-gitlab' => PrepareDeployCommand::class,
    ];

    public function register()
    {
        foreach ($this->commands as $alias => $command) {
            $this->app->singleton($alias, $command);
        }
    }

    public function boot()
    {
        $this->publishes([
            $this->packagePath('examples/deploy.php.stub') => base_path('deploy.php'),
            $this->packagePath('examples/dep-log.example.txt') => base_path('deploy/dep-log.example.txt'),
            $this->packagePath('examples/deploy-prepare.example.yml') => base_path('deploy/deploy-prepare.example.yml'),
            $this->packagePath('examples/.gitignore.stub') => base_path('deploy/.gitignore'),
            $this->packagePath('examples/rsync.php.stub') => base_path('deploy/recipe/rsync.php'),
        ], 'gitlab-deploy-examples');

        $this->publishes([
            $this->packagePath('config/gitlab-deploy.php') => config_path('gitlab-deploy.php'),
        ], 'gitlab-deploy-config');

        $this->mergeConfigFrom($this->packagePath('config/gitlab-deploy.php'), 'gitlab-deploy');

        if ($this->app->runningInConsole()) {
            $this->commands(array_keys($this->commands));
        }
    }

    /**
     * Get the absolute path to some package resource.
     *
     * @param string $path The relative path to the resource
     *
     * @return string
     */
    protected function packagePath(string $path): string
    {
        return __DIR__ . '/../' . $path;
    }
}
