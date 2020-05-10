<?php

namespace Nac\Mvc;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nac\Mvc\Compilers\TemplateCompiler;
use Nac\Mvc\Filesystem\Filesystem;
use Nac\Mvc\Generators\Generator;

class MvcGenCommand extends Command
{
    protected $signature = 'make:mvc {className} {--table=} {--module=}';

    protected $description = 'Generate mvc class';

    protected $className;

    protected $options = [];

    protected $table;

    protected $module;

    /**
     * @var Generator $generator
     */
    protected $generator;
    /**
     * @var Filesystem $file
     */
    protected $file;
    /**
     * @var TemplateCompiler $compiler
     */
    protected $compiler;
    /**
     * @var Config $config
     */
    protected $config;

    protected $columns = [];

    protected $result = [];

    public function __construct(
        Generator $generator,
        Filesystem $file,
        TemplateCompiler $compiler,
        Config $config
    )
    {
        $this->generator = $generator;
        $this->file = $file;
        $this->compiler = $compiler;
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
        $this->className = $this->argument('className');
        $this->table = $options['table'] ?? $this->uncamelize($this->className);
        $this->module = $options['module'] ?? '';

        if ($this->existTable()) {
            $this->createModel();
        }

        $this->createRepository();

        $this->createController();
    }

    protected function createModel()
    {
        $className = ucfirst($this->className) . config('mvc.model_suffix', 'Model');
        $modelRoot = config('mvc.model_root_path', 'Models');
        $namespace = 'App\\' . $modelRoot;
        $filePath = app_path() . '\\' . $modelRoot;
        if (!empty($this->module)) {
            $namespace = $namespace . '\\' . $this->module;
            $filePath = $filePath . '\\' . $this->module;
        }
        $path = $filePath . "\\" . $className . ".php";
        list($extendNamespace, $extendName) = $this->parseClassNamespace(config('mvc.model_extend_class', 'Illuminate\\Database\\Eloquent\\Model'));
        $tempData = [
            'NAMESPACE' => $namespace,
            'NAME' => $className,
            'TABLE' => $this->table,
            'PROPERTY' => $this->getFieldVar(),
            'EXTEND_NAMESPACE' => $extendNamespace ? 'use ' . $extendNamespace . ';' : '',
            'EXTEND_NAME' => $extendName ? 'extends ' . $extendName : '',
        ];
        try {
            $this->generator->make($this->getTemplatePath('model'), $tempData, $path);
            $this->info("Created: {$namespace}\\{$className}");
            $this->result['MODEL_NAMESPACE'] = $namespace;
            $this->result['MODEL_NAME'] = $className;
        } catch (\Exception $e) {
            $this->error("Exception: " . $e->getMessage());
        }
    }

    protected function createRepository()
    {
        $className = ucfirst($this->className) . config('mvc.repository_suffix', 'Repository');
        $unitRoot = $this->options['Repository'] ?? config('mvc.repository_root_path', 'Repositories');
        $namespace = 'App\\' . $unitRoot;
        $filePath = app_path() . '\\' . $unitRoot;
        if (!empty($this->module)) {
            $namespace = $namespace . '\\' . $this->module;
            $filePath = $filePath . '\\' . $this->module;
        }
        $path = $filePath . "\\" . $className . ".php";
        list($extendNamespace, $extendName) = $this->parseClassNamespace(config('mvc.repository_extend_class', ''));
        $tempData = array_merge($this->result, [
            'NAMESPACE' => $namespace,
            'NAME' => $className,
            'EXTEND_NAMESPACE' => $extendNamespace ? 'use ' . $extendNamespace . ';' : '',
            'EXTEND_NAME' => $extendName ? 'extends ' . $extendName : '',
        ]);
        try {
            $this->generator->make($this->getTemplatePath('repository'), $tempData, $path);
            $this->info("Created: {$namespace}\\{$className}");
            $this->result['REPOSITORY_NAMESPACE'] = $namespace;
            $this->result['REPOSITORY_NAME'] = $className;
        } catch (\Exception $e) {
            $this->error("Exception: " . $e->getMessage());
        }
    }

    protected function createController()
    {
        $className = ucfirst($this->className);
        $controllerName = $className . 'Controller';
        $namespace = 'App\\Http\\Controllers';
        $filePath = app_path() . '\\Http\\Controllers';
        if (!empty($this->module)) {
            $namespace = $namespace . '\\' . $this->module;
            $filePath = $filePath . '\\' . $this->module;
        }
        $path = $filePath . "\\" . $controllerName . ".php";
        list($extendNamespace, $extendName) = $this->parseClassNamespace(config('mvc.controller_extend_class', 'Illuminate\\Routing\\Controller'));
        $tempData = array_merge($this->result, [
            'NAMESPACE' => $namespace,
            'NAME' => $controllerName,
            'COLLECTION' => strtolower($this->className),
            'VIEW' => $this->parseView(),
            'EXTEND_NAMESPACE' => $extendNamespace ? 'use ' . $extendNamespace . ';' : '',
            'EXTEND_NAME' => $extendName ? 'extends ' . $extendName : '',
        ]);
        try {
            $this->generator->make($this->getTemplatePath('controller'), $tempData, $path);
            $this->info("Created: {$namespace}\\{$className}");
        } catch (\Exception $e) {
            $this->error("Exception: " . $e->getMessage());
        }
    }

    protected function parseView()
    {
        $arr = [];
        if (!empty($this->module)) {
            if (strpos($this->module, '\\') !== false) {
                $arr = array_filter(preg_split('/\\\\/', $this->module));
            } else if (strpos($this->module, '/') !== false) {
                $arr = array_filter(preg_split('/\//', $this->module));
            } else {
                $arr[] = $this->module;
            }
        }
        $arr[] = $this->className;
        $arr = array_map(function ($val) {
            return $this->uncamelize($val, '-');
        }, $arr);
        return join('.', $arr);
    }

    protected function uncamelize($camelCaps, $separator = '_')
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', "$1" . $separator . "$2", $camelCaps));
    }

    protected function existTable()
    {
        return Schema::hasTable($this->table);
    }

    /**
     * Get path to template for generator
     *
     * @return string
     */
    protected function getTemplatePath($type)
    {
        return __DIR__ . "/templates/{$type}.txt";
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

    protected function getFieldVar()
    {
        $fields = DB::select("SHOW FULL COLUMNS FROM {$this->table}");
        $columns = [];
        $property = [];
        foreach ($fields as $row) {
            $type = $row->Type;
            $type_text = 'string';
            if (strpos($type, 'tinyint') !== false
                || strpos($type, 'smallint') !== false
                || strpos($type, 'mediumint') !== false
                || strpos($type, 'int') !== false
                || strpos($type, 'bigint') !== false) {
                $type_text = 'integer';
            }
            if (strpos($type, 'float') !== false
                || strpos($type, 'double') !== false
                || strpos($type, 'real') !== false
                || strpos($type, 'decimal') !== false) {
                $type_text = 'numeric';
            }
            if (strpos($type, 'datetime')) {
                $type_text = 'date';
            }
            $property[] = rtrim(" * @property {$type_text} {$row->Field} {$row->Comment}");
            $columns [] = $row->Field;
        }
        $this->columns = $columns;
        return join(PHP_EOL, $property);
    }

    private function parseClassNamespace($classFullName)
    {
        if (empty($classFullName)) {
            return ['', ''];
        }
        $name = '';
        if (strpos($classFullName, '\\') !== false) {
            $tmp = explode('\\', $classFullName);
            $name = $tmp[count($tmp) - 1];
        }
        return [$classFullName, $name];
    }
}
