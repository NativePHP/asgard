<?php

namespace Native\Asgard\Blade;

use Native\Asgard\Exceptions\InvalidFragment;

/**
 * Compiles <native:*> tags directly into NodeCollector calls, so a region
 * view written in the app's Blade dialect builds asgard Nodes.
 *
 * Copied from nativephp/mobile src/Edge/NativeTagPrecompiler.php at
 * mobile-air commit 0de637ae8c2593e16f78cfeb85c9598186d5e523. It is meant
 * to move into a package both sides share, so keep it close to that file.
 * Differences from the original:
 *
 * - Calls go to \Native\Asgard\Blade\NodeCollector.
 * - `native:model`, `@model`, `native:poll` and `@navigate` are refused at
 *   compile time. A fragment has no client props to sync, re-renders by
 *   push rather than polling, and navigation is not a fragment callback
 *   yet. TODO: compile `@navigate` once the protocol has a navigate slot.
 * - Event attributes take a `.client` modifier (`@press.client="x"`), and
 *   the five fragment callbacks compile to a structured {call, args} array
 *   instead of an expression string. See compileCallback().
 * - `<virtual-list />` is an ordinary leaf. There is no item view loop.
 * - Unknown bare tags are reported without the app's element hints.
 */
class NativeTagPrecompiler
{
    /**
     * Elements that capture their slot content as a text prop.
     * tag name => prop name for the captured text
     */
    private const TEXT_ELEMENTS = [
        'text' => 'text',
        'button' => 'label',
    ];

    /**
     * Elements that capture their slot content as a raw markup prop —
     * no strip_tags or entity decoding, because the markup itself is the
     * payload (e.g. <webview> renders its slot as an inline HTML document).
     * An explicit `:prop` attribute takes precedence over the slot.
     * tag name => prop name for the captured markup
     */
    private const RAW_SLOT_ELEMENTS = [
        'webview' => 'html',
    ];

    /**
     * Alias directive → canonical press-family directive. Both spellings are
     * permanent; the alias is normalized away at compile time so the rest of
     * the pipeline only ever sees the canonical name.
     */
    private const TAP_ALIASES = [
        'tap' => 'press',
        'longTap' => 'longPress',
        'tapDown' => 'pressDown',
        'tapUp' => 'pressUp',
    ];

    /**
     * Built-in `@event` names rewritten to `_event` before Blade treats `@` as a directive.
     *
     * @var string[]
     */
    private const CORE_ELEMENT_EVENTS = [
        'tapDown',
        'tapUp',
        'tap',
        'pressDown',
        'pressUp',
        'press',
        'longPress',
        'doubleTap',
        'selectionChange',
        'change',
        'submit',
        'dismiss',
        'refresh',
        'endReached',
        'swipeDelete',
        'swipe',
        'pinchEnd',
        'dragEnd',
        'trigger',
        'navigated',
    ];

    /** @var string[] Plugin-declared `@event` names. */
    private static array $customElementEvents = [];

    /**
     * Compiled attribute names whose value is a fragment callback. Both the
     * server form (`_press`) and the client form (`_press-client`, from
     * `@press.client`) compile to a {call, args} array.
     */
    private const CALLBACK_EVENTS = ['press', 'longPress', 'doubleTap', 'change', 'submit'];

    private const C = '\\Native\\Asgard\\Blade\\NodeCollector';

    /**
     * Marker stamped into every natively-compiled view (prepended to the
     * template as a raw PHP block, so it survives Blade compilation into
     * the compiled file's first bytes).
     *
     * Blade's compiled-view cache is keyed by path + mtime only — whether
     * THIS precompiler was active is not part of the key. So a view first
     * compiled by a web render (or `artisan view:cache`) is cached with
     * its native tags untouched, and a later native render would include
     * that poisoned file and collect zero elements ("No root element was
     * built by the Blade template"). The marker makes compiled output
     * self-describing: the native render path force-recompiles any
     * compiled file that lacks it. See compiledFileIsNative().
     */
    public const COMPILED_MARKER = '/*nphp:native*/';

    /**
     * Whether the compiled file at $compiledPath was produced with this
     * precompiler active. Missing files return false; the caller's
     * compile() handles both cases identically.
     *
     * Deliberately unmemoized: in a long-lived dev-server process a web
     * request can recompile the same view without the marker between
     * native frames, so a cached "marked" answer could go stale. The
     * marker sits in the file's first bytes — a 256-byte head read per
     * view per frame is noise next to the render itself.
     */
    public static function compiledFileIsNative(string $compiledPath): bool
    {
        if (! is_file($compiledPath)) {
            return false;
        }

        $head = @file_get_contents($compiledPath, false, null, 0, 256);

        return $head !== false && str_contains($head, self::COMPILED_MARKER);
    }

    /**
     * Whether native-tag transformation is currently active.
     *
     * The precompiler is registered globally on the Blade compiler, so it is
     * invoked while compiling *every* view — including plain web pages,
     * Livewire components, Flux, and Laravel's own exception renderer. Bare
     * short-form tags like `<button>` collide with real HTML, so transforming
     * those views would rewrite legitimate markup (e.g. an Alpine
     * `<button :class="{ ... }">` becomes invalid PHP and fatals).
     *
     * In asgard the precompiler is only registered on the region compiler
     * (see RegionCompiler), and RegionViews flips this flag on for the
     * duration of a region render. Everywhere else it is a no-op.
     */
    private static bool $active = false;

    public static function active(): bool
    {
        return self::$active;
    }

    /**
     * Toggle native-tag transformation, returning the previous state so callers
     * can restore it (the render path can re-enter for nested partials).
     */
    public static function setActive(bool $active): bool
    {
        $previous = self::$active;
        self::$active = $active;

        return $previous;
    }

    /**
     * Register extra `@event` names so they compile like `@change`.
     * Names are global at compile time. Core names are ignored.
     *
     * @param  string[]  $names
     */
    public static function registerElementEvents(array $names): void
    {
        foreach ($names as $name) {
            if (! is_string($name) || $name === '' || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $name)) {
                continue;
            }

            if (in_array($name, self::CORE_ELEMENT_EVENTS, true) || in_array($name, self::$customElementEvents, true)) {
                continue;
            }

            self::$customElementEvents[] = $name;
        }
    }

    /**
     * @return string[]
     */
    public static function customElementEvents(): array
    {
        return self::$customElementEvents;
    }

    /** Drop plugin-registered names. Used by tests. */
    public static function resetElementEvents(): void
    {
        self::$customElementEvents = [];
    }

    /** Known `@event` names, longest first, preg_quoted. */
    private static function elementEventAlternation(): string
    {
        $names = array_merge(self::CORE_ELEMENT_EVENTS, self::$customElementEvents);
        usort($names, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return implode('|', array_map(static fn (string $name) => preg_quote($name, '/'), $names));
    }

    /**
     * Bare tag names (without the `native:` prefix) that should also be
     * recognized as native elements. The server has no element registry,
     * so these come from Elements::tags() (a copied list plus
     * `asgard.blade.elements`) so both `<native:column>` and `<column>`
     * compile to the same NodeCollector calls.
     *
     * @var string[]
     */
    private array $shortFormTags;

    /** Precomputed alternation regex group (e.g. `column|row|stack|...`). */
    private ?string $shortFormAlt;

    /**
     * @param  string[]  $shortFormTags  Bare tag names to recognize alongside `<native:*>`.
     */
    public function __construct(array $shortFormTags = [])
    {
        $this->shortFormTags = $shortFormTags;

        // Sort by descending length so the regex alternation matches the
        // longest tag first — otherwise `<top-bar-action>` would tokenize
        // as `<top-bar>` with `-action` left over as attrs when both
        // names are in the list.
        $sorted = $shortFormTags;
        usort($sorted, fn ($a, $b) => strlen($b) - strlen($a));

        $this->shortFormAlt = $sorted === []
            ? null
            : implode('|', array_map('preg_quote', $sorted));
    }

    public function __invoke(string $value): string
    {
        // Only rewrite native tags when compiling a native view. For every
        // other view (web pages, Livewire, Flux, the exception renderer) leave
        // the source untouched so bare `<button>`/`<text>` etc. stay as HTML.
        if (! self::$active) {
            return $value;
        }

        // A bare tag no registered element claims is left as markup, and a
        // native render drops markup on the floor. Say so before that happens.
        $this->reportUnknownBareTags($value);

        // Stamp the compiled output as natively-compiled (see COMPILED_MARKER).
        // No trailing newline — line numbers in compile errors stay unchanged.
        $value = '<?php '.self::COMPILED_MARKER.' ?>'.$value;

        // Directives that mean something only to an on-device screen. A
        // fragment has no client props to sync and re-renders by push, and
        // navigation is not a fragment callback yet. Fail the compile with
        // a clear message rather than letting them fall through as props.
        foreach ([
            '/native:model\b/' => 'native:model',
            '/@model=/' => '@model',
            '/native:poll\b/' => 'native:poll',
            '/@navigate\b/' => '@navigate',
        ] as $pattern => $name) {
            if (preg_match($pattern, $value)) {
                throw new InvalidFragment("{$name} is not supported in a server region view.".$this->inView());
            }
        }

        // Rename `native:key="…"` → `native-key="…"` so the attribute parser
        // (which rejects ':' in names) preserves it. The collector turns it
        // (or a plain `key`) into the node's key.
        $value = str_replace('native:key=', 'native-key=', $value);

        // Tap spellings are aliases of the press family — `@tap` is the
        // mobile-native way to say `@tap`, and both are supported for
        // good. They rewrite straight to the *canonical* underscored attr,
        // so nothing downstream (collector, Element, wire format, testing
        // suite) ever learns a second name, and every existing app written
        // against `@tap` compiles byte-identically.
        // Longer spellings precede their prefix, as in the canonical pass
        // below. `@doubleTap` is untouched: the alternation is anchored at
        // `@`, so `tap` can't match mid-word.
        // The `.client` modifier survives as a `-client` suffix, because the
        // attribute parser rejects '.' in names.
        $value = preg_replace_callback(
            '/@(longTap|tapDown|tapUp|tap)(\.client)?=/',
            fn ($m) => '_'.self::TAP_ALIASES[$m[1]].(($m[2] ?? '') !== '' ? '-client' : '').'=',
            $value
        );

        // Convert known `@event=` attributes (core + plugin-registered) to
        // underscored versions before Blade interprets @ as a directive.
        // Longer spellings precede their prefix (`pressDown`/`pressUp` before
        // `press`, `swipeDelete` before `swipe`) so they win the longer match.
        $value = preg_replace_callback(
            '/@('.self::elementEventAlternation().')(\.client)?=/',
            fn ($m) => '_'.$m[1].(($m[2] ?? '') !== '' ? '-client' : '').'=',
            $value
        );

        // Any REMAINING `@name="..."` attribute is a child-component event
        // binding — the tag-level half of `$this->emit()`:
        //
        //     <native:order-row :order="$o" @order-shipped="markShipped({{ $o->id }})" />
        //
        // Rewritten to a plain `_event-name` attribute (the attr parser
        // rejects '@'), which mountChildComponent() strips off and maps to
        // the parent method when the child emits that event. Runs AFTER
        // every known directive pass, so only unknown `@x=` spellings reach
        // it; the leading whitespace requirement keeps it in attribute
        // position (an inline `a@b=` in text is left alone). On a plain
        // element the attribute is stripped by the collector — components
        // are resolved at runtime, so compile time can't tell the two apart.
        $value = preg_replace('/(?<=\s)@([a-zA-Z][a-zA-Z0-9_-]*)=/', '_event-$1=', $value);

        // The attribute-region pattern below uses possessive quantifiers
        // (`*+`) to keep PCRE from catastrophically backtracking when a
        // long template has many tags and quoted attribute values. The
        // earlier non-possessive form hit PHP's pcre.backtrack_limit on
        // templates above ~9KB, returning NULL silently — caller saw
        // "No root element was built" because tags weren't transformed.
        //
        // The character class also excludes `/` so the trailing `/>`
        // terminator of a self-closing tag stays visible to the closing
        // pattern. Without that exclusion the possessive `*+` would
        // swallow the `/` and the regex would fail to match (where the
        // non-possessive form previously backtracked to release it).
        $attrs = "((?:[^>\"'\\/]*+(?:\"[^\"]*+\"|'[^']*+')[^>\"'\\/]*+)*+|[^>\"'\\/]*+)";

        // 1. Self-closing tags: <native:type attrs />
        $value = preg_replace_callback(
            '/<\s*native\s*:\s*([a-zA-Z0-9\-_]+)\s*'.$attrs.'\s*\/>/s',
            fn ($m) => $this->compileSelfClosing($m[1], trim($m[2] ?? '')),
            $value
        );

        // 2. Closing tags: </native:type>
        $value = preg_replace_callback(
            '/<\/\s*native\s*:\s*([a-zA-Z0-9\-_]+)\s*>/s',
            fn ($m) => $this->compileClosing($m[1]),
            $value
        );

        // 3. Opening tags: <native:type attrs>
        $value = preg_replace_callback(
            '/<\s*native\s*:\s*([a-zA-Z0-9\-_]+)\s*'.$attrs.'\s*>/s',
            fn ($m) => $this->compileOpening($m[1], trim($m[2] ?? '')),
            $value
        );

        // Short-form pass — same compilation, no `native:` prefix required.
        // Tag must be in the registered-element allowlist so we don't
        // accidentally rewrite arbitrary markup. Order matches the
        // prefixed pass: self-closing, then closing, then opening.
        if ($this->shortFormAlt !== null) {
            $alt = $this->shortFormAlt;

            $value = preg_replace_callback(
                '/<\s*('.$alt.')\s*'.$attrs.'\s*\/>/s',
                fn ($m) => $this->compileSelfClosing($m[1], trim($m[2] ?? '')),
                $value
            );

            $value = preg_replace_callback(
                '/<\/\s*('.$alt.')\s*>/s',
                fn ($m) => $this->compileClosing($m[1]),
                $value
            );

            $value = preg_replace_callback(
                '/<\s*('.$alt.')\s*'.$attrs.'\s*>/s',
                fn ($m) => $this->compileOpening($m[1], trim($m[2] ?? '')),
                $value
            );
        }

        return $value;
    }

    /**
     * Find bare tags in a region view that will not compile to a node and
     * were clearly meant as one: any hyphenated custom-element name.
     * Single-word unknown tags are left alone, since those could be stray
     * HTML inside a text slot.
     *
     * With app.debug on this throws, so the render fails with the tag
     * named. With it off the tag is reported and the region renders
     * without it.
     */
    private function reportUnknownBareTags(string $value): void
    {
        // A webview slot is an HTML document; its tags are not elements.
        $scan = preg_replace('/<\s*(?:native\s*:\s*)?webview\b.*?<\/\s*(?:native\s*:\s*)?webview\s*>/s', '', $value) ?? $value;

        if (! preg_match_all('/<([a-zA-Z][a-zA-Z0-9]*(?:[-_][a-zA-Z0-9]+)*)(?=[\s\/>])/', $scan, $matches)) {
            return;
        }

        $known = array_flip($this->shortFormTags);

        $unknown = array_values(array_filter(
            array_unique($matches[1]),
            fn (string $tag) => ! isset($known[$tag]) && str_contains($tag, '-'),
        ));

        if ($unknown === []) {
            return;
        }

        $exception = new InvalidFragment(
            'Unknown element tag <'.implode('>, <', $unknown).'> in a server region view. '
            .'Add its type to asgard.blade.elements, or write it as <native:...>.'.$this->inView()
        );

        if (config('app.debug', false)) {
            throw $exception;
        }

        report($exception);
    }

    private function inView(): string
    {
        try {
            $path = app('asgard.blade.compiler')->getPath();
        } catch (\Throwable) {
            $path = null;
        }

        return $path ? " ({$path})" : '';
    }

    private function tagToType(string $tag): string
    {
        return str_replace('-', '_', $tag);
    }

    private function compileSelfClosing(string $tag, string $rawAttrs): string
    {
        $type = $this->tagToType($tag);
        $attrs = $this->compileAttributes($rawAttrs);

        // A self-closing <text /> carries attribute text only. Route it
        // through textLeaf so a whitespace-* class governs `:text`
        // exactly as the paired form does at textClose.
        if ($tag === 'text') {
            return '<?php '.self::C."::textLeaf({$attrs}); ?>";
        }

        return '<?php '.self::C."::leaf('{$type}', {$attrs}); ?>";
    }

    private function compileOpening(string $tag, string $rawAttrs): string
    {
        $type = $this->tagToType($tag);
        $attrs = $this->compileAttributes($rawAttrs);

        // <text> captures its slot as ordered inline runs (nested <text> +
        // interleaved raw text, in document order) so the renderer composes one
        // wrapping attributed string. Other text-capture elements (button) keep
        // the flat-string slot path.
        if ($tag === 'text') {
            return '<?php '.self::C."::textOpen({$attrs}); ?>";
        }

        // Text-capture elements: save attrs and start output buffering
        if (isset(self::TEXT_ELEMENTS[$tag])) {
            return "<?php \$__nativeSlotAttrs = {$attrs}; ob_start(); ?>";
        }

        // Raw-slot elements: same buffering, but the slot is kept verbatim
        // on close — the markup itself is the payload.
        if (isset(self::RAW_SLOT_ELEMENTS[$tag])) {
            return "<?php \$__nativeSlotAttrs = {$attrs}; ob_start(); ?>";
        }

        // Container: push onto collector stack
        return '<?php '.self::C."::open('{$type}', {$attrs}); ?>";
    }

    private function compileClosing(string $tag): string
    {
        // <text> close — emit its captured inline runs (see textOpen).
        if ($tag === 'text') {
            return '<?php '.self::C.'::textClose(); ?>';
        }

        if (isset(self::TEXT_ELEMENTS[$tag])) {
            $propName = self::TEXT_ELEMENTS[$tag];
            $type = $this->tagToType($tag);

            $code = '<?php $__nativeSlot = preg_replace(\'/\s+/\', \' \', trim(html_entity_decode(strip_tags(ob_get_clean()), ENT_QUOTES, \'UTF-8\')));';

            if ($tag === 'button') {
                $code .= " if (\$__nativeSlot !== '' && !isset(\$__nativeSlotAttrs['label'])) { \$__nativeSlotAttrs['label'] = \$__nativeSlot; }";
            } else {
                $code .= " if (\$__nativeSlot !== '') { \$__nativeSlotAttrs['{$propName}'] = \$__nativeSlot; }";
            }

            $code .= ' '.self::C."::leaf('{$type}', \$__nativeSlotAttrs); ?>";

            return $code;
        }

        if (isset(self::RAW_SLOT_ELEMENTS[$tag])) {
            $propName = self::RAW_SLOT_ELEMENTS[$tag];
            $type = $this->tagToType($tag);

            // No strip_tags/entity decoding — the markup is the payload. An
            // explicit attribute (e.g. `:html`) wins over the captured slot.
            $code = '<?php $__nativeSlot = trim(ob_get_clean());';
            $code .= " if (\$__nativeSlot !== '' && !isset(\$__nativeSlotAttrs['{$propName}'])) { \$__nativeSlotAttrs['{$propName}'] = \$__nativeSlot; }";
            $code .= ' '.self::C."::leaf('{$type}', \$__nativeSlotAttrs); ?>";

            return $code;
        }

        // Container: pop from collector stack
        return '<?php '.self::C.'::close(); ?>';
    }

    /**
     * Compile an attribute value, interpolating Blade {{ }} and {!! !!} syntax.
     *
     * In native context we skip e() since there's no HTML to escape.
     *   "{{ $category }}"          → ($category)
     *   "{!! $raw !!}"             → ($raw)
     *   "Price: {{ $price }}/night" → 'Price: ' . ($price) . '/night'
     *   "plain text"                → 'plain text'
     */
    private function compileAttributeValue(string $value): string
    {
        // No Blade interpolation — return as literal string
        if (! preg_match('/\{\{|\{!!/', $value)) {
            return "'".addslashes($value)."'";
        }

        // Split on {{ expr }} and {!! expr !!} boundaries, keeping delimiters
        $parts = preg_split('/(\{\{.*?\}\}|\{!!.*?!!\})/s', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $segments = [];

        foreach ($parts as $part) {
            if (preg_match('/^\{\{\s*(.+?)\s*\}\}$/s', $part, $m)) {
                $segments[] = '('.trim($m[1]).')';
            } elseif (preg_match('/^\{!!\s*(.+?)\s*!!\}$/s', $part, $m)) {
                $segments[] = '('.trim($m[1]).')';
            } else {
                $segments[] = "'".addslashes($part)."'";
            }
        }

        return count($segments) === 1 ? $segments[0] : implode(' . ', $segments);
    }

    private function compileAttributes(string $rawAttrs): string
    {
        if ($rawAttrs === '') {
            return '[]';
        }

        $parts = [];
        $remaining = $rawAttrs;

        while (($remaining = ltrim($remaining)) !== '') {
            // Dynamic attribute :name="expr"
            if (preg_match('/^:([a-zA-Z0-9_\-]+)\s*=\s*"([^"]*)"/s', $remaining, $m)) {
                $parts[] = "'".addslashes($m[1])."' => (".$m[2].')';
                $remaining = substr($remaining, strlen($m[0]));

                continue;
            }

            // Dynamic attribute :name='expr'
            if (preg_match("/^:([a-zA-Z0-9_\\-]+)\\s*=\\s*'([^']*)'/s", $remaining, $m)) {
                $parts[] = "'".addslashes($m[1])."' => (".$m[2].')';
                $remaining = substr($remaining, strlen($m[0]));

                continue;
            }

            // Static attribute name="value"
            if (preg_match('/^([a-zA-Z0-9_\-]+)\s*=\s*"([^"]*)"/s', $remaining, $m)) {
                $parts[] = "'".addslashes($m[1])."' => ".($this->isCallbackAttribute($m[1]) ? $this->compileCallback($m[1], $m[2]) : $this->compileAttributeValue($m[2]));
                $remaining = substr($remaining, strlen($m[0]));

                continue;
            }

            // Static attribute name='value'
            if (preg_match("/^([a-zA-Z0-9_\\-]+)\\s*=\\s*'([^']*)'/s", $remaining, $m)) {
                $parts[] = "'".addslashes($m[1])."' => ".($this->isCallbackAttribute($m[1]) ? $this->compileCallback($m[1], $m[2]) : $this->compileAttributeValue($m[2]));
                $remaining = substr($remaining, strlen($m[0]));

                continue;
            }

            // Boolean attribute (standalone word)
            if (preg_match('/^([a-zA-Z0-9_\-]+)/', $remaining, $m)) {
                $parts[] = "'".$m[1]."' => true";
                $remaining = substr($remaining, strlen($m[0]));

                continue;
            }

            // Skip unrecognized character
            $remaining = substr($remaining, 1);
        }

        return '['.implode(', ', $parts).']';
    }

    private function isCallbackAttribute(string $name): bool
    {
        return (bool) preg_match('/^_('.implode('|', self::CALLBACK_EVENTS).')(-client)?$/D', $name);
    }

    /**
     * Compile a callback attribute like `select('a', 2, {{ $id }})` into a
     * PHP array literal: ['call' => 'select', 'args' => ['a', 2, ($id)]].
     *
     * Nothing is parsed at runtime and nothing is evaluated from a string.
     * The method name must be a bare identifier. Each argument must be a
     * PHP literal (a quoted string without interpolation, a number, true,
     * false, null, or a [...] array of those) or one whole `{{ expr }}`,
     * which is evaluated as a value, not spliced into text. So
     * `select({{ $id }})` passes $id as it is (an int stays an int), and a
     * string holding `', 'x` cannot add arguments.
     */
    private function compileCallback(string $attribute, string $expression): string
    {
        $exprs = [];
        $source = preg_replace_callback('/\{\{\s*(.+?)\s*\}\}|\{!!\s*(.+?)\s*!!\}/s', function ($m) use (&$exprs) {
            $exprs[] = trim(($m[1] ?? '') !== '' ? $m[1] : $m[2]);

            return '__asgard_arg_'.(count($exprs) - 1).'__';
        }, $expression);

        $event = ltrim(preg_replace('/-client$/', '', $attribute), '_');
        $fail = fn (string $why) => new InvalidFragment("@{$event}=\"{$expression}\": {$why}".$this->inView());

        if (! preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*(?:\((.*)\))?\s*$/sD', $source, $m)) {
            throw $fail('expected a method name, optionally followed by (args).');
        }

        [$call, $args] = [$m[1], $m[2] ?? ''];

        if (str_starts_with($call, '__asgard_arg_')) {
            throw $fail('the method name cannot be dynamic.');
        }

        $tokens = array_values(array_filter(
            array_slice(\PhpToken::tokenize('<?php '.$args.';'), 1, -1),
            fn (\PhpToken $t) => ! $t->isIgnorable(),
        ));

        $compiled = '';
        $depth = 0;
        foreach ($tokens as $i => $token) {
            $text = $token->text;

            if ($token->is(T_CONSTANT_ENCAPSED_STRING) && str_contains($text, '__asgard_arg_')) {
                throw $fail('{{ }} must be a whole argument, not part of a quoted string.');
            }

            $compiled .= match (true) {
                $token->is([T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_DOUBLE_ARROW]) => $text,
                $text === ',' => $text,
                $text === '-' && ($tokens[$i + 1] ?? null)?->is([T_LNUMBER, T_DNUMBER]) => $text,
                $text === '[' && ++$depth <= 4 => $text,
                $text === ']' && $depth-- > 0 => $text,
                $token->is(T_STRING) && in_array(strtolower($text), ['true', 'false', 'null'], true) => strtolower($text),
                $token->is(T_STRING) && preg_match('/^__asgard_arg_(\d+)__$/D', $text, $n) === 1 => '('.$exprs[(int) $n[1]].')',
                default => throw $fail("[{$text}] is not allowed in an argument. Use literals, or {{ }} for a value."),
            };
            $compiled .= ' ';
        }

        if ($depth !== 0) {
            throw $fail('unbalanced brackets.');
        }

        return "['call' => '{$call}', 'args' => [{$compiled}]]";
    }
}
