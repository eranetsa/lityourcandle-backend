<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int, array{method:string,pattern:string,handler:array,middleware:array}> */
    private array $routes = [];

    public function get(string $path, array $handler, array $middleware = []): void    { $this->add('GET', $path, $handler, $middleware); }
    public function post(string $path, array $handler, array $middleware = []): void   { $this->add('POST', $path, $handler, $middleware); }
    public function put(string $path, array $handler, array $middleware = []): void    { $this->add('PUT', $path, $handler, $middleware); }
    public function patch(string $path, array $handler, array $middleware = []): void  { $this->add('PATCH', $path, $handler, $middleware); }
    public function delete(string $path, array $handler, array $middleware = []): void { $this->add('DELETE', $path, $handler, $middleware); }

    private function add(string $method, string $path, array $handler, array $middleware): void
    {
        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $this->compile($path),
            'handler'    => $handler,
            'middleware' => $middleware,
            'path'       => $path,
        ];
    }

    private function compile(string $path): string
    {
        $regex = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $path);
        return '#^' . $regex . '$#';
    }

    public function dispatch(Request $req): void
    {
        if ($req->method === 'OPTIONS') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET,POST,PUT,PATCH,DELETE,OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            Response::noContent();
        }
        header('Access-Control-Allow-Origin: *');

        foreach ($this->routes as $r) {
            if ($r['method'] !== $req->method) continue;
            if (!preg_match($r['pattern'], $req->path, $m)) continue;

            foreach ($m as $k => $v) {
                if (!is_int($k)) $req->params[$k] = $v;
            }

            foreach ($r['middleware'] as $mwClass) {
                /** @var Middleware $mw */
                $mw = new $mwClass();
                $mw->handle($req);
            }

            [$controllerClass, $method] = $r['handler'];
            $controller = new $controllerClass();
            $controller->$method($req);
            return;
        }

        Response::error('not_found', 404);
    }
}

interface Middleware
{
    public function handle(Request $req): void;
}
