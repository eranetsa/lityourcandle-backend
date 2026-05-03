<?php
declare(strict_types=1);

use App\Controllers\AiController;
use App\Controllers\AuthController;
use App\Controllers\BookingController;
use App\Controllers\ChatController;
use App\Controllers\ConsultantController;
use App\Controllers\DailyMessageController;
use App\Controllers\HealthController;
use App\Controllers\MoodController;
use App\Controllers\NotificationController;
use App\Controllers\PaywallController;
use App\Controllers\ProgramController;
use App\Controllers\SubscriptionController;
use App\Controllers\UserController;
use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;

/** @var \App\Core\Router $router */

$router->get ('/api/health',  [HealthController::class, 'index']);

// Auth
$router->post('/api/auth/register', [AuthController::class, 'register'], [RateLimitMiddleware::class]);
$router->post('/api/auth/login',    [AuthController::class, 'login'],    [RateLimitMiddleware::class]);
$router->post('/api/auth/guest',    [AuthController::class, 'guest'],    [RateLimitMiddleware::class]);

// Users
$router->get ('/api/me',                [UserController::class, 'me'],            [AuthMiddleware::class]);
$router->put ('/api/me',                [UserController::class, 'update'],        [AuthMiddleware::class]);
$router->post('/api/me/push-token',     [UserController::class, 'savePushToken'], [AuthMiddleware::class]);
$router->post('/api/me/change-password',[UserController::class, 'changePassword'],[AuthMiddleware::class]);

// Subscriptions
$router->get ('/api/subscriptions/plans',          [SubscriptionController::class, 'plans']);
$router->get ('/api/subscriptions/me',             [SubscriptionController::class, 'mine'],          [AuthMiddleware::class]);
$router->post('/api/subscriptions/start-trial',    [SubscriptionController::class, 'startTrial'],    [AuthMiddleware::class]);
$router->post('/api/subscriptions/apple/validate', [SubscriptionController::class, 'validateApple'], [AuthMiddleware::class]);
$router->post('/api/subscriptions/google/validate',[SubscriptionController::class, 'validateGoogle'],[AuthMiddleware::class]);
$router->post('/api/subscriptions/extra-session',  [SubscriptionController::class, 'buyExtraSession'], [AuthMiddleware::class]);

// Consultants
$router->get ('/api/consultants',           [ConsultantController::class, 'list']);
$router->get ('/api/consultants/{id}',      [ConsultantController::class, 'show']);
$router->get ('/api/consultants/{id}/availability', [ConsultantController::class, 'availability']);

// Booking & Sessions
$router->post('/api/bookings',                [BookingController::class, 'create'],   [AuthMiddleware::class]);
$router->get ('/api/sessions',                [BookingController::class, 'mine'],     [AuthMiddleware::class]);
$router->get ('/api/sessions/{id}',           [BookingController::class, 'show'],     [AuthMiddleware::class]);
$router->post('/api/sessions/{id}/start',     [BookingController::class, 'start'],    [AuthMiddleware::class]);
$router->post('/api/sessions/{id}/end',       [BookingController::class, 'end'],      [AuthMiddleware::class]);
$router->post('/api/sessions/{id}/cancel',    [BookingController::class, 'cancel'],   [AuthMiddleware::class]);
$router->post('/api/sessions/{id}/feedback',  [BookingController::class, 'feedback'], [AuthMiddleware::class]);

// Chat / Voice / Video
$router->get ('/api/sessions/{id}/messages',  [ChatController::class, 'list'], [AuthMiddleware::class]);
$router->post('/api/sessions/{id}/messages',  [ChatController::class, 'send'], [AuthMiddleware::class]);
$router->get ('/api/sessions/{id}/rtc-token', [ChatController::class, 'rtcToken'], [AuthMiddleware::class]);

// AI شمعة
$router->post('/api/ai/candle', [AiController::class, 'candle'], [AuthMiddleware::class, RateLimitMiddleware::class]);

// Mood
$router->post('/api/mood',          [MoodController::class, 'log'],     [AuthMiddleware::class]);
$router->get ('/api/mood/history',  [MoodController::class, 'history'], [AuthMiddleware::class]);
$router->get ('/api/mood/insights', [MoodController::class, 'insights'],[AuthMiddleware::class]);

// Programs
$router->get ('/api/programs',            [ProgramController::class, 'list']);
$router->get ('/api/programs/{slug}',     [ProgramController::class, 'show']);
$router->post('/api/programs/{slug}/days/{day}/complete', [ProgramController::class, 'complete'], [AuthMiddleware::class]);

// Daily message
$router->get ('/api/daily-message', [DailyMessageController::class, 'today']);

// Notifications
$router->get ('/api/notifications',          [NotificationController::class, 'list'], [AuthMiddleware::class]);
$router->post('/api/notifications/{id}/read',[NotificationController::class, 'markRead'], [AuthMiddleware::class]);

// Paywall / exit popup
$router->post('/api/paywall/check',   [PaywallController::class, 'check'],     [AuthMiddleware::class]);
$router->get ('/api/exit-popup',      [PaywallController::class, 'exitPopup']);
