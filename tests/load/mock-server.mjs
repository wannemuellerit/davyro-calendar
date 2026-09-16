import { createHmac, timingSafeEqual } from 'node:crypto';
import { createServer } from 'node:http';

const host = '127.0.0.1';
const port = Number(process.env.DAVYRO_LOAD_MOCK_PORT || 19089);
const sharedSecret = 'load-smoke-secret';
const ticket = 'b'.repeat(64);

const mailboxes = Array.from({ length: 4 }, (_, index) => ({
  id: `mailbox-${index + 1}`,
  email: `load-${index + 1}@example.test`,
  name: `Load mailbox ${index + 1}`,
}));
const calendars = Array.from({ length: 12 }, (_, index) => ({
  id: `calendar-${String(index + 1).padStart(2, '0')}`,
  mailbox_id: mailboxes[index % mailboxes.length].id,
  name: `Load calendar ${index + 1}`,
  archived_at: null,
}));

const server = createServer(async (request, response) => {
  const body = await requestBody(request);

  if (request.method === 'GET' && request.url === '/ready') {
    return json(response, 200, { ready: true });
  }

  if (request.method === 'POST' && request.url === '/internal/calendar/session-tickets') {
    if (!validInternalSignature(request, body)) {
      return json(response, 401, { error: 'invalid signature' });
    }

    return json(response, 200, { ticket, expires_at: new Date(Date.now() + 60000).toISOString() });
  }

  if (request.method === 'POST' && request.url === '/calendar-app/api/v1/session') {
    let payload;
    try {
      payload = JSON.parse(body);
    } catch (_) {
      return json(response, 422, { error: 'invalid JSON' });
    }
    if (payload.ticket !== ticket) {
      return json(response, 403, { error: 'invalid ticket' });
    }
    response.setHeader('Set-Cookie', 'davyro-load-session=ok; Path=/calendar-app; HttpOnly');

    return json(response, 201, { data: { authenticated: true }, csrf_token: 'smoke' });
  }

  if (request.method === 'GET' && request.url?.startsWith('/calendar-app/api/v1/')) {
    if (!request.headers.cookie?.includes('davyro-load-session=ok')) {
      return json(response, 401, { error: 'unauthenticated' });
    }
    if (request.url === '/calendar-app/api/v1/context') {
      return json(response, 200, {
        data: { user: { id: 'load-user' }, mailboxes, calendars },
        csrf_token: 'smoke',
      });
    }
    if (request.url.startsWith('/calendar-app/api/v1/events?')) {
      const url = new URL(request.url, `http://${host}:${port}`);
      const ids = (url.searchParams.get('calendar_ids') || '').split(',').filter(Boolean);
      if (ids.length !== calendars.length || !url.searchParams.has('from') || !url.searchParams.has('to')) {
        return json(response, 422, { error: 'invalid event query' });
      }

      return json(response, 200, { data: [] });
    }
  }

  return json(response, 404, { error: 'not found' });
});

server.listen(port, host, () => {
  process.stdout.write(`Davyro load-test mock listening on http://${host}:${port}\n`);
});

function validInternalSignature(request, body) {
  const authorization = request.headers.authorization || '';
  const timestamp = request.headers['x-davyro-timestamp'] || '';
  const nonce = request.headers['x-davyro-nonce'] || '';
  const signature = request.headers['x-davyro-signature'] || '';
  if (authorization !== `Bearer ${sharedSecret}` || !/^\d{10}$/.test(timestamp)) {
    return false;
  }
  const canonical = [timestamp, nonce, request.method, request.url, body].join('\n');
  const expected = createHmac('sha256', sharedSecret).update(canonical).digest('hex');
  const providedBuffer = Buffer.from(signature, 'utf8');
  const expectedBuffer = Buffer.from(expected, 'utf8');

  return providedBuffer.length === expectedBuffer.length
    && timingSafeEqual(providedBuffer, expectedBuffer);
}

async function requestBody(request) {
  const chunks = [];
  for await (const chunk of request) {
    chunks.push(chunk);
  }

  return Buffer.concat(chunks).toString('utf8');
}

function json(response, status, payload) {
  response.writeHead(status, {
    'Cache-Control': 'no-store',
    'Content-Type': 'application/json; charset=utf-8',
  });
  response.end(JSON.stringify(payload));
}
