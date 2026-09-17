<?php

declare(strict_types=1);
namespace CloudPortal\Controllers\Backend;
use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Infrastructure\BackendConfiguration;
use CloudPortal\Services\Infrastructure\BackendSession;
use CloudPortal\Services\Infrastructure\InfrastructureBackendClient;

final class PortalController
{
    private BackendConfiguration $configuration;
    public function __construct(private readonly Application $app) { $this->configuration=new BackendConfiguration($app->root); }
    public function handles(Request $request): bool
    {
        return $this->configuration->configured() || !$this->app->installed() || $request->path==='/settings/infrastructure/backend';
    }
    private function csrf(Request $request): void
    {
        if(in_array($request->method,['GET','HEAD'],true)) return;
        $provided=$request->header('x-csrf-token')??$request->input('_csrf','');
        if(!is_string($provided)||!hash_equals($this->app->csrf->token(),$provided)) throw new HttpException(419,'Nieprawidłowy token CSRF.');
    }
    public function dispatch(Request $request): Response
    {
        $incomingId=(string)$request->header('x-request-id','');
        $_SERVER['CLOUD_PORTAL_REQUEST_ID']=preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di',$incomingId)
            ?strtolower($incomingId):InfrastructureBackendClient::uuid();
        header('X-Request-ID: '.$_SERVER['CLOUD_PORTAL_REQUEST_ID']);
        $this->csrf($request);
        $config=$this->configuration->load();
        $session=new BackendSession($config);
        if($request->path==='/settings/infrastructure/backend' || !$this->configuration->configured()) return $this->settings($request,$session,$config);
        if($request->path==='/login') {
            if($request->method==='POST') {
                $session->login((string)$request->input('username'),(string)$request->input('password'));
                return Response::redirect($this->app->url('/'));
            }
            return $this->render('login',null,[]);
        }
        if($request->path==='/password-reset') {
            if($request->method==='POST') {
                (new InfrastructureBackendClient($config))->request('POST','/auth/reset-password',['token'=>(string)$request->input('token'),'password'=>(string)$request->input('password')]);
                return Response::redirect($this->app->url('/login'));
            }
            return $this->render('reset',null,[]);
        }
        if($request->path==='/logout' && $request->method==='POST') { $session->logout(); return Response::redirect($this->app->url('/login')); }
        try { $me=$session->current(); }
        catch(HttpException $e) { if($e->status===401 && !$request->expectsJson()) return Response::redirect($this->app->url('/login')); throw $e; }
        if(str_starts_with($request->path,'/backend-api/')) return $this->proxy($request,$session->client());
        $pages=['/'=>'dashboard','/infrastructure'=>'dashboard','/admin/users'=>'users','/admin/roles'=>'roles',
            '/admin/permissions'=>'permissions','/admin/tokens'=>'tokens','/admin/audit'=>'audit','/infrastructure/providers'=>'providers',
            '/infrastructure/credentials'=>'credentials','/infrastructure/templates'=>'templates','/infrastructure/deployments'=>'deployments',
            '/infrastructure/jobs'=>'jobs','/infrastructure/logs'=>'logs','/infrastructure/ansible'=>'ansible',
            '/create-server'=>'catalog','/automation/blueprints'=>'blueprints','/infrastructure/hostnames'=>'hostnames','/account'=>'account'];
        $page=$pages[$request->path]??null;
        if($page===null || $request->method!=='GET') throw new HttpException(404,'Strona nie istnieje w trybie centralnego backendu.');
        $permission=match($page) {'dashboard','account'=>null,'permissions'=>'roles.read','templates'=>'terraform.read','logs'=>'jobs.read','catalog'=>'blueprints.read',default=>$page.'.read'};
        if($permission!==null && !in_array($permission,$me['permissions'],true)) throw new HttpException(403,'Brak uprawnienia: '.$permission);
        return $this->render($page,$me,[]);
    }
    private function proxy(Request $request,InfrastructureBackendClient $client): Response
    {
        $path=substr($request->path,strlen('/backend-api'));
        $allowed=match($request->method) {
            'GET'=>'#^/(?:health|info|permissions|audit|templates(?:/[a-z0-9_-]+)?|terraform/templates|ansible/playbooks|auth/me|hostname-schemes|hostnames|blueprints(?:/\d+)?|(?:users|roles|tokens|credentials|providers|deployments|jobs)(?:/[a-z0-9-]+(?:/(?:roles|nodes|storages|networks|templates|vms|pools|logs))?)?)$#D',
            'POST'=>'#^/(?:users|roles|tokens|credentials|providers|deployments|jobs|blueprints|hostname-schemes|hostnames/generate|auth/change-password|users/\d+/(?:enable|disable|unlock|reset-password)|tokens/\d+/revoke|credentials/\d+/test|blueprints/\d+/execute|hostnames/[a-z0-9-]+/(?:assign|release)|deployments/[a-z0-9-]+/destroy|jobs/[a-z0-9-]+/cancel)$#D',
            'PUT'=>'#^/(?:(?:users|roles|credentials|providers|blueprints|hostname-schemes)/\d+|users/\d+/roles)$#D',
            'DELETE'=>'#^/(?:users|roles|tokens|credentials|providers|blueprints|hostname-schemes)/\d+$#D',
            default=>'#(?!)#',
        };
        if(!preg_match($allowed,$path)) throw new HttpException(404,'Nieobsługiwana operacja API.');
        $query=[];
        foreach(['offset','limit','after'] as $key) if($request->query($key)!==null) $query[$key]=max(0,(int)$request->query($key));
        if($request->query('available')!==null) $query['available']=$request->query('available')==='true'?'true':'false';
        if($request->query('status')!==null) {
            $status=(string)$request->query('status');
            if(!in_array($status,['reserved','assigned','released'],true)) throw new HttpException(422,'Nieprawidłowy status hostname.');
            $query['status']=$status;
        }
        if($request->query('request_id')!==null) $query['request_id']=(string)$request->query('request_id');
        if($request->query('node')!==null) {
            $node=(string)$request->query('node');
            if(!preg_match('/^[A-Za-z0-9_.-]{1,63}$/D',$node)) throw new HttpException(422,'Nieprawidłowa nazwa węzła.');
            $query['node']=$node;
        }
        if($query!==[]) $path.='?'.http_build_query($query);
        $body=null;
        if($request->method!=='GET') {
            if(strlen($request->rawBody())>1024*1024) throw new HttpException(413,'Limit żądania backendu wynosi 1 MiB.');
            // Preserve JSON object/array types, including empty objects in Ansible variables.
            $body=$request->rawBody()!==''&&str_contains((string)$request->header('content-type'),'application/json')
                ?json_decode($request->rawBody(),false,64,JSON_THROW_ON_ERROR):(object)$request->all();
            if(!is_array($body)&&!$body instanceof \stdClass) throw new HttpException(422,'Wymagany obiekt JSON.');
        }
        $client->request($request->method,$path,$body,$request->header('idempotency-key'),$path==='/health');
        return new Response($client->responseBody(),$path==='/health'?200:$client->responseStatus(),['Content-Type'=>'application/json; charset=utf-8']);
    }
    private function settings(Request $request,BackendSession $session,array $config): Response
    {
        $me=null;
        $first=!$this->configuration->configured();
        if(!$first) {
            try { $me=$session->current(); }
            catch(HttpException $e) { if($e->status===401) return Response::redirect($this->app->url('/login')); throw $e; }
            if(!in_array('settings.update',$me['permissions'],true)) throw new HttpException(403,'Brak uprawnienia settings.update.');
        } elseif($this->app->installed()) {
            $this->app->auth()->requirePermission('admin.access');
        }
        $result=null;
        if($request->method==='POST') {
            if($first&&!$this->app->installed()&&!$this->configuration->verifySetupKey((string)$request->input('setup_key'))) throw new HttpException(403,'Wymagany klucz konfiguracji z php bin/backend-setup.php.');
            $candidate=['url'=>rtrim(trim((string)$request->input('url')),'/'),'timeout'=>(int)$request->input('timeout',30),
                'verify_tls'=>$request->input('verify_tls')==='1','token'=>trim((string)$request->input('token'))?:$config['token'],
                'ca_file'=>$config['ca_file']??''];
            if($candidate['url']!==$config['url'] && trim((string)$request->input('token'))==='') throw new HttpException(422,'Zmiana adresu backendu wymaga podania nowego tokena.');
            $client=new InfrastructureBackendClient($candidate,$candidate['token']);
            $identity=$client->getCurrentUser();
            if(!in_array('portal.connect',$identity['permissions'],true)) throw new HttpException(403,'Token połączenia musi mieć uprawnienie portal.connect.');
            $result=['health'=>$client->getHealth(),'authentication'=>'OK', 'service_account'=>(bool)$identity['user']['is_service_account']];
            if($request->input('action')==='save') {
                $this->configuration->save($candidate);
                if($first) { @unlink($this->app->root.'/storage/backend-setup.token'); $_SESSION=[]; }
                if($first || $candidate['url']!==$config['url']) return Response::redirect($this->app->url('/login'));
                $config=$candidate;
            }
        }
        // Never render the stored service token back into HTML.
        unset($config['token']);
        return $this->render('settings',$me,['config'=>$config,'first'=>$first,'result'=>$result]);
    }
    private function render(string $page,?array $me,array $extra): Response
    {
        $base=$this->app->basePath();
        $csrf=$this->app->csrf->token();
        $escape=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        extract($extra,EXTR_SKIP);
        ob_start();
        require $this->app->root.'/resources/views/backend/portal.php';
        return Response::html((string)ob_get_clean());
    }
}
