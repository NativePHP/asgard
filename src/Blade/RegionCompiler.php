<?php

namespace Native\Asgard\Blade;

use Illuminate\View\Compilers\BladeCompiler;

/**
 * The Blade compiler for region views.
 *
 * A separate instance from the app's `blade.compiler`, with its own cache
 * directory, so a region view and a web view never share a compiled file
 * and the app's web views never see NativeTagPrecompiler. A compiled file
 * without the native marker counts as expired, as on the device, in case
 * the cache directory is ever shared.
 */
class RegionCompiler extends BladeCompiler
{
    public function isExpired($path)
    {
        return parent::isExpired($path)
            || ! NativeTagPrecompiler::compiledFileIsNative($this->getCompiledPath($path));
    }

    /**
     * Copy the app's custom directives and extensions, so a region view can
     * use the same `@directives` as the rest of the app. Precompilers and
     * component aliases are not copied.
     */
    public function inherit(BladeCompiler $app): void
    {
        foreach ($app->getCustomDirectives() as $name => $handler) {
            $this->directive($name, $handler);
        }

        foreach ($app->getExtensions() as $extension) {
            $this->extend($extension);
        }
    }
}
