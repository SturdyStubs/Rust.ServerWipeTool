import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import WipesScreen from "../src/client/WipesScreen";
import SettingsPanel from "../src/client/SettingsPanel";
import { addFile, localInput } from "../src/client/types";

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
