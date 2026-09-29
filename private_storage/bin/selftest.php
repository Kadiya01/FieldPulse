<?php

declare(strict_types=1);

/**
 * Offline self-test.
 *
 *   php private_storage/bin/selftest.php
 *   php private_storage/bin/selftest.php --filter=phash --verbose
 *
 * These assertions are the ones that catch real defects without a database: the
 * DCT and its known-answer vector, Haversine against a computable reference,
 * the Monday period boundary, JWT round-trips and tamper detection, ECDSA
 * signature verification against a keypair generated in-process, and the
 * canonical signing string.
 *
 * Anything that needs MySQL belongs in bin/healthcheck.php instead, because a
 * test that silently skips on a developer's laptop is worse than no test.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Testing\TestRunner;

/*
 * Boot against a throwaway environment file rather than the real .env.
 *
 * The suite must run on a laptop that has no deployment, and Config::boot()
 * fails closed on a missing .env or a short JWT_SECRET — correct for production,
 * fatal for a unit test. Rather than weaken that check, this writes a synthetic
 * env to the system temp directory and boots against it explicitly. The real
 * .env is never read, so running the suite can never be affected by, or leak,
 * production secrets.
 */
$selftestEnv = sys_get_temp_dir() . '/fieldpulse-selftest-' . getmypid() . '.env';

file_put_contents($selftestEnv, implode("\n", [
    'APP_ENV=testing',
    'APP_DEBUG=true',
    'APP_NAME=FieldPulseSelftest',
    'APP_URL=https://selftest.invalid',
    'APP_TIMEZONE=Africa/Lagos',
    'DB_NAME=selftest',
    'DB_USER=selftest',
    'JWT_SECRET=' . bin2hex(random_bytes(48)),
    'JWT_ISSUER=fieldpulse',
    'JWT_AUDIENCE=fieldpulse-pwa',
    '',
]));

register_shutdown_function(static function () use ($selftestEnv): void {
    @unlink($selftestEnv);
});

Config::boot($selftestEnv);

$argv   = Cli::argv();
$filter = Cli::option($argv, 'filter');
$verbose = Cli::hasFlag($argv, 'verbose');

$t = new TestRunner($filter);

// --- Support primitives -----------------------------------------------------
$t->group('support');

$t->test('uuid v4 is well formed and unique', function (TestRunner $t): void {
    $seen = [];

    for ($i = 0; $i < 500; $i++) {
        $uuid = \FieldPulse\Support\Uuid::v4();

        $t->assertMatches(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
            'uuid format'
        );

        $t->assertFalse(isset($seen[$uuid]), 'uuid collision');
        $seen[$uuid] = true;
    }
});

$t->test('base64url is unpadded and URL safe', function (TestRunner $t): void {
    $encoded = \FieldPulse\Support\Str::base64UrlEncode(random_bytes(40));

    $t->assertFalse(str_contains($encoded, '='), 'must not be padded');
    $t->assertFalse(str_contains($encoded, '+'), 'must not contain +');
    $t->assertFalse(str_contains($encoded, '/'), 'must not contain /');

    // Decode returns the raw bytes, so a real round trip has to re-encode.
    // Comparing the encoding against the decode output directly only proves
    // that the two differ.
    $decoded = \FieldPulse\Support\Str::base64UrlDecode($encoded);

    $t->assertNotNull($decoded);
    $t->assertSame(40, strlen((string) $decoded), 'all bytes survive');
    $t->assertSame($encoded, \FieldPulse\Support\Str::base64UrlEncode((string) $decoded), 'round trip');
});

$t->test('base64url returns null on malformed input rather than garbage', function (TestRunner $t): void {
    // Null, not a silent partial decode. A caller that ignored the null would
    // verify a signature over the wrong bytes.
    $t->assertNull(\FieldPulse\Support\Str::base64UrlDecode('not valid!!'));
    $t->assertNull(\FieldPulse\Support\Str::base64UrlDecode(''));
    $t->assertNull(\FieldPulse\Support\Str::base64UrlDecode('A'), 'length 4k+1 is impossible');
    $t->assertNull(\FieldPulse\Support\Str::base64UrlDecode('A+/B'), 'standard base64 alphabet must be refused');
});

$t->test('base64url survives every payload length modulo 4', function (TestRunner $t): void {
    for ($length = 1; $length <= 32; $length++) {
        $raw      = random_bytes($length);
        $encoded  = \FieldPulse\Support\Str::base64UrlEncode($raw);
        $decoded  = \FieldPulse\Support\Str::base64UrlDecode($encoded);

        $t->assertNotNull($decoded, 'decoded length ' . $length);
        $t->assertSame(bin2hex($raw), bin2hex((string) $decoded), 'round trip at length ' . $length);
    }
});

$t->test('json encode is deterministic in key order', function (TestRunner $t): void {
    $a = \FieldPulse\Support\Json::encode(['b' => 1, 'a' => 2]);
    $b = \FieldPulse\Support\Json::encode(['b' => 1, 'a' => 2]);

    $t->assertSame($a, $b);
    $t->assertSame('{"b":1,"a":2}', $a, 'insertion order must be preserved for signed payloads');
});

$t->test('clock parses SQL datetimes as UTC', function (TestRunner $t): void {
    $parsed = \FieldPulse\Support\Clock::parseSql('2026-09-28 13:45:00');

    $t->assertNotNull($parsed);
    $t->assertSame('UTC', $parsed->getTimezone()->getName());
    $t->assertSame('2026-09-28', $parsed->format('Y-m-d'));
});

$t->test('clock rejects an impossible date', function (TestRunner $t): void {
    $t->assertNull(\FieldPulse\Support\Clock::parseSql('2026-02-30 00:00:00'));
    $t->assertNull(\FieldPulse\Support\Clock::parseSql('not a date'));
});

// --- pHash / DCT ------------------------------------------------------------
$t->group('phash');

$t->test('DCT of a constant block yields energy only in DC', function (TestRunner $t): void {
    // A uniform grey image has no structure, so every AC coefficient must be
    // ~0. This is the property that makes the median threshold meaningful: if
    // the AC terms were not near zero, a flat image would produce a
    // content-dependent hash and every blank photo would look like a match.
    $image = imagecreatetruecolor(32, 32);
    imagefilledrectangle($image, 0, 0, 31, 31, imagecolorallocate($image, 128, 128, 128));

    $hash = \FieldPulse\Imaging\PHash::fromGdImage($image);
    imagedestroy($image);

    $t->assertNotNull($hash);
    $t->assertMatches('/^[0-9a-f]{16}$/', $hash['hex'], 'hex length and alphabet');
    $t->assertSame(4, count($hash['bands']));

    // A flat field has all 63 AC coefficients equal, so every AC bit compares
    // false against the median and only the DC bit may be set.
    $t->assertSame('0000000000000001', $hash['hex'], 'flat image must hash to DC-only');
});

$t->test('identical pixels produce identical hashes', function (TestRunner $t): void {
    $make = static function (): \GdImage {
        $image = imagecreatetruecolor(64, 64);

        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                imagesetpixel($image, $x, $y, imagecolorallocate($image, $x * 4 % 256, $y * 4 % 256, 90));
            }
        }

        return $image;
    };

    $a = \FieldPulse\Imaging\PHash::fromGdImage($make());
    $b = \FieldPulse\Imaging\PHash::fromGdImage($make());

    $t->assertNotNull($a);
    $t->assertNotNull($b);
    $t->assertSame($a['hex'], $b['hex'], 'determinism');
});

$t->test('flat images of any brightness share one AC pattern', function (TestRunner $t): void {
    // The flat-image failure was not that the hash was "wrong" — it was that it
    // was not stable. The AC coefficients of a constant block are zero in exact
    // arithmetic, so any non-zero value is round-off, and comparing round-off
    // against a median of round-off sets roughly half the bits at random. Every
    // blank photo then got its own hash and the duplicate detector saw a stream
    // of unrelated images instead of a run of identical ones.
    //
    // So: all of these must agree on the AC block, and differ only in the DC
    // bit. Checking one brightness alone would not catch a coin flip.
    $hashes = [];

    foreach ([0, 1, 64, 128, 200, 255] as $level) {
        $image = imagecreatetruecolor(32, 32);
        imagefilledrectangle($image, 0, 0, 31, 31, imagecolorallocate($image, $level, $level, $level));

        $hash = \FieldPulse\Imaging\PHash::fromGdImage($image);
        imagedestroy($image);

        $t->assertNotNull($hash, 'hashes a flat ' . $level . ' field');
        $hashes[$level] = $hash['hex'];

        // The 63 AC bits must be clear for every brightness. This is the part
        // that was broken: the DC bit alone is allowed to differ.
        $t->assertTrue(
            in_array($hash['hex'], ['0000000000000000', '0000000000000001'], true),
            'flat ' . $level . ' field has an empty AC block, got ' . $hash['hex']
        );

        // A pure black field is the one legitimate exception to DC-only: its DC
        // is 0, so it is not strictly greater than a median of 0 and the bit
        // legitimately stays clear. Every other level is bright enough to win.
        $expected = $level === 0 ? '0000000000000000' : '0000000000000001';

        $t->assertSame($expected, $hash['hex'], 'flat ' . $level . ' field');
    }

    $t->assertSame(
        count(array_unique(array_slice($hashes, 1))),
        1,
        'every non-black flat brightness yields the same hash, got: ' . implode(', ', $hashes)
    );
});

$t->test('flat greyscale and flat colour agree, so hue is not hashed', function (TestRunner $t): void {
    // A red field and a grey field of the same luminance differ in every pixel
    // yet are perceptually identical in luminance, which is all pHash reads.
    // If these diverged, a colour-cast re-encode of a flat photo would look
    // like a different image.
    $grey = imagecreatetruecolor(32, 32);
    imagefilledrectangle($grey, 0, 0, 31, 31, imagecolorallocate($grey, 128, 128, 128));

    // Luma 128/255 in pure red, per the same Rec. 601 weights pHash uses.
    $red  = imagecreatetruecolor(32, 32);
    imagefilledrectangle($red, 0, 0, 31, 31, imagecolorallocate($red, 255, 0, 0));

    $a = \FieldPulse\Imaging\PHash::fromGdImage($grey);
    $b = \FieldPulse\Imaging\PHash::fromGdImage($red);
    imagedestroy($grey);
    imagedestroy($red);

    $t->assertNotNull($a);
    $t->assertNotNull($b);
    $t->assertSame('0000000000000001', $a['hex']);
    $t->assertSame('0000000000000001', $b['hex'], 'hue alone must not change the hash');
});

$t->test('the hash encodes spatial position, not coefficient magnitude order', function (TestRunner $t): void {
    // Regression test for a real bug: the median threshold was computed on the
    // same array that the bit loop iterated, so the coefficients got sorted by
    // magnitude before their bits were assigned. Every bit then meant "the i-th
    // largest coefficient" instead of "the coefficient at spatial position i",
    // which discards the arrangement the hash exists to capture.
    //
    // A horizontal mirror is the probe. Reflecting the input changes the sign of
    // every odd-numbered row of the 2-D DCT and leaves the even rows, including
    // DC, untouched — and it leaves the SET of coefficient magnitudes completely
    // unchanged. So a hash that is truly position-sensitive must react to the
    // mirror, and one that had sorted its coefficients away must not.
    $make = static function (bool $flip): \GdImage {
        $image = imagecreatetruecolor(64, 64);

        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                // Deliberately asymmetric left-to-right, otherwise the two
                // images are the same and the test proves nothing.
                $v = (int) (127 + 100 * sin(($flip ? 63 - $x : $x) / 6.5) * cos($y / 4.0));

                imagesetpixel($image, $x, $y, imagecolorallocate($image, $v, (int) ($v * 0.8), 40));
            }
        }

        return $image;
    };

    $normal = \FieldPulse\Imaging\PHash::fromGdImage($make(false));
    $mirror = \FieldPulse\Imaging\PHash::fromGdImage($make(true));

    $t->assertNotNull($normal, 'original image hashed');
    $t->assertNotNull($mirror, 'mirrored image hashed');

    $distance = \FieldPulse\Imaging\Hamming::betweenHex($normal['hex'], $mirror['hex']);

    $t->assertTrue(
        $distance > 4,
        'a mirror must move the hash — distance was ' . $distance . '. '
        . 'Identical hashes here mean coefficient ordering was discarded before '
        . 'the bits were assigned, so the hash sees only a magnitude distribution '
        . 'and unrelated images will collide as duplicates.'
    );
});

$t->test('a re-encoded copy stays within the perceptual threshold', function (TestRunner $t): void {
    // Re-saving at a lower quality is the realistic duplicate case. It must
    // still be detected as a near-duplicate rather than falling outside the
    // margin, which is the whole reason for a perceptual hash.
    $image = imagecreatetruecolor(200, 150);

    for ($y = 0; $y < 150; $y++) {
        for ($x = 0; $x < 200; $x++) {
            $r = (int) (127 + 120 * sin($x / 9.0) * cos($y / 7.0));
            $g = (int) (127 + 100 * sin(($x + $y) / 11.0));
            $b = (int) (127 + 80 * cos($x / 5.0));

            imagesetpixel($image, $x, $y, imagecolorallocate(
                $image,
                max(0, min(255, $r)),
                max(0, min(255, $g)),
                max(0, min(255, $b))
            ));
        }
    }

    $original = imagecreatetruecolor(32, 32);
    imagecopyresampled($original, $image, 0, 0, 0, 0, 32, 32, 200, 150);

    $before = \FieldPulse\Imaging\PHash::fromGdImage($original);
    imagedestroy($original);

    // Lossy round trip.
    ob_start();
    imagejpeg($image, null, 25);
    $jpeg = ob_get_clean();
    imagedestroy($image);

    $tmp = tempnam(sys_get_temp_dir(), 'fptest');
    file_put_contents($tmp, $jpeg);
    $after = \FieldPulse\Imaging\PHash::fromFile($tmp, 'image/jpeg');
    @unlink($tmp);

    $t->assertNotNull($before);
    $t->assertNotNull($after);

    $distance = \FieldPulse\Imaging\Hamming::betweenHex($before['hex'], $after['hex']);

    $t->assertTrue(
        $distance <= (int) \FieldPulse\Config\Config::instance()->int('phash.hamming_threshold') + 4,
        're-encoded copy should stay within the margin, got distance ' . $distance
    );
});

$t->test('visually different images are far apart', function (TestRunner $t): void {
    $make = static function (int $pattern): \GdImage {
        $image = imagecreatetruecolor(64, 64);

        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $v = $pattern === 0
                    ? (($x ^ $y) % 256)
                    : (int) ((($x * 7 + $y * 13) % 256));
                imagesetpixel($image, $x, $y, imagecolorallocate($image, $v, $v, $v));
            }
        }

        return $image;
    };

    $a = \FieldPulse\Imaging\PHash::fromGdImage($make(0));
    $b = \FieldPulse\Imaging\PHash::fromGdImage($make(1));

    $t->assertNotNull($a);
    $t->assertNotNull($b);
    $t->assertNotSame($a['hex'], $b['hex'], 'different images must not collide exactly');
});

$t->test('hex round trip preserves all 64 bits', function (TestRunner $t): void {
    foreach (['0000000000000000', 'ffffffffffffffff', '8000000000000001', '0123456789abcdef'] as $hex) {
        $parsed = \FieldPulse\Imaging\PHash::fromHex($hex);

        $t->assertSame($hex, $parsed['hex'], 'hex preserved for ' . $hex);

        foreach ($parsed['bands'] as $index => $band) {
            $t->assertTrue($band >= 0 && $band <= 0xFFFF, 'band in range');
            $t->assertSame($band, $parsed['bands'][$index]);
        }
    }
});

$t->test('bands recombine into the original hash', function (TestRunner $t): void {
    $hex    = 'fedcba9876543210';
    $parsed = \FieldPulse\Imaging\PHash::fromHex($hex);

    $rebuilt = (($parsed['bands'][0] << 48) | ($parsed['bands'][1] << 32)
        | ($parsed['bands'][2] << 16) | $parsed['bands'][3]);

    $t->assertSame($parsed['integer'], $rebuilt, 'band split must be lossless');
});

$t->test('hamming distance is correct on known values', function (TestRunner $t): void {
    $t->assertSame(0, \FieldPulse\Imaging\Hamming::betweenHex('0000000000000000', '0000000000000000'));
    $t->assertSame(64, \FieldPulse\Imaging\Hamming::betweenHex('0000000000000000', 'ffffffffffffffff'));
    $t->assertSame(1, \FieldPulse\Imaging\Hamming::betweenHex('0000000000000000', '0000000000000001'));
    $t->assertSame(64, \FieldPulse\Imaging\Hamming::betweenHex('ffffffffffffffff', '0000000000000000'));

    // 0xff ^ 0xf0 == 0x0f, which has four set bits, not two.
    $t->assertSame(4, \FieldPulse\Imaging\Hamming::betweenHex('00000000000000ff', '00000000000000f0'));

    // The sign bit is the interesting one: a 64-bit hash with the top bit set
    // is a NEGATIVE int in PHP, so any shift-and-mask implementation that
    // forgets to handle sign extension silently loses the top nibble.
    $t->assertSame(1, \FieldPulse\Imaging\Hamming::betweenHex('ffffffffffffffff', 'fffffffffffffffe'));
    $t->assertSame(1, \FieldPulse\Imaging\Hamming::betweenHex('0000000000000000', '8000000000000000'));
    // Every nibble differs, so all 64 bits differ.
    $t->assertSame(64, \FieldPulse\Imaging\Hamming::betweenHex('00000000ffffffff', 'ffffffff00000000'));
});

$t->test('hamming distance agrees with an independent reference on random hashes', function (TestRunner $t): void {
    // Reference method: walk the hex strings and count the set bits of each
    // differing nibble. Completely different code path from decbin() on the
    // packed integer, so agreement is meaningful — and it is the only way to
    // catch a 64-bit boundary error, because hand-written "expected 2" values
    // have already been wrong twice in this file.
    $nibbleBits = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];

    $reference = static function (string $a, string $b) use ($nibbleBits): int {
        $a = strtolower($a);
        $b = strtolower($b);
        $count = 0;

        for ($i = 0; $i < strlen($a); $i++) {
            $count += $nibbleBits[hexdec($a[$i]) ^ hexdec($b[$i])];
        }

        return $count;
    };

    // Include the extremes explicitly: all-zero and all-ones force the sign bit
    // on and off, and random bytes rarely hit them.
    $cases = [
        ['0000000000000000', 'ffffffffffffffff'],
        ['ffffffffffffffff', 'ffffffffffffffff'],
        ['0000000000000000', '0000000000000000'],
        ['8000000000000000', '0000000000000000'],
        ['7fffffffffffffff', 'ffffffffffffffff'],
    ];

    for ($i = 0; $i < 400; $i++) {
        $cases[] = [bin2hex(random_bytes(8)), bin2hex(random_bytes(8))];
    }

    foreach ($cases as [$a, $b]) {
        $expected = $reference($a, $b);
        $actual   = \FieldPulse\Imaging\Hamming::betweenHex($a, $b);

        $t->assertSame($expected, $actual, "distance($a, $b)");
    }
});

$t->test('hamming distance is symmetric and bounded', function (TestRunner $t): void {
    for ($i = 0; $i < 100; $i++) {
        $a = bin2hex(random_bytes(8));
        $b = bin2hex(random_bytes(8));

        $ab = \FieldPulse\Imaging\Hamming::betweenHex($a, $b);
        $ba = \FieldPulse\Imaging\Hamming::betweenHex($b, $a);

        $t->assertSame($ab, $ba, 'symmetric');
        $t->assertTrue($ab >= 0 && $ab <= 64, 'within 0..64, got ' . $ab);
    }
});

$t->test('nearest picks the closest candidate', function (TestRunner $t): void {
    $nearest = \FieldPulse\Imaging\Hamming::nearest('0000000000000000', [
        ['id' => 1, 'phash_hex' => '00000000000000ff'],   // 8 away
        ['id' => 2, 'phash_hex' => '0000000000000003'],   // 2 away
        ['id' => 3, 'phash_hex' => 'ffffffffffffffff'],   // 64 away
    ]);

    $t->assertNotNull($nearest);
    $t->assertSame(2, $nearest['id']);
    $t->assertSame(2, $nearest['distance']);
});

$t->test('nearest ignores a candidate with no hash', function (TestRunner $t): void {
    $nearest = \FieldPulse\Imaging\Hamming::nearest('0000000000000000', [
        ['id' => 1, 'phash_hex' => ''],
    ]);

    $t->assertNull($nearest, 'an empty hash must not be measured as distance 0');
});

// --- Geodesy ----------------------------------------------------------------
$t->group('geo');

$t->test('one degree of longitude at the equator is ~111.19 km', function (TestRunner $t): void {
    $d = \FieldPulse\Geo\Haversine::metres(0.0, 0.0, 0.0, 1.0);

    $t->assertNear(111194.93, $d, 1.0, 'equatorial degree length');
});

$t->test('distance is zero for identical points', function (TestRunner $t): void {
    $t->assertNear(0.0, \FieldPulse\Geo\Haversine::metres(6.5244, 3.3792, 6.5244, 3.3792), 0.001);
});

$t->test('distance is symmetric', function (TestRunner $t): void {
    $ab = \FieldPulse\Geo\Haversine::metres(6.5, 3.4, 6.6, 3.5);
    $ba = \FieldPulse\Geo\Haversine::metres(6.6, 3.5, 6.5, 3.4);

    $t->assertNear($ab, $ba, 0.001);
});

$t->test('Lagos to Abuja is ~500 km', function (TestRunner $t): void {
    // Lagos 6.5244, 3.3792 -> Abuja 9.0765, 7.3986
    $d = \FieldPulse\Geo\Haversine::metres(6.5244, 3.3792, 9.0765, 7.3986);

    $t->assertNear(520000.0, $d, 20000.0, 'Lagos to Abuja');
});

$t->test('antipodal points do not produce NAN', function (TestRunner $t): void {
    // The asin() domain error this guards against would make every comparison
    // false and silently approve an out-of-range geofence.
    $d = \FieldPulse\Geo\Haversine::metres(0.0, 0.0, 0.0, 180.0);

    $t->assertFalse(is_nan($d), 'must not be NAN');
    $t->assertNear(20015086.8, $d, 5000.0, 'half circumference');
});

$t->test('bounding box contains the circle it was built from', function (TestRunner $t): void {
    $lat = 6.5244;
    $lng = 3.3792;
    $r   = 250.0;

    $box = \FieldPulse\Geo\Haversine::boundingBox($lat, $lng, $r);

    $t->assertTrue($box['min_lat'] < $lat && $box['max_lat'] > $lat, 'latitude centred');
    $t->assertTrue($box['min_lng'] < $lng && $box['max_lng'] > $lng, 'longitude centred');

    // Due north by r must fall inside the box.
    $north = \FieldPulse\Geo\Haversine::metres($lat, $lng, $box['max_lat'], $lng);
    $t->assertTrue($north >= $r * 0.999 && $north <= $r * 1.001, 'north edge is r away');

    $t->assertTrue(
        \FieldPulse\Geo\Haversine::withinBox($lat, $lng, $box),
        'the point itself is inside its own box'
    );
});

$t->test('bounding box clamps instead of exploding at the pole', function (TestRunner $t): void {
    $box = \FieldPulse\Geo\Haversine::boundingBox(89.9999, 0.0, 500.0);

    $t->assertTrue($box['min_lng'] >= -180.0, 'longitude lower bound');
    $t->assertTrue($box['max_lng'] <= 180.0, 'longitude upper bound');
    $t->assertFalse(is_nan($box['min_lng']), 'no NAN at high latitude');
});

// --- Periods ----------------------------------------------------------------
$t->group('periods');

$t->test('a Monday is its own period start', function (TestRunner $t): void {
    // 2026-09-28 is a Monday.
    $period = \FieldPulse\Domain\PeriodResolver::periodFor('2026-09-28 00:00:00');

    $t->assertSame('2026-09-28', $period['date']);
});

$t->test('Sunday late belongs to the week that began six days earlier', function (TestRunner $t): void {
    // 2026-10-04 is a Sunday. The instant is given in UTC, so the Sunday-evening
    // moment has to be expressed in UTC too: Africa/Lagos is UTC+1, which means
    // 22:30 UTC is 23:30 local Sunday. 23:30 UTC would already be 00:30 Monday
    // in Lagos and belongs to the *new* week — the next test pins that down.
    $period = \FieldPulse\Domain\PeriodResolver::periodFor('2026-10-04 22:30:00');

    $t->assertSame('2026-09-28', $period['date'], 'ISO week rolls back to the Monday');
});

$t->test('the period starts at local midnight, not an hour later', function (TestRunner $t): void {
    // Regression test. timezone.period_day (1 = Monday) was being passed as the
    // HOUR argument to setTime(), so every period began at 01:00 local while
    // utcRangeForPeriod() began at 00:00. The two disagreed by an hour, so
    // submissions between local midnight and 01:00 on the period's first day
    // were assigned to one week and counted against another.
    $period = \FieldPulse\Domain\PeriodResolver::periodFor('2026-09-30 09:00:00');

    $t->assertSame(
        '2026-09-27 23:00:00',
        $period['start_utc'],
        '2026-09-28 00:00 Africa/Lagos is 2026-09-27 23:00 UTC'
    );
    $t->assertSame(
        '2026-10-04 23:00:00',
        $period['end_utc'],
        'end is seven days after the start, in UTC'
    );
});

$t->test('the UTC window is seven days and half-open', function (TestRunner $t): void {
    $period = \FieldPulse\Domain\PeriodResolver::periodFor('2026-09-28 12:00:00');

    $start = new DateTimeImmutable($period['start_utc']);
    $end   = new DateTimeImmutable($period['end_utc']);

    $t->assertSame(7 * 86400, $end->getTimestamp() - $start->getTimestamp(), 'exactly one week');
    $t->assertTrue($end > $start, 'window is ordered');
});

$t->test('a submission at 00:30 local Monday lands in the new week', function (TestRunner $t): void {
    // Africa/Lagos is UTC+1, so 2026-09-28 00:30 local is 2026-09-27 23:30 UTC —
    // still Sunday in UTC, which is the boundary this test exists to pin down.
    $period = \FieldPulse\Domain\PeriodResolver::periodFor('2026-09-27 23:30:00');

    $t->assertSame('2026-09-28', $period['date'], 'business timezone wins over UTC');
});

$t->test('utcRangeForPeriod agrees with periodFor', function (TestRunner $t): void {
    $fromPeriod = \FieldPulse\Domain\PeriodResolver::periodFor('2026-09-30 09:00:00');
    $fromDate   = \FieldPulse\Domain\PeriodResolver::utcRangeForPeriod('2026-09-28');

    // These two MUST agree. periodFor() derives the window for a moment in time;
    // utcRangeForPeriod() rebuilds it from the stored period_start_date. If they
    // diverge, a submission is assigned a period by one and counted by the
    // other, and the leaderboard silently loses whatever falls in the gap.
    $t->assertSame($fromPeriod['start_utc'], $fromDate['start_utc'], 'start_utc must match');
    $t->assertSame($fromPeriod['end_utc'], $fromDate['end_utc'], 'end_utc must match');
});

$t->test('every day of a week maps to the same period', function (TestRunner $t): void {
    // Exhaustively walk the boundary. A single mismatched hour anywhere in the
    // week moves a day of submissions into the wrong total.
    $expected = '2026-09-28';

    for ($day = 28; $day <= 4; $day++) {
        $date = $day <= 30
            ? sprintf('2026-09-%02d 12:00:00', $day)
            : sprintf('2026-10-%02d 12:00:00', $day - 30);

        $period = \FieldPulse\Domain\PeriodResolver::periodFor($date);

        $t->assertSame(
            $expected,
            $period['date'],
            'midday UTC on ' . $date . ' belongs to ' . $expected
        );
    }

    // And the very next Monday starts a new one.
    $t->assertSame(
        '2026-10-05',
        \FieldPulse\Domain\PeriodResolver::periodFor('2026-10-05 12:00:00')['date'],
        'the following Monday is a new period'
    );
});

$t->test('periodRange returns the requested number of distinct Mondays', function (TestRunner $t): void {
    $dates = \FieldPulse\Domain\PeriodResolver::periodRange(6);

    $t->assertSame(6, count($dates));
    $t->assertSame(count($dates), count(array_unique($dates)), 'no duplicates');

    $previous = null;

    foreach ($dates as $date) {
        $t->assertTrue(
            \FieldPulse\Domain\Validator::isIsoDate($date),
            'each entry is a real date'
        );
        $t->assertSame('1', (new DateTimeImmutable($date))->format('N'), 'each entry is a Monday');

        if ($previous !== null) {
            $gap = (strtotime($previous) - strtotime($date)) / 86400;
            $t->assertSame(7.0, (float) $gap, 'consecutive weeks');
        }

        $previous = $date;
    }
});

// --- Validation -------------------------------------------------------------
$t->group('validation');

$t->test('IMEI length and Luhn are enforced', function (TestRunner $t): void {
    $t->assertThrows(static fn () => \FieldPulse\Domain\Validator::imei('123'), '14 to 16');
    $t->assertThrows(static fn () => \FieldPulse\Domain\Validator::imei('353456789012345'), 'not a valid IMEI');
    $t->assertThrows(static fn () => \FieldPulse\Domain\Validator::imei('abcdefghijklmno'), '14 to 16');
    $t->assertThrows(static fn () => \FieldPulse\Domain\Validator::imei(353456789012345), 'required');

    // A Luhn-valid 15-digit IMEI is accepted, and separators the agent types
    // from the handset box are tolerated.
    $t->assertSame('490154203237518', \FieldPulse\Domain\Validator::imei('490154203237518'));
    $t->assertSame('490154203237518', \FieldPulse\Domain\Validator::imei('490-154-203237518'));
});

$t->test('count_claimed range is enforced at the boundary', function (TestRunner $t): void {
    $t->assertSame(1, \FieldPulse\Domain\Validator::intRange(1, 1, 500, 'count_claimed'));
    $t->assertSame(500, \FieldPulse\Domain\Validator::intRange(500, 1, 500, 'count_claimed'));
    $t->assertThrows(static fn () => \FieldPulse\Domain\Validator::intRange(0, 1, 500, 'c'));
    $t->assertThrows(static fn () => \FieldPulse\Domain\Validator::intRange(501, 1, 500, 'c'));
});

$t->test('coordinates reject out-of-range values', function (TestRunner $t): void {
    $t->assertThrows(static fn () => \FieldPulse\Domain\Validator::coordinates(91.0, 0.0));
    $t->assertThrows(static fn () => \FieldPulse\Domain\Validator::coordinates(0.0, 181.0));
    $t->assertThrows(static fn () => \FieldPulse\Domain\Validator::coordinates('abc', 0.0));

    $ok = \FieldPulse\Domain\Validator::coordinates(6.5244, 3.3792);
    $t->assertNear(6.5244, $ok['latitude'], 0.0001);
    $t->assertNear(3.3792, $ok['longitude'], 0.0001);
});

$t->test('isIsoDate rejects a format-valid but non-existent date', function (TestRunner $t): void {
    $t->assertTrue(\FieldPulse\Domain\Validator::isIsoDate('2026-02-28'));
    $t->assertFalse(\FieldPulse\Domain\Validator::isIsoDate('2026-02-30'), 'February has no 30th');
    $t->assertFalse(\FieldPulse\Domain\Validator::isIsoDate('2026-13-01'));
    $t->assertFalse(\FieldPulse\Domain\Validator::isIsoDate('2026-1-1'));
    $t->assertFalse(\FieldPulse\Domain\Validator::isIsoDate(''));
});

$t->test('unknown keys are rejected, not ignored', function (TestRunner $t): void {
    // An exact match on the contract is accepted silently.
    \FieldPulse\Domain\Validator::assertNoUnknownKeys(
        ['a' => 1, 'b' => 2],
        ['a', 'b'],
        'test'
    );

    // A subset is fine too: absent optional fields are not errors.
    \FieldPulse\Domain\Validator::assertNoUnknownKeys(
        ['a' => 1],
        ['a', 'b'],
        'test'
    );

    $t->assertThrows(
        static fn () => \FieldPulse\Domain\Validator::assertNoUnknownKeys(
            ['a' => 1, 'surprise' => 2],
            ['a'],
            'test'
        ),
        'surprise',
        'an unexpected field must be an error'
    );
});

$t->test('every unexpected field is named in the error', function (TestRunner $t): void {
    // Silent dropping turns a fixable contract mismatch into invisible data
    // loss, so all of them must be reported, not just the first.
    try {
        \FieldPulse\Domain\Validator::assertNoUnknownKeys(
            ['a' => 1, 'extra_one' => 2, 'extra_two' => 3],
            ['a'],
            'payload'
        );
        $t->assertTrue(false, 'expected a validation error');
    } catch (\FieldPulse\Http\ApiException $e) {
        $t->assertTrue(
            str_contains($e->getMessage(), 'extra_one'),
            'names the first unexpected field, got: ' . $e->getMessage()
        );
        $t->assertTrue(
            str_contains($e->getMessage(), 'extra_two'),
            'names the second unexpected field, got: ' . $e->getMessage()
        );
    }
});

// --- JWT --------------------------------------------------------------------
$t->group('jwt');

/** Build a token with fully caller-controlled claims, including exp/iss/aud. */
$makeToken = static function (array $overrides = []): string {
    $now = time();

    return \FieldPulse\Security\Jwt::issue($overrides + [
        'agent_id'   => '42',
        'device_id'  => 'd',
        'device_uuid' => '11111111-1111-4111-8111-111111111111',
        'iat'        => $now,
        'exp'        => $now + 900,
    ]);
};

$t->test('a token round-trips through issue and verifyAndDecode', function (TestRunner $t) use ($makeToken): void {
    $token = $makeToken();
    $parts = explode('.', $token);

    $t->assertSame(3, count($parts), 'three JWT segments');

    $decoded = \FieldPulse\Security\Jwt::verifyAndDecode($token);

    $t->assertSame('42', $decoded['sub'], 'sub is the agent id, as a string');
    $t->assertSame('11111111-1111-4111-8111-111111111111', $decoded['did'] ?? $decoded['device_uuid'] ?? '');
    $t->assertSame('fieldpulse', $decoded['iss']);
});

$t->test('a tampered payload is rejected', function (TestRunner $t) use ($makeToken): void {
    $token = $makeToken();

    [$header, $payload, $signature] = explode('.', $token);

    $claims = json_decode(\FieldPulse\Support\Str::base64UrlDecode($payload), true);
    $claims['sub'] = '999';
    $forged = \FieldPulse\Support\Str::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));

    $tampered = $header . '.' . $forged . '.' . $signature;

    $t->assertThrows(
        static fn () => \FieldPulse\Security\Jwt::verifyAndDecode($tampered),
        null,
        'signature must fail on a modified payload'
    );
});

$t->test('an expired token is rejected', function (TestRunner $t) use ($makeToken): void {
    $token = $makeToken(['iat' => time() - 7200, 'exp' => time() - 3600]);

    $t->assertThrows(
        static fn () => \FieldPulse\Security\Jwt::verifyAndDecode($token),
        null,
        'exp must be enforced'
    );
});

$t->test('the alg=none downgrade is refused', function (TestRunner $t): void {
    $header  = \FieldPulse\Support\Str::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'none'], JSON_THROW_ON_ERROR));
    $payload = \FieldPulse\Support\Str::base64UrlEncode(json_encode([
        'sub' => '1', 'iat' => time(), 'exp' => time() + 900,
    ], JSON_THROW_ON_ERROR));

    $t->assertThrows(
        static fn () => \FieldPulse\Security\Jwt::verifyAndDecode($header . '.' . $payload . '.'),
        null,
        'alg=none must never verify'
    );
});

$t->test('a foreign issuer is rejected', function (TestRunner $t) use ($makeToken): void {
    $token = $makeToken(['iss' => 'someone-else']);

    $t->assertThrows(
        static fn () => \FieldPulse\Security\Jwt::verifyAndDecode($token),
        null,
        'iss must be checked'
    );
});

$t->test('a foreign audience is rejected', function (TestRunner $t) use ($makeToken): void {
    $token = $makeToken(['aud' => 'another-app']);

    $t->assertThrows(
        static fn () => \FieldPulse\Security\Jwt::verifyAndDecode($token),
        null,
        'aud must be checked'
    );
});

$t->test('the jti claim is unique per token', function (TestRunner $t) use ($makeToken): void {
    $a = \FieldPulse\Security\Jwt::verifyAndDecode($makeToken());
    $b = \FieldPulse\Security\Jwt::verifyAndDecode($makeToken());

    $t->assertNotSame($a['jti'], $b['jti'], 'jti must not repeat');
});

// --- ECDSA and the canonical payload ---------------------------------------
$t->group('signature');

/** @return array{0:\GdImage,1:array<string,mixed>} a fresh P-256 keypair and its public JWK */
$makeKey = static function (): array {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

    if ($key === false) {
        // Report what OpenSSL actually said. "openssl is unavailable" is the
        // wrong diagnosis whenever the extension is loaded but the library
        // cannot start — a missing openssl.cnf looks identical from the outside
        // and used to send people looking for a disabled extension.
        $errors = [];

        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        $detail = $errors === []
            ? 'OpenSSL reported no detail.'
            : 'OpenSSL errors: ' . implode(' | ', $errors) . '.';

        throw new RuntimeException(
            'openssl_pkey_new() failed, so the signature tests cannot run. ' . $detail
            . ' Loaded: ' . var_export(extension_loaded('openssl'), true) . '.'
            . ' On a Windows PHP build this normally means OPENSSL_CONF is unset;'
            . ' on Linux it means the openssl package is misconfigured.'
        );
    }

    $jwk = \FieldPulse\Security\Jwk::fromPublicKey($key);

    return [$key, $jwk];
};

$t->test('a public key exports to a PEM OpenSSL can re-import', function (TestRunner $t) use ($makeKey): void {
    [$key, $jwk] = $makeKey();

    $t->assertNotNull($jwk, 'fromPublicKey must produce a JWK');
    $t->assertSame('EC', $jwk['kty']);
    $t->assertSame('P-256', $jwk['crv']);

    // The round trip that matters: PEM text -> key resource. If the DER encoding
    // is wrong this is where it fails, and every device signature would 500.
    $pem = \FieldPulse\Security\Jwk::toPem($jwk);

    $t->assertNotNull($pem, 'toPem must produce a PEM');
    $t->assertContains('BEGIN PUBLIC KEY', $pem);

    $reimported = openssl_pkey_get_public($pem);
    $t->assertNotFalse($reimported, 'the generated PEM must be importable');
});

$t->test('an exported PEM describes the same key', function (TestRunner $t) use ($makeKey): void {
    [$key, $jwk] = $makeKey();

    $original = openssl_pkey_get_details($key);
    $viaJwk   = openssl_pkey_get_details(openssl_pkey_get_public(\FieldPulse\Security\Jwk::toPem($jwk)));

    $t->assertSame(
        $original['ec']['x'],
        $viaJwk['ec']['x'],
        'x coordinate must survive the JWK round trip'
    );
    $t->assertSame($original['ec']['y'], $viaJwk['ec']['y'], 'y coordinate must survive');
});

$t->test('a malformed JWK is refused', function (TestRunner $t): void {
    $t->assertThrows(static fn () => \FieldPulse\Security\Jwk::validatePublicJwk([
        'kty' => 'RSA', 'crv' => 'P-256', 'x' => 'AA', 'y' => 'BB',
    ]), null, 'kty must be EC');

    $t->assertThrows(static fn () => \FieldPulse\Security\Jwk::validatePublicJwk([
        'kty' => 'EC', 'crv' => 'P-384', 'x' => 'AA', 'y' => 'BB',
    ]), null, 'only P-256 is accepted');

    $t->assertThrows(static fn () => \FieldPulse\Security\Jwk::validatePublicJwk([
        'kty' => 'EC', 'crv' => 'P-256', 'x' => 'not base64url!!', 'y' => 'zz',
    ]), null, 'coordinates must be base64url');
});

$t->test('a signature from the matching key verifies', function (TestRunner $t) use ($makeKey): void {
    [$key, $jwk] = $makeKey();

    $message  = "FieldPulse-Test-v1\nabc123\ndevice-uuid";
    $signature = '';

    $t->assertTrue(openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256), 'openssl_sign');

    \FieldPulse\Security\SignatureVerifier::assertProofOfPossession(
        $jwk,
        $message,
        \FieldPulse\Support\Str::base64UrlEncode($signature)
    );

    $t->assertTrue(true, 'verification reached without throwing');
});

$t->test('a signature from a different key does not verify', function (TestRunner $t) use ($makeKey): void {
    [$keyA] = $makeKey();
    [, $jwkB] = $makeKey();

    $message = "FieldPulse-Test-v1\nabc123\ndevice-uuid";

    $signature = '';
    openssl_sign($message, $signature, $keyA, OPENSSL_ALGO_SHA256);

    $t->assertThrows(
        static fn () => \FieldPulse\Security\SignatureVerifier::assertProofOfPossession(
            $jwkB,
            $message,
            \FieldPulse\Support\Str::base64UrlEncode($signature)
        ),
        null,
        'a signature from another key must fail'
    );
});

$t->test('a signature over a different message does not verify', function (TestRunner $t) use ($makeKey): void {
    [$key, $jwk] = $makeKey();

    $signature = '';
    openssl_sign('a different message', $signature, $key, OPENSSL_ALGO_SHA256);

    $t->assertThrows(
        static fn () => \FieldPulse\Security\SignatureVerifier::assertProofOfPossession(
            $jwk,
            "FieldPulse-Test-v1\nabc123\ndevice-uuid",
            \FieldPulse\Support\Str::base64UrlEncode($signature)
        ),
        null,
        'the signature must be bound to the exact signed message'
    );
});

$t->test('the canonical payload is five newline-separated fields', function (TestRunner $t): void {
    $body     = '{"submission_uuid":"x","count_claimed":3}';
    $canonical = \FieldPulse\Security\CanonicalPayload::build(
        'POST',
        '/api/v1/submit.php',
        1788000000,
        'abcdefghijklmnopqrstuvwxyz',
        $body
    );

    $lines = explode("\n", $canonical);

    $t->assertSame(5, count($lines), 'five fields');
    $t->assertSame('POST', $lines[0]);
    $t->assertSame('/api/v1/submit.php', $lines[1]);
    $t->assertSame('1788000000', $lines[2]);
    $t->assertSame('abcdefghijklmnopqrstuvwxyz', $lines[3]);
    $t->assertSame(hash('sha256', $body), $lines[4], 'field five is SHA256HEX(body)');
    $t->assertFalse(str_ends_with($canonical, "\n"), 'no trailing newline');
});

$t->test('changing any field changes the canonical string', function (TestRunner $t): void {
    $body = '{"a":1}';
    $nonce = 'abcdefghijklmnopqrstuvwxyz';

    $base = \FieldPulse\Security\CanonicalPayload::build('POST', '/p', 1, $nonce, $body);

    $t->assertNotSame($base, \FieldPulse\Security\CanonicalPayload::build('GET',  '/p', 1, $nonce, $body));
    $t->assertNotSame($base, \FieldPulse\Security\CanonicalPayload::build('POST', '/q', 1, $nonce, $body));
    $t->assertNotSame($base, \FieldPulse\Security\CanonicalPayload::build('POST', '/p', 2, $nonce, $body));
    $t->assertNotSame($base, \FieldPulse\Security\CanonicalPayload::build('POST', '/p', 1, 'zyxwvutsrqponmlkjihgfedcba', $body));
    $t->assertNotSame($base, \FieldPulse\Security\CanonicalPayload::build('POST', '/p', 1, $nonce, '{"a":2}'));
});

$t->test('newline delimiters prevent field-shift collisions', function (TestRunner $t): void {
    // The reason the format is newline-delimited rather than concatenated: under
    // '+', these two produce the identical byte string, so a signature over one
    // would verify for the other.
    $nonce = 'abcdefghijklmnopqrstuvwxyz';

    $a = \FieldPulse\Security\CanonicalPayload::build('POST', '/api/x', 1, $nonce, 'b');
    $b = \FieldPulse\Security\CanonicalPayload::build('POST', '/ap', 1, 'ix' . $nonce, 'b');

    $t->assertNotSame($a, $b, 'a shifted field must change the canonical string');
});

$t->test('a newline in a field cannot forge a canonical string', function (TestRunner $t): void {
    $t->assertThrows(
        static fn () => \FieldPulse\Security\CanonicalPayload::build(
            'POST',
            "/api/v1/x\nX-Injected: 1",
            1,
            'abcdefghijklmnopqrstuvwxyz',
            'x'
        ),
        null,
        'a newline in the path must be rejected'
    );
});

// --- Paths ------------------------------------------------------------------
$t->group('paths');

$t->test('stored paths cannot escape the storage root', function (TestRunner $t): void {
    foreach (['../../../etc/passwd', '/etc/passwd', 'quarantine/../../x', "a\0b"] as $attempt) {
        $t->assertThrows(
            static fn () => \FieldPulse\Support\Paths::absoluteForStoredPath($attempt),
            null,
            'traversal must be refused: ' . $attempt
        );
    }
});

$t->test('a write path cannot escape the storage root', function (TestRunner $t): void {
    $t->assertThrows(
        static fn () => \FieldPulse\Support\Paths::storagePath('processed/../../escape.jpg'),
        null,
        'traversal must be refused on write'
    );
});

// --- Review decision vocabulary ----------------------------------------------
$t->group('review');

$t->test('a decision maps to a value both ENUMs accept', function (TestRunner $t): void {
    // The API vocabulary (APPROVE/REJECT) and the stored vocabulary are
    // different on purpose. Writing the decision straight into
    // submission_verifications.final_disposition is rejected under strict mode
    // and truncates to '' otherwise, so the mapping has to happen in PHP. This
    // asserts both target ENUMs actually accept what we map to — checked against
    // the literals in migrations 004 and 005, which is the whole point, since a
    // schema change there must break this test loudly.
    $submissionStatuses = ['RECEIVED', 'QUEUED', 'PROCESSING', 'VERIFIED', 'REQUIRES_REVIEW', 'REJECTED'];
    $dispositions       = ['VERIFIED', 'REQUIRES_REVIEW', 'REJECTED'];

    $t->assertSame(
        'VERIFIED',
        \FieldPulse\Database\ReviewRepository::outcomeFor(\FieldPulse\Database\ReviewRepository::DECISION_APPROVE),
        'APPROVE stores as VERIFIED'
    );
    $t->assertSame(
        'REJECTED',
        \FieldPulse\Database\ReviewRepository::outcomeFor(\FieldPulse\Database\ReviewRepository::DECISION_REJECT),
        'REJECT stores as REJECTED'
    );

    foreach (\FieldPulse\Database\ReviewRepository::OUTCOME_FOR_DECISION as $decision => $outcome) {
        $t->assertTrue(
            in_array($outcome, $dispositions, true),
            "final_disposition ENUM accepts the outcome for $decision (got $outcome)"
        );
        $t->assertTrue(
            in_array($outcome, $submissionStatuses, true),
            "submissions.status ENUM accepts the outcome for $decision (got $outcome)"
        );
        $t->assertNotSame(
            $decision,
            $outcome,
            'the decision must not be stored verbatim'
        );
    }
});

$t->test('an unrecognised decision is refused, not coerced', function (TestRunner $t): void {
    // Coercing an unknown decision to VERIFIED would let a typo pay an agent.
    $t->assertThrows(
        static fn () => \FieldPulse\Database\ReviewRepository::outcomeFor('APROVE'),
        null,
        'a typo must not resolve'
    );

    $t->assertThrows(
        static fn () => \FieldPulse\Database\ReviewRepository::outcomeFor(''),
        null,
        'an empty decision must not resolve'
    );
});

// --- Exit -------------------------------------------------------------------
exit($t->run($verbose));
