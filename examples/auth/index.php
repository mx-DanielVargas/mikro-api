<?php
/**
 * Authentication Example with JWT
 * 
 * This example shows how to implement JWT authentication
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MikroApi\App;
use MikroApi\Request;
use MikroApi\Response;
use MikroApi\BaseGuard;
use MikroApi\RequestDto;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Route;
use MikroApi\Attributes\Body;
use MikroApi\Attributes\UseGuards;
use MikroApi\Attributes\Validation\Required;
use MikroApi\Attributes\Validation\IsEmail;

// Login DTO
class LoginDto extends RequestDto
{
    #[Required]
    #[IsEmail]
    public string $email;

    #[Required]
    public string $password;
}

// Simple JWT Guard
class JwtGuard extends BaseGuard
{
    private const SECRET = 'demo-secret-key';

    public function canActivate(Request $request): bool
    {
        $authHeader = $request->header('AUTHORIZATION');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return false;
        }

        $token = substr($authHeader, 7);
        $payload = $this->validateToken($token);

        if ($payload) {
            $request->params['_auth'] = $payload;
            return true;
        }

        return false;
    }

    public function deny(): Response
    {
        return Response::error('Unauthorized', 401);
    }

    private function validateToken(string $token): ?array
    {
        // Simple validation (use proper JWT library in production)
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        $payload = json_decode(base64_decode($parts[1]), true);
        if (!$payload || !isset($payload['exp'])) return null;

        if ($payload['exp'] < time()) return null;

        return $payload;
    }

    public static function generateToken(array $payload): string
    {
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload['exp'] = time() + 3600;
        $payloadEncoded = base64_encode(json_encode($payload));
        $signature = base64_encode(hash_hmac('sha256', "$header.$payloadEncoded", self::SECRET, true));

        return "$header.$payloadEncoded.$signature";
    }
}

// Auth Controller
#[Controller('/auth')]
class AuthController
{
    #[Route('POST', '/login')]
    #[Body(LoginDto::class)]
    public function login(Request $req): Response
    {
        $dto = $req->dto;

        // Simple validation (use database in production)
        if ($dto->email === 'user@example.com' && $dto->password === 'password') {
            $token = JwtGuard::generateToken([
                'email' => $dto->email,
                'role' => 'user'
            ]);

            return Response::json([
                'access_token' => $token,
                'token_type' => 'Bearer'
            ]);
        }

        return Response::error('Invalid credentials', 401);
    }

    #[Route('GET', '/me')]
    #[UseGuards(JwtGuard::class)]
    public function me(Request $req): Response
    {
        return Response::json([
            'user' => $req->params['_auth']
        ]);
    }
}

// Bootstrap
$app = new App();
$app->useController(AuthController::class)->run();
