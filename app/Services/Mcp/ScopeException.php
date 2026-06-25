<?php

namespace App\Services\Mcp;

use RuntimeException;

/**
 * Хвърля се, когато tool изисква OAuth scope, който конекторът няма
 * (напр. gmail.send_email при connector само с gmail.readonly).
 */
class ScopeException extends RuntimeException {}
