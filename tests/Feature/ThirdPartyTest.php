<?php

use Tests\Support\ThirdParty;

it('keeps THIRD-PARTY.md in step with the lock files (php scripts/third-party.php)', function () {
    expect(file_get_contents(base_path('THIRD-PARTY.md')))->toBe(ThirdParty::markdown(base_path()));
});
