<?php

declare(strict_types=1);
namespace Armes\Tests;

use Armes\Armes;
use Armes\Tree\Node;
use Armes\Runtime\References\{ReferenceSession, ReferenceException, StructuralAddress, StructuralView, ResultAdapterRegistry};
use PHPUnit\Framework\TestCase;

final class ReferenceSessionTest extends TestCase
{
    private function session(array $source, array $options=[]): ReferenceSession
    {
        $core = new Armes();
        return $core->referenceSession($core->parseJson(json_encode($source)), $options);
    }
    private function ready(ReferenceSession $session): array
    {
        $snapshot=$session->resolve();
        self::assertSame('ready',$snapshot['status'],json_encode($snapshot['diagnostics']));
        return $snapshot;
    }
    public function testExpressionOperandsReadOneConsumingPlan():void
    {
        $s=$this->session(['A'=>['@'=>['value'=>3]],'B'=>['@'=>['value'=>5]],'Text'=>['@'=>['value'=>[':logic:add'=>[[':ref'=>'$/child/A/props/value'],[':ref'=>'$/child/B/props/value']]]]]]);
        $r=$this->ready($s);
        self::assertSame(8,$r['tree']->children[2]->props['value']);
        self::assertCount(2,$r['references']);self::assertCount(2,$r['edges']);
        self::assertSame($r['references'][0]->target,$r['references'][1]->target);
        self::assertNotSame($r['edges'][0]['source'],$r['edges'][1]['source']);
    }
    public function testLegacyNestedReferenceDataAndOpaqueLiteralsStayLiteral():void
    {
        $source=['Widget'=>['@'=>['payload'=>['external'=>[':ref'=>'some-data-id']], 'object'=>[':object'=>[':ref'=>'literal']], 'array'=>[':array'=>[[':ref'=>'literal']]]]]];
        $s=$this->session($source);$r=$this->ready($s);
        self::assertCount(0,$r['references']);self::assertSame(['external'=>[':ref'=>'some-data-id']],$r['tree']->props['payload']);
        self::assertSame([':ref'=>'literal'],$r['tree']->props['object']);
        foreach(['json','yaml','toml'] as $format){$core=new Armes();$reload=$core->referenceSession($core->{'parse'.ucfirst($format)}($s->source($format)));self::assertSame($r['tree']->props,$this->ready($reload)['tree']->props);}
    }
    public function testReorderRetainsIdentityAndSaveNormalizesCurrentPosition():void
    {
        $s=$this->session([['Item'=>['@'=>['value'=>1]]],['Item'=>['@'=>['value'=>2]]],['Text'=>['@'=>['value'=>[':ref'=>'$/children/0/props/value']]]]]);
        $r=$this->ready($s);$old=$s->tree();[$a,$b,$text]=$old->children;$id=$s->nodeId($a);
        $s->transaction($old->withChildren([$b,$a,$text]),[[$old,$old]]);
        $r=$this->ready($s);self::assertSame(1,$r['tree']->children[2]->props['value']);self::assertSame($id,$r['edges'][0]['source']['node']);
        self::assertStringContainsString('$/children/1/props/value',$s->source());
        $core=new Armes();self::assertSame(1,$this->ready($core->referenceSession($core->parseJson($s->source())))['tree']->children[2]->props['value']);
        $s->transaction($s->tree()->withChildren([$s->tree()->children[0],$s->tree()->children[2]]));
        self::assertSame('stale-reference',$s->resolve()['diagnostics'][0]['code']);
        $this->expectException(ReferenceException::class);$s->source();
    }
    public function testDuplicateNamesCyclesAndInactiveSourcesDiagnose():void
    {
        $s=$this->session([['Item'=>1],['Item'=>2],['Text'=>['@'=>['value'=>[':ref'=>'$/child/Item']]]]]);
        self::assertSame('ambiguous-reference',$s->resolve()['diagnostics'][0]['code']);
        $s=$this->session(['A'=>['@'=>['value'=>[':ref'=>'$/child/B/props/value']]],'B'=>['@'=>['value'=>[':ref'=>'$/child/A/props/value']]]]);
        self::assertSame('dependency-cycle',$s->resolve()['diagnostics'][0]['code']);
        $s=$this->session([':if'=>['@'=>['test'=>false],'#'=>[['Hidden'=>['@'=>['value'=>4]]]]],'Text'=>['@'=>['value'=>[':ref'=>'$/child/:if/branch/then/child/Hidden/props/value']]]]);
        self::assertSame('inactive-source',$s->resolve()['diagnostics'][0]['code']);
    }
    public function testBindUnbindAndSourceCapabilityAreSeparate():void
    {
        $s=$this->session(['A'=>['@'=>['value'=>2]],'B'=>['@'=>['value'=>0]]]);
        $s->bind('$/child/B/props/value','$/child/A/props/value');self::assertSame(2,$this->ready($s)['tree']->children[1]->props['value']);
        $s->unbind('$/child/B/props/value',9);self::assertSame(9,$this->ready($s)['tree']->children[1]->props['value']);
        $s=$this->session(['A'=>['@'=>['value'=>2]],'B'=>['@'=>['value'=>[':ref'=>'$/child/A/props/value']]]],['canSelect'=>fn()=>false]);
        self::assertSame('not-selectable',$s->resolve()['diagnostics'][0]['code']);
    }
    public function testStructuralHandlesAreMaterializedOnlyOnDemand():void
    {
        $s=$this->session(['A'=>['span'=>'hello'],'B'=>['@'=>['content'=>[':ref'=>'$/child/A']]]]);$r=$this->ready($s);
        self::assertInstanceOf(StructuralView::class,$r['tree']->children[1]->props['content']);
        self::assertSame('structure',$r['edges'][0]['selection']);
        self::assertSame('A',$s->materialize()->children[1]->props['content']->name);
        self::assertStringNotContainsString('revision',$s->source());
    }
    public function testResultAdaptersTrackReadsAndStableKeysAcrossRevision():void
    {
        $rows=[['id'=>'a','value'=>3],['id'=>'b','value'=>5]];
        $registry=ResultAdapterRegistry::empty()->with('element','Rows',function()use(&$rows){return ['kind'=>'value','value'=>$rows,'keys'=>array_column($rows,'id')];})
            ->with('element','Double',fn($ctx)=>['kind'=>'value','value'=>$ctx['read']('$/child/Text/props/value')*2]);
        $s=$this->session(['Rows'=>null,'Text'=>['@'=>['value'=>[':ref'=>'$/child/Rows/result/item/0/member/value']]],'Double'=>null,'Output'=>['@'=>['value'=>[':ref'=>'$/child/Double/result']]]],['resultAdapters'=>$registry]);
        $r=$this->ready($s);self::assertSame(6,$r['tree']->children[3]->props['value']);self::assertCount(3,$r['edges']);
        self::assertStringContainsString('/result/entry/a/member/value',$s->source());
        $rows=array_reverse($rows);$s->transaction($s->tree());$r=$this->ready($s);self::assertSame(3,$r['tree']->children[1]->props['value']);
    }
    public function testNestedLocationsRequireExplicitExpressionCapability():void
    {
        $source=['A'=>['@'=>['value'=>7]],'B'=>['@'=>['payload'=>['nested'=>[':ref'=>'$/child/A/props/value']]]]];
        $s=$this->session($source,['isExpressionLocation'=>fn($node,$path)=>$path===['props','payload','nested']]);
        self::assertSame(['nested'=>7],$this->ready($s)['tree']->children[1]->props['payload']);
    }
    public function testEscapedAddressesRoundTrip():void
    {
        $path='$/child/a~1b~0c/props/x~1y';self::assertSame($path,(string)StructuralAddress::parse($path));
        $s=$this->session(['a/b~c'=>['@'=>['x/y'=>8]],'B'=>['@'=>['v'=>[':ref'=>$path]]]]);self::assertSame(8,$this->ready($s)['tree']->children[1]->props['v']);
    }
    public function testRepeatedImportsUseSeparateDocumentRoots():void
    {
        $core=new Armes();$tree=$core->parseJsonFile(__DIR__.'/../fixtures/references/imports.input.json');
        $s=$core->referenceSession($tree);$r=$this->ready($s);
        self::assertSame('Ada',$r['tree']->children[0]->children[1]->props['value']);
        self::assertSame('Grace',$r['tree']->children[1]->children[1]->props['value']);
        self::assertNotSame($r['edges'][0]['source']['node'],$r['edges'][1]['source']['node']);
        self::assertStringNotContainsString('document:', $s->source());
    }
    public function testLoopReferencesResolveInIterationAndKeyedResultCanBeSelected():void
    {
        $s=$this->session([':each'=>['@'=>['items'=>[':expr'=>['var'=>'rows']],'as'=>'row'],'#'=>[
            ['Source'=>['@'=>['value'=>[':expr'=>['var'=>'row']]]]],
            ['Use'=>['@'=>['value'=>[':ref'=>'$/child/:each/child/Source/props/value']]]]
        ]],'Outside'=>['@'=>['value'=>[':ref'=>'$/child/:each/result/entry/b/child/Use/props/value']]]],['context'=>['rows'=>['a'=>2,'b'=>4]]]);
        $r=$this->ready($s);self::assertSame(2,$r['tree']->children[1]->props['value']);self::assertSame(4,$r['tree']->children[3]->props['value']);self::assertSame(4,$r['tree']->children[4]->props['value']);
        self::assertNotSame($r['edges'][0]['source']['node'],$r['edges'][1]['source']['node']);
    }
    public function testCopyRebindsInternalReferencesAndForkKeepsPriorRevision():void
    {
        $s=$this->session(['Group'=>['Source'=>['@'=>['value'=>3]],'Use'=>['@'=>['value'=>[':ref'=>'$/child/Group/child/Source/props/value']]]]]);
        $this->ready($s);$fork=$s->fork();$s->duplicate('$/child/Group');$r=$this->ready($s);
        self::assertCount(2,$r['edges']);self::assertNotSame($r['edges'][0]['source'],$r['edges'][1]['source']);
        self::assertCount(1,$this->ready($fork)['edges']);
        $core=new Armes();self::assertCount(2,$this->ready($core->referenceSession($core->parseJson($s->source())))['edges']);
    }
    public function testUnkeyedGeneratedChangesAndInvalidKeysFailExplicitly():void
    {
        $rows=[1,2];$registry=ResultAdapterRegistry::empty()->with('element','Rows',function()use(&$rows){return ['kind'=>'value','value'=>$rows];});
        $s=$this->session(['Rows'=>null,'Use'=>['@'=>['value'=>[':ref'=>'$/child/Rows/result/item/0']]]],['resultAdapters'=>$registry]);
        $this->ready($s);$rows=[2,1];$s->transaction($s->tree());self::assertSame('stale-reference',$s->resolve()['diagnostics'][0]['code']);
        $registry=ResultAdapterRegistry::empty()->with('element','Rows',fn()=>['kind'=>'value','value'=>[1,2],'keys'=>['a','a']]);
        $s=$this->session(['Rows'=>null,'Use'=>['@'=>['value'=>[':ref'=>'$/child/Rows/result']]]],['resultAdapters'=>$registry]);
        self::assertSame('invalid-result-keys',$s->resolve()['diagnostics'][0]['code']);
    }
    public function testStructuralCyclesFailDuringConcreteMaterialization():void
    {
        $s=$this->session(['A'=>['@'=>['other'=>[':ref'=>'$/child/B']]],'B'=>['@'=>['other'=>[':ref'=>'$/child/A']]]]);
        $this->ready($s);$this->expectException(ReferenceException::class);$s->materialize();
    }

    public function testImportInputsBindInTheContainingDocumentAndPublishOneRevision():void
    {
        $core=new Armes();$tree=$core->parseJson(json_encode([
            'Parent'=>['@'=>['value'=>'Local']],
            ':import'=>['@'=>['src'=>'./instance.armes.json','props'=>['name'=>[':ref'=>'$/child/Parent/props/value']]]]
        ]),__DIR__.'/../fixtures/references/imports.input.json');
        $s=$core->referenceSession($tree);$published=[];$s->subscribe(function($snapshot)use(&$published){$published[]=$snapshot;});
        $r=$this->ready($s);self::assertSame('Local',$r['tree']->children[1]->children[1]->props['value']);
        self::assertSame($r,$s->resolve());self::assertCount(1,$published);
    }
    public function testValidLookingLegacyDataAndTypedNestedBindingsStayOpaque():void
    {
        $path='$/child/A/props/value';
        $s=$this->session(['A'=>['@'=>['value'=>3]],'B'=>['@'=>['data'=>['nested'=>[':ref'=>$path]],'opaque'=>[':type:object'=>['nested'=>[':ref'=>$path]]]]]]);
        $r=$this->ready($s);self::assertCount(0,$r['references']);self::assertSame(['nested'=>[':ref'=>$path]],$r['tree']->children[1]->props['data']);
        $s=$this->session(['A'=>['@'=>['value'=>3]],'B'=>['@'=>['opaque'=>[':type:object'=>['nested'=>0]]]]],['isExpressionLocation'=>fn()=>true]);
        $this->expectException(ReferenceException::class);$s->bind('$/child/B/props/opaque/value/member/nested',$path);
    }

}
