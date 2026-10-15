import { chromium } from "playwright";
import { existsSync, mkdirSync } from "node:fs";
const edge = "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe";
const browser = await chromium.launch({
  headless: true,
  ...(existsSync(edge) ? { executablePath: edge } : {}),
});
mkdirSync("tests/screenshots", { recursive: true });
const errors = [];
const payload = {
  name: null,
  description: null,
  seed: null,
  size: null,
  level: null,
  random_seed: false,
  random_level: false,
  files: "/server/rust/*.sav",
  blueprints: false,
  identity_path: "/server/rust",
  commands: [],
  backup: true,
  readiness: "wings",
};
const configuration = {
  format: "rust-server-wiper.config",
  version: 1,
  schedules: [
    {
      label: "Weekly imported wipe",
      timezone: "America/Phoenix",
      repeat: ["weekly 4 14:00"],
      time: "",
      payload,
    },
  ],
  presets: [{ name: "Imported map wipe", payload }],
  maps: [
    {
      name: "Coastal map",
      url: "https://example.com/coastal.map",
      weight: 1,
      position: 0,
    },
  ],
};
for (const [name, width] of [
  ["desktop", 1440],
  ["mobile", 390],
]) {
  const page = await browser.newPage({
    viewport: { width, height: 1000 },
    timezoneId: "America/Phoenix",
  });
  page.on("pageerror", (e) => errors.push(e.message));
  async function capture(surface) {
    await page.screenshot({
      path: `tests/screenshots/${name}-${surface}.png`,
      fullPage: surface !== "timestamp-menu",
    });
    if (
      await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth,
      )
    )
      errors.push(`${name} ${surface} overflows horizontally`);
  }
  await page.goto("http://127.0.0.1:4174");
  await page.getByText("Monthly full wipe", { exact: true }).first().waitFor();
  await capture("overview");
  await page.addInitScript(() => {
    Object.defineProperty(navigator, "clipboard", {
      configurable: true,
      value: {
        writeText: async (value) => {
          window.__copiedTimestamp = value;
        },
      },
    });
  });
  await page.evaluate(() => {
    Object.defineProperty(navigator, "clipboard", {
      configurable: true,
      value: {
        writeText: async (value) => {
          window.__copiedTimestamp = value;
        },
      },
    });
  });
  await page
    .getByRole("button", {
      name: "Copy Discord timestamp for Monthly full wipe",
    })
    .first()
    .click();
  await capture("timestamp-menu");
  await page.getByRole("menuitem", { name: /^Relative time/ }).click();
  if (!(await page.evaluate(() => window.__copiedTimestamp)).endsWith(":R>"))
    errors.push("Timestamp clipboard copy did not work");
  await page.getByRole("button", { name: "Create wipe", exact: true }).click();
  await page.getByLabel("Schedule name", { exact: true }).fill("Test wipe");
  await page
    .getByLabel("Files and patterns", { exact: false })
    .fill("/server/rust/*.sav");
  await capture("editor");
  await page
    .getByRole("button", { name: "3 Timing & safeguards", exact: true })
    .click();
  if (
    (await page
      .getByRole("combobox", { name: "Timezone", exact: true })
      .inputValue()) !== "America/Phoenix"
  )
    errors.push("Browser timezone did not become the default");
  await page
    .getByRole("button", { name: "Set to five minutes from now" })
    .click();
  await page.getByLabel("Repeat frequency").selectOption("weekly");
  await page.getByRole("button", { name: "Add repeat", exact: true }).click();
  await capture("timing");
  await page
    .getByRole("button", { name: "Continue to review", exact: true })
    .click();
  await page
    .getByRole("button", { name: "Run health check", exact: true })
    .click();
  await page
    .getByText("No live scheduler is connected to this preview.", {
      exact: true,
    })
    .waitFor();
  await page
    .getByRole("button", { name: "Preview files", exact: true })
    .click();
  await page.getByRole("button", { name: "Wipe now", exact: true }).waitFor();
  await capture("health-review");
  await page
    .getByRole("button", { name: "Import / export", exact: true })
    .click();
  await page
    .getByLabel("Configuration JSON file", { exact: true })
    .setInputFiles({
      name: "sample.json",
      mimeType: "application/json",
      buffer: Buffer.from(JSON.stringify(configuration)),
    });
  await page
    .getByRole("button", { name: "Preview import", exact: true })
    .click();
  await page
    .getByRole("heading", { name: "Review the import", exact: true })
    .waitFor();
  await page
    .locator('section[aria-labelledby="rsw-transfer-title"] details summary')
    .first()
    .click();
  await capture("configuration");
  await page.getByRole("button", { name: "Settings", exact: true }).click();
  await page
    .getByRole("button", { name: "Save settings", exact: true })
    .waitFor();
  await capture("settings");
  await page
    .locator(".rsw-announcement-item > summary")
    .filter({ hasText: "30-minute reminder" })
    .click();
  await capture("announcement-rule");
  await page
    .getByRole("button", { name: "Message presets (2)", exact: true })
    .click();
  await page
    .locator(".rsw-announcement-item > summary")
    .filter({ hasText: "Fresh wipe" })
    .first()
    .click();
  await page
    .getByRole("button", { name: "Preview message", exact: true })
    .click();
  await page
    .getByRole("heading", { name: /Preview.*nothing is sent/ })
    .waitFor();
  await capture("announcement-preset");
  await page.getByRole("button", { name: "Webhooks (2)", exact: true }).click();
  await capture("announcement-webhooks");
  await page.goto("http://127.0.0.1:4174/admin");
  await page.getByText("Rust test server · #1").waitFor();
  await capture("admin");
  await page.close();
}
await browser.close();
if (errors.length) throw new Error(errors.join("\n"));
console.log(
  "Desktop/mobile workflow, local timezone default, health check, import review, settings and admin rendered without page errors or page-level horizontal overflow.",
);
