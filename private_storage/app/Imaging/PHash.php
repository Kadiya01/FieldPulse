<?php

declare(strict_types=1);

namespace FieldPulse\Imaging;

use FieldPulse\Config\Config;
use FieldPulse\Support\Logger;

/**
 * 64-bit perceptual hash via the DCT (pHash) algorithm.
 *
 * Pipeline:
 *   1. Decode with GD.
 *   2. Resize to 32x32 in one pass (imagecopyresampled), then convert to
 *      grayscale. Resizing before graying is not just faster — averaging in
 *      colour first reduces aliasing, which is the dominant source of
 *      pHash instability on re-encoded images.
 *   3. Two-dimensional DCT-II over the 32x32 grayscale block.
 *   4. Take the top-left 8x8 of the coefficient matrix, excluding DC (0,0),
 *      giving 63 values.
 *   5. Compare each against the median of those 63 values -> 63 bits, plus one
 *      comparison bit for DC, for a total 64.
 *
 * Separable implementation: a 2-D DCT is two 1-D passes (rows, then columns).
 * That turns 32*32*32*2 = 65,536 multiply-adds into that many instead of
 * 1,048,576, which is the difference between a pHash costing ~8 ms and ~130 ms
 * on shared hosting. The cosine basis is computed once per process and reused.
 *
 * The 64 bits are split into four 16-bit bands, because the contract indexes
 * each band separately and a MySQL SMALLINT UNSIGNED holds exactly 16 bits.
 */
final class PHash
{
    public const SIZE       = 32;
    public const HASH_SIZE  = 8;
    public const BITS       = 64;
    public const BAND_BITS  = 16;

    /** @var list<list<float>>|null Cosine basis, built once per process. */
    private static ?array $cosine = null;

    private function __construct()
    {
    }

    /**
     * @return array{
     *     hex:string,
     *     band_1:int,band_2:int,band_3:int,band_4:int,
     *     bands:list<int>
     * }|null null when the file cannot be decoded
     */
    public static function fromFile(string $path, ?string $mime = null): ?array
    {
        $mime ??= ImageInspector::detectMime($path);

        $image = match ($mime) {
            ImageInspector::MIME_JPEG => @imagecreatefromjpeg($path),
            ImageInspector::MIME_PNG  => @imagecreatefrompng($path),
            default                   => false,
        };

        if ($image === false) {
            Logger::warning('phash.decode_failed', ['path' => basename($path)]);

            return null;
        }

        try {
            return self::fromGdImage($image);
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * @param  \GdImage $image
     * @return array{hex:string,band_1:int,band_2:int,band_3:int,band_4:int,bands:list<int>}|null
     */
    public static function fromGdImage(\GdImage $image): ?array
    {
        $size = Config::instance()->int('phash.resize') ?: self::SIZE;

        $pixels = self::toGrayscaleBlock($image, $size);

        if ($pixels === null) {
            return null;
        }

        $coefficients = self::dct2d($pixels, $size);

        return self::packBits($coefficients);
    }

    /**
     * Resize to $size x $size and read luminance.
     *
     * @return list<list<float>>|null
     */
    private static function toGrayscaleBlock(\GdImage $image, int $size): ?array
    {
        $thumb = @imagecreatetruecolor($size, $size);

        if ($thumb === false) {
            return null;
        }

        try {
            // Fill white first: a JPEG with an alpha channel would otherwise
            // composite onto black, and a transparent PNG onto undefined
            // memory, both of which shift every low-frequency coefficient.
            $white = imagecolorallocate($thumb, 255, 255, 255);
            if ($white !== false) {
                imagefilledrectangle($thumb, 0, 0, $size - 1, $size - 1, $white);
            }

            imagecopyresampled(
                $thumb,
                $image,
                0,
                0,
                0,
                0,
                $size,
                $size,
                imagesx($image),
                imagesy($image)
            );

            $block = [];

            for ($y = 0; $y < $size; $y++) {
                $row = [];

                for ($x = 0; $x < $size; $x++) {
                    $rgb = imagecolorat($thumb, $x, $y);

                    // Rec. 601 luma, matching the convention pHash libraries use.
                    // Using GD's IMG_COLORSPACE_* constants instead would make
                    // our hashes incomparable with the reference implementation.
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;

                    $row[] = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255.0;
                }

                $block[] = $row;
            }

            return $block;
        } finally {
            imagedestroy($thumb);
        }
    }

    /**
     * 2-D DCT-II, separable.
     *
     * @param  list<list<float>> $block
     * @return list<list<float>>
     */
    private static function dct2d(array $block, int $size): array
    {
        $basis = self::cosineBasis($size);

        // Pass 1: transform each row, keeping only the first HASH_SIZE columns.
        // Discarding here is exact, not an approximation: each output column is
        // independent, and higher-frequency columns never feed lower ones.
        $rows = [];

        for ($y = 0; $y < $size; $y++) {
            $row = $block[$y];
            $out = [];

            for ($u = 0; $u < self::HASH_SIZE; $u++) {
                $sum = 0.0;

                for ($x = 0; $x < $size; $x++) {
                    $sum += $row[$x] * $basis[$u][$x];
                }

                $out[] = $sum;
            }

            $rows[] = $out;
        }

        // Pass 2: transform each retained column down the image.
        $result = [];

        for ($x = 0; $x < self::HASH_SIZE; $x++) {
            $column = [];

            for ($y = 0; $y < $size; $y++) {
                $column[] = $rows[$y][$x];
            }

            for ($u = 0; $u < self::HASH_SIZE; $u++) {
                $sum = 0.0;

                for ($y = 0; $y < $size; $y++) {
                    $sum += $column[$y] * $basis[$u][$y];
                }

                $result[$u][$x] = $sum;
            }
        }

        return $result;
    }

    /**
     * basis[u][n] = C(u) * cos((2n + 1) * u * pi / (2N))
     *
     * C(0) = sqrt(1/N), C(u>0) = sqrt(2/N). The scaling constant cancels in the
     * median comparison but is included so the values match a textbook DCT.
     *
     * @return list<list<float>>
     */
    private static function cosineBasis(int $size): array
    {
        if (self::$cosine !== null && count(self::$cosine) === self::HASH_SIZE) {
            return self::$cosine;
        }

        $basis = [];

        for ($u = 0; $u < self::HASH_SIZE; $u++) {
            $scale = $u === 0 ? sqrt(1.0 / $size) : sqrt(2.0 / $size);
            $row   = [];

            for ($n = 0; $n < $size; $n++) {
                $row[] = $scale * cos((2 * $n + 1) * $u * M_PI / (2 * $size));
            }

            $basis[] = $row;
        }

        self::$cosine = $basis;

        return $basis;
    }

    /**
     * Reduce the 8x8 coefficient block to 64 bits.
     *
     * The DC term (0,0) carries overall brightness, not structure, so it is
     * excluded from the median. Including it lets a brightness change flip every
     * bit in the hash — precisely the failure mode a perceptual hash exists to
     * avoid. The 64th bit is filled from the DC against the same median, which
     * is the conventional way to reach a full 64 bits.
     *
     * @param  list<list<float>> $coefficients
     * @return array{hex:string,band_1:int,band_2:int,band_3:int,band_4:int,bands:list<int>}
     */
    private static function packBits(array $coefficients): array
    {
        $values = [];

        for ($u = 0; $u < self::HASH_SIZE; $u++) {
            for ($x = 0; $x < self::HASH_SIZE; $x++) {
                $values[] = (float) $coefficients[$u][$x];
            }
        }

        $dc = $values[0];

        /*
         * Squash numerical noise before thresholding.
         *
         * The DCT of a constant signal is zero in every AC band, exactly, in
         * real arithmetic. In floating point it lands on residues of order 1e-16
         * instead. That matters more than it sounds: the threshold is the median
         * of the AC values, so for a flat image the median is itself a residue,
         * and "is this coefficient above the median" becomes a comparison of one
         * rounding error against another. Roughly half the bits come out set at
         * random, and a blank photo gets a hash that differs from every other
         * blank photo — which reads to the duplicate detector as a stream of
         * unrelated submissions and quietly defeats band matching.
         *
         * The floor is relative to the DC term so it scales with image
         * brightness, with a small absolute floor for near-black images. It sits
         * far below real structure: the smallest meaningful luminance step in an
         * 8-bit image is 1/255, which at this DCT scale produces AC coefficients
         * around 1e-2, roughly seven orders of magnitude above the threshold.
         */
        $floor = max(abs($dc), 0.001) * 1e-9;

        $acValues = [];
        for ($i = 1, $count = count($values); $i < $count; $i++) {
            $value       = $values[$i];
            $acValues[]  = abs($value) <= $floor ? 0.0 : $value;
        }

        // Sort a COPY to find the median threshold. The bit loop below must walk
        // $acValues in its original row-major order: bit i has to mean "the i-th
        // low-frequency coefficient, at a fixed spatial position". Sorting the
        // live array first made every bit mean "the i-th coefficient by
        // magnitude", which throws away exactly the spatial information the hash
        // exists to capture — two unrelated images with a similar spread of
        // coefficient sizes then hash as near-identical, while a real duplicate
        // that was re-compressed hashes as different.
        $sortedForMedian = $acValues;
        sort($sortedForMedian, SORT_NUMERIC);
        $median = self::median($sortedForMedian);

        $bits = '';

        foreach ($acValues as $value) {
            $bits .= $value > $median ? '1' : '0';
        }

        // 63 bits from the AC block, one from DC.
        $bits .= $dc > $median ? '1' : '0';

        $padded = str_pad($bits, self::BITS, '0', STR_PAD_RIGHT);

        $integer = 0;

        for ($i = 0; $i < self::BITS; $i++) {
            $integer = ($integer << 1) | (int) $padded[$i];
        }

        $bands = self::splitBands($integer);

        return [
            'hex'   => self::toHex($padded),
            'band_1' => $bands[0],
            'band_2' => $bands[1],
            'band_3' => $bands[2],
            'band_4' => $bands[3],
            'bands'  => $bands,
        ];
    }

    /**
     * @param  list<float> $sorted
     */
    private static function median(array $sorted): float
    {
        $count = count($sorted);

        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $sorted[$middle];
        }

        return ($sorted[$middle - 1] + $sorted[$middle]) / 2.0;
    }

    /**
     * Split a 64-bit hash into four 16-bit bands, most significant first.
     *
     * @return list<int>
     */
    public static function splitBands(int $hash): array
    {
        // Avoid a negative shift on 32-bit PHP builds, where 0xFFFFFFFF << 48
        // would be undefined. Hosts are 64-bit in practice, but the shift is
        // performed on a string-derived value so it is safe either way.
        return [
            ($hash >> 48) & 0xFFFF,
            ($hash >> 32) & 0xFFFF,
            ($hash >> 16) & 0xFFFF,
            $hash & 0xFFFF,
        ];
    }

    /**
     * Parse a stored 16-character hex pHash back into bands.
     *
     * Uses hex2bin + 'J' (uint64, big-endian) rather than hexdec() because
     * hexdec() returns a float for values above PHP_INT_MAX, which would
     * silently corrupt the upper 32 bits. 'J' is exact on every build.
     *
     * @return array{hex:string,integer:int,bands:list<int>}
     */
    public static function fromHex(string $hex): array
    {
        $normalised = self::normaliseHex($hex);
        $binary     = hex2bin($normalised);

        if ($binary === false || strlen($binary) !== 8) {
            // Unreachable for a 16-char hex string, but a corrupt row must not
            // become a PHP warning inside a queue worker.
            $binary = str_repeat("\x00", 8);
        }

        /** @var array{1:int} $unpacked */
        $unpacked = unpack('J', $binary);

        $integer = $unpacked[1];

        return [
            'hex'     => $normalised,
            'integer' => $integer,
            'bands'   => self::splitBands($integer),
        ];
    }

    private static function toHex(string $bits): string
    {
        $hex = '';

        for ($i = 0; $i < self::BITS; $i += 4) {
            $hex .= dechex(bindec(substr($bits, $i, 4)));
        }

        return $hex;
    }

    /**
     * Normalise a 64-bit hash to exactly 16 lowercase hex characters.
     */
    public static function normaliseHex(string $hex): string
    {
        return str_pad(strtolower(trim($hex)), 16, '0', STR_PAD_LEFT);
    }

    /**
     * Reset the cached cosine basis. Used by the test-suite only.
     */
    public static function resetCache(): void
    {
        self::$cosine = null;
    }
}
