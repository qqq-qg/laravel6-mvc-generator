<?php

namespace Nac\Mvc\Generators;

use Nac\Mvc\Compilers\TemplateCompiler;
use Nac\Mvc\Filesystem\Filesystem;

class Generator
{

  /**
   * @var Filesystem
   */
  protected $file;

  /**
   * @param Filesystem $file
   */
  public function __construct(Filesystem $file)
  {
    $this->file = $file;
  }

  public function make($templatePath, $templateData, $filePathToGenerate)
  {
    // We first need to compile the template,
    // according to the data that we provide.
    $template = $this->compile($templatePath, $templateData, new TemplateCompiler);

    $this->getGenFilePath(dirname($filePathToGenerate));
    // Now that we have the compiled template,
    // we can actually generate the file.
    $this->file->make($filePathToGenerate, $template);
  }

  public function compile($templatePath, array $data, TemplateCompiler $compiler)
  {
    return $compiler->compile($this->file->get($templatePath), $data);
  }

  protected function getGenFilePath($path, $level = 1)
  {
    if (is_dir($path)) {
      return true;
    }
    $sub = dirname($path);
    $rst = $this->getGenFilePath($sub, $level + 1);
    if ($rst === false) {
      mkdir($sub, 0777, true);
    }
    if ($level === 1) {
      mkdir($path, 0777, true);
    }
    return false;
  }
}
