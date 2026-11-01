<?php
/** Tiny dependency-free test helper (no Composer needed). */
declare(strict_types=1);

final class T
{
    public static int $passed = 0;
    public static array $failed = [];
    private static string $current = '';

    public static function group(string $name): void
    {
        self::$current = $name;
        echo "\n$name\n";
    }

    public static function ok(bool $condition, string $label): void
    {
        if ($condition) {
            self::$passed++;
            echo "  ok   $label\n";
        } else {
            self::$failed[] = self::$current . ' :: ' . $label;
            echo "  FAIL $label\n";
        }
    }

    public static function same($expected, $actual, string $label): void
    {
        self::ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'));
    }

    public static function finish(): void
    {
        $failed = count(self::$failed);
        echo "\n" . self::$passed . " passed, $failed failed\n";
        foreach (self::$failed as $f) {
            echo "  - $f\n";
        }
        exit($failed === 0 ? 0 : 1);
    }
}
