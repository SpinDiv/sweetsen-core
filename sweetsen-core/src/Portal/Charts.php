<?php

declare(strict_types=1);

namespace SweetSen\Portal;

/**
 * Small, dependency-free charts for the console, drawn server-side.
 * Single series in deep grey; the one point that matters (today) in golden yellow.
 * Every chart carries its numbers as text too (labels, tooltips, a table view).
 */
final class Charts
{
    /** @param list<array{date:string, label:string, count:int}> $days */
    public static function dailyBars(array $days): string
    {
        $W = 520; $H = 240; $L = 30; $R = 6; $T = 24; $B = 32;
        $pw = $W - $L - $R; $ph = $H - $T - $B;
        $max = max(1, ...array_column($days, 'count'));
        $step = self::niceStep($max / 4);
        $yMax = max($step, $step * (int) ceil($max / $step));
        $n = count($days);
        $band = $pw / $n;
        $bw = min(24, $band * 0.62);
        $y = static fn(float $v): float => $T + $ph - ($v / $yMax) * $ph;

        $grid = '';
        for ($v = 0; $v <= $yMax; $v += $step) {
            $yy = round($y($v), 1);
            $grid .= '<line class="grid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '"/>'
                . '<text class="axis" x="' . ($L - 8) . '" y="' . ($yy + 4) . '" text-anchor="end">' . $v . '</text>';
        }
        $bars = '';
        $total = 0;
        foreach ($days as $i => $d) {
            $total += $d['count'];
            $cx = $L + $band * $i + $band / 2;
            $isToday = $i === $n - 1;
            $label = esc_attr($d['label'] . ($isToday ? ' (today)' : '') . ': ' . $d['count'] . ' ' . ($d['count'] === 1 ? 'release' : 'releases'));
            $bar = '';
            if ($d['count'] > 0) {
                $top = $y($d['count']);
                $bar = '<path class="bar' . ($isToday ? ' today' : '') . '" d="' . self::roundedTop($cx - $bw / 2, $top, $bw, $y(0) - $top, 4) . '"/>'
                    . '<text class="val" x="' . round($cx, 1) . '" y="' . round($top - 6, 1) . '" text-anchor="middle">' . $d['count'] . '</text>';
            }
            // Hit target spans the whole column, not just the bar.
            $bars .= '<g class="bar-g" tabindex="0" role="img" aria-label="' . $label . '"><title>' . $label . '</title>'
                . '<rect x="' . round($L + $band * $i, 1) . '" y="' . $T . '" width="' . round($band, 1) . '" height="' . $ph . '" fill="transparent"/>' . $bar . '</g>';
            if ($i % 2 === ($n - 1) % 2) {
                $bars .= '<text class="axis" x="' . round($cx, 1) . '" y="' . ($H - 10) . '" text-anchor="middle"' . ($isToday ? ' font-weight="700"' : '') . '>'
                    . esc_html($isToday ? 'Today' : $d['label']) . '</text>';
            }
        }
        $rows = '';
        foreach (array_reverse($days) as $d) {
            $rows .= '<tr><td>' . esc_html($d['label']) . '</td><td class="num">' . $d['count'] . '</td></tr>';
        }
        return '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="group" aria-label="Releases per day for the last 14 days">' . $grid . $bars . '</svg>'
            . '<p class="chart-note">' . $total . ' ' . ($total === 1 ? 'release' : 'releases') . ' in 14 days. Today is in yellow.</p>'
            . '<details class="table-view"><summary>Show as table</summary><table class="tbl"><thead><tr><th>Day</th><th class="num">Releases</th></tr></thead><tbody>' . $rows . '</tbody></table></details>';
    }

    /** @param list<array{program:string, approved:int, released:int, waiting:int}> $rows */
    public static function progress(array $rows): string
    {
        if ($rows === []) {
            return '<p class="empty">No published programs yet.</p>';
        }
        $out = '';
        foreach ($rows as $r) {
            $pct = $r['approved'] > 0 ? round($r['released'] / $r['approved'] * 100, 1) : 0;
            $text = $r['approved'] === 0 ? 'No approvals yet' : $r['released'] . ' of ' . $r['approved'] . ' released';
            $out .= '<li><p class="line"><span class="strong">' . esc_html($r['program']) . '</span><span>' . esc_html($text) . '</span></p>'
                . '<div class="track" aria-hidden="true"><div class="fill" style="width:' . $pct . '%"></div></div>'
                . ($r['waiting'] > 0 ? '<p class="sub">' . $r['waiting'] . ' approved and waiting for release</p>' : '') . '</li>';
        }
        return '<ul class="prog">' . $out . '</ul>';
    }

    /** Bar with rounded top corners and a square base on the baseline. */
    private static function roundedTop(float $x, float $y, float $w, float $h, float $r): string
    {
        $r = min($r, $h, $w / 2);
        $f = static fn(float $v): string => (string) round($v, 1);
        return 'M' . $f($x) . ',' . $f($y + $h) . 'V' . $f($y + $r) . 'Q' . $f($x) . ',' . $f($y) . ' ' . $f($x + $r) . ',' . $f($y)
            . 'H' . $f($x + $w - $r) . 'Q' . $f($x + $w) . ',' . $f($y) . ' ' . $f($x + $w) . ',' . $f($y + $r) . 'V' . $f($y + $h) . 'Z';
    }

    private static function niceStep(float $raw): int
    {
        if ($raw <= 1) {
            return 1;
        }
        $mag = 10 ** (int) floor(log10($raw));
        foreach ([1, 2, 5, 10] as $m) {
            if ($raw <= $m * $mag) {
                return (int) ($m * $mag);
            }
        }
        return (int) (10 * $mag);
    }
}
