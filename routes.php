<?php
declare(strict_types=1);

// Toutes les routes de l'API. Le contrat détaillé est dans docs/api-contract.md (dépôt frontend).

use Corder\Controllers\Admin\ArticleAdminController;
use Corder\Controllers\Admin\AuthController;
use Corder\Controllers\Admin\DashboardController;
use Corder\Controllers\Admin\RegistrationAdminController;
use Corder\Controllers\Admin\TrainingAdminController;
use Corder\Controllers\Admin\UploadController;
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

// ── Administration (auth: true = session d'administration obligatoire) ──
$router->post('/admin/login', [AuthController::class, 'login']);
$router->post('/admin/logout', [AuthController::class, 'logout']);
$router->get('/admin/me', [AuthController::class, 'me']);
$router->get('/admin/stats', [DashboardController::class, 'stats'], auth: true);

$router->get('/admin/articles', [ArticleAdminController::class, 'index'], auth: true);
$router->post('/admin/articles', [ArticleAdminController::class, 'create'], auth: true);
$router->get('/admin/articles/{id}', [ArticleAdminController::class, 'show'], auth: true);
$router->put('/admin/articles/{id}', [ArticleAdminController::class, 'update'], auth: true);
$router->delete('/admin/articles/{id}', [ArticleAdminController::class, 'delete'], auth: true);
$router->get('/admin/categories', [ArticleAdminController::class, 'categories'], auth: true);
$router->post('/admin/uploads', [UploadController::class, 'store'], auth: true);

$router->get('/admin/trainings', [TrainingAdminController::class, 'index'], auth: true);
$router->post('/admin/trainings', [TrainingAdminController::class, 'create'], auth: true);
$router->get('/admin/trainings/{id}', [TrainingAdminController::class, 'show'], auth: true);
$router->put('/admin/trainings/{id}', [TrainingAdminController::class, 'update'], auth: true);
$router->delete('/admin/trainings/{id}', [TrainingAdminController::class, 'delete'], auth: true);
$router->post('/admin/trainings/{id}/registrations', [RegistrationAdminController::class, 'create'], auth: true);

$router->get('/admin/registrations', [RegistrationAdminController::class, 'index'], auth: true);
$router->get('/admin/registrations/export', [RegistrationAdminController::class, 'export'], auth: true);
$router->delete('/admin/registrations/{id}', [RegistrationAdminController::class, 'delete'], auth: true);

return $router;
