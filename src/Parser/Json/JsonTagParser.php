<?php

declare(strict_types=1);

namespace Armes\Parser\Json;

use Armes\Exception\ParseException;
use Armes\Parser\Native\NativeTagParser;
use Armes\Tree\Node;
use JsonException;

final class JsonTagParser
{
    public function __construct(
        private readonly NativeTagParser $nativeParser = new NativeTagParser(),
    ) {
    }

    public function parseFile(string $path): Node
    {
        if (!is_file($path)) {
            throw new ParseException(sprintf('ARMES JSON source "%s" does not exist.', $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new ParseException(sprintf('Unable to read ARMES JSON source "%s".', $path));
        }

        return $this->parseString($content, $path);
    }

    /**
     * @param list<array{level: string, message: string}>|null $diagnostics
     */
    public function parseString(string $json, ?string $source = null, bool $strict = true, ?array &$diagnostics = null): Node
    {
        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ParseException(sprintf('Invalid ARMES JSON: %s', $exception->getMessage()), 0, $exception);
        }

        return $this->nativeParser->parse($decoded, $source, $strict, $diagnostics);
    }
}
