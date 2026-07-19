const { test, expect } = require('@playwright/test');
const crypto = require('crypto');

function decodeBase32(value) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const character of value.replace(/=+$/g, '').toUpperCase()) {
    const index = alphabet.indexOf(character);
    if (index < 0) throw new Error('Invalid base32 secret');
    bits += index.toString(2).padStart(5, '0');
  }
  const bytes = [];
  for (let offset = 0; offset + 8 <= bits.length; offset += 8) bytes.push(parseInt(bits.slice(offset, offset + 8), 2));
  return Buffer.from(bytes);
}

function totp(secret, timestamp = Date.now()) {
  const counter = Math.floor(timestamp / 30000);
  const buffer = Buffer.alloc(8);
  buffer.writeUInt32BE(Math.floor(counter / 0x100000000), 0);
  buffer.writeUInt32BE(counter >>> 0, 4);
  const digest = crypto.createHmac('sha1', decodeBase32(secret)).update(buffer).digest();
  const offset = digest[digest.length - 1] & 0x0f;
  return ((digest.readUInt32BE(offset) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}

async function signIn(page) {
  await page.goto('/index.php?route=login');
  await page.getByLabel('Username').fill('secureadmin');
  await page.getByLabel('Password').fill('secure-browser-password');
  await page.getByRole('button', { name: 'Sign in' }).click();
}

test('trusted HTTPS supports TOTP, recovery readiness, passkeys, and recovery UI', async ({ page, context, browserName, baseURL }) => {
  test.skip(browserName !== 'chromium', 'Virtual WebAuthn coverage uses Chromium DevTools; Firefox is covered by the general UI workflow.');
  test.skip(!String(baseURL || '').startsWith('https://'), 'Secure-auth coverage requires the HTTPS staging profile.');
  test.setTimeout(90000);
  await page.goto('/index.php');
  await expect(page.getByRole('heading', { name: 'Install Cogwork Engine' })).toBeVisible();
  await page.getByLabel('Administrator username').fill('secureadmin');
  await page.getByLabel('Administrator email').fill('secureadmin@example.test');
  await page.getByLabel('Administrator password', { exact: true }).fill('secure-browser-password');
  await page.getByLabel('Repeat administrator password').fill('secure-browser-password');
  await page.getByRole('button', { name: 'Install' }).click();

  await signIn(page);
  await page.getByRole('dialog').getByRole('button', { name: 'Skip tutorial' }).click();
  await expect(page.getByRole('heading', { name: 'Modpacks' })).toBeVisible();
  await page.waitForLoadState('networkidle');
  // Caddy's internal certificate can report an aborted load after committing a
  // same-origin navigation in Chromium; assert the resulting document instead.
  await page.goto('/index.php?route=admin&tab=system', { waitUntil: 'commit' }).catch(() => {});
  await expect(page.getByRole('heading', { name: 'Administration' })).toBeVisible();
  await page.getByRole('link', { name: /^Security/ }).click();
  const security = page.locator('.system-settings > details', { hasText: 'Security and public URL' });
  await security.locator('summary').click();
  await security.getByLabel('Canonical URL').fill('https://e2e-secure-proxy');
  await security.getByLabel('Trusted proxies (one per line)').fill('0.0.0.0/0');
  await security.getByRole('button', { name: 'Save security settings' }).click();
  await expect(page.getByText('Trusted HTTPS is not active')).toHaveCount(0);
  await page.getByRole('link', { name: /^Integrations/ }).click();
  const mail = page.locator('.system-settings > details', { has: page.getByText('Mail delivery', { exact: true }) });
  await mail.locator('summary').click();
  await mail.getByLabel('Sender address').fill('noreply@example.test');
  await mail.getByRole('button', { name: 'Save mail settings' }).click();
  await page.getByRole('link', { name: /^Features/ }).click();
  const features = page.locator('.system-settings > details', { has: page.getByText('Features', { exact: true }) });
  await features.locator('summary').click();
  await features.getByLabel('Password recovery').check();
  await features.getByLabel('Two-factor authentication').check();
  await features.getByLabel('Passkeys / Windows Hello').check();
  await features.getByLabel('Reason').fill('Secure browser regression test');
  await features.getByRole('button', { name: 'Save features' }).click();

  // The recovery form is available only after both canonical and effective HTTPS are trusted.
  await page.locator('[data-account-menu] summary').click();
  await page.getByRole('menuitem', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await page.getByRole('link', { name: 'Forgot password?' }).click();
  await expect(page.getByRole('heading', { name: 'Forgot password' })).toBeVisible();
  await page.getByLabel('Email').fill('unknown@example.test');
  await page.getByRole('button', { name: 'Send reset link' }).click();
  await expect(page.getByText('If that address belongs to an account, a reset link has been sent.')).toBeVisible();

  await signIn(page);
  await page.goto('/index.php?route=account');
  await page.getByRole('link', { name: 'Set up authenticator app' }).click();
  const secret = await page.getByLabel('Manual setup key').inputValue();
  await page.getByLabel('Authentication code').fill(totp(secret));
  await page.getByRole('button', { name: 'Confirm and generate recovery codes' }).click();
  await expect(page.getByRole('heading', { name: 'Save your recovery codes' })).toBeVisible();
  await expect(page.locator('.recovery-codes li')).toHaveCount(10);
  await page.getByRole('button', { name: 'I have saved these recovery codes' }).click();

  await page.locator('[data-account-menu] summary').click();
  await page.getByRole('menuitem', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await signIn(page);
  await expect(page.getByRole('heading', { name: 'Two-factor authentication' })).toBeVisible();
  // Enrollment claims the current counter. Use the next counter, which is
  // accepted by the documented one-step skew window, rather than replaying it.
  await expect(page.getByRole('link', { name: 'Recover with email' })).toBeVisible();
  await page.getByLabel('Authentication code').fill(totp(secret, Date.now() + 30000));
  await page.getByRole('button', { name: 'Verify authenticator code' }).click();
  await expect(page.getByRole('heading', { name: 'Modpacks' })).toBeVisible();

  const cdp = await context.newCDPSession(page);
  await cdp.send('WebAuthn.enable');
  await cdp.send('WebAuthn.addVirtualAuthenticator', { options: {
    protocol: 'ctap2', transport: 'internal', hasResidentKey: true,
    hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true,
  }});
  await page.goto('/index.php?route=account');
  await page.getByLabel('Passkey name').fill('Automated platform passkey');
  await page.getByRole('button', { name: 'Add passkey' }).click();
  await expect(page.getByText('Automated platform passkey', { exact: true })).toBeVisible();

  await page.locator('[data-account-menu] summary').click();
  await page.getByRole('menuitem', { name: 'Sign out' }).click();
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await signIn(page);
  await expect(page.getByText('Choose how you want to verify this sign-in.')).toBeVisible();
  await page.getByRole('link', { name: 'Use passkey / Windows Hello' }).click();
  await page.getByRole('button', { name: 'Continue with passkey / Windows Hello' }).click();
  await expect(page.getByRole('heading', { name: 'Modpacks' })).toBeVisible();
});
