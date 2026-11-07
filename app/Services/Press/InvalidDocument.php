<?php

namespace App\Services\Press;

use RuntimeException;

/**
 * A value that cannot be stored in a presentation document. The message is a short reason meant for the `skipped`
 * list an ops answer carries, so it never repeats the offending content.
 */
class InvalidDocument extends RuntimeException {}
