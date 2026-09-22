<?php

declare(strict_types=1);
namespace Armes\Tests;

use Armes\Armes;
use Armes\Emitter\SourceSerializer;
use Armes\Parser\Json\JsonTagParser;
use Armes\Runtime\RuntimeResolver;
use Armes\Exception\RuntimeResolutionException;
use Armes\Tree\Node;
use PHPUnit\Framework\TestCase;

final class ReferenceLiteralTest extends TestCase
{
    public function testOpaqueContainersPreserveTheirKindsAndLiteralReferenceData(): void
    {
        $parser = new JsonTagParser();
        $tree = $parser->parseString(file_get_contents(__DIR__ . '/../fixtures/references/opaque-literals.input.json'));
        $source = json_encode(SourceSerializer::toSourceNode($tree), JSON_THROW_ON_ERROR);
        $again = $parser->parseString($source);
        self::assertSame(Node::VALUE, $again->props['object']->kind);
        self::assertSame('object', $again->props['object']->type);
        self::assertSame('array', $again->props['array']->type);
        $resolved = (new RuntimeResolver())->resolve($again);
        self::assertSame([':ref' => '$/child/Secret'], $resolved->props['object']);
        self::assertSame([[':ref' => 'some-data-id']], $resolved->props['array']);
    }

    public function testDisabledPhpRuntimeDoesNotStartExecutingNestedReferences(): void
    {
        $tree = (new JsonTagParser())->parseString('{"Widget":{"@":{"payload":{"external":{":ref":"some-data-id"}}}}}');
        $this->expectException(RuntimeResolutionException::class);
        $this->expectExceptionMessage('Unknown runtime node');
        (new RuntimeResolver())->resolve($tree);
    }
}
