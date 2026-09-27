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
