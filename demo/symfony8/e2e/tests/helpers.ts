import { expect, type Page } from '@playwright/test';

/** Admin list of the default profile (nowo_marketing_kit.web_ui.path_prefix). */
export const ADMIN = '/admin/marketing';

/** Placeholder provider IDs (demo only; they only have to match the provider ID format). */
export const GA4_ID = 'G-DEMO2026';
export const PIXEL_ID = '1234567890';

/**
 * Opens the admin list and waits for the page header. `make test-e2e` / `make demo-screenshots` reset the demo
 * database first (`make reset-demo`), so every run starts from an empty marketing_tool table.
 */
export async function openAdmin(page: Page): Promise<void> {
  const response = await page.goto(ADMIN);
  expect(response?.status()).toBe(200);
  await expect(page.getByRole('heading', { name: 'Marketing services', level: 1 })).toBeVisible();
}

/** Seeds the built-in catalog (POST + CSRF header action) unless the list already has services. */
export async function seedCatalog(page: Page): Promise<void> {
  await openAdmin(page);
  if (await page.getByTestId('marketing-kit-state-gtm').count() > 0) {
    return;
  }
  await page.getByTestId('marketing-kit-seed').click();
  await expect(page.getByText(/Seeded \d+ marketing service\(s\) for profile "default"\./)).toBeVisible();
}

/** Imports the demo YAML profile (GTM, GTM noscript and Clarity with their IDs, enabled). */
export async function importYaml(page: Page): Promise<void> {
  await page.getByTestId('marketing-kit-import').click();
  await expect(page.getByText(/Imported\/updated 3 service\(s\) from YAML profile "default"\./)).toBeVisible();
}

/** Edits one service: sets its provider ID option, enables it and saves. */
export async function configureService(page: Page, code: string, optionField: string, value: string): Promise<void> {
  await openAdmin(page);
  await page.getByTestId(`marketing-kit-edit-${code}`).click();
  await expect(page.getByRole('heading', { name: 'Edit service', level: 1 })).toBeVisible();
  await page.locator(`#marketing_tool_option_${optionField}`).fill(value);
  await page.locator('#marketing_tool_enabled').check();
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Service updated.')).toBeVisible();
  await expect(page.getByTestId(`marketing-kit-state-${code}`)).toHaveText('On');
}

/** Realistic demo state: catalog seeded, YAML imported, GA4 and Meta Pixel configured. */
export async function prepareDemoState(page: Page): Promise<void> {
  await seedCatalog(page);
  await importYaml(page);
  await configureService(page, 'ga4', 'measurement_id', GA4_ID);
  await configureService(page, 'meta_pixel', 'pixel_id', PIXEL_ID);
}
