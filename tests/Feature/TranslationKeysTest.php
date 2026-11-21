<?php

/*
 * Every literal key handed to t() / tc() in resources/js and to __() / trans() / trans_choice() in app and
 * resources/views has its French text in lang/fr.json (or, for a dotted key, in lang/fr/<file>.php): a missing one
 * shows the English key in the French interface. A literal ending in "." is the prefix of keys built at run time
 * (an enum value appended) and must start at least one key.
 */

/** @return list<array{string, string}> [key, where] */
function translationKeysUsed(): array
{
    $pattern = '/(?<![\w$.>:])(?:t|tc|__|trans|trans_choice)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/';
    $used = [];
    foreach (['app', 'resources/js', 'resources/views'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (! preg_match('/\.(php|vue|ts)$/', $path) || str_ends_with($path, '.test.ts')) {
                continue;
            }
            $source = (string) file_get_contents($path);
            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $literal = isset($match[2]) && $match[2][1] >= 0 ? $match[2] : $match[1];
                $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;
                $used[] = [stripcslashes($literal[0]), substr($path, strlen(base_path()) + 1).":{$line}"];
            }
        }
    }

    return $used;
}

it('finds every translation key in lang/fr.json', function () {
    /** @var array<string, string> $french */
    $french = json_decode((string) file_get_contents(lang_path('fr.json')), true);
    $used = translationKeysUsed();

    $missing = [];
    foreach ($used as [$key, $where]) {
        $known = array_key_exists($key, $french)
            || (str_ends_with($key, '.') && array_filter(array_keys($french), fn (string $k) => str_starts_with($k, $key)) !== [])
            || (preg_match('/^([a-z_]+)\.[\w.]+$/', $key, $file) === 1 && is_file(lang_path("fr/{$file[1]}.php")) && trans($key, [], 'fr') !== $key);
        if (! $known) {
            $missing[] = "{$where} {$key}";
        }
    }

    expect(count($used))->toBeGreaterThan(500)
        ->and($missing)->toBe([]);
});
