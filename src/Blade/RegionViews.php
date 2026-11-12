<?php

namespace Native\Asgard\Blade;

use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\View\View;
use Illuminate\View\ViewException;
use Native\Asgard\Chrome\Chrome;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;
use Throwable;

/**
 * Turns a region method's `view(...)` into Nodes.
 *
 * The view is rendered again through the region engine: its own compiler
 * (with NativeTagPrecompiler), its own compiled-view directory, and `$this`
 * bound to the component. The component's public properties are view
 * variables too. While it renders, the view factory's `blade`
 * engine is swapped for the region engine, so `@include`d partials compile
 * the same way. The app's own engine is put back afterwards, so web views
 * rendered in the same process are not touched.
 */
class RegionViews
{
    public function __construct(protected RegionEngine $engine) {}

    /**
     * A View becomes one Node, or a list when the view has several roots.
     * Anything else is returned as it is, for FragmentValidator to judge.
     */
    public function resolve(mixed $result, ServerComponent $component): mixed
    {
        return $result instanceof ViewContract ? $this->render($result, $component) : $result;
    }

    /**
     * A screen's chrome, with any Blade view in bottom_bar or
     * tab_accessory rendered like the body. Works on a Chrome and on the
     * array form. Everything else is returned as it is, for
     * ChromeValidator to judge.
     */
    public function chrome(mixed $chrome, ServerComponent $component): mixed
    {
        $render = fn (ViewContract $view) => $this->render($view, $component);

        if ($chrome instanceof Chrome) {
            return $chrome->renderViews($render);
        }

        if (is_array($chrome)) {
            foreach (['bottom_bar', 'tab_accessory'] as $slot) {
                if (($chrome[$slot] ?? null) instanceof ViewContract) {
                    $chrome[$slot] = $render($chrome[$slot]);
                }
            }
        }

        return $chrome;
    }

    /** @return Node|list<Node> */
    public function render(ViewContract $view, ServerComponent $component): Node|array
    {
        if (! $view instanceof View || ! str_ends_with($view->getPath(), '.blade.php')) {
            throw new InvalidFragment('A region view must be a Blade view (.blade.php).');
        }

        $factory = $view->getFactory();
        // Public properties are view variables too, as in the app. Data
        // passed to view() wins over a property of the same name.
        $data = array_merge(get_object_vars($component), $view->getData());
        $region = new View($factory, $this->engine, $view->name(), $view->getPath(), $data);

        $resolver = $factory->getEngineResolver();
        $appEngine = $resolver->resolve('blade');
        $resolver->register('blade', fn () => $this->engine);

        $wasActive = NativeTagPrecompiler::setActive(true);
        $previous = $this->engine->bind($component);
        $saved = NodeCollector::begin();

        try {
            $region->render();
        } catch (Throwable $e) {
            NodeCollector::end($saved, check: false);

            throw $this->unwrap($e);
        } finally {
            $this->engine->bind($previous);
            NativeTagPrecompiler::setActive($wasActive);
            $resolver->register('blade', fn () => $appEngine);
        }

        $roots = NodeCollector::end($saved);

        return match (count($roots)) {
            0 => throw new InvalidFragment("Region view [{$view->name()}] built no elements."),
            1 => $roots[0],
            default => $roots,
        };
    }

    /** The view engine wraps errors, possibly several times. Surface ours. */
    protected function unwrap(Throwable $e): Throwable
    {
        $inner = $e;
        while ($inner instanceof ViewException && $inner->getPrevious() !== null) {
            $inner = $inner->getPrevious();
        }

        return $inner instanceof InvalidFragment ? $inner : $e;
    }
}
