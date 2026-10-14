import {
  useEffect,
  useRef,
  useState,
  type FormEvent,
  type ReactNode,
} from "react";
import { useQuery } from "@tanstack/react-query";
import {
  Button,
  ServerContentBlock,
  Spinner,
  http,
  httpErrorToHuman,
  useCurrentServerRequired,
} from "@pterodactyl/sdk";
import {
  addFile,
  blankDraft,
  localInput,
  type Draft,
  type FileEntry,
  type Overview,
  type Payload,
  type Preview,
  type Wipe,
} from "./types";

const weekdays = [
  "Monday",
  "Tuesday",
  "Wednesday",
  "Thursday",
  "Friday",
  "Saturday",
  "Sunday",
];
const ordinals = ["First", "Second", "Third", "Fourth", "Fifth"];
function Field({
  label,
  help,
  children,
}: {
  label: string;
  help?: string;
  children: ReactNode;
}) {
  return (
    <label className="rsw-field">
      <span>{label}</span>
      {children}
      {help && <small>{help}</small>}
    </label>
  );
}
function Check({
  label,
  value,
  change,
  disabled = false,
}: {
  label: string;
  value: boolean;
  change: (v: boolean) => void;
  disabled?: boolean;
}) {
  return (
    <label className="rsw-check">
      <input
        type="checkbox"
        checked={value}
        onChange={(e) => change(e.target.checked)}
        disabled={disabled}
      />
      {label}
    </label>
  );
}
function date(iso: string | null, zone: string) {
  return iso
    ? new Intl.DateTimeFormat(undefined, {
        timeZone: zone,
        dateStyle: "medium",
        timeStyle: "short",
      }).format(new Date(iso))
    : "No upcoming run";
}

export default function WipesScreen() {
  const server = useCurrentServerRequired();
  const identifier = server.attributes.identifier;
  const base = `/api/client/servers/${encodeURIComponent(identifier)}/extensions/rust-server-wiper`;
  const overview = useQuery({
    queryKey: ["rust-server-wiper", identifier],
    queryFn: async ({ signal }) =>
      (await http.get<Overview>(base, { signal })).data,
    refetchInterval: 5000,
  });
  const [draft, setDraft] = useState<Draft>(blankDraft);
  const [editing, setEditing] = useState<number | null>(null);
  const [showEditor, setShowEditor] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [preview, setPreview] = useState<Preview | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const [week, setWeek] = useState("1");
  const [weekday, setWeekday] = useState("4");
  const [clock, setClock] = useState("14:00");
  const [browse, setBrowse] = useState(false);
  const [directory, setDirectory] = useState("/");
  const [mapName, setMapName] = useState("");
  const [mapUrl, setMapUrl] = useState("");
  const editor = useRef<HTMLDivElement>(null);
  const listing = useQuery({
    queryKey: ["rust-server-wiper-files", identifier, directory],
    enabled: browse,
    queryFn: async ({ signal }) =>
      (
        await http.get<{ files: FileEntry[] }>(`${base}/files`, {
          params: { directory },
          signal,
        })
      ).data.files,
  });
  useEffect(() => {
    setPreview(null);
    setConfirmed(false);
  }, [draft]);

  async function action(work: () => Promise<unknown>, message?: string) {
    if (busy) return;
    setBusy(true);
    setError("");
    setNotice("");
    try {
      await work();
      if (message) setNotice(message);
      await overview.refetch();
    } catch (e) {
      setError(httpErrorToHuman(e));
    } finally {
      setBusy(false);
    }
  }
  function payload<K extends keyof Payload>(key: K, value: Payload[K]) {
    setDraft((d) => ({ ...d, payload: { ...d.payload, [key]: value } }));
  }
  function open(wipe?: Wipe) {
    setEditing(wipe?.id ?? null);
    setError("");
    setNotice("");
    setDraft(
      wipe
        ? {
            label: wipe.label,
            enabled: wipe.enabled,
            timezone: wipe.timezone,
            repeat: [...wipe.repeat],
            payload: structuredClone(wipe.payload),
            time:
              wipe.repeat.length || !wipe.next_run_at
                ? ""
                : localInput(wipe.next_run_at, wipe.timezone),
          }
        : blankDraft(),
    );
    setShowEditor(true);
    window.setTimeout(
      () =>
        editor.current?.scrollIntoView({ behavior: "smooth", block: "start" }),
      0,
    );
  }
  function save(e: FormEvent) {
    e.preventDefault();
    void action(async () => {
      await http.request({
        method: editing ? "PUT" : "POST",
        url: editing ? `${base}/wipes/${editing}` : `${base}/wipes`,
        data: draft,
      });
      setShowEditor(false);
    }, "Wipe schedule saved.");
  }
  const active = overview.data?.runs.some((r) =>
    ["queued", "running"].includes(r.status),
  );
  const custom = !!draft.payload.level || draft.payload.random_level;

  return (
    <ServerContentBlock title="Rust Wipes">
      <div className="rsw-page">
        <div className="rsw-heading">
          <div>
            <h1>Rust Wipes</h1>
            <p>
              Schedule fresh starts, rotate maps, and review what each wipe
              removed.
            </p>
          </div>
          <Button onClick={() => open()} disabled={busy}>
            Create wipe
          </Button>
        </div>
        {(error || overview.error) && (
          <div role="alert" className="rsw-error">
            {error || httpErrorToHuman(overview.error)}{" "}
            <button onClick={() => void overview.refetch()}>Reload</button>
          </div>
        )}
        {notice && (
          <p role="status" className="rsw-notice">
            {notice}
          </p>
        )}
        {overview.isPending && <Spinner />}
        <section aria-labelledby="rsw-schedules">
          <h2 id="rsw-schedules">Scheduled wipes</h2>
          {overview.data && overview.data.wipes.length === 0 && (
            <p className="rsw-empty">
              No wipes scheduled. Create a wipe to choose files and a time.
            </p>
          )}
          <div className="rsw-table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Wipe</th>
                  <th>Next run</th>
                  <th>Status</th>
                  <th>
                    <span className="rsw-sr">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {overview.data?.wipes.map((wipe) => (
                  <tr key={wipe.id}>
                    <td>
                      <strong>{wipe.label}</strong>
                      <small>
                        {wipe.repeat.length ? "Monthly repeat" : "One time"}
                        {wipe.payload.blueprints
                          ? " · Includes blueprints"
                          : ""}
                      </small>
                    </td>
                    <td>
                      {date(wipe.next_run_at, wipe.timezone)}
                      <small>{wipe.timezone}</small>
                    </td>
                    <td>
                      {!wipe.enabled
                        ? "Paused"
                        : wipe.next_run_at
                          ? "Scheduled"
                          : "Finished"}
                    </td>
                    <td>
                      <div className="rsw-actions">
                        <Button
                          size="small"
                          isSecondary
                          onClick={() => open(wipe)}
                          disabled={busy}
                        >
                          Edit
                        </Button>
                        <Button
                          size="small"
                          isSecondary
                          disabled={busy}
                          onClick={() =>
                            void action(() =>
                              http.patch(`${base}/wipes/${wipe.id}/enabled`, {
                                enabled: !wipe.enabled,
                              }),
                            )
                          }
                        >
                          {wipe.enabled ? "Pause" : "Enable"}
                        </Button>
                        <Button
                          size="small"
                          color="red"
                          isSecondary
                          disabled={busy}
                          onClick={() => {
                            if (
                              window.confirm(`Delete schedule “${wipe.label}”?`)
                            )
                              void action(
                                () => http.delete(`${base}/wipes/${wipe.id}`),
                                "Schedule deleted.",
                              );
                          }}
                        >
                          Delete
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>

        {showEditor && (
          <section
            ref={editor}
            className="rsw-editor"
            aria-labelledby="rsw-editor-title"
          >
            <div className="rsw-heading">
              <h2 id="rsw-editor-title">
                {editing ? "Edit wipe" : "Create wipe"}
              </h2>
              <Button
                isSecondary
                size="small"
                disabled={busy}
                onClick={() => setShowEditor(false)}
              >
                Close editor
              </Button>
            </div>
            <form onSubmit={save}>
              <fieldset disabled={busy}>
                <div className="rsw-grid">
                  <Field label="Schedule name">
                    <input
                      required
                      maxLength={191}
                      value={draft.label}
                      onChange={(e) =>
                        setDraft({ ...draft, label: e.target.value })
                      }
                      placeholder="Monthly full wipe"
                    />
                  </Field>
                  <Field
                    label="Timezone"
                    help="All dates and repeat rules use this timezone."
                  >
                    <input
                      list="rsw-timezones"
                      required
                      value={draft.timezone}
                      onChange={(e) =>
                        setDraft({ ...draft, timezone: e.target.value })
                      }
                    />
                    <datalist id="rsw-timezones">
                      {overview.data?.timezones.map((z) => (
                        <option key={z} value={z} />
                      ))}
                    </datalist>
                  </Field>
                </div>
                <h3>Files to remove</h3>
                <p>
                  Choose the files that make up your wipe. Map files are only
                  removed when listed here.
                </p>
                <Field
                  label="Files and patterns"
                  help="One absolute server path per line. * matches filenames only; for example /server/rust/*.sav. Directories must be selected explicitly."
                >
                  <textarea
                    rows={5}
                    value={draft.payload.files}
                    onChange={(e) => payload("files", e.target.value)}
                    placeholder={
                      "/server/rust/*.map\n/server/rust/*.sav\n/server/rust/*.sav.*"
                    }
                  />
                </Field>
                <div className="rsw-actions">
                  <Button
                    size="small"
                    isSecondary
                    onClick={() => setBrowse(!browse)}
                  >
                    {browse ? "Close file browser" : "Browse server files"}
                  </Button>
                  <Check
                    label="Wipe blueprints"
                    value={draft.payload.blueprints}
                    change={(v) => payload("blueprints", v)}
                  />
                </div>
                <Field
                  label="Rust identity directory"
                  help="Used for blueprint files and cfg/server.cfg. Match the server.identity used by your startup command."
                >
                  <input
                    required
                    value={draft.payload.identity_path}
                    onChange={(e) => payload("identity_path", e.target.value)}
                  />
                </Field>
                {browse && (
                  <div className="rsw-browser">
                    <div className="rsw-actions">
                      <Button
                        size="small"
                        isSecondary
                        disabled={directory === "/"}
                        onClick={() =>
                          setDirectory(
                            directory.substring(
                              0,
                              directory.lastIndexOf("/"),
                            ) || "/",
                          )
                        }
                      >
                        Parent directory
                      </Button>
                      <code>{directory}</code>
                    </div>
                    {listing.isPending && <p>Loading files…</p>}
                    {listing.error && (
                      <p role="alert">{httpErrorToHuman(listing.error)}</p>
                    )}
                    {listing.data?.length === 0 && (
                      <p>This directory is empty.</p>
                    )}
                    <ul>
                      {listing.data?.map((file) => {
                        const path = `${directory === "/" ? "" : directory}/${file.name}`;
                        const selected = draft.payload.files
                          .split(/\r?\n/)
                          .includes(path);
                        return (
                          <li key={file.name}>
                            {!file.file && !file.symlink ? (
                              <button
                                type="button"
                                onClick={() => setDirectory(path)}
                              >
                                {file.name}/
                              </button>
                            ) : (
                              <span>
                                {file.name}
                                {file.symlink ? " (symlink)" : ""}
                              </span>
                            )}
                            {!file.symlink && directory !== "/" && (
                              <button
                                type="button"
                                disabled={selected}
                                onClick={() =>
                                  payload(
                                    "files",
                                    addFile(draft.payload.files, path),
                                  )
                                }
                              >
                                {selected ? "Selected" : "Select"}
                              </button>
                            )}
                          </li>
                        );
                      })}
                    </ul>
                  </div>
                )}

                <h3>Startup after the wipe</h3>
                <p>
                  Leave optional fields blank to keep their current values.
                  %DAY%, %MONTH% and %YEAR% use the wipe date.
                </p>
                <Field label="Server name">
                  <input
                    maxLength={191}
                    value={draft.payload.name ?? ""}
                    placeholder={overview.data?.environment.HOSTNAME}
                    onChange={(e) => payload("name", e.target.value || null)}
                  />
                </Field>
                <Field label="Server description">
                  <textarea
                    rows={3}
                    value={draft.payload.description ?? ""}
                    placeholder={overview.data?.environment.DESCRIPTION}
                    onChange={(e) =>
                      payload("description", e.target.value || null)
                    }
                  />
                </Field>
                <div className="rsw-grid">
                  <Field label="Map size">
                    <input
                      type="number"
                      min={1000}
                      max={10000}
                      disabled={custom}
                      value={draft.payload.size ?? ""}
                      onChange={(e) =>
                        payload(
                          "size",
                          e.target.value === "" ? null : Number(e.target.value),
                        )
                      }
                    />
                  </Field>
                  <Field label="Map seed">
                    <input
                      type="number"
                      min={0}
                      max={2147483647}
                      disabled={custom || draft.payload.random_seed}
                      value={draft.payload.seed ?? ""}
                      onChange={(e) =>
                        payload(
                          "seed",
                          e.target.value === "" ? null : Number(e.target.value),
                        )
                      }
                    />
                  </Field>
                </div>
                <div className="rsw-actions">
                  <Check
                    label="Generate a random seed"
                    value={draft.payload.random_seed}
                    disabled={custom}
                    change={(v) =>
                      setDraft({
                        ...draft,
                        payload: {
                          ...draft.payload,
                          random_seed: v,
                          seed: v ? null : draft.payload.seed,
                        },
                      })
                    }
                  />
                  <Check
                    label="Rotate a saved custom map"
                    value={draft.payload.random_level}
                    change={(v) =>
                      setDraft({
                        ...draft,
                        payload: {
                          ...draft.payload,
                          random_level: v,
                          ...(v
                            ? {
                                level: null,
                                seed: null,
                                size: null,
                                random_seed: false,
                              }
                            : {}),
                        },
                      })
                    }
                  />
                </div>
                <Field
                  label="Custom map URL"
                  help="Choose a fixed map or rotate from the saved maps below. An explicit seed or size switches back to a procedural map."
                >
                  <input
                    type="url"
                    disabled={draft.payload.random_level}
                    value={draft.payload.level ?? ""}
                    onChange={(e) =>
                      setDraft({
                        ...draft,
                        payload: {
                          ...draft.payload,
                          level: e.target.value || null,
                          ...(e.target.value
                            ? { seed: null, size: null, random_seed: false }
                            : {}),
                        },
                      })
                    }
                  />
                </Field>

                <h3>When to wipe</h3>
                <Field label="One-time date and time">
                  <input
                    type="datetime-local"
                    disabled={draft.repeat.length > 0}
                    value={draft.time}
                    onChange={(e) =>
                      setDraft({ ...draft, time: e.target.value })
                    }
                  />
                </Field>
                <p>
                  Or repeat on an ordinal weekday each month. A fifth weekday is
                  skipped in months where it does not exist.
                </p>
                <div className="rsw-rule">
                  <Field label="Week">
                    <select
                      value={week}
                      onChange={(e) => setWeek(e.target.value)}
                    >
                      {ordinals.map((w, i) => (
                        <option key={w} value={i + 1}>
                          {w}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field label="Day">
                    <select
                      value={weekday}
                      onChange={(e) => setWeekday(e.target.value)}
                    >
                      {weekdays.map((d, i) => (
                        <option key={d} value={i + 1}>
                          {d}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field label="Time">
                    <input
                      type="time"
                      value={clock}
                      onChange={(e) => setClock(e.target.value)}
                    />
                  </Field>
                  <Button
                    size="small"
                    isSecondary
                    onClick={() => {
                      if (clock)
                        setDraft({
                          ...draft,
                          time: "",
                          repeat: [
                            ...new Set([
                              ...draft.repeat,
                              `${week} ${weekday} ${clock}`,
                            ]),
                          ],
                        });
                    }}
                  >
                    Add repeat
                  </Button>
                </div>
                <ul className="rsw-rules">
                  {draft.repeat.map((rule) => {
                    const [w, d, t] = rule.split(" ");
                    return (
                      <li key={rule}>
                        <span>
                          {ordinals[Number(w) - 1]} {weekdays[Number(d) - 1]} at{" "}
                          {t}
                        </span>
                        <button
                          type="button"
                          aria-label={`Remove repeat ${rule}`}
                          onClick={() =>
                            setDraft({
                              ...draft,
                              repeat: draft.repeat.filter((r) => r !== rule),
                            })
                          }
                        >
                          Remove
                        </button>
                      </li>
                    );
                  })}
                </ul>
                <Check
                  label="Schedule enabled"
                  value={draft.enabled}
                  change={(enabled) => setDraft({ ...draft, enabled })}
                />

                <h3>Before-wipe console commands</h3>
                <p>
                  Commands are sent at the selected number of minutes before a
                  scheduled wipe.
                </p>
                {draft.payload.commands.map((command, i) => (
                  <div className="rsw-command" key={i}>
                    <Field label={`Command ${i + 1}`}>
                      <input
                        required
                        maxLength={2000}
                        value={command.command}
                        onChange={(e) =>
                          payload(
                            "commands",
                            draft.payload.commands.map((c, j) =>
                              j === i ? { ...c, command: e.target.value } : c,
                            ),
                          )
                        }
                        placeholder="say Server wipe in 10 minutes"
                      />
                    </Field>
                    <Field label="Minutes before">
                      <input
                        type="number"
                        min={1}
                        max={1440}
                        required
                        value={command.minutes}
                        onChange={(e) =>
                          payload(
                            "commands",
                            draft.payload.commands.map((c, j) =>
                              j === i
                                ? { ...c, minutes: Number(e.target.value) }
                                : c,
                            ),
                          )
                        }
                      />
                    </Field>
                    <Button
                      size="small"
                      isSecondary
                      onClick={() =>
                        payload(
                          "commands",
                          draft.payload.commands.filter((_, j) => j !== i),
                        )
                      }
                    >
                      Remove
                    </Button>
                  </div>
                ))}
                <Button
                  size="small"
                  isSecondary
                  disabled={draft.payload.commands.length >= 20}
                  onClick={() =>
                    payload("commands", [
                      ...draft.payload.commands,
                      { command: "", minutes: 10 },
                    ])
                  }
                >
                  Add command
                </Button>
                <div className="rsw-form-actions">
                  <Button type="submit" isLoading={busy}>
                    Save schedule
                  </Button>
                  <Button
                    isSecondary
                    onClick={() =>
                      void action(async () =>
                        setPreview(
                          (await http.post<Preview>(`${base}/preview`, draft))
                            .data,
                        ),
                      )
                    }
                  >
                    Preview files
                  </Button>
                </div>
                {preview && (
                  <div className="rsw-preview">
                    <h3>
                      {preview.count}{" "}
                      {preview.count === 1 ? "entry matches" : "entries match"}{" "}
                      this wipe
                    </h3>
                    <p>
                      This preview is current now. The wipe will resolve the
                      patterns again after the server stops.
                    </p>
                    <ul>
                      {preview.files.map((file) => (
                        <li key={file}>
                          <code>{file}</code>
                        </li>
                      ))}
                    </ul>
                    <Check
                      label="I reviewed the files and understand that this wipe permanently deletes them."
                      value={confirmed}
                      change={setConfirmed}
                    />
                    <Button
                      color="red"
                      disabled={!confirmed || busy || !!active}
                      onClick={() =>
                        void action(async () => {
                          await http.post(`${base}/run`, {
                            ...draft,
                            confirm: true,
                          });
                          setShowEditor(false);
                        }, "Wipe queued. Follow its progress in run history.")
                      }
                    >
                      Wipe now
                    </Button>
                    <small>
                      The server will stop and start. Scheduled announcements
                      are skipped for immediate wipes.
                    </small>
                  </div>
                )}
              </fieldset>
            </form>
          </section>
        )}

        <section aria-labelledby="rsw-maps">
          <h2 id="rsw-maps">Custom map rotation</h2>
          <p>
            Rotation avoids the current map when another saved map is available.
          </p>
          <ul className="rsw-maps">
            {overview.data?.maps.map((map) => (
              <li key={map.id}>
                <div>
                  <strong>{map.name}</strong>
                  <small>{map.url}</small>
                </div>
                <Button
                  isSecondary
                  size="small"
                  color="red"
                  disabled={busy}
                  onClick={() => {
                    if (
                      window.confirm(`Remove “${map.name}” from map rotation?`)
                    )
                      void action(() => http.delete(`${base}/maps/${map.id}`));
                  }}
                >
                  Remove
                </Button>
              </li>
            ))}
          </ul>
          {overview.data?.maps.length === 0 && (
            <p className="rsw-empty">
              No saved maps. Add a .map URL to use map rotation.
            </p>
          )}
          <form
            className="rsw-map-form"
            onSubmit={(e) => {
              e.preventDefault();
              void action(async () => {
                await http.post(`${base}/maps`, { name: mapName, url: mapUrl });
                setMapName("");
                setMapUrl("");
              }, "Map saved.");
            }}
          >
            <Field label="Map name">
              <input
                required
                disabled={busy}
                maxLength={191}
                value={mapName}
                onChange={(e) => setMapName(e.target.value)}
              />
            </Field>
            <Field label="Map URL">
              <input
                required
                disabled={busy}
                type="url"
                value={mapUrl}
                onChange={(e) => setMapUrl(e.target.value)}
              />
            </Field>
            <Button type="submit" disabled={busy}>
              Add map
            </Button>
          </form>
        </section>

        <section aria-labelledby="rsw-history">
          <h2 id="rsw-history">Run history</h2>
          <p>
            The latest 50 runs. Failed and missed wipes require review before
            running again.
          </p>
          {overview.data?.runs.length === 0 && (
            <p className="rsw-empty">
              Wipes will appear here when they are queued.
            </p>
          )}
          <div className="rsw-table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Wipe</th>
                  <th>Due</th>
                  <th>Status and result</th>
                </tr>
              </thead>
              <tbody>
                {overview.data?.runs.map((run) => (
                  <tr key={run.id}>
                    <td>{run.label}</td>
                    <td>
                      {date(run.due_at, run.timezone)}
                      <small>{run.timezone}</small>
                    </td>
                    <td>
                      <strong
                        className={run.status === "failed" ? "rsw-failed" : ""}
                      >
                        {run.status}
                      </strong>
                      <small>{run.message}</small>
                      {run.result && (
                        <details>
                          <summary>View files and startup values</summary>
                          <ul>
                            {run.result.deleted?.map((file) => (
                              <li key={file}>
                                <code>{file}</code>
                              </li>
                            ))}
                          </ul>
                          {Object.entries(run.result.environment ?? {}).map(
                            ([name, value]) => (
                              <div key={name}>
                                <code>
                                  {name}: {value}
                                </code>
                              </div>
                            ),
                          )}
                        </details>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </ServerContentBlock>
  );
}
