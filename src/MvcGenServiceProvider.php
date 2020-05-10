<?php

namespace Nac\Mvc;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class MvcGenServiceProvider extends ServiceProvider implements DeferrableProvider
{
  /**
   * Register services.
   *
   * @return void
   */
  public function register()
  {
    $this->mergeConfigFrom(__DIR__ . '/Config/mvc.php', 'mvc');

    $this->app->singleton(MvcGenCommand::class,
      function (\Illuminate\Contracts\Foundation\Application $app) {
        return new MvcGenCommand(
          $app->make('Nac\Mvc\Generators\Generator'),
          $app->make('Nac\Mvc\Filesystem\Filesystem'),
          $app->make('Nac\Mvc\Compilers\TemplateCompiler'),
          $app->make('config')
        );
      }
    );

    $this->app->singleton(MigrateGenCommand::class,
      function (\Illuminate\Contracts\Foundation\Application $app) {
        return new MigrateGenCommand(
          $app->make('Nac\Mvc\Generators\Generator'),
          $app->make('Nac\Mvc\Filesystem\Filesystem'),
          $app->make('Nac\Mvc\Compilers\TemplateCompiler'),
          $app->make('migration.repository'),
          $app->make('config')
        );
      }
    );
    $this->commands([MvcGenCommand::class, MigrateGenCommand::class]);
  }

  /**
   * Bootstrap services.
   *
   * @return void
   */
  public function boot()
  {
    $this->publishes([
      __DIR__ . '/Config/mvc.php' => config_path('mvc.php'),
    ]);
  }

  public function provides()
  {
    return [MvcGenCommand::class, MigrateGenCommand::class];
  }
}
