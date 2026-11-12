<?php
declare(strict_types=1);

namespace Admin;

final class Router
{
    /** @var array<int,array{method:string,pattern:string,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable $h): void { $this->add('GET', $pattern, $h); }
    public function post(string $pattern, callable $h): void { $this->add('POST', $pattern, $h); }
    public function put(string $pattern, callable $h): void { $this->add('PUT', $pattern, $h); }
    public function delete(string $pattern, callable $h): void { $this->add('DELETE', $pattern, $h); }

    public function dispatch(string $method, string $path): void
    {
        // HTMX peut envoyer la méthode via _method override
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper($_POST['_method']);
            if (in_array($override, ['PUT', 'DELETE', 'PATCH'], true)) {
                $method = $override;
            }
        }
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) continue;
            $regex = $this->compile($route['pattern']);
            if (preg_match($regex, $path, $m)) {
                $params = array_filter($m, fn($k) => is_string($k), ARRAY_FILTER_USE_KEY);
                ($route['handler'])($params);
                return;
            }
        }
        http_response_code(404);
        echo "404 Not Found: {$method} {$path}\n";
    }

    /** Compile /machines/{id} → ~^/machines/(?<id>[^/]+)$~ */
    private function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '/\{(\w+)\}/',
            fn($m) => '(?<' . $m[1] . '>[^/]+)',
            $pattern
        );
        return '~^' . $regex . '$~';
    }
}
