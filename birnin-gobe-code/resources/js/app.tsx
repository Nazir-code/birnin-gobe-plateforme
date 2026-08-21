import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

createInertiaApp({
  resolve: (name) => {
    const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true }) as Record<string, { default: React.ComponentType }>;
    return pages[`./Pages/${name}.tsx`];
  },
  // Inertia's built-in top-of-page progress bar, restyled to match the brand
  // instead of the default blue. No extra dependency — this ships with @inertiajs/react.
  progress: { color: '#f2b515', showSpinner: false },
  setup({ el, App, props }) {
    createRoot(el).render(<App {...props} />);
  },
});
