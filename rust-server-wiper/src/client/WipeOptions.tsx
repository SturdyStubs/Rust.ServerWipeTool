import { useState } from "react";
import { Button, http } from "@pterodactyl/sdk";
import type { Draft, Overview, Payload } from "./types";
type Props = {
  section: "scope" | "rotation" | "safeguards";
  base: string;
  draft: Draft;
  setDraft: (value: Draft) => void;
  overview?: Overview;
  action: (work: () => Promise<unknown>, message?: string) => Promise<void>;
};
export default function WipeOptions({
  section,
  base,
  draft,
  setDraft,
  overview,
  action,
}: Props) {
  const [preset, setPreset] = useState("");
  const [presetName, setPresetName] = useState("");
  const p = draft.payload;
  function change<K extends keyof Payload>(key: K, value: Payload[K]) {
    setDraft({ ...draft, payload: { ...p, [key]: value } });
  }
  function apply(id: string) {
    setPreset(id);
    if (id === "map" || id === "full")
      setDraft({
        ...draft,
        payload: {
          ...p,
          files: `${p.identity_path}/*.map\n${p.identity_path}/*.sav\n${p.identity_path}/*.sav.*`,
          blueprints: id === "full",
        },
      });
    else {
      const selected = overview?.presets?.find((s) => String(s.id) === id);
      if (selected) {
        setPresetName(selected.name);
        setDraft({ ...draft, payload: structuredClone(selected.payload) });
      }
    }
  }
  return (
    <>
      {section === "scope" && (
        <>
          <h3>Start with a preset</h3>
          <p>
            Apply a preset, then review its file patterns before saving or
            running.
          </p>
          <div className="rsw-grid">
            <label className="rsw-field">
              <span>Apply preset</span>
              <select value={preset} onChange={(e) => apply(e.target.value)}>
                <option value="">Choose a preset</option>
                <option value="map">Map wipe</option>
                <option value="full">Full wipe with blueprints</option>
                {overview?.presets?.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </select>
            </label>
          </div>
          <details className="rsw-disclosure">
            <summary>Save or manage reusable presets</summary>
            <label className="rsw-field">
              <span>Preset name</span>
              <input
                value={presetName}
                maxLength={191}
                onChange={(e) => setPresetName(e.target.value)}
              />
            </label>
            <div className="rsw-actions">
              <Button
                size="small"
                isSecondary
                disabled={
                  !presetName || overview?.permissions?.schedule === false
                }
                onClick={() =>
                  void action(
                    () =>
                      http.post(`${base}/presets`, {
                        name: presetName,
                        payload: p,
                      }),
                    "Preset saved.",
                  )
                }
              >
                Save as preset
              </Button>
              {/^\d+$/.test(preset) && (
                <>
                  <Button
                    size="small"
                    isSecondary
                    disabled={
                      !presetName || overview?.permissions?.schedule === false
                    }
                    onClick={() =>
                      void action(
                        () =>
                          http.put(`${base}/presets/${preset}`, {
                            name: presetName,
                            payload: p,
                          }),
                        "Preset updated.",
                      )
                    }
                  >
                    Update preset
                  </Button>
                  <Button
                    size="small"
                    color="red"
                    isSecondary
                    disabled={overview?.permissions?.schedule === false}
                    onClick={() => {
                      if (window.confirm("Delete this saved preset?"))
                        void action(
                          () => http.delete(`${base}/presets/${preset}`),
                          "Preset deleted.",
                        );
                    }}
                  >
                    Delete preset
                  </Button>
                </>
              )}
            </div>
          </details>
          {overview?.identity?.path && (
            <p>
              Detected identity: <code>{overview.identity.path}</code> from{" "}
              {overview.identity.source}.{" "}
              <Button
                size="small"
                isSecondary
                onClick={() =>
                  change("identity_path", overview.identity!.path!)
                }
              >
                Use detected directory
              </Button>
            </p>
          )}
          {overview?.identity && !overview.identity.path && (
            <p>{overview.identity.message}</p>
          )}
        </>
      )}
      {section === "safeguards" && (
        <>
          <h3>Backup & startup</h3>
          <label className="rsw-check">
            <input
              type="checkbox"
              checked={p.backup ?? false}
              onChange={(e) => change("backup", e.target.checked)}
            />
            Create a locked full-server backup before deleting
          </label>
          <p>
            The backup includes the wipe files and server configuration. A
            failed or unfinished backup prevents deletion. It uses the panel's
            backup storage and quota.
          </p>
          <details className="rsw-disclosure">
            <summary>Backup timeout & startup verification</summary>
            <div className="rsw-grid">
              <label className="rsw-field">
                <span>Backup timeout in seconds</span>
                <input
                  type="number"
                  min={30}
                  max={600}
                  value={p.backup_timeout ?? 300}
                  onChange={(e) =>
                    change("backup_timeout", Number(e.target.value))
                  }
                />
              </label>
              <label className="rsw-field">
                <span>Startup verification</span>
                <select
                  value={p.readiness ?? "none"}
                  onChange={(e) =>
                    change("readiness", e.target.value as Payload["readiness"])
                  }
                >
                  <option value="none">Start signal only</option>
                  <option value="wings">
                    Wings reports the process running
                  </option>
                  <option value="rcon">Rust responds through WebRCON</option>
                </select>
                <small>
                  Readiness checks run for up to five minutes. Configure WebRCON
                  in Settings for Rust readiness.
                </small>
              </label>
            </div>
          </details>
        </>
      )}
      {section === "rotation" && p.random_level && (
        <details className="rsw-disclosure">
          <summary>Map rotation policy</summary>
          <div className="rsw-grid">
            <label className="rsw-field">
              <span>Rotation mode</span>
              <select
                value={p.map_mode ?? "random"}
                onChange={(e) =>
                  change("map_mode", e.target.value as Payload["map_mode"])
                }
              >
                <option value="random">Random</option>
                <option value="ordered">Ordered</option>
                <option value="weighted">Weighted random</option>
              </select>
            </label>
            <label className="rsw-field">
              <span>Exclude recent maps</span>
              <input
                type="number"
                min={0}
                max={20}
                value={p.exclude_recent ?? 0}
                onChange={(e) =>
                  change("exclude_recent", Number(e.target.value))
                }
              />
              <small>
                If all maps are excluded, allow older maps while avoiding the
                current map where possible.
              </small>
            </label>
          </div>
        </details>
      )}
      {section === "safeguards" && (
        <details className="rsw-disclosure">
          <summary>
            Wait for players to leave{" "}
            <small>Optional - requires WebRCON in Settings</small>
          </summary>
          <label className="rsw-check">
            <input
              type="checkbox"
              checked={p.wait_players ?? false}
              onChange={(e) => change("wait_players", e.target.checked)}
            />
            Wait until the server is empty
          </label>
          {p.wait_players && (
            <>
              <p>
                Player counts use WebRCON. Deferred wipes retry every five
                minutes.
              </p>
              <div className="rsw-grid">
                <label className="rsw-field">
                  <span>Maximum deferral in minutes</span>
                  <input
                    type="number"
                    min={5}
                    max={1440}
                    value={p.max_deferral ?? 60}
                    onChange={(e) =>
                      change("max_deferral", Number(e.target.value))
                    }
                  />
                </label>
                <label className="rsw-field">
                  <span>At the deadline</span>
                  <select
                    value={p.deadline_action ?? "cancel"}
                    onChange={(e) =>
                      change(
                        "deadline_action",
                        e.target.value as Payload["deadline_action"],
                      )
                    }
                  >
                    <option value="cancel">Cancel the wipe</option>
                    <option value="proceed">
                      Wipe even if players are online
                    </option>
                  </select>
                </label>
                <label className="rsw-field">
                  <span>If player count is unavailable</span>
                  <select
                    value={p.unknown_players ?? "defer"}
                    onChange={(e) =>
                      change(
                        "unknown_players",
                        e.target.value as Payload["unknown_players"],
                      )
                    }
                  >
                    <option value="defer">Defer until the deadline</option>
                    <option value="cancel">Cancel the wipe</option>
                    <option value="proceed">Proceed without a count</option>
                  </select>
                </label>
              </div>
            </>
          )}
        </details>
      )}
    </>
  );
}
