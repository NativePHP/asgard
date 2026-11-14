<?php

namespace Native\Asgard\Blade;

/**
 * Element types a region view may write as a bare tag (`<column>` as well
 * as `<native:column>`).
 *
 * On the device this list comes from the app's ElementRegistry. The server
 * has no registry, so this is a copy: nativephp/mobile's core elements plus
 * every component in nativephp/mobile-ui's nativephp.json (66 types, as of
 * November 2026). Add plugin types with `asgard.blade.elements`.
 *
 * Screen chrome (top_bar, bottom_nav...) is on the list on purpose. A bare
 * `<top-bar>` then compiles to a node, and FragmentValidator rejects it by
 * name, instead of the tag being dropped as markup without a word.
 *
 * `<native:anything>` always compiles, whatever is on this list. Compiling
 * is not the same as sending: a fragment may only use the core types and
 * the ones the client listed in `elements` at the handshake, and
 * FragmentValidator refuses the rest (Asgard protocol §9.3).
 */
final class Elements
{
    public const DEFAULT = [
        // nativephp/mobile core
        'column', 'row', 'stack', 'scroll_view', 'spacer',
        'text', 'image', 'icon', 'pressable',
        'top_bar', 'top_bar_action', 'top_bar_title',
        'bottom_nav', 'bottom_nav_item',
        'side_nav', 'side_nav_item', 'side_nav_group', 'side_nav_header',
        'fab', 'gesture_area', 'refreshable', 'server_region',
        'canvas', 'rect', 'circle', 'line', 'divider',

        // nativephp/mobile-ui
        'accordion', 'accordion_content', 'accordion_header', 'activity_indicator',
        'background_layer', 'badge', 'bare_text_input', 'bottom_sheet', 'button',
        'button_group', 'carousel', 'checkbox', 'chip', 'date_picker', 'emoji_picker',
        'filled_text_input', 'floating_overlay', 'horizontal_divider', 'lazy_grid',
        'list', 'list_item', 'list_placeholder', 'list_section', 'modal', 'native_drawer',
        'outlined_text_input', 'pager', 'pager_header', 'pager_overlay', 'popover',
        'progress_bar', 'radio', 'radio_group', 'select', 'sheet_pane', 'slider',
        'sticky_header', 'tab', 'tab_row', 'toggle', 'virtual_list', 'webview',
    ];

    /**
     * Bare tag names, kebab-case, for the precompiler.
     *
     * @param  list<string>  $extra  more snake_case types, from config
     * @return list<string>
     */
    public static function tags(array $extra = []): array
    {
        $types = array_unique([...self::DEFAULT, ...array_filter($extra, 'is_string')]);

        return array_values(array_map(fn (string $type) => str_replace('_', '-', $type), $types));
    }
}
