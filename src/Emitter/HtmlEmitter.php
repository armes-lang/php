<?php

declare(strict_types=1);

namespace Armes\Emitter;

use Armes\Exception\MappingException;
use Armes\Mapper\TargetNode;

final class HtmlEmitter implements EmitterInterface
{
    /** @var array<string, true> */
    private const VOID_TAGS = [
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

    public function emit(mixed $node): string
    {
        if (!$node instanceof TargetNode) {
            throw new MappingException('HTML emitter expects a mapped TargetNode.');
        }

        return $this->emitNode($node);
    }

    private function emitNode(TargetNode $node): string
    {
        return match ($node->kind) {
            TargetNode::FRAGMENT => implode('', array_map(fn (TargetNode $child): string => $this->emitNode($child), $node->children)),
            TargetNode::TEXT => $this->escapeText((string) $node->value),
            TargetNode::RAW_TEXT => (string) $node->value,
            TargetNode::COMMENT => '<!--' . (string) $node->value . '-->',
            TargetNode::DOCTYPE => '<!doctype ' . $this->doctype((string) $node->value) . '>',
            TargetNode::ELEMENT => $this->emitElement($node),
            default => '',
        };
    }

    private function emitElement(TargetNode $node): string
    {
        $name = (string) $node->name;
        $attrs = $this->attributes($node->props);
        if (isset(self::VOID_TAGS[strtolower($name)])) {
            return '<' . $name . $attrs . '>';
        }
        return '<' . $name . $attrs . '>'
            . implode('', array_map(fn (TargetNode $child): string => $this->emitNode($child), $node->children))
            . '</' . $name . '>';
    }

    /**
     * @param array<string, mixed> $props
     */
    private function attributes(array $props): string
    {
        $parts = [];
        foreach ($props as $key => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            if ($value === true) {
                $parts[] = $this->escapeAttributeName($key);
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            }
            $parts[] = $this->escapeAttributeName($key) . '="' . $this->escapeAttributeValue((string) $value) . '"';
        }
        return $parts === [] ? '' : ' ' . implode(' ', $parts);
    }

    private function escapeText(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttributeValue(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttributeName(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}_:\\-.]/u', '', $value) ?: '';
    }

    private function doctype(string $value): string
    {
        $value = trim($value);
        return $value === '' ? 'html' : $value;
    }
}
