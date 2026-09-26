<?php
declare(strict_types=1);
namespace Aibid\Http;

use Aibid\App;
use Aibid\Domain\{ApiProblem, ApiResponse, Problem, Protocol, StrictJson, Uuid};

final class ApiKernel
{
    public function __construct(private App $app) {}

    public function handle(): void
    {
        $requestId = Uuid::create();
        header('Content-Type: application/json; charset=utf-8');
        try {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
            $routes = ['/v1/activations/challenge'=>'challenge','/v1/activations'=>'activate','/v1/activations/refresh'=>'refresh','/v1/activations/deactivate'=>'deactivate'];
            if (!isset($routes[$path])) { throw new ApiProblem('INVALID_REQUEST', 404); }
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); throw new ApiProblem('INVALID_REQUEST', 405); }
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if (($_SERVER['HTTPS'] ?? '') !== 'on' && !($this->app->config->development() && in_array($ip, ['127.0.0.1','::1'], true))) { throw new ApiProblem('INVALID_REQUEST', 403); }
            if (!preg_match('/\Aapplication\/json(?:\s*;\s*charset=utf-8)?\z/i', $_SERVER['CONTENT_TYPE'] ?? '')) { throw new ApiProblem('INVALID_REQUEST', 415); }
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) { throw new ApiProblem('INVALID_REQUEST', 413); }
            $action = $routes[$path];
            $this->app->rate->consume($action === 'challenge' ? 'api-challenge-ip' : 'api-operation-ip', $ip, 30, 60);
            $body = file_get_contents('php://input', false, null, 0, 16385);
            $input = StrictJson::object($body === false ? '' : $body);
            if (isset($input['request_id'])) {
                try { $requestId = Protocol::uuid($input['request_id']); } catch (ApiProblem) {}
            }
            $response = $action === 'challenge' ? $this->app->activations->challenge($input) : $this->app->activations->execute($action, $input);
        } catch (ApiProblem $error) { $response = ApiResponse::error($error,$requestId); }
        catch (Problem $error) {
            if ($error->status !== 429) { throw $error; }
            $response = ApiResponse::error(new ApiProblem('RATE_LIMITED',429),$requestId);
        } catch (\Throwable $error) {
            error_log('AIBID API [' . $requestId . '] ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine());
            $response = ApiResponse::error(new ApiProblem('TEMPORARY_UNAVAILABLE',503),$requestId);
        }
        http_response_code($response->status);
        if ($response->status === 429) { header('Retry-After: 60'); }
        echo $response->body;
    }
}
