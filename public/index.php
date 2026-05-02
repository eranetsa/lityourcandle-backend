<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Request;
use App\Core\Router;

App::boot(dirname(__DIR__));

$router = new Router();
require dirname(__DIR__) . '/config/routes.php';
$router->dispatch(new Request());
