import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  Button,
  PageContentBlock,
  http,
  httpErrorToHuman,
} from "@pterodactyl/sdk";
type Data = {
  servers: { id: number; name: string; identity: { path: string | null } }[];
  presets: { id: number; name: string; server_id: number }[];
  runs: {
    id: number;
    server_id: number;
    label: string;
    status: string;
    message: string;
  }[];
  has_more: boolean;
  page: number;
};
type Plan = {
  server_id: number;
  name?: string;
  count?: number;
  files?: string[];
  error: string | null;
  next_run_at?: string;
  data?: { next_run_at: string; payload: { identity_path: string } };
};
export default function AdminScreen() {
  const base = "/api/admin/extensions/rust-server-wiper";
  const [page, setPage] = useState(1);
  const overview = useQuery({
    queryKey: ["rust-wiper-admin", page],
    queryFn: async () =>
      (await http.get<Data>(base, { params: { page } })).data,
    refetchInterval: 10000,
  });
  const [selected, setSelected] = useState<number[]>([]);
  const [preset, setPreset] = useState("");
  const [time, setTime] = useState("");
  const [timezone, setTimezone] = useState("UTC");
  const [stagger, setStagger] = useState(10);
  const [fallback, setFallback] = useState(false);
  const [plan, setPlan] = useState<Plan[] | null>(null);
  const [confirm, setConfirm] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [batch, setBatch] = useState(crypto.randomUUID());
  useEffect(() => {
    setPlan(null);
    setConfirm(false);
    setBatch(crypto.randomUUID());
  }, [selected, preset, time, timezone, stagger, fallback]);
  const input = {
    server_ids: selected,
    preset_id: Number(preset),
    time,
    timezone,
    stagger_minutes: stagger,
    use_preset_identity: fallback,
  };
  async function request(save: boolean) {
    setBusy(true);
    setError("");
    try {
      const result = await http.post<{ servers: Plan[] }>(
        `${base}/bulk${save ? "" : "/preview"}`,
        { ...input, ...(save ? { confirm: true, batch_uuid: batch } : {}) },
      );
      setPlan(result.data.servers);
      if (save) {
        setConfirm(false);
        setMessage("Batch processed. Each server result is listed below.");
        await overview.refetch();
      }
    } catch (e) {
      setError(httpErrorToHuman(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <PageContentBlock>
      <div className="rsw-page">
        <h1>Rust wipe management</h1>
        <p>
          Apply a saved server preset to selected Rust servers and stagger their
          one-time schedules.
        </p>
        {(error || overview.error) && (
          <p role="alert" className="rsw-error">
            {error || httpErrorToHuman(overview.error)}
          </p>
        )}
        {message && <p role="status">{message}</p>}
        <section>
          <h2>Select servers</h2>
          {overview.isPending && <p>Loading servers…</p>}
          <ul className="rsw-calendar">
            {overview.data?.servers.map((server) => (
              <li key={server.id}>
                <label className="rsw-check">
                  <input
                    type="checkbox"
                    disabled={busy}
                    checked={selected.includes(server.id)}
                    onChange={(e) =>
                      setSelected(
                        e.target.checked
                          ? [...selected, server.id]
                          : selected.filter((id) => id !== server.id),
                      )
                    }
                  />
                  {server.name} · #{server.id}
                </label>
                <small>
                  {server.identity.path ?? "Identity needs manual review"}
                </small>
              </li>
            ))}
          </ul>
          <div className="rsw-actions">
            <Button
              size="small"
              isSecondary
              disabled={page === 1 || busy}
              onClick={() => setPage(page - 1)}
            >
              Previous page
            </Button>
            <span>
              Page {page} · {selected.length} selected
            </span>
            <Button
              size="small"
              isSecondary
              disabled={!overview.data?.has_more || busy}
              onClick={() => setPage(page + 1)}
            >
              Next page
            </Button>
          </div>
        </section>
        <section>
          <h2>Batch schedule</h2>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              void request(false);
            }}
          >
            <fieldset disabled={busy}>
              <div className="rsw-grid">
                <label className="rsw-field">
                  <span>Saved preset</span>
                  <select
                    required
                    value={preset}
                    onChange={(e) => setPreset(e.target.value)}
                  >
                    <option value="">Choose a saved preset</option>
                    {overview.data?.presets.map((p) => (
                      <option key={p.id} value={p.id}>
                        {p.name} · server #{p.server_id}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="rsw-field">
                  <span>Timezone</span>
                  <input
                    required
                    value={timezone}
                    onChange={(e) => setTimezone(e.target.value)}
                  />
                </label>
                <label className="rsw-field">
                  <span>First wipe date and time</span>
                  <input
                    required
                    type="datetime-local"
                    value={time}
                    onChange={(e) => setTime(e.target.value)}
                  />
                </label>
                <label className="rsw-field">
                  <span>Minutes between servers</span>
                  <input
                    required
                    type="number"
                    min={0}
                    max={1440}
                    value={stagger}
                    onChange={(e) => setStagger(Number(e.target.value))}
                  />
                </label>
              </div>
              <label className="rsw-check">
                <input
                  type="checkbox"
                  checked={fallback}
                  onChange={(e) => setFallback(e.target.checked)}
                />
                Use the preset's identity directory when automatic detection
                fails
              </label>
              <Button
                type="submit"
                disabled={!selected.length || selected.length > 50}
              >
                Preview batch
              </Button>
            </fieldset>
          </form>
          {plan && (
            <>
              <h3>Server results</h3>
              <ul className="rsw-calendar">
                {plan.map((row) => (
                  <li key={row.server_id}>
                    <strong>{row.name ?? `Server #${row.server_id}`}</strong>
                    {row.error ? (
                      <p className="rsw-failed">{row.error}</p>
                    ) : (
                      <>
                        <p>
                          {row.count ?? "Schedule saved"}
                          {row.count !== undefined
                            ? " file entries match"
                            : ""}{" "}
                          · {row.data?.next_run_at ?? row.next_run_at}
                        </p>
                        {row.data && (
                          <small>
                            Identity: {row.data.payload.identity_path}
                          </small>
                        )}
                        <details>
                          <summary>View matching files</summary>
                          <ul>
                            {row.files?.map((file) => (
                              <li key={file}>
                                <code>{file}</code>
                              </li>
                            ))}
                          </ul>
                        </details>
                      </>
                    )}
                  </li>
                ))}
              </ul>
              <label className="rsw-check">
                <input
                  type="checkbox"
                  checked={confirm}
                  onChange={(e) => setConfirm(e.target.checked)}
                />
                I reviewed the selected servers, file matches and schedule
                times.
              </label>
              <Button
                disabled={!confirm || busy || plan.every((row) => row.error)}
                onClick={() => void request(true)}
              >
                Create batch schedules
              </Button>
            </>
          )}
        </section>
        <section>
          <h2>Recent runs across servers</h2>
          <ul className="rsw-calendar">
            {overview.data?.runs.map((run) => (
              <li key={run.id}>
                <strong>
                  {run.label} · server #{run.server_id}
                </strong>
                <span>
                  {run.status}: {run.message}
                </span>
              </li>
            ))}
          </ul>
        </section>
      </div>
    </PageContentBlock>
  );
}
