import { useState } from "react";
import { Button, http } from "@pterodactyl/sdk";
import type { WipeMap } from "./types";
export default function MapRules({
  map,
  base,
  disabled,
  action,
}: {
  map: WipeMap;
  base: string;
  disabled: boolean;
  action: (work: () => Promise<unknown>, message?: string) => Promise<void>;
}) {
  const [weight, setWeight] = useState(map.weight ?? 1);
  const [position, setPosition] = useState(map.position ?? 0);
  return (
    <details>
      <summary>Rotation weight & order</summary>
      <div className="rsw-map-rules">
        <label className="rsw-field">
          <span>Weight for {map.name}</span>
          <input
            type="number"
            min={1}
            max={1000}
            disabled={disabled}
            value={weight}
            onChange={(e) => setWeight(Number(e.target.value))}
          />
        </label>
        <label className="rsw-field">
          <span>Order for {map.name}</span>
          <input
            type="number"
            min={0}
            max={10000}
            disabled={disabled}
            value={position}
            onChange={(e) => setPosition(Number(e.target.value))}
          />
        </label>
        <Button
          size="small"
          isSecondary
          disabled={
            disabled ||
            weight < 1 ||
            weight > 1000 ||
            position < 0 ||
            position > 10000
          }
          onClick={() =>
            void action(
              () => http.put(`${base}/maps/${map.id}`, { weight, position }),
              "Rotation rules saved.",
            )
          }
        >
          Save rotation rules
        </Button>
      </div>
    </details>
  );
}
