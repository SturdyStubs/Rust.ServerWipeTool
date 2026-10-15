export type Embed = {
  title?: string;
  description?: string;
  url?: string;
  color?: number;
  timestamp?: string;
  footer?: { text: string; icon_url?: string };
  author?: { name: string; url?: string; icon_url?: string };
  image?: { url: string };
  thumbnail?: { url: string };
  fields?: { name: string; value: string; inline?: boolean }[];
};
export type MessagePreset = {
  id: string;
  name: string;
  content: string | null;
  embeds: Embed[];
  role_ids: string[];
  mention_everyone: boolean;
};
export type WebhookDestination = {
  id: string;
  name: string;
  url: string;
  enabled: boolean;
  configured?: boolean;
};
export type AnnouncementEvent = "before" | "wiping" | "wiped" | "failed";
export type AnnouncementRule = {
  id: string;
  name: string;
  enabled: boolean;
  event: AnnouncementEvent;
  minutes: number | null;
  preset_id: string;
  webhook_ids: string[];
  wipe_id: number | null;
};
export type AnnouncementConfiguration = {
  revision: number;
  enabled: boolean;
  webhooks: WebhookDestination[];
  presets: MessagePreset[];
  rules: AnnouncementRule[];
};
export const emptyAnnouncements: AnnouncementConfiguration = {
  revision: 0,
  enabled: true,
  webhooks: [],
  presets: [],
  rules: [],
};
export const announcementEvents: Record<AnnouncementEvent, string> = {
  before: "Before the wipe",
  wiping: "Wiping · server going down",
  wiped: "Wiped · server running",
  failed: "Wipe or startup failed",
};
export const messageStarters = {
  reminder: {
    name: "Wipe reminder",
    content: "{server} wipes {relative} ({timestamp}).",
    title: "Upcoming wipe",
    description: "{label} starts in {minutes} minutes.",
    color: 5793266,
  },
  wiping: {
    name: "Server going down",
    content: "{server} is going down for its wipe.",
    title: "Wipe starting",
    description:
      "We are stopping the server for {label}. We will announce when it is running again.",
    color: 16705372,
  },
  wiped: {
    name: "Fresh wipe",
    content: "{server} has wiped and is running again!",
    title: "Fresh start",
    description:
      "{label} has finished wiping. The server is running; Rust may still be loading.",
    color: 5763719,
  },
  failed: {
    name: "Wipe failed",
    content: "{server}: {label} needs attention.",
    title: "Wipe or startup failed",
    description: "{message}",
    color: 15548997,
  },
  custom: {
    name: "Custom message",
    content: "",
    title: "",
    description: "",
    color: 5793266,
  },
};
