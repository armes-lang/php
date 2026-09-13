<?php

declare(strict_types=1);

namespace Armes\Parser\Markup;

final class MarkupParseOptions
{
    public const MODE_HTML = 'html';
    public const MODE_XML = 'xml';
    public const MODE_ARMES = 'armes';

    public function __construct(
        public readonly string $mode = self::MODE_HTML,
        public readonly bool $fragment = false,
        public readonly bool $preserveWhitespace = true,
        public readonly bool $preserveComments = true,
        public readonly bool $preserveDoctype = true,
        public readonly bool $includeMeta = true,
        public readonly ?int $libxmlOptions = null,
    ) {
    }

    public function htmlLibxmlOptions(): int
    {
        return $this->libxmlOptions
            ?? $this->defaultLibxmlOptions();
    }

    public function xmlLibxmlOptions(): int
    {
        return $this->libxmlOptions
            ?? $this->defaultLibxmlOptions();
    }

    private function defaultLibxmlOptions(): int
    {
        $options = LIBXML_NOWARNING
            | LIBXML_NOERROR
            | LIBXML_NSCLEAN
            | LIBXML_NOCDATA
            | LIBXML_COMPACT;

        if (defined('LIBXML_NOXMLDECL')) {
            $options |= constant('LIBXML_NOXMLDECL');
        }

        if (defined('LIBXML_NOEMPTYTAG')) {
            $options |= constant('LIBXML_NOEMPTYTAG');
        }

        return $options;
    }
}
