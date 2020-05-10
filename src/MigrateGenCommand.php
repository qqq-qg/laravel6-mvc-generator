<?php

namespace Nac\Mvc;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Support\Str;
use Nac\Mvc\Compilers\TemplateCompiler;
use Nac\Mvc\Filesystem\Filesystem;
use Nac\Mvc\Generators\Generator;
use Nac\Mvc\Generators\SchemaGenerator;
use Nac\Mvc\Syntax\AddForeignKeysToTable;
use Nac\Mvc\Syntax\AddToTable;
use Nac\Mvc\Syntax\DroppedTable;
use Nac\Mvc\Syntax\RemoveForeignKeysFromTable;
use Symfony\Component\Console\Input\InputOption;

class MigrateGenCommand extends Command
{
  protected $signature = 'make:mg {--connection=} {--tables=} {--ignore=}';

  protected $description = 'Generate a migration from an existing table structure.';

  protected $options = [];

  /**
   * @var SchemaGenerator $schemaGenerator
   */
  protected $generator;
  protected $file;
  protected $compiler;

  protected $schemaGenerator;

  protected $log = false;

  /**
   * @var string
   */
  protected $connection;

  /**
   * @var string[] $tables
   */
  protected $tables;

  /**
   * @var MigrationRepositoryInterface $repository
   */
  protected $repository;

  /**
   * @var \Illuminate\Config\Repository $config
   */
  protected $config;

  /**
   * @var int
   */
  protected $batch;

  /**
   * Filename date prefix (Y_m_d_His)
   * @var string
   */
  protected $datePrefix;

  /**
   * @var string
   */
  protected $migrationName;

  /**
   * @var string
   */
  protected $method;

  /**
   * @var string
   */
  protected $table;

  /**
   * Array of Fields to create in a new Migration
   * Namely: Columns, Indexes and Foreign Keys
   * @var array
   */
  protected $fields = array();

  /**
   * List of Migrations that has been done
   * @var array
   */
  protected $migrations = array();

  public function __construct(
    Generator $generator,
    Filesystem $file,
    TemplateCompiler $compiler,
    MigrationRepositoryInterface $repository,
    Config $config
  )
  {
    $this->generator = $generator;
    $this->file = $file;
    $this->compiler = $compiler;
    $this->repository = $repository;
    $this->config = $config;
    parent::__construct();
  }

  public function handle()
  {
    $this->fire();
  }

  public function fire()
  {
    $this->options = $options = $this->options();
    $this->connection = $options['connection'] ?? config('database.default');
    $this->info('Using connection: ' . $this->connection . "\n");
    $this->schemaGenerator = new SchemaGenerator($this->connection, $options['defaultIndexNames'] ?? '', $options['defaultFKNames'] ?? '');
    if (!empty($options['tables'])) {
      $tables = explode(',', $options['tables']);
    } else {
      $tables = $this->schemaGenerator->getTables();
    }
    $tables = $this->removeExcludedTables($tables);
    $this->info('Generating migrations for: ' . implode(', ', $tables));
    if (!empty($options['no-interaction'])) {
      $this->log = $this->askYn('Do you want to log these migrations in the migrations table?');
    }
    if ($this->log) {
      $this->repository->setSource($this->connection);
      if (!$this->repository->repositoryExists()) {
        $this->call('migrate:install', ['--database' => $this->connection]);
      }
      $batch = $this->repository->getNextBatchNumber();
      $this->batch = $this->askNumeric('Next Batch Number is: ' . $batch . '. We recommend using Batch Number 0 so that it becomes the "first" migration', 0);
    }
    $this->info("Setting up Tables and Index Migrations");
    $this->datePrefix = date('Y_m_d_His');
    $this->generateTablesAndIndices($tables);
    $this->info("\nSetting up Foreign Key Migrations\n");
    $this->datePrefix = date('Y_m_d_His', strtotime('+1 second'));
    $this->generateForeignKeys($tables);
    $this->info("\nFinished!\n");
  }


  /**
   * Generate tables and index migrations.
   *
   * @param array $tables List of tables to create migrations for
   * @return void
   */
  protected function generateTablesAndIndices(array $tables)
  {
    $this->method = 'create';
    foreach ($tables as $table) {
      $this->table = $table;
      $this->migrationName = 'create_' . $this->table . '_table';
      $this->fields = $this->schemaGenerator->getFields($this->table);
      $this->generate();
    }
  }

  /**
   * Generate foreign key migrations.
   *
   * @param array $tables List of tables to create migrations for
   * @return void
   */
  protected function generateForeignKeys(array $tables)
  {
    $this->method = 'table';

    foreach ($tables as $table) {
      $this->table = $table;
      $this->migrationName = 'add_foreign_keys_to_' . $this->table . '_table';
      $this->fields = $this->schemaGenerator->getForeignKeyConstraints($this->table);

      $this->generate();
    }
  }

  /**
   * Generate Migration for the current table.
   *
   * @return void
   */
  protected function generate()
  {
    if ($this->fields) {
      $this->fireFile();
      if ($this->log) {
        $file = $this->datePrefix . '_' . $this->migrationName;
        $this->repository->log($file, $this->batch);
      }
    }
  }

  public function fireFile()
  {
    $filePathToGenerate = $this->getFileGenerationPath();
    try {
      $this->generator->make($this->getTemplatePath(), $this->getTemplateData(), $filePathToGenerate);
      $this->info("Created: {$filePathToGenerate}");
    } catch (\Exception $e) {
      $this->error("The file, {$filePathToGenerate}, already exists! I don't want to overwrite it.");
    }
  }

  /**
   * The path where the file will be created
   *
   * @return string
   */
  protected function getFileGenerationPath()
  {
    $path = base_path() . '/database/migrations';
    $migrationName = str_replace('/', '_', $this->migrationName);
    $fileName = $this->getDatePrefix() . '_' . $migrationName . '.php';
    return "{$path}/{$fileName}";
  }

  /**
   * Get the date prefix for the migration.
   *
   * @return string
   */
  protected function getDatePrefix()
  {
    return $this->datePrefix;
  }

  /**
   * Fetch the template data
   *
   * @return array
   */
  protected function getTemplateData()
  {
    if ($this->method == 'create') {
      $up = (new AddToTable($this->file, $this->compiler))->run($this->fields, $this->table, $this->connection, 'create');
      $down = (new DroppedTable)->drop($this->table, $this->connection);
    }

    if ($this->method == 'table') {
      $up = (new AddForeignKeysToTable($this->file, $this->compiler))->run($this->fields, $this->table, $this->connection);
      $down = (new RemoveForeignKeysFromTable($this->file, $this->compiler))->run($this->fields, $this->table, $this->connection);
    }

    return [
      'CLASS' => ucwords(Str::camel($this->migrationName)),
      'UP' => $up,
      'DOWN' => $down
    ];
  }

  /**
   * Get path to template for generator
   *
   * @return string
   */
  protected function getTemplatePath()
  {
    return __DIR__ . '/templates/migration.txt';
  }

  protected function askYn($question)
  {
    $answer = $this->ask($question . ' [Y/n] ');
    while (!in_array(strtolower($answer), ['y', 'n', 'yes', 'no'])) {
      $answer = $this->ask('Please choose either yes or no. ');
    }
    return in_array(strtolower($answer), ['y', 'yes']);
  }

  protected function askNumeric($question, $default = null)
  {
    $ask = 'Your answer needs to be a numeric value';
    if (!is_null($default)) {
      $question .= ' [Default: ' . $default . '] ';
      $ask .= ' or blank for default';
    }
    $answer = $this->ask($question);
    while (!is_numeric($answer) and !($answer == '' and !is_null($default))) {
      $answer = $this->ask($ask . '. ');
    }
    if ($answer == '') {
      $answer = $default;
    }
    return $answer;
  }

  protected function getOptions()
  {
    return [
      ['connection', 'c', InputOption::VALUE_OPTIONAL, 'The database connection to use.', config('database.default')],
      ['tables', 't', InputOption::VALUE_OPTIONAL, 'A list of Tables you wish to Generate Migrations for separated by a comma: users,posts,comments'],
      ['ignore', 'i', InputOption::VALUE_OPTIONAL, 'A list of Tables you wish to ignore, separated by a comma: users,posts,comments'],
      ['path', 'p', InputOption::VALUE_OPTIONAL, 'Where should the file be created?'],
      ['templatePath', 'tp', InputOption::VALUE_OPTIONAL, 'The location of the template for this generator'],
      ['defaultIndexNames', null, InputOption::VALUE_NONE, 'Don\'t use db index names for migrations'],
      ['defaultFKNames', null, InputOption::VALUE_NONE, 'Don\'t use db foreign key names for migrations'],
    ];
  }

  protected function removeExcludedTables(array $tables)
  {
    $excludes = $this->getExcludedTables();
    $tables = array_diff($tables, $excludes);
    return $tables;
  }

  protected function getExcludedTables()
  {
    $excludes = ['migrations'];
    if (!empty($this->options['ignore'])) {
      return array_merge($excludes, explode(',', $this->options['ignore']));
    }
    return $excludes;
  }
}
