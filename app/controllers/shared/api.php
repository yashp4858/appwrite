<?php

use Appwrite\Auth\Auth;
use Appwrite\Event\Audit;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Mail;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Usage\Stats;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Request;
use Utopia\App;
use Appwrite\Extend\Exception;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

$parseLabel = function (string $label, array $responsePayload, array $requestParams, Document $user) {
    preg_match_all('/{(.*?)}/', $label, $matches);
    foreach ($matches[1] ?? [] as $pos => $match) {
        $find = $matches[0][$pos];
        $parts = explode('.', $match);

        if (count($parts) !== 2) {
            throw new Exception('Too less or too many parts', 400, Exception::GENERAL_ARGUMENT_INVALID);
        }

        $namespace = $parts[0] ?? '';
        $replace = $parts[1] ?? '';

        $params = match ($namespace) {
            'user' => (array)$user,
            'request' => $requestParams,
            default => $responsePayload,
        };

        if (array_key_exists($replace, $params)) {
            $label = \str_replace($find, $params[$replace], $label);
        }
    }
    return $label;
};

App::init()
    ->groups(['api'])
    ->inject('utopia')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->inject('audits')
    ->inject('mails')
    ->inject('usage')
    ->inject('deletes')
    ->inject('database')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (App $utopia, Request $request, Response $response, Document $project, Document $user, Event $events, Audit $audits, Mail $mails, Stats $usage, Delete $deletes, EventDatabase $database, Database $dbForProject, string $mode) {

        $route = $utopia->match($request);

        if ($project->isEmpty() && $route->getLabel('abuse-limit', 0) > 0) { // Abuse limit requires an active project scope
            throw new Exception(Exception::PROJECT_UNKNOWN);
        }

        /*
        * Abuse Check
        */
        $abuseKeyLabel = $route->getLabel('abuse-key', 'url:{url},ip:{ip}');
        $timeLimitArray = [];

        $abuseKeyLabel = (!is_array($abuseKeyLabel)) ? [$abuseKeyLabel] : $abuseKeyLabel;

        foreach ($abuseKeyLabel as $abuseKey) {
            $timeLimit = new TimeLimit($abuseKey, $route->getLabel('abuse-limit', 0), $route->getLabel('abuse-time', 3600), $dbForProject);
            $timeLimit
                ->setParam('{userId}', $user->getId())
                ->setParam('{userAgent}', $request->getUserAgent(''))
                ->setParam('{ip}', $request->getIP())
                ->setParam('{url}', $request->getHostname() . $route->getPath());
            $timeLimitArray[] = $timeLimit;
        }

        $closestLimit = null;

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        foreach ($timeLimitArray as $timeLimit) {
            foreach ($request->getParams() as $key => $value) { // Set request params as potential abuse keys
                if (!empty($value)) {
                    $timeLimit->setParam('{param-' . $key . '}', (\is_array($value)) ? \json_encode($value) : $value);
                }
            }

            $abuse = new Abuse($timeLimit);

            if ($timeLimit->limit() && ($timeLimit->remaining() < $closestLimit || is_null($closestLimit))) {
                $closestLimit = $timeLimit->remaining();
                $response
                    ->addHeader('X-RateLimit-Limit', $timeLimit->limit())
                    ->addHeader('X-RateLimit-Remaining', $timeLimit->remaining())
                    ->addHeader('X-RateLimit-Reset', $timeLimit->time() + $route->getLabel('abuse-time', 3600));
            }

            if (
                (App::getEnv('_APP_OPTIONS_ABUSE', 'enabled') !== 'disabled' // Route is rate-limited
                    && $abuse->check()) // Abuse is not disabled
                && (!$isAppUser && !$isPrivilegedUser)
            ) { // User is not an admin or API key
                throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED);
            }
        }

        /*
        * Background Jobs
        */
        $events
            ->setEvent($route->getLabel('event', ''))
            ->setProject($project)
            ->setUser($user);

        $mails
            ->setProject($project)
            ->setUser($user);

        $audits
            ->setMode($mode)
            ->setUserAgent($request->getUserAgent(''))
            ->setIP($request->getIP())
            ->setEvent($route->getLabel('event', ''))
            ->setProject($project)
            ->setUser($user);

        $usage
            ->setParam('projectId', $project->getId())
            ->setParam('httpRequest', 1)
            ->setParam('httpUrl', $request->getHostname() . $request->getURI())
            ->setParam('httpMethod', $request->getMethod())
            ->setParam('httpPath', $route->getPath())
            ->setParam('networkRequestSize', 0)
            ->setParam('networkResponseSize', 0)
            ->setParam('storage', 0);

        $deletes->setProject($project);
        $database->setProject($project);
    });

App::init()
    ->groups(['auth'])
    ->inject('utopia')
    ->inject('request')
    ->inject('project')
    ->action(function (App $utopia, Request $request, Document $project) {

        $route = $utopia->match($request);

        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());
        $isAppUser = Auth::isAppUser(Authorization::getRoles());

        if ($isAppUser || $isPrivilegedUser) { // Skip limits for app and console devs
            return;
        }

        $auths = $project->getAttribute('auths', []);
        switch ($route->getLabel('auth.type', '')) {
            case 'emailPassword':
                if (($auths['emailPassword'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Email / Password authentication is disabled for this project');
                }
                break;

            case 'magic-url':
                if ($project->getAttribute('usersAuthMagicURL', true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Magic URL authentication is disabled for this project');
                }
                break;

            case 'anonymous':
                if (($auths['anonymous'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Anonymous authentication is disabled for this project');
                }
                break;

            case 'invites':
                if (($auths['invites'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Invites authentication is disabled for this project');
                }
                break;

            case 'jwt':
                if (($auths['JWT'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'JWT authentication is disabled for this project');
                }
                break;

            default:
                throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Unsupported authentication route');
                break;
        }
    });

App::shutdown()
    ->groups(['api'])
    ->inject('utopia')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('events')
    ->inject('audits')
    ->inject('usage')
    ->inject('deletes')
    ->inject('database')
    ->inject('mode')
    ->inject('dbForProject')
    ->action(function (App $utopia, Request $request, Response $response, Document $project, Event $events, Audit $audits, Stats $usage, Delete $deletes, EventDatabase $database, string $mode, Database $dbForProject) use ($parseLabel) {
        $responsePayload = $response->getPayload();

        if (!empty($events->getEvent())) {
            if (empty($events->getPayload())) {
                $events->setPayload($responsePayload);
            }
            /**
             * Trigger functions.
             */
            $events
                ->setClass(Event::FUNCTIONS_CLASS_NAME)
                ->setQueue(Event::FUNCTIONS_QUEUE_NAME)
                ->trigger();

            /**
             * Trigger webhooks.
             */
            $events
                ->setClass(Event::WEBHOOK_CLASS_NAME)
                ->setQueue(Event::WEBHOOK_QUEUE_NAME)
                ->trigger();

            /**
             * Trigger realtime.
             */
            if ($project->getId() !== 'console') {
                $allEvents = Event::generateEvents($events->getEvent(), $events->getParams());
                $payload = new Document($events->getPayload());

                $db = $events->getContext('database');
                $collection = $events->getContext('collection');
                $bucket = $events->getContext('bucket');

                $target = Realtime::fromPayload(
                    // Pass first, most verbose event pattern
                    event: $allEvents[0],
                    payload: $payload,
                    project: $project,
                    database: $db,
                    collection: $collection,
                    bucket: $bucket,
                );

                Realtime::send(
                    projectId: $target['projectId'] ?? $project->getId(),
                    payload: $events->getPayload(),
                    events: $allEvents,
                    channels: $target['channels'],
                    roles: $target['roles'],
                    options: [
                        'permissionsChanged' => $target['permissionsChanged'],
                        'userId' => $events->getParam('userId')
                    ]
                );
            }
        }

        $route = $utopia->match($request);
        $requestParams = $route->getParamsValues();
        $user = $audits->getUser();

        $pattern = $route->getLabel('audits.resource', null);
        if (!empty($pattern)) {
            $resource = $parseLabel($pattern, $responsePayload, $requestParams, $user);
            if (!empty($resource) && $resource !== $pattern) {
                $audits->setResource($resource);
            }
        }

        $pattern = $route->getLabel('audits.userId', null);
        if (!empty($pattern)) {
            $userId = $parseLabel($pattern, $responsePayload, $requestParams, $user);
            $user = $dbForProject->getDocument('users', $userId);
            $audits->setUser($user);
        }

        if (!empty($audits->getResource()) && !empty($audits->getUser()->getId())) {
            /**
             * audits.payload is switched to default true
             * in order to auto audit payload for all endpoints
             */
            $pattern = $route->getLabel('audits.payload', true);
            if (!empty($pattern)) {
                $audits->setPayload($responsePayload);
            }

            foreach ($events->getParams() as $key => $value) {
                $audits->setParam($key, $value);
            }
            $audits->trigger();
        }

        if (!empty($deletes->getType())) {
            $deletes->trigger();
        }

        if (!empty($database->getType())) {
            $database->trigger();
        }

        if (
            App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled'
            && $project->getId()
            && $mode !== APP_MODE_ADMIN // TODO: add check to make sure user is admin
            && !empty($route->getLabel('sdk.namespace', null))
        ) { // Don't calculate console usage on admin mode
            $metric = $route->getLabel('usage.metric', '');
            $usageParams = $route->getLabel('usage.params', []);

            if (!empty($metric)) {
                $usage->setParam($metric, 1);
                foreach ($usageParams as $param => $value) {
                    $parts = explode('.', $value);
                    $namespace = $parts[0] ?? '';
                    $key = $parts[1] ?? '';

                    $params = [];
                    switch ($namespace) {
                        case 'response':
                            $params = $responsePayload;
                            break;
                        case 'request':
                            $params = $requestParams;
                            break;
                        case 'value':
                            $usage->setParam($param, $key);
                            break;
                        default:
                            $params = $responsePayload;
                    }

                    if (array_key_exists($key, $params)) {
                        $usage->setParam($param, $params[$key]);
                    }
                }
            }

            $usage
            ->setParam('networkRequestSize', $request->getSize() + $usage->getParam('storage'))
            ->setParam('networkResponseSize', $response->getSize())
            ->submit();
        }
    });
