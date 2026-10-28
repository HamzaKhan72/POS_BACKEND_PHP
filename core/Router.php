<?php
class Router {
    private $routes = [];

    // Pattern me {id} likh sakte hain, jaise '/api/products/{id}'. Handler ko {id} argument ban kar milta hai.
    public function add($method, $path, $handler, $middleware = []) {
        $regex = '#^' . preg_replace('#\{[a-z_]+\}#i', '([^/]+)', $path) . '$#';
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    // '/pos_backend/public/api/products/5' -> '/api/products/5' (folder ka naam kuch bhi ho, chalega)
    private function normalize($uri) {
        $pos = strpos($uri, '/api/');
        $path = $pos === false ? $uri : substr($uri, $pos);
        return rtrim($path, '/') ?: '/';
    }

    public function dispatch($method, $uri) {
        if ($method === 'OPTIONS') {
            Response::json(['status' => 'ok']);
        }

        $path = $this->normalize($uri);
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) continue;
            $pathMatched = true;
            if ($route['method'] !== $method) continue;

            array_shift($m);
            $params = array_map('urldecode', $m);

            foreach ($route['middleware'] as $mw) {
                call_user_func($mw);
            }
            call_user_func_array($route['handler'], $params);
            return;
        }

        if ($pathMatched) {
            Response::error('Method not allowed', 405);
        }
        Response::error('Endpoint not found', 404);
    }
}
