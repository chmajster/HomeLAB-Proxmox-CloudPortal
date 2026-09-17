<?php

declare(strict_types=1);
namespace CloudPortal\Services\Infrastructure;
use CloudPortal\Http\HttpException;

final class BackendSession
{
    public function __construct(private readonly array $config) {}
    public function login(string $username,string $password): void
    {
        // A shared service token never substitutes for the user's own bearer token.
        $data=(new InfrastructureBackendClient($this->config))->login($username,$password);
        if(session_status()===PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION=[];
        $this->store($data);
    }
    private function store(array $data): void
    {
        $_SESSION['backend_origin']=rtrim($this->config['url'],'/');
        $_SESSION['backend_access']=$data['access_token'];
        $_SESSION['backend_refresh']=$data['refresh_token'];
        $_SESSION['backend_expires']=time()+(int)$data['expires_in'];
    }
    public function client(): InfrastructureBackendClient
    {
        if (($_SESSION['backend_origin']??null)!==rtrim($this->config['url'],'/')) {
            $_SESSION=[];
            throw new HttpException(401,'Połączenie backendu zmieniło się. Zaloguj się ponownie.');
        }
        if(empty($_SESSION['backend_access'])) throw new HttpException(401,'Zaloguj się do backendu.');
        if(time()>=(int)($_SESSION['backend_expires']??0)-30) {
            try { $this->store((new InfrastructureBackendClient($this->config))->refresh((string)$_SESSION['backend_refresh'])); }
            catch(HttpException $e) { if($e->status===401) $_SESSION=[]; throw $e; }
        }
        return new InfrastructureBackendClient($this->config,$_SESSION['backend_access']);
    }
    public function current(): array
    {
        try { return $this->client()->getCurrentUser(); }
        catch(HttpException $e) { if($e->status===401) $_SESSION=[]; throw $e; }
    }
    public function logout(): void
    {
        try { $this->client()->logout(); }
        finally { $_SESSION=[]; if(session_status()===PHP_SESSION_ACTIVE) session_regenerate_id(true); }
    }
}
