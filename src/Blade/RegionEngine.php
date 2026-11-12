<?php

namespace Native\Asgard\Blade;

use Illuminate\View\Engines\CompilerEngine;
use Native\Asgard\ServerComponent;
use Throwable;

/**
 * Evaluates compiled region views with `$this` bound to the component being
 * rendered, so `{{ $this->name }}` and `$name` both work, as in the app.
 */
class RegionEngine extends CompilerEngine
{
    protected ?ServerComponent $component = null;

    public function bind(?ServerComponent $component): ?ServerComponent
    {
        $previous = $this->component;
        $this->component = $component;

        return $previous;
    }

    protected function evaluatePath($path, $data)
    {
        if ($this->component === null) {
            return parent::evaluatePath($path, $data);
        }

        $obLevel = ob_get_level();

        ob_start();

        try {
            \Closure::bind(function ($__path, $__data) {
                extract($__data, EXTR_SKIP);

                include $__path;
            }, $this->component, $this->component::class)($path, $data);
        } catch (Throwable $e) {
            $this->handleViewException($e, $obLevel);
        }

        return ltrim(ob_get_clean());
    }
}
