import { test, expect } from '@playwright/test';
import { ADMIN, GA4_ID, configureService, importYaml, openAdmin, seedCatalog } from './helpers';

/**
 * REQ-DEMO-013 — happy path of the Marketing Kit admin (UiKit list / form scaffolds) on a freshly reset demo:
 * empty state, seed the catalog, import the YAML profile, edit a provider ID (validation included), toggle and
 * delete (UiKit confirm dialog), then check the public page renders the tags configured in the database.
 */
test.describe.configure({ mode: 'serial' });

test.describe('Marketing Kit admin', () => {
  test('empty list explains how to start', async ({ page }) => {
    await openAdmin(page);
    await expect(page.getByText('No marketing services')).toBeVisible();
    await expect(page.getByTestId('marketing-kit-new')).toBeVisible();
  });

  test('seed the catalog and import the YAML profile', async ({ page }) => {
    await seedCatalog(page);
    for (const name of ['Google Tag Manager', 'Google Analytics 4', 'Meta Pixel', 'Microsoft Clarity']) {
      await expect(page.getByRole('cell', { name: new RegExp(`^${name}`) }).first()).toBeVisible();
    }
    await expect(page.getByTestId('marketing-kit-state-ga4')).toHaveText('Off');

    await importYaml(page);
    await expect(page.getByTestId('marketing-kit-state-gtm')).toHaveText('On');
    await expect(page.getByTestId('marketing-kit-state-clarity')).toHaveText('On');
  });

  test('an invalid provider ID is rejected', async ({ page }) => {
    await openAdmin(page);
    await page.getByTestId('marketing-kit-edit-ga4').click();
    await page.locator('#marketing_tool_option_measurement_id').fill("G-1');alert(1);//");
    const response = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/edit'));
    await page.getByRole('button', { name: 'Save' }).click();
    expect((await response).status()).toBe(422);
    await expect(page.getByText('This ID does not match the provider format.')).toBeVisible();
  });

  test('configure GA4 and render it on the public page', async ({ page }) => {
    await configureService(page, 'ga4', 'measurement_id', GA4_ID);

    const home = await page.request.get('/');
    expect(home.status()).toBe(200);
    expect(await home.text()).toContain(GA4_ID);
  });

  test('toggle a service off and on', async ({ page }) => {
    await openAdmin(page);
    await page.getByTestId('marketing-kit-toggle-clarity').click();
    await expect(page.getByText('Service "clarity" is now disabled.')).toBeVisible();
    await expect(page.getByTestId('marketing-kit-state-clarity')).toHaveText('Off');

    await page.getByTestId('marketing-kit-toggle-clarity').click();
    await expect(page.getByTestId('marketing-kit-state-clarity')).toHaveText('On');
  });

  test('delete a service through the confirm dialog', async ({ page }) => {
    await openAdmin(page);
    await page.getByTestId('marketing-kit-delete-tiktok').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('Delete “TikTok Pixel”?');
    await dialog.getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByText('Service deleted.')).toBeVisible();
    await expect(page.getByTestId('marketing-kit-state-tiktok')).toHaveCount(0);
    expect(page.url()).toContain(ADMIN);
  });
});
