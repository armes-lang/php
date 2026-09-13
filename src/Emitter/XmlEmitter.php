<?php

declare(strict_types=1);

namespace Armes\Emitter;

use Armes\Exception\MappingException;
use Armes\Mapper\TargetNode;

final class XmlEmitter implements EmitterInterface
{
    public function emit(mixed $node): string
    {
        if (!$node instanceof TargetNode) {
            throw new MappingException('XML emitter expects a mapped TargetNode.');
        }

        return $this->emitNode($node);
    }

    private function emitNode(TargetNode $node): string
    {
        return match ($node->kind) {
            TargetNode::FRAGMENT => implode('', array_map(fn (TargetNode $child): string => $this->emitNode($child), $node->children)),
            TargetNode::TEXT, TargetNode::RAW_TEXT => $this->escapeText((string) $node->value),
            TargetNode::COMMENT => '<!--' . $this->comment((string) $node->value) . '-->',
            TargetNode::DOCTYPE => '<!DOCTYPE ' . $this->doctype((string) $node->value) . '>',
            TargetNode::ELEMENT => $this->emitElement($node),
            default => '',
        };
    }

    private function emitElement(TargetNode $node): string
    {
        $name = (string) $node->name;
        return '<' . $name . $this->attributes($node->props) . '>'
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
            if ($value === null || $value === false) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            }
            $parts[] = $this->escapeName($key) . '="' . $this->escapeAttributeValue($value === true ? 'true' : (string) $value) . '"';
        }
        return $parts === [] ? '' : ' ' . implode(' ', $parts);
    }

    private function escapeText(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttributeValue(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeName(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}_:\\-.]/u', '', $value) ?: '';
    }

    private function comment(string $value): string
    {
        return str_replace('--', '- -', $value);
    }

    private function doctype(string $value): string
    {
        $value = trim($value);
        return $value === '' ? 'html' : $value;
    }
}
