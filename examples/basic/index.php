<?php
/**
 * Basic MikroAPI Example
 * 
 * This example shows how to create a simple API with MikroAPI
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use MikroApi\App;
use MikroApi\Request;
use MikroApi\Response;
use MikroApi\Attributes\Controller;
use MikroApi\Attributes\Route;

// Simple controller
#[Controller('/api')]
class HelloController
{
    #[Route('GET', '/hello')]
    public function hello(Request $req): Response
    {
        return Response::json([
            'message' => 'Hello from MikroAPI!',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    #[Route('GET', '/hello/:name')]
    public function greet(Request $req): Response
    {
        $name = $req->params['name'];
        return Response::json([
            'message' => "Hello, {$name}!",
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}

// Bootstrap application
$app = new App();
$app->useController(HelloController::class)->run();
