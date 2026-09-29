<?php

declare(strict_types=1);

namespace FieldPulse\Imaging;

/**
 * Hamming distance over 64-bit pHashes.
 *
 * Implementation note, because the obvious approach does not work: PHP has no
 * `popcount()` function. `gmp_popcount()` exists but only when the GMP
 * extension is loaded, which a basic cPanel plan does not guarantee. What is
 * always available is 64-bit integer arithmetic, so a hash whose top bit is set
 * arrives here as a NEGATIVE int, and any hand-rolled shift-and-mask loop has to
 * cope with that.
 *
 * This counts through the binary string instead. It is a 64-character operation
 * on a value called at most a few hundred times per verification job, so the
 * microseconds do not matter, and it is correct by inspection rather than by
 * careful reasoning about sign extension — which is exactly where the previous
 * version went wrong, masking to 60 bits and silently dropping the top nibble.
 */
final class Hamming
{
    private function __construct()
    {
    }

    /**
     * Distance between two 16-character hex hashes.
     */
    public static function betweenHex(string $a, string $b): int
    {
        if (hash_equals(strtolower(trim($a)), strtolower(trim($b)))) {
            return 0;
        }

        $left  = PHash::fromHex($a)['integer'];
        $right = PHash::fromHex($b)['integer'];

        return self::betweenInts($left, $right);
    }

    public static function betweenInts(int $a, int $b): int
    {
        if ($a === $b) {
            return 0;
        }

        return self::popcount($a ^ $b);
    }

    /**
     * Number of set bits in a 64-bit value, including the sign bit.
     *
     * A 64-bit hash with the top bit set is a negative int in PHP, and decbin()
     * of a negative int reports the magnitude rather than the 64-bit pattern.
     * So count the low 63 bits from the masked value and add the sign bit back
     * separately — that is exactly one bit, not a whole sign extension:
     *
     *   -1  (0xFFFFFFFFFFFFFFFF) -> 63 low bits + 1 = 64
     *   PHP_INT_MIN (0x8000...)  ->  0 low bits + 1 =  1
     *
     * Getting this wrong is invisible on low-bit hashes, which is why the
     * self-test compares against a hex-nibble reference that shares none of
     * this code.
     */
    public static function popcount(int $value): int
    {
        if ($value === 0) {
            return 0;
        }

        $count = substr_count(decbin($value & PHP_INT_MAX), '1');

        return $value < 0 ? $count + 1 : $count;
    }

    /**
     * Nearest candidate to a target hash, given a scored candidate list.
     *
     * @param  list<array{id:int,phash_hex:string,distance?:int}> $candidates
     * @return array{id:int,distance:int}|null
     */
    public static function nearest(string $targetHex, array $candidates): ?array
    {
        $best      = null;
        $bestScore = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $hex = (string) ($candidate['phash_hex'] ?? '');

            if ($hex === '') {
                continue;
            }

            $distance = self::betweenHex($targetHex, $hex);

            if ($distance < $bestScore) {
                $bestScore = $distance;
                $best      = ['id' => (int) $candidate['id'], 'distance' => $distance];
            }
        }

        return $best;
    }

    public static function max(): int
    {
        return PHash::BITS;
    }
}
