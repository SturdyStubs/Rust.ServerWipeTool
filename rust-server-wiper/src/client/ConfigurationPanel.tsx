import { useEffect, useRef, useState } from "react";
import { Button, http, httpErrorToHuman } from "@pterodactyl/sdk";
import { describeRule, type Payload } from "./types";

type Plan = {
  schedules: {
    label: string;
    timezone: string;
    next_run_at: string | null;
    repeat: string[];
    payload: Payload;
  }[];
  presets: { name: string; payload: Payload }[];
  maps: { name: string; url: string; weight: number; position: number }[];
  warnings: string[];
  target_identity: string;
};

export default function ConfigurationPanel({
  base,
  identity,
  onImported,
}: {
  base: string;
  identity: string;
  onImported: () => void;
}) {
  const [target, setTarget] = useState(identity);
  const [configuration, setConfiguration] = useState<unknown>(null);
  const [fileName, setFileName] = useState("");
  const [plan, setPlan] = useState<Plan | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [imported, setImported] = useState(false);
  const requestId = useRef(crypto.randomUUID());
  useEffect(() => {
    setPlan(null);
    setConfirmed(false);
    setImported(false);
    setMessage("");
    requestId.current = crypto.randomUUID();
  }, [configuration, target]);
  async function preview() {
    setBusy(true);
    setError("");
    setPlan(null);
    setConfirmed(false);
    try {
      setPlan(
        (
          await http.post<Plan>(`${base}/configuration/preview`, {
            configuration,
            target_identity: target,
          })
        ).data,
      );
    } catch (e) {
      setError(httpErrorToHuman(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <section aria-labelledby="rsw-transfer-title">
      <h2 id="rsw-transfer-title">Import / export configuration</h2>
      <p>
        Copy wipe schedules, presets, and your map library between servers.
        Importing adds to this server; existing configuration stays in place.
      </p>
      <h3>Export this server</h3>
      <p>
        Discord and RCON settings, console commands, and URL credentials or
        query strings are excluded. Review names, descriptions, and paths before
        sharing the file. Signed map links may need to be entered again.
      </p>
      <a
        href={`${base}/configuration/export`}
        download="rust-wiper-configuration.json"
      >
        Download configuration JSON
      </a>
      <h3>Import a configuration</h3>
      <p>
        Imported schedules start paused. Review the target directory and every
        imported path before enabling them.
      </p>
      <div className="rsw-grid">
        <label className="rsw-field">
          <span>Configuration JSON file</span>
          <input
            type="file"
            accept=".json,application/json"
            disabled={busy}
            onChange={async (e) => {
              const file = e.target.files?.[0];
              setConfiguration(null);
              setFileName("");
              setError("");
              if (!file) return;
              if (file.size > 2 * 1024 * 1024) {
                setError("Choose a JSON file smaller than 2 MB.");
                return;
              }
              setBusy(true);
              try {
                const content: unknown = JSON.parse(await file.text());
                setConfiguration(content);
                setFileName(file.name);
              } catch {
                setError(
                  "This file is not valid JSON. Choose a configuration exported by Rust Server Wiper.",
                );
              } finally {
                setBusy(false);
              }
            }}
          />
        </label>
        <label className="rsw-field">
          <span>Target Rust identity directory</span>
          <input
            required
            value={target}
            disabled={busy}
            onChange={(e) => setTarget(e.target.value)}
          />
          <small>
            Paths under each source identity directory are adapted to this
            directory. Other paths are listed for review.
          </small>
        </label>
      </div>
      {fileName && <small>Selected: {fileName}</small>}
      <div className="rsw-form-actions">
        <Button
          isSecondary
          disabled={busy || !configuration || !target || imported}
          isLoading={busy}
          onClick={() => void preview()}
        >
          Preview import
        </Button>
      </div>
      {error && (
        <p role="alert" className="rsw-error">
          {error}
        </p>
      )}
      {message && (
        <p role="status" className="rsw-notice">
          {message}
        </p>
      )}
      {plan && (
        <>
          <h3>Review the import</h3>
          <p>
            {plan.schedules.length} paused schedules, {plan.presets.length}{" "}
            presets, and {plan.maps.length} maps will be added. Target identity:{" "}
            <code>{plan.target_identity}</code>.
          </p>
          <ul className="rsw-import-warnings">
            {plan.warnings.map((warning) => (
              <li key={warning}>{warning}</li>
            ))}
          </ul>
          {plan.schedules.map((schedule, i) => (
            <details className="rsw-disclosure" key={`schedule-${i}`}>
              <summary>
                {schedule.label}{" "}
                <small>
                  Paused ·{" "}
                  {schedule.repeat.length
                    ? schedule.repeat.map(describeRule).join("; ")
                    : schedule.next_run_at
                      ? new Intl.DateTimeFormat(undefined, {
                          timeZone: schedule.timezone,
                          dateStyle: "medium",
                          timeStyle: "short",
                        }).format(new Date(schedule.next_run_at))
                      : "Needs a future date"}{" "}
                  · {schedule.timezone}
                </small>
              </summary>
              <pre className="rsw-import-paths">
                {schedule.payload.files || "No explicit file patterns"}
              </pre>
              <details>
                <summary>All wipe options</summary>
                <pre className="rsw-import-paths">
                  {JSON.stringify(schedule.payload, null, 2)}
                </pre>
              </details>
              <small>
                {schedule.payload.blueprints
                  ? "Includes blueprint deletion."
                  : "Blueprints are not automatically selected."}{" "}
                {schedule.payload.backup
                  ? "Backup selected."
                  : "No pre-wipe backup."}
              </small>
            </details>
          ))}
          {plan.presets.map((preset, i) => (
            <details className="rsw-disclosure" key={`preset-${i}`}>
              <summary>Preset: {preset.name}</summary>
              <pre className="rsw-import-paths">
                {JSON.stringify(preset.payload, null, 2)}
              </pre>
              <small>
                {preset.payload.blueprints
                  ? "Includes blueprint deletion."
                  : "Blueprints are not automatically selected."}
              </small>
            </details>
          ))}
          {!!plan.maps.length && (
            <details className="rsw-disclosure">
              <summary>Map library ({plan.maps.length})</summary>
              <ul className="rsw-maps">
                {plan.maps.map((map, i) => (
                  <li key={i}>
                    <div>
                      <strong>{map.name}</strong>
                      <small>{map.url}</small>
                    </div>
                  </li>
                ))}
              </ul>
            </details>
          )}
          <label className="rsw-check">
            <input
              type="checkbox"
              checked={confirmed}
              disabled={busy || imported}
              onChange={(e) => setConfirmed(e.target.checked)}
            />
            I reviewed the imported paths and understand that schedules will be
            paused.
          </label>
          <Button
            disabled={busy || !confirmed || imported}
            isLoading={busy}
            onClick={async () => {
              setBusy(true);
              setError("");
              try {
                const result = await http.post<{
                  schedules: number;
                  presets: number;
                  maps: number;
                }>(`${base}/configuration/import`, {
                  configuration,
                  target_identity: target,
                  confirm: true,
                  request_uuid: requestId.current,
                });
                setImported(true);
                setConfirmed(false);
                setMessage(
                  `Imported ${result.data.schedules} paused schedules, ${result.data.presets} presets, and ${result.data.maps} maps. Open Schedules to review them before enabling.`,
                );
                onImported();
              } catch (e) {
                setError(httpErrorToHuman(e));
              } finally {
                setBusy(false);
              }
            }}
          >
            Import configuration
          </Button>
        </>
      )}
    </section>
  );
}
