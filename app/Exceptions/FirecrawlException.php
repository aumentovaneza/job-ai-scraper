<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised for any Firecrawl API failure (T-20): missing key, transport error,
 * non-2xx response, or an unsuccessful payload.
 */
class FirecrawlException extends RuntimeException {}
