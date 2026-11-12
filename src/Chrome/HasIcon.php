<?php

namespace Native\Asgard\Chrome;

/**
 * An icon name, with optional per-platform overrides, as the device's
 * NavAction and Tab builders take them. Names are the device's: SF Symbols
 * on iOS, Material on Android, or the shared names it maps to both.
 */
trait HasIcon
{
    protected ?string $icon = null;

    protected ?string $iosIcon = null;

    protected ?string $androidIcon = null;

    public function icon(?string $icon = null, ?string $ios = null, ?string $android = null): static
    {
        $this->icon = $icon;
        $this->iosIcon = $ios;
        $this->androidIcon = $android;

        return $this;
    }

    /** @return array<string, string> */
    protected function iconArray(): array
    {
        return array_filter([
            'icon' => $this->icon,
            'ios_icon' => $this->iosIcon,
            'android_icon' => $this->androidIcon,
        ], fn ($value) => $value !== null);
    }
}
