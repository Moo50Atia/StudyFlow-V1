import React, { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import Scene from "./Scene";

const rootEl = document.getElementById("root");
if (rootEl) {
  const root = createRoot(rootEl);
  root.render(
    <StrictMode>
      <Scene />
    </StrictMode>
  );
}
