<?php

declare(strict_types=1);

namespace Armes\Parser\Yaml;

use Armes\Exception\ParseException;
use Armes\Parser\Native\NativeTagParser;
use Armes\Tree\Node;
use Symfony\Component\Yaml\Exception\ParseException as SymfonyYamlParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlTagParser
{
    public function __construct(
        private readonly NativeTagParser $nativeParser = new NativeTagParser(),
    ) {
    }

    public function parseFile(string $path): Node
    {
        if (!is_file($path)) {
            throw new ParseException(sprintf('YAML source "%s" does not exist.', $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new ParseException(sprintf('Unable to read YAML source "%s".', $path));
        }

        return $this->parseString($content, $path);
    }

    public function parseString(string $yaml, ?string $source = null): Node
    {
        try {
            $decoded = Yaml::parse($yaml);
        } catch (SymfonyYamlParseException $exception) {
            throw new ParseException(sprintf('Invalid ARMES YAML: %s', $exception->getMessage()), 0, $exception);
        }

        return $this->nativeParser->parse($decoded, $source);
    }
}
