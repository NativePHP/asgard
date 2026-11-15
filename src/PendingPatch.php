<?php

namespace Native\Asgard;

use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Native\Asgard\Blade\RegionViews;
use Native\Asgard\Support\FragmentValidator;
use Native\Asgard\Support\PushVersions;

/**
 * A change to one list inside a region, from Asgard::patch() or
 * Asgard::to()->patch(). Each call sends one patch: it bumps the region's
 * counters as a push would and logs the patch against them, so streams
 * send it on as a `patch` event instead of a signal.
 *
 * The nodes are checked and rendered to JSON once, here. Streams only wrap
 * and seal them.
 *
 * The app must change the data the region renders from as well. A device
 * that cannot apply a patch fetches the whole region, and so does every
 * device that opens the region later.
 */
class PendingPatch
{
    public const MAX_NODES = 100;

    public const MAX_KEYS = 1000;

    public const MAX_KEEP = 100000;

    /**
     * @param  list<string>  $counters  the region counters to bump, as PushVersions keys
     * @param  class-string<ServerComponent>  $class  for rendering a Blade view
     */
    public function __construct(
        protected PushVersions $versions,
        protected array $counters,
        protected string $class,
        protected string $region,
    ) {}

    /**
     * Insert nodes, in order, before the first child of the node keyed
     * $into. With $keep, keep at most that many children, dropping from the end.
     *
     * @param  Node|View|array<mixed>  $nodes
     */
    public function prepend(Node|View|array $nodes, string|int $into, ?int $keep = null): static
    {
        return $this->insert('prepend', $nodes, $into, $keep);
    }

    /**
     * Insert nodes, in order, after the last child of the node keyed $into.
     * With $keep, keep at most that many children, dropping from the start.
     *
     * @param  Node|View|array<mixed>  $nodes
     */
    public function append(Node|View|array $nodes, string|int $into, ?int $keep = null): static
    {
        return $this->insert('append', $nodes, $into, $keep);
    }

    /**
     * Drop the direct children of the node keyed $into whose key is one of
     * $keys. Keys the device does not hold are ignored.
     *
     * @param  list<string|int>  $keys
     */
    public function remove(string|int $into, array $keys): static
    {
        if ($keys === [] || count($keys) > self::MAX_KEYS) {
            throw new InvalidArgumentException('A remove patch takes between 1 and '.self::MAX_KEYS.' keys.');
        }

        foreach ($keys as $key) {
            if (! is_string($key) && ! is_int($key)) {
                throw new InvalidArgumentException('A remove patch\'s keys must be strings or ints.');
            }
        }

        return $this->send(
            ['op' => 'remove', 'target' => $this->target($into), 'keys' => array_values(array_map('strval', $keys))],
            [],
        );
    }

    /** @param  Node|View|array<mixed>  $nodes */
    protected function insert(string $op, Node|View|array $nodes, string|int $into, ?int $keep): static
    {
        $target = $this->target($into);

        if ($keep !== null && ($keep < 1 || $keep > self::MAX_KEEP)) {
            throw new InvalidArgumentException('A patch\'s keep must be between 1 and '.self::MAX_KEEP.'.');
        }

        $nodes = FragmentValidator::validate($this->nodes($nodes), null);

        if ($nodes === [] || count($nodes) > self::MAX_NODES) {
            throw new InvalidArgumentException("A {$op} patch takes between 1 and ".self::MAX_NODES.' nodes.');
        }

        $patch = ['op' => $op, 'target' => $target, 'nodes' => $nodes];
        if ($keep !== null) {
            $patch['keep'] = $keep;
        }

        return $this->send($patch, FragmentValidator::types($nodes));
    }

    /**
     * Whatever was given, as a list of nodes for FragmentValidator. A Blade
     * view is rendered as a region view, on a fresh component with no state.
     *
     * @param  Node|View|array<mixed>  $nodes
     * @return list<mixed>
     */
    protected function nodes(Node|View|array $nodes): array
    {
        if ($nodes instanceof View) {
            $nodes = $this->view($nodes);
        }

        if ($nodes instanceof Node || ! array_is_list($nodes)) {
            return [$nodes];
        }

        $list = [];
        foreach ($nodes as $node) {
            $node = $node instanceof View ? $this->view($node) : $node;
            array_push($list, ...(is_array($node) && array_is_list($node) ? $node : [$node]));
        }

        return $list;
    }

    /** @return Node|list<Node> */
    protected function view(View $view): Node|array
    {
        $component = app($this->class);
        $component->setAsgardContext($this->region);

        return app(RegionViews::class)->render($view, $component);
    }

    protected function target(string|int $into): string
    {
        $target = (string) $into;

        if ($target === '') {
            throw new InvalidArgumentException('A patch needs the key of the node it changes.');
        }

        return $target;
    }

    /**
     * @param  array<string, mixed>  $patch
     * @param  list<string>  $types
     */
    protected function send(array $patch, array $types): static
    {
        $this->versions->patch($this->counters, [
            'patch' => json_encode($patch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'types' => $types,
        ]);

        return $this;
    }
}
