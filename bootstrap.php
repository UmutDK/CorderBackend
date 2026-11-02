<?php
declare(strict_types=1);

// Chargé par public/index.php et par les scripts de database/ : autoload, .env, fuseau horaire.

define('BASE_PATH', __DIR__);

require BASE_PATH . '/vendor/autoload.php';

Corder\Env::load(BASE_PATH . '/.env');

// Toutes les dates sont stockées et affichées à l'heure belge
date_default_timezone_set('Europe/Brussels');
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');
