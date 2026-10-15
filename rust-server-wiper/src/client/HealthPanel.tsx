import { useEffect, useRef, useState } from "react";
import { Button, http, httpErrorToHuman } from "@pterodactyl/sdk";
import type { Draft } from "./types";

type Check = {
  id: string;
  label: string;
  status: "pass" | "fail" | "warning" | "pending";
  message: string;
};
type Health = { checked_at: string; probe_id: string; checks: Check[] };

export default function HealthPanel({
  base,
  draft,
  disabled,
}: {
  base: string;
  draft: Draft;
  disabled: boolean;
}) {
  const [health, setHealth] = useState<Health | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const version = useRef(0);
  useEffect(() => {
    version.current++;
    setHealth(null);
    setError("");
  }, [draft, base]);
  useEffect(() => {
    if (!health?.checks.some((check) => check.status === "pending")) return;
    const controller = new AbortController();
    const started = Date.now();
    let checking = false;
    const interval = window.setInterval(async () => {
      if (checking) return;
      checking = true;
      try {
        const response = await http.get<{ acknowledged: boolean }>(
          `${base}/health/probes/${health.probe_id}`,
          { signal: controller.signal },
        );
        if (controller.signal.aborted) return;
        if (response.data.acknowledged || Date.now() - started > 30000) {
          const acknowledged = response.data.acknowledged;
          setHealth(
            (previous) =>
              previous && {
                ...previous,
                checks: previous.checks.map((check) =>
                  check.id === "worker"
                    ? {
                        ...check,
                        status: acknowledged ? "pass" : "warning",
                        message: acknowledged
                          ? "The standard queue processed the probe."
                          : "No acknowledgement within 30 seconds. The worker may be stopped or busy. Check the standard queue and shared cache, then recheck.",
                      }
                    : check,
                ),
              },
          );
        }
      } catch {
        if (!controller.signal.aborted)
          setHealth(
            (previous) =>
              previous && {
                ...previous,
                checks: previous.checks.map((check) =>
                  check.id === "worker"
                    ? {
                        ...check,
                        status: "warning",
                        message:
                          "The worker acknowledgement could not be read. Check the connection and run the health check again.",
                      }
                    : check,
                ),
              },
          );
      } finally {
        checking = false;
      }
    }, 2000);
    return () => {
      controller.abort();
      window.clearInterval(interval);
    };
  }, [
    base,
    health?.probe_id,
    health?.checks.some((check) => check.status === "pending"),
  ]);
  return (
    <div className="rsw-health">
      <div className="rsw-heading">
        <div>
          <h3>Pre-wipe health check</h3>
          <p>
            Check this plan against the server without stopping it or changing
            files.
          </p>
        </div>
        <Button
          isSecondary
          isLoading={busy}
          disabled={disabled || busy}
          onClick={async () => {
            const current = version.current;
            setBusy(true);
            setError("");
            setHealth(null);
            try {
              const result = await http.post<Health>(`${base}/health`, draft);
              if (current === version.current) setHealth(result.data);
            } catch (e) {
              if (current === version.current) setError(httpErrorToHuman(e));
            } finally {
              setBusy(false);
            }
          }}
        >
          {health ? "Recheck health" : "Run health check"}
        </Button>
      </div>
      {error && (
        <p role="alert" className="rsw-error">
          {error}
        </p>
      )}
      {health && (
        <>
          <p role="status">
            {health.checks.some((check) => check.status === "fail")
              ? "Some checks need attention before wiping."
              : health.checks.some((check) => check.status !== "pass")
                ? "Review the warnings and pending checks below."
                : "All checks passed."}{" "}
            Checked {new Date(health.checked_at).toLocaleTimeString()}.
          </p>
          <ul className="rsw-health-checks">
            {health.checks.map((check) => (
              <li key={check.id}>
                <span className={`rsw-badge rsw-health-${check.status}`}>
                  {
                    {
                      pass: "Passed",
                      fail: "Needs attention",
                      warning: "Review",
                      pending: "Checking",
                    }[check.status]
                  }
                </span>
                <div>
                  <strong>{check.label}</strong>
                  <small>{check.message}</small>
                </div>
              </li>
            ))}
          </ul>
          <small>
            Results describe this moment. Execution checks run again at wipe
            time; a health check cannot guarantee a future backup or startup
            will succeed.
          </small>
        </>
      )}
    </div>
  );
}
