<?php

declare(strict_types=1);

namespace SweetSen\Portal;

/**
 * SweetSen brand: the product name and the sampaguita mark (original artwork, Philippine national flower).
 * One source for the header, portal bar, console, sign-in page, QR cards and the browser tab icon.
 */
final class Brand
{
    public const NAME = 'SweetSen';

    public const SVG = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><g fill='#FFE07A' stroke='#22272E' stroke-width='1.5' stroke-linejoin='round' transform='translate(16 16) scale(.8) translate(-16 -16)'><path d='M16 15.6C10.9 12.9 10.6 5.1 16 3.1C21.4 5.1 21.1 12.9 16 15.6Z' transform='rotate(36 16 16)'/><path d='M16 15.6C10.9 12.9 10.6 5.1 16 3.1C21.4 5.1 21.1 12.9 16 15.6Z' transform='rotate(108 16 16)'/><path d='M16 15.6C10.9 12.9 10.6 5.1 16 3.1C21.4 5.1 21.1 12.9 16 15.6Z' transform='rotate(180 16 16)'/><path d='M16 15.6C10.9 12.9 10.6 5.1 16 3.1C21.4 5.1 21.1 12.9 16 15.6Z' transform='rotate(252 16 16)'/><path d='M16 15.6C10.9 12.9 10.6 5.1 16 3.1C21.4 5.1 21.1 12.9 16 15.6Z' transform='rotate(324 16 16)'/></g><g fill='#F2B705' stroke='#22272E' stroke-width='1.5' stroke-linejoin='round'><path d='M16 15.6C10.9 12.9 10.6 5.1 16 3.1C21.4 5.1 21.1 12.9 16 15.6Z' transform='rotate(0 16 16)'/><path d='M16 15.6C10.9 12.9 10.6 5.1 16 3.1C21.4 5.1 21.1 12.9 16 15.6Z' transform='rotate(72 16 16)'/><path d='M16 15.6C10.9 12.9 10.6 5.1 16 3.1C21.4 5.1 21.1 12.9 16 15.6Z' transform='rotate(144 16 16)'/><path d='M16 15.6C10.9 12.9 10.6 5.1 16 3.1C21.4 5.1 21.1 12.9 16 15.6Z' transform='rotate(216 16 16)'/><path d='M16 15.6C10.9 12.9 10.6 5.1 16 3.1C21.4 5.1 21.1 12.9 16 15.6Z' transform='rotate(288 16 16)'/></g><circle cx='16' cy='16' r='3.2' fill='#22272E'/><circle cx='16' cy='16' r='1.2' fill='#FFF4CC'/></svg>";

    /** The mark as a data URI, safe inside CSS url("...") and HTML attributes. */
    public static function uri(): string
    {
        return 'data:image/svg+xml,' . str_replace(['#', '<', '>'], ['%23', '%3C', '%3E'], self::SVG);
    }

    public static function favicon(): string
    {
        return '<link rel="icon" type="image/svg+xml" href="' . esc_attr(self::uri()) . '">';
    }

    /** CSS that turns an empty element (or ::before) into the mark at the given size. */
    public static function markCss(string $selector, string $size): string
    {
        return $selector . '{width:' . $size . ';height:' . $size . ';background:url("' . self::uri() . '") center/contain no-repeat;border-radius:0;box-shadow:none;flex:none}';
    }

    public static function printFavicon(): void
    {
        echo self::favicon() . "\n";
    }
}
