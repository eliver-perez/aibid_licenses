<?php
declare(strict_types=1);
namespace Aibid\Domain;

final class ApiProblem extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 400)
    {
        parent::__construct(match ($errorCode) {
            'INVALID_PROOF' => 'No se pudo autenticar la solicitud.',
            'LICENSE_NOT_FOUND' => 'La clave y el producto no autorizan una licencia.',
            'ACTIVATION_LIMIT' => 'La licencia ya tiene una instalación activa.',
            'REVOKED' => 'La autorización fue revocada.',
            'RATE_LIMITED' => 'Demasiadas solicitudes. Intenta nuevamente más tarde.',
            'INCOMPATIBLE_SCHEMA' => 'La versión del contrato no es compatible.',
            'TEMPORARY_UNAVAILABLE' => 'El servicio no está disponible temporalmente.',
            default => 'La solicitud no es válida.',
        });
    }
}
