import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Button, http, httpErrorToHuman } from "@pterodactyl/sdk";
type Config = {
  options: {
    rcon_port?: number;
    rcon_tls?: boolean;
    discord_events?: string[];
    discord_minutes?: number;
    discord_template?: string;
    discord_role?: string;
  };
  discord_configured: boolean;
  rcon_configured: boolean;
  notifications?: {
    event_key: string;
    status: string;
    message: string | null;
    created_at: string;
  }[];
};
function SettingsForm({ initial, base }: { initial: Config; base: string }) {
  const [options, setOptions] = useState(initial.options ?? {});
  const [webhook, setWebhook] = useState("");
  const [password, setPassword] = useState("");
  const [clearDiscord, setClearDiscord] = useState(false);
  const [clearRcon, setClearRcon] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  async function save() {
    setBusy(true);
    setError("");
    try {
      await http.put(`${base}/settings`, {
        options,
        discord_webhook: webhook || null,
        rcon_password: password || null,
        clear_discord: clearDiscord,
        clear_rcon: clearRcon,
      });
      setWebhook("");
      setPassword("");
      setMessage("Settings saved. Secret fields are cleared from this form.");
    } catch (e) {
      setError(httpErrorToHuman(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        void save();
      }}
    >
      <fieldset disabled={busy}>
        <p>
          Blank secret fields keep saved values. Passwords and webhook URLs are
          encrypted and never returned to this form.
        </p>
        <h3>Discord announcements</h3>
        <div className="rsw-grid">
          <label className="rsw-field">
            <span>Discord webhook URL</span>
            <input
              type="password"
              autoComplete="new-password"
              value={webhook}
              onChange={(e) => setWebhook(e.target.value)}
              placeholder={
                initial.discord_configured
                  ? "Webhook saved"
                  : "https://discord.com/api/webhooks/…"
              }
            />
          </label>
          <label className="rsw-field">
            <span>Optional Discord role ID</span>
            <input
              value={options.discord_role ?? ""}
              onChange={(e) =>
                setOptions({
                  ...options,
                  discord_role: e.target.value || undefined,
                })
              }
            />
          </label>
          <label className="rsw-field">
            <span>Announcement minutes before wipe</span>
            <input
              type="number"
              min={1}
              max={1440}
              value={options.discord_minutes ?? 30}
              onChange={(e) =>
                setOptions({
                  ...options,
                  discord_minutes: Number(e.target.value),
                })
              }
            />
          </label>
          <label className="rsw-field">
            <span>Notification template</span>
            <input
              maxLength={1500}
              value={options.discord_template ?? "{label}: {event}. {message}"}
              onChange={(e) =>
                setOptions({ ...options, discord_template: e.target.value })
              }
            />
            <small>
              Tokens: {"{label}, {event}, {message}, {time}, {server}"}
            </small>
          </label>
        </div>
        <div className="rsw-actions">
          {["upcoming", "completed", "ready", "failed"].map((event) => (
            <label className="rsw-check" key={event}>
              <input
                type="checkbox"
                checked={(
                  options.discord_events ?? [
                    "upcoming",
                    "completed",
                    "ready",
                    "failed",
                  ]
                ).includes(event)}
                onChange={(e) =>
                  setOptions({
                    ...options,
                    discord_events: e.target.checked
                      ? [
                          ...new Set([
                            ...(options.discord_events ?? [
                              "upcoming",
                              "completed",
                              "ready",
                              "failed",
                            ]),
                            event,
                          ]),
                        ]
                      : (
                          options.discord_events ?? [
                            "upcoming",
                            "completed",
                            "ready",
                            "failed",
                          ]
                        ).filter((v) => v !== event),
                  })
                }
              />
              {event}
            </label>
          ))}
        </div>
        <label className="rsw-check">
          <input
            type="checkbox"
            checked={clearDiscord}
            onChange={(e) => setClearDiscord(e.target.checked)}
          />
          Remove saved Discord webhook
        </label>
        <h3>Rust WebRCON</h3>
        <p>
          The host comes from this server's allocation or node. Leave the
          password blank to use the egg's RCON_PASS or RCON_PASSWORD value when
          no override is saved.
        </p>
        <div className="rsw-grid">
          <label className="rsw-field">
            <span>RCON port</span>
            <input
              type="number"
              min={1}
              max={65535}
              value={options.rcon_port ?? ""}
              onChange={(e) =>
                setOptions({
                  ...options,
                  rcon_port: e.target.value
                    ? Number(e.target.value)
                    : undefined,
                })
              }
              placeholder="Use the egg RCON_PORT"
            />
          </label>
          <label className="rsw-field">
            <span>RCON password override</span>
            <input
              type="password"
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder={
                initial.rcon_configured
                  ? "Override saved"
                  : "Use the egg password"
              }
            />
          </label>
        </div>
        <label className="rsw-check">
          <input
            type="checkbox"
            checked={options.rcon_tls ?? false}
            onChange={(e) =>
              setOptions({ ...options, rcon_tls: e.target.checked })
            }
          />
          Use TLS for an RCON proxy
        </label>
        <label className="rsw-check">
          <input
            type="checkbox"
            checked={clearRcon}
            onChange={(e) => setClearRcon(e.target.checked)}
          />
          Remove saved RCON password override
        </label>
        <div className="rsw-actions">
          <Button type="submit" isLoading={busy}>
            Save settings
          </Button>
          <Button
            isSecondary
            onClick={async () => {
              setBusy(true);
              setError("");
              try {
                const result = await http.post<{ players: number }>(
                  `${base}/settings/probe`,
                );
                setMessage(
                  `Saved configuration responds: ${result.data.players} players online.`,
                );
              } catch (e) {
                setError(httpErrorToHuman(e));
              } finally {
                setBusy(false);
              }
            }}
          >
            Check saved RCON configuration
          </Button>
        </div>
        {message && <p role="status">{message}</p>}
        {error && (
          <p role="alert" className="rsw-error">
            {error}
          </p>
        )}
      </fieldset>
    </form>
  );
}
export default function SettingsPanel({ base }: { base: string }) {
  const query = useQuery({
    queryKey: ["rust-wiper-settings", base],
    queryFn: async () => (await http.get<Config>(`${base}/settings`)).data,
    refetchInterval: 15000,
  });
  return (
    <section>
      <h2>Discord notifications and WebRCON settings</h2>
      <p>
        Connect Discord for wipe announcements and WebRCON for player counts and
        Rust startup checks.
      </p>
      {query.isPending && <p>Loading settings…</p>}
      {query.error && <p role="alert">{httpErrorToHuman(query.error)}</p>}
      {query.data && <SettingsForm initial={query.data} base={base} />}
      {!!query.data?.notifications?.length && (
        <>
          <h3>Recent notification deliveries</h3>
          <ul className="rsw-calendar">
            {query.data.notifications.map((notification) => (
              <li key={notification.event_key}>
                <strong>
                  {notification.event_key}: {notification.status}
                </strong>
                <small>
                  {notification.created_at} {notification.message}
                </small>
              </li>
            ))}
          </ul>
        </>
      )}
    </section>
  );
}
