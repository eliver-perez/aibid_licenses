<?php
declare(strict_types=1);
namespace Aibid\Domain;

final class ApiResponse
{
    public function __construct(public readonly int $status, public readonly string $body) {}
    public static function data(array $data): self { return new self(200, Protocol::json($data)); }
    public static function error(ApiProblem $error, string $requestId): self
    {
        return new self($error->status, Protocol::json(['error'=>['code'=>$error->errorCode,'message'=>$error->getMessage(),'request_id'=>$requestId]]));
    }
}
