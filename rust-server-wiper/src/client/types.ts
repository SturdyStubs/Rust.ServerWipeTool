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
}
export interface Run {
  id: number;
  label: string;
  timezone: string;
  due_at: string;
  status: string;
  message: string | null;
  result: { deleted?: string[]; environment?: Record<string, string> } | null;
}
export interface Overview {
  environment: Record<string, string>;
  timezones: string[];
  wipes: Wipe[];
  maps: WipeMap[];
  runs: Run[];
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
    timezone: "UTC",
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
