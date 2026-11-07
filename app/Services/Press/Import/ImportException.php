<?php

namespace App\Services\Press\Import;

use RuntimeException;

/** A file the importer refuses. The message is written for the person who uploaded it. */
final class ImportException extends RuntimeException {}
