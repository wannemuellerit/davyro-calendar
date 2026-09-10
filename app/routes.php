<?php

use AgenDAV\Controller\Authentication;
use AgenDAV\Controller\DavyroAuthentication;
use AgenDAV\Controller\Calendars;
use AgenDAV\Controller\Event;
use AgenDAV\Controller\JavaScriptCode;
use AgenDAV\Controller\InternalInvitationResponse;
use AgenDAV\Controller\InternalInvitationReply;
use AgenDAV\Controller\Preferences;
use AgenDAV\Controller\Principals;
use AgenDAV\Controller\InternalMailboxLifecycleController;
use AgenDAV\Controller\Api\V1\CalendarsController as ApiCalendarsController;
use AgenDAV\Controller\Api\V1\ContextController as ApiContextController;
use AgenDAV\Controller\Api\V1\EventsController as ApiEventsController;
use AgenDAV\Controller\Api\V1\EventDeliveryController as ApiEventDeliveryController;
use AgenDAV\Controller\Api\V1\InvitationsController as ApiInvitationsController;
use AgenDAV\Controller\Api\V1\SessionController as ApiSessionController;
use AgenDAV\Controller\Api\V1\SharesController as ApiSharesController;
use AgenDAV\Controller\Api\V1\AvailabilityController as ApiAvailabilityController;
use AgenDAV\Controller\Api\V1\IcsImportsController as ApiIcsImportsController;
use AgenDAV\Controller\Api\V1\InternalIcsImportsController;
use AgenDAV\Controller\Api\V1\PublicationsController as ApiPublicationsController;
use AgenDAV\Controller\Api\V1\PublishedCalendarController;
use AgenDAV\Controller\Api\V1\WebCalSubscriptionsController as ApiWebCalSubscriptionsController;
use AgenDAV\Middleware\ApiAuthMiddleware;
use AgenDAV\Middleware\AuthMiddleware;
use AgenDAV\Middleware\InternalApiAuthMiddleware;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $container = $app->getContainer();

    // Public routes (no auth required)
    $app->get('/login', Authentication::class . ':loginAction')->setName('login');
    $app->post('/login', Authentication::class . ':loginAction');
    $app->get('/logout', Authentication::class . ':logoutAction')->setName('logout');
    $app->get('/auth/davyro', DavyroAuthentication::class)->setName('auth.davyro');
    $app->post('/api/v1/session', ApiSessionController::class)->setName('api.v1.session');
    $app->get('/public/calendars/{token}.ics', PublishedCalendarController::class)
        ->setName('calendar.publication');
    $app->group('/internal/davyro', function (RouteCollectorProxy $internal) {
        $internal->post('/invitations/respond', InternalInvitationResponse::class);
        $internal->post('/invitations/reply', InternalInvitationReply::class);
        $internal->post('/mailboxes/{action}', InternalMailboxLifecycleController::class);
        $internal->post('/calendars/import/preview', InternalIcsImportsController::class.':preview');
        $internal->post('/calendars/import/commit', InternalIcsImportsController::class.':commit');
    })->add(new InternalApiAuthMiddleware($container));

    $app->group('/api/v1', function (RouteCollectorProxy $api) {
        $api->get('/context', ApiContextController::class)->setName('api.v1.context');
        $api->get('/mailboxes/{mailbox_id}/calendars', ApiCalendarsController::class.':list');
        $api->post('/mailboxes/{mailbox_id}/calendars', ApiCalendarsController::class.':create');
        $api->patch('/calendars/{id}', ApiCalendarsController::class.':update');
        $api->delete('/calendars/{id}', ApiCalendarsController::class.':delete');
        $api->get('/events', ApiEventsController::class.':list');
        $api->post('/calendars/{id}/events', ApiEventsController::class.':create');
        $api->patch('/calendars/{id}/events/{uid}', ApiEventsController::class.':update');
        $api->delete('/calendars/{id}/events/{uid}', ApiEventsController::class.':delete');
        $api->get('/events/{id}/delivery', ApiEventDeliveryController::class.':get');
        $api->post('/events/{id}/delivery/retry', ApiEventDeliveryController::class.':retry');
        $api->get('/invitations/{id}', ApiInvitationsController::class.':get');
        $api->post('/invitations/{id}/response', ApiInvitationsController::class.':respond');
        $api->get('/calendars/{id}/share-candidates', ApiSharesController::class.':candidates');
        $api->get('/calendars/{id}/shares', ApiSharesController::class.':list');
        $api->post('/calendars/{id}/shares', ApiSharesController::class.':create');
        $api->delete('/calendars/{id}/shares/{share_id}', ApiSharesController::class.':delete');
        $api->post('/imports/ics/preview', ApiIcsImportsController::class.':preview');
        $api->post('/calendars/{id}/imports/ics', ApiIcsImportsController::class.':commit');
        $api->get('/calendars/{id}/publications', ApiPublicationsController::class.':list');
        $api->post('/calendars/{id}/publications', ApiPublicationsController::class.':create');
        $api->post('/calendars/{id}/publications/rotate', ApiPublicationsController::class.':rotate');
        $api->delete('/calendars/{id}/publications/{publication_id}', ApiPublicationsController::class.':revoke');
        $api->get('/mailboxes/{id}/availability', ApiAvailabilityController::class.':get');
        $api->put('/mailboxes/{id}/availability', ApiAvailabilityController::class.':put');
        $api->post('/availability/check', ApiAvailabilityController::class.':check');
        $api->get('/mailboxes/{mailbox_id}/subscriptions', ApiWebCalSubscriptionsController::class.':list');
        $api->post('/mailboxes/{mailbox_id}/subscriptions', ApiWebCalSubscriptionsController::class.':create');
        $api->patch('/mailboxes/{mailbox_id}/subscriptions/{id}', ApiWebCalSubscriptionsController::class.':update');
        $api->delete('/mailboxes/{mailbox_id}/subscriptions/{id}', ApiWebCalSubscriptionsController::class.':delete');
        $api->post('/mailboxes/{mailbox_id}/subscriptions/{id}/refresh', ApiWebCalSubscriptionsController::class.':refreshOne');
    })->add(new ApiAuthMiddleware($container));

    // Authenticated routes
    $app->group('', function (RouteCollectorProxy $g) use ($container) {
        $g->get('/', function (ServerRequestInterface $request, ResponseInterface $response) use ($container) {
            $body = $container->get('twig')->render('calendar.html', [
                'json_config' => (new JavaScriptCode($container))->renderConfig($request),
            ]);
            $response->getBody()->write($body);
            return $response;
        })->setName('calendar');

        $g->get('/preferences', Preferences::class . ':indexAction')->setName('preferences');
        $g->post('/preferences', Preferences::class . ':saveAction')->setName('preferences.save');

        $g->get('/calendars', Calendars\Listing::class)->setName('calendars.list');
        $g->post('/calendars', Calendars\Create::class)->setName('calendar.create');
        $g->post('/calendars/delete', Calendars\Delete::class)->setName('calendar.delete');
        $g->post('/calendars/save', Calendars\Save::class)->setName('calendar.save');

        $g->get('/events', Event\Listing::class)->setName('events.list');
        $g->get('/eventbase', Event\GetBase::class)->setName('event.getBase');
        $g->post('/events/drop', Event\Drop::class)->setName('event.drop');
        $g->post('/events/resize', Event\Resize::class)->setName('event.resize');
        $g->post('/events/delete', Event\Delete::class)->setName('event.delete');
        $g->post('/events/save', Event\Save::class)->setName('event.save');

        $g->get('/principals', Principals::class . ':search')->setName('principals.search');

        // Session keepalive
        $g->get('/keepalive', function (ServerRequestInterface $request, ResponseInterface $response) {
            return $response;
        });
    })->add(new AuthMiddleware($container));
};
