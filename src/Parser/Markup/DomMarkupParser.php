<?php

declare(strict_types=1);

namespace Armes\Parser\Markup;

use Armes\Exception\ParseException;
use Armes\Runtime\LogicOperators;
use Armes\Runtime\ValueTypes;
use Armes\Tree\Node;
use DOMCdataSection;
use DOMComment;
use DOMDocument;
use DOMDocumentType;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
use DOMText;

final class DomMarkupParser
{
    /** @var array<string, string> */
    private array $preservedTagNames = [];

    /** @var array<string, string> */
    private array $preservedAttributeNames = [];

    /** @var array<string, true> */
    private const VOID_ELEMENTS = [
        'area' => true,
        'base' => true,
        'br' => true,
        'col' => true,
        'embed' => true,
        'hr' => true,
        'img' => true,
        'input' => true,
        'link' => true,
        'meta' => true,
        'param' => true,
        'source' => true,
        'track' => true,
        'wbr' => true,
    ];

    /** @var array<string, true> */
    private const RAW_TEXT_ELEMENTS = [
        'script' => true,
        'style' => true,
    ];

    /** @var array<string, true> */
    private const BOOLEAN_ATTRIBUTES = [
        'allowfullscreen' => true,
        'async' => true,
        'autofocus' => true,
        'autoplay' => true,
        'checked' => true,
        'controls' => true,
        'default' => true,
        'defer' => true,
        'disabled' => true,
        'formnovalidate' => true,
        'hidden' => true,
        'ismap' => true,
        'itemscope' => true,
        'loop' => true,
        'multiple' => true,
        'muted' => true,
        'nomodule' => true,
        'novalidate' => true,
        'open' => true,
        'readonly' => true,
        'required' => true,
        'reversed' => true,
        'selected' => true,
    ];

    public function parseHtmlFile(string $path, ?MarkupParseOptions $options = null): Node
    {
        if (!is_file($path)) {
            throw new ParseException(sprintf('HTML source "%s" does not exist.', $path));
        }

        $source = file_get_contents($path);
        if ($source === false) {
            throw new ParseException(sprintf('Unable to read HTML source "%s".', $path));
        }

        return $this->parseHtmlString($source, $path, $options);
    }

    public function parseArmesFile(string $path, ?MarkupParseOptions $options = null): Node
    {
        if (!is_file($path)) {
            throw new ParseException(sprintf('ARMES source "%s" does not exist.', $path));
        }

        $source = file_get_contents($path);
        if ($source === false) {
            throw new ParseException(sprintf('Unable to read ARMES source "%s".', $path));
        }

        return $this->parseArmesString($source, $path, $options);
    }

    public function parseArmesString(string $source, ?string $sourceName = null, ?MarkupParseOptions $options = null): Node
    {
        $options ??= new MarkupParseOptions(mode: MarkupParseOptions::MODE_ARMES);
        return $this->parseHtmlString($source, $sourceName, $options);
    }

    public function parseHtmlString(string $source, ?string $sourceName = null, ?MarkupParseOptions $options = null): Node
    {
        $options ??= new MarkupParseOptions();
        if (!$this->containsMarkupToken($source)) {
            return Node::value('string', $source, $this->meta($options, $sourceName, '/'));
        }

        $sourceHadDoctype = $this->sourceHasDoctype($source);
        $this->resetPreservedNames();

        $html = $options->fragment
            ? '<armes-fragment-root>' . $this->preserveUnsupportedNames($source, $sourceName) . '</armes-fragment-root>'
            : $this->preserveUnsupportedNames($source, $sourceName);

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($this->withUtf8Hint($html), $options->htmlLibxmlOptions());
        $errors = $this->collectLibxmlErrors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new ParseException('Unable to parse HTML source: ' . implode('; ', $errors));
        }

        if ($options->fragment) {
            $wrapper = $document->getElementsByTagName('armes-fragment-root')->item(0);
            if (!$wrapper instanceof DOMElement) {
                throw new ParseException('Unable to locate internal HTML fragment wrapper.');
            }

            return Node::fragment(
                $this->convertChildren($wrapper, $options, $sourceName, '/'),
                $this->meta($options, $sourceName, '/'),
            );
        }

        $children = [];
        $index = 0;
        foreach ($document->childNodes as $child) {
            $node = $this->convertNode($child, $options, $sourceName, '/' . $index, null, $sourceHadDoctype);
            if ($node !== null) {
                $children[] = $node;
            }
            $index++;
        }

        return count($children) === 1
            ? $children[0]
            : Node::fragment($children, $this->meta($options, $sourceName, '/'));
    }

    public function parseXmlFile(string $path, ?MarkupParseOptions $options = null): Node
    {
        if (!is_file($path)) {
            throw new ParseException(sprintf('XML source "%s" does not exist.', $path));
        }

        $source = file_get_contents($path);
        if ($source === false) {
            throw new ParseException(sprintf('Unable to read XML source "%s".', $path));
        }

        return $this->parseXmlString($source, $path, $options);
    }

    public function parseXmlString(string $source, ?string $sourceName = null, ?MarkupParseOptions $options = null): Node
    {
        $options ??= new MarkupParseOptions(mode: MarkupParseOptions::MODE_XML);
        $this->resetPreservedNames();
        $xml = $this->preserveUnsupportedNames($source, $sourceName);

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, $options->xmlLibxmlOptions());
        $errors = $this->collectLibxmlErrors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new ParseException('Unable to parse XML source: ' . implode('; ', $errors));
        }

        $children = [];
        $index = 0;
        foreach ($document->childNodes as $child) {
            $node = $this->convertNode($child, $options, $sourceName, '/' . $index, null, true);
            if ($node !== null) {
                $children[] = $node;
            }
            $index++;
        }

        return count($children) === 1
            ? $children[0]
            : Node::fragment($children, $this->meta($options, $sourceName, '/'));
    }

    /**
     * @return list<Node>
     */
    private function convertChildren(DOMNode $node, MarkupParseOptions $options, ?string $sourceName, string $pointer): array
    {
        $children = [];
        $index = 0;
        $parentName = $node instanceof DOMElement ? strtolower($node->tagName) : null;
        foreach ($node->childNodes as $child) {
            $converted = $this->convertNode($child, $options, $sourceName, $pointer . '/' . $index, $parentName, true);
            if ($converted !== null) {
                $children[] = $converted;
            }
            if ($child instanceof DOMElement && isset(self::VOID_ELEMENTS[strtolower($child->tagName)])) {
                array_push($children, ...$this->convertChildren($child, $options, $sourceName, $pointer . '/' . $index));
            }
            $index++;
        }
        return $children;
    }

    private function convertNode(
        DOMNode $node,
        MarkupParseOptions $options,
        ?string $sourceName,
        string $pointer,
        ?string $parentName,
        bool $sourceHadDoctype,
    ): ?Node {
        if ($node instanceof DOMProcessingInstruction) {
            return null;
        }

        if ($node instanceof DOMDocumentType) {
            if (!$options->preserveDoctype || !$sourceHadDoctype) {
                return null;
            }

            return Node::value('doctype', $this->doctypeValue($node), $this->meta($options, $sourceName, $pointer));
        }

        if ($node instanceof DOMElement) {
            $name = $this->restoreTagName($node->tagName);
            $props = $this->attributes($node);
            $children = isset(self::VOID_ELEMENTS[strtolower($name)])
                ? []
                : $this->convertChildren($node, $options, $sourceName, $pointer);

            $logicOp = $this->logicOperatorFromMarkupName($name);
            if ($logicOp !== null) {
                return Node::logic($logicOp, $children, $this->meta($options, $sourceName, $pointer, $node));
            }

            $typedName = $this->typedValueFromMarkupName($name);
            if ($typedName !== null) {
                return $this->typedValueNode($typedName, $children, $this->meta($options, $sourceName, $pointer, $node));
            }

            if (str_starts_with($name, ':')) {
                $runtimeName = substr($name, 1);
                if ($runtimeName === '') {
                    throw new ParseException($this->formatSourceError(
                        'Runtime markup node names must not be empty. DOMDocument parsed this tag as ":"; the original tag name is likely unsupported by the HTML parser.',
                        $sourceName,
                        $pointer,
                        $node,
                    ));
                }

                if ($runtimeName === 'logic') {
                    return count($children) === 1 ? $children[0] : Node::fragment($children, $this->meta($options, $sourceName, $pointer, $node));
                }

                $runtimeTypedName = ValueTypes::normalize($runtimeName);
                if ($runtimeTypedName !== null) {
                    return $this->typedValueNode($runtimeTypedName, $children, $this->meta($options, $sourceName, $pointer, $node));
                }

                $runtimeLogicOp = LogicOperators::normalize($runtimeName, true);
                if ($runtimeLogicOp !== null) {
                    return Node::logic($runtimeLogicOp, $children, $this->meta($options, $sourceName, $pointer, $node));
                }

                return Node::runtime($runtimeName, $props, $children, null, $this->meta($options, $sourceName, $pointer, $node));
            }

            return Node::element($name, $props, $children, $this->meta($options, $sourceName, $pointer, $node));
        }

        if ($node instanceof DOMComment) {
            if (!$options->preserveComments) {
                return null;
            }

            return Node::value('comment', $node->nodeValue ?? '', $this->meta($options, $sourceName, $pointer, $node));
        }

        if ($node instanceof DOMCdataSection) {
            $type = $parentName !== null && isset(self::RAW_TEXT_ELEMENTS[$parentName]) ? 'raw' : 'cdata';
            return Node::value($type, $node->nodeValue ?? '', $this->meta($options, $sourceName, $pointer, $node));
        }

        if ($node instanceof DOMText) {
            $value = $node->nodeValue ?? '';
            if (!$options->preserveWhitespace && trim($value) === '') {
                return null;
            }

            $type = $parentName !== null && isset(self::RAW_TEXT_ELEMENTS[$parentName]) ? 'raw' : 'string';
            return Node::value($type, $value, $this->meta($options, $sourceName, $pointer, $node));
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(DOMElement $element): array
    {
        $props = [];
        foreach ($element->attributes as $attribute) {
            $name = $attribute->nodeName;
            $name = $this->restoreAttributeName($name);
            $value = $attribute->nodeValue ?? '';
            $lowerName = strtolower($name);
            $isBooleanAttribute = isset(self::BOOLEAN_ATTRIBUTES[$lowerName])
                && ($value === '' || strcasecmp($value, $name) === 0);
            $props[$name] = $isBooleanAttribute ? true : $value;
        }
        return $props;
    }

    private function doctypeValue(DOMDocumentType $doctype): string
    {
        $value = $doctype->name;
        if ($doctype->publicId !== '') {
            $value .= ' PUBLIC "' . $doctype->publicId . '"';
        }
        if ($doctype->systemId !== '') {
            $value .= ' "' . $doctype->systemId . '"';
        }
        if (is_string($doctype->internalSubset) && $doctype->internalSubset !== '') {
            $value .= ' [' . $doctype->internalSubset . ']';
        }
        return $value;
    }

    private function withUtf8Hint(string $source): string
    {
        return '<?xml encoding="UTF-8">' . $source;
    }

    private function resetPreservedNames(): void
    {
        $this->preservedTagNames = [];
        $this->preservedAttributeNames = [];
    }

    private function preserveUnsupportedNames(string $source, ?string $sourceName): string
    {
        return preg_replace_callback(
            '/(<!--.*?-->|<!\[CDATA\[.*?\]\]>|<script\b[^>]*>.*?<\/script\s*>|<style\b[^>]*>.*?<\/style\s*>|<[^>]+>)/isu',
            function (array $match) use ($source, $sourceName): string {
                $tag = $match[0];
                $offset = $match[0][1] ?? null;
                if (is_array($tag)) {
                    $tag = $tag[0];
                }
                if (!is_string($tag) || $this->shouldSkipTagToken($tag)) {
                    return is_string($tag) ? $tag : '';
                }

                if ($this->isRawTextBlock($tag)) {
                    return $this->preserveRawTextBlock($tag, $source, $sourceName, is_int($offset) ? $offset : null);
                }

                return $this->preserveTagToken($tag, $source, $sourceName, is_int($offset) ? $offset : null);
            },
            $source,
            flags: PREG_OFFSET_CAPTURE,
        ) ?? $source;
    }

    private function shouldSkipTagToken(string $tag): bool
    {
        return preg_match('/^<\s*(?:!|\?)/u', $tag) === 1;
    }

    private function isRawTextBlock(string $tag): bool
    {
        return preg_match('/^<(script|style)\b/i', $tag) === 1
            && preg_match('/<\/(script|style)\s*>$/i', $tag) === 1;
    }

    private function preserveRawTextBlock(string $block, string $source, ?string $sourceName, ?int $offset): string
    {
        if (preg_match('/^(<(script|style)\b[^>]*>)(.*)(<\/\2\s*>)$/isu', $block, $matches) !== 1) {
            return $block;
        }

        $open = $this->preserveTagToken($matches[1], $source, $sourceName, $offset);
        $closeOffset = $offset === null ? null : $offset + strlen($matches[1]) + strlen($matches[3]);
        $close = $this->preserveTagToken($matches[4], $source, $sourceName, $closeOffset);

        return $open . $matches[3] . $close;
    }

    private function preserveTagToken(string $tag, string $source, ?string $sourceName, ?int $offset): string
    {
        if (preg_match('/^<\s*(\/?)\s*([^\s\/>]+)/u', $tag, $matches) !== 1) {
            return $tag;
        }

        $closing = $matches[1] === '/';
        $name = $matches[2];
        if (str_starts_with($name, ':')
            && !$this->isPortableRuntimeTagName($name)
            && LogicOperators::normalize(substr($name, 1), true) === null
            && ValueTypes::normalize(substr($name, 1)) === null) {
            throw new ParseException($this->formatSourceTextError(
                'Runtime markup node names after ":" must use portable ASCII names.',
                $source,
                $sourceName,
                $offset,
                $tag,
            ));
        }

        $result = $tag;
        if ($this->shouldPreserveName($name)) {
            $placeholder = $this->tagPlaceholder($name);
            $result = preg_replace('/^<(\s*\/?\s*)' . preg_quote($name, '/') . '/u', '<$1' . $placeholder, $result, 1) ?? $result;
        }

        if (!$closing) {
            $result = $this->preserveAttributeNames($result);
        }

        return $result;
    }

    private function preserveAttributeNames(string $tag): string
    {
        return preg_replace_callback(
            '/(\s+)([^\s\/=<>"\']+)(\s*(?:=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?)/u',
            function (array $matches): string {
                $name = $matches[2];
                if (!$this->shouldPreserveName($name)) {
                    return $matches[0];
                }

                return $matches[1] . $this->attributePlaceholder($name) . $matches[3];
            },
            $tag,
        ) ?? $tag;
    }

    private function shouldPreserveName(string $name): bool
    {
        return strlen($name) > 40 || preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1;
    }

    private function isPortableRuntimeTagName(string $name): bool
    {
        return preg_match('/^:[A-Za-z_][A-Za-z0-9_.-]*$/', $name) === 1;
    }

    private function logicOperatorFromMarkupName(string $name): ?string
    {
        if (!str_starts_with($name, ':logic:')) {
            return null;
        }

        return LogicOperators::normalize($name, true);
    }

    private function typedValueFromMarkupName(string $name): ?string
    {
        return str_starts_with($name, 'type:') ? ValueTypes::normalize($name) : null;
    }

    /**
     * @param list<Node> $children
     * @param array<string, mixed> $meta
     */
    private function typedValueNode(string $type, array $children, array $meta): Node
    {
        $rawValue = count($children) === 1 && $children[0]->kind === Node::VALUE
            ? $children[0]->value
            : array_map(static fn (Node $child): mixed => $child->kind === Node::VALUE ? $child->value : $child->toArray(), $children);

        return match ($type) {
            'string' => Node::value('string', is_scalar($rawValue) || $rawValue === null ? (string) $rawValue : json_encode($rawValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $meta),
            'int' => Node::value('int', (int) (is_scalar($rawValue) || $rawValue === null ? $rawValue : 0), $meta),
            'float' => Node::value('float', (float) (is_scalar($rawValue) || $rawValue === null ? $rawValue : 0), $meta),
            'bool' => Node::value('bool', $rawValue === true || strtolower((string) $rawValue) === 'true', $meta),
            'null' => Node::value('null', null, $meta),
            'array' => Node::value('array', is_array($rawValue) ? array_values($rawValue) : [$rawValue], $meta),
            'object' => Node::value('object', is_array($rawValue) ? $rawValue : [], $meta),
            default => Node::value($type, $rawValue, $meta),
        };
    }

    private function tagPlaceholder(string $name): string
    {
        $existing = array_search($name, $this->preservedTagNames, true);
        if (is_string($existing)) {
            return $existing;
        }

        $placeholder = 'armes-tag-' . count($this->preservedTagNames);
        $this->preservedTagNames[$placeholder] = $name;
        return $placeholder;
    }

    private function attributePlaceholder(string $name): string
    {
        $existing = array_search($name, $this->preservedAttributeNames, true);
        if (is_string($existing)) {
            return $existing;
        }

        $placeholder = 'data-armes-attr-' . count($this->preservedAttributeNames);
        $this->preservedAttributeNames[$placeholder] = $name;
        return $placeholder;
    }

    private function restoreTagName(string $name): string
    {
        return $this->preservedTagNames[strtolower($name)] ?? $name;
    }

    private function restoreAttributeName(string $name): string
    {
        return $this->preservedAttributeNames[strtolower($name)] ?? $name;
    }

    private function sourceHasDoctype(string $source): bool
    {
        return preg_match('/^\s*<!doctype\b/i', $source) === 1;
    }

    private function containsMarkupToken(string $source): bool
    {
        return preg_match(
            '/<!--.*?-->|<!\[CDATA\[.*?\]\]>|<!doctype\b[^>]*>|<\?xml\b[^>]*\?>|<\s*\/?\s*[:\p{L}_\.][^\s<>]*[^>]*>/isu',
            $source,
        ) === 1;
    }

    /**
     * @return list<string>
     */
    private function collectLibxmlErrors(): array
    {
        $messages = [];
        foreach (libxml_get_errors() as $error) {
            $message = trim($error->message);
            if ($message !== '') {
                $messages[] = $message;
            }
        }
        libxml_clear_errors();
        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(MarkupParseOptions $options, ?string $sourceName, string $pointer, ?DOMNode $node = null): array
    {
        if (!$options->includeMeta) {
            return [];
        }

        $meta = array_filter([
            'source' => $sourceName,
            'pointer' => $pointer,
        ], static fn (?string $value): bool => $value !== null);

        $line = $node?->getLineNo() ?? 0;
        if ($line > 0) {
            $meta['line'] = $line;
        }

        return $meta;
    }

    private function formatSourceError(string $message, ?string $sourceName, string $pointer, DOMNode $node): string
    {
        $line = $node->getLineNo();
        $location = $sourceName !== null ? $sourceName : 'markup source';
        if ($line > 0) {
            $location .= ':' . $line;
        }
        $location .= ' at ' . $pointer;

        $sourceLine = $this->sourceLine($sourceName, $line);
        if ($sourceLine !== null) {
            $location .= ' near "' . trim($sourceLine) . '"';
        }

        return $message . ' Source: ' . $location . '.';
    }

    private function formatSourceTextError(string $message, string $source, ?string $sourceName, ?int $offset, string $token): string
    {
        $line = $offset === null ? 0 : substr_count(substr($source, 0, $offset), "\n") + 1;
        $location = $sourceName !== null ? $sourceName : 'markup source';
        if ($line > 0) {
            $location .= ':' . $line;
        }
        $sourceLine = $line > 0 ? $this->sourceLineFromString($source, $line) : null;
        $location .= ' near "' . trim($sourceLine ?? $token) . '"';

        return $message . ' Source: ' . $location . '.';
    }

    private function sourceLineFromString(string $source, int $line): ?string
    {
        $lines = explode("\n", $source);
        return $lines[$line - 1] ?? null;
    }

    private function sourceLine(?string $sourceName, int $line): ?string
    {
        if ($sourceName === null || $line < 1 || !is_file($sourceName)) {
            return null;
        }

        $file = new \SplFileObject($sourceName);
        $file->seek($line - 1);
        if ($file->eof()) {
            return null;
        }

        return rtrim((string) $file->current());
    }
}
