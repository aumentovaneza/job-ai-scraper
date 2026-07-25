<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised for any Voyage AI embeddings failure (T-25): missing key, transport
 * error, non-2xx response, or an empty/malformed payload.
 */
class VoyageException extends RuntimeException {}
