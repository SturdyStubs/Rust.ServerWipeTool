import { browserTimezone } from "./TimezoneField";
export interface Payload {
  name: string | null;
  description: string | null;
  size: number | null;
  seed: number | null;
  random_seed: boolean;
  random_level: boolean;
  level: string | null;
  files: string;
  blueprints: boolean;
  identity_path: string;
  commands: { minutes: number; command: string }[];
  backup?: boolean;
  backup_timeout?: number;
  readiness?: "none" | "wings" | "rcon";
  map_mode?: "random" | "ordered" | "weighted";
  exclude_recent?: number;
  wait_players?: boolean;
  max_deferral?: number;
  deadline_action?: "cancel" | "proceed";
  unknown_players?: "cancel" | "defer" | "proceed";
}
export interface Draft {
  label: string;
  timezone: string;
  enabled: boolean;
  time: string;
  repeat: string[];
  payload: Payload;
}
export interface Wipe extends Omit<Draft, "time"> {
  id: number;
  next_run_at: string | null;
}
export interface WipeMap {
  id: number;
  name: string;
  url: string;
  weight?: number;
  position?: number;
}
export interface Run {
  id: number;
  label: string;
  timezone: string;
  due_at: string;
  status: string;
  message: string | null;
  result: {
    deleted?: string[];
    environment?: Record<string, string>;
    readiness?: string;
    map_url?: string | null;
    rotation_fallback?: boolean;
  } | null;
  backup_uuid?: string | null;
  phase?: string;
  created_by?: number | null;
}
export interface Overview {
  server_now?: string;
  environment: Record<string, string>;
  timezones: string[];
  wipes: Wipe[];
  maps: WipeMap[];
  runs: Run[];
  permissions?: Record<string, boolean>;
  identity?: {
    path: string | null;
    source: string | null;
    message: string;
    patterns?: string[];
  };
  presets?: { id: number; name: string; payload: Payload }[];
  calendar?: {
    events: {
      wipe_id: number;
      label: string;
      at: string;
      timezone: string;
      conflict: boolean;
    }[];
    notes: { wipe_id: number; message: string }[];
    skipped?: {
      wipe_id: number;
      label: string;
      local: string;
      rule: string;
      reason: string;
      timezone: string;
    }[];
  };
  maintenance?: { operation: string; expires_at: string } | null;
  restores?: { id: number; status: string; message: string }[];
}
export interface Preview {
  files: string[];
  count: number;
  patterns: string[];
}
export interface FileEntry {
  name: string;
  file: boolean;
  symlink: boolean;
}

export function blankDraft(): Draft {
  return {
    label: "",
    timezone: browserTimezone(),
    enabled: true,
    time: "",
    repeat: [],
    payload: {
      name: null,
      description: null,
      size: null,
      seed: null,
      random_seed: false,
      random_level: false,
      level: null,
      files: "",
      blueprints: false,
      identity_path: "/server/rust",
      commands: [],
      backup: true,
      backup_timeout: 300,
      readiness: "wings",
      map_mode: "random",
      exclude_recent: 0,
      wait_players: false,
      max_deferral: 60,
      deadline_action: "cancel",
      unknown_players: "defer",
    },
  };
}
export function localInput(iso: string, timezone: string): string {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: timezone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(new Date(iso));
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? "";
  return `${get("year")}-${get("month")}-${get("day")}T${get("hour")}:${get("minute")}`;
}
export function addFile(files: string, path: string): string {
  return [
    ...new Set([
      ...files
        .split(/\r?\n/)
        .map((p) => p.trim())
        .filter(Boolean),
      path,
    ]),
  ].join("\n");
}

export function describeRule(rule: string): string {
  const [kind, day, time] = rule.split(" ");
  const days = [
    "Monday",
    "Tuesday",
    "Wednesday",
    "Thursday",
    "Friday",
    "Saturday",
    "Sunday",
  ];
  if (kind === "weekly") return `Every ${days[Number(day) - 1]} at ${time}`;
  if (kind === "fortnightly") return `Every 14 days from ${day} at ${time}`;
  return `${["First", "Second", "Third", "Fourth", "Fifth"][Number(kind) - 1]} ${days[Number(day) - 1]} at ${time}`;
}
