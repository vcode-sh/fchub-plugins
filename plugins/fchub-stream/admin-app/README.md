# FCHub Stream Admin App

> [!WARNING]
> FCHub Stream is discontinued and no longer maintained. This code is retained as-is, without support or updates. See the [plugin README](../README.md) for the full status.

Vue.js admin interface for FCHub Stream plugin.

## Development

```bash
# Install dependencies
npm install

# Start development server
npm run dev

# Build for production
npm run build
```

## Structure

- `src/` - Vue source files
  - `App.vue` - Main app component
  - `main.js` - Entry point
  - `components/` - Vue components
- `index.html` - Development HTML template
- `vite.config.js` - Vite configuration

## Build Output

Built files are output to `admin/dist/assets/` directory and loaded by WordPress.
