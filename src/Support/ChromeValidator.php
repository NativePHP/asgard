<?php

namespace Native\Asgard\Support;

use Native\Asgard\Chrome\Chrome;
use Native\Asgard\Exceptions\InvalidFragment;

/**
 * Turns what a screen's chrome() returned into a clean chrome object, or
 * throws (protocol §12, "Chrome").
 *
 * Chrome is a closed vocabulary. Every key is known, every value has one
 * type, and an unknown key is an error rather than something passed
 * through. Buttons carry the same checked slots fragments do, and the
 * content slots (title_view, bottom_bar, tab_accessory) are fragments,
 * checked by FragmentValidator, so they cannot hold chrome or regions
 * either.
 */
final class ChromeValidator
{
    public const MAX_STRING = 1024;

    public const MAX_ACTIONS = 16;

    public const MAX_MENU_ITEMS = 32;

    public const MAX_TABS = 10;

    public const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,100}$/D';

    public const DISPLAY_MODES = ['large', 'inline', 'automatic'];

    public const SCROLL_BEHAVIORS = ['collapse', 'pinned', 'enterAlways'];

    public const LABEL_VISIBILITIES = ['labeled', 'selected', 'unlabeled'];

    /** @return array<string, mixed> */
    public static function validate(mixed $chrome): array
    {
        if ($chrome === null) {
            return [];
        }

        if ($chrome instanceof Chrome) {
            $chrome = $chrome->toArray();
        }

        $chrome = self::object($chrome, 'chrome');
        $clean = [];

        foreach ($chrome as $key => $value) {
            $path = "chrome.{$key}";

            $clean[$key] = match ($key) {
                'native' => self::bool($value, $path),
                'font' => self::string($value, $path),
                'nav_bar' => self::navBar($value, $path),
                'tab_bar' => self::tabBar($value, $path),
                'bottom_bar', 'tab_accessory' => self::content($value, $path),
                default => throw new InvalidFragment("{$path}: unknown chrome field."),
            };
        }

        return $clean;
    }

    /** @return array<string, mixed> */
    private static function navBar(mixed $bar, string $path): array
    {
        $clean = [];

        foreach (self::object($bar, $path) as $key => $value) {
            $at = "{$path}.{$key}";

            $clean[$key] = match ($key) {
                'hidden', 'back' => self::bool($value, $at),
                'title', 'subtitle', 'background_color', 'text_color', 'font' => self::string($value, $at),
                'elevation' => self::int($value, $at, 0, 64),
                'display_mode' => self::oneOf($value, $at, self::DISPLAY_MODES),
                'scroll_behavior' => self::oneOf($value, $at, self::SCROLL_BEHAVIORS),
                'logo' => self::logo($value, $at),
                'title_view' => self::content($value, $at),
                'search' => self::search($value, $at),
                'actions' => self::list($value, $at, self::MAX_ACTIONS, fn ($action, $p) => self::action($action, $p, true)),
                default => throw new InvalidFragment("{$at}: unknown nav bar field."),
            };
        }

        return $clean;
    }

    /** @return array<string, mixed> */
    private static function action(mixed $action, string $path, bool $topLevel): array
    {
        $action = self::object($action, $path);

        if (! isset($action['id']) || ! is_string($action['id']) || ! preg_match(self::ID_PATTERN, $action['id'])) {
            throw new InvalidFragment("{$path}.id: an action needs an id matching ".self::ID_PATTERN.'.');
        }

        if (($action['divider'] ?? false) === true) {
            if (array_diff(array_keys($action), ['id', 'divider']) !== []) {
                throw new InvalidFragment("{$path}: a divider has only an id.");
            }

            return ['id' => $action['id'], 'divider' => true];
        }

        $clean = [];
        foreach ($action as $key => $value) {
            $at = "{$path}.{$key}";

            $clean[$key] = match ($key) {
                'id' => $value,
                'label', 'a11y_label', 'icon', 'ios_icon', 'android_icon' => self::string($value, $at),
                'image' => self::url($value, $at),
                'destructive', 'disabled' => self::bool($value, $at),
                'on_press' => Callback::validateSlot($value, $at, 'on_press'),
                'items' => $topLevel
                    ? self::list($value, $at, self::MAX_MENU_ITEMS, fn ($item, $p) => self::action($item, $p, false))
                    : throw new InvalidFragment("{$at}: menu items cannot have items of their own."),
                default => throw new InvalidFragment("{$at}: unknown action field."),
            };
        }

        return $clean;
    }

    /** @return array<string, mixed> */
    private static function tabBar(mixed $bar, string $path): array
    {
        $clean = [];

        foreach (self::object($bar, $path) as $key => $value) {
            $at = "{$path}.{$key}";

            $clean[$key] = match ($key) {
                'hidden', 'dark', 'minimize_on_scroll' => self::bool($value, $at),
                'highlight' => self::id($value, $at),
                'active_color', 'background_color', 'text_color', 'font' => self::string($value, $at),
                'label_visibility' => self::oneOf($value, $at, self::LABEL_VISIBILITIES),
                'presentation' => self::presentation($value, $at),
                'tabs' => self::list($value, $at, self::MAX_TABS, fn ($tab, $p) => self::tab($tab, $p)),
                default => throw new InvalidFragment("{$at}: unknown tab bar field."),
            };
        }

        return $clean;
    }

    /** @return array<string, mixed> */
    private static function tab(mixed $tab, string $path): array
    {
        $tab = self::object($tab, $path);

        foreach (['id', 'label'] as $required) {
            if (! array_key_exists($required, $tab)) {
                throw new InvalidFragment("{$path}: a tab needs an {$required}.");
            }
        }

        $clean = [];
        foreach ($tab as $key => $value) {
            $at = "{$path}.{$key}";

            $clean[$key] = match ($key) {
                'id' => self::id($value, $at),
                'label', 'icon', 'ios_icon', 'android_icon', 'badge', 'badge_color' => self::string($value, $at),
                'url' => ($problem = Callback::uriProblem($value)) ? throw new InvalidFragment("{$at}: {$problem}") : $value,
                'news', 'active' => self::bool($value, $at),
                'search' => self::tabSearch($value, $at),
                'on_press' => Callback::validateSlot($value, $at, 'on_press'),
                default => throw new InvalidFragment("{$at}: unknown tab field."),
            };
        }

        return $clean;
    }

    /** @return array{src: string, height?: float|int} */
    private static function logo(mixed $logo, string $path): array
    {
        $logo = self::object($logo, $path);

        if (array_diff(array_keys($logo), ['src', 'height']) !== [] || ! isset($logo['src'])) {
            throw new InvalidFragment("{$path}: a logo is {\"src\", \"height\"?}.");
        }

        $clean = ['src' => self::string($logo['src'], "{$path}.src")];

        if (array_key_exists('height', $logo)) {
            if (! (is_int($logo['height']) || is_float($logo['height'])) || $logo['height'] <= 0 || $logo['height'] > 200) {
                throw new InvalidFragment("{$path}.height: must be a number between 0 and 200.");
            }
            $clean['height'] = $logo['height'];
        }

        return $clean;
    }

    /** @return array<string, mixed> */
    private static function search(mixed $search, string $path): array
    {
        $clean = [];

        foreach (self::object($search, $path) as $key => $value) {
            $at = "{$path}.{$key}";

            $clean[$key] = match ($key) {
                'placeholder' => self::string($value, $at),
                'debounce_ms' => self::int($value, $at, 0, 5000),
                // The query text is appended to args, as for on_change, so it never navigates.
                'on_query' => Callback::validateSlot($value, $at, 'on_change'),
                default => throw new InvalidFragment("{$at}: unknown search field."),
            };
        }

        return $clean;
    }

    /** @return array<string, mixed> */
    private static function tabSearch(mixed $search, string $path): array
    {
        $clean = [];

        foreach (self::object($search, $path) as $key => $value) {
            $at = "{$path}.{$key}";

            $clean[$key] = match ($key) {
                'placeholder' => self::string($value, $at),
                'debounce_ms' => self::int($value, $at, 0, 5000),
                default => throw new InvalidFragment("{$at}: unknown search field."),
            };
        }

        return $clean;
    }

    /** @return array{id: string, configuration?: array<string, mixed>} */
    private static function presentation(mixed $presentation, string $path): array
    {
        $presentation = self::object($presentation, $path);

        if (array_diff(array_keys($presentation), ['id', 'configuration']) !== []
            || ! is_string($presentation['id'] ?? null)
            || strlen($presentation['id']) > 128
            || ! preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*(?::[a-z0-9]+(?:[._-][a-z0-9]+)*)?\z/', $presentation['id'])) {
            throw new InvalidFragment("{$path}: a presentation is {\"id\", \"configuration\"?} with a lowercase id.");
        }

        $clean = ['id' => $presentation['id']];

        if (array_key_exists('configuration', $presentation)) {
            $configuration = $presentation['configuration'];

            if (! is_array($configuration) || ($configuration !== [] && array_is_list($configuration))
                || ! self::plain($configuration, 0)
                || strlen((string) json_encode($configuration)) > 16384) {
                throw new InvalidFragment("{$path}.configuration: must be a JSON object of plain values, at most 16 KB.");
            }

            if ($configuration !== []) {
                $clean['configuration'] = $configuration;
            }
        }

        return $clean;
    }

    /** A fragment (one node or a list) in a chrome content slot. */
    private static function content(mixed $content, string $path): array
    {
        try {
            return FragmentValidator::validate($content);
        } catch (InvalidFragment $e) {
            throw new InvalidFragment("{$path}: {$e->getMessage()}");
        }
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $path): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidFragment("{$path}: must be an object.");
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $path, int $max, \Closure $each): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidFragment("{$path}: must be a list.");
        }

        if (count($value) > $max) {
            throw new InvalidFragment("{$path}: at most {$max} entries.");
        }

        return array_map(fn ($item, $i) => $each($item, "{$path}[{$i}]"), $value, array_keys($value));
    }

    private static function bool(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            throw new InvalidFragment("{$path}: must be a boolean.");
        }

        return $value;
    }

    private static function string(mixed $value, string $path): string
    {
        if (! is_string($value) || strlen($value) > self::MAX_STRING) {
            throw new InvalidFragment("{$path}: must be a string of at most ".self::MAX_STRING.' bytes.');
        }

        return $value;
    }

    private static function id(mixed $value, string $path): string
    {
        if (! is_string($value) || ! preg_match(self::ID_PATTERN, $value)) {
            throw new InvalidFragment("{$path}: must be an id matching ".self::ID_PATTERN.'.');
        }

        return $value;
    }

    private static function url(mixed $value, string $path): string
    {
        $value = self::string($value, $path);

        if (! preg_match('#^https://[^\s]+$#D', $value)) {
            throw new InvalidFragment("{$path}: must be an https:// image URL.");
        }

        return $value;
    }

    private static function int(mixed $value, string $path, int $min, int $max): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new InvalidFragment("{$path}: must be an integer from {$min} to {$max}.");
        }

        return $value;
    }

    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, string $path, array $allowed): string
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidFragment("{$path}: must be one of ".implode(', ', $allowed).'.');
        }

        return $value;
    }

    private static function plain(mixed $value, int $depth): bool
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }
        if (! is_array($value) || $depth >= 8) {
            return false;
        }
        foreach ($value as $item) {
            if (! self::plain($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }
}
