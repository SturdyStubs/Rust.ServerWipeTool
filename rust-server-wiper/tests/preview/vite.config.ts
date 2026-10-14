import { defineConfig } from "vite";
import { fileURLToPath } from "node:url";
export default defineConfig({
  root: fileURLToPath(new URL(".", import.meta.url)),
  resolve: {
    alias: {
      "@pterodactyl/sdk": fileURLToPath(new URL("./sdk.tsx", import.meta.url)),
    },
  },
  server: { host: "127.0.0.1", port: 4174, strictPort: true },
});
