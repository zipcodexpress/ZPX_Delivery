import { expect, test } from '@playwright/test';

const credentials = JSON.parse(process.env.ZPX_E2E_CREDENTIALS || '[]');
const account = role => {
  const match = credentials.find(item => item.identity.endsWith(':' + role));
  if (!match) throw new Error(`Missing synthetic ${role} credentials`);
  return match;
};

async function signIn(page, role) {
  const user = account(role);
  await page.getByRole('textbox', { name: 'Email address' }).fill(user.email);
  await page.getByRole('textbox', { name: 'Password' }).fill(user.password);
  await page.locator('form').getByRole('button', { name: 'Sign in', exact: true }).click();
}

async function scanDriver(page, label, count, buttonName) {
  await page.getByRole('textbox', { name: 'Scan or enter label token' }).fill(label);
  await page.getByRole('button', { name: buttonName }).click();
  await expect(page.locator('.notice[role="status"]')).toContainText(`Package scanned. ${count}/5 loaded.`);
}

test('synthetic parcel moves from inbound driver through hub to ordered outbound arrival', async ({ page }) => {
  await page.goto('/');
  await signIn(page, 'DRIVER-IN');
  await expect(page.getByText('DRIVER WORKSPACE')).toBeVisible();
  await page.locator('.run-list li').filter({ hasText: 'INBOUND' }).click();
  await page.getByRole('button', { name: 'Acknowledge run' }).click();
  await expect(page.locator('.notice[role="status"]')).toContainText('Run acknowledged.');
  for (let n = 1; n <= 5; n++) await scanDriver(page, `TEST-LABEL-00${n}`, n, 'Scan package');
  await page.getByRole('button', { name: 'Sign out' }).click();

  await signIn(page, 'HUB-STAFF');
  await expect(page.getByText('HUB OPERATIONS', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Open session' }).click();
  await page.getByRole('textbox', { name: 'Scan or enter label token' }).fill('TEST-LABEL-001');
  await page.getByRole('button', { name: 'Receive package' }).click();
  await expect(page.getByText('1 / 5')).toBeVisible();
  await page.reload();
  await page.getByRole('button', { name: 'Resume session' }).click();
  await expect(page.getByText('1 / 5')).toBeVisible();
  for (let n = 2; n <= 5; n++) {
    await page.getByRole('textbox', { name: 'Scan or enter label token' }).fill(`TEST-LABEL-00${n}`);
    await page.getByRole('button', { name: 'Receive package' }).click();
    await expect(page.getByText(`${n} / 5`)).toBeVisible();
  }
  await page.getByRole('button', { name: 'Close Session' }).click();
  await expect(page.locator('.notice[role="status"]')).toContainText('Session closed. 5/5 received.');

  await page.getByRole('button', { name: 'Staging' }).click();
  for (let n = 1; n <= 5; n++) {
    await page.getByRole('textbox', { name: 'Label token' }).fill(`TEST-LABEL-00${n}`);
    await page.getByRole('button', { name: 'Resolve package' }).click();
    await expect(page.getByText('RESOLVED', { exact: true })).toBeVisible();
    await page.getByLabel('Destination slot').selectOption('LOT-AUS-004');
    await page.getByRole('button', { name: 'Confirm stage' }).click();
    await expect(page.locator('.notice[role="status"]')).toContainText('staged to LOT-AUS-004');
  }
  await page.getByRole('button', { name: 'Dispatch' }).click();
  await page.getByLabel('Ready staging slot').selectOption({ index: 1 });
  await page.getByRole('button', { name: 'Create dispatch call' }).click();
  await expect(page.locator('.notice[role="status"]')).toContainText('Dispatch call created');
  await page.getByRole('button', { name: 'Sign out' }).click();

  await signIn(page, 'DRIVER-OUT');
  await page.getByRole('button', { name: 'Accept dispatch' }).click();
  await expect(page.locator('.notice[role="status"]')).toContainText('Dispatch accepted.');
  await page.locator('.run-list li').filter({ hasText: 'OUTBOUND' }).click();
  for (let n = 1; n <= 4; n++) await scanDriver(page, `TEST-LABEL-00${n}`, n, 'Load package');
  await expect(page.getByRole('button', { name: 'Verify load and depart' })).toBeDisabled();
  await page.getByRole('textbox', { name: 'Scan or enter label token' }).fill('TEST-LABEL-001');
  await page.getByRole('button', { name: 'Load package' }).click();
  await expect(page.getByRole('alert')).toContainText('not eligible');
  await expect(page.getByRole('button', { name: 'Verify load and depart' })).toBeDisabled();
  await scanDriver(page, 'TEST-LABEL-005', 5, 'Load package');
  await page.getByRole('button', { name: 'Verify load and depart' }).click();
  await expect(page.locator('.notice[role="status"]')).toContainText('Run departed');
  await page.getByRole('button', { name: 'Report arrival at stop 1' }).click();
  await expect(page.locator('.notice[role="status"]')).toContainText('Packages remain in your custody');
  await expect(page.locator('.stop-group').first()).toContainText(/arrived/i);
  await page.reload();
  await page.locator('.run-list li').filter({ hasText: 'OUTBOUND' }).click();
  await expect(page.locator('.stop-group').first()).toContainText(/arrived/i);
});

test('admin can reach driver approvals', async ({ page }) => {
  await page.goto('/');
  await signIn(page, 'ADMIN');
  await expect(page.getByText('ADMIN OPERATIONS')).toBeVisible();
  await page.getByRole('button', { name: 'Driver approvals' }).click();
  await expect(page.getByRole('heading', { name: 'Pending drivers' })).toBeVisible();
  await expect(page.getByText('No pending applications.')).toBeVisible();
  await page.getByRole('button', { name: 'Pickup routes' }).click();
  await expect(page.getByRole('heading', { name: 'Origin pickup routes' })).toBeVisible();
  await page.getByLabel('local:AUS-001 latitude').fill('30.2672');
  await page.getByLabel('local:AUS-001 longitude').fill('-97.7431');
  await page.getByRole('button', { name: 'Save route' }).first().click();
  await expect(page.locator('.notice[role="status"]')).toContainText('Pickup route saved');
});

test('customer can view shipment history and open the draft form', async ({ page }) => {
  await page.goto(process.env.ZPX_E2E_CUSTOMER_URL);
  await signIn(page, 'CUSTOMER');
  await expect(page.getByText('ZPX / CUSTOMER PORTAL')).toBeVisible();
  await page.getByRole('navigation', { name: 'Customer navigation' }).getByRole('button', { name: 'Shipments & history' }).click();
  await expect(page.getByRole('heading', { name: 'Your shipment history' })).toBeVisible();
  await expect(page.locator('.shipment-list .shipment-item').first()).toBeVisible();
  await page.getByRole('button', { name: /SYNTHETIC-local-P01/ }).click();
  await expect(page.locator('.shipment-detail .timeline')).toContainText('Driver reported stop arrival');
  await page.getByRole('button', { name: /SYNTHETIC-local-DEPOSIT-DEMO/ }).click();
  await expect(page.locator('.shipment-detail')).toContainText('At destination');
  await expect(page.locator('.shipment-detail .timeline')).toContainText('Demo final deposit assumed');
  await page.getByRole('button', { name: /SYNTHETIC-local-PICKUP-DEMO/ }).click();
  await expect(page.locator('.shipment-detail')).toContainText('Collected');
  await expect(page.locator('.shipment-detail .timeline')).toContainText('Demo recipient pickup assumed');
  await page.getByRole('button', { name: '＋ Create shipment' }).click();
  await expect(page.getByRole('heading', { name: 'Where is your parcel going?' })).toBeVisible();
});

test('recipient can see both assumed locker outcomes', async ({ page }) => {
  await page.goto(process.env.ZPX_E2E_CUSTOMER_URL);
  await signIn(page, 'RECIPIENT');
  await page.getByRole('navigation', { name: 'Customer navigation' }).getByRole('button', { name: 'Shipments & history' }).click();
  await page.getByRole('button', { name: 'Receiving', exact: true }).click();
  await expect(page.getByRole('button', { name: /SYNTHETIC-local-DEPOSIT-DEMO/ })).toBeVisible();
  await expect(page.getByRole('button', { name: /SYNTHETIC-local-PICKUP-DEMO/ })).toBeVisible();
});

test('customer can pay a size difference and finish a virtual origin deposit', async ({ page }) => {
  const site = process.env.ZPX_E2E_CUSTOMER_URL;
  await page.goto(site);
  const loginResponse = page.waitForResponse(response => response.url().endsWith('/auth/login'));
  await signIn(page, 'CUSTOMER');
  const csrf = (await (await loginResponse).json()).csrf_token;
  const base = site + '/api/delivery/v1';
  const locations = (await (await page.request.get(base + '/locations')).json()).items;
  const post = async (path, body, version) => {
    const response = await page.request.post(base + path, { data: body, headers: {
      'X-CSRF-Token': csrf, 'Idempotency-Key': crypto.randomUUID(),
      ...(version === undefined ? {} : { 'If-Match': `"${version}"` }),
    } });
    expect(response.ok(), await response.text()).toBeTruthy();
    return response.json();
  };
  const recipient = account('RECIPIENT');
  const shipment = await post('/shipments', {
    origin_location_id: locations[0].id, destination_location_id: locations[1].id, service_level: 'STANDARD',
    recipient: { name: 'Synthetic Recipient', email: recipient.email, phone: recipient.phone,
      address: { line1: 'Test only', city: 'Austin', region: 'TX', postal_code: '00000', country_code: 'US' } },
    package: { size_class: 'SMALL', width_mm: 100, height_mm: 100, depth_mm: 100, weight_g: 500 },
  });
  const quote = await post('/shipments/' + shipment.shipment_id + '/quotes', { service_level: 'STANDARD' }, 0);
  const payment = await post('/shipments/' + shipment.shipment_id + '/payment-session', { quote_id: quote.quote_id }, 0);
  await post('/development/payments/' + payment.payment_id + '/confirm', { outcome: 'SUCCEEDED' });
  await page.getByRole('navigation', { name: 'Customer navigation' }).getByRole('button', { name: 'Shipments & history' }).click();
  await page.getByRole('button', { name: new RegExp(shipment.public_reference) }).click();
  await page.getByRole('button', { name: 'Generate / reprint test label' }).click();
  const origin = page.getByLabel('Origin deposit development simulation');
  await expect(origin).toBeVisible();
  await origin.getByLabel('Need a larger compartment?').selectOption('MEDIUM');
  await origin.getByLabel('Width (mm)').fill('300');
  await origin.getByLabel('Height (mm)').fill('300');
  await origin.getByLabel('Depth (mm)').fill('350');
  await origin.getByLabel('Weight (g)').fill('1500');
  await origin.getByRole('button', { name: 'Get size-upgrade quote' }).click();
  await expect(origin).toContainText('Upgrade to MEDIUM');
  await origin.getByRole('button', { name: 'Continue to test checkout' }).click();
  await page.reload();
  await page.getByRole('navigation', { name: 'Customer navigation' }).getByRole('button', { name: 'Shipments & history' }).click();
  await page.getByRole('button', { name: new RegExp(shipment.public_reference) }).click();
  await page.getByLabel('Origin deposit development simulation').getByRole('button', { name: 'Simulate payment success' }).click();
  await expect(page.locator('.shipment-facts')).toContainText('MEDIUM');
  await page.getByRole('button', { name: 'Generate / reprint test label' }).click();
  const ready = page.getByLabel('Origin deposit development simulation');
  await expect(ready.getByLabel('Scan or paste the primary test label')).not.toBeEmpty();
  const depositedLabel = await ready.getByLabel('Scan or paste the primary test label').inputValue();
  await ready.getByRole('button', { name: 'Pair with virtual origin locker' }).click();
  await ready.getByRole('button', { name: 'Simulate door open' }).click();
  await ready.getByRole('button', { name: 'Simulate door close' }).click();
  await ready.getByRole('button', { name: 'Confirm virtual placement' }).click();
  await expect(page.locator('.shipment-facts')).toContainText('At origin');
  await expect(page.locator('.shipment-detail')).toContainText('no physical door was operated');
  await page.getByRole('button', { name: 'Sign out' }).click();
  await page.goto('/');
  await signIn(page, 'DRIVER-IN');
  await page.context().grantPermissions(['geolocation']);
  await page.context().setGeolocation({ latitude: 30.2671, longitude: -97.7430 });
  await page.getByRole('button', { name: "I'm available · Find pickups" }).click();
  await expect(page.getByRole('button', { name: 'Accept pickup' })).toBeVisible();
  await page.getByRole('button', { name: 'Accept pickup' }).click();
  const notice = await page.locator('.notice[role="status"]').innerText();
  const runId = notice.match(/inbound run (\d+)/)?.[1];
  expect(runId).toBeTruthy();
  await page.locator('.run-list li').filter({ hasText: `INBOUND #${runId}` }).click();
  await page.getByRole('button', { name: 'Acknowledge run' }).click();
  await page.getByRole('textbox', { name: 'Scan or enter label token' }).fill(depositedLabel);
  await page.getByRole('button', { name: 'Scan package' }).click();
  await expect(page.locator('.notice[role="status"]')).toContainText('1/1 loaded');
});
