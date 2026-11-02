<?php
declare(strict_types=1);

// Toutes les routes de l'API. Le contrat détaillé est dans docs/api-contract.md (dépôt frontend).

use Corder\Controllers\ArticleController;
use Corder\Controllers\ContactController;
use Corder\Controllers\ContentController;
use Corder\Controllers\NewsletterController;
use Corder\Controllers\TrainingController;
use Corder\Http\Response;
use Corder\Http\Router;

$router = new Router();

$router->get('/', static fn () => Response::json(['ok' => true, 'name' => 'API Corder']));

// Actualités et alertes
$router->get('/articles', [ArticleController::class, 'index']);
$router->get('/articles/{slug}', [ArticleController::class, 'show']);
$router->get('/alerts', [ArticleController::class, 'alerts']);

// Formations
$router->get('/trainings', [TrainingController::class, 'index']);
$router->get('/trainings/{slug}', [TrainingController::class, 'show']);
$router->post('/trainings/{slug}/registrations', [TrainingController::class, 'register']);

// Contenus
$router->get('/faq', [ContentController::class, 'faq']);
$router->get('/key-figures', [ContentController::class, 'keyFigures']);
$router->get('/team', [ContentController::class, 'team']);

// Formulaires
$router->post('/contact', [ContactController::class, 'send']);
$router->post('/newsletter/subscriptions', [NewsletterController::class, 'subscribe']);
$router->get('/newsletter/confirm', [NewsletterController::class, 'confirm']);

return $router;
