<?php
declare(strict_types=1);
namespace Armes\Tests;
use Armes\Armes;
use Armes\Loader\{MemorySourceLoader,FileSourceLoader,SourceLoader};
use Armes\Runtime\References\{ReferenceSyntax,ResultAdapterRegistry,ReferenceSession};
use Armes\Tree\Node;
use PHPUnit\Framework\TestCase;
final class ReferenceCompactContractTest extends TestCase {
    public function testIdentityDirectiveSourceRoundTrips():void {
        $c=new Armes();$nodes=[Node::value('int',7),Node::logic('add',[Node::value('int',2),Node::value('int',3)]),Node::runtime('ref',[],[],'$/data/@value'),Node::runtime('expr',[],[],Node::logic('mul',[Node::value('int',2),Node::value('int',3)])),Node::runtime('props',[],[],['value'=>7]),Node::runtime('attributes',[],[],['title'=>'hello'])];
        $nodes[]=Node::runtime('if',['test'=>true]);$nodes[]=Node::runtime('import',['src'=>'./component.armes.json']);foreach(['comment','doctype','cdata','raw'] as $type)$nodes[]=Node::value($type,'literal <>&');
        foreach($nodes as $i=>$node){$node=$node->withSemanticId('identity-'.$i);$expected=json_decode($c->sourceJson($node),true,256,JSON_THROW_ON_ERROR);foreach([$c->parseJson($c->sourceJson($node)),$c->parseArmes($c->sourceArmes($node))] as $again){self::assertSame($node->semanticId,$again->semanticId);self::assertEquals($expected,json_decode($c->sourceJson($again),true,256,JSON_THROW_ON_ERROR));}}
    }
    public function testEmbeddedIdsAreRejected():void {
        $c=new Armes();$tree=$c->parseJson('{"Use":{"@":{"value":{":ref":{"@":{":id":"hidden"},"#":["./@base"]}},"base":7}}}');self::assertSame('invalid-id',$c->referenceSession($tree)->resolve()['diagnostics'][0]['code']);
    }
    public function testCustomLoadersCannotBypassSourceAndNetworkPolicy():void {
        $c=new Armes();$tree=$c->parseJson('{"Use":{"@":{"value":{":ref":"./data.json::$"}}}}','/w/main.armes.json');
        $loader=new class implements SourceLoader {public string $uri='/w/data.json';public function resolve(?string $from,string $path):string{return $this->uri;}public function readText(string $uri,?int $maxBytes=null):string{return '{"value":"exceeds source limit"}';}};
        self::assertSame('source-limit',$c->referenceSession($tree,['loader'=>$loader,'limits'=>['sourceBytes'=>4]])->resolve()['diagnostics'][0]['code']);
        $loader->uri='sftp://example.test/data.json';self::assertSame('resource-denied',$c->referenceSession($tree,['loader'=>$loader])->resolve()['diagnostics'][0]['code']);
    }
    public function testParsedResourceCacheIsBoundedAcrossRevisions():void {
        $c=new Armes();foreach([['resources'=>2],['resources'=>10,'totalSourceBytes'=>24]] as $limits){
            $parses=0;$registry=\Armes\Parser\ParserRegistry::defaults()->with('json',function($source)use(&$parses){$parses++;return json_decode($source,false,256,JSON_THROW_ON_ERROR);});$loader=new MemorySourceLoader(['/w/a.json'=>'{"value":1}','/w/b.json'=>'{"value":2}','/w/c.json'=>'{"value":3}']);
            $tree=fn($file)=>$c->parseJson($this->json(['Use'=>['@'=>['value'=>[':ref'=>'./'.$file.'.json::$[value]']]]]),'/w/main.armes.json');$session=$c->referenceSession($tree('a'),['loader'=>$loader,'parserRegistry'=>$registry,'limits'=>$limits]);
            foreach([['a',1],['b',2],['c',3],['a',1]] as [$file,$expected]){$session->transaction($tree($file));self::assertSame($expected,$this->props($session)['value']);}self::assertSame(4,$parses);
        }
    }
    public function testExternalReadDoesNotInstantiateImports():void {
        $c=new Armes();$loader=new MemorySourceLoader(['/w/component.armes.json'=>'{"Use":{"@":{"safe":7,"unsafe":{":import":{"@":{"src":"/outside.json"}}}},"#":[{":import":{"@":{"src":"/outside.json"}}}]}}']);
        foreach(['$/Use/@safe','$/Use/@unsafe','$/Use/[0]/#result'] as $path){$tree=$c->parseJson($this->json(['Use'=>['@'=>['value'=>[':ref'=>['src'=>'./component.armes.json','path'=>$path]]]]]),'/w/main.armes.json');$session=$c->referenceSession($tree,['loader'=>$loader]);if($path==='$/Use/@safe')self::assertSame(7,$this->props($session)['value']);else self::assertSame('handler-denied',$session->resolve()['diagnostics'][0]['code']);}
    }
    private function fixture():array {return json_decode(file_get_contents(__DIR__.'/../fixtures/references/compact-contract.json'),true,256,JSON_THROW_ON_ERROR);}
    private function props(ReferenceSession $session):array {$s=$session->resolve();self::assertSame('ready',$s['status'],json_encode($s['diagnostics']));$find=function(Node $n)use(&$find):?Node{if($n->name==='Use')return $n;foreach($n->children as $c)if($found=$find($c))return $found;return null;};return $find($s['tree'])->props;}
    private function json(mixed $v):string {return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
    public function testSharedDataSelectorsAndErrors():void {
        $f=$this->fixture();$core=new Armes();$loader=new MemorySourceLoader(['/workspace/data.json'=>$this->json($f['data'])]);
        foreach([...$f['selectors'],...$f['errors']] as $case){$tree=$core->parseJson($this->json(['Use'=>['@'=>['value'=>[':ref'=>['src'=>'./data.json','path'=>$case['path']]]]]]),'/workspace/main.armes.json');$s=$core->referenceSession($tree,['loader'=>$loader]);
            if(isset($case['code'])){self::assertSame($case['code'],$s->resolve()['diagnostics'][0]['code'],$case['path']);}else self::assertSame($this->json($case['value']),$this->json($this->props($s)['value']),$case['path']);}
    }
    public function testSharedDocumentAndInlineRoundTrips():void {
        $f=$this->fixture();$core=new Armes();$tree=$core->parseJson($this->json($f['json']));self::assertSame($this->json($f['expected']),$this->json($this->props($core->referenceSession($tree))));
        $tree=$core->parseArmes($f['markup']);self::assertSame($this->json($f['markupExpected']),$this->json($this->props($core->referenceSession($tree))));
        foreach([$core->parseJson($core->sourceJson($tree)),$core->parseArmes($core->sourceArmes($tree))] as $again)self::assertSame($this->json($f['markupExpected']),$this->json($this->props($core->referenceSession($again))));
    }
    public function testDefaultNamedAndKeyedResults():void {
        $adapters=ResultAdapterRegistry::empty()->with('element','Rows',fn()=>['kind'=>'value','value'=>[['name'=>'Ada'],['name'=>'Grace']],'keys'=>['ada','grace']])->withOutput('element','Rows','count',fn()=>['kind'=>'value','value'=>2]);
        $c=new Armes();$s=$c->referenceSession($c->parseJson('{"Rows":[],"Use":{"@":{"value":{":ref":"$/Rows/#result[ada][name]"},"count":{":ref":"$/Rows/#count"}}}}'),['resultAdapters'=>$adapters]);self::assertSame(['value'=>'Ada','count'=>2],$this->props($s));
    }
    public function testExternalDocumentsAreDemandDrivenAndTypedAcrossFormats():void {
        $loader=new MemorySourceLoader(['/w/component.armes'=>'<data :id="source-value" value="{:type:int 7}"/><:code>inert</:code>','/w/data.yaml'=>'flags: {editor: true, disabled: false, empty: null}','/w/data.toml'=>"[flags]\neditor = true",'/w/data.txt'=>'value: 4']);
        $c=new Armes();$tree=$c->parseJson('{"Use":{"@":{"value":{":ref":"./component.armes::#source-value/@value"},"yaml":{":ref":"./data.yaml::$[flags][editor]"},"toml":{":ref":"./data.toml::$[flags][editor]"},"override":{":ref":{"src":"./data.txt","format":"yaml","path":"$[value]"}}}}}', '/w/main.armes.json');self::assertSame(['value'=>7,'yaml'=>true,'toml'=>true,'override'=>4],$this->props($c->referenceSession($tree,['loader'=>$loader])));
        $emitted=$c->sourceArmes($tree);self::assertSame(['value'=>7,'yaml'=>true,'toml'=>true,'override'=>4],$this->props($c->referenceSession($c->parseArmes($emitted,'/w/main.armes'),['loader'=>$loader])));
    }
    public function testMalformedAndLimitsNeverBecomeValues():void {
        $c=new Armes();$s=$c->referenceSession($c->parseJson('{"Use":{"@":{"value":{":ref":"./data.json::$[rows][]"}}}}'),['baseUri'=>'/w/main.armes.json','loader'=>new MemorySourceLoader(['/w/data.json'=>'{"rows":[1,2,3]}']),'limits'=>['selectionSize'=>2]]);self::assertSame('selection-limit',$s->resolve()['diagnostics'][0]['code']);
        $this->expectException(\Armes\Exception\ParseException::class);$c->parseArmes('<Use value="{:logic:add 1"/>');
    }
    public function testIdentityBindingCopyAndMigrationStaySymbolic():void {
        $c=new Armes();$s=$c->referenceSession($c->parseJson('{"Group":{"#":[{"data":{"@":{"id":"html","value":7}}},{"Use":{"@":{"value":0}}}]}}'));
        $s->bind('$/Group/Use/@value','$/Group/data/@value',true);$before=$s->authoredRevision();self::assertSame(7,$this->props($s)['value']);self::assertStringContainsString('#node-',$s->source());self::assertStringContainsString('"id": "html"',$s->source());
        $s->duplicate('$/Group');self::assertSame($before+1,$s->authoredRevision());$tree=$c->parseJson($s->source());self::assertNotSame($tree->children[0]->children[0]->semanticId,$tree->children[1]->children[0]->semanticId);self::assertSame('ready',$c->referenceSession($tree)->resolve()['status']);
        $legacy=$c->referenceSession($c->parseJson('{"Use":{"@":{"value":{":ref":"$/child/data/props/value"}}}}'));$before=$legacy->source();$preview=$legacy->previewReferenceMigration();self::assertSame([['before'=>'$/child/data/props/value','after'=>'$/data/@value']],$preview['changes']);self::assertSame($before,$legacy->source());
    }
    public function testCopyRemapsModifierAndNestedLogicIdsButKeepsLiteralData():void {
        $c=new Armes();$tree=$c->parseJson('{"Group":{"#":[{"data":{"@":{":id":"data","value":7}}},{"Use":{"#":[{":props":{"value":{":ref":"#data/@value"},"double":{":logic:mul":[{":ref":"#data/@value"},2]},"literal":{":type:object":{":ref":"#data/@value"}}}}]}}]}}');
        $copy=\Armes\Tree\SemanticIdentity::copy($tree);$source=$c->sourceJson($copy);self::assertSame('ready',$c->referenceSession($copy)->resolve()['status']);self::assertSame(7,$this->props($c->referenceSession($copy))['value']);self::assertSame(14,$this->props($c->referenceSession($copy))['double']);self::assertSame([':ref'=>'#data/@value'],$this->props($c->referenceSession($copy))['literal']);self::assertStringContainsString('#node-',$source);
    }
    public function testEmptyObjectsAndNumericKeysRetainTheirShapes():void {
        $c=new Armes();$tree=$c->parseJson('{"Use":{"@":{"empty":{},"array":[],"typed":{":type:object":{}},"numeric":{"0":"zero"},"value":{":ref":"./@numeric[\"0\"]"}}}}');
        foreach([$tree,$c->parseJson($c->sourceJson($tree)),$c->parseArmes($c->sourceArmes($tree))] as $again){$props=$this->props($c->referenceSession($again));self::assertSame('zero',$props['value']);self::assertSame('{}',$this->json($props['empty']));self::assertSame('{}',$this->json($props['typed']));self::assertSame('[]',$this->json($props['array']));}
    }
    public function testResourceParsingCacheCannotBeMutatedThroughReads():void {
        $text='{"value":{"0":"original"}}';$parses=0;
        $loader=new class($text) implements SourceLoader {public function __construct(public string $text){}public function resolve(?string $from,string $path):string{return '/w/data.json';}public function readText(string $uri,?int $maxBytes=null):string{return $this->text;}};
        $registry=\Armes\Parser\ParserRegistry::defaults()->with('json',function($source)use(&$parses){$parses++;return json_decode($source,false,256,JSON_THROW_ON_ERROR);});
        $c=new Armes();$s=$c->referenceSession($c->parseJson('{"Use":{"@":{"value":{":ref":"./data.json::$[value]"}}}}'),['baseUri'=>'/w/main.armes.json','loader'=>$loader,'parserRegistry'=>$registry]);$read=$this->props($s)['value'];$read->{'0'}='mutated';$s->refreshResources();self::assertSame('original',$this->props($s)['value']->{'0'});self::assertSame(1,$parses);$loader->text='{"value":{"0":"edited"}}';$s->refreshResources();self::assertSame('edited',$this->props($s)['value']->{'0'});self::assertSame(2,$parses);
    }
    public function testIndependentCrossFileReadsAndActualCycles():void {
        $c=new Armes();$files=[];foreach(['a'=>1,'b'=>2] as $name=>$value)$files['/w/'.$name.'.armes.json']=$this->json(['data'=>['@'=>['value'=>$value,'next'=>[':ref'=>'./'.($name==='a'?'b':'a').'.armes.json::$/data/@value']]]]);
        $tree=$c->parseJson('{"Use":{"@":{"value":{":ref":"./a.armes.json::$/data/@next"}}}}','/w/main.armes.json');self::assertSame(2,$this->props($c->referenceSession($tree,['loader'=>new MemorySourceLoader($files)]))['value']);
        foreach(['a','b'] as $name)$files['/w/'.$name.'.armes.json']=$this->json(['data'=>['@'=>['next'=>[':ref'=>'./'.($name==='a'?'b':'a').'.armes.json::$/data/@next']]]]);self::assertSame('dependency-cycle',$c->referenceSession($tree,['loader'=>new MemorySourceLoader($files)])->resolve()['diagnostics'][0]['code']);
    }
    public function testDataDirectivesRemainDataAndResolvedNetworkIsDenied():void {
        $c=new Armes();$tree=$c->parseJson('{"Use":{"@":{"value":{":ref":{"src":"./data.json"}}}}}','/w/main.armes.json');$props=$this->props($c->referenceSession($tree,['loader'=>new MemorySourceLoader(['/w/data.json'=>'{":ref":"literal",":code":"inert"}'])]));self::assertSame('{":ref":"literal",":code":"inert"}',$this->json($props['value']));
        $loader=new class implements SourceLoader {public function resolve(?string $from,string $path):string{return 'https://example.test/data.json';}public function readText(string $uri,?int $maxBytes=null):string{throw new \LogicException('must not read');}};self::assertSame('resource-denied',$c->referenceSession($tree,['loader'=>$loader])->resolve()['diagnostics'][0]['code']);
    }
    public function testEvaluatedModifierPropsAndEachInstances():void {
        $c=new Armes();$tree=$c->parseJson('[{"Compute":{"@":{"input":1},"#":[{":props":{"input":7,"extra":false}}]}},{"Use":{"@":{"value":{":ref":"$/Compute/#result"}}}}]');
        $adapters=ResultAdapterRegistry::empty()->with('element','Compute',fn($ctx)=>['kind'=>'value','value'=>$ctx['props']]);self::assertSame(['input'=>7,'extra'=>false],$this->props($c->referenceSession($tree,['resultAdapters'=>$adapters]))['value']);
        $loop=$c->parseJson('{":each":{"@":{"items":[7,9],"as":"item"},"#":[{"Use":{"@":{":id":"template","value":{":logic:var":"item"},"relative":{":ref":"./@value"},"stable":{":ref":"#template/@value"}}}}]}}');$s=$c->referenceSession($loop)->resolve();self::assertSame('ready',$s['status']);self::assertSame([['value'=>7,'relative'=>7,'stable'=>7],['value'=>9,'relative'=>9,'stable'=>9]],array_map(fn($n)=>$n->props,$s['tree']->children));
        $only=$c->parseJson('[{"data":{"#":[{":props":{"value":0}}]}},{"Use":{"@":{"value":{":ref":"$/data/@value"}}}}]');self::assertSame(0,$this->props($c->referenceSession($only))['value']);self::assertSame('missing-reference',$c->referenceSession($c->parseJson('{"Use":{"@":{"value":{":ref":"./@missing"}}}}'))->resolve()['diagnostics'][0]['code']);
    }
}
