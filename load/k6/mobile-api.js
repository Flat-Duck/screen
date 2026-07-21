import http from 'k6/http';
import { check, sleep } from 'k6';

const baseUrl = (__ENV.BASE_URL || '').replace(/\/$/, '');
const userToken = __ENV.USER_TOKEN || '';
const deviceToken = __ENV.DEVICE_TOKEN || '';
const conversationId = __ENV.CONVERSATION_ID || '';
const postId = __ENV.POST_ID || '';
const screenshot = __ENV.SCREENSHOT_PATH ? open(__ENV.SCREENSHOT_PATH, 'b') : null;

if (!baseUrl || !userToken) throw new Error('BASE_URL and USER_TOKEN are required. Read load/README.md before running.');

const scenarios = {
  reads: { executor: 'constant-arrival-rate', exec: 'readJourneys', rate: Number(__ENV.READ_RPS || 5), timeUnit: '1s', duration: __ENV.DURATION || '2m', preAllocatedVUs: 10, maxVUs: 50 },
  analytics: { executor: 'constant-arrival-rate', exec: 'analyticsBatches', rate: Number(__ENV.ANALYTICS_RPS || 1), timeUnit: '1s', duration: __ENV.DURATION || '2m', preAllocatedVUs: 5, maxVUs: 20 },
};
if (conversationId) scenarios.messaging = { executor: 'constant-vus', exec: 'messaging', vus: Number(__ENV.MESSAGE_VUS || 1), duration: __ENV.DURATION || '2m' };
if (screenshot) scenarios.uploads = { executor: 'constant-arrival-rate', exec: 'screenshotUploads', rate: Number(__ENV.UPLOADS_PER_MINUTE || 2), timeUnit: '1m', duration: __ENV.DURATION || '2m', preAllocatedVUs: 2, maxVUs: 5 };
if (deviceToken) scenarios.telemetry = { executor: 'constant-arrival-rate', exec: 'telemetryBatches', rate: 1, timeUnit: '1s', duration: __ENV.DURATION || '2m', preAllocatedVUs: 2, maxVUs: 10 };

export const options = {
  scenarios,
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{workflow:read}': ['p(95)<500'],
    'http_req_duration{workflow:write}': ['p(95)<1000'],
    checks: ['rate>0.99'],
  },
};

function userParams(tags = {}) { return { headers: { Authorization: `Bearer ${userToken}`, Accept: 'application/json' }, tags }; }
function jsonParams(token, tags = {}) { return { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' }, tags }; }
function successful(response) { return check(response, { 'status is successful': (r) => r.status >= 200 && r.status < 300 }); }
function randomUUID() { return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => { const value = Math.floor(Math.random() * 16); return (character === 'x' ? value : (value & 0x3) | 0x8).toString(16); }); }

export function readJourneys() {
  const params = userParams({ workflow: 'read' });
  successful(http.get(`${baseUrl}/api/v1/feed/for-you`, params));
  successful(http.get(`${baseUrl}/api/v1/explore`, params));
  successful(http.get(`${baseUrl}/api/v1/search/posts?q=screenshot`, params));
  successful(http.get(`${baseUrl}/api/v1/notifications`, params));
  sleep(1);
}

export function analyticsBatches() {
  if (!postId) return;
  const event = { event_id: randomUUID(), event_type: 'impression', post_id: Number(postId), author_id: Number(__ENV.AUTHOR_ID), surface: 'for_you_feed', occurred_at: new Date().toISOString(), position: 0, candidate_source: 'trending' };
  successful(http.post(`${baseUrl}/api/v1/analytics/content-events`, JSON.stringify({ events: [event] }), jsonParams(userToken, { workflow: 'write' })));
}

export function messaging() {
  const body = { body: `Load-test message ${randomUUID()}`, client_message_id: randomUUID() };
  successful(http.post(`${baseUrl}/api/v1/conversations/${conversationId}/messages`, JSON.stringify(body), jsonParams(userToken, { workflow: 'write' })));
  successful(http.get(`${baseUrl}/api/v1/conversations/${conversationId}/messages`, userParams({ workflow: 'read' })));
  sleep(1);
}

export function screenshotUploads() {
  const response = http.post(`${baseUrl}/api/v1/posts`, { caption: 'Authorized load-test screenshot', 'images[]': http.file(screenshot, 'load-test.png', 'image/png') }, userParams({ workflow: 'write' }));
  successful(response);
}

export function telemetryBatches() {
  const event = { event_id: randomUUID(), kind: 'event', name: 'load_test_heartbeat', occurred_at: new Date().toISOString(), extras: {}, breadcrumbs: [] };
  successful(http.post(`${baseUrl}/api/v1/telemetry/events`, JSON.stringify({ app: { version_name: 'load-test', version_code: 1, build_type: 'test' }, os_version: 'test', events: [event] }), jsonParams(deviceToken, { workflow: 'write' })));
}
