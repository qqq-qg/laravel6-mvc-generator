<?php

namespace Nac\Mvc\Syntax;

use Nac\Mvc\Compilers\TemplateCompiler;
use Nac\Mvc\Filesystem\Filesystem;

abstract class AbsTable
{

  /**
   * @var Filesystem
   */
  protected $file;

  /**
   * @var TemplateCompiler
   */
  protected $compiler;

  /**
   * @param Filesystem $file
   * @param TemplateCompiler $compiler
   */
  function __construct(Filesystem $file, TemplateCompiler $compiler)
  {
    $this->compiler = $compiler;
    $this->file = $file;
  }

  /**
   * Fetch the template of the schema
   *
   * @return string
   */
  protected function getTemplate()
  {
    return $this->file->get(__DIR__ . '/../templates/schema.txt');
  }


  /**
   * Replace $FIELDS$ in the given template
   * with the provided schema
   *
   * @param $schema
   * @param $template
   * @return mixed
   */
  protected function replaceFieldsWith($schema, $template)
  {
    return str_replace('$FIELDS$', implode(PHP_EOL . "            ", $schema), $template);
  }
}
