import crypto from 'k6/crypto';
import exec from 'k6/execution';
import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import { Counter, Rate } from 'k6/metrics';

const virtualUsers = integerEnvironment('DAVYRO_LOAD_VUS', 100, 1);
const expectedMailboxes = integerEnvironment('DAVYRO_LOAD_EXPECTED_MAILBOXES', 4, 1);
const visibleCalendars = integerEnvironment('DAVYRO_LOAD_VISIBLE_CALENDARS', 12, 1);
const smoke = __ENV.DAVYRO_LOAD_SMOKE === '1';
const calendarBaseUrl = trimTrailingSlash(
  __ENV.DAVYRO_LOAD_CALENDAR_URL || 'http://127.0.0.1:8089/calendar-app',
);
const portalBaseUrl = trimTrailingSlash(
  __ENV.DAVYRO_LOAD_PORTAL_URL || 'http://127.0.0.1:8088',
);
const portalTicketTarget = __ENV.DAVYRO_LOAD_PORTAL_TICKET_TARGET
  || '/internal/calendar/session-tickets';
const requestTimeout = __ENV.DAVYRO_LOAD_REQUEST_TIMEOUT || '10s';
const pauseSeconds = numberEnvironment('DAVYRO_LOAD_PAUSE_SECONDS', 1, 0);
const pastDays = integerEnvironment('DAVYRO_LOAD_PAST_DAYS', 7, 0);
const futureDays = integerEnvironment('DAVYRO_LOAD_FUTURE_DAYS', 35, 1);
const p95Milliseconds = integerEnvironment('DAVYRO_LOAD_P95_MS', 1000, 1);
const actorsFile = __ENV.DAVYRO_LOAD_ACTORS || './actors.example.json';
const sharedSecret = loadSharedSecret();

const actors = new SharedArray('Davyro calendar load actors', () => {
  const parsed = JSON.parse(open(actorsFile));
  if (!Array.isArray(parsed)) {
    throw new Error('DAVYRO_LOAD_ACTORS must contain a JSON array');
  }

  return parsed;
});

const topologyFailures = new Counter('calendar_topology_failures');
const apiSuccess = new Rate('calendar_api_success');

export const options = {
  discardResponseBodies: false,
  noCookiesReset: true,
  scenarios: smoke
    ? {
        calendar_api: {
          executor: 'per-vu-iterations',
          vus: 1,
          iterations: 2,
          maxDuration: '30s',
        },
      }
    : {
        calendar_api: {
          executor: 'constant-vus',
          vus: virtualUsers,
          duration: __ENV.DAVYRO_LOAD_DURATION || '5m',
          gracefulStop: '30s',
        },
      },
  thresholds: {
    'http_req_duration{api:calendar}': [`p(95)<${p95Milliseconds}`],
    'http_req_failed{api:calendar}': ['rate<0.01'],
    calendar_api_success: ['rate>0.99'],
    calendar_topology_failures: ['count==0'],
  },
};

let runtimeContext = null;

export function setup() {
  if (__ENV.DAVYRO_LOAD_CONFIRM_NON_PRODUCTION !== '1') {
    exec.test.abort(
      'Refusing to run without DAVYRO_LOAD_CONFIRM_NON_PRODUCTION=1. '
      + 'Use an isolated staging or restored production-like environment.',
    );
  }

  const requiredActors = smoke ? 1 : virtualUsers;
  if (actors.length < requiredActors) {
    exec.test.abort(
      `Actor manifest contains ${actors.length} entries, but ${requiredActors} are required.`,
    );
  }

  const selectedActors = actors.slice(0, requiredActors);
  selectedActors.forEach((actor, index) => validateActor(actor, index));
  if (new Set(selectedActors.map((actor) => actor.name)).size !== requiredActors) {
    exec.test.abort('Actor names must be unique.');
  }
  if (new Set(selectedActors.map(actorCredential)).size !== requiredActors) {
    exec.test.abort('Each actor must use a distinct ticket or bridge session.');
  }

  return { actorCount: requiredActors };
}

export default function () {
  const actor = actors[exec.vu.idInTest - 1];
  if (runtimeContext === null) {
    runtimeContext = bootstrap(actor);
  }

  const contextResponse = http.get(`${calendarBaseUrl}/api/v1/context`, {
    timeout: requestTimeout,
    tags: {
      api: 'calendar',
      endpoint: 'context',
      name: 'GET /api/v1/context',
    },
  });
  const contextOkay = check(contextResponse, {
    'context returns HTTP 200': (response) => response.status === 200,
    'context returns the expected API shape': (response) => {
      const body = jsonBody(response);
      return body !== null
        && body.data !== null
        && typeof body.data === 'object'
        && Array.isArray(body.data.mailboxes)
        && Array.isArray(body.data.calendars);
    },
  }, { api: 'calendar', endpoint: 'context' });
  apiSuccess.add(contextOkay, { endpoint: 'context' });

  const now = Date.now();
  const from = new Date(now - pastDays * 86400000).toISOString();
  const to = new Date(now + futureDays * 86400000).toISOString();
  const query = [
    `from=${encodeURIComponent(from)}`,
    `to=${encodeURIComponent(to)}`,
    `calendar_ids=${encodeURIComponent(runtimeContext.calendarIds.join(','))}`,
  ].join('&');
  const eventsResponse = http.get(`${calendarBaseUrl}/api/v1/events?${query}`, {
    timeout: requestTimeout,
    tags: {
      api: 'calendar',
      endpoint: 'events',
      name: 'GET /api/v1/events',
      visible_calendars: String(runtimeContext.calendarIds.length),
    },
  });
  const eventsOkay = check(eventsResponse, {
    'events returns HTTP 200': (response) => response.status === 200,
    'events returns an array': (response) => {
      const body = jsonBody(response);
      return body !== null && Array.isArray(body.data);
    },
  }, { api: 'calendar', endpoint: 'events' });
  apiSuccess.add(eventsOkay, { endpoint: 'events' });

  sleep(pauseSeconds);
}

function bootstrap(actor) {
  const ticket = typeof actor.ticket === 'string' && /^[A-Za-z0-9]{64}$/.test(actor.ticket)
    ? actor.ticket
    : issueTicket(actor);
  const sessionResponse = http.post(
    `${calendarBaseUrl}/api/v1/session`,
    JSON.stringify({ ticket, embedded: true }),
    {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      timeout: requestTimeout,
      tags: {
        phase: 'bootstrap',
        name: 'POST /api/v1/session',
      },
    },
  );
  if (sessionResponse.status !== 201) {
    abortForActor(actor, `calendar session returned HTTP ${sessionResponse.status}`);
  }

  const contextResponse = http.get(`${calendarBaseUrl}/api/v1/context`, {
    timeout: requestTimeout,
    tags: {
      phase: 'topology',
      name: 'GET /api/v1/context (topology)',
    },
  });
  const body = jsonBody(contextResponse);
  if (contextResponse.status !== 200 || body === null || body.data === null) {
    abortForActor(actor, `topology request returned HTTP ${contextResponse.status}`);
  }

  const mailboxes = Array.isArray(body.data.mailboxes) ? body.data.mailboxes : [];
  const calendars = Array.isArray(body.data.calendars)
    ? body.data.calendars.filter((calendar) => calendar.archived_at === null)
    : [];
  const uniqueCalendarIds = [
    ...new Set(
      calendars
        .map((calendar) => calendar.id)
        .filter((id) => typeof id === 'string' && id.length > 0),
    ),
  ];

  if (mailboxes.length !== expectedMailboxes) {
    abortForActor(
      actor,
      `expected ${expectedMailboxes} mailboxes, received ${mailboxes.length}`,
    );
  }
  if (uniqueCalendarIds.length < visibleCalendars) {
    abortForActor(
      actor,
      `expected at least ${visibleCalendars} visible calendars, received ${uniqueCalendarIds.length}`,
    );
  }

  return { calendarIds: uniqueCalendarIds.slice(0, visibleCalendars) };
}

function issueTicket(actor) {
  const body = JSON.stringify({
    bridge_token: actor.bridge_token,
    mail_account_id: actor.initial_mail_account_id,
  });
  const timestamp = String(Math.floor(Date.now() / 1000));
  const nonce = crypto.sha256(
    `${timestamp}:${exec.vu.idInTest}:${exec.scenario.iterationInTest}:${Math.random()}`,
    'hex',
  );
  const canonical = [timestamp, nonce, 'POST', portalTicketTarget, body].join('\n');
  const signature = crypto.hmac('sha256', sharedSecret, canonical, 'hex');
  const response = http.post(`${portalBaseUrl}${portalTicketTarget}`, body, {
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${sharedSecret}`,
      'Content-Type': 'application/json',
      'X-Davyro-Nonce': nonce,
      'X-Davyro-Signature': signature,
      'X-Davyro-Timestamp': timestamp,
    },
    timeout: requestTimeout,
    tags: {
      phase: 'bootstrap',
      name: 'POST /internal/calendar/session-tickets',
    },
  });
  const responseBody = jsonBody(response);
  if (response.status !== 200
    || responseBody === null
    || typeof responseBody.ticket !== 'string'
    || !/^[A-Za-z0-9]{64}$/.test(responseBody.ticket)
  ) {
    abortForActor(actor, `calendar ticket request returned HTTP ${response.status}`);
  }

  return responseBody.ticket;
}

function validateActor(actor, index) {
  if (actor === null || typeof actor !== 'object') {
    exec.test.abort(`Actor ${index + 1} is not a JSON object.`);
  }
  if (typeof actor.name !== 'string' || actor.name.trim() === '') {
    exec.test.abort(`Actor ${index + 1} has no name.`);
  }

  const hasTicket = typeof actor.ticket === 'string'
    && /^[A-Za-z0-9]{64}$/.test(actor.ticket);
  const hasBridgeSession = typeof actor.bridge_token === 'string'
    && actor.bridge_token.length === 80
    && Number.isInteger(actor.initial_mail_account_id)
    && actor.initial_mail_account_id > 0;
  if (!hasTicket && !hasBridgeSession) {
    exec.test.abort(
      `Actor ${actor.name} needs either a 64-character ticket or an `
      + '80-character bridge_token with initial_mail_account_id.',
    );
  }
  if (hasBridgeSession && sharedSecret === '') {
    exec.test.abort(
      `Actor ${actor.name} uses a bridge session, but no shared secret was supplied.`,
    );
  }
}

function actorCredential(actor) {
  return typeof actor.ticket === 'string' && /^[A-Za-z0-9]{64}$/.test(actor.ticket)
    ? `ticket:${actor.ticket}`
    : `bridge:${actor.bridge_token}`;
}

function abortForActor(actor, reason) {
  topologyFailures.add(1);
  exec.test.abort(`Actor ${actor.name}: ${reason}. The measured run was aborted.`);
}

function jsonBody(response) {
  try {
    return response.json();
  } catch (_) {
    return null;
  }
}

function loadSharedSecret() {
  if (typeof __ENV.DAVYRO_LOAD_SHARED_SECRET === 'string') {
    return __ENV.DAVYRO_LOAD_SHARED_SECRET.trim();
  }
  if (typeof __ENV.DAVYRO_LOAD_SHARED_SECRET_FILE === 'string'
    && __ENV.DAVYRO_LOAD_SHARED_SECRET_FILE.trim() !== ''
  ) {
    return open(__ENV.DAVYRO_LOAD_SHARED_SECRET_FILE).trim();
  }

  return '';
}

function integerEnvironment(name, fallback, minimum) {
  const value = __ENV[name] === undefined ? fallback : Number(__ENV[name]);
  if (!Number.isInteger(value) || value < minimum) {
    throw new Error(`${name} must be an integer greater than or equal to ${minimum}`);
  }

  return value;
}

function numberEnvironment(name, fallback, minimum) {
  const value = __ENV[name] === undefined ? fallback : Number(__ENV[name]);
  if (!Number.isFinite(value) || value < minimum) {
    throw new Error(`${name} must be a number greater than or equal to ${minimum}`);
  }

  return value;
}

function trimTrailingSlash(value) {
  return value.replace(/\/+$/, '');
}
