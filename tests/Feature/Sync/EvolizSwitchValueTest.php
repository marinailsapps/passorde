<?php

use App\Support\EvolizState;
use App\Support\SyncMode;

/*
| a mistyped EVOLIZ_ENABLED stops the app, as SYNC_PROVIDER does,
| instead of switching Evoliz off.
*/

/**
 * config/server.php's evoliz_enabled with this EVOLIZ_ENABLED (null: unset).
 */
function evolizEnabledWith(?string $value): mixed
{
    $saved = getenv('EVOLIZ_ENABLED');
    if ($value === null) {
        putenv('EVOLIZ_ENABLED');
        unset($_ENV['EVOLIZ_ENABLED'], $_SERVER['EVOLIZ_ENABLED']);
    } else {
        putenv("EVOLIZ_ENABLED={$value}");
        $_ENV['EVOLIZ_ENABLED'] = $_SERVER['EVOLIZ_ENABLED'] = $value;
    }

    try {
        return (require base_path('config/server.php'))['evoliz_enabled'];
    } finally {
        if ($saved === false) {
            putenv('EVOLIZ_ENABLED');
            unset($_ENV['EVOLIZ_ENABLED'], $_SERVER['EVOLIZ_ENABLED']);
        } else {
            putenv("EVOLIZ_ENABLED={$saved}");
            $_ENV['EVOLIZ_ENABLED'] = $_SERVER['EVOLIZ_ENABLED'] = $saved;
        }
    }
}

it('reads the usual spellings of true and false, and unset as unset', function (?string $value, ?bool $expected) {
    expect(evolizEnabledWith($value))->toBe($expected);
})->with([
    'true' => ['true', true],
    'TRUE' => ['TRUE', true],
    '1' => ['1', true],
    'on' => ['on', true],
    'yes' => ['yes', true],
    'false' => ['false', false],
    '0' => ['0', false],
    'off' => ['off', false],
    'no' => ['no', false],
    'empty' => ['', null],
    'unset' => [null, null],
]);

it('stops on a typo instead of switching Evoliz off', function (string $value) {
    config()->set('server.evoliz_enabled', evolizEnabledWith($value));

    expect(fn () => EvolizState::enabled())->toThrow(InvalidArgumentException::class, "EVOLIZ_ENABLED must be true or false, got \"{$value}\".")
        ->and(fn () => SyncMode::provider())->toThrow(InvalidArgumentException::class);
})->with(['ture', 'enabled', 'evoliz', 'flase']);
