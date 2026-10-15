import { Button } from "@pterodactyl/sdk";
import type { Embed } from "./announcementTypes";
import type { ReactNode } from "react";

export function AnnouncementField({
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

export default function EmbedEditor({
  embed,
  index,
  change,
  remove,
}: {
  embed: Embed;
  index: number;
  change: (value: Embed) => void;
  remove: () => void;
}) {
  const suffix = ` · embed ${index + 1}`;
  return (
    <div className="rsw-embed-editor">
      <div className="rsw-heading">
        <h4>Embed {index + 1}</h4>
        <Button size="small" isSecondary onClick={remove}>
          Remove embed {index + 1}
        </Button>
      </div>
      <AnnouncementField label={`Title${suffix}`}>
        <input
          maxLength={256}
          value={embed.title ?? ""}
          onChange={(e) =>
            change({ ...embed, title: e.target.value || undefined })
          }
        />
      </AnnouncementField>
      <AnnouncementField label={`Description${suffix}`}>
        <textarea
          rows={4}
          maxLength={4096}
          value={embed.description ?? ""}
          onChange={(e) =>
            change({ ...embed, description: e.target.value || undefined })
          }
        />
      </AnnouncementField>
      <div className="rsw-actions">
        <label className="rsw-check">
          <input
            type="checkbox"
            checked={embed.color !== undefined}
            onChange={(e) =>
              change({
                ...embed,
                color: e.target.checked ? 5793266 : undefined,
              })
            }
          />
          Accent color
        </label>
        {embed.color !== undefined && (
          <AnnouncementField label={`Color${suffix}`}>
            <input
              type="color"
              value={`#${embed.color.toString(16).padStart(6, "0")}`}
              onChange={(e) =>
                change({
                  ...embed,
                  color: parseInt(e.target.value.slice(1), 16),
                })
              }
            />
          </AnnouncementField>
        )}
        <label className="rsw-check">
          <input
            type="checkbox"
            checked={!!embed.timestamp}
            onChange={(e) =>
              change({
                ...embed,
                timestamp: e.target.checked ? "{iso_time}" : undefined,
              })
            }
          />
          Include event timestamp
        </label>
      </div>
      <details className="rsw-disclosure">
        <summary>Links, images, author & footer</summary>
        <div className="rsw-grid">
          <AnnouncementField label={`Title link${suffix}`}>
            <input
              type="url"
              value={embed.url ?? ""}
              onChange={(e) =>
                change({ ...embed, url: e.target.value || undefined })
              }
            />
          </AnnouncementField>
          <AnnouncementField label={`Image URL${suffix}`}>
            <input
              type="url"
              value={embed.image?.url ?? ""}
              onChange={(e) =>
                change({
                  ...embed,
                  image: e.target.value ? { url: e.target.value } : undefined,
                })
              }
            />
          </AnnouncementField>
          <AnnouncementField label={`Thumbnail URL${suffix}`}>
            <input
              type="url"
              value={embed.thumbnail?.url ?? ""}
              onChange={(e) =>
                change({
                  ...embed,
                  thumbnail: e.target.value
                    ? { url: e.target.value }
                    : undefined,
                })
              }
            />
          </AnnouncementField>
          <AnnouncementField label={`Author name${suffix}`}>
            <input
              maxLength={256}
              value={embed.author?.name ?? ""}
              onChange={(e) =>
                change({
                  ...embed,
                  author: e.target.value
                    ? { ...embed.author, name: e.target.value }
                    : undefined,
                })
              }
            />
          </AnnouncementField>
          {embed.author && (
            <>
              <AnnouncementField label={`Author link${suffix}`}>
                <input
                  type="url"
                  value={embed.author.url ?? ""}
                  onChange={(e) =>
                    change({
                      ...embed,
                      author: {
                        ...embed.author!,
                        url: e.target.value || undefined,
                      },
                    })
                  }
                />
              </AnnouncementField>
              <AnnouncementField label={`Author icon URL${suffix}`}>
                <input
                  type="url"
                  value={embed.author.icon_url ?? ""}
                  onChange={(e) =>
                    change({
                      ...embed,
                      author: {
                        ...embed.author!,
                        icon_url: e.target.value || undefined,
                      },
                    })
                  }
                />
              </AnnouncementField>
            </>
          )}
          <AnnouncementField label={`Footer text${suffix}`}>
            <input
              maxLength={2048}
              value={embed.footer?.text ?? ""}
              onChange={(e) =>
                change({
                  ...embed,
                  footer: e.target.value
                    ? { ...embed.footer, text: e.target.value }
                    : undefined,
                })
              }
            />
          </AnnouncementField>
          {embed.footer && (
            <AnnouncementField label={`Footer icon URL${suffix}`}>
              <input
                type="url"
                value={embed.footer.icon_url ?? ""}
                onChange={(e) =>
                  change({
                    ...embed,
                    footer: {
                      ...embed.footer!,
                      icon_url: e.target.value || undefined,
                    },
                  })
                }
              />
            </AnnouncementField>
          )}
        </div>
      </details>
      <details className="rsw-disclosure">
        <summary>Embed fields ({embed.fields?.length ?? 0})</summary>
        {embed.fields?.map((field, fieldIndex) => (
          <div className="rsw-embed-field" key={fieldIndex}>
            <AnnouncementField label={`Field ${fieldIndex + 1} name${suffix}`}>
              <input
                maxLength={256}
                value={field.name}
                onChange={(e) =>
                  change({
                    ...embed,
                    fields: embed.fields!.map((item, i) =>
                      i === fieldIndex
                        ? { ...item, name: e.target.value }
                        : item,
                    ),
                  })
                }
              />
            </AnnouncementField>
            <AnnouncementField label={`Field ${fieldIndex + 1} value${suffix}`}>
              <textarea
                rows={2}
                maxLength={1024}
                value={field.value}
                onChange={(e) =>
                  change({
                    ...embed,
                    fields: embed.fields!.map((item, i) =>
                      i === fieldIndex
                        ? { ...item, value: e.target.value }
                        : item,
                    ),
                  })
                }
              />
            </AnnouncementField>
            <div className="rsw-actions">
              <label className="rsw-check">
                <input
                  type="checkbox"
                  checked={field.inline ?? false}
                  onChange={(e) =>
                    change({
                      ...embed,
                      fields: embed.fields!.map((item, i) =>
                        i === fieldIndex
                          ? { ...item, inline: e.target.checked }
                          : item,
                      ),
                    })
                  }
                />
                Inline field
              </label>
              <Button
                size="small"
                isSecondary
                onClick={() =>
                  change({
                    ...embed,
                    fields: embed.fields!.filter((_, i) => i !== fieldIndex),
                  })
                }
              >
                Remove field {fieldIndex + 1}
              </Button>
            </div>
          </div>
        ))}
        <Button
          size="small"
          isSecondary
          disabled={(embed.fields?.length ?? 0) >= 25}
          onClick={() =>
            change({
              ...embed,
              fields: [
                ...(embed.fields ?? []),
                { name: "", value: "", inline: false },
              ],
            })
          }
        >
          Add field
        </Button>
      </details>
    </div>
  );
}
