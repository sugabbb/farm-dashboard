import '@fontsource/roboto/latin-400.css';
import '@fontsource/roboto/latin-500.css';
import '@fontsource/roboto/latin-600.css';
import '@fontsource/roboto/latin-700.css';
import 'react-datepicker/dist/react-datepicker.css';
import 'react-toastify/dist/ReactToastify.css';
import '../css/app.scss';

import { createInertiaApp } from '@inertiajs/react';
import { ComponentType, StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

const pages = import.meta.glob<{ default: ComponentType<Record<string, unknown>> }>('./pages/**/*.tsx');

createInertiaApp({
  title: title => `${title} · Pondok Berkah Bersama`,
  resolve: async name => {
    const load = pages[`./pages/${name}.tsx`];
    if (!load) throw new Error(`Halaman Inertia tidak ditemukan: ${name}`);
    return (await load()).default;
  },
  setup({ el, App, props }) {
    createRoot(el).render(
      <StrictMode>
        <App {...props} />
      </StrictMode>,
    );
  },
  progress: { color: '#3a7afe', showSpinner: true },
});
