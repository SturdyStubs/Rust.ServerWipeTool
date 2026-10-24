import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import WipesScreen from "../src/client/WipesScreen";
import AnnouncementsPanel from "../src/client/AnnouncementsPanel";
import DiscordTimestamp, {
  discordTimestampStyles,
} from "../src/client/DiscordTimestamp";
import { emptyAnnouncements } from "../src/client/announcementTypes";
import SettingsPanel from "../src/client/SettingsPanel";
import HealthPanel from "../src/client/HealthPanel";
import ConfigurationPanel from "../src/client/ConfigurationPanel";
import { addFile, localInput, blankDraft } from "../src/client/types";

const mock = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  patch: vi.fn(),
  delete: vi.fn(),
  request: vi.fn(),
}));
vi.mock("@pterodactyl/sdk", () => ({
  Button: ({
    children,
    isLoading,
    isSecondary,
    size,
    color,
    ...props
  }: any) => (
    <button type="button" disabled={isLoading || props.disabled} {...props}>
      {children}
    </button>
  ),
  ServerContentBlock: ({ children }: any) => <main>{children}</main>,
  Spinner: () => <p>Loading</p>,
  useCurrentServerRequired: () => ({ attributes: { identifier: "test" } }),
  http: mock,
  httpErrorToHuman: (e: any) => e.message ?? "Request failed",
}));
const overview = {
  environment: {},
  timezones: ["UTC", "America/Phoenix"],
  wipes: [],
  maps: [],
  runs: [],
};
beforeEach(() => {
  vi.clearAllMocks();
  Element.prototype.scrollIntoView = vi.fn();
  mock.get.mockResolvedValue({ data: overview });
  mock.post.mockResolvedValue({
    data: {
      files: ["/server/rust/world.sav"],
      count: 1,
      patterns: ["/server/rust/*.sav"],
    },
  });
  mock.request.mockResolvedValue({ data: {} });
  mock.put.mockResolvedValue({ data: {} });
});
afterEach(cleanup);
function mount() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <WipesScreen />
    </QueryClientProvider>,
  );
  return client;
}
function step(name: string) {
  fireEvent.click(
    screen.getByRole("button", { name: new RegExp(`^[1-4] ${name}$`) }),
  );
}
async function edit() {
  mount();
  fireEvent.click(screen.getByRole("button", { name: "Create wipe" }));
  fireEvent.change(screen.getByLabelText("Schedule name"), {
    target: { value: "Monthly" },
  });
  fireEvent.change(screen.getByLabelText(/Files and patterns/), {
    target: { value: "/server/rust/*.sav" },
  });
  await screen.findByLabelText("Apply preset");
}
describe("wipe editor", () => {
  it("requires reviewed preview before destructive action", async () => {
    await edit();
    expect(screen.queryByRole("button", { name: "Wipe now" })).toBeNull();
    step("Review");
    fireEvent.click(screen.getByRole("button", { name: "Preview files" }));
    const run = await screen.findByRole("button", { name: "Wipe now" });
    expect((run as HTMLButtonElement).disabled).toBe(true);
    fireEvent.click(screen.getByLabelText(/I reviewed the files/));
    expect((run as HTMLButtonElement).disabled).toBe(false);
    fireEvent.click(run);
    await waitFor(() =>
      expect(mock.post).toHaveBeenCalledWith(
        expect.stringContaining("/run"),
        expect.objectContaining({ confirm: true, label: "Monthly" }),
      ),
    );
  });
  it("invalidates preview and acknowledgement after any draft edit", async () => {
    await edit();
    step("Review");
    fireEvent.click(screen.getByRole("button", { name: "Preview files" }));
    await screen.findByRole("button", { name: "Wipe now" });
    fireEvent.click(screen.getByLabelText(/I reviewed the files/));
    fireEvent.change(screen.getByLabelText(/Files and patterns/), {
      target: { value: "/server/rust/*.map" },
    });
    expect(screen.queryByRole("button", { name: "Wipe now" })).toBeNull();
  });
  it("clears incompatible procedural values when map rotation enabled", async () => {
    await edit();
    step("Next map");
    fireEvent.change(screen.getByLabelText("Map seed"), {
      target: { value: "42" },
    });
    fireEvent.click(screen.getByLabelText("Rotate a saved custom map"));
    expect((screen.getByLabelText("Map seed") as HTMLInputElement).value).toBe(
      "",
    );
    expect(
      (screen.getByLabelText("Map seed") as HTMLInputElement).disabled,
    ).toBe(true);
    step("Review");
    fireEvent.click(screen.getByRole("button", { name: "Preview files" }));
    await waitFor(() =>
      expect(mock.post).toHaveBeenCalledWith(
        expect.stringContaining("/preview"),
        expect.objectContaining({
          payload: expect.objectContaining({
            seed: null,
            size: null,
            random_level: true,
          }),
        }),
      ),
    );
  });
  it("monthly repeat clears the one-time date", async () => {
    await edit();
    step("Timing & safeguards");
    fireEvent.change(screen.getByLabelText("One-time date and time"), {
      target: { value: "2030-01-01T12:00" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Add repeat" }));
    expect(
      (screen.getByLabelText("One-time date and time") as HTMLInputElement)
        .value,
    ).toBe("");
    expect(
      (screen.getByLabelText("One-time date and time") as HTMLInputElement)
        .disabled,
    ).toBe(true);
    expect(screen.getByText("First Thursday at 14:00")).toBeTruthy();
  });
  it("request failures remain visible and editor remains available", async () => {
    await edit();
    mock.post.mockRejectedValueOnce(new Error("Wings is unavailable"));
    step("Review");
    fireEvent.click(screen.getByRole("button", { name: "Preview files" }));
    expect(await screen.findByRole("alert")).toBeTruthy();
    expect(screen.getByRole("alert").textContent).toContain(
      "Wings is unavailable",
    );
    expect(screen.getByLabelText("Schedule name")).toBeTruthy();
  });
});
it("editing preserves the selected timezone instead of browser timezone", () => {
  expect(localInput("2030-01-01T21:00:00Z", "America/Phoenix")).toBe(
    "2030-01-01T14:00",
  );
});
it("file browser selection is deduplicated", () => {
  expect(addFile("/server/rust/a.sav", "/server/rust/a.sav")).toBe(
    "/server/rust/a.sav",
  );
});
it("full preset exposes map files and blueprint deletion for review", async () => {
  await edit();
  fireEvent.change(screen.getByLabelText("Apply preset"), {
    target: { value: "full" },
  });
  expect(
    (screen.getByLabelText(/Files and patterns/) as HTMLTextAreaElement).value,
  ).toContain("/server/rust/*.map");
  expect(
    (screen.getByLabelText("Wipe blueprints") as HTMLInputElement).checked,
  ).toBe(true);
});
it("player-aware controls default to cancellation at the deadline", async () => {
  await edit();
  step("Timing & safeguards");
  fireEvent.click(screen.getByText("Wait for players to leave"));
  fireEvent.click(screen.getByLabelText("Wait until the server is empty"));
  expect(
    (screen.getByLabelText("At the deadline") as HTMLSelectElement).value,
  ).toBe("cancel");
  expect(
    (
      screen.getByLabelText(
        "If player count is unavailable",
      ) as HTMLSelectElement
    ).value,
  ).toBe("defer");
});
it("read-only users see reports without execution or configuration controls", async () => {
  mock.get.mockResolvedValue({
    data: {
      ...overview,
      permissions: {
        view: true,
        schedule: false,
        execute: false,
        restore: false,
        configure: false,
      },
    },
  });
  mount();
  await screen.findByText(
    "No wipes scheduled. Create a wipe to choose files and a time.",
  );
  expect(
    (screen.getByRole("button", { name: "Create wipe" }) as HTMLButtonElement)
      .disabled,
  ).toBe(true);
  expect(
    screen.queryByText("Discord notifications and WebRCON settings"),
  ).toBeNull();
});
it("saved secrets stay blank and blank inputs preserve them on save", async () => {
  mock.get.mockResolvedValue({
    data: { options: {}, discord_configured: true, rcon_configured: true },
  });
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <SettingsPanel base="/fixture" />
    </QueryClientProvider>,
  );
  const password = await screen.findByLabelText("RCON password override");
  expect((password as HTMLInputElement).value).toBe("");
  fireEvent.click(screen.getByRole("button", { name: "Save settings" }));
  await waitFor(() =>
    expect(mock.put).toHaveBeenCalledWith(
      "/fixture/settings",
      expect.objectContaining({ discord_webhook: null, rcon_password: null }),
    ),
  );
});

it("separates tasks and preserves an unfinished wipe across navigation", async () => {
  await edit();
  expect(screen.queryByRole("heading", { name: "Run history" })).toBeNull();
  fireEvent.click(screen.getByRole("button", { name: "Map library" }));
  expect(
    screen.getByRole("heading", { name: "Custom map rotation" }),
  ).toBeTruthy();
  expect(
    screen.queryByRole("button", { name: "Continue to next map" }),
  ).toBeNull();
  fireEvent.click(screen.getByRole("button", { name: "Schedules" }));
  expect(
    (screen.getByLabelText("Schedule name") as HTMLInputElement).value,
  ).toBe("Monthly");
  fireEvent.click(screen.getByRole("button", { name: "Continue to next map" }));
  expect(screen.getByRole("heading", { name: "Next map" })).toBeTruthy();
});
it("requires timing before saving and returns to invalid fields before preview", async () => {
  await edit();
  step("Review");
  expect(
    (screen.getByRole("button", { name: "Save schedule" }) as HTMLButtonElement)
      .disabled,
  ).toBe(true);
  fireEvent.change(screen.getByLabelText("Schedule name"), {
    target: { value: "" },
  });
  fireEvent.click(screen.getByRole("button", { name: "Preview files" }));
  expect(screen.getByRole("heading", { name: "Wipe scope" })).toBeTruthy();
  expect(mock.post).not.toHaveBeenCalled();
});

it("defaults new wipes to the browser timezone", () => {
  expect(blankDraft().timezone).toBe(
    Intl.DateTimeFormat().resolvedOptions().timeZone,
  );
});
it("uses a timezone dropdown and the server clock for near-future scheduling", async () => {
  mock.get.mockResolvedValue({
    data: { ...overview, server_now: "2030-01-01T21:00:00Z" },
  });
  await edit();
  step("Timing & safeguards");
  fireEvent.change(screen.getByRole("combobox", { name: "Timezone" }), {
    target: { value: "America/Phoenix" },
  });
  fireEvent.click(
    screen.getByRole("button", { name: "Set to five minutes from now" }),
  );
  expect(
    (screen.getByLabelText("One-time date and time") as HTMLInputElement).value,
  ).toBe("2030-01-01T14:05");
  expect(screen.getByRole("timer").textContent).toContain("America/Phoenix");
  step("Review");
  fireEvent.click(screen.getByRole("button", { name: "Save schedule" }));
  await waitFor(() =>
    expect(mock.request).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({
          timezone: "America/Phoenix",
          time: "2030-01-01T14:05",
        }),
      }),
    ),
  );
});
it("adds weekly and anchored fourteen-day rules without a one-time date", async () => {
  await edit();
  step("Timing & safeguards");
  fireEvent.change(screen.getByLabelText("Repeat frequency"), {
    target: { value: "weekly" },
  });
  fireEvent.click(screen.getByRole("button", { name: "Add repeat" }));
  expect(screen.getByText("Every Thursday at 14:00")).toBeTruthy();
  fireEvent.change(screen.getByLabelText("Repeat frequency"), {
    target: { value: "fortnightly" },
  });
  expect(
    (screen.getByRole("button", { name: "Add repeat" }) as HTMLButtonElement)
      .disabled,
  ).toBe(true);
  fireEvent.change(screen.getByLabelText("First repeat date"), {
    target: { value: "2030-01-01" },
  });
  fireEvent.click(screen.getByRole("button", { name: "Add repeat" }));
  step("Review");
  fireEvent.click(screen.getByRole("button", { name: "Save schedule" }));
  await waitFor(() =>
    expect(mock.request).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({
          repeat: ["weekly 4 14:00", "fortnightly 2030-01-01 14:00"],
          time: "",
        }),
      }),
    ),
  );
});
it("health check is read-only and becomes stale after editing the plan", async () => {
  await edit();
  step("Review");
  mock.post.mockResolvedValueOnce({
    data: {
      checked_at: new Date().toISOString(),
      probe_id: "probe",
      checks: [
        {
          id: "plan",
          label: "Files and map",
          status: "pass",
          message: "One file matched.",
        },
      ],
    },
  });
  fireEvent.click(screen.getByRole("button", { name: "Run health check" }));
  expect(
    await screen.findByText("All checks passed.", { exact: false }),
  ).toBeTruthy();
  expect(mock.post).toHaveBeenCalledWith(
    expect.stringContaining("/health"),
    expect.objectContaining({ label: "Monthly" }),
  );
  expect(mock.request).not.toHaveBeenCalled();
  step("Wipe scope");
  fireEvent.change(screen.getByLabelText(/Files and patterns/), {
    target: { value: "/server/rust/*.map" },
  });
  step("Review");
  expect(screen.queryByText("One file matched.")).toBeNull();
  expect(screen.getByRole("button", { name: "Run health check" })).toBeTruthy();
});
it("shows health check failures with a retry action", async () => {
  await edit();
  step("Review");
  mock.post.mockRejectedValueOnce(new Error("Health endpoint unavailable"));
  fireEvent.click(screen.getByRole("button", { name: "Run health check" }));
  expect((await screen.findByRole("alert")).textContent).toContain(
    "Health endpoint unavailable",
  );
  expect(
    (
      screen.getByRole("button", {
        name: "Run health check",
      }) as HTMLButtonElement
    ).disabled,
  ).toBe(false);
});
const importPlan = {
  schedules: [],
  presets: [{ name: "Map preset", payload: blankDraft().payload }],
  maps: [],
  warnings: ["Schedules will be paused."],
  target_identity: "/server/rust",
};
async function chooseConfig(
  content = '{"format":"rust-server-wiper.config","version":1}',
) {
  const file = new File([content], "configuration.json", {
    type: "application/json",
  });
  Object.defineProperty(file, "text", { value: async () => content });
  fireEvent.change(screen.getByLabelText("Configuration JSON file"), {
    target: { files: [file] },
  });
  await waitFor(() =>
    expect(screen.getByText("Selected: configuration.json")).toBeTruthy(),
  );
}
it("imports require a preview and acknowledgement and are not repeated after success", async () => {
  const imported = vi.fn();
  render(
    <ConfigurationPanel
      base="/fixture"
      identity="/server/rust"
      onImported={imported}
    />,
  );
  await chooseConfig();
  expect(
    screen.queryByRole("button", { name: "Import configuration" }),
  ).toBeNull();
  mock.post.mockResolvedValueOnce({ data: importPlan });
  fireEvent.click(screen.getByRole("button", { name: "Preview import" }));
  const submit = await screen.findByRole("button", {
    name: "Import configuration",
  });
  expect((submit as HTMLButtonElement).disabled).toBe(true);
  fireEvent.click(screen.getByLabelText(/I reviewed the imported paths/));
  mock.post.mockResolvedValueOnce({
    data: { schedules: 0, presets: 1, maps: 0 },
  });
  fireEvent.click(submit);
  await waitFor(() => expect(imported).toHaveBeenCalledOnce());
  expect((submit as HTMLButtonElement).disabled).toBe(true);
  expect(mock.post).toHaveBeenCalledWith(
    "/fixture/configuration/import",
    expect.objectContaining({
      confirm: true,
      target_identity: "/server/rust",
      request_uuid: expect.any(String),
    }),
  );
});
it("changing the import directory invalidates the reviewed preview", async () => {
  render(
    <ConfigurationPanel
      base="/fixture"
      identity="/server/rust"
      onImported={() => {}}
    />,
  );
  await chooseConfig();
  mock.post.mockResolvedValueOnce({ data: importPlan });
  fireEvent.click(screen.getByRole("button", { name: "Preview import" }));
  await screen.findByRole("button", { name: "Import configuration" });
  fireEvent.click(screen.getByLabelText(/I reviewed the imported paths/));
  fireEvent.change(screen.getByLabelText(/Target Rust identity directory/), {
    target: { value: "/server/other" },
  });
  expect(
    screen.queryByRole("button", { name: "Import configuration" }),
  ).toBeNull();
});
it("invalid JSON is rejected locally without an import request", async () => {
  render(
    <ConfigurationPanel
      base="/fixture"
      identity="/server/rust"
      onImported={() => {}}
    />,
  );
  const file = new File(["bad"], "bad.json");
  Object.defineProperty(file, "text", { value: async () => "bad" });
  fireEvent.change(screen.getByLabelText("Configuration JSON file"), {
    target: { files: [file] },
  });
  expect((await screen.findByRole("alert")).textContent).toContain(
    "not valid JSON",
  );
  expect(mock.post).not.toHaveBeenCalled();
});

it("waits for a worker acknowledgement before marking the worker healthy", async () => {
  vi.useFakeTimers();
  try {
    mock.post.mockResolvedValueOnce({
      data: {
        checked_at: new Date().toISOString(),
        probe_id: "worker-test",
        checks: [
          {
            id: "worker",
            label: "Queue worker",
            status: "pending",
            message: "Waiting for a worker",
          },
        ],
      },
    });
    mock.get.mockResolvedValueOnce({ data: { acknowledged: true } });
    render(
      <HealthPanel base="/fixture" draft={blankDraft()} disabled={false} />,
    );
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Run health check" }));
    });
    expect(screen.getByText("Checking")).toBeTruthy();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(2000);
    });
    expect(
      screen.getByText("The standard queue processed the probe."),
    ).toBeTruthy();
    expect(mock.get).toHaveBeenCalledWith(
      "/fixture/health/probes/worker-test",
      expect.any(Object),
    );
  } finally {
    cleanup();
    vi.useRealTimers();
  }
});
function mountAnnouncements() {
  mock.get.mockResolvedValue({
    data: {
      configuration: structuredClone(emptyAnnouncements),
      schedules: [],
      deliveries: [],
    },
  });
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <AnnouncementsPanel base="/fixture" />
    </QueryClientProvider>,
  );
}

it("builds reusable messages, multiple channels and independent reminder rules", async () => {
  mountAnnouncements();
  fireEvent.click(
    await screen.findByRole("button", { name: "Add webhook", exact: true }),
  );
  fireEvent.change(screen.getByLabelText("Webhook name 1"), {
    target: { value: "Community" },
  });
  fireEvent.change(screen.getByLabelText(/^Webhook URL 1/), {
    target: { value: "https://discord.com/api/webhooks/123/token" },
  });
  fireEvent.click(
    screen.getByRole("button", { name: "Add webhook", exact: true }),
  );
  fireEvent.change(screen.getByLabelText("Webhook name 2"), {
    target: { value: "Staff" },
  });
  fireEvent.change(screen.getByLabelText(/^Webhook URL 2/), {
    target: { value: "https://discord.com/api/webhooks/456/token" },
  });
  fireEvent.click(screen.getByRole("button", { name: "Message presets (0)" }));
  fireEvent.click(screen.getByRole("button", { name: "Add message preset" }));
  fireEvent.change(screen.getByLabelText(/^Message content/), {
    target: { value: "" },
  });
  fireEvent.change(screen.getByLabelText("Description · embed 1"), {
    target: { value: "Wipe starts {relative}." },
  });
  fireEvent.click(
    screen.getByRole("button", { name: "Announcement rules (0)" }),
  );
  fireEvent.click(
    screen.getByRole("button", { name: "Add announcement rule" }),
  );
  fireEvent.click(screen.getByLabelText("Staff"));
  fireEvent.click(
    screen.getByRole("button", { name: "Add announcement rule" }),
  );
  fireEvent.change(
    screen.getAllByRole("spinbutton", { name: /^Minutes before wipe/ }).at(-1)!,
    { target: { value: "5" } },
  );
  mock.put.mockImplementationOnce(async (_url: string, data: any) => ({
    data: {
      configuration: {
        ...data,
        revision: 1,
        webhooks: data.webhooks.map((hook: any) => ({
          ...hook,
          url: "",
          configured: true,
        })),
      },
    },
  }));
  fireEvent.click(screen.getByRole("button", { name: "Save announcements" }));
  await waitFor(() =>
    expect(mock.put).toHaveBeenCalledWith(
      "/fixture/announcements",
      expect.objectContaining({
        webhooks: expect.arrayContaining([
          expect.objectContaining({ name: "Community" }),
          expect.objectContaining({ name: "Staff" }),
        ]),
        presets: [
          expect.objectContaining({
            content: "",
            embeds: [
              expect.objectContaining({
                description: "Wipe starts {relative}.",
              }),
            ],
          }),
        ],
        rules: [
          expect.objectContaining({
            minutes: 30,
            webhook_ids: expect.any(Array),
          }),
          expect.objectContaining({ minutes: 5 }),
        ],
      }),
    ),
  );
  expect(mock.put.mock.calls[0][1].rules[0].webhook_ids.length).toBe(2);
  fireEvent.click(screen.getByRole("button", { name: "Webhooks (2)" }));
  await waitFor(() =>
    expect(
      (screen.getByLabelText(/^Webhook URL 1/) as HTMLInputElement).value,
    ).toBe(""),
  );
});

it("previews an embed without sending a webhook and preserves editable role separators", async () => {
  mountAnnouncements();
  fireEvent.click(
    await screen.findByRole("button", { name: "Message presets (0)" }),
  );
  fireEvent.click(screen.getByRole("button", { name: "Add message preset" }));
  fireEvent.click(screen.getByText("Role mentions & notifications"));
  const roles = screen.getByLabelText(/^Role IDs to notify/);
  fireEvent.change(roles, { target: { value: "123, " } });
  expect((roles as HTMLInputElement).value).toBe("123, ");
  fireEvent.change(roles, { target: { value: "123, 456" } });
  mock.post.mockResolvedValueOnce({
    data: {
      payload: {
        content: "Example preview",
        embeds: [{ title: "Embed preview", description: "Nothing was sent." }],
      },
    },
  });
  fireEvent.click(screen.getByRole("button", { name: "Preview message" }));
  expect(await screen.findByText("Embed preview")).toBeTruthy();
  expect(mock.post).toHaveBeenCalledWith(
    "/fixture/announcements/preview",
    expect.objectContaining({
      preset: expect.objectContaining({ role_ids: ["123", "456"] }),
    }),
  );
  expect(mock.put).not.toHaveBeenCalled();
});

it("copies every documented Discord timestamp style from the epoch dropdown", async () => {
  const writeText = vi.fn().mockResolvedValue(undefined);
  Object.defineProperty(navigator, "clipboard", {
    configurable: true,
    value: { writeText },
  });
  const iso = "2030-01-01T21:00:00Z";
  const epoch = Math.floor(Date.parse(iso) / 1000);
  render(<DiscordTimestamp iso={iso} label="Monthly" />);
  for (const [style, label] of discordTimestampStyles) {
    fireEvent.click(
      screen.getByRole("button", {
        name: "Copy Discord timestamp for Monthly",
      }),
    );
    expect(screen.getAllByRole("menuitem").length).toBe(11);
    fireEvent.click(
      screen.getAllByRole("menuitem")[
        discordTimestampStyles.findIndex(([item]) => item === style) + 2
      ],
    );
    await waitFor(() =>
      expect(writeText).toHaveBeenLastCalledWith(`<t:${epoch}:${style}>`),
    );
    await waitFor(() => expect(screen.queryByRole("menu")).toBeNull());
  }
  fireEvent.click(
    screen.getByRole("button", { name: "Copy Discord timestamp for Monthly" }),
  );
  fireEvent.click(screen.getByRole("menuitem", { name: /^Raw epoch seconds/ }));
  await waitFor(() =>
    expect(writeText).toHaveBeenLastCalledWith(String(epoch)),
  );
});

it("timestamp menu supports keyboard navigation and reports clipboard failures", async () => {
  const writeText = vi.fn().mockRejectedValue(new Error("Clipboard blocked"));
  Object.defineProperty(navigator, "clipboard", {
    configurable: true,
    value: { writeText },
  });
  render(<DiscordTimestamp iso="2030-01-01T21:00:00Z" label="Monthly" />);
  const trigger = screen.getByRole("button", {
    name: "Copy Discord timestamp for Monthly",
  });
  fireEvent.click(trigger);
  const items = screen.getAllByRole("menuitem");
  expect(document.activeElement).toBe(items[0]);
  fireEvent.keyDown(items[0], { key: "End" });
  expect(document.activeElement).toBe(items[items.length - 1]);
  fireEvent.click(items[items.length - 1]);
  expect((await screen.findByRole("alert")).textContent).toContain(
    "Copy manually: <t:",
  );
  fireEvent.keyDown(items[items.length - 1], { key: "Escape" });
  expect(screen.queryByRole("menu")).toBeNull();
  expect(document.activeElement).toBe(trigger);
});

it("renders the saved Phoenix late-night time and epoch without a day or timezone shift", async () => {
  mock.get.mockResolvedValue({
    data: {
      ...overview,
      wipes: [
        {
          ...blankDraft(),
          id: 1,
          label: "Phoenix late night",
          timezone: "America/Phoenix",
          repeat: [],
          enabled: true,
          next_run_at: "2026-10-15T06:56:00.000000Z",
        },
      ],
    },
  });
  mount();
  expect(await screen.findByText("Oct 14, 2026, 11:56 PM")).toBeTruthy();
  expect(screen.getByText("1792047360", { exact: false })).toBeTruthy();
  fireEvent.click(screen.getByRole("button", { name: "Edit", exact: true }));
  step("Timing & safeguards");
  expect(
    (screen.getByLabelText("One-time date and time") as HTMLInputElement).value,
  ).toBe("2026-10-14T23:56");
});
