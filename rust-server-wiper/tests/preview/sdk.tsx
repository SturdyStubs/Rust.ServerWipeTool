import type { ButtonHTMLAttributes, ReactNode } from "react";
import { blankDraft } from "../../src/client/types";
const draft = blankDraft();
const data = {
  server_now: new Date().toISOString(),
  environment: {
    HOSTNAME: "Rust test server",
    DESCRIPTION: "A disposable test server",
    WORLD_SEED: "42",
    WORLD_SIZE: "3500",
  },
  timezones: ["UTC", "America/Phoenix", "Europe/London"],
  permissions: {
    view: true,
    schedule: true,
    execute: true,
    restore: true,
    configure: true,
  },
  identity: {
    path: "/server/rust",
    source: "startup server.identity",
    message: "Detected from startup.",
  },
  presets: [
    {
      id: 1,
      name: "Saved map wipe",
      server_id: 1,
      payload: { ...draft.payload, files: "/server/rust/*.sav" },
    },
  ],
  calendar: {
    events: [
      {
        wipe_id: 1,
        label: "Monthly full wipe",
        at: "2030-11-07T21:00:00Z",
        timezone: "America/Phoenix",
        conflict: false,
      },
    ],
    notes: [],
    skipped: [],
  },
  wipes: [
    {
      ...draft,
      id: 1,
      label: "Monthly full wipe",
      next_run_at: "2030-11-07T21:00:00Z",
      repeat: ["1 4 14:00"],
      timezone: "America/Phoenix",
      payload: {
        ...draft.payload,
        files: "/server/rust/*.sav",
        blueprints: true,
      },
    },
  ],
  maps: [
    { id: 1, name: "Coastal map", url: "https://example.com/coastal.map" },
  ],
  runs: [
    {
      id: 1,
      label: "Monthly full wipe",
      due_at: "2030-10-03T21:00:00Z",
      server_id: 1,
      timezone: "America/Phoenix",
      status: "completed",
      message: "Wipe completed; start signal sent.",
      result: { deleted: ["/server/rust/world.sav"] },
      backup_uuid: "fixture-backup-uuid",
      phase: "finished",
      created_by: 1,
    },
  ],
};
export const useCurrentServerRequired = () => ({
  attributes: { identifier: "fixture" },
});
export const httpErrorToHuman = (e: Error) => e.message;
let announcements = {
  revision: 0,
  enabled: true,
  webhooks: [
    {
      id: "20000000-0000-4000-8000-000000000001",
      name: "Community announcements",
      url: "",
      configured: true,
      enabled: true,
    },
    {
      id: "20000000-0000-4000-8000-000000000002",
      name: "Staff alerts",
      url: "",
      configured: true,
      enabled: true,
    },
  ],
  presets: [
    {
      id: "20000000-0000-4000-8000-000000000003",
      name: "Wipe reminder",
      content: "{server} wipes {relative}.",
      embeds: [
        {
          title: "Upcoming wipe",
          description:
            "{label} begins in {minutes} minutes.\nJoin us for a fresh start.",
          color: 5793266,
        },
      ],
      role_ids: [],
      mention_everyone: false,
    },
    {
      id: "20000000-0000-4000-8000-000000000004",
      name: "Fresh wipe",
      content: "",
      embeds: [
        {
          title: "A fresh start",
          description:
            "{server} has wiped and is running again.\nRust may still be loading.",
          color: 5763719,
          footer: { text: "See you on the island" },
        },
      ],
      role_ids: [],
      mention_everyone: false,
    },
  ],
  rules: [
    {
      id: "20000000-0000-4000-8000-000000000005",
      name: "30-minute reminder",
      enabled: true,
      event: "before",
      minutes: 30,
      preset_id: "20000000-0000-4000-8000-000000000003",
      webhook_ids: ["20000000-0000-4000-8000-000000000001"],
      wipe_id: null,
    },
    {
      id: "20000000-0000-4000-8000-000000000006",
      name: "Five-minute reminder",
      enabled: true,
      event: "before",
      minutes: 5,
      preset_id: "20000000-0000-4000-8000-000000000003",
      webhook_ids: [
        "20000000-0000-4000-8000-000000000001",
        "20000000-0000-4000-8000-000000000002",
      ],
      wipe_id: null,
    },
    {
      id: "20000000-0000-4000-8000-000000000007",
      name: "Fresh wipe announcement",
      enabled: true,
      event: "wiped",
      minutes: null,
      preset_id: "20000000-0000-4000-8000-000000000004",
      webhook_ids: ["20000000-0000-4000-8000-000000000001"],
      wipe_id: null,
    },
  ],
};
export const http = {
  get: async (url: string) => ({
    data: url.endsWith("/announcements")
      ? {
          configuration: announcements,
          schedules: [{ id: 1, label: "Monthly full wipe" }],
          deliveries: [],
        }
      : url.endsWith("/files")
        ? { files: [{ name: "server", file: false, symlink: false }] }
        : url.endsWith("/settings")
          ? { options: {}, discord_configured: false, rcon_configured: false }
          : url.startsWith("/api/admin")
            ? {
                ...data,
                servers: [
                  {
                    id: 1,
                    name: "Rust test server",
                    identity: { path: "/server/rust" },
                  },
                ],
                page: 1,
                has_more: false,
              }
            : data,
  }),
  post: async (url: string, input: any) => ({
    data: url.endsWith("/announcements/preview")
      ? {
          payload: {
            content: input.preset.content
              ?.replaceAll("{server}", "Rust test server")
              .replaceAll("{relative}", "<t:1910000000:R>"),
            embeds: JSON.parse(
              JSON.stringify(input.preset.embeds)
                .replaceAll("{server}", "Rust test server")
                .replaceAll("{label}", "Monthly wipe")
                .replaceAll("{minutes}", "30"),
            ),
          },
        }
      : url.endsWith("/announcements/probe")
        ? { probe_id: "simulated", asynchronous: false }
        : url.endsWith("/health")
          ? {
              checked_at: new Date().toISOString(),
              probe_id: "preview-probe",
              checks: [
                {
                  id: "wings",
                  label: "Server & Wings connection",
                  status: "pass",
                  message: "Simulated Wings connection; server running.",
                },
                {
                  id: "plan",
                  label: "Files, map & startup values",
                  status: "pass",
                  message: "One entry matches. Preview fixture only.",
                },
                {
                  id: "backup",
                  label: "Backup quota",
                  status: "pass",
                  message: "1 of 5 backup slots used (simulated).",
                },
                {
                  id: "scheduler",
                  label: "Minute scheduler",
                  status: "warning",
                  message: "No live scheduler is connected to this preview.",
                },
                {
                  id: "worker",
                  label: "Queue worker",
                  status: "warning",
                  message: "No live worker is connected to this preview.",
                },
              ],
            }
          : url.endsWith("/configuration/preview")
            ? {
                schedules: input.configuration.schedules.map((s: any) => ({
                  ...s,
                  enabled: false,
                  next_run_at: "2030-11-07T21:00:00Z",
                })),
                presets: input.configuration.presets,
                maps: input.configuration.maps,
                warnings: [
                  "All imported schedules are paused. Preview fixture only.",
                ],
                target_identity: input.target_identity,
              }
            : url.endsWith("/configuration/import")
              ? {
                  schedules: input.configuration.schedules.length,
                  presets: input.configuration.presets.length,
                  maps: input.configuration.maps.length,
                }
              : {
                  files: ["/server/rust/world.sav"],
                  count: 1,
                  patterns: ["/server/rust/*.sav"],
                },
  }),
  patch: async () => ({ data: {} }),
  put: async (url: string, input: any) => {
    if (url.endsWith("/announcements")) {
      announcements = {
        ...input,
        revision: input.revision + 1,
        webhooks: input.webhooks.map((hook: any) => ({
          ...hook,
          url: "",
          configured: true,
        })),
      };
      return { data: { configuration: announcements } };
    }
    return { data: {} };
  },
  delete: async () => ({ data: {} }),
  request: async () => ({ data: {} }),
};
export function Button({
  children,
  color,
  isLoading,
  isSecondary,
  size,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
  color?: string;
  isLoading?: boolean;
  isSecondary?: boolean;
  size?: string;
}) {
  return (
    <button
      type="button"
      className={`fixture-button ${color === "red" ? "fixture-danger" : ""} ${isSecondary ? "fixture-secondary" : ""}`}
      disabled={isLoading || props.disabled}
      {...props}
    >
      {children}
    </button>
  );
}
export const Spinner = () => <p>Loading…</p>;
export const ServerContentBlock = ({ children }: { children: ReactNode }) => (
  <main>{children}</main>
);
export const PageContentBlock = ServerContentBlock;
