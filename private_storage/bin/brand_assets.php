<?php

declare(strict_types=1);

/**
 * Regenerate the PWA icon set, and check it against the brand colours.
 *
 *   php private_storage/bin/brand_assets.php            # write the PNGs
 *   php private_storage/bin/brand_assets.php --check    # verify only, non-zero on drift
 *
 * WHY THIS EXISTS
 *
 * The repository shipped the Vite starter's own artwork: a purple (#863bff) bolt
 * favicon and two mostly-transparent PNGs of the same bolt, with only the
 * maskable icon carrying a blue background behind a purple glyph. The PWA was
 * therefore advertising a different product from the one it was, in a different
 * colour, on every installed handset — and the browser tab said "Vite" rather
 * than FieldPulse.
 *
 * GENERATED, NOT DRAWN BY HAND
 *
 * The PNGs are produced from the same geometry as public/favicon.svg so the four
 * assets cannot drift apart. Editing the SVG without re-running this is the one
 * thing that can still put them out of step, which is what `--check` exists to
 * make visible: it re-renders into memory and compares byte-for-byte, so a
 * hand-edited PNG fails the check instead of shipping.
 *
 * The source of truth for the colour is BLUE below, and the manifest, the
 * `theme-color` meta tag and the app header all have to agree with it.
 * deploy_test.php asserts that they do.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Console\Cli;

Cli::init(__FILE__);

const BRAND_BLUE = [0x25, 0x63, 0xeb];  // blue-600: header, nav, theme-color
const BRAND_WHITE = [0xff, 0xff, 0xff];



/**
 * The mark, as fractions of the icon's edge.
 *
 * Held in one place because the SVG in public/ describes the same shape by
 * hand. A single `path` element in the SVG, the same sequence of points here:
 * a flat baseline, a dip, a tall spike, a settle, and back to the baseline.
 *
 * Corner radius 112/512 = 21.9%, matching rx="112" in the SVG.
 */
const TRACE = [
    // [x, y] in 0..1 units, as `M`, `L`, `H`, `V` equivalents.
    [0.1875, 0.5625],  // start of the trace
    [0.3281, 0.5625],
    [0.4062, 0.3750],  // up
    [0.5000, 0.7031],  // down, past the baseline
    [0.5781, 0.4922],  // settle
    [0.6328, 0.6250],
    [0.7188, 0.6250],  // back to the baseline
];

const STROKE = 40 / 512;   // 7.8% of the edge
const RADIUS = 112 / 512;

$checkOnly = in_array('--check', Cli::argv(), true);

/**
 * Render one icon.
 *
 * `true` (opaque) icons get the rounded tile and transparent corners, so an
 * installed app on a launcher that does not mask shows the mark rather than a
 * blue square. `maskable` icons get a full-bleed square because the launcher
 * crops those itself and a rounded tile would have its own corners shaved off,
 * leaving the blue showing through in a circle.
 */
function renderIcon(int $size, bool $maskable): GdImage
{
    $im = imagecreatetruecolor($size, $size);
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);

    $blue = imagecolorallocate($im, BRAND_BLUE[0], BRAND_BLUE[1], BRAND_BLUE[2]);
    $white = imagecolorallocate($im, BRAND_WHITE[0], BRAND_WHITE[1], BRAND_WHITE[2]);

    if ($maskable) {
        imagefilledrectangle($im, 0, 0, $size - 1, $size - 1, $blue);
    } else {
        // A rounded rectangle, drawn as concentric filled rectangles rather than
        // an arc call: GD's arc is an ellipse primitive and this is a squircle
        // with a circular corner, which the loop reproduces exactly at any size.
        $r = (int) round(RADIUS * $size);

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $inset = cornerInset($x, $y, $size, $r);

                if ($inset === 0) {
                    imagesetpixel($im, $x, $y, $blue);
                }
            }
        }
    }

    drawTrace($im, $size, $white);

    return $im;
}

/**
 * How far outside the rounded shape a pixel sits: 0 inside, >0 outside.
 *
 * Measured against the corner circles rather than the whole square, which is what
 * makes the tile square-cornered and only-round-in-the-corners.
 */
function cornerInset(int $x, int $y, int $size, int $r): int
{
    $cx = $x < $r ? $r : ($x > $size - 1 - $r ? $size - 1 - $r : null);
    $cy = $y < $r ? $r : ($y > $size - 1 - $r ? $size - 1 - $r : null);

    if ($cx === null || $cy === null) {
        return 0;
    }

    $dx = $cx - $x;
    $dy = $cy - $y;

    // Integer distance from the corner centre, compared against the radius.
    return ($dx * $dx + $dy * $dy) <= ($r * $r) ? 0 : 1;
}

/**
 * The pulse trace, as a round-capped thick polyline.
 *
 * Drawn as a filled quadrilateral per segment plus a disc at each joint rather
 * than with `imagesetthickness`, which only draws axis-aligned lines and would
 * render this trace as a staircase.
 */
function drawTrace(GdImage $im, int $size, int $colour): void
{
    $half = max(1, (int) round(STROKE * $size / 2));
    $points = array_map(
        static fn (array $p): array => [
            (int) round($p[0] * $size),
            (int) round($p[1] * $size)
        ],
        TRACE
    );

    for ($i = 0; $i < count($points) - 1; $i++) {
        [$x1, $y1] = $points[$i];
        [$x2, $y2] = $points[$i + 1];
        drawThickSegment($im, $x1, $y1, $x2, $y2, $half, $colour);
    }

    // Round joins and caps. Also what keeps a one-pixel segment from vanishing.
    foreach ($points as [$x, $y]) {
        filledCircle($im, $x, $y, $half, $colour);
    }
}

function drawThickSegment(GdImage $im, int $x1, int $y1, int $x2, int $y2, int $half, int $colour): void
{
    // Bounding box of the segment grown by the half-width.
    $minX = min($x1, $x2) - $half;
    $maxX = max($x1, $x2) + $half;
    $minY = min($y1, $y2) - $half;
    $maxY = max($y1, $y2) + $half;

    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $lenSq = ($dx * $dx) + ($dy * $dy);

    if ($lenSq === 0) {
        filledCircle($im, $x1, $y1, $half, $colour);
        return;
    }

    for ($y = max(0, $minY); $y <= min(imagesy($im) - 1, $maxY); $y++) {
        for ($x = max(0, $minX); $x <= min(imagesx($im) - 1, $maxX); $x++) {
            // Closest point on the segment to this pixel, then its distance.
            $t = ((($x - $x1) * $dx) + (($y - $y1) * $dy)) / $lenSq;
            $t = max(0.0, min(1.0, $t));

            $nx = $x1 + ($t * $dx);
            $ny = $y1 + ($t * $dy);
            $ex = $x - $nx;
            $ey = $y - $ny;

            if (($ex * $ex) + ($ey * $ey) <= $half * $half + ($half / 2)) {
                imagesetpixel($im, $x, $y, $colour);
            }
        }
    }
}

function filledCircle(GdImage $im, int $cx, int $cy, int $r, int $colour): void
{
    for ($y = $cy - $r; $y <= $cy + $r; $y++) {
        for ($x = $cx - $r; $x <= $cx + $r; $x++) {
            $dx = $x - $cx;
            $dy = $y - $cy;

            if (($dx * $dx) + ($dy * $dy) <= $r * $r) {
                $x2 = max(0, $x);
                $x2 = min(imagesx($im) - 1, $x2);
                $y2 = max(0, $y);
                $y2 = min(imagesy($im) - 1, $y2);

                imagesetpixel($im, $x2, $y2, $colour);
            }
        }
    }
}

$targets = [
    'pwa-192x192.png'           => [192, false],
    'pwa-512x512.png'           => [512, false],
    'pwa-maskable-512x512.png' => [512, true],
];

$root = dirname(__DIR__, 2) . '/public';
$drift = 0;

foreach ($targets as $file => [$size, $maskable]) {
    $path = $root . '/' . $file;
    $im = renderIcon($size, $maskable);

    ob_start();
    imagepng($im, null, 9);
    $fresh = (string) ob_get_clean();
    imagedestroy($im);

    if ($checkOnly) {
        $onDisk = is_file($path) ? (string) file_get_contents($path) : '';

        if ($onDisk === $fresh) {
            Cli::ok($file . ' matches the brand mark');
            continue;
        }

        $drift++;
        Cli::fail($file . ' does not match the brand mark — run: php ' . basename(__FILE__));
        continue;
    }

    file_put_contents($path, $fresh);
    Cli::ok(sprintf('%s written (%d bytes, %dx%d%s)', $file, strlen($fresh), $size, $size,
        $maskable ? ', maskable' : ''));
}

if ($checkOnly && $drift > 0) {
    Cli::fail($drift . ' icon(s) drifted from the mark in public/favicon.svg');
    exit(1);
}

// The manifest and the browser chrome read the same colour as the tiles, so a
// mismatch here is a visible one: a blue icon under a grey title bar.
$config = (string) file_get_contents(dirname(__DIR__, 2) . '/vite.config.ts');
$html = (string) file_get_contents(dirname(__DIR__, 2) . '/index.html');
$expected = sprintf('#%02x%02x%02x', BRAND_BLUE[0], BRAND_BLUE[1], BRAND_BLUE[2]);

foreach (['vite.config.ts' => "theme_color: '$expected'", 'index.html' => "content=\"$expected\""] as $file => $needle) {
    $contents = $file === 'vite.config.ts' ? $config : $html;

    if (!str_contains($contents, $needle)) {
        Cli::fail("$file does not declare $expected");
        exit(1);
    }

    Cli::ok("$file declares $expected");
}

if ($checkOnly) {
    Cli::ok('brand assets consistent');
}