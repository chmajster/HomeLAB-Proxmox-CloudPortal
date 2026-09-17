<?php

declare(strict_types=1);
namespace CloudPortal\Tests\Unit;
use CloudPortal\Http\HttpException;
use CloudPortal\Services\Infrastructure\InfrastructureBackendClient;
use CloudPortal\Services\Infrastructure\BackendConfiguration;
use PHPUnit\Framework\TestCase;

final class InfrastructureBackendClientTest extends TestCase
{
    public function testUsesUserTokenAndForwardsIdempotencyAndRequestId(): void
    {
        $captured=[];$token='cp_'.str_repeat('u',64);$key=InfrastructureBackendClient::uuid();
        $_SERVER['CLOUD_PORTAL_REQUEST_ID']=$key;
        $client=new InfrastructureBackendClient(['url'=>'https://backend.example','token'=>'cp_'.str_repeat('s',64)],$token,
            static function($method,$url,$headers,$body)use(&$captured):array{$captured=compact('method','url','headers','body');return [202,'{"id":"deployment-1"}'];});
        self::assertSame('deployment-1',$client->createDeployment(['name'=>'vm'],$key)['id']);
        self::assertSame('https://backend.example/api/v1/deployments',$captured['url']);
        self::assertContains('Authorization: Bearer '.$token,$captured['headers']);
        self::assertContains('Idempotency-Key: '.$key,$captured['headers']);
        self::assertContains('X-Request-ID: '.$key,$captured['headers']);
        self::assertNotContains('Authorization: Bearer cp_'.str_repeat('s',64),$captured['headers']);
    }
    public function testLoginDoesNotFallBackToServiceToken(): void
    {
        $client=new InfrastructureBackendClient(['url'=>'https://backend.example','token'=>'cp_'.str_repeat('s',64)],null,
            static function($method,$url,$headers,$body):array{self::assertSame([],array_values(array_filter($headers,static fn($h)=>str_starts_with($h,'Authorization:'))));return [200,'{"access_token":"test"}'];});
        self::assertSame('test',$client->login('admin','password')['access_token']);
    }
    public function testDeniedResponseRemainsForbidden(): void
    {
        $client=new InfrastructureBackendClient(['url'=>'https://backend.example'],null,static fn()=>[403,'{"detail":"Permission required: credentials.create"}']);
        try{$client->createCredential([],InfrastructureBackendClient::uuid());self::fail('Expected 403');}
        catch(HttpException $error){self::assertSame(403,$error->status);}
    }
    public function testMalformedBackendResponseIsBadGateway(): void
    {
        $client=new InfrastructureBackendClient(['url'=>'https://backend.example'],null,static fn()=>[200,'<html>broken</html>']);
        $this->expectException(HttpException::class);$client->getUsers();
    }
    public function testHealthCanReportWorkerOffline(): void
    {
        $client=new InfrastructureBackendClient(['url'=>'https://backend.example'],null,static fn()=>[503,'{"status":"degraded","checks":{"workers":{"online":0,"expected":1}}}']);
        self::assertSame(0,$client->getHealth()['checks']['workers']['online']);
    }
    public function testRejectsCredentialUrlAndHttp(): void
    {
        foreach(['http://backend.example','https://user@backend.example','https://backend.example/path','https://backend.example?x=y'] as $url){
            try{new InfrastructureBackendClient(['url'=>$url]);self::fail('Accepted unsafe URL: '.$url);}catch(HttpException $e){self::assertSame(422,$e->status);}
        }
    }
    public function testConfigurationPreservesServiceSecretOutsideHtml(): void
    {
        $root=sys_get_temp_dir().'/backend-config-'.bin2hex(random_bytes(8));mkdir($root);mkdir($root.'/config');mkdir($root.'/storage');
        try{$c=new BackendConfiguration($root);$data=['url'=>'https://backend.example','token'=>'cp_'.str_repeat('s',64),'timeout'=>30,'verify_tls'=>true];$c->save($data);self::assertSame($data['token'],$c->load()['token']);self::assertSame(0600,fileperms($root.'/config/backend.json')&0777);}
        finally{@unlink($root.'/config/backend.json');rmdir($root.'/config');rmdir($root.'/storage');rmdir($root);}
    }
}
