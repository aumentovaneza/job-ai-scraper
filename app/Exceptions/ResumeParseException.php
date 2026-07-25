<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when an uploaded resume can't be turned into usable text — an
 * unsupported format, a corrupt file, or a document with no extractable content.
 * Controllers surface this as a 422 so the user can re-upload.
 */
class ResumeParseException extends RuntimeException
{
    //
}
