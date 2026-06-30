<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when a passport cannot be published (missing fields, quota exceeded). */
class PublishException extends RuntimeException {}
