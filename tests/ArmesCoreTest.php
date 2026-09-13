<?php

declare(strict_types=1);

namespace Armes\Tests;

use Armes\Armes;
use Armes\Emitter\HtmlEmitter;
use Armes\Emitter\JsonEmitter;
use Armes\Emitter\JsxEmitter;
use Armes\Exception\ImportException;
use Armes\Exception\MappingException;
use Armes\Exception\ParseException;
use Armes\Exception\RuntimeResolutionException;
use Armes\Mapper\HtmlElementMapping;
use Armes\Mapper\HtmlMapper;
use Armes\Mapper\ReactComponent;
use Armes\Mapper\ReactMapper;
use Armes\Parser\Json\JsonTagParser;
use Armes\Parser\Markup\DomMarkupParser;
use Armes\Parser\Markup\MarkupParseOptions;
use Armes\Parser\Pkl\PklTagParser;
use Armes\Render\RenderTarget;
use Armes\Runtime\RuntimeResolver;
use Armes\Tree\Node;
use PHPUnit\Framework\TestCase;

if (PHP_SAPI !== 'cli') {
    // ini_set('display_errors', '1');
    // ini_set('display_startup_errors', '1');
    // error_reporting(E_ALL);

    require_once __DIR__ . '/../vendor/autoload.php';
}

final class ArmesCoreTest extends TestCase
{
    private JsonTagParser $parser;
    private Armes $core;

    protected function setUp(): void
    {
        $this->parser = new JsonTagParser();
        $this->core = new Armes($this->parser);
    }

    public function testArmesFileAndPublicNamingContract(): void
    {
        $tree = $this->core->parseArmesFile(__DIR__ . '/../fixtures/markup/hello.armes');
        $source = $this->core->sourceArmes($tree, false);
        self::assertSame('<section><h1>Hello</h1></section>', $this->core->renderHtml($this->core->parseArmes($source)));
        // Former names are deliberate negative assertions for the breaking rename.
        self::assertFalse(class_exists('Abstract\\AbstractCore'));
        self::assertFalse(method_exists($this->core, 'parseAml'));
        self::assertFalse(method_exists($this->core, 'sourceAml'));
    }

    public function testSimpleElementFixture(): void
    {
        $input = file_get_contents(__DIR__ . '/../fixtures/json/simple-element.input.json');
        self::assertIsString($input);

        $tree = $this->parser->parseString($input);
        self::assertSame(
            json_decode((string) file_get_contents(__DIR__ . '/../fixtures/json/simple-element.tree.json'), true),
            $tree->toArray(),
        );
        self::assertSame(
            trim((string) file_get_contents(__DIR__ . '/../fixtures/json/simple-element.html')),
            $this->core->renderHtml($tree),
        );
    }

    public function testPropsAndChildrenFixture(): void
    {
        $tree = $this->parser->parseFile(__DIR__ . '/../fixtures/json/props-and-children.input.json');
        self::assertSame(
            trim((string) file_get_contents(__DIR__ . '/../fixtures/json/props-and-children.html')),
            $this->core->renderHtml($tree),
        );
    }

    public function testNestedElementAndShorthandChildObject(): void
    {
        $tree = $this->parser->parseString('{"div":{"h1":"Title","p":"Body"}}');

        self::assertSame('<div><h1>Title</h1><p>Body</p></div>', $this->core->renderHtml($tree));
    }

    public function testShorthandArrayChildrenAndRepeatedElements(): void
    {
        $tree = $this->parser->parseString('{"ul":[{"li":"One"},{"li":"Two"}]}');

        self::assertSame('<ul><li>One</li><li>Two</li></ul>', $this->core->renderHtml($tree));
    }

    public function testPrimitiveTypeInference(): void
    {
        $tree = $this->parser->parseString('{"div":["hello",42,1.5,true,null]}');

        self::assertSame(['string', 'int', 'float', 'bool', 'null'], array_map(
            static fn (Node $node): ?string => $node->type,
            $tree->children,
        ));
    }

    public function testExplicitTypedNodesOverrideInference(): void
    {
        $tree = $this->parser->parseString('{"div":[{":type:string":42},{":type:int":"42"},{":type:float":"1.5"},{":type:bool":"true"},{":type:null":"ignored"},{":type:array":{"a":1}},{":type:object":{"a":1}}]}');
        $string = $this->parser->parseString('{":type:string":"x"}');
        $legacyString = $this->parser->parseString('{":string":"x"}');
        $bool = $this->parser->parseString('{":type:bool":true}');
        $legacyBool = $this->parser->parseString('{":boolean":true}');

        self::assertSame(['string', 'int', 'float', 'bool', 'null', 'array', 'object'], array_map(
            static fn (Node $node): ?string => $node->type,
            $tree->children,
        ));
        self::assertSame(['42', 42, 1.5, true, null, [1], ['a' => 1]], array_map(
            static fn (Node $node): mixed => $node->value,
            $tree->children,
        ));
        self::assertSame($string->type, $legacyString->type);
        self::assertSame($string->value, $legacyString->value);
        self::assertSame($bool->type, $legacyBool->type);
        self::assertSame($bool->value, $legacyBool->value);
        self::assertSame('{":type:bool":true}', $this->core->sourceJson($bool, false, explicitTypedValues: true));
    }

    public function testEveryDocumentedTypeCommandAndCompatibilityAliasNormalizes(): void
    {
        $cases = [
            'string' => ['input' => 42, 'expected' => '42', 'aliases' => [':string']],
            'int' => ['input' => '42', 'expected' => 42, 'aliases' => [':int', ':integer', ':type:integer']],
            'float' => ['input' => '1.5', 'expected' => 1.5, 'aliases' => [':float']],
            'bool' => ['input' => 'true', 'expected' => true, 'aliases' => [':bool', ':boolean', ':type:boolean']],
            'null' => ['input' => 'ignored', 'expected' => null, 'aliases' => [':null']],
            'array' => ['input' => ['a' => 1], 'expected' => [1], 'aliases' => [':array']],
            'object' => ['input' => ['a' => 1], 'expected' => ['a' => 1], 'aliases' => [':object']],
        ];

        foreach ($cases as $type => $case) {
            $preferred = $this->core->parseJson((string) json_encode([':type:' . $type => $case['input']], JSON_THROW_ON_ERROR));
            self::assertSame(Node::VALUE, $preferred->kind, $type);
            self::assertSame($type, $preferred->type, $type);
            self::assertSame($case['expected'], $preferred->value, $type);

            foreach ($case['aliases'] as $alias) {
                $compatible = $this->core->parseJson((string) json_encode([$alias => $case['input']], JSON_THROW_ON_ERROR));
                self::assertSame($type, $compatible->type, $alias);
                self::assertSame($case['expected'], $compatible->value, $alias);
            }
        }
    }

    public function testAttributesModifierPatchesParentProps(): void
    {
        $tree = $this->parser->parseString('{"div":[{":attributes":{"class":"card"}},{"span":"Hello"}]}');

        self::assertSame('<div class="card"><span>Hello</span></div>', $this->core->renderHtml($tree));
    }

    public function testPropsModifierPatchesComponentProps(): void
    {
        $tree = $this->parser->parseString('{"Button":[{":props":{"variant":"primary","disabled":false}},"Save"]}');

        self::assertSame('<Button variant="primary">Save</Button>', $this->core->renderHtml($tree));
        self::assertSame('<Button variant="primary" disabled={false}>Save</Button>', $this->core->renderJsx($tree));
    }

    public function testPropsModifierCanUseRuntimeExpressions(): void
    {
        $tree = $this->parser->parseString('{"User":[{":props":{"name":{":expr":{"var":"user.name"}}}}]}');

        self::assertSame('<User name="Ada"></User>', $this->core->renderHtml($tree, ['user' => ['name' => 'Ada']]));
    }

    public function testMultiplePropModifiersUseDeterministicMergeOrder(): void
    {
        $tree = $this->parser->parseString('{"div":{"@":{"class":"first","id":"a"},"#":[{":attributes":{"class":"second"}},{":props":{"id":"b"}}]}}');

        self::assertSame('<div class="second" id="b"></div>', $this->core->renderHtml($tree));
    }

    public function testAttributesWithoutParentIsInvalidInStrictMode(): void
    {
        $this->expectException(RuntimeResolutionException::class);

        $this->core->resolve($this->parser->parseString('{":attributes":{"class":"card"}}'));
    }

    public function testExprVarLookupAsChildAndProp(): void
    {
        $tree = $this->parser->parseString('{"User":{"@":{"name":{":expr":{"var":"user.name"}}},"#":[{":expr":{"var":"user.name"}}]}}');

        self::assertSame('<User name="Ada">Ada</User>', $this->core->renderHtml($tree, ['user' => ['name' => 'Ada']]));
    }

    public function testExprComparison(): void
    {
        $tree = $this->parser->parseString('{":expr":{"==":[{"var":"user.role"},"admin"]}}');
        $resolved = $this->core->resolve($tree, ['user' => ['role' => 'admin']]);

        self::assertSame(Node::VALUE, $resolved->kind);
        self::assertSame('bool', $resolved->type);
        self::assertTrue($resolved->value);
    }

    public function testJsonLogicOperatorAliasesNormalizeAndEvaluate(): void
    {
        $qualified = $this->parser->parseString('{":logic:eq":[{":type:bool":true},{":type:int":1}]}');
        $eq = $this->parser->parseString('{":==":[{":type:bool":true},{":type:int":1}]}');
        $fallback = $this->parser->parseString('{":eq":[{":type:bool":true},{":type:int":1}]}');
        $ne = $this->parser->parseString('{":!=":[{":type:bool":false},{":type:int":0}]}');
        $nested = $this->parser->parseString('{":logic:and":[{":==":[{":type:bool":true},{":type:int":1}]},{":logic:ne":[{":type:bool":false},{":type:int":1}]}]}');
        $wrapped = $this->parser->parseString('{":logic":{":eq":[{":type:bool":true},{":type:int":1}]}}');
        $mod = $this->parser->parseString('{":%":[{":type:int":5},{":type:int":2}]}');
        $element = $this->parser->parseString('{"logic:eq":[true,1]}');

        self::assertSame(Node::LOGIC, $qualified->kind);
        self::assertSame('eq', $qualified->op);
        self::assertSame(Node::LOGIC, $eq->kind);
        self::assertSame('eq', $eq->op);
        self::assertSame('eq', $fallback->op);
        self::assertSame('ne', $ne->op);
        self::assertSame('and', $nested->op);
        self::assertSame('eq', $wrapped->op);
        self::assertSame([true, 1], array_map(static fn (Node $arg): mixed => $arg->value, $qualified->args));
        self::assertSame(Node::ELEMENT, $element->kind);
        self::assertSame('logic:eq', $element->name);
        self::assertTrue($this->core->resolve($eq)->value);
        self::assertTrue($this->core->resolve($nested)->value);
        self::assertSame(1, $this->core->resolve($mod)->value);
    }

    public function testEveryDocumentedLogicCommandNormalizesAndEvaluates(): void
    {
        $cases = [
            'eq' => ['args' => [1, '1'], 'expected' => true, 'symbol' => '=='],
            'ne' => ['args' => [1, 2], 'expected' => true, 'symbol' => '!='],
            'gt' => ['args' => [2, 1], 'expected' => true, 'symbol' => '>'],
            'gte' => ['args' => [2, 2], 'expected' => true, 'symbol' => '>='],
            'lt' => ['args' => [1, 2], 'expected' => true, 'symbol' => '<'],
            'lte' => ['args' => [2, 2], 'expected' => true, 'symbol' => '<='],
            'and' => ['args' => [true, 1], 'expected' => true],
            'or' => ['args' => [false, 1], 'expected' => true],
            'not' => ['args' => [false], 'expected' => true, 'symbol' => '!'],
            'add' => ['args' => [2, 3], 'expected' => 5, 'symbol' => '+'],
            'sub' => ['args' => [5, 2], 'expected' => 3, 'symbol' => '-'],
            'mul' => ['args' => [2, 3], 'expected' => 6, 'symbol' => '*'],
            'div' => ['args' => [8, 2], 'expected' => 4, 'symbol' => '/'],
            'mod' => ['args' => [5, 2], 'expected' => 1, 'symbol' => '%'],
            'var' => ['args' => ['user.name'], 'expected' => 'Ada', 'context' => ['user' => ['name' => 'Ada']]],
        ];

        foreach ($cases as $op => $case) {
            $preferred = $this->core->parseJson((string) json_encode([':logic:' . $op => $case['args']], JSON_THROW_ON_ERROR));
            $fallback = $this->core->parseJson((string) json_encode([':' . $op => $case['args']], JSON_THROW_ON_ERROR));
            $wrapped = $this->core->parseJson((string) json_encode([':logic' => [':' . $op => $case['args']]], JSON_THROW_ON_ERROR));

            foreach ([$preferred, $fallback, $wrapped] as $node) {
                self::assertSame(Node::LOGIC, $node->kind, $op);
                self::assertSame($op, $node->op, $op);
            }

            if (isset($case['symbol'])) {
                $symbol = $this->core->parseJson((string) json_encode([':' . $case['symbol'] => $case['args']], JSON_THROW_ON_ERROR));
                self::assertSame($op, $symbol->op, ':' . $case['symbol']);
            }

            $resolved = $this->core->resolve($preferred, $case['context'] ?? []);
            self::assertSame($case['expected'], $resolved->value, $op);
            self::assertStringContainsString('":logic:' . $op . '"', $this->core->sourceJson($preferred, false));
        }
    }

    public function testUnknownExplicitTypeCommandsErrorInStrictModeAndPreserveInLooseMode(): void
    {
        try {
            $this->parser->parseString('{":type:custom":"x"}');
            self::fail('Expected unknown :type command to throw.');
        } catch (ParseException $exception) {
            self::assertStringContainsString('Unknown ARMES Type ":type:custom"', $exception->getMessage());
        }

        $element = $this->parser->parseString('{"type:string":"x"}');
        self::assertSame(Node::ELEMENT, $element->kind);
        self::assertSame('type:string', $element->name);

        $diagnostics = [];
        $loose = $this->core->parseJson('{":type:custom":"x"}', strict: false, diagnostics: $diagnostics);
        self::assertSame(Node::RUNTIME, $loose->kind);
        self::assertSame('type:custom', $loose->name);
        self::assertSame('x', $loose->value);
        self::assertStringContainsString('Unknown ARMES Type ":type:custom"', $diagnostics[0]['message']);
    }

    public function testUnknownExplicitLogicOperatorsErrorInStrictModeAndPreserveInLooseMode(): void
    {
        try {
            $this->parser->parseString('{":logic":{"eq":[1,2]}}');
            self::fail('Expected unknown :logic operator to throw.');
        } catch (ParseException $exception) {
            self::assertStringContainsString('Unknown ARMES Logic operator "eq"', $exception->getMessage());
        }

        try {
            $this->parser->parseString('{":logic:xor":[1,2]}');
            self::fail('Expected unknown logic namespace operator to throw.');
        } catch (ParseException $exception) {
            self::assertStringContainsString('Unknown ARMES Logic operator ":logic:xor"', $exception->getMessage());
        }

        $wrappedDiagnostics = [];
        $wrapped = $this->core->parseJson('{":logic":{"eq":[1,2]}}', strict: false, diagnostics: $wrappedDiagnostics);
        self::assertSame(Node::RUNTIME, $wrapped->kind);
        self::assertSame('logic', $wrapped->name);
        self::assertSame(['eq' => [1, 2]], $wrapped->value);
        self::assertStringContainsString('Unknown ARMES Logic operator "eq"', $wrappedDiagnostics[0]['message']);

        $qualifiedDiagnostics = [];
        $qualified = $this->core->parseJson('{":logic:xor":[1,2]}', strict: false, diagnostics: $qualifiedDiagnostics);
        self::assertSame(Node::RUNTIME, $qualified->kind);
        self::assertSame('logic:xor', $qualified->name);
        self::assertStringContainsString('Unknown ARMES Logic operator ":logic:xor"', $qualifiedDiagnostics[0]['message']);
    }

    public function testArmesLogicOperatorAliasesAndSourceEmission(): void
    {
        $options = new MarkupParseOptions(mode: MarkupParseOptions::MODE_ARMES, preserveWhitespace: false, includeMeta: false);
        $scoped = $this->core->parseArmes(<<<'ARMES'
	<:logic>
	  <:logic:and>
	    <:==><:type:bool>true</:type:bool><:type:int>1</:type:int></:==>
	    <:logic:ne><:type:bool>false</:type:bool><:type:int>0</:type:int></:logic:ne>
	  </:logic:and>
	</:logic>
	ARMES, options: $options);
        $prefixed = $this->core->parseArmes('<:logic:eq><:type:bool>true</:type:bool><:type:int>1</:type:int></:logic:eq>', options: $options);
        $fallback = $this->core->parseArmes('<:eq><:type:bool>true</:type:bool><:type:int>1</:type:int></:eq>', options: $options);
        $readable = $this->core->parseArmes('<:logic:eq><:type:bool>true</:type:bool><:type:int>1</:type:int></:logic:eq>', options: $options);
        $legacyTyped = $this->core->parseArmes('<:logic:eq><type:boolean>true</type:boolean><:int>1</:int></:logic:eq>', options: $options);
        $xmlLogic = $this->core->parseXml('<:logic:eq>true</:logic:eq>');
        $xmlTyped = $this->core->parseXml('<:type:bool>true</:type:bool>');

        self::assertSame(Node::LOGIC, $scoped->kind);
        self::assertSame('and', $scoped->op);
        self::assertSame('eq', $prefixed->op);
        self::assertSame('eq', $fallback->op);
        self::assertSame('eq', $legacyTyped->op);
        self::assertSame('eq', $xmlLogic->op);
        self::assertSame(Node::VALUE, $xmlTyped->kind);
        self::assertSame('bool', $xmlTyped->type);
        self::assertSame($prefixed->op, $readable->op);
        self::assertSame($prefixed->args[0]->value, $readable->args[0]->value);
        self::assertSame($legacyTyped->args[0]->value, $readable->args[0]->value);
        self::assertSame('<:logic:eq><:type:bool>true</:type:bool><:type:int>1</:type:int></:logic:eq>', $this->core->sourceArmes($readable, false));
        self::assertSame('<:==><:type:bool>true</:type:bool><:type:int>1</:type:int></:==>', $this->core->sourceArmes($readable, false, 'symbol'));
        self::assertSame('{":logic:eq":[true,1]}', $this->core->sourceJson($readable, false));
        self::assertSame('{":==":[true,1]}', $this->core->sourceJson($readable, false, 'symbol'));
        self::assertSame('<:type:bool>true</:type:bool>', $this->core->sourceArmes(Node::value('bool', true), false, explicitTypedValues: true));
    }

    public function testIfTrueBranchFixture(): void
    {
        $tree = $this->parser->parseFile(__DIR__ . '/../fixtures/runtime/logic-if.input.json');
        $context = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/runtime/logic-if.context.json'), true);

        self::assertSame(
            trim((string) file_get_contents(__DIR__ . '/../fixtures/runtime/logic-if.html')),
            $this->core->renderHtml($tree, $context),
        );
    }

    public function testIfElseBranch(): void
    {
        $tree = $this->parser->parseFile(__DIR__ . '/../fixtures/runtime/logic-if.input.json');

        self::assertSame('<Login></Login>', $this->core->renderHtml($tree, ['user' => ['role' => 'guest']]));
    }

    public function testEachLoop(): void
    {
        $tree = $this->parser->parseString('{"ul":[{":each":{"@":{"items":{":expr":{"var":"users"}},"as":"user"},"#":[{"li":{":expr":{"var":"user.name"}}}]}}]}');

        self::assertSame('<ul><li>Ada</li><li>Grace</li></ul>', $this->core->renderHtml($tree, [
            'users' => [
                ['name' => 'Ada'],
                ['name' => 'Grace'],
            ],
        ]));
    }

    public function testSimpleImportAndImportWithPropsAndSlotChildren(): void
    {
        $tree = $this->parser->parseFile(__DIR__ . '/../fixtures/import/page.input.json');

        self::assertSame(
            trim((string) file_get_contents(__DIR__ . '/../fixtures/import/expected.html')),
            $this->core->renderHtml($tree),
        );
    }

    public function testIncludeUsesTheDocumentedImportCompatibilityBehavior(): void
    {
        $tree = $this->parser->parseString(
            '{":include":"./components/Header.armes.json"}',
            __DIR__ . '/../fixtures/import/page.input.json',
        );

        self::assertSame('<header class="site-header"><h1>ARMES</h1></header>', $this->core->renderHtml($tree));
    }

    public function testEveryDocumentedStructuralValueCommandParsesAsAValue(): void
    {
        $expectedTypes = [
            'comment' => 'comment',
            'doctype' => 'doctype',
            'cdata' => 'cdata',
            'raw' => 'raw',
            'text' => 'string',
        ];

        foreach ($expectedTypes as $command => $type) {
            $node = $this->core->parseJson((string) json_encode([':' . $command => 'content'], JSON_THROW_ON_ERROR));
            self::assertSame(Node::VALUE, $node->kind, $command);
            self::assertSame($type, $node->type, $command);
        }
    }

    public function testCircularImportError(): void
    {
        $this->expectException(ImportException::class);

        $tree = $this->parser->parseFile(__DIR__ . '/../fixtures/import/components/CycleA.armes.json');
        $this->core->resolve($tree);
    }

    public function testMissingImportError(): void
    {
        $this->expectException(ImportException::class);

        $tree = $this->parser->parseString('{":import":"./missing.armes.json"}', __DIR__ . '/../fixtures/import/page.input.json');
        $this->core->resolve($tree);
    }

    public function testUnknownRuntimeStrictModeError(): void
    {
        $this->expectException(RuntimeResolutionException::class);

        $this->core->resolve($this->parser->parseString('{":vendor.unknown":"x"}'));
    }

    public function testUnknownRuntimeLooseModeWarnsAndDropsSafely(): void
    {
        $resolver = new RuntimeResolver(false, $this->parser);
        $resolved = $resolver->resolve($this->parser->parseString('{"div":[{":vendor.unknown":"x"},"safe"]}'));

        self::assertSame('<div>safe</div>', (new HtmlEmitter())->emit((new HtmlMapper(false))->map($resolved)));
        self::assertCount(1, $resolver->diagnostics());
    }

    public function testHtmlEscaping(): void
    {
        $tree = $this->parser->parseString('{"div":{"@":{"title":"<unsafe> & \"quoted\""},"#":["<script>"]}}');

        self::assertSame('<div title="&lt;unsafe&gt; &amp; &quot;quoted&quot;">&lt;script&gt;</div>', $this->core->renderHtml($tree));
    }

    public function testReactJsxOutputEscapesTextAndMapsClassName(): void
    {
        $tree = $this->parser->parseString('{"div":{"@":{"class":"card","count":2},"#":["{hello}<"]}}');
        $resolved = $this->core->resolve($tree);

        self::assertSame('<div count={2} className="card">&#123;hello&#125;&lt;</div>', (new JsxEmitter())->emit((new ReactMapper())->map($resolved)));
    }

    public function testMapperRejectsUnresolvedRuntimeInStrictMode(): void
    {
        $this->expectException(MappingException::class);

        (new HtmlMapper())->map($this->parser->parseString('{":expr":{"var":"x"}}'));
    }

    public function testPayloadNodesAreRejectedByStrictDefaultRuntime(): void
    {
        foreach (['php', 'js', 'ts', 'code'] as $directive) {
            try {
                $this->core->resolve($this->parser->parseString((string) json_encode([':' . $directive => 'payload'], JSON_THROW_ON_ERROR)));
                self::fail('Expected :' . $directive . ' to be rejected.');
            } catch (RuntimeResolutionException $exception) {
                self::assertStringContainsString('not executable or renderable', $exception->getMessage());
            }
        }

        $this->expectException(RuntimeResolutionException::class);
        $this->expectExceptionMessage('only valid inside ":if"');
        $this->core->resolve($this->parser->parseString('{":elseif":[]}'));
    }

    public function testMarkupParserParsesFullHtmlDocumentFixture(): void
    {
        $parser = new DomMarkupParser();
        $tree = $parser->parseHtmlFile(__DIR__ . '/../fixtures/markup/simple-document.input.html', new MarkupParseOptions(includeMeta: false));

        self::assertSame(
            trim((string) file_get_contents(__DIR__ . '/../fixtures/markup/simple-document.html')),
            (new HtmlEmitter())->emit((new HtmlMapper())->map($tree)),
        );
        self::assertSame(
            trim((string) file_get_contents(__DIR__ . '/../fixtures/markup/simple-document.compact.json')),
            (new JsonEmitter())->emitCompactTree($tree),
        );
    }

    public function testTextOnlyMarkupDoesNotBecomeParagraph(): void
    {
        $tree = $this->core->parseHtmlFile(__DIR__ . '/../examples/00-text-markup-roundtrip.source.html', new MarkupParseOptions(includeMeta: false));
        $compactJson = $this->core->treeJson($tree, pretty: false, mode: JsonEmitter::MODE_COMPACT);

        self::assertSame(Node::VALUE, $tree->kind);
        self::assertSame('Hello Test', $tree->value);
        self::assertSame('"Hello Test"', $compactJson);
        self::assertSame('Hello Test', $this->core->renderHtml($this->core->parseJson($compactJson)));
    }

    public function testTextOnlyMarkupPreservesWhitespaceAndPunctuation(): void
    {
        $source = "  Hello, friend! Are you ready?\n";
        $tree = $this->core->parseHtml($source, options: new MarkupParseOptions(includeMeta: false));

        self::assertSame(Node::VALUE, $tree->kind);
        self::assertSame($source, $tree->value);
        self::assertSame($source, $this->core->renderHtml($tree));
    }

    public function testMarkupCompactJsonCanReparseAndRender(): void
    {
        $parser = new DomMarkupParser();
        $tree = $parser->parseHtmlString(
            '<div class="card">Hi<!--saved--><br><script>if (a < b) alert("&");</script></div>',
            options: new MarkupParseOptions(fragment: true, includeMeta: false),
        );

        $json = (new JsonEmitter())->emitCompactTree($tree);
        $reparsed = $this->parser->parseString($json);

        self::assertSame(
            '<div class="card">Hi<!--saved--><br><script>if (a < b) alert("&");</script></div>',
            (new HtmlEmitter())->emit((new HtmlMapper())->map($reparsed)),
        );
    }

    public function testMarkupParserPreservesUtf8AndRawScriptText(): void
    {
        $parser = new DomMarkupParser();
        $tree = $parser->parseHtmlString(
            '<section><p>สวัสดี</p><style>.a > .b { content: "&"; }</style></section>',
            options: new MarkupParseOptions(fragment: true, includeMeta: false),
        );

        self::assertSame(
            '<section><p>สวัสดี</p><style>.a > .b { content: "&"; }</style></section>',
            (new HtmlEmitter())->emit((new HtmlMapper())->map($tree)),
        );
    }

    public function testMarkupParserPreservesNonEnglishAndLongNames(): void
    {
        $longName = str_repeat('hello', 45);
        $source = '<root><กรรม ทดสอบ="20">ทดสอบ</กรรม><Timothée>ok</Timothée><xsl:if>GOF</xsl:if><' . $longName . '>123</' . $longName . '></root>';

        $tree = (new DomMarkupParser())->parseHtmlString(
            $source,
            options: new MarkupParseOptions(fragment: true, includeMeta: false),
        );

        self::assertSame(
            $source,
            (new HtmlEmitter())->emit((new HtmlMapper())->map($tree)),
        );
        self::assertStringContainsString('"กรรม"', (new JsonEmitter())->emitCompactTree($tree, true));
        self::assertStringContainsString('"ทดสอบ"', (new JsonEmitter())->emitCompactTree($tree, true));
        self::assertStringContainsString('"Timothée"', (new JsonEmitter())->emitCompactTree($tree, true));
        self::assertStringContainsString('"xsl:if"', (new JsonEmitter())->emitCompactTree($tree, true));
    }

    public function testMarkupStyleTypedRuntimeBodiesParseAsExplicitValues(): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $string = $this->parser->parseString('{":string":{"#":["456"]}}');
            $int = $this->parser->parseString('{":int":{"#":["900"]}}');
        } finally {
            restore_error_handler();
        }

        self::assertSame(Node::VALUE, $string->kind);
        self::assertSame('456', $string->value);
        self::assertSame(Node::VALUE, $int->kind);
        self::assertSame(900, $int->value);
    }

    public function testMarkupParserLiftsDomDocumentVoidElementChildren(): void
    {
        $parser = new DomMarkupParser();
        $tree = $parser->parseHtmlString(
            '<picture><source srcset="large.png"><source srcset="small.png"><img src="fallback.png"></picture>',
            options: new MarkupParseOptions(fragment: true, includeMeta: false),
        );

        self::assertSame(
            '<picture><source srcset="large.png"><source srcset="small.png"><img src="fallback.png"></picture>',
            (new HtmlEmitter())->emit((new HtmlMapper())->map($tree)),
        );
    }

    public function testMarkupParserCanCreateRuntimeNodesWithoutRenderingThemLiterally(): void
    {
        $parser = new DomMarkupParser();
        $tree = $parser->parseHtmlString(
            '<:if><span>ok</span></:if>',
            options: new MarkupParseOptions(fragment: true, includeMeta: false),
        );

        self::assertSame(Node::FRAGMENT, $tree->kind);
        self::assertSame(Node::RUNTIME, $tree->children[0]->kind);
        self::assertSame('if', $tree->children[0]->name);

        $this->expectException(MappingException::class);
        (new HtmlMapper())->map($tree);
    }

    public function testMarkupParserReportsSourceLineForUnsupportedRuntimeTagName(): void
    {
        $path = __DIR__ . '/../fixtures/markup/invalid-runtime-name.input.html';

        try {
            (new DomMarkupParser())->parseHtmlFile($path, new MarkupParseOptions(includeMeta: false));
            self::fail('Expected unsupported runtime tag name parse error.');
        } catch (ParseException $exception) {
            self::assertStringContainsString($path . ':3', $exception->getMessage());
            self::assertStringContainsString('<:กรรม>bad</:กรรม>', $exception->getMessage());
        }
    }

    public function testXmlParseAndRenderRoundtrip(): void
    {
        $tree = $this->core->parseXml(
            '<root><item id="1">Hi &amp; Bye</item><empty></empty></root>',
            options: new MarkupParseOptions(mode: MarkupParseOptions::MODE_XML, includeMeta: false),
        );

        self::assertSame('<root><item id="1">Hi &amp; Bye</item><empty></empty></root>', $this->core->renderXml($tree));
    }

    public function testYamlParsesTagKeySyntaxAndRendersYaml(): void
    {
        $tree = $this->core->parseYaml(<<<'YAML'
div:
  '@':
    class: card
  '#':
    - span: Hello
    - ':if':
        '@':
          test:
            ':expr':
              var: show
        '#':
          - strong: Yes
        ':else':
          - em: No
YAML);

        self::assertSame('<div class="card"><span>Hello</span><strong>Yes</strong></div>', $this->core->renderHtml($tree, ['show' => true]));
        self::assertStringContainsString("class: card", $this->core->renderYaml($tree, ['show' => true]));
    }

    public function testYamlScalarRootParsesAsValue(): void
    {
        $tree = $this->core->parseYaml('Hello Test');

        self::assertSame(Node::VALUE, $tree->kind);
        self::assertSame('Hello Test', $tree->value);
    }

    public function testTomlParsesObjectRootsAndRendersToml(): void
    {
        $tree = $this->core->parseToml(<<<'TOML'
[div]
"#" = ["Hello"]

[div."@"]
class = "card"
TOML);

        self::assertSame('<div class="card">Hello</div>', $this->core->renderHtml($tree));
        $toml = $this->core->renderToml($tree);
        self::assertStringContainsString('[div]', $toml);
        self::assertSame('<div class="card">Hello</div>', $this->core->renderHtml($this->core->parseToml($toml)));
    }

    public function testTomlRejectsScalarDocumentAndScalarOutput(): void
    {
        $this->expectException(ParseException::class);
        $this->core->parseToml('"Hello"');
    }

    public function testTomlScalarTreeOutputIsInvalid(): void
    {
        $this->expectException(MappingException::class);
        $this->core->renderToml(Node::value('string', 'Hello'));
    }

    public function testPklParsesThroughCli(): void
    {
        $this->skipWhenPklIsMissing();

        $tree = $this->core->parsePkl(<<<'PKL'
div = new Mapping {
  ["@"] = new Mapping { ["class"] = "card" }
  ["#"] = List("Hello")
}
PKL);

        self::assertSame('<div class="card">Hello</div>', $this->core->renderHtml($tree));
    }

    public function testPklReportsUnavailableCliClearly(): void
    {
        $this->expectException(ParseException::class);
        (new PklTagParser(binary: '/definitely/missing/armes-pkl'))->parseString('div = "Hello"');
    }

    public function testPklRenderSyntaxValidatesThroughCli(): void
    {
        $this->skipWhenPklIsMissing();

        $tree = $this->parser->parseString('{"div":{"@":{"class":"card"},"#":["Hello",{"span":"World"}]}}');
        $pkl = $this->core->renderPkl($tree);
        $reparsed = $this->core->parsePkl($pkl);

        self::assertSame('<div class="card">Hello<span>World</span></div>', $this->core->renderHtml($reparsed));
    }

    public function testDataFormatParsersNormalizeEquivalentSources(): void
    {
        $this->skipWhenPklIsMissing();

        $json = $this->parser->parseFile(__DIR__ . '/../fixtures/formats/equivalent.input.json');
        $yaml = $this->core->parseYamlFile(__DIR__ . '/../fixtures/formats/equivalent.input.yaml');
        $toml = $this->core->parseTomlFile(__DIR__ . '/../fixtures/formats/equivalent.input.toml');
        $pkl = $this->core->parsePklFile(__DIR__ . '/../fixtures/formats/equivalent.input.pkl');
        $emitter = new JsonEmitter();
        $expected = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/formats/equivalent.compact.json'), true);

        self::assertSame($expected, $emitter->toData($json, JsonEmitter::MODE_COMPACT));
        self::assertSame($emitter->toData($json, JsonEmitter::MODE_COMPACT), $emitter->toData($yaml, JsonEmitter::MODE_COMPACT));
        self::assertSame($emitter->toData($json, JsonEmitter::MODE_COMPACT), $emitter->toData($toml, JsonEmitter::MODE_COMPACT));
        self::assertSame($emitter->toData($json, JsonEmitter::MODE_COMPACT), $emitter->toData($pkl, JsonEmitter::MODE_COMPACT));
    }

    public function testGenericRenderUsesDefaultHtmlTarget(): void
    {
        $tree = $this->parser->parseString('{"div":"Hello"}');

        self::assertSame('<div>Hello</div>', $this->core->render('html', $tree));
    }

    public function testDefaultJsxInputRemainsNativeWithoutCustomMapping(): void
    {
        $tree = $this->parser->parseString('{"input":[{":props":{"type":"text","name":"email"}}]}');

        self::assertSame('<input type="text" name="email" />', $this->core->renderJsx($tree));
    }

    public function testArmesCoreCanUseCustomHtmlMapper(): void
    {
        $core = Armes::default()->withRenderTarget('html', RenderTarget::make(
            HtmlMapper::make()->element('input', HtmlElementMapping::tag('x-input')),
            new HtmlEmitter(),
        ));
        $tree = $core->parseJson('{"input":[{":props":{"type":"text","name":"email"}}, "Child"]}');

        self::assertSame('<x-input type="text" name="email">Child</x-input>', $core->renderHtml($tree));
    }

    public function testArmesCoreCanUseCustomReactMapperWithImports(): void
    {
        $core = Armes::default()->withRenderTarget('jsx', RenderTarget::make(
            ReactMapper::make()->component('input', ReactComponent::imported(
                source: '@headlessui/react',
                export: 'Input',
                as: 'HeadlessInput',
            )),
            new JsxEmitter(),
        ));
        $tree = $core->parseJson('{"input":[{":props":{"type":"text","name":"email","className":"border"}}]}');

        self::assertSame(
            'import { Input as HeadlessInput } from "@headlessui/react";' . "\n\n" . '<HeadlessInput type="text" name="email" className="border" />',
            $core->renderJsx($tree),
        );
    }

    public function testCustomReactNamespacedMappingAndImportDeduplication(): void
    {
        $component = ReactComponent::imported(
            source: '@headlessui/react',
            export: 'Input',
            as: 'HeadlessInput',
        );
        $core = Armes::default()->withRenderTarget('jsx', RenderTarget::make(
            ReactMapper::make()
                ->component('input', $component)
                ->component('ui.input', $component),
            new JsxEmitter(),
        ));
        $tree = $core->parseJson('[{"input":[{":props":{"name":"email"}}]},{"ui.input":[{":props":{"name":"phone"}}]}]');

        self::assertSame(
            'import { Input as HeadlessInput } from "@headlessui/react";' . "\n\n" . '<HeadlessInput name="email" /><HeadlessInput name="phone" />',
            $core->renderJsx($tree),
        );
    }

    public function testConfigDrivenTargetCustomization(): void
    {
        $core = Armes::fromConfig([
            'targets' => [
                'html' => [
                    'elements' => [
                        'input' => ['tag' => 'x-input'],
                    ],
                ],
                'jsx' => [
                    'components' => [
                        'input' => [
                            'source' => '@headlessui/react',
                            'export' => 'Input',
                            'as' => 'HeadlessInput',
                            'importKind' => 'named',
                        ],
                    ],
                ],
            ],
        ]);
        $tree = $core->parseJson('{"input":[{":props":{"name":"email"}}]}');

        self::assertSame('<x-input name="email"></x-input>', $core->renderHtml($tree));
        self::assertSame(
            'import { Input as HeadlessInput } from "@headlessui/react";' . "\n\n" . '<HeadlessInput name="email" />',
            $core->renderJsx($tree),
        );
    }

    private function skipWhenPklIsMissing(): void
    {
        $path = trim((string) shell_exec('command -v pkl 2>/dev/null'));
        if ($path === '') {
            self::markTestSkipped('Pkl CLI is not installed or not on PATH.');
        }
    }

    public function testJsonParserReportsSourcePointerForInvalidRuntimeKey(): void
    {
        try {
            $this->parser->parseString('{":":{}}', 'generated.compact.json');
            self::fail('Expected invalid runtime key parse error.');
        } catch (ParseException $exception) {
            self::assertStringContainsString('generated.compact.json at /:', $exception->getMessage());
        }
    }

    public function testCanonicalAndTaggedJsonModesRemainAvailable(): void
    {
        $tree = Node::element('div', [], [Node::value('string', 'Hello')]);
        $emitter = new JsonEmitter();

        self::assertStringContainsString('"kind": "element"', $emitter->emitTree($tree));
        self::assertSame('{"div":"Hello"}', $emitter->emitTree($tree, false, JsonEmitter::MODE_COMPACT));
        self::assertSame('{"div":{"#":[{":string":"Hello"}]}}', $emitter->emitTree($tree, false, JsonEmitter::MODE_TAGGED));
    }
}
