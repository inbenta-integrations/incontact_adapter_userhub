<?php

require __DIR__ . '/vendor/autoload.php';

use App\IncontactAction;
use App\SessionManager;
use Klein\Klein as Router;
use Klein\Request;
use Klein\Response;

$envPath = __DIR__ . '/.env';
if (is_file($envPath)) {
    Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

$router = new Router();

try {
    $session = new SessionManager();
    $app = new IncontactAction($session);

    $router->respond('GET', '/hours-of-operation', function (Request $request, Response $response) use ($app) {
        $result = $app->getHoursOfOperation($request->params(), $response);
        return $response->json($result);
    });

    $router->respond('GET', '/agents-availability', function (Request $request, Response $response) use ($app) {
        $result = $app->getAgentsAvailability($request->params(), $response);
        return $response->json($result);
    });

    $router->respond('GET', '/chat-profile', function (Request $request, Response $response) use ($app) {
        $result = $app->getChatProfile($request->params(), $response);
        return $response->json($result);
    });

    $router->respond('POST', '/make-chat', function (Request $request, Response $response) use ($app) {
        $result = $app->makeChat($request->body(), $response);
        return $response->json($result);
    });

    $router->respond('GET', '/get-response', function (Request $request, Response $response) use ($app) {
        $result = $app->getResponse($request->params(), $response);
        return $response->json($result);
    });

    $router->respond('POST', '/send-text', function (Request $request, Response $response) use ($app) {
        $result = $app->sendText($request->params(), $request->body(), $response);
        return $response->json($result);
    });

    $router->respond('POST', '/end-chat', function (Request $request, Response $response) use ($app) {
        $result = $app->endChat($request->params(), $response);
        return $response->json($result);
    });
} catch (Exception $e) {
    $router->respond(function (Request $request, Response $response) use ($e) {
        $response->code(403);
        $error = ["error" => $e->getMessage()];
        return $response->json($error);
    });
}

$router->dispatch();
