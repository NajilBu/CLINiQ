import http from 'k6/http';
import { check, sleep } from 'k6';

const base = (__ENV.BASE_URL || 'http://127.0.0.1:8081').replace(/\/$/, '');
const productionHost = /plpuhs\.dpdns\.org/i.test(base);
if (productionHost && __ENV.LOAD_TEST_ALLOW_PRODUCTION !== '1') {
  throw new Error('Refusing to load-test the production domain. Use a staging URL or set LOAD_TEST_ALLOW_PRODUCTION=1 explicitly.');
}
const studentId = __ENV.STUDENT_ID || '';
const studentPassword = __ENV.STUDENT_PASSWORD || '';
const adminBase = (__ENV.ADMIN_BASE_URL || '').replace(/\/$/, '');
const adminCookie = __ENV.ADMIN_COOKIE || '';

export const options = {
  scenarios: {
    enrollment_ramp: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '2m', target: 50 },
        { duration: '5m', target: 200 },
        { duration: '5m', target: 500 },
        { duration: '5m', target: 500 },
        { duration: '2m', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<1500', 'p(99)<3000'],
  },
};

export default function () {
  const login = http.get(`${base}/patient-portal/patient-login.php?logged_out=1`, { tags: { flow: 'login_page' } });
  check(login, { 'login page responds': (r) => r.status === 200 });

  const register = http.get(`${base}/patient-portal/patient-register.php`, { tags: { flow: 'registration_page' } });
  check(register, { 'registration page responds': (r) => r.status === 200 });

  if (studentId && studentPassword) {
    const csrfMatch = login.body.match(/name=["']_csrf["'][^>]*value=["']([^"']+)/i);
    const csrf = csrfMatch ? csrfMatch[1] : '';
    const session = http.post(`${base}/patient-portal/patient-login.php`, {
      _csrf: csrf,
      student_id: studentId,
      password: studentPassword,
    }, { redirects: 0, tags: { flow: 'student_login' } });
    check(session, { 'student login accepted': (r) => [302, 303].includes(r.status) });

    const dashboard = http.get(`${base}/patient-portal/patient-dashboard.php`, { tags: { flow: 'student_dashboard' } });
    check(dashboard, { 'student dashboard responds': (r) => r.status === 200 });
    const ape = http.get(`${base}/patient-portal/patient-ape-status.php`, { tags: { flow: 'student_ape_status' } });
    check(ape, { 'student APE status responds': (r) => r.status === 200 });
  }

  if (adminBase && adminCookie) {
    const admin = http.get(`${adminBase}/public/patient-accounts/index.php`, {
      headers: { Cookie: adminCookie },
      tags: { flow: 'admin_student_search' },
    });
    check(admin, { 'admin search page responds': (r) => r.status === 200 });
  }

  const health = http.get(`${base}/public/api/health.php`, { tags: { flow: 'health' } });
  check(health, { 'health endpoint responds': (r) => r.status === 200 || r.status === 404 });
  sleep(Math.random() * 2);
}
