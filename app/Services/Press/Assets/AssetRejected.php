<?php

namespace App\Services\Press\Assets;

use RuntimeException;

/**
 * A file was refused for a reason the person can act on (wrong type, too big, damaged, unsafe).
 * The message is written for the end user. The API turns it into a 422.
 */
class AssetRejected extends RuntimeException {}
