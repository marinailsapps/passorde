<?php

namespace App\Domain\Import;

use RuntimeException;

/** One row of the file cannot be used; thrown before it writes anything, and the rest continue. */
final class RowRejected extends RuntimeException {}
