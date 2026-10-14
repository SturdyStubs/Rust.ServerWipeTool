import { createRoot } from "react-dom/client";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import WipesScreen from "../../src/client/WipesScreen";
import "../../src/client/styles.css";
import "./shell.css";
createRoot(document.getElementById("root")!).render(
  <QueryClientProvider client={new QueryClient()}>
    <div className="fixture-label">Local interface test · simulated server</div>
    <WipesScreen />
  </QueryClientProvider>,
);
