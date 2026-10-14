import type { ButtonHTMLAttributes, ReactNode } from "react";
import { blankDraft } from "../../src/client/types";
const draft = blankDraft();
const data = {
  environment: {
    HOSTNAME: "Rust test server",
    DESCRIPTION: "A disposable test server",
    WORLD_SEED: "42",
    WORLD_SIZE: "3500",
  },
  timezones: ["UTC", "America/Phoenix", "Europe/London"],
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
      timezone: "America/Phoenix",
      status: "completed",
      message: "Wipe completed; start signal sent.",
      result: { deleted: ["/server/rust/world.sav"] },
    },
  ],
};
export const useCurrentServerRequired = () => ({
  attributes: { identifier: "fixture" },
});
export const httpErrorToHuman = (e: Error) => e.message;
export const http = {
  get: async (url: string) => ({
    data: url.endsWith("/files")
      ? { files: [{ name: "server", file: false, symlink: false }] }
      : data,
  }),
  post: async () => ({
    data: {
      files: ["/server/rust/world.sav"],
      count: 1,
      patterns: ["/server/rust/*.sav"],
    },
  }),
  patch: async () => ({ data: {} }),
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
