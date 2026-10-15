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
import WipeOptions from "./WipeOptions";
import SettingsPanel from "./SettingsPanel";
import MapRules from "./MapRules";

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
  const [view, setView] = useState("schedules");
  const [step, setStep] = useState(0);
  const steps = ["Wipe scope", "Next map", "Timing & safeguards", "Review"];
  const form = useRef<HTMLFormElement>(null);
  const stepTitle = useRef<HTMLHeadingElement>(null);
  function goStep(next: number) {
    setStep(next);
    window.setTimeout(() => stepTitle.current?.focus(), 0);
  }
  function validateDraft() {
    const invalid = form.current?.querySelector<HTMLInputElement>(
      "input:invalid, select:invalid, textarea:invalid",
    );
    if (!invalid) return true;
    const panel = invalid.closest<HTMLElement>("[data-step]");
    if (panel) setStep(Number(panel.dataset.step));
    const details = invalid.closest("details");
    if (details) details.open = true;
    window.setTimeout(() => invalid.reportValidity(), 0);
    return false;
  }
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
  const requestId = useRef(crypto.randomUUID());
  const restoreIds = useRef<Record<number, string>>({});
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
    requestId.current = crypto.randomUUID();
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
    setStep(0);
    setView("schedules");
    setBrowse(false);
    setShowEditor(true);
    window.setTimeout(
      () =>
        editor.current?.scrollIntoView({ behavior: "smooth", block: "start" }),
      0,
    );
  }
  function save(e: FormEvent) {
    e.preventDefault();
    if (step !== 3 || !validateDraft()) return;
    void action(async () => {
      await http.request({
        method: editing ? "PUT" : "POST",
        url: editing ? `${base}/wipes/${editing}` : `${base}/wipes`,
        data: draft,
      });
      setShowEditor(false);
    }, "Wipe schedule saved.");
  }
  const active =
    overview.data?.runs.some((r) =>
      ["queued", "running", "deferred"].includes(r.status),
    ) ||
    overview.data?.restores?.some((r) =>
      ["queued", "stopping", "restoring", "restored"].includes(r.status),
    );
  const can = (key: string) => overview.data?.permissions?.[key] !== false;
  const custom = !!draft.payload.level || draft.payload.random_level;

  return (
    <ServerContentBlock title="Rust Wipes">
      <div className="rsw-page">
        <div className="rsw-heading">
          <div>
            <h1>Rust Wipes</h1>
            <p>Plan your next fresh start. Review every wipe before it runs.</p>
          </div>
          <Button
            onClick={() => open()}
            disabled={
              busy || showEditor || (!can("schedule") && !can("execute"))
            }
          >
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
        {overview.data?.maintenance && (
          <div role="status" className="rsw-notice">
            <strong>
              Maintenance active: {overview.data.maintenance.operation}
            </strong>
            <p>
              Power and supported file changes are blocked while the operation
              runs. Inspect the server before releasing an expired operation.
            </p>
            {can("configure") && (
              <Button
                size="small"
                isSecondary
                disabled={busy}
                onClick={() => {
                  if (
                    window.confirm(
                      "Release expired maintenance after inspecting the server?",
                    )
                  )
                    void action(
                      () =>
                        http.post(`${base}/maintenance/recover`, {
                          confirm: true,
                        }),
                      "Expired maintenance released.",
                    );
                }}
              >
                Recover expired maintenance
              </Button>
            )}
          </div>
        )}
        <nav className="rsw-nav" aria-label="Wiper sections">
          {[
            ["schedules", "Schedules"],
            ["maps", "Map library"],
            ["history", "Activity & backups"],
            ...(can("configure") ? [["settings", "Settings"]] : []),
          ].map(([id, label]) => (
            <button
              type="button"
              key={id}
              className="rsw-nav-item"
              aria-current={view === id ? "page" : undefined}
              disabled={busy}
              onClick={() => setView(id)}
            >
              {label}
            </button>
          ))}
        </nav>
        <div hidden={view !== "schedules"}>
          {!showEditor && overview.data && (
            <div className="rsw-workflow-note">
              <strong>
                {active
                  ? "A server operation is in progress"
                  : "Ready for your next wipe"}
              </strong>
              <p>
                {active
                  ? "Open Activity & backups to follow progress. New wipes are blocked until the current operation finishes."
                  : "Choose files, configure the next map, then save a schedule or preview the files and wipe now."}
              </p>
              {active && (
                <Button
                  size="small"
                  isSecondary
                  onClick={() => setView("history")}
                >
                  View activity
                </Button>
              )}
            </div>
          )}
          <section hidden={showEditor} aria-labelledby="rsw-schedules">
            <h2 id="rsw-schedules">Scheduled wipes</h2>
            {overview.data && overview.data.wipes.length === 0 && (
              <p className="rsw-empty">
                No wipes scheduled. Create a wipe to choose files and a time.
              </p>
            )}
            <div
              className="rsw-table-wrap"
              hidden={overview.data?.wipes.length === 0}
            >
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
                        <span
                          className={`rsw-badge ${wipe.enabled && wipe.next_run_at ? "rsw-badge-active" : ""}`}
                        >
                          {!wipe.enabled
                            ? "Paused"
                            : wipe.next_run_at
                              ? "Scheduled"
                              : "Finished"}
                        </span>
                      </td>
                      <td>
                        <div className="rsw-actions">
                          <Button
                            size="small"
                            isSecondary
                            onClick={() => open(wipe)}
                            disabled={
                              busy || (!can("schedule") && !can("execute"))
                            }
                          >
                            Edit
                          </Button>
                          <Button
                            size="small"
                            isSecondary
                            disabled={busy || !can("schedule")}
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
                            disabled={busy || !can("schedule")}
                            onClick={() => {
                              if (
                                window.confirm(
                                  `Delete schedule “${wipe.label}”?`,
                                )
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
              <ol className="rsw-steps" aria-label="Wipe setup progress">
                {steps.map((label, index) => (
                  <li key={label}>
                    <button
                      type="button"
                      className="rsw-step-button"
                      disabled={busy}
                      aria-current={step === index ? "step" : undefined}
                      onClick={() => goStep(index)}
                    >
                      <span>{index + 1}</span>
                      {label}
                    </button>
                  </li>
                ))}
              </ol>
              <h3 ref={stepTitle} tabIndex={-1} className="rsw-step-title">
                {steps[step]}
              </h3>
              <p className="rsw-step-help">
                {
                  [
                    "Name this wipe and choose what will be removed. A preset fills in common file patterns for you.",
                    "Keep your current settings or choose the map players will join after the wipe.",
                    "Choose a date or monthly repeat. Configure backup, startup, and player safeguards.",
                    "Check the plan. Save it for later, or preview the files before wiping now.",
                  ][step]
                }
              </p>
              <form ref={form} onSubmit={save} noValidate>
                <fieldset disabled={busy}>
                  <div data-step="0" hidden={step !== 0}>
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
                    </div>
                    <WipeOptions
                      base={base}
                      draft={draft}
                      setDraft={setDraft}
                      overview={overview.data}
                      action={action}
                      section="scope"
                    />
                    <h3>Files to remove</h3>
                    <p>
                      Choose the files that make up your wipe. Map files are
                      only removed when listed here.
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
                        onChange={(e) =>
                          payload("identity_path", e.target.value)
                        }
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
                  </div>
                  <div data-step="1" hidden={step !== 1}>
                    <details className="rsw-disclosure">
                      <summary>
                        Server name & description{" "}
                        <small>
                          Optional - leave blank to keep current values
                        </small>
                      </summary>
                      <h3>Startup after the wipe</h3>
                      <p>
                        Leave optional fields blank to keep their current
                        values. %DAY%, %MONTH% and %YEAR% use the wipe date.
                      </p>
                      <Field label="Server name">
                        <input
                          maxLength={191}
                          value={draft.payload.name ?? ""}
                          placeholder={overview.data?.environment.HOSTNAME}
                          onChange={(e) =>
                            payload("name", e.target.value || null)
                          }
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
                    </details>
                    <div className="rsw-grid">
                      <Field
                        label="Map size"
                        help="Leave blank to keep the current size."
                      >
                        <input
                          type="number"
                          min={1000}
                          max={10000}
                          disabled={custom}
                          value={draft.payload.size ?? ""}
                          onChange={(e) =>
                            payload(
                              "size",
                              e.target.value === ""
                                ? null
                                : Number(e.target.value),
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
                              e.target.value === ""
                                ? null
                                : Number(e.target.value),
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
                      help="Use a fixed .map URL, or enable rotation to choose from your Map library. Clear this URL to use a procedural map."
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

                    <WipeOptions
                      base={base}
                      draft={draft}
                      setDraft={setDraft}
                      overview={overview.data}
                      action={action}
                      section="rotation"
                    />
                  </div>
                  <div data-step="2" hidden={step !== 2}>
                    <h3>When to wipe</h3>
                    <p>
                      Set a date for a scheduled wipe. To wipe now, leave the
                      date blank and continue to Review.
                    </p>
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
                      Or repeat on an ordinal weekday each month. A fifth
                      weekday is skipped in months where it does not exist.
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
                              {ordinals[Number(w) - 1]}{" "}
                              {weekdays[Number(d) - 1]} at {t}
                            </span>
                            <button
                              type="button"
                              aria-label={`Remove repeat ${rule}`}
                              onClick={() =>
                                setDraft({
                                  ...draft,
                                  repeat: draft.repeat.filter(
                                    (r) => r !== rule,
                                  ),
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

                    <WipeOptions
                      base={base}
                      draft={draft}
                      setDraft={setDraft}
                      overview={overview.data}
                      action={action}
                      section="safeguards"
                    />
                    <details className="rsw-disclosure">
                      <summary>
                        Player announcements & console commands{" "}
                        <small>
                          Optional - sent before scheduled wipes only
                        </small>
                      </summary>
                      <h3>Before-wipe console commands</h3>
                      <p>
                        Commands are sent at the selected number of minutes
                        before a scheduled wipe.
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
                                    j === i
                                      ? { ...c, command: e.target.value }
                                      : c,
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
                                      ? {
                                          ...c,
                                          minutes: Number(e.target.value),
                                        }
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
                                draft.payload.commands.filter(
                                  (_, j) => j !== i,
                                ),
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
                    </details>
                  </div>
                  <div data-step="3" hidden={step !== 3}>
                    <dl className="rsw-review">
                      <div>
                        <dt>Wipe</dt>
                        <dd>{draft.label || "Untitled wipe"}</dd>
                      </div>
                      <div>
                        <dt>Files</dt>
                        <dd>
                          {
                            draft.payload.files.split(/\r?\n/).filter(Boolean)
                              .length
                          }{" "}
                          paths or patterns.{" "}
                          {draft.payload.blueprints
                            ? "Blueprints will be deleted."
                            : "Blueprints kept unless explicitly selected."}
                        </dd>
                      </div>
                      <div>
                        <dt>Next map</dt>
                        <dd>
                          {draft.payload.random_level
                            ? `Saved map rotation (${overview.data?.maps.length ?? 0} maps)`
                            : draft.payload.level ||
                              (!draft.payload.random_seed &&
                              draft.payload.seed === null &&
                              draft.payload.size === null
                                ? "Keep current map settings"
                                : `Procedural map: seed ${draft.payload.random_seed ? "random" : (draft.payload.seed ?? "unchanged")}, size ${draft.payload.size ?? "unchanged"}`)}
                        </dd>
                      </div>
                      <div>
                        <dt>Timing</dt>
                        <dd>
                          {draft.repeat.length
                            ? draft.repeat
                                .map((rule) => {
                                  const [w, d, t] = rule.split(" ");
                                  return `${ordinals[Number(w) - 1]} ${weekdays[Number(d) - 1]} at ${t}`;
                                })
                                .join("; ")
                            : draft.time.replace("T", " at ") ||
                              "Run now after preview"}{" "}
                          ({draft.timezone})
                          {!draft.enabled && " - Schedule paused"}
                        </dd>
                      </div>
                      <div>
                        <dt>Protection</dt>
                        <dd>
                          {draft.payload.backup
                            ? "Full backup before deletion"
                            : "No pre-wipe backup"}
                          {draft.payload.wait_players
                            ? "; wait for an empty server"
                            : ""}
                        </dd>
                      </div>
                    </dl>
                    <p className="rsw-workflow-note">
                      A wipe stops the server, removes selected files, applies
                      startup changes, then starts the server. Previewing files
                      does not change the server.
                    </p>
                    <div className="rsw-form-actions">
                      <Button
                        type="submit"
                        isLoading={busy}
                        disabled={
                          !can("schedule") ||
                          (!draft.time && !draft.repeat.length)
                        }
                      >
                        Save schedule
                      </Button>
                      <Button
                        isSecondary
                        onClick={() =>
                          validateDraft() &&
                          void action(async () =>
                            setPreview(
                              (
                                await http.post<Preview>(
                                  `${base}/preview`,
                                  draft,
                                )
                              ).data,
                            ),
                          )
                        }
                      >
                        Preview files
                      </Button>
                    </div>
                    {!draft.time && !draft.repeat.length && (
                      <small>
                        To save a schedule, choose a date or add a repeat in
                        Timing & safeguards.
                      </small>
                    )}
                    {(active || overview.data?.maintenance) && (
                      <p role="status" className="rsw-notice">
                        Wiping now is unavailable while another server operation
                        is active.
                      </p>
                    )}
                    {draft.payload.random_level &&
                      !overview.data?.maps.length && (
                        <p role="status" className="rsw-notice">
                          Add a map in Map library before using rotation.
                        </p>
                      )}
                    {preview && (
                      <div className="rsw-preview">
                        <h3>
                          {preview.count}{" "}
                          {preview.count === 1
                            ? "entry matches"
                            : "entries match"}{" "}
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
                          disabled={
                            !confirmed ||
                            busy ||
                            !!active ||
                            !can("execute") ||
                            !!overview.data?.maintenance
                          }
                          onClick={() =>
                            void action(async () => {
                              await http.post(`${base}/run`, {
                                ...draft,
                                confirm: true,
                                request_uuid: requestId.current,
                              });
                              setShowEditor(false);
                            }, "Wipe queued. Open Activity & backups to follow its progress.")
                          }
                        >
                          Wipe now
                        </Button>
                        <small>
                          The server will stop and start. Scheduled
                          announcements are skipped for immediate wipes.
                        </small>
                      </div>
                    )}
                  </div>
                  <div className="rsw-wizard-footer">
                    <Button
                      isSecondary
                      disabled={step === 0 || busy}
                      onClick={() => goStep(step - 1)}
                    >
                      Back
                    </Button>
                    <small>
                      Step {step + 1} of {steps.length}
                    </small>
                    {step < 3 && (
                      <Button onClick={() => goStep(step + 1)}>
                        {step === 2
                          ? "Continue to review"
                          : `Continue to ${steps[step + 1].toLowerCase()}`}
                      </Button>
                    )}
                  </div>
                </fieldset>
              </form>
            </section>
          )}
        </div>
        <div hidden={view !== "maps"}>
          <section aria-labelledby="rsw-maps">
            <h2 id="rsw-maps">Custom map rotation</h2>
            <p>
              Rotation avoids the current map when another saved map is
              available.
            </p>
            <ul className="rsw-maps">
              {overview.data?.maps.map((map) => (
                <li key={map.id}>
                  <div>
                    <strong>{map.name}</strong>
                    <small>{map.url}</small>
                    <MapRules
                      map={map}
                      base={base}
                      disabled={busy || !can("schedule")}
                      action={action}
                    />
                  </div>
                  <Button
                    isSecondary
                    size="small"
                    color="red"
                    disabled={busy || !can("schedule")}
                    onClick={() => {
                      if (
                        window.confirm(
                          `Remove “${map.name}” from map rotation?`,
                        )
                      )
                        void action(() =>
                          http.delete(`${base}/maps/${map.id}`),
                        );
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
                  await http.post(`${base}/maps`, {
                    name: mapName,
                    url: mapUrl,
                  });
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
              <Button type="submit" disabled={busy || !can("schedule")}>
                Add map
              </Button>
            </form>
          </section>
        </div>
        <div hidden={view !== "schedules" || showEditor}>
          {overview.data?.calendar && (
            <section>
              <h2>Upcoming wipes</h2>
              <p>
                Dates use each schedule's timezone. Wipes less than 20 minutes
                apart are flagged for review.
              </p>
              {overview.data.calendar.events.length === 0 && (
                <p>No upcoming wipes.</p>
              )}
              <ul className="rsw-calendar">
                {overview.data.calendar.events.map((event) => (
                  <li key={`${event.wipe_id}-${event.at}`}>
                    <strong>{event.label}</strong>
                    <span>
                      {date(event.at, event.timezone)} · {event.timezone}
                    </span>
                    {event.conflict && (
                      <strong className="rsw-failed">
                        Overlaps another wipe
                      </strong>
                    )}
                  </li>
                ))}
              </ul>
              {overview.data.calendar.notes.map((note) => (
                <small key={note.wipe_id}>{note.message}</small>
              ))}
              {!!overview.data.calendar.skipped?.length && (
                <details>
                  <summary>Skipped dates and months</summary>
                  <ul>
                    {overview.data.calendar.skipped.map((skip) => (
                      <li key={`${skip.wipe_id}-${skip.local}-${skip.rule}`}>
                        {skip.label}: {skip.local} · {skip.timezone} ·{" "}
                        {skip.reason}
                      </li>
                    ))}
                  </ul>
                </details>
              )}
            </section>
          )}
        </div>
        {view === "settings" && can("configure") && (
          <SettingsPanel base={base} />
        )}
        <div hidden={view !== "history"}>
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
                          className={
                            run.status === "failed" ? "rsw-failed" : ""
                          }
                        >
                          {run.status}
                        </strong>
                        <small>{run.message}</small>
                        {run.result?.readiness && (
                          <small>
                            Startup:{" "}
                            {(
                              {
                                rust_ready: "Rust ready",
                                process_running: "Wings process running",
                                start_accepted: "Start signal accepted",
                                timed_out: "Readiness timed out",
                              } as Record<string, string>
                            )[run.result.readiness] ?? run.result.readiness}
                          </small>
                        )}
                        {run.result?.rotation_fallback && (
                          <small>
                            Map pool was too small for the recent-map exclusion;
                            fallback applied.
                          </small>
                        )}
                        {run.phase && (
                          <small>
                            Phase: {run.phase}
                            {run.created_by
                              ? ` · Requested by user #${run.created_by}`
                              : " · Scheduled/system run"}
                          </small>
                        )}
                        {run.backup_uuid && (
                          <small>Backup: {run.backup_uuid}</small>
                        )}
                        <div className="rsw-actions">
                          <a href={`${base}/runs/${run.id}/report`} download>
                            Download report
                          </a>
                          {run.backup_uuid && can("restore") && (
                            <Button
                              color="red"
                              size="small"
                              isSecondary
                              disabled={
                                busy || !!active || !!overview.data?.maintenance
                              }
                              onClick={() => {
                                if (
                                  window.confirm(
                                    "Restore the full pre-wipe backup? This replaces ALL current server files and restores previous startup values. The server will remain stopped.",
                                  )
                                )
                                  void action(async () => {
                                    const id = (restoreIds.current[run.id] ??=
                                      crypto.randomUUID());
                                    const response = await http.post(
                                      `${base}/runs/${run.id}/restore`,
                                      { confirm: true, request_uuid: id },
                                    );
                                    delete restoreIds.current[run.id];
                                    return response;
                                  }, "Restore queued.");
                              }}
                            >
                              Restore full backup
                            </Button>
                          )}
                        </div>
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
          {!!overview.data?.restores?.length && (
            <section>
              <h2>Restore history</h2>
              <ul>
                {overview.data.restores.map((restore) => (
                  <li key={restore.id}>
                    <strong>
                      Restore #{restore.id}: {restore.status}
                    </strong>
                    <p>{restore.message}</p>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </div>
      </div>
    </ServerContentBlock>
  );
}
