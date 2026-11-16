<?php

namespace App\Domain\Import;

use RuntimeException;

/** The whole file is unusable (too large, not a CSV, a required column missing). */
final class CsvRejected extends RuntimeException {}
