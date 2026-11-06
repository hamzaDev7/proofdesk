<?php

namespace ProofDesk\Core;

class Router
{
    private array $routes = [];
    private array $globalMiddlewares = [];

    public function use(callable|string $middleware): self
    {
        $this->globalMiddlewares[] = $middleware;
        return $this;
    }

    public function get(string $path, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('PUT', $path, $handler, $middlewares);
    }

    public function patch(string $path, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('PATCH', $path, $handler, $middlewares);
    }

    public function delete(string $path, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('DELETE', $path, $handler, $middlewares);
    }

    public function options(string $path, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('OPTIONS', $path, $handler, $middlewares);
    }

    private function addRoute(string $method, string $path, callable|array $handler, array $middlewares = []): self
    {
        // Normalize path
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        // Convert {param} into regex (?P<param>[^/]+)
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $path);
        $regex = '#^' . $pattern . '$#';

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'regex' => $regex,
            'handler' => $handler,
            'middlewares' => $middlewares
        ];

        return $this;
    }

    public function dispatch(Request $request): void
    {
        $reqMethod = $request->getMethod();
        $reqPath = '/' . trim($request->getPath(), '/');
        if ($reqPath !== '/') {
            $reqPath = rtrim($reqPath, '/');
        }

        // Run global middlewares first
        foreach ($this->globalMiddlewares as $mw) {
            $this->executeMiddleware($mw, $request);
        }

        $matchedPath = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $reqPath, $matches)) {
                $matchedPath = true;

                if ($route['method'] === $reqMethod || ($reqMethod === 'OPTIONS' && $route['method'] === 'OPTIONS')) {
                    // Extract named parameters
                    $params = [];
                    foreach ($matches as $k => $v) {
                        if (is_string($k)) {
                            $params[$k] = $v;
                        }
                    }
                    $request->setParams($params);

                    // Execute route-specific middlewares
                    foreach ($route['middlewares'] as $mw) {
                        $this->executeMiddleware($mw, $request);
                    }

                    // Execute handler
                    $this->executeHandler($route['handler'], $request);
                    return;
                }
            }
        }

        if ($matchedPath) {
            Response::error('Method not allowed for this endpoint', 405, null, 'METHOD_NOT_ALLOWED');
        }

        Response::error(sprintf('Endpoint not found: %s %s', $reqMethod, $reqPath), 404, null, 'NOT_FOUND');
    }

    private function executeMiddleware(callable|string $middleware, Request $request): void
    {
        if (is_string($middleware)) {
            $instance = new $middleware();
            $instance->handle($request);
        } elseif (is_callable($middleware)) {
            $middleware($request);
        }
    }

    private function executeHandler(callable|array $handler, Request $request): void
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            if (is_string($class)) {
                $class = new $class();
            }
            $class->$method($request);
        } elseif (is_callable($handler)) {
            $handler($request);
        }
    }
}
