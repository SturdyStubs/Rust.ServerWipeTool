import { definePterodactylExtension } from "@pterodactyl/sdk";
import "./styles.css";

export default definePterodactylExtension({
  setup({ screens }) {
    screens.register("wipes", () => import("./WipesScreen"), {
      visible: ({ server }) =>
        !!server &&
        (server.attributes.egg_tags.some(
          (tag) => tag.toLowerCase() === "rust",
        ) ||
          /rustdedicated|\brust\b/i.test(server.attributes.invocation) ||
          ("data" in (server.attributes.relationships?.variables ?? {}) &&
            (
              server.attributes.relationships?.variables as {
                data: { attributes: { env_variable: string } }[];
              }
            ).data.some((v) => v.attributes.env_variable === "WORLD_SEED"))),
    });
  },
});
