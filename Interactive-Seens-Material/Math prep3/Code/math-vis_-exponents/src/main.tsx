import {StrictMode} from 'react';
import {createRoot} from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App.tsx';
import './index.css';
import {configureTextBuilder} from 'troika-three-text';

// Bundle the scene font: the default Unicode resolver needs a remote CDN.
// A pending font request suspends every object in the Canvas, not just text.
configureTextBuilder({defaultFontURL: `${import.meta.env.BASE_URL}fonts/DejaVuSans.ttf`, useWorker: false});

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </StrictMode>,
);
