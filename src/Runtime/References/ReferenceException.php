<?php
declare(strict_types=1);
namespace Armes\Runtime\References;
use Armes\Exception\RuntimeResolutionException;
final class ReferenceException extends RuntimeResolutionException
{
    public function __construct(public readonly string $diagnosticCode, string $message) { parent::__construct($message); }
}
