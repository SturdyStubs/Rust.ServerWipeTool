import { useEffect, useId, useRef, useState } from "react";
import { createPortal } from "react-dom";

export const discordTimestampStyles = [
  ["t", "Short time"],
  ["T", "Time with seconds"],
  ["d", "Short date"],
  ["D", "Long date"],
  ["f", "Long date & time"],
  ["F", "Full date & time"],
  ["s", "Short date & time"],
  ["S", "Short date & time with seconds"],
  ["R", "Relative time"],
] as const;

function example(date: Date, style: string) {
  if (style === "R") {
    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const unit =
      Math.abs(seconds) >= 86400
        ? "day"
        : Math.abs(seconds) >= 3600
          ? "hour"
          : "minute";
    return new Intl.RelativeTimeFormat(undefined, { numeric: "auto" }).format(
      Math.round(
        seconds / (unit === "day" ? 86400 : unit === "hour" ? 3600 : 60),
      ),
      unit,
    );
  }
  const formats: Record<string, Intl.DateTimeFormatOptions> = {
    t: { timeStyle: "short" },
    T: { timeStyle: "medium" },
    d: { dateStyle: "short" },
    D: { dateStyle: "long" },
    f: { dateStyle: "long", timeStyle: "short" },
    F: { dateStyle: "full", timeStyle: "short" },
    s: { dateStyle: "short", timeStyle: "short" },
    S: { dateStyle: "short", timeStyle: "medium" },
  };
  return new Intl.DateTimeFormat(undefined, formats[style]).format(date);
}

export default function DiscordTimestamp({
  iso,
  label,
}: {
  iso: string | null;
  label: string;
}) {
  const id = useId();
  const trigger = useRef<HTMLButtonElement>(null);
  const menu = useRef<HTMLDivElement>(null);
  const [position, setPosition] = useState<{
    top: number;
    left: number;
    width: number;
  } | null>(null);
  const [feedback, setFeedback] = useState("");
  const [error, setError] = useState(false);
  const date = iso ? new Date(iso) : null;
  const epoch =
    date && Number.isFinite(date.getTime())
      ? Math.floor(date.getTime() / 1000)
      : null;
  useEffect(() => {
    setPosition(null);
    setFeedback("");
  }, [iso]);
  useEffect(() => {
    if (!position) return;
    menu.current?.querySelector<HTMLButtonElement>("button")?.focus();
    const close = (event: Event) => {
      if (
        menu.current?.contains(event.target as Node) ||
        trigger.current?.contains(event.target as Node)
      )
        return;
      setPosition(null);
    };
    const resize = () => setPosition(null);
    document.addEventListener("pointerdown", close);
    document.addEventListener("scroll", close, true);
    window.addEventListener("resize", resize);
    return () => {
      document.removeEventListener("pointerdown", close);
      document.removeEventListener("scroll", close, true);
      window.removeEventListener("resize", resize);
    };
  }, [position]);
  if (!date || epoch === null) return null;
  async function copy(value: string) {
    try {
      if (navigator.clipboard?.writeText)
        await navigator.clipboard.writeText(value);
      else {
        const field = document.createElement("textarea");
        field.value = value;
        field.style.position = "fixed";
        field.style.opacity = "0";
        document.body.appendChild(field);
        field.select();
        let copied = false;
        try {
          copied = document.execCommand("copy");
        } finally {
          field.remove();
        }
        if (!copied) throw new Error("Clipboard unavailable");
      }
      setFeedback(`Copied ${value}`);
      setError(false);
      setPosition(null);
      trigger.current?.focus();
    } catch {
      setFeedback(`Clipboard unavailable. Copy manually: ${value}`);
      setError(true);
    }
  }
  return (
    <div className="rsw-timestamp">
      <button
        ref={trigger}
        type="button"
        className="rsw-epoch"
        aria-label={`Copy Discord timestamp for ${label}`}
        aria-haspopup="menu"
        aria-expanded={!!position}
        aria-controls={position ? id : undefined}
        onClick={() => {
          if (position) {
            setPosition(null);
            return;
          }
          const rect = trigger.current!.getBoundingClientRect();
          const width = Math.min(390, window.innerWidth - 32);
          setPosition({
            top: Math.max(
              16,
              Math.min(
                rect.bottom + 6,
                window.innerHeight - Math.min(420, window.innerHeight - 32),
              ),
            ),
            left: Math.max(
              16,
              Math.min(rect.left, window.innerWidth - width - 16),
            ),
            width,
          });
          setFeedback("");
        }}
      >
        Epoch <code>{epoch}</code>
        <svg
          width="12"
          height="12"
          viewBox="0 0 16 16"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
          aria-hidden="true"
        >
          <path d="m4 6 4 4 4-4" />
        </svg>
      </button>
      {feedback && <small role={error ? "alert" : "status"}>{feedback}</small>}
      {position &&
        createPortal(
          <div
            className="rsw-page rsw-timestamp-menu"
            id={id}
            ref={menu}
            role="menu"
            aria-label="Copy timestamp format"
            style={position}
            onKeyDown={(event) => {
              const buttons = Array.from(
                menu.current?.querySelectorAll<HTMLButtonElement>("button") ??
                  [],
              );
              const index = buttons.indexOf(
                document.activeElement as HTMLButtonElement,
              );
              if (event.key === "Escape") {
                event.preventDefault();
                setPosition(null);
                trigger.current?.focus();
              }
              if (["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) {
                event.preventDefault();
                buttons[
                  event.key === "Home"
                    ? 0
                    : event.key === "End"
                      ? buttons.length - 1
                      : (index +
                          (event.key === "ArrowUp" ? -1 : 1) +
                          buttons.length) %
                        buttons.length
                ]?.focus();
              }
              if (event.key === "Tab") setPosition(null);
            }}
          >
            <p>Choose a format to copy</p>
            <button
              role="menuitem"
              type="button"
              onClick={() => void copy(String(epoch))}
            >
              <span>Raw epoch seconds</span>
              <code>{epoch}</code>
            </button>
            <button
              role="menuitem"
              type="button"
              onClick={() => void copy(`<t:${epoch}>`)}
            >
              <span>Discord default</span>
              <code>{`<t:${epoch}>`}</code>
            </button>
            {discordTimestampStyles.map(([style, name]) => (
              <button
                key={style}
                role="menuitem"
                type="button"
                onClick={() => void copy(`<t:${epoch}:${style}>`)}
              >
                <span>
                  {name}
                  <small>{example(date, style)}</small>
                </span>
                <code>{`<t:${epoch}:${style}>`}</code>
              </button>
            ))}
            <small>
              Examples use your timezone. Discord adapts timestamps for each
              viewer.
            </small>
          </div>,
          document.body,
        )}
    </div>
  );
}
