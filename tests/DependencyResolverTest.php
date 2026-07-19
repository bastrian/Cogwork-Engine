<?php
declare(strict_types=1);
namespace Modright\Tests;
use Modright\DependencyResolver;
use PHPUnit\Framework\TestCase;

final class DependencyResolverTest extends TestCase
{
    public function testAddsCompatibleRequiredDependenciesAndRecordsUnavailableExternalOnes():void
    { $projects=['root'=>['title'=>'Root','client_side'=>'required','server_side'=>'required'],'library'=>['title'=>'Library','client_side'=>'required','server_side'=>'required'],'external'=>['title'=>'External Library','client_side'=>'required','server_side'=>'required']];$versions=['root'=>[$this->version('root-v','root.jar',[['dependency_type'=>'required','project_id'=>'library'],['dependency_type'=>'required','project_id'=>'external']])],'library'=>[$this->version('library-v','library.jar')],'external'=>[$this->version('old','old.jar',[],['1.20.1'])]];$result=(new DependencyResolver())->resolve(['root'],[],'1.21.1','neoforge',fn($id)=>$projects[$id],fn($id)=>$versions[$id]);self::assertSame(['root','library'],array_column(array_column($result['files'],'cogwork'),'project_id'));self::assertTrue($result['files'][1]['cogwork']['automatically_added']);self::assertSame('External Library',$result['unresolved']['external']['title']);self::assertFalse($result['unresolved']['external']['acknowledged']); }

    public function testRejectsSelectedProjectWhenOnlyWrongTargetIsAvailable():void
    { $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('no compatible Modrinth version');(new DependencyResolver())->resolve(['root'],[],'1.21.1','neoforge',fn()=>['title'=>'Wrong Target','client_side'=>'required','server_side'=>'required'],fn()=>[$this->version('old','old.jar',[],['1.20.1'])]); }

    public function testExistingProjectBreaksDependencyCycles():void
    { $projects=fn(string$id)=>['title'=>$id,'client_side'=>'required','server_side'=>'required'];$versions=fn(string$id)=>[$this->version($id.'-v',$id.'.jar',[['dependency_type'=>'required','project_id'=>$id==='a'?'b':'a']])];$result=(new DependencyResolver())->resolve(['a'],['b'],'1.21.1','neoforge',$projects,$versions);self::assertCount(1,$result['files']);self::assertSame([], $result['unresolved']); }

    private function version(string$id,string$file,array$dependencies=[],array$games=['1.21.1']):array
    { return['id'=>$id,'game_versions'=>$games,'loaders'=>['neoforge'],'dependencies'=>$dependencies,'files'=>[['primary'=>true,'filename'=>$file,'url'=>'https://cdn.modrinth.com/data/x/versions/'.$id.'/'.$file,'size'=>1,'hashes'=>['sha1'=>str_repeat('a',40),'sha512'=>str_repeat('b',128)]]]]; }
}
