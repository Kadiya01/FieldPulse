// Imported from vitest/config rather than vite so the `test` block below is
// type-checked; it is a superset of vite's own defineConfig.
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
  test: {
    // Node, not jsdom: the only test today mocks IndexedDB and exercises Web
    // Crypto, both of which Node provides natively. Standing up the jsdom
    // environment took 91% of a 62s run for three tests, so component tests
    // opt in per file with `// @vitest-environment jsdom` instead.
    environment: 'node',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.{test,spec}.{ts,tsx}'],
  },
  plugins: [
    react(),
    tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate',
      workbox: {
        globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
        // We do NOT cache POST API requests.
        runtimeCaching: [
          {
            urlPattern: /^\/api\/v1\//,
            handler: 'NetworkOnly', // Offline capture handles persistence
          }
        ]
      },
      manifest: {
        name: 'FieldPulse',
        short_name: 'FieldPulse',
        description: 'Offline-First Biometric Enrollment PWA',
        theme_color: '#2563eb', // blue-600
        background_color: '#f3f4f6', // gray-100
        display: 'standalone',
        orientation: 'portrait',
        icons: [
          {
            src: 'pwa-192x192.png',
            sizes: '192x192',
            type: 'image/png',
            purpose: 'any'
          },
          {
            src: 'pwa-512x512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'any'
          },
          {
            // Must be the opaque, safe-zone-padded icon. Pointing `maskable` at
            // the transparent 512 was wrong twice over: the launcher's circular
            // crop would shave the bolt's corners, and the transparent corners
            // composite against an undefined colour.
            src: 'pwa-maskable-512x512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable'
          }
        ]
      }
    })
  ]
});
