<?php

namespace MikroApi\Auth;

use MikroApi\Exception\UnauthorizedException;

/**
 * Firma y verifica JSON Web Tokens con HMAC (HS256, HS384, HS512), sin
 * dependencias externas.
 *
 * Registro (el secreto no se puede autowirear):
 *   $container->singleton(JwtService::class, fn() => new JwtService(
 *       secret: $config->getOrThrow('JWT_SECRET'),
 *       ttl:    3600,
 *   ));
 *
 * Uso:
 *   $token   = $jwt->sign(['sub' => $user['id'], 'roles' => ['admin']]);
 *   $payload = $jwt->verify($token); // lanza UnauthorizedException si es inválido/expirado
 */
class JwtService
{
    private const ALGORITHMS = [
        'HS256' => 'sha256',
        'HS384' => 'sha384',
        'HS512' => 'sha512',
    ];

    /**
     * @param int $ttl    Segundos de validez por defecto (0 = sin expiración)
     * @param int $leeway Tolerancia en segundos para exp/nbf/iat (desfase de reloj)
     */
    public function __construct(
        private string $secret,
        private string $algorithm = 'HS256',
        private int $ttl = 3600,
        private int $leeway = 0,
        private ?string $issuer = null,
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('JwtService: el secreto no puede estar vacío.');
        }
        if (!isset(self::ALGORITHMS[$algorithm])) {
            throw new \InvalidArgumentException(
                "JwtService: algoritmo '{$algorithm}' no soportado (usa HS256, HS384 o HS512)."
            );
        }
    }

    /**
     * Firma un payload. Agrega iat, exp (si hay ttl) e iss (si se configuró)
     * salvo que el payload ya los traiga.
     *
     * @param int|null $ttl Sobrescribe el ttl por defecto (0 = sin expiración)
     */
    public function sign(array $payload, ?int $ttl = null): string
    {
        $now = \time();
        $ttl ??= $this->ttl;

        $payload += ['iat' => $now];
        if ($ttl > 0) {
            $payload += ['exp' => $now + $ttl];
        }
        if ($this->issuer !== null) {
            $payload += ['iss' => $this->issuer];
        }

        $header  = self::base64UrlEncode(\json_encode(['alg' => $this->algorithm, 'typ' => 'JWT']));
        $body    = self::base64UrlEncode(\json_encode($payload, JSON_UNESCAPED_UNICODE));
        $signature = self::base64UrlEncode($this->hmac("{$header}.{$body}"));

        return "{$header}.{$body}.{$signature}";
    }

    /**
     * Verifica firma y claims temporales y retorna el payload.
     *
     * @throws UnauthorizedException si el token es inválido, está expirado o aún no es válido
     */
    public function verify(string $token): array
    {
        $parts = \explode('.', $token);
        if (\count($parts) !== 3) {
            throw new UnauthorizedException('Token malformado');
        }
        [$header64, $body64, $signature64] = $parts;

        $header = \json_decode(self::base64UrlDecode($header64), true);
        if (!\is_array($header) || ($header['alg'] ?? null) !== $this->algorithm) {
            // Rechazar cualquier algoritmo distinto al configurado (incluido "none")
            throw new UnauthorizedException('Token inválido');
        }

        $expected = $this->hmac("{$header64}.{$body64}");
        if (!\hash_equals($expected, self::base64UrlDecode($signature64))) {
            throw new UnauthorizedException('Token inválido');
        }

        $payload = \json_decode(self::base64UrlDecode($body64), true);
        if (!\is_array($payload)) {
            throw new UnauthorizedException('Token inválido');
        }

        $now = \time();
        if (isset($payload['exp']) && \is_numeric($payload['exp']) && $now - $this->leeway >= (int) $payload['exp']) {
            throw new UnauthorizedException('Token expirado');
        }
        if (isset($payload['nbf']) && \is_numeric($payload['nbf']) && $now + $this->leeway < (int) $payload['nbf']) {
            throw new UnauthorizedException('Token aún no válido');
        }
        if ($this->issuer !== null && ($payload['iss'] ?? null) !== $this->issuer) {
            throw new UnauthorizedException('Token inválido');
        }

        return $payload;
    }

    private function hmac(string $data): string
    {
        return \hash_hmac(self::ALGORITHMS[$this->algorithm], $data, $this->secret, true);
    }

    private static function base64UrlEncode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $decoded = \base64_decode(\strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
