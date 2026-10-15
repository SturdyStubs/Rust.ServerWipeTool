import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Button, http, httpErrorToHuman } from "@pterodactyl/sdk";
import EmbedEditor, { AnnouncementField as Field } from "./EmbedEditor";
import {
  announcementEvents,
  emptyAnnouncements,
  messageStarters,
  type AnnouncementConfiguration,
  type AnnouncementEvent,
  type Embed,
  type MessagePreset,
} from "./announcementTypes";

type Delivery = {
  id: number;
  event: string | null;
  preset_name: string | null;
  destination_name: string | null;
  status: string;
  message: string | null;
  created_at: string;
};
type Data = {
  configuration: AnnouncementConfiguration;
  schedules: { id: number; label: string }[];
  deliveries: Delivery[];
};
type Preview = { content?: string; embeds?: Embed[] };

function RoleIdsInput({
  roles,
  change,
}: {
  roles: string[];
  change: (values: string[]) => void;
}) {
  const [text, setText] = useState(roles.join(", "));
  return (
    <input
      value={text}
      onChange={(e) => {
        setText(e.target.value);
        change([...new Set(e.target.value.split(/[,\s]+/).filter(Boolean))]);
      }}
    />
  );
}

function AnnouncementEditor({
  initial,
  base,
  schedules,
  onSaved,
}: {
  initial: AnnouncementConfiguration;
  base: string;
  schedules: Data["schedules"];
  onSaved: () => void;
}) {
  const [config, setConfig] = useState<AnnouncementConfiguration>(() =>
    structuredClone(initial),
  );
  const [panel, setPanel] = useState(
    initial.webhooks.length ? "rules" : "webhooks",
  );
  const [presetOpen, setPresetOpen] = useState<string | null>(null);
  const [ruleOpen, setRuleOpen] = useState<string | null>(null);
  const [starter, setStarter] =
    useState<keyof typeof messageStarters>("reminder");
  const [preview, setPreview] = useState<{
    id: string;
    payload: Preview;
  } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [saved, setSaved] = useState(JSON.stringify(initial));
  const [probe, setProbe] = useState<{ id: string; started: number } | null>(
    null,
  );
  const [worker, setWorker] = useState("");
  useEffect(() => {
    setPreview(null);
  }, [config]);
  useEffect(() => {
    if (!probe) return;
    const controller = new AbortController();
    let checking = false;
    const interval = window.setInterval(async () => {
      if (checking) return;
      checking = true;
      try {
        const response = await http.get<{ acknowledged: boolean }>(
          `${base}/announcements/probes/${probe.id}`,
          { signal: controller.signal },
        );
        if (controller.signal.aborted) return;
        if (response.data.acknowledged) {
          setWorker("The announcement delivery worker responded.");
          setProbe(null);
        } else if (Date.now() - probe.started >= 30000) {
          setWorker(
            "No delivery worker responded within 30 seconds. Start a separate worker for the rust-wiper-notifications queue, then check again.",
          );
          setProbe(null);
        }
      } catch {
        if (!controller.signal.aborted) {
          setWorker(
            "Could not read the worker response. Check your connection and try again.",
          );
          setProbe(null);
        }
      } finally {
        checking = false;
      }
    }, 2000);
    return () => {
      controller.abort();
      window.clearInterval(interval);
    };
  }, [probe, base]);
  function presetChange(id: string, patch: Partial<MessagePreset>) {
    setConfig((current) => ({
      ...current,
      presets: current.presets.map((preset) =>
        preset.id === id ? { ...preset, ...patch } : preset,
      ),
    }));
  }
  return (
    <form
      noValidate
      onSubmit={async (event) => {
        event.preventDefault();
        setBusy(true);
        setError("");
        setNotice("");
        try {
          const result = await http.put<{
            configuration: AnnouncementConfiguration;
          }>(`${base}/announcements`, config);
          setConfig(result.data.configuration);
          setSaved(JSON.stringify(result.data.configuration));
          onSaved();
          setNotice(
            "Announcements saved. Webhook URL fields are cleared; saved destinations remain connected.",
          );
        } catch (e) {
          setError(httpErrorToHuman(e));
        } finally {
          setBusy(false);
        }
      }}
    >
      {error && (
        <p role="alert" className="rsw-error">
          {error}
        </p>
      )}
      {notice && saved === JSON.stringify(config) && (
        <p role="status" className="rsw-notice">
          {notice}
        </p>
      )}
      <fieldset disabled={busy}>
        <div className="rsw-announcement-toolbar">
          <label className="rsw-check">
            <input
              type="checkbox"
              checked={config.enabled}
              onChange={(e) =>
                setConfig({ ...config, enabled: e.target.checked })
              }
            />
            Enable announcements
          </label>
          <Button
            size="small"
            isSecondary
            disabled={!!probe}
            onClick={async () => {
              setBusy(true);
              setWorker("Checking the delivery worker…");
              try {
                const result = await http.post<{
                  probe_id: string;
                  asynchronous: boolean;
                }>(`${base}/announcements/probe`);
                if (!result.data.asynchronous) {
                  setWorker(
                    "A separate worker cannot be verified with a synchronous queue or temporary cache. Configure an asynchronous queue and shared cache.",
                  );
                  return;
                }
                setProbe({ id: result.data.probe_id, started: Date.now() });
              } catch (e) {
                setWorker(httpErrorToHuman(e));
              } finally {
                setBusy(false);
              }
            }}
          >
            Check delivery worker
          </Button>
        </div>
        {worker && (
          <p role="status" className="rsw-notice">
            {worker}
          </p>
        )}
        {!config.enabled && (
          <p className="rsw-notice">
            Announcements are paused. Save to apply this to queued and future
            messages.
          </p>
        )}
        <nav className="rsw-announcement-nav" aria-label="Announcement setup">
          {[
            ["webhooks", `Webhooks (${config.webhooks.length})`],
            ["presets", `Message presets (${config.presets.length})`],
            ["rules", `Announcement rules (${config.rules.length})`],
          ].map(([id, title]) => (
            <button
              type="button"
              className="rsw-nav-item"
              key={id}
              aria-current={panel === id ? "page" : undefined}
              onClick={() => setPanel(id)}
            >
              {title}
            </button>
          ))}
        </nav>
        <div hidden={panel !== "webhooks"}>
          <h3>Webhook destinations</h3>
          <p>
            Add a named webhook for each Discord channel. Blank URL fields keep
            existing secrets.
          </p>
          {!config.webhooks.length && (
            <p className="rsw-empty">
              Add your first webhook, then create a message preset and a rule to
              connect them.
            </p>
          )}
          {config.webhooks.map((hook, index) => {
            const used = config.rules.some((rule) =>
              rule.webhook_ids.includes(hook.id),
            );
            return (
              <div className="rsw-webhook-row" key={hook.id}>
                <div className="rsw-grid">
                  <Field label={`Webhook name ${index + 1}`}>
                    <input
                      required
                      maxLength={191}
                      value={hook.name}
                      onChange={(e) =>
                        setConfig({
                          ...config,
                          webhooks: config.webhooks.map((item) =>
                            item.id === hook.id
                              ? { ...item, name: e.target.value }
                              : item,
                          ),
                        })
                      }
                      placeholder="Community announcements"
                    />
                  </Field>
                  <Field
                    label={`Webhook URL ${index + 1}`}
                    help={
                      hook.configured
                        ? "URL saved. Leave blank to keep it, or enter a replacement."
                        : "Create a webhook in the destination Discord channel and paste its URL."
                    }
                  >
                    <input
                      type="password"
                      autoComplete="new-password"
                      value={hook.url}
                      onChange={(e) =>
                        setConfig({
                          ...config,
                          webhooks: config.webhooks.map((item) =>
                            item.id === hook.id
                              ? { ...item, url: e.target.value }
                              : item,
                          ),
                        })
                      }
                      placeholder={
                        hook.configured
                          ? "Webhook saved"
                          : "https://discord.com/api/webhooks/…"
                      }
                    />
                  </Field>
                </div>
                <div className="rsw-actions">
                  <label className="rsw-check">
                    <input
                      type="checkbox"
                      checked={hook.enabled}
                      onChange={(e) =>
                        setConfig({
                          ...config,
                          webhooks: config.webhooks.map((item) =>
                            item.id === hook.id
                              ? { ...item, enabled: e.target.checked }
                              : item,
                          ),
                        })
                      }
                    />
                    Enable {hook.name || `webhook ${index + 1}`}
                  </label>
                  <Button
                    size="small"
                    isSecondary
                    disabled={used}
                    onClick={() =>
                      setConfig({
                        ...config,
                        webhooks: config.webhooks.filter(
                          (item) => item.id !== hook.id,
                        ),
                      })
                    }
                  >
                    Remove webhook {index + 1}
                  </Button>
                  {used && (
                    <small>
                      Used by a rule. Remove it from those rules before
                      deleting.
                    </small>
                  )}
                </div>
              </div>
            );
          })}
          <Button
            isSecondary
            onClick={() =>
              setConfig({
                ...config,
                webhooks: [
                  ...config.webhooks,
                  { id: crypto.randomUUID(), name: "", url: "", enabled: true },
                ],
              })
            }
          >
            Add webhook
          </Button>
        </div>
        <div hidden={panel !== "presets"}>
          <h3>Reusable message presets</h3>
          <p>
            Write text, build embeds, or use both. A preset can be reused by any
            number of rules.
          </p>
          <details className="rsw-disclosure">
            <summary>Available message tokens</summary>
            <p>
              <code>
                {
                  "{server}, {label}, {event}, {message}, {minutes}, {time}, {epoch}, {timestamp}, {relative}, {iso_time}"
                }
              </code>
            </p>
            <small>
              Epoch and Discord timestamp tokens refer to the wipe's scheduled
              time. The embed timestamp uses the event time. Minutes is
              available for reminders.
            </small>
          </details>
          <div className="rsw-actions">
            <Field label="Start a message from">
              <select
                value={starter}
                onChange={(e) =>
                  setStarter(e.target.value as keyof typeof messageStarters)
                }
              >
                {Object.entries(messageStarters).map(([key, value]) => (
                  <option key={key} value={key}>
                    {value.name}
                  </option>
                ))}
              </select>
            </Field>
            <Button
              isSecondary
              onClick={() => {
                const item = messageStarters[starter];
                const id = crypto.randomUUID();
                setConfig({
                  ...config,
                  presets: [
                    ...config.presets,
                    {
                      id,
                      name: item.name,
                      content: item.content,
                      embeds:
                        starter === "custom"
                          ? []
                          : [
                              {
                                title: item.title,
                                description: item.description,
                                color: item.color,
                              },
                            ],
                      role_ids: [],
                      mention_everyone: false,
                    },
                  ],
                });
                setPresetOpen(id);
              }}
            >
              Add message preset
            </Button>
          </div>
          {!config.presets.length && (
            <p className="rsw-empty">
              Choose a starter above, or start with a custom message. Adding a
              preset does not send anything.
            </p>
          )}
          {config.presets.map((preset) => {
            const used = config.rules.some(
              (rule) => rule.preset_id === preset.id,
            );
            return (
              <details
                className="rsw-announcement-item"
                key={preset.id}
                open={presetOpen === preset.id}
              >
                <summary
                  onClick={(e) => {
                    e.preventDefault();
                    setPresetOpen(presetOpen === preset.id ? null : preset.id);
                  }}
                >
                  {preset.name || "Untitled message"}
                  <small>
                    {preset.content ? "Message text" : "No message text"} ·{" "}
                    {preset.embeds.length} embeds
                  </small>
                </summary>
                <Field label="Preset name">
                  <input
                    required
                    maxLength={191}
                    value={preset.name}
                    onChange={(e) =>
                      presetChange(preset.id, { name: e.target.value })
                    }
                  />
                </Field>
                <Field
                  label="Message content"
                  help="Optional when an embed has content. Discord supports Markdown and timestamp tokens."
                >
                  <textarea
                    rows={4}
                    maxLength={2000}
                    value={preset.content ?? ""}
                    onChange={(e) =>
                      presetChange(preset.id, { content: e.target.value })
                    }
                  />
                </Field>
                <small>
                  {preset.content?.length ?? 0} / 2,000 message characters. Up
                  to 10 embeds; 6,000 characters across all embed text.
                </small>
                {preset.embeds.map((embed, index) => (
                  <EmbedEditor
                    key={index}
                    index={index}
                    embed={embed}
                    change={(value) =>
                      presetChange(preset.id, {
                        embeds: preset.embeds.map((item, i) =>
                          i === index ? value : item,
                        ),
                      })
                    }
                    remove={() =>
                      presetChange(preset.id, {
                        embeds: preset.embeds.filter((_, i) => i !== index),
                      })
                    }
                  />
                ))}
                <Button
                  size="small"
                  isSecondary
                  disabled={preset.embeds.length >= 10}
                  onClick={() =>
                    presetChange(preset.id, {
                      embeds: [
                        ...preset.embeds,
                        { title: "", description: "" },
                      ],
                    })
                  }
                >
                  Add embed
                </Button>
                <details className="rsw-disclosure">
                  <summary>Role mentions & notifications</summary>
                  <Field
                    label="Role IDs to notify"
                    help="Separate IDs with commas or spaces. These roles are added to the message; other role and user mentions are disabled."
                  >
                    <RoleIdsInput
                      key={`${preset.id}:${config.revision}`}
                      roles={preset.role_ids}
                      change={(role_ids) =>
                        presetChange(preset.id, { role_ids })
                      }
                    />
                  </Field>
                  <label className="rsw-check">
                    <input
                      type="checkbox"
                      checked={preset.mention_everyone}
                      onChange={(e) =>
                        presetChange(preset.id, {
                          mention_everyone: e.target.checked,
                        })
                      }
                    />
                    Allow @everyone and @here when written in this message
                  </label>
                </details>
                <div className="rsw-actions">
                  <Button
                    size="small"
                    isSecondary
                    onClick={async () => {
                      setBusy(true);
                      setError("");
                      try {
                        const result = await http.post<{ payload: Preview }>(
                          `${base}/announcements/preview`,
                          { preset, event: "before", minutes: 30 },
                        );
                        setPreview({
                          id: preset.id,
                          payload: result.data.payload,
                        });
                      } catch (e) {
                        setError(httpErrorToHuman(e));
                      } finally {
                        setBusy(false);
                      }
                    }}
                  >
                    Preview message
                  </Button>
                  <Button
                    size="small"
                    isSecondary
                    disabled={used}
                    onClick={() =>
                      setConfig({
                        ...config,
                        presets: config.presets.filter(
                          (item) => item.id !== preset.id,
                        ),
                      })
                    }
                  >
                    Delete message preset
                  </Button>
                  {used && (
                    <small>
                      Used by a rule. Choose another preset there before
                      deleting.
                    </small>
                  )}
                </div>
                {preview?.id === preset.id && (
                  <div
                    className="rsw-message-preview"
                    aria-label="Message preview"
                  >
                    <h4>Preview · nothing is sent</h4>
                    <small>
                      Example values. Discord applies Markdown and timestamp
                      formatting.
                    </small>
                    {preview.payload.content && (
                      <p>{preview.payload.content}</p>
                    )}
                    {preview.payload.embeds?.map((embed, index) => (
                      <div
                        className="rsw-discord-embed"
                        key={index}
                        style={{
                          borderColor:
                            embed.color === undefined
                              ? undefined
                              : `#${embed.color.toString(16).padStart(6, "0")}`,
                        }}
                      >
                        {embed.author && <small>{embed.author.name}</small>}
                        {embed.title && <h4>{embed.title}</h4>}
                        {embed.description && <p>{embed.description}</p>}
                        {embed.fields?.map((field, fieldIndex) => (
                          <div key={fieldIndex}>
                            <strong>{field.name}</strong>
                            <p>{field.value}</p>
                          </div>
                        ))}
                        {embed.image && <small>Image: {embed.image.url}</small>}
                        {embed.thumbnail && (
                          <small>Thumbnail: {embed.thumbnail.url}</small>
                        )}
                        {embed.footer && <small>{embed.footer.text}</small>}
                        {embed.timestamp && <small>{embed.timestamp}</small>}
                      </div>
                    ))}
                  </div>
                )}
              </details>
            );
          })}
        </div>
        <div hidden={panel !== "rules"}>
          <h3>When to announce</h3>
          <p>
            Connect an event to a message preset and one or more channels. Add
            separate rules for multiple reminders or messages at the same event.
          </p>
          {(!config.webhooks.length || !config.presets.length) && (
            <p className="rsw-notice">
              Add a webhook and a message preset first. Then create a rule to
              connect them.
            </p>
          )}
          {!config.rules.length && (
            <p className="rsw-empty">
              No announcement rules yet. For example, send reminders 60, 30, and
              5 minutes before a wipe, then announce shutdown and completion.
            </p>
          )}
          {config.rules.map((rule) => (
            <details
              className="rsw-announcement-item"
              key={rule.id}
              open={ruleOpen === rule.id}
            >
              <summary
                onClick={(e) => {
                  e.preventDefault();
                  setRuleOpen(ruleOpen === rule.id ? null : rule.id);
                }}
              >
                {rule.name || "Untitled rule"}
                <small>
                  {rule.enabled
                    ? rule.event === "before"
                      ? `${rule.minutes ?? "?"} minutes before the wipe`
                      : announcementEvents[rule.event]
                    : "Paused"}{" "}
                  ·{" "}
                  {config.presets.find((item) => item.id === rule.preset_id)
                    ?.name ?? "Choose a message"}{" "}
                  · {rule.webhook_ids.length} channels
                </small>
              </summary>
              <div className="rsw-grid">
                <Field label="Rule name">
                  <input
                    required
                    maxLength={191}
                    value={rule.name}
                    onChange={(e) =>
                      setConfig({
                        ...config,
                        rules: config.rules.map((item) =>
                          item.id === rule.id
                            ? { ...item, name: e.target.value }
                            : item,
                        ),
                      })
                    }
                  />
                </Field>
                <Field label="When to send">
                  <select
                    value={rule.event}
                    onChange={(e) =>
                      setConfig({
                        ...config,
                        rules: config.rules.map((item) =>
                          item.id === rule.id
                            ? {
                                ...item,
                                event: e.target.value as AnnouncementEvent,
                                minutes:
                                  e.target.value === "before" ? 30 : null,
                              }
                            : item,
                        ),
                      })
                    }
                  >
                    {Object.entries(announcementEvents).map(
                      ([event, label]) => (
                        <option key={event} value={event}>
                          {label}
                        </option>
                      ),
                    )}
                  </select>
                </Field>
                {rule.event === "before" && (
                  <Field
                    label="Minutes before wipe"
                    help="1–10,080 minutes (up to seven days). Reminders apply to scheduled wipes."
                  >
                    <input
                      type="number"
                      min={1}
                      max={10080}
                      required
                      value={rule.minutes ?? ""}
                      onChange={(e) =>
                        setConfig({
                          ...config,
                          rules: config.rules.map((item) =>
                            item.id === rule.id
                              ? {
                                  ...item,
                                  minutes: e.target.value
                                    ? Number(e.target.value)
                                    : null,
                                }
                              : item,
                          ),
                        })
                      }
                    />
                  </Field>
                )}
                <Field label="Message preset">
                  <select
                    value={rule.preset_id}
                    onChange={(e) =>
                      setConfig({
                        ...config,
                        rules: config.rules.map((item) =>
                          item.id === rule.id
                            ? { ...item, preset_id: e.target.value }
                            : item,
                        ),
                      })
                    }
                  >
                    <option value="">Choose a message preset</option>
                    {config.presets.map((preset) => (
                      <option key={preset.id} value={preset.id}>
                        {preset.name}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="Apply to">
                  <select
                    value={rule.wipe_id ?? ""}
                    onChange={(e) =>
                      setConfig({
                        ...config,
                        rules: config.rules.map((item) =>
                          item.id === rule.id
                            ? {
                                ...item,
                                wipe_id: e.target.value
                                  ? Number(e.target.value)
                                  : null,
                              }
                            : item,
                        ),
                      })
                    }
                  >
                    <option value="">All wipes on this server</option>
                    {schedules.map((schedule) => (
                      <option key={schedule.id} value={schedule.id}>
                        {schedule.label}
                      </option>
                    ))}
                  </select>
                </Field>
              </div>
              {rule.event === "wiped" && (
                <p>
                  Sent when Wings first reports the server running after
                  deletion. Rust may still be loading.
                </p>
              )}
              <h4>Send to channels</h4>
              <div className="rsw-destination-options">
                {config.webhooks.map((hook) => (
                  <label className="rsw-check" key={hook.id}>
                    <input
                      type="checkbox"
                      checked={rule.webhook_ids.includes(hook.id)}
                      onChange={(e) =>
                        setConfig({
                          ...config,
                          rules: config.rules.map((item) =>
                            item.id === rule.id
                              ? {
                                  ...item,
                                  webhook_ids: e.target.checked
                                    ? [...item.webhook_ids, hook.id]
                                    : item.webhook_ids.filter(
                                        (id) => id !== hook.id,
                                      ),
                                }
                              : item,
                          ),
                        })
                      }
                    />
                    {hook.name || "Unnamed webhook"}
                    {!hook.enabled && " (paused)"}
                  </label>
                ))}
              </div>
              {!rule.webhook_ids.length && (
                <small>Choose at least one destination.</small>
              )}
              <div className="rsw-actions">
                <label className="rsw-check">
                  <input
                    type="checkbox"
                    checked={rule.enabled}
                    onChange={(e) =>
                      setConfig({
                        ...config,
                        rules: config.rules.map((item) =>
                          item.id === rule.id
                            ? { ...item, enabled: e.target.checked }
                            : item,
                        ),
                      })
                    }
                  />
                  Enable this rule
                </label>
                <Button
                  size="small"
                  isSecondary
                  onClick={() =>
                    setConfig({
                      ...config,
                      rules: config.rules.filter((item) => item.id !== rule.id),
                    })
                  }
                >
                  Remove rule
                </Button>
              </div>
            </details>
          ))}
          <Button
            isSecondary
            disabled={!config.webhooks.length || !config.presets.length}
            onClick={() => {
              const id = crypto.randomUUID();
              setConfig({
                ...config,
                rules: [
                  ...config.rules,
                  {
                    id,
                    name: "Wipe reminder",
                    enabled: true,
                    event: "before",
                    minutes: 30,
                    preset_id: config.presets[0].id,
                    webhook_ids: [config.webhooks[0].id],
                    wipe_id: null,
                  },
                ],
              });
              setRuleOpen(id);
            }}
          >
            Add announcement rule
          </Button>
        </div>
        <div className="rsw-form-actions">
          <Button type="submit" isLoading={busy}>
            Save announcements
          </Button>
          <Button
            isSecondary
            onClick={async () => {
              setBusy(true);
              setError("");
              try {
                const result = await http.get<Data>(`${base}/announcements`);
                setConfig(result.data.configuration);
                onSaved();
              } catch (e) {
                setError(httpErrorToHuman(e));
              } finally {
                setBusy(false);
              }
            }}
          >
            Reload saved announcements
          </Button>
        </div>
        <small>
          All changes apply together when saved. Pausing or removing a rule or
          webhook also cancels its queued messages.
        </small>
      </fieldset>
    </form>
  );
}

export default function AnnouncementsPanel({ base }: { base: string }) {
  const query = useQuery({
    queryKey: ["rust-wiper-announcements", base],
    queryFn: async () => (await http.get<Data>(`${base}/announcements`)).data,
    refetchInterval: 10000,
  });
  return (
    <section aria-labelledby="rsw-announcement-title">
      <h2 id="rsw-announcement-title">Discord announcements</h2>
      <p>
        Build the message once, choose where it goes, then decide when to send
        it.
      </p>
      {query.isPending && <p>Loading announcements…</p>}
      {query.error && (
        <p role="alert" className="rsw-error">
          {httpErrorToHuman(query.error)}
        </p>
      )}
      {query.data && (
        <AnnouncementEditor
          initial={query.data.configuration ?? emptyAnnouncements}
          base={base}
          schedules={query.data.schedules ?? []}
          onSaved={() => {
            void query.refetch();
          }}
        />
      )}
      {!!query.data?.deliveries?.length && (
        <details className="rsw-disclosure">
          <summary>Recent announcement deliveries</summary>
          <ul className="rsw-calendar">
            {query.data.deliveries.map((delivery) => (
              <li key={delivery.id}>
                <strong>
                  {delivery.preset_name ?? "Legacy message"} →{" "}
                  {delivery.destination_name ?? "Discord"}
                </strong>
                <span>
                  {delivery.status} ·{" "}
                  {delivery.event
                    ? (announcementEvents[
                        delivery.event as AnnouncementEvent
                      ] ?? delivery.event)
                    : "Notification"}
                </span>
                <small>
                  {new Date(delivery.created_at).toLocaleString()}
                  {delivery.message ? ` · ${delivery.message}` : ""}
                </small>
              </li>
            ))}
          </ul>
        </details>
      )}
    </section>
  );
}
