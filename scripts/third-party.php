<?php

use Tests\Support\ThirdParty;

/*
| Writes THIRD-PARTY.md from composer.lock and package-lock.json (Tests\Support\ThirdParty). Run it after
| changing a dependency; tests/Feature/ThirdPartyTest.php fails while the file is stale.
|
|   docker compose exec app php scripts/third-party.php
*/

require dirname(__DIR__).'/vendor/autoload.php';

file_put_contents(dirname(__DIR__).'/THIRD-PARTY.md', ThirdParty::markdown(dirname(__DIR__)));
echo "THIRD-PARTY.md written\n";
