import { chromium } from "playwright";
import { existsSync, mkdirSync } from "node:fs";
const edge = "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe";
const browser = await chromium.launch({
  headless: true,
  ...(existsSync(edge) ? { executablePath: edge } : {}),
});
mkdirSync("tests/screenshots", { recursive: true });
const errors = [];
for (const [name, width] of [
  ["desktop", 1440],
  ["mobile", 390],
]) {
  const page = await browser.newPage({ viewport: { width, height: 1000 } });
  page.on("pageerror", (e) => errors.push(e.message));
  await page.goto("http://127.0.0.1:4174");
  await page.getByText("Monthly full wipe", { exact: true }).first().waitFor();
  await page.screenshot({
    path: `tests/screenshots/${name}-overview.png`,
    fullPage: true,
  });
  await page.getByRole("button", { name: "Create wipe", exact: true }).click();
  await page.getByLabel("Schedule name", { exact: true }).fill("Test wipe");
  await page
    .getByLabel("Files and patterns", { exact: false })
    .fill("/server/rust/*.sav");
  await page.screenshot({
    path: `tests/screenshots/${name}-editor.png`,
    fullPage: true,
  });
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth > window.innerWidth,
  );
  if (overflow) errors.push(`${name} page overflows horizontally`);
  await page.goto("http://127.0.0.1:4174/admin");
  await page.getByText("Rust wipe management", { exact: true }).waitFor();
  await page.getByText("Rust test server · #1").waitFor();
  await page.screenshot({
    path: `tests/screenshots/${name}-admin.png`,
    fullPage: true,
  });
  if (
    await page.evaluate(
      () => document.documentElement.scrollWidth > window.innerWidth,
    )
  )
    errors.push(`${name} admin page overflows horizontally`);
  await page.close();
}
await browser.close();
if (errors.length) throw new Error(errors.join("\n"));
console.log(
  "Desktop and mobile render without page errors or page-level horizontal overflow.",
);
