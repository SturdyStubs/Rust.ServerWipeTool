import { useEffect, useMemo, useState } from "react";

export function browserTimezone() {
  return Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";
}

export default function TimezoneField({
  value,
  onChange,
  timezones = [],
  serverNow,
}: {
  value: string;
  onChange: (zone: string) => void;
  timezones?: string[];
  serverNow?: string;
}) {
  const [tick, setTick] = useState(Date.now());
  const sample = useMemo(
    () => ({
      received: Date.now(),
      server: serverNow ? Date.parse(serverNow) : NaN,
    }),
    [serverNow],
  );
  useEffect(() => {
    const interval = window.setInterval(() => setTick(Date.now()), 1000);
    return () => window.clearInterval(interval);
  }, []);
  const zones = [
    ...new Set([value, browserTimezone(), "UTC", ...timezones]),
  ].sort();
  const current = Number.isFinite(sample.server)
    ? sample.server + Math.max(0, tick - sample.received)
    : null;
  const local =
    current === null
      ? null
      : new Intl.DateTimeFormat(undefined, {
          timeZone: value,
          dateStyle: "medium",
          timeStyle: "long",
        }).format(new Date(current));
  return (
    <div>
      <label className="rsw-field">
        <span>Timezone</span>
        <select
          aria-label="Timezone"
          required
          value={value}
          onChange={(e) => onChange(e.target.value)}
        >
          {zones.map((zone) => (
            <option value={zone} key={zone}>
              {zone.replaceAll("_", " ")}
              {zone === browserTimezone() ? " (your timezone)" : ""}
            </option>
          ))}
        </select>
        <small>
          Dates and repeat rules use the selected timezone. New wipes default to
          your browser timezone.
        </small>
      </label>
      {local && (
        <p className="rsw-clock" role="timer" aria-live="off">
          Server clock in {value}: <strong>{local}</strong>
        </p>
      )}
      {Number.isFinite(sample.server) &&
        Math.abs(sample.server - sample.received) > 120000 && (
          <p className="rsw-notice">
            Your device and the server clock differ by more than two minutes.
            Scheduling uses the server clock. Check both clocks before choosing
            a time.
          </p>
        )}
    </div>
  );
}
