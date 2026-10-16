<?php

namespace Folklore\Eclair\Exceptions;

/**
 * Eclair could not be reached: a network error or a timeout. The request may or may not have
 * reached it: retry with the same idempotency key.
 */
class ConnectionFailed extends EclairException {}
