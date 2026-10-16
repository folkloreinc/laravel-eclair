<?php

namespace Folklore\Eclair\Exceptions;

use RuntimeException;

/**
 * Every error of the client: Eclair is not configured, can't be reached, or answered with an
 * error.
 */
class EclairException extends RuntimeException {}
