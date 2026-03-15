const fs = require('fs');
const path = require('path');

const root = process.cwd();
const tmpDir = path.join(root, '.tmp');
const baseUrl = process.env.GUIDE_BASE_URL || 'http://127.0.0.1:8000';

const roles = [
  {
    key: 'admin',
    phone: '910000001',
  },
  {
    key: 'focal',
    phone: '910000003',
  },
  {
    key: 'donor',
    phone: '910000004',
  },
];

async function login(phone) {
  const response = await fetch(`${baseUrl}/api/auth/verify-otp`, {
    method: 'POST',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      country_code: '+218',
      phone,
      code: '111111',
      preferred_locale: 'en',
    }),
  });

  if (!response.ok) {
    const body = await response.text();
    throw new Error(`verify-otp failed for ${phone}: ${response.status} ${body}`);
  }

  return response.json();
}

function writeState(name, payload) {
  const state = {
    cookies: [],
    origins: [
      {
        origin: baseUrl,
        localStorage: [
          {
            name: 'undp_token',
            value: payload.token,
          },
          {
            name: 'undp_user',
            value: JSON.stringify(payload.user),
          },
        ],
      },
    ],
  };

  fs.writeFileSync(path.join(tmpDir, `auth-state-${name}.json`), `${JSON.stringify(state, null, 2)}\n`);
}

function writeGuestState() {
  const state = {
    cookies: [],
    origins: [
      {
        origin: baseUrl,
        localStorage: [],
      },
    ],
  };

  fs.writeFileSync(path.join(tmpDir, 'auth-state-guest.json'), `${JSON.stringify(state, null, 2)}\n`);
}

(async () => {
  writeGuestState();

  for (const role of roles) {
    const payload = await login(role.phone);
    writeState(role.key, payload);
    console.log(`created auth-state-${role.key}.json`);
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
