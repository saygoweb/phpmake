<?php

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Factory\AppFactory;

require(__DIR__ . '/vendor/autoload.php');

$app = AppFactory::create();
$app->setBasePath('/deploy');

$app->addBodyParsingMiddleware();
// $app->addRoutingMiddleware(); // Not needed CP 2020-04-26 See http://www.slimframework.com/docs/v4/middleware/routing.html

// $app->addErrorMiddleware(true, true, true, $container->get(\Psr\Log\LoggerInterface::class));

// Enabling Cors - Needs to be the last middleware, first in last out
$app->add(function (Request $request, RequestHandler $handler) use ($allowedOrigin) {
    $response = $handler->handle($request);
    return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'X-Timezone, X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});

$app->options('/{routes:.+}', function (Request $request, Response $response, $args) {
    return $response;
});

$app->post('/push', function(Request $request, Response $response) {
    // Called on GitHub push
    

});

$app->run();